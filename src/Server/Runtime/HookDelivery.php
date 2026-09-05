<?php

/**
 * Phlix media server component: Runtime.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Runtime;

use Swoole\Coroutine;
use Swoole\Runtime;

/**
 * Makes the curated hook allowlist LAND in a worker, then PROVES it landed —
 * behaviourally, never through the option the runtime reports.
 *
 * ## The defect this closes (S433, was S255)
 *
 * `Workerman\Events\Swoole::__construct()` runs `Coroutine::set(['hook_flags'
 * => SWOOLE_HOOK_ALL])` once per worker BEFORE `onWorkerStart`, and Workerman
 * dispatches every `onWorkerStart` INSIDE a coroutine (`start.php:114-125`).
 * The shipped remedy (`start.php:150` at filing) re-asserted the curated mask
 * from there via the same `Coroutine::set()` API. Measured on this estate's
 * own stack (PHP 8.3 / Swoole 6.2.1, A/B with repeats — and matching the
 * 2026-08-12 flagged-13 measurement of 191 sibling ticks on the older box):
 *
 *  - `Coroutine::set(['hook_flags' => X])` called INSIDE a coroutine updates
 *    only the REPORTED option: `getOptions()['hook_flags']` shows `0x42fe`
 *    while the cURL handlers from `SWOOLE_HOOK_ALL` are still physically
 *    installed (a blocking
 *    cURL against a never-answered local socket yielded ~30 sibling ticks per
 *    150 ms window). The allowlist reached NO worker, and the obvious check
 *    reported SUCCESS either way.
 *  - `Swoole\Runtime::enableCoroutine(X)` — the full-mask REPLACEMENT API —
 *    un-swaps already-installed handlers from the same calling context
 *    (measured: 0-1 sibling ticks after, 30 again after a re-install). Calling
 *    context is NOT the variable; the API is. (This narrows S255's own
 *    fix-direction note, which believed only an OUTSIDE-coroutine re-assert
 *    could work — that experiment changed two variables at once.)
 *
 * ## The delivery evidence, and why it is the only kind that counts
 *
 * {@see probe()} drives a sibling coroutine ticking every ~2 ms around a cURL
 * request to a local listener that never answers. A physically-installed cURL
 * hook yields to the event loop, so the sibling accrues dozens of ticks inside
 * the timeout window; an unhooked cURL blocks the whole worker, so it accrues
 * none. That asymmetry is mask-observable behaviour no reported option can
 * fake. The probe NEVER consults `getOptions()` — that equality check is the
 * exact trap this file exists to replace, and the named tests stay RED for it
 * (see `CuratedHookDeliveryProbeTest` and `HookAllowlistEnforcementGuardTest`).
 *
 * An inconclusive sample (the cURL did not actually block for the window)
 * throws rather than passing: a check that cannot decide must not report
 * success — the S146 lesson applied to the runtime.
 *
 * ## Failure posture (deliberate)
 *
 * `enforceAndVerify()` throws {@see HookDeliveryException} on any
 * non-delivery. In `start.php` that kills the worker child; Workerman logs the
 * non-zero exit and re-forks. That loud crash-loop is the POINT: the curated
 * mask is the SIGSEGV mitigation (PHP 8.5 / Swoole 6.2.1 / kernel-7 io_uring,
 * 200+ worker crashes a day without it), so "hooks not delivered" is a
 * site-down-class condition that must never hide behind a green start.
 *
 * @package Phlix\Server\Runtime
 * @since 1.2.4
 */
final class HookDelivery
{
    /**
     * How long the probe's cURL request is made to block, in milliseconds.
     * Long enough that a yielding (hooked) call accrues dozens of sibling
     * ticks; short enough to stay far under worker-start budgets.
     */
    public const BLOCK_MS = 150;

    /**
     * Ticks at or above this during the window mean the call YIELDED (a cURL
     * hook is physically installed). Measured separation at this tip: 0-1
     * unhooked vs ~30 hooked per window with a 2 ms ticker — the floor sits
     * mid-gap so neither scheduling jitter nor one pre-block ticker turn
     * (stream setup can yield once while hooks are on) flips a verdict.
     */
    public const YIELD_TICK_FLOOR = 5;

