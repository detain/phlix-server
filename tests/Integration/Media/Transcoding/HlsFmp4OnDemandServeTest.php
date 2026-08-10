<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Controllers\HlsController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Workerman\MySQL\Connection;

/**
 * S310 acceptance — real ffmpeg, real bytes, through the real HLS controller.
 *
 * The AC is that a client walking `master.m3u8` → `media_v{V}.m3u8` →
 * `#EXT-X-MAP` init → segments **through the real `/hls/{job_id}/{file}` route**
 * receives playable bytes for every shape. Every word of that is load-bearing,
 * so this test refuses the shortcuts that would let it pass vacuously:
 *
 *  - The job directory is built by the REAL writer
 *    ({@see TranscodeManager::ensurePlaylistRegenerated()}) and then asserted to
 *    hold **zero** `.m4s` files. That assertion is the denominator: without it a
 *    200 could mean "the file was already there".
 *  - Every byte is served by {@see HlsController::serveFile()}. That is exactly
 *    the gap S57's browser gate recorded and could not close — it proved hls.js
 *    can PLAY these bytes while serving them from its own static HTTP server,
 *    with the controller still matching `\.ts$`.
 *  - The filenames are read out of the PLAYLISTS the producer wrote, not typed
 *    by a test author. A hand-written list would test the parser against the
 *    same assumption the parser was written from.
 *  - The transcoder is the real {@see TranscodeManager} over the real
 *    {@see FfmpegRunner}, i.e. a real detached ffmpeg publish chain. Nothing
 *    about `ensureSegment()` is mocked.
 *  - The served bytes are handed to ffprobe/ffmpeg, so "200 with a body" cannot
 *    be mistaken for "200 with a playable segment".
 *
 * ## Reading the refusals
 *
 * Every refusal sits beside a succeeding control and is TIMED against
 * {@see self::MAX_WAIT_MS}, the segment wait ceiling this manager was built
 * with. A 404 that took ~90 s would be an encode timing out (a broken producer);
 * a 404 in milliseconds is the producer DECIDING it cannot make that file. The
 * assertions say which happened.
 */
final class HlsFmp4OnDemandServeTest extends TestCase
{
    private const FFMPEG = '/usr/bin/ffmpeg';
    private const FFPROBE = '/usr/bin/ffprobe';

    private const JOB_ID = 's310-job';
    private const DURATION = 8.0;
    private const SEGMENT_SECONDS = 4;

    /**
     * The segment wait ceiling handed to the manager. A refusal bounded by THIS
     * is a timeout, not a decision — the timing assertions separate them.
     */
    private const MAX_WAIT_MS = 90_000;

    private string $root = '';
    private string $dir = '';
    private string $clip = '';
    private TranscodeManager $manager;
    private HlsController $controller;

    protected function setUp(): void
    {
        if (!is_executable(self::FFMPEG) || !is_executable(self::FFPROBE)) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }

        $this->root = sys_get_temp_dir() . '/phlix_s310_it_' . uniqid();
        mkdir($this->root, 0755, true);
        $this->dir = $this->root . '/' . self::JOB_ID;
        mkdir($this->dir, 0755, true);

        $this->clip = $this->makeClip();
        $this->manager = $this->makeManager(self::JOB_ID, $this->dir, 'fmp4', true);
        $this->controller = $this->makeController($this->manager);

