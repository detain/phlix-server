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

    private function runner(?string $cacheDir = null, ?string $ffprobe = null): FfmpegRunner
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', $ffprobe ?? $this->ffprobe, $this->dir);
        if ($cacheDir !== null) {
            $runner->setProbeCache(new ProbeCache($cacheDir));
        }

        return $runner;
    }

    private function probeRuns(): int
    {
        return is_file($this->counter) ? count(file($this->counter, FILE_IGNORE_NEW_LINES) ?: []) : 0;
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
}
