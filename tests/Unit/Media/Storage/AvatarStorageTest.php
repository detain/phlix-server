<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Storage;

use Phlix\Media\Storage\AvatarStorage;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AvatarStorage}.
 *
 * These tests operate entirely on temporary files and do not require
 * a real /var/avatars/ directory or any database/environment variables.
 */
final class AvatarStorageTest extends TestCase
{
    private string $tmpDir;
    private AvatarStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/avatar-storage-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);

        $this->storage = new AvatarStorage($this->tmpDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeDirectory($this->tmpDir);
    }

    /**
     * Allocate a palette color and assert it succeeded.
     *
     * PHPStan's GD stub declares imagecolorallocate() as returning int<0,max>|false
     * and expects int<0,255> parameters, but PHP's actual function accepts any int
     * (values outside 0-255 are clamped by GD). This is a known PHPStan false positive
     * from the GD stubs; GD works correctly at runtime.
     *
     * @param \GdImage $img
     * @param int<0,255> $r
     * @param int<0,255> $g
     * @param int<0,255> $b
     * @return int
     */
    private static function alloc(\GdImage $img, int $r, int $g, int $b): int
    {
        $color = imagecolorallocate($img, $r, $g, $b);
        if ($color === false) {
            throw new \RuntimeException('imagecolorallocate returned false');
        }

        return $color;
    }

    public function testStoreRejectsPhpFile(): void
    {
        $phpFile = $this->tmpDir . '/malicious.php';
        file_put_contents($phpFile, '<?php system($_GET["cmd"]);');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/forbidden MIME|not a valid image/i');

        $this->storage->store('user-123', $phpFile);
    }

    public function testStoreRejectsOversizedFile(): void
    {
        // Create a file that exceeds MAX_FILE_SIZE (5 MB)
        $tmpFile = $this->tmpDir . '/oversized.bin';
        file_put_contents($tmpFile, str_repeat("\x00", AvatarStorage::MAX_FILE_SIZE + 1));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed.*maximum size/i');

        $this->storage->store('user-123', $tmpFile);
    }

    public function testStoreRejectsInvalidMime(): void
    {
        // Text file with a .jpg extension — not a real image
        $txtFile = $this->tmpDir . '/fake.jpg';
        file_put_contents($txtFile, 'This is not a valid image, just plain text with a .jpg extension.');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a valid image|not supported/i');

        $this->storage->store('user-123', $txtFile);
    }

    public function testStoreResizesTo256Square(): void
    {
        // 1000×500 — non-square, should be center-cropped to 256×256
        $source = imagecreatetruecolor(1000, 500);
        self::alloc($source, 255, 0, 0);
        $white = self::alloc($source, 255, 255, 255);
        imagefill($source, 0, 0, self::alloc($source, 255, 0, 0));
        imagestring($source, 5, 400, 230, 'TEST', $white);

        $tmpFile = $this->tmpDir . '/source.jpg';
        imagejpeg($source, $tmpFile, 95);
        imagedestroy($source);

        $storedPath = $this->storage->store('user-wide', $tmpFile);

        $this->assertFileExists($storedPath);

        $info = @getimagesize($storedPath);
        $this->assertNotFalse($info);
        $this->assertSame(256, $info[0]);
        $this->assertSame(256, $info[1]);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);

        $verify = @imagecreatefromjpeg($storedPath);
        $this->assertNotFalse($verify);
        imagedestroy($verify);
    }

    public function testStoreAtomicRename(): void
    {
        // 640×480 — non-square (rectangular) avoids GD edge case with 100×100 upscaling
        $source = imagecreatetruecolor(640, 480);
        imagefill($source, 0, 0, self::alloc($source, 0, 0, 255));

        $tmpFile = $this->tmpDir . '/source2.jpg';
        imagejpeg($source, $tmpFile, 95);
        imagedestroy($source);

        $this->storage->store('user-atomic', $tmpFile);

        $targetFile = $this->tmpDir . '/user-atomic.jpg';
        $this->assertFileExists($targetFile);

        $tmpFiles = array_filter(scandir($this->tmpDir), fn($f) => str_ends_with($f, '.tmp'));
        $this->assertEmpty($tmpFiles, 'No .tmp files should remain after atomic rename');
    }

    public function testDeleteRemovesFile(): void
    {
        $source = imagecreatetruecolor(100, 100);
        imagefill($source, 0, 0, self::alloc($source, 0, 255, 0));

        $tmpFile = $this->tmpDir . '/source3.jpg';
        imagejpeg($source, $tmpFile, 95);
        imagedestroy($source);

        $storedPath = $this->storage->store('user-delete', $tmpFile);
        $this->assertFileExists($storedPath);

        $this->storage->delete('user-delete');

        $this->assertFileDoesNotExist($storedPath);
    }

    public function testDeleteNoOpWhenNone(): void
    {
        // Should not throw - reaching here without exception is the test
        $this->storage->delete('user-nonexistent');
        // Assert the method returns void (reaches this point without throwing)
        $this->assertTrue(true, 'delete() should not throw for non-existent avatar');
    }

    public function testPathReturnsNullWhenNone(): void
    {
        $this->assertNull($this->storage->path('user-has-no-avatar'));
    }

    public function testUrlMintsSignedUrl(): void
    {
        putenv('JWT_SECRET=test-secret-for-avatar-url-test-32bytes!');
        \Phlix\Auth\SignedUrl::resetSharedForTesting();

        try {
            // Store an avatar so path() returns non-null
            $source = imagecreatetruecolor(100, 100);
            imagefill($source, 0, 0, self::alloc($source, 255, 255, 0));

            $tmpFile = $this->tmpDir . '/source4.jpg';
            imagejpeg($source, $tmpFile, 95);
            imagedestroy($source);

            $this->storage->store('user-url-test', $tmpFile);

            $url = $this->storage->url('user-url-test');

            $this->assertNotNull($url);
            $this->assertStringStartsWith('/api/v1/users/user-url-test/avatar?', $url);
            $this->assertStringContainsString('exp=', $url);
            $this->assertStringContainsString('sig=', $url);
        } finally {
            \Phlix\Auth\SignedUrl::resetSharedForTesting();
            putenv('JWT_SECRET');
        }
    }

    public function testUrlReturnsNullWhenNoAvatar(): void
    {
        $this->assertNull($this->storage->url('user-has-no-avatar'));
    }

    public function testStoreAcceptsPng(): void
    {
        $source = imagecreatetruecolor(200, 200);
        imagefill($source, 0, 0, self::alloc($source, 0, 128, 128));

        $tmpFile = $this->tmpDir . '/source.png';
        imagepng($source, $tmpFile);
        imagedestroy($source);

        $storedPath = $this->storage->store('user-png', $tmpFile);

        $this->assertFileExists($storedPath);

        $info = @getimagesize($storedPath);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame(256, $info[0]);
        $this->assertSame(256, $info[1]);
    }

    public function testStoreAcceptsWebp(): void
    {
        $source = imagecreatetruecolor(200, 200);
        imagefill($source, 0, 0, self::alloc($source, 128, 0, 128));

        $tmpFile = $this->tmpDir . '/source.webp';
        imagewebp($source, $tmpFile, 95);
        imagedestroy($source);

        $storedPath = $this->storage->store('user-webp', $tmpFile);

        $this->assertFileExists($storedPath);

        $info = @getimagesize($storedPath);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame(256, $info[0]);
    }

    public function testStoreAcceptsGif(): void
    {
        $source = imagecreatetruecolor(200, 200);
        imagefill($source, 0, 0, self::alloc($source, 255, 165, 0));

        $tmpFile = $this->tmpDir . '/source.gif';
        imagegif($source, $tmpFile);
        imagedestroy($source);

        $storedPath = $this->storage->store('user-gif', $tmpFile);

        $this->assertFileExists($storedPath);

        $info = @getimagesize($storedPath);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
    }

    public function testStoreRejectsMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not accessible/i');

        $this->storage->store('user-missing', '/nonexistent/path/file.jpg');
    }

    public function testStoreRejectsDimensionCap(): void
    {
        // 5000×5000 exceeds the 4096×4096 cap
        $source = imagecreatetruecolor(5000, 5000);
        imagefill($source, 0, 0, self::alloc($source, 10, 10, 10));

        $tmpFile = $this->tmpDir . '/huge.jpg';
        imagejpeg($source, $tmpFile, 90);
        imagedestroy($source);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed.*maximum/i');

        $this->storage->store('user-huge', $tmpFile);
    }

    public function testStoreReplacesPriorAvatar(): void
    {
        // Create source files in a SEPARATE subdirectory so they don't pollute avatar storage
        $sourceDir = $this->tmpDir . '/sources';
        mkdir($sourceDir, 0755, true);

        // Store first avatar
        $s1 = imagecreatetruecolor(640, 480);
        imagefill($s1, 0, 0, self::alloc($s1, 255, 0, 0));
        $f1 = $sourceDir . '/first.jpg';
        imagejpeg($s1, $f1, 95);
        imagedestroy($s1);
        $p1 = $this->storage->store('user-replace', $f1);

        // Store second avatar (different color) — same user overwrites
        $s2 = imagecreatetruecolor(640, 480);
        imagefill($s2, 0, 0, self::alloc($s2, 0, 0, 255));
        $f2 = $sourceDir . '/second.jpg';
        imagejpeg($s2, $f2, 95);
        imagedestroy($s2);
        $p2 = $this->storage->store('user-replace', $f2);

        // Same path returned
        $this->assertSame($p1, $p2);
        $this->assertFileExists($p2);

        // Avatar path from storage.path() should confirm
        $this->assertSame($p2, $this->storage->path('user-replace'));

        // Only the avatar .jpg file should exist in the storage directory
        // (source files are in a separate /sources subdirectory)
        $storageDirFiles = array_filter(scandir($this->tmpDir), fn($f) => str_ends_with($f, '.jpg'));
        $this->assertCount(1, $storageDirFiles, 'Only one avatar file should exist after replace');
    }

    public function testStoredFileIsJpegAt85Quality(): void
    {
        $source = imagecreatetruecolor(256, 256);
        imagefill($source, 0, 0, self::alloc($source, 100, 150, 200));

        $tmpFile = $this->tmpDir . '/q85source.jpg';
        imagejpeg($source, $tmpFile, 100); // source at 100% quality
        imagedestroy($source);

        $storedPath = $this->storage->store('user-quality', $tmpFile);

        // Stored file should be smaller than a 100% quality JPEG would be
        // because re-encoding at 85% strips some data
        $storedSize = filesize($storedPath);
        $sourceSize = filesize($tmpFile);

        $this->assertIsInt($storedSize);
        $this->assertIsInt($sourceSize);
        $this->assertLessThan($sourceSize, $storedSize);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
