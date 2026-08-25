<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use Phlix\Hub\HttpClient;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use Phlix\Tests\Support\Coroutine\WithWorkerEventLoop;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Workerman\Http\Client;

/**
 * S196 — the `Hub\HttpClient` coroutine fork on both arms.
 *
 * `request()` picks the async workerman/http-client arm when
 * `isEventLoopRunning() && inCoroutine()` and the blocking cURL arm otherwise.
 * Under PHPUnit neither term was ever true, so the arm a production worker
 * executes every heartbeat was unexecuted by the suite (the S170 defect class).
 *
 * The coroutine arm is driven through the REAL fork decision: the Workerman
 * event loop reports running (see {@see WithWorkerEventLoop}) and the body runs
 * inside a real Swoole coroutine (see {@see RunsInCoroutine}); only the
 * workerman/http-client itself is faked (injected into the private
 * `$asyncClient` property) so no real socket is involved. Branch identity is
 * observed: the fake client records being asked, and the main-stack control
 * proves the same call takes cURL instead.
 */
final class HttpClientCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;
    use WithWorkerEventLoop;

    /**
     * Injects a fake workerman/http-client into the private $asyncClient
     * property so the async arm needs no real socket.
     */
    private function injectAsyncClient(HttpClient $client, Client $fake): void
    {
        $prop = new \ReflectionProperty(HttpClient::class, 'asyncClient');
        $prop->setAccessible(true);
        $prop->setValue($client, $fake);
    }

    /**
     * A fake client whose coroutine-style request() returns a PSR-7 response
     * synchronously (no callbacks — the Hub client calls request() in
     * coroutine mode and expects the response back directly).
     */
    private function fakeCoroutineClient(int $status = 200, string $body = '{"ok":true}'): Client
    {
        $fake = $this->createMock(Client::class);
        $fake->expects($this->once())
            ->method('request')
            ->with($this->stringContains('hub.example.test'))
            ->willReturn(new \Workerman\Psr7\Response($status, ['Content-Type' => 'application/json'], $body));
        return $fake;
    }

    /**
     * INSIDE a real coroutine with the Workerman event loop reporting running,
     * get() must take the async workerman/http-client arm: the response comes
     * back through the fake client, and the cURL arm is never consulted.
     */
    public function testCoroutineArmServesGetThroughAsyncClient(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $fake = $this->fakeCoroutineClient(200, '{"status":"ok"}');
        $client = new HttpClient('http://hub.example.test');
        $this->injectAsyncClient($client, $fake);

        $response = $this->runWithWorkerEventLoop(
            fn (): mixed => $this->runInCoroutine(fn () => $client->get('/api/v1/server-claims/new'))
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['status' => 'ok'], $response->body);
        $this->assertWorkerEventLoopRestored();
    }

    /**
     * OUTSIDE a coroutine (the PHPUnit/CLI default) the same call must take the
     * blocking cURL arm: the fake async client is never consulted, and the
     * unroutable base URL surfaces as a cURL transport failure.
     */
    public function testBlockingArmUsesCurlOutsideCoroutine(): void
    {
        $fake = $this->createMock(Client::class);
        $fake->expects($this->never())->method('request');

        $client = new HttpClient('http://127.0.0.1:1', null, 1);
        $this->injectAsyncClient($client, $fake);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cURL error/');
        $client->get('/api/v1/server-claims/new');
    }
}