<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\StreamProbeBackfill;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * How the scanner treats a DEGENERATE ffprobe "video" stream — one whose own
 * description is not self-consistent: no `codec_name`, a dimension that cannot
 * describe a frame (≤ 0 or absent), or an aspect ratio outside anything the
 * measured library holds.
 *
 * ## Two separate questions, and only one of them may say "no"
 *
 * 1. **May this stream define `metadata_json['source']`?** A stream with
 *    implausible GEOMETRY may not: production carries 10 rows reading `1920x0`,
 *    and handing that to `SourceProfile` clamps every rung of the ABR ladder to
 *    zero height. A stream that merely names no codec MAY, because its
 *    width/height are still the file's real dimensions.
 * 2. **May this stream be persisted as a `media_streams` row?** No defect can
 *    cost a stream its row. The only reason a video stream loses its row is the
 *    pre-existing `disposition.attached_pic` cover-art skip — and that skip is
 *    unchanged from master, including for a poster that answers question 1.
 *
 * ## The row set is master's row set, exactly
 *
 * The rule for (2) is `MediaScanner::coverArtLosesItsRow()`, and it is master's:
 * a flagged poster keeps its row only when the file has NO non-poster video
 * stream. Winning the `source` role does not earn one. An earlier revision
 * coupled the two, so a poster in front of a geometry-defective content track
 * gained a row master never wrote — and because
 * {@see StreamProbeBackfill::videoCodecMissing()} takes the first codec-bearing
 * video row, that row answered "codec known", `ensureVideoCodecFor()`
 * short-circuited, and the item became permanently unrepairable. That A/B is
 * pinned by `testAPromotedPosterNeverGainsARowMasterWouldNotHaveWritten()`.
 *
 * ## Why (2) is unconditional — this is a fixed regression, not a preference
 *
 * An earlier revision of this change DID drop the row, keeping only "the last
 * video row" via the promoted-`$video` escape hatch. That hatch guarantees *at
 * least one video row survives*, NOT *the real video track's row survives*: the
 * primary-`$video` loop takes the first NON-degenerate stream, so the moment a
 * file carries any second plausible video-type stream (an unflagged poster is
 * enough) the escape hatch never fires and the file's actual content track is
 * dropped. Reproduced on real files with real ffprobe on ffmpeg 6.1.1:
 *
 *     poster_vvc1.mp4   idx0 codec_type=video codec_name=ABSENT 320x240   <- content
 *                       idx1 codec_type=video codec_name=mjpeg  600x900   <- poster
 *
 *     master   rows: [video 0 (NULL codec), video 1 (mjpeg), audio 2]
 *                    source {"width":320,"height":240,"video_codec":null}
 *     rejecting rows: [video 1 (mjpeg), audio 2]          <- content track GONE
 *                    source {"width":600,"height":900,"video_codec":"mjpeg"}
 *
 * A `codec_type=video` stream with an ABSENT `codec_name` and valid dimensions is
 * precisely what ffmpeg reports for a sample-entry fourcc the installed build
 * cannot map: `vvc1` (H.266), `apv1`, and EVERY Common-Encryption `encv`
 * (Widevine / PlayReady) or FairPlay `drmi` MP4. `codec_name='h264'` at `0x0` is
 * what an MPEG-TS joined mid-GOP reports — an in-progress DVR recording or a
 * partial download. Those are content tracks. The row is the only record that the
 * track exists at all, plus its dimensions, bitrate, language and color metadata.
 *
 * A junk row, by contrast, is inert: every reader that asks "what is this item's
 * video codec?" already skips a blank `codec` (the SPA's
 * `videoCodecFromStreams()`, {@see StreamProbeBackfill::videoCodecMissing()}) and
 * `ItemRepository::getVideoStreamColorMetadata()` reads the LOWEST `stream_index`.
 * A dropped row, meanwhile, is not recoverable: nothing re-probes an item that
 * already carries a duration, a `source` and a `streams_probed_at` stamp, so its
 * absence is permanent and indistinguishable from "ffprobe never reported that
 * stream". Preserving the row therefore outranks not storing a junk one.
 *
 * ## The measured defect this change is still answering
 *
 * Production 2026-07-28: 18 `media_streams` rows across 14 items with
 * `stream_type='video'` and `codec IS NULL`, all at `stream_index` 2 or 3, all
 * next to a real video row at index 0 (`hevc` x10, `h264` x3, `mpeg4` x1):
 *
 *     10 x  1920 x 0     bitrate  82-131  language 'eng'
 *      4 x     4 x 3841  bitrate  80-144  language NULL
 *      4 x     4 x 5634  bitrate  64-128  language NULL
 *
 * Those 18 rows are NOT deleted here, and no cleanup migration ships with this
 * change. The only predicate that identifies them — "a `video` row with a blank
 * codec, on an item that also has a `video` row WITH a codec" — matches the
 * `encv`/`drmi`/`vvc1` content track of every file that carries an unflagged
 * poster or a second video stream, i.e. exactly the rows this test file exists to
 * protect. Proved on MySQL 8.0.46 against rows `summarizeProbe()` really emitted
 * for such a file: the DELETE took the 320x240 content track and left the 600x900
 * poster. What ships instead is
 * {@see MediaScanner::logDegenerateVideoStreams()}, so an operator can SEE the
 * junk rows and decide, and a one-off DELETE against a measured library stays
 * available to them.
 */
final class MediaScannerDegenerateVideoStreamTest extends TestCase
{
    /** Absolute path of the temp log file the logging cases capture into. */
    private string $logFile = '';
    /** Absolute path of the temp logger config the logging cases install. */
    private string $logConfig = '';

