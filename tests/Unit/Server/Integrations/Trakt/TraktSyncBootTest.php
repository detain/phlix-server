<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Integrations\Trakt;

use Phlix\Admin\SettingsRepository;
use Phlix\Server\Integrations\Trakt\TraktSyncBoot;
use Phlix\Tests\Support\Database\InMemoryServerSettingsConnection;
use PHPUnit\Framework\TestCase;

/**
 * S340 — the Trakt pull-sync due-decision and its persistence, in isolation.
 *
 * The daemon's timer no longer fires on a bare interval; it sweeps every
 * {@see TraktSyncBoot::DEFAULT_SWEEP_SECONDS} and asks
 * {@see TraktSyncBoot::runIfDue()} whether a pull should run. This file pins
 * the decision itself — every branch enumerated (S345 lesson 1) — and the
 * orchestration: run only when due, persist the last-run afterwards, and let
 * the persisted state be the restart's only carry-over.
 *
 * The state-evaluation lines are printed to STDERR (phpunit.xml sets
 * `beStrictAboutOutputDuringTests` with `failOnRisky`), so a reader sees the
 * exact input the decision was made on rather than taking a boolean for it.
 *
 * @package Phlix\Tests\Unit\Server\Integrations\Trakt
 */
final class TraktSyncBootTest extends TestCase
{
    // ------------------------------------------------------------------
    // 1. The pure decision — every branch.
    // ------------------------------------------------------------------

    public function testNeverRanIsDue(): void
    {
        fwrite(STDERR, sprintf(
            "\n[S340] isDue(last_run=null, interval=3600, now=%d) => %s\n",
            1_700_000_000,
            var_export(TraktSyncBoot::isDue(null, 3600, 1_700_000_000), true),
        ));

        self::assertTrue(
            TraktSyncBoot::isDue(null, 3600, 1_700_000_000),
            'A pull that has never run must be due — this is the whole boot catch-up: a fresh '
            . 'box with no persisted last-run runs the pull on its first sweep.',
        );
    }

    public function testFreshLastRunIsNotDue(): void
    {
        $now = 1_700_000_000;
        $lastRunAt = $now - 60; // 60s ago, interval is 3600s

        fwrite(STDERR, sprintf(
            "[S340] isDue(last_run=%d, interval=3600, now=%d) => %s (elapsed %ds < interval)\n",
            $lastRunAt,
            $now,
            var_export(TraktSyncBoot::isDue($lastRunAt, 3600, $now), true),
            $now - $lastRunAt,
        ));

        self::assertFalse(
            TraktSyncBoot::isDue($lastRunAt, 3600, $now),
            'A last-run inside the interval must NOT be due.',
        );
    }

    public function testStaleLastRunIsDue(): void
    {
        $now = 1_700_000_000;
        $lastRunAt = $now - 7200; // 2 intervals ago

        fwrite(STDERR, sprintf(
            "[S340] isDue(last_run=%d, interval=3600, now=%d) => %s (elapsed %ds >= interval)\n",
            $lastRunAt,
            $now,
            var_export(TraktSyncBoot::isDue($lastRunAt, 3600, $now), true),
            $now - $lastRunAt,
        ));

        self::assertTrue(
            TraktSyncBoot::isDue($lastRunAt, 3600, $now),
            'A last-run older than the interval must be due — the stale-state catch-up a '
            . 'restarted box reaches on its first sweep.',
        );
    }

    public function testBoundaryIsDue(): void
    {
        $now = 1_700_000_000;
        $lastRunAt = $now - 3600; // exactly one interval ago

        self::assertTrue(
            TraktSyncBoot::isDue($lastRunAt, 3600, $now),
            'A last-run exactly one interval ago must be due (>=, matching the S308 shape).',
        );
    }

    public function testFutureLastRunIsDue(): void
    {
        $now = 1_700_000_000;
        $lastRunAt = $now + 86_400; // clock moved backwards

        fwrite(STDERR, sprintf(
            "[S340] isDue(last_run=%d, interval=3600, now=%d) => %s (last_run in the future)\n",
            $lastRunAt,
            $now,
            var_export(TraktSyncBoot::isDue($lastRunAt, 3600, $now), true),
        ));

        self::assertTrue(
            TraktSyncBoot::isDue($lastRunAt, 3600, $now),
            'A last-run in the future (clock moved backwards) must be due, or the pull is '
            . 'silenced for years.',
        );
    }

