<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Workerman\MySQL\Connection;

/**
 * S56 acceptance — real ffmpeg, real bytes, driven through the real production
 * entry point (`TranscodeManager::ensureSegment()`), not through a command-string
 * assertion.
 *
 * AC1 is "fMP4 segments are produced CORRECTLY for a representative set of
 * sources", so what is asserted here is parsed box structure and a demuxer's
 * reading of the timeline:
 *
 *  - the init segment is `ftyp` + `moov` and carries NO fragment;
 *  - the media segment is `styp`/`moof` + `mdat` and carries NO `ftyp`/`moov`
 *    (a `moov` in a media segment is what makes a DASH
 *    `SegmentTemplate@initialization` setup non-conformant, which is the whole
 *    prize of the S56–S60 chain);
 *  - `ffprobe` REFUSES the bare media segment (no `moov`) but reads
 *    `init ++ segment` as a normal file with the expected codecs;
 *  - and that concatenation starts at the segment's position ON THE VOD
 *    TIMELINE, not at zero — the property `-output_ts_offset` silently fails to
 *    deliver for fMP4 and `Fmp4SegmentRebaser` exists to restore.
 *
 * Both codec paths the AC names are covered: H.264/AAC and HEVC/AC-3 5.1.
 *
 * AC2's control lives here too: the same job, the same request, the flag off,
 * must still produce a real MPEG-TS segment.
 *
 * The box walk below is written independently of `Fmp4SegmentRebaser` on
 * purpose — a check derived from its own subject self-adjusts.
 */
final class Fmp4SegmentProductionTest extends TestCase
{
    private const FFMPEG = '/usr/bin/ffmpeg';
    private const FFPROBE = '/usr/bin/ffprobe';

    private string $root;

