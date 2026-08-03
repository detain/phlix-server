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

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && defined('SIGALRM')) {
    // Deliver signals asynchronously so a queued SIGALRM hits our no-op handler
    // instead of the default (process-terminating) disposition.
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function (): void {
        // Intentionally swallow Workerman's no-event-loop Timer alarm.
    });
}
