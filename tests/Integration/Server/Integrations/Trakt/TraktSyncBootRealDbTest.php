<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Server\Integrations\Trakt;

use Phlix\Admin\SettingsRepository;
use Phlix\Server\Integrations\Trakt\TraktSyncBoot;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\Events\EventInterface;
use Workerman\Events\Select;
use Workerman\MySQL\Connection;
use Workerman\Timer;
use Workerman\Worker;

/**
 * S340 — the Trakt pull-sync boot catch-up against the REAL database.
 *
 * The acceptance criteria demand a demonstration, not an argument:
 *
 *  1. the sync runs on a box whose uptime is shorter than the interval — the
 *     restart is SIMULATED here by tearing down and rebuilding the boot
 *     objects while the only durable carry-over (the `server_settings` row
 *     `trakt.sync_last_run_at`) stays in MySQL;
 *  2. the pre-fix shape is OBSERVED not firing as the control — a bare
 *     `Timer::add(interval, …)` is armed against a real Workerman event loop
 *     and the loop is run for less than the interval: the callback never
 *     fires, exactly what a restarted box experiences;
 *  3. last-run state survives restart — written by one repository instance,
 *     read back by a fresh instance from the real DB.
 *
 * Everything is driven through the real {@see SettingsRepository} over
 * `ConnectionPool`'s real MySQL connection (the same store start.php's sweep
 * uses), never an in-memory mock — a hand-written fixture cannot detect that
 * the real thing differs (S345 lesson 2). The state each decision was made on
 * is printed to STDERR (phpunit.xml sets `beStrictAboutOutputDuringTests`
 * with `failOnRisky`).
 *
 * @package Phlix\Tests\Integration\Server\Integrations\Trakt
 */
final class TraktSyncBootRealDbTest extends TestCase
{
    use RequiresRealDatabase;

    private const INTERVAL_SECONDS = 3600;

    private ?Connection $db = null;

    private ?EventInterface $savedGlobalEvent = null;

    private ?EventInterface $savedTimerEvent = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping S340 Trakt sync-boot real-DB test. Runs in CI.');

        // Only rows this run creates are touched: the one scheduler key.
        $this->db->query(
            'DELETE FROM server_settings WHERE setting_key = ?',
            [TraktSyncBoot::STATE_LAST_RUN_AT],
        );

