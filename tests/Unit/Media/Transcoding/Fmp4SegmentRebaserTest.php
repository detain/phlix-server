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
 * version-0 boxes, 64-bit `tkhd`/`mdhd`, an overflowing 32-bit field, a
 * truncated box — plus the arithmetic itself, which real output cannot pin
 * sharply because ffmpeg always hands us a `tfdt` of exactly 0.
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

    private static function tfdt(int $version, int $value): string
    {
        return self::fullBox('tfdt', $version, $version === 1 ? pack('J', $value) : pack('N', $value));
    }

    private static function traf(int $trackId, string $tfdt): string
    {
        return self::box('traf', self::tfhd($trackId) . $tfdt);
    }

    private static function moof(string ...$trafs): string
    {
        return self::box('moof', self::fullBox('mfhd', 0, pack('N', 1)) . implode('', $trafs));
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

    public function test_the_offset_is_rounded_to_whole_ticks(): void
    {
        $segment = self::moof(self::traf(1, self::tfdt(1, 0)));

        // 6.5 s × 12288 = 79872 exactly; 0.0001 s × 12288 = 1.2288 → 1.
        $this->assertSame([79872], $this->tfdtValues($this->rebased($segment, [1 => 12288], 6.5)));
        $this->assertSame([1], $this->tfdtValues($this->rebased($segment, [1 => 12288], 0.0001)));
    }

    public function test_a_version_0_tfdt_is_rebased_in_its_32_bit_field(): void
    {
        $segment = self::moof(self::traf(1, self::tfdt(0, 500)));

        $this->assertSame([500 + 6 * 12288], $this->tfdtValues($this->rebased($segment, [1 => 12288], 6.0)));
    }

    /**
     * A 32-bit field cannot be widened without moving every byte after it, so
     * the publish must fail loudly rather than wrap the timestamp — a wrapped
     * `tfdt` is a fragment that plays at the wrong time with no error anywhere.
     */
    public function test_an_overflowing_version_0_tfdt_is_rejected(): void
    {
        $segment = self::moof(self::traf(1, self::tfdt(0, 0xFFFFFFF0)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/overflows its 32-bit field/');
        $this->rebased($segment, [1 => 12288], 6.0);
    }

    // ─────────────────────────────────────────────────────────────────
    // sidx
    // ─────────────────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────────────────
    // refusals
    // ─────────────────────────────────────────────────────────────────

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

        $this->expectException(\RuntimeException::class);
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

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }
}
