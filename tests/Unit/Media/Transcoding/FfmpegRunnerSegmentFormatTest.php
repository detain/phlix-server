<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;

/**
 * S56 — the segment container branch in all three segment command builders, and
 * the CMAF publish chain.
 *
 * ## The A/B, and why it can fail
 *
 * The step's second acceptance criterion is that with the flag OFF the existing
 * `.ts` behaviour is *provably* unchanged. A test that merely runs the flag-off
 * path and passes would prove nothing — it would pass just as well if the fMP4
 * code had never been written.
 *
 * So the MPEG-TS expectations below are not derived from the current
 * implementation. Every one of them is a LITERAL captured by executing the
 * builders out of a `git worktree` of `origin/master` (i.e. pre-S56 code, with
 * `vendor/` hardlinked and `src/` a genuine separate copy) and pasting the
 * output here. Any edit to the MPEG-TS branch — a reordered flag, a dropped
 * `-output_ts_offset`, a changed `-f mpegts`, a different publish chain —
 * changes one of these strings and reds this file. And every fMP4 case is
 * paired with the corresponding MPEG-TS case over the SAME params, so a branch
 * that silently applied to both would fail one side of the pair.
 */
final class FfmpegRunnerSegmentFormatTest extends TestCase
{
    /**
     * Captured from `origin/master` @ 969f68a1. See the class docblock.
     */
    private const MASTER_VIDEO = "'/usr/bin/ffmpeg' -nostdin -y -hide_banner -loglevel error -ss 252"
        . " -i '/media/in.mkv' -t 6 -map 0:v:0 -map 0:a:0? -dn -sn -c:v libx264 -preset veryfast -crf 23"
        . ' -maxrate 3000000 -bufsize 6000000'
        . ' -vf "scale=1280:720:force_original_aspect_ratio=decrease:force_divisible_by=2"'
        . " -force_key_frames 'expr:eq(n,0)' -pix_fmt yuv420p -profile:v high -level 4.1"
        . ' -c:a aac -b:a 128k -ac 2 -muxdelay 0 -muxpreload 0 -output_ts_offset 252'
        . " -f mpegts '/tmp/segs/job1/seg-v720p-00042.ts'";

    private const MASTER_VIDEO_COPY = "'/usr/bin/ffmpeg' -nostdin -y -hide_banner -loglevel error -ss 0"
        . " -i '/media/in.mkv' -t 6 -map 0:v:0 -map 0:a:0? -dn -sn -c:v copy -c:a copy"
        . ' -muxdelay 0 -muxpreload 0 -output_ts_offset 0'
        . " -f mpegts '/tmp/segs/job1/seg-v720p-00042.ts'";

    private const MASTER_AUDIO = "'/usr/bin/ffmpeg' -nostdin -y -hide_banner -loglevel error -ss 252"
        . " -i '/media/in.mkv' -t 6 -vn -dn -sn -map 0:a:1 -c:a aac -b:a 128k -ac 2"
        . ' -muxdelay 0 -muxpreload 0 -output_ts_offset 252'
        . " -f mpegts '/tmp/segs/job1/seg-a1-00042.ts'";

    private const MASTER_DETACHED = "nohup setsid timeout -k 10 -s TERM 900 sh -c 'FFMPEG && mv -f"
        . " '\\''/tmp/segs/job1/seg-v720p-00042.ts.part-deadbeef'\\''"
        . " '\\''/tmp/segs/job1/seg-v720p-00042.ts'\\''"
        . " || rm -f '\\''/tmp/segs/job1/seg-v720p-00042.ts.part-deadbeef'\\'''"
        . " >> '/tmp/segs/job1/ffmpeg-segments.log' 2>&1 & echo \$!";

    private const MASTER_DETACHED_NO_TIMEOUT = "nohup setsid sh -c 'FFMPEG && mv -f"
        . " '\\''/tmp/segs/job1/seg-v720p-00042.ts.part-deadbeef'\\''"
        . " '\\''/tmp/segs/job1/seg-v720p-00042.ts'\\''"
        . " || rm -f '\\''/tmp/segs/job1/seg-v720p-00042.ts.part-deadbeef'\\'''"
        . " >> '/tmp/segs/job1/ffmpeg-segments.log' 2>&1 & echo \$!";

