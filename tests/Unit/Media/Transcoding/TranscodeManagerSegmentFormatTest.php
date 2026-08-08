<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Media\Transcoding\EncodeSettings;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\SegmentBusyException;
use Phlix\Media\Transcoding\TranscodeManager;
use Workerman\MySQL\Connection;

/**
 * S56 — the segment container as seen from `TranscodeManager::ensureSegment()`,
 * i.e. through the real production entry point rather than through reflection
 * on the private naming helpers.
 *
 * Everything here is a PAIR: the same job row, the same request, once without
 * `segment_format` in the persisted params and once with it. A change that
 * applied to both containers, or to neither, fails one side.
 *
 * Assertions are never made inside the `startSegmentEncode` mock callback. The
 * call sits inside `produceSegment()`'s `try`/`finally`, and this codebase has
 * been bitten before by an assertion that an upstream `catch (Throwable)` could
 * swallow; every callback here RECORDS into a variable seeded with a
 * distinguishing sentinel, and the assertions run after the call returns.
 */
final class TranscodeManagerSegmentFormatTest extends TestCase
{
    /** The pre-S56 in-flight glob, kept verbatim as the control for the new one. */
    private const PRE_S56_INFLIGHT_GLOB = 'seg-*.ts.part-*';

    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_s56_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->segmentDir);
    }

    // ─────────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $jobRow
     */
    private function mockDb(array $jobRow): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use ($jobRow): array {
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['c' => 0]];
                }
                if (str_contains($sql, 'transcode_jobs WHERE id = ?')) {
                    return [$jobRow];
                }
                return [];
            }
        );

        return $db;
    }

    /**
     * A single-variant on-demand job row. `$fmp4` stamps the container into the
     * persisted `segment_params` exactly as `computeSegmentParams()` does.
     *
     * @return array<string, mixed>
     */
    private function legacyJobRow(string $dir, string $input, bool $fmp4): array
    {
        $params = ['video_codec' => 'libx264', 'audio_codec' => 'aac'];
        if ($fmp4) {
            $params['segment_format'] = 'fmp4';
        }

        return [
            'id' => 'seg-job',
            'hls_dir' => $dir,
            'input_path' => $input,
            'status' => 'completed',
            'duration_seconds' => 60,
            'segment_seconds' => 6,
            'segment_params' => json_encode($params),
        ];
    }

    /**
     * A multi-variant (ABR) job row. `segmentParamsForRendition()` rebuilds the
     * params from `variants` and carries NOTHING from `segment_params`, which is
     * exactly why `applySegmentFormat()` has to merge the container back in.
     *
     * @return array<string, mixed>
     */
    private function abrJobRow(string $dir, string $input, bool $fmp4, bool $audioGroup = false): array
    {
        $row = $this->legacyJobRow($dir, $input, $fmp4);
        $variants = [
            'renditions' => [[
                'id' => '720p',
                'width' => 1280,
                'height' => 720,
                'bandwidth' => 3000000,
                'video_codec' => 'libx264',
                'audio_codec' => 'aac',
                'is_copy' => false,
            ]],
        ];
        if ($audioGroup) {
            $variants['audio_tracks'] = [
                ['index' => 0, 'language' => 'eng', 'name' => 'English'],
                ['index' => 1, 'language' => 'fra', 'name' => 'French'],
            ];
        }
        $row['variants'] = json_encode($variants);

        return $row;
    }

    private function manager(Connection $db, FfmpegRunner $ff, ?int $maxSegments = null): TranscodeManager
    {
        return new TranscodeManager(
            $db,
            $ff,
            $this->segmentDir,
            null,
            6,
            null,
            null,
            null,
            $maxSegments,
            null,
            null,
            50 // short wait: these tests never let an encode finish
        );
    }

    private function jobDir(string $name): string
    {
        $dir = "{$this->segmentDir}/{$name}";
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/in.mkv", 'x');

        return $dir;
    }

    /**
     * Records what `startSegmentEncode()` was handed, and materialises the
     * output so the poll loop finishes immediately.
     *
     * @param FfmpegRunner&MockObject       $ff
     * @param array{out: string, params: array<string, mixed>, start: float} $seen
     */
    private function recordEncode(FfmpegRunner $ff, array &$seen): void
    {
        $ff->method('startSegmentEncode')->willReturnCallback(
            static function (
                string $in,
                string $out,
                float $start,
                float $len,
                array $params
            ) use (&$seen): int {
                $seen = ['out' => $out, 'params' => $params, 'start' => $start];
                file_put_contents($out, 'encoded');
                return 4242;
            }
        );
    }

    /**
     * @return array{out: string, params: array<string, mixed>, start: float}
     */
    private function sentinel(): array
    {
        return ['out' => '<startSegmentEncode was never called>', 'params' => [], 'start' => -1.0];
    }

    // ─────────────────────────────────────────────────────────────────
    // naming — legacy single-variant job
    // ─────────────────────────────────────────────────────────────────

    public function test_flag_off_legacy_job_still_produces_a_ts_segment(): void
    {
        $dir = $this->jobDir('legacy-ts');
        $ff = $this->createMock(FfmpegRunner::class);
        $seen = $this->sentinel();
        $this->recordEncode($ff, $seen);

        $path = $this->manager($this->mockDb($this->legacyJobRow($dir, "{$dir}/in.mkv", false)), $ff)
            ->ensureSegment('seg-job', null, 2);

        $this->assertSame("{$dir}/seg-00002.ts", $path);
        $this->assertSame("{$dir}/seg-00002.ts", $seen['out']);
        $this->assertSame(12.0, $seen['start']);
        $this->assertArrayNotHasKey('segment_format', $seen['params']);
        $this->assertArrayNotHasKey('init_file', $seen['params']);
    }

    public function test_flag_on_legacy_job_produces_an_m4s_segment_and_an_init(): void
    {
        $dir = $this->jobDir('legacy-fmp4');
        $ff = $this->createMock(FfmpegRunner::class);
        $seen = $this->sentinel();
        $this->recordEncode($ff, $seen);

        $path = $this->manager($this->mockDb($this->legacyJobRow($dir, "{$dir}/in.mkv", true)), $ff)
            ->ensureSegment('seg-job', null, 2);

        $this->assertSame("{$dir}/seg-00002.m4s", $path);
        $this->assertSame("{$dir}/seg-00002.m4s", $seen['out']);
        $this->assertSame(12.0, $seen['start'], 'the timeline is untouched by the container');
        $this->assertSame('fmp4', $seen['params']['segment_format'] ?? null);
        $this->assertSame("{$dir}/init.m4s", $seen['params']['init_file'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────
    // naming — ABR job (the applySegmentFormat landmine)
    // ─────────────────────────────────────────────────────────────────

    public function test_flag_off_abr_rendition_still_produces_a_ts_segment(): void
    {
        $dir = $this->jobDir('abr-ts');
        $ff = $this->createMock(FfmpegRunner::class);
        $seen = $this->sentinel();
        $this->recordEncode($ff, $seen);

        $path = $this->manager($this->mockDb($this->abrJobRow($dir, "{$dir}/in.mkv", false)), $ff)
            ->ensureSegment('seg-job', '720p', 3);

        $this->assertSame("{$dir}/seg-v720p-00003.ts", $path);
        $this->assertArrayNotHasKey('init_file', $seen['params']);
    }

    /**
     * `segmentParamsForRendition()` rebuilds `$segParams` from the ABR ladder
     * and reads nothing from `segment_params`, so without the
     * `applySegmentFormat()` merge a flagged job would emit `.ts` here while its
     * legacy path emitted `.m4s`. This is the same landmine SV-1.1(b′), SV-1.6
     * and SV-3.3(1B) each hit on this exact method.
     */
    public function test_flag_on_abr_rendition_produces_a_per_variant_m4s_and_init(): void
    {
        $dir = $this->jobDir('abr-fmp4');
        $ff = $this->createMock(FfmpegRunner::class);
        $seen = $this->sentinel();
        $this->recordEncode($ff, $seen);

        $path = $this->manager($this->mockDb($this->abrJobRow($dir, "{$dir}/in.mkv", true)), $ff)
            ->ensureSegment('seg-job', '720p', 3);

        $this->assertSame("{$dir}/seg-v720p-00003.m4s", $path);
        $this->assertSame('fmp4', $seen['params']['segment_format'] ?? null);
        $this->assertSame(
            "{$dir}/init-v720p.m4s",
            $seen['params']['init_file'] ?? null,
            'the init is PER VARIANT — one shared init for the whole ladder would carry the wrong codec config'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // naming — audio-only rendition (P3B multi-audio)
    // ─────────────────────────────────────────────────────────────────

    public function test_flag_off_audio_only_rendition_still_produces_a_ts_segment(): void
    {
        $dir = $this->jobDir('aud-ts');
        $ff = $this->createMock(FfmpegRunner::class);
        $seen = $this->sentinel();
        $this->recordEncode($ff, $seen);

        $path = $this->manager($this->mockDb($this->abrJobRow($dir, "{$dir}/in.mkv", false, true)), $ff)
            ->ensureSegment('seg-job', null, 1, 'a1');

        $this->assertSame("{$dir}/seg-a1-00001.ts", $path);
        $this->assertArrayNotHasKey('init_file', $seen['params']);
    }

    /**
     * `produceAudioSegment()` builds `$segParams` from scratch and never reads
     * `segment_params` at all. A flagged job that missed this merge would write
     * `.m4s` video segments beside `.ts` audio segments — half a job.
     */
    public function test_flag_on_audio_only_rendition_produces_an_m4s_and_its_own_init(): void
    {
        $dir = $this->jobDir('aud-fmp4');
        $ff = $this->createMock(FfmpegRunner::class);
        $seen = $this->sentinel();
        $this->recordEncode($ff, $seen);

        $path = $this->manager($this->mockDb($this->abrJobRow($dir, "{$dir}/in.mkv", true, true)), $ff)
            ->ensureSegment('seg-job', null, 1, 'a1');

        $this->assertSame("{$dir}/seg-a1-00001.m4s", $path);
        $this->assertSame('fmp4', $seen['params']['segment_format'] ?? null);
        $this->assertSame("{$dir}/init-a1.m4s", $seen['params']['init_file'] ?? null);
        $this->assertTrue($seen['params']['audio_only'] ?? false, 'still a -vn audio-only encode');
    }

    // ─────────────────────────────────────────────────────────────────
    // the container is a property of the JOB, not of the live setting
    // ─────────────────────────────────────────────────────────────────

    /**
     * A job created before the flip keeps producing `.ts` even while the setting
     * says `fmp4`. Reading the live setting per segment would 404 every
     * subsequent segment for viewers already on that job; the flip is instead
     * absorbed by `EncodeSettings::fingerprint()` changing the job key, so the
     * NEXT job gets a fresh id and a fresh directory.
     *
     * The manager here is constructed with no `EncodeSettings` at all, so the
     * only possible source of the container is the row — which is the point.
     */
    public function test_the_container_comes_from_the_job_row_not_from_the_live_setting(): void
    {
        $dir = $this->jobDir('job-scoped');
        $ff = $this->createMock(FfmpegRunner::class);
        $seen = $this->sentinel();
        $this->recordEncode($ff, $seen);

        $path = $this->manager($this->mockDb($this->legacyJobRow($dir, "{$dir}/in.mkv", false)), $ff)
            ->ensureSegment('seg-job', null, 0);

        $this->assertSame("{$dir}/seg-00000.ts", $path);
    }

    /**
     * A corrupted / hand-edited `segment_format` must degrade to `.ts` rather
     * than invent a third naming scheme no playlist advertises.
     */
    public function test_an_unrecognised_persisted_container_degrades_to_ts(): void
    {
        $dir = $this->jobDir('junk-format');
        $row = $this->legacyJobRow($dir, "{$dir}/in.mkv", false);
        $row['segment_params'] = json_encode([
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'segment_format' => 'cmaf',
        ]);
        $ff = $this->createMock(FfmpegRunner::class);
        $seen = $this->sentinel();
        $this->recordEncode($ff, $seen);

        $path = $this->manager($this->mockDb($row), $ff)->ensureSegment('seg-job', null, 0);

        $this->assertSame("{$dir}/seg-00000.ts", $path);
        $this->assertArrayNotHasKey('init_file', $seen['params']);
    }

    // ─────────────────────────────────────────────────────────────────
    // job creation — where the container is STAMPED
    // ─────────────────────────────────────────────────────────────────

    /**
     * Everything above reads the container back out of a job row. This is the
     * other end: `computeSegmentParams()` is the single place that WRITES it,
     * and it only writes it when the setting says so.
     *
     * The flag-off case asserts the key is absent rather than merely `mpegts`,
     * because a job whose persisted `segment_params` JSON gained a key would no
     * longer be byte-identical to a pre-S56 job.
     */
    public function test_job_creation_stamps_the_container_only_when_the_setting_is_fmp4(): void
    {
        $offParams = $this->createdJobSegmentParams('mpegts');
        $onParams = $this->createdJobSegmentParams('fmp4');

        $this->assertSame('libx264', $offParams['video_codec'] ?? null, 'control: the INSERT was captured');
        $this->assertArrayNotHasKey('segment_format', $offParams);

        $this->assertSame('libx264', $onParams['video_codec'] ?? null);
        $this->assertSame('fmp4', $onParams['segment_format'] ?? null);
    }

    /**
     * Runs a real `ensureHlsJob()` with the given setting and returns the
     * `segment_params` JSON it persisted.
     *
     * @return array<string, mixed>
     */
    private function createdJobSegmentParams(string $format): array
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, ?array $params = null) use (&$captured): array {
                $captured[] = [$sql, $params ?? []];
                if (str_contains($sql, 'key_hash = ?') && str_contains($sql, 'IN (')) {
                    return [];
                }
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['c' => 0]];
                }
                if (str_contains($sql, 'FROM media_items')) {
                    return [['path' => '/m.mkv']];
                }
                return [];
            }
        );

        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720,
                    'pix_fmt' => 'yuv420p', 'profile' => 'High'],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '600.0'],
        ]);
        $ff->method('extractColorMetadata')->willReturn([
            'color_space' => 'bt709',
            'color_transfer' => 'bt709',
            'color_primaries' => 'bt709',
            'pix_fmt' => 'yuv420p',
            'bit_depth' => 8,
            'is_hdr' => false,
        ]);

        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static fn (string $key) => $key === EncodeSettings::SEGMENT_FORMAT_KEY ? $format : null
        );

        $manager = new TranscodeManager(
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
            50,
            null,
            null,
            null,
            new EncodeSettings($repo)
        );
        $manager->ensureHlsJob('media-1', 'web');

        foreach ($captured as [$sql, $params]) {
            if (!str_contains($sql, 'INSERT INTO transcode_jobs')) {
                continue;
            }
            // Placeholder 13 is segment_params — see TranscodeManagerTest::capturedJobInsert().
            $decoded = is_string($params[13] ?? null) ? json_decode($params[13], true) : null;
            $this->assertIsArray($decoded);

            return $decoded;
        }
        $this->fail('no transcode_jobs INSERT was captured');
    }

    // ─────────────────────────────────────────────────────────────────
    // progress reporting
    // ─────────────────────────────────────────────────────────────────

    /**
     * `countSegments()` is what feeds a job's reported progress. It globbed
     * `chunk-*.m4s` and `seg-*.ts` only, so on the CMAF branch every flagged
     * job would have reported ZERO produced segments forever.
     *
     * The `.part-` temp and the init are both in the fixture deliberately: a
     * half-written segment must not count, and the init is one shared header
     * per rendition rather than a media segment — counting it would overstate
     * progress by the size of the ladder.
     */
    public function test_produced_segments_are_counted_in_both_containers(): void
    {
        $dir = $this->jobDir('counted');
        foreach ([
            'seg-v720p-00000.m4s',
            'seg-v720p-00001.m4s',
            'seg-v1080p-00000.m4s',
            'seg-00000.ts',
            'chunk-0.m4s',
            'init-v720p.m4s',
            'seg-v720p-00002.m4s.part-deadbeef',
        ] as $name) {
            file_put_contents("{$dir}/{$name}", 'x');
        }

        $count = $this->countSegments($dir);

        $this->assertSame(5, $count, 'three .m4s + one .ts + one legacy chunk; not the init, not the temp');
    }

    private function countSegments(string $dir): int
    {
        $method = new \ReflectionMethod(TranscodeManager::class, 'countSegments');
        $manager = $this->manager($this->mockDb([]), $this->createMock(FfmpegRunner::class));
        $value = $method->invoke($manager, $dir);
        $this->assertIsInt($value);

        return $value;
    }

    // ─────────────────────────────────────────────────────────────────
    // the in-flight glob — the seek-cascade backpressure
    // ─────────────────────────────────────────────────────────────────

    /**
     * A/B on the glob itself. The pre-S56 pattern hardcoded `.ts`, so on the
     * CMAF branch the global cap would have counted ZERO encodes in flight and
     * the `SegmentBusyException`/503 backpressure that fixed the seek cascade
     * would have been silently inert for every flagged job.
     *
     * The denominators are printed as assertions so a pattern matching NOTHING
     * cannot read as a pass.
     */
    public function test_the_in_flight_glob_sees_fmp4_temps_that_the_pre_s56_pattern_missed(): void
    {
        $dir = $this->jobDir('glob-fmp4');
        file_put_contents("{$dir}/seg-v720p-00001.m4s.part-deadbeef", 'p');

        $old = glob("{$this->segmentDir}/*/" . self::PRE_S56_INFLIGHT_GLOB) ?: [];
        $new = glob("{$this->segmentDir}/*/" . $this->inFlightGlob()) ?: [];

        $this->assertCount(0, $old, 'control: the pre-S56 pattern is blind to an fMP4 temp');
        $this->assertCount(1, $new, 'the S56 pattern counts it');
    }

    /**
     * The other half: for an MPEG-TS temp the two patterns must select an
     * IDENTICAL set, or the cap's behaviour changed on the flag-off path.
     */
    public function test_the_in_flight_glob_selects_the_same_mpegts_temps_as_the_pre_s56_pattern(): void
    {
        $dir = $this->jobDir('glob-ts');
        file_put_contents("{$dir}/seg-00000.ts.part-aaaabbbb", 'p');
        file_put_contents("{$dir}/seg-v1080p-00007.ts.part-0123abcd", 'p');
        file_put_contents("{$dir}/seg-a0-00007.ts.part-99887766", 'p');
        // Published segments and the job's own files must be matched by neither.
        file_put_contents("{$dir}/seg-00001.ts", 'done');
        file_put_contents("{$dir}/master.m3u8", 'x');

        $old = glob("{$this->segmentDir}/*/" . self::PRE_S56_INFLIGHT_GLOB) ?: [];
        $new = glob("{$this->segmentDir}/*/" . $this->inFlightGlob()) ?: [];
        sort($old);
        sort($new);

        $this->assertCount(3, $old, 'control: the pre-S56 pattern finds all three temps');
        $this->assertSame($old, $new);
    }

    /**
     * The CMAF encode writes `<tmp>.i`, `<tmp>.s0` and `<tmp>.m3u8` beside its
     * marker. A looser `seg-*.part-*` would have matched all four and inflated
     * the in-flight count fourfold, throttling the fleet to a quarter of its
     * configured ceiling.
     */
    public function test_the_in_flight_glob_ignores_the_cmaf_auxiliary_temps(): void
    {
        $dir = $this->jobDir('glob-aux');
        $stem = "{$dir}/seg-v720p-00001.m4s.part-deadbeef";
        foreach (['', '.i', '.s0', '.m3u8'] as $suffix) {
            file_put_contents($stem . $suffix, 'p');
        }

        $loose = glob("{$this->segmentDir}/*/seg-*.part-*") ?: [];
        $new = glob("{$this->segmentDir}/*/" . $this->inFlightGlob()) ?: [];

        $this->assertCount(4, $loose, 'control: a loose pattern would count every auxiliary temp');
        $this->assertCount(1, $new);
        $this->assertSame($stem, $new[0]);
    }

    /**
     * End-to-end proof that the cap still fires on the CMAF branch: one live
     * fMP4 temp in ANOTHER job fills a ceiling of 1, so this request must
     * fast-fail with the retryable 503 rather than pile a second encode on.
     */
    public function test_the_global_cap_still_throws_segment_busy_on_the_fmp4_branch(): void
    {
        $dir = $this->jobDir('busy-fmp4');
        $other = $this->jobDir('busy-other');
        file_put_contents("{$other}/seg-v720p-00000.m4s.part-aaaabbbb", 'p');
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('startSegmentEncode');

        $this->expectException(SegmentBusyException::class);
        $this->manager($this->mockDb($this->legacyJobRow($dir, "{$dir}/in.mkv", true)), $ff, 1)
            ->ensureSegment('seg-job', null, 2);
    }

    /**
     * Reads the production constant rather than re-spelling the pattern, so
     * this file cannot drift into testing a private copy of it.
     */
    private function inFlightGlob(): string
    {
        $ref = new \ReflectionClass(TranscodeManager::class);
        $value = $ref->getConstant('INFLIGHT_TEMP_GLOB');
        $this->assertIsString($value);

        return $value;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "{$dir}/{$entry}";
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
