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

        $this->cache()->write('/media/a.mkv', 100, 1_700_000_000, 7, 9, $probe);

        self::assertSame($probe, $this->cache()->read('/media/a.mkv', 100, 1_700_000_000, 7, 9));
    }

    public function testAChangedSizeOrMtimeIsAMiss(): void
    {
        $this->cache()->write('/media/a.mkv', 100, 1_700_000_000, 7, 9, $this->sampleProbe());

        self::assertNull($this->cache()->read('/media/a.mkv', 101, 1_700_000_000, 7, 9), 'size changed');
        self::assertNull($this->cache()->read('/media/a.mkv', 100, 1_700_000_001, 7, 9), 'mtime changed');
    }

    public function testAnEntryWhosePathDoesNotMatchIsAMiss(): void
    {
        $this->cache()->write('/media/a.mkv', 100, 1_700_000_000, 7, 9, $this->sampleProbe());
        // Simulate a hash collision / tampered entry: the file is found by the sha1 of
        // the realpath, but the recorded path is some other file.
        $raw = (string) file_get_contents($this->entryFile('/media/a.mkv'));
        $tampered = str_replace('/media/a.mkv', '/media/other.mkv', $raw);
        file_put_contents($this->entryFile('/media/a.mkv'), $tampered);

        self::assertNull($this->cache()->read('/media/a.mkv', 100, 1_700_000_000, 7, 9));
    }

    public function testCorruptOrWrongVersionEntriesAreMissesNotErrors(): void
    {
        mkdir($this->dir . '/cache', 0700, true);
        file_put_contents($this->entryFile('/media/bad.mkv'), '{not json');
        self::assertNull($this->cache()->read('/media/bad.mkv', 1, 1, 7, 9));

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
        self::assertNull($this->cache()->read('/media/old.mkv', 1, 1, 7, 9), 'unknown format version');

        file_put_contents(
            $this->entryFile('/media/shape.mkv'),
            json_encode([
                'v' => ProbeCache::FORMAT_VERSION,
                'path' => '/media/shape.mkv',
                'size' => 1,
                'mtime' => 1,
                'ctime' => 7,
                'ino' => 9,
                'probe' => 'nope',
            ]),
        );
        self::assertNull($this->cache()->read('/media/shape.mkv', 1, 1, 7, 9), 'probe is not the expected shape');
    }

    public function testMissingCacheDirectoryIsAMissNotAnError(): void
    {
        self::assertNull($this->cache()->read('/media/never-written.mkv', 1, 1, 7, 9));
    }

    public function testAnUnwritableDirectoryDoesNotThrowOrPersist(): void
    {
        // The cache "directory" is a regular file, so it can never be created.
        mkdir($this->dir, 0700, true);
        $blocker = $this->dir . '/cache';
        file_put_contents($blocker, 'not a directory');

        $cache = new ProbeCache($blocker);
        $cache->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());

        self::assertNull($cache->read('/media/a.mkv', 1, 1, 7, 9));
        self::assertSame('not a directory', file_get_contents($blocker), 'the blocking file is untouched');
    }

    public function testAnEntryOverTheSizeBoundIsNotStored(): void
    {
        $huge = $this->sampleProbe(str_repeat('x', ProbeCache::MAX_ENTRY_BYTES + 1));

        $this->cache()->write('/media/huge.mkv', 1, 1, 7, 9, $huge);

        self::assertFileDoesNotExist($this->entryFile('/media/huge.mkv'));
        self::assertNull($this->cache()->read('/media/huge.mkv', 1, 1, 7, 9));
    }

    public function testEntriesAreWrittenAtomicallyWithOwnerOnlyMode(): void
    {
        $this->cache()->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());

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
            $cache->write($path, 1, $base, 7, 9, $this->sampleProbe());
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
        $cache->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());
        $cache->write('/media/b.mkv', 1, 1, 7, 9, $this->sampleProbe());

        $cache->prune();

        self::assertCount(2, glob($this->dir . '/cache/*.json') ?: []);
    }

    public function testTheStampIncludesCtimeAndInode(): void
    {
        $this->cache()->write('/media/a.mkv', 100, 1_700_000_000, 1_700_000_500, 4242, $this->sampleProbe());

        self::assertNotNull($this->cache()->read('/media/a.mkv', 100, 1_700_000_000, 1_700_000_500, 4242));
        $cache = $this->cache();
        self::assertNull($cache->read('/media/a.mkv', 100, 1_700_000_000, 1_700_000_501, 4242), 'ctime changed');
        self::assertNull($cache->read('/media/a.mkv', 100, 1_700_000_000, 1_700_000_500, 4243), 'inode changed');
    }

    public function testWriteNeverChangesTheProcessUmask(): void
    {
        $before = umask();

        $this->cache()->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());

        self::assertSame($before, umask(), 'the private mode comes from chmod on the temp file, not a global umask');
    }

    public function testWriteNeverPrunes(): void
    {
        $cache = $this->cache(2);
        for ($i = 0; $i < 5; $i++) {
            $cache->write('/media/' . $i . '.mkv', 1, 1, 7, 9, $this->sampleProbe());
        }

        self::assertCount(5, glob($this->dir . '/cache/*.json') ?: [], 'pruning is not on the probe write path');
    }

    public function testPruneRemovesStaleTempFilesAndKeepsRecentOnes(): void
    {
        $cache = $this->cache();
        $cache->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());
        $stale = $this->dir . '/cache/.x.json.deadbeef.tmp';
        $recent = $this->dir . '/cache/.y.json.cafebabe.tmp';
        file_put_contents($stale, 'partial');
        file_put_contents($recent, 'partial');
        touch($stale, time() - ProbeCache::STALE_TMP_SECONDS - 60);

        self::assertSame(1, $cache->prune());
        self::assertFileDoesNotExist($stale);
        self::assertFileExists($recent, 'a recent temp file may still be in flight');
    }

    public function testOnePruneIsBoundedToTheDeleteLimit(): void
    {
        $cache = $this->cache(10);
        $total = ProbeCache::PRUNE_DELETE_LIMIT + 100;
        for ($i = 0; $i < $total; $i++) {
            $cache->write('/media/' . $i . '.mkv', 1, 1, 7, 9, $this->sampleProbe());
        }

        self::assertSame(ProbeCache::PRUNE_DELETE_LIMIT, $cache->prune(), 'one pass unlinks at most the limit');
        self::assertCount($total - ProbeCache::PRUNE_DELETE_LIMIT, glob($this->dir . '/cache/*.json') ?: []);
    }

    public function testAFailedUnlinkEndsThePassWithoutRescanning(): void
    {
        if (!function_exists('posix_getuid') || posix_getuid() === 0) {
            self::markTestSkipped('A read-only directory blocks unlink only for a non-root user.');
        }
        $cache = $this->cache(5);
        for ($i = 0; $i < 8; $i++) {
            $cache->write('/media/' . $i . '.mkv', 1, 1, 7, 9, $this->sampleProbe());
        }

        chmod($this->dir . '/cache', 0500);
        try {
            self::assertSame(0, $cache->prune(), 'every unlink fails, so the pass returns after the first failure');
            self::assertCount(8, glob($this->dir . '/cache/*.json') ?: []);
        } finally {
            chmod($this->dir . '/cache', 0700);
        }
    }

    public function testStartingThePruneTimerOutsideAWorkerDoesNotThrow(): void
    {
        self::expectNotToPerformAssertions();

        $this->cache()->startPruneTimer();
    }

    /** Item 3: a failed stale-temp unlink ends the pass before any eviction. */
    public function testAFailedStaleTempUnlinkEndsThePassBeforeEviction(): void
    {
        $cache = $this->cache(5);
        for ($i = 0; $i < 8; $i++) {
            $cache->write('/media/' . $i . '.mkv', 1, 1, 7, 9, $this->sampleProbe());
        }
        // A non-empty directory named like a stale temp file: unlink() on it fails, while
        // every .json entry is removable.
        $stale = $this->dir . '/cache/.blocked.json.deadbeef.tmp';
        mkdir($stale);
        file_put_contents($stale . '/inside', 'x');
        touch($stale, time() - ProbeCache::STALE_TMP_SECONDS - 60);

        self::assertSame(0, $cache->prune(), 'the failed temp unlink returns before the entries are evicted');
        self::assertCount(8, glob($this->dir . '/cache/*.json') ?: [], 'no eviction after a failed unlink');
    }

    /** Item 7: eviction starts only above the cap, so exactly the cap is left alone. */
    public function testPruneDoesNotEvictAtExactlyTheCap(): void
    {
        $cache = $this->cache(5);
        for ($i = 0; $i < 5; $i++) {
            $cache->write('/media/' . $i . '.mkv', 1, 1, 7, 9, $this->sampleProbe());
        }

        self::assertSame(0, $cache->prune());
        self::assertCount(5, glob($this->dir . '/cache/*.json') ?: []);
    }

    /** Item 6: a second call inside the TTL is skipped, so only one worker's pass runs per interval. */
    public function testPruneIfDueRunsOnceWithinTheTtl(): void
    {
        $cache = $this->cache(5);
        for ($i = 0; $i < 8; $i++) {
            $cache->write('/media/' . $i . '.mkv', 1, 1, 7, 9, $this->sampleProbe());
        }

        self::assertSame(4, $cache->pruneIfDue(), 'the first call in an interval runs the pass');
        for ($i = 8; $i < 13; $i++) {
            $cache->write('/media/' . $i . '.mkv', 1, 1, 7, 9, $this->sampleProbe());
        }

        self::assertSame(0, $cache->pruneIfDue(), 'a second worker inside the TTL skips the pass');
        self::assertCount(9, glob($this->dir . '/cache/*.json') ?: []);
    }

    /** Item 6: a pass is skipped while another worker holds the prune lock. */
    public function testPruneIfDueIsSkippedWhileAnotherWorkerHoldsTheLock(): void
    {
        $cache = $this->cache(5);
        for ($i = 0; $i < 8; $i++) {
            $cache->write('/media/' . $i . '.mkv', 1, 1, 7, 9, $this->sampleProbe());
        }
        $held = fopen($this->dir . '/cache/' . ProbeCache::PRUNE_LOCK_FILE, 'c');
        self::assertIsResource($held);
        self::assertTrue(flock($held, LOCK_EX));
        try {
            self::assertSame(0, $cache->pruneIfDue(), 'the lock is held elsewhere, so this worker does not scan');
            self::assertCount(8, glob($this->dir . '/cache/*.json') ?: []);
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
    }

    /**
     * Item 2 and finding 2: the racy-stat window. A timestamp at most RACY_WINDOW_SECONDS older than now
     * makes the file racy, through mtime or through ctime. The bound is inclusive. A timestamp ahead of
     * now by no more than MAX_FUTURE_SKEW_SECONDS is racy, because a skewed network clock can stamp it.
     * Past that cap the stamp is not racy, and the boundary itself is inclusive.
     */
    public function testARecentMtimeOrCtimeIsRacy(): void
    {
        $now = 1_000;
        $cap = ProbeCache::MAX_FUTURE_SKEW_SECONDS;
        self::assertTrue(ProbeCache::isRacy($now, 0, $now), 'written this second');
        self::assertTrue(ProbeCache::isRacy($now - 2, 0, $now), 'two seconds old is still inside the window');
        self::assertFalse(ProbeCache::isRacy($now - 3, 0, $now), 'three seconds old is outside the window');
        self::assertTrue(ProbeCache::isRacy(0, $now - 1, $now), 'a recent ctime alone is racy');
        self::assertTrue(ProbeCache::isRacy($now + 2, 0, $now), 'a future mtime counts, for clock skew');
        self::assertTrue(ProbeCache::isRacy($now + 3_600, 0, $now), 'a future mtime an hour ahead is racy');
        self::assertTrue(ProbeCache::isRacy(0, $now + 3_600, $now), 'a future ctime an hour ahead is racy');
        self::assertTrue(ProbeCache::isRacy($now + $cap, 0, $now), 'the cap itself is inclusive');
        self::assertFalse(ProbeCache::isRacy($now + $cap + 1, 0, $now), 'a day and a second ahead is not racy');
        self::assertFalse(ProbeCache::isRacy(0, $now + $cap + 1, $now), 'a future ctime past the cap is not racy');
        self::assertFalse(ProbeCache::isRacy(0, 0, $now), 'an old file is not racy');
    }
}
