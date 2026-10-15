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

    /**
     * R20-01 (SSRF) — the async arm must hand workerman/http-client an
     * explicit zero-redirect policy. The vendor default in
     * Workerman\Http\Request::$options is ['allow_redirects' => ['max' => 5]],
     * and every redirect hop is fetched WITHOUT any re-check of the SsrfGuard
     * that gated the original URL — a public endpoint answering
     * 302 -> http://169.254.169.254/ would have its FINAL body persisted into
     * the admin-readable delivery log (exfiltration), and 301/302/303 re-issues
     * downgrade the POST to an internal GET scanner. Pinning the option here
     * means any regression that drops it from $options reddens this test.
     */
    public function testAsyncArmDisablesRedirects(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $capturedOptions = null;

        $fake = $this->createMock(Client::class);
        $fake->expects($this->once())
            ->method('request')
            ->willReturnCallback(
                static function (string $url, array $options) use (&$capturedOptions): void {
                    $capturedOptions = $options;
                    $options['success'](new \Workerman\Psr7\Response(200, [], 'ok'));
                }
            );

        $client = new WebhookHttpClient();
        $this->injectAsyncClient($client, $fake);

        $this->runWithWorkerEventLoop(
            fn (): mixed => $this->runInCoroutine(
                fn () => $client->postWithHeaders('http://hooks.test/r', [], '{}')
            )
        );

        $this->assertIsArray($capturedOptions);
        $this->assertArrayHasKey(
            'allow_redirects',
            $capturedOptions,
            'R20-01: the async options must carry an explicit allow_redirects policy'
        );
        $this->assertSame(
            ['max' => 0],
            $capturedOptions['allow_redirects'],
            'R20-01: redirects must be denied outright (vendor default max=5 is the SSRF hole)'
        );
    }

    /**
     * R20-01 companion — with redirects denied at the transport level, a 302
     * from the guarded target arrives as the FINAL response and must be
     * reported as a FAILED delivery (success requires 2xx), never as success,
     * and the fake client must be consulted exactly once (no second hop).
     */
    public function testRedirectResponseIsFailedDeliveryNotSecondRequest(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $fake = $this->createMock(Client::class);
        $fake->expects($this->once())
            ->method('request')
            ->willReturnCallback(
                static function (string $url, array $options): void {
                    // allow_redirects max=0 => the vendor re-queues nothing;
                    // the 3xx itself is delivered to the success callback.
                    $options['success'](new \Workerman\Psr7\Response(
                        302,
                        ['Location' => 'http://169.254.169.254/latest/meta-data/'],
                        'moved'
                    ));
                }
            );

        $client = new WebhookHttpClient();
        $this->injectAsyncClient($client, $fake);

        $result = $this->runWithWorkerEventLoop(
            fn (): mixed => $this->runInCoroutine(
                fn () => $client->postWithHeaders('http://hooks.test/redirect', [], '{}')
            )
        );

        $this->assertFalse($result['success'], 'a 3xx must be a failed webhook delivery');
        $this->assertSame(302, $result['response_code']);
        $this->assertWorkerEventLoopRestored();
    }
}
