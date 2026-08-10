<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Controllers\DashController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Workerman\MySQL\Connection;

/**
 * S59 acceptance — real ffmpeg, real bytes, through the real controller.
 *
 * The AC is "a fresh DASH request against a job with **no existing segments**
 * triggers encode-then-serve". Every word of that is load-bearing, so this test
 * refuses the shortcuts that would make it pass vacuously:
 *
 *  - The job directory is built by the REAL writer
 *    ({@see TranscodeManager::ensurePlaylistRegenerated()}) and then asserted to
 *    contain **zero** `.m4s` files. That assertion is the denominator: without
 *    it, "the controller returned 200" could mean "S58 had already written the
 *    file" rather than "S59 produced it".
 *  - The bytes are served by {@see DashController::serveFile()} — not by a test
 *    harness's own file server, which is the limit S57's E2E recorded.
 *  - The transcoder is the real {@see TranscodeManager} over the real
 *    {@see FfmpegRunner}, i.e. a real detached ffmpeg publish chain. Nothing
 *    about `ensureSegment()` is mocked.
 *  - The served bytes are handed to ffprobe/ffmpeg, so "200 with a body" cannot
 *    be confused with "200 with a playable segment".
 *
 * ## Reading the refusals
 *
 * Every refusal below sits beside a succeeding control, and each is TIMED
 * against {@see self::MAX_WAIT_MS} — the segment wait ceiling this manager was
 * constructed with. A 404 that took ~90 s would be the encode timing out (i.e. a
 * broken producer); a 404 that takes milliseconds is the producer DECIDING it
 * cannot make that file. The assertions say which one happened.
 */
final class DashOnDemandServeTest extends TestCase
{
    private const FFMPEG = '/usr/bin/ffmpeg';
    private const FFPROBE = '/usr/bin/ffprobe';

    private const JOB_ID = 's59-job';
    private const DURATION = 8.0;
    private const SEGMENT_SECONDS = 4;

    /**
     * The segment wait ceiling handed to the manager. A refusal bounded by THIS
     * is a timeout, not a decision — the timing assertions below separate them.
     */
    private const MAX_WAIT_MS = 90_000;

    private string $root = '';
    private string $dir = '';
    private TranscodeManager $manager;
    private DashController $controller;

    protected function setUp(): void
    {
        if (!is_executable(self::FFMPEG) || !is_executable(self::FFPROBE)) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }

        $this->root = sys_get_temp_dir() . '/phlix_s59_it_' . uniqid();
        mkdir($this->root, 0755, true);
        $this->dir = $this->root . '/' . self::JOB_ID;
        mkdir($this->dir, 0755, true);

        $clip = $this->makeClip();
        $this->manager = $this->makeManager($clip);
        // The controller is constructed exactly as Application::getDashController()
        // does: the shared HLS segment dir plus the container's TranscodeManager.
        $this->controller = new DashController($this->root, $this->manager);

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
     * The premise: a freshly written job has a manifest and NOT ONE segment.
     *
     * Asserted as its own case so that if S58's writer ever starts producing
     * segments up front, this file fails HERE — loudly — instead of every other
     * case below quietly degrading into "served a file that was already there".
     */
    public function testTheFreshJobHasAManifestAndZeroSegments(): void
    {
        $this->assertFileExists($this->dir . '/' . TranscodeManager::MPD_FILENAME);
        $this->assertSame(
            [],
            $this->m4sFiles(),
            'the AC is "a job with no existing segments" — this fixture must start with none'
        );
    }

    /**
     * The AC, end to end, for the first two bytes a DASH client ever fetches.
     *
     * A DASH client reads `SegmentTemplate@initialization` FIRST. Before S59 that
     * request 404'd and no encode was ever triggered, so the presentation was
     * unreachable from its own first request. Here it must produce the init, and
     * the init must be a real fMP4 header (`ftyp` + `moov`, no media).
     */
    public function testTheInitSegmentIsEncodedOnDemandAndServedAsRealFmp4(): void
    {
        $this->assertFileDoesNotExist($this->dir . '/init-v240p.m4s');

        $response = $this->get('init-v240p.m4s');

        $this->assertSame(200, $response->statusCode, $this->ffmpegLog());
        $this->assertSame('video/mp4', $response->headers['Content-Type']);
        $bytes = $this->bodyOf($response);
        $this->assertNotSame('', $bytes);
        $this->assertFileExists($this->dir . '/init-v240p.m4s', 'the encode must have published the init');

        // An init is `ftyp` + `moov` and carries no media. Box type lives at
        // bytes 4..8 of the first box.
        $this->assertSame('ftyp', substr($bytes, 4, 4), 'an init segment must open with ftyp');
        $this->assertStringContainsString('moov', substr($bytes, 0, 512));
        $this->assertStringNotContainsString('moof', $bytes, 'an init carries no media fragment');
    }

