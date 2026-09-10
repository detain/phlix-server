<?php

/**
 * PHPUnit bootstrap.
 *
 * Beyond loading the Composer autoloader, this guards the test runner against a
 * stray SIGALRM raised by Workerman's Timer subsystem.
 *
 * Several units under test (e.g. HlsSegmentPrefetcher, HlsRelayManager, the
 * WebSocket server) legitimately call `Workerman\Timer::add()`. When Workerman
 * is used OUTSIDE a running event loop — exactly the situation inside PHPUnit —
 * `Timer::init()` falls back to the pcntl signal scheduler: it registers a
 * SIGALRM handler and arms `pcntl_alarm(1)`. With no Workerman event loop
 * draining that timer, the alarm fires ~1s later and, because PHP does not
 * dispatch the signal to Workerman's handler in this context, the process is
 * terminated with the default SIGALRM disposition ("Alarm clock", exit 142).
 *
 * That is precisely the non-deterministic exit-142 failure seen in CI (the
 * suite uses `executionOrder="random"`, so whichever timer-arming test runs
 * first kills everything after it). Installing a harmless async SIGALRM handler
 * here makes the stray alarm a no-op so the full suite can run to completion.
 *
 * This affects only the test process; production code keeps its real Workerman
 * event loop, which delivers and drains timers normally.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/*
 * S457 (prototype) — opt-in per-process isolation seam for parallel test runs.
 *
 * Two independently gated switches, each inert when its env var is absent, so a
 * serial run (CI today, every developer shell) behaves byte-for-byte as before:
 *
 *  1. TMPDIR — per-worker temp isolation MUST be done by the launcher, before PHP
 *     starts: sys_get_temp_dir() resolves TMPDIR once at startup and a later
 *     putenv() is provably inert (measured on PHP 8.3.6). Why it matters: the S439
 *     ZeroResidueCensus extension snapshots sys_get_temp_dir() at extension
 *     bootstrap (which PHPUnit 10.5 runs AFTER this script) and diffs it at run
 *     end; with N concurrent workers sharing one TMPDIR, the worker that finishes
 *     first sees its still-running siblings' in-flight phlix_* files as "residue"
 *     and kills the run. Under paraunit, scripts/parallel/php (a PATH shim
 *     prepended by the launcher) sets a distinct TMPDIR at every child exec
 *     using PARAUNIT_PROCESS_UNIQUE_ID.
 *
 *  2. PHLIX_TEST_DB_SHARDS=N — claim one of the databases phlix_test_w1..wN with
 *     a non-blocking flock held for the process lifetime, then point DB_DATABASE
 *     at it. Integration tests mutate shared tables and some (S47LoginRepoint…)
 *     assert whole-table row-count deltas, so N workers on one schema race; the
 *     clones give each worker an exclusive schema. The lock is what keeps slot
 *     assignment collision-free among CONCURRENT workers even though PHPUnit
 *     recycles OS PIDs between the short-lived per-file processes. The launcher
 *     must keep at most N workers alive; if every slot is held for longer than
 *     PHLIX_TEST_DB_SLOT_WAIT_SECONDS (default 10) the seam fails loudly rather
 *     than silently sharing a schema.
 *
 * config/database.php resolves every DB_* through getenv() at call time, so a
 * putenv() here (before the first test runs any query — and before the extension
 * bootstrap, see PHPUnit\TextUI\Application::run()) is authoritative for the whole
 * process, including child scripts spawned by tests, which inherit the env.
 */
(static function (): void {
    $shardCount = (int) (getenv('PHLIX_TEST_DB_SHARDS') ?: 0);
    if ($shardCount <= 0) {
        return;
    }

    // The slot lock dir MUST live in a namespace every worker shares. Under the
    // parallel launcher, TMPDIR (and therefore sys_get_temp_dir()) is already
    // per-worker by the time this runs, so the base env var is the shared root;
    // PHLIX_TEST_TMP_BASE is exported to every child for exactly this reason.
    $tmpBase = getenv('PHLIX_TEST_TMP_BASE');
    $sharedRoot = $tmpBase !== false && $tmpBase !== '' ? rtrim($tmpBase, '/') : sys_get_temp_dir();
    $lockDir = $sharedRoot . '/phlix-test-db-slots';
    if (!is_dir($lockDir) && !mkdir($lockDir, 0777, true) && !is_dir($lockDir)) {
        throw new RuntimeException("PHLIX_TEST_DB_SHARDS: cannot create slot lock dir {$lockDir}");
    }

    $waitSeconds = (float) (getenv('PHLIX_TEST_DB_SLOT_WAIT_SECONDS') ?: 10);
    $deadline = microtime(true) + $waitSeconds;
    while (true) {
        for ($slot = 1; $slot <= $shardCount; $slot++) {
            $lockHandle = fopen($lockDir . "/w{$slot}.lock", 'c');
            if ($lockHandle === false) {
                throw new RuntimeException("PHLIX_TEST_DB_SHARDS: cannot open slot lock file for shard {$slot}");
            }
            if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
                // Hold the handle (and therefore the lock) in $GLOBALS for the
                // lifetime of the process; closing it would release the slot.
                $GLOBALS['phlix_test_db_slot_lock'] = $lockHandle;
                putenv('DB_DATABASE=phlix_test_w' . $slot);
                return;
            }
            fclose($lockHandle);
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException(
                "PHLIX_TEST_DB_SHARDS: no free phlix_test schema among {$shardCount} slots after {$waitSeconds}s — "
                . 'the launcher is running more concurrent workers than there are shards'
            );
        }
        usleep(50_000);
    }
})();

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && defined('SIGALRM')) {
    // Deliver signals asynchronously so a queued SIGALRM hits our no-op handler
    // instead of the default (process-terminating) disposition.
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function (): void {
        // Intentionally swallow Workerman's no-event-loop Timer alarm.
    });
}
