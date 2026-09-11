<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Storage;

use Phlix\Media\Storage\ArtworkStorage;
use PHPUnit\Framework\TestCase;

/**
 * S73 lazy resize-then-cache tests for {@see ArtworkStorage::ensureVariant()}.
 *
 * The serve path may only do LOCAL work: when a stored item has its
 * `original.jpg` but not the requested width, that single width is produced
 * from the local original and atomically cached. No network, no downloads,
 * no full-ladder regeneration — and a genuine miss (no original, non-ladder
 * width, PNG-only logo, corrupt source) stays a clean null so the caller can
 * answer with the same flat 404 JSON as before S73.
 */
final class ArtworkLazyVariantTest extends TestCase
{
    private string $tmpDir;
    private TestableArtworkStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/artwork-lazy-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
        $this->storage = new TestableArtworkStorage($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    /**
     * Seed the flat cache directory with a real JPEG as the item's original.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function seedOriginal(string $itemId, int $width, int $height): string
    {
        mkdir($this->tmpDir . '/' . $itemId, 0755, true);
        $img = imagecreatetruecolor($width, $height);
        self::assertNotFalse($img);
        $path = $this->tmpDir . '/' . $itemId . '/original.jpg';
        self::assertTrue(imagejpeg($img, $path));
        imagedestroy($img);

        return $path;
    }

    public function testLazyResizeProducesExactlyTheRequestedWidthFromTheLocalOriginal(): void
    {
        $this->seedOriginal('people-77', 800, 1200);

        $path = $this->storage->ensureVariant('people-77', 'w500');

        self::assertIsString($path);
        self::assertFileExists($path);
        $size = getimagesize($path);
        self::assertNotFalse($size);
        self::assertSame(500, $size[0], 'the produced variant must be the requested width');
        // Nothing else was produced — the lazy arm resizes ONE width, not the ladder.
        self::assertFileDoesNotExist($this->tmpDir . '/people-77/w185.jpg');
        self::assertFileDoesNotExist($this->tmpDir . '/people-77/w780.jpg');
        // Zero fetches were issued: the serve-side arm never touches the network.
        self::assertSame([], $this->storage->blockingFetchUrls);
    }

    public function testExistingVariantIsAnsweredWithoutResizingAgain(): void
    {
        $this->seedOriginal('people-77', 800, 1200);
        $first = $this->storage->ensureVariant('people-77', 'w342');
        self::assertIsString($first);

        // Age the cached file; a re-resize would replace it and reset the mtime.
        self::assertTrue(touch($first, 1_700_000_000));
        $second = $this->storage->ensureVariant('people-77', 'w342');

        self::assertSame($first, $second);
        self::assertSame(1_700_000_000, filemtime($second), 'cache hit must not re-produce the variant');
    }

    public function testMissingOriginalYieldsNullNotAFetch(): void
    {
        self::assertNull($this->storage->ensureVariant('people-404', 'w500'));
        self::assertSame([], $this->storage->blockingFetchUrls);
        self::assertDirectoryDoesNotExist($this->tmpDir . '/people-404/w500.jpg');
    }

    public function testSizesOutsideTheStoredSetAreNeverLazilyProduced(): void
    {
        $this->seedOriginal('p-1', 800, 800);
        // Stored sizes are simply answered (that IS the cache-hit arm), even
        // for the two special sizes:
        self::assertSame(
            $this->tmpDir . '/p-1/original.jpg',
            $this->storage->ensureVariant('p-1', ArtworkStorage::ORIGINAL),
        );

        // An item with a JPEG variant but NO original and NO logo: neither can
        // be lazily derived (logo is a stored PNG; original is the source).
        mkdir($this->tmpDir . '/p-2', 0755, true);
        file_put_contents($this->tmpDir . '/p-2/w185.jpg', 'x');
        self::assertNull($this->storage->ensureVariant('p-2', ArtworkStorage::LOGO_SIZE));
        self::assertNull($this->storage->ensureVariant('p-2', ArtworkStorage::ORIGINAL));

        // Off-ladder widths are refused even with a healthy original.
        self::assertNull($this->storage->ensureVariant('p-1', 'w999'));
        self::assertNull($this->storage->ensureVariant('p-1', 'nonsense'));
    }

    public function testEveryLadderWidthIsProducible(): void
    {
        $this->seedOriginal('p-all', 900, 600);

        foreach (ArtworkStorage::WIDTHS as $width) {
            $path = $this->storage->ensureVariant('p-all', 'w' . $width);
            self::assertIsString($path, "ladder width w{$width} must lazily produce");
            $size = getimagesize($path);
            self::assertNotFalse($size);
            self::assertSame($width, $size[0]);
        }
    }

    public function testCorruptSourceYieldsNullAndLeavesNoPartialVariant(): void
    {
        mkdir($this->tmpDir . '/p-broken', 0755, true);
        file_put_contents($this->tmpDir . '/p-broken/original.jpg', 'this is not an image');

        self::assertNull($this->storage->ensureVariant('p-broken', 'w185'));
        self::assertFileDoesNotExist($this->tmpDir . '/p-broken/w185.jpg');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
