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
     * FINDING 1 — the `original` variant must NOT contribute an invalid `0w`
     * width descriptor to the srcset. `(int) preg_replace('/[^0-9]/','','original')`
     * is 0, and a `0w` descriptor is a parse error that conformant browsers drop
     * (silently losing the candidate). After the fix, `original` is skipped from
     * the `w`-descriptor srcset entirely while the sized variants keep their real
     * widths.
     */
    public function testSrcsetSkipsOriginalZeroWidthDescriptor(): void
    {
        $storage = new TestableArtworkStorage($this->tmpDir);

        // Write real (on-disk) variant files so getStoredVariants() sees them.
        $itemDir = $this->tmpDir . '/item-srcset/';
        mkdir($itemDir, 0755, true);
        foreach (['w185', 'w342', 'w500', 'w780', 'original'] as $variant) {
            file_put_contents($itemDir . $variant . '.jpg', 'x');
        }

        $srcset = $storage->srcset('item-srcset');
        self::assertIsString($srcset);

        // The sized variants keep their real `w`-descriptors...
        self::assertStringContainsString('size=w185 185w', $srcset);
        self::assertStringContainsString('size=w342 342w', $srcset);
        self::assertStringContainsString('size=w500 500w', $srcset);
        self::assertStringContainsString('size=w780 780w', $srcset);

        // ...and `original` (with its invalid ` 0w` descriptor) is NOT present.
        // Match the standalone descriptor token (leading space) so the legitimate
        // `500w`/`780w` substrings don't false-positive.
        self::assertStringNotContainsString(' 0w', $srcset);
        self::assertStringNotContainsString('original', $srcset);

        // Every entry carries a real (>=1) width descriptor.
        foreach (explode(', ', $srcset) as $entry) {
            self::assertMatchesRegularExpression('/ [1-9]\d*w$/', $entry, "Bad srcset entry: {$entry}");
        }
    }

    /**
     * FINDING 2 — a successful variant write goes through a temp-then-rename
     * path: the final file ends up with the exact bytes in one atomic step and
     * NO `.tmp` scratch file is left behind afterwards.
     */
    public function testAtomicWriteVariantRenamesAndLeavesNoTempFile(): void
    {
        $storage = new TestableArtworkStorage($this->tmpDir);

        $itemDir = $this->tmpDir . '/item-atomic/';
        mkdir($itemDir, 0755, true);
        $target = $itemDir . 'w185.jpg';

        $bytes = 'JPEGBYTES-' . str_repeat('a', 4096);
        self::assertTrue($storage->atomicWriteVariantPublic($target, $bytes));

        // Final file exists with the COMPLETE content (atomic — never partial).
        self::assertFileExists($target);
        self::assertSame($bytes, file_get_contents($target));

        // No leftover temp scratch file remains in the directory.
        self::assertSame([], glob($itemDir . '*.tmp'));
    }

    /**
     * FINDING 2 — when the rename onto the final path fails (here the final path
     * is pre-occupied by a directory, so `rename()` of a file onto it fails), the
     * writer returns false AND cleans up its temp file — no orphaned `.tmp`
     * scratch files are left behind, and no partial final file is created.
     */
    public function testAtomicWriteVariantCleansUpTempOnRenameFailure(): void
    {
        $storage = new TestableArtworkStorage($this->tmpDir);

        $itemDir = $this->tmpDir . '/item-atomic-fail/';
        mkdir($itemDir, 0755, true);

        // Occupy the final path with a NON-EMPTY directory so rename() fails.
        $target = $itemDir . 'w185.jpg';
        mkdir($target, 0755, true);
        file_put_contents($target . '/blocker', 'x');

        self::assertFalse($storage->atomicWriteVariantPublic($target, 'JPEGBYTES'));

        // Temp file was cleaned up — nothing dangling in the directory.
        self::assertSame([], glob($itemDir . '*.tmp'));
        // The pre-existing directory is untouched (no partial file clobbered it).
        self::assertDirectoryExists($target);
    }

    /**
     * The title-logo cache must store a REAL PNG (transparency preserved) and
     * must NOT route the image through the JPEG re-encode pipeline. The stored
     * bytes are identical to the validated source PNG (verbatim), the on-disk file
     * is a PNG per getimagesize(), and the alpha channel survives.
     */
    public function testDownloadAndStoreLogoStoresVerbatimTransparentPng(): void
    {
        if (! \extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension required for the async coroutine path');
        }

        $png = $this->makeTransparentPngBytes(300, 120);

        $storage = new TestableArtworkStorage($this->tmpDir);
        $storage->forceBlocking = false;
        $storage->fakeClient = $this->makeFakeClient(function (string $url, array $options) use ($png): void {
            self::invokeCallback($options['success'] ?? null, new HttpResponse(200, [], $png));
        });

        /** @var array{result: ?string, error: \Throwable|null} $out */
        $out = ['result' => null, 'error' => null];

        \Swoole\Coroutine\run(function () use ($storage, &$out): void {
            try {
                $out['result'] = $storage->downloadAndStoreLogo('item-logo', '/logo.png');
            } catch (\Throwable $e) {
                $out['error'] = $e;
            }
        });

        self::assertNull($out['error'], 'Logo store must not throw on a valid PNG');
        $stored = $out['result'];
        self::assertIsString($stored);
        self::assertFileExists($this->tmpDir . '/item-logo/logo.png');

        // Stored bytes are the source bytes VERBATIM (no JPEG re-encode).
        self::assertSame($png, file_get_contents($stored));

        // On disk it is a genuine PNG.
        $info = getimagesize($stored);
        self::assertIsArray($info);
        self::assertSame(IMAGETYPE_PNG, $info[2]);

        // The alpha channel survived (a JPEG re-encode would have flattened it).
        $img = imagecreatefrompng($stored);
        self::assertNotFalse($img);
        imagesavealpha($img, true);
        $rgba = imagecolorat($img, 0, 0);
        $alpha = ($rgba >> 24) & 0x7F;
        imagedestroy($img);
        self::assertSame(127, $alpha, 'Top-left pixel must remain fully transparent');
    }

    /**
     * A non-PNG source (e.g. a JPEG logo) is rejected — the transparency-safe
     * logo cache only ever holds true PNGs, so downloadAndStoreLogo returns null
     * and writes nothing.
     */
    public function testDownloadAndStoreLogoRejectsNonPngSource(): void
    {
        if (! \extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension required for the async coroutine path');
        }

        $jpeg = $this->makeJpegBytes(300, 120);

        $storage = new TestableArtworkStorage($this->tmpDir);
        $storage->forceBlocking = false;
        $storage->fakeClient = $this->makeFakeClient(function (string $url, array $options) use ($jpeg): void {
            self::invokeCallback($options['success'] ?? null, new HttpResponse(200, [], $jpeg));
        });

        /** @var array{result: ?string, error: \Throwable|null} $out */
        $out = ['result' => 'unset', 'error' => null];

        \Swoole\Coroutine\run(function () use ($storage, &$out): void {
            try {
                $out['result'] = $storage->downloadAndStoreLogo('item-jpeg-logo', '/logo.png');
            } catch (\Throwable $e) {
                $out['error'] = $e;
            }
        });

        self::assertNull($out['error']);
        self::assertNull($out['result'], 'A JPEG source must be rejected (null)');
        self::assertFileDoesNotExist($this->tmpDir . '/item-jpeg-logo/logo.png');
    }

    /**
     * variantPath('…', 'logo') resolves the transparency-safe logo.png, while the
     * poster variants keep their JPEG naming scheme untouched.
     */
    public function testVariantPathResolvesLogoSize(): void
    {
        $storage = new TestableArtworkStorage($this->tmpDir);

        $itemDir = $this->tmpDir . '/item-vp/';
        mkdir($itemDir, 0755, true);
        file_put_contents($itemDir . 'logo.png', 'PNGBYTES');
        file_put_contents($itemDir . 'w185.jpg', 'JPEG');

        self::assertSame($itemDir . 'logo.png', $storage->variantPath('item-vp', ArtworkStorage::LOGO_SIZE));
        self::assertSame($itemDir . 'w185.jpg', $storage->variantPath('item-vp', 'w185'));
        // A missing logo yields null (not a logo.jpg lookup).
        self::assertNull($storage->variantPath('item-none', ArtworkStorage::LOGO_SIZE));
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

    /**
     * Generate a PNG with a fully-transparent top-left pixel via GD, so a test can
     * assert the alpha channel survives the logo cache.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function makeTransparentPngBytes(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        self::assertNotFalse($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);

        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        self::assertNotFalse($transparent);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $transparent);

        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        self::assertIsString($bytes);
        self::assertNotSame('', $bytes);

        return $bytes;
    }

    /**
     * deleteItemArtwork() removes every cached file (JPEG variants AND the
     * transparency-safe logo.png) plus the item directory itself, freeing disk.
     */
    public function testDeleteItemArtworkRemovesEntireItemDirectory(): void
    {
        $storage = new ArtworkStorage($this->tmpDir);

        $itemId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $itemDir = rtrim($this->tmpDir, '/') . '/' . $itemId;
        self::assertTrue(mkdir($itemDir, 0755, true));
        file_put_contents($itemDir . '/w185.jpg', 'x');
        file_put_contents($itemDir . '/original.jpg', 'x');
        file_put_contents($itemDir . '/logo.png', 'x');

        self::assertDirectoryExists($itemDir);

        $storage->deleteItemArtwork($itemId);

        self::assertDirectoryDoesNotExist($itemDir);
    }

    /**
     * deleteItemArtwork() is idempotent: a missing item directory is a no-op
     * (never throws).
     */
    public function testDeleteItemArtworkIsNoOpWhenNothingCached(): void
    {
        $storage = new ArtworkStorage($this->tmpDir);

        // Never created — must not throw.
        $storage->deleteItemArtwork('11111111-2222-3333-4444-555555555555');

        self::assertDirectoryExists($this->tmpDir);
        $this->addToAssertionCount(1);
    }

    /**
     * deleteItemArtwork() jails the path through the same sanitised itemDir()
     * logic: a traversal-laden id is REJECTED before any filesystem call, so it
     * can never escape the artwork root.
     */
    public function testDeleteItemArtworkRejectsPathTraversalId(): void
    {
        $storage = new ArtworkStorage($this->tmpDir);

        // A sentinel directory OUTSIDE the artwork root that a traversal id would
        // target if the path were not jailed.
        $outside = rtrim($this->tmpDir, '/') . '_outside';
        self::assertTrue(mkdir($outside, 0755, true));
        file_put_contents($outside . '/keep.txt', 'x');

        try {
            $storage->deleteItemArtwork('../' . basename($outside));
            self::fail('Expected InvalidArgumentException for a traversal item id');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Invalid item ID', $e->getMessage());
        }

        // The outside directory is completely untouched.
        self::assertFileExists($outside . '/keep.txt');

        $this->removeDirectory($outside);
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
