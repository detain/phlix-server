<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support\Coroutine;

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use RuntimeException;

/**
 * S170 — the coroutine harness proves itself before anything relies on it.
 *
 * "A harness that cannot fail is worse than no harness." The three properties
 * asserted here are exactly the ones that would make {@see RunsInCoroutine}
 * fraudulent if they did not hold:
 *
 *  1. the body really runs with `getCid() > 0`, while the main stack is `-1`
 *     (the measurement the whole of S170 rests on);
 *  2. a value can be carried back out, so assertions can live on the main stack;
 *  3. a Throwable from the body — including a PHPUnit assertion failure — reaches
 *     PHPUnit instead of killing the process.
 *
 * ⚠ Property 3 is deliberately proved with EXPLICITLY THROWN exceptions rather
 * than with a failing `assertX()` call. A really-failed assertion emits PHPUnit's
 * `AssertionFailed` event, and the S120 escape guard then reports the test as
 * VACUOUS ("an assertion failed, the test did not") and reddens CI via
 * scripts/assertion-escape-check.php — measured, not guessed. Throwing the same
 * exception CLASS by hand goes through no assertion, emits no event, and pins the
 * same propagation path. The failing-assertion case was verified separately by
 * mutation (see the S169/S170 worklog entry).
 */
final class RunsInCoroutineTest extends TestCase
{
    use RunsInCoroutine;

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required for the coroutine harness tests.');
        }
    }

    public function testMainStackIsNotACoroutineButTheBodyIs(): void
    {
        // The premise of S170, as an executable fact rather than a claim: the
        // extension is loaded, and getCid() is still -1 out here.
        $this->assertTrue(extension_loaded('swoole'), 'swoole must be loaded for this measurement to mean anything');
        $this->assertSame(-1, \Swoole\Coroutine::getCid(), 'PHPUnit never runs inside a coroutine');

        $cid = $this->runInCoroutine(static fn (): int => \Swoole\Coroutine::getCid());

        $this->assertIsInt($cid);
        $this->assertGreaterThan(0, $cid, 'the harness body must run with a real coroutine id');
    }

    public function testTheBodyReturnValueComesBackOut(): void
    {
        $result = $this->runInCoroutine(static fn (): array => ['probe' => 'refused', 'n' => 7]);

        $this->assertSame(['probe' => 'refused', 'n' => 7], $result);
    }

    public function testAThrowableFromTheBodyReachesPhpunit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('escaped from the coroutine');

        $this->runInCoroutine(static function (): void {
            throw new RuntimeException('escaped from the coroutine');
        });
    }

    public function testAnAssertionFailureFromTheBodyReachesPhpunit(): void
    {
        // Same exception CLASS PHPUnit raises for a failed assertion — thrown by
        // hand so no AssertionFailed event fires (see the class docblock).
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('a failed assertion must not be lost');

        $this->runInCoroutine(static function (): void {
            throw new ExpectationFailedException('a failed assertion must not be lost');
        });
    }
}
