<?php

/**
 * Phlix media server component: Media\Transcoding.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

/**
 * Rebases one independently-encoded CMAF fragment onto its position on the VOD
 * timeline, by rewriting `moof/traf/tfdt@baseMediaDecodeTime` and every
 * top-level `sidx@earliest_presentation_time`.
 *
 * ## Why this exists at all — the measurement that forced it
 *
 * Phlix produces every on-demand segment as its OWN short `ffmpeg -ss <start>
 * -t <len>` run (see {@see FfmpegRunner::startSegmentEncode()}). ffmpeg's mp4
 * muxer always normalises the FIRST fragment of a file to
 * `baseMediaDecodeTime = 0`, so every independently-encoded segment claims to
 * start at t=0. Measured on ffmpeg 6.1.1 against six different muxer
 * configurations — `-f hls -hls_segment_type fmp4`, `-f dash`, `-f mp4
 * -movflags +frag_keyframe+empty_moov+dash`, with and without
 * `-output_ts_offset`, `-copyts -avoid_negative_ts disabled`, and
 * `+frag_discont` — **all six wrote `tfdt = 0`**. (Positive control, so the
 * finding is about independent encodes rather than the parser: within ONE
 * continuous `-hls_time 6` encode of the same clip, segment 1's video `tfdt`
 * is 73728 = 6 s × 12288, exactly as expected.)
 *
 * `-output_ts_offset` is worse than merely ineffective here: it does survive,
 * but as an EMPTY EDIT in the `moov`'s `elst` — i.e. inside the INIT segment,
 * which is shared by every segment of the variant. A 6-second offset baked
 * into the init would be applied to all of them. So the fMP4 branch of
 * {@see FfmpegRunner::buildSegmentCommand()} deliberately drops
 * `-output_ts_offset`, which makes the init byte-identical for every segment
 * index (verified: md5 of the init produced at `-ss 0` and at `-ss 6` match),
 * and the offset is instead written into the fragment here, where it belongs.
 *
 * Without this, a DASH client resolving `SegmentTemplate@duration × number`
 * appends every segment at t=0 — total playback failure — and an MSE-based
 * HLS client fares no better.
 *
 * ## Scope and field widths
 *
 * A narrow byte editor, not an ISO-BMFF library. It walks the top-level box
 * list, descends `moof → traf`, and rewrites:
 *
 * - `tfdt@baseMediaDecodeTime` — 32-bit at version 0, 64-bit at version 1; other versions are refused.
 * - `sidx@earliest_presentation_time` — 32-bit at version 0, 64-bit at version 1; other versions are refused.
 * - `trun@data_offset` and `sidx` reference sizes, only where a box growth below
 *   moves the bytes they point at.
 *
 * A rebased value that does not fit a version-0 field is written as version 1
 * instead, which grows the box (see {@see self::planTraf()} and
 * {@see self::finishSidx()} for the exact byte rules). Every other byte of the
 * segment is returned untouched, and a segment whose start fits version 0
 * everywhere is rewritten in place exactly as before.
 *
 * The only start that is refused is one that is not representable at all: a
 * non-finite or negative start (refused before any file is read), a scaled tick
 * count outside the signed 64-bit range (see {@see self::ticks()}), or a rebased
 * 64-bit field that overflows its unsigned range (see {@see self::addUint64()}).
 * A fragment that carries an absolute `base_data_offset` (a `tfhd` with flag 0x000001) is refused whenever
 * the rebase grows ANY box in it, including a box after that `tfhd`: an absolute base is not adjusted by this
 * rebaser, so a refusal is preferred to a guessed value. Such a fragment is rewritten only when nothing grows,
 * which is the case when every timestamp fits version 0.
 * A `tfdt` or `sidx` of version 2 or later is malformed for this rebaser and is
 * refused with a RuntimeException (exit 1), because ISO/IEC 14496-12 defines only
 * versions 0 and 1 for both boxes.
 *
 * @phpstan-type Plan array{
 *     edits: list<array{int, int}>,
 *     patches: list<array{int, int, string}>,
 *     dataOffsets: list<array{int, int, int}>,
 *     absoluteBase: bool,
 *     rewritten: int
 * }
 * @phpstan-type SidxPlan array{
 *     offset: int,
 *     size: int,
 *     header: int,
 *     version: int,
 *     timescale: int,
 *     earliest: int,
 *     firstOffset: int,
 *     promoted: bool
 * }
 *
 * @package Phlix\Media\Transcoding
 * @since S56
 */
