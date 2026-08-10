<?php

declare(strict_types=1);

namespace Phlix\Tests\E2E\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Tests\Support\Browser\BrowserProbeEnvironment;
use Workerman\MySQL\Connection;

/**
 * S57 acceptance — hls.js, in a real headless Chrome, PLAYING the fMP4 HLS
 * playlists this server writes.
 *
 * This exists because the alternative is worthless. Asserting a playlist's text
 * against expected strings proves only that the writer wrote what its author
 * intended; `TranscodeManagerPlaylistFormatTest` does that, and it cannot tell
 * you that a player accepts the result. A malformed `EXT-X-MAP` or a stale
 * `#EXT-X-VERSION` is a FATAL `manifestParsingError`, not a degradation, and the
 * HLS path is the hottest one in the app.
 *
 * What actually runs:
 *
 *  1. real ffmpeg encodes a source clip;
 *  2. the real `TranscodeManager` writes the playlists (`writeVodPlaylists()`
 *     via `ensurePlaylistRegenerated()`) and produces every segment through the
 *     real `ensureSegment()` → `FfmpegRunner` → detached CMAF publish chain;
 *  3. `tests/Support/Browser/hls-playback-probe.mjs` serves that directory,
 *     loads it into hls.js 1.6 inside headless Chrome, plays past a segment
 *     BOUNDARY, and reports what the player did.
 *
 * ⚠ **What this does NOT prove.** The bytes are served by the probe's own static
 * HTTP server, not by `HlsController`. This test therefore establishes that the
 * PLAYLISTS AND SEGMENTS are playable; it says nothing about the app's serve
 * path for them. That serve path was, when this file was written, genuinely
 * missing — `HlsController::serveFile()` matched `\.ts$` only, so a flagged job
 * 404'd every `.m4s`. S310 added the `.m4s` and `init*.m4s` arms and proved them
 * over real ffmpeg bytes in
 * {@see \Phlix\Tests\Integration\Media\Transcoding\HlsFmp4OnDemandServeTest},
 * which fetches a whole presentation THROUGH the controller. The two are
 * complementary and neither subsumes the other: this one has a real browser and
 * a fake server, that one has a real server and ffmpeg's demuxer for a client.
 *
 * The negative control below is not decoration: without it a probe that quietly
 * stopped loading anything would report "no fatal errors" and read as a pass.
 *
 * ⚠ **The `markTestSkipped()` calls below are load-bearing on a developer box and
 * were a HOLE in CI.** S57 shipped them and, because the PHP workflow never
 * installed hls.js, all three cases skipped on every CI run — `Skipped: 6` against
 * master's 3, measured on PR #664 (run `31263130044`) — while the job stayed green,
 * because a skipped test reads as a pass. S305 did NOT remove the guards: it makes
 * CI supply every prerequisite (`scripts/ci-browser-e2e-prereqs.php`) and then
 * asserts, from the JUnit report and by name, that these three cases executed
 * (`scripts/assert-browser-e2e-ran.php`). The prerequisite paths live in
 * {@see BrowserProbeEnvironment} so the workflow's idea of "installed" and this
 * file's idea of "available" cannot drift apart.
 */
final class Fmp4HlsPlaybackE2ETest extends TestCase
{
    private const FFMPEG = BrowserProbeEnvironment::FFMPEG;
    private const FFPROBE = BrowserProbeEnvironment::FFPROBE;

    /** Segment length, and therefore the boundary playback must cross. */
    private const SEG_SECONDS = 3;

    /** Play past the FIRST BOUNDARY: one segment playing proves far less. */
    private const PLAY_TO_SECONDS = 4;

    private string $root;
    private string $node;
    private string $chrome;
    private string $hlsjs;