        $this->assertTrue(
            $this->manager->ensurePlaylistRegenerated(self::JOB_ID),
            'the production writer did not produce the job directory'
        );
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            self::rrmdir($this->root);
        }
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * The premise: a freshly written fMP4 job has playlists and NOT ONE segment.
     *
     * Asserted as its own case so that if the writer ever starts producing
     * segments up front, this file fails HERE — loudly — instead of every case
     * below quietly degrading into "served a file that was already there".
     */
    public function testTheFreshJobHasFmp4PlaylistsAndZeroSegments(): void
    {
        $this->assertFileExists($this->dir . '/master.m3u8');
        $this->assertFileExists($this->dir . '/media_v240p.m3u8');
        $this->assertStringContainsString(
            '#EXT-X-MAP:URI="init-v240p.m4s"',
            (string) file_get_contents($this->dir . '/media_v240p.m3u8'),
            'the fixture must be an fMP4 job — an mpegts one carries no EXT-X-MAP'
        );
        $this->assertSame(
            [],
            $this->m4sFiles(),
            'the AC is "produced on demand" — this fixture must start with no segments at all'
        );
    }

    /**
     * ⚠ The case S310 exists for.
     *
     * hls.js resolves `#EXT-X-MAP` BEFORE any media segment, and the init has no
     * producer of its own — it is published beside segment 0. Before S310 this
     * request fell through to a static lookup, 404'd, and triggered no encode
     * whatsoever, so a flagged stream was unreachable from its own opening
     * request. Here it must produce the init, and the init must be a real fMP4
     * header: `ftyp` + `moov`, no media fragment.
     */
    public function testTheExtXMapInitIsEncodedOnDemandAndServedAsRealFmp4(): void
    {
        $this->assertFileDoesNotExist($this->dir . '/init-v240p.m4s');

        $response = $this->get('init-v240p.m4s');

        $this->assertSame(200, $response->statusCode, $this->ffmpegLog());
        $this->assertSame('video/mp4', $response->headers['Content-Type']);
        $bytes = $this->bodyOf($response);
        $this->assertNotSame('', $bytes);
        $this->assertFileExists($this->dir . '/init-v240p.m4s', 'the encode must have published the init');

        // An init is `ftyp` + `moov` and carries no media. The box type lives at
        // bytes 4..8 of the first box.
        $this->assertSame('ftyp', substr($bytes, 4, 4), 'an init segment must open with ftyp');
        $this->assertStringContainsString('moov', substr($bytes, 0, 512));
        $this->assertStringNotContainsString('moof', $bytes, 'an init carries no media fragment');
    }

    /**
     * The other half: a media segment, produced on demand and playable when
     * concatenated onto its init — the only way a bare CMAF fragment can be
     * decoded at all (S56 split them deliberately).
     */
    public function testAnFmp4MediaSegmentIsEncodedOnDemandAndDecodesAgainstItsInit(): void
    {
        $this->assertFileDoesNotExist($this->dir . '/seg-v240p-00000.m4s');

        $init = $this->bodyOf($this->assertOk($this->get('init-v240p.m4s')));
        $segment = $this->bodyOf($this->assertOk($this->get('seg-v240p-00000.m4s')));

        // A bare fragment: styp/moof, never ftyp/moov.
        $this->assertSame('styp', substr($segment, 4, 4), 'a media segment must open with styp');
        $this->assertStringNotContainsString('moov', $segment);

        $joined = $this->root . '/joined.mp4';
        file_put_contents($joined, $init . $segment);
        $probe = $this->ffprobeJson($joined);
        $streams = is_array($probe['streams'] ?? null) ? $probe['streams'] : [];

        $this->assertNotSame([], $streams, 'init+fragment must decode as a real elementary stream');
        $this->assertSame('h264', $streams[0]['codec_name'] ?? null);
        $this->assertSame(320, (int) ($streams[0]['width'] ?? 0));
        $this->assertSame(240, (int) ($streams[0]['height'] ?? 0));

        // Control: the fragment ALONE has no moov, so it must NOT decode. Without
        // this, "ffprobe was happy" could simply mean ffprobe is lenient.
        $alone = $this->root . '/alone.m4s';
        file_put_contents($alone, $segment);
        $status = 0;
        $out = [];
        exec(
            self::FFPROBE . ' -v error -show_streams -of json ' . escapeshellarg($alone) . ' 2>&1',
            $out,
            $status
        );
        $this->assertNotSame(0, $status, 'a bare fragment without its init must not probe as a stream');
    }

    /**
     * The audio renditions resolve through the OTHER argument shape
     * (`ensureSegment($job, null, $index, 'a{N}')`). A controller that routed
     * everything through the video arm would 404 here — and an fMP4 stream with
     * a working video ladder and no audio group is a silent, half-broken stream.
     */
    public function testAnAudioRenditionIsAlsoEncodedOnDemand(): void
    {
        $init = $this->bodyOf($this->assertOk($this->get('init-a1.m4s')));
        $segment = $this->bodyOf($this->assertOk($this->get('seg-a1-00001.m4s')));

        $this->assertSame('ftyp', substr($init, 4, 4));
        $this->assertSame('styp', substr($segment, 4, 4));

        $joined = $this->root . '/joined-audio.mp4';
        file_put_contents($joined, $init . $segment);
        $probe = $this->ffprobeJson($joined);
        $streams = is_array($probe['streams'] ?? null) ? $probe['streams'] : [];

        $this->assertNotSame([], $streams);
        $this->assertSame('aac', $streams[0]['codec_name'] ?? null);
        // The SECOND audio track of the clip is the 880 Hz tone; asserting it is
        // audio-only is what shows `a1` did not silently resolve via the video arm.
        $this->assertSame('audio', $streams[0]['codec_type'] ?? null);
        $this->assertCount(1, $streams);
    }

    /**
     * The two shapes only a LEGACY single-variant job (`variants IS NULL`) can
     * produce: a bare `init.m4s` and `seg-NNNNN.m4s`.
     *
     * They complete the six, and they are not reachable from the multi-variant
     * fixture above at all — its playlists never name them. A job row with a
     * null `variants` column is the only thing that makes the writer emit them,
     * so the fixture is what proves the arm is real rather than decorative.
     */
    public function testTheLegacySingleVariantShapesAreEncodedOnDemand(): void
    {
        [$manager, $controller, $dir] = $this->makeLegacyJob('s310-legacy', 'fmp4');
        $this->assertTrue($manager->ensurePlaylistRegenerated('s310-legacy'));
        $this->assertStringContainsString(
            '#EXT-X-MAP:URI="init.m4s"',
            (string) file_get_contents($dir . '/media_0.m3u8'),
            'the legacy writer must name the bare init — otherwise this case tests nothing'
        );
        $this->assertSame([], self::m4sIn($dir), 'the legacy fixture must start with no segments');

        $init = $this->bodyOf($this->assertOk(
            $controller->serveFile(new Request(), ['job_id' => 's310-legacy', 'file' => 'init.m4s'])
        ));
        $segment = $this->bodyOf($this->assertOk(
            $controller->serveFile(new Request(), ['job_id' => 's310-legacy', 'file' => 'seg-00000.m4s'])
        ));

        $this->assertSame('ftyp', substr($init, 4, 4));
        $this->assertSame('styp', substr($segment, 4, 4));

        $joined = $this->root . '/joined-legacy.mp4';
        file_put_contents($joined, $init . $segment);
        $probe = $this->ffprobeJson($joined);
        $streams = is_array($probe['streams'] ?? null) ? $probe['streams'] : [];
        $this->assertNotSame([], $streams, 'the legacy init+fragment must decode');
        $this->assertSame(
            'h264',
            $streams[0]['codec_name'] ?? null,
            'the legacy shape carries the muxed programme, not an audio-only rendition'
        );
    }

    /**
     * The whole presentation, driven by a real HLS demuxer over bytes THIS
     * CONTROLLER served — from the master playlist down.
     *
     * The walk is a client's: fetch `master.m3u8` through `serveFile()`, read the
     * variant and `EXT-X-MEDIA` URIs out of it, fetch each media playlist through
     * `serveFile()`, read its `EXT-X-MAP` and `EXTINF` entries, and fetch every
     * one of those through `serveFile()` too. Nothing is read off disk; the mirror
     * directory contains only what the controller returned. That distinction is
     * the entire point of S310 — S57 already proved the files are playable and
     * could not prove they are reachable.
     */
    public function testAnHlsClientPlaysAPresentationServedEntirelyByTheController(): void
    {
        $mirror = $this->root . '/served';
        mkdir($mirror, 0755, true);

        $master = $this->bodyOf($this->assertOk($this->get('master.m3u8')));
        file_put_contents("{$mirror}/master.m3u8", $master);

        $playlists = self::playlistReferences($master);
        sort($playlists);
        $this->assertSame(
            ['media_a0.m3u8', 'media_a1.m3u8', 'media_v144p.m3u8', 'media_v240p.m3u8'],
            $playlists,
            'denominator: two video rungs plus two audio renditions'
        );

        $segments = [];
        foreach ($playlists as $playlist) {
            $body = $this->bodyOf($this->assertOk($this->get($playlist)));
            file_put_contents("{$mirror}/{$playlist}", $body);
            foreach (self::segmentReferences($body) as $reference) {
                $segments[] = $reference;
            }
        }

        $this->assertGreaterThanOrEqual(
            12,
            count($segments),
            'denominator: 4 renditions x (1 EXT-X-MAP init + 2 media segments)'
        );
        $this->assertContains('init-v240p.m4s', $segments, 'the walk must have found an init to fetch');

        foreach ($segments as $reference) {
            $response = $this->get($reference);
            $this->assertSame(
                200,
                $response->statusCode,
                "the controller refused {$reference}. " . $this->ffmpegLog()
            );
            $bytes = $this->bodyOf($response);
            $this->assertNotSame('', $bytes, "{$reference} served zero bytes");
            file_put_contents("{$mirror}/{$reference}", $bytes);
        }

        // Video rung: remuxed straight out of the SERVED bytes.
        $video = $this->remux("{$mirror}/media_v240p.m3u8", $this->root . '/served-video.mp4');
        $this->assertSame('h264', $video['streams'][0]['codec_name'] ?? null);
        $this->assertSame(320, (int) ($video['streams'][0]['width'] ?? 0));
        $this->assertSame(240, (int) ($video['streams'][0]['height'] ?? 0));
        $this->assertEqualsWithDelta(
            self::DURATION,
            (float) ($video['format']['duration'] ?? 0.0),
            0.35,
            'the remux must span every segment, not just the first'
        );

        // Audio rendition: the group hls.js would attach to that rung.
        $audio = $this->remux("{$mirror}/media_a0.m3u8", $this->root . '/served-audio.mp4');
        $this->assertSame('aac', $audio['streams'][0]['codec_name'] ?? null);
        $this->assertEqualsWithDelta(self::DURATION, (float) ($audio['format']['duration'] ?? 0.0), 0.35);
    }

    /**
     * ⚠ The regression control for the SHIPPED default.
     *
     * S310 widened a router that every MPEG-TS job in production goes through.
     * An `mpegts` job must still serve `.ts` on demand exactly as before — and
     * the assertion is on real bytes (`ffprobe` sees `mpegts`), not a 200, so a
     * router that started answering `.ts` requests with the wrong producer
     * arguments would be visible.
     */
    public function testAnMpegTsJobStillServesItsTsSegmentsOnDemand(): void
    {
        [$manager, $controller, $dir] = $this->makeMultiVariantJob('s310-mpegts', 'mpegts');
        $this->assertTrue($manager->ensurePlaylistRegenerated('s310-mpegts'));
        $this->assertStringNotContainsString(
            'EXT-X-MAP',
            (string) file_get_contents($dir . '/media_v240p.m3u8'),
            'an mpegts job must carry no EXT-X-MAP — otherwise this is not the control it claims to be'
        );
        $this->assertSame([], glob($dir . '/*.ts') ?: [], 'the mpegts fixture must start with no segments');

        $response = $controller->serveFile(
            new Request(),
            ['job_id' => 's310-mpegts', 'file' => 'seg-v240p-00000.ts']
        );
        $this->assertSame(200, $response->statusCode, $this->ffmpegLog($dir));
        $this->assertSame('video/mp2t', $response->headers['Content-Type']);

        $served = $this->root . '/served.ts';
        file_put_contents($served, $this->bodyOf($response));
        $probe = $this->ffprobeJson($served);
        $streams = is_array($probe['streams'] ?? null) ? $probe['streams'] : [];
        $this->assertNotSame([], $streams, 'a served MPEG-TS segment must probe as a real stream');
        $this->assertSame('h264', $streams[0]['codec_name'] ?? null);
    }

    /**
     * A miss the producer cannot satisfy is a DECISION, measured against a
     * succeeding control and against the wait ceiling.
     *
     * Two refusals of different kinds are timed: a rung that is not in the
     * ladder, and an index past the end. Both must answer in a small fraction of
     * {@see self::MAX_WAIT_MS}; a refusal that cost the ceiling would mean the
     * encode was launched and timed out — a different, worse failure wearing the
     * same 404. The unknown-rung INIT is included because it is the shape S310
     * added: before this step it 404'd for the wrong reason (no arm), and it must
     * now 404 for the right one (the producer refused).
     */
    public function testFmp4RefusalsAre404DecisionsNotTimeouts(): void
    {
        // Succeeding control FIRST, so a "fast 404" cannot simply mean nothing in
        // this fixture ever does any work.
        $startOk = hrtime(true);
        $ok = $this->get('seg-v240p-00000.m4s');
        $okMs = (hrtime(true) - $startOk) / 1_000_000.0;
        $this->assertSame(200, $ok->statusCode, $this->ffmpegLog());
        $this->assertGreaterThan(0.0, $okMs);

        $refusals = [
            'init-v9999p.m4s' => 'unknown rung, init',
            'seg-v9999p-00000.m4s' => 'unknown rung, segment',
            'seg-v240p-00099.m4s' => 'index past the end',
        ];
        foreach ($refusals as $file => $why) {
            $start = hrtime(true);
            $response = $this->get($file);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;

            $this->assertSame(404, $response->statusCode, "{$why} must 404");
            /** @var array<string, mixed> $body */
            $body = json_decode($response->body, true);
            $this->assertSame(
                'segment unavailable',
                $body['error'],
                "{$why} must be the producer's refusal, not a static-lookup 404"
            );
            $this->assertLessThan(
                self::MAX_WAIT_MS / 10.0,
                $elapsedMs,
                "the '{$why}' 404 took {$elapsedMs}ms against a " . self::MAX_WAIT_MS
                . 'ms wait ceiling — that is a TIMEOUT, not a decision'
            );
            $this->assertFileDoesNotExist($this->dir . '/' . $file, 'a refused segment must not be published');
        }
    }

    /**
     * A swept job directory still regenerates its playlists, and the fMP4
     * segments are then produced against the regenerated ones.
     *
     * This is the replayed-signed-URL path for a flagged job: the whole dir is
     * gone (that is how `sweepSegmentCache()` evicts), the client asks for the
     * init it read from a playlist it fetched an hour ago, and the answer must be
     * bytes rather than a 404.
     */
    public function testASweptFmp4JobRecoversAndStillProducesItsInit(): void
    {
        self::rrmdir($this->dir);
        $this->assertDirectoryDoesNotExist($this->dir);

        $playlist = $this->bodyOf($this->assertOk($this->get('media_v240p.m3u8')));
        $this->assertStringContainsString('#EXT-X-MAP:URI="init-v240p.m4s"', $playlist);

        $init = $this->bodyOf($this->assertOk($this->get('init-v240p.m4s')));
        $this->assertSame('ftyp', substr($init, 4, 4));
    }

    // ─────────────────────────────────────────────────────────────────
    // harness
    // ─────────────────────────────────────────────────────────────────

    private function get(string $file): Response
    {
        return $this->controller->serveFile(new Request(), ['job_id' => self::JOB_ID, 'file' => $file]);
    }

    private function assertOk(Response $response): Response
    {
        $this->assertSame(200, $response->statusCode, $response->body . ' ' . $this->ffmpegLog());

        return $response;
    }

    /**
     * The bytes a client would receive: file-backed responses stream via
     * {@see Response::withFile()} rather than buffering into `->body`.
     */
    private function bodyOf(Response $response): string
    {
        if ($response->filePath === null) {
            return $response->body;
        }
        $bytes = $response->fileLength > 0
            ? file_get_contents($response->filePath, false, null, $response->fileOffset, $response->fileLength)
            : file_get_contents($response->filePath, false, null, $response->fileOffset);

        return $bytes === false ? '' : $bytes;
    }

    /**
     * Every media playlist a master playlist points at: the `URI="…"` of each
     * `#EXT-X-MEDIA` plus the line following each `#EXT-X-STREAM-INF`.
     *
     * Deliberately a tiny independent reader rather than anything the producer
     * exposes, so the list is what a CLIENT would resolve.
     *
     * @return list<string>
     */
    private static function playlistReferences(string $master): array
    {
        $out = [];
        $lines = preg_split('/\R/', $master) ?: [];
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '#EXT-X-MEDIA:') && preg_match('/URI="([^"]+)"/', $line, $m) === 1) {
                $out[] = $m[1];
                continue;
            }
            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $next = trim($lines[$i + 1] ?? '');
                if ($next !== '' && !str_starts_with($next, '#')) {
                    $out[] = $next;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Every segment a media playlist references: the `#EXT-X-MAP` init first
     * (which is the order a client fetches them in), then each `#EXTINF` entry.
     *
     * @return list<string>
     */
    private static function segmentReferences(string $playlist): array
    {
        $out = [];
        foreach (preg_split('/\R/', $playlist) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#EXT-X-MAP:') && preg_match('/URI="([^"]+)"/', $line, $m) === 1) {
                $out[] = $m[1];
                continue;
            }
            if (!str_starts_with($line, '#')) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** @return list<string> Every `.m4s` currently in the main job dir, sorted. */
    private function m4sFiles(): array
    {
        return self::m4sIn($this->dir);
    }

    /** @return list<string> */
    private static function m4sIn(string $dir): array
    {
        $found = array_map('basename', glob($dir . '/*.m4s') ?: []);
        sort($found);

        return array_values($found);
    }

    private function makeController(TranscodeManager $manager): HlsController
    {
        // Constructed exactly as Application::getHlsController() does: the shared
        // HLS streamer over the segment dir, plus the container's TranscodeManager.
        return new HlsController(
            new HlsStreamer($this->root, 'http://localhost:8096', new QualitySelector()),
            $manager
        );
    }

    /**
     * @return array{0: TranscodeManager, 1: HlsController, 2: string}
     */
    private function makeMultiVariantJob(string $jobId, string $format): array
    {
        $dir = $this->root . '/' . $jobId;
        mkdir($dir, 0755, true);
        $manager = $this->makeManager($jobId, $dir, $format, true);

        return [$manager, $this->makeController($manager), $dir];
    }

    /**
     * @return array{0: TranscodeManager, 1: HlsController, 2: string}
     */
    private function makeLegacyJob(string $jobId, string $format): array
    {
        $dir = $this->root . '/' . $jobId;
        mkdir($dir, 0755, true);
        $manager = $this->makeManager($jobId, $dir, $format, false);

        return [$manager, $this->makeController($manager), $dir];
    }

    /**
     * A job row as a live one carries it. `$multiVariant = false` gives the
     * legacy `variants IS NULL` shape, which is the only way to reach the
     * unprefixed filename arms.
     */
    private function makeManager(string $jobId, string $dir, string $format, bool $multiVariant): TranscodeManager
    {
        $row = [
            'id' => $jobId,
            'hls_dir' => $dir,
            'input_path' => $this->clip,
            'status' => 'completed',
            'duration_seconds' => (int) self::DURATION,
            'segment_seconds' => self::SEGMENT_SECONDS,
            'width' => 320,
            'height' => 240,
            'bitrate' => 3000000,
            'segment_params' => json_encode([
                'video_codec' => 'libx264',
                'preset' => 'ultrafast',
                'crf' => 30,
                'audio_codec' => 'aac',
                'segment_format' => $format,
            ]),
            'variants' => $multiVariant ? json_encode([
                'renditions' => [
                    ['id' => '240p', 'width' => 320, 'height' => 240, 'bitrate' => 500000,
                        'codecs' => 'avc1.640029,mp4a.40.2', 'video_codec' => 'libx264',
                        'audio_codec' => 'aac', 'is_copy' => false],
                    ['id' => '144p', 'width' => 192, 'height' => 144, 'bitrate' => 250000,
                        'codecs' => 'avc1.640029,mp4a.40.2', 'video_codec' => 'libx264',
                        'audio_codec' => 'aac', 'is_copy' => false],
                ],
                'audio_tracks' => [
                    ['index' => 0, 'stream_index' => 1, 'language' => 'eng', 'label' => 'English',
                        'default' => true, 'codec' => 'aac'],
                    ['index' => 1, 'stream_index' => 2, 'language' => 'fra', 'label' => 'Francais',
                        'default' => false, 'codec' => 'aac'],
                ],
            ]) : null,
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

        return new TranscodeManager(
            $db,
            new FfmpegRunner(self::FFMPEG, self::FFPROBE, $this->root),
            $this->root,
            null,
            self::SEGMENT_SECONDS,
            null,
            null,
            null,
            null,
            null,
            null,
            self::MAX_WAIT_MS
        );
    }

    /** A short clip with TWO audio tracks, so the audio renditions are real. */
    private function makeClip(): string
    {
        $clip = $this->root . '/source.mkv';
        $seconds = (int) self::DURATION;
        $log = [];
        $status = 0;
        exec(
            self::FFMPEG . ' -nostdin -y'
            . " -f lavfi -i testsrc=size=320x240:rate=24:duration={$seconds}"
            . " -f lavfi -i sine=frequency=440:duration={$seconds}"
            . " -f lavfi -i sine=frequency=880:duration={$seconds}"
            . ' -map 0:v -map 1:a -map 2:a'
            . ' -c:v libx264 -preset ultrafast -pix_fmt yuv420p -g 48 -c:a aac -shortest '
            . escapeshellarg($clip) . ' 2>&1',
            $log,
            $status
        );
        $this->assertSame(0, $status, "clip generation failed:\n" . implode("\n", $log));

        return $clip;
    }

    private function ffmpegLog(?string $dir = null): string
    {
        $path = ($dir ?? $this->dir) . '/ffmpeg-segments.log';

        return is_file($path) ? "ffmpeg log:\n" . (string) file_get_contents($path) : '(no ffmpeg log)';
    }

    /**
     * Remuxes an HLS media playlist with `-c copy` and probes the result. The
     * demuxer is the client here: it resolves the `EXT-X-MAP` and every segment
     * URI itself, from the mirror directory the controller filled.
     *
     * @return array<string, mixed>
     */
    private function remux(string $playlist, string $out): array
    {
        $log = [];
        $status = 0;
        exec(
            self::FFMPEG . ' -nostdin -y -i ' . escapeshellarg($playlist)
            . ' -map 0 -c copy ' . escapeshellarg($out) . ' 2>&1',
            $log,
            $status
        );
        $this->assertSame(
            0,
            $status,
            "the HLS demuxer refused the SERVED playlist {$playlist}:\n" . implode("\n", $log)
        );

        return $this->ffprobeJson($out);
    }

    /**
     * @return array<string, mixed>
     */
    private function ffprobeJson(string $path): array
    {
        $lines = [];
        $status = 0;
        exec(
            self::FFPROBE . ' -v error -show_entries stream=index,codec_name,codec_type,width,height'
            . ':format=duration,format_name -of json ' . escapeshellarg($path),
            $lines,
            $status
        );
        $this->assertSame(0, $status, 'ffprobe refused ' . $path);
        $decoded = json_decode(implode("\n", $lines), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private static function rrmdir(string $dir): void
    {
        foreach (glob("{$dir}/*") ?: [] as $path) {
            is_dir($path) ? self::rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