final class Fmp4SegmentRebaser
{
    /** Box header: 4-byte size + 4-byte type. */
    private const HEADER_BYTES = 8;

    /** Largest segment we will read into memory (a 6 s CMAF fragment is ~1 MB). */
    private const MAX_SEGMENT_BYTES = 256 * 1024 * 1024;

    /**
     * Adds `$startSeconds` to every fragment timestamp in `$segmentPath`.
     *
     * @param string $segmentPath  The `moof`+`mdat` media segment to rewrite in place.
     * @param string $initPath     The matching `ftyp`+`moov` init segment; read
     *                             (never written) to resolve each track's media
     *                             timescale, since a fragment carries none.
     * @param float  $startSeconds The segment's start on the VOD timeline.
     *
     * @return int Number of timestamp fields rewritten. Zero is never a success:
     *             a well-formed fragment always has at least one `tfdt`.
     *
     * @throws \RangeException   When the start cannot be represented at all: a non-finite or negative
     *                           start (refused before either file is read), the scaled ticks lie
     *                           outside the signed 64-bit range, a rebased 64-bit field would exceed
     *                           2^64 - 1, or a fragment-driven field overflows once the grown boxes are
     *                           measured: a trun data_offset outside the signed 32-bit range, a sidx
     *                           first_offset outside its 32-bit field, a sidx referenced_size outside its
     *                           31-bit field, or a box growth that would need an adjustment this rebaser
     *                           does not make (a base_data_offset; see the class docblock). Nothing is
     *                           written. The CLI maps this to exit 2.
     * @throws \RuntimeException For any other failure: unreadable, malformed or truncated files,
     *                           a tfdt or sidx whose version is neither 0 nor 1, or no rewritable
     *                           timestamp. The CLI maps this to exit 1.
     */
    public static function rebase(string $segmentPath, string $initPath, float $startSeconds): int
    {
        self::assertStart($startSeconds);
        $timescales = self::trackTimescales($initPath);
        $segment = self::read($segmentPath);

        $rewritten = self::rebaseBytes($segment, $timescales, $startSeconds);

        if (file_put_contents($segmentPath, $segment) === false) {
            throw new \RuntimeException("fmp4 rebase: cannot write {$segmentPath}");
        }

        return $rewritten;
    }

