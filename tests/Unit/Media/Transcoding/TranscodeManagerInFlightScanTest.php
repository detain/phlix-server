<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * The in-flight segment-temp scan that feeds the global cap, the prewarm budget
 * and the dedup snapshot.
 *
 * It used to glob the whole tree (`{segmentDir}/*\/seg-*.part-…`) on every launch
 * decision. It now lists job directories and only globs the ones whose mtime is
 * within the temp death bound. These tests pin the observable contract: the same
 * live temps are reported, families are split by suffix length, and a job
 * directory that cannot hold a live temp is skipped.
 */
final class TranscodeManagerInFlightScanTest extends TestCase
{
    private string $segmentDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->segmentDir = sys_get_temp_dir() . '/phlix_tmiscan_' . uniqid('', true);
        mkdir($this->segmentDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->segmentDir);
        parent::tearDown();
    }

    private function manager(int $transcodeTimeout): TranscodeManager
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getTranscodeTimeout')->willReturn($transcodeTimeout);

        return new TranscodeManager(
            $db,
            $ffmpeg,
            $this->segmentDir,
            null,
            6,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );
    }

    /**
     * @return array{base: list<string>, prewarm: list<string>}
     */
    private function scan(TranscodeManager $manager): array
    {
        $method = new ReflectionMethod(TranscodeManager::class, 'scanInFlightTemps');
        $method->setAccessible(true);

        /** @var array{base: list<string>, prewarm: list<string>} $families */
        $families = $method->invoke($manager);

        return $families;
    }

    public function testLiveTempsAreFoundInRecentJobDirectoriesAndSplitByFamily(): void
    {
        $job = $this->segmentDir . '/job-a';
        mkdir($job, 0o755, true);
        $base = $job . '/seg-voriginal-00000.m4s.part-0a1b2c3d';
        $prewarm = $job . '/seg-voriginal-00001.m4s.part-p0a1b2c3d';
        touch($base);
        touch($prewarm);

        $families = $this->scan($this->manager(7200));

        self::assertSame([$base], $families['base']);
        self::assertSame([$prewarm], $families['prewarm']);
    }

    public function testJobDirectoryOlderThanTheDeathBoundIsNotScanned(): void
    {
        $old = $this->segmentDir . '/job-old';
        mkdir($old, 0o755, true);
        $stale = $old . '/seg-00000.ts.part-deadbeef';
        touch($stale, time() - 2 * 86400);
        touch($old, time() - 2 * 86400);

        $families = $this->scan($this->manager(7200));

        self::assertSame([], $families['base']);
        self::assertSame([], $families['prewarm']);
    }

    /**
     * With the wrapper timeout unset there is no provable death bound, so the
     * purge stands down and every job directory is scanned (the pre-change rule).
     */
    public function testUnsetTimeoutScansEveryJobDirectory(): void
    {
        $old = $this->segmentDir . '/job-old';
        mkdir($old, 0o755, true);
        $temp = $old . '/seg-00000.ts.part-deadbeef';
        touch($temp, time() - 2 * 86400);
        touch($old, time() - 2 * 86400);

        $families = $this->scan($this->manager(0));

        self::assertSame([$temp], $families['base']);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                $this->rrmdir($entry);
            } else {
                @unlink($entry);
            }
        }
        @rmdir($dir);
    }
}