    protected function setUp(): void
    {
        if (!is_executable(self::FFMPEG) || !is_executable(self::FFPROBE)) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
        $node = BrowserProbeEnvironment::node();
        if ($node === null) {
            $this->markTestSkipped('node (>= 22, for the built-in WebSocket client) not available');
        }
        $this->node = $node;

        $chrome = BrowserProbeEnvironment::chrome();
        if ($chrome === null) {
            $this->markTestSkipped('no Chrome/Chromium binary — the hls.js check needs a real browser');
        }
        $this->chrome = $chrome;

        $hlsjs = BrowserProbeEnvironment::hlsJs();
        if ($hlsjs === null) {
            $this->markTestSkipped(
                'web-ui/node_modules/hls.js is not installed (php scripts/ci-browser-e2e-prereqs.php)'
            );
        }
        $this->hlsjs = $hlsjs;

        $this->root = sys_get_temp_dir() . '/phlix_s57_e2e_' . uniqid();
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $this->rrmdir($this->root);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // the acceptance criterion
    // ─────────────────────────────────────────────────────────────────

    public function testHlsJsPlaysTheFmp4VariantPastASegmentBoundary(): void
    {
        $dir = $this->buildJob('fmp4');
        $probe = $this->probe($dir);

        $this->assertTrue(
            $probe['ok'] === true,
            'hls.js did not play the fMP4 playlist: ' . json_encode($probe, JSON_PRETTY_PRINT)
        );
        $this->assertSame([], $this->fatalErrors($probe), 'hls.js raised a fatal error');

        // EXT-X-MAP was honoured. Asserted on the PROBE SERVER'S request log,
        // not on an hls.js event: the player fetched the file the tag named, and
        // got it. (hls.js does report init loads through `FRAG_LOADED` with
        // `sn === 'initSegment'`, but which event carries them is a library
        // detail; the wire is not.)
        $this->assertSame(
            [['name' => 'init-v360p.m4s', 'status' => 200]],
            $this->requestsMatching($probe, '/^init-/'),
            'the player must have fetched exactly this variant\'s init segment, once, successfully'
        );

        // It crossed the boundary: two distinct media fragments, in order.
        $this->assertSame(
            [
                ['name' => 'seg-v360p-00000.m4s', 'status' => 200],
                ['name' => 'seg-v360p-00001.m4s', 'status' => 200],
            ],
            array_slice($this->requestsMatching($probe, '/^seg-/'), 0, 2)
        );

        // And pixels really came out the other end.
        $this->assertGreaterThanOrEqual(self::PLAY_TO_SECONDS, $probe['currentTime']);
        $this->assertGreaterThan(0, $probe['decodedFrames'], 'no video frame was decoded');
        $this->assertSame(640, $probe['videoWidth']);
        $this->assertSame(360, $probe['videoHeight']);
    }

    /**
     * THE CONTROL THAT MAKES THE ABOVE MEAN SOMETHING.
     *
     * The same directory, the same segments, the same probe — with the single
     * `#EXT-X-MAP` line deleted from the media playlist. hls.js must FAIL. If it
     * did not, the pass above would be evidence that the player tolerates the
     * playlist, not that S57's tag is doing anything.
     */
    public function testRemovingTheExtXMapBreaksPlayback(): void
    {
        $dir = $this->buildJob('fmp4');

        $media = "{$dir}/media_v360p.m3u8";
        $before = (string) file_get_contents($media);
        $after = (string) preg_replace('/^#EXT-X-MAP:.*\R/m', '', $before);
        $this->assertNotSame($before, $after, 'the fixture must actually have carried an EXT-X-MAP to remove');
        file_put_contents($media, $after);

        $probe = $this->probe($dir);

        $this->assertFalse(
            $probe['ok'] === true,
            'hls.js played an fMP4 playlist with NO EXT-X-MAP — the tag is not what makes it work: '
            . json_encode($probe, JSON_PRETTY_PRINT)
        );
        $this->assertNotSame([], $probe['errors'], 'the failure must be a reported hls.js error, not silence');
    }

    /**
     * The other control: the flag-OFF flavour of the very same source still
     * plays through the very same probe. Without it, a probe broken in some
     * fMP4-independent way could produce the negative control's failure for the
     * wrong reason, and the "S57 changed nothing for MPEG-TS" claim would rest
     * entirely on string comparison.
     */
    public function testHlsJsStillPlaysTheMpegTsVariant(): void
    {
        $dir = $this->buildJob('mpegts');
        $probe = $this->probe($dir);

        $this->assertTrue(
            $probe['ok'] === true,
            'hls.js did not play the MPEG-TS playlist: ' . json_encode($probe, JSON_PRETTY_PRINT)
        );
        $this->assertSame([], $this->requestsMatching($probe, '/^init-/'), 'MPEG-TS has no init segment to fetch');
        $this->assertSame(
            [
                ['name' => 'seg-v360p-00000.ts', 'status' => 200],
                ['name' => 'seg-v360p-00001.ts', 'status' => 200],
            ],
            array_slice($this->requestsMatching($probe, '/^seg-/'), 0, 2)
        );
        $this->assertGreaterThanOrEqual(self::PLAY_TO_SECONDS, $probe['currentTime']);
        $this->assertGreaterThan(0, $probe['decodedFrames']);
    }

    // ─────────────────────────────────────────────────────────────────
    // building a real job
    // ─────────────────────────────────────────────────────────────────

    /**
     * Writes the playlists through the real `writeVodPlaylists()` (reached via
     * `ensurePlaylistRegenerated()`, its second production caller) and produces
     * every segment through the real `ensureSegment()`.
     *
     * A single 360p rung, so the ladder is deterministic and the encode count is
     * bounded — this test's cost is real ffmpeg work plus real-time playback.
     */
    private function buildJob(string $format): string
    {
        $clip = $this->makeClip();
        // `ensurePlaylistRegenerated()` resolves the directory as
        // `{segmentDir}/{jobId}` and ignores the row's `hls_dir`, so the job id
        // IS the directory name here.
        $jobId = "e2e-{$format}";
        $dir = "{$this->root}/{$jobId}";
        mkdir($dir, 0755, true);

        $params = ['video_codec' => 'libx264', 'preset' => 'ultrafast', 'crf' => 30, 'audio_codec' => 'aac'];
        if ($format === 'fmp4') {
            $params['segment_format'] = 'fmp4';
        }
        $row = [
            'id' => $jobId,
            'hls_dir' => $dir,
            'input_path' => $clip,
            'status' => 'completed',
            'duration_seconds' => 12,
            'segment_seconds' => self::SEG_SECONDS,
            'segment_params' => json_encode($params),
            'variants' => json_encode(['renditions' => [[
                'id' => '360p',
                'width' => 640,
                'height' => 360,
                'bandwidth' => 800000,
                'codecs' => 'avc1.640029,mp4a.40.2',
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
            self::SEG_SECONDS,
            null,
            null,
            null,
            null,
            null,
            null,
            90_000
        );

        $this->assertTrue($manager->ensurePlaylistRegenerated($jobId), 'playlists were not written');

        // 12 s at 3 s ⇒ segments 0..3. Produce them all up front: the probe's
        // static server does no on-demand encoding (that is HlsController's job,
        // and it does not route .m4s until S59).
        for ($i = 0; $i < 4; $i++) {
            $path = $manager->ensureSegment($jobId, '360p', $i);
            $log = "{$dir}/ffmpeg-segments.log";
            $this->assertIsString(
                $path,
                "segment {$i} was not produced. ffmpeg log: "
                . (is_file($log) ? (string) file_get_contents($log) : '(none)')
            );
        }

        return $dir;
    }

    private function makeClip(): string
    {
        $path = "{$this->root}/source.mp4";
        if (is_file($path)) {
            return $path;
        }
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -f lavfi -i testsrc=duration=12:size=640x360:rate=24 '
            . '-f lavfi -i sine=frequency=440:duration=12 '
            . '-c:v libx264 -preset ultrafast -pix_fmt yuv420p -c:a aac -shortest %s 2>&1',
            escapeshellarg(self::FFMPEG),
            escapeshellarg($path)
        );
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, 'failed to generate the source clip: ' . implode("\n", $output));

        return $path;
    }

    // ─────────────────────────────────────────────────────────────────
    // the browser
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return array{
     *     ok: bool, reason: ?string, errors: list<array<string, mixed>>,
     *     initSegments: list<string>, fragments: list<string>, levels: list<array<string, mixed>>,
     *     requests: list<array{name: string, status: int, bytes: int}>,
     *     currentTime: float, decodedFrames: int, videoWidth: int, videoHeight: int
     * }
     */
    private function probe(string $dir): array
    {
        $script = dirname(__DIR__, 3) . '/Support/Browser/hls-playback-probe.mjs';
        $this->assertFileExists($script);

        $cmd = implode(' ', array_map('escapeshellarg', [
            $this->node,
            $script,
            '--dir',
            $dir,
            '--hlsjs',
            $this->hlsjs,
            '--chrome',
            $this->chrome,
            '--playlist',
            'master.m3u8',
            '--seconds',
            (string) self::PLAY_TO_SECONDS,
            '--timeout',
            '45000',
        ])) . ' 2>&1';

        exec($cmd, $output, $code);
        $text = implode("\n", $output);
        $this->assertSame(0, $code, "the browser probe could not run:\n{$text}");

        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded, "the probe did not emit JSON:\n{$text}");
        // A probe that never reached the page would report `hlsSupported: null`
        // and every list empty — which must not read as a pass anywhere above.
        $this->assertTrue(
            $decoded['hlsSupported'] === true,
            "hls.js reported MSE unsupported in this browser build:\n{$text}"
        );

        /** @var array{ok: bool, reason: ?string, errors: list<array<string, mixed>>, initSegments: list<string>,
         *      fragments: list<string>, levels: list<array<string, mixed>>,
         *      requests: list<array{name: string, status: int, bytes: int}>, currentTime: float,
         *      decodedFrames: int, videoWidth: int, videoHeight: int} $decoded */
        return $decoded;
    }

    /**
     * The probe server's request log, filtered by filename and reduced to
     * `{name, status}` so a comparison reads legibly on failure.
     *
     * @param array{requests: list<array{name: string, status: int, bytes: int}>} $probe
     *
     * @return list<array{name: string, status: int}>
     */
    private function requestsMatching(array $probe, string $pattern): array
    {
        $out = [];
        foreach ($probe['requests'] as $request) {
            if (preg_match($pattern, $request['name']) === 1) {
                $out[] = ['name' => $request['name'], 'status' => $request['status']];
            }
        }

        return $out;
    }

    /**
     * @param array{errors: list<array<string, mixed>>} $probe
     *
     * @return list<array<string, mixed>>
     */
    private function fatalErrors(array $probe): array
    {
        return array_values(array_filter(
            $probe['errors'],
            static fn (array $e): bool => ($e['fatal'] ?? false) === true
        ));
    }

    // ─────────────────────────────────────────────────────────────────
    // environment
    // ─────────────────────────────────────────────────────────────────
    //
    // Discovery of node/chrome/hls.js moved to
    // tests/Support/Browser/BrowserProbeEnvironment.php in S305, so that the CI
    // step which INSTALLS the prerequisites and this file, which decides whether
    // they are usable, cannot disagree. If they could, CI would satisfy the
    // installer and still skip these cases.

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