    /**
     * The in-memory half of {@see self::rebase()}: rewrites `$segment` by
     * reference and reports how many fields changed. Split out so the byte
     * arithmetic is unit-testable without touching the filesystem.
     *
     * The whole edit is planned against the original bytes first and only then
     * assembled, so `$segment` is left UNCHANGED when this method throws.
     *
     * @param string             $segment      Raw fragment bytes, rewritten in place.
     * @param array<int, int>    $timescales   `track_ID => media timescale`.
     * @param float              $startSeconds Timeline offset to add.
     *
     * @throws \RangeException   When the start is non-finite or negative, or cannot be represented, or a
     *                           fragment-driven field overflows: a trun data_offset, a sidx first_offset,
     *                           a sidx referenced_size, or a base_data_offset that a grown box would move
     *                           (see {@see self::rebase()}).
     * @throws \RuntimeException On a malformed fragment.
     */
    public static function rebaseBytes(string &$segment, array $timescales, float $startSeconds): int
    {
        self::assertStart($startSeconds);

        /** @var Plan $plan */
        $plan = ['edits' => [], 'patches' => [], 'dataOffsets' => [], 'absoluteBase' => false, 'rewritten' => 0];
        $sidxs = [];

        foreach (self::topLevelBoxes($segment) as [$type, $offset, $size, $header]) {
            if ($type === 'sidx') {
                $sidxs[] = self::planSidxTime($segment, $offset, $size, $header, $startSeconds);
                $plan['rewritten']++;
            } elseif ($type === 'moof') {
                self::planMoof($segment, $offset, $size, $header, $timescales, $startSeconds, $plan);
            }
        }

        if ($plan['rewritten'] === 0) {
            throw new \RuntimeException('fmp4 rebase: no tfdt/sidx timestamp found — not a CMAF fragment?');
        }

        // A version-0 sidx is promoted when either field needs 64 bits: earliest_presentation_time (decided
        // by planSidxTime) or first_offset (decided here, against the edits planned so far). Promoting a sidx
        // inserts bytes at its end, which can sit inside another sidx's referenced range and move its
        // first_offset, so the first_offset test repeats until nothing more is promoted. Each pass only adds
        // edits, so the loop terminates.
        foreach ($sidxs as $sidx) {
            if ($sidx['promoted']) {
                // version 1 adds 4 bytes to each of earliest_presentation_time and first_offset: 8 at the sidx end.
                $plan['edits'][] = [$sidx['offset'] + $sidx['size'], 8];
            }
        }
        do {
            $changed = false;
            foreach ($sidxs as $i => $sidx) {
                if ($sidxs[$i]['promoted'] || $sidx['version'] !== 0) {
                    continue;
                }
                $anchor = $sidx['offset'] + $sidx['size'];
                $newFirst = self::shift($plan['edits'], $anchor + $sidx['firstOffset'])
                    - self::shift($plan['edits'], $anchor);
                if ($newFirst > 0xFFFFFFFF) {
                    $sidxs[$i]['promoted'] = true;
                    $plan['edits'][] = [$anchor, 8];
                    $changed = true;
                }
            }
        } while ($changed);

        if ($plan['absoluteBase'] && $plan['edits'] !== []) {
            throw new \RangeException('fmp4 rebase: cannot adjust a base_data_offset when a box before it grows');
        }

        // trun@data_offset is relative to the moof start (default-base-is-moof, or the first traf with
        // neither base flag). The new offset is the same distance measured in the grown file.
        foreach ($plan['dataOffsets'] as [$field, $base, $value]) {
            $adjusted = self::shift($plan['edits'], $base + $value) - self::shift($plan['edits'], $base);
            if ($adjusted === $value) {
                continue;
            }
            if ($adjusted < -0x80000000 || $adjusted > 0x7FFFFFFF) {
                throw new \RangeException('fmp4 rebase: trun data_offset would overflow its signed 32-bit field');
            }
            $plan['patches'][] = [$field, $field + 4, pack('N', $adjusted & 0xFFFFFFFF)];
        }

        foreach ($sidxs as $sidx) {
            $patch = self::finishSidx($segment, $sidx, $plan['edits']);
            if ($patch !== null) {
                $plan['patches'][] = $patch;
            }
        }

        usort($plan['patches'], static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $out = '';
        $cursor = 0;
        foreach ($plan['patches'] as [$start, $end, $bytes]) {
            if ($start < $cursor) {
                throw new \RuntimeException('fmp4 rebase: overlapping edits');
            }
            $out .= substr($segment, $cursor, $start - $cursor) . $bytes;
            $cursor = $end;
        }
        $segment = $out . substr($segment, $cursor);

        return $plan['rewritten'];
    }

    /**
     * Reads each track's media timescale out of an init segment's
     * `moov/trak/{tkhd,mdia/mdhd}` pair.
     *
     * @return array<int, int> `track_ID => timescale`, never empty.
     *
     * @throws \RuntimeException When the init is unreadable or carries no track.
     */
    public static function trackTimescales(string $initPath): array
    {
        $init = self::read($initPath);
        $timescales = [];

        foreach (self::topLevelBoxes($init) as [$type, $offset, $size, $header]) {
            if ($type !== 'moov') {
                continue;
            }
            foreach (self::childBoxes($init, $offset + $header, $offset + $size) as $trak) {
                [$trakType, $trakOffset, $trakSize, $trakHeader] = $trak;
                if ($trakType !== 'trak') {
                    continue;
                }
                $trackId = null;
                $timescale = null;
                foreach (self::childBoxes($init, $trakOffset + $trakHeader, $trakOffset + $trakSize) as $child) {
                    [$childType, $childOffset, $childSize, $childHeader] = $child;
                    if ($childType === 'tkhd') {
                        $trackId = self::readTkhdTrackId($init, $childOffset + $childHeader);
                    } elseif ($childType === 'mdia') {
                        $timescale = self::findMdhdTimescale(
                            $init,
                            $childOffset + $childHeader,
                            $childOffset + $childSize
                        );
                    }
                }
                if ($trackId !== null && $timescale !== null && $timescale > 0) {
                    $timescales[$trackId] = $timescale;
                }
            }
        }

        if ($timescales === []) {
            throw new \RuntimeException("fmp4 rebase: no moov/trak timescales in {$initPath}");
        }

        return $timescales;
    }

    /**
     * Computes the rebased `earliest_presentation_time` of one top-level `sidx`.
     *
     * A sidx carries its own `timescale`, so it does not consult the init's map.
     * At version 0 the value is promoted to version 1 when it no longer fits 32 bits. first_offset
     * can also force promotion; rebaseBytes() decides that once every edit is known, and the growth
     * is recorded after every box has been planned. A version other than 0 or 1 is refused.
     *
     * @return SidxPlan
     *
     * @throws \RuntimeException For a sidx of version 2 or later, or a zero timescale.
     * @throws \RangeException   From {@see self::ticks()} when the start, scaled by this sidx's timescale,
     *                           lies outside the int range (see {@see self::rebase()}).
     */
    private static function planSidxTime(
        string $segment,
        int $offset,
        int $size,
        int $header,
        float $startSeconds
    ): array {
        // version(1) flags(3) reference_ID(4) timescale(4) then
        // earliest_presentation_time + first_offset, 32-bit at version 0 and 64-bit from version 1.
        $body = $offset + $header;
        $version = self::byteAt($segment, $body);
        if ($version > 1) {
            throw new \RuntimeException("fmp4 rebase: sidx version {$version} is unsupported (only 0 and 1)");
        }
        $timescale = self::uint32($segment, $body + 8);
        if ($timescale <= 0) {
            throw new \RuntimeException('fmp4 rebase: sidx with zero timescale');
        }
        $delta = self::ticks($startSeconds, $timescale);
        $field = $body + 12;

        if ($version === 0) {
            $earliest = self::addUint64(self::uint32($segment, $field), $delta);
            $firstOffset = self::uint32($segment, $field + 4);
            // A negative bit pattern means the sum reached 2^63, which is past 32 bits too.
            $promoted = $earliest < 0 || $earliest > 0xFFFFFFFF;
        } else {
            $earliest = self::addUint64(self::uint64($segment, $field), $delta);
            $firstOffset = self::uint64($segment, $field + 8);
            $promoted = false;
        }

        return [
            'offset' => $offset,
            'size' => $size,
            'header' => $header,
            'version' => $version,
            'timescale' => $timescale,
            'earliest' => $earliest,
            'firstOffset' => $firstOffset,
            'promoted' => $promoted,
        ];
    }

    /**
     * Plans `moof`: each `traf` in it, then the `moof` header if any traf grew it.
     *
     * @param array<int, int> $timescales `track_ID => media timescale`, from {@see trackTimescales()}.
     * @param Plan            $plan       Updated in place.
     */
    private static function planMoof(
        string $segment,
        int $offset,
        int $size,
        int $header,
        array $timescales,
        float $startSeconds,
        array &$plan
    ): void {
        $growth = 0;
        $first = true;
        $children = self::childBoxes($segment, $offset + $header, $offset + $size);
        foreach ($children as [$childType, $childOffset, $childSize, $childHeader]) {
            if ($childType !== 'traf') {
                continue;
            }
            $growth += self::planTraf(
                $segment,
                $offset,
                $childOffset,
                $childSize,
                $childHeader,
                $first,
                $timescales,
                $startSeconds,
                $plan
            );
            $first = false;
        }

        if ($growth > 0) {
            $plan['patches'][] = [$offset, $offset + $header, self::boxHeader('moof', $size + $growth, $header)];
        }
    }

    /**
     * Plans one `traf`: the `tfdt` (in place, or grown from version 0 to version 1), and the
     * `trun@data_offset` fields whose base is the moof start.
     *
     * @param array<int, int> $timescales `track_ID => media timescale`, from {@see trackTimescales()}.
     * @param Plan            $plan       Updated in place.
     *
     * @return int Bytes this traf grew by (0 or 4).
     */
    private static function planTraf(
        string $segment,
        int $moofOffset,
        int $offset,
        int $size,
        int $header,
        bool $first,
        array $timescales,
        float $startSeconds,
        array &$plan
    ): int {
        $trackId = null;
        $tfhdFlags = 0;
        $tfdt = null;
        $truns = [];
        $children = self::childBoxes($segment, $offset + $header, $offset + $size);
        foreach ($children as [$childType, $childOffset, $childSize, $childHeader]) {
            $body = $childOffset + $childHeader;
            if ($childType === 'tfhd') {
                // version(1) flags(3) track_ID(4)
                $tfhdFlags = self::uint32($segment, $body) & 0xFFFFFF;
                $trackId = self::uint32($segment, $body + 4);
            } elseif ($childType === 'tfdt') {
                $tfdt = [$childOffset, $childSize, $childHeader];
            } elseif ($childType === 'trun') {
                $truns[] = $body;
            }
        }

        $growth = 0;
        if ($tfdt !== null) {
            [$tfdtOffset, $tfdtSize, $tfdtHeader] = $tfdt;
            if ($trackId === null || !isset($timescales[$trackId])) {
                throw new \RuntimeException(
                    'fmp4 rebase: traf track_ID ' . var_export($trackId, true) . ' absent from the init segment'
                );
            }
            $delta = self::ticks($startSeconds, $timescales[$trackId]);
            $body = $tfdtOffset + $tfdtHeader;
            $version = self::byteAt($segment, $body);
            if ($version > 1) {
                throw new \RuntimeException("fmp4 rebase: tfdt version {$version} is unsupported (only 0 and 1)");
            }
            $field = $body + 4; // after version(1) + flags(3)

            if ($version === 0) {
                $value = self::addUint64(self::uint32($segment, $field), $delta);
                if ($value >= 0 && $value <= 0xFFFFFFFF) {
                    $plan['patches'][] = [$field, $field + 4, pack('N', $value)];
                } else {
                    // Grow to version 1: a 16-byte version-0 box becomes a 20-byte version-1 box
                    // (size, 'tfdt', version=1 + flags=0, 64-bit baseMediaDecodeTime). Only the
                    // plain 8-byte header is accepted, so the box size is exactly 16.
                    if ($tfdtHeader !== self::HEADER_BYTES || $tfdtSize !== 16) {
                        throw new \RuntimeException('fmp4 rebase: unexpected version-0 tfdt layout');
                    }
                    $plan['patches'][] = [
                        $tfdtOffset,
                        $tfdtOffset + 16,
                        pack('N', 20) . 'tfdt' . "\x01\0\0\0" . pack('J', $value),
                    ];
                    $plan['edits'][] = [$tfdtOffset + 16, 4];
                    $growth += 4;
                }
            } else {
                $value = self::addUint64(self::uint64($segment, $field), $delta);
                $plan['patches'][] = [$field, $field + 8, pack('J', $value)];
            }
            $plan['rewritten']++;
        }

        // ISO/IEC 14496-12 8.8.7.1: base-data-offset is, when present, absolute. With default-base-is-moof
        // it is the moof start. With neither flag, the first traf is based on the moof start and later
        // ones on the end of the previous traf's data, which lies after the grown boxes and so moves with them.
        $baseOffsetPresent = ($tfhdFlags & 0x000001) !== 0;
        if ($baseOffsetPresent) {
            $plan['absoluteBase'] = true;
        } elseif (($tfhdFlags & 0x020000) !== 0 || $first) {
            foreach ($truns as $body) {
                $trunFlags = self::uint32($segment, $body) & 0xFFFFFF;
                if (($trunFlags & 0x000001) === 0) {
                    continue; // data_offset absent
                }
                // version(1) flags(3) sample_count(4) data_offset(signed 32-bit)
                $raw = self::uint32($segment, $body + 8);
                $plan['dataOffsets'][] = [
                    $body + 8,
                    $moofOffset,
                    $raw >= 0x80000000 ? $raw - 0x100000000 : $raw,
                ];
            }
        }

        if ($growth > 0) {
            $plan['patches'][] = [$offset, $offset + $header, self::boxHeader('traf', $size + $growth, $header)];
        }

        return $growth;
    }

    /**
     * Builds the replacement for one `sidx`, or returns null when its bytes do not change.
     *
     * Reference sizes are recomputed from the new positions of each referenced byte range. The
     * first_offset is recomputed from the new position of the first referenced byte, measured from
     * the new end of the sidx. Only these fields change; the reserved bytes and reference_count are
     * copied through.
     *
     * @param SidxPlan                  $sidx
     * @param list<array{int, int}>     $edits
     *
     * @return array{int, int, string}|null [start, end, replacement] in original offsets, or null.
     *
     * @throws \RangeException   When a rebased first_offset (32-bit field) or referenced_size (31-bit) overflows.
     * @throws \RuntimeException When the sidx is truncated, when a version-1 first_offset lies past the signed
     *                           64-bit range, when an offset sum would leave the int range, or when its
     *                           reference table is inconsistent and would need a reference rewritten.
     */
    private static function finishSidx(string $segment, array $sidx, array $edits): ?array
    {
        $offset = $sidx['offset'];
        $size = $sidx['size'];
        $header = $sidx['header'];
        $version = $sidx['version'];
        $promoted = $sidx['promoted'];
        $body = $offset + $header;
        $anchor = $offset + $size; // the anchor for first_offset is the end of the sidx box

        $fieldsStart = $body + 12;
        $sourceWide = $version === 1;
        $firstOffset = $sourceWide
            ? self::uint64($segment, $fieldsStart + 8)
            : self::uint32($segment, $fieldsStart + 4);
        $trailerStart = $fieldsStart + ($sourceWide ? 16 : 8);
        if ($trailerStart > $anchor) {
            throw new \RuntimeException('fmp4 rebase: sidx is truncated');
        }
        // A version-1 first_offset is an unsigned 64-bit field: bit 63 set reads back as a negative int.
        if ((($firstOffset >> 63) & 1) === 1) {
            throw new \RuntimeException(
                'fmp4 rebase: sidx first_offset exceeds the signed 64-bit range this rebaser supports'
            );
        }

        $newFirst = self::shift($edits, self::addInt($anchor, $firstOffset)) - self::shift($edits, $anchor);
        $wide = $promoted || $sourceWide;
        if (!$wide && $newFirst > 0xFFFFFFFF) {
            throw new \RangeException('fmp4 rebase: sidx first_offset overflows its 32-bit field');
        }

        $trailer = substr($segment, $trailerStart, $anchor - $trailerStart);
        $trailerLength = strlen($trailer);
        // reserved(2) reference_count(2) then 12 bytes per reference
        $count = $trailerLength >= 4 ? (ord($trailer[2]) << 8) | ord($trailer[3]) : -1;
        $newTrailer = $trailer;
        if ($count >= 0 && $trailerLength === 4 + 12 * $count) {
            $position = self::addInt($anchor, $firstOffset);
            for ($i = 0; $i < $count; $i++) {
                $refPos = 4 + 12 * $i;
                // reference_type(1 bit) referenced_size(31 bits), then duration and SAP fields we copy
                $word = self::uint32($trailer, $refPos);
                $newStart = self::shift($edits, $position);
                $position = self::addInt($position, $word & 0x7FFFFFFF);
                $newSize = self::shift($edits, $position) - $newStart;
                if ($newSize > 0x7FFFFFFF) {
                    throw new \RangeException('fmp4 rebase: sidx referenced_size overflows its 31-bit field');
                }
                $newTrailer = substr_replace($newTrailer, pack('N', ($word & 0x80000000) | $newSize), $refPos, 4);
            }
        } elseif ($edits !== []) {
            throw new \RuntimeException('fmp4 rebase: sidx reference table does not match its reference_count');
        }

        $newFields = $wide
            ? pack('J', $sidx['earliest']) . pack('J', $newFirst)
            : pack('N', $sidx['earliest']) . pack('N', $newFirst);
        $newBox = self::boxHeader('sidx', $size + ($promoted ? 8 : 0), $header)
            . chr($promoted ? 1 : $version) . substr($segment, $body + 1, 3) // version + flags
            . substr($segment, $body + 4, 8) // reference_ID + timescale
            . $newFields
            . $newTrailer;

        if ($newBox === substr($segment, $offset, $size)) {
            return null;
        }

        return [$offset, $offset + $size, $newBox];
    }

    /**
     * `pos + bytes inserted at or before pos`: maps an original offset to its offset in the grown
     * file. Each edit is `[original offset, bytes inserted there]`.
     *
     * @param list<array{int, int}> $edits
     */
    private static function shift(array $edits, int $position): int
    {
        $shifted = $position;
        foreach ($edits as [$at, $bytes]) {
            if ($at <= $position) {
                $shifted = self::addInt($shifted, $bytes);
            }
        }

        return $shifted;
    }

    /**
     * `$a + $b` for two non-negative offsets, refused when the sum would leave [0, PHP_INT_MAX].
     *
     * Integer addition that overflows becomes a float in PHP, which a strict `int` return or argument
     * rejects with a TypeError. The sum is checked first, so the failure is a RuntimeException (exit 1)
     * and nothing is written.
     *
     * @throws \RuntimeException When either operand is negative or the sum exceeds PHP_INT_MAX.
     */
    private static function addInt(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || $a > PHP_INT_MAX - $b) {
            throw new \RuntimeException('fmp4 rebase: sidx offset arithmetic overflows the signed 64-bit range');
        }

        return $a + $b;
    }

