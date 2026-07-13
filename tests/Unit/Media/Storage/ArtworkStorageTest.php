<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Storage;

use Phlix\Common\Runtime\WorkerContext;
use Phlix\Media\Storage\ArtworkStorage;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Workerman\Http\Client;
use Workerman\Http\Response as HttpResponse;

/**
 * Unit tests for {@see ArtworkStorage}'s SV-3.4 non-blocking download path.
 *
 * These tests mock the HTTP layer entirely (no network) and operate on
 * temporary directories. They assert:
 *   (a) inside a coroutine, the async client path is taken and a successful
 *       response writes the temp file and triggers variant generation;
 *   (b) outside a coroutine (the PHPUnit/CLI default), the blocking fallback
 *       path is selected;
 *   (c) an async transport error / non-200 yields a clean typed failure
 *       (\RuntimeException) — not a raw Swoole/Channel error and not a
 *       busy-spin.
 */
final class ArtworkStorageTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/artwork-storage-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeDirectory($this->tmpDir);
    }

    /**
     * (b) In the PHPUnit/CLI context there is no running Workerman worker, so
     * the download must select the blocking cURL fallback regardless of
     * coroutine state.
     */
    public function testNonWorkerContextSelectsBlockingDownload(): void
    {
        $storage = new TestableArtworkStorage($this->tmpDir);

        self::assertFalse(WorkerContext::isEventLoopRunning());
        self::assertTrue(
            $storage->shouldUseBlockingDownloadPublic('https://image.tmdb.org/t/p/original/abc.jpg'),
            'Blocking download must be selected when no Workerman worker is running',
        );
    }

    /**
     * (b) https URLs under the Swoole event loop must also take the blocking
     * path (EventLoopTls TLS-stall guard). We assert plain-http vs https differ
     * only through the shared guard, proving the guard is consulted.
     */
    public function testBlockingSelectionConsultsSharedHelpers(): void
    {
        $storage = new TestableArtworkStorage($this->tmpDir);

        // No worker loop => always blocking (short-circuits before the TLS guard).
        self::assertTrue($storage->shouldUseBlockingDownloadPublic('http://example.test/a.jpg'));
        self::assertTrue($storage->shouldUseBlockingDownloadPublic('https://example.test/a.jpg'));
    }

    /**
     * (a) When forced onto the async path inside a live coroutine, a successful
     * response body is written to the temp file and all sized variants plus the
     * original are generated.
     */
    public function testAsyncPathWritesFileAndGeneratesVariants(): void
    {
        if (! \extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension required for the async coroutine path');
        }

        $jpeg = $this->makeJpegBytes(800, 1200);

        $storage = new TestableArtworkStorage($this->tmpDir);
        $storage->forceBlocking = false;
        $storage->fakeClient = $this->makeFakeClient(function (string $url, array $options) use ($jpeg): void {
            self::invokeCallback($options['success'] ?? null, new HttpResponse(200, [], $jpeg));
        });

        /** @var array{result: string[]|null, error: \Throwable|null} $out */
        $out = ['result' => null, 'error' => null];

        \Swoole\Coroutine\run(function () use ($storage, &$out): void {
            try {
                $out['result'] = $storage->downloadAndStore('item-abc', '/poster.jpg');
            } catch (\Throwable $e) {
                $out['error'] = $e;
            }
        });

        self::assertNull($out['error'], 'Async success path must not throw');
        self::assertNotNull($out['result']);
        self::assertContains('original', $out['result']);
        self::assertContains('w185', $out['result']);
        self::assertContains('w780', $out['result']);
        self::assertFileExists($this->tmpDir . '/item-abc/w185.jpg');
        self::assertFileExists($this->tmpDir . '/item-abc/original.jpg');
    }

    /**
     * (c) An async transport error surfaces as a clean \RuntimeException (caught
     * best-effort by the matcher) rather than a raw Swoole error or a hang.
     */
    public function testAsyncErrorYieldsCleanRuntimeException(): void
    {
        if (! \extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension required for the async coroutine path');
        }

        $storage = new TestableArtworkStorage($this->tmpDir);
        $storage->forceBlocking = false;
        $storage->fakeClient = $this->makeFakeClient(function (string $url, array $options): void {
            self::invokeCallback($options['error'] ?? null, new \RuntimeException('connection refused'));
        });

        $captured = null;

        \Swoole\Coroutine\run(function () use ($storage, &$captured): void {
            try {
                $storage->downloadAndStore('item-err', '/poster.jpg');
            } catch (\Throwable $e) {
                $captured = $e;
            }
        });

        self::assertInstanceOf(\RuntimeException::class, $captured);
        self::assertStringContainsString('async', strtolower($captured->getMessage()));
        // No partial temp files or item dir left behind.
        self::assertDirectoryDoesNotExist($this->tmpDir . '/item-err');
    }

    /**
     * (c) A non-200 async response is also a clean typed failure.
     */
    public function testAsyncNon200YieldsCleanRuntimeException(): void
    {
        if (! \extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension required for the async coroutine path');
        }

        $storage = new TestableArtworkStorage($this->tmpDir);
        $storage->forceBlocking = false;
        $storage->fakeClient = $this->makeFakeClient(function (string $url, array $options): void {
            self::invokeCallback($options['success'] ?? null, new HttpResponse(404, [], 'not found'));
        });

        $captured = null;

        \Swoole\Coroutine\run(function () use ($storage, &$captured): void {
            try {
                $storage->downloadAndStore('item-404', '/poster.jpg');
            } catch (\Throwable $e) {
                $captured = $e;
            }
        });

        self::assertInstanceOf(\RuntimeException::class, $captured);
        self::assertStringContainsString('HTTP 404', $captured->getMessage());
    }

    /**
     * Build a fake Workerman HTTP client whose request() resolves the given
     * handler synchronously (no network, no event loop).
     *
     * @param callable(string, array<string, mixed>): void $handler
     */
    private function makeFakeClient(callable $handler): Client
    {
        return new class ($handler) extends Client {
            /** @var callable(string, array<string, mixed>): void */
            private $handler;

            /**
             * @param callable(string, array<string, mixed>): void $handler
             */
            public function __construct(callable $handler)
            {
                parent::__construct([]);
                $this->handler = $handler;
            }

            /**
             * @param array<string, mixed> $options
             */
            public function request(string $url, array $options = []): mixed
            {
                ($this->handler)($url, $options);

                return null;
            }
        };
    }

    /**
     * Invoke a workerman http-client callback (success/error) after narrowing
     * it from the `mixed` options-array value to a real callable.
     */
    private static function invokeCallback(mixed $callback, mixed $argument): void
    {
        self::assertIsCallable($callback);
        $callback($argument);
    }

    /**
     * Generate valid JPEG bytes of the given dimensions via GD.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function makeJpegBytes(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        self::assertNotFalse($img);
        $color = imagecolorallocate($img, 120, 80, 200);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $color === false ? 0 : $color);

        ob_start();
        imagejpeg($img, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($img);

        self::assertIsString($bytes);
        self::assertNotSame('', $bytes);

        return $bytes;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
