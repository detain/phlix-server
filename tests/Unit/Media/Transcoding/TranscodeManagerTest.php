<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\AbrLadder;
use Phlix\Media\Streaming\SourceProfile;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\SegmentBusyException;
use Phlix\Media\Transcoding\SegmentCacheFullException;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
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
     * @param array<string, mixed> $colorRow SV-1.1(a) row returned for the persisted
     *                                        media_streams video-stream color lookup
     *                                        ([] = none → decision falls back to the probe).
     */
    private function mockDb(
        array $reuseRow,
        int $runningCount,
        array $mediaRow,
        array $jobRow,
        array &$captured,
        array $colorRow = []
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
                $colorRow,
                &$captured
            ) {
                $captured[] = [$sql, $params ?? []];
                if (str_contains($sql, 'key_hash = ?') && str_contains($sql, 'IN (')) {
                    return $reuseRow === [] ? [] : [$reuseRow];
                }
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['c' => $runningCount]];
                }
                // SV-1.1(a): the persisted video-stream color lookup. Checked before
                // the media_items branch — 'media_streams' does not contain the
                // 'media_items' substring, but order it explicitly for clarity.
                if (str_contains($sql, 'FROM media_streams') && str_contains($sql, "stream_type = 'video'")) {
                    return $colorRow === [] ? [] : [$colorRow];
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

    /**
     * @param FfmpegRunner&MockObject $ff
     */
    private function manager(Connection $db, FfmpegRunner $ff): TranscodeManager
    {
        $this->stubColorMetadata($ff);
        return new TranscodeManager($db, $ff, $this->segmentDir, null, 6);
    }

    /**
     * FfmpegRunner::extractColorMetadata() always returns a fully-populated
     * shape in production, so computeHlsParams() reads its keys unguarded.
     * A bare mock returns null → "Undefined array key" warnings; stub the
     * default here so the mock honours the real contract.
     *
     * @param FfmpegRunner&MockObject $ff
     */
    private function stubColorMetadata(FfmpegRunner $ff): void
    {
        $ff->method('extractColorMetadata')->willReturn([
            'color_space' => 'bt709',
            'color_transfer' => 'bt709',
            'color_primaries' => 'bt709',
            'max_luminance' => 1000.0,
            'avg_luminance' => 200.0,
        ]);
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
            $hlsDir = $params[4] ?? '';
            $duration = $params[11] ?? 0;
            $segmentSeconds = $params[12] ?? 0;
            return [
                'hls_dir' => is_scalar($hlsDir) ? (string) $hlsDir : '',
                'duration' => is_numeric($duration) ? (int) $duration : 0,
                'segment_seconds' => is_numeric($segmentSeconds) ? (int) $segmentSeconds : 0,
                'segment_params' => is_array($segParams) ? $segParams : [],
            ];
        }
        $this->fail('no transcode_jobs INSERT was captured');
    }

    /**
     * Extracts the string `id` from a job-variant array (asserting its type).
     *
     * @param array<string, mixed> $variant
     */
    private function variantId(array $variant): string
    {
        $id = $variant['id'] ?? null;
        $this->assertIsString($id);
        return $id;
    }

    /**
     * The master playlist's ADVERTISED LEVEL SET, in master order: one
     * `{id, bandwidth, resolution}` per `#EXT-X-STREAM-INF` + URI pair, with the id
     * taken from the `media_v{id}.m3u8` URI that follows it.
     *
     * This is the client-visible ABR contract — which qualities a player can adapt
     * across, and (because `phlix-ui` resolves "Original" by matching a level of the
     * same height) which qualities its menu can even offer. S49 fix round 1 pins it
     * per source shape so a future re-fold cannot silently drop the top level again.
     *
     * @param string $dir Job directory containing `master.m3u8`.
     *
     * @return list<array{id: string, bandwidth: int, resolution: string}>
     */
    private function masterLevels(string $dir): array
    {
        $master = (string) file_get_contents("{$dir}/master.m3u8");
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $master)),
            static fn (string $l): bool => $l !== ''
        ));

        $levels = [];
        foreach ($lines as $i => $line) {
            if (!str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                continue;
            }
            $uri = $lines[$i + 1] ?? '';
            $this->assertMatchesRegularExpression(
                '/^media_v[a-z0-9]+\.m3u8$/',
                $uri,
                'every STREAM-INF must be followed by its per-variant media playlist URI'
            );
            $this->assertSame(1, preg_match('/BANDWIDTH=(\d+)/', $line, $bw), $line);
            $this->assertSame(1, preg_match('/RESOLUTION=(\d+x\d+)/', $line, $res), $line);
            $levels[] = [
                'id' => (string) preg_replace('/^media_v|\.m3u8$/', '', $uri),
                'bandwidth' => (int) $bw[1],
                'resolution' => $res[1],
            ];
        }

        return $levels;
    }

    /**
     * Runs `ensureHlsJob('media-1', 'web')` against a mocked probe of the given
     * source shape and returns the job directory the playlists were written to.
     *
     * @param array<string, mixed> $video A probe video-stream shape.
     * @param array<string, mixed> $audio A probe audio-stream shape.
     */
    private function ensureJobForSource(array $video, array $audio, string $duration = '25.0'): string
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video'] + $video,
                ['codec_type' => 'audio'] + $audio,
            ],
            'format' => ['duration' => $duration],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        return $this->capturedJobInsert($captured)['hls_dir'];
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

        $result = $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        $this->assertTrue($result['reused']);
        $this->assertSame('existing-job', $result['job_id']);
        $this->assertStringContainsString('/hls/existing-job/master.m3u8', $result['master_url']);
        // S59 REPLACES S11's `assertArrayNotHasKey('dash_url')` here. S11 removed
        // the key because it always pointed at a manifest nothing wrote; S59 built
        // the producer (S58) and the serve path, so the key is back — but GATED on
        // the manifest existing. This job dir has no `manifest.mpd`, so the key
        // must be PRESENT and NULL: present, because a client checks `!= null`
        // rather than guessing from key absence; null, because advertising a URL
        // for a file that is not there is exactly the S11 defect.
        $this->assertArrayHasKey('dash_url', $result);
        $this->assertNull($result['dash_url'], 'a job with no manifest.mpd must advertise no DASH URL');
        $this->assertNoOtherDashKey($result);
    }

    /**
     * The POSITIVE control for the case above — and it is not optional.
     *
     * S59 measured this: mutating the reused branch to a hard-coded
     * `'dash_url' => null` SURVIVED the whole suite, because the only fixture
     * that reached it had no `manifest.mpd` and so expected null either way. The
     * assertion could not tell a computed null from a constant one. This case
     * gives the reused branch a job dir that DOES carry a manifest, so the branch
     * has to have consulted the disk to answer correctly.
     */
    public function testEnsureHlsJobAdvertisesTheDashUrlWhenTheReusedJobHasAManifest(): void
    {
        $existingDir = $this->segmentDir . '/existing-job';
        mkdir($existingDir, 0755, true);
        file_put_contents($existingDir . '/' . TranscodeManager::MPD_FILENAME, '<?xml version="1.0"?><MPD/>');
        $captured = [];
        $db = $this->mockDb(
            ['id' => 'existing-job', 'hls_dir' => $existingDir, 'status' => 'running'],
            0,
            [],
            ['status' => 'running'],
            $captured
        );

        $result = $this->manager($db, $this->createMock(FfmpegRunner::class))->ensureHlsJob('media-1', 'web');

        $this->assertTrue($result['reused'], 'fixture must exercise the REUSED return, not the fresh one');
        $this->assertSame('/dash/existing-job/manifest.mpd', $result['dash_url']);
    }

    /**
     * The OTHER `ensureHlsJob()` return array — the fresh-job branch.
     *
     * S11 named two sites in `TranscodeManager` (the reused-job and fresh-job
     * returns) but only the reused-job branch above got a guard. Re-adding
     * `'dash_url' => "/dash/{$jobId}/manifest.mpd"` to the FRESH-job return left
     * the whole Unit suite (8,469 tests) green, so half the fix was unpinned:
     * the very first play of an item — the only branch that runs when no job
     * exists yet — could start advertising the 404'ing key again unnoticed.
     *
     * S59 keeps this case for the same reason and flips its sense: the branch
     * must now emit the key, and must emit it as NULL for a job that published no
     * manifest (this fixture is `segment_format=mpegts`, the shipped default). A
     * branch that hardcoded the URL back would fail here exactly as it used to.
     */
    public function testEnsureHlsJobAdvertisesNoDashUrlOnTheFreshJobBranchWithoutAManifest(): void
    {
        $captured = [];
        // Empty reuse row => findReusableJob() misses => the fresh-encode branch.
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '25.0'],
        ]);

        $result = $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        // Prove we really are on the fresh branch and not silently reusing.
        $this->assertFalse($result['reused'], 'fixture must exercise the fresh-encode return');
        $this->assertArrayHasKey('dash_url', $result);
        $this->assertNull($result['dash_url'], 'an mpegts job writes no manifest.mpd, so it advertises none');
        $this->assertNoOtherDashKey($result);
    }

    /**
     * `dash_url` is the ONE key an `ensureHlsJob()` payload may spell with "dash".
     *
     * S11's version of this helper forbade the substring outright, to catch a
     * re-introduction that RENAMED rather than restored. S59 restores exactly one
     * key, so the helper keeps its job by allow-listing exactly that spelling:
     * a second DASH key under any other name (`dashUrl`, `dash_manifest`, …) is
     * still a failure, and a client written against `dash_url` still cannot be
     * silently switched to a different key.
     *
     * @param array<string, mixed> $payload
     */
    private function assertNoOtherDashKey(array $payload): void
    {
        foreach (array_keys($payload) as $key) {
            if ((string) $key === 'dash_url') {
                continue;
            }
            $this->assertStringNotContainsStringIgnoringCase(
                'dash',
                (string) $key,
                'the only DASH key a transcode payload may carry is `dash_url` (S59)',
            );
        }
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

    /**
     * SV-1.6: `ensureHlsJob()`'s `subtitle_burn_in_index`/`force_subtitle_burn_in`
     * options (matching {@see \Phlix\Media\Streaming\StreamManager::getSubtitleBurnInConfig()}'s
     * shape) are persisted into the job's base `segment_params` so
     * {@see TranscodeManager::applySubtitleBurnIn()} can resolve them per
     * segment later. Defaults (no option passed) persist a null/false pair —
     * no behavior change for every existing caller.
     */
    public function testEnsureHlsJobPersistsSubtitleBurnInOptions(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '600.0'],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web', [
            'subtitle_burn_in_index' => 2,
            'force_subtitle_burn_in' => true,
        ]);
        $p = $this->capturedJobInsert($captured)['segment_params'];

        $this->assertSame(2, $p['subtitle_burn_in_index']);
        $this->assertTrue($p['force_subtitle_burn_in']);
    }

    public function testEnsureHlsJobDefaultsSubtitleBurnInToDisabled(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '600.0'],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
        $p = $this->capturedJobInsert($captured)['segment_params'];

        $this->assertNull($p['subtitle_burn_in_index']);
        $this->assertFalse($p['force_subtitle_burn_in']);
    }

    /**
     * SV-1.1(b): for an HDR source, computeSegmentParams resolves the tone-map
     * filter STRING once (via FfmpegRunner::resolveToneMapFilterFromProbe, with
     * the FINAL video codec) and threads it into `segment_params` alongside
     * `require_hdr_tone_map` — and the pair survives the segment_params JSON
     * encode→decode (capturedJobInsert decodes the persisted JSON).
     */
    public function testEnsureHlsJobThreadsResolvedToneMapFilterForHdrSource(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);

        $canon = 'zscale=t=linear:npl=100,format=gbrpf32le,'
            . 'zscale=p=bt709,tonemap=hable:desat=0,'
            . 'zscale=t=bt709:m=bt709:r=tv,format=yuv420p';

        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'hevc', 'width' => 3840, 'height' => 2160],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '600.0'],
        ]);
        // HDR color metadata → computeHlsParams sets require_hdr_tone_map.
        $ff->method('extractColorMetadata')->willReturn([
            'color_space' => 'bt2020nc',
            'color_transfer' => 'smpte2084',
            'color_primaries' => 'bt2020',
            'max_luminance' => 1000.0,
            'avg_luminance' => 200.0,
        ]);
        // The filter is resolved ONCE, with the final (post copy→libx264) codec.
        $ff->expects($this->once())
            ->method('resolveToneMapFilterFromProbe')
            ->with($this->anything(), 'libx264')
            ->willReturn($canon);

        // Not via manager() — that stubs extractColorMetadata to a NON-HDR shape.
        $manager = new TranscodeManager($db, $ff, $this->segmentDir, null, 6);
        $manager->ensureHlsJob('media-1', 'web');

        $p = $this->capturedJobInsert($captured)['segment_params'];
        $this->assertTrue($p['require_hdr_tone_map']);
        $this->assertSame($canon, $p['tone_map_filter']);
    }

    /**
     * SV-1.1(b): a NON-HDR source neither flags require_hdr_tone_map nor threads a
     * tone_map_filter — so the resolver is never consulted and the extra key stays
     * absent from segment_params (no behavior change for the common SDR case).
     */
    public function testEnsureHlsJobOmitsToneMapFilterForSdrSource(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'hevc', 'width' => 3840, 'height' => 2160],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '600.0'],
        ]);
        $ff->expects($this->never())->method('resolveToneMapFilterFromProbe');

        // manager() stubs a NON-HDR extractColorMetadata (bt709).
        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        $p = $this->capturedJobInsert($captured)['segment_params'];
        $this->assertArrayNotHasKey('require_hdr_tone_map', $p);
        $this->assertArrayNotHasKey('tone_map_filter', $p);
    }

    /**
     * The canonical zscale HDR→SDR graph, reused across the SV-1.1(a) tests as the
     * tone_map_filter value so an assertion doubles as a byte-identity check.
     */
    private const CANON_TONE_MAP =
        'zscale=t=linear:npl=100,format=gbrpf32le,'
        . 'zscale=p=bt709,tonemap=hable:desat=0,'
        . 'zscale=t=bt709:m=bt709:r=tv,format=yuv420p';

    /**
     * HDR10 color columns as the scanner persists them (migration 073) on the
     * video-stream row — DECIMAL luminance arrives from the driver as strings.
     *
     * @return array<string, mixed>
     */
    private function hdrColorRow(): array
    {
        return [
            'color_space' => 'bt2020nc',
            'color_transfer' => 'smpte2084',
            'color_primaries' => 'bt2020',
            'max_luminance' => '1000.00',
            'avg_luminance' => '200.00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hevc4kProbe(): array
    {
        return [
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'hevc', 'width' => 3840, 'height' => 2160],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '600.0'],
        ];
    }

    /**
     * SV-1.1(a) core: when the persisted media_streams color columns (migration
     * 073) are present for the item, the HDR tone-map decision is sourced from
     * THEM — the live probe's color is NEVER consulted (extractColorMetadata is
     * never called) — and the tone_map_filter is resolved from the SAME
     * column-sourced metadata (byte-identical to the probe path, with the final
     * codec libx264). Mutation sense: red if computeHlsParams still reads the
     * probe's color when columns are present.
     */
    public function testEnsureHlsJobSourcesHdrDecisionFromPersistedColorColumns(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured, $this->hdrColorRow());

        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn($this->hevc4kProbe());
        // Column-sourced decision → the probe's color metadata is NEVER extracted.
        $ff->expects($this->never())->method('extractColorMetadata');
        // Filter resolved from the SAME column metadata (0 probes), NOT the probe.
        $ff->expects($this->never())->method('resolveToneMapFilterFromProbe');
        $ff->expects($this->once())
            ->method('resolveToneMapFilterFromColorMeta')
            ->with(
                [
                    'color_space' => 'bt2020nc',
                    'color_transfer' => 'smpte2084',
                    'color_primaries' => 'bt2020',
                    'max_luminance' => 1000.0,
                    'avg_luminance' => 200.0,
                ],
                'libx264'
            )
            ->willReturn(self::CANON_TONE_MAP);

        // Not via manager() — that stubs extractColorMetadata (asserted never here).
        $manager = new TranscodeManager($db, $ff, $this->segmentDir, null, 6);
        $manager->ensureHlsJob('media-1', 'web');

        $p = $this->capturedJobInsert($captured)['segment_params'];
        $this->assertTrue($p['require_hdr_tone_map']);
        $this->assertSame(self::CANON_TONE_MAP, $p['tone_map_filter']);
    }

    /**
     * SV-1.1(a) fallback: with NO persisted color columns (pre-073 / un-rescanned /
     * audio-only), the HDR decision + filter come from the live probe exactly as
     * before sub-step (a) — resolveToneMapFilterFromColorMeta is NEVER called.
     * Mutation sense: red if the column path is taken when columns are absent.
     */
    public function testEnsureHlsJobFallsBackToProbeColorWhenColumnsAbsent(): void
    {
        $captured = [];
        // Default mockDb colorRow = [] → getVideoStreamColorMetadata returns null.
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);

        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn($this->hevc4kProbe());
        // The pre-(a) path: HDR is derived from the probe's color metadata.
        $ff->method('extractColorMetadata')->willReturn([
            'color_space' => 'bt2020nc',
            'color_transfer' => 'smpte2084',
            'color_primaries' => 'bt2020',
            'max_luminance' => 1000.0,
            'avg_luminance' => 200.0,
        ]);
        $ff->expects($this->never())->method('resolveToneMapFilterFromColorMeta');
        $ff->expects($this->once())
            ->method('resolveToneMapFilterFromProbe')
            ->with($this->anything(), 'libx264')
            ->willReturn(self::CANON_TONE_MAP);

        $manager = new TranscodeManager($db, $ff, $this->segmentDir, null, 6);
        $manager->ensureHlsJob('media-1', 'web');

        $p = $this->capturedJobInsert($captured)['segment_params'];
        $this->assertTrue($p['require_hdr_tone_map']);
        $this->assertSame(self::CANON_TONE_MAP, $p['tone_map_filter']);
    }

    /**
     * SV-1.1(a) HONEST probe-count framing: sourcing the HDR decision from the
     * columns adds ZERO probes/extractColorMetadata calls FOR THE DECISION. The
     * single probe() at job creation still runs (embedded subtitle/audio stream
     * detection needs the live stream list), so the observable probe count stays
     * at exactly 1 — reaching a true 0 requires persisting subtitle/audio
     * descriptors (a separate follow-up, out of scope for sub-step (a)). This
     * asserts probe() is called exactly once and extractColorMetadata never.
     */
    public function testEnsureHlsJobHdrDecisionFromColumnsAddsZeroProbes(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured, $this->hdrColorRow());

        $ff = $this->createMock(FfmpegRunner::class);
        // Exactly ONE probe — the subtitle/audio-detection probe at job creation.
        $ff->expects($this->once())->method('probe')->willReturn($this->hevc4kProbe());
        // ZERO probes contributed by the HDR decision: the probe's color is never
        // extracted; the decision is entirely column-sourced.
        $ff->expects($this->never())->method('extractColorMetadata');
        $ff->method('resolveToneMapFilterFromColorMeta')->willReturn(self::CANON_TONE_MAP);

        $manager = new TranscodeManager($db, $ff, $this->segmentDir, null, 6);
        $manager->ensureHlsJob('media-1', 'web');

        $p = $this->capturedJobInsert($captured)['segment_params'];
        $this->assertTrue($p['require_hdr_tone_map']);
        $this->assertSame(self::CANON_TONE_MAP, $p['tone_map_filter']);
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
        // A 6-channel AC-3 5.1(side) source is forced to browser-safe stereo: the
        // native aac encoder would otherwise write channel_configuration=0 (PCE)
        // that hls.js cannot parse, breaking the audio SourceBuffer.
        $this->assertSame(2, $p['audio_channels']);
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
        // The master lists non-copy variants only (SV-4.6: a copy variant — here
        // the "original", since a 720p H.264+AAC source is copy-eligible — is
        // excluded from the switchable ABR set because its segment boundaries may
        // drift from the uniform timeline due to input-side -ss seeking without
        // -force_key_frames). All variants (including copy) still get their own
        // media playlist written for explicit quality selection.
        $master = (string) file_get_contents("{$dir}/master.m3u8");
        $this->assertGreaterThanOrEqual(2, substr_count($master, '#EXT-X-STREAM-INF:'));
        $this->assertStringContainsString('media_v720p.m3u8', $master);
        $this->assertStringNotContainsString('media_voriginal.m3u8', $master);
        // But the original's media playlist file still exists for manual selection.
        $this->assertFileExists("{$dir}/media_voriginal.m3u8");
        $this->assertStringContainsString('/hls/', $result['master_url']);
        // No legacy single-variant artefacts written for a multi-variant job.
        $this->assertFileDoesNotExist("{$dir}/media_0.m3u8");
    }

    /**
     * S49 ACCEPTANCE — the exact live shape from the bug report: a HEVC video with
     * AC-3 audio, whose transcode "Original" collapses onto the top ladder rung
     * (1.2 Mbps source → both capped to the same 2,054,000 BANDWIDTH at the same
     * 1920x1080 frame). Until v8 the ladder FOLDED that Original out of
     * `streamVariants()`, and because `writeVodPlaylists()` iterates exactly that
     * list, `media_voriginal.m3u8` was never written — every request for it 404'd
     * (masked only by HlsController's serve-time top-rung alias).
     *
     * It must now be a real, complete VOD media playlist on disk naming
     * `seg-voriginal-NNNNN.ts` segments.
     */
    public function testEnsureHlsJobWritesOriginalPlaylistForHevcAc3SourceThatUsedToFold(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'codec_name' => 'hevc',
                    'width' => 1920,
                    'height' => 1080,
                    'bit_rate' => '1200000',
                ],
                ['codec_type' => 'audio', 'codec_name' => 'ac3', 'channels' => 6, 'bit_rate' => '448000'],
            ],
            // 25s at 6s segments → 5 segments (0..4).
            'format' => ['duration' => '25.0'],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');

        // Pin the fixture: this source really is the duplicate-BANDWIDTH case, i.e.
        // exactly what the old fold dropped. If AbrLadder's clamp maths ever moves
        // so that it stops colliding, this test silently stops covering S49.
        $ladder = (new AbrLadder())->build(
            new SourceProfile(1920, 1080, 'hevc', 1_200_000, 'ac3', 448_000),
            'web'
        );
        $this->assertFalse($ladder->original->isCopy, 'HEVC/AC-3 → transcode (non-copy) Original');
        $this->assertSame(
            $ladder->renditions[0]->bandwidth(),
            $ladder->original->bandwidth(),
            'fixture must be the collapsed/duplicate-BANDWIDTH case'
        );
        $this->assertSame($ladder->renditions[0]->height, $ladder->original->height);

        $dir = $this->capturedJobInsert($captured)['hls_dir'];

        // THE ACCEPTANCE CRITERION: a real Original media playlist exists.
        $this->assertFileExists("{$dir}/media_voriginal.m3u8");
        $original = (string) file_get_contents("{$dir}/media_voriginal.m3u8");
        $this->assertStringContainsString('#EXT-X-PLAYLIST-TYPE:VOD', $original);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $original);
        $this->assertStringContainsString('seg-voriginal-00000.ts', $original);
        $this->assertStringContainsString('seg-voriginal-00004.ts', $original);
        $this->assertStringNotContainsString('seg-voriginal-00005.ts', $original);

        // …and it is NOT advertised as a switchable ABR level in the master (SV-4.6),
        // which is what keeps the duplicate-BANDWIDTH defect from returning.
        $master = (string) file_get_contents("{$dir}/master.m3u8");
        $this->assertStringNotContainsString('media_voriginal.m3u8', $master);
        // The pruned rung gradient is still advertised (1080p/480p/360p/240p).
        $this->assertStringContainsString('media_v1080p.m3u8', $master);
        $this->assertStringContainsString('media_v240p.m3u8', $master);
    }

    /**
     * S49 REGRESSION GUARD (the v7 defect). Removing the Original fold must NOT
     * reintroduce two identical-BANDWIDTH `#EXT-X-STREAM-INF` levels in the master:
     * a player merges those and ABR is left with nothing to climb to. Same
     * low-bitrate HEVC source as above — the one whose 1080p/720p rungs both cap to
     * the source bitrate and whose Original duplicates the top rung.
     */
    public function testMasterHasNoDuplicateBandwidthLevelsForLowBitrateSource(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'codec_name' => 'hevc',
                    'width' => 1920,
                    'height' => 1080,
                    'bit_rate' => '1200000',
                ],
                ['codec_type' => 'audio', 'codec_name' => 'ac3', 'channels' => 6, 'bit_rate' => '448000'],
            ],
            'format' => ['duration' => '25.0'],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
        $master = (string) file_get_contents($this->capturedJobInsert($captured)['hls_dir'] . '/master.m3u8');

        $this->assertGreaterThanOrEqual(2, preg_match_all('/BANDWIDTH=(\d+)/', $master, $bw));
        $bandwidths = array_map('intval', $bw[1]);
        $this->assertSame(
            $bandwidths,
            array_values(array_unique($bandwidths)),
            'no two master levels may share a BANDWIDTH (the v7 duplicate-level defect)'
        );
        $descending = $bandwidths;
        rsort($descending);
        $this->assertSame($descending, $bandwidths, 'master levels stay strictly descending');
        // The 720p rung collapsed onto 1080p's bandwidth and is correctly pruned.
        $this->assertStringNotContainsString('media_v720p.m3u8', $master);
    }

    /**
     * S49 fix round 1 — THE regression this round exists to prevent (HIGH-2).
     *
     * A transcode "Original" that is NOT a duplicate of the top rung is a genuine,
     * ABR-safe extra top LEVEL and must stay in the master. An interim revision of
     * S49 excluded every `original` from the switchable set, which silently halved
     * the auto-ABR ceiling for ordinary non-copy sources (this fixture: 10.0 Mbps →
     * 5.478 Mbps) and, because `phlix-ui` resolves "Original" by matching a master
     * level of the same height, could hide the very option S49 restores.
     *
     * Pins the whole advertised level set — ids + BANDWIDTH + RESOLUTION, in order.
     */
    public function testMasterAdvertisesNonDuplicateTranscodeOriginalAsItsTopLevel(): void
    {
        $dir = $this->ensureJobForSource(
            ['codec_name' => 'hevc', 'width' => 1920, 'height' => 1080, 'bit_rate' => '8000000'],
            ['codec_name' => 'aac', 'channels' => 2, 'bit_rate' => '128000'],
        );

        // Pin the fixture: HEVC ⇒ transcode (non-copy) Original, and it is NOT a
        // duplicate of the top rung, so it is a legitimate distinct level.
        $ladder = (new AbrLadder())->build(
            new SourceProfile(1920, 1080, 'hevc', 8_000_000, 'aac', 128_000),
            'web'
        );
        $this->assertFalse($ladder->original->isCopy);
        $this->assertFalse(
            $ladder->original->duplicatesForAbr($ladder->renditions[0]),
            'fixture must NOT be the duplicate-BANDWIDTH case'
        );

        $this->assertSame(
            [
                ['id' => 'original', 'bandwidth' => 10_000_000, 'resolution' => '1920x1080'],
                ['id' => '1080p', 'bandwidth' => 5_478_000, 'resolution' => '1920x1080'],
                ['id' => '720p', 'bandwidth' => 3_124_000, 'resolution' => '1280x720'],
                ['id' => '480p', 'bandwidth' => 1_626_000, 'resolution' => '854x480'],
                ['id' => '360p', 'bandwidth' => 984_000, 'resolution' => '640x360'],
                ['id' => '240p', 'bandwidth' => 556_000, 'resolution' => '426x240'],
            ],
            $this->masterLevels($dir),
            'the non-duplicate transcode Original is the master\'s top ABR level'
        );
        $this->assertFileExists("{$dir}/media_voriginal.m3u8");
    }

    /**
     * S49 fix round 1 (HIGH-1) — the same rule for a source whose original height
     * matches NO canonical rung: a 2048×1080 DCI-2K master. Its Original clamps to
     * `1920x1012` while the 1080 rung is dropped for width, so the top rung is
     * `720p`. If the Original is not a master level, `phlix-ui`'s
     * `levelIndexForVariant()` finds neither an equal-height nor a taller level and
     * HIDES "Original" — the file exists but is unreachable from the menu.
     */
    public function testMasterAdvertisesAnamorphicTranscodeOriginalAboveEveryRung(): void
    {
        $dir = $this->ensureJobForSource(
            ['codec_name' => 'hevc', 'width' => 2048, 'height' => 1080, 'bit_rate' => '9000000'],
            ['codec_name' => 'ac3', 'channels' => 6, 'bit_rate' => '448000'],
        );

        $this->assertSame(
            [
                ['id' => 'original', 'bandwidth' => 10_000_000, 'resolution' => '1920x1012'],
                ['id' => '720p', 'bandwidth' => 3_124_000, 'resolution' => '1366x720'],
                ['id' => '480p', 'bandwidth' => 1_626_000, 'resolution' => '910x480'],
                ['id' => '360p', 'bandwidth' => 984_000, 'resolution' => '682x360'],
                ['id' => '240p', 'bandwidth' => 556_000, 'resolution' => '456x240'],
            ],
            $this->masterLevels($dir),
            'a DCI-2K Original stays the master\'s top level (nothing else covers 1012px)'
        );
        $this->assertFileExists("{$dir}/media_voriginal.m3u8");
    }

    /**
     * S49 fix round 1 — the OTHER half of the SV-4.6 rule, pinned so a future
     * loosening cannot re-admit it: a stream-COPY Original is never an ABR level
     * (its segment boundaries can drift off the uniform timeline), but it still gets
     * its own media playlist for explicit selection. Note the copy's BANDWIDTH
     * (8,128,000) is well ABOVE the 1080p rung, so its absence is the copy rule at
     * work, not the duplicate rule.
     */
    public function testMasterOmitsCopyOriginalFromLevelsButStillWritesItsPlaylist(): void
    {
        $dir = $this->ensureJobForSource(
            ['codec_name' => 'h264', 'width' => 1920, 'height' => 1080, 'bit_rate' => '8000000'],
            ['codec_name' => 'aac', 'channels' => 2, 'bit_rate' => '128000'],
        );

        $ladder = (new AbrLadder())->build(
            new SourceProfile(1920, 1080, 'h264', 8_000_000, 'aac', 128_000),
            'web'
        );
        $this->assertTrue($ladder->original->isCopy, 'H.264 + AAC within the cap ⇒ copy Original');
        $this->assertSame(8_128_000, $ladder->original->bandwidth());

        $this->assertSame(
            [
                ['id' => '1080p', 'bandwidth' => 5_478_000, 'resolution' => '1920x1080'],
                ['id' => '720p', 'bandwidth' => 3_124_000, 'resolution' => '1280x720'],
                ['id' => '480p', 'bandwidth' => 1_626_000, 'resolution' => '854x480'],
                ['id' => '360p', 'bandwidth' => 984_000, 'resolution' => '640x360'],
                ['id' => '240p', 'bandwidth' => 556_000, 'resolution' => '426x240'],
            ],
            $this->masterLevels($dir),
            'a copy Original is never an ABR level'
        );
        $this->assertFileExists("{$dir}/media_voriginal.m3u8");
    }

    /**
     * S49 fix round 1 — the duplicate case, as an exact level set (the loose
     * uniqueness assertions live in
     * {@see testMasterHasNoDuplicateBandwidthLevelsForLowBitrateSource}). A 1.2 Mbps
     * HEVC source caps its Original AND its 1080p rung to the same 2,054,000 at the
     * same frame, so the Original is withheld from the LEVELS (a player would merge
     * two indistinguishable levels — the v7 defect) while still getting a playlist.
     */
    public function testMasterOmitsDuplicateTranscodeOriginalFromLevels(): void
    {
        $dir = $this->ensureJobForSource(
            ['codec_name' => 'hevc', 'width' => 1920, 'height' => 1080, 'bit_rate' => '1200000'],
            ['codec_name' => 'ac3', 'channels' => 6, 'bit_rate' => '448000'],
        );

        $this->assertSame(
            [
                ['id' => '1080p', 'bandwidth' => 2_054_000, 'resolution' => '1920x1080'],
                ['id' => '480p', 'bandwidth' => 1_626_000, 'resolution' => '854x480'],
                ['id' => '360p', 'bandwidth' => 984_000, 'resolution' => '640x360'],
                ['id' => '240p', 'bandwidth' => 556_000, 'resolution' => '426x240'],
            ],
            $this->masterLevels($dir),
            'a transcode Original that duplicates the top rung is withheld from the levels'
        );
        $this->assertFileExists("{$dir}/media_voriginal.m3u8");
    }

    /**
     * S49 (the undocumented AC blocker): after an LRU eviction of the job directory
     * ({@see TranscodeManager::sweepSegmentCache()}) the regenerated playlist set
     * must be the SAME set `ensureHlsJob()` wrote — including `media_voriginal.m3u8`
     * and the multi-audio group. `ensurePlaylistRegenerated()` used to rebuild the
     * variant list from the persisted `renditions` ONLY (losing the Original even
     * for a never-folded stream-COPY original) and to read audio tracks from a
     * `transcode_jobs.audio_tracks` column that does not exist (losing every
     * `media_a{N}.m3u8`). Both are read from the persisted ladder JSON now.
     */
    public function testEnsurePlaylistRegeneratedRestoresOriginalAndAudioPlaylists(): void
    {
        $ladder = (new AbrLadder())->build(
            new SourceProfile(1920, 1080, 'hevc', 1_200_000, 'ac3', 448_000),
            'web'
        );
        $ladderArray = $ladder->toArray();
        $ladderArray['audio_tracks'] = [
            [
                'index' => 0,
                'stream_index' => 1,
                'language' => 'eng',
                'label' => 'English',
                'default' => true,
                'codec' => 'ac3',
            ],
            [
                'index' => 1,
                'stream_index' => 2,
                'language' => 'fra',
                'label' => 'French',
                'default' => false,
                'codec' => 'ac3',
            ],
        ];
        $captured = [];
        $db = $this->mockDb([], 0, [], [
            'id' => 'job-evicted',
            'status' => 'completed',
            'variants' => (string) json_encode($ladderArray),
            'duration_seconds' => 25,
            'segment_seconds' => 6,
        ], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        // The whole directory was swept away — nothing on disk at all.
        $dir = $this->segmentDir . '/job-evicted';
        $this->assertDirectoryDoesNotExist($dir);

        $this->assertTrue($this->manager($db, $ff)->ensurePlaylistRegenerated('job-evicted'));

        // The Original playlist is back, complete, with its own segment names.
        $this->assertFileExists("{$dir}/media_voriginal.m3u8");
        $original = (string) file_get_contents("{$dir}/media_voriginal.m3u8");
        $this->assertStringContainsString('seg-voriginal-00000.ts', $original);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $original);
        // Every rung is back too.
        foreach ($ladder->renditions as $rung) {
            $this->assertFileExists("{$dir}/media_v{$rung->id}.m3u8");
        }
        // The multi-audio group survives regeneration (audio_tracks lives in the
        // ladder JSON, never in a `transcode_jobs` column).
        $master = (string) file_get_contents("{$dir}/master.m3u8");
        $this->assertStringContainsString('#EXT-X-MEDIA:TYPE=AUDIO', $master);
        $this->assertStringContainsString('GROUP-ID="aud"', $master);
        $this->assertFileExists("{$dir}/media_a0.m3u8");
        $this->assertFileExists("{$dir}/media_a1.m3u8");
        // …and the master still excludes the Original from the switchable set.
        $this->assertStringNotContainsString('media_voriginal.m3u8', $master);
    }

    /**
     * S49 fix round 1 (the two regeneration LOWs): a persisted ladder with no usable
     * rendition id must FAIL, not write a level-less master.
     *
     * A rendition id is interpolated straight into `media_v{id}.m3u8` and into the
     * master's URI, so it is validated against the same `^[a-z0-9]+$` allowlist the
     * segment serve path uses — a corrupt/hand-edited blob can neither escape the job
     * directory nor produce a `media_v.m3u8`. And when nothing survives that check,
     * writing the playlists anyway would emit a master with ZERO `#EXT-X-STREAM-INF`
     * lines whose mere existence then short-circuits every later regeneration attempt
     * — a permanent, unplayable job. It returns false and writes nothing instead.
     */
    public function testEnsurePlaylistRegeneratedFailsInsteadOfWritingALevellessMaster(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, [], [
            'id' => 'job-junk',
            'status' => 'completed',
            'variants' => (string) json_encode([
                'renditions' => [
                    ['id' => '', 'width' => 1920, 'height' => 1080, 'bitrate' => 5_478_000],
                    ['id' => '../../escape', 'width' => 1920, 'height' => 1080, 'bitrate' => 5_478_000],
                    ['id' => 'MiXeD', 'width' => 1280, 'height' => 720, 'bitrate' => 3_124_000],
                ],
                'original' => ['id' => '../evil', 'width' => 1920, 'height' => 1080, 'bitrate' => 9_000_000],
            ]),
            'duration_seconds' => 25,
            'segment_seconds' => 6,
        ], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $this->assertFalse($this->manager($db, $ff)->ensurePlaylistRegenerated('job-junk'));

        $dir = $this->segmentDir . '/job-junk';
        $this->assertFileDoesNotExist("{$dir}/master.m3u8");
        // Nothing was written for any of the rejected ids, inside the dir or above it.
        $this->assertSame([], glob("{$dir}/*.m3u8") ?: []);
        $this->assertFileDoesNotExist($this->segmentDir . '/evil.m3u8');
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
        $this->assertIsArray($decoded);
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

    /**
     * SV-4.12 (load-bearing): a wedged-age 'running' job whose dir contains ONLY
     * an on-demand `seg-*.ts` file — no legacy CMAF `chunk-*.m4s` — must NOT be
     * reaped. The reaper globs BOTH `chunk-*.m4s` and `seg-*.ts`; deleting the
     * `seg-*.ts` glob arm would leave the rest of the suite green, so this test
     * pins that arm: the current on-demand production path writes `seg-*.ts`,
     * and such a job is alive (producing output), not wedged.
     */
    public function testReapStaleRunningJobsKeepsJobWithOnDemandTsSegments(): void
    {
        $jobDir = $this->segmentDir . '/ts-segments-job';
        mkdir($jobDir, 0755, true);
        // A finalized on-demand HLS segment — matches glob("$dir/seg-*.ts").
        file_put_contents("{$jobDir}/seg-v720p-00000.ts", 'fake-ts-segment-data');
        $captured = [];
        $db = $this->createMock(Connection::class);
        // 90s ago — well past the 60s SEGMENT_PRODUCTION_TIMEOUT window.
        $startedAt = date('Y-m-d H:i:s', strtotime('-90 seconds'));
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use (&$captured, $jobDir, $startedAt) {
                $captured[] = [$sql, $params ?? []];
                if (str_contains($sql, "WHERE status = 'running'") && str_contains($sql, 'SELECT id')) {
                    return [[
                        'id' => 'ts-seg-1',
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

        $this->assertSame(0, $reaped, 'job with on-demand seg-*.ts output must not be reaped even if old');
        foreach ($captured as [$sql]) {
            $this->assertStringNotContainsString("SET status = 'failed'", $sql);
        }
    }

    /**
     * SV-4.12 (glob precision): a wedged-age 'running' job whose dir contains
     * ONLY in-flight `seg-*.ts.part-*` temp files — no finalized `seg-*.ts`, no
     * `chunk-*.m4s` — MUST be reaped. Partial temp writes are not produced
     * segments; the `seg-*.ts` glob must not match `.part-*` suffixes, so the
     * job still looks wedged and is failed. Guards the glob against widening to
     * `seg-*.ts*`.
     */
    public function testReapStaleRunningJobsReapsJobWithOnlyPartTempSegments(): void
    {
        $jobDir = $this->segmentDir . '/part-temp-job';
        mkdir($jobDir, 0755, true);
        // Only an in-flight temp file (mirrors the `seg-*.ts.part-*` write path).
        // glob("$dir/seg-*.ts") must NOT match this — its name ends in `.part-...`.
        file_put_contents("{$jobDir}/seg-v720p-00000.ts.part-a1b2c3", 'partial-write-data');
        $captured = [];
        $db = $this->createMock(Connection::class);
        $startedAt = date('Y-m-d H:i:s', strtotime('-90 seconds'));
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use (&$captured, $jobDir, $startedAt) {
                $captured[] = [$sql, $params ?? []];
                if (str_contains($sql, "WHERE status = 'running'") && str_contains($sql, 'SELECT id')) {
                    return [[
                        'id' => 'part-only-1',
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

        $this->assertSame(1, $reaped, 'a dir with only .part-* temps has produced no segment → must be reaped');
        $failUpdate = null;
        foreach ($captured as [$sql, $params]) {
            if (str_contains($sql, "SET status = 'failed'") && ($params[1] ?? null) === 'part-only-1') {
                $failUpdate = $params;
                break;
            }
        }
        $this->assertNotNull($failUpdate, 'part-only job must be marked failed');
        $this->assertStringContainsString('no segment produced within', (string) $failUpdate[0]);
    }

    /**
     * SV-4.12 (array_merge of both arms): a wedged-age 'running' job whose dir
     * contains BOTH a legacy `chunk-*.m4s` and an on-demand `seg-*.ts` is kept —
     * locking that the reaper merges both glob arms rather than replacing one.
     */
    public function testReapStaleRunningJobsKeepsJobWithBothCmafAndTsSegments(): void
    {
        $jobDir = $this->segmentDir . '/mixed-segments-job';
        mkdir($jobDir, 0755, true);
        file_put_contents("{$jobDir}/chunk-0-00001.m4s", 'fake-cmaf-data');
        file_put_contents("{$jobDir}/seg-v720p-00000.ts", 'fake-ts-data');
        $captured = [];
        $db = $this->createMock(Connection::class);
        $startedAt = date('Y-m-d H:i:s', strtotime('-90 seconds'));
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use (&$captured, $jobDir, $startedAt) {
                $captured[] = [$sql, $params ?? []];
                if (str_contains($sql, "WHERE status = 'running'") && str_contains($sql, 'SELECT id')) {
                    return [[
                        'id' => 'mixed-1',
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

        $this->assertSame(0, $reaped, 'job with both CMAF and TS output must not be reaped');
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

    // --- S9: epoch-guarded job-row cache (coherent under the coroutine DB pool) ---
    //
    // Under the connection pool a reader coroutine and a writer coroutine hold
    // DIFFERENT physical connections, so a cache-miss SELECT and a concurrent
    // invalidate are no longer serialised by a shared connection mutex and may
    // complete in either order. The epoch guard makes the in-worker cache safe:
    // jobRowEntry() snapshots the jobId's epoch BEFORE the SELECT and only
    // populates the LRU if the epoch is unchanged on return; invalidateJobRowCache()
    // bumps that epoch. These tests deterministically model "a write races in
    // between the SELECT dispatch and its return" by having the DB mock's query
    // callback invoke invalidateJobRowCache() WHILE the (mocked) SELECT is being
    // served — exactly the window the pool exposes — with no real coroutine
    // scheduler needed. (The real-MySQL pool behaviour is proven in
    // tests/Integration/Media/Transcoding/PooledConnectionConcurrencyTest.php.)

    private function readEpoch(TranscodeManager $m, string $jobId): int
    {
        $prop = new \ReflectionProperty(TranscodeManager::class, 'jobRowEpoch');
        $prop->setAccessible(true);
        $epochs = $prop->getValue($m);
        $this->assertIsArray($epochs);
        return is_numeric($epochs[$jobId] ?? null) ? (int) $epochs[$jobId] : 0;
    }

    private function cacheHas(TranscodeManager $m, string $jobId): bool
    {
        $prop = new \ReflectionProperty(TranscodeManager::class, 'jobRowCache');
        $prop->setAccessible(true);
        $cache = $prop->getValue($m);
        $this->assertIsArray($cache);
        return array_key_exists($jobId, $cache);
    }

    /**
     * Builds a Connection mock for the job-row SELECT whose callback can fire
     * $invalidations invalidateJobRowCache() calls on the manager during the
     * FIRST job-row SELECT (simulating N concurrent writers completing while the
     * reader's SELECT is in flight). The manager is supplied late via $holder.
     *
     * @param array<string, mixed>                             $jobRow
     * @param object{m: ?TranscodeManager}                     $holder
     * @param array<int, array{0: string, 1: array<int,mixed>}> $captured
     */
    private function racingJobRowDb(
        array $jobRow,
        object $holder,
        int $invalidations,
        string $jobId,
        array &$captured
    ): Connection {
        $fired = false;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (
                string $sql,
                ?array $params = null
            ) use (
                $jobRow,
                $holder,
                $invalidations,
                $jobId,
                &$captured,
                &$fired
            ) {
                $captured[] = [$sql, $params ?? []];
                if (str_contains($sql, 'transcode_jobs WHERE id = ?')) {
                    // Model "a write completed while this SELECT was in flight":
                    // invalidate the cache/epoch mid-query, once, on the first read.
                    if (!$fired && $invalidations > 0 && $holder->m !== null) {
                        $fired = true;
                        for ($i = 0; $i < $invalidations; $i++) {
                            $this->callPrivate($holder->m, 'invalidateJobRowCache', $jobId);
                        }
                    }
                    return [$jobRow];
                }
                return [];
            }
        );
        return $db;
    }

    public function testCacheMissPopulatesWhenEpochUnchanged(): void
    {
        // Baseline: with no racing write, the cache-miss SELECT populates the LRU
        // (epoch unchanged) so the second read is a hit — one SELECT total.
        $captured = [];
        $db = $this->mockDb([], 0, [], ['id' => 'job-e0', 'status' => 'completed'], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->manager($db, $ff);

        $first = $this->callPrivate($manager, 'jobRowEntry', 'job-e0');
        $this->assertIsArray($first);
        $this->assertTrue($this->cacheHas($manager, 'job-e0'), 'unraced miss must cache the row');
        $this->assertSame(0, $this->readEpoch($manager, 'job-e0'));

        $this->callPrivate($manager, 'jobRowEntry', 'job-e0'); // cache hit — no new SELECT
        $this->assertSame(1, $this->countJobRowSelects($captured), 'second read must hit the cache');
    }

    public function testRacingWriteDuringSelectReturnsFreshRowButDoesNotCacheIt(): void
    {
        // A single writer invalidates while the reader's SELECT is in flight. The
        // reader still gets its freshly-fetched row (one-shot, no throw) but MUST
        // NOT cache it (its snapshot epoch is stale), so the next read re-queries.
        $captured = [];
        $holder = new class {
            public ?TranscodeManager $m = null;
        };
        $row = ['id' => 'job-race1', 'status' => 'running'];
        $db = $this->racingJobRowDb($row, $holder, 1, 'job-race1', $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->manager($db, $ff);
        $holder->m = $manager;

        $entry = $this->callPrivate($manager, 'jobRowEntry', 'job-race1');
        $this->assertIsArray($entry);
        $this->assertSame($row, $entry['row'], 'the raced reader still receives its freshly-fetched row (one-shot)');
        $this->assertFalse(
            $this->cacheHas($manager, 'job-race1'),
            'a row whose epoch advanced during the SELECT must NOT be cached (would be stale forever — no TTL)'
        );
        $this->assertSame(1, $this->readEpoch($manager, 'job-race1'), 'the racing write bumped the epoch once');

        // Next read re-queries (cache was never populated); no writer races this
        // time, so it caches cleanly and a third read is a hit.
        $this->callPrivate($manager, 'jobRowEntry', 'job-race1');
        $this->assertTrue($this->cacheHas($manager, 'job-race1'), 'once the race clears, caching resumes');
        $this->callPrivate($manager, 'jobRowEntry', 'job-race1');
        $this->assertSame(2, $this->countJobRowSelects($captured), 'exactly one re-query after the raced read');
    }

    public function testMultipleWritesRacingDuringOneSelectAreDetected(): void
    {
        // Two (or more) writers invalidate during a single in-flight SELECT: the
        // epoch is monotonic (+1 each), so E -> E+2 and the E+2 === E check is
        // false — the mismatch is still detected and the row is not cached.
        $captured = [];
        $holder = new class {
            public ?TranscodeManager $m = null;
        };
        $row = ['id' => 'job-race2', 'status' => 'running'];
        $db = $this->racingJobRowDb($row, $holder, 2, 'job-race2', $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->manager($db, $ff);
        $holder->m = $manager;

        $entry = $this->callPrivate($manager, 'jobRowEntry', 'job-race2');
        $this->assertIsArray($entry);
        $this->assertSame($row, $entry['row']);
        $this->assertFalse(
            $this->cacheHas($manager, 'job-race2'),
            'any number of concurrent invalidations (>=1) during the SELECT must block the populate'
        );
        $this->assertSame(2, $this->readEpoch($manager, 'job-race2'), 'both racing writes advanced the epoch');
    }

    public function testInvalidateBumpsEpochMonotonically(): void
    {
        // The core guarantee invalidateJobRowCache() must provide: every call
        // both drops the cached entry and strictly increments the jobId's epoch,
        // so a stale snapshot from before ANY of them is detectable on return.
        $captured = [];
        $db = $this->mockDb([], 0, [], ['id' => 'job-ep', 'status' => 'running'], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->manager($db, $ff);

        $this->callPrivate($manager, 'jobRowEntry', 'job-ep'); // prime cache, epoch 0
        $this->assertTrue($this->cacheHas($manager, 'job-ep'));
        for ($i = 1; $i <= 5; $i++) {
            $this->callPrivate($manager, 'invalidateJobRowCache', 'job-ep');
            $this->assertSame($i, $this->readEpoch($manager, 'job-ep'), "invalidate #$i must bump the epoch to $i");
        }
        $this->assertFalse($this->cacheHas($manager, 'job-ep'), 'invalidate must also drop the cached entry');
    }

    public function testCompletionTransitionBumpsEpochViaPublicPath(): void
    {
        // One of the 3 invalidateJobRowCache() call sites (completion, legacy-failure,
        // reap), reached through the public API: getJobReadiness() syncing a
        // running->completed transition issues the status UPDATE and then invalidates —
        // which must
        // bump the epoch, not merely unset the cache, so a reader whose SELECT was
        // in flight across this write cannot re-poison the cache under the pool.
        $dir = $this->segmentDir . '/job-ep-pub';
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/master.m3u8", "#EXTM3U\n");
        file_put_contents("{$dir}/.complete", '');
        $captured = [];
        $db = $this->mockDb([], 0, [], ['hls_dir' => $dir, 'status' => 'running'], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->manager($db, $ff);

        $this->assertSame(0, $this->readEpoch($manager, 'job-ep-pub'));
        $manager->getJobReadiness('job-ep-pub'); // running -> completed => UPDATE + invalidate
        $this->assertSame(1, $this->readEpoch($manager, 'job-ep-pub'), 'the public completion transition must bump the epoch');
        $this->assertFalse($this->cacheHas($manager, 'job-ep-pub'), 'and drop the cached row');
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
        $this->assertCount(2, $first); // this row's "original" has no id → not listed
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

    // NOTE (S10): the former testCancelInvalidatesCachedJobRow() was removed with the
    // dead blocking transcode path. It exercised stopTranscode(), the only "cancel"
    // invalidateJobRowCache() call site, which gated entirely on the now-deleted
    // in-memory $activeJobs map and had no production caller — so no remaining call
    // site maps to a "cancel" scenario to repoint it at. The 3 surviving call sites
    // (completion, legacy-failure, reap) are covered by
    // testCompletionTransitionBumpsEpochViaPublicPath(),
    // testTerminalTransitionInvalidatesCachedJobRow(), and testReapInvalidatesCachedJobRow().

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
     * SV-1.6: a legacy (single-variant) job with `subtitle_burn_in_index` set
     * in its persisted `segment_params` resolves to the real, already-extracted
     * `sub-{index}.vtt` sidecar and threads it through to
     * {@see FfmpegRunner::startSegmentEncode()} as a `subtitle_burn_in` param —
     * proving the toggle reaches the real per-segment builder, not just the
     * job-creation INSERT.
     */
    public function testEnsureSegmentResolvesSubtitleBurnInForLegacyJob(): void
    {
        $dir = $this->segmentDir . '/seg-subs-legacy';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        file_put_contents("{$dir}/sub-0.vtt", "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nHi\n");

        $jobRow = [
            'id' => 'seg-job',
            'hls_dir' => $dir,
            'input_path' => $input,
            'status' => 'completed',
            'duration_seconds' => 60,
            'segment_seconds' => 6,
            'segment_params' => json_encode([
                'video_codec' => 'libx264',
                'audio_codec' => 'aac',
                'subtitle_burn_in_index' => 0,
                'force_subtitle_burn_in' => false,
            ]),
        ];
        $captured = [];
        $db = $this->mockDb([], 0, [], $jobRow, $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $captParams = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $start, float $len, array $params) use (&$captParams): int {
                $captParams = $params;
                file_put_contents($out, 'encoded');
                return 1;
            }
        );

        $this->manager($db, $ff)->ensureSegment('seg-job', null, 0);

        $this->assertNotNull($captParams);
        $this->assertSame(
            ['path' => "{$dir}/sub-0.vtt", 'format' => 'vtt'],
            $captParams['subtitle_burn_in'] ?? null
        );
    }

    /**
     * SV-1.6: when the requested index's sidecar has not been extracted yet
     * (or never will be — e.g. a bitmap-only track), the toggle degrades
     * silently: no `subtitle_burn_in` param is passed, rather than pointing
     * FfmpegRunner at a nonexistent file.
     */
    public function testEnsureSegmentSkipsSubtitleBurnInWhenSidecarMissing(): void
    {
        $dir = $this->segmentDir . '/seg-subs-missing';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        // Deliberately do NOT create sub-0.vtt.

        $jobRow = [
            'id' => 'seg-job',
            'hls_dir' => $dir,
            'input_path' => $input,
            'status' => 'completed',
            'duration_seconds' => 60,
            'segment_seconds' => 6,
            'segment_params' => json_encode([
                'video_codec' => 'libx264',
                'audio_codec' => 'aac',
                'subtitle_burn_in_index' => 0,
            ]),
        ];
        $captured = [];
        $db = $this->mockDb([], 0, [], $jobRow, $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $captParams = null;
        $ff->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $start, float $len, array $params) use (&$captParams): int {
                $captParams = $params;
                file_put_contents($out, 'encoded');
                return 1;
            }
        );

        $this->manager($db, $ff)->ensureSegment('seg-job', null, 0);

        $this->assertNotNull($captParams);
        $this->assertArrayNotHasKey('subtitle_burn_in', $captParams);
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

    /**
     * SV-1.9: builds a manager with an injected ENOSPC threshold (constructor
     * position 13) plus a short segment poll ceiling, so the disk-space guard can
     * be exercised without a 30 s real-world wait. An explicit $segmentDir lets a
     * test point the guard at a bogus path (disk_free_space() → false → fail-open).
     *
     * @param FfmpegRunner&MockObject $ff
     */
    private function diskGuardManager(
        Connection $db,
        FfmpegRunner $ff,
        ?int $minDiskSpaceBytes,
        ?string $segmentDir = null
    ): TranscodeManager {
        $this->stubColorMetadata($ff);
        return new TranscodeManager(
            $db,
            $ff,
            $segmentDir ?? $this->segmentDir,
            null,
            6,
            null,
            null,
            null,
            null,
            null,
            null,
            200,               // segmentMaxWaitMs — short poll ceiling for tests
            $minDiskSpaceBytes // position 13: SV-1.9 ENOSPC threshold
        );
    }

    public function testEnsureSegmentThrowsSegmentCacheFullWhenDiskLow(): void
    {
        // SV-1.9: an ENOSPC guard threshold larger than any real filesystem's free
        // space forces the throw. It must fire BEFORE ffmpeg is ever spawned so the
        // HLS controller can 503 + sweep rather than letting FFmpeg hit ENOSPC.
        $dir = $this->segmentDir . '/seg-enospc';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('startSegmentEncode');

        $manager = $this->diskGuardManager($db, $ff, PHP_INT_MAX);

        $this->expectException(SegmentCacheFullException::class);
        $manager->ensureSegment('seg-job', null, 2);
    }

    public function testEnsureSegmentEncodesWhenDiskSpaceAmple(): void
    {
        // SV-1.9: a trivially-satisfiable 1-byte floor must NOT block the encode —
        // the guard is a fast-fail floor, not an eager gate.
        $dir = $this->segmentDir . '/seg-ample';
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

        $manager = $this->diskGuardManager($db, $ff, 1);

        $path = $manager->ensureSegment('seg-job', null, 2);

        $this->assertSame("{$dir}/seg-00002.ts", $path);
        $this->assertFileExists($path);
    }

    public function testEnsureSegmentFailsOpenWhenFreeSpaceUndeterminable(): void
    {
        // SV-1.9 fail-open: only the manager's segment_dir (the path the guard
        // probes) is bogus, so disk_free_space() returns false; the job dir is real
        // so the encode still writes there. Even with an impossibly-high floor the
        // guard must NOT throw when free space can't be determined — the actual
        // ENOSPC (if any) surfaces later in ffmpeg rather than blocking every encode.
        $dir = $this->segmentDir . '/seg-failopen';
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

        $bogusSegmentDir = $this->segmentDir . '/not-a-real-dir-' . uniqid();
        $manager = $this->diskGuardManager($db, $ff, PHP_INT_MAX, $bogusSegmentDir);

        $path = $manager->ensureSegment('seg-job', null, 2);

        $this->assertSame("{$dir}/seg-00002.ts", $path);
        $this->assertFileExists($path);
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
        $this->assertIsArray($decoded);
        $renditions = $decoded['renditions'];
        $this->assertIsArray($renditions);
        $ids = array_column($renditions, 'id');
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

        // SV-4.6: a copy variant (here the "original" — this 1920x1080 H.264+AAC
        // source is copy-eligible) is excluded from the switchable ABR set in the
        // master playlist because its segment boundaries may drift from the uniform
        // timeline (input-side -ss seeking without -force_key_frames). A non-copy
        // Original would be advertised; see testMasterAdvertises* for that case.
        // Use array_values to re-index since array_filter preserves original keys.
        $switchableVariants = array_values(array_filter(
            $variants,
            static fn (\Phlix\Media\Streaming\Rendition $v): bool => !$v->isCopy
        ));

        // One STREAM-INF + media_v{id}.m3u8 per NON-COPY variant, in the SAME (highest-first) order.
        $lines = array_values(array_filter(explode("\n", $master), static fn (string $l): bool => $l !== ''));
        $streamLines = [];
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $streamLines[] = [$line, $lines[$i + 1] ?? ''];
            }
        }
        $this->assertCount(count($switchableVariants), $streamLines);
        foreach ($switchableVariants as $pos => $variant) {
            [$inf, $uri] = $streamLines[$pos];
            $this->assertStringContainsString('BANDWIDTH=' . $variant->bandwidth(), $inf);
            $this->assertStringContainsString('RESOLUTION=' . $variant->resolution(), $inf);
            $this->assertStringContainsString('CODECS="' . $variant->codecs . '"', $inf);
            $this->assertSame("media_v{$variant->id}.m3u8", $uri);
        }
        // Highest-first: the first STREAM-INF's resolution height ≥ the last's.
        $this->assertSame("media_v{$switchableVariants[array_key_first($switchableVariants)]->id}.m3u8", $streamLines[0][1]);
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
        $this->assertNotNull($captParams);
        $this->assertSame('libx264', $captParams['video_codec']);
        $this->assertSame('aac', $captParams['audio_codec']);
        $this->assertSame(854, $captParams['width']);
        $this->assertSame(480, $captParams['height']);
        $this->assertSame('yuv420p', $captParams['pix_fmt']);
        $this->assertSame('high', $captParams['profile']);
        $this->assertArrayHasKey('maxrate', $captParams);
        $this->assertArrayHasKey('bufsize', $captParams);
        $maxrate = $captParams['maxrate'];
        $this->assertIsInt($maxrate);
        $this->assertSame($maxrate * 2, $captParams['bufsize']);
    }

    /**
     * SV-1.6: {@see TranscodeManager::segmentParamsForRendition()} rebuilds
     * segParams FRESH per-rendition from the ABR ladder and does NOT itself
     * carry the job-level `subtitle_burn_in_index` — proving the toggle still
     * reaches a MULTI-VARIANT job's per-rendition segment encode (not just
     * the legacy single-variant path) requires
     * {@see TranscodeManager::applySubtitleBurnIn()} to merge it back in from
     * the row's persisted base `segment_params`.
     */
    public function testEnsureSegmentResolvesSubtitleBurnInForMultiVariantJob(): void
    {
        $dir = $this->segmentDir . '/mv-subs';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        file_put_contents("{$dir}/sub-1.vtt", "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nHi\n");
        $ladderJson = (string) json_encode(
            (new AbrLadder())->build(
                new SourceProfile(width: 1920, height: 1080, videoCodec: 'h264', videoBitrate: 6000000, audioCodec: 'aac'),
                'web'
            )->toArray()
        );
        $jobRow = $this->multiVariantJobRow($dir, $input, $ladderJson);
        $jobRow['segment_params'] = json_encode([
            'video_codec' => 'copy',
            'audio_codec' => 'copy',
            'subtitle_burn_in_index' => 1,
        ]);
        $captured = [];
        $db = $this->mockDb([], 0, [], $jobRow, $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $captParams = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $start, float $len, array $params) use (&$captParams): int {
                $captParams = $params;
                file_put_contents($out, 'encoded');
                return 1;
            }
        );

        $this->manager($db, $ff)->ensureSegment('seg-job', '480p', 2);

        $this->assertNotNull($captParams);
        $this->assertSame(
            ['path' => "{$dir}/sub-1.vtt", 'format' => 'vtt'],
            $captParams['subtitle_burn_in'] ?? null
        );
        // The rendition-specific encode contract is untouched by the merge.
        $this->assertSame('libx264', $captParams['video_codec']);
    }

    /**
     * SV-1.1(b′): segmentParamsForRendition() rebuilds a multi-variant job's
     * segParams FRESH per-rendition and carries NEITHER `require_hdr_tone_map`
     * nor the resolved `tone_map_filter` STRING — so proving a MULTI-VARIANT
     * (ABR) job's per-rendition segment encode uses the threaded tone-map string
     * (instead of re-deriving the HDR decision per segment) requires
     * {@see TranscodeManager::applyToneMap()} to merge both back in from the
     * row's persisted base `segment_params`. This is the persist→decode→merge
     * round-trip: the flag+filter resolved ONCE at job creation
     * ({@see TranscodeManager::computeSegmentParams()}) reach the per-rendition
     * encode contract without any per-segment probe.
     */
    public function testEnsureSegmentThreadsToneMapFilterForMultiVariantHdrJob(): void
    {
        $canon = 'zscale=t=linear:npl=100,format=gbrpf32le,'
            . 'zscale=p=bt709,tonemap=hable:desat=0,'
            . 'zscale=t=bt709:m=bt709:r=tv,format=yuv420p';

        $dir = $this->segmentDir . '/mv-tonemap';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $ladderJson = (string) json_encode(
            (new AbrLadder())->build(
                new SourceProfile(width: 1920, height: 1080, videoCodec: 'h264', videoBitrate: 6000000, audioCodec: 'aac'),
                'web'
            )->toArray()
        );
        $jobRow = $this->multiVariantJobRow($dir, $input, $ladderJson);
        // Base segment_params as computeSegmentParams() persists it for an HDR
        // source: the job-level flag + the tone-map filter resolved ONCE (final
        // codec libx264, post copy→libx264 upgrade).
        $jobRow['segment_params'] = json_encode([
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'require_hdr_tone_map' => true,
            'tone_map_filter' => $canon,
        ]);
        $captured = [];
        $db = $this->mockDb([], 0, [], $jobRow, $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        // The threaded filter must reach the encode WITHOUT any per-segment
        // re-derive: neither needsToneMapping() nor getToneMappingProfile() (nor a
        // probe) is consulted on the multi-variant produce path.
        $ff->expects($this->never())->method('needsToneMapping');
        $ff->expects($this->never())->method('getToneMappingProfile');
        $ff->expects($this->never())->method('resolveToneMapFilterFromProbe');
        $captParams = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $start, float $len, array $params) use (&$captParams): int {
                $captParams = $params;
                file_put_contents($out, 'encoded');
                return 1;
            }
        );

        $this->manager($db, $ff)->ensureSegment('seg-job', '480p', 2);

        $this->assertNotNull($captParams);
        $this->assertTrue($captParams['require_hdr_tone_map'] ?? null);
        $this->assertSame($canon, $captParams['tone_map_filter'] ?? null);
        // The rendition-specific encode contract (transcode rung → libx264) is
        // untouched by the merge — the tone-map keys ride ALONGSIDE it.
        $this->assertSame('libx264', $captParams['video_codec']);
    }

    /**
     * SV-1.1(b′) fallback: a multi-variant job whose persisted base
     * `segment_params` carries NO `require_hdr_tone_map`/`tone_map_filter` (a
     * pre-b′ job, un-rescanned item, or plain SDR source) must NOT have either
     * key injected into its per-rendition segParams — so the ABR builders keep
     * their legacy per-segment fallback (unchanged behaviour). Mutation sense:
     * red if applyToneMap injects a key unconditionally.
     */
    public function testEnsureSegmentOmitsToneMapForMultiVariantJobWithoutBaseFlag(): void
    {
        $dir = $this->segmentDir . '/mv-tonemap-sdr';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $ladderJson = (string) json_encode(
            (new AbrLadder())->build(
                new SourceProfile(width: 1920, height: 1080, videoCodec: 'h264', videoBitrate: 6000000, audioCodec: 'aac'),
                'web'
            )->toArray()
        );
        // multiVariantJobRow()'s default base segment_params carries neither key.
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

        $this->manager($db, $ff)->ensureSegment('seg-job', '480p', 2);

        $this->assertNotNull($captParams);
        $this->assertArrayNotHasKey('require_hdr_tone_map', $captParams);
        $this->assertArrayNotHasKey('tone_map_filter', $captParams);
    }

    /**
     * SV-1.1(b′) guards: applyToneMap() leaves $segParams untouched (no
     * flag/filter injected) when the job row carries no base segment_params at
     * all, or carries a malformed (non-JSON) segment_params — both degrade to the
     * legacy per-segment fallback rather than throwing. Exercised directly via
     * reflection so the defensive early-returns are pinned.
     */
    public function testApplyToneMapLeavesParamsUntouchedForMissingOrMalformedBase(): void
    {
        $captured = [];
        $manager = $this->manager($this->mockDb([], 0, [], [], $captured), $this->createMock(FfmpegRunner::class));
        $apply = new ReflectionMethod(TranscodeManager::class, 'applyToneMap');
        $apply->setAccessible(true);
        $seg = ['video_codec' => 'libx264'];

        // No base segment_params on the row → returned unchanged.
        $this->assertSame($seg, $apply->invoke($manager, [], $seg));
        // Empty-string base → unchanged.
        $this->assertSame($seg, $apply->invoke($manager, ['segment_params' => ''], $seg));
        // Malformed (non-JSON) base → unchanged (json_decode yields non-array).
        $this->assertSame($seg, $apply->invoke($manager, ['segment_params' => 'not-json{'], $seg));
    }

    /**
     * SV-1.1(b′) build proof: the REAL per-rendition params
     * ({@see TranscodeManager::segmentParamsForRendition()}) for a transcode rung,
     * once the tone-map flag+filter are merged in (as
     * {@see TranscodeManager::applyToneMap()} does), drive
     * {@see FfmpegRunner::buildSegmentCommand()} to emit the threaded string
     * VERBATIM (byte-identical `-vf` graph, tone-map BEFORE scale per SV-1.6) with
     * ZERO probe()/needsToneMapping()/getToneMappingProfile() re-derivation. Uses
     * the call-counting {@see ToneMapThreadingSpyRunner} from sub-step (b).
     */
    public function testMultiVariantRenditionToneMapParamsBuildWithoutReDeriving(): void
    {
        $canon = 'zscale=t=linear:npl=100,format=gbrpf32le,'
            . 'zscale=p=bt709,tonemap=hable:desat=0,'
            . 'zscale=t=bt709:m=bt709:r=tv,format=yuv420p';

        // The genuine per-rendition encode contract for a 480p transcode rung.
        $forRendition = new ReflectionMethod(TranscodeManager::class, 'segmentParamsForRendition');
        $forRendition->setAccessible(true);
        /** @var array<string, mixed> $segParams */
        $segParams = $forRendition->invoke(self::bareTranscodeManagerForRendition(), [
            'is_copy' => false,
            'video_bitrate' => 1400000,
            'codecs' => 'avc1.64001f,mp4a.40.2',
            'width' => 854,
            'height' => 480,
        ]);
        $this->assertSame('libx264', $segParams['video_codec']);

        // Merge the tone-map flag+filter exactly as applyToneMap() would.
        $segParams['require_hdr_tone_map'] = true;
        $segParams['tone_map_filter'] = $canon;

        $runner = new ToneMapThreadingSpyRunner('FALLBACK_DERIVED_TONEMAP_FILTER');
        $cmd = $runner->buildSegmentCommand('/in.mkv', '/out/seg-v480p-00002.ts', 12.0, 6.0, $segParams);

        $this->assertStringContainsString('-vf "' . $canon, $cmd);
        $this->assertStringNotContainsString('FALLBACK_DERIVED_TONEMAP_FILTER', $cmd);
        // SV-1.6 ordering: tone-map precedes the rung scale in the -vf chain.
        $tonePos = strpos($cmd, $canon);
        $scalePos = strpos($cmd, 'scale=854:480');
        $this->assertIsInt($tonePos);
        $this->assertIsInt($scalePos);
        $this->assertLessThan($scalePos, $tonePos, 'tone-map must precede scale');
        // Zero per-segment re-derive on the ABR build path.
        $this->assertSame(0, $runner->probeCalls);
        $this->assertSame(0, $runner->needsToneMappingCalls);
        $this->assertSame(0, $runner->getToneMappingProfileCalls);
    }

    /**
     * SV-1.1 END-TO-END SESSION (the plan §2 L654-662 acceptance criterion):
     * "a 2-hour playback triggers ≤1 ffprobe for HDR detection (ideally 0 if
     * scanned), not ~3 per segment." This is the mandated
     * "count probe invocations across a simulated multi-segment session" test.
     *
     * It drives ONE real {@see TranscodeManager::ensureHlsJob()} (the sole probe a
     * job is allowed — subtitle/audio stream detection) followed by MANY segment
     * builds across BOTH the legacy single-variant {@see TranscodeManager::produceSegment()}
     * path AND the ABR-rendition path, sharing ONE call-counting
     * {@see ToneMapSessionSpyRunner} so probe()/needsToneMapping()/getToneMappingProfile()
     * accumulate across the whole session. The segment builds run the REAL
     * {@see FfmpegRunner::buildSegmentCommand()} (where any per-segment re-derive
     * would live) without spawning ffmpeg.
     *
     * The item is SCANNED (persisted media_streams HDR color columns, mig 073), so
     * the HDR *decision* contributes ZERO probes — the observable TOTAL across the
     * entire session is therefore exactly 1 (the job-creation probe), 0 additional
     * for any of the segments. Mutation sense: red the instant any segment
     * re-derives the HDR decision (a probe(), a needsToneMapping(), or a
     * getToneMappingProfile() on any segment build bumps a counter).
     */
    public function testMultiSegmentPlaybackSessionMakesAtMostOneProbe(): void
    {
        $spy = new ToneMapSessionSpyRunner();

        // ---- Phase 1: job creation — the ONE permitted probe -----------------
        // Scanned HDR item: the persisted color columns supply the HDR decision
        // (0 probes), so the single probe() is purely subtitle/audio detection.
        $jobCaptured = [];
        $jobDb = $this->mockDb([], 0, ['path' => '/session.mkv'], [], $jobCaptured, $this->hdrColorRow());
        $manager = new TranscodeManager($jobDb, $spy, $this->segmentDir, null, 6);
        $manager->ensureHlsJob('media-session', 'web');

        $this->assertSame(1, $spy->probeCalls, 'job creation must probe exactly once (subtitle/audio detection)');

        // The base segment_params computeSegmentParams() persisted for the HDR
        // source: require_hdr_tone_map + the tone_map_filter resolved ONCE from the
        // columns. Round-trip THIS into the segment job rows below.
        $base = $this->capturedJobInsert($jobCaptured)['segment_params'];
        $this->assertTrue($base['require_hdr_tone_map'] ?? null, 'HDR job must persist require_hdr_tone_map');
        $this->assertSame(
            self::CANON_TONE_MAP,
            $base['tone_map_filter'] ?? null,
            'tone_map_filter resolved once at job creation'
        );
        $baseJson = (string) json_encode($base);

        // ---- Phase 2: single-variant segment sequence (produceSegment) -------
        $svDir = $this->segmentDir . '/session-sv';
        mkdir($svDir, 0755, true);
        $svInput = $svDir . '/in.mkv';
        file_put_contents($svInput, 'x');
        $svRow = $this->onDemandJobRow($svDir, $svInput);
        $svRow['segment_params'] = $baseJson; // the HDR base params, round-tripped
        $svCaptured = [];
        $svDb = $this->mockDb([], 0, [], $svRow, $svCaptured);
        $svManager = new TranscodeManager($svDb, $spy, $this->segmentDir, null, 6);
        foreach ([0, 1, 2, 3, 4] as $i) {
            $path = $svManager->ensureSegment('seg-job', null, $i);
            $this->assertSame("{$svDir}/seg-" . sprintf('%05d', $i) . '.ts', $path);
            $this->assertFileExists((string) $path);
        }

        // ---- Phase 3: ABR rendition segment sequence -------------------------
        // (segmentParamsForRendition rebuilds fresh per-rendition → applyToneMap
        //  merges the flag+filter back in → produceSegment → buildSegmentCommand.)
        $abrDir = $this->segmentDir . '/session-abr';
        mkdir($abrDir, 0755, true);
        $abrInput = $abrDir . '/in.mkv';
        file_put_contents($abrInput, 'x');
        $ladderJson = (string) json_encode(
            (new AbrLadder())->build(
                new SourceProfile(width: 1920, height: 1080, videoCodec: 'h264', videoBitrate: 6000000, audioCodec: 'aac'),
                'web'
            )->toArray()
        );
        $abrRow = $this->multiVariantJobRow($abrDir, $abrInput, $ladderJson);
        $abrRow['segment_params'] = $baseJson; // carries require_hdr_tone_map + filter
        $abrCaptured = [];
        $abrDb = $this->mockDb([], 0, [], $abrRow, $abrCaptured);
        $abrManager = new TranscodeManager($abrDb, $spy, $this->segmentDir, null, 6);
        foreach ([['480p', 0], ['480p', 1], ['720p', 0], ['1080p', 2], ['360p', 3]] as [$variant, $i]) {
            $path = $abrManager->ensureSegment('seg-job', $variant, $i);
            $this->assertNotNull($path, "ABR {$variant}#{$i} must produce a segment");
            $this->assertFileExists((string) $path);
        }

        // ---- The AC assertion: TOTAL probes across the WHOLE session ≤ 1 -----
        // 5 single-variant + 5 ABR segment builds re-derived HDR ZERO times.
        $this->assertSame(
            1,
            $spy->probeCalls,
            'a full multi-segment session must probe AT MOST once (job creation only)'
        );
        $this->assertSame(
            0,
            $spy->needsToneMappingCalls,
            'no segment may re-derive the HDR decision via needsToneMapping()'
        );
        $this->assertSame(
            0,
            $spy->getToneMappingProfileCalls,
            'no segment may re-derive the HDR decision via getToneMappingProfile()'
        );
    }

    /**
     * SV-4.2 ([S-F23]) fix (findings #1/#2): when a launched segment encode never
     * publishes within THIS request's poll window, that is a WAIT-TIMEOUT, not
     * abandonment. The poll loop's finally must NOT kill the encode — a
     * slow-but-wanted software 4K/HEVC transcode has to finish and publish for the
     * retrying requester. It only RELEASES tracking (via
     * releaseSegmentProcessAfterWaitTimeout), which also cleans the temp iff the
     * encode is already dead. Genuine abandonment kills via
     * RelayConsumer::onHttpCancel; `timeout <n>` is the stuck-encode backstop.
     */
    public function testEnsureSegmentDoesNotKillEncodeOnWaitTimeoutOnlyReleases(): void
    {
        $dir = $this->segmentDir . '/mv-timeout';
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

        $final = "{$dir}/seg-v480p-00002.ts";
        $ff = $this->createMock(FfmpegRunner::class);
        // Launch "succeeds" (returns a PID) but the segment file is NEVER created,
        // so the poll times out.
        $ff->expects($this->once())->method('startSegmentEncode')->willReturn(4321);
        // Release-only on wait-timeout — the still-running encode must NOT be
        // killed, and the plain (completion) release path must NOT run.
        $ff->expects($this->once())->method('releaseSegmentProcessAfterWaitTimeout')->with($final);
        $ff->expects($this->never())->method('releaseSegmentProcess');

        // Short poll ceiling (200ms) so the timeout path is fast.
        $manager = new TranscodeManager($db, $ff, $this->segmentDir, null, 6, null, null, null, null, null, null, 200);
        $path = $manager->ensureSegment('seg-job', '480p', 2);

        $this->assertNull($path, 'a timed-out segment resolves to null (503 upstream)');
    }

    /**
     * SV-4.2: the happy path — when the encode publishes the segment, the finally
     * RELEASES the registry entry (drops it without killing) so the map never
     * leaks, and does NOT kill the (already-exited) process.
     */
    public function testEnsureSegmentReleasesRegistryOnCompletion(): void
    {
        $dir = $this->segmentDir . '/mv-release';
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

        $final = "{$dir}/seg-v480p-00002.ts";
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out): int {
                file_put_contents($out, 'encoded');
                return 4321;
            }
        );
        $ff->expects($this->once())->method('releaseSegmentProcess')->with($final);

        $path = $this->manager($db, $ff)->ensureSegment('seg-job', '480p', 2);

        $this->assertSame($final, $path);
    }

    /**
     * SV-4.2-disconnect (SS-1): a SOLE requester is counted as exactly one waiter
     * on its `$final` while it is in the poll, `hasOtherWaiter()` is false, and the
     * ref-count returns to 0 (map entry removed) once the request finishes — no
     * leak. Sampled inside the launch callback, which runs while the launcher is
     * mid-produceSegment (already counted).
     */
    public function testSegmentWaiterCountIsOneForSoleRequesterAndReturnsToZero(): void
    {
        $dir = $this->segmentDir . '/mv-waiter-solo';
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

        $final = "{$dir}/seg-v480p-00002.ts";
        $observedCount = null;
        $observedOther = null;
        /** @var TranscodeManager|null $manager (assigned below; captured by-ref in the launch callback) */
        $manager = null;

        $ff = $this->createMock(FfmpegRunner::class);
        $this->stubColorMetadata($ff);
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out) use (&$observedCount, &$observedOther, &$manager, $final): int {
                assert($manager instanceof TranscodeManager);
                // The launcher is already counted (SS-1 increments at the top of
                // the poll try, before startSegmentEncode).
                $observedCount = $manager->waiterCount($final);
                $observedOther = $manager->hasOtherWaiter($final);
                file_put_contents($out, 'encoded'); // publish → the poll exits at once
                return 4321;
            }
        );

        $manager = new TranscodeManager($db, $ff, $this->segmentDir, null, 6);
        $path = $manager->ensureSegment('seg-job', '480p', 2);

        $this->assertSame($final, $path);
        $this->assertSame(1, $observedCount, 'the sole launcher is counted as exactly one waiter');
        $this->assertFalse($observedOther, 'a sole requester has no OTHER waiter');
        $this->assertSame(0, $manager->waiterCount($final), 'count returns to 0 after finishing — no leak');
    }

    /**
     * SV-4.2-disconnect (SS-1): TWO concurrent requesters for the same `$final` —
     * a launcher plus a piggybacker that joins the in-flight encode — push the
     * waiter ref-count to 2 (so `hasOtherWaiter()` is true), and it returns to 0
     * with the map entry removed once BOTH finish. Driven with real Swoole
     * coroutines: the launcher spawns the second requester inside its launch
     * callback and yields, so the two genuinely overlap in their poll loops.
     */
    public function testSegmentWaitersReachTwoWithConcurrentRequestersAndReturnToZero(): void
    {
        if (! extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required for the concurrent-waiter test.');
        }

        $dir = $this->segmentDir . '/mv-waiter-concurrent';
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

        $final = "{$dir}/seg-v480p-00002.ts";
        $peakCount = 0;
        $peakOther = false;
        /** @var TranscodeManager|null $manager (assigned below; captured by-ref in the launch callback) */
        $manager = null;

        $ff = $this->createMock(FfmpegRunner::class);
        $this->stubColorMetadata($ff);
        // Only the LAUNCHER calls startSegmentEncode; the second requester
        // deduplicates onto the same $final and piggybacks (no encode of its own).
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out) use (&$peakCount, &$peakOther, &$manager, $final): int {
                // We (the launcher) hold the reservation and are counted as a
                // waiter. Spawn a SECOND requester that sees the in-flight encode
                // and piggybacks (count → 2), then yield so it parks in its poll
                // before we sample and publish.
                \Swoole\Coroutine\go(static function () use (&$manager): void {
                    assert($manager instanceof TranscodeManager);
                    $manager->ensureSegment('seg-job', '480p', 2);
                });
                \Swoole\Coroutine::sleep(0.05);
                assert($manager instanceof TranscodeManager);
                $peakCount = $manager->waiterCount($final);
                $peakOther = $manager->hasOtherWaiter($final);
                file_put_contents($out, 'encoded'); // publish so BOTH requesters exit
                return 4321;
            }
        );

        // Generous poll ceiling so neither requester times out during the handoff.
        $manager = new TranscodeManager($db, $ff, $this->segmentDir, null, 6, null, null, null, null, null, null, 5000);

        \Swoole\Coroutine\run(static function () use ($manager): void {
            // The launcher runs in this top coroutine; the piggyback is spawned
            // inside startSegmentEncode. Co\run returns only once BOTH have run.
            $manager->ensureSegment('seg-job', '480p', 2);
        });

        $this->assertSame(2, $peakCount, 'launcher + piggyback are both counted as waiters');
        $this->assertTrue($peakOther, 'a piggybacker is a genuine OTHER waiter (guards the shared encode)');
        $this->assertSame(0, $manager->waiterCount($final), 'both finished → count back to 0, entry removed (no leak)');
    }

    /**
     * SV-4.2-disconnect (SS-1): the AUDIO-only poll path (produceAudioSegment)
     * also ref-counts its waiter and returns to 0 — the guard covers both the
     * video and audio segment paths.
     */
    public function testAudioSegmentAlsoRefCountsWaiterAndReturnsToZero(): void
    {
        $dir = $this->segmentDir . '/ma-waiter';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->multiAudioJobRow($dir, $input), $captured);

        $final = "{$dir}/seg-a1-00001.ts";
        $observedCount = null;
        /** @var TranscodeManager|null $manager (assigned below; captured by-ref in the launch callback) */
        $manager = null;

        $ff = $this->createMock(FfmpegRunner::class);
        $this->stubColorMetadata($ff);
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out) use (&$observedCount, &$manager, $final): int {
                assert($manager instanceof TranscodeManager);
                $observedCount = $manager->waiterCount($final);
                file_put_contents($out, 'audio');
                return 1;
            }
        );

        $manager = new TranscodeManager($db, $ff, $this->segmentDir, null, 6);
        $path = $manager->ensureSegment('seg-job', null, 1, 'a1');

        $this->assertSame($final, $path);
        $this->assertSame(1, $observedCount, 'the audio launcher is counted as a waiter too');
        $this->assertSame(0, $manager->waiterCount($final), 'audio path also returns the count to 0 — no leak');
    }

    // -------------------------------------------------------------------------
    // SV-4.2-disconnect F1: invalidate the dedup reservation on a genuine reap so
    // the next requester re-launches instead of deduping onto the killed encode.
    // The registry's reap callback + waiter guard are wired to the manager exactly
    // as TranscodeServicesProvider does in production (not a fake guard).
    // -------------------------------------------------------------------------

    /**
     * Build a real per-worker registry with a spy signal sender and wire it to the
     * manager the SAME way TranscodeServicesProvider does (waiter guard + reap
     * callback), so a real kill() defers on a live piggybacker and invalidates the
     * dedup reservation on a genuine reap.
     *
     * @param array<int, int> $signalled Filled with each PID the registry signals.
     */
    private function wiredRegistry(TranscodeManager $manager, array &$signalled): SegmentProcessRegistry
    {
        $signalled = [];
        $registry = new SegmentProcessRegistry(
            null,
            static function (int $pid, int $signal) use (&$signalled): void {
                $signalled[] = $pid;
            },
            static fn (int $pid): bool => false, // dead immediately → no SIGKILL escalation
            0.01,
            static function (string $tmp): void {
                // no-op temp cleaner (never touch the filesystem in tests)
            },
        );
        $registry->setWaiterGuard(static fn (string $key): bool => $manager->hasOtherWaiter($key));
        $registry->setReapCallback(static function (string $key) use ($manager): void {
            $manager->invalidateReservation($key);
        });

        return $registry;
    }

    public function testReapInvalidatesReservationSoNextRequesterRelaunches(): void
    {
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff);

        $final = "{$this->segmentDir}/job/seg-v720p-00005.ts";
        $now = (int) (hrtime(true) / 1_000_000);
        // A launcher reserved + is mid-encode (generation-stamped reservation).
        $this->setPrivate($manager, 'segmentEncodesInFlight', [$final => ['at' => $now, 'gen' => 1]]);
        $this->assertTrue(
            $this->callPrivate($manager, 'segmentEncodeInFlight', $final),
            'a fresh requester would dedup onto the in-flight encode'
        );

        $signalled = [];
        $registry = $this->wiredRegistry($manager, $signalled);
        $registry->register($final, 4242); // the launcher's tracked PID

        // Genuine abandonment (no other waiter) → signal + reap + invalidate.
        $killed = $registry->kill($final);

        $this->assertSame(1, $killed);
        $this->assertSame([4242], $signalled, 'the abandoned encode was signalled');
        $this->assertFalse(
            $this->callPrivate($manager, 'segmentEncodeInFlight', $final),
            'F1: the reservation is invalidated on reap, so the NEXT requester re-launches (no dedup-onto-corpse 404)'
        );
        $this->assertSame([], $this->inFlightSet($manager), 'no stale reservation left behind');
    }

    public function testDeferredKillDoesNotInvalidateReservation(): void
    {
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff);

        $final = "{$this->segmentDir}/job/seg-v720p-00006.ts";
        $now = (int) (hrtime(true) / 1_000_000);
        $this->setPrivate($manager, 'segmentEncodesInFlight', [$final => ['at' => $now, 'gen' => 1]]);
        // TWO waiters (launcher + piggyback) → hasOtherWaiter() true → kill defers.
        $this->setPrivate($manager, 'segmentWaiters', [$final => 2]);

        $signalled = [];
        $registry = $this->wiredRegistry($manager, $signalled);
        $registry->register($final, 4242);

        $killed = $registry->kill($final);

        $this->assertSame(0, $killed, 'deferred: nothing signalled while a piggybacker waits');
        $this->assertSame([], $signalled);
        $this->assertTrue(
            $this->callPrivate($manager, 'segmentEncodeInFlight', $final),
            'F1: a DEFERRED kill must NOT invalidate — the encode is still wanted and keeps publishing'
        );
        $this->assertSame([4242], $registry->pidsFor($final), 'the deferred encode stays tracked for the remaining waiter');
    }

    public function testStaleLauncherFinallyDoesNotClobberFreshReservation(): void
    {
        // A launches gen=1 and is mid-encode. A disconnects → its encode is reaped
        // (reservation invalidated). A fresh launcher B re-reserves the same $final
        // (gen=2) and registers its own encode. When A's still-parked coroutine
        // times out, its finally must be a NO-OP (gen mismatch) — it must NOT clear
        // B's reservation nor release B's registry registration, which would re-open
        // the SV-4.1 double-encode race + drop B's cancel tracking.
        $dir = $this->segmentDir . '/f1-noclobber';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        // 50ms wait ceiling so A's poll times out quickly after the supersede.
        $manager = $this->segManager($db, $ff, null, null, null, 50);

        $final = "{$dir}/seg-00004.ts";
        $signalled = [];
        $registry = $this->wiredRegistry($manager, $signalled);

        // A (this call) launches; inside the launch we model A being reaped mid-poll
        // and B superseding the reservation with a fresh generation + its own PID.
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function () use ($manager, $registry, $final): int {
                $registry->register($final, 4242);   // A's PID, now mid-encode
                $registry->kill($final);             // disconnect reaps A + invalidates gen=1
                // B re-launches the same segment: fresh reservation (gen=2) + PID.
                $now = (int) (hrtime(true) / 1_000_000);
                $this->setPrivate($manager, 'segmentEncodesInFlight', [$final => ['at' => $now, 'gen' => 2]]);
                $registry->register($final, 7777);
                return 4242; // A's encode never publishes (it was killed)
            }
        );
        // A's finally must NOT release the registry for $final (gen mismatch), so B's
        // PID stays tracked and the mock's release methods are never called by A.
        $ff->expects($this->never())->method('releaseSegmentProcess');
        $ff->expects($this->never())->method('releaseSegmentProcessAfterWaitTimeout');

        $path = $manager->ensureSegment('seg-job', null, 4);

        $this->assertNull($path, 'A never published (its encode was reaped)');
        $this->assertSame(
            2,
            $this->inFlightSet($manager)[$final]['gen'] ?? null,
            'F1 no-clobber: A stale finally must not clear B fresh reservation'
        );
        $this->assertSame([7777], $registry->pidsFor($final), "B's PID tracking preserved (still cancellable)");
    }

    public function testReconcileSupersedeReleasesRegistryOnCompletedSoLauncherFinallyDoesNotOrphan(): void
    {
        // SV-4.2 (F1 leak-regression): A launches gen=1 (registers its PID + cancel
        // group), parks in its poll loop, and ffmpeg publishes $final while A sleeps.
        // A CONCURRENT request B runs reconcileInFlightSegments(), which — seeing the
        // published final and NO live .part-* — clears A's completed reservation with
        // NO kill. That supersedes A's generation, so when A wakes its gen-gated
        // finally is a no-op. Pre-fix, reconcile released nothing, so A's registry
        // entry (dead PID + temp + cancel-group link) was ORPHANED (a resident-memory
        // leak in the long-running worker). The fix makes reconcile perform A's own
        // completed-case release, so the entry is drained exactly once (reconcile
        // releases; A's superseded finally no-ops — no double release).
        $dir = $this->segmentDir . '/f1-reconcile-complete';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff);

        $final = "{$dir}/seg-00004.ts";
        $signalled = [];
        $registry = $this->wiredRegistry($manager, $signalled);

        // Forward the manager's release call to the real registry so the leak is
        // observable at the registry level (no orphaned PID/tmp/group).
        $releaseCalls = 0;
        $ff->method('releaseSegmentProcess')->willReturnCallback(
            function (string $key) use ($registry, &$releaseCalls): void {
                $releaseCalls++;
                $registry->release($key);
            }
        );
        // Completed branch must use the plain release, never the wait-timeout one.
        $ff->expects($this->never())->method('releaseSegmentProcessAfterWaitTimeout');

        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function () use ($manager, $registry, $final): int {
                // A is now mid-encode: track its detached PID + cancel group + temp.
                $registry->register($final, 4242, 'chan-A', $final . '.part-deadbeef');
                // ffmpeg renames its .part-* → $final (publishes) while A sleeps; only
                // the final now exists on disk (no live .part-* remains for $final).
                file_put_contents($final, 'x');
                // Concurrent request B reconciles: reset the throttle A's own
                // produceSegment reconcile stamped so B's pass re-globs and runs.
                $this->setPrivate($manager, 'lastInFlightReconcileMs', null);
                $this->callPrivate($manager, 'reconcileInFlightSegments');
                return 4242; // A's PID (its encode already published)
            }
        );

        $path = $manager->ensureSegment('seg-job', null, 4);

        $this->assertSame($final, $path, 'A observes the published segment');
        $this->assertSame([], $this->inFlightSet($manager), 'reconcile cleared A completed reservation');
        $this->assertSame(1, $releaseCalls, 'exactly ONE release (reconcile); A stale finally no-ops (no double release)');
        $this->assertSame(0, $registry->registeredKeyCount(), 'F1 fix: reconcile released A registry entry — no orphaned PID');
        $this->assertSame([], $registry->pidsFor($final), 'no orphaned PID left under $final');
        $this->assertSame(0, $registry->registeredGroupCount(), 'A cancel-group link torn down (no orphaned group)');
        $this->assertSame([], $signalled, 'reconcile-release is NOT a kill — the (dead) PID was never signalled');
    }

    public function testReconcileSupersedeAudioReleasesRegistryOnCompletedSoLauncherFinallyDoesNotOrphan(): void
    {
        // F1 leak-regression parity for the AUDIO launcher (produceAudioSegment): its
        // reconcile at the top of the request + its gen-gated finally behave exactly
        // like the video path, so a reconcile-supersede must likewise release the
        // audio launcher's registry entry rather than orphan it.
        $dir = $this->segmentDir . '/f1-reconcile-audio';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->multiAudioJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $this->stubColorMetadata($ff);
        $manager = new TranscodeManager($db, $ff, $this->segmentDir, null, 6);

        $final = "{$dir}/seg-a1-00001.ts";
        $signalled = [];
        $registry = $this->wiredRegistry($manager, $signalled);

        $releaseCalls = 0;
        $ff->method('releaseSegmentProcess')->willReturnCallback(
            function (string $key) use ($registry, &$releaseCalls): void {
                $releaseCalls++;
                $registry->release($key);
            }
        );
        $ff->expects($this->never())->method('releaseSegmentProcessAfterWaitTimeout');

        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function () use ($manager, $registry, $final): int {
                $registry->register($final, 4343, 'chan-Aa', $final . '.part-feedface');
                file_put_contents($final, 'audio');
                $this->setPrivate($manager, 'lastInFlightReconcileMs', null);
                $this->callPrivate($manager, 'reconcileInFlightSegments');
                return 4343;
            }
        );

        $path = $manager->ensureSegment('seg-job', null, 1, 'a1');

        $this->assertSame($final, $path, 'the audio launcher observes the published segment');
        $this->assertSame([], $this->inFlightSet($manager), 'reconcile cleared the audio launcher completed reservation');
        $this->assertSame(1, $releaseCalls, 'exactly ONE release (reconcile); the audio finally no-ops');
        $this->assertSame(0, $registry->registeredKeyCount(), 'F1 fix: audio launcher registry entry released — no orphan');
        $this->assertSame([], $registry->pidsFor($final), 'no orphaned audio PID left under $final');
        $this->assertSame(0, $registry->registeredGroupCount(), 'audio cancel-group link torn down');
        $this->assertSame([], $signalled, 'reconcile-release never signals the PID');
    }

    public function testReconcileSupersedeReleasesRegistryOnStaleBranchViaWaitTimeout(): void
    {
        // The STALE branch (encode died without publishing: no final, no live
        // .part-*, past the grace window) also clears the reservation with NO kill.
        // Pre-fix it left the launcher's registry entry orphaned; the fix mirrors the
        // launcher's non-is_file case — releaseSegmentProcessAfterWaitTimeout — which
        // drops the entry and cleans the dead encode's own temp (never signalling).
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff);

        $jobDir = "{$this->segmentDir}/f1-reconcile-stale";
        mkdir($jobDir, 0755, true);
        $final = "{$jobDir}/seg-v720p-00002.ts"; // no final + no .part-* on disk → stale

        $signalled = [];
        $registry = $this->wiredRegistry($manager, $signalled);
        $registry->register($final, 5150, 'chan-S', $final . '.part-cafef00d');

        $waitReleases = [];
        $ff->method('releaseSegmentProcessAfterWaitTimeout')->willReturnCallback(
            function (string $key) use ($registry, &$waitReleases): void {
                $waitReleases[] = $key;
                $registry->releaseAfterWaitTimeout($key);
            }
        );
        // Stale branch must use the wait-timeout release, never the plain one.
        $ff->expects($this->never())->method('releaseSegmentProcess');

        $now = (int) (hrtime(true) / 1_000_000);
        $grace = (new \ReflectionClassConstant(TranscodeManager::class, 'SEGMENT_INFLIGHT_STALE_GRACE_MS'))
            ->getValue();
        $this->assertIsInt($grace);
        // Reservation launched well before the grace window → reconcile treats it stale.
        $this->setPrivate($manager, 'segmentEncodesInFlight', [$final => ['at' => $now - $grace - 1000, 'gen' => 1]]);

        $this->callPrivate($manager, 'reconcileInFlightSegments');

        $this->assertSame([], $this->inFlightSet($manager), 'stale reservation cleared');
        $this->assertSame([$final], $waitReleases, 'reconcile performed the wait-timeout release for the stale entry');
        $this->assertSame(0, $registry->registeredKeyCount(), 'F1 fix: stale-branch registry entry released — no orphan');
        $this->assertSame([], $registry->pidsFor($final), 'no orphaned PID under the stale $final');
        $this->assertSame(0, $registry->registeredGroupCount(), 'stale entry cancel-group link torn down');
        $this->assertSame([], $signalled, 'wait-timeout release never signals the PID');
    }

    public function testAudioSegmentReservationIsGenerationStampedAndClears(): void
    {
        // F1 covers BOTH paths: the audio launcher (produceAudioSegment) also stamps
        // a generation token and its finally compare-and-clears on completion.
        $dir = $this->segmentDir . '/f1-audio';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->multiAudioJobRow($dir, $input), $captured);

        $final = "{$dir}/seg-a1-00001.ts";
        $observedGen = null;
        /** @var TranscodeManager|null $manager (assigned below; captured by-ref) */
        $manager = null;
        $ff = $this->createMock(FfmpegRunner::class);
        $this->stubColorMetadata($ff);
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out) use (&$observedGen, &$manager, $final): int {
                assert($manager instanceof TranscodeManager);
                $observedGen = $this->inFlightSet($manager)[$final]['gen'] ?? null;
                file_put_contents($out, 'audio');
                return 1;
            }
        );

        $manager = new TranscodeManager($db, $ff, $this->segmentDir, null, 6);
        $path = $manager->ensureSegment('seg-job', null, 1, 'a1');

        $this->assertSame($final, $path);
        $this->assertIsInt($observedGen, 'F1: the audio path also stamps a generation token on its reservation');
        $this->assertSame([], $this->inFlightSet($manager), 'audio reservation cleared on completion (gen match)');
    }

    public function testRelayKillGroupAlsoInvalidatesReservation(): void
    {
        // Relay parity: RelayConsumer::onHttpCancel reaps via killGroup(channelId),
        // which flows through the SAME kill() → so invalidate-on-reap fires for the
        // relay transport too (one shared registry callback covers both transports).
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff);

        $final = "{$this->segmentDir}/job/seg-v720p-00009.ts";
        $now = (int) (hrtime(true) / 1_000_000);
        $this->setPrivate($manager, 'segmentEncodesInFlight', [$final => ['at' => $now, 'gen' => 1]]);

        $signalled = [];
        $registry = $this->wiredRegistry($manager, $signalled);
        $registry->register($final, 4242, 'chan-7'); // grouped under a relay channel id

        $killed = $registry->killGroup('chan-7');

        $this->assertSame(1, $killed);
        $this->assertSame([4242], $signalled);
        $this->assertFalse(
            $this->callPrivate($manager, 'segmentEncodeInFlight', $final),
            'F1 relay parity: killGroup (onHttpCancel path) also invalidates the reservation'
        );
    }

    public function testGenerationStampedReservationStillDedupsWhenNotKilled(): void
    {
        // SV-4.1 intact: with the new generation-stamped reservation shape, a
        // concurrent requester for a segment already reserved (and NOT killed) still
        // dedups — startSegmentEncode is never called a second time and the pre-
        // existing reservation is not clobbered by the piggyback's finally.
        $dir = $this->segmentDir . '/f1-dedup';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('startSegmentEncode');
        $manager = $this->segManager($db, $ff, null, null, null, 50);

        $final = "{$dir}/seg-00007.ts";
        $now = (int) (hrtime(true) / 1_000_000);
        // An encode is already reserved in-worker (gen-stamped) but NOT killed.
        $this->setPrivate($manager, 'segmentEncodesInFlight', [$final => ['at' => $now, 'gen' => 1]]);

        $this->assertNull(
            $manager->ensureSegment('seg-job', null, 7),
            'poll times out but no duplicate encode is launched'
        );
        $this->assertSame(
            1,
            $this->inFlightSet($manager)[$final]['gen'] ?? null,
            'dedup: reservation preserved, no clobber by a piggyback'
        );
    }

    public function testSegmentWaiterCountReturnsToZeroWhenPollBodyThrows(): void
    {
        // SV-4.2-disconnect F7 (leak-on-exception): the waiter ref-count
        // (segmentWaiters) must decrement in the finally even when the poll body
        // throws mid-launch. A leaked waiter would keep the segment permanently
        // "wanted" (hasOtherWaiter true) and wrongly DEFER every future kill for it.
        $dir = $this->segmentDir . '/f7-waiter-throw';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->onDemandJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = $this->segManager($db, $ff, null, null, null, 100);

        // S120 — the callback RECORDS, the assertions run OUTSIDE it. It used to call
        // $this->assertSame() directly, which the `catch (\RuntimeException)` below
        // swallowed (ExpectationFailedException IS a RuntimeException), so this test
        // stayed GREEN with `assertTrue(false)` planted in the callback.
        $final = "{$dir}/seg-00008.ts";
        $waitersAtLaunch = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function () use ($manager, $final, &$waitersAtLaunch): int {
                $waitersAtLaunch = $manager->waiterCount($final);
                throw new \RuntimeException('ffmpeg arg builder blew up');
            }
        );

        $thrown = null;
        try {
            $manager->ensureSegment('seg-job', null, 8);
        } catch (AssertionFailedError $e) {
            // S120 — PHPUnit's ExpectationFailedException extends AssertionFailedError
            // extends PHPUnit\Framework\Exception extends RuntimeException, so without
            // this arm the `catch (\RuntimeException)` below eats any assertion that
            // future maintenance puts inside the callback above and the test goes
            // green regardless. Re-throw: a failed assertion is never "the exception
            // under test".
            throw $e;
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        // Identify the exception by MESSAGE, not merely by "something was caught":
        // the old assertNotNull() was satisfied by a swallowed assertion failure, so
        // the guard meant to prove propagation was what the swallowing made pass.
        $this->assertInstanceOf(\RuntimeException::class, $thrown, 'the exception propagates, it is not swallowed');
        $this->assertSame(
            'ffmpeg arg builder blew up',
            $thrown->getMessage(),
            'the launcher\'s own exception propagated — not some other RuntimeException'
        );
        $this->assertSame(1, $waitersAtLaunch, 'the launcher is counted before the throw');
        $this->assertSame(0, $manager->waiterCount($final), 'F7: waiter ref-count decremented on a mid-poll throw — no leak');
        $this->assertFalse($manager->hasOtherWaiter($final), 'no phantom waiter left to defer future kills');
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

    public function testEnsureSegmentResolvesNonCopyOriginalAsTranscode(): void
    {
        // A NON-copy original (HEVC source → transcode) is now ALWAYS advertised in
        // the master, so ensureSegment('original', …) must resolve it to a genuine
        // transcode contract at the source-resolution frame (clamped to the cap).
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
        $captParams = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $start, float $len, array $params) use (&$captParams): int {
                $captParams = $params;
                file_put_contents($out, 'encoded');
                return 1;
            }
        );

        $path = $this->manager($db, $ff)->ensureSegment('seg-job', 'original', 0);

        $this->assertSame("{$dir}/seg-voriginal-00000.ts", $path);
        $this->assertNotNull($captParams);
        $this->assertSame('libx264', $captParams['video_codec']);
        $this->assertSame('aac', $captParams['audio_codec']);
        // Source frame clamped to web's 1920×1080 cap (4K source, aspect kept).
        $this->assertSame(1920, $captParams['width']);
        $this->assertSame(1080, $captParams['height']);
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
        $copy = $method->invoke(self::bareTranscodeManagerForRendition(), ['is_copy' => true, 'width' => 1920, 'height' => 1080,
            'codecs' => 'avc1.640029,mp4a.40.2', 'video_bitrate' => 6000000]);
        $this->assertSame(['video_codec' => 'copy', 'audio_codec' => 'copy'], $copy);

        // Transcode rendition → capped-CRF H.264/AAC contract with derived VBV + level.
        $t = $method->invoke(self::bareTranscodeManagerForRendition(), ['is_copy' => false, 'width' => 1280, 'height' => 720,
            'codecs' => 'avc1.640029,mp4a.40.2', 'video_bitrate' => 2800000]);
        $this->assertIsArray($t);
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
        $this->assertNotNull($captParams);
        $this->assertSame('libx264', $captParams['video_codec']);
        $this->assertSame('aac', $captParams['audio_codec']);
    }

    // --- P3B multi-audio: shared audio group + relative-index mapping ---

    /**
     * A probe with a video stream at global index 0 and TWO audio streams at
     * GLOBAL ffprobe indexes 1 and 2 — so the audio-RELATIVE indexes (0, 1)
     * deliberately differ from the global ones.
     *
     * @return array<string, mixed>
     */
    private function twoAudioProbe(): array
    {
        return [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2,
                    'tags' => ['language' => 'eng', 'title' => 'English'],
                    'disposition' => ['default' => 1]],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'ac3', 'channels' => 6,
                    'tags' => ['language' => 'jpn', 'title' => 'Japanese']],
            ],
            'format' => ['duration' => '25.0'],
        ];
    }

    /**
     * Audio-track descriptors matching {@see twoAudioProbe()} as
     * buildAudioTrackDescriptors() persists them (relative index + global stream_index).
     *
     * @return list<array<string, mixed>>
     */
    private function twoAudioTracks(): array
    {
        return [
            ['index' => 0, 'stream_index' => 1, 'language' => 'eng', 'label' => 'English',
                'default' => true, 'codec' => 'aac'],
            ['index' => 1, 'stream_index' => 2, 'language' => 'jpn', 'label' => 'Japanese',
                'default' => false, 'codec' => 'ac3'],
        ];
    }

    public function testEnsureHlsJobEmitsSingleSharedAudioGroupMaster(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn($this->twoAudioProbe());

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
        $dir = $this->capturedJobInsert($captured)['hls_dir'];
        $master = (string) file_get_contents("{$dir}/master.m3u8");

        // ONE shared group: every EXT-X-MEDIA rendition sits under GROUP-ID="aud"
        // (per-track groups a0/a1 were the bug — hls.js only ever exposes the
        // renditions of the group the playing variant references).
        $this->assertSame(2, substr_count($master, '#EXT-X-MEDIA:TYPE=AUDIO'));
        $this->assertSame(2, substr_count($master, 'GROUP-ID="aud"'));
        $this->assertStringNotContainsString('GROUP-ID="a0"', $master);
        $this->assertStringNotContainsString('GROUP-ID="a1"', $master);
        // Per-track NAME + LANGUAGE, exactly one DEFAULT=YES, AUTOSELECT on all.
        $this->assertStringContainsString('NAME="English"', $master);
        $this->assertStringContainsString('NAME="Japanese"', $master);
        $this->assertStringContainsString('LANGUAGE="eng"', $master);
        $this->assertStringContainsString('LANGUAGE="jpn"', $master);
        $this->assertSame(1, substr_count($master, 'DEFAULT=YES'));
        $this->assertSame(2, substr_count($master, 'AUTOSELECT=YES'));
        // URIs are keyed on the AUDIO-RELATIVE index (0/1), not global (1/2).
        $this->assertStringContainsString('URI="media_a0.m3u8"', $master);
        $this->assertStringContainsString('URI="media_a1.m3u8"', $master);
        $this->assertStringNotContainsString('media_a2.m3u8', $master);
        // EVERY video variant references the shared group.
        foreach (explode("\n", $master) as $line) {
            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $this->assertStringContainsString('AUDIO="aud"', $line);
            }
        }
        // The audio-only playlists exist and reference relative-index segments.
        $this->assertFileExists("{$dir}/media_a0.m3u8");
        $this->assertFileExists("{$dir}/media_a1.m3u8");
        $audio1 = (string) file_get_contents("{$dir}/media_a1.m3u8");
        $this->assertStringContainsString('seg-a1-00000.ts', $audio1);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $audio1);

        // The persisted variants JSON carries the descriptors with BOTH indexes.
        $decoded = json_decode($this->capturedVariantsJson($captured), true);
        $this->assertIsArray($decoded);
        $this->assertSame([0, 1], array_column($decoded['audio_tracks'], 'index'));
        $this->assertSame([1, 2], array_column($decoded['audio_tracks'], 'stream_index'));
        $this->assertSame([true, false], array_column($decoded['audio_tracks'], 'default'));
    }

    public function testSingleAudioSourceEmitsNoAudioGroup(): void
    {
        // One audio stream → no EXT-X-MEDIA, no AUDIO= attr, muxed segments as before.
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '25.0'],
        ]);

        $this->manager($db, $ff)->ensureHlsJob('media-1', 'web');
        $dir = $this->capturedJobInsert($captured)['hls_dir'];
        $master = (string) file_get_contents("{$dir}/master.m3u8");

        $this->assertStringNotContainsString('#EXT-X-MEDIA', $master);
        $this->assertStringNotContainsString('AUDIO=', $master);
        $decoded = json_decode($this->capturedVariantsJson($captured), true);
        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('audio_tracks', $decoded);
    }

    /**
     * A multi-variant job row whose ladder JSON carries the two-audio descriptors.
     *
     * @return array<string, mixed>
     */
    private function multiAudioJobRow(string $dir, string $input): array
    {
        $ladder = (new AbrLadder())->build(
            new SourceProfile(width: 1280, height: 720, videoCodec: 'h264', audioCodec: 'aac'),
            'web'
        )->toArray();
        $ladder['audio_tracks'] = $this->twoAudioTracks();
        return $this->multiVariantJobRow($dir, $input, (string) json_encode($ladder));
    }

    public function testEnsureSegmentAudioOnlyUsesRelativeIndexAndAudioOnlyParams(): void
    {
        // seg-a1-NNNNN.ts = the SECOND audio stream: the encode must receive the
        // audio-RELATIVE index 1 (→ -map 0:a:1), NOT the global ffprobe index 2,
        // and must be flagged audio_only so FfmpegRunner takes the -vn path.
        $dir = $this->segmentDir . '/ma-audio';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->multiAudioJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $captParams = null;
        $captOut = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $s, float $l, array $params) use (&$captParams, &$captOut): int {
                $captParams = $params;
                $captOut = $out;
                file_put_contents($out, 'audio');
                return 1;
            }
        );

        $path = $this->manager($db, $ff)->ensureSegment('seg-job', null, 1, 'a1');

        $this->assertSame("{$dir}/seg-a1-00001.ts", $path);
        $this->assertSame("{$dir}/seg-a1-00001.ts", $captOut);
        $this->assertNotNull($captParams);
        $this->assertTrue($captParams['audio_only']);
        $this->assertSame(1, $captParams['audio_stream_index'], 'audio-relative index, not the global 2');
        $this->assertSame('aac', $captParams['audio_codec']);
        $this->assertArrayNotHasKey('video_codec', $captParams);
    }

    public function testEnsureSegmentAudioOnlyRejectsUnknownTrackAndGrouplessJob(): void
    {
        $dir = $this->segmentDir . '/ma-audio-reject';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->multiAudioJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('startSegmentEncode');
        $manager = $this->manager($db, $ff);

        // Out-of-range track (only a0/a1 exist) and malformed ids → null (404).
        $this->assertNull($manager->ensureSegment('seg-job', null, 0, 'a5'));
        $this->assertNull($manager->ensureSegment('seg-job', null, 0, 'axx'));

        // A job WITHOUT audio_tracks advertises no audio renditions → null too.
        $dir2 = $this->segmentDir . '/ma-audio-nogroup';
        mkdir($dir2, 0755, true);
        $ladderJson = (string) json_encode(
            (new AbrLadder())->build(
                new SourceProfile(width: 1280, height: 720, videoCodec: 'h264', audioCodec: 'aac'),
                'web'
            )->toArray()
        );
        $captured2 = [];
        $db2 = $this->mockDb([], 0, [], $this->multiVariantJobRow($dir2, $input, $ladderJson), $captured2);
        $ff2 = $this->createMock(FfmpegRunner::class);
        $ff2->expects($this->never())->method('startSegmentEncode');

        $this->assertNull($this->manager($db2, $ff2)->ensureSegment('seg-job', null, 0, 'a0'));
    }

    public function testVideoVariantSegmentsAreVideoOnlyWhenAudioGroupPresent(): void
    {
        // With a shared audio group in the master, sound travels in the audio
        // renditions — a video variant segment must be encoded video-only.
        $dir = $this->segmentDir . '/ma-videoonly';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->multiAudioJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $captParams = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $s, float $l, array $params) use (&$captParams): int {
                $captParams = $params;
                file_put_contents($out, 'video');
                return 1;
            }
        );

        $path = $this->manager($db, $ff)->ensureSegment('seg-job', '480p', 0);

        $this->assertSame("{$dir}/seg-v480p-00000.ts", $path);
        $this->assertNotNull($captParams);
        $this->assertTrue($captParams['video_only']);
        $this->assertSame('libx264', $captParams['video_codec']);
    }

    public function testVideoVariantSegmentsStayMuxedWithoutAudioGroup(): void
    {
        // Single-audio job (no audio_tracks): current muxed behaviour is preserved.
        $dir = $this->segmentDir . '/ma-muxed';
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
        $captParams = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $s, float $l, array $params) use (&$captParams): int {
                $captParams = $params;
                file_put_contents($out, 'video');
                return 1;
            }
        );

        $this->manager($db, $ff)->ensureSegment('seg-job', '480p', 0);

        $this->assertNotNull($captParams);
        $this->assertArrayNotHasKey('video_only', $captParams);
        $this->assertSame('aac', $captParams['audio_codec']);
    }

    // ---- SV-3.3(1B): loudness normalization reaches all three param-assembly sites ----

    /**
     * The canonical enabled loudness target the provider (SV-3.3(1A)) would thread
     * into the ctor: single-pass EBU R128 I/LRA/TP.
     *
     * @return array{I: float, LRA: float, TP: float}
     */
    private function loudnormTarget(): array
    {
        return ['I' => -16.0, 'LRA' => 11.0, 'TP' => -1.5];
    }

    /**
     * Builds a manager with the SV-3.3(1A) loudness target threaded as ctor arg 14
     * (the position sub-step 1A added it at, after $minDiskSpaceBytes).
     *
     * @param FfmpegRunner&MockObject $ff
     * @param array<string, float>|null $loudnorm
     */
    private function loudnormManager(Connection $db, FfmpegRunner $ff, ?array $loudnorm): TranscodeManager
    {
        $this->stubColorMetadata($ff);
        return new TranscodeManager(
            $db,
            $ff,
            $this->segmentDir,
            null,
            6,
            null,
            null,
            null,
            null,
            null,
            null,
            5000,
            null,
            $loudnorm
        );
    }

    /**
     * A single-audio ffprobe (one video + one AAC stream) — a job that muxes audio
     * into the video segments (no shared audio group), so its computeSegmentParams
     * base params (site 1) and its ABR video rungs (site 2) both carry the audio.
     *
     * @return array<string, mixed>
     */
    private function singleAudioProbe(): array
    {
        return [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '25.0'],
        ];
    }

    /**
     * SITE 1 (computeSegmentParams): with loudness ENABLED, the target is threaded
     * into the base params that ensureHlsJob() persists to `segment_params` — the
     * exact column the LEGACY single-variant ensureSegment() path reads straight
     * back. Feeding that persisted array into the real FfmpegRunner proves the
     * command gains `-af "loudnorm=…"` on the audio re-encode.
     */
    public function testComputeSegmentParamsPersistsLoudnormTargetWhenEnabled(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn($this->singleAudioProbe());

        $this->loudnormManager($db, $ff, $this->loudnormTarget())->ensureHlsJob('media-1', 'web');

        $seg = $this->capturedJobInsert($captured)['segment_params'];
        $this->assertArrayHasKey('loudnorm', $seg);
        $this->assertIsArray($seg['loudnorm']);
        $this->assertEqualsWithDelta(-16.0, $seg['loudnorm']['I'], 0.001);
        $this->assertEqualsWithDelta(11.0, $seg['loudnorm']['LRA'], 0.001);
        $this->assertEqualsWithDelta(-1.5, $seg['loudnorm']['TP'], 0.001);

        // The persisted params (what the legacy path forwards verbatim) drive a real
        // `-af "loudnorm=…"` on the video+audio segment command.
        $cmd = (new ToneMapThreadingSpyRunner(null, false))
            ->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, $seg);
        $this->assertStringContainsString('-af "loudnorm=I=-16:LRA=11:TP=-1.5"', $cmd);
    }

    /**
     * SITE 1 disabled: with loudness OFF (null — the shipped default), no `loudnorm`
     * key is added to the persisted params, so the produced command is byte-clean of
     * any `-af loudnorm` (byte-identical to pre-SV-3.3).
     */
    public function testComputeSegmentParamsOmitsLoudnormWhenDisabled(): void
    {
        $captured = [];
        $db = $this->mockDb([], 0, ['path' => '/m.mkv'], [], $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn($this->singleAudioProbe());

        $this->loudnormManager($db, $ff, null)->ensureHlsJob('media-1', 'web');

        $seg = $this->capturedJobInsert($captured)['segment_params'];
        $this->assertArrayNotHasKey('loudnorm', $seg);

        $cmd = (new ToneMapThreadingSpyRunner(null, false))
            ->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, $seg);
        $this->assertStringNotContainsString('loudnorm', $cmd);
    }

    /**
     * SITE 1 legacy read path: a legacy single-variant job (no `variants`) whose
     * persisted `segment_params` carries the loudnorm target (as computeSegmentParams
     * wrote it) forwards that target through ensureSegment() → startSegmentEncode(),
     * and the real builder emits `-af "loudnorm=…"`.
     */
    public function testEnsureSegmentLegacyPathForwardsPersistedLoudnorm(): void
    {
        $dir = $this->segmentDir . '/ln-legacy';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $jobRow = [
            'id' => 'seg-job',
            'hls_dir' => $dir,
            'input_path' => $input,
            'status' => 'completed',
            'duration_seconds' => 60,
            'segment_seconds' => 6,
            'segment_params' => json_encode([
                'video_codec' => 'libx264',
                'audio_codec' => 'aac',
                'loudnorm' => $this->loudnormTarget(),
            ]),
        ];
        $captured = [];
        $db = $this->mockDb([], 0, [], $jobRow, $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $captParams = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $s, float $l, array $params) use (&$captParams): int {
                $captParams = $params;
                file_put_contents($out, 'encoded');
                return 1;
            }
        );

        $this->manager($db, $ff)->ensureSegment('seg-job', null, 2);

        $this->assertNotNull($captParams);
        $this->assertArrayHasKey('loudnorm', $captParams);
        $cmd = (new ToneMapThreadingSpyRunner(null, false))
            ->buildSegmentCommand($input, "{$dir}/seg-00002.ts", 12.0, 6.0, $captParams);
        $this->assertStringContainsString('-af "loudnorm=I=-16:LRA=11:TP=-1.5"', $cmd);
    }

    /**
     * SITE 2 (ABR per-rendition): segmentParamsForRendition() rebuilds params fresh
     * per-rung and carries NO loudnorm target; the applyLoudnorm() merge-back in the
     * multi-variant branch is what threads it. On a SINGLE-audio job the video rung
     * muxes audio, so the target reaches the real segment command's audio re-encode.
     */
    public function testEnsureSegmentAbrRenditionThreadsLoudnormWhenEnabled(): void
    {
        $dir = $this->segmentDir . '/ln-abr';
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
            function (string $in, string $out, float $s, float $l, array $params) use (&$captParams): int {
                $captParams = $params;
                file_put_contents($out, 'encoded');
                return 1;
            }
        );

        $this->loudnormManager($db, $ff, $this->loudnormTarget())->ensureSegment('seg-job', '480p', 2);

        $this->assertNotNull($captParams);
        $this->assertSame($this->loudnormTarget(), $captParams['loudnorm'] ?? null);
        $this->assertArrayNotHasKey('video_only', $captParams, 'single-audio rung muxes audio');
        $cmd = (new ToneMapThreadingSpyRunner(null, false))
            ->buildSegmentCommand($input, "{$dir}/seg-v480p-00002.ts", 12.0, 6.0, $captParams);
        $this->assertStringContainsString('-af "loudnorm=I=-16:LRA=11:TP=-1.5"', $cmd);
    }

    /**
     * SITE 2 disabled: the ABR rendition carries no loudnorm key and its command is
     * byte-clean of `-af loudnorm`.
     */
    public function testEnsureSegmentAbrRenditionOmitsLoudnormWhenDisabled(): void
    {
        $dir = $this->segmentDir . '/ln-abr-off';
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
            function (string $in, string $out, float $s, float $l, array $params) use (&$captParams): int {
                $captParams = $params;
                file_put_contents($out, 'encoded');
                return 1;
            }
        );

        $this->loudnormManager($db, $ff, null)->ensureSegment('seg-job', '480p', 2);

        $this->assertNotNull($captParams);
        $this->assertArrayNotHasKey('loudnorm', $captParams);
        $cmd = (new ToneMapThreadingSpyRunner(null, false))
            ->buildSegmentCommand($input, "{$dir}/seg-v480p-00002.ts", 12.0, 6.0, $captParams);
        $this->assertStringNotContainsString('loudnorm', $cmd);
    }

    /**
     * SITE 3 (multi-audio audio-only): produceAudioSegment() builds a fresh
     * $segParams that never reads segment_params, so applyLoudnorm() there is the
     * ONLY way the target reaches a multi-audio job's normalized sound (its video
     * segments are `-an`). The real buildAudioSegmentCommand() emits `-af loudnorm`.
     */
    public function testProduceAudioSegmentThreadsLoudnormWhenEnabled(): void
    {
        $dir = $this->segmentDir . '/ln-audioonly';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->multiAudioJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $captParams = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $s, float $l, array $params) use (&$captParams): int {
                $captParams = $params;
                file_put_contents($out, 'audio');
                return 1;
            }
        );

        $path = $this->loudnormManager($db, $ff, $this->loudnormTarget())
            ->ensureSegment('seg-job', null, 1, 'a1');

        $this->assertSame("{$dir}/seg-a1-00001.ts", $path);
        $this->assertNotNull($captParams);
        $this->assertTrue($captParams['audio_only']);
        $this->assertSame($this->loudnormTarget(), $captParams['loudnorm'] ?? null);
        $cmd = $this->runner()->buildAudioSegmentCommand($input, "{$dir}/seg-a1-00001.ts", 6.0, 6.0, $captParams);
        $this->assertStringContainsString('-af "loudnorm=I=-16:LRA=11:TP=-1.5"', $cmd);
    }

    /**
     * SITE 3 disabled: the audio-only segment params carry no loudnorm key and the
     * command is byte-clean of `-af loudnorm`.
     */
    public function testProduceAudioSegmentOmitsLoudnormWhenDisabled(): void
    {
        $dir = $this->segmentDir . '/ln-audioonly-off';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $captured = [];
        $db = $this->mockDb([], 0, [], $this->multiAudioJobRow($dir, $input), $captured);
        $ff = $this->createMock(FfmpegRunner::class);
        $captParams = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function (string $in, string $out, float $s, float $l, array $params) use (&$captParams): int {
                $captParams = $params;
                file_put_contents($out, 'audio');
                return 1;
            }
        );

        $this->loudnormManager($db, $ff, null)->ensureSegment('seg-job', null, 1, 'a1');

        $this->assertNotNull($captParams);
        $this->assertArrayNotHasKey('loudnorm', $captParams);
        $cmd = $this->runner()->buildAudioSegmentCommand($input, "{$dir}/seg-a1-00001.ts", 6.0, 6.0, $captParams);
        $this->assertStringNotContainsString('loudnorm', $cmd);
    }

    /**
     * A plain real FfmpegRunner for asserting on produced audio-only commands
     * (buildAudioSegmentCommand never spawns a probe, so no spy is needed here).
     */
    private function runner(): FfmpegRunner
    {
        return new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
    }

    public function testEnsureHlsJobReuseKeyCarriesFormatVersion(): void
    {
        // The reuse key embeds a format version so every job persisted before the
        // multi-audio change (keyed sha1(media|profile)) can never be reused.
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

        $expectedKey = sha1('media-1|web|v9');
        $reuseKey = null;
        $insertKey = null;
        foreach ($captured as [$sql, $params]) {
            if (str_contains($sql, 'key_hash = ?')) {
                $reuseKey = $params[0] ?? null;
            }
            if (str_contains($sql, 'INSERT INTO transcode_jobs')) {
                $insertKey = $params[6] ?? null; // placeholder 6 = key_hash
            }
        }
        $this->assertSame($expectedKey, $reuseKey, 'reuse lookup must use the versioned key');
        $this->assertSame($expectedKey, $insertKey, 'job row must persist the versioned key');
        $this->assertNotSame(sha1('media-1|web'), $expectedKey, 'pre-version jobs can never match');
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
        $this->assertSame($expectedIds, array_map($this->variantId(...), $variants));
        // The copy original is the highest (first) entry.
        $this->assertSame('original', $variants[0]['id']);
        $this->assertTrue($variants[0]['is_copy']);
        // Every entry carries its own signed-later media playlist url.
        foreach ($variants as $entry) {
            $entryId = $this->variantId($entry);
            $this->assertSame("/hls/seg-job/media_v{$entryId}.m3u8", $entry['url']);
            // Flat Rendition shape preserved.
            $this->assertArrayHasKey('label', $entry);
            $this->assertArrayHasKey('height', $entry);
            $this->assertArrayHasKey('bitrate', $entry);
            $this->assertArrayHasKey('codecs', $entry);
        }
    }

    public function testGetJobVariantsIncludesNonCopyOriginal(): void
    {
        // HEVC + AC3 source → the "original" is NOT a copy, but it is now still a
        // DISTINCT playable variant (transcode at source resolution) and must be
        // listed first, ahead of the clamped rungs — the client contract is that
        // the variants list always contains {id: 'original', height: <source height>}.
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
        $ids = array_map($this->variantId(...), $variants);
        $this->assertContains('original', $ids, 'non-copy original must be listed too');
        // Membership + order equals streamVariants(): original first, then rungs.
        $expectedIds = array_map(static fn ($r): string => $r->id, $ladder->streamVariants());
        $this->assertSame($expectedIds, $ids);
        $this->assertSame('original', $variants[0]['id']);
        $this->assertFalse($variants[0]['is_copy']);
        // Source height clamped to web's 1080 cap (4K source).
        $this->assertSame(1080, $variants[0]['height']);
        foreach ($variants as $entry) {
            $entryId = $this->variantId($entry);
            $this->assertSame("/hls/seg-job/media_v{$entryId}.m3u8", $entry['url']);
        }
    }

    public function testGetJobVariantsIncludesOriginalThatDuplicatesTopRung(): void
    {
        // S49, fold site 2 (the array-level mirror): the persisted `original` of a
        // low-bitrate HEVC/AC-3 job is the SAME frame at the SAME BANDWIDTH as its
        // top rung. getJobVariants() used to drop it (originalDuplicatesTopRung()),
        // so the client's quality menu had no "Original" for exactly the titles
        // whose Original playlist is now written. It must be listed first.
        $ladder = (new AbrLadder())->build(
            new SourceProfile(1920, 1080, 'hevc', 1_200_000, 'ac3', 448_000),
            'web'
        );
        $this->assertFalse($ladder->original->isCopy);
        $this->assertSame(
            $ladder->renditions[0]->bandwidth(),
            $ladder->original->bandwidth(),
            'fixture must be the duplicate-BANDWIDTH case the array-level fold dropped'
        );
        $captured = [];
        $db = $this->mockDb([], 0, [], [
            'id' => 'seg-job',
            'variants' => (string) json_encode($ladder->toArray()),
        ], $captured);
        $ff = $this->createMock(FfmpegRunner::class);

        $variants = $this->manager($db, $ff)->getJobVariants('seg-job');

        $this->assertNotNull($variants);
        $this->assertSame('original', $variants[0]['id']);
        $this->assertFalse($variants[0]['is_copy']);
        $this->assertSame('/hls/seg-job/media_voriginal.m3u8', $variants[0]['url']);
        // Membership + order still equals streamVariants() exactly.
        $this->assertSame(
            array_map(static fn ($r): string => $r->id, $ladder->streamVariants()),
            array_map($this->variantId(...), $variants)
        );
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
     * @return array<string, array{at: int, gen: int}> final segment path →
     *         {at: monotonic launch ms, gen: generation token}
     */
    private function inFlightSet(TranscodeManager $m): array
    {
        $p = new \ReflectionProperty(TranscodeManager::class, 'segmentEncodesInFlight');
        $p->setAccessible(true);
        /** @var array<string, array{at: int, gen: int}> $value */
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

        // S120 — the callback RECORDS, the assertions run OUTSIDE it. It used to call
        // $this->assertArrayHasKey() directly, which the `catch (\RuntimeException)`
        // below swallowed, so this test stayed GREEN with `assertTrue(false)` planted.
        $final = "{$dir}/seg-00003.ts";
        $inFlightAtLaunch = null;
        $ff->expects($this->once())->method('startSegmentEncode')->willReturnCallback(
            function () use ($manager, &$inFlightAtLaunch): int {
                $inFlightAtLaunch = $this->inFlightSet($manager);
                throw new \RuntimeException('ffmpeg arg builder blew up');
            }
        );

        $thrown = null;
        try {
            $manager->ensureSegment('seg-job', null, 3);
        } catch (AssertionFailedError $e) {
            // S120 — see the twin arm in
            // testSegmentWaiterCountReturnsToZeroWhenPollBodyThrows(): an
            // ExpectationFailedException IS a RuntimeException, so this arm is what
            // stops the catch below from silently eating a future in-callback
            // assertion.
            throw $e;
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        // Identify by MESSAGE: the old assertNotNull() was satisfied by a swallowed
        // assertion failure, i.e. by the very thing it was written to rule out.
        $this->assertInstanceOf(
            \RuntimeException::class,
            $thrown,
            'the RuntimeException must propagate, not be swallowed'
        );
        $this->assertSame(
            'ffmpeg arg builder blew up',
            $thrown->getMessage(),
            'the launcher\'s own exception propagated — not some other RuntimeException'
        );
        $this->assertIsArray($inFlightAtLaunch, 'the launch callback ran');
        $this->assertArrayHasKey($final, $inFlightAtLaunch, 'the in-flight slot is taken before the launch call');
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
        $this->setPrivate($manager, 'segmentEncodesInFlight', [
            $a => ['at' => 10, 'gen' => 1],
            $b => ['at' => 20, 'gen' => 2],
        ]);

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
            "{$this->segmentDir}/x/seg-00000.ts" => ['at' => $now, 'gen' => 1],
            "{$this->segmentDir}/y/seg-00000.ts" => ['at' => $now, 'gen' => 2],
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
            $live => ['at' => $now, 'gen' => 1],
            $done => ['at' => $now, 'gen' => 2],
            $dead => ['at' => $now - $grace - 1000, 'gen' => 3], // past the grace window
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

    /**
     * A bare TranscodeManager for reflecting into `segmentParamsForRendition()`,
     * which became an instance method when the ABR rungs started reading the
     * effective `transcoding.*` encode settings. Constructed without the ctor so
     * no database is needed; the encode settings then default to the shipped
     * literals, which is what these assertions expect.
     */
    private static function bareTranscodeManagerForRendition(): TranscodeManager
    {
        $ref = new \ReflectionClass(TranscodeManager::class);
        $manager = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('encodeSettings');
        $prop->setAccessible(true);
        $prop->setValue($manager, new \Phlix\Media\Transcoding\EncodeSettings());

        return $manager;
    }

    /**
     * readFailureReason() must return valid UTF-8: its result is written into
     * `transcode_jobs.error` (TEXT on a utf8mb4 table), and MySQL rejects invalid
     * UTF-8 with error 1366 — so a byte-wise tail cut would make a merely-failed
     * job into one whose failure cannot be recorded at all.
     *
     * The log here ends with a non-ASCII filename followed by ASCII padding, so
     * the last 500 BYTES begin inside a multibyte character. The test asserts the
     * precondition (the byte-wise cut really is invalid) before asserting the
     * method's output is clean, so the fixture cannot decay into a non-case.
     */
    public function testReadFailureReasonReturnsValidUtf8WhenTheTailCutsMidCharacter(): void
    {
        // 467 bytes of padding puts the 500-byte boundary inside "ü" (C3 BC),
        // so the byte-wise tail would start on the dangling 0xBC.
        $log = "Error opening /media/Grüße aus Österreich/Grüße.mkv\n" . str_repeat('x', 467);
        $reason = $this->invokeReadFailureReason($log);

        $this->assertFalse(
            mb_check_encoding(substr(trim($log), -500), 'UTF-8'),
            'fixture must cut mid-character at 500 bytes for this test to mean anything',
        );
        $this->assertTrue(mb_check_encoding($reason, 'UTF-8'), 'reason must be valid UTF-8 or MySQL 1366s');
        $this->assertLessThanOrEqual(500, mb_strlen($reason, 'UTF-8'), 'reason stays within the 500-character bound');
        $this->assertStringContainsString('Grüße', $reason, 'the readable tail is preserved, not mangled');
    }

    /**
     * Sliding the 500-wide window across the log proves the guard is load-bearing
     * rather than incidentally passing on one lucky offset: the byte-wise cut is
     * invalid at several offsets, the shipped implementation at none.
     */
    public function testReadFailureReasonIsValidAtEveryTailOffset(): void
    {
        $byteWiseFailures = 0;
        $shippedFailures = 0;

        for ($pad = 0; $pad < 60; $pad++) {
            $log = "Error opening /media/Grüße aus Österreich/Grüße.mkv\n" . str_repeat('x', 440 + $pad);
            if (!mb_check_encoding(substr(trim($log), -500), 'UTF-8')) {
                $byteWiseFailures++;
            }
            if (!mb_check_encoding($this->invokeReadFailureReason($log), 'UTF-8')) {
                $shippedFailures++;
            }
        }

        $this->assertGreaterThan(
            0,
            $byteWiseFailures,
            'the byte-wise tail this replaced must break on at least one offset, else nothing is being guarded',
        );
        $this->assertSame(0, $shippedFailures, 'the shipped tail must be valid UTF-8 at every offset');
    }

    /**
     * The log is a file on disk with no encoding guarantee, so a genuinely
     * non-UTF-8 byte (here Windows-1252 0x9C) must also be scrubbed rather than
     * passed through to the utf8mb4 column.
     */
    public function testReadFailureReasonScrubsNonUtf8LogBytes(): void
    {
        $reason = $this->invokeReadFailureReason("Error: bad file \x9C name.mkv");

        $this->assertTrue(mb_check_encoding($reason, 'UTF-8'), 'stray Windows-1252 byte must not survive');
        $this->assertStringContainsString('bad file', $reason);
    }

    /** A log-less job directory still yields the generic reason. */
    public function testReadFailureReasonFallsBackWhenNoLogExists(): void
    {
        $ref = new \ReflectionClass(TranscodeManager::class);
        $method = $ref->getMethod('readFailureReason');
        $method->setAccessible(true);

        $this->assertSame(
            'Transcode failed',
            $method->invoke($ref->newInstanceWithoutConstructor(), $this->segmentDir . '/no-such-job'),
        );
    }

    /**
     * Write $content as a job's ffmpeg.log and return readFailureReason()'s value.
     * The method only reads the file, so a ctor-less instance suffices.
     */
    private function invokeReadFailureReason(string $content): string
    {
        $dir = $this->segmentDir . '/job-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/ffmpeg.log', $content);

        $ref = new \ReflectionClass(TranscodeManager::class);
        $method = $ref->getMethod('readFailureReason');
        $method->setAccessible(true);

        $result = $method->invoke($ref->newInstanceWithoutConstructor(), $dir);

        return is_string($result) ? $result : '';
    }
}
