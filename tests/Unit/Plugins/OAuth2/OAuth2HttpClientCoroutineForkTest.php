<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\OAuth2;

use Phlix\Plugins\OAuth2\OAuth2HttpClient;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use Phlix\Tests\Support\Coroutine\WithWorkerEventLoop;
use PHPUnit\Framework\TestCase;
use Workerman\Http\Client;

/**
 * S196 — the `OAuth2HttpClient` coroutine fork on both arms.
 *
 * `send()` computes `$needsBlocking` from `isEventLoopRunning()`,
 * `inCoroutine()` and `EventLoopTls::requiresBlockingCurl()`; only when all
 * three allow does it take the async workerman/http-client arm. The existing
 * `OAuth2HttpClientTimeoutTest` deliberately asserts `inCoroutine() === false`
 * — it covers ONLY the blocking cURL branch. This file drives the async arm
 * through the REAL fork decision (event loop reporting running + real
 * coroutine; only the workerman/http-client is faked via reflection).
 */
final class OAuth2HttpClientCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;
    use WithWorkerEventLoop;

    private function injectAsyncClient(OAuth2HttpClient $client, Client $fake): void
    {
        $prop = new \ReflectionProperty(OAuth2HttpClient::class, 'asyncClient');
        $prop->setAccessible(true);
        $prop->setValue($client, $fake);
    }

    /**
     * INSIDE a real coroutine with the Workerman event loop reporting running,
     * get() must take the async workerman/http-client arm: the response comes
     * back through the fake client's synchronous success callback.
     */
    public function testCoroutineArmServesGetThroughAsyncClient(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $fake = $this->createMock(Client::class);
        $fake->expects($this->once())
            ->method('request')
            ->with($this->stringContains('oauth.test'))
            ->willReturnCallback(
                static function (string $url, array $options): void {
                    $options['success'](new \Workerman\Psr7\Response(200, [], '{"access_token":"tok-123"}'));
                }
            );

        $client = new OAuth2HttpClient();
        $this->injectAsyncClient($client, $fake);

        $response = $this->runWithWorkerEventLoop(
            fn (): mixed => $this->runInCoroutine(
                fn () => $client->get('http://oauth.test/userinfo')
            )
        );

        $this->assertNotNull($response, 'the async arm must return the response');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"access_token":"tok-123"}', (string) $response->getBody());
        $this->assertWorkerEventLoopRestored();
    }

    /**
     * OUTSIDE a coroutine the same call must take the blocking cURL arm: the
     * fake async client is never consulted and the unroutable URL yields null
     * (the blocking arm's documented failure shape).
     */
    public function testBlockingArmUsesCurlOutsideCoroutine(): void
    {
        $fake = $this->createMock(Client::class);
        $fake->expects($this->never())->method('request');

        $client = new OAuth2HttpClient(timeout: 1);
        $this->injectAsyncClient($client, $fake);

        $this->assertNull($client->get('http://127.0.0.1:1/userinfo'));
    }
}