    /**
     * The other half: a media segment, produced on demand and playable when
     * concatenated onto its init — which is the only way a bare CMAF fragment
     * can be decoded at all (S56 split them deliberately).
     */
    public function testAMediaSegmentIsEncodedOnDemandAndDecodesAgainstItsInit(): void
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
        exec(
            self::FFPROBE . ' -v error -show_streams -of json ' . escapeshellarg($alone) . ' 2>&1',
            $out,
            $status
        );
        $this->assertNotSame(0, $status, 'a bare fragment without its init must not probe as a stream');
    }

    /**
     * The audio AdaptationSets resolve through the same trigger, with the OTHER
     * argument shape (`ensureSegment($job, null, $index, 'a{N}')`). A controller
     * that routed everything through the video arm would 404 here.
     */
    public function testAnAudioRenditionIsAlsoEncodedOnDemand(): void
    {
        $init = $this->bodyOf($this->assertOk($this->get('init-a1.m4s')));
        $segment = $this->bodyOf($this->assertOk($this->get('seg-a1-00001.m4s')));

        $joined = $this->root . '/joined-audio.mp4';
        file_put_contents($joined, $init . $segment);
        $probe = $this->ffprobeJson($joined);
        $streams = is_array($probe['streams'] ?? null) ? $probe['streams'] : [];

        $this->assertNotSame([], $streams);
        $this->assertSame('aac', $streams[0]['codec_name'] ?? null);
        // The SECOND audio track of the clip is the 880 Hz tone; asserting it is
        // audio-only is what shows `a1` did not silently resolve to the video arm.
        $this->assertSame('audio', $streams[0]['codec_type'] ?? null);
        $this->assertCount(1, $streams);
    }

    /**
     * The whole presentation, driven by a reference DASH client over bytes THIS
     * CONTROLLER served.
     *
     * Every reference in the manifest is fetched through
     * {@see DashController::serveFile()} into a mirror directory — so what
     * `ffmpeg -i manifest.mpd` then reads is the controller's output, not the
     * producer's on-disk output. That distinction is the entire point of S59:
     * S58 already proved the files are right, and could not prove they are
     * reachable.
     */
    public function testAReferenceDashClientPlaysAPresentationServedEntirelyByTheController(): void
    {
        $mirror = $this->root . '/served';
        mkdir($mirror, 0755, true);

        $references = $this->manifestReferences();
        $this->assertGreaterThanOrEqual(
            12,
            count($references),
            'denominator: 2 video rungs + 2 audio tracks, each an init plus two segments'
        );

        foreach ($references as $reference) {
            $response = $this->get($reference);
            $this->assertSame(200, $response->statusCode, "the controller refused {$reference}. " . $this->ffmpegLog());
            $bytes = $this->bodyOf($response);
            $this->assertNotSame('', $bytes, "{$reference} served zero bytes");
            file_put_contents("{$mirror}/{$reference}", $bytes);
        }

        // The manifest too, through the controller.
        $manifest = $this->bodyOf($this->assertOk($this->get(TranscodeManager::MPD_FILENAME)));
        file_put_contents("{$mirror}/" . TranscodeManager::MPD_FILENAME, $manifest);

        $out = $this->root . '/served-remux.mp4';
        $log = [];
        $status = 0;
        exec(
            self::FFMPEG . ' -nostdin -y -i ' . escapeshellarg("{$mirror}/" . TranscodeManager::MPD_FILENAME)
            . ' -map 0 -c copy ' . escapeshellarg($out) . ' 2>&1',
            $log,
            $status
        );

        $this->assertSame(0, $status, "the DASH demuxer refused the SERVED presentation:\n" . implode("\n", $log));
        $probe = $this->ffprobeJson($out);
        $streams = is_array($probe['streams'] ?? null) ? $probe['streams'] : [];
        $this->assertCount(4, $streams, 'two video rungs and two audio tracks must all have been served');
        $this->assertSame(
            ['h264', 'h264', 'aac', 'aac'],
            array_map(static fn (array $s): string => (string) ($s['codec_name'] ?? ''), $streams)
        );
        $this->assertEqualsWithDelta(
            self::DURATION,
            (float) ($probe['format']['duration'] ?? 0.0),
            0.35,
            'the remux must span every segment, not just the first'
        );
    }

    /**
     * A DASH miss the producer cannot satisfy is a DECISION, measured against a
     * succeeding control and against the wait ceiling.
     *
     * Two refusals of different kinds are timed: a rung that is not in the
     * ladder, and an index past the end of the presentation. Both must answer in
     * a small fraction of {@see self::MAX_WAIT_MS}; a refusal that cost the
     * ceiling would mean the encode was launched and timed out, which is a
     * different (and much worse) failure wearing the same 404.
     */
    public function testRefusalsAre404DecisionsNotTimeouts(): void
    {
        // Succeeding control FIRST, so a "fast 404" cannot simply mean nothing
        // in this fixture ever does any work.
        $startOk = hrtime(true);
        $ok = $this->get('seg-v240p-00000.m4s');
        $okMs = (hrtime(true) - $startOk) / 1_000_000.0;
        $this->assertSame(200, $ok->statusCode, $this->ffmpegLog());
        $this->assertGreaterThan(0.0, $okMs);

        $refusals = [
            'seg-v9999p-00000.m4s' => 'unknown rung',
            'seg-v240p-00099.m4s' => 'index past the end',
        ];
        foreach ($refusals as $file => $why) {
            $start = hrtime(true);
            $response = $this->get($file);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;

            $this->assertSame(404, $response->statusCode, "{$why} must 404");
            /** @var array<string, mixed> $body */
            $body = json_decode($response->body, true);
            $this->assertSame('segment unavailable', $body['error']);
            $this->assertLessThan(
                self::MAX_WAIT_MS / 10.0,
                $elapsedMs,
                "the {$why} 404 took {$elapsedMs}ms against a " . self::MAX_WAIT_MS
                . 'ms wait ceiling — that is a TIMEOUT, not a decision'
            );
            $this->assertFileDoesNotExist($this->dir . '/' . $file, 'a refused segment must not be published');
        }
    }

    /**
     * A SWEPT job directory regenerates its manifest on the next request — the
     * DASH peer of the HLS playlist miss-recovery.
     *
     * The sweep is modelled the way it really happens: `sweepSegmentCache()`
     * deletes the whole job DIRECTORY (idle TTL / LRU budget), never one file out
     * of it. That matters for the next case.
     */
    public function testASweptJobDirectoryRegeneratesItsManifestOnRequest(): void
    {
        $before = (string) file_get_contents($this->dir . '/' . TranscodeManager::MPD_FILENAME);
        self::rrmdir($this->dir);
        $this->assertDirectoryDoesNotExist($this->dir);

        $response = $this->assertOk($this->get(TranscodeManager::MPD_FILENAME));

        $this->assertSame('application/dash+xml', $response->headers['Content-Type']);
        $this->assertSame('no-cache', $response->headers['Cache-Control']);
        $this->assertSame($before, $this->bodyOf($response), 'the regenerated manifest must be the same manifest');
    }

    /**
     * ⚠ RECORDED LIMITATION, not an aspiration: deleting ONLY `manifest.mpd` and
     * leaving `master.m3u8` behind is NOT recovered.
     *
     * {@see TranscodeManager::ensurePlaylistRegenerated()} short-circuits on
     * `is_file("{$dir}/master.m3u8")` — a pre-S58 early return that predates there
     * being a manifest to regenerate. S59 does not touch it, because the state it
     * misses cannot arise from the sweep (which removes the directory as a unit,
     * as the case above models); it can only arise from a hand-deleted file or a
     * swallowed MPD write error at job creation.
     *
     * This is pinned rather than left implicit so that the day someone widens that
     * early return, a test says so instead of a behaviour silently changing.
     */
    public function testAManifestDeletedOnItsOwnIsNotRegenerated(): void
    {
        unlink($this->dir . '/' . TranscodeManager::MPD_FILENAME);
        $this->assertFileExists($this->dir . '/master.m3u8', 'the short-circuit this pins needs master.m3u8 present');

        $response = $this->get(TranscodeManager::MPD_FILENAME);

        $this->assertSame(404, $response->statusCode);
        $this->assertFileDoesNotExist($this->dir . '/' . TranscodeManager::MPD_FILENAME);
    }

    /**
     * `dash_url` is advertised only for a job whose manifest actually exists.
     *
     * The negative half of the S11 restoration, over the real manager: sweep the
     * job dir and the URL disappears; it comes back once the writer restores the
     * file. A `dash_url` derived from the FLAG rather than from the file would
     * answer the same in both states, so this is what makes the gate falsifiable.
     */
    public function testTheAdvertisedDashUrlTracksTheManifestsExistence(): void
    {
        $this->assertSame(
            '/dash/' . self::JOB_ID . '/' . TranscodeManager::MPD_FILENAME,
            $this->manager->dashManifestUrl(self::JOB_ID)
        );

        self::rrmdir($this->dir);
        $this->assertNull($this->manager->dashManifestUrl(self::JOB_ID));

        $this->assertTrue($this->manager->ensurePlaylistRegenerated(self::JOB_ID));
        $this->assertSame(
            '/dash/' . self::JOB_ID . '/' . TranscodeManager::MPD_FILENAME,
            $this->manager->dashManifestUrl(self::JOB_ID)
        );
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

    /** @return list<string> Every `.m4s` currently in the job dir, sorted. */
    private function m4sFiles(): array
    {
        $found = array_map('basename', glob($this->dir . '/*.m4s') ?: []);
        sort($found);

        return array_values($found);
    }

    /**
     * Every filename the manifest's `SegmentTemplate`s expand to.
     *
     * Deliberately expanded here with a tiny independent expander rather than
     * read off the producer, so the list is what a CLIENT would ask for.
     *
     * @return list<string>
     */
    private function manifestReferences(): array
    {
        $doc = new \DOMDocument();
        $this->assertTrue($doc->load($this->dir . '/' . TranscodeManager::MPD_FILENAME));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('m', 'urn:mpeg:dash:schema:mpd:2011');

        $total = (float) substr(
            (string) $doc->documentElement?->getAttribute('mediaPresentationDuration'),
            2,
            -1
        );
        $this->assertGreaterThan(0.0, $total);

        $out = [];
        foreach ($xpath->query('/m:MPD/m:Period/m:AdaptationSet') ?: [] as $set) {
            $template = $xpath->query('./m:SegmentTemplate', $set)?->item(0);
            $this->assertInstanceOf(\DOMElement::class, $template);
            $timescale = (float) $template->getAttribute('timescale');
            $this->assertGreaterThan(0.0, $timescale);
            $seconds = (float) $template->getAttribute('duration') / $timescale;
            $this->assertGreaterThan(0.0, $seconds);
            $start = (int) $template->getAttribute('startNumber');
            $count = (int) ceil($total / $seconds);

            foreach ($xpath->query('./m:Representation', $set) ?: [] as $rep) {
                $this->assertInstanceOf(\DOMElement::class, $rep);
                $id = $rep->getAttribute('id');
                $out[] = self::expand($template->getAttribute('initialization'), $id, 0);
                for ($n = $start; $n < $start + $count; $n++) {
                    $out[] = self::expand($template->getAttribute('media'), $id, $n);
                }
            }
        }

        return $out;
    }

    private static function expand(string $template, string $representationId, int $number): string
    {
        return (string) preg_replace_callback(
            '/\$Number%0(\d+)d\$/',
            static fn (array $m): string => sprintf('%0' . $m[1] . 'd', $number),
            str_replace('$RepresentationID$', $representationId, $template)
        );
    }

    /** A real fMP4 multi-rung, multi-audio job row, as a live one carries it. */
    private function makeManager(string $clip): TranscodeManager
    {
        $row = [
            'id' => self::JOB_ID,
            'hls_dir' => $this->dir,
            'input_path' => $clip,
            'status' => 'completed',
            'duration_seconds' => (int) self::DURATION,
            'segment_seconds' => self::SEGMENT_SECONDS,
            'segment_params' => json_encode([
                'video_codec' => 'libx264',
                'preset' => 'ultrafast',
                'crf' => 30,
                'audio_codec' => 'aac',
                'segment_format' => 'fmp4',
            ]),
            'variants' => json_encode([
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
            ]),
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

    /** A short clip with TWO audio tracks, so the audio AdaptationSets are real. */
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

    private function ffmpegLog(): string
    {
        $path = $this->dir . '/ffmpeg-segments.log';

        return is_file($path) ? "ffmpeg log:\n" . (string) file_get_contents($path) : '(no ffmpeg log)';
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
            . ':format=duration -of json ' . escapeshellarg($path),
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
