<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use Phlix\Admin\S3Client;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use Phlix\Tests\Support\Coroutine\WithWorkerEventLoop;
use PHPUnit\Framework\TestCase;
use Workerman\Http\Client;

/**
 * S196 — the `S3Client` coroutine fork on both arms.
 *
 * `doRequest()` takes the async `requestAsync()` arm only when
 * `isEventLoopRunning() && inCoroutine() && !requiresBlockingCurl($url)`.
 * The existing `S3ClientTest` never enters a coroutine, so the async arm (the
 * one a production worker's backup upload executes) was unexecuted by the
 * suite. This file drives it through the REAL fork decision.
 */
final class S3ClientCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;
    use WithWorkerEventLoop;

    private function injectAsyncClient(S3Client $client, Client $fake): void
    {
        $prop = new \ReflectionProperty(S3Client::class, 'asyncClient');
        $prop->setAccessible(true);
        $prop->setValue($client, $fake);
    }

    private function makeTempFile(): string
    {
        $path = sys_get_temp_dir() . '/phlix_s3_fork_' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($path, 'backup-bytes');
        return $path;
    }

    /**
     * INSIDE a real coroutine with the Workerman event loop reporting running,
     * upload() must take the async arm: the fake client's success callback
     * delivers the 200 and the upload reports true.
     */
    public function testCoroutineArmUploadsThroughAsyncClient(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $path = $this->makeTempFile();
        $checksum = hash_file('sha256', $path);

        $fake = $this->createMock(Client::class);
        $fake->expects($this->once())
            ->method('request')
            ->with($this->stringContains('s3.test'), $this->callback(
                static fn (array $options): bool => ($options['method'] ?? null) === 'PUT'
            ))
            ->willReturnCallback(
                static function (string $url, array $options): void {
                    $options['success'](new \Workerman\Psr7\Response(200, [], ''));
                }
            );

        $client = new S3Client('us-east-1', 'ak', 'sk', 'http://s3.test');
        $this->injectAsyncClient($client, $fake);

        $result = $this->runWithWorkerEventLoop(
            fn (): mixed => $this->runInCoroutine(
                fn () => $client->upload('backups', 'phlix/db.sql.gz', $path, $checksum)
            )
        );

        $this->assertTrue($result, 'the async arm must report upload success on 200');
        $this->assertWorkerEventLoopRestored();
        unlink($path);
    }

    /**
     * OUTSIDE a coroutine the same call must take the blocking cURL arm: the
     * fake async client is never consulted and the unroutable endpoint makes
     * upload() report false.
     */
    public function testBlockingArmUsesCurlOutsideCoroutine(): void
    {
        $path = $this->makeTempFile();
        $checksum = hash_file('sha256', $path);

        $fake = $this->createMock(Client::class);
        $fake->expects($this->never())->method('request');

        $client = new S3Client('us-east-1', 'ak', 'sk', 'http://127.0.0.1:1');
        $this->injectAsyncClient($client, $fake);

        $result = $client->upload('backups', 'phlix/db.sql.gz', $path, $checksum);

        $this->assertFalse($result, 'the blocking arm must report failure on a refused endpoint');
        unlink($path);
    }
}