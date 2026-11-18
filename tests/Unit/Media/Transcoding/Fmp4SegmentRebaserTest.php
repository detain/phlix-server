<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\Fmp4SegmentRebaser;
use PHPUnit\Framework\TestCase;

/**
 * S56 — the CMAF fragment rebaser, on synthetic ISO-BMFF fixtures.
 *
 * Real ffmpeg output is exercised separately by
 * `tests/Integration/Media/Transcoding/Fmp4SegmentProductionTest`. These cases
 * exist for the shapes real ffmpeg 6.1 does NOT emit but the format permits —
 * version-0 boxes, 64-bit `tkhd`/`mdhd`, a version-0 field that must grow to
 * version 1, a truncated box — plus the arithmetic itself, which real output
 * cannot pin sharply because ffmpeg always hands us a `tfdt` of exactly 0.
 *
 * That last point is why every fixture below starts from a NON-ZERO `tfdt`
 * where it can: against a canonical all-zero fragment, `+= delta` and
 * `= delta` are indistinguishable, so the fixture would make the mutation a
 * no-op.
 */
final class Fmp4SegmentRebaserTest extends TestCase
{
    private const VIDEO_TIMESCALE = 12288;
    private const AUDIO_TIMESCALE = 44100;

    // ─────────────────────────────────────────────────────────────────
    // fixture builders
    // ─────────────────────────────────────────────────────────────────

