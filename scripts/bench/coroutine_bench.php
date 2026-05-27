#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phlix coroutine-runtime micro-bench (step 0.2b).
 *
 * Drives N "deliberately slow" units of work concurrently through the
 * Swoole coroutine scheduler (when ext-swoole is present) and asserts
 * that wall-clock time stays roughly equal to a SINGLE unit, not N
 * units in a row. Concretely: with N=4 units of `~100ms` each, the
 * serialized cost is ~400ms; the coroutine-scheduled cost should be
 * ~100-120ms.
 *
 * The bench deliberately uses `time_nanosleep()` (which the Swoole
 * SWOOLE_HOOK_ALL hook converts to a coroutine yield) as the slow op,
 * so it runs in CI without needing a live HTTP target. The existing
 * `concurrent_streams.php` bench requires media-ID + running server
 * and is not CI-friendly; this bench is.
 *
 * Exit codes:
 *   0   pass — concurrent runtime ≤ 1.5× single-unit runtime
 *   1   fail — concurrent runtime > 1.5× single-unit runtime
 *   2   skipped — ext-swoole not loaded (no coroutine scheduler available)
 *
 * Usage:
 *   php scripts/bench/coroutine_bench.php           # defaults: N=4, unit=100ms
 *   php scripts/bench/coroutine_bench.php --n=8 --unit-ms=50
 *
 * @author  Phlix Media Server Team
 * @since   0.10.x (Step 0.2b)
 */

namespace Phlix\Scripts\Bench;

require_once __DIR__ . '/../../vendor/autoload.php';

// ---------- arg parsing ----------
$n      = 4;
$unitMs = 100;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--n=')) {
        $n = max(1, (int) substr($arg, 4));
    }
    if (str_starts_with($arg, '--unit-ms=')) {
        $unitMs = max(1, (int) substr($arg, 10));
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php scripts/bench/coroutine_bench.php [--n=<N>] [--unit-ms=<MS>]\n";
        exit(0);
    }
}

// ---------- skip gracefully when ext-swoole is absent ----------
if (!extension_loaded('swoole')) {
    fwrite(STDERR, "[skip] ext-swoole not loaded — no coroutine scheduler to bench against.\n");
    fwrite(STDERR, "       Install php-swoole and re-run to verify non-serialized concurrency.\n");
    exit(2);
}

$unitSeconds = $unitMs / 1000.0;

// Activate the runtime hook so time_nanosleep yields to the coroutine
// scheduler instead of blocking the thread. Mirrors `start.php`.
\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

/**
 * One unit of "slow work" — a hooked nanosleep that yields under
 * SWOOLE_HOOK_ALL.
 *
 * @param int $unitMs Sleep length in milliseconds.
 */
function unitOfWork(int $unitMs): void
{
    $sec  = intdiv($unitMs, 1000);
    $nano = ($unitMs % 1000) * 1_000_000;
    time_nanosleep($sec, $nano);
}

printf("[bench] N=%d concurrent units, unit=%dms (ext-swoole=on, SWOOLE_HOOK_ALL)\n", $n, $unitMs);

// ---------- serial baseline ----------
$serialStart = hrtime(true);
unitOfWork($unitMs);
$serialNs = hrtime(true) - $serialStart;
$serialMs = $serialNs / 1_000_000.0;
printf("[bench] serial single-unit:  %7.2f ms\n", $serialMs);

// ---------- concurrent run ----------
$concurrentMs = 0.0;
\Swoole\Coroutine\run(function () use (&$concurrentMs, $n, $unitMs): void {
    $start = hrtime(true);
    $wg = new \Swoole\Coroutine\WaitGroup();
    for ($i = 0; $i < $n; $i++) {
        $wg->add();
        \Swoole\Coroutine::create(function () use ($wg, $unitMs): void {
            try {
                unitOfWork($unitMs);
            } finally {
                $wg->done();
            }
        });
    }
    $wg->wait();
    $concurrentMs = (hrtime(true) - $start) / 1_000_000.0;
});

printf("[bench] concurrent N=%d:     %7.2f ms\n", $n, $concurrentMs);

// ---------- pass/fail ----------
// Allow up to 1.5× the single-unit cost for scheduler overhead +
// system jitter. If concurrency truly serialized, we'd see N×.
$threshold = $serialMs * 1.5;
$serializedExpected = $serialMs * $n;
printf(
    "[bench] threshold = serial × 1.5 = %7.2f ms ; serialized would be ≈ %7.2f ms\n",
    $threshold,
    $serializedExpected
);

if ($concurrentMs <= $threshold) {
    printf(
        "[bench] PASS — concurrent %7.2f ms ≤ threshold %7.2f ms (speedup ≈ %.2fx vs serial)\n",
        $concurrentMs,
        $threshold,
        $serializedExpected / max($concurrentMs, 0.001)
    );
    exit(0);
}

fprintf(
    STDERR,
    "[bench] FAIL — concurrent %7.2f ms > threshold %7.2f ms; concurrency appears to serialize.\n",
    $concurrentMs,
    $threshold
);
exit(1);
