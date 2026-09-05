<?php

/**
 * S433 — static enforcement guard: the curated coroutine-hook allowlist must
 * be DELIVERED by the APIs that physically work, verified BEHAVIOURALLY, and
 * never again by a check that reports success either way.
 *
 * ## Why a static guard next to the behavioural test
 *
 * `CuratedHookDeliveryProbeTest` proves the shipped mechanism works on a real
 * Swoole runtime. This file proves the mechanism is the ONLY one wired into
 * the bootstrap, with rules that can never regress to the measured-lie shapes
 * — even on a box without ext-swoole (CI runs the suite headless; the probe
 * skips there). The exact trap, measured before any fix shipped (see the
 * `HookDelivery` class docblock):
 *
 *  - `Coroutine::set(['hook_flags' => X])` inside a coroutine updates the
 *    REPORTED option; already-installed handlers stay physically hooked.
 *  - `Coroutine::getOptions()['hook_flags']` then READS X in both the working
 *    and the broken state — the standard check that passed while the SIGSEGV
 *    mitigation reached no worker.
 *
 * So this guard tokenizes (comment-stripped) and pins, per rule:
 *   1. `start.php`'s `$applyCuratedCoroutineHooks` body calls
 *      `HookDelivery::enforceAndVerify(SwooleRuntime::runtimeHookMask($config))`
 *      and contains NEITHER `Coroutine::set` NOR `getOptions` — the lie-API is
 *      structurally gone from the worker path, and the escape-hatch mask still
 *      flows through resolution (config path preserved);
 *   2. `HookDelivery` itself never reads `getOptions` (that equality IS the
 *      planted-drift trap: replace the tick comparison with it and this rule
 *      reddens even before the behavioural test gets a chance), does call
 *      `Swoole\Runtime::enableCoroutine` (the only physically-effective API),
 *      drives the sibling ticker with `Coroutine::create`, and compares the
 *      sample against `YIELD_TICK_FLOOR` behind `HookDeliveryException` throws;
 *   3. the one silent-success hole among the six worker types — the background
 *      timer worker's whole-body `catch (\Throwable)` that `trigger_error`s
 *      best-effort — rethrows `HookDeliveryException` BEFORE that warning, so
 *      a no-delivery worker still dies loudly there;
 *   4. the census: exactly six per-worker call sites (http, ws, hub-heartbeat,
 *      background-timers, relay-tunnel, managed) — the same six the defect
 *      statement filed, pinned so a new worker type that forgets the hook
 *      reddens a number instead of shipping silently;
 *   5. the survival token is code-resident here and absent from the
 *      production bootstrap.
 *
 * @package Phlix\Tests\Unit\Server\Runtime
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Runtime;

use PHPUnit\Framework\TestCase;

final class HookAllowlistEnforcementGuardTest extends TestCase
{
    /** Survival token for S433 — must live in code, never in any *.md file. */
    public const SURVIVAL_TOKEN = 'S433HOOKALLOWLISTENFORCEDX7K2';

    private const START_PHP = __DIR__ . '/../../../../start.php';
    private const HOOK_DELIVERY = __DIR__ . '/../../../../src/Server/Runtime/HookDelivery.php';

    /** Comment-stripped, whitespace-normalized source — prose can lie, tokens can't. */
    private static function code(string $file): string
    {
        $stripped = php_strip_whitespace($file);
        self::assertNotFalse($stripped, "php_strip_whitespace({$file}) failed — empty/missing file?");

        return (string) preg_replace('/\s+/', ' ', $stripped);
    }

    /**
     * The body of `$applyCuratedCoroutineHooks` as it ships in start.php —
     * brace-matched from the closure's opening `{` (string literals in the
     * body carry no braces, so a plain depth walk over the comment-stripped,
     * whitespace-normalized text is exact).
     */
    private static function reassertClosureBody(string $start): string
    {
        $anchor = '$applyCuratedCoroutineHooks = static function';
        $at = strpos($start, $anchor);
        self::assertNotFalse($at, 'start.php must define the per-worker re-assert closure.');
        $open = strpos($start, '{', $at);
        self::assertNotFalse($open);

        $depth = 0;
        $len = strlen($start);
        for ($i = $open; $i < $len; $i++) {
            if ($start[$i] === '{') {
                $depth++;
            } elseif ($start[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($start, $open, $i - $open + 1);
                }
            }
        }

        self::fail('unterminated re-assert closure in start.php?');
    }

    public function test_worker_reassert_uses_the_delivery_probe_and_never_the_lie_api(): void
    {
        $body = self::reassertClosureBody(self::code(self::START_PHP));

        foreach (
            [
                'HookDelivery::enforceAndVerify' =>
                    'the only call site that both installs physically and behaviourally proves the mask',
                'SwooleRuntime::runtimeHookMask' =>
                    'the configured mask (incl. the coroutine.hook_flags escape hatch) must reach the probe',
                'Worker::log'                     => 'per-worker DELIVERY ACK: every worker that starts says so',
            ] as $needle => $why
        ) {
            self::assertStringContainsString(
                $needle,
                $body,
                'S433: the per-worker re-assert must contain ' . $needle . ' — ' . $why . '.'
            );
        }

        foreach (
            [
                'Coroutine::set' =>
                    'inside a coroutine this only updates the REPORTED mask — the measured silent-success shape',
                'getOptions' =>
                    'the reported option reads curated in BOTH the working and the broken state — the exact trap',
            ] as $forbidden => $why
        ) {
            self::assertStringNotContainsString(
                $forbidden,
                $body,
                'S433: the re-assert body may not reference ' . $forbidden . ' — ' . $why . '.'
            );
        }
    }

    public function test_delivery_probe_is_behavioural_not_reported(): void
    {
        $probe = self::code(self::HOOK_DELIVERY);

        self::assertStringNotContainsString(
            'getOptions',
            $probe,
            'S433: HookDelivery must never consult the reported mask — swapping the tick '
            . 'comparison for a getOptions() equality is the planted-drift trap this guard exists to end.'
        );
        foreach (
            [
                'Runtime::enableCoroutine' =>
                    'the full-mask replacement API — the only one that physically un-swaps handlers',
                'Coroutine::create' => 'the sibling ticker that counts yields',
                'Coroutine::sleep' => 'the ticker cadence',
                'YIELD_TICK_FLOOR' => 'the behavioural threshold the verdict is taken against',
                'new HookDeliveryException' => 'an inconclusive or no-delivery verdict must be a THROWN failure',
            ] as $needle => $why
        ) {
            self::assertStringContainsString(
                $needle,
                $probe,
                'S433: HookDelivery must contain ' . $needle . ' — ' . $why . '.'
            );
        }
    }

    public function test_background_timer_worker_reraises_hook_delivery_failure(): void
    {
        $start = self::code(self::START_PHP);

        $rethrow = strpos($start, 'instanceof \\Phlix\\Server\\Runtime\\HookDeliveryException');
        self::assertNotFalse(
            $rethrow,
            'S433: the background-timer worker catch must single HookDeliveryException out of its '
            . 'best-effort catch — an undelivered allowlist is the SIGSEGV condition, not a warning.'
        );
        self::assertStringContainsString(
            'throw $e;',
            substr($start, $rethrow, 300),
            'S433: the HookDeliveryException arm must immediately `throw $e;`.'
        );

        $warning = strpos($start, "'Background timer worker failed to start: '");
        self::assertNotFalse($warning, 'the best-effort warning must still exist for NON-delivery failures');
        self::assertLessThan(
            $warning,
            $rethrow,
            'S433: the delivery rethrow must come BEFORE the best-effort trigger_error, or the '
            . 'warning past it re-opens the silent-success hole in this one worker type.'
        );
        self::assertLessThan(600, $warning - $rethrow, 'the rethrow and the catch it guards drifted apart?');
    }

    public function test_every_worker_type_calls_the_reassert_exactly_once(): void
    {
        $start = self::code(self::START_PHP);

        self::assertSame(
            6,
            substr_count($start, '$applyCuratedCoroutineHooks();'),
            'S433: the curated-mask re-assert must run in exactly six onWorkerStart bodies '
            . '(http, ws, hub-heartbeat, background-timers, relay-tunnel, managed) — a seventh '
            . 'worker type added without it, or a delivery point removed, is the census this pins.'
        );
    }

    public function test_survival_token_is_code_resident_and_absent_from_production_source(): void
    {
        self::assertStringContainsString(
            self::SURVIVAL_TOKEN,
            (string) file_get_contents(__FILE__),
            'S433 survival token vanished from its carrier file.'
        );
        self::assertStringNotContainsString(
            self::SURVIVAL_TOKEN,
            self::code(self::START_PHP),
            'S433 token must never ship in the bootstrap.'
        );
        self::assertStringNotContainsString(
            self::SURVIVAL_TOKEN,
            self::code(self::HOOK_DELIVERY),
            'S433 token must never ship in the probe.'
        );
    }
}
