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
 *   3. the one silent-success hole among the worker types — the background
 *      timer worker's whole-body `catch (\Throwable)` that `trigger_error`s
 *      best-effort — rethrows `HookDeliveryException` BEFORE that warning, so
 *      a no-delivery worker still dies loudly there;
 *   4. the census: exactly seven per-worker call sites (http, ws, hub-heartbeat,
 *      background-timers, relay-tunnel, managed, natpmp-maintenance) — the same
 *      six the defect statement filed, plus the NAT-PMP maintenance worker added
 *      by the full-RFC-citizenship lane (2026-10-01), which re-asserts the mask
 *      in its own onWorkerStart and rethrows HookDeliveryException like the
 *      background-timer worker; pinned so a new worker type that forgets the
 *      hook reddens a number instead of shipping silently;
 *   5. the survival token is code-resident here and absent from the
 *      production bootstrap;
 *   6. (F-1c, rotating the F-1b narrowing rule) the relay-tunnel worker is
 *      the ONE role born unhooked: its registration binds
 *      `$worker->eventLoop = Select::class` before `onWorkerStart`, the
 *      re-assert closure role-gates itself OFF whenever the loop is Select
 *      (the bare call census of rule 4 stays intact — the gate is in the
 *      shared body, never by deleting wiring), and the MASTER never calls
 *      `Runtime::enableCoroutine` — the pre-fork enable made the swap
 *      inheritable and thereby poisoned the only regime measured to complete
 *      TLS on the production host (mask matrix: per-bit exclusion INERT,
 *      Select+inherited-hooks crash-loop; Select+never-hooked COMPLETES).
 *      The bootstrap-wide absence of any `relayTunnelHookMask` wiring is
 *      pinned too: the helper stays belt-only (unit-tested), unwired;
 *   7. (F-1c minors round M2 — convicts reviewer probe MT-D, which slipped all
 *      six original clauses with 0 reds) the Select override is EXCLUSIVE to
 *      the relay-tunnel registration: the only `$var->eventLoop =` assignment
 *      target in the bootstrap is `$relayTunnelWorker`, and Select is bound
 *      exactly once. A second per-worker override would silently opt that role
 *      out of curated-mask delivery through the rule-6 gate while rule 4's
 *      bare-call census still counted its no-op call;
 *   8. (F-1c minors round M3) the bootstrap text never references the
 *      `Workerman\Coroutine` namespace — a master-path load would freeze the
 *      Swoole context driver before the child flips the loop to Select and
 *      crash-loop every relay child at boot (load-order invariant; see the
 *      method docblock).
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
            7,
            substr_count($start, '$applyCuratedCoroutineHooks();'),
            'S433: the curated-mask re-assert must be CALLED from exactly seven onWorkerStart '
            . 'bodies (http, ws, hub-heartbeat, background-timers, relay-tunnel, managed, '
            . 'natpmp-maintenance) — an eighth worker type added without it, or a delivery '
            . 'point removed, is the census this pins. Re-pinned 6→7 by the NAT-PMP '
            . 'full-RFC-citizenship lane (2026-10-01), which added the seventh body WITH '
            . 'the re-assert, measured from the phpunit red. F-1c note: the call site is '
            . 'the census unit; the relay-tunnel call executes the shared body only for '
            . 'hooked children — the body role-gates Select workers out (rule 6).'
        );
    }

    /**
     * F-1c (rotating the F-1b narrowing site, measured-red first): the cure
     * regime proven on the production host is Select-loop + NEVER-HOOKED
     * (per-bit mask exclusion is inert — the openssl function swap is sticky
     * once any hook is live; and Select with inherited hooks crash-loops
     * 'API must be called in the coroutine'). So: the relay-tunnel
     * registration binds Select BEFORE onWorkerStart; the shared re-assert
     * closure role-gates itself off for Select children (rule 4's bare-call
     * census survives — gating is in the body, not by deleting wiring); the
     * master never enables coroutine hooks pre-fork (children install
     * physically themselves: the Swoole adapter constructor plus each
     * non-relay worker's enforceAndVerify); and the F-1b bootstrap narrowing
     * is gone (helper belt-only). Every clause reddens if weakened: strip
     * the gate → the closure-regex asserts; re-add master enableCoroutine →
     * the notContains assert; delete the Select binding → the registration
     * regex; re-wire the narrowing → the count asserts.
     */
    public function test_relay_tunnel_worker_is_born_unhooked_on_select(): void
    {
        $start = self::code(self::START_PHP);
        $body = self::reassertClosureBody($start);

        self::assertMatchesRegularExpression(
            '/\$relayTunnelWorker->eventLoop = Select::class;.*?\$relayTunnelWorker->onWorkerStart/s',
            $start,
            'F-1c: the relay-tunnel registration must bind the Select event loop before onWorkerStart'
        );

        self::assertMatchesRegularExpression(
            '/if \(Worker::\$eventLoopClass === Select::class\) \{ return; \}/',
            $body,
            'F-1c: the re-assert closure must role-gate Select children out of hook delivery entirely'
        );
        $gate = strpos($body, 'Worker::$eventLoopClass === Select::class');
        $enforce = strpos($body, 'HookDelivery::enforceAndVerify');
        self::assertNotFalse($gate);
        self::assertNotFalse($enforce);
        self::assertLessThan($enforce, $gate, 'F-1c: the role gate must precede the delivery call.');

        self::assertStringContainsString(
            'Worker::$eventLoopClass = \Workerman\Events\Swoole::class;',
            $start,
            'F-1c: every OTHER worker role keeps the Swoole loop — the master assigns it'
            . ' globally and ONLY the relay-tunnel registration deviates via its own'
            . ' $worker->eventLoop override. Swapping the global assignment to Select'
            . ' would silently unhook the http/ws/heartbeat/timer/managed/natpmp workers.'
        );

        self::assertStringNotContainsString(
            'Runtime::enableCoroutine',
            $start,
            'F-1c: the master must NEVER pre-enable coroutine hooks — a hooked master'
            . ' poisons the relay child (sticky swap; Select+hooks crash-loops). Children'
            . ' install physically via HookDelivery (S433).'
        );

        self::assertSame(
            0,
            substr_count($start, 'relayTunnelHookMask'),
            'F-1c: the F-1b narrowing is unwired from the bootstrap — the helper is belt-only'
        );
    }

    /**
     * F-1c minors round, law 7 (M2; red-first via reviewer probe MT-D). Rule 6
     * pins that the relay-tunnel registration binds Select — but alone it is
     * silent about a SECOND per-worker override anywhere else: adding
     * `$httpWorker->eventLoop = Select::class;` passed all six original
     * clauses (measured 0 reds in the review clone). The role gate keys on the
     * global Worker::$eventLoopClass, so such a copy would silently opt an
     * otherwise-hooked worker out of curated-mask delivery while rule 4's
     * bare-call census still counted its now-inert call.
     *
     * Shape honesty: a static scan cannot know which runtime role a variable
     * names, so the enforceable form is the assignment TARGET text — every
     * `$var->eventLoop =` in the bootstrap must be the relay-tunnel
     * registration variable. Renaming that variable reddens this law together
     * with rule 6's binding regex (same contract, same name), and a rename
     * that kept both in step would be visible at review — the same trust
     * budget every other clause here spends on the file's own identifiers.
     */
    public function test_select_override_is_exclusive_to_the_relay_tunnel_worker(): void
    {
        $start = self::code(self::START_PHP);

        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)->eventLoop\s*=/', $start, $targets);
        $distinct = array_values(array_unique($targets[1]));
        self::assertSame(
            ['relayTunnelWorker'],
            $distinct,
            'F-1c/M2: the per-worker eventLoop override belongs to the relay-tunnel'
            . ' registration ALONE — every other role keeps the Swoole loop the master'
            . ' assigns globally (rule 6). A second override silently opts that worker'
            . ' out of curated-mask delivery through the role gate (probe MT-D).'
            . ' Offending target(s): ' . implode(', ', $distinct) . '.'
        );

        self::assertSame(
            1,
            substr_count($start, '->eventLoop = Select::class;'),
            'F-1c/M2: the Select binding must appear EXACTLY once in the bootstrap —'
            . ' one override, one role.'
        );
    }

    /**
     * F-1c minors round, law 8 (M3; red-first via a master-path class_exists
     * injection). The relay child boots crash-free only while
     * `Workerman\Coroutine\Context` FIRST AUTOLOADS inside the child — i.e.
     * after `forkOneWorkerForLinux()` has flipped `Worker::$eventLoopClass` to
     * Select from `$worker->eventLoop` (vendor workerman v5.2.3
     * Worker.php:1777-1778). Context.php memoizes its driver (`??=`) from that
     * SAME static at first load: had the class loaded on the master path
     * (static still Swoole), the flip would inherit Context\Swoole while
     * run()'s Fiber branch still executes `Context::destroy()` — calling
     * `Coroutine::getContext()->exchangeArray([])` outside a coroutine, an
     * exchangeArray-on-null crash-loop before the worker ever serves (the
     * invariant documented at start.php:204-213; no belt can catch it — the
     * child dies at boot).
     *
     * Scope honesty: this pins start.php's own comment-stripped text —
     * `use` imports, qualified calls, and string literals (class_exists /
     * autoload forms) all reference the namespace. It does NOT (and cannot)
     * pin vendor autoload ordering: a future vendor file that loads Context at
     * require time is a review-visible dependency change, and Timer, Select
     * and TcpConnection were verified coroutine-load-free at the F-1c review.
     */
    public function test_bootstrap_never_loads_the_coroutine_namespace(): void
    {
        $start = self::code(self::START_PHP);

        // One-or-more literal backslashes between the two words: convicts
        // `Workerman\Coroutine` (import/FQCN) and `Workerman\\Coroutine`
        // (double-quoted literal text) alike; comment stripping already ran,
        // so prose can never convict this law.
        preg_match_all('/Workerman\\\\+Coroutine/', $start, $references);
        self::assertSame(
            [],
            array_values(array_unique($references[0])),
            'F-1c/M3: the master-path bootstrap must never reference the'
            . ' Workerman\\Coroutine namespace — Context memoizes its driver from'
            . ' Worker::$eventLoopClass at first load, so a master-time load hands'
            . ' the Select relay child the Swoole driver and its boot crash-loops on'
            . ' exchangeArray()-on-null (start.php:204-213).'
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