    // ------------------------------------------------------------------
    // 2. runIfDue — orchestration over a REAL SettingsRepository.
    //    (InMemoryServerSettingsConnection models the real table the same
    //    way CoreUpdateCheckWorkerTest does; the real MySQL proof lives in
    //    TraktSyncBootRealDbTest.)
    // ------------------------------------------------------------------

    private function repository(): SettingsRepository
    {
        return new SettingsRepository(
            new InMemoryServerSettingsConnection(),
            dirname(__DIR__, 5) . '/config',
        );
    }

    public function testRunIfDueRunsTheSyncAndPersistsTheLastRun(): void
    {
        $repo = $this->repository();
        $runs = 0;
        $now = time();

        $ran = TraktSyncBoot::runIfDue(
            $repo,
            3600,
            static function () use (&$runs): void {
                $runs++;
            },
            $now,
        );

        self::assertTrue($ran, 'A never-run pull must run.');
        self::assertSame(1, $runs, 'The sync callback must run exactly once.');

        $row = $repo->getOverride(TraktSyncBoot::STATE_LAST_RUN_AT);
        self::assertIsArray($row, 'The last-run must be persisted after a run.');
        self::assertIsInt($row['value']);
        self::assertGreaterThanOrEqual(
            $now,
            $row['value'],
            'The persisted last-run must be the completion time, no earlier than the decision time.',
        );
        self::assertLessThan(
            $now + 60,
            $row['value'],
            'The persisted last-run must be stamped within a minute of the decision.',
        );
    }

    public function testRunIfDueSkipsWhenNotDueAndWritesNothing(): void
    {
        $repo = $this->repository();
        $now = time();
        $repo->set(TraktSyncBoot::STATE_LAST_RUN_AT, $now - 60, 'int');

        $runs = 0;
        $ran = TraktSyncBoot::runIfDue(
            $repo,
            3600,
            static function () use (&$runs): void {
                $runs++;
            },
            $now,
        );

        self::assertFalse($ran, 'A fresh last-run must NOT run the pull.');
        self::assertSame(0, $runs, 'The sync callback must not run at all when not due.');

        $row = $repo->getOverride(TraktSyncBoot::STATE_LAST_RUN_AT);
        self::assertIsArray($row);
        self::assertSame($now - 60, $row['value'], 'Not-due must not rewrite the persisted last-run.');
    }

    /**
     * The persisted last-run is the restart's only carry-over: a FRESH
     * repository instance pointed at the same store must read back exactly
     * what the previous "process" wrote.
     */
    public function testPersistedLastRunSurvivesARestart(): void
    {
        $store = new InMemoryServerSettingsConnection();
        $now = time();

        // "Boot 1": a fresh store, the pull runs and persists.
        TraktSyncBoot::runIfDue(
            new SettingsRepository($store, dirname(__DIR__, 5) . '/config'),
            3600,
            static function (): void {
            },
            $now,
        );

        // "Restart": a brand-new repository instance (no in-process cache) over
        // the SAME store. The only durable carry-over is the DB row.
        $afterRestart = new SettingsRepository($store, dirname(__DIR__, 5) . '/config');
        $row = $afterRestart->getOverride(TraktSyncBoot::STATE_LAST_RUN_AT);

        self::assertIsArray($row, 'The last-run must still be readable after a restart.');
        self::assertIsInt($row['value']);
        self::assertGreaterThanOrEqual(
            $now,
            $row['value'],
            'The last-run must be read back as the completion time, no earlier than the decision.',
        );
        self::assertLessThan(
            $now + 60,
            $row['value'],
            'The last-run must be read back within a minute of the decision.',
        );
    }

    /**
     * A throwing sync must propagate (the daemon's timer wrapper logs it) and
     * must NOT advance the last-run — a failed pull is retried on the next
     * sweep, never recorded as a success.
     */
    public function testAThrowingSyncDoesNotPersistTheLastRun(): void
    {
        $repo = $this->repository();

        try {
            TraktSyncBoot::runIfDue(
                $repo,
                3600,
                static function (): void {
                    throw new \RuntimeException('sync exploded');
                },
                1_700_000_000,
            );
            self::fail('A throwing sync must propagate out of runIfDue().');
        } catch (\RuntimeException $e) {
            self::assertSame('sync exploded', $e->getMessage());
        }

        self::assertNull(
            $repo->getOverride(TraktSyncBoot::STATE_LAST_RUN_AT),
            'A failed pull must not be recorded as a completed run.',
        );
    }
}
