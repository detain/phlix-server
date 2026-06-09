<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\EncodingHelper;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Workerman\MySQL\Connection;

/**
 * Covers the HLS job lifecycle added to {@see TranscodeManager}: idempotent
 * reuse, concurrency guarding, per-stream copy/encode decisions and on-disk
 * readiness reporting. The DB and FFmpeg runner are mocked — the real-ffmpeg
 * path is covered by the integration suite.
 */
class TranscodeManagerTest extends TestCase
{
    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_tm_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->segmentDir);
    }

    /**
     * Builds a Connection mock that routes query() by SQL substring.
     *
     * @param array<string, mixed> $reuseRow     Row returned for the reuse lookup ([] = none).
     * @param int                  $runningCount Value returned by the COUNT(*) running query.
     * @param array<string, mixed> $mediaRow     Row returned for the media_items lookup ([] = not found).
     * @param array<string, mixed> $jobRow       Row returned for SELECT * ... WHERE id (readiness).
     * @param array<int, array{0: string, 1: array<int, mixed>}> $captured Receives [sql, params] of every call.
     */
    private function mockDb(
        array $reuseRow,
        int $runningCount,
        array $mediaRow,
        array $jobRow,
        array &$captured
    ): Connection {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (
                string $sql,
                ?array $params = null
            ) use (
                $reuseRow,
                $runningCount,
                $mediaRow,
                $jobRow,
                &$captured
            ) {
                $captured[] = [$sql, $params ?? []];
                if (str_contains($sql, 'key_hash = ?') && str_contains($sql, 'IN (')) {
                    return $reuseRow === [] ? [] : [$reuseRow];
                }
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['c' => $runningCount]];
                }
                if (str_contains($sql, 'FROM media_items')) {
                    return $mediaRow === [] ? [] : [$mediaRow];
                }
                if (str_contains($sql, 'SELECT * FROM transcode_jobs WHERE id')) {
                    return $jobRow === [] ? [] : [$jobRow];
                }
                return [];
            }
        );
        return $db;
    }

    private function manager(Connection $db, FfmpegRunner $ff): TranscodeManager
    {
        return new TranscodeManager($db, $ff, new EncodingHelper(), $this->segmentDir, $this->segmentDir, null, 6);
    }

    public function testEnsureHlsJobReusesExistingValidJob(): void
    {
        $existingDir = $this->segmentDir . '/existing-job';
        mkdir($existingDir, 0755, true);
        $captured = [];
        $db = $this->mockDb(
            ['id' => 'existing-job', 'hls_dir' => $existingDir, 'status' => 'running'],
            0,
            [],
            ['status' => 'running'],
            $captured
        );
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('startHlsTranscode');

        $result = $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        $this->assertTrue($result['reused']);
        $this->assertSame('existing-job', $result['job_id']);
        $this->assertStringContainsString('/hls/existing-job/master.m3u8', $result['master_url']);
    }

    public function testEnsureHlsJobIgnoresReuseRowWhenDirMissing(): void
    {
        $captured = [];
        $db = $this->mockDb(
            ['id' => 'gone-job', 'hls_dir' => $this->segmentDir . '/nope', 'status' => 'completed'],
            0,
            ['path' => '/movies/a.mkv'],
            [],
            $captured
        );
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn(['streams' => [
            ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
            ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
        ]]);
        $ff->method('startHlsTranscode')->willReturn(4242);

        $result = $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        $this->assertFalse($result['reused']);
        $this->assertSame('running', $result['status']);
    }

    public function testEnsureHlsJobThrowsWhenConcurrencyExhausted(): void
    {
        $captured = [];
        $db = $this->mockDb([], 4, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Maximum concurrent transcodes');
        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
    }

    public function testEnsureHlsJobThrowsWhenItemNotFound(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, [], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->manager($db, $ff)->ensureHlsJob('missing', 'web');
    }

    public function testEnsureHlsJobCopiesH264AacWithoutDownscale(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn(['streams' => [
            ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
            ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
        ]]);
        $passed = [];
        $ff->method('startHlsTranscode')->willReturnCallback(
            function (string $in, string $dir, array $params) use (&$passed): int {
                $passed = $params;
                return 100;
            }
        );

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        $this->assertSame('copy', $passed['video_codec']);
        $this->assertSame('copy', $passed['audio_codec']);
        $this->assertArrayNotHasKey('width', $passed);
    }

    public function testEnsureHlsJobEncodesHevcAndDownscales4kForWeb(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn(['streams' => [
            ['codec_type' => 'video', 'codec_name' => 'hevc', 'width' => 3840, 'height' => 2160],
            ['codec_type' => 'audio', 'codec_name' => 'ac3', 'channels' => 6],
        ]]);
        $passed = [];
        $ff->method('startHlsTranscode')->willReturnCallback(
            function (string $in, string $dir, array $params) use (&$passed): int {
                $passed = $params;
                return 100;
            }
        );

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        $this->assertSame('libx264', $passed['video_codec']);
        $this->assertSame(1920, $passed['width']);
        $this->assertSame(1080, $passed['height']);
        $this->assertSame('aac', $passed['audio_codec']);
        $this->assertSame(6, $passed['audio_channels']);
    }

    public function testEnsureHlsJobFailsWhenLaunchReturnsZeroPid(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn(['streams' => [
            ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
        ]]);
        $ff->method('startHlsTranscode')->willReturn(0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to launch transcode');
        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
    }

    public function testGetJobReadinessReportsCompleted(): void
    {
        $dir = $this->segmentDir . '/job-c';
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/stream_0.m3u8", "#EXTM3U\n");
        file_put_contents("{$dir}/segment_0_000.ts", 'x');
        file_put_contents("{$dir}/.complete", '');
        $captured = [];
        $db = $this->mockDb([], 0, [], ['hls_dir' => $dir, 'status' => 'running'], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $r = $this->manager($db, $ff)->getJobReadiness('job-c');

        $this->assertSame('completed', $r['status']);
        $this->assertSame(1, $r['segments']);
        $this->assertTrue($r['playlist_ready']);
        $this->assertSame(100.0, $r['progress']);
    }

    public function testGetJobReadinessReportsFailed(): void
    {
        $dir = $this->segmentDir . '/job-f';
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/.failed", '');
        file_put_contents("{$dir}/ffmpeg.log", 'boom');
        $captured = [];
        $db = $this->mockDb([], 0, [], ['hls_dir' => $dir, 'status' => 'running'], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $r = $this->manager($db, $ff)->getJobReadiness('job-f');

        $this->assertSame('failed', $r['status']);
    }

    public function testGetJobReadinessReportsRunningWhenSegmentsButNoMarker(): void
    {
        $dir = $this->segmentDir . '/job-r';
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/stream_0.m3u8", "#EXTM3U\n");
        file_put_contents("{$dir}/segment_0_000.ts", 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], ['hls_dir' => $dir, 'status' => 'running'], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $r = $this->manager($db, $ff)->getJobReadiness('job-r');

        $this->assertSame('running', $r['status']);
        $this->assertTrue($r['playlist_ready']);
    }

    public function testGetJobReadinessNotFound(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, [], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $r = $this->manager($db, $ff)->getJobReadiness('nope');

        $this->assertSame('not_found', $r['status']);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir) ?: [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = "{$dir}/{$e}";
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