    /**
     * Header for a rebuilt box, keeping the original header width (8, or 16 for a 64-bit size).
     */
    private static function boxHeader(string $type, int $size, int $headerBytes): string
    {
        if ($headerBytes === 16) {
            return pack('N', 1) . $type . pack('J', $size);
        }
        if ($size > 0xFFFFFFFF) {
            throw new \RuntimeException("fmp4 rebase: '{$type}' would outgrow its 32-bit size field");
        }

        return pack('N', $size) . $type;
    }

    /**
     * Refuses a start that is not a usable timeline offset: NaN, infinite or negative. Called by
     * rebase() before any file is read, and by rebaseBytes().
     *
     * @throws \RangeException When the start is non-finite or negative.
     */
    private static function assertStart(float $startSeconds): void
    {
        if (is_nan($startSeconds) || is_infinite($startSeconds)) {
            throw new \RangeException('fmp4 rebase: non-finite start offset');
        }
        if ($startSeconds < 0.0) {
            throw new \RangeException('fmp4 rebase: negative start offset');
        }
    }

    /**
     * Converts a timeline offset in seconds to whole ticks of `$timescale`.
     *
     * An (int) cast of a float is only meaningful inside the int range. INF and NaN cast to 0,
     * and a finite value outside [-2^63, 2^63) wraps modulo 2^64 (PHP 7+) to an unrelated,
     * possibly negative, shift. Both are refused here.
     *
     * Exactness is bounded: below 2^53 ticks every integer is representable as a float, so
     * `round($startSeconds * $timescale)` returns the whole tick nearest the product without losing
     * a tick. At or above 2^53 the float spacing is wider than one tick, so the result is no longer
     * exact to the tick; it is still returned, and refused only when it leaves the int range.
     *
     * @throws \RangeException When the scaled value is not finite or lies outside the int range.
     */
    private static function ticks(float $startSeconds, int $timescale): int
    {
        $ticks = round($startSeconds * $timescale);
        if (!is_finite($ticks) || $ticks >= (float) PHP_INT_MAX || $ticks < (float) PHP_INT_MIN) {
            throw new \RangeException(
                "fmp4 rebase: start offset {$startSeconds}s is out of range for timescale {$timescale}"
            );
        }

        return (int) $ticks;
    }

