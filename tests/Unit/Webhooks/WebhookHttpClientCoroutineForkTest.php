<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks;

use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use Phlix\Tests\Support\Coroutine\WithWorkerEventLoop;
use Phlix\Webhooks\WebhookHttpClient;
use PHPUnit\Framework\TestCase;
use Workerman\Http\Client;

/**
 * S196 — the `WebhookHttpClient` coroutine fork on both arms.
 *
 * `postWithHeaders()` takes the async `postAsync()` arm only when
 * `isEventLoopRunning() && inCoroutine() && !requiresBlockingCurl($url)` —
 * the Channel-based cooperative wait is only valid inside a coroutine. The
 * existing `WebhookHttpClientTest` never enters a coroutine, so the async arm
 * (the one a production worker's webhook dispatch executes) was unexecuted by
 * the suite. This file drives it through the REAL fork decision.
 */
final class WebhookHttpClientCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;
    use WithWorkerEventLoop;

    private function injectAsyncClient(WebhookHttpClient $client, Client $fake): void
    {
        $prop = new \ReflectionProperty(WebhookHttpClient::class, 'asyncClient');
        $prop->setAccessible(true);
        $prop->setValue($client, $fake);
    }

    /**
     * INSIDE a real coroutine with the Workerman event loop reporting running,
     * postWithHeaders() must take the async arm: the fake client's success
     * callback delivers the 204 and the Channel wait returns it.
     */
    public function testCoroutineArmPostsThroughAsyncClient(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $fake = $this->createMock(Client::class);
        $fake->expects($this->once())
            ->method('request')
            ->with($this->stringContains('hooks.test'), $this->callback(
                static fn (array $options): bool => ($options['method'] ?? null) === 'POST'
            ))
            ->willReturnCallback(
                static function (string $url, array $options): void {
                    $options['success'](new \Workerman\Psr7\Response(204, [], ''));
                }
            );

        $client = new WebhookHttpClient();
        $this->injectAsyncClient($client, $fake);

        $result = $this->runWithWorkerEventLoop(
            fn (): mixed => $this->runInCoroutine(
                fn () => $client->postWithHeaders(
                    'http://hooks.test/endpoint',
                    ['X-Phlix-Event' => 'media.updated'],
                    '{"id":1}'
                )
            )
        );

        $this->assertTrue($result['success'], 'the async arm must report success on 204');
        $this->assertSame(204, $result['response_code']);
        $this->assertNull($result['error']);
        $this->assertWorkerEventLoopRestored();
    }

    /**
     * INSIDE a coroutine, an error callback (connection failure) must also
     * arrive through the async arm and be reported as a failure, not a timeout.
     */
    public function testCoroutineArmReportsTransportErrorFromAsyncClient(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $fake = $this->createMock(Client::class);
        $fake->expects($this->once())
            ->method('request')
            ->willReturnCallback(
                static function (string $url, array $options): void {
                    $options['error'](new \RuntimeException('connection refused'));
                }
            );

        $client = new WebhookHttpClient();
        $this->injectAsyncClient($client, $fake);

        $result = $this->runWithWorkerEventLoop(
            fn (): mixed => $this->runInCoroutine(
                fn () => $client->postWithHeaders('http://hooks.test/x', [], '{}')
            )
        );

        $this->assertFalse($result['success']);
        $this->assertNull($result['response_code']);
        $this->assertSame('connection refused', $result['error']);
        $this->assertWorkerEventLoopRestored();
    }

    /**
     * OUTSIDE a coroutine the same call must take the blocking cURL arm: the
     * fake async client is never consulted and the unroutable URL reports a
     * transport failure.
     */
    public function testBlockingArmUsesCurlOutsideCoroutine(): void
    {
        $fake = $this->createMock(Client::class);
        $fake->expects($this->never())->method('request');

        $client = new WebhookHttpClient(timeout: 1);
        $this->injectAsyncClient($client, $fake);

        $result = $client->postWithHeaders('http://127.0.0.1:1/x', [], '{}');

        $this->assertFalse($result['success']);
        $this->assertNull($result['response_code']);
        $this->assertIsString($result['error']);
        $this->assertNotSame('', $result['error']);
    }
}
