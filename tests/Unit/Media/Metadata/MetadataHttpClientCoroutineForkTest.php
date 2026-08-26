<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Media\Metadata\MetadataFailureKind;
use Phlix\Media\Metadata\MetadataHttpClient;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use Phlix\Tests\Support\Coroutine\WithWorkerEventLoop;
use PHPUnit\Framework\TestCase;
use Workerman\Http\Client;

/**
 * S196 — the `MetadataHttpClient` coroutine fork on both arms.
 *
 * `getResult()` computes `$needsBlocking` per attempt from `isEventLoopRunning()`,
 * `inCoroutine()` and `EventLoopTls::requiresBlockingCurl()`; only when all
 * three allow does it take the async `requestAsync()` arm. The existing
 * `MetadataHttpClientStatusTest` / `TlsTest` never enter a coroutine, so the
 * async arm (the one a production worker's metadata fetch executes) was
 * unexecuted by the suite. This file drives it through the REAL fork decision.
 */
final class MetadataHttpClientCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;
    use WithWorkerEventLoop;

    private function injectAsyncClient(MetadataHttpClient $client, Client $fake): void
    {
        $prop = new \ReflectionProperty(MetadataHttpClient::class, 'asyncClient');
        $prop->setAccessible(true);
        $prop->setValue($client, $fake);
    }

    /**
     * INSIDE a real coroutine with the Workerman event loop reporting running,
     * getResult() must take the async arm: the fake client's success callback
     * delivers the 200 and the body is parsed.
     */
    public function testCoroutineArmServesGetThroughAsyncClient(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $fake = $this->createMock(Client::class);
        $fake->expects($this->once())
            ->method('request')
            ->with($this->stringContains('metadata.test'))
            ->willReturnCallback(
                static function (string $url, array $options): void {
                    $options['success'](
                        new \Workerman\Psr7\Response(200, [], '{"results":[{"id":603,"title":"The Matrix"}]}')
                    );
                }
            );

        $client = new MetadataHttpClient('http://metadata.test', 'k');
        $this->injectAsyncClient($client, $fake);

        $result = $this->runWithWorkerEventLoop(
            fn (): mixed => $this->runInCoroutine(
                fn () => $client->getResult('search/movie', ['query' => 'matrix'])
            )
        );

        $this->assertTrue($result->isSuccess(), 'the async arm must classify the 200 as success');
        $this->assertSame(200, $result->httpStatus);
        $this->assertSame('The Matrix', $result->body()['results'][0]['title'] ?? null);
        $this->assertWorkerEventLoopRestored();
    }

    /**
     * OUTSIDE a coroutine the same call must take the blocking cURL arm: the
     * fake async client is never consulted and the unroutable base URL
     * classifies as a transport failure.
     */
    public function testBlockingArmUsesCurlOutsideCoroutine(): void
    {
        $fake = $this->createMock(Client::class);
        $fake->expects($this->never())->method('request');

        $client = new MetadataHttpClient('http://127.0.0.1:1', 'k', 1);
        $this->injectAsyncClient($client, $fake);

        $result = $client->getResult('search/movie', ['query' => 'matrix']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(MetadataFailureKind::Transport, $result->kind);
    }
}