    /**
     * `$base + $delta` for an UNSIGNED 64-bit field. `$base` is the field's bit pattern as read
     * by uint64(), so a value at or above 2^63 arrives as a negative int. The sum is therefore
     * computed on 32-bit halves: the carry out of the low half is explicit, and the overflow
     * test is the unsigned one (the sum must not exceed 2^64 - 1). Signed-int overflow is not
     * the criterion, so a field past PHP_INT_MAX that stays below 2^64 is rebased correctly.
     *
     * Also used for version-0 fields, whose 32-bit base is widened here before the fit test.
     *
     * @param int $delta Whole ticks to add; never negative (the start offset is non-negative).
     *
     * @throws \RangeException          When the sum exceeds 2^64 - 1.
     * @throws \InvalidArgumentException When the delta is negative (a programmer error).
     */
    private static function addUint64(int $base, int $delta): int
    {
        if ($delta < 0) {
            throw new \InvalidArgumentException('fmp4 rebase: negative 64-bit timestamp delta');
        }

        // `>>` is arithmetic on a negative $base, so the high half is masked. $delta < 2^63, so its
        // high half is non-negative.
        $baseHigh = ($base >> 32) & 0xFFFFFFFF;
        $baseLow = $base & 0xFFFFFFFF;
        $deltaHigh = $delta >> 32;
        $deltaLow = $delta & 0xFFFFFFFF;

        $low = $baseLow + $deltaLow;
        $carry = $low >> 32;
        $low &= 0xFFFFFFFF;

        $high = $baseHigh + $deltaHigh + $carry;
        if ($high > 0xFFFFFFFF) {
            throw new \RangeException('fmp4 rebase: rebased 64-bit timestamp overflows its field');
        }

        // `<<` on a 64-bit int wraps at the bit level and never becomes a float; the result is the
        // field's bit pattern, which pack('J') writes back unchanged.
        return ($high << 32) | $low;
    }

