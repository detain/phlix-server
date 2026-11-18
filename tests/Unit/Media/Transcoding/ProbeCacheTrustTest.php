<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\ProbeCache;

/**
 * security (finding 3): the ffprobe disk cache is used only from a directory that is a
 * real directory, owned by the process uid, with no group or other write bit. Probe
 * results drive copy/transcode decisions, so a directory another local user can write
 * must disable the cache, not be trusted. Ownership is injected where a foreign-owned
 * directory cannot be created by the test.
 */
final class ProbeCacheTrustTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        if (!function_exists('posix_getuid')) {
            self::markTestSkipped('The posix extension is needed to check directory ownership.');
        }
        $this->root = sys_get_temp_dir() . '/phlix-probetrust-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        $this->root = '';
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (glob($path . '/*') ?: [] as $entry) {
            $this->removeTree($entry);
        }
        foreach (glob($path . '/.*') ?: [] as $entry) {
            if (basename($entry) !== '.' && basename($entry) !== '..') {
                $this->removeTree($entry);
            }
        }
        @rmdir($path);
    }

    private function uid(): int
    {
        return posix_getuid();
    }

    /**
     * @return array{streams: array<int, array<string, mixed>>, format: array<string, mixed>}
     */
    private function sampleProbe(): array
    {
        return [
            'streams' => [['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264']],
            'format' => ['duration' => '60.000000', 'format_name' => 'mp4'],
        ];
    }

    public function testAPrivateDirectoryOwnedByThisUserIsAccepted(): void
    {
        $dir = $this->root . '/cache';
        mkdir($dir, 0700);

        self::assertInstanceOf(ProbeCache::class, ProbeCache::createIfSecure($dir));
    }

    public function testAMissingDirectoryIsCreatedPrivateAndAccepted(): void
    {
        $dir = $this->root . '/fresh';

        self::assertInstanceOf(ProbeCache::class, ProbeCache::createIfSecure($dir));
        self::assertSame(0700, fileperms($dir) & 0777);
    }

    public function testASymlinkedDirectoryDisablesTheCache(): void
    {
        $target = $this->root . '/real';
        mkdir($target, 0700);
        $link = $this->root . '/link';
        symlink($target, $link);

        self::assertNull(ProbeCache::createIfSecure($link), 'a symlink is never trusted');
    }

    public function testAGroupWritableDirectoryDisablesTheCache(): void
    {
        $dir = $this->root . '/group';
        mkdir($dir, 0700);
        chmod($dir, 0770);

        self::assertNull(ProbeCache::createIfSecure($dir));
    }

    public function testAWorldWritableDirectoryDisablesTheCache(): void
    {
        $dir = $this->root . '/world';
        mkdir($dir, 0700);
        chmod($dir, 0707);

        self::assertNull(ProbeCache::createIfSecure($dir));
    }

    public function testADirectoryOwnedByAnotherUidDisablesTheCache(): void
    {
        $dir = $this->root . '/foreign';
        mkdir($dir, 0700);

        self::assertNull(ProbeCache::createIfSecure($dir, ProbeCache::MAX_ENTRIES, null, $this->uid() + 1));
    }

    public function testARegularFileAtThePathDisablesTheCache(): void
    {
        $file = $this->root . '/not-a-dir';
        file_put_contents($file, 'x');

        self::assertNull(ProbeCache::createIfSecure($file));
    }

    public function testAnUntrustedDirectoryNeverReceivesAnEntry(): void
    {
        $dir = $this->root . '/untrusted';
        mkdir($dir, 0700);

        // Constructed directly so the refusal is the per-call check, not the factory.
        $cache = new ProbeCache($dir, ProbeCache::MAX_ENTRIES, null, $this->uid() + 1);
        $cache->write('/media/a.mkv', 1, 1, $this->sampleProbe());

        self::assertSame([], glob($dir . '/*') ?: [], 'nothing is written to an untrusted directory');
        self::assertNull($cache->read('/media/a.mkv', 1, 1));
    }

    public function testADirectoryWidenedAfterBuildIsRefusedOnTheNextCall(): void
    {
        $dir = $this->root . '/widened';
        mkdir($dir, 0700);
        $cache = ProbeCache::createIfSecure($dir);
        self::assertInstanceOf(ProbeCache::class, $cache);
        $cache->write('/media/a.mkv', 1, 1, $this->sampleProbe());
        self::assertNotNull($cache->read('/media/a.mkv', 1, 1), 'trusted while private');

        // Another local user widens the directory after build: the per-call check refuses it.
        chmod($dir, 0777);

        self::assertNull($cache->read('/media/a.mkv', 1, 1), 'a widened directory is never read');
        $cache->write('/media/b.mkv', 1, 1, $this->sampleProbe());
        self::assertFileDoesNotExist($dir . '/' . sha1('/media/b.mkv') . '.json');
    }
}
