<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Integrations\Trakt;

use Phlix\Server\Integrations\Trakt\HttpClient;
use Phlix\Server\Integrations\Trakt\TraktApiException;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use Phlix\Tests\Support\Coroutine\WithWorkerEventLoop;
use PHPUnit\Framework\TestCase;
use Workerman\Http\Client;

/**
 * S196 — the `Trakt\HttpClient` coroutine fork on both arms.
 *
 * `request()` computes `$needsBlocking` from `requiresBlockingCurl($url)`,
 * `isEventLoopRunning()` and `inCoroutine()`; only when all three allow does it
 * take the async workerman/http-client arm. Under PHPUnit none of the terms
 * were ever true, so the async arm — the one a production worker's Trakt sync
 * executes — was unexecuted by the suite (the S170 defect class).
 *
 * The async arm is driven through the REAL fork decision (event loop reporting
 * running + real coroutine; only the workerman/http-client is faked via
 * reflection). Branch identity is observed: the fake client is asked exactly
 * once in the coroutine arm and never in the blocking control.
 */
final class HttpClientCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;
    use WithWorkerEventLoop;

    private function injectAsyncClient(HttpClient $client, Client $fake): void
    {
        $prop = new \ReflectionProperty(HttpClient::class, 'asyncClient');
        $prop->setAccessible(true);
        $prop->setValue($client, $fake);
    }

    /**
     * A fake client that fires the success callback synchronously, exactly as
     * the event loop would on response (the estate's established pattern).
     */
    private function fakeCallbackClient(string $body): Client
    {
        $fake = $this->createMock(Client::class);
        $fake->expects($this->once())
            ->method('request')
            ->with($this->stringContains('api.trakt.test'))
            ->willReturnCallback(
                static function (string $url, array $options) use ($body): void {
                    $options['success'](new \Workerman\Psr7\Response(200, [], $body));
                }
            );
        return $fake;
    }

    /**
     * INSIDE a real coroutine with the Workerman event loop reporting running,
     * get() must take the async workerman/http-client arm and return the parsed
     * JSON body.
     */
    public function testCoroutineArmServesGetThroughAsyncClient(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $fake = $this->fakeCallbackClient('{"user":{"username":"detain"}}');
        $client = new HttpClient();
        $this->injectAsyncClient($client, $fake);

        $result = $this->runWithWorkerEventLoop(
            fn (): mixed => $this->runInCoroutine(
                fn () => $client->get('http://api.trakt.test/users/me')
            )
        );

        $this->assertSame(['user' => ['username' => 'detain']], $result, 'get() must return the decoded JSON body.');
        $this->assertWorkerEventLoopRestored();
    }

    /**
     * OUTSIDE a coroutine the same call must take the blocking cURL arm: the
     * fake async client is never consulted and the unroutable URL surfaces as
     * a cURL transport failure.
     */
    public function testBlockingArmUsesCurlOutsideCoroutine(): void
    {
        $fake = $this->createMock(Client::class);
        $fake->expects($this->never())->method('request');

        $client = new HttpClient(timeout: 1);
        $this->injectAsyncClient($client, $fake);

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessageMatches('/cURL error/');
        $client->get('http://127.0.0.1:1/users/me');
    }
}