    private const OUT_TS = '/tmp/segs/job1/seg-v720p-00042.ts';
    private const OUT_M4S = '/tmp/segs/job1/seg-v720p-00042.m4s';

    protected function tearDown(): void
    {
        HwaccelRegistry::reset();
    }

    private function runner(): FfmpegRunner
    {
        return new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp/segs');
    }

    /**
     * A 5.1(side) AC-3 source shape, so `browserSafeAudioChannels()` has
     * something to clamp on both branches.
     *
     * @return array<string, mixed>
     */
    private function videoParams(): array
    {
        return [
            'video_codec' => 'libx264',
            'preset' => 'veryfast',
            'crf' => 23,
            'maxrate' => 3000000,
            'bufsize' => 6000000,
            'width' => 1280,
            'height' => 720,
            'pix_fmt' => 'yuv420p',
            'profile' => 'high',
            'level' => '4.1',
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
            'audio_channels' => 6,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function audioParams(): array
    {
        return [
            'audio_only' => true,
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
            'audio_stream_index' => 1,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // AC2 — flag off is byte-identical to origin/master
    // ─────────────────────────────────────────────────────────────────

    public function test_flag_off_video_command_is_byte_identical_to_master(): void
    {
        $cmd = $this->runner()->buildSegmentCommand('/media/in.mkv', self::OUT_TS, 252.0, 6.0, $this->videoParams());

        $this->assertSame(self::MASTER_VIDEO, $cmd);
    }

    public function test_flag_off_stream_copy_command_is_byte_identical_to_master(): void
    {
        $cmd = $this->runner()->buildSegmentCommand('/media/in.mkv', self::OUT_TS, 0.0, 6.0, [
            'video_codec' => 'copy',
            'audio_codec' => 'copy',
        ]);

        $this->assertSame(self::MASTER_VIDEO_COPY, $cmd);
    }

    public function test_flag_off_audio_only_command_is_byte_identical_to_master(): void
    {
        $cmd = $this->runner()->buildAudioSegmentCommand(
            '/media/in.mkv',
            '/tmp/segs/job1/seg-a1-00042.ts',
            252.0,
            6.0,
            $this->audioParams()
        );

        $this->assertSame(self::MASTER_AUDIO, $cmd);
    }

    /**
     * The publish chain is separately pinned, because "flag off is unchanged"
     * has to be a claim about the whole detached shell command and not only
     * about the ffmpeg arguments inside it.
     */
    public function test_flag_off_detached_publish_chain_is_byte_identical_to_master(): void
    {
        $runner = $this->runner();

        $this->assertSame(
            self::MASTER_DETACHED,
            $runner->buildDetachedSegmentCommand('FFMPEG', self::OUT_TS . '.part-deadbeef', self::OUT_TS, 900)
        );
        $this->assertSame(
            self::MASTER_DETACHED_NO_TIMEOUT,
            $runner->buildDetachedSegmentCommand('FFMPEG', self::OUT_TS . '.part-deadbeef', self::OUT_TS)
        );
    }

    /**
     * An explicit `segment_format => mpegts` must reach the same string as
     * omitting the key entirely — otherwise a job stamped with the shipped
     * value would diverge from a pre-S56 job.
     */
    public function test_an_explicit_mpegts_value_is_the_same_as_no_value_at_all(): void
    {
        $runner = $this->runner();

        $this->assertSame(
            self::MASTER_VIDEO,
            $runner->buildSegmentCommand(
                '/media/in.mkv',
                self::OUT_TS,
                252.0,
                6.0,
                $this->videoParams() + ['segment_format' => 'mpegts']
            )
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // AC1 — the fMP4 branch
    // ─────────────────────────────────────────────────────────────────

    public function test_fmp4_video_command_selects_the_cmaf_muxer(): void
    {
        $cmd = $this->runner()->buildSegmentCommand(
            '/media/in.mkv',
            self::OUT_M4S,
            252.0,
            6.0,
            $this->videoParams() + ['segment_format' => 'fmp4']
        );

        $this->assertStringContainsString(' -f hls', $cmd);
        $this->assertStringContainsString(' -hls_segment_type fmp4', $cmd);
        $this->assertStringContainsString(' -start_number 0', $cmd);
        $this->assertStringContainsString(" -hls_fmp4_init_filename 'seg-v720p-00042.m4s.i'", $cmd);
        $this->assertStringContainsString(" -hls_segment_filename '/tmp/segs/job1/seg-v720p-00042.m4s.s%d'", $cmd);
        $this->assertStringContainsString("'/tmp/segs/job1/seg-v720p-00042.m4s.m3u8'", $cmd);
    }

    /**
     * The other half of the pair: the MPEG-TS muxer must be GONE, not merely
     * accompanied. Two separate encoders in one command is an ffmpeg error, and
     * a branch that only appended would still satisfy the case above.
     */
    public function test_fmp4_video_command_drops_the_mpegts_muxer(): void
    {
        $cmd = $this->runner()->buildSegmentCommand(
            '/media/in.mkv',
            self::OUT_M4S,
            252.0,
            6.0,
            $this->videoParams() + ['segment_format' => 'fmp4']
        );

        $this->assertStringNotContainsString('-f mpegts', $cmd);
        $this->assertStringNotContainsString("'/tmp/segs/job1/seg-v720p-00042.m4s'", $cmd);
    }

    /**
     * `-output_ts_offset` is deliberately absent on the CMAF branch — measured,
     * not assumed. With it, ffmpeg 6.1 writes the offset into the `moov`'s
     * `elst` as an empty edit, i.e. into the INIT segment, which is shared by
     * every segment of the variant; producing the init from segment 42 would
     * shift segments 0..41. The offset is instead written into the fragment's
     * `tfdt` afterwards by `Fmp4SegmentRebaser`.
     *
     * The paired assertion on the MPEG-TS command is what makes this a claim
     * about the branch rather than about the flag being read at all.
     */
    public function test_output_ts_offset_is_emitted_for_mpegts_and_withheld_for_fmp4(): void
    {
        $runner = $this->runner();
        $params = $this->videoParams();

        $mpegts = $runner->buildSegmentCommand('/media/in.mkv', self::OUT_TS, 252.0, 6.0, $params);
        $fmp4 = $runner->buildSegmentCommand(
            '/media/in.mkv',
            self::OUT_M4S,
            252.0,
            6.0,
            $params + ['segment_format' => 'fmp4']
        );

        $this->assertStringContainsString(' -output_ts_offset 252', $mpegts);
        $this->assertStringNotContainsString('-output_ts_offset', $fmp4);
    }

    /**
     * The three stacked browser-safety layers survive the container change.
     * Each has its own prior incident behind it: an un-flagged software encode
     * produced 10-bit H.264 no browser decodes, a 5.1(side) AC-3 source yielded
     * `channel_configuration=0` AAC hls.js refuses to parse, and an odd scaled
     * dimension makes libx264 fail outright.
     */
    public function test_the_browser_safety_flags_are_re_asserted_on_the_fmp4_branch(): void
    {
        $cmd = $this->runner()->buildSegmentCommand(
            '/media/in.mkv',
            self::OUT_M4S,
            252.0,
            6.0,
            $this->videoParams() + ['segment_format' => 'fmp4']
        );

        $this->assertStringContainsString('-pix_fmt yuv420p', $cmd);
        $this->assertStringContainsString('-profile:v high', $cmd);
        $this->assertStringContainsString(' -ac 2', $cmd);
        $this->assertStringContainsString('force_divisible_by=2', $cmd);
        $this->assertStringContainsString("-force_key_frames 'expr:eq(n,0)'", $cmd);
    }

    /**
     * The HLS muxer has no "never split" switch, so the CMAF branch says it with
     * an `-hls_time` far above any segment length. If that ever shrank below the
     * encode window, the muxer would cut it into several fragments, the publish
     * chain would pick up only the first (`…s0`), and every segment would
     * silently become a fraction of its advertised `#EXTINF` — a stall at every
     * boundary, with the box structure and the start time still looking perfect.
     *
     * ## Why this case exists rather than the integration test alone
     *
     * Lowering the constant to 1 SURVIVED the whole suite, including the
     * real-ffmpeg fragment-length assertion. The reason is not that the test was
     * weak: with the encode flags this builder emits, the muxer physically
     * CANNOT split. It only cuts at keyframes, and `-force_key_frames
     * expr:eq(n,0)` plus libx264's default keyint (250 frames ≈ 10 s at 24 fps)
     * means a 6 s window contains exactly one IDR, at frame 0. Measured:
     * `-hls_time 1` over that window produced one segment (`x.s0`) and
     * `ffprobe -show_entries frame=key_frame` counted 0 further keyframes.
     * Positive control, so this is a real finding and not a broken measurement:
     * the SAME `-hls_time 1` with `-g 24 -keyint_min 24 -sc_threshold 0`
     * produced six segments.
     *
     * So the mutation is a no-op only for TODAY's GOP settings. The moment any
     * rung sets a GOP shorter than a segment, the constant becomes load-bearing
     * again — and nothing else in the suite would notice. This asserts the
     * invariant directly instead.
     */
    public function test_the_cmaf_muxer_is_told_never_to_split_the_encode_window(): void
    {
        $cmd = $this->runner()->buildSegmentCommand(
            '/media/in.mkv',
            self::OUT_M4S,
            252.0,
            6.0,
            $this->videoParams() + ['segment_format' => 'fmp4']
        );

        $this->assertSame(1, preg_match('/ -hls_time (\d+)/', $cmd, $m), 'control: an -hls_time is emitted at all');
        $this->assertGreaterThanOrEqual(
            3600,
            (int) $m[1],
            '-hls_time must exceed any segment length this server will ever ask for, so the muxer '
            . 'emits exactly one fragment per encode'
        );
    }

    public function test_fmp4_audio_only_command_selects_the_cmaf_muxer(): void
    {
        $cmd = $this->runner()->buildAudioSegmentCommand(
            '/media/in.mkv',
            '/tmp/segs/job1/seg-a1-00042.m4s',
            252.0,
            6.0,
            $this->audioParams() + ['segment_format' => 'fmp4']
        );

        $this->assertStringContainsString(' -hls_segment_type fmp4', $cmd);
        $this->assertStringContainsString(" -hls_fmp4_init_filename 'seg-a1-00042.m4s.i'", $cmd);
        $this->assertStringNotContainsString('-f mpegts', $cmd);
        // The audio rendition must still be a real `-vn` AAC encode at the
        // browser-safe channel count.
        $this->assertStringContainsString(' -vn -dn -sn', $cmd);
        $this->assertStringContainsString(' -ac 2', $cmd);
    }

    /**
     * The hwaccel builder is the third `-f mpegts` site and the one most often
     * forgotten — it is why `v4` had to be bumped once already.
     */
    // ─────────────────────────────────────────────────────────────────
    // S60 — the flip changes the MUXER and nothing else
    // ─────────────────────────────────────────────────────────────────

    /**
     * ⚠ S60 AC: "subtitle-burn-in behaviour is verified not regressed".
     *
     * The structural claim, isolated: the container is decided in
     * {@see FfmpegRunner::muxerTail()}, which runs AFTER the filtergraph is
     * assembled, so the `-vf` chain — tone-map, `subtitles=` burn-in, scale, in
     * that order — must come out BYTE-IDENTICAL in both containers. Only the tail
     * may differ.
     *
     * Asserted as an equality between the two extracted chains rather than as a
     * `assertStringContainsString('subtitles=')` on the fMP4 one: "the fMP4
     * command mentions subtitles" would still pass if the filter were emitted
     * differently there (a different escape, a different position relative to
     * `scale`, a dropped tone-map). Position matters — libass composites onto the
     * pre-scale frame — so an equality is the only assertion that catches a
     * reorder.
     *
     * The real-pixel half of this claim lives in
     * `Fmp4SegmentProductionTest::test_subtitle_burn_in_still_reaches_the_picture_in_the_fmp4_container()`,
     * which runs ffmpeg and compares decoded frames.
     */
    public function test_the_subtitle_burn_in_filter_chain_is_byte_identical_in_both_containers(): void
    {
        $vtt = sys_get_temp_dir() . '/phlix_s60_burnin_' . bin2hex(random_bytes(4)) . '.vtt';
        file_put_contents($vtt, "WEBVTT\n\n00:00:00.000 --> 00:00:06.000\nhello\n");

        try {
            $params = $this->videoParams() + [
                'require_hdr_tone_map' => true,
                'tone_map_filter' => 'zscale=t=linear:npl=100,tonemap=hable',
                'subtitle_burn_in' => ['path' => $vtt, 'format' => 'vtt'],
            ];

            $ts = $this->runner()->buildSegmentCommand('/media/in.mkv', self::OUT_TS, 252.0, 6.0, $params);
            $m4s = $this->runner()->buildSegmentCommand(
                '/media/in.mkv',
                self::OUT_M4S,
                252.0,
                6.0,
                ['segment_format' => 'fmp4'] + $params
            );

            $tsChain = $this->videoFilterChain($ts);
            $m4sChain = $this->videoFilterChain($m4s);

            // Denominator: the chain really contains all three stages, so an
            // equality between two EMPTY chains cannot read as a pass.
            $this->assertStringContainsString('tonemap=hable', $tsChain);
            $this->assertStringContainsString('subtitles=', $tsChain);
            $this->assertStringContainsString('scale=1280:720', $tsChain);
            $this->assertSame(
                ['zscale', 'tonemap', 'subtitles', 'scale'],
                array_map(
                    static fn (string $f): string => explode('=', $f, 2)[0],
                    explode(',', $tsChain)
                ),
                'the burn-in must sit AFTER the tone-map and BEFORE the scale'
            );

            $this->assertSame(
                $tsChain,
                $m4sChain,
                'the segment container changed the video filter chain. It must not: the container is '
                . 'chosen by muxerTail() after the graph is built, and burn-in is a -vf filter.'
            );

            // …and the commands are NOT simply identical: the tails differ, which
            // is what makes the equality above a statement about the filtergraph
            // rather than about the flag being inert.
            $this->assertNotSame($ts, $m4s);
            $this->assertStringContainsString('-f mpegts', $ts);
            $this->assertStringNotContainsString('-f mpegts', $m4s);
        } finally {
            @unlink($vtt);
        }
    }

    /**
     * ⚠ S60 AC: "multi-audio behaviour is verified not regressed".
     *
     * P3B multi-audio splits one job across TWO builders — `-an` video-only
     * segments from {@see FfmpegRunner::buildSegmentCommand()} and `-vn`
     * audio-only ones from {@see FfmpegRunner::buildAudioSegmentCommand()} — and
     * `TranscodeManager::produceAudioSegment()` builds its `$segParams` FRESH,
     * never reading the persisted array. That is the exact shape of the SV-3.3
     * landmine: wire the container into one builder and a flagged job writes
     * `.m4s` video beside `.ts` audio in one directory, which no playlist
     * assertion and no per-builder test would catch.
     *
     * So both arms are driven here over the same job, in the same container.
     * The real-ffmpeg, real-controller half is
     * {@see \Phlix\Tests\Integration\Media\Transcoding\HlsFmp4OnDemandServeTest},
     * which produces and serves `seg-a{N}-NNNNN.m4s` for a two-audio-track source.
     */
    public function test_both_multi_audio_arms_select_the_same_container(): void
    {
        $videoOnly = ['segment_format' => 'fmp4', 'video_only' => true] + $this->videoParams();
        $audioOnly = ['segment_format' => 'fmp4'] + $this->audioParams();

        $video = $this->runner()->buildSegmentCommand('/media/in.mkv', self::OUT_M4S, 252.0, 6.0, $videoOnly);
        $audio = $this->runner()->buildAudioSegmentCommand(
            '/media/in.mkv',
            '/tmp/segs/job1/seg-a1-00042.m4s',
            252.0,
            6.0,
            $audioOnly
        );

        foreach (['video' => $video, 'audio' => $audio] as $which => $cmd) {
            $this->assertStringContainsString('-hls_segment_type fmp4', $cmd, $which);
            $this->assertStringNotContainsString('-f mpegts', $cmd, $which);
        }

        // The multi-audio shape itself is intact in the fMP4 branch: the video
        // segment carries no audio, the audio segment carries no video and maps
        // the AUDIO-RELATIVE stream the rendition id names.
        $this->assertStringContainsString(' -an', $video);
        $this->assertStringContainsString(' -vn', $audio);
        $this->assertStringContainsString('-map 0:a:1', $audio);

        // The control: with the key absent, BOTH arms fall back to MPEG-TS
        // together. A container wired into one builder only would pass the loop
        // above and fail here (or vice versa).
        $videoTs = $this->runner()->buildSegmentCommand(
            '/media/in.mkv',
            self::OUT_TS,
            252.0,
            6.0,
            ['video_only' => true] + $this->videoParams()
        );
        $audioTs = $this->runner()->buildAudioSegmentCommand(
            '/media/in.mkv',
            '/tmp/segs/job1/seg-a1-00042.ts',
            252.0,
            6.0,
            $this->audioParams()
        );
        foreach (['video' => $videoTs, 'audio' => $audioTs] as $which => $cmd) {
            $this->assertStringContainsString('-f mpegts', $cmd, $which);
            $this->assertStringNotContainsString('-hls_segment_type fmp4', $cmd, $which);
        }
    }

    /**
     * The `-vf "…"` chain of a built command, or `''` when there is none.
     */
    private function videoFilterChain(string $cmd): string
    {
        return preg_match('/ -vf "([^"]*)"/', $cmd, $m) === 1 ? $m[1] : '';
    }

    public function test_the_hwaccel_builder_honours_both_containers(): void
    {
        $this->seedNvenc();
        $runner = $this->runner();
        $runner->setConfig(['enabled' => true, 'prefer_hardware' => true]);
        $runner->probeHardwareAcceleration(HwaccelRegistry::getInstance());

        $mpegts = $runner->buildHwaccelSegmentCommand(
            '/media/in.mkv',
            self::OUT_TS,
            252.0,
            6.0,
            $this->videoParams()
        );
        $fmp4 = $runner->buildHwaccelSegmentCommand(
            '/media/in.mkv',
            self::OUT_M4S,
            252.0,
            6.0,
            $this->videoParams() + ['segment_format' => 'fmp4']
        );

        $this->assertIsString($mpegts);
        $this->assertIsString($fmp4);
        $this->assertStringContainsString(' -f mpegts ', $mpegts);
        $this->assertStringContainsString(' -output_ts_offset 252', $mpegts);
        $this->assertStringContainsString(' -hls_segment_type fmp4', $fmp4);
        $this->assertStringNotContainsString('-f mpegts', $fmp4);
        $this->assertStringNotContainsString('-output_ts_offset', $fmp4);
        $this->assertStringContainsString(' -ac 2', $fmp4);
    }

    // ─────────────────────────────────────────────────────────────────
    // the CMAF publish chain
    // ─────────────────────────────────────────────────────────────────

    public function test_the_cmaf_publish_chain_marks_the_encode_in_flight_before_ffmpeg_runs(): void
    {
        $cmd = $this->cmafChain();

        // The `.part-<hex>` marker is what the global cap and the cross-worker
        // dedup glob for; on this branch ffmpeg writes `<tmp>.s0`, never
        // `<tmp>`, so without the touch the seek-cascade backpressure is inert.
        $touchPos = strpos($cmd, "touch '\\''/tmp/segs/job1/seg-v720p-00042.m4s.part-deadbeef'\\''");
        $encodePos = strpos($cmd, 'FFMPEG');

        $this->assertIsInt($touchPos);
        $this->assertIsInt($encodePos);
        $this->assertLessThan($encodePos, $touchPos, 'the in-flight marker must exist before ffmpeg starts');
    }

    public function test_the_cmaf_publish_chain_rebases_before_it_publishes_anything(): void
    {
        $cmd = $this->cmafChain();

        $rebasePos = strpos($cmd, 'fmp4-rebase-segment.php');
        $initMvPos = strpos($cmd, "mv -f '\\''/tmp/segs/job1/seg-v720p-00042.m4s.part-deadbeef.i'\\''");
        $segMvPos = strpos($cmd, "mv -f '\\''/tmp/segs/job1/seg-v720p-00042.m4s.part-deadbeef.s0'\\''");

        $this->assertIsInt($rebasePos, 'the fragment rebaser must be part of the publish chain');
        $this->assertIsInt($initMvPos);
        $this->assertIsInt($segMvPos);
        // Rebase first: a published fragment may already be being streamed by a
        // sibling worker, so it must never be visible with the wrong tfdt.
        $this->assertLessThan($initMvPos, $rebasePos);
        // Init before media: gives "if the segment exists, its init exists",
        // which is what lets S57/S59 serve an EXT-X-MAP target with no extra
        // orchestration.
        $this->assertLessThan($segMvPos, $initMvPos);
    }

    public function test_the_cmaf_publish_chain_passes_the_segment_start_to_the_rebaser(): void
    {
        $cmd = $this->cmafChain();

        // The rebaser is what turns a `tfdt` of 0 into the segment's real
        // position, so the start offset reaching it is the whole point.
        $this->assertMatchesRegularExpression(
            '/fmp4-rebase-segment\.php.+\.s0.+\.i.+ 252(\s|$)/',
            $cmd
        );
    }

    public function test_the_cmaf_publish_chain_removes_every_auxiliary_temp(): void
    {
        $cmd = $this->cmafChain();
        $stem = '/tmp/segs/job1/seg-v720p-00042.m4s.part-deadbeef';

        $tail = substr($cmd, (int) strpos($cmd, ' ; rm -f '));
        foreach (['', '.i', '.s0', '.m3u8'] as $suffix) {
            $this->assertStringContainsString(
                "'\\''{$stem}{$suffix}'\\''",
                $tail,
                "the '{$suffix}' temp must be cleaned up"
            );
        }
        // `;` and not `||`: the auxiliary temps have to go on the SUCCESS path
        // too, where the `mv`s already consumed the two that get published.
        $this->assertStringContainsString(' ; rm -f ', $cmd);
    }

    /**
     * The chain still runs under the SV-4.2 `timeout`/`setsid` wrapper — the
     * only backstop for a genuinely stuck encode.
     */
    public function test_the_cmaf_publish_chain_keeps_the_timeout_and_setsid_wrapper(): void
    {
        $cmd = $this->cmafChain();

        $this->assertStringStartsWith('nohup setsid timeout -k 10 -s TERM 900 sh -c ', $cmd);
        $this->assertStringEndsWith(" >> '/tmp/segs/job1/ffmpeg-segments.log' 2>&1 & echo \$!", $cmd);
    }

    private function cmafChain(): string
    {
        return $this->runner()->buildDetachedSegmentCommand(
            'FFMPEG',
            self::OUT_M4S . '.part-deadbeef',
            self::OUT_M4S,
            900,
            '/tmp/segs/job1/init-v720p.m4s',
            252.0
        );
    }

    private function seedNvenc(): void
    {
        HwaccelRegistry::reset();
        $registry = HwaccelRegistry::getInstance();
        $ref = new \ReflectionObject($registry);

        $capabilities = $ref->getProperty('capabilities');
        $capabilities->setAccessible(true);
        $capabilities->setValue($registry, [
            'nvenc' => new HwaccelCapability(
                vendor: 'nvenc',
                encoder: 'h264_nvenc',
                decoder: 'h264_cuvid',
                supports_hdr_tone_mapping: true,
                supported_codecs: ['h264', 'hevc'],
                supported_profiles: ['baseline', 'main', 'high'],
                max_resolution_w: 3840,
                max_resolution_h: 2160,
                max_bitrate: 100000000,
            ),
        ]);

        $initialized = $ref->getProperty('initialized');
        $initialized->setAccessible(true);
        $initialized->setValue($registry, true);
    }
}
