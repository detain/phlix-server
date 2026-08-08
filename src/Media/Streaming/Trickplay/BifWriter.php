<?php

/**
 * Phlix media server component: Trickplay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Streaming\Trickplay;

/**
 * Writes Roku BIF (Base Index Frames) trickplay archives.
 *
 * The layout below is Roku's published one, not a paraphrase of it. Every
 * multi-byte integer is unsigned 32-bit **little-endian**.
 *
 * ```text
 * offset  len  field
 * 0       8    magic          0x89 'B' 'I' 'F' 0x0d 0x0a 0x1a 0x0a
 * 8       4    version        0 (the only version defined)
 * 12      4    image count N
 * 16      4    timestamp multiplier, in milliseconds (0 means 1000)
 * 20      44   reserved, all zero
 * 64      8*N  index: N entries of (frame timestamp, absolute byte offset)
 * 64+8*N  8    index terminator: (0xffffffff, last byte of data + 1)
 * 64+8*(N+1)   the JPEG payloads, concatenated in index order
 * ```
 *
 * A frame's byte length is not stored — a reader derives it by subtracting its
 * offset from the next entry's offset, which is why the terminator's second
 * field must be the end-of-file offset and why the payloads must be adjacent
 * and in the same order as the index.
 *
 * A frame timestamp is a **count of multiplier units**, not milliseconds: frame
 * `i` is shown at `i * multiplier` ms. This writer therefore sets the multiplier
 * to the capture interval and numbers the frames `0..N-1`, which is exactly
 * representable for any integral-millisecond interval.
 *
 * Nothing here needs `gd`, `imagick` or ffmpeg — a BIF is a container, and the
 * payloads are already-encoded JPEG bytes. The only image inspection performed
 * is the SOI-marker check that refuses to archive something that is not a JPEG.
 *
 * @since 0.103.0
 */
final class BifWriter
{
    /**
     * The one place the archive's file name is spelled.
     *
     * Both halves of the feature read it from here — the producer that writes
     * the file and the controller that serves it. The pre-S275 code kept the
     * producing and serving *directories* in two config keys and they had
     * drifted apart, which is precisely how an artefact ends up unreachable.
     */
    public const FILENAME = 'thumbs.bif';

    /** Bytes 0-7 of every BIF file. */
    public const MAGIC = "\x89\x42\x49\x46\x0d\x0a\x1a\x0a";

    /** Fixed header size in bytes; the index starts immediately after it. */
    public const HEADER_LENGTH = 64;

    /** Size of one index entry: two unsigned 32-bit little-endian values. */
    public const INDEX_ENTRY_LENGTH = 8;

    /** The only file-format version Roku defines. */
    public const VERSION = 0;

    /** Timestamp field of the end-of-data index entry. */
    public const INDEX_TERMINATOR = 0xFFFFFFFF;

    /** JPEG start-of-image marker; the first two bytes of every payload. */
    public const JPEG_SOI = "\xFF\xD8";

    /** Upper bound for any uint32 field (offsets included). */
    private const UINT32_MAX = 4294967295;

    /**
     * Builds a complete BIF archive in memory.
     *
     * @param list<string> $jpegs Raw JPEG payloads, ascending in capture order.
     * @param int $timestampMultiplierMs Milliseconds between consecutive frames.
     *
     * @return string The complete `.bif` byte stream.
     *
     * @throws \InvalidArgumentException If the frame list is empty, a payload is
     *         not a JPEG, the multiplier is out of range, or the archive would
     *         exceed the uint32 offset space.
     */
    public static function build(array $jpegs, int $timestampMultiplierMs): string
    {
        $sizes = [];
        foreach ($jpegs as $index => $jpeg) {
            self::assertJpeg($jpeg, $index);
            $sizes[] = \strlen($jpeg);
        }

        return self::buildHeaderAndIndex($sizes, $timestampMultiplierMs) . \implode('', $jpegs);
    }