    private static function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)) . $type . $payload;
    }

    private static function fullBox(string $type, int $version, string $payload): string
    {
        return self::box($type, chr($version) . "\0\0\0" . $payload);
    }

    private static function tfhd(int $trackId): string
    {
        return self::fullBox('tfhd', 0, pack('N', $trackId));
    }

    /** tfhd with an explicit 24-bit flags word (version 0), e.g. 0x020000 = default-base-is-moof. */
    private static function tfhdWithFlags(int $trackId, int $flags): string
    {
        return self::box('tfhd', pack('N', $flags) . pack('N', $trackId));
    }

    private static function tfdt(int $version, int $value): string
    {
        return self::fullBox('tfdt', $version, $version === 1 ? pack('J', $value) : pack('N', $value));
    }

    /** trun, version 0, with the data-offset-present flag (0x000001) and one 32-bit data_offset. */
    private static function trunWithDataOffset(int $dataOffset, int $sampleCount = 1): string
    {
        return self::box('trun', pack('N', 0x000001) . pack('N', $sampleCount) . pack('N', $dataOffset & 0xFFFFFFFF));
    }

    /**
     * trun, version 0, with the data-offset (0x000001) and per-sample-size (0x000200) flags and ONE sample of
     * `$sampleSize` bytes. The sample size is what makes the traf cover its data: without it a trun covers zero
     * bytes and the next traf's base is not where a test expects it.
     */
    private static function trunWithDataOffsetAndSize(int $dataOffset, int $sampleSize): string
    {
        return self::box(
            'trun',
            pack('N', 0x000201) . pack('N', 1) . pack('N', $dataOffset & 0xFFFFFFFF) . pack('N', $sampleSize)
        );
    }

    private static function traf(int $trackId, string $tfdt): string
    {
        return self::box('traf', self::tfhd($trackId) . $tfdt);
    }

    private static function moof(string ...$trafs): string
    {
        return self::box('moof', self::fullBox('mfhd', 0, pack('N', 1)) . implode('', $trafs));
    }

    /**
     * One track's fragment whose `tfhd` declares default-base-is-moof, so each `trun@data_offset`
     * is measured from the moof start. `$dataOffset` is written as given, so a caller that wants
     * it to reach the mdat payload passes `strlen(moof) + 8`.
     */
    private static function moofWithTrun(int $tfdtValue, int $dataOffset): string
    {
        return self::box(
            'moof',
            self::fullBox('mfhd', 0, pack('N', 1))
            . self::box(
                'traf',
                self::tfhdWithFlags(1, 0x020000) . self::tfdt(0, $tfdtValue) . self::trunWithDataOffset($dataOffset)
            )
        );
    }

    /**
     * `styp` + moof(tfdt v0, trun) + mdat('SAMPLEDATA'), with the trun pointing at the mdat payload.
     */
    private static function trunFragment(int $tfdtValue): string
    {
        $dataOffset = strlen(self::moofWithTrun($tfdtValue, 0)) + 8;

        return self::box('styp', 'msdh')
            . self::moofWithTrun($tfdtValue, $dataOffset)
            . self::box('mdat', 'SAMPLEDATA');
    }

    private static function sidx(int $version, int $referenceId, int $timescale, int $earliest): string
    {
        $payload = pack('N', $referenceId) . pack('N', $timescale);
        $payload .= $version === 0
            ? pack('N', $earliest) . pack('N', 0)
            : pack('J', $earliest) . pack('J', 0);
        $payload .= pack('n', 0) . pack('n', 0);

        return self::fullBox('sidx', $version, $payload);
    }

    /**
     * A sidx with a reference table: each entry of `$refSizes` is one referenced subsegment's
     * `referenced_size`, with a fixed duration and SAP word (both copied through by the rebaser).
     *
     * @param list<int> $refSizes
     */
    private static function sidxWithRefs(
        int $version,
        int $timescale,
        int $earliest,
        int $firstOffset,
        array $refSizes
    ): string {
        $payload = pack('N', 1) . pack('N', $timescale);
        $payload .= $version === 0
            ? pack('N', $earliest) . pack('N', $firstOffset)
            : pack('J', $earliest) . pack('J', $firstOffset);
        $payload .= pack('n', 0) . pack('n', count($refSizes));
        foreach ($refSizes as $size) {
            $payload .= pack('N', $size) . pack('N', 90000) . pack('N', 0x90000000);
        }

        return self::fullBox('sidx', $version, $payload);
    }

    /**
     * A two-track init with DELIBERATELY different timescales, so a rebaser that
     * applied one track's timescale to every `traf` is caught.
     */
    private static function init(int $tkhdVersion = 0, int $mdhdVersion = 0): string
    {
        $trak = static function (int $trackId, int $timescale) use ($tkhdVersion, $mdhdVersion): string {
            $tkhdPad = $tkhdVersion === 1 ? str_repeat("\0", 16) : str_repeat("\0", 8);
            $tkhd = self::fullBox('tkhd', $tkhdVersion, $tkhdPad . pack('N', $trackId));
            $mdhdPad = $mdhdVersion === 1 ? str_repeat("\0", 16) : str_repeat("\0", 8);
            $mdhd = self::fullBox('mdhd', $mdhdVersion, $mdhdPad . pack('N', $timescale) . pack('N', 0));

            return self::box('trak', $tkhd . self::box('mdia', $mdhd . self::box('minf', '')));
        };

        return self::box('ftyp', 'iso5avc1')
            . self::box('moov', $trak(1, self::VIDEO_TIMESCALE) . $trak(2, self::AUDIO_TIMESCALE));
    }

    /**
     * @param array<int, int> $timescales
     */
    private function rebased(string $segment, array $timescales, float $start): string
    {
        Fmp4SegmentRebaser::rebaseBytes($segment, $timescales, $start);

        return $segment;
    }

    private function tfdtValues(string $segment): array
    {
        preg_match_all('/tfdt/', $segment, $m, PREG_OFFSET_CAPTURE);
        $values = [];
        foreach ($m[0] as [, $offset]) {
            $version = ord($segment[$offset + 4]);
            $values[] = $version === 1
                ? unpack('J', substr($segment, $offset + 8, 8))[1]
                : unpack('N', substr($segment, $offset + 8, 4))[1];
        }

        return $values;
    }

    // ─────────────────────────────────────────────────────────────────
    // timescale extraction
    // ─────────────────────────────────────────────────────────────────

    public function test_it_reads_each_track_timescale_from_the_init(): void
    {
        $path = $this->tempFile(self::init());

        $this->assertSame(
            [1 => self::VIDEO_TIMESCALE, 2 => self::AUDIO_TIMESCALE],
            Fmp4SegmentRebaser::trackTimescales($path)
        );
    }

    /**
     * `tkhd` and `mdhd` widen their creation/modification times from 32 to 64
     * bits at version 1, moving every field after them. Reading `track_ID` at
     * the version-0 offset out of a version-1 box yields garbage, so a wrong
     * offset here would silently mis-map a whole track.
     */
    public function test_it_reads_the_64_bit_header_variants(): void
    {
        $path = $this->tempFile(self::init(tkhdVersion: 1, mdhdVersion: 1));

        $this->assertSame(
            [1 => self::VIDEO_TIMESCALE, 2 => self::AUDIO_TIMESCALE],
            Fmp4SegmentRebaser::trackTimescales($path)
        );
    }

    public function test_an_init_without_tracks_is_rejected(): void
    {
        $path = $this->tempFile(self::box('ftyp', 'iso5avc1') . self::box('moov', ''));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no moov\/trak timescales/');
        Fmp4SegmentRebaser::trackTimescales($path);
    }

    // ─────────────────────────────────────────────────────────────────
    // the arithmetic
    // ─────────────────────────────────────────────────────────────────

    /**
     * Each `traf` is offset by its OWN track's timescale. The two tracks here
     * have different timescales and different starting values, so neither "use
     * the first timescale everywhere" nor "overwrite instead of add" survives.
     */
    public function test_each_traf_is_offset_by_its_own_track_timescale(): void
    {
        $segment = self::box('styp', 'msdh')
            . self::moof(
                self::traf(1, self::tfdt(1, 1000)),
                self::traf(2, self::tfdt(1, 2000))
            )
            . self::box('mdat', 'payload');

        $out = $this->rebased($segment, [1 => self::VIDEO_TIMESCALE, 2 => self::AUDIO_TIMESCALE], 6.0);

        $this->assertSame(
            [1000 + 6 * self::VIDEO_TIMESCALE, 2000 + 6 * self::AUDIO_TIMESCALE],
            $this->tfdtValues($out)
        );
    }

    public function test_a_zero_offset_leaves_the_fragment_byte_identical(): void
    {
        $segment = self::box('styp', 'msdh')
            . self::moof(self::traf(1, self::tfdt(1, 4242)))
            . self::box('mdat', 'payload');

        $this->assertSame($segment, $this->rebased($segment, [1 => self::VIDEO_TIMESCALE], 0.0));
    }

    /**
     * ROUNDED, not truncated. The two inputs below are chosen so that `round()`
     * and an `(int)` cast disagree — 0.00006 s × 12288 = 0.73728 rounds to 1 and
     * truncates to 0, and 0.00012 s × 12288 = 1.47456 rounds to 1 either way —
     * so the pair distinguishes the two implementations instead of agreeing
     * with both.
     */
    public function test_the_offset_is_rounded_to_whole_ticks_not_truncated(): void
    {
        $segment = self::moof(self::traf(1, self::tfdt(1, 0)));

        $this->assertSame(0, (int) (0.00006 * 12288), 'control: an (int) cast would truncate this to zero');
        $this->assertSame([1], $this->tfdtValues($this->rebased($segment, [1 => 12288], 0.00006)));
        $this->assertSame([1], $this->tfdtValues($this->rebased($segment, [1 => 12288], 0.00012)));
        // 6.5 s × 12288 = 79872 exactly — an ordinary whole-tick case.
        $this->assertSame([79872], $this->tfdtValues($this->rebased($segment, [1 => 12288], 6.5)));
    }

    public function test_a_version_0_tfdt_is_rebased_in_its_32_bit_field(): void
    {
        $segment = self::moof(self::traf(1, self::tfdt(0, 500)));

        $this->assertSame([500 + 6 * 12288], $this->tfdtValues($this->rebased($segment, [1 => 12288], 6.0)));
    }

    /**
     * A version-0 tfdt whose rebased value no longer fits 32 bits is rewritten to
     * version 1 (64-bit field): the box grows by 4 and the moof grows with it.
     */
    public function test_an_overflowing_version_0_tfdt_is_rewritten_to_version_1(): void
    {
        $segment = self::moof(self::traf(1, self::tfdt(0, 0xFFFFFFF0)));
        $before = strlen($segment);

        $this->assertSame(1, Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 12288], 6.0));
        $this->assertSame([0xFFFFFFF0 + 6 * 12288], $this->tfdtValues($segment));
        $this->assertSame($before + 4, strlen($segment));
        $this->assertSame(1, ord($segment[strpos($segment, 'tfdt') + 4]), 'tfdt is now version 1');
    }

    /**
     * The version-0 limit is the start alone: 0xFFFFFFFF / timescale seconds. At 90000 that is
     * 47,721.86 s; 47,721 (4,294,890,000 ticks) still fits version 0 and stays in place.
     */
    public function test_a_version_0_start_at_47721_seconds_at_90000_stays_version_0(): void
    {
        $segment = self::moof(self::traf(1, self::tfdt(0, 0)));
        $before = strlen($segment);

        $this->assertSame(1, Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 90000], 47721.0));
        $this->assertSame([4294890000], $this->tfdtValues($segment));
        $this->assertSame(0, ord($segment[strpos($segment, 'tfdt') + 4]), 'still version 0');
        $this->assertSame($before, strlen($segment), 'a version-0 rewrite never resizes');
    }

    /**
     * 47,722 s at 90000 is 4,294,980,000 ticks, past 0xFFFFFFFF. The tfdt becomes version 1 with the
     * exact 64-bit value, its box is 20 bytes (16 + 4), and the traf and moof sizes grow by 4.
     */
    public function test_a_version_0_start_at_47722_seconds_at_90000_is_rewritten_to_version_1(): void
    {
        $segment = self::moof(self::traf(1, self::tfdt(0, 0)));
        $before = strlen($segment);
        $trafBefore = strlen(self::traf(1, self::tfdt(0, 0)));

        $this->assertSame(1, Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 90000], 47722.0));
        $this->assertSame([4294980000], $this->tfdtValues($segment));

        $tfdtAt = strpos($segment, 'tfdt');
        $this->assertSame(1, ord($segment[$tfdtAt + 4]), 'version byte');
        $this->assertSame(20, unpack('N', substr($segment, $tfdtAt - 4, 4))[1], 'v1 tfdt box is 20 bytes');
        $this->assertSame($trafBefore + 4, unpack('N', substr($segment, strpos($segment, 'traf') - 4, 4))[1]);
        $this->assertSame($before + 4, unpack('N', substr($segment, 0, 4))[1], 'moof size rewritten');
        $this->assertSame($before + 4, strlen($segment), 'the only growth is the tfdt box');
    }

    /**
     * At 12288 the last whole second that fits a version-0 field is 349,525 (4,294,963,200 ticks).
     */
    public function test_a_version_0_start_at_349525_seconds_at_12288_stays_version_0(): void
    {
        $segment = self::moof(self::traf(1, self::tfdt(0, 0)));

        $this->assertSame(1, Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 12288], 349525.0));
        $this->assertSame([4294963200], $this->tfdtValues($segment));
        $this->assertSame(0, ord($segment[strpos($segment, 'tfdt') + 4]));
    }

    /**
     * 349,526 s at 12288 is 4,294,975,488 ticks, past 0xFFFFFFFF, so the tfdt is rewritten to version 1.
     */
    public function test_a_version_0_start_at_349526_seconds_at_12288_is_rewritten_to_version_1(): void
    {
        $segment = self::moof(self::traf(1, self::tfdt(0, 0)));
        $before = strlen($segment);

        $this->assertSame(1, Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 12288], 349526.0));
        $this->assertSame([4294975488], $this->tfdtValues($segment));
        $this->assertSame($before + 4, strlen($segment));
    }

    /**
     * (b) The moof size and the trun data_offset. The trun is measured from the moof start
     * (default-base-is-moof). Growing the tfdt by 4 moves the mdat payload 4 bytes further from the
     * moof start, so data_offset must become old + 4, and it must still land on the sample bytes.
     */
    public function test_growing_a_tfdt_adjusts_the_moof_size_and_the_trun_data_offset(): void
    {
        $segment = self::trunFragment(500);
        $styp = strlen(self::box('styp', 'msdh'));
        $moofBefore = strlen(self::moofWithTrun(500, 0));
        $oldDataOffset = $moofBefore + 8;
        $this->assertSame(
            'SAMPLEDATA',
            substr($segment, $styp + $oldDataOffset, 10),
            'control: the fixture points at the sample bytes before the rebase'
        );

        $this->assertSame(1, Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 90000], 47722.0));

        $this->assertSame([4294980500], $this->tfdtValues($segment));
        $this->assertSame($moofBefore + 4, unpack('N', substr($segment, $styp, 4))[1], 'moof size +4');

        $trunField = strpos($segment, 'trun') + 12; // type(4) version+flags(4) sample_count(4) → data_offset
        $newDataOffset = unpack('N', substr($segment, $trunField, 4))[1];
        $this->assertSame($oldDataOffset + 4, $newDataOffset, 'trun data_offset +4');
        $this->assertSame(
            'SAMPLEDATA',
            substr($segment, $styp + $newDataOffset, 10),
            'the adjusted data_offset still lands on the sample bytes'
        );
        $this->assertSame('SAMPLEDATA', substr($segment, -10), 'the mdat payload is untouched');
    }

    /**
     * (c) Golden: a start that fits version 0 is rewritten exactly as the in-place field edit, with
     * no box resized and no data_offset touched. The sidx is covered too, with its reference table.
     */
    public function test_a_start_that_fits_version_0_is_byte_identical_to_the_in_place_golden(): void
    {
        $input = self::trunFragment(500);
        $expected = self::trunFragment(500 + 6 * self::VIDEO_TIMESCALE);

        $this->assertSame($expected, $this->rebased($input, [1 => self::VIDEO_TIMESCALE], 6.0));
    }

    public function test_a_version_0_sidx_and_fragment_that_fit_version_0_are_byte_identical(): void
    {
        $moof = self::moofWithTrun(500, strlen(self::moofWithTrun(500, 0)) + 8);
        $mdat = self::box('mdat', 'SAMPLEDATA');
        $refSize = strlen($moof) + strlen($mdat);

        $input = self::sidxWithRefs(0, self::VIDEO_TIMESCALE, 0, 0, [$refSize]) . $moof . $mdat;
        $movedMoof = self::moofWithTrun(500 + 6 * self::VIDEO_TIMESCALE, strlen($moof) + 8);
        $expected = self::sidxWithRefs(0, self::VIDEO_TIMESCALE, 6 * self::VIDEO_TIMESCALE, 0, [$refSize])
            . $movedMoof
            . $mdat;

        $this->assertSame($expected, $this->rebased($input, [1 => self::VIDEO_TIMESCALE], 6.0));
    }

    public function test_a_version_0_sidx_overflowing_its_32_bit_field_is_rewritten_to_version_1(): void
    {
        $segment = self::sidx(0, 1, self::VIDEO_TIMESCALE, 0xFFFFFFF0)
            . self::moof(self::traf(1, self::tfdt(1, 0)));
        $sidxBefore = strlen(self::sidx(0, 1, self::VIDEO_TIMESCALE, 0xFFFFFFF0));

        // one sidx + one tfdt
        $this->assertSame(2, Fmp4SegmentRebaser::rebaseBytes($segment, [1 => self::VIDEO_TIMESCALE], 6.0));

        $this->assertSame(1, ord($segment[8]), 'sidx is now version 1');
        $this->assertSame($sidxBefore + 8, unpack('N', substr($segment, 0, 4))[1], 'sidx grows by 8');
        $this->assertSame(0xFFFFFFF0 + 6 * self::VIDEO_TIMESCALE, unpack('J', substr($segment, 20, 8))[1]);
        $this->assertSame(0, unpack('J', substr($segment, 28, 8))[1], 'first_offset unchanged');
        $this->assertSame([6 * self::VIDEO_TIMESCALE], $this->tfdtValues($segment));
    }

    /**
     * first_offset can force promotion on its own. Earliest fits 32 bits here: 47,722 s at timescale
     * 12288 is 586,407,936 ticks. But first_offset is 0xFFFFFFF8, so the first referenced byte lies
     * about 4 GB past the sidx end. The two trafs (track 1, timescale 90000, tfdt v0 0, each grows by 4)
     * insert 8 bytes inside that range, so the rebased first_offset is 0xFFFFFFF8 + 8 = 0x100000000,
     * past 32 bits. The sidx must be promoted (+8, 40 bytes) rather than refused. Its own promotion
     * edit sits at the sidx end, which both shifts cancel, so the first_offset stays 0x100000000.
     *
     * Reachability: first_offset is a 32-bit field, so it can sit a few bytes below 2^32 while the
     * growth that pushes it over lies in a small buffer. This is the only route to that promotion.
     */
    public function test_a_version_0_sidx_is_promoted_when_only_its_first_offset_overflows(): void
    {
        $sidxBefore = self::sidxWithRefs(0, 12288, 0, 0xFFFFFFF8, []);
        $this->assertSame(32, strlen($sidxBefore), 'control: a v0 sidx with no references is 32 bytes');
        $segment = $sidxBefore . self::moof(self::traf(1, self::tfdt(0, 0)), self::traf(1, self::tfdt(0, 0)));
        $this->assertSame(104, strlen($segment) - 32, 'control: the moof is 104 bytes before the rebase');

        $this->assertSame(3, Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 90000], 47722.0));

        $this->assertSame(1, ord($segment[8]), 'sidx is now version 1');
        $this->assertSame(40, unpack('N', substr($segment, 0, 4))[1], 'sidx grew by 8');
        $this->assertSame(586407936, unpack('J', substr($segment, 20, 8))[1], 'earliest_presentation_time');
        $this->assertSame(4294967296, unpack('J', substr($segment, 28, 8))[1], 'first_offset = 0xFFFFFFF8 + 8');
        $this->assertSame(112, unpack('N', substr($segment, 40, 4))[1], 'moof grew by 8 (two tfdt)');
        $this->assertSame([4294980000, 4294980000], $this->tfdtValues($segment));
    }

    /**
     * (d) A sidx v0 for a long start, with a two-entry reference table. Both referenced subsegments
     * each contain a moof whose tfdt grows, so each referenced_size rises by 4. first_offset stays 0
     * because the sidx end moves with the first moof. The sidx goes to version 1 (+8).
     */
    public function test_a_long_start_rewrites_a_sidx_to_version_1_and_grows_its_reference_sizes(): void
    {
        $moof1 = self::moofWithTrun(0, strlen(self::moofWithTrun(0, 0)) + 8);
        $mdat1 = self::box('mdat', 'AAAA');
        $moof2 = self::moofWithTrun(0, strlen(self::moofWithTrun(0, 0)) + 8);
        $mdat2 = self::box('mdat', 'BBBBBBBB');
        $ref1 = strlen($moof1) + strlen($mdat1);
        $ref2 = strlen($moof2) + strlen($mdat2);

        // 90000 is the sidx and track timescale here: 47,722 s × 90000 = 4,294,980,000 is the first
        // whole second past the 32-bit limit at that timescale.
        $sidxBefore = self::sidxWithRefs(0, 90000, 0, 0, [$ref1, $ref2]);
        $segment = $sidxBefore . $moof1 . $mdat1 . $moof2 . $mdat2;
        $before = strlen($segment);

        // one sidx + two tfdt
        $this->assertSame(3, Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 90000], 47722.0));

        $this->assertSame(1, ord($segment[8]), 'sidx is now version 1');
        $this->assertSame(strlen($sidxBefore) + 8, unpack('N', substr($segment, 0, 4))[1]);
        $this->assertSame(
            4294980000,
            unpack('J', substr($segment, 20, 8))[1],
            'earliest_presentation_time is the 64-bit start'
        );
        $this->assertSame(0, unpack('J', substr($segment, 28, 8))[1], 'first_offset stays at the moof');
        $this->assertSame(2, unpack('n', substr($segment, 38, 2))[1], 'reference_count unchanged');
        $this->assertSame($ref1 + 4, unpack('N', substr($segment, 40, 4))[1], 'reference 1 grew with its moof');
        $this->assertSame($ref2 + 4, unpack('N', substr($segment, 52, 4))[1], 'reference 2 grew with its moof');
        $this->assertSame($before + 8 + 4 + 4, strlen($segment));
        $this->assertSame([4294980000, 4294980000], $this->tfdtValues($segment));
    }

    /**
     * (a) A `sidx` that immediately precedes the `moof`, with a version-0 `tfdt` that grows.
     *
     * Input layout: sidx v0 [0,32) with no references, timescale 90000, earliest 0. Its earliest
     * overflows 32 bits at 47,722 s, so it is promoted to v1 (+8). moof [32,116) = header 8 + mfhd 16
     * + traf 60 (header 8, tfhd 16, tfdt v0 16, trun 20); the trun data_offset is 92, so it reaches the
     * mdat payload at 32 + 92 = 124. mdat [116,134) holds 'SAMPLEDATA' at 124.
     *
     * Output layout: sidx 40 bytes; moof at 40 is 84 + 4 = 88 bytes (the tfdt grows to v1); the mdat
     * payload is at 40 + 88 + 8 = 136. The trun data_offset is measured from the moof start, so it is
     * 136 - 40 = 96.
     */
    public function test_a_trun_data_offset_follows_a_sidx_and_a_growing_tfdt_before_the_moof(): void
    {
        $moof = self::moofWithTrun(500, 92);
        $this->assertSame(84, strlen($moof), 'control: the moof is 84 bytes');
        $sidxBefore = self::sidx(0, 1, 90000, 0);
        $this->assertSame(32, strlen($sidxBefore), 'control: a v0 sidx with no references is 32 bytes');
        $segment = $sidxBefore . $moof . self::box('mdat', 'SAMPLEDATA');

        $this->assertSame(2, Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 90000], 47722.0));

        $this->assertSame(1, ord($segment[8]), 'sidx is now version 1');
        $this->assertSame(40, unpack('N', substr($segment, 0, 4))[1], 'sidx grew by 8');
        $this->assertSame(88, unpack('N', substr($segment, 40, 4))[1], 'moof grew by 4');
        $trunField = strpos($segment, 'trun');
        $this->assertIsInt($trunField);
        $this->assertSame(96, unpack('N', substr($segment, $trunField + 12, 4))[1], 'trun data_offset is 96');
        $this->assertSame(
            'SAMPLEDATA',
            substr($segment, 40 + 96, 10),
            'the adjusted data_offset lands on the sample bytes'
        );
        $this->assertSame([4294980500], $this->tfdtValues($segment));
    }

    /**
     * (b) A `moof` with TWO trafs, each with a trun, both with default-base-is-moof (0x020000).
     *
     * Traf A is track 1 (timescale 90000): its tfdt v0 0 overflows at 47,722 s, so it grows by 4.
     * Traf B is track 2 (timescale 12288): 47,722 s is 586,407,936 ticks, which fits v0, so it does not
     * grow. Input: moof [0,144) = header 8 + mfhd 16 + traf A 60 + traf B 60; mdat [144,152) with the
     * payload 'AAAABBBB' at 152. Trun A points at 152 ('AAAA') and trun B at 156 ('BBBB').
     * Output: the moof is 144 + 4 = 148 and the payload starts at 156. Both data_offsets move by the
     * moof growth alone: A becomes 156 and B becomes 160.
     */
    public function test_two_trafs_with_default_base_is_moof_both_move_by_the_moof_growth_only(): void
    {
        $trafA = self::box(
            'traf',
            self::tfhdWithFlags(1, 0x020000) . self::tfdt(0, 0) . self::trunWithDataOffset(152)
        );
        $trafB = self::box(
            'traf',
            self::tfhdWithFlags(2, 0x020000) . self::tfdt(0, 0) . self::trunWithDataOffset(156)
        );
        $segment = self::box('moof', self::fullBox('mfhd', 0, pack('N', 1)) . $trafA . $trafB)
            . self::box('mdat', 'AAAABBBB');
        $this->assertSame(144, strpos($segment, 'mdat') - 4, 'control: the moof is 144 bytes');

        $this->assertSame(2, Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 90000, 2 => 12288], 47722.0));

        $this->assertSame(148, unpack('N', substr($segment, 0, 4))[1], 'moof grew by 4, from traf A only');
        $trunA = strpos($segment, 'trun');
        $this->assertIsInt($trunA);
        $trunB = strpos($segment, 'trun', $trunA + 1);
        $this->assertIsInt($trunB);
        $this->assertSame(156, unpack('N', substr($segment, $trunA + 12, 4))[1], 'trun A: 152 + 4');
        $this->assertSame(160, unpack('N', substr($segment, $trunB + 12, 4))[1], 'trun B: 156 + 4');
        $this->assertSame('AAAA', substr($segment, 156, 4), 'trun A still lands on its samples');
        $this->assertSame('BBBB', substr($segment, 160, 4), 'trun B still lands on its samples');
        $this->assertSame([4294980000, 586407936], $this->tfdtValues($segment));
    }

    /**
     * (c) A second traf with NEITHER base flag, which takes the code path at the base-flag check in
     * planTraf(). ISO/IEC 14496-12 8.8.7.1: the first traf's base is the moof start, and a later traf's
     * base is the end of the previous traf's data. That end lies in the mdat, after the grown boxes, so it
     * moves with the samples it follows. The later data_offset stays correct, so it must NOT be adjusted.
     * The code leaves it alone: only the first traf, or a traf with default-base-is-moof, is adjusted.
     *
     * Input layout, all offsets from the start of the segment:
     * - moof [0,148) = header 8 + mfhd 16 + traf A 64 + traf B 60. Traf A = header 8 + tfhd 16 + tfdt v0 16
     *   + trun 24 (flags 0x000201, one sample of 4 bytes). Traf B = header 8 + tfhd 16 + tfdt v0 16 + trun 20.
     * - traf A (tfhd flags 0, first) has trun data_offset 156 = moof end 148 + mdat header 8, so its one
     *   4-byte sample is [156,160) = 'AAAA'. Traf A covers exactly that data.
     * - traf B (tfhd flags 0, not first) has trun data_offset 4 and no sample size. Its base is the end of
     *   traf A's data, 156 + 4 = 160, so 160 + 4 = 164 = 'BBBB'.
     * - mdat [148,168) = header 8 + payload 'AAAApad!BBBB' (12 bytes): 'AAAA' [156,160), 'pad!' [160,164),
     *   'BBBB' [164,168).
     * Output: traf A's tfdt grows (track 1, timescale 90000, 47,722 s is 4,294,980,000 ticks, past 32 bits),
     * so the moof grows by 4 to 152 and the mdat payload moves to 160: 'AAAA' [160,164), 'pad!' [164,168),
     * 'BBBB' [168,172). Trun A becomes 156 + 4 = 160, since the growth lies before 156. Traf B's base is the
     * end of traf A's data, 160 + 4 = 164, and its data_offset stays 4, which reaches 164 + 4 = 168 = 'BBBB'.
     */
    public function test_a_later_traf_with_neither_base_flag_is_left_unadjusted(): void
    {
        $trafA = self::box(
            'traf',
            self::tfhdWithFlags(1, 0) . self::tfdt(0, 0) . self::trunWithDataOffsetAndSize(156, 4)
        );
        $trafB = self::box(
            'traf',
            self::tfhdWithFlags(2, 0) . self::tfdt(0, 0) . self::trunWithDataOffset(4)
        );
        $segment = self::box('moof', self::fullBox('mfhd', 0, pack('N', 1)) . $trafA . $trafB)
            . self::box('mdat', 'AAAApad!BBBB');
        $this->assertSame(148, strpos($segment, 'mdat') - 4, 'control: the moof is 148 bytes');
        $this->assertSame('AAAA', substr($segment, 156, 4), 'control: traf A samples before the rebase');
        $this->assertSame('BBBB', substr($segment, 164, 4), 'control: traf B samples before the rebase');

        $this->assertSame(2, Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 90000, 2 => 12288], 47722.0));

        $this->assertSame(152, unpack('N', substr($segment, 0, 4))[1], 'moof grew by 4, from traf A');
        $trunA = strpos($segment, 'trun');
        $this->assertIsInt($trunA);
        $trunB = strpos($segment, 'trun', $trunA + 1);
        $this->assertIsInt($trunB);
        // trun data_offset sits 12 bytes after the 'trun' type string: size(4) type(4) flags(4) count(4) → offset.
        $dataOffsetA = unpack('N', substr($segment, $trunA + 12, 4))[1];
        $dataOffsetB = unpack('N', substr($segment, $trunB + 12, 4))[1];
        $this->assertSame(160, $dataOffsetA, 'first traf: 156 + 4 (the tfdt growth lies before it)');
        $this->assertSame(4, $dataOffsetB, 'later traf is not adjusted');
        $this->assertSame('AAAA', substr($segment, $dataOffsetA, 4), 'first traf lands on its samples');
        // Traf B's base is the end of traf A's data: traf A's 4-byte sample ends at 160 + 4.
        $baseB = $dataOffsetA + 4;
        $this->assertSame(164, $baseB, 'base of traf B: 160 + 4');
        $this->assertSame('BBBB', substr($segment, $baseB + $dataOffsetB, 4), 'later traf lands on its samples');
    }

    /**
     * A `base_data_offset` is absolute, so a growth before it would move it. Refuse rather than guess.
     */
    public function test_a_growth_before_an_absolute_base_data_offset_is_refused(): void
    {
        $tfhdWithBase = self::box('tfhd', pack('N', 0x000001) . pack('N', 1) . pack('J', 0));
        $segment = self::box(
            'moof',
            self::fullBox('mfhd', 0, pack('N', 1)) . self::box('traf', $tfhdWithBase . self::tfdt(0, 0))
        );

        $this->expectException(\RangeException::class);
        $this->expectExceptionMessageMatches('/base_data_offset/');
        Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 90000], 47722.0);
    }

    /**
     * ISO/IEC 14496-12: a sidx version-1 field is 64 bits. Its own timescale applies, not the init's.
     */
    public function test_sidx_earliest_presentation_time_moves_with_the_fragment(): void
    {
        $segment = self::sidx(1, 1, self::VIDEO_TIMESCALE, 900)
            . self::sidx(0, 2, self::AUDIO_TIMESCALE, 800)
            . self::moof(self::traf(1, self::tfdt(1, 0)));

        $out = $this->rebased($segment, [1 => self::VIDEO_TIMESCALE, 2 => self::AUDIO_TIMESCALE], 6.0);

        // The sidx carries its OWN timescale, so it is read from the box, not
        // from the init map.
        $this->assertSame(900 + 6 * self::VIDEO_TIMESCALE, unpack('J', substr($out, 20, 8))[1]);
        // From offset 8, so the search cannot re-find the FIRST box's own type
        // string (which sits at offset 4).
        $sidx0Offset = strpos($out, 'sidx', 8);
        $this->assertIsInt($sidx0Offset);
        $this->assertSame(800 + 6 * self::AUDIO_TIMESCALE, unpack('N', substr($out, $sidx0Offset + 16, 4))[1]);
    }

    public function test_a_sidx_with_a_zero_timescale_is_rejected(): void
    {
        $segment = self::sidx(1, 1, 0, 0) . self::moof(self::traf(1, self::tfdt(1, 0)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/zero timescale/');
        $this->rebased($segment, [1 => 12288], 6.0);
    }

    /**
     * A version-1 first_offset at or above 2^63 has bit 63 set, so it reads back as a negative int. That is
     * past the signed range this rebaser supports, and the refusal must name that condition rather than report
     * a truncated box. It is a RuntimeException (exit 1), not a RangeException, and the input is left unchanged.
     */
    public function test_a_version_1_sidx_first_offset_past_the_signed_range_is_refused(): void
    {
        $segment = self::sidxWithRefs(1, self::VIDEO_TIMESCALE, 0, PHP_INT_MIN, [])
            . self::moof(self::traf(1, self::tfdt(1, 0)));
        $before = $segment;

        $caught = null;
        try {
            Fmp4SegmentRebaser::rebaseBytes($segment, [1 => self::VIDEO_TIMESCALE], 6.0);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertNotInstanceOf(\RangeException::class, $caught);
        self::assertStringContainsString('exceeds the signed 64-bit range', $caught->getMessage());
        self::assertStringNotContainsString('truncated', $caught->getMessage());
        self::assertSame($before, $segment);
    }

    /**
     * A version-1 first_offset just below PHP_INT_MAX: the sidx end plus first_offset is past PHP_INT_MAX
     * (40 + PHP_INT_MAX - 10). Plain integer addition would make that sum a float, which a strict int argument
     * rejects with a TypeError. The sum is checked first, so the refusal is a RuntimeException and nothing
     * is written.
     */
    public function test_a_version_1_first_offset_near_php_int_max_is_refused_not_a_type_error(): void
    {
        $segment = self::sidxWithRefs(1, self::VIDEO_TIMESCALE, 0, PHP_INT_MAX - 10, [])
            . self::moof(self::traf(1, self::tfdt(1, 0)));
        $before = $segment;

        $caught = null;
        try {
            Fmp4SegmentRebaser::rebaseBytes($segment, [1 => self::VIDEO_TIMESCALE], 6.0);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertStringContainsString('overflows the signed 64-bit range', $caught->getMessage());
        self::assertSame($before, $segment);
    }

    /**
     * The reference walk adds each referenced_size to a running position. With a 52-byte sidx (one reference),
     * anchor 52 + first_offset (PHP_INT_MAX - 100) = PHP_INT_MAX - 48 still fits, but adding the reference's
     * 0x7FFFFFFF bytes takes it past PHP_INT_MAX. That addition is checked too: a RuntimeException, nothing written.
     */
    public function test_a_version_1_reference_walk_past_php_int_max_is_refused(): void
    {
        $segment = self::sidxWithRefs(1, self::VIDEO_TIMESCALE, 0, PHP_INT_MAX - 100, [0x7FFFFFFF])
            . self::moof(self::traf(1, self::tfdt(1, 0)));
        $before = $segment;

        $caught = null;
        try {
            Fmp4SegmentRebaser::rebaseBytes($segment, [1 => self::VIDEO_TIMESCALE], 6.0);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertStringContainsString('overflows the signed 64-bit range', $caught->getMessage());
        self::assertSame($before, $segment);
    }

    // ─────────────────────────────────────────────────────────────────
    // refusals
    // ─────────────────────────────────────────────────────────────────

    /**
     * A tfdt of version 2 or later is not a layout this rebaser knows: ISO/IEC 14496-12 defines only
     * versions 0 and 1. Reading it at 8 bytes would change the wrong bytes, so it is refused with a
     * RuntimeException (exit 1, a malformed file) and the input is left unchanged.
     */
    public function test_a_tfdt_of_version_2_is_refused_and_the_input_is_unchanged(): void
    {
        $segment = self::moof(self::traf(1, self::tfdt(2, 0)));
        $before = $segment;

        $caught = null;
        try {
            Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 12288], 6.0);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertStringContainsString('tfdt version 2', $caught->getMessage());
        self::assertSame($before, $segment);
    }

    /**
     * A sidx of version 2 or later is refused the same way, with nothing written. The sidx is
     * planned first, so the refusal happens before any traf is read.
     */
    public function test_a_sidx_of_version_2_is_refused_and_the_input_is_unchanged(): void
    {
        $segment = self::sidx(2, 1, self::VIDEO_TIMESCALE, 0) . self::moof(self::traf(1, self::tfdt(1, 0)));
        $before = $segment;

        $caught = null;
        try {
            Fmp4SegmentRebaser::rebaseBytes($segment, [1 => self::VIDEO_TIMESCALE], 6.0);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertStringContainsString('sidx version 2', $caught->getMessage());
        self::assertSame($before, $segment);
    }

    /**
     * "Rewrote nothing" must never read as success. A fragment with no `tfdt` is
     * either not a CMAF fragment or a walk that silently found nothing, and
     * publishing it would put an un-rebased segment on the timeline.
     */
    public function test_a_fragment_with_no_timestamp_is_rejected(): void
    {
        $segment = self::box('styp', 'msdh') . self::box('mdat', 'payload');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no tfdt\/sidx timestamp/');
        $this->rebased($segment, [1 => 12288], 6.0);
    }

    public function test_a_traf_for_a_track_absent_from_the_init_is_rejected(): void
    {
        $segment = self::moof(self::traf(7, self::tfdt(1, 0)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/track_ID 7 absent from the init segment/');
        $this->rebased($segment, [1 => 12288], 6.0);
    }

    public function test_a_box_that_overruns_the_buffer_is_rejected(): void
    {
        $segment = pack('N', 4096) . 'moof' . 'short';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/overruns the buffer/');
        $this->rebased($segment, [1 => 12288], 6.0);
    }

    public function test_a_negative_offset_is_rejected(): void
    {
        $segment = self::moof(self::traf(1, self::tfdt(1, 0)));

        $this->expectException(\RangeException::class);
        $this->expectExceptionMessageMatches('/negative start offset/');
        $this->rebased($segment, [1 => 12288], -1.0);
    }

    public function test_a_missing_file_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist/');
        Fmp4SegmentRebaser::trackTimescales('/nonexistent/init.m4s');
    }

    public function test_an_empty_file_is_rejected(): void
    {
        $path = $this->tempFile('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty or unreadable/');
        Fmp4SegmentRebaser::trackTimescales($path);
    }

    // ─────────────────────────────────────────────────────────────────
    // the file-level entry point
    // ─────────────────────────────────────────────────────────────────

    /**
     * The start is validated before either file is read. The paths do not exist, so a read would fail
     * with a RuntimeException; the RangeException here proves no read happened first. RangeException is
     * the type the CLI maps to exit 2.
     */
    public function test_a_negative_start_through_rebase_is_refused_before_any_file_is_read(): void
    {
        $this->expectException(\RangeException::class);
        $this->expectExceptionMessageMatches('/negative start offset/');
        Fmp4SegmentRebaser::rebase('/nonexistent/segment.m4s', '/nonexistent/init.m4s', -1.0);
    }

    public function test_a_non_finite_start_through_rebase_is_refused_before_any_file_is_read(): void
    {
        $this->expectException(\RangeException::class);
        $this->expectExceptionMessageMatches('/non-finite start offset/');
        Fmp4SegmentRebaser::rebase('/nonexistent/segment.m4s', '/nonexistent/init.m4s', INF);
    }

    public function test_rebase_writes_the_segment_back_and_leaves_the_init_untouched(): void
    {
        $initBytes = self::init();
        $initPath = $this->tempFile($initBytes);
        $segmentBytes = self::box('styp', 'msdh')
            . self::moof(self::traf(1, self::tfdt(1, 0)), self::traf(2, self::tfdt(1, 0)))
            . self::box('mdat', str_repeat('z', 64));
        $segmentPath = $this->tempFile($segmentBytes);

        $rewritten = Fmp4SegmentRebaser::rebase($segmentPath, $initPath, 12.0);

        $this->assertSame(2, $rewritten);
        $this->assertSame($initBytes, (string) file_get_contents($initPath), 'the init must never be rewritten');
        $this->assertSame(
            [12 * self::VIDEO_TIMESCALE, 12 * self::AUDIO_TIMESCALE],
            $this->tfdtValues((string) file_get_contents($segmentPath))
        );
        $this->assertSame(
            strlen($segmentBytes),
            strlen((string) file_get_contents($segmentPath)),
            'the rebase is a field rewrite, never a resize'
        );
    }

    /** @var list<string> */
    private array $tempFiles = [];

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phlix_s56_');
        $this->assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Calls the private ticks() directly. The value it returns is the byte arithmetic's
     * delta, so both the normal and the refused cases are pinned here.
     */
    private static function ticks(float $startSeconds, int $timescale): int
    {
        $method = new \ReflectionMethod(Fmp4SegmentRebaser::class, 'ticks');
        /** @var int $ticks */
        $ticks = $method->invoke(null, $startSeconds, $timescale);

        return $ticks;
    }

    public function test_ticks_of_a_normal_offset_is_unchanged(): void
    {
        $this->assertSame(6 * 90000, self::ticks(6.0, 90000));
        // 6.5 s × 12288 = 79872, the same whole-tick case as above.
        $this->assertSame(79872, self::ticks(6.5, 12288));
    }

    /**
     * 1e30 s × 90000 is finite but far past PHP_INT_MAX. An (int) cast would return 0 or a
     * wrapped value, and the segment would publish with no shift at all, so it must throw.
     */
    public function test_ticks_rejects_an_offset_beyond_the_int_range(): void
    {
        $this->expectException(\RangeException::class);
        $this->expectExceptionMessageMatches('/out of range/');
        self::ticks(1.0e30, 90000);
    }

    public function test_ticks_rejects_an_infinite_offset(): void
    {
        $this->expectException(\RangeException::class);
        $this->expectExceptionMessageMatches('/out of range/');
        self::ticks(INF, 90000);
    }

    /**
     * The largest double below 2^63 is exact and casts to an int; 2^63 itself would wrap.
     */
    public function test_ticks_accepts_the_largest_representable_value_below_2_to_the_63(): void
    {
        $this->assertSame(PHP_INT_MAX - 1023, self::ticks((float) (PHP_INT_MAX - 1023), 1));
    }

    public function test_ticks_rejects_2_to_the_63_exactly(): void
    {
        $this->expectException(\RangeException::class);
        self::ticks((float) PHP_INT_MAX, 1);
    }

    public function test_ticks_accepts_negative_2_to_the_63_exactly(): void
    {
        $this->assertSame(PHP_INT_MIN, self::ticks((float) PHP_INT_MIN, 1));
    }

    public function test_ticks_rejects_a_negative_value_past_the_int_range(): void
    {
        $this->expectException(\RangeException::class);
        self::ticks(-1.0e19, 1);
    }

    /**
     * A start beyond the 64-bit range is still refused, with nothing written.
     */
    public function test_a_huge_start_leaves_the_box_bytes_unchanged(): void
    {
        $segment = self::moof(self::traf(1, self::tfdt(1, 0)));
        $before = $segment;

        $caught = null;
        try {
            Fmp4SegmentRebaser::rebaseBytes($segment, [1 => self::VIDEO_TIMESCALE], 1.0e30);
        } catch (\RangeException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(\RangeException::class, $caught);
        self::assertStringContainsString('out of range', $caught->getMessage());
        self::assertSame($before, $segment);
    }

    public function test_a_version_1_timestamp_overflowing_the_unsigned_64_bit_field_is_rejected(): void
    {
        // 0xFFFFFFFFFFFFFFF0 + 0x20 = 2^64 + 0x10, past the unsigned field. The bytes must survive.
        $segment = self::moof(self::traf(1, self::tfdt(1, -16)));
        $before = $segment;

        $caught = null;
        try {
            Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 1], 32.0);
        } catch (\RangeException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(\RangeException::class, $caught);
        self::assertStringContainsString('64-bit timestamp overflows', $caught->getMessage());
        self::assertSame($before, $segment);
    }

    public function test_a_version_1_timestamp_that_stays_in_the_unsigned_range_is_rebased(): void
    {
        // 0xFFFFFFFFFFFFFFF0 + 0x0F = 0xFFFFFFFFFFFFFFFF, the largest value the field holds.
        $segment = self::moof(self::traf(1, self::tfdt(1, -16)));

        $written = Fmp4SegmentRebaser::rebaseBytes($segment, [1 => 1], 15.0);

        self::assertSame(1, $written);
        self::assertSame([-1], $this->tfdtValues($segment));
    }

    public function test_a_version_1_timestamp_past_the_signed_range_but_below_2_to_the_64_is_rebased(): void
    {
        // PHP_INT_MAX - 10 + 73728 = 2^63 + 73717. It is past PHP_INT_MAX (signed overflow) yet
        // in range for the unsigned field, so it must be written, as its negative bit pattern.
        $segment = self::moof(self::traf(1, self::tfdt(1, PHP_INT_MAX - 10)));

        $written = Fmp4SegmentRebaser::rebaseBytes($segment, [1 => self::VIDEO_TIMESCALE], 6.0);

        self::assertSame(1, $written);
        self::assertSame([PHP_INT_MIN + 73717], $this->tfdtValues($segment));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }
}