        // The event-loop harness below installs a REAL Select loop as
        // Worker::$globalEvent and hands it to Timer::init(), and Workerman's
        // Timer::add() routes every subsequent call through Timer::$event while
        // it is set. Save both so tearDown can restore them: the suite's
        // Timer-inspection tests (CoreUpdateCheckWorkerTest et al.) read the
        // in-process `self::$tasks` table, which Timer::add() only fills when
        // Timer::$event is null (mirrors
        // {@see \Phlix\Tests\Unit\Dlna\SsdpMSearchListenerTest}).
        $this->savedGlobalEvent = Worker::$globalEvent;
        $this->savedTimerEvent = $this->timerEvent();
    }

    protected function tearDown(): void
    {
        Timer::delAll();
        $this->setTimerEvent($this->savedTimerEvent);
        Worker::$globalEvent = $this->savedGlobalEvent;

        $db = $this->db;
        if ($db !== null) {
            $db->query(
                'DELETE FROM server_settings WHERE setting_key = ?',
                [TraktSyncBoot::STATE_LAST_RUN_AT],
            );
        }

        parent::tearDown();
    }

    private function repository(): SettingsRepository
    {
        return new SettingsRepository($this->db, dirname(__DIR__, 5) . '/config');
    }

    /**
     * The restart simulation. "Boot" here is a fresh repository instance over
     * the SAME real MySQL row — a restart's only durable carry-over is the DB.
     */
    public function testRestartWithAStaleLastRunRunsTheSyncOnTheFirstSweep(): void
    {
        $runs = 0;
        $sync = static function () use (&$runs): void {
            $runs++;
        };
        $bootTime = time();

        // Boot 1 — a fresh box (no row): the pull runs immediately.
        $ran1 = TraktSyncBoot::runIfDue($this->repository(), self::INTERVAL_SECONDS, $sync, $bootTime);
        self::assertTrue($ran1, 'a never-run pull must run on the first sweep');
        self::assertSame(1, $runs);

        // Restart — a NEW repository instance reads the row back from MySQL.
        $afterRestart = $this->repository();
        $row = $afterRestart->getOverride(TraktSyncBoot::STATE_LAST_RUN_AT);
        self::assertIsArray($row, 'the last-run must survive the restart (real DB read-back)');
        self::assertIsInt($row['value']);
        self::assertGreaterThanOrEqual(
            $bootTime,
            $row['value'],
            'the last-run must be the completion time of boot 1, no earlier than the decision',
        );
        self::assertLessThan(
            $bootTime + 60,
            $row['value'],
            'the last-run must have been stamped at boot 1',
        );

        // Boot 2 — uptime (60s) SHORTER than the interval (3600s), fresh
        // last-run: the sync must NOT re-run.
        $ran2 = TraktSyncBoot::runIfDue($afterRestart, self::INTERVAL_SECONDS, $sync, $bootTime + 60);
        self::assertFalse($ran2, 'a fresh last-run must not re-run the sync on a box restarted early');
        self::assertSame(1, $runs);

        // Boot 3 — the box was down for two intervals (stale last-run): the
        // first sweep after boot must run the sync — the boot catch-up.
        $afterRestart->set(TraktSyncBoot::STATE_LAST_RUN_AT, $bootTime - (2 * self::INTERVAL_SECONDS), 'int');
        $afterSecondRestart = $this->repository();
        $ran3 = TraktSyncBoot::runIfDue($afterSecondRestart, self::INTERVAL_SECONDS, $sync, $bootTime);
        self::assertTrue($ran3, 'a stale last-run must run the sync on the first sweep after a restart');
        self::assertSame(2, $runs);

        $final = $afterSecondRestart->getOverride(TraktSyncBoot::STATE_LAST_RUN_AT);
        fwrite(STDERR, sprintf(
            "\n[S340] restart simulation over the real DB (interval=%ds):\n"
            . "[S340]   boot1 fresh-box ran=%s runs=%d -> persisted last_run=%d\n"
            . "[S340]   boot2 uptime-60s<interval ran=%s runs=%d (fresh last-run must NOT re-run)\n"
            . "[S340]   boot3 stale-2-intervals ran=%s runs=%d -> persisted last_run=%d\n",
            self::INTERVAL_SECONDS,
            var_export($ran1, true),
            1,
            (int) $row['value'],
            var_export($ran2, true),
            1,
            var_export($ran3, true),
            2,
            is_array($final) ? (int) $final['value'] : -1,
        ));
    }

    /**
     * The CONTROL and the FIX, side by side, over a REAL Workerman event loop.
     *
     * The pre-fix shape (`Timer::add($interval, $cb)`) is armed and the loop is
     * run for less than the interval — the callback is OBSERVED not firing,
     * which is exactly what a box restarted more often than the interval
     * experiences. The fix shape (a 1s sweep + due-check against a stale
     * last-run) fires the sync on the first sweep within the same budget.
     */
    public function testPreFixBareIntervalControlDoesNotFireWhileTheSweepCatchesUp(): void
    {
        // ---- CONTROL: the pre-fix bare interval. 5s interval, 2s "uptime". ----
        $controlFired = 0;
        $this->runLoop(2.0, static function () use (&$controlFired): void {
            Timer::add(5, static function () use (&$controlFired): void {
                $controlFired++;
            });
        });
        self::assertSame(
            0,
            $controlFired,
            'CONTROL: a bare Timer::add(interval, …) must NOT fire within an uptime shorter than '
            . 'the interval — the pre-fix shape never fires on a box that restarts that often.',
        );

        // ---- FIX: a sweep + due-check against a STALE last-run. ----
        $this->repository()->set(TraktSyncBoot::STATE_LAST_RUN_AT, time() - (2 * self::INTERVAL_SECONDS), 'int');

        $syncRuns = 0;
        $repo = $this->repository();
        $this->runLoop(3.0, static function () use ($repo, &$syncRuns): void {
            Timer::add(1, static function () use ($repo, &$syncRuns): void {
                TraktSyncBoot::runIfDue(
                    $repo,
                    self::INTERVAL_SECONDS,
                    static function () use (&$syncRuns): void {
                        $syncRuns++;
                    },
                );
            });
        });

        self::assertSame(
            1,
            $syncRuns,
            'FIX: the first sweep after a restart must run the sync exactly once when a poll was '
            . 'missed (the sweep keeps asking; the interval decides).',
        );

        $row = $this->repository()->getOverride(TraktSyncBoot::STATE_LAST_RUN_AT);
        self::assertIsArray($row, 'the fix loop must have persisted the new last-run to MySQL');
        self::assertIsInt($row['value']);
        self::assertGreaterThan(
            time() - 10,
            $row['value'],
            'the persisted last-run must be the sweep time, not the stale pre-restart value',
        );

        fwrite(STDERR, sprintf(
            "\n[S340] real event-loop control (loop budgets: control 2.0s, fix 3.0s):\n"
            . "[S340]   CONTROL bare Timer::add(5s, cb) within 2s uptime -> fired=%d "
            . "(pre-fix shape observed NOT firing)\n"
            . "[S340]   FIX sweep Timer::add(1s) + stale last-run -> sync_runs=%d, persisted last_run=%d\n",
            $controlFired,
            $syncRuns,
            is_array($row) ? (int) $row['value'] : -1,
        ));
    }

    /**
     * Run a real Workerman event loop for a bounded budget.
     *
     * Mirrors the harness in {@see \Phlix\Tests\Unit\Dlna\SsdpMSearchListenerTest}:
     * a fresh Select loop installed as `Worker::$globalEvent`, `Timer::init()`
     * over it, timers armed by `$arm`, a delay-stopped budget as the safety
     * net, then `Timer::delAll()`.
     */
    private function runLoop(float $budget, callable $arm): void
    {
        $select = new Select();
        Worker::$globalEvent = $select;
        Timer::init($select);

        $arm();

        $select->delay($budget, static function () use ($select): void {
            $select->stop();
        }, []);

        $select->run();
        Timer::delAll();
    }

    private function timerEvent(): ?EventInterface
    {
        $prop = new ReflectionProperty(Timer::class, 'event');
        $prop->setAccessible(true);
        /** @var EventInterface|null $value */
        $value = $prop->getValue();

        return $value;
    }

    private function setTimerEvent(?EventInterface $event): void
    {
        $prop = new ReflectionProperty(Timer::class, 'event');
        $prop->setAccessible(true);
        $prop->setValue(null, $event);
    }
}
