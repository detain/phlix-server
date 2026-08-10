<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Transcoding;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Tests\Support\Dash\MpdSchema;
use Workerman\MySQL\Connection;

/**
 * S58 acceptance, second half — real ffmpeg, real bytes.
 *
 * The AC is "the generated `manifest.mpd` validates against the MPD schema /
 * a reference DASH validator, and its references resolve to real segment
 * paths". A unit test can expand a `SegmentTemplate` and compare it to a
 * playlist string, but it cannot say whether either names a file that ffmpeg
 * will ever write. So this test:
 *
 *  1. drives the REAL `TranscodeManager::ensurePlaylistRegenerated()` — one of
 *     the writer's two production callers — over a real source clip, so the
 *     manifest under test is the one production writes;
 *  2. produces EVERY segment the manifest names through the real
 *     `ensureSegment()` → detached CMAF publish chain (real ffmpeg);
 *  3. expands every `SegmentTemplate` the way a client does and requires each
 *     expansion to be a file that exists, with a **control** showing that a
 *     plausible but wrong template resolves to a file that does not;
 *  4. hands the manifest to a **reference DASH client** — ffmpeg's own `dash`
 *     demuxer — and requires it to resolve the templates, concatenate
 *     init + fragments and remux the whole presentation.
 *
 * Point 4 is what the blueprint nominates in place of a device lab: there is no
 * DASH player in `phlix-ui` at all, so `ffmpeg -i manifest.mpd` is the only
 * independent implementation of MPD parsing available here.
 */
final class VodMpdSegmentResolutionTest extends TestCase
{
    private const FFMPEG = '/usr/bin/ffmpeg';
    private const FFPROBE = '/usr/bin/ffprobe';
    private const NS = 'urn:mpeg:dash:schema:mpd:2011';

    private const JOB_ID = 's58-job';
    private const DURATION = 8.0;
    private const SEGMENT_SECONDS = 4;

    /** Built once (real encodes), reused by every case; removed in tearDownAfterClass. */
    private static ?string $root = null;

    protected function setUp(): void
    {
        if (!is_executable(self::FFMPEG) || !is_executable(self::FFPROBE)) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$root !== null && is_dir(self::$root)) {
            self::rrmdir(self::$root);
        }
        self::$root = null;
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * The manifest production actually writes, against the real schema.
     *
     * The unit suite validates manifests built from synthetic Renditions; this
     * one is built from a persisted `variants` blob decoded by
     * `renditionFromArray()`, which is the shape a live job carries.
     */
    public function testTheManifestProductionWritesValidatesAgainstTheDashSchema(): void
    {
        $dir = $this->job();
        $xml = (string) file_get_contents("{$dir}/" . TranscodeManager::MPD_FILENAME);

        $this->assertNotSame('', $xml);
        $errors = MpdSchema::errors($xml);
        $this->assertSame([], $errors, implode("\n", $errors));
    }

    /**
     * Every template expansion is a file on disk — and a wrong one is not.
     *
     * The control matters more than the assertion: an expander that produced no
     * candidates at all, or that resolved to the directory rather than a file,
     * would report "nothing missing" and read exactly like a pass. So the same
     * resolver is run against a deliberately corrupted template (the audio
     * naming applied to a video rendition — the exact confusion
     * `initSegmentFileName()` exists to prevent, and S57's mutation M9) and must
     * come back with EVERY reference missing.
     */
    public function testEverySegmentTemplateReferenceResolvesToAFileThatExists(): void
    {
        $dir = $this->job();

        $resolved = $this->resolveAll($dir, null);
        $this->assertGreaterThanOrEqual(
            12,
            count($resolved),
            'denominator: 2 video rungs + 2 audio tracks, each an init plus two segments'
        );
        $this->assertSame([], $this->missing($dir, $resolved), 'manifest references that do not exist on disk');

        // Control: init-v{id}.m4s → init-{id}.m4s, i.e. a video rendition
        // pointed at the audio naming scheme.
        $wrong = $this->resolveAll($dir, static fn (string $t): string => str_replace('init-v$', 'init-$', $t));
        $missing = $this->missing($dir, $wrong);
        $this->assertSame(
            ['init-144p.m4s', 'init-240p.m4s'],
            array_values(array_unique(array_intersect($missing, ['init-144p.m4s', 'init-240p.m4s']))),
            'the file-existence check must be able to FAIL — a wrong init template resolves to nothing'
        );
    }