    /**
     * Absolute ceiling on sibling-ticker iterations, independent of the stop
     * flag. The caller clears the flag the instant curl_exec returns (the
     * probe's timeout guarantees that is bounded), so this cap never binds a
     * correct run — it only guarantees a stopped-up scheduler cannot burn a
     * worker forever inside a probe that was always meant to be transient.
     */
    public const TICK_HARD_CAP = 25_000;

    /** Sibling ticker wake-up period, seconds. */
    private const TICK_INTERVAL_S = 0.002;


    /**
     * The fraction of the window the cURL must demonstrably spend blocked
     * before the tick count is trusted at all (below it the sample is
     * inconclusive — refused connection, answering proxy, …).
     */
    private const MIN_BLOCKED_FRACTION = 0.6;

    /**
     * Physically install `$mask` as this process's coroutine hook set.
     *
     * This is the only API that un-swaps handlers an earlier call already
     * installed — class docblock, second bullet.
     *
     * @param int $mask A `SWOOLE_HOOK_*` bitmask ({@see SwooleRuntime::runtimeHookMask()}).
     */
    public static function enforce(int $mask): void
    {
        Runtime::enableCoroutine($mask);
    }

    /**
     * Behavioural sibling-tick probe: does a cURL request block the worker
     * (hooks OFF) or yield to the event loop (hooks ON)?
     *
     * Must run inside a coroutine — that is exactly where the reported option
     * lies and where the remedy has to be verified.
     *
     * @param int $blockMs Length of the blocking window.
     *
     * @return array{ticks: int, blocked_ms: float}
     *
     * @throws \LogicException          called outside a coroutine (no probe possible).
     * @throws HookDeliveryException    the sample is inconclusive.
     */
    public static function probe(int $blockMs = self::BLOCK_MS): array
    {
        if (Coroutine::getCid() < 0) {
            throw new \LogicException(
                'HookDelivery::probe() must run inside a Swoole coroutine — '
                . 'outside one there is no scheduler to observe, so it would always '
                . '"pass" without proving anything.'
            );
        }

        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new HookDeliveryException(sprintf(
                'hook-delivery probe cannot open its local listener (errno %d: %s) — refusing to '
                . 'report delivery it did not measure',
                (int) $errno,
                (string) $errstr
            ));
        }

        $name = stream_socket_get_name($server, false);
        if (!is_string($name)) {
            fclose($server);
            throw new HookDeliveryException(
                'hook-delivery probe could not read back its local listener address — refusing to '
                . 'report delivery it did not measure'
            );
        }

