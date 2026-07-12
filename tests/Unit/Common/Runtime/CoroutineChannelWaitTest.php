<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Runtime;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Runtime\WorkerContext;

/**
 * SV-0.4 — exercises the Swoole\Coroutine\Channel cooperative-wait mechanism the
 * four HTTP clients (MetadataHttpClient::requestAsync, WebhookHttpClient::postAsync,
 * S3Client::requestAsync) rely on: a `Channel(1)` popped with a timeout, woken by
 * a `push(true)` from the async success/error callback.
 *
 * Strategy: this box HAS the Swoole extension, so we run a REAL coroutine test
 * (`Swoole\Coroutine\run`) rather than the routing-invariant fallback. The
 * clients' private request methods need a live Workerman\Http\Client + event
 * loop that a bare coroutine cannot provide, so we drive the Channel/callback
 * pattern directly — it is byte-for-byte what the client callbacks do
 * (`$channel->push(true)` on completion, `$channel->pop($timeout)` to wait).
 *
 * We deliberately do NOT call Channel::pop() outside a coroutine: that is the
 * exact false-timeout bug, and it emits a PHP warning that would (correctly)
 * fail the suite. Instead {@see WorkerContext::inCoroutine()} guards that call
 * — asserted here to flip false→true across the coroutine boundary.
 *
 * @requires extension swoole
 */
final class CoroutineChannelWaitTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required for the coroutine Channel wait test.');
        }
    }

    /**
     * The guard that routes clients to the Channel path must be false on the
     * main stack and true inside a Swoole coroutine. This is the discriminator
     * that keeps Channel::pop() reachable ONLY with getCid() > 0.
     */
    public function testInCoroutineGuardFlipsInsideCoroutine(): void
    {
        $this->assertFalse(WorkerContext::inCoroutine(), 'main stack: not a coroutine');

        $inside = null;
        \Swoole\Coroutine\run(static function () use (&$inside): void {
            $inside = WorkerContext::inCoroutine();
        });

        $this->assertTrue($inside, 'inCoroutine() must be true inside a Swoole coroutine (getCid() > 0).');
    }

    /**
     * (a) The waiter wakes as soon as the callback pushes — mirroring the async
     * success handler — and returns the pushed value well before the timeout,
     * with the shared $state populated.
     */
    public function testChannelWaiterWakesOnCallbackPush(): void
    {
        $popResult = null;
        $state = ['response' => null, 'error' => null];
        $elapsedMs = null;

        \Swoole\Coroutine\run(static function () use (&$popResult, &$state, &$elapsedMs): void {
            $channel = new \Swoole\Coroutine\Channel(1);

            // Mirror the client's async 'success' callback firing from the loop.
            \Swoole\Coroutine\go(static function () use ($channel, &$state): void {
                \Swoole\Coroutine::sleep(0.01);
                $state['response'] = 'OK';
                $channel->push(true);
            });

            $start = hrtime(true);
            $popResult = $channel->pop(2.0);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;
        });

        $this->assertTrue($popResult, 'pop() must return the pushed value (woke on callback).');
        $this->assertSame('OK', $state['response'], 'callback state must be visible to the waiter.');
        $this->assertNotNull($elapsedMs);
        $this->assertLessThan(2000.0, $elapsedMs, 'must wake on the push, not run out the full timeout.');
    }

    /**
     * (b) With no push, the waiter returns a clean timeout: pop() yields false
     * AFTER actually waiting the timeout window — not the immediate false a
     * non-coroutine (invalid) Channel::pop() would return. This is the clean
     * timeout the clients translate into a null/error result.
     */
    public function testChannelWaiterReturnsFalseOnTimeout(): void
    {
        $popResult = null;
        $elapsedMs = null;

        \Swoole\Coroutine\run(static function () use (&$popResult, &$elapsedMs): void {
            $channel = new \Swoole\Coroutine\Channel(1);

            $start = hrtime(true);
            $popResult = $channel->pop(0.05); // nobody pushes → clean timeout
            $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;
        });

        $this->assertFalse($popResult, 'pop() must return false when no push arrives within the timeout.');
        $this->assertNotNull($elapsedMs);
        $this->assertGreaterThanOrEqual(
            40.0,
            $elapsedMs,
            'a clean timeout must actually wait the window, not return immediately (the false-timeout bug).'
        );
    }
}