    /**
     * A reference DASH client reads the manifest end to end.
     *
     * `ffmpeg -i manifest.mpd -map 0 -c copy` exercises an independent MPD
     * parser, `$RepresentationID$`/`$Number%05d$` substitution, `startNumber`,
     * and the init + fragment concatenation that S56's bare-fragment split
     * depends on. Nothing about this path shares code with the writer.
     */
    public function testFfmpegsDashDemuxerPlaysTheWholePresentation(): void
    {
        $dir = $this->job();
        $out = $dir . '/../dash-remux.mp4';

        $log = [];
        $status = 0;
        exec(
            self::FFMPEG . ' -nostdin -y -i ' . escapeshellarg("{$dir}/" . TranscodeManager::MPD_FILENAME)
            . ' -map 0 -c copy ' . escapeshellarg($out) . ' 2>&1',
            $log,
            $status
        );

        $this->assertSame(0, $status, "the DASH demuxer refused the manifest:\n" . implode("\n", $log));
        $this->assertFileExists($out);

        $probe = $this->ffprobeJson($out);
        $streams = is_array($probe['streams'] ?? null) ? $probe['streams'] : [];
        $this->assertCount(4, $streams, 'two video rungs and two audio tracks must all have been resolved');
        $this->assertSame(
            ['h264', 'h264', 'aac', 'aac'],
            array_map(static fn (array $s): string => (string) ($s['codec_name'] ?? ''), $streams)
        );
        $this->assertSame(
            [[320, 240], [192, 144]],
            [
                [(int) ($streams[0]['width'] ?? 0), (int) ($streams[0]['height'] ?? 0)],
                [(int) ($streams[1]['width'] ?? 0), (int) ($streams[1]['height'] ?? 0)],
            ],
            'the ladder came back in manifest order, so the demuxer used our Representation ids'
        );

        // The WHOLE presentation, not just its first segment: a demuxer that
        // stopped after segment 0 would still exit 0 with a valid file.
        $this->assertEqualsWithDelta(
            self::DURATION,
            (float) ($probe['format']['duration'] ?? 0.0),
            0.35,
            'the remux must span every segment the template expands to, not just the first'
        );
    }

