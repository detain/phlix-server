<?php

/**
 * Phlix media server test: Media\Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S122(c) — what a tag read actually costs, measured rather than asserted.
 *
 * Every number in {@see MusicLibraryScanner::getId3Reader()}'s docblock came from this
 * technique: a stream wrapper that counts the bytes, `fread`s and `fseek`s getID3
 * performs on a synthetic MP3. Keeping it in the suite means the claims are falsifiable
 * on a DB-less box in CI, and that a future "cleanup" of those options has to argue with
 * a number.
 *
 * ⚠ **The headline finding in this file is a BUG, not a tuning result.**
 * `getID3::analyze()` does not populate `$info['comments']` — that view is built by
 * `getid3_lib::CopyTagsToComments()`, which nothing inside getID3 ever calls — so
 * `probeViaGetId3()` returned NULL for every file ever scanned and 100 % of the library
 * went through the ffprobe fallback at a measured **114.9 ms and 232 read syscalls per
 * file**, after paying getID3's full read first.
 *
 * @internal
 */
final class MusicScanTagReadCostTest extends TestCase
{
    /** @var list<string> Fixtures to remove. */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(CountingStreamWrapper::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(CountingStreamWrapper::SCHEME);
        }
        stream_wrapper_register(CountingStreamWrapper::SCHEME, CountingStreamWrapper::class);
        CountingStreamWrapper::reset();
    }

    protected function tearDown(): void
    {
        if (in_array(CountingStreamWrapper::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(CountingStreamWrapper::SCHEME);
        }

        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];

        parent::tearDown();
    }

    /**
     * ⚠ THE BUG FIX, AND THE MOST IMPORTANT ASSERTION IN THIS FILE.
     *
     * `probeViaGetId3()` must return the tags of a real, well-formed MP3. Before S122(c)
     * it returned NULL for every file in existence, because it read
     * `$info['comments']` — a key `analyze()` leaves EMPTY (at most `['picture']`) since
     * the merged view is built only by `getid3_lib::CopyTagsToComments()`, which no
     * getID3 module calls. The consequence was not "slightly worse tags": it was that
     * the entire ffprobe fallback ran for 100 % of the library, at 114.9 ms and 232 read
     * syscalls per file against getID3's 2.2 ms.
     */
    public function testTagsAreActuallyExtractedFromARealMp3(): void
    {
        $tags = $this->scanner()->readTags(self::realFixture());

        self::assertIsArray(
            $tags,
            'getID3 must return usable tags for a well-formed MP3. A NULL here means the merged '
            . 'comments view is not being built and EVERY file is falling through to ffprobe — '
            . 'which is exactly the pre-S122(c) production behaviour.'
        );
        self::assertSame('Measured Artist', $tags['artist']);
        self::assertSame('Measured Album', $tags['album']);
        self::assertSame('Measured Title', $tags['title']);
        self::assertSame(3, $tags['track_number'], 'the "3/12" position/total form must parse');
        self::assertSame(1, $tags['disc_number'], 'the "1/2" disc form must parse');
        self::assertSame(1999, $tags['year']);
        self::assertSame('Rock', $tags['genre']);
        self::assertGreaterThan(0, $tags['duration_secs'], 'playtime must survive the reduced frame check');
    }

    /**
     * Tags are extracted just as well from a file carrying a 404 KB cover art frame —
     * i.e. suppressing attachment RETENTION does not suppress the text frames.
     */
    public function testTagsAreExtractedFromAFileWithLargeCoverArt(): void
    {
        $path = $this->mp3('art', artBytes: 404 * 1024);
        $tags = $this->scanner()->readTags($path);

        self::assertIsArray($tags);
        self::assertSame('Measured Artist', $tags['artist']);
        self::assertSame('Measured Album', $tags['album']);
    }

    /**
     * The tuned reader is strictly cheaper on every axis, on a PRODUCTION-SHAPED file.
     *
     * ⚠ **The honest size of this win, stated so it is not oversold.** On a long file
     * carrying 404 KB of cover art the byte reduction is SMALL — measured at development
     * time as 598,585 → 582,201, i.e. **2.7 %** — because ≈404 KB of that is the ID3v2
     * frame region, which `module.tag.id3v2.php:139` reads unconditionally and
     * contiguously. What does improve is the read and SEEK count (76 → 74 reads, 11 → 9
     * seeks), and on the production mount a seek is a remote round trip against a
     * spindle at `r_await` 10.58 ms, so seeks matter out of proportion to bytes.
     *
     * The AC-4 answer for a probed file is therefore not this option set — it is
     * {@see self::testTagsAreActuallyExtractedFromARealMp3()}, which removes the 114.9 ms
     * / 232-syscall ffprobe subprocess that used to run for 100 % of files. And the AC-4
     * answer for the SCAN is S122(a): an unchanged file is not opened at all, so its cost
     * is 0 bytes.
     */
    public function testTheTunedReaderIsStrictlyCheaperOnAProductionShapedFile(): void
    {
        $path = $this->mp3('cost-long', artBytes: 404 * 1024, mpegFrames: 2000);

        $baseline = $this->measure($path, tuned: false);
        $tuned = $this->measure($path, tuned: true);

        self::assertLessThan(
            $baseline['bytes'],
            $tuned['bytes'],
            sprintf('tuned read %d bytes against a pre-S122 %d', $tuned['bytes'], $baseline['bytes'])
        );
        self::assertLessThan(
            $baseline['reads'],
            $tuned['reads'],
            sprintf('tuned performed %d reads against %d', $tuned['reads'], $baseline['reads'])
        );
        self::assertLessThan(
            $baseline['seeks'],
            $tuned['seeks'],
            sprintf(
                'tuned performed %d seeks against %d — on the production mount each seek is a '
                . 'remote round trip, so this is the figure that dominates',
                $tuned['seeks'],
                $baseline['seeks']
            )
        );

        // The bytes ceiling is the ID3v2 frame region, and it is NOT removable without a
        // vendor patch. Pinning the ratio band keeps the docblock's "2.7 %" honest: if a
        // future change made this a large win, the docblock is now wrong and must be
        // updated rather than left overstating or understating the lever.
        self::assertGreaterThan(
            $baseline['bytes'] * 0.9,
            $tuned['bytes'],
            'the cover-art read is structural in getID3: if the byte count suddenly collapsed on a '
            . 'long art-carrying file, something removed that read and getId3Reader()\'s docblock '
            . 'needs rewriting'
        );
    }

    /**
     * On a SHORT file the reduction is large, because the 50-frame validity requirement
     * forces getID3 into repeated re-reads of a file that does not contain 50 frames.
     *
     * Measured at development time on a 16.6 KB ffmpeg-written MP3: 455,654 → 139,931
     * bytes (−69 %), 61 → 21 reads, 59 → 19 seeks. This is the shape
     * `options_audio_mp3_mp3_valid_check_frames` actually addresses, and it is common in
     * real libraries (interludes, intros, sound effects, short tracks).
     */
    public function testTheTunedReaderReadsFarLessOnAShortFile(): void
    {
        $path = self::realFixture();

        $baseline = $this->measure($path, tuned: false);
        $tuned = $this->measure($path, tuned: true);

        self::assertLessThan(
            $baseline['bytes'] * 0.5,
            $tuned['bytes'],
            sprintf(
                'tuned read %d bytes against a pre-S122 %d — expected less than half on a short '
                . 'file. (Development measurement: 455,654 -> 139,931, a 69 %% cut.)',
                $tuned['bytes'],
                $baseline['bytes']
            )
        );
        self::assertLessThan(
            $baseline['seeks'] * 0.6,
            $tuned['seeks'],
            sprintf(
                'and far fewer seeks: %d against %d (development measurement: 59 -> 19). Seeks are '
                . 'what the diagnostic counted as "~60 per MP3", and each one is a remote round '
                . 'trip on the production mount.',
                $tuned['seeks'],
                $baseline['seeks']
            )
        );
        self::assertLessThan(
            $baseline['reads'] * 0.6,
            $tuned['reads'],
            sprintf('and far fewer reads: %d against %d (development: 61 -> 21)', $tuned['reads'], $baseline['reads'])
        );
    }

    /**
     * ⚠ THE CORRECTION TO THE DIAGNOSTIC, PINNED SO IT CANNOT BE FORGOTTEN.
     *
     * The vault diagnostic identified ≈404 KB of discarded cover art per file and
     * pointed at `option_save_attachments`. That option does **NOT** reduce bytes read:
     * `module.tag.id3v2.php:139` reads the whole frame region in one contiguous
     * `fread($sizeofframes)` BEFORE any frame is inspected, and the option is consulted
     * afterwards (`:1448`) only to decide whether to KEEP the picture.
     *
     * What it does buy is memory — measured at development time as 1,891,240 → 33,256
     * bytes retained per analyse, a 98.2 % cut — which in a resident scan worker is 1.9 MB
     * of churn per file. Both halves are asserted here so nobody re-reads the diagnostic
     * and "fixes" the read by toggling this option again.
     */
    public function testAttachmentSuppressionCutsMemoryButNotBytesRead(): void
    {
        $path = $this->mp3('cost-art', artBytes: 404 * 1024);

        $withArt = $this->measure($path, tuned: false, saveAttachments: true);
        $withoutArt = $this->measure($path, tuned: false, saveAttachments: false);

        self::assertSame(
            $withArt['bytes'],
            $withoutArt['bytes'],
            'option_save_attachments must NOT change the byte count — the ID3v2 frame region is '
            . 'read unconditionally and contiguously. Removing that read needs a vendor patch, '
            . 'which is forbidden; the lever for it is S122(a) (do not open the file at all).'
        );

        self::assertGreaterThan(
            300_000,
            $withArt['retained'],
            'the fixture must actually retain the cover art in the baseline configuration'
        );
        self::assertLessThan(
            $withArt['retained'] * 0.25,
            $withoutArt['retained'],
            sprintf(
                'suppressing attachments must cut retained memory sharply: %d -> %d bytes '
                . '(development measurement: 1,891,240 -> 33,256, a 98.2 %% cut)',
                $withArt['retained'],
                $withoutArt['retained']
            )
        );
    }

    /**
     * The shipped reader carries exactly the options the docblock claims, and the two
     * that must stay ON are still on.
     *
     * `option_tags_process` is the one that would silently break everything: it gates
     * `HandleAllTags()` (`getid3.php:790`), without which `$info['tags']` is never built
     * and there is nothing for the merge to copy — reproducing the very NULL-for-every-file
     * bug this step fixed.
     */
    public function testTheShippedReaderIsConfiguredAsDocumented(): void
    {
        $reader = $this->scanner()->reader();

        self::assertFalse($reader->option_md5_data, 'hashing the payload would read the whole file');
        self::assertFalse($reader->option_md5_data_source);
        self::assertFalse($reader->option_sha1_data);
        self::assertFalse($reader->option_save_attachments, 'do not RETAIN the cover art');
        self::assertFalse($reader->option_tags_html, 'nothing in this codebase reads tags_html');
        self::assertSame(
            10,
            $reader->options_audio_mp3_mp3_valid_check_frames,
            'vendor default is 50; the vendor docblock recommends 5-20 for faster scanning'
        );
        self::assertSame('UTF-8', $reader->encoding);

        self::assertTrue(
            $reader->option_tags_process,
            'MUST stay TRUE: it gates HandleAllTags(), and without it $info[tags] is never built, '
            . 'so the merged comments view is empty and every file falls through to ffprobe again'
        );
        self::assertTrue(
            $reader->option_tag_id3v1,
            'MUST stay TRUE: the legacy fallback for files carrying no ID3v2 at all'
        );
        self::assertTrue(
            $reader->option_tag_id3v2,
            'MUST stay TRUE: it is where essentially every real tag lives'
        );
    }

    /**
     * The reader is built once and reused for the whole scan.
     */
    public function testTheReaderIsMemoisedAcrossFiles(): void
    {
        $scanner = $this->scanner();

        self::assertSame($scanner->reader(), $scanner->reader());
    }

    /**
     * Measures one `analyze()` + merge through the counting wrapper.
     *
     * @param string $path Real filesystem path of the fixture.
     * @param bool $tuned TRUE for the shipped S122(c) options, FALSE for the pre-S122 set.
     * @param bool|null $saveAttachments Override for `option_save_attachments`.
     * @return array{bytes: int, reads: int, seeks: int, retained: int}
     */
    private function measure(string $path, bool $tuned, ?bool $saveAttachments = null): array
    {
        CountingStreamWrapper::reset();

        $reader = new \getID3();
        // Pre-S122 configuration, verbatim from `master` at 762c2c99.
        $reader->option_md5_data = false;
        $reader->option_md5_data_source = false;
        $reader->option_sha1_data = false;
        $reader->encoding = 'UTF-8';

        if ($tuned) {
            $reader->option_save_attachments = false;
            $reader->option_tags_html = false;
            $reader->options_audio_mp3_mp3_valid_check_frames = 10;
        }
        if ($saveAttachments !== null) {
            $reader->option_save_attachments = $saveAttachments;
        }

        $before = memory_get_usage();
        $info = $reader->analyze(CountingStreamWrapper::SCHEME . '://' . $path);
        $reader->CopyTagsToComments($info);
        $retained = memory_get_usage() - $before;

        self::assertSame('Measured Artist', $info['comments']['artist'][0] ?? null, 'the measurement must be of a SUCCESSFUL read');

        return [
            'bytes' => CountingStreamWrapper::$bytes,
            'reads' => CountingStreamWrapper::$reads,
            'seeks' => CountingStreamWrapper::$seeks,
            'retained' => $retained,
        ];
    }

    /**
     * Writes a synthetic but structurally valid MP3: ID3v2.3 text frames, an optional
     * `APIC` frame, real MPEG-1 Layer III frame headers, and an ID3v1 tag at EOF.
     *
     * Synthetic rather than a checked-in binary so the cover-art size is a parameter —
     * the whole point is to compare a file WITH 404 KB of art against one without.
     *
     * @param string $name Fixture name fragment.
     * @param int $artBytes Size of the `APIC` payload.
     * @param int $mpegFrames MPEG frames to emit. Low values reproduce the short-file
     *        shape where getID3's 50-frame validity requirement forces repeated re-reads.
     * @return string Absolute path.
     */
    private function mp3(string $name, int $artBytes, int $mpegFrames = 200): string
    {
        $frames = self::textFrame('TIT2', 'Measured Title')
            . self::textFrame('TPE1', 'Measured Artist')
            . self::textFrame('TALB', 'Measured Album')
            . self::textFrame('TRCK', '3/12')
            . self::textFrame('TPOS', '1/2')
            . self::textFrame('TYER', '1999')
            . self::textFrame('TCON', 'Rock');

        if ($artBytes > 0) {
            $body = "\x00" . "image/jpeg\x00" . "\x03" . "\x00" . str_repeat("\xAB", $artBytes);
            $frames .= 'APIC' . pack('N', strlen($body)) . "\x00\x00" . $body;
        }

        $size = strlen($frames);
        $tag = 'ID3' . "\x03\x00" . "\x00"
            . chr(($size >> 21) & 0x7F) . chr(($size >> 14) & 0x7F)
            . chr(($size >> 7) & 0x7F) . chr($size & 0x7F)
            . $frames;

        // MPEG-1 Layer III, 128 kbps, 44.1 kHz, no padding => 417-byte frames.
        $audio = str_repeat("\xFF\xFB\x90\x00" . str_repeat("\x00", 413), $mpegFrames);

        $id3v1 = 'TAG'
            . str_pad('Measured Title', 30, "\x00")
            . str_pad('Measured Artist', 30, "\x00")
            . str_pad('Measured Album', 30, "\x00")
            . '1999'
            . str_repeat("\x00", 30)
            . "\x11";

        $path = sys_get_temp_dir() . '/phlix_s122_' . $name . '_' . bin2hex(random_bytes(4)) . '.mp3';
        file_put_contents($path, $tag . $audio . $id3v1);
        $this->files[] = $path;

        return $path;
    }

    /**
     * The checked-in, encoder-produced MP3.
     *
     * ⚠ **A REAL FILE IS REQUIRED HERE AND A SYNTHETIC ONE WILL NOT DO — MEASURED.** The
     * synthetic builder below emits textbook-perfect CBR frames, which getID3 validates in
     * one pass: 21 reads / 11 seeks, and the tuned options then change almost nothing
     * (141,441 -> 125,057 bytes, 21 -> 19 reads). A real encoder's output carries a
     * Xing/LAME header and sends getID3 down its recursive frame-scanning path, which is
     * where the diagnostic's "~60 `fread`/`fseek` per MP3" figure comes from — this
     * fixture measures **61 reads / 59 seeks / 455,654 bytes** untuned against **21 / 19 /
     * 139,931** tuned. Asserting the option change against a synthetic fixture would have
     * silently proved almost nothing.
     *
     * Generated once with:
     * `ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -metadata artist=… -id3v2_version 3
     * -write_xing 1 tagged-short.mp3` — a 440 Hz sine wave, so it carries no third-party
     * content. It is committed rather than generated at test time so the suite does not
     * depend on ffmpeg being installed, and so the byte counts cannot drift with an ffmpeg
     * upgrade.
     *
     * @return string Absolute path.
     */
    private static function realFixture(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/Media/Music/tagged-short.mp3';
    }

    /**
     * An ID3v2.3 text frame (ISO-8859-1 encoding byte, 32-bit big-endian size).
     */
    private static function textFrame(string $id, string $text): string
    {
        $body = "\x00" . $text;

        return $id . pack('N', strlen($body)) . "\x00\x00" . $body;
    }

    private function scanner(): TagReadingScanner
    {
        return new TagReadingScanner(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
            $this->createMock(StructuredLogger::class)
        );
    }
}
