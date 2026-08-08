<?php

/**
 * Phlix media server component: Media\Transcoding.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

/**
 * Rebases one independently-encoded CMAF fragment onto its position on the VOD
 * timeline, by rewriting `moof/traf/tfdt@baseMediaDecodeTime` (and every
 * top-level `sidx@earliest_presentation_time`) in place.
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
 * ## Scope
 *
 * Deliberately a narrow byte editor, not an ISO-BMFF library: it walks the
 * top-level box list, descends `moof → traf`, and rewrites exactly two field
 * kinds. Every other byte of the segment is returned untouched.
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
     * @throws \RuntimeException When either file is unreadable, malformed, has no
     *                           rewritable timestamp, or when the rebased value
     *                           will not fit the field width ffmpeg chose.
     */
    public static function rebase(string $segmentPath, string $initPath, float $startSeconds): int
    {
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
     * @param string             $segment      Raw fragment bytes, rewritten in place.
     * @param array<int, int>    $timescales   `track_ID => media timescale`.
     * @param float              $startSeconds Timeline offset to add.
     *
     * @throws \RuntimeException On a malformed fragment or an unrepresentable value.
     */
    public static function rebaseBytes(string &$segment, array $timescales, float $startSeconds): int
    {
        if ($startSeconds < 0.0) {
            throw new \RuntimeException('fmp4 rebase: negative start offset');
        }

        $rewritten = 0;
        foreach (self::topLevelBoxes($segment) as [$type, $offset, $size, $header]) {
            if ($type === 'sidx') {
                $rewritten += self::rebaseSidx($segment, $offset + $header, $startSeconds);
                continue;
            }
            if ($type === 'moof') {
                $rewritten += self::rebaseMoof(
                    $segment,
                    $offset + $header,
                    $offset + $size,
                    $timescales,
                    $startSeconds
                );
            }
        }

        if ($rewritten === 0) {
            throw new \RuntimeException('fmp4 rebase: no tfdt/sidx timestamp found — not a CMAF fragment?');
        }

        return $rewritten;
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
     * Rewrites `sidx@earliest_presentation_time`. A `sidx` carries its own
     * `timescale`, so it does not consult the init's map.
     */
    private static function rebaseSidx(string &$segment, int $bodyOffset, float $startSeconds): int
    {
        // version(1) flags(3) reference_ID(4) timescale(4) then
        // earliest_presentation_time + first_offset, 32-bit at version 0 and
        // 64-bit from version 1.
        $version = self::byteAt($segment, $bodyOffset);
        $timescale = self::uint32($segment, $bodyOffset + 8);
        if ($timescale <= 0) {
            throw new \RuntimeException('fmp4 rebase: sidx with zero timescale');
        }
        $field = $bodyOffset + 12;
        $delta = self::ticks($startSeconds, $timescale);

        if ($version === 0) {
            self::writeUint32($segment, $field, self::uint32($segment, $field) + $delta);
        } else {
            self::writeUint64($segment, $field, self::uint64($segment, $field) + $delta);
        }

        return 1;
    }

    /**
     * Walks `moof → traf` and rewrites each `tfdt`, resolving the timescale via
     * the `tfhd@track_ID` that precedes it inside the same `traf`.
     *
     * @param array<int, int> $timescales
     */
    private static function rebaseMoof(
        string &$segment,
        int $bodyOffset,
        int $end,
        array $timescales,
        float $startSeconds
    ): int {
        $rewritten = 0;
        foreach (self::childBoxes($segment, $bodyOffset, $end) as $traf) {
            [$trafType, $trafOffset, $trafSize, $trafHeader] = $traf;
            if ($trafType !== 'traf') {
                continue;
            }
            $trackId = null;
            $tfdtBody = null;
            foreach (self::childBoxes($segment, $trafOffset + $trafHeader, $trafOffset + $trafSize) as $child) {
                [$childType, $childOffset, , $childHeader] = $child;
                if ($childType === 'tfhd') {
                    // version(1) flags(3) track_ID(4)
                    $trackId = self::uint32($segment, $childOffset + $childHeader + 4);
                } elseif ($childType === 'tfdt') {
                    $tfdtBody = $childOffset + $childHeader;
                }
            }
            if ($tfdtBody === null) {
                continue;
            }
            if ($trackId === null || !isset($timescales[$trackId])) {
                throw new \RuntimeException(
                    'fmp4 rebase: traf track_ID ' . var_export($trackId, true) . ' absent from the init segment'
                );
            }
            $delta = self::ticks($startSeconds, $timescales[$trackId]);
            $version = self::byteAt($segment, $tfdtBody);
            $field = $tfdtBody + 4; // after version(1) + flags(3)

            if ($version === 0) {
                $value = self::uint32($segment, $field) + $delta;
                if ($value > 0xFFFFFFFF) {
                    // A 32-bit tfdt cannot be widened without moving every byte
                    // after it, so fail the publish rather than silently wrap.
                    throw new \RuntimeException('fmp4 rebase: rebased tfdt overflows its 32-bit field');
                }
                self::writeUint32($segment, $field, $value);
            } else {
                self::writeUint64($segment, $field, self::uint64($segment, $field) + $delta);
            }
            $rewritten++;
        }

        return $rewritten;
    }

    /**
     * Converts a timeline offset in seconds to whole ticks of `$timescale`.
     */
    private static function ticks(float $startSeconds, int $timescale): int
    {
        return (int) round($startSeconds * $timescale);
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

    private static function writeUint32(string &$data, int $offset, int $value): void
    {
        self::requireBytes($data, $offset, 4);
        $packed = pack('N', $value);
        for ($i = 0; $i < 4; $i++) {
            $data[$offset + $i] = $packed[$i];
        }
    }

    private static function writeUint64(string &$data, int $offset, int $value): void
    {
        self::requireBytes($data, $offset, 8);
        $packed = pack('J', $value);
        for ($i = 0; $i < 8; $i++) {
            $data[$offset + $i] = $packed[$i];
        }
    }

    private static function requireBytes(string $data, int $offset, int $length): void
    {
        if ($offset < 0 || $offset + $length > strlen($data)) {
            throw new \RuntimeException("fmp4 rebase: read of {$length} bytes at {$offset} is out of bounds");
        }
    }
}