    /**
     * ffmpeg detects the end of a `@duration`-templated presentation by asking
     * for one segment past the last and failing — recorded here so that log
     * line is never mistaken for a defect. The verdict is the EXIT CODE and the
     * remuxed duration above, not the absence of warnings.
     */
    public function testTheDemuxerOverrunsByExactlyOneSegmentAndStillSucceeds(): void
    {
        $dir = $this->job();

        $log = [];
        $status = 0;
        exec(
            self::FFMPEG . ' -nostdin -v verbose -y -i '
            . escapeshellarg("{$dir}/" . TranscodeManager::MPD_FILENAME)
            . ' -map 0 -c copy -f null - 2>&1',
            $log,
            $status
        );
        $text = implode("\n", $log);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('seg-v240p-00001.m4s', $text, 'the LAST real segment was requested');
        $this->assertStringNotContainsString(
            'seg-v240p-00002.m4s',
            $text,
            'the video sets stop at the last real index'
        );
        $this->assertMatchesRegularExpression(
            '/seg-a\d-00002\.m4s/',
            $text,
            'the one-past-the-end probe is how ffmpeg finds the end of a @duration template'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // fixture
    // ─────────────────────────────────────────────────────────────────

    /**
     * Builds (once) a real fMP4 job: playlists + manifest through the real
     * writer, then every segment the manifest names through real ffmpeg.
     *
     * @return string The job directory.
     */
    private function job(): string
    {
        if (self::$root !== null) {
            return self::$root . '/' . self::JOB_ID;
        }

        $root = sys_get_temp_dir() . '/phlix_s58_it_' . uniqid();
        mkdir($root, 0755, true);
        $dir = "{$root}/" . self::JOB_ID;
        mkdir($dir, 0755, true);

        $clip = $this->makeClip($root);
        $manager = $this->manager($root, $dir, $clip);

        $this->assertTrue(
            $manager->ensurePlaylistRegenerated(self::JOB_ID),
            'the production writer did not produce the job directory'
        );
        $this->assertFileExists("{$dir}/" . TranscodeManager::MPD_FILENAME);

        // Produce every segment the manifest names, through the real producer.
        $total = (int) ceil(self::DURATION / self::SEGMENT_SECONDS);
        foreach ([['240p', false], ['144p', false], ['a0', true], ['a1', true]] as [$id, $isAudio]) {
            for ($i = 0; $i < $total; $i++) {
                $path = $isAudio
                    ? $manager->ensureSegment(self::JOB_ID, null, $i, $id)
                    : $manager->ensureSegment(self::JOB_ID, $id, $i);
                $this->assertIsString(
                    $path,
                    "segment {$i} of {$id} was not produced. ffmpeg log: "
                    . (is_file("{$dir}/ffmpeg-segments.log")
                        ? (string) file_get_contents("{$dir}/ffmpeg-segments.log")
                        : '(none)')
                );
            }
        }

        self::$root = $root;

        return $dir;
    }

    private function manager(string $root, string $dir, string $clip): TranscodeManager
    {
        $row = [
            'id' => self::JOB_ID,
            'hls_dir' => $dir,
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
            new FfmpegRunner(self::FFMPEG, self::FFPROBE, $root),
            $root,
            null,
            self::SEGMENT_SECONDS,
            null,
            null,
            null,
            null,
            null,
            null,
            90_000 // real encodes; give the poll room
        );
    }

    /** A short clip with TWO audio tracks, so the audio AdaptationSets are real. */
    private function makeClip(string $root): string
    {
        $clip = "{$root}/source.mkv";
        $seconds = (int) self::DURATION;
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

    // ─────────────────────────────────────────────────────────────────
    // the resolver (written independently of the writer)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Expands every AdaptationSet's template over every Representation and
     * index, the way a client does.
     *
     * @param (callable(string): string)|null $corrupt Applied to each raw template first (control).
     *
     * @return list<string> Relative filenames.
     */
    private function resolveAll(string $dir, ?callable $corrupt): array
    {
        $doc = new DOMDocument();
        $this->assertTrue($doc->load("{$dir}/" . TranscodeManager::MPD_FILENAME));
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('m', self::NS);

        $total = (float) substr(
            (string) $doc->documentElement?->getAttribute('mediaPresentationDuration'),
            2,
            -1
        );
        $this->assertGreaterThan(0.0, $total, 'a zero presentation length would expand to zero references');

        $out = [];
        foreach ($xpath->query('/m:MPD/m:Period/m:AdaptationSet') ?: [] as $set) {
            $this->assertInstanceOf(DOMElement::class, $set);
            $template = $xpath->query('./m:SegmentTemplate', $set)?->item(0);
            $this->assertInstanceOf(DOMElement::class, $template);

            $timescale = (float) $template->getAttribute('timescale');
            $this->assertGreaterThan(0.0, $timescale);
            $segment = (float) $template->getAttribute('duration') / $timescale;
            $this->assertGreaterThan(0.0, $segment);
            $start = (int) $template->getAttribute('startNumber');
            $count = (int) ceil($total / $segment);

            $media = $template->getAttribute('media');
            $init = $template->getAttribute('initialization');
            if ($corrupt !== null) {
                $media = $corrupt($media);
                $init = $corrupt($init);
            }

            foreach ($xpath->query('./m:Representation', $set) ?: [] as $rep) {
                $this->assertInstanceOf(DOMElement::class, $rep);
                $id = $rep->getAttribute('id');
                $out[] = $this->expand($init, $id, 0);
                for ($n = $start; $n < $start + $count; $n++) {
                    $out[] = $this->expand($media, $id, $n);
                }
            }
        }

        return $out;
    }

    /**
     * @param list<string> $references
     *
     * @return list<string>
     */
    private function missing(string $dir, array $references): array
    {
        $missing = [];
        foreach ($references as $reference) {
            if (!is_file("{$dir}/{$reference}")) {
                $missing[] = $reference;
            }
        }
        sort($missing);

        return $missing;
    }

    private function expand(string $template, string $representationId, int $number): string
    {
        $expanded = str_replace('$RepresentationID$', $representationId, $template);

        return (string) preg_replace_callback(
            '/\$Number%0(\d+)d\$/',
            static fn (array $m): string => sprintf('%0' . $m[1] . 'd', $number),
            $expanded
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function ffprobeJson(string $path): array
    {
        exec(
            self::FFPROBE . ' -v error -show_entries stream=index,codec_name,width,height'
            . ':format=duration -of json ' . escapeshellarg($path),
            $lines,
            $status
        );
        $this->assertSame(0, $status, 'ffprobe refused the remuxed file');
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