    protected function tearDown(): void
    {
        // Always hand the process back a logger pointed at the real config, the
        // state every other suite member expects.
        if ($this->logFile !== '') {
            @unlink($this->logFile);
            $this->logFile = '';
        }
        if ($this->logConfig !== '') {
            @unlink($this->logConfig);
            $this->logConfig = '';
        }
        LoggerFactory::reset();
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    /**
     * Invoke the public static `MediaScanner::summarizeProbe()`.
     *
     * @param array<string, mixed> $probe
     *
     * @return array{
     *     duration_seconds: int|null,
     *     source: array<string, mixed>|null,
     *     streams: array<int, array<string, mixed>>
     * }
     */
    private function summarize(array $probe): array
    {
        /** @var array{duration_seconds: int|null, source: array<string, mixed>|null, streams: array<int, array<string, mixed>>} $result */
        $result = MediaScanner::summarizeProbe($probe);

        return $result;
    }

    /**
     * Invoke the private static `MediaScanner::videoStreamDefects()`.
     *
     * @return list<string>
     */
    private function defects(mixed $stream): array
    {
        $method = new ReflectionMethod(MediaScanner::class, 'videoStreamDefects');
        $method->setAccessible(true);
        /** @var list<string> $defects */
        $defects = $method->invoke(null, $stream);

        return $defects;
    }

    /**
     * Invoke the private static `MediaScanner::defectsIncludeGeometry()`.
     *
     * @param list<string> $defects
     */
    private function includesGeometry(array $defects): bool
    {
        $method = new ReflectionMethod(MediaScanner::class, 'defectsIncludeGeometry');
        $method->setAccessible(true);

        return (bool) $method->invoke(null, $defects);
    }

    /** Invoke the private static `StreamProbeBackfill::videoCodecMissing()` — the REAL repair trigger. */
    private function videoCodecMissing(array $streams): bool
    {
        $method = new ReflectionMethod(StreamProbeBackfill::class, 'videoCodecMissing');
        $method->setAccessible(true);

        return (bool) $method->invoke(null, $streams);
    }

    /**
     * `[stream_type, stream_index]` for every persisted row, in emitted order.
     *
     * @param array<int, array<string, mixed>> $streams
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function rowKeys(array $streams): array
    {
        return array_values(array_map(
            static fn (array $row): array => [$row['stream_type'] ?? null, $row['stream_index'] ?? null],
            $streams
        ));
    }

    /**
     * Route the MEDIA channel into a temp file so the emitted records can be
     * read back. Returns the file path.
     */
    private function captureLog(): string
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'phlix_degen_log_') ?: '';
        $this->assertNotSame('', $this->logFile, 'could not create a temp log file');
        $this->logConfig = $this->logFile . '.php';
        file_put_contents($this->logConfig, '<?php return ' . var_export([
            'handlers' => [
                'capture' => ['type' => 'stream', 'path' => $this->logFile, 'level' => 'debug'],
            ],
        ], true) . ';');

        LoggerFactory::reset();
        LoggerFactory::init($this->logConfig);