    /**
     * Writes a BIF archive from JPEG files on disk, streaming each payload.
     *
     * Only one payload is resident at a time, so a long video's frame set never
     * has to fit in the worker's memory alongside everything else it is holding.
     *
     * @param list<string> $paths Absolute paths to JPEG files, in capture order.
     * @param int $timestampMultiplierMs Milliseconds between consecutive frames.
     * @param string $outputPath Destination `.bif` path (overwritten if present).
     *
     * @return int Total bytes written.
     *
     * @throws \InvalidArgumentException If a frame is missing/unreadable/not a
     *         JPEG, or the inputs are otherwise invalid.
     * @throws \RuntimeException If the destination cannot be written.
     */
    public static function writeFromFiles(array $paths, int $timestampMultiplierMs, string $outputPath): int
    {
        $sizes = [];
        foreach ($paths as $index => $path) {
            if (!\is_file($path)) {
                throw new \InvalidArgumentException("BifWriter: frame {$index} does not exist: {$path}");
            }
            $size = \filesize($path);
            if ($size === false || $size <= 0) {
                throw new \InvalidArgumentException("BifWriter: frame {$index} is empty: {$path}");
            }
            $handle = @\fopen($path, 'rb');
            if ($handle === false) {
                throw new \InvalidArgumentException("BifWriter: frame {$index} is unreadable: {$path}");
            }
            $soi = (string) \fread($handle, 2);
            \fclose($handle);
            self::assertJpeg($soi, $index);
            $sizes[] = $size;
        }

        $prelude = self::buildHeaderAndIndex($sizes, $timestampMultiplierMs);

        $out = @\fopen($outputPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException("BifWriter: cannot open output for writing: {$outputPath}");
        }

        try {
            $written = (int) \fwrite($out, $prelude);
            foreach ($paths as $index => $path) {
                $in = @\fopen($path, 'rb');
                if ($in === false) {
                    throw new \InvalidArgumentException("BifWriter: frame {$index} is unreadable: {$path}");
                }
                try {
                    $copied = \stream_copy_to_stream($in, $out);
                } finally {
                    \fclose($in);
                }
                $written += (int) $copied;
            }
        } finally {
            \fclose($out);
        }

        return $written;
    }

    /**
     * Total size a BIF archive occupies for a given frame-size list.
     *
     * Exposed so callers (and tests) can assert the file length without
     * re-deriving the layout arithmetic and thereby re-deriving any error in it.
     *
     * @param list<int> $sizes Payload sizes in bytes.
     *
     * @return int Byte length of header + index + payloads.
     */
    public static function archiveSize(array $sizes): int
    {
        return self::dataOffset(\count($sizes)) + \array_sum($sizes);
    }

    /**
     * Absolute byte offset at which the payload section begins.
     *
     * @param int $frameCount Number of frames (N).
     *
     * @return int `64 + 8 * (N + 1)`.
     */
    public static function dataOffset(int $frameCount): int
    {
        return self::HEADER_LENGTH + self::INDEX_ENTRY_LENGTH * ($frameCount + 1);
    }

    /**
     * Builds the fixed header plus the complete `N + 1` entry index.
     *
     * @param list<int> $sizes Payload sizes in bytes, in index order.
     * @param int $timestampMultiplierMs Milliseconds between consecutive frames.
     *
     * @return string Header and index bytes.
     *
     * @throws \InvalidArgumentException If any bound is violated.
     */
    private static function buildHeaderAndIndex(array $sizes, int $timestampMultiplierMs): string
    {
        $count = \count($sizes);
        if ($count === 0) {
            throw new \InvalidArgumentException('BifWriter: a BIF archive needs at least one frame');
        }
        if ($timestampMultiplierMs < 1 || $timestampMultiplierMs > self::UINT32_MAX) {
            throw new \InvalidArgumentException(
                'BifWriter: timestamp multiplier must be 1..4294967295 ms, got ' . $timestampMultiplierMs
            );
        }

        $total = self::archiveSize($sizes);
        if ($total > self::UINT32_MAX) {
            throw new \InvalidArgumentException(
                'BifWriter: archive would be ' . $total . ' bytes, past the uint32 offset space'
            );
        }

        $header = self::MAGIC
            . \pack('V', self::VERSION)
            . \pack('V', $count)
            . \pack('V', $timestampMultiplierMs)
            . \str_repeat("\x00", self::HEADER_LENGTH - 20);

        $index = '';
        $offset = self::dataOffset($count);
        foreach ($sizes as $position => $size) {
            $index .= \pack('V', $position) . \pack('V', $offset);
            $offset += $size;
        }
        // End-of-data entry: the terminator timestamp and one-past-the-last byte.
        $index .= \pack('V', self::INDEX_TERMINATOR) . \pack('V', $offset);

        return $header . $index;
    }

    /**
     * Rejects a payload that does not begin with the JPEG SOI marker.
     *
     * Roku's decoder reads the payload as JPEG; archiving raw RGB (or a PNG, or
     * a truncated file) produces a BIF that passes every structural check and
     * still renders nothing on the device.
     *
     * @param string $bytes The payload, or at least its first two bytes.
     * @param int $index Frame position, for the error message.
     *
     * @throws \InvalidArgumentException If the SOI marker is absent.
     */
    private static function assertJpeg(string $bytes, int $index): void
    {
        if (\strncmp($bytes, self::JPEG_SOI, 2) !== 0) {
            throw new \InvalidArgumentException(
                'BifWriter: frame ' . $index . ' is not a JPEG (no 0xFFD8 start-of-image marker)'
            );
        }
    }
}
