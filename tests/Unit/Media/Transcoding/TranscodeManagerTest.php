<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\AbrLadder;
use Phlix\Media\Streaming\SourceProfile;
use Phlix\Media\Transcoding\EncodingHelper;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\SegmentBusyException;
use Phlix\Media\Transcoding\TranscodeManager;
use ReflectionMethod;
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
     * @param array<string, mixed> $jobRow       Row returned for the narrowed `... FROM transcode_jobs WHERE id = ?` lookup.
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
                if (str_contains($sql, 'transcode_jobs WHERE id = ?')) {
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
        // A5: a COMPLETE VOD media playlist per VARIANT is published up front (full
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
        // A 720p H.264 + AAC source → rungs 240/360/480/720 + a copy "original".
        $media = (string) file_get_contents("{$dir}/media_v720p.m3u8");
        $this->assertStringContainsString('#EXT-X-PLAYLIST-TYPE:VOD', $media);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $media);
        $this->assertStringContainsString('seg-v720p-00000.ts', $media);
        $this->assertStringContainsString('seg-v720p-00004.ts', $media);
        $this->assertStringNotContainsString('seg-v720p-00005.ts', $media);
        // The master lists every variant and references the per-variant playlists.
        $master = (string) file_get_contents("{$dir}/master.m3u8");
        $this->assertGreaterThanOrEqual(2, substr_count($master, '#EXT-X-STREAM-INF:'));
        $this->assertStringContainsString('media_v720p.m3u8', $master);
        $this->assertStringContainsString('media_voriginal.m3u8', $master);
        $this->assertStringContainsString('/hls/', $result['master_url']);
        // No legacy single-variant artefacts written for a multi-variant job.
        $this->assertFileDoesNotExist("{$dir}/media_0.m3u8");
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

    public function testEnsureHlsJobSkipsDurationProbeWorkWhenSourceMetadataFresh(): void
    {
        // S6: when A1 persisted BOTH real source dims AND a duration, the source
        // length is taken from the scan — the probe-derived duration is NOT used and
        // NO (redundant) metadata_json UPDATE is issued. The probe deliberately
        // reports a DIFFERENT duration to prove the persisted 1200 wins.
        $captured = [];
        $meta = json_encode([
            'duration_seconds' => 1200,
            'source' => ['width' => 1920, 'height' => 1080, 'video_codec' => 'h264', 'audio_codec' => 'aac'],
        ]);
        $db = $this->mockDb([], 0, ['path' => '/m.mkv', 'metadata_json' => $meta], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '999.0'],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        foreach ($captured as [$sql]) {
            $this->assertStringNotContainsString(
                'UPDATE media_items SET metadata_json',
                $sql,
                'no redundant duration persist when source metadata is fresh'
            );
        }
        // The persisted 1200s (not the probe's 999s) is stamped onto the job row.
        $this->assertSame(1200, $this->capturedJobInsert($captured)['duration']);
    }

    public function testEnsureHlsJobToleratesProbeFailureWhenSourceMetadataFresh(): void
    {
        // S6: a live probe FAILURE (null) no longer refuses playback when the scan
        // already described the source — the job is still created (from persisted
        // duration + source), just without embedded-subtitle sidecars.
        $captured = [];
        $meta = json_encode([
            'duration_seconds' => 900,
            'source' => ['width' => 1280, 'height' => 720, 'video_codec' => 'h264', 'audio_codec' => 'aac'],
        ]);
        $db = $this->mockDb([], 0, ['path' => '/m.mkv', 'metadata_json' => $meta], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn(null);

        $result = $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        $this->assertSame('completed', $result['status']);
        $this->assertSame([], $result['subtitles'], 'no subtitles detectable without a probe');
        $this->assertSame(900, $this->capturedJobInsert($captured)['duration']);
    }

    public function testEnsureHlsJobStillThrowsOnProbeFailureWithoutFreshMetadata(): void
    {
        // Unchanged pre-S6 behavior: with no persisted source metadata to fall back
        // on, a failed probe must still refuse the job.
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv', 'metadata_json' => '{"name":"X"}'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to probe media file');

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
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

    /**
     * Counts job-row SELECTs (the `getJobRow`/`jobRowEntry` narrowed query) captured
     * from the DB mock.
     *
     * @param array<int, array{0: string, 1: array<int, mixed>}> $captured
     */
    private function countJobRowSelects(array $captured): int
    {
        $n = 0;
        foreach ($captured as [$sql]) {
            if (str_starts_with(ltrim($sql), 'SELECT') && str_contains($sql, 'transcode_jobs WHERE id = ?')) {
                $n++;
            }
        }
        return $n;
    }

    public function testJobRowCacheServesSecondReadWithoutSelectAndReusesParsedVariants(): void
    {
        // S1: an immutable job row is fetched once and cached in-worker; the parsed
        // `variants` ladder is memoised alongside it so repeated readers neither
        // re-SELECT nor re-json_decode.
        $variants = json_encode([
            'renditions' => [
                ['id' => '1080p', 'width' => 1920, 'height' => 1080, 'bandwidth' => 5_000_000],
                ['id' => '720p', 'width' => 1280, 'height' => 720, 'bandwidth' => 2_500_000],
            ],
            'original' => ['is_copy' => false],
        ]);
        $captured = [];
        $db = $this->mockDb(
            [],
            0,
            [],
            ['id' => 'job-v', 'status' => 'completed', 'variants' => $variants],
            $captured
        );
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->manager($db, $ff);

        $first = $manager->getJobVariants('job-v');
        $second = $manager->getJobVariants('job-v');

        $this->assertNotNull($first);
        $this->assertCount(2, $first); // non-copy "original" mirrors the top rung → dropped
        $this->assertSame('/hls/job-v/media_v1080p.m3u8', $first[0]['url']);
        $this->assertSame($first, $second);
        $this->assertSame(1, $this->countJobRowSelects($captured), 'second read must hit the cache');
    }

    public function testTerminalTransitionInvalidatesCachedJobRow(): void
    {
        // S1: a completion transition (running → completed synced from the on-disk
        // .complete marker) must invalidate the cached row so a stale status is never
        // served — the next read re-SELECTs.
        $dir = $this->segmentDir . '/job-inval';
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/master.m3u8", "#EXTM3U\n");
        file_put_contents("{$dir}/.complete", '');
        $captured = [];
        $db = $this->mockDb([], 0, [], ['hls_dir' => $dir, 'status' => 'running'], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->manager($db, $ff);

        $manager->getJobReadiness('job-inval'); // transitions → UPDATE + invalidate
        $manager->getJobReadiness('job-inval'); // cache dropped → re-SELECT

        $this->assertSame(2, $this->countJobRowSelects($captured), 'transition must invalidate the cache');
    }

    public function testReapInvalidatesCachedJobRow(): void
    {
        // S1: reaping a stale 'running' job invalidates its cached row so a later read
        // reflects the reaped (failed) state instead of a stale cache hit.
        $dir = $this->segmentDir . '/job-reap';
        mkdir($dir, 0755, true);
        $variants = json_encode([
            'renditions' => [['id' => '720p', 'width' => 1280, 'height' => 720, 'bandwidth' => 2_500_000]],
            'original' => ['is_copy' => false],
        ]);
        $captured = [];
        $reapRow = ['id' => 'job-reap', 'hls_dir' => $dir . '/gone', 'output_path' => '', 'started_at' => '2000-01-01 00:00:00'];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use ($variants, $dir, $reapRow, &$captured) {
                $captured[] = [$sql, $params ?? []];
                if (str_starts_with(ltrim($sql), 'SELECT') && str_contains($sql, "status = 'running'")) {
                    return [$reapRow]; // reaper's running-jobs scan
                }
                if (str_contains($sql, 'transcode_jobs WHERE id = ?')) {
                    return [['id' => 'job-reap', 'status' => 'completed', 'hls_dir' => $dir, 'variants' => $variants]];
                }
                return [];
            }
        );
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->manager($db, $ff);

        $this->assertNotNull($manager->getJobVariants('job-reap')); // primes the cache
        $manager->reapStaleRunningJobs(0); // dir missing → reaped → invalidate
        $manager->getJobVariants('job-reap'); // cache dropped → re-SELECT

        $this->assertSame(2, $this->countJobRowSelects($captured), 'reap must invalidate the cache');
    }

    public function testCancelInvalidatesCachedJobRow(): void
    {
        // S1: stopTranscode() writes status='cancelled'; the cached row must be
        // dropped so a subsequent read never serves the stale (pre-cancel) status.
        $variants = json_encode([
            'renditions' => [['id' => '720p', 'width' => 1280, 'height' => 720, 'bandwidth' => 2_500_000]],
            'original' => ['is_copy' => false],
        ]);
        $captured = [];
        $db = $this->mockDb([], 0, [], ['id' => 'job-cancel', 'status' => 'running', 'variants' => $variants], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->manager($db, $ff);

        // Register an active job so stopTranscode() proceeds to the UPDATE + invalidate
        // (its dir does not exist on disk, so the file cleanup is a no-op).
        $active = new \ReflectionProperty(TranscodeManager::class, 'activeJobs');
        $active->setAccessible(true);
        $active->setValue($manager, ['job-cancel' => ['output_path' => $this->segmentDir . '/job-cancel/out.m3u8']]);

        $this->assertNotNull($manager->getJobVariants('job-cancel')); // primes the cache
        $manager->stopTranscode('job-cancel');                        // → UPDATE cancelled + invalidate
        $manager->getJobVariants('job-cancel');                       // cache dropped → re-SELECT

        $this->assertSame(2, $this->countJobRowSelects($captured), 'cancel must invalidate the cache');
    }

    public function testLruEvictsOldestEntryBeyondCacheMax(): void
    {
        // S1: the in-worker cache is bounded at JOB_ROW_CACHE_MAX with oldest-first
        // eviction. Priming max+1 distinct rows evicts the least-recently-used job
        // (#0); it must then re-SELECT, while the most-recent job stays a cache hit.
        $max = (new \ReflectionClassConstant(TranscodeManager::class, 'JOB_ROW_CACHE_MAX'))->getValue();
        $this->assertIsInt($max);
        $captured = [];
        $db = $this->mockDb([], 0, [], ['status' => 'completed'], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->manager($db, $ff);

        // A 'completed' row never triggers a status write, so nothing here invalidates
        // the cache — evictions are driven purely by the bound.
        for ($i = 0; $i <= $max; $i++) {
            $manager->getJobReadiness("lru-{$i}");
        }

        $selectsFor = static function (string $id) use (&$captured): int {
            $n = 0;
            foreach ($captured as [$sql, $params]) {
                if (
                    str_starts_with(ltrim($sql), 'SELECT')
                    && str_contains($sql, 'transcode_jobs WHERE id = ?')
                    && ($params[0] ?? null) === $id
                ) {
                    $n++;
                }
            }
            return $n;
        };

        $manager->getJobReadiness('lru-0');        // evicted at the cap → re-SELECT
        $manager->getJobReadiness("lru-{$max}");   // still cached → no new SELECT

        $this->assertSame(2, $selectsFor('lru-0'), 'oldest entry must be evicted at the cap');
        $this->assertSame(1, $selectsFor("lru-{$max}"), 'most-recent entry must remain cached');
    }

    public function testNarrowedJobRowColumnsCoverEveryColumnCallersRead(): void
    {
        // S1 guard: getJobRow() was narrowed from `SELECT *` to a fixed column list.
        // Every column any caller reads MUST be in that list, else in production the
        // caller silently gets null (the DB returns only the selected columns — the
        // test DB mock returns the whole row regardless, so a dropped column can NOT
        // be caught behaviourally). This pins the contract so a future narrowing that
        // drops a still-needed column fails here, loudly, with the offending column.
        $columns = (new \ReflectionClassConstant(TranscodeManager::class, 'JOB_ROW_COLUMNS'))->getValue();
        $this->assertIsString($columns);
        $selected = array_map('trim', explode(',', $columns));

        $required = [
            'id',               // job identity
            'status',           // getJobReadiness() / statusOf()
            'input_path',       // produceSegment() ffmpeg source
            'hls_dir',          // getJobReadiness() / produceSegment() output dir
            'duration_seconds', // ensureSegment() timeline (segment count / bounds)
            'segment_seconds',  // ensureSegment() timeline
            'segment_params',   // ensureSegment() legacy single-variant encode params
            'subtitle_tracks',  // decodeSubtitleTracks()
            'variants',         // getJobVariants() / ensureSegment() ladder
        ];
        foreach ($required as $col) {
            $this->assertContains($col, $selected, "getJobRow() must SELECT `{$col}` — a caller reads it");
        }
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

        $this->assertNull($this->manager($db, $ff)->ensureSegment('seg-job', null, 0));
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

        $this->assertNull($this->manager($db, $ff)->ensureSegment('seg-job', null, 10));
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

        $path = $this->manager($db, $ff)->ensureSegment('seg-job', null, 3);

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

        $path = $this->manager($db, $ff)->ensureSegment('seg-job', null, 2);

        $this->assertSame("{$dir}/seg-00002.ts", $path);
        $this->assertFileExists($path);
    }

    /**
     * Builds a manager with the on-demand concurrency / cache / poll seams exposed
     * so segment behaviour can be exercised without a 30 s real-world wait.
     */
    private function segManager(
        Connection $db,
        FfmpegRunner $ff,
        ?int $maxConcurrentSegments = null,
        ?int $cacheMaxBytes = null,
        ?int $cacheMaxAge = null,
        ?int $waitMs = 200
    ): TranscodeManager {
        return new TranscodeManager(
            $db,
            $ff,
            new EncodingHelper(),
            $this->segmentDir,
            $this->segmentDir,
            null,
            6,
            null,
            null,
            null,
            $maxConcurrentSegments,
            $cacheMaxBytes,
            $cacheMaxAge,
            $waitMs
        );
    }

    public function testEnsureSegmentDoesNotRelaunchWhenSegmentAlreadyEncoding(): void
    {
        $dir = $this->segmentDir . '/seg-dedup';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        // An encode for seg-00002 is already in flight (its atomic-write temp exists).
        file_put_contents("{$dir}/seg-00002.ts.part-deadbeef", 'partial');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        // The in-flight encode must NOT be duplicated — this is the anti-cascade fix.
        $ff->expects($this->never())->method('startSegmentEncode');

        $this->assertNull($this->segManager($db, $ff)->ensureSegment('seg-job', null, 2));
    }

    public function testEnsureSegmentThrowsSegmentBusyWhenGlobalCeilingReached(): void
    {
        $dir = $this->segmentDir . '/seg-busy';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        // A different job already has an encode in flight, filling the ceiling (=1).
        $otherDir = $this->segmentDir . '/other-job';
        mkdir($otherDir, 0755, true);
        file_put_contents("{$otherDir}/seg-00000.ts.part-aaaabbbb", 'p');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('startSegmentEncode');

        $this->expectException(SegmentBusyException::class);
        $this->segManager($db, $ff, 1)->ensureSegment('seg-job', null, 2);
    }

    public function testEnsureSegmentAtCapacityStillServesAlreadyEncodingSegment(): void
    {
        $dir = $this->segmentDir . '/seg-cap-inflight';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        // This exact segment is already encoding; even though its temp also fills the
        // ceiling (=1), an in-flight segment must piggyback rather than fast-fail.
        file_put_contents("{$dir}/seg-00002.ts.part-bbbbcccc", 'p');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('startSegmentEncode');

        // No SegmentBusyException — returns null after the short wait since the
        // in-flight encode does not complete within the test.
        $this->assertNull($this->segManager($db, $ff, 1)->ensureSegment('seg-job', null, 2));
    }

    public function testEnsureSegmentRecreatesMissingJobDir(): void
    {
        // hls_dir is gone (evicted by the sweep, or wiped by a PrivateTmp restart).
        $dir = $this->segmentDir . '/seg-gone';
        $input = $this->segmentDir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out): int {
                $this->assertDirectoryExists(dirname($out)); // dir was recreated
                file_put_contents($out, 'encoded');
                return 1;
            }
        );

        $path = $this->segManager($db, $ff)->ensureSegment('seg-job', null, 1);

        $this->assertSame("{$dir}/seg-00001.ts", $path);
        $this->assertDirectoryExists($dir);
    }

    public function testSweepSegmentCacheEvictsIdleSessionsPastTtl(): void
    {
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $old = $this->segmentDir . '/ttl-old';
        $fresh = $this->segmentDir . '/ttl-fresh';
        mkdir($old, 0755, true);
        mkdir($fresh, 0755, true);
        file_put_contents("{$old}/seg-00000.ts", 'x');
        file_put_contents("{$fresh}/seg-00000.ts", 'x');
        touch($old, time() - 10); // idle 10s

        // cacheMaxAge = 1s → the idle dir is past TTL, the fresh one is not.
        $reaped = $this->segManager($db, $ff, null, null, 1)->sweepSegmentCache();

        $this->assertSame(1, $reaped);
        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($fresh);
    }

    public function testSweepSegmentCacheEvictsLruOverBudgetButKeepsActiveSessions(): void
    {
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $old = $this->segmentDir . '/lru-old';
        $active = $this->segmentDir . '/lru-active';
        mkdir($old, 0755, true);
        mkdir($active, 0755, true);
        file_put_contents("{$old}/seg-00000.ts", str_repeat('x', 4096));
        file_put_contents("{$active}/seg-00000.ts", str_repeat('y', 4096));
        touch($old, time() - 2400);   // idle 40 min → past the 30-min active window
        touch($active, time());       // actively watched right now

        // Budget tiny (over it), TTL huge (nothing TTL-evicted) → LRU size eviction,
        // but the live session is never pulled out from under the viewer.
        $reaped = $this->segManager($db, $ff, null, 1024, 86400)->sweepSegmentCache();

        $this->assertSame(1, $reaped);
        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($active);
    }

    // ---------------------------------------------------------------------
    // A5 — multi-variant ABR ladder pipeline
    // ---------------------------------------------------------------------

    /**
     * Extracts the `variants` JSON (bound param index 14, right after segment_params)
     * from the captured on-demand job INSERT.
     *
     * @param array<int, array{0: string, 1: array<int, mixed>}> $captured
     */
    private function capturedVariantsJson(array $captured): string
    {
        foreach ($captured as [$sql, $params]) {
            if (!str_contains($sql, 'INSERT INTO transcode_jobs')) {
                continue;
            }
            return is_string($params[14] ?? null) ? $params[14] : '';
        }
        $this->fail('no transcode_jobs INSERT was captured');
    }

    /**
     * A multi-variant (A5+) job row: carries the persisted `variants` ladder JSON.
     *
     * @return array<string, mixed>
     */
    private function multiVariantJobRow(string $dir, string $input, string $variantsJson): array
    {
        return [
            'id' => 'seg-job',
            'hls_dir' => $dir,
            'input_path' => $input,
            'status' => 'completed',
            'duration_seconds' => 60,
            'segment_seconds' => 6,
            // segment_params is still present (BC) but must be IGNORED for a job
            // whose `variants` column is populated.
            'segment_params' => json_encode(['video_codec' => 'copy', 'audio_codec' => 'copy']),
            'variants' => $variantsJson,
        ];
    }

    public function testEnsureHlsJobBuildsLadderFromPersistedSourceMetadata(): void
    {
        // A1 persisted a 1080p H.264/AAC source. The live probe deliberately reports a
        // SMALLER 360p frame — if the ladder were (wrongly) built from the probe it
        // would cap at 360p. Proving a 1080p rung is present proves the A1 metadata
        // (not the probe) drove the ladder, and the persisted JSON must equal exactly
        // what AbrLadder::build() produces for that SourceProfile (genuinely usable).
        $source = [
            'width' => 1920,
            'height' => 1080,
            'video_codec' => 'h264',
            'video_bitrate' => 6000000,
            'audio_codec' => 'aac',
            'audio_bitrate' => 128000,
            'pix_fmt' => 'yuv420p',
        ];
        $captured = [];
        $db = $this->mockDb(
            [],
            0,
            ['path' => '/m.mkv', 'metadata_json' => json_encode(['source' => $source])],
            [],
            $captured
        );
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 640, 'height' => 360],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '600.0'],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
        $variantsJson = $this->capturedVariantsJson($captured);

        // Exactly what AbrLadder produces for the SAME SourceProfile → genuinely usable.
        $expected = (string) json_encode(
            (new AbrLadder())->build(SourceProfile::fromSourceMetadata($source), 'web')->toArray()
        );
        $this->assertSame($expected, $variantsJson);

        $decoded = json_decode($variantsJson, true);
        $this->assertIsArray($decoded);
        $ids = array_column($decoded['renditions'], 'id');
        $this->assertContains('1080p', $ids, 'ladder built from A1 metadata (not the 360p probe)');
    }

    public function testEnsureHlsJobFallsBackToProbeWhenSourceMetadataAbsent(): void
    {
        // No metadata_json['source'] → the ladder is derived from the live probe.
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720,
                    'bit_rate' => 2500000, 'pix_fmt' => 'yuv420p'],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2, 'bit_rate' => 128000],
            ],
            'format' => ['duration' => '600.0'],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
        $variantsJson = $this->capturedVariantsJson($captured);

        $expected = (string) json_encode(
            (new AbrLadder())->build(
                new SourceProfile(
                    width: 1280,
                    height: 720,
                    videoCodec: 'h264',
                    videoBitrate: 2500000,
                    audioCodec: 'aac',
                    audioBitrate: 128000,
                    pixFmt: 'yuv420p',
                ),
                'web'
            )->toArray()
        );
        $this->assertSame($expected, $variantsJson);

        $decoded = json_decode($variantsJson, true);
        $ids = array_column($decoded['renditions'], 'id');
        $this->assertContains('720p', $ids);
        $this->assertNotContains('1080p', $ids, 'no upscaling beyond the 720p probe');
    }

    public function testMasterListsEveryVariantHighestFirstWithCorrectAttrs(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080,
                    'bit_rate' => 6000000],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '60.0'],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
        $dir = $this->capturedJobInsert($captured)['hls_dir'];
        $master = (string) file_get_contents("{$dir}/master.m3u8");

        // Rebuild the ladder to know the expected order/attrs.
        $variants = (new AbrLadder())->build(
            new SourceProfile(width: 1920, height: 1080, videoCodec: 'h264', videoBitrate: 6000000, audioCodec: 'aac'),
            'web'
        )->streamVariants();

        // One STREAM-INF + media_v{id}.m3u8 per variant, in the SAME (highest-first) order.
        $lines = array_values(array_filter(explode("\n", $master), static fn (string $l): bool => $l !== ''));
        $streamLines = [];
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $streamLines[] = [$line, $lines[$i + 1] ?? ''];
            }
        }
        $this->assertCount(count($variants), $streamLines);
        foreach ($variants as $pos => $variant) {
            [$inf, $uri] = $streamLines[$pos];
            $this->assertStringContainsString('BANDWIDTH=' . $variant->bandwidth(), $inf);
            $this->assertStringContainsString('RESOLUTION=' . $variant->resolution(), $inf);
            $this->assertStringContainsString('CODECS="' . $variant->codecs . '"', $inf);
            $this->assertSame("media_v{$variant->id}.m3u8", $uri);
        }
        // Highest-first: the first STREAM-INF's resolution height ≥ the last's.
        $this->assertSame("media_v{$variants[0]->id}.m3u8", $streamLines[0][1]);
    }

    public function testMediaPlaylistTimingIdenticalAcrossVariants(): void
    {
        // Segment BOUNDARIES/TIMING must be identical across every variant — only the
        // filename prefix differs. Compare each variant's playlist with the segment
        // names normalised away.
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '25.0'],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
        $dir = $this->capturedJobInsert($captured)['hls_dir'];

        $playlists = glob("{$dir}/media_v*.m3u8") ?: [];
        $this->assertGreaterThanOrEqual(2, count($playlists));
        $normalised = null;
        foreach ($playlists as $file) {
            $body = (string) file_get_contents($file);
            // Strip the per-variant segment name so only the TIMELINE remains.
            $timeline = preg_replace('/seg-v[^-]+-(\d{5})\.ts/', 'SEG-$1.ts', $body);
            $normalised ??= $timeline;
            $this->assertSame($normalised, $timeline, "timing differs in {$file}");
            $this->assertStringContainsString('#EXT-X-PLAYLIST-TYPE:VOD', $body);
            $this->assertStringContainsString('#EXT-X-ENDLIST', $body);
        }
    }

    public function testEnsureSegmentResolvesRenditionVariant(): void
    {
        // A pinned rung (e.g. 480p) resolves to its Rendition and encodes the
        // correctly-prefixed seg-v480p-NNNNN.ts with the transcode param contract.
        $dir = $this->segmentDir . '/mv-rung';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $ladderJson = (string) json_encode(
            (new AbrLadder())->build(
                new SourceProfile(width: 1920, height: 1080, videoCodec: 'h264', videoBitrate: 6000000, audioCodec: 'aac'),
                'web'
            )->toArray()
        );
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->multiVariantJobRow($dir, $input, $ladderJson), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $captParams = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $start, float $len, array $params) use (&$captParams): int {
                $captParams = $params;
                file_put_contents($out, 'encoded');
                return 1;
            }
        );

        $path = $this->manager($db, $ff)->ensureSegment('seg-job', '480p', 2);

        $this->assertSame("{$dir}/seg-v480p-00002.ts", $path);
        // Transcode rung contract.
        $this->assertSame('libx264', $captParams['video_codec']);
        $this->assertSame('aac', $captParams['audio_codec']);
        $this->assertSame(854, $captParams['width']);
        $this->assertSame(480, $captParams['height']);
        $this->assertSame('yuv420p', $captParams['pix_fmt']);
        $this->assertSame('high', $captParams['profile']);
        $this->assertArrayHasKey('maxrate', $captParams);
        $this->assertArrayHasKey('bufsize', $captParams);
        $this->assertSame($captParams['maxrate'] * 2, $captParams['bufsize']);
    }

    public function testEnsureSegmentResolvesCopyOriginalVariant(): void
    {
        // The copy "original" (H.264 + AAC source) resolves to a genuine -c copy
        // contract and its own seg-voriginal-NNNNN.ts.
        $dir = $this->segmentDir . '/mv-orig';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $ladder = (new AbrLadder())->build(
            new SourceProfile(width: 1920, height: 1080, videoCodec: 'h264', videoBitrate: 6000000, audioCodec: 'aac'),
            'web'
        );
        $this->assertTrue($ladder->original->isCopy, 'H.264/AAC source → copy original');
        $ladderJson = (string) json_encode($ladder->toArray());
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->multiVariantJobRow($dir, $input, $ladderJson), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $captParams = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $start, float $len, array $params) use (&$captParams): int {
                $captParams = $params;
                file_put_contents($out, 'copied');
                return 1;
            }
        );

        $path = $this->manager($db, $ff)->ensureSegment('seg-job', 'original', 0);

        $this->assertSame("{$dir}/seg-voriginal-00000.ts", $path);
        $this->assertSame(['video_codec' => 'copy', 'audio_codec' => 'copy'], $captParams);
    }

    public function testEnsureSegmentReturnsNullForNonCopyOriginal(): void
    {
        // A NON-copy original (HEVC source → transcode) is NOT advertised in the
        // master, so ensureSegment('original', …) must resolve to null — nothing
        // ever requests it.
        $dir = $this->segmentDir . '/mv-orig-noncopy';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $ladder = (new AbrLadder())->build(
            new SourceProfile(width: 3840, height: 2160, videoCodec: 'hevc', videoBitrate: 20000000, audioCodec: 'ac3'),
            'web'
        );
        $this->assertFalse($ladder->original->isCopy, 'HEVC/AC3 source → non-copy original');
        $ladderJson = (string) json_encode($ladder->toArray());
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->multiVariantJobRow($dir, $input, $ladderJson), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('startSegmentEncode');

        $this->assertNull($this->manager($db, $ff)->ensureSegment('seg-job', 'original', 0));
    }

    public function testEnsureSegmentReturnsNullForUnknownVariant(): void
    {
        $dir = $this->segmentDir . '/mv-unknown';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $ladderJson = (string) json_encode(
            (new AbrLadder())->build(
                new SourceProfile(width: 1280, height: 720, videoCodec: 'h264', audioCodec: 'aac'),
                'web'
            )->toArray()
        );
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->multiVariantJobRow($dir, $input, $ladderJson), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('startSegmentEncode');

        // 4320p is not a rung this source produces.
        $this->assertNull($this->manager($db, $ff)->ensureSegment('seg-job', '4320p', 0));
        // A null variant against a multi-variant job is also unresolvable.
        $this->assertNull($this->manager($db, $ff)->ensureSegment('seg-job', null, 0));
    }

    public function testSegmentParamsForRenditionContract(): void
    {
        $method = new ReflectionMethod(TranscodeManager::class, 'segmentParamsForRendition');
        $method->setAccessible(true);

        // Copy rendition → minimal -c copy contract (A4 skips everything else).
        $copy = $method->invoke(null, ['is_copy' => true, 'width' => 1920, 'height' => 1080,
            'codecs' => 'avc1.640029,mp4a.40.2', 'video_bitrate' => 6000000]);
        $this->assertSame(['video_codec' => 'copy', 'audio_codec' => 'copy'], $copy);

        // Transcode rendition → capped-CRF H.264/AAC contract with derived VBV + level.
        $t = $method->invoke(null, ['is_copy' => false, 'width' => 1280, 'height' => 720,
            'codecs' => 'avc1.640029,mp4a.40.2', 'video_bitrate' => 2800000]);
        $this->assertSame('libx264', $t['video_codec']);
        $this->assertSame('veryfast', $t['preset']);
        $this->assertSame(23, $t['crf']);
        $this->assertSame('yuv420p', $t['pix_fmt']);
        $this->assertSame('high', $t['profile']);
        $this->assertSame('4.1', $t['level']);
        $this->assertSame(1280, $t['width']);
        $this->assertSame(720, $t['height']);
        $this->assertSame(2800000, $t['video_bitrate']);
        $this->assertSame((int) round(2800000 * 1.07), $t['maxrate']);
        $this->assertSame(((int) round(2800000 * 1.07)) * 2, $t['bufsize']);
        $this->assertSame('aac', $t['audio_codec']);
        $this->assertSame('128k', $t['audio_bitrate']);
        $this->assertSame(48000, $t['audio_sample_rate']);
    }

    public function testFfmpegLevelFromCodecsCoversAllKnownStrings(): void
    {
        $method = new ReflectionMethod(TranscodeManager::class, 'ffmpegLevelFromCodecs');
        $method->setAccessible(true);

        $cases = [
            'avc1.64001E,mp4a.40.2' => '3.0',
            'avc1.64001F,mp4a.40.2' => '3.1',
            'avc1.640020,mp4a.40.2' => '3.2',
            'avc1.640029,mp4a.40.2' => '4.1',
            'avc1.64002A,mp4a.40.2' => '4.2',
            'avc1.640032,mp4a.40.2' => '5.0',
            'avc1.640033,mp4a.40.2' => '5.1',
        ];
        foreach ($cases as $codecs => $expected) {
            $this->assertSame($expected, $method->invoke(null, $codecs), "level for {$codecs}");
        }
        // Defensive fallback for an unparseable / unmapped codec string.
        $this->assertSame('4.1', $method->invoke(null, 'garbage'));
        $this->assertSame('4.1', $method->invoke(null, 'avc1.6400FF,mp4a.40.2'));
    }

    public function testEnsureSegmentLegacyPathUnprefixedNameAndParams(): void
    {
        // Regression: a variants=NULL job still reads segment_params and writes the
        // legacy unprefixed seg-NNNNN.ts, ignoring any variant argument.
        $dir = $this->segmentDir . '/legacy-seg';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        // onDemandJobRow has segment_params + NO variants key → legacy path.
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $captParams = null;
        $captOut = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $s, float $l, array $params) use (&$captParams, &$captOut): int {
                $captParams = $params;
                $captOut = $out;
                file_put_contents($out, 'encoded');
                return 1;
            }
        );

        // Pass a bogus variant — it MUST be ignored on the legacy path.
        $path = $this->manager($db, $ff)->ensureSegment('seg-job', '1080p', 3);

        $this->assertSame("{$dir}/seg-00003.ts", $path);
        $this->assertSame("{$dir}/seg-00003.ts", $captOut);
        // Params come from the legacy segment_params column verbatim.
        $this->assertSame('libx264', $captParams['video_codec']);
        $this->assertSame('aac', $captParams['audio_codec']);
    }

    // --- Audit tests: the FS-glob helpers must see variant-prefixed filenames ---

    public function testSegmentEncodeInFlightDedupsPerVariant(): void
    {
        // A seg-v720p-00002 encode is already in flight (its .part- temp exists) —
        // ensureSegment for that exact variant/index must NOT relaunch (per-variant dedup).
        $dir = $this->segmentDir . '/audit-dedup';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        file_put_contents("{$dir}/seg-v720p-00002.ts.part-deadbeef", 'partial');
        $ladderJson = (string) json_encode(
            (new AbrLadder())->build(
                new SourceProfile(width: 1280, height: 720, videoCodec: 'h264', audioCodec: 'aac'),
                'web'
            )->toArray()
        );
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->multiVariantJobRow($dir, $input, $ladderJson), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('startSegmentEncode');

        $this->assertNull($this->segManager($db, $ff)->ensureSegment('seg-job', '720p', 2));
    }

    public function testCountInFlightSegmentEncodesCountsMixedVariants(): void
    {
        // The global cap globs seg-*.ts.part-* — it must count variant-prefixed temps
        // from DIFFERENT variants across DIFFERENT jobs. Two are in flight; with a
        // ceiling of 2 a fresh (different-variant) encode fast-fails 503.
        $dir = $this->segmentDir . '/audit-cap';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $otherA = $this->segmentDir . '/other-a';
        $otherB = $this->segmentDir . '/other-b';
        mkdir($otherA, 0755, true);
        mkdir($otherB, 0755, true);
        file_put_contents("{$otherA}/seg-v240p-00000.ts.part-aaaaaaaa", 'p');
        file_put_contents("{$otherB}/seg-v1080p-00005.ts.part-bbbbbbbb", 'p');
        $ladderJson = (string) json_encode(
            (new AbrLadder())->build(
                new SourceProfile(width: 1280, height: 720, videoCodec: 'h264', audioCodec: 'aac'),
                'web'
            )->toArray()
        );
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->multiVariantJobRow($dir, $input, $ladderJson), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('startSegmentEncode');

        $this->expectException(SegmentBusyException::class);
        $this->segManager($db, $ff, 2)->ensureSegment('seg-job', '720p', 1);
    }

    public function testCountSegmentsIncludesAllVariantSegments(): void
    {
        // getJobReadiness()'s segment count globs seg-*.ts → it must total segments
        // across every variant present in the job dir.
        $dir = $this->segmentDir . '/audit-count';
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/master.m3u8", "#EXTM3U\n");
        file_put_contents("{$dir}/seg-v240p-00000.ts", 'x');
        file_put_contents("{$dir}/seg-v240p-00001.ts", 'x');
        file_put_contents("{$dir}/seg-v1080p-00000.ts", 'x');
        // A half-written temp must NOT be counted.
        file_put_contents("{$dir}/seg-v1080p-00001.ts.part-cccccccc", 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], ['hls_dir' => $dir, 'status' => 'completed'], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $r = $this->manager($db, $ff)->getJobReadiness('audit-count');

        $this->assertSame(3, $r['segments']);
    }

    public function testSweepSegmentCacheReclaimsDirWithVariantSegments(): void
    {
        // The cache sweep is directory-level; it must reclaim a stale job dir whose
        // contents are variant-prefixed segments (dirSize globs {dir}/*).
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $old = $this->segmentDir . '/audit-sweep-old';
        mkdir($old, 0755, true);
        file_put_contents("{$old}/seg-v240p-00000.ts", str_repeat('x', 2048));
        file_put_contents("{$old}/seg-v1080p-00000.ts", str_repeat('y', 2048));
        touch($old, time() - 10);

        $reaped = $this->segManager($db, $ff, null, null, 1)->sweepSegmentCache();

        $this->assertSame(1, $reaped);
        $this->assertDirectoryDoesNotExist($old);
    }

    public function testGetJobVariantsPrependsCopyOriginal(): void
    {
        // H.264 + AAC 1080p source → the "original" is a genuine stream-copy variant,
        // so getJobVariants() must PREPEND it (highest) ahead of the clamped rungs,
        // exactly like LadderResult::streamVariants().
        $ladder = (new AbrLadder())->build(
            new SourceProfile(width: 1920, height: 1080, videoCodec: 'h264', videoBitrate: 6000000, audioCodec: 'aac'),
            'web'
        );
        $this->assertTrue($ladder->original->isCopy, 'H.264/AAC source → copy original');
        $ladderJson = (string) json_encode($ladder->toArray());
        $captured = [];
        $db = $this->mockDb([], 0, [], ['id' => 'seg-job', 'variants' => $ladderJson], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $variants = $this->manager($db, $ff)->getJobVariants('seg-job');

        $this->assertNotNull($variants);
        // Same membership + order as the streamVariants() dedup rule.
        $expectedIds = array_map(
            static fn ($r): string => $r->id,
            $ladder->streamVariants(),
        );
        $this->assertSame($expectedIds, array_map(static fn (array $v): string => (string) $v['id'], $variants));
        // The copy original is the highest (first) entry.
        $this->assertSame('original', $variants[0]['id']);
        $this->assertTrue($variants[0]['is_copy']);
        // Every entry carries its own signed-later media playlist url.
        foreach ($variants as $entry) {
            $this->assertSame("/hls/seg-job/media_v{$entry['id']}.m3u8", $entry['url']);
            // Flat Rendition shape preserved.
            $this->assertArrayHasKey('label', $entry);
            $this->assertArrayHasKey('height', $entry);
            $this->assertArrayHasKey('bitrate', $entry);
            $this->assertArrayHasKey('codecs', $entry);
        }
    }

    public function testGetJobVariantsOmitsNonCopyOriginal(): void
    {
        // HEVC + AC3 source → the "original" is NOT a copy (it mirrors the top
        // transcode rung), so it must NOT appear as a separate variant — the list
        // is exactly the clamped rungs, highest-first.
        $ladder = (new AbrLadder())->build(
            new SourceProfile(width: 3840, height: 2160, videoCodec: 'hevc', videoBitrate: 20000000, audioCodec: 'ac3'),
            'web'
        );
        $this->assertFalse($ladder->original->isCopy, 'HEVC/AC3 source → non-copy original');
        $ladderJson = (string) json_encode($ladder->toArray());
        $captured = [];
        $db = $this->mockDb([], 0, [], ['id' => 'seg-job', 'variants' => $ladderJson], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $variants = $this->manager($db, $ff)->getJobVariants('seg-job');

        $this->assertNotNull($variants);
        $ids = array_map(static fn (array $v): string => (string) $v['id'], $variants);
        $this->assertNotContains('original', $ids, 'non-copy original must not be listed');
        // Membership + order equals the clamped rungs highest-first.
        $expectedIds = array_map(static fn ($r): string => $r->id, $ladder->streamVariants());
        $this->assertSame($expectedIds, $ids);
        // Highest-first: first entry is the tallest rung.
        $this->assertSame('1080p', $variants[0]['id']); // web profile caps at 1080p
        foreach ($variants as $entry) {
            $this->assertSame("/hls/seg-job/media_v{$entry['id']}.m3u8", $entry['url']);
        }
    }

    public function testGetJobVariantsReturnsNullForLegacyJob(): void
    {
        // A legacy job (variants IS NULL) must return null so callers advertise
        // `variants: null` and old clients see a byte-compatible response.
        $captured = [];
        $db = $this->mockDb([], 0, [], ['id' => 'seg-job', 'status' => 'completed'], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $this->assertNull($this->manager($db, $ff)->getJobVariants('seg-job'));
    }

    public function testGetJobVariantsReturnsNullForUnknownJob(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, [], [], $captured); // no job row
        $ff = $this->createMock(FfmpegRunner::class);

        $this->assertNull($this->manager($db, $ff)->getJobVariants('nope'));
    }

    public function testGetJobVariantsHandlesMalformedVariantsJsonGracefully(): void
    {
        // A corrupt `variants` column must degrade to null, never throw — this
        // reads a DB column that should be well-formed but must not blow up a request.
        $captured = [];
        $db = $this->mockDb([], 0, [], ['id' => 'seg-job', 'variants' => '{not valid json'], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $this->assertNull($this->manager($db, $ff)->getJobVariants('seg-job'));
    }

    // --- S2: in-worker in-flight segment counter (drop hot-path globbing) ---

    /**
     * Reads the private in-worker in-flight set.
     *
     * @return array<string, int> final segment path → monotonic launch ms
     */
    private function inFlightSet(TranscodeManager $m): array
    {
        $p = new \ReflectionProperty(TranscodeManager::class, 'segmentEncodesInFlight');
        $p->setAccessible(true);
        /** @var array<string, int> $value */
        $value = $p->getValue($m);

        return $value;
    }

    /**
     * Reads the private global in-flight snapshot.
     *
     * @return array<string, true>
     */
    private function inFlightSnapshot(TranscodeManager $m): array
    {
        $p = new \ReflectionProperty(TranscodeManager::class, 'globalInFlightSnapshot');
        $p->setAccessible(true);
        /** @var array<string, true> $value */
        $value = $p->getValue($m);

        return $value;
    }

    private function setPrivate(TranscodeManager $m, string $prop, mixed $value): void
    {
        $p = new \ReflectionProperty(TranscodeManager::class, $prop);
        $p->setAccessible(true);
        $p->setValue($m, $value);
    }

    private function callPrivate(TranscodeManager $m, string $method, mixed ...$args): mixed
    {
        $r = new ReflectionMethod(TranscodeManager::class, $method);
        $r->setAccessible(true);

        return $r->invoke($m, ...$args);
    }

    public function testInFlightCounterIncrementsOnLaunchThenReleasesOnCompletion(): void
    {
        $dir = $this->segmentDir . '/s2-complete';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff);

        $final = "{$dir}/seg-00002.ts";
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out) use ($manager, $final): int {
                // The launch is recorded in-worker BEFORE ffmpeg is spawned, so a
                // concurrent same-segment request dedups against it immediately.
                $this->assertArrayHasKey($final, $this->inFlightSet($manager));
                file_put_contents($out, 'encoded'); // encode completes
                return 4242;
            }
        );

        $path = $manager->ensureSegment('seg-job', null, 2);

        $this->assertSame($final, $path);
        // Completion → the launcher's finally releases the slot.
        $this->assertSame([], $this->inFlightSet($manager));
    }

    public function testInFlightCounterReleasedViaFinallyWhenEncodeFails(): void
    {
        // A launched encode that never publishes its segment (ffmpeg died) must
        // still release this worker's slot via the finally — a leaked increment
        // would otherwise permanently over-count and wrongly 503 forever.
        $dir = $this->segmentDir . '/s2-fail';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff, null, null, null, 100); // 100ms wait ceiling

        $final = "{$dir}/seg-00001.ts";
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function () use ($manager, $final): int {
                $this->assertArrayHasKey($final, $this->inFlightSet($manager)); // incremented
                return 0; // launch "failed" — no segment file ever appears
            }
        );

        $this->assertNull($manager->ensureSegment('seg-job', null, 1));
        $this->assertSame([], $this->inFlightSet($manager), 'finally releases the slot on failure');
    }

    public function testInFlightCounterReleasedWhenLaunchThrowsBeforePolling(): void
    {
        // Review Finding 2 regression: the increment + touchJobDir() + the ffmpeg
        // launch call must ALL run inside the try, so a throwable from the launch
        // itself (not just a poll-loop failure) still reaches the finally and
        // releases the slot. Before the fix, the increment sat OUTSIDE the try, so
        // this exact scenario leaked the entry permanently (a worker that leaks and
        // then goes idle never revisits this code to self-heal).
        $dir = $this->segmentDir . '/s2-throw';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff, null, null, null, 100);

        $final = "{$dir}/seg-00003.ts";
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function () use ($manager, $final): int {
                $this->assertArrayHasKey($final, $this->inFlightSet($manager)); // incremented first
                throw new \RuntimeException('ffmpeg arg builder blew up');
            }
        );

        $thrown = null;
        try {
            $manager->ensureSegment('seg-job', null, 3);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'the RuntimeException must propagate, not be swallowed');
        $this->assertSame([], $this->inFlightSet($manager), 'finally releases the slot even on a launch-time throw');
    }

    public function testInFlightCounterKeyedPerJobVariantAndIndex(): void
    {
        // segmentEncodeInFlight() (DEDUP) is memory-based and keyed per exact
        // (job, variant, index) path — this is unaffected by the review fix, which
        // only changed countInFlightSegmentEncodes() (the CAP) to read the shared
        // tree live instead of this in-worker set.
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff);

        $a = "{$this->segmentDir}/jobA/seg-v720p-00000.ts";
        $b = "{$this->segmentDir}/jobA/seg-v480p-00000.ts"; // same job, different variant
        $c = "{$this->segmentDir}/jobB/seg-v720p-00000.ts"; // different job, untracked
        $this->setPrivate($manager, 'segmentEncodesInFlight', [$a => 10, $b => 20]);

        $this->assertTrue($this->callPrivate($manager, 'segmentEncodeInFlight', $a));
        $this->assertTrue($this->callPrivate($manager, 'segmentEncodeInFlight', $b)); // isolated key
        $this->assertFalse($this->callPrivate($manager, 'segmentEncodeInFlight', $c)); // untracked
    }

    public function testGlobalCapIgnoresInWorkerCounterAndReadsSharedTreeLive(): void
    {
        // Review Finding 1 fix: the CAP must NOT fire from the in-worker counter
        // alone. Two "phantom" entries are tracked in-worker with no backing
        // `.part-*` files on disk — if the cap still consulted memory, this would
        // wrongly throw SegmentBusyException at a ceiling of 2. It must NOT, because
        // the cap now reads the shared tree live and the tree is empty.
        $dir = $this->segmentDir . '/s2-cap-live';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out): int {
                file_put_contents($out, 'encoded');
                return 4242;
            }
        );
        $manager = $this->segManager($db, $ff, 2); // ceiling = 2

        $now = (int) (hrtime(true) / 1_000_000);
        $this->setPrivate($manager, 'segmentEncodesInFlight', [
            "{$this->segmentDir}/x/seg-00000.ts" => $now,
            "{$this->segmentDir}/y/seg-00000.ts" => $now,
        ]);

        // No SegmentBusyException: the live glob sees zero real `.part-*` files, so
        // the encode is launched normally despite the (stale/phantom) in-worker count.
        $path = $manager->ensureSegment('seg-job', null, 5);
        $this->assertNotNull($path);
    }

    public function testGlobalCapEnforcedFromRealPartFilesOnDiskNotInWorkerState(): void
    {
        // Mirror of the above: the cap MUST fire from genuine `.part-*` files on
        // disk even with an EMPTY in-worker set (e.g. a fresh worker, or one that
        // never launched these particular encodes itself — a sibling process did).
        $dir = $this->segmentDir . '/s2-cap-disk';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $otherA = $this->segmentDir . '/s2-cap-disk-a';
        $otherB = $this->segmentDir . '/s2-cap-disk-b';
        mkdir($otherA, 0755, true);
        mkdir($otherB, 0755, true);
        file_put_contents("{$otherA}/seg-00000.ts.part-aaaaaaaa", 'p');
        file_put_contents("{$otherB}/seg-00000.ts.part-bbbbbbbb", 'p');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('startSegmentEncode');
        $manager = $this->segManager($db, $ff, 2); // ceiling = 2

        // segmentEncodesInFlight is empty here — this worker launched nothing — yet
        // the cap still trips because it reads the real files on disk directly.
        $this->assertSame([], $this->inFlightSet($manager));

        $this->expectException(SegmentBusyException::class);
        $manager->ensureSegment('seg-job', null, 5);
    }

    public function testReconcileKeepsLiveDropsCompletedAndDeadEntries(): void
    {
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff);

        $jobDir = "{$this->segmentDir}/recon";
        mkdir($jobDir, 0755, true);

        $live = "{$jobDir}/seg-v720p-00000.ts";        // has a live .part-* → keep
        file_put_contents($live . '.part-deadbeef', 'p');
        $done = "{$jobDir}/seg-v720p-00001.ts";        // final published, no part → drop
        file_put_contents($done, 'x');
        $dead = "{$jobDir}/seg-v720p-00002.ts";        // no part, no final, stale → drop

        $now = (int) (hrtime(true) / 1_000_000);
        $grace = (new \ReflectionClassConstant(TranscodeManager::class, 'SEGMENT_INFLIGHT_STALE_GRACE_MS'))
            ->getValue();
        $this->assertIsInt($grace);
        $this->setPrivate($manager, 'segmentEncodesInFlight', [
            $live => $now,
            $done => $now,
            $dead => $now - $grace - 1000, // past the grace window
        ]);

        $this->callPrivate($manager, 'reconcileInFlightSegments');

        $this->assertSame([$live], array_keys($this->inFlightSet($manager)), 'only the live encode is retained');
        // The global snapshot mirrors the live `.part-*` across the shared tree.
        $this->assertSame([$live => true], $this->inFlightSnapshot($manager));
    }

    public function testReconcileGlobThrottledWithinWindow(): void
    {
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff);

        $jobDir = "{$this->segmentDir}/throttle";
        mkdir($jobDir, 0755, true);

        // First reconcile: empty tree → empty snapshot (and stamps the throttle).
        $this->callPrivate($manager, 'reconcileInFlightSegments');
        $this->assertSame([], $this->inFlightSnapshot($manager));

        // A new encode appears on disk...
        file_put_contents("{$jobDir}/seg-v720p-00000.ts.part-abcd1234", 'p');

        // ...but a second reconcile within the throttle window must NOT re-glob:
        // the hot path is served from memory, not the filesystem, between windows.
        $this->callPrivate($manager, 'reconcileInFlightSegments');
        $this->assertSame([], $this->inFlightSnapshot($manager), 'throttled: snapshot not refreshed within the window');
    }

    public function testReconcileReglobsAfterThrottleWindowElapses(): void
    {
        // The other half of the throttle contract (testReconcileGlobThrottledWithinWindow
        // proves the "within window → no re-glob" side): once more than
        // SEGMENT_INFLIGHT_RECONCILE_INTERVAL_MS has passed since the last glob, the
        // NEXT reconcile MUST re-glob the shared tree and pick up a sibling worker's
        // freshly-appeared `.part-*` — otherwise cross-worker dedup would go
        // permanently stale after the very first refresh. This exercises the throttle
        // end-to-end (expiry actually fires the glob), not just the stamp-before-glob
        // ordering.
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff);

        $jobDir = "{$this->segmentDir}/throttle-expiry";
        mkdir($jobDir, 0755, true);

        // Prime the throttle, then make it look like the last glob happened well
        // outside the window (window is 1000ms; back-date by 2000ms).
        $this->callPrivate($manager, 'reconcileInFlightSegments');
        $this->assertSame([], $this->inFlightSnapshot($manager));

        $interval = (new \ReflectionClassConstant(
            TranscodeManager::class,
            'SEGMENT_INFLIGHT_RECONCILE_INTERVAL_MS'
        ))->getValue();
        $this->assertIsInt($interval);
        $now = (int) (hrtime(true) / 1_000_000);
        $this->setPrivate($manager, 'lastInFlightReconcileMs', $now - $interval - 1000);

        // A sibling worker's encode appears on the shared tree...
        $final = "{$jobDir}/seg-v720p-00007.ts";
        file_put_contents($final . '.part-cafebabe', 'p');

        // ...and because the window has elapsed, this reconcile DOES re-glob and
        // refreshes the cross-worker snapshot to include it.
        $this->callPrivate($manager, 'reconcileInFlightSegments');
        $this->assertSame([$final => true], $this->inFlightSnapshot($manager), 'window elapsed: snapshot re-globbed and refreshed');
    }

    public function testDedupIgnoresOnDiskPartFilesProvingNoHotPathGlob(): void
    {
        // The core hot-path-relief claim, proven from the opposite direction: a live
        // `.part-*` for this exact segment sits on disk, yet BOTH in-worker bookkeeping
        // structures are empty. The pre-S2 glob-based segmentEncodeInFlight() would
        // have returned TRUE here (it globbed `{final}.part-*`); the memory-based
        // version must return FALSE, proving the dedup check genuinely performs zero
        // filesystem access on the hot path and reads only in-worker state.
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff);

        $jobDir = "{$this->segmentDir}/no-glob";
        mkdir($jobDir, 0755, true);
        $final = "{$jobDir}/seg-v720p-00000.ts";
        file_put_contents($final . '.part-deadbeef', 'p'); // a real in-flight temp on disk

        $this->assertSame([], $this->inFlightSet($manager));
        $this->assertSame([], $this->inFlightSnapshot($manager));
        $this->assertFalse(
            $this->callPrivate($manager, 'segmentEncodeInFlight', $final),
            'dedup must ignore on-disk .part-* files — it reads only in-worker state (no hot-path glob)'
        );
    }

    public function testDedupMatchesSiblingWorkerViaGlobalSnapshot(): void
    {
        // Cross-worker dedup relief: an encode this worker never launched (empty local
        // set) is still deduplicated when it appears in the throttled global snapshot
        // that reconcileInFlightSegments() populates from sibling workers' `.part-*`
        // temps — again with no per-call glob.
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff);

        $final = "{$this->segmentDir}/sibling/seg-v480p-00003.ts";
        $this->setPrivate($manager, 'globalInFlightSnapshot', [$final => true]);

        $this->assertSame([], $this->inFlightSet($manager), 'this worker launched nothing');
        $this->assertTrue(
            $this->callPrivate($manager, 'segmentEncodeInFlight', $final),
            'a sibling worker in-flight encode is deduplicated via the global snapshot'
        );
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
