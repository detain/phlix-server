<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Roku;

use PHPUnit\Framework\TestCase;
use Phlix\Roku\RemoteRokuClient;

/**
 * SV-4.5 / S-F15 — the remote Roku control client must not stall the worker:
 * the channel-launch delay is a coroutine-aware yield (not blocking usleep) and
 * the ECP HTTP POST goes through the async coroutine client when running inside
 * a worker+coroutine (never a blocking file_get_contents on the event loop).
 *
 * RelayConsumer is `final` (cannot be mocked) and its collaborators are heavy;
 * the methods under test don't touch the relay, so we build instances via
 * newInstanceWithoutConstructor().
 */
final class RemoteRokuClientTest extends TestCase
{
    public function testPreferAsyncHttpIsFalseOutsideCoroutine(): void
    {
        $client = (new \ReflectionClass(RemoteRokuClient::class))->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod($client, 'preferAsyncHttp');
        $method->setAccessible(true);

        $this->assertFalse(
            $method->invoke($client),
            'Outside a worker/coroutine the blocking path must be chosen.'
        );
    }

    public function testHttpPostRoutesThroughAsyncClientWhenPreferred(): void
    {
        $client = (new \ReflectionClass(RemoteRokuClientAsyncSeamStub::class))->newInstanceWithoutConstructor();

        $httpPost = new \ReflectionMethod($client, 'httpPost');
        $httpPost->setAccessible(true);
        $result = $httpPost->invoke($client, 'http://192.168.1.100:8060/input', 'key=Play');

        $this->assertSame('ok', $result);
        $this->assertTrue($client->asyncCalled, 'async coroutine transport must be used in-coroutine');
        $this->assertFalse($client->blockingCalled, 'blocking file_get_contents must NOT be used in-coroutine');
    }

    /**
     * @requires extension swoole
     */
    public function testCoroutineAwareSleepYieldsInsideCoroutine(): void
    {
        if (! extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required.');
        }

        $client = (new \ReflectionClass(RemoteRokuClient::class))->newInstanceWithoutConstructor();
        $sleep = new \ReflectionMethod($client, 'coroutineAwareSleep');
        $sleep->setAccessible(true);

        $order = [];
        \Swoole\Coroutine\run(function () use (&$order, $sleep, $client): void {
            $chan = new \Swoole\Coroutine\Channel(2);

            \Swoole\Coroutine\go(function () use (&$order, $chan, $sleep, $client): void {
                $order[] = 'sleep-start';
                $sleep->invoke($client, 0.2);
                $order[] = 'sleep-end';
                $chan->push(true);
            });
            \Swoole\Coroutine\go(function () use (&$order, $chan): void {
                \Swoole\Coroutine::sleep(0.02);
                $order[] = 'sibling-ran';
                $chan->push(true);
            });

            $chan->pop(2.0);
            $chan->pop(2.0);
        });

        $this->assertLessThan(
            array_search('sleep-end', $order, true),
            array_search('sibling-ran', $order, true),
            'sibling must run during the cooperative sleep (Coroutine::sleep, not usleep).'
        );
    }
}