    protected function setUp(): void
    {
        if (!is_executable(self::FFMPEG) || !is_executable(self::FFPROBE)) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
        $this->root = sys_get_temp_dir() . '/phlix_s56_it_' . uniqid();
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $this->rrmdir($this->root);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // AC1 — H.264 / AAC
    // ─────────────────────────────────────────────────────────────────

    public function testH264AacSourceProducesAConformantCmafFragmentAndInit(): void
    {
        $clip = $this->makeH264AacClip();

        $segment = $this->produce($clip, 'fmp4', '720p', 1);
        $init = dirname($segment) . '/init-v720p.m4s';

        $this->assertStringEndsWith('/seg-v720p-00001.m4s', $segment);
        $this->assertFileExists($init);

        $this->assertInitShape($init);
        $this->assertMediaSegmentShape($segment);
        $this->assertBareSegmentIsNotSelfContained($segment);

        $streams = $this->probeConcat($init, $segment);
        $this->assertSame('h264', $streams[0]['codec_name'] ?? null);
        $this->assertSame('yuv420p', $streams[0]['pix_fmt'] ?? null);
        $this->assertSame('aac', $streams[1]['codec_name'] ?? null);
    }

    /**
     * One fragment per encode, covering the WHOLE requested window.
     *
     * The HLS muxer has no "never split" switch — the fMP4 branch says it with
     * an `-hls_time` far above any segment length. If that ever regressed, the
     * muxer would cut the 6 s window into several fragments, the publish chain
     * would pick up only the first (`…s0`), and every segment would silently
     * become a fraction of its advertised duration: a playlist whose `#EXTINF`
     * says 6 s served by a 1 s fragment, i.e. a stall at every boundary.
     * Nothing about the box structure or the start time would look wrong.
     */
    public function testOneEncodeYieldsOneFragmentCoveringTheWholeSegmentWindow(): void
    {
        $clip = $this->makeH264AacClip();

        // Segment 0, so `format.duration` (an END time on the rebased timeline)
        // is the fragment's LENGTH and nothing else.
        $segment = $this->produce($clip, 'fmp4', '720p', 0);
        $init = dirname($segment) . '/init-v720p.m4s';

        $types = $this->topLevelBoxTypes($segment);
        $this->assertSame(1, count(array_keys($types, 'moof', true)), 'exactly one fragment per encode');

        $joined = $this->root . '/dur-' . bin2hex(random_bytes(3)) . '.mp4';
        file_put_contents($joined, (string) file_get_contents($init) . (string) file_get_contents($segment));
        $format = $this->ffprobeJson(['-show_format', $joined]);

        $this->assertEqualsWithDelta(
            6.0,
            (float) ($format['format']['duration'] ?? 0.0),
            0.35,
            'the fragment must cover the full segment window, not just its first GOP'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // AC1 — HEVC / AC-3 5.1
    // ─────────────────────────────────────────────────────────────────

    /**
     * The second source the AC names, and the one that carries the `v5`
     * incident: a 5.1(side) AC-3 source re-encoded without `-ac` yields AAC
     * with `channel_configuration=0`, which hls.js refuses to parse. That guard
     * lives in the codec half of the builder, which the container branch sits
     * beside — so it is re-asserted here on real output rather than assumed to
     * have survived.
     */
    public function testHevcAc3SourceProducesAConformantCmafFragmentAtBrowserSafeChannels(): void
    {
        $clip = $this->makeHevcAc3Clip();

        $segment = $this->produce($clip, 'fmp4', '720p', 1);
        $init = dirname($segment) . '/init-v720p.m4s';

        $this->assertInitShape($init);
        $this->assertMediaSegmentShape($segment);
        $this->assertBareSegmentIsNotSelfContained($segment);

        $streams = $this->probeConcat($init, $segment);
        $this->assertSame('h264', $streams[0]['codec_name'] ?? null);
        $this->assertSame('yuv420p', $streams[0]['pix_fmt'] ?? null);
        $this->assertSame('aac', $streams[1]['codec_name'] ?? null);
        $this->assertSame(
            2,
            $streams[1]['channels'] ?? null,
            'browserSafeAudioChannels must still clamp a 5.1 source on the CMAF branch'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // AC1 — the timeline
    // ─────────────────────────────────────────────────────────────────

    /**
     * The measurement that justifies `Fmp4SegmentRebaser` existing at all.
     *
     * ffmpeg normalises the first fragment of every independently-encoded file
     * to `baseMediaDecodeTime = 0`, so an un-rebased segment 1 would decode at
     * t≈0 — indistinguishable from segment 0, and every DASH client would stack
     * the whole presentation at the origin. Segment 0 is produced alongside as
     * the control, so "the number is near 6" cannot pass by accident on a
     * fragment that simply always reports its nominal index.
     */
    public function testEachFragmentDecodesAtItsOwnPositionOnTheVodTimeline(): void
    {
        $clip = $this->makeH264AacClip();

        $first = $this->produce($clip, 'fmp4', '720p', 0);
        $later = $this->produce($clip, 'fmp4', '720p', 1, dirname($first));
        $init = dirname($first) . '/init-v720p.m4s';

        $firstDts = $this->firstVideoDts($init, $first);
        $laterDts = $this->firstVideoDts($init, $later);

        // Segment length is 6 s, so segment 1 starts at 6 s.
        $this->assertEqualsWithDelta(0.0, $firstDts, 0.25, 'control: segment 0 decodes at the origin');
        $this->assertEqualsWithDelta(6.0, $laterDts, 0.25, 'segment 1 must decode 6 s in, not at the origin');
    }

    // ─────────────────────────────────────────────────────────────────
    // AC2 — the flag-off control, on real bytes
    // ─────────────────────────────────────────────────────────────────

    /**
     * The same job, the same request, the flag off. This is the byte-level half
     * of the A/B: the command-string half lives in
     * `FfmpegRunnerSegmentFormatTest`, which pins the MPEG-TS strings against
     * literals captured from `origin/master`.
     */
    public function testWithTheFlagOffTheSameRequestStillProducesARealMpegTsSegment(): void
    {
        $clip = $this->makeH264AacClip();

        $segment = $this->produce($clip, 'mpegts', '720p', 1);

        $this->assertStringEndsWith('/seg-v720p-00001.ts', $segment);
        $this->assertFileDoesNotExist(dirname($segment) . '/init-v720p.m4s');
        $this->assertSame([], glob(dirname($segment) . '/*.m4s') ?: []);

        // An MPEG-TS segment is self-contained: ffprobe reads it on its own,
        // which is exactly what the CMAF fragment must NOT do.
        $format = $this->ffprobeJson(['-show_format', $segment]);
        $this->assertStringContainsString('mpegts', (string) ($format['format']['format_name'] ?? ''));

        $streams = $this->ffprobeStreams($segment);
        $this->assertSame('h264', $streams[0]['codec_name'] ?? null);
        $this->assertSame('aac', $streams[1]['codec_name'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────
    // production driver
    // ─────────────────────────────────────────────────────────────────

    /**
     * Drives a real `TranscodeManager::ensureSegment()` with a real
     * `FfmpegRunner` over a mocked job row, and returns the published path.
     */
    private function produce(string $clip, string $format, string $variant, int $index, ?string $dir = null): string
    {
        $dir ??= $this->root . '/job-' . bin2hex(random_bytes(3));
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $params = ['video_codec' => 'libx264', 'preset' => 'ultrafast', 'crf' => 30, 'audio_codec' => 'aac'];
        if ($format === 'fmp4') {
            $params['segment_format'] = 'fmp4';
        }
        $row = [
            'id' => 'it-job',
            'hls_dir' => $dir,
            'input_path' => $clip,
            'status' => 'completed',
            'duration_seconds' => 12,
            'segment_seconds' => 6,
            'segment_params' => json_encode($params),
            'variants' => json_encode(['renditions' => [[
                'id' => $variant,
                'width' => 320,
                'height' => 240,
                'bandwidth' => 500000,
                'video_codec' => 'libx264',
                'audio_codec' => 'aac',
                'is_copy' => false,
            ]]]),
        ];

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use ($row): array {
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['c' => 0]];
                }
                return str_contains($sql, 'transcode_jobs WHERE id = ?') ? [$row] : [];
            }
        );

        $manager = new TranscodeManager(
            $db,
            new FfmpegRunner(self::FFMPEG, self::FFPROBE, $this->root),
            $this->root,
            null,
            6,
            null,
            null,
            null,
            null,
            null,
            null,
            60_000 // the encode is a real one; give it room
        );

        $path = $manager->ensureSegment('it-job', $variant, $index);
        $log = "{$dir}/ffmpeg-segments.log";
        $this->assertIsString(
            $path,
            'segment was not produced. ffmpeg log: ' . (is_file($log) ? (string) file_get_contents($log) : '(none)')
        );

        return $path;
    }

    // ─────────────────────────────────────────────────────────────────
    // assertions on parsed bytes
    // ─────────────────────────────────────────────────────────────────

    private function assertInitShape(string $path): void
    {
        $types = $this->topLevelBoxTypes($path);

        $this->assertNotEmpty($types, 'the init parsed to ZERO boxes — a walk that found nothing is not a pass');
        $this->assertSame('ftyp', $types[0], "init must open with ftyp, got: " . implode(',', $types));
        $this->assertContains('moov', $types, "init must carry moov, got: " . implode(',', $types));
        $this->assertNotContains('moof', $types, 'the init must carry no media fragment');
        $this->assertNotContains('mdat', $types, 'the init must carry no media data');
    }

    private function assertMediaSegmentShape(string $path): void
    {
        $types = $this->topLevelBoxTypes($path);

        $this->assertNotEmpty($types, 'the media segment parsed to ZERO boxes');
        $this->assertContains('moof', $types, "media segment must carry moof, got: " . implode(',', $types));
        $this->assertContains('mdat', $types, "media segment must carry mdat, got: " . implode(',', $types));
        $this->assertSame('styp', $types[0], "a CMAF segment opens with styp, got: " . implode(',', $types));
        // The whole point: no self-contained header.
        $this->assertNotContains('ftyp', $types, 'a CMAF media segment must not repeat ftyp');
        $this->assertNotContains(
            'moov',
            $types,
            'a media segment carrying its own moov is non-conformant for DASH SegmentTemplate@initialization'
        );
        $this->assertLessThan(
            strpos(implode(',', $types), 'mdat'),
            strpos(implode(',', $types), 'moof'),
            'moof must precede mdat'
        );
    }

    /**
     * The blueprint's stated PASS for a bare fMP4 fragment: ffprobe finds no
     * streams in it, because it has no `moov`.
     */
    private function assertBareSegmentIsNotSelfContained(string $segment): void
    {
        exec(
            sprintf('%s -v error -show_streams %s 2>&1', escapeshellarg(self::FFPROBE), escapeshellarg($segment)),
            $output,
            $code
        );

        $this->assertNotSame(0, $code, 'ffprobe must REFUSE a bare fragment: ' . implode("\n", $output));
    }

    /**
     * A deliberately independent top-level box walk — 32-bit sizes, with the
     * 64-bit `largesize` escape — so this is not a re-derivation of the code it
     * is checking.
     *
     * @return list<string>
     */
    private function topLevelBoxTypes(string $path): array
    {
        $data = (string) file_get_contents($path);
        $types = [];
        $offset = 0;
        $length = strlen($data);
        while ($offset + 8 <= $length) {
            $size = (int) unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            if ($size === 1) {
                $size = (int) unpack('J', substr($data, $offset + 8, 8))[1];
            } elseif ($size === 0) {
                $size = $length - $offset;
            }
            if ($size < 8) {
                break;
            }
            $types[] = $type;
            $offset += $size;
        }

        return $types;
    }

    // ─────────────────────────────────────────────────────────────────
    // ffprobe helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private function probeConcat(string $init, string $segment): array
    {
        $joined = $this->root . '/concat-' . bin2hex(random_bytes(3)) . '.mp4';
        file_put_contents($joined, (string) file_get_contents($init) . (string) file_get_contents($segment));

        $streams = $this->ffprobeStreams($joined);
        $this->assertCount(2, $streams, 'init ++ fragment must read back as a normal two-stream file');

        return $streams;
    }

    private function firstVideoDts(string $init, string $segment): float
    {
        $joined = $this->root . '/dts-' . bin2hex(random_bytes(3)) . '.mp4';
        file_put_contents($joined, (string) file_get_contents($init) . (string) file_get_contents($segment));

        $decoded = $this->ffprobeJson([
            '-select_streams', 'v:0', '-read_intervals', '%+#1', '-show_packets', $joined,
        ]);
        $packets = $decoded['packets'] ?? [];
        $this->assertIsArray($packets);
        $this->assertNotEmpty($packets, 'no video packet could be read back from init ++ fragment');

        return (float) ($packets[0]['dts_time'] ?? -1.0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ffprobeStreams(string $path): array
    {
        $decoded = $this->ffprobeJson(['-show_streams', $path]);
        $streams = $decoded['streams'] ?? [];
        $this->assertIsArray($streams);

        return array_values($streams);
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, mixed>
     */
    private function ffprobeJson(array $args): array
    {
        $cmd = escapeshellarg(self::FFPROBE) . ' -v error -of json '
            . implode(' ', array_map('escapeshellarg', $args));
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, "ffprobe failed: {$cmd}\n" . implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    // ─────────────────────────────────────────────────────────────────
    // fixtures
    // ─────────────────────────────────────────────────────────────────

    private function makeH264AacClip(): string
    {
        return $this->makeClip('h264_aac.mp4', '-c:v libx264 -preset ultrafast -pix_fmt yuv420p -c:a aac');
    }

    private function makeHevcAc3Clip(): string
    {
        return $this->makeClip(
            'hevc_ac3.mkv',
            '-c:v libx265 -preset ultrafast -x265-params log-level=none -pix_fmt yuv420p -c:a ac3 -ac 6'
        );
    }

    private function makeClip(string $name, string $codecArgs): string
    {
        $path = "{$this->root}/{$name}";
        if (is_file($path)) {
            return $path;
        }
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -f lavfi -i testsrc=duration=12:size=320x240:rate=24 '
            . '-f lavfi -i sine=frequency=440:duration=12 %s -shortest %s 2>&1',
            escapeshellarg(self::FFMPEG),
            $codecArgs,
            escapeshellarg($path)
        );
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, "failed to generate {$name}: " . implode("\n", $output));

        return $path;
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "{$dir}/{$entry}";
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