        return $this->logFile;
    }

    // ------------------------------------------------------------------
    // The two measured production shapes
    // ------------------------------------------------------------------

    /**
     * The exact production shape (10 of the 18 rows): real `hevc` at index 0,
     * audio at 1, a codec-less `1920x0` at 2.
     *
     * The junk row keeps its row — cleaning it up is an operator's call against
     * a measured library, not the scanner's — and the point of the fix is that
     * the real `hevc` stream, not the `1920x0` header, defines `source`. Which it
     * already did on this shape
     * (the junk sits at index 2, so "first video stream" picked index 0 anyway);
     * the assertion is here so a future change to the selection order cannot
     * silently regress the measured case.
     */
    public function testProductionShapeAKeepsEveryRowAndTheRealSourceDescriptor(): void
    {
        $summary = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'hevc',
                 'width' => 1920, 'height' => 1080, 'bit_rate' => '4000000'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'eac3',
                 'channels' => 6, 'bit_rate' => '640000', 'tags' => ['language' => 'eng']],
                // The junk: ffprobe said "video", named no codec, and reported a
                // height of 0 with a 112 bps rate.
                ['index' => 2, 'codec_type' => 'video',
                 'width' => 1920, 'height' => 0, 'bit_rate' => '112', 'tags' => ['language' => 'eng']],
            ],
            'format' => ['duration' => '5400.0'],
        ]);

        $this->assertSame(
            [['video', 0], ['audio', 1], ['video', 2]],
            $this->rowKeys($summary['streams']),
            'no video row is ever dropped for being degenerate',
        );
        $source = $summary['source'];
        $this->assertIsArray($source);
        $this->assertSame('hevc', $source['video_codec']);
        $this->assertSame(1080, $source['height'], 'a 1920x0 header must not clamp the ABR ladder');
        $this->assertSame(4000000, $source['video_bitrate'], 'not the junk 112 bps');
    }

    /**
     * The other production shape (8 of the 18 rows): TWO codec-less rows at
     * indexes 2 and 3 with `4x3841` / `4x5634` geometry — aspect ratios of
     * 1:960 and 1:1408.
     *
     * Also pins that every row keeps its OWN ffprobe `stream_index` (the
     * subtitle stays at 4). Renumbering would break `StreamTrackShaper`'s
     * `0:s:N` ordinals and the subtitle-extraction endpoint.
     */
    public function testProductionShapeBKeepsBothJunkRowsAndEveryIndex(): void
    {
        $summary = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264',
                 'width' => 1280, 'height' => 720, 'bit_rate' => '3000000'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
                ['index' => 2, 'codec_type' => 'video', 'width' => 4, 'height' => 3841, 'bit_rate' => '144'],
                ['index' => 3, 'codec_type' => 'video', 'width' => 4, 'height' => 5634, 'bit_rate' => '128'],
                ['index' => 4, 'codec_type' => 'subtitle', 'codec_name' => 'subrip',
                 'tags' => ['language' => 'eng']],
            ],
            'format' => [],
        ]);

        $this->assertSame(
            [['video', 0], ['audio', 1], ['video', 2], ['video', 3], ['subtitle', 4]],
            $this->rowKeys($summary['streams']),
        );
        $source = $summary['source'];
        $this->assertIsArray($source);
        $this->assertSame('h264', $source['video_codec']);
        $this->assertSame(720, $source['height']);
    }

    /**
     * A degenerate row is persisted WHOLE — the row is the record that the track
     * existed, so its dimensions, bitrate, language, default flag and color
     * metadata must all survive, not just its existence.
     */
    public function testADegenerateRowKeepsEveryFieldItCarried(): void
    {
        $summary = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'hevc',
                 'width' => 1920, 'height' => 1080],
                ['index' => 1, 'codec_type' => 'video', 'width' => 1920, 'height' => 0,
                 'bit_rate' => '112', 'tags' => ['language' => 'eng', 'title' => 'broken header'],
                 'disposition' => ['default' => 1],
                 'color_space' => 'bt709', 'color_transfer' => 'bt709', 'color_primaries' => 'bt709'],
            ],
            'format' => [],
        ]);

        $row = $summary['streams'][1];
        $this->assertSame(1, $row['stream_index']);
        $this->assertSame('video', $row['stream_type']);
        $this->assertNull($row['codec'], 'stored as NULL — that is the truth about the stream');
        $this->assertSame(1920, $row['width']);
        $this->assertSame(0, $row['height']);
        $this->assertSame(112, $row['bitrate']);
        $this->assertSame('eng', $row['language']);
        $this->assertSame('broken header', $row['title']);
        $this->assertSame(1, $row['is_default']);
        $this->assertSame('bt709', $row['color_space']);
    }

    // ------------------------------------------------------------------
    // THE HOLE: a degenerate content track beside a plausible sibling
    // ------------------------------------------------------------------

    /**
     * The regression the escape hatch did not cover. Every one of these shapes
     * has at least one degenerate video stream AND at least one non-degenerate
     * one, so the promoted-`$video` fallback never fires — which is exactly when
     * the rejecting revision dropped the content track.
     *
     * Asserted as an exact row-key list, so the test fails whether a row is
     * dropped, duplicated or renumbered.
     *
     * @dataProvider mixedVideoStreamShapes
     *
     * @param array<int, array<string, mixed>> $rawStreams
     * @param list<array{0: string, 1: int}>   $expectedRows
     * @param array<string, mixed>             $expectedSource
     */
    public function testEveryVideoStreamKeepsItsRowWhenAPlausibleSiblingExists(
        array $rawStreams,
        array $expectedRows,
        array $expectedSource
    ): void {
        $summary = $this->summarize(['streams' => $rawStreams, 'format' => []]);

        $this->assertSame($expectedRows, $this->rowKeys($summary['streams']));

        $source = $summary['source'];
        $this->assertIsArray($source);
        foreach ($expectedSource as $key => $expected) {
            $this->assertSame($expected, $source[$key] ?? null, sprintf('source[%s]', $key));
        }
    }

    /**
     * Five SHAPES, not five copies of one shape: the degenerate track before the
     * poster and after it, a codec-absent defect and a geometry defect, two
     * degenerate tracks, and a three-video-stream file. Each `poster`-shaped
     * stream deliberately carries NO `attached_pic` flag, because that is the
     * case the old code mishandled — a flagged poster was always excluded by the
     * pre-existing skip and so never displaced anything.
     *
     * @return array<string, array{
     *     0: array<int, array<string, mixed>>,
     *     1: list<array{0: string, 1: int}>,
     *     2: array<string, mixed>
     * }>
     */
    public static function mixedVideoStreamShapes(): array
    {
        $poster = static fn (int $index): array => [
            'index' => $index, 'codec_type' => 'video', 'codec_name' => 'mjpeg',
            'width' => 600, 'height' => 900, 'bit_rate' => '694600',
        ];
        $aac = static fn (int $index): array => [
            'index' => $index, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2,
        ];
        // The real ffprobe shape of a vvc1 / apv1 / encv / drmi content track:
        // codec_type=video, codec_name ABSENT, dimensions correct.
        $unmappedFourcc = static fn (int $index): array => [
            'index' => $index, 'codec_type' => 'video',
            'width' => 320, 'height' => 240, 'bit_rate' => '58472',
        ];

        return [
            // The reviewer's reproduction, verbatim: content first, poster second.
            'codec-absent content BEFORE an unflagged poster' => [
                [$unmappedFourcc(0), $poster(1), $aac(2)],
                [['video', 0], ['video', 1], ['audio', 2]],
                ['width' => 320, 'height' => 240, 'video_codec' => null],
            ],
            // Reversed: the poster wins `source` here on master too, because it
            // is simply the first video stream. Pinned so the row survival is
            // not confused with a change in selection.
            'codec-absent content AFTER an unflagged poster' => [
                [$poster(0), $unmappedFourcc(1), $aac(2)],
                [['video', 0], ['video', 1], ['audio', 2]],
                ['width' => 600, 'height' => 900, 'video_codec' => 'mjpeg'],
            ],
            // The partial-DVR shape: codec PRESENT, geometry 0x0. Its row
            // survives; it does lose `source` to the poster, which is the whole
            // point of the geometry rule — a 0x0 source clamps the ladder.
            'h264 at 0x0 (mid-GOP .ts) BEFORE an unflagged poster' => [
                [
                    ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264',
                     'width' => 0, 'height' => 0, 'bit_rate' => '162555'],
                    $poster(1),
                    $aac(2),
                ],
                [['video', 0], ['video', 1], ['audio', 2]],
                ['width' => 600, 'height' => 900, 'video_codec' => 'mjpeg'],
            ],
            // Three video streams, degenerate first: the codec-absent track
            // still defines `source` (its dimensions are real), and all three
            // rows are kept.
            'codec-absent content, poster, and a second real h264' => [
                [
                    $unmappedFourcc(0),
                    $poster(1),
                    ['index' => 2, 'codec_type' => 'video', 'codec_name' => 'h264',
                     'width' => 320, 'height' => 240],
                    $aac(3),
                ],
                [['video', 0], ['video', 1], ['video', 2], ['audio', 3]],
                ['width' => 320, 'height' => 240, 'video_codec' => null],
            ],
            // TWO degenerate tracks with different defects plus one plausible.
            'a codec-absent AND a 4x5634 track beside a real hevc' => [
                [
                    $unmappedFourcc(0),
                    ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'mpeg4',
                     'width' => 4, 'height' => 5634],
                    ['index' => 2, 'codec_type' => 'video', 'codec_name' => 'hevc',
                     'width' => 1920, 'height' => 1080],
                    $aac(3),
                ],
                [['video', 0], ['video', 1], ['video', 2], ['audio', 3]],
                ['width' => 320, 'height' => 240, 'video_codec' => null],
            ],
        ];
    }

    /**
     * Every fourcc the installed ffmpeg build cannot map — verified on ffmpeg
     * 6.1.1 to probe as `codec_type=video`, `codec_name` ABSENT, dimensions
     * correct — keeps its row and its `source` role next to an unflagged poster.
     *
     * Named individually because these are the CONTENT-LOSS cases with real
     * users behind them: `encv` is any Widevine/PlayReady MP4 and `drmi` is
     * FairPlay, i.e. every DRM title in a library.
     *
     * @dataProvider unmappableFourccs
     */
    public function testAnUnmappableFourccKeepsItsRowAndItsSourceRole(string $fourcc): void
    {
        $summary = $this->summarize([
            'streams' => [
                // codec_name absent is all ffprobe reports; the fourcc only
                // shows up in codec_tag_string, which nothing here reads.
                ['index' => 0, 'codec_type' => 'video', 'codec_tag_string' => $fourcc,
                 'width' => 320, 'height' => 240, 'bit_rate' => '58472'],
                ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'mjpeg',
                 'width' => 600, 'height' => 900],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
            'format' => [],
        ]);

        $this->assertSame(
            [['video', 0], ['video', 1], ['audio', 2]],
            $this->rowKeys($summary['streams']),
            $fourcc . ' is a real content track and must keep its row',
        );
        $source = $summary['source'];
        $this->assertIsArray($source);
        $this->assertSame(320, $source['width'], $fourcc . ' defines the source, not the poster');
        $this->assertSame(240, $source['height']);
        $this->assertNull($source['video_codec'], 'unknown codec is reported as unknown, not as mjpeg');
    }

    /** @return array<string, array{0: string}> */
    public static function unmappableFourccs(): array
    {
        return [
            'vvc1 (H.266/VVC in MP4)' => ['vvc1'],
            'apv1 (APV)' => ['apv1'],
            'encv (Common Encryption: Widevine / PlayReady)' => ['encv'],
            'drmi (Apple FairPlay)' => ['drmi'],
        ];
    }

    // ------------------------------------------------------------------
    // Repairability
    // ------------------------------------------------------------------

    /**
     * The item must stay REPAIRABLE. When the only codec-bearing video stream is
     * a flagged cover art, the poster is (correctly) not persisted, so the
     * item's only video row is the codec-less content track and the REAL
     * {@see StreamProbeBackfill::videoCodecMissing()} reports "codec unknown —
     * repairable". Anything that made this false would short-circuit
     * `ensureVideoCodecFor()` and mask the item from the repair backfill forever.
     */
    public function testVideoCodecMissingStaysTrueWhenTheOnlyCodecBearingVideoRowIsAFlaggedPoster(): void
    {
        $summary = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'width' => 320, 'height' => 240],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac'],
                ['index' => 2, 'codec_type' => 'video', 'codec_name' => 'mjpeg',
                 'width' => 600, 'height' => 900, 'disposition' => ['attached_pic' => 1]],
            ],
            'format' => [],
        ]);

        $this->assertSame([['video', 0], ['audio', 1]], $this->rowKeys($summary['streams']));
        $this->assertTrue(
            $this->videoCodecMissing($summary['streams']),
            'the item must stay visible to ensureVideoCodecFor()',
        );
    }

    /**
     * The honest counterpart, recorded so nobody reads the test above as a
     * stronger guarantee than it is: when the poster is NOT flagged
     * `attached_pic` it is a persisted video row that names `mjpeg`, and
     * `videoCodecMissing()` therefore returns FALSE — the first non-blank
     * `codec` wins and it has no way to know the row is a poster.
     *
     * That is PRE-EXISTING behaviour, identical on master (verified by running
     * both trees over the real `poster_vvc1.mp4` probe), and it is why dropping
     * the content row was not what made such an item unrepairable — losing the
     * row's dimensions/bitrate/existence was. Repairability is preserved by
     * `videoCodecMissing()` returning `$sawVideoRow`, which only a file with
     * ZERO video rows can defeat, and no video stream is ever dropped now.
     */
    public function testAnUnflaggedPosterMasksTheRepairTriggerOnMasterToo(): void
    {
        $summary = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'width' => 320, 'height' => 240],
                ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'mjpeg',
                 'width' => 600, 'height' => 900],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
            'format' => [],
        ]);

        $this->assertSame(
            [['video', 0], ['video', 1], ['audio', 2]],
            $this->rowKeys($summary['streams']),
            'both video rows survive — that is what this change guarantees',
        );
        $this->assertFalse(
            $this->videoCodecMissing($summary['streams']),
            'an unflagged mjpeg poster satisfies the repair trigger; unchanged from master',
        );
    }

    /**
     * A file whose EVERY video stream is degenerate keeps EVERY one of them —
     * not just one via a fallback.
     *
     * @dataProvider allDegenerateFiles
     *
     * @param array<int, array<string, mixed>> $rawStreams
     */
    public function testEveryVideoRowSurvivesWhenAllOfThemAreDegenerate(array $rawStreams, int $expectedVideoRows): void
    {
        $summary = $this->summarize(['streams' => $rawStreams, 'format' => []]);

        $videoRows = array_values(array_filter(
            $summary['streams'],
            static fn (array $row): bool => ($row['stream_type'] ?? null) === 'video'
        ));

        $this->assertCount($expectedVideoRows, $videoRows);
        $this->assertNotNull($summary['source'], 'a file with any video-type stream gets a source blob');

        // The repair trigger must be the exact complement of "some video row
        // names a codec" — so an item is never left both codec-unknown AND
        // invisible to ensureVideoCodecFor().
        $anyCodec = false;
        foreach ($videoRows as $row) {
            $codec = $row['codec'] ?? null;
            if (is_string($codec) && trim($codec) !== '') {
                $anyCodec = true;
            }
        }
        $this->assertSame(
            !$anyCodec,
            $this->videoCodecMissing($summary['streams']),
            'codec unknown <=> repairable; never codec-unknown AND masked',
        );
    }

    /**
     * Four different all-degenerate shapes, so the guarantee is not pinned to
     * one plant: two codec-less rows, two zero-height rows that DO name a codec,
     * a mixed pair, and a single codec-less row with no audio at all.
     *
     * @return array<string, array{0: array<int, array<string, mixed>>, 1: int}>
     */
    public static function allDegenerateFiles(): array
    {
        return [
            'two codec-less rows' => [[
                ['index' => 0, 'codec_type' => 'video', 'width' => 4, 'height' => 3841],
                ['index' => 1, 'codec_type' => 'video', 'width' => 4, 'height' => 5634],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ], 2],
            'two zero-height rows that DO name a codec' => [[
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 0],
                ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 0],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ], 2],
            'mixed: codec-less then absurd aspect' => [[
                ['index' => 0, 'codec_type' => 'video', 'width' => 1920, 'height' => 1080],
                ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'mpeg4', 'width' => 4, 'height' => 5634],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ], 2],
            'a single codec-less row, no audio at all' => [[
                ['index' => 0, 'codec_type' => 'video', 'width' => 1920, 'height' => 0],
            ], 1],
        ];
    }

    // ------------------------------------------------------------------
    // The geometry rule: it decides `source`, and nothing else
    // ------------------------------------------------------------------

    /**
     * A stream with implausible geometry may not define `source` — modelled with
     * the junk FIRST so "first video stream wins" would have picked it — and it
     * keeps its row anyway.
     *
     * @dataProvider impossibleGeometries
     */
    public function testImpossibleGeometryLosesTheSourceRoleButKeepsItsRow(
        ?int $width,
        ?int $height,
        string $why
    ): void {
        // Codec NAMED, so only the geometry can be the reason.
        $junk = ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'bit_rate' => '120'];
        if ($width !== null) {
            $junk['width'] = $width;
        }
        if ($height !== null) {
            $junk['height'] = $height;
        }

        $summary = $this->summarize([
            'streams' => [
                $junk,
                ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'hevc',
                 'width' => 1920, 'height' => 1080, 'bit_rate' => '4000000'],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
            'format' => [],
        ]);

        $source = $summary['source'];
        $this->assertIsArray($source);
        $this->assertSame('hevc', $source['video_codec'], $why);
        $this->assertSame(1080, $source['height'], $why);
        $this->assertSame(
            [['video', 0], ['video', 1], ['audio', 2]],
            $this->rowKeys($summary['streams']),
            'losing the source role must never cost the row',
        );
    }

    /**
     * @return array<string, array{0: int|null, 1: int|null, 2: string}>
     */
    public static function impossibleGeometries(): array
    {
        return [
            'zero height (the real 1920x0 header)' => [1920, 0, 'a height of 0 cannot describe a frame'],
            'zero width' => [0, 1080, 'a width of 0 cannot describe a frame'],
            'zero by zero (mid-GOP .ts)' => [0, 0, 'no dimensions at all'],
            'negative height' => [1920, -1080, 'a negative dimension is nonsense'],
            'width absent entirely' => [null, 1080, 'no width to build a ladder from'],
            'height absent entirely' => [1920, null, 'no height to build a ladder from'],
            'both absent' => [null, null, 'nothing to believe'],
            'absurdly wide aspect' => [200_000, 4, '50000:1 is past the aspect bound'],
            'the real 4x3841 shape' => [4, 3841, '1:960 is not a frame this library holds'],
            'the real 4x5634 shape' => [4, 5634, '1:1408 is not a frame this library holds'],
        ];
    }

    /**
     * The bounds must not cost anything that would actually play its `source`
     * role. Each case is the FIRST video stream, so it wins `source` unless the
     * geometry rule wrongly rejects it — and a real hevc stream sits behind it,
     * so a false rejection is visible as the wrong descriptor rather than as no
     * descriptor.
     *
     * The measured production library spans 184-3840 px and aspect 1.02-3.00, so
     * every case here already sits outside real content and is still accepted.
     *
     * @dataProvider plausibleGeometries
     */
    public function testPlausibleGeometryKeepsTheSourceRole(int $width, int $height, string $codec): void
    {
        $summary = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => $codec,
                 'width' => $width, 'height' => $height],
                ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'hevc',
                 'width' => 1920, 'height' => 1080],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
            'format' => [],
        ]);

        $source = $summary['source'];
        $this->assertIsArray($source);
        $this->assertSame($width, $source['width'], sprintf('%dx%d (%s) is plausible', $width, $height, $codec));
        $this->assertSame($height, $source['height']);
        $this->assertSame($codec, $source['video_codec']);
        $this->assertSame(
            [['video', 0], ['video', 1], ['audio', 2]],
            $this->rowKeys($summary['streams']),
        );
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    public static function plausibleGeometries(): array
    {
        return [
            '4K UHD' => [3840, 2160, 'hevc'],
            '8K' => [7680, 4320, 'av1'],
            'portrait phone video' => [1080, 1920, 'h264'],
            'a poster-sized stream with no attached_pic flag' => [600, 900, 'mjpeg'],
            'an LED ticker, 60:1' => [3840, 64, 'h264'],
            'the same ticker rotated, 1:60' => [64, 3840, 'h264'],
            'a tiny 8x8 clip' => [8, 8, 'h264'],
            'exactly the aspect bound, 100:1' => [2000, 20, 'h264'],
            'exactly the aspect bound inverted, 1:100' => [20, 2000, 'h264'],
            // Both are REAL files, built and checked on ffmpeg 6.1.1:
            // `-f lavfi -i color=s=70000x1000 -c:v ffv1` and its 1000x70000
            // rotation each encode (exit 0), probe as `ffv1,70000,1000` /
            // `ffv1,1000,70000`, and decode via `-f null -` (exit 0). There is
            // no 65536 ceiling in libavcodec, so nothing bounds a single
            // dimension here and only the 70:1 aspect is judged.
            'a real decodable 70000x1000 ffv1 (70:1)' => [70_000, 1_000, 'ffv1'],
            'its rotation, a real 1000x70000 ffv1 (1:70)' => [1_000, 70_000, 'ffv1'],
        ];
    }

    /**
     * A codec-less stream with PLAUSIBLE geometry is not disqualified from
     * `source` — the split that separates this change from the one that broke
     * `vvc1`/`encv`/`drmi`. Modelled with the codec-less stream first and a
     * fully-formed hevc stream behind it, so treating "no codec" as a geometry
     * defect would show up as `video_codec => 'hevc'`.
     */
    public function testAMissingCodecAloneDoesNotCostTheSourceRole(): void
    {
        $summary = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'width' => 3840, 'height' => 2160,
                 'bit_rate' => '20000000', 'pix_fmt' => 'yuv420p10le'],
                ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'hevc',
                 'width' => 1920, 'height' => 1080],
            ],
            'format' => [],
        ]);

        $source = $summary['source'];
        $this->assertIsArray($source);
        $this->assertNull($source['video_codec'], 'unknown, and reported as unknown');
        $this->assertSame(3840, $source['width'], 'a 4K DRM title must not be laddered from another stream');
        $this->assertSame(2160, $source['height']);
    }

    // ------------------------------------------------------------------
    // Scope, and composition with the cover-art skip
    // ------------------------------------------------------------------

    /**
     * The predicate is scoped to VIDEO rows, and audio/subtitle rows were never
     * subject to it. A codec-less audio or subtitle row is a different question
     * with different consumers (`StreamTrackShaper` emits an audio track with
     * `codec => ''`, and its per-type ordinal must keep counting so `0:a:N` /
     * `0:s:N` stay aligned with ffmpeg).
     */
    public function testCodeclessAudioAndSubtitleRowsAreUntouched(): void
    {
        $summary = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'hevc', 'width' => 1920, 'height' => 1080],
                ['index' => 1, 'codec_type' => 'audio', 'channels' => 2],
                ['index' => 2, 'codec_type' => 'subtitle'],
                ['index' => 3, 'codec_type' => 'video', 'width' => 1920, 'height' => 0],
            ],
            'format' => [],
        ]);

        $this->assertSame(
            [['video', 0], ['audio', 1], ['subtitle', 2], ['video', 3]],
            $this->rowKeys($summary['streams']),
        );
        $this->assertNull($summary['streams'][1]['codec']);
        $this->assertNull($summary['streams'][2]['codec']);
    }

    /**
     * `attached_pic` is the ONLY reason a video stream loses its row, and the
     * skip composes with the geometry rule WITHOUT widening: the flagged poster
     * is excluded whenever any non-poster video stream exists — whether that
     * stream is sound or degenerate — and it may still take the `source` role
     * from a `1920x0` header, because a poster with real dimensions describes
     * the file better than a header that would clamp the ABR ladder to zero.
     *
     * The second case is the one that regressed. Giving the promoted poster a
     * row there costs the item its repairability
     * ({@see testAPromotedPosterNeverGainsARowMasterWouldNotHaveWritten}), so
     * `source` and the row set are decided separately.
     */
    public function testCoverArtIsTheOnlyRemainingSkip(): void
    {
        $withReal = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'mjpeg',
                 'width' => 600, 'height' => 900, 'disposition' => ['attached_pic' => 1]],
                ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'hevc',
                 'width' => 1920, 'height' => 1080],
            ],
            'format' => [],
        ]);
        $this->assertSame([['video', 1]], $this->rowKeys($withReal['streams']));

        $withDegenerate = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'mjpeg',
                 'width' => 600, 'height' => 900, 'disposition' => ['attached_pic' => 1]],
                ['index' => 1, 'codec_type' => 'video', 'width' => 1920, 'height' => 0],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
            'format' => [],
        ]);
        // The degenerate row is kept (it is not cover art); the poster is NOT
        // persisted, because the file has a non-poster video stream — exactly
        // as on master. It does define `source`, which master's row set says
        // nothing about.
        $this->assertSame(
            [['video', 1], ['audio', 2]],
            $this->rowKeys($withDegenerate['streams']),
        );
        $source = $withDegenerate['source'];
        $this->assertIsArray($source);
        $this->assertSame('mjpeg', $source['video_codec']);
        $this->assertSame(900, $source['height'], 'not the junk 0');
    }

    /**
     * A poster that wins the `source` role must NOT gain a `media_streams` row,
     * because the row would mask the item from the repair backfill forever.
     *
     * This is the acceptance test for that regression, and every expectation in
     * the provider is MASTER's output, not a preference: each `$masterRows` /
     * `$masterRepairable` pair was captured by running pristine
     * `b07b5de3`'s `MediaScanner::summarizeProbe()` over the same probe (the
     * master class `require`d ahead of the autoloader so it wins the FQN) and
     * judging the result with the real, reflected
     * {@see StreamProbeBackfill::videoCodecMissing()} — the same private method
     * this test calls. Nothing here may differ from master.
     *
     * Case D is the control: identical shape with the geometry defect removed,
     * so a failure that appears in A/B/C but not in D isolates the promotion
     * path rather than the cover-art skip in general.
     *
     * @dataProvider posterPromotionShapes
     *
     * @param array<int, array<string, mixed>> $rawStreams
     * @param list<array{0: string, 1: int}>   $masterRows
     */
    public function testAPromotedPosterNeverGainsARowMasterWouldNotHaveWritten(
        array $rawStreams,
        array $masterRows,
        bool $masterRepairable,
        string $why
    ): void {
        $summary = $this->summarize(['streams' => $rawStreams, 'format' => []]);

        $this->assertSame(
            $masterRows,
            $this->rowKeys($summary['streams']),
            'no media_streams row may exist that master would not have written: ' . $why,
        );
        $this->assertSame(
            $masterRepairable,
            $this->videoCodecMissing($summary['streams']),
            'the repair trigger must read exactly as it does on master: ' . $why,
        );
    }

    /**
     * The reviewer's four shapes plus the two poster-only files that prove the
     * skip did not become unconditional.
     *
     * A/B/C differ only in WHICH geometry defect the content track carries
     * (`0x0`, the measured `1920x0`, the measured `4x3841`), so the promotion
     * fires for three independent reasons. D removes the defect entirely. E and
     * F are the escape hatch that must survive: with no non-poster video stream
     * the first poster is the item's only record of a picture, and master keeps
     * it.
     *
     * @return array<string, array{
     *     0: array<int, array<string, mixed>>,
     *     1: list<array{0: string, 1: int}>,
     *     2: bool,
     *     3: string
     * }>
     */
    public static function posterPromotionShapes(): array
    {
        $poster = static fn (int $index): array => [
            'index' => $index, 'codec_type' => 'video', 'codec_name' => 'mjpeg',
            'width' => 600, 'height' => 900, 'disposition' => ['attached_pic' => 1],
        ];
        $content = static fn (int $index, int $w, int $h): array => [
            'index' => $index, 'codec_type' => 'video', 'width' => $w, 'height' => $h,
        ];
        $aac = static fn (int $index): array => [
            'index' => $index, 'codec_type' => 'audio', 'codec_name' => 'aac',
        ];

        return [
            'A: flagged poster idx0 + codec-NULL 0x0 content idx1' => [
                [$poster(0), $content(1, 0, 0), $aac(2)],
                [['video', 1], ['audio', 2]],
                true,
                'a mid-GOP .ts beside cover art',
            ],
            'B: flagged poster idx0 + codec-NULL 1920x0 content idx1' => [
                [$poster(0), $content(1, 1920, 0), $aac(2)],
                [['video', 1], ['audio', 2]],
                true,
                'the measured 1920x0 production shape beside cover art',
            ],
            'C: flagged poster idx0 + codec-NULL 4x3841 content idx1' => [
                [$poster(0), $content(1, 4, 3841), $aac(2)],
                [['video', 1], ['audio', 2]],
                true,
                'the measured 4x3841 production shape beside cover art',
            ],
            'D: control — flagged poster idx0 + codec-NULL 320x240 content idx1' => [
                [$poster(0), $content(1, 320, 240), $aac(2)],
                [['video', 1], ['audio', 2]],
                true,
                'no geometry defect at all, so the promotion never fires',
            ],
            'E: a poster-only file keeps its ONE poster row' => [
                [$poster(0), $aac(1)],
                [['video', 0], ['audio', 1]],
                false,
                'an mp3 with cover art: master persists the promoted poster',
            ],
            'F: two posters and nothing else — only the first keeps a row' => [
                [
                    $poster(0),
                    ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'png',
                     'width' => 300, 'height' => 300, 'disposition' => ['attached_pic' => 1]],
                    $aac(2),
                ],
                [['video', 0], ['audio', 2]],
                false,
                'the fallback promotes the FIRST video-type stream, and only that one',
            ],
        ];
    }

    /**
     * The log must not claim a row it does not write. A degenerate stream that
     * is ALSO flagged cover art is the one case where a defect is reported for a
     * stream that the cover-art skip then drops, so `row_persisted` is computed
     * from the same helper the persist loop uses rather than hard-coded.
     */
    public function testRowPersistedIsFalseForACoverArtStreamThatIsAlsoDegenerate(): void
    {
        $log = $this->captureLog();

        $summary = $this->summarize([
            'streams' => [
                // A flagged poster with a broken 0-height header, in front of a
                // real content track. It is degenerate (so it is reported) AND
                // cover art with a non-poster sibling (so it gets no row).
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'mjpeg',
                 'width' => 600, 'height' => 0, 'disposition' => ['attached_pic' => 1]],
                ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'hevc',
                 'width' => 1920, 'height' => 1080],
            ],
            'format' => ['filename' => '/vault1/movies/Broken Cover.mkv'],
        ]);

        $this->assertSame([['video', 1]], $this->rowKeys($summary['streams']));
        $this->assertStringContainsString('"row_persisted":false', (string) file_get_contents($log));
    }

    // ------------------------------------------------------------------
    // The predicate itself
    // ------------------------------------------------------------------

    /**
     * `videoStreamDefects()` must name EVERY rule that fired, at the boundaries,
     * so a `>` silently becoming `>=` (or a bound being widened) is caught here
     * and not only through the selection logic.
     *
     * @dataProvider predicateCases
     *
     * @param array<string, mixed> $stream
     * @param list<string>         $expected
     */
    public function testTheDefectListAtItsBoundaries(array $stream, array $expected, string $why): void
    {
        $this->assertSame($expected, $this->defects($stream), $why);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<string>, 2: string}>
     */
    public static function predicateCases(): array
    {
        $ok = ['codec_name' => 'h264', 'width' => 1920, 'height' => 1080];

        return [
            'a normal stream' => [$ok, [], '1920x1080 h264 is the baseline'],
            'codec_name absent' => [
                ['width' => 1920, 'height' => 1080],
                ['codec_name_absent'],
                'a missing codec is recorded and nothing else',
            ],
            'codec_name empty string' => [
                ['codec_name' => '', 'width' => 1920, 'height' => 1080],
                ['codec_name_absent'],
                'an empty codec is no codec',
            ],
            'codec_name not a string' => [
                ['codec_name' => 0, 'width' => 1920, 'height' => 1080],
                ['codec_name_absent'],
                'stringOrNull() rejects a non-string, so the stored codec would be NULL',
            ],
            'the real 1920x0 header' => [
                ['width' => 1920, 'height' => 0],
                ['codec_name_absent', 'dimension_missing_or_not_positive'],
                'both rules fire on the measured production row',
            ],
            'zero height with a codec' => [
                ['codec_name' => 'h264', 'width' => 1920, 'height' => 0],
                ['dimension_missing_or_not_positive'],
                'the mid-GOP .ts shape: geometry only',
            ],
            'zero height does NOT divide by zero' => [
                ['codec_name' => 'h264', 'width' => 1920, 'height' => 0],
                ['dimension_missing_or_not_positive'],
                'the not-positive check must return BEFORE the ratio (DivisionByZeroError)',
            ],
            'width absent' => [
                ['codec_name' => 'h264', 'height' => 1080],
                ['dimension_missing_or_not_positive'],
                'an absent dimension is not a dimension',
            ],
            // No rule bounds a single dimension. These three cases are the
            // tripwire for re-adding one: each was rejected by the deleted
            // `MAX_VIDEO_DIMENSION = 65536`, each is a shape ffmpeg 6.1.1
            // encodes/probes/decodes with exit 0, and none is a defect.
            'the deleted dimension bound, exactly' => [
                ['codec_name' => 'h264', 'width' => 65536, 'height' => 65536],
                [],
                'no rule bounds a single dimension',
            ],
            'one past the deleted dimension bound' => [
                ['codec_name' => 'h264', 'width' => 65537, 'height' => 1080],
                [],
                '60.7:1 is inside the aspect bound, and nothing else judges 65537',
            ],
            'the real 70000x1000 ffv1 file' => [
                ['codec_name' => 'ffv1', 'width' => 70_000, 'height' => 1_000],
                [],
                'encodes, probes and decodes with exit 0 on ffmpeg 6.1.1 — not a defect',
            ],
            'past the aspect bound with huge dimensions' => [
                ['codec_name' => 'h264', 'width' => 200_000, 'height' => 4],
                ['aspect_beyond_bound'],
                '50000:1 is past the aspect bound; the 200000 width itself is judged by nothing',
            ],
            'both rules at once' => [
                ['width' => 200_000, 'height' => 4],
                ['codec_name_absent', 'aspect_beyond_bound'],
                'every rule that fires is named, in a stable order',
            ],
            'aspect exactly 100:1' => [
                ['codec_name' => 'h264', 'width' => 2000, 'height' => 20],
                [],
                '100:1 is the bound itself and must be accepted',
            ],
            'aspect exactly 1:100' => [
                ['codec_name' => 'h264', 'width' => 20, 'height' => 2000],
                [],
                '1:100 is the bound itself and must be accepted',
            ],
            'aspect just past 100:1' => [
                ['codec_name' => 'h264', 'width' => 2001, 'height' => 20],
                ['aspect_beyond_bound'],
                'wider than 100:1 is outside the measured population',
            ],
            'aspect just past 1:100' => [
                ['codec_name' => 'h264', 'width' => 20, 'height' => 2001],
                ['aspect_beyond_bound'],
                'taller than 1:100 is outside the measured population',
            ],
            'the real 4x3841 shape' => [
                ['width' => 4, 'height' => 3841],
                ['codec_name_absent', 'aspect_beyond_bound'],
                'the measured production row, both rules',
            ],
            'numeric-string dimensions (ffprobe emits these)' => [
                ['codec_name' => 'h264', 'width' => '1920', 'height' => '1080'],
                [],
                'intOrNull() accepts numeric strings, so the predicate must too',
            ],
            'numeric-string zero height' => [
                ['codec_name' => 'h264', 'width' => '1920', 'height' => '0'],
                ['dimension_missing_or_not_positive'],
                'the string "0" is still zero',
            ],
            'an empty stream entry' => [
                [],
                ['codec_name_absent', 'dimension_missing_or_not_positive'],
                'nothing is known about it',
            ],
        ];
    }

    /**
     * `defectsIncludeGeometry()` exempts EXACTLY one defect id. Spelled as a
     * denylist in the implementation so a new geometry rule is honoured
     * automatically; this test is the tripwire if the exemption list grows.
     */
    public function testOnlyTheCodecRuleIsExemptFromTheGeometryVerdict(): void
    {
        $this->assertFalse($this->includesGeometry([]));
        $this->assertFalse($this->includesGeometry(['codec_name_absent']));
        $this->assertTrue($this->includesGeometry(['dimension_missing_or_not_positive']));
        $this->assertTrue($this->includesGeometry(['aspect_beyond_bound']));
        $this->assertTrue($this->includesGeometry(['codec_name_absent', 'aspect_beyond_bound']));
        $this->assertTrue(
            $this->includesGeometry(['some_future_rule']),
            'an unrecognised defect must count as consequential, not be silently ignored',
        );
    }

    /**
     * A non-array value must be answered with an EMPTY defect list, not fatal:
     * the predicate takes `mixed` so callers never pre-narrow the raw ffprobe
     * entry (mirroring `isAttachedPic()`). Empty is the safe answer because such
     * an entry is already dropped by the `is_array()` guard in both of
     * `summarizeProbe()`'s loops — claiming a defect would be a claim about a
     * stream that does not exist.
     */
    public function testANonArrayStreamHasNoDefects(): void
    {
        $this->assertSame([], $this->defects(null));
        $this->assertSame([], $this->defects('video'));
        $this->assertSame([], $this->defects(42));
    }

    // ------------------------------------------------------------------
    // The discard is no longer silent
    // ------------------------------------------------------------------

    /**
     * A geometry defect that actually cost a stream the `source` role is logged
     * at WARNING with everything needed to diagnose a false positive after the
     * fact: the path, the stream index, the rules that fired, the codec and
     * geometry seen, and which stream won instead.
     */
    public function testAConsequentialGeometryDefectIsLoggedLoudly(): void
    {
        $log = $this->captureLog();

        $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'width' => 1920, 'height' => 0, 'bit_rate' => '112'],
                ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'hevc',
                 'width' => 1920, 'height' => 1080],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
            'format' => ['filename' => '/vault1/movies/Junk First.mkv'],
        ]);

        $contents = (string) file_get_contents($log);
        $this->assertStringContainsString('media.WARNING', $contents);
        $this->assertStringContainsString('implausible geometry', $contents);
        $this->assertStringContainsString('/vault1/movies/Junk First.mkv', $contents);
        $this->assertStringContainsString('"stream_index":0', $contents);
        $this->assertStringContainsString('"source_stream_index":1', $contents);
        $this->assertStringContainsString('dimension_missing_or_not_positive', $contents);
        $this->assertStringContainsString('"width":1920', $contents);
        $this->assertStringContainsString('"row_persisted":true', $contents);
    }

    /**
     * A missing codec changes nothing, so it is reported at DEBUG — a library of
     * DRM (`encv`/`drmi`) titles must not fill error.log with warnings about
     * every one of them. The record still exists, so an operator who wants to
     * find the measured junk rows has a trail that names the file and the rule.
     */
    public function testAConsequenceFreeDefectIsLoggedQuietly(): void
    {
        $log = $this->captureLog();

        $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'width' => 320, 'height' => 240],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
            'format' => ['filename' => '/vault1/movies/Encrypted.mp4'],
        ]);

        $contents = (string) file_get_contents($log);
        $this->assertStringContainsString('media.DEBUG', $contents);
        $this->assertStringNotContainsString('media.WARNING', $contents);
        $this->assertStringContainsString('codec_name_absent', $contents);
        $this->assertStringContainsString('/vault1/movies/Encrypted.mp4', $contents);
    }

    /**
     * A geometry defect on a stream that was promoted ANYWAY (the file's only
     * video-type stream, e.g. an in-progress DVR `.ts`) had no consequence, so
     * it is DEBUG too. Guards the volume claim: a recorder that re-probes a
     * growing file must not emit a warning per probe.
     */
    public function testAPromotedDegenerateStreamIsNotWarnedAbout(): void
    {
        $log = $this->captureLog();

        $summary = $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264',
                 'width' => 0, 'height' => 0, 'bit_rate' => '162555'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
            'format' => ['filename' => '/var/recordings/in-progress.ts'],
        ]);

        $this->assertSame([['video', 0], ['audio', 1]], $this->rowKeys($summary['streams']));
        $contents = (string) file_get_contents($log);
        $this->assertStringContainsString('media.DEBUG', $contents);
        $this->assertStringNotContainsString('media.WARNING', $contents);
    }

    /**
     * A clean file logs NOTHING — the list is empty and the reporter is never
     * called, so a full-library scan is not slowed down or drowned by files that
     * have nothing wrong with them.
     */
    public function testACleanFileLogsNothing(): void
    {
        $log = $this->captureLog();

        $this->summarize([
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'hevc',
                 'width' => 1920, 'height' => 1080],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'eac3'],
                ['index' => 2, 'codec_type' => 'subtitle', 'codec_name' => 'subrip'],
            ],
            'format' => ['filename' => '/vault1/movies/Fine.mkv'],
        ]);

        $this->assertSame('', (string) file_get_contents($log));
    }
}
