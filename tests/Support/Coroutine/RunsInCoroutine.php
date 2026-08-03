<?php

/**
 * Phlix media server test support: running a test body inside a Swoole coroutine.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Support\Coroutine;

use PHPUnit\Framework\Assert;
use Throwable;

/**
 * S170 — runs a closure inside a real Swoole coroutine and gets its result back out.
 *
 * ## THE PROBLEM THIS EXISTS FOR
 *
 * ~23 files in `src/` fork on `Swoole\Coroutine::getCid() > 0` (usually via a
 * one-line private `inCoroutine()` helper) to pick a non-blocking implementation
 * in a Swoole worker and a blocking one otherwise. **PHPUnit never runs inside a
 * coroutine** — the extension is LOADED, but there is no coroutine, so
 * `getCid()` returns `-1` (measured on this box: `-1` on the main stack, `1`
 * inside `Swoole\Coroutine\run`). Every such test therefore takes the FALLBACK,
 * and the branch a production worker actually executes is unexecuted by the
 * suite. S169 is what that costs: `StunClient::testPortAccessibility()`'s
 * coroutine arm had two `return true` statements and so could not answer "no",
 * for the whole life of the method, with a green suite.
 *
 * ## THE LANDMINE, MEASURED — AN ESCAPING THROWABLE IS FATAL, NOT A FAILURE
 *
 * A naked assertion inside `Swoole\Coroutine\run` does not fail the test. It
 * kills the PHP process:
 *
 *     PHP Fatal error:  Uncaught PHPUnit\Framework\ExpectationFailedException:
 *     ... #4 [internal function]: {closure}() #5 {main}
 *
 * With `executionOrder="random"` the rest of the suite simply never runs. The
 * same is true of any other exception — a `RuntimeException` thrown in the body
 * is an uncaught exception in that coroutine, not something PHPUnit can attribute
 * to a test. So this helper catches every `Throwable` inside the coroutine and
 * re-throws it on the main stack, where PHPUnit sees it as that test's failure
 * (verified: catch-and-rethrow of a failed assertion produces a normal `F`).
 *
 * ## HOW TO USE IT — ASSERT OUTSIDE, NOT INSIDE
 *
 *     $result = $this->runInCoroutine(fn () => $client->probePort('127.0.0.1', $port));
 *     $this->assertSame(PortProbeOutcome::Refused, $result);
 *
 * Return what you want to check and assert on the main stack. That is the
 * established shape in this repo (see CoroutineChannelWaitTest) and it keeps
 * assertions out of a callback entirely, which the S120 assertion-escape guard
 * also wants. Putting `$this->assertX()` inside the closure is not forbidden and
 * will now fail the right test rather than the whole run — but the failure is
 * routed through a `catch (Throwable)`, so prefer the shape above.
 *
 * ⚠ Entering a coroutine is NOT the same as proving the code under test took its
 * coroutine branch. This helper asserts that its own body ran with `getCid() > 0`
 * — that is necessary, not sufficient. To show the production fork flipped, make
 * the branch observable (StunClient logs a `transport` field for exactly this
 * reason) or mutate the coroutine arm and watch the named test go red.
 *
 * ⚠ A PHP WARNING raised inside the coroutine cannot be attributed to a test.
 * Measured while mutation-testing S169: forcing StunClient's BLOCKING arm to run
 * inside the coroutine turned three tests into
 * `PHPUnit\Event\Code\NoTestCaseObjectOnCallStackException: Cannot find TestCase
 * object on call stack`, raised from the `fsockopen()` line. PHPUnit's error
 * handler walks the PHP call stack for the enclosing TestCase and a coroutine has
 * its own stack, so any diagnostic — even from an `@`-suppressed call — errors the
 * test out instead of failing it with something readable. So: run only the
 * NON-BLOCKING implementation inside this helper. If the code under test raises
 * warnings, that error message is the symptom, not a bug in the harness.
 *
 * The S120 escape guard is satisfied by all of this: a failing assertion inside
 * the body does decide the test's outcome via the re-throw, so it emits one
 * `AssertionFailed` for one failed test and no escape is recorded (verified by
 * running a deliberately-false assertion through it and checking
 * scripts/assertion-escape-check.php stayed clean).
 */
trait RunsInCoroutine
{
    /**
     * Runs $body inside a Swoole coroutine and returns its value.
     *
     * Skips the test when ext-swoole is absent. Any Throwable from $body is
     * re-thrown here, on the main stack.
     *
     * @param callable(): mixed $body Body to execute inside the coroutine.
     *
     * @return mixed Whatever $body returned.
     */
    protected function runInCoroutine(callable $body): mixed
    {
        if (!extension_loaded('swoole')) {
            Assert::markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $entered = false;
        $cid = -1;
        $result = null;
        $escaped = null;

        \Swoole\Coroutine\run(
            static function () use ($body, &$entered, &$cid, &$result, &$escaped): void {
                $entered = true;
                $cid = \Swoole\Coroutine::getCid();
                try {
                    $result = $body();
                } catch (Throwable $e) {
                    // Captured, never handled here: re-thrown on the main stack
                    // below so PHPUnit attributes it to this test instead of the
                    // process dying with an uncaught exception.
                    $escaped = $e;
                }
            }
        );

        if ($escaped !== null) {
            throw $escaped;
        }

        Assert::assertTrue($entered, 'Swoole\Coroutine\run must have executed the body.');
        Assert::assertGreaterThan(
            0,
            $cid,
            'the body must run with getCid() > 0, i.e. really inside a coroutine — '
            . 'that is the whole point of this helper (S170).'
        );

        return $result;
    }
}