        try {
            return self::probeAgainst('http://' . $name . '/', $blockMs);
        } finally {
            fclose($server);
        }
    }

    /**
     * The probe machinery pointed at an explicit URL — the seam that lets the
     * inconclusive path itself be tested (a refused connection must throw, not
     * read as "unhooked, delivered"). The listener is NOT accepted on, so the
     * kernel completes the handshake and never answers: the request costs
     * exactly `$blockMs` either way the hooks fall.
     *
     * @param string $url     Absolute http:// URL to block against.
     * @param int    $blockMs Length of the blocking window.
     *
     * @return array{ticks: int, blocked_ms: float}
     *
     * @throws HookDeliveryException the sample is inconclusive.
     */
    public static function probeAgainst(string $url, int $blockMs = self::BLOCK_MS): array
    {
        $ticks = 0;
        // Stop signal lives in a by-ref array, not a bare bool: the ticker
        // coroutine reads it while the caller's coroutine writes it, and a
        // plain `$sampling = true` looks provably-constant to level-9 flow
        // analysis ("while loop always true") even though the write is real.
        /** @var array{sampling: bool} $stop Cross-coroutine stop flag. */
        $stop = ['sampling' => true];

        Coroutine::create(static function () use (&$ticks, &$stop): void {
            while ($stop['sampling'] && $ticks < self::TICK_HARD_CAP) {
                $ticks++;
                Coroutine::sleep(self::TICK_INTERVAL_S);
            }
        });

        $handle = curl_init($url);
        if ($handle === false) {
            $stop['sampling'] = false;
            throw new HookDeliveryException(
                "hook-delivery probe could not init a cURL handle for {$url} — refusing to "
                . 'report delivery it did not measure'
            );
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => $blockMs,
            CURLOPT_TIMEOUT_MS => $blockMs,
            // A configured proxy would answer instantly and poison the sample.
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);

        $t0 = hrtime(true);
        $body = curl_exec($handle);
        $blockedMs = (hrtime(true) - $t0) / 1e6;

        // Snapshot BEFORE clearing the flag: the ticker holds the last wake in
        // its own loop test, so one tick already counted post-block cannot be
        // added to the snapshot, and one missed cannot remove it.
        $observed = $ticks;
        $stop['sampling'] = false;
        curl_close($handle);

        $inconclusive = $blockedMs < $blockMs * self::MIN_BLOCKED_FRACTION;
        if ($inconclusive) {
            throw new HookDeliveryException(sprintf(
                'hook-delivery probe inconclusive against %s: blocked only %.1fms of %dms (expected the '
                . 'full window — refused connection or an answering middlebox?), so the tick count %d '
                . 'proves nothing about whether the hooks landed. A probe that cannot decide must not '
                . 'report success.',
                $url,
                $blockedMs,
                $blockMs,
                $observed
            ));
        }
        if (is_string($body)) {
            // Never happens against the non-accepting listener; an answering
            // proxy could fake it. Loud either way — same discipline as above.
            throw new HookDeliveryException(sprintf(
                'hook-delivery probe inconclusive against %s: the listener answered a full response, '
                . 'which means something other than the probe is serving this address.',
                $url
            ));
        }

        return ['ticks' => $observed, 'blocked_ms' => $blockedMs];
    }

    /**
     * Verify the mask physically in force RIGHT NOW matches `$mask` — by
     * behaviour only. The observable is whether `curl_exec` yields; the
     * expectation is the curl-CLASS of the configured mask
     * ({@see SwooleRuntime::curlHookFlags()}), and the two ways that can
     * disagree are both true failures, stated loudly:
     *
     *  - the worker yields although the mask holds no curl bit → the allowlist
     *    did not land (the S433 defect itself);
     *  - the mask holds a curl bit yet the worker blocks → the accepted mask
     *    is INERT on this build (measured on the source-built box:
     *    `SWOOLE_HOOK_NATIVE_CURL` alone leaves `curl_exec` blocking; only the
     *    emulated `SWOOLE_HOOK_CURL` yields, ~59 ticks/120 ms) — an operator
     *    who "enabled curl hooks" that are not in force must hear about it,
     *    not run a green-configured worker that is not what they configured.
     *
     * A build that cannot install the requested handler at all (the CI PECL
     * swoole throws `Swoole\Exception` code 600 "curl_init func not exists"
     * from `enableCoroutine()` itself when asked for the emulated hook) fails
     * even earlier — inside {@see enforce()}, still loudly.
     *
     * @param int $mask The mask the configuration says is in force.
     *
     * @return array{ticks: int, blocked_ms: float} the sample the verdict was taken from.
     *
     * @throws HookDeliveryException the mask did not land (or the probe could not tell).
     */
    public static function verify(int $mask, int $blockMs = self::BLOCK_MS): array
    {
        $sample = self::probe($blockMs);
        $yielded = $sample['ticks'] >= self::YIELD_TICK_FLOOR;
        $expectsYield = ($mask & SwooleRuntime::curlHookFlags()) !== 0;

        if ($yielded !== $expectsYield) {
            throw new HookDeliveryException(sprintf(
                'coroutine hook delivery FAILED: config mask 0x%04x %s, but the worker %s '
                . '(%d sibling ticks in a %.1f ms window; yield floor %d). The reported option cannot '
                . 'tell these states apart — this behavioural probe is the verdict (S433).',
                $mask,
                $expectsYield
                    ? 'expects a yielding cURL (a cURL hook is in the mask)'
                    : 'expects a blocking cURL (no cURL hook is in the mask)',
                $yielded
                    ? 'is yielding, so a cURL hook is physically installed'
                    : 'is blocking, so no cURL hook is installed',
                $sample['ticks'],
                $sample['blocked_ms'],
                self::YIELD_TICK_FLOOR
            ));
        }

        return $sample;
    }

    /**
     * One worker-start step: replace the physical hook set with `$mask`, then
     * prove by behaviour that it landed. Throws on any non-delivery — see the
     * failure posture in the class docblock.
     *
     * @return array{ticks: int, blocked_ms: float}
     */
    public static function enforceAndVerify(int $mask, int $blockMs = self::BLOCK_MS): array
    {
        self::enforce($mask);

        return self::verify($mask, $blockMs);
    }
}
