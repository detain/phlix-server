<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\ProbeCache;

/**
 * perf #11 at the runner seam: {@see FfmpegRunner::probe()} with a wired persisted
 * {@see ProbeCache}. A fake ffprobe appends one line to a counter file per REAL
 * invocation, so each assertion can prove whether the probe ran or was served from
 * the in-process memo or the disk cache. Two runner instances over one cache
 * directory stand in for two HTTP workers.
 */
final class FfmpegRunnerProbeCacheTest extends TestCase
{
    private string $dir = '';

    private string $media = '';

    private string $counter = '';

    private string $ffprobe = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phlix-probe-runner-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);

        $this->media = $this->dir . '/clip.mkv';
        file_put_contents($this->media, 'media-bytes-v1');

        $this->counter = $this->dir . '/ffprobe-runs.log';
        $this->ffprobe = $this->dir . '/fake-ffprobe';
        file_put_contents(
            $this->ffprobe,
            "#!/bin/sh\n"
            . 'echo run >> ' . escapeshellarg($this->counter) . "\n"
            . "cat <<'JSON'\n"
            . '{"streams":[{"index":0,"codec_type":"video","codec_name":"h264","width":1920,"height":1080}],'
            . '"format":{"duration":"12.000000","format_name":"matroska,webm"}}' . "\n"
            . "JSON\n",
        );
        chmod($this->ffprobe, 0755);
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
     * A runner whose clock is a minute ahead, so the media file (written "now" in setUp) is past the
     * racy-stat window and its result is persisted and memoized as it is in production. The racy
     * tests set their own clock, see {@see runnerAt()}.
     */
    private function runner(?string $cacheDir = null, ?string $ffprobe = null): FfmpegRunner
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', $ffprobe ?? $this->ffprobe, $this->dir);
        $runner->setWallClock(static fn (): int => time() + 60);
        if ($cacheDir !== null) {
            $runner->setProbeCache(new ProbeCache($cacheDir));
        }

        return $runner;
    }

    private function probeRuns(): int
    {
        return is_file($this->counter) ? count(file($this->counter, FILE_IGNORE_NEW_LINES) ?: []) : 0;
    }

    /** A runner over $cacheDir whose wall clock reads exactly $now. */
    private function runnerAt(string $cacheDir, int $now, ?string $ffprobe = null): FfmpegRunner
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', $ffprobe ?? $this->ffprobe, $this->dir);
        $runner->setProbeCache(new ProbeCache($cacheDir));
        $runner->setWallClock(static fn (): int => $now);

        return $runner;
    }

    /**
     * A fake ffprobe whose output depends on the media bytes: width 1280 when the file holds "v2",
     * otherwise 1920. It still appends one line to the counter per real run.
     */
    private function contentAwareFfprobe(): string
    {
        $script = $this->dir . '/fake-ffprobe-content';
        $body = <<<'SH'
#!/bin/sh
for last; do :; done
echo run >> COUNTER
if grep -q v2 "$last"; then width=1280; else width=1920; fi
printf '{"streams":[{"index":0,"codec_type":"video","codec_name":"h264","width":%s,"height":1080}],' "$width"
printf '"format":{"duration":"12.000000","format_name":"matroska,webm"}}\n'
SH;
        file_put_contents($script, str_replace('COUNTER', escapeshellarg($this->counter), $body) . "\n");
        chmod($script, 0755);

        return $script;
    }

    public function testASecondWorkerIsServedFromThePersistedCacheWithoutRunningFfprobe(): void
    {
        $cacheDir = $this->dir . '/cache';

        $first = $this->runner($cacheDir)->probe($this->media);
        self::assertSame(1, $this->probeRuns(), 'first probe runs ffprobe');

        // A fresh runner has an empty in-process memo, as a freshly started worker does.
        $second = $this->runner($cacheDir)->probe($this->media);

        self::assertSame(1, $this->probeRuns(), 'the persisted entry answered; ffprobe did not run again');
        self::assertSame($first, $second);
        self::assertSame(1920, $second['streams'][0]['width'] ?? null);
        self::assertSame('12.000000', $second['format']['duration'] ?? null);
    }

    public function testTheInProcessMemoStillAnswersRepeatCallsOnTheSameRunner(): void
    {
        $runner = $this->runner($this->dir . '/cache');

        $runner->probe($this->media);
        $runner->probe($this->media);
        $runner->probe($this->media);

        self::assertSame(1, $this->probeRuns());
    }

    public function testAChangedFileIsReprobedAndTheStaleEntryIsReplaced(): void
    {
        $cacheDir = $this->dir . '/cache';
        $this->runner($cacheDir)->probe($this->media);
        self::assertSame(1, $this->probeRuns());

        // A different size is a different file as far as the cache is concerned.
        file_put_contents($this->media, 'media-bytes-version-two-is-longer');

        $result = $this->runner($cacheDir)->probe($this->media);

        self::assertSame(2, $this->probeRuns(), 'size change forces a re-probe');
        self::assertIsArray($result);
        self::assertCount(1, glob($cacheDir . '/*.json') ?: [], 'the entry is replaced, not duplicated');
    }

    public function testASameSizeReplaceWithTheOldMtimeIsReprobed(): void
    {
        // mtime is whole seconds and size is unchanged, so only the inode (and ctime)
        // tell this replacement apart from the cached file. It must not be a stale hit.
        $cacheDir = $this->dir . '/cache';
        $this->runner($cacheDir)->probe($this->media);
        self::assertSame(1, $this->probeRuns());

        $mtime = filemtime($this->media);
        $replacement = $this->dir . '/replacement.mkv';
        file_put_contents($replacement, 'media-bytes-v2');
        self::assertSame(strlen('media-bytes-v1'), strlen('media-bytes-v2'));
        touch($replacement, is_int($mtime) ? $mtime : time());
        rename($replacement, $this->media);

        $this->runner($cacheDir)->probe($this->media);

        self::assertSame(2, $this->probeRuns(), 'a replaced inode forces a re-probe');
    }

    public function testAnUnwritableCacheDirectoryFallsBackToProbing(): void
    {
        // The configured cache "directory" is a regular file, so every write fails.
        $blocker = $this->dir . '/not-a-dir';
        file_put_contents($blocker, 'x');

        $runner = $this->runner($blocker);
        $result = $runner->probe($this->media);

        self::assertIsArray($result, 'a failing cache never fails the probe');
        self::assertSame(1, $this->probeRuns());
        self::assertSame(1920, $result['streams'][0]['width'] ?? null);
    }

    public function testAFailedProbeIsNotPersisted(): void
    {
        $cacheDir = $this->dir . '/cache';
        $runner = $this->runner($cacheDir, $this->dir . '/does-not-exist-ffprobe');

        self::assertNull($runner->probe($this->media));
        self::assertSame([], glob($cacheDir . '/*.json') ?: [], 'a failure may be transient: nothing is stored');
    }

    public function testARunnerWithoutACacheNeverTouchesTheDisk(): void
    {
        $runner = $this->runner();
        $runner->probe($this->media);

        self::assertSame(1, $this->probeRuns());
        self::assertDirectoryDoesNotExist($this->dir . '/cache');
    }

    /**
     * Item 2: the racy-stat guard. The media file was written in the same second as the probe, so a
     * same-size in-place rewrite would leave its stamp unchanged. The result is therefore neither
     * persisted nor kept in the memo, and the next probe runs ffprobe again. The clock is the file's
     * own mtime, so the file is exactly "now" and the test does not depend on how fast it runs.
     */
    public function testAFreshlyWrittenFileIsNotPersisted(): void
    {
        $cacheDir = $this->dir . '/cache';
        $mtime = filemtime($this->media);
        self::assertIsInt($mtime);
        $runner = $this->runnerAt($cacheDir, $mtime);

        $result = $runner->probe($this->media);

        self::assertIsArray($result);
        self::assertSame(1, $this->probeRuns());
        self::assertSame([], glob($cacheDir . '/*.json') ?: [], 'a racy result is not persisted');

        $runner->probe($this->media);
        self::assertSame(2, $this->probeRuns(), 'a racy result is not memoized, so the next call probes again');
    }

    /**
     * Item 2: the window is inclusive at RACY_WINDOW_SECONDS. At two seconds the file is still racy and
     * is not stored; at three seconds it is stored and a fresh runner is served from the disk cache.
     */
    public function testTheRacyWindowIsInclusiveAtTwoSeconds(): void
    {
        $cacheDir = $this->dir . '/cache';
        $mtime = filemtime($this->media);
        self::assertIsInt($mtime);

        $this->runnerAt($cacheDir, $mtime + ProbeCache::RACY_WINDOW_SECONDS)->probe($this->media);
        self::assertSame([], glob($cacheDir . '/*.json') ?: [], 'two seconds old is still racy');

        $this->runnerAt($cacheDir, $mtime + ProbeCache::RACY_WINDOW_SECONDS + 1)->probe($this->media);
        self::assertCount(1, glob($cacheDir . '/*.json') ?: [], 'three seconds old is stored');

        $this->runnerAt($cacheDir, $mtime + ProbeCache::RACY_WINDOW_SECONDS + 1)->probe($this->media);
        self::assertSame(2, $this->probeRuns(), 'the stored entry answers the fresh runner');
    }

    /**
     * Finding 2: a server clock that runs ahead of this host (NFS, SMB) stamps the file with a future mtime.
     * A same-size rewrite inside that server second would leave the stamp unchanged, so the probe is neither
     * persisted nor memoized, and the file is probed again until the clock passes it. The local clock is
     * 10 s behind the real time, the mtime is 7 s ahead of the clock, and the ctime is the real touch time,
     * so both timestamps lie ahead of the clock.
     */
    public function testAFutureDatedFileIsNeitherPersistedNorMemoized(): void
    {
        $cacheDir = $this->dir . '/cache';
        $clock = time() - 10;
        touch($this->media, $clock + 7);
        clearstatcache();

        $runner = $this->runnerAt($cacheDir, $clock);
        $runner->probe($this->media);
        self::assertSame([], glob($cacheDir . '/*.json') ?: [], 'a future-dated result is not persisted');

        $runner->probe($this->media);
        self::assertSame(2, $this->probeRuns(), 'and not memoized, so the same runner probes again');

        $this->runnerAt($cacheDir, $clock)->probe($this->media);
        self::assertSame(3, $this->probeRuns(), 'a second worker also probes, because nothing was stored');
    }

    /**
     * Item 2 (cap): a stamp an hour ahead of the clock is inside MAX_FUTURE_SKEW_SECONDS, so it is racy. The clock
     * is 60 s ahead of the real touch time, so the ctime is an ordinary old stamp and only the mtime counts. The
     * probe is neither persisted nor memoized, so the same runner and a second worker both run ffprobe again.
     */
    public function testAStampAnHourAheadIsNeitherPersistedNorMemoized(): void
    {
        $cacheDir = $this->dir . '/cache';
        $clock = time() + 60;
        touch($this->media, $clock + 3_600);
        clearstatcache();

        $runner = $this->runnerAt($cacheDir, $clock);
        $runner->probe($this->media);
        self::assertSame([], glob($cacheDir . '/*.json') ?: [], 'a racy result is not persisted');

        $runner->probe($this->media);
        self::assertSame(2, $this->probeRuns(), 'a racy result is not memoized, so the same runner probes again');

        $this->runnerAt($cacheDir, $clock)->probe($this->media);
        self::assertSame(3, $this->probeRuns(), 'a second worker also probes, because nothing was stored');
    }

    /**
     * Item 2 (cap): a stamp one day and one second ahead of the clock is past MAX_FUTURE_SKEW_SECONDS, so it is not
     * racy. Its probe is persisted and memoized like an old file's, so the same runner and a fresh worker both
     * answer without running ffprobe again. The clock is 60 s ahead of the real touch time, as in the test above.
     */
    public function testAStampPastTheCapIsPersistedAndMemoized(): void
    {
        $cacheDir = $this->dir . '/cache';
        $clock = time() + 60;
        touch($this->media, $clock + ProbeCache::MAX_FUTURE_SKEW_SECONDS + 1);
        clearstatcache();

        $runner = $this->runnerAt($cacheDir, $clock);
        $runner->probe($this->media);
        self::assertSame(1, $this->probeRuns());
        self::assertCount(1, glob($cacheDir . '/*.json') ?: [], 'a stamp past the cap is persisted');

        $runner->probe($this->media);
        self::assertSame(1, $this->probeRuns(), 'and memoized, so the same runner does not probe again');

        $this->runnerAt($cacheDir, $clock)->probe($this->media);
        self::assertSame(1, $this->probeRuns(), 'a fresh worker is served from the disk cache');
    }

    /**
     * Finding 5: a same-size rewrite within the same second as the probe. The file is probed with the clock
     * pinned to its mtime, so the stamp is racy. It is then rewritten at the same size, and its mtime is put back
     * to the probed value. ctime is not restored: file_put_contents and touch both set it to the wall clock, so it
     * changes only if the wall clock has moved on. The racy guard is therefore what keeps this result out of the
     * cache, not the stamp. The not-persisted assertion at the end is the one that catches guard removal: without
     * the guard the first result is stored and that assertion fails. The run-count assertion may fail before it,
     * if the stamp still matches the stored entry. The second probe must run ffprobe again and return the new
     * output, not a stale entry.
     */
    public function testASameSizeRewriteWithinTheSameSecondIsReprobed(): void
    {
        $cacheDir = $this->dir . '/cache';
        $ffprobe = $this->contentAwareFfprobe();
        $mtime = filemtime($this->media);
        self::assertIsInt($mtime);

        $first = $this->runnerAt($cacheDir, $mtime, $ffprobe)->probe($this->media);
        self::assertSame(1920, $first['streams'][0]['width'] ?? null, 'the original bytes probe as 1920');
        self::assertSame(1, $this->probeRuns());

        // Same size as "media-bytes-v1" (14 bytes), rewritten in place, with the mtime put back to the probed value.
        file_put_contents($this->media, 'media-bytes-v2');
        touch($this->media, $mtime);
        clearstatcache();
        self::assertSame(strlen('media-bytes-v1'), filesize($this->media));

        $second = $this->runnerAt($cacheDir, $mtime, $ffprobe)->probe($this->media);

        self::assertSame(2, $this->probeRuns(), 'the rewritten file is probed again');
        self::assertSame(1280, $second['streams'][0]['width'] ?? null, 'the new output is returned, not a stale entry');
        self::assertSame([], glob($cacheDir . '/*.json') ?: [], 'the racy results are not persisted');
    }

    /**
     * A fake ffprobe whose reported width is whatever the file at $widthFile holds, so a test can change what
     * ffprobe reports without changing the media file's stamp. It still appends one line to the counter per real run.
     */
    private function widthControlledFfprobe(string $widthFile): string
    {
        $script = $this->dir . '/fake-ffprobe-width';
        $body = <<<'SH'
#!/bin/sh
echo run >> COUNTER
width=$(cat WIDTHFILE)
printf '{"streams":[{"index":0,"codec_type":"video","codec_name":"h264","width":%s,"height":1080}],' "$width"
printf '"format":{"duration":"12.000000","format_name":"matroska,webm"}}\n'
SH;
        file_put_contents(
            $script,
            str_replace(
                ['COUNTER', 'WIDTHFILE'],
                [escapeshellarg($this->counter), escapeshellarg($widthFile)],
                $body
            ) . "\n"
        );
        chmod($script, 0755);

        return $script;
    }

    /**
     * Racy transition (MEDIUM finding). The media file is stamped two days ahead of t0, which is past the
     * MAX_FUTURE_SKEW_SECONDS cap, so at t0 it is not racy: the probe runs once, is memoized and is persisted.
     * The stamp does not change afterwards. One day on, the clock is exactly MAX_FUTURE_SKEW_SECONDS behind the
     * stamp, so the stamp is racy. The racy probe must run ffprobe again and return the new result, and it must
     * not be answered from the same runner's memo or from a fresh runner's disk entry over the same directory.
     * The clock is 60 s ahead of the real time, so the ctime (the real touch time) is an ordinary old stamp and
     * only the mtime decides the racy state.
     */
    public function testAStampThatBecomesRacyIsNotServedFromTheMemoOrTheDisk(): void
    {
        $cacheDir = $this->dir . '/cache';
        $widthFile = $this->dir . '/width';
        file_put_contents($widthFile, '1920');
        $ffprobe = $this->widthControlledFfprobe($widthFile);

        $t0 = time() + 60;
        touch($this->media, $t0 + 2 * ProbeCache::MAX_FUTURE_SKEW_SECONDS);
        clearstatcache();

        $now = $t0;
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', $ffprobe, $this->dir);
        $runner->setProbeCache(new ProbeCache($cacheDir));
        $runner->setWallClock(static function () use (&$now): int {
            return $now;
        });

        // At t0 the stamp is not racy: probed once, memoized and persisted.
        $first = $runner->probe($this->media);
        self::assertSame(1, $this->probeRuns(), 'non-racy: ffprobe runs once');
        self::assertSame(1920, $first['streams'][0]['width'] ?? null);
        self::assertCount(1, glob($cacheDir . '/*.json') ?: [], 'non-racy: the result is persisted');

        $runner->probe($this->media);
        self::assertSame(1, $this->probeRuns(), 'non-racy: the same runner is served from the memo');

        $this->runnerAt($cacheDir, $t0, $ffprobe)->probe($this->media);
        self::assertSame(1, $this->probeRuns(), 'non-racy: a fresh worker is served from the disk cache');

        // What ffprobe reports now differs, and the stamp is unchanged. One day on, the stamp is racy.
        file_put_contents($widthFile, '1280');
        $now = $t0 + ProbeCache::MAX_FUTURE_SKEW_SECONDS;

        $racy = $runner->probe($this->media);
        self::assertSame(2, $this->probeRuns(), 'racy: the memo entry is not used, so ffprobe runs again');
        self::assertSame(1280, $racy['streams'][0]['width'] ?? null, 'racy: the new ffprobe result is returned');

        $fresh = $this->runnerAt($cacheDir, $now, $ffprobe)->probe($this->media);
        self::assertSame(3, $this->probeRuns(), 'racy: a fresh worker does not read the disk entry, so ffprobe runs');
        self::assertSame(1280, $fresh['streams'][0]['width'] ?? null, 'racy: the fresh worker gets the new result');
        self::assertCount(1, glob($cacheDir . '/*.json') ?: [], 'racy: nothing is stored, the old entry remains');

        // The non-racy memo entry was kept, not cleared: when the stamp is non-racy again, the memo answers.
        $now = $t0;
        $runner->probe($this->media);
        self::assertSame(3, $this->probeRuns(), 'back at t0 the kept memo entry answers without ffprobe');
    }
}
