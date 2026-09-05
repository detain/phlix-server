<?php

/**
 * S433 — the BEHAVIOURAL delivery proof for the curated coroutine-hook
 * allowlist: this file is why the standard check can never again report
 * success whether or not the allowlist landed.
 *
 * ## What the suite refuses to trust
 *
 * `Swoole\Coroutine::getOptions()['hook_flags']` reports the curated `0x42fe`
 * after ANY re-assert, delivered or not — the harness below reproduces exactly
 * that lie (the old-remedy test) and pins both sides of it. So every verdict
 * here comes from {@see HookDelivery::probe()}: a sibling coroutine ticking
 * every ~2 ms around a cURL to a local listener that never answers. Ticks in
 * the window ⇒ a cURL hook is physically installed ⇒ the allowlist did NOT
 * land. No ticks while the request still costs the full window ⇒ handlers
 * really were un-swapped ⇒ the allowlist IS in force.
 *
 * ## The planted-drift anchor
 *
 * `test_the_old_in_coroutine_set_lies_and_the_probe_catches_it()` asserts the
 * pair of facts that makes S433 a bug: after the SHIPPED pre-S433 remedy line
 * (`Coroutine::set(['hook_flags' => curated])` inside the worker coroutine),
 * the REPORTED option equals curated AND the probe still sees yielding. That
 * is precisely the state a `getOptions()`-equality delivery check would grade
 * GREEN. Swap {@see HookDelivery::verify()}'s tick comparison for such an
 * equality (the exact trap) and this test's `verify()` call stops throwing →
 * RED. `test_enforce_delivers_the_curated_mask_and_verify_stays_green()` is
 * its green mirror: after `enforce()`, the same `verify()` passes on the same
 * harness — real delivery stays green.
 *
 * @package Phlix\Tests\Unit\Server\Runtime
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Runtime;

use Phlix\Server\Runtime\HookDelivery;
use Phlix\Server\Runtime\HookDeliveryException;
use Phlix\Server\Runtime\SwooleRuntime;
use PHPUnit\Framework\TestCase;

final class CuratedHookDeliveryProbeTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        // Process-wide contamination guard (measured the hard way in CI): leave the
        // PHYSICAL hook set as a virgin process is actually born with — nothing
        // installed. Restoring the value `getOptions()['hook_flags']` reported at
        // class entry is the trap this whole step exists to teach: a fresh process
        // REPORTS SWOOLE_HOOK_ALL as the option default while no handlers are
        // installed, so restoring that number would physically enable
        // FILE/PROC/CURL for every later test in the randomised order (CI observed:
        // proc_open in AccessScheduleHeadNoBodyWireTest fataling "API must be
        // called in the coroutine").
        if (extension_loaded('swoole')) {
            \Swoole\Runtime::enableCoroutine(0);
        }
    }

    protected function setUp(): void
    {
        if (!extension_loaded('swoole') || !function_exists('curl_init')) {
            self::markTestSkipped('The behavioural hook probe needs ext-swoole AND ext-curl.');
        }
        if (SwooleRuntime::safeHookFlags() === 0) {
            self::markTestSkipped('No SWOOLE_HOOK_* constants — nothing to deliver.');
        }
    }

    /** The curated allowlist, exactly as a default-config worker resolves it. */
    private static function curated(): int
    {
        return SwooleRuntime::safeHookFlags();
    }

    /**
     * Put the process in the state the Workerman Swoole adapter's constructor
     * produces in a fresh worker child — cURL handlers PHYSICALLY installed —
     * independent of whatever earlier tests in this phpunit process did.
     *
     * The vendor line is `Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL])`.
     * In a fresh child that is also a physical install (that is the shipped
     * defect's mechanism, reproduced by this suite's first draft literally
     * constructing the adapter — see the S433 record). But `Coroutine::set()`
     * only ever ADDs to the reported mask and its install behaviour is defined
     * against the last state `Runtime::enableCoroutine()` saw, so in a shared
     * phpunit process the faithful, order-proof reproduction is the same
     * physical end state via the authoritative API: unhook everything, then
     * install ALL.
     */
    private static function installAllLikeTheAdapterConstructor(): void
    {
        \Swoole\Runtime::enableCoroutine(0);
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    }

    public function test_the_adapter_constructor_state_physically_hooks_curl(): void
    {
        self::installAllLikeTheAdapterConstructor();

        $thrown = null;
        $sample = null;
        \Swoole\Coroutine\run(static function () use (&$sample, &$thrown): void {
            try {
                $sample = HookDelivery::probe();
            } catch (\Throwable $e) {
                $thrown = $e;
            }
        });

        self::assertNull($thrown, 'the probe itself must run clean: ' . ($thrown?->getMessage() ?? ''));
        self::assertIsArray($sample);
        self::assertGreaterThanOrEqual(
            HookDelivery::YIELD_TICK_FLOOR,
            $sample['ticks'],
            'right after the adapter constructor a cURL hook must be PHYSICALLY installed '
            . '(sibling ticks during the blocking window) — if this reads zero the probe cannot '
            . 'sense the bug state and every assertion below it is vacuous.'
        );
    }

    public function test_the_old_in_coroutine_set_lies_and_the_probe_catches_it(): void
    {
        self::installAllLikeTheAdapterConstructor();
        $curated = self::curated();

        $thrown = null;
        $sample = null;
        $reportedMask = -1;
        $verifyThrew = false;
        $verifyMessage = '';
        $flow = static function () use (
            $curated,
            &$sample,
            &$thrown,
            &$reportedMask,
            &$verifyThrew,
            &$verifyMessage
        ): void {
            try {
                // The EXACT pre-S433 remedy line from start.php:150 (at filing).
                \Swoole\Coroutine::set(['hook_flags' => $curated]);

                // Side 1 of the lie: the reported option shows curated — the
                // check that "verified" the mitigation and passed while it was
                // NOT in force.
                $reportedMask = (int) (\Swoole\Coroutine::getOptions()['hook_flags'] ?? -1);

                // Side 2 of the truth: the handlers are still installed.
                $sample = HookDelivery::probe();

                // And the delivery assertion under test must reject this
                // state. If verify() ever degrades to the getOptions()
                // equality above, it stops throwing HERE — this is the
                // planted-drift RED line.
                try {
                    HookDelivery::verify($curated);
                } catch (HookDeliveryException $e) {
                    $verifyThrew = true;
                    $verifyMessage = $e->getMessage();
                }
            } catch (\Throwable $e) {
                $thrown = $e;
            }
        };
        \Swoole\Coroutine\run($flow);

        self::assertNull($thrown, 'the harness must run clean: ' . ($thrown?->getMessage() ?? ''));
        self::assertSame(
            $curated,
            $reportedMask,
            'this pin documents the trap: getOptions() reports the re-asserted mask. A delivery '
            . 'check built on this equality would grade the bug state as SUCCESS.'
        );
        self::assertIsArray($sample);
        self::assertGreaterThanOrEqual(
            HookDelivery::YIELD_TICK_FLOOR,
            $sample['ticks'],
            'after the old in-coroutine re-assert the cURL must still be physically HOOKED — '
            . 'this is the exact silent-success state S433 closes.'
        );
        self::assertTrue(
            $verifyThrew,
            'verify() must reject the in-coroutine re-assert state (reported curated, physically '
            . 'hooked) — accepting it would BE the S433 silent-success defect.'
        );
        self::assertStringContainsString('delivery FAILED', $verifyMessage);
    }

    public function test_enforce_delivers_the_curated_mask_and_verify_stays_green(): void
    {
        self::installAllLikeTheAdapterConstructor();
        $curated = self::curated();

        $thrown = null;
        $sample = null;
        \Swoole\Coroutine\run(static function () use ($curated, &$sample, &$thrown): void {
            try {
                $sample = HookDelivery::enforceAndVerify($curated);
            } catch (\Throwable $e) {
                $thrown = $e;
            }
        });

        self::assertNull(
            $thrown,
            'enforce+verify of the curated mask must pass on this box (a failure here is a real '
            . 'delivery regression, not drift): ' . ($thrown?->getMessage() ?? '')
        );
        self::assertIsArray($sample);
        self::assertLessThan(
            HookDelivery::YIELD_TICK_FLOOR,
            $sample['ticks'],
            'after enforce() the cURL must be physically UNHOOKED: the blocking window may not '
            . 'yield sibling ticks.'
        );
        self::assertGreaterThanOrEqual(
            HookDelivery::BLOCK_MS * 0.5,
            $sample['blocked_ms'],
            'the request must have spent its window actually blocked.'
        );
    }

    /**
     * Which cURL bit — if any — does THIS Swoole build let the probe observe?
     * Measured (S433): the source-built box honours the emulated
     * `SWOOLE_HOOK_CURL` (0x800, ~59 ticks) and treats `NATIVE_CURL` alone as
     * inert (1 tick); the CI PECL build refuses to install the emulated hook
     * at runtime altogether (`Swoole\Exception` 600 from enableCoroutine).
     * Candidates are therefore MEASURED, not assumed: accepted by
     * `enableCoroutine()` AND observed yielding. Leaves hooks in whatever state
     * the winning measurement ended in; the class teardown zeroes them.
     */
    private static function observableCurlBit(int $curated): int
    {
        $candidates = array_values(array_unique(array_filter([
            defined('SWOOLE_HOOK_CURL') ? (int) SWOOLE_HOOK_CURL : 0,
            defined('SWOOLE_HOOK_NATIVE_CURL') ? (int) SWOOLE_HOOK_NATIVE_CURL : 0,
        ])));

        foreach ($candidates as $bit) {
            $ticks = -1;
            \Swoole\Runtime::enableCoroutine(0);
            \Swoole\Coroutine\run(static function () use ($curated, $bit, &$ticks): void {
                try {
                    \Swoole\Runtime::enableCoroutine($curated | $bit);
                    $ticks = HookDelivery::probe(120)['ticks'];
                } catch (\Throwable) {
                    $ticks = -1;
                }
            });
            if ($ticks >= HookDelivery::YIELD_TICK_FLOOR) {
                return $bit;
            }
        }

        return 0;
    }

    public function test_the_escape_hatch_mask_lands_and_mismatches_fail_loud_both_ways(): void
    {
        $curated = self::curated();
        $curlBit = self::observableCurlBit($curated);
        if ($curlBit === 0) {
            self::markTestSkipped(
                'this Swoole build cannot deliver a probe-observable cURL hook (measured, not '
                . 'assumed — see observableCurlBit); the negative side of the two-sided check '
                . 'still runs in test_a_config_claiming_a_curl_hook_over_a_blocking_worker_fails_loud'
            );
        }
        $withCurl = $curated | $curlBit;

        $thrown = null;
        $hatchSample = null;
        $mismatchThrew = false;
        $flow = static function () use (
            $curated,
            $withCurl,
            &$hatchSample,
            &$mismatchThrew,
            &$thrown
        ): void {
            try {
                // An operator mask that WANTS curl hooked must verify green —
                // delivery is asserted against the CONFIGURED mask, not a
                // hardcoded one (this is the `coroutine.hook_flags` path).
                $hatchSample = HookDelivery::enforceAndVerify($withCurl);

                // And the same physical state must fail verification when the
                // config claims the curated (curl-free) mask: the check is
                // two-sided, config and behaviour have to agree.
                try {
                    HookDelivery::verify($curated);
                } catch (HookDeliveryException $e) {
                    $mismatchThrew = true;
                }
            } catch (\Throwable $e) {
                $thrown = $e;
            }
        };
        \Swoole\Coroutine\run($flow);

        self::assertNull($thrown, 'the hatch enforce must run clean: ' . ($thrown?->getMessage() ?? ''));
        self::assertIsArray($hatchSample);
        self::assertGreaterThanOrEqual(
            HookDelivery::YIELD_TICK_FLOOR,
            $hatchSample['ticks'],
            'an operator mask that includes the observable cURL bit must PROVE it landed (yielding).'
        );
        self::assertTrue(
            $mismatchThrew,
            'verify(curated) over a physically curl-hooked worker must throw — a mask that '
            . 'reports one thing and does another is the defect, in either direction.'
        );
    }

    public function test_a_config_claiming_a_curl_hook_over_a_blocking_worker_fails_loud(): void
    {
        // The build-independent half of the escape-hatch proof: no cURL handler
        // is ever INSTALLED here — the config merely CLAIMS one while the worker
        // physically blocks. That mismatch must throw on every Swoole (the CI
        // PECL build, which refuses runtime cURL-hook installation, included).
        $curated = self::curated();
        $claimed = $curated | SwooleRuntime::curlHookFlags();
        if (($claimed & ~$curated) === 0) {
            self::markTestSkipped('this build exposes no cURL-class bits to mis-claim');
        }

        $cleanThrew = false;
        $mismatchThrew = false;
        $thrown = null;
        $flow = static function () use ($curated, $claimed, &$cleanThrew, &$mismatchThrew, &$thrown): void {
            try {
                HookDelivery::enforceAndVerify($curated);
                try {
                    HookDelivery::verify($claimed);
                } catch (HookDeliveryException $e) {
                    $mismatchThrew = true;
                }
            } catch (HookDeliveryException $e) {
                $cleanThrew = true;
                $thrown = $e;
            } catch (\Throwable $e) {
                $thrown = $e;
            }
        };
                \Swoole\Coroutine\run($flow);

        self::assertFalse(
            $cleanThrew,
            'the delivered-curated baseline must be green: ' . ($thrown?->getMessage() ?? '')
        );
        self::assertNull($thrown, 'the harness must run clean: ' . ($thrown?->getMessage() ?? ''));
        self::assertTrue(
            $mismatchThrew,
            'config claims a yielding cURL, the worker blocks — the check must refuse that pair.'
        );
    }

    public function test_probe_refuses_a_sample_that_never_blocked(): void
    {
        // A refused connection returns instantly with ~0 ticks and would LOOK
        // "delivered" to a tick-count-only check. It must throw instead:
        // reports-success-either-way is the defect class.
        $thrown = null;
        \Swoole\Coroutine\run(static function () use (&$thrown): void {
            try {
                // Port 1: nothing listens; connect is refused immediately.
                HookDelivery::probeAgainst('http://127.0.0.1:1/', 120);
            } catch (\Throwable $e) {
                $thrown = $e;
            }
        });

        self::assertInstanceOf(
            HookDeliveryException::class,
            $thrown,
            'an inconclusive sample must throw, never be scored: ' . ($thrown?->getMessage() ?? '(no throw)')
        );
        self::assertStringContainsString('inconclusive', strtolower((string) $thrown?->getMessage()));
    }

    public function test_probe_demands_a_coroutine_context(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('inside a Swoole coroutine');

        // Top level here is OUTSIDE any coroutine — a probe there would tickle
        // nothing and "pass" vacuously.
        HookDelivery::probe();
    }
}