    /**
     * `tkhd@track_ID` — at a different offset depending on the box version,
     * because the creation/modification times widen from 32 to 64 bits.
     */
    private static function readTkhdTrackId(string $data, int $bodyOffset): int
    {
        $version = self::byteAt($data, $bodyOffset);

        return self::uint32($data, $bodyOffset + 4 + ($version === 1 ? 16 : 8));
    }

    /**
     * `mdia/mdhd@timescale`, with the same version-dependent layout as `tkhd`.
     */
    private static function findMdhdTimescale(string $data, int $offset, int $end): ?int
    {
        foreach (self::childBoxes($data, $offset, $end) as [$type, $boxOffset, , $header]) {
            if ($type !== 'mdhd') {
                continue;
            }
            $body = $boxOffset + $header;
            $version = self::byteAt($data, $body);

            return self::uint32($data, $body + 4 + ($version === 1 ? 16 : 8));
        }

        return null;
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: int}> `[type, offset, size, headerBytes]`.
     */
    private static function topLevelBoxes(string $data): array
    {
        return self::childBoxes($data, 0, strlen($data));
    }

    /**
     * Enumerates the boxes between `$offset` and `$end`.
     *
     * @return list<array{0: string, 1: int, 2: int, 3: int}> `[type, offset, size, headerBytes]`.
     *
     * @throws \RuntimeException On a truncated or zero/negative-sized box — either
     *                           means the file is not the fragment we were told it is.
     */
    private static function childBoxes(string $data, int $offset, int $end): array
    {
        $boxes = [];
        while ($offset + self::HEADER_BYTES <= $end) {
            $size = self::uint32($data, $offset);
            $type = substr($data, $offset + 4, 4);
            $header = self::HEADER_BYTES;
            if ($size === 1) {
                $size = self::uint64($data, $offset + self::HEADER_BYTES);
                $header = 16;
            } elseif ($size === 0) {
                // "to end of file" — legal only as the last box.
                $size = $end - $offset;
            }
            if ($size < $header || $offset + $size > $end) {
                throw new \RuntimeException(
                    "fmp4 rebase: box '{$type}' at {$offset} declares size {$size}, which overruns the buffer"
                );
            }
            $boxes[] = [$type, $offset, $size, $header];
            $offset += $size;
        }

        return $boxes;
    }

    private static function read(string $path): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException("fmp4 rebase: {$path} does not exist");
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_SEGMENT_BYTES) {
            throw new \RuntimeException("fmp4 rebase: {$path} is unreadable or implausibly large");
        }
        $data = file_get_contents($path);
        if ($data === false || $data === '') {
            throw new \RuntimeException("fmp4 rebase: {$path} is empty or unreadable");
        }

        return $data;
    }

    private static function byteAt(string $data, int $offset): int
    {
        self::requireBytes($data, $offset, 1);

        return ord($data[$offset]);
    }

    private static function uint32(string $data, int $offset): int
    {
        self::requireBytes($data, $offset, 4);
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($data, $offset, 4));

        return $unpacked[1];
    }

    private static function uint64(string $data, int $offset): int
    {
        self::requireBytes($data, $offset, 8);
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', substr($data, $offset, 8));

        return $unpacked[1];
    }

    private static function requireBytes(string $data, int $offset, int $length): void
    {
        if ($offset < 0 || $offset + $length > strlen($data)) {
            throw new \RuntimeException("fmp4 rebase: read of {$length} bytes at {$offset} is out of bounds");
        }
    }
}
