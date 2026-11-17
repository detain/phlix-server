<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\ProbeCache;

/**
 * perf #11: the persisted ffprobe cache's on-disk contract — round-trip, invalidation
 * by size / mtime / path, corruption and unwritable-directory tolerance, the entry
 * size bound, atomic 0600 writes, and oldest-first pruning.
 */
final class ProbeCacheTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-probecache-test-' . bin2hex(random_bytes(6));
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
        $this->dir = '';
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
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

    /**
     * @return array{streams: array<int, array<string, mixed>>, format: array<string, mixed>}
     */
    private function sampleProbe(string $title = 'Sample'): array
    {
        return [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '7200.000000', 'format_name' => 'matroska,webm', 'title' => $title],
        ];
    }

    private function cache(?int $maxEntries = null): ProbeCache
    {
        return $maxEntries === null
            ? new ProbeCache($this->dir . '/cache')
            : new ProbeCache($this->dir . '/cache', $maxEntries);
    }

    private function entryFile(string $realPath): string
    {
        return $this->dir . '/cache/' . sha1($realPath) . '.json';
    }

    public function testWrittenEntryRoundTripsForTheSameSizeAndMtime(): void
    {
        $probe = $this->sampleProbe();

        $this->cache()->write('/media/a.mkv', 100, 1_700_000_000, $probe);

        self::assertSame($probe, $this->cache()->read('/media/a.mkv', 100, 1_700_000_000));
    }

    public function testAChangedSizeOrMtimeIsAMiss(): void
    {
        $this->cache()->write('/media/a.mkv', 100, 1_700_000_000, $this->sampleProbe());

        self::assertNull($this->cache()->read('/media/a.mkv', 101, 1_700_000_000), 'size changed');
        self::assertNull($this->cache()->read('/media/a.mkv', 100, 1_700_000_001), 'mtime changed');
    }

    public function testAnEntryWhosePathDoesNotMatchIsAMiss(): void
    {
        $this->cache()->write('/media/a.mkv', 100, 1_700_000_000, $this->sampleProbe());
        // Simulate a hash collision / tampered entry: the file is found by the sha1 of
        // the realpath, but the recorded path is some other file.
        $raw = (string) file_get_contents($this->entryFile('/media/a.mkv'));
        $tampered = str_replace('/media/a.mkv', '/media/other.mkv', $raw);
        file_put_contents($this->entryFile('/media/a.mkv'), $tampered);

        self::assertNull($this->cache()->read('/media/a.mkv', 100, 1_700_000_000));
    }

    public function testCorruptOrWrongVersionEntriesAreMissesNotErrors(): void
    {
        mkdir($this->dir . '/cache', 0700, true);
        file_put_contents($this->entryFile('/media/bad.mkv'), '{not json');
        self::assertNull($this->cache()->read('/media/bad.mkv', 1, 1));

        file_put_contents(
            $this->entryFile('/media/old.mkv'),
            json_encode([
                'v' => 0,
                'path' => '/media/old.mkv',
                'size' => 1,
                'mtime' => 1,
                'probe' => $this->sampleProbe(),
            ]),
        );
        self::assertNull($this->cache()->read('/media/old.mkv', 1, 1), 'unknown format version');

        file_put_contents(
            $this->entryFile('/media/shape.mkv'),
            json_encode(['v' => 1, 'path' => '/media/shape.mkv', 'size' => 1, 'mtime' => 1, 'probe' => 'nope']),
        );
        self::assertNull($this->cache()->read('/media/shape.mkv', 1, 1), 'probe is not the expected shape');
    }

    public function testMissingCacheDirectoryIsAMissNotAnError(): void
    {
        self::assertNull($this->cache()->read('/media/never-written.mkv', 1, 1));
    }

    public function testAnUnwritableDirectoryDoesNotThrowOrPersist(): void
    {
        // The cache "directory" is a regular file, so it can never be created.
        mkdir($this->dir, 0700, true);
        $blocker = $this->dir . '/cache';
        file_put_contents($blocker, 'not a directory');

        $cache = new ProbeCache($blocker);
        $cache->write('/media/a.mkv', 1, 1, $this->sampleProbe());

        self::assertNull($cache->read('/media/a.mkv', 1, 1));
        self::assertSame('not a directory', file_get_contents($blocker), 'the blocking file is untouched');
    }

    public function testAnEntryOverTheSizeBoundIsNotStored(): void
    {
        $huge = $this->sampleProbe(str_repeat('x', ProbeCache::MAX_ENTRY_BYTES + 1));

        $this->cache()->write('/media/huge.mkv', 1, 1, $huge);

        self::assertFileDoesNotExist($this->entryFile('/media/huge.mkv'));
        self::assertNull($this->cache()->read('/media/huge.mkv', 1, 1));
    }

    public function testEntriesAreWrittenAtomicallyWithOwnerOnlyMode(): void
    {
        $this->cache()->write('/media/a.mkv', 1, 1, $this->sampleProbe());

        self::assertFileExists($this->entryFile('/media/a.mkv'));
        self::assertSame([], glob($this->dir . '/cache/.*.tmp') ?: [], 'no temp file is left behind');
        // Owner-only: ffprobe tags can carry personal metadata.
        self::assertSame(0600, fileperms($this->entryFile('/media/a.mkv')) & 0777);
        self::assertSame(0700, fileperms($this->dir . '/cache') & 0777, 'directory is owner-only');
    }

    public function testPruneRemovesTheOldestEntriesDownToNinetyPercentOfTheCap(): void
    {
        $cache = $this->cache(5);
        $base = 1_700_000_000;
        for ($i = 0; $i < 8; $i++) {
            $path = '/media/' . $i . '.mkv';
            $cache->write($path, 1, $base, $this->sampleProbe());
            // Distinct mtimes so "oldest" is well defined: 0 is oldest, 7 is newest.
            touch($this->entryFile($path), $base + $i);
        }

        $cache->prune();

        $remaining = glob($this->dir . '/cache/*.json') ?: [];
        self::assertCount(4, $remaining, 'floor(5 * 0.9) entries kept');
        // The four newest (4..7) survive; the four oldest (0..3) are gone.
        for ($i = 0; $i < 4; $i++) {
            self::assertFileDoesNotExist($this->entryFile('/media/' . $i . '.mkv'));
        }
        for ($i = 4; $i < 8; $i++) {
            self::assertFileExists($this->entryFile('/media/' . $i . '.mkv'));
        }
    }

    public function testPruneIsANoOpUnderTheCap(): void
    {
        $cache = $this->cache(10);
        $cache->write('/media/a.mkv', 1, 1, $this->sampleProbe());
        $cache->write('/media/b.mkv', 1, 1, $this->sampleProbe());

        $cache->prune();

        self::assertCount(2, glob($this->dir . '/cache/*.json') ?: []);
    }
}
