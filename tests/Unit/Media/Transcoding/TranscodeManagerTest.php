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

    /**
     * Extracts the on-demand job INSERT from captured queries.
     *
     * @param array<int, array{0: string, 1: array<int, mixed>}> $captured
     *
     * @return array{hls_dir: string, duration: int, segment_seconds: int, segment_params: array<string, mixed>}
     */
    private function capturedJobInsert(array $captured): array
    {
        foreach ($captured as [$sql, $params]) {
            if (!str_contains($sql, 'INSERT INTO transcode_jobs')) {
                continue;
            }
            // Placeholder order (status/progress/timestamps are SQL literals, not params):
            //  0 id, 1 media_item_id, 2 input_path, 3 output_path, 4 hls_dir, 5 profile,
            //  6 key_hash, 7 variant_width, 8 variant_height, 9 variant_bandwidth,
            // 10 subtitle_tracks, 11 duration_seconds, 12 segment_seconds, 13 segment_params
            $segParams = is_string($params[13] ?? null) ? json_decode($params[13], true) : [];
            return [
                'hls_dir' => (string) ($params[4] ?? ''),
                'duration' => (int) ($params[11] ?? 0),
                'segment_seconds' => (int) ($params[12] ?? 0),
                'segment_params' => is_array($segParams) ? $segParams : [],
            ];
        }
        $this->fail('no transcode_jobs INSERT was captured');
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
        $ff->expects($this->never())->method('startCmafTranscodeWithSubtitles');

        $result = $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        $this->assertTrue($result['reused']);
        $this->assertSame('existing-job', $result['job_id']);
        $this->assertStringContainsString('/hls/existing-job/master.m3u8', $result['master_url']);
        $this->assertSame('/dash/existing-job/manifest.mpd', $result['dash_url']);
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
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '600.0'],
        ]);

        $result = $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        $this->assertFalse($result['reused']);
        // On-demand jobs are 'completed' the instant their VOD playlist is written.
        $this->assertSame('completed', $result['status']);
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

    public function testEnsureHlsJobForcesEncodeForOnDemandSegments(): void
    {
        // On-demand segments can never stream-copy (a copy can't force a keyframe at
        // each segment boundary), so even an otherwise-copyable 8-bit H.264 + AAC
        // source is recorded with a real H.264 / AAC encode in segment_params.
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720,
                    'pix_fmt' => 'yuv420p', 'profile' => 'High'],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '600.0'],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
        $insert = $this->capturedJobInsert($captured);

        $this->assertSame('libx264', $insert['segment_params']['video_codec']);
        $this->assertSame('aac', $insert['segment_params']['audio_codec']);
        $this->assertArrayNotHasKey('width', $insert['segment_params']);
    }

    public function testEnsureHlsJobEncodesHevcAndDownscales4kForWeb(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'hevc', 'width' => 3840, 'height' => 2160],
                ['codec_type' => 'audio', 'codec_name' => 'ac3', 'channels' => 6],
            ],
            'format' => ['duration' => '1200.0'],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
        $p = $this->capturedJobInsert($captured)['segment_params'];

        $this->assertSame('libx264', $p['video_codec']);
        $this->assertSame(1920, $p['width']);
        $this->assertSame(1080, $p['height']);
        $this->assertSame('aac', $p['audio_codec']);
        $this->assertSame(6, $p['audio_channels']);
        // The encode must pin a browser-decodable 8-bit 4:2:0 profile.
        $this->assertSame('yuv420p', $p['pix_fmt']);
        $this->assertSame('high', $p['profile']);
    }

    public function testEnsureHlsJobWritesCompleteVodPlaylist(): void
    {
        // The fix: a COMPLETE VOD media playlist is published up front (full
        // duration, every segment, EXT-X-ENDLIST) so the player reports the true
        // total length and can seek anywhere — never a live, ever-growing playlist.
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            // 25s at 6s segments → 5 segments (0..4): four 6s + one 1s.
            'format' => ['duration' => '25.0'],
        ]);

        $result = $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        $dir = $this->capturedJobInsert($captured)['hls_dir'];
        $media = (string) file_get_contents("{$dir}/media_0.m3u8");
        $this->assertStringContainsString('#EXT-X-PLAYLIST-TYPE:VOD', $media);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $media);
        $this->assertStringContainsString('seg-00000.ts', $media);
        $this->assertStringContainsString('seg-00004.ts', $media);
        $this->assertStringNotContainsString('seg-00005.ts', $media);
        // The master points at the single media playlist.
        $master = (string) file_get_contents("{$dir}/master.m3u8");
        $this->assertStringContainsString('media_0.m3u8', $master);
        $this->assertStringContainsString('/hls/', $result['master_url']);
    }

    public function testEnsureHlsJobPersistsProbedDurationToMediaItem(): void
    {
        // The probe's format.duration is written to media_items.metadata_json as
        // `duration_seconds` so the UI has an authoritative length.
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv', 'metadata_json' => '{"name":"X"}'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '1447.025000'],
        ]);
        $ff->method('startCmafTranscodeWithSubtitles')->willReturn(100);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        $update = null;
        foreach ($captured as [$sql, $params]) {
            if (str_contains($sql, 'UPDATE media_items SET metadata_json')) {
                $update = $params;
                break;
            }
        }
        $this->assertNotNull($update, 'a metadata_json update must be issued');
        $this->assertIsString($update[0]);
        $decoded = json_decode($update[0], true);
        $this->assertSame(1447, $decoded['duration_seconds']);
        $this->assertSame('X', $decoded['name'], 'existing metadata is preserved');
    }

    public function testReapStaleRunningJobsFailsGhostWithMissingDir(): void
    {
        // A 'running' row whose working dir is gone is a ghost from a dead worker;
        // it must be flipped to 'failed' so it stops occupying a concurrency slot.
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use (&$captured) {
                $captured[] = [$sql, $params ?? []];
                if (str_contains($sql, "WHERE status = 'running'") && str_contains($sql, 'SELECT id')) {
                    return [[
                        'id' => 'ghost-1',
                        'hls_dir' => $this->segmentDir . '/does-not-exist',
                        'output_path' => '',
                        'started_at' => '2026-06-13 07:11:28',
                    ]];
                }
                return [];
            }
        );
        $ff = $this->createMock(FfmpegRunner::class);

        $reaped = $this->manager($db, $ff)->reapStaleRunningJobs();

        $this->assertSame(1, $reaped);
        $failUpdate = null;
        foreach ($captured as [$sql, $params]) {
            if (str_contains($sql, "SET status = 'failed'") && ($params[1] ?? null) === 'ghost-1') {
                $failUpdate = $params;
                break;
            }
        }
        $this->assertNotNull($failUpdate, 'ghost job must be marked failed');
        $this->assertStringContainsString('working directory missing', (string) $failUpdate[0]);
    }

    public function testReapStaleRunningJobsKeepsLiveJob(): void
    {
        // A 'running' job whose dir exists and started recently is left alone.
        $liveDir = $this->segmentDir . '/live-job';
        mkdir($liveDir, 0755, true);
        $captured = [];
        $db = $this->createMock(Connection::class);
        $now = date('Y-m-d H:i:s');
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use (&$captured, $liveDir, $now) {
                $captured[] = [$sql, $params ?? []];
                if (str_contains($sql, "WHERE status = 'running'") && str_contains($sql, 'SELECT id')) {
                    return [[
                        'id' => 'live-1',
                        'hls_dir' => $liveDir,
                        'output_path' => '',
                        'started_at' => $now,
                    ]];
                }
                return [];
            }
        );
        $ff = $this->createMock(FfmpegRunner::class);

        $reaped = $this->manager($db, $ff)->reapStaleRunningJobs();

        $this->assertSame(0, $reaped);
        foreach ($captured as [$sql]) {
            $this->assertStringNotContainsString("SET status = 'failed'", $sql);
        }
    }

    public function testReapStaleRunningJobsReapsJobWithNoSegmentWithinTimeout(): void
    {
        // A 'running' job started more than SEGMENT_PRODUCTION_TIMEOUT (60s) ago
        // but with no CMAF segment files on disk is wedged at startup and must be reaped.
        $jobDir = $this->segmentDir . '/no-segment-job';
        mkdir($jobDir, 0755, true);
        $captured = [];
        $db = $this->createMock(Connection::class);
        // started_at is set to 90 seconds ago — well past the 60s window.
        $startedAt = date('Y-m-d H:i:s', strtotime('-90 seconds'));
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use (&$captured, $jobDir, $startedAt) {
                $captured[] = [$sql, $params ?? []];
                if (str_contains($sql, "WHERE status = 'running'") && str_contains($sql, 'SELECT id')) {
                    return [[
                        'id' => 'no-seg-1',
                        'hls_dir' => $jobDir,
                        'output_path' => '',
                        'started_at' => $startedAt,
                    ]];
                }
                return [];
            }
        );
        $ff = $this->createMock(FfmpegRunner::class);

        $reaped = $this->manager($db, $ff)->reapStaleRunningJobs();

        $this->assertSame(1, $reaped);
        $failUpdate = null;
        foreach ($captured as [$sql, $params]) {
            if (str_contains($sql, "SET status = 'failed'") && ($params[1] ?? null) === 'no-seg-1') {
                $failUpdate = $params;
                break;
            }
        }
        $this->assertNotNull($failUpdate, 'no-segment job must be marked failed');
        $this->assertStringContainsString('no segment produced within', (string) $failUpdate[0]);
    }

    public function testReapStaleRunningJobsKeepsJobWithSegmentsAfterTimeout(): void
    {
        // A 'running' job started more than SEGMENT_PRODUCTION_TIMEOUT (60s) ago
        // but that HAS produced at least one segment is alive — the encoder may be
        // slow (e.g. 4K on low-power hardware) but is not wedged.
        $jobDir = $this->segmentDir . '/has-segments-job';
        mkdir($jobDir, 0755, true);
        // Produce a fake segment file so the job looks alive.
        file_put_contents("{$jobDir}/chunk-0-00001.m4s", 'fake-segment-data');
        $captured = [];
        $db = $this->createMock(Connection::class);
        $startedAt = date('Y-m-d H:i:s', strtotime('-90 seconds'));
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use (&$captured, $jobDir, $startedAt) {
                $captured[] = [$sql, $params ?? []];
                if (str_contains($sql, "WHERE status = 'running'") && str_contains($sql, 'SELECT id')) {
                    return [[
                        'id' => 'has-seg-1',
                        'hls_dir' => $jobDir,
                        'output_path' => '',
                        'started_at' => $startedAt,
                    ]];
                }
                return [];
            }
        );
        $ff = $this->createMock(FfmpegRunner::class);

        $reaped = $this->manager($db, $ff)->reapStaleRunningJobs();

        $this->assertSame(0, $reaped, 'job with segments must not be reaped even if old');
        foreach ($captured as [$sql]) {
            $this->assertStringNotContainsString("SET status = 'failed'", $sql);
        }
    }

    public function testEnsureHlsJobReEncodes10BitH264InsteadOfCopying(): void
    {
        // A 10-bit (High 10) H.264 stream copies cleanly but won't decode in the
        // browser — it must be re-encoded to 8-bit 4:2:0, not remuxed.
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'codec_name' => 'h264',
                    'width' => 1920,
                    'height' => 1080,
                    'pix_fmt' => 'yuv420p10le',
                    'profile' => 'High 10',
                ],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '600.0'],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
        $p = $this->capturedJobInsert($captured)['segment_params'];

        $this->assertSame('libx264', $p['video_codec']);
        $this->assertSame('yuv420p', $p['pix_fmt']);
    }

    public function testEnsureHlsJobPersistsSegmentBookkeeping(): void
    {
        // The on-demand job persists the probed duration + segment length so any
        // segment can be built later without re-probing the source.
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '1423.4'],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
        $insert = $this->capturedJobInsert($captured);

        $this->assertSame(1423, $insert['duration']);
        $this->assertSame(6, $insert['segment_seconds']);
        $this->assertSame(6, $insert['segment_params']['segment_seconds'] ?? 6);
    }

    public function testEnsureHlsJobThrowsWhenDurationUndeterminable(): void
    {
        // Without a probeable duration the full VOD playlist cannot be built, so the
        // job is refused rather than silently producing a live/growing stream.
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn(['streams' => [
            ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
        ]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not determine media duration');
        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
    }

    public function testGetJobReadinessReportsCompleted(): void
    {
        $dir = $this->segmentDir . '/job-c';
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/master.m3u8", "#EXTM3U\n");
        file_put_contents("{$dir}/chunk-0-00001.m4s", 'x');
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
        file_put_contents("{$dir}/master.m3u8", "#EXTM3U\n");
        file_put_contents("{$dir}/chunk-0-00001.m4s", 'x');
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

    public function testSubtitleTracksOnlyAdvertisedWhenVttExistsOnDisk(): void
    {
        // Finding 2: the persisted descriptor list may advertise more tracks than
        // actually materialized (extraction is async / a track may fail). Only a
        // track whose sub-{index}.vtt exists on disk should be returned, so every
        // advertised url resolves (no 404s).
        $dir = $this->segmentDir . '/job-subs';
        mkdir($dir, 0755, true);
        // Two detected tracks persisted, but only track 0's sidecar materialized.
        file_put_contents("{$dir}/sub-0.vtt", "WEBVTT\n");
        $tracks = json_encode([
            ['index' => 0, 'language' => 'eng', 'label' => 'English', 'default' => true,
                'codec' => 'ass', 'filename' => 'sub-0.vtt'],
            ['index' => 1, 'language' => 'jpn', 'label' => 'Japanese', 'default' => false,
                'codec' => 'ass', 'filename' => 'sub-1.vtt'],
        ]);

        $captured = [];
        $db = $this->mockDb(
            [],
            0,
            [],
            ['hls_dir' => $dir, 'status' => 'completed', 'subtitle_tracks' => $tracks],
            $captured
        );
        $ff = $this->createMock(FfmpegRunner::class);

        $result = $this->manager($db, $ff)->subtitleTracksFor('job-subs');

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['index']);
        $this->assertSame('/hls/job-subs/sub-0.vtt', $result[0]['url']);
        // Every advertised url resolves to a real file on disk.
        foreach ($result as $track) {
            $file = $dir . '/' . basename($track['url']);
            $this->assertFileExists($file);
        }
    }

    public function testSubtitleTracksEmptyWhenNoVttMaterialized(): void
    {
        // Detected at job creation but extraction not yet done / failed → no
        // sidecars on disk → advertise nothing rather than 404-ing urls.
        $dir = $this->segmentDir . '/job-nosubs';
        mkdir($dir, 0755, true);
        $tracks = json_encode([
            ['index' => 0, 'language' => 'eng', 'label' => 'English', 'default' => true,
                'codec' => 'ass', 'filename' => 'sub-0.vtt'],
        ]);

        $captured = [];
        $db = $this->mockDb(
            [],
            0,
            [],
            ['hls_dir' => $dir, 'status' => 'running', 'subtitle_tracks' => $tracks],
            $captured
        );
        $ff = $this->createMock(FfmpegRunner::class);

        $this->assertSame([], $this->manager($db, $ff)->subtitleTracksFor('job-nosubs'));
    }

    public function testGetJobReadinessOnDemandCompletedWithoutSegments(): void
    {
        // An on-demand job is 'completed' with its VOD playlist present but NO
        // segments yet (they are produced on request). Readiness must report it
        // completed + playlist_ready so the player starts immediately.
        $dir = $this->segmentDir . '/job-od';
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/master.m3u8", "#EXTM3U\n");
        $captured = [];
        $db = $this->mockDb([], 0, [], ['hls_dir' => $dir, 'status' => 'completed'], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $r = $this->manager($db, $ff)->getJobReadiness('job-od');

        $this->assertSame('completed', $r['status']);
        $this->assertTrue($r['playlist_ready']);
        $this->assertSame(0, $r['segments']);
    }

    /**
     * Builds a job row for an on-demand ensureSegment() test.
     *
     * @return array<string, mixed>
     */
    private function onDemandJobRow(string $dir, string $inputPath): array
    {
        return [
            'id' => 'seg-job',
            'hls_dir' => $dir,
            'input_path' => $inputPath,
            'status' => 'completed',
            'duration_seconds' => 60,
            'segment_seconds' => 6,
            'segment_params' => json_encode(['video_codec' => 'libx264', 'audio_codec' => 'aac']),
        ];
    }

    public function testEnsureSegmentReturnsNullForLegacyJobWithoutSegmentParams(): void
    {
        // A legacy CMAF job (no segment_params) cannot serve on-demand segments.
        $captured = [];
        $db = $this->mockDb([], 0, [], ['hls_dir' => $this->segmentDir, 'status' => 'completed'], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $this->assertNull($this->manager($db, $ff)->ensureSegment('seg-job', 0));
    }

    public function testEnsureSegmentRejectsOutOfRangeIndex(): void
    {
        $dir = $this->segmentDir . '/seg-oor';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        // 60s / 6s = 10 segments (0..9); index 10 is past the end.
        $ff->expects($this->never())->method('startSegmentEncode');

        $this->assertNull($this->manager($db, $ff)->ensureSegment('seg-job', 10));
    }

    public function testEnsureSegmentReturnsCachedSegmentWithoutEncoding(): void
    {
        $dir = $this->segmentDir . '/seg-cache';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        file_put_contents("{$dir}/seg-00003.ts", 'cached');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('startSegmentEncode');

        $path = $this->manager($db, $ff)->ensureSegment('seg-job', 3);

        $this->assertSame("{$dir}/seg-00003.ts", $path);
    }

    public function testEnsureSegmentTranscodesOnDemand(): void
    {
        $dir = $this->segmentDir . '/seg-live';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        // Segment 2 → start 12s, length 6s. The encoder mock materializes the file
        // (the real one writes to a temp then atomically renames to this path).
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $start, float $len) use ($input): int {
                $this->assertSame($input, $in);
                $this->assertSame(12.0, $start);
                $this->assertSame(6.0, $len);
                file_put_contents($out, 'encoded');
                return 4242;
            }
        );

        $path = $this->manager($db, $ff)->ensureSegment('seg-job', 2);

        $this->assertSame("{$dir}/seg-00002.ts", $path);
        $this->assertFileExists($path);
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
