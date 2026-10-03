<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use PHPUnit\Framework\TestCase;

/**
 * F16 close-out — the stats/bootstrap dual-entry law after S171.
 *
 * F16 originally demanded a structural pin that the stats bootstrap wiring of
 * `start.php` and `public/index.php` stay EQUAL. S171 then deleted
 * `public/index.php` (nothing in any deployment artifact served it;
 * PublicFrontControllerRemovalGuardTest pins the removal), so the literal
 * two-file equality became vacuous. The LAW survives its second operand: the
 * two live bootstrap entries today are
 *
 *   1. `Application::run()`            — foreground/dev mode, and
 *   2. the `background-timers` worker  — the daemon path (`start.php`),
 *
 * and BOTH must funnel through the SAME single method
 * (`Application::startBackgroundTimers()`), because the 73fa51dd history —
 * the daemon forked a timer worker but only `run()` used to arm the timers,
 * so `stats_storage` was never written in production — is exactly the
 * divergence a duplicated bootstrap would resurrect. These pins keep the
 * two entries structurally identical without naming a deleted file:
 *
 *   - the stats snapshot timer exists in exactly ONE place in the codebase
 *     (`startStorageSnapshotTimer`), reached from exactly ONE call chain per
 *     entry, never inlined a second time;
 *   - every worker fork site in `start.php` bootstraps config through the
 *     ONE overlay-complete helper (`EffectiveConfig::bootstrapAndOverlay`) —
 *     a raw `bootstrap(` call there would hand a fork the pre-DB file config
 *     and re-open the "two entries disagree about settings" class for real.
 *
 * Source-structure pins in the S171/S299 census idiom: exact token counts on
 * the two files, with the attribution comments telling a future rotator WHY
 * each number is what it is.
 */
final class StatsBootstrapSingleSourceTest extends TestCase
{
    private string $app;

    private string $start;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 4);
        $appFile = $root . '/src/Server/Core/Application.php';
        $startFile = $root . '/start.php';

        $app = file_get_contents($appFile);
        $start = file_get_contents($startFile);

        self::assertIsString($app, "Unreadable {$appFile} — the entry the stats funnel lives in.");
        self::assertIsString($start, "Unreadable {$startFile} — the daemon entry F16 pairs against it.");

        $this->app = $app;
        $this->start = $start;
    }

    public function test_the_literal_f16_second_operand_stays_deleted(): void
    {
        // If this reddens, someone restored public/index.php and the ORIGINAL
        // F16 equality question (does the CGI entry wire stats identically?)
        // must be re-answered, not silently ignored.
        self::assertFileDoesNotExist(
            dirname(__DIR__, 4) . '/public/index.php',
            'public/index.php is back. F16 was closed as "vacuous since S171" precisely '
            . 'because this file does not exist; restoring it re-opens the dual-entry '
            . 'stats-equality question this test no longer covers.'
        );
    }

    public function test_both_live_entries_funnel_through_the_single_timer_method(): void
    {
        // Entry 1: run() arms the timers through the shared method — exactly once.
        self::assertSame(
            1,
            substr_count($this->app, '$this->startBackgroundTimers();'),
            'Application must invoke startBackgroundTimers() exactly once (from run()). '
            . 'A second in-place invocation means the entries diverged again.'
        );

        // Entry 2: the daemon's background-timers worker arms the SAME method.
        self::assertSame(
            1,
            substr_count($this->start, '$timerApp->startBackgroundTimers();'),
            'start.php must arm background timers exactly once, via the timer worker '
            . 'calling the same Application method run() uses.'
        );

        // The method itself is defined once and exported public for that pair only.
        self::assertSame(
            1,
            substr_count($this->app, 'public function startBackgroundTimers(): void'),
            'Exactly one definition of the funnel may exist.'
        );
    }

    public function test_the_storage_snapshot_registration_exists_exactly_once(): void
    {
        // The stats half of the divergence F16 feared was startStorageSnapshotTimer
        // (73fa51dd): pin that its registration and body were never duplicated.
        self::assertSame(
            1,
            substr_count($this->app, 'private function startStorageSnapshotTimer(): void'),
            'The storage-snapshot bootstrap must exist exactly once.'
        );
        self::assertSame(
            1,
            substr_count($this->app, 'Timer::add(' . "\n" . '                self::STORAGE_SNAPSHOT_INTERVAL,'),
            'The periodic storage-snapshot timer may be armed from exactly one place — '
            . 'the single-source method both entries funnel through.'
        );
        // $this->recordStorageSnapshots( appears twice by design: the immediate
        // boot snapshot and the recurring tick closure, both INSIDE the one
        // method. Any third would be a second bootstrap outside the funnel.
        self::assertSame(
            2,
            substr_count($this->app, '$this->recordStorageSnapshots($collector, $db, $logger);'),
            'recordStorageSnapshots must be called exactly twice in Application — boot + '
            . 'tick, both inside startStorageSnapshotTimer(). A third call would bypass the '
            . 'single-source funnel F16 exists to protect.'
        );
    }

    public function test_every_start_php_fork_site_bootstraps_through_the_overlay_helper(): void
    {
        // Rotation law: adding a worker fork to start.php REQUIRES one more
        // bootstrapAndOverlay site (measured 7 across the 2026-10-03 tip: HTTP,
        // WS, hub-heartbeat, background-timers, relay-tunnel, syncplay, and the
        // master pre-flight) — bump this number in the same commit and say so
        // in the commit message.
        self::assertSame(
            7,
            substr_count($this->start, 'EffectiveConfig::bootstrapAndOverlay'),
            'Every fork/pre-flight site in start.php must take the overlay-complete '
            . 'bootstrap; a count change means a site was added or removed and both '
            . 'this pin and its attribution comment need the same commit.'
        );

        // And the failure mode it prevents: a RAW bootstrap() silently skips
        // the DB overlay, so that fork would read file-defaults where the HTTP
        // worker reads admin-saved values — the dual-entry divergence, reborn.
        self::assertSame(
            0,
            preg_match_all('/EffectiveConfig::bootstrap\s*\(/', $this->start),
            'start.php must never call EffectiveConfig::bootstrap() raw — only '
            . 'bootstrapAndOverlay(). A raw call re-creates the F16 bug class: two '
            . 'entries wiring settings differently.'
        );
    }
}
