<?php

/**
 * Phlix media server component: Streaming.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Streaming;

/**
 * SourceProfile — the typed, I/O-free input to {@see AbrLadder}.
 *
 * A small immutable value object describing a source's video/audio
 * characteristics. It mirrors A1's persisted `metadata_json['source']` blob
 * (`{width, height, video_codec, video_bitrate, pix_fmt, audio_codec,
 * audio_bitrate}`, bitrates in bps, any field may be null) but the ladder
 * builder takes it as an explicit argument and never touches the DB, ffprobe,
 * or the filesystem. Use {@see self::fromSourceMetadata()} to adapt the raw
 * A1 array, or construct it directly (e.g. from a live probe fallback in A5).
 */
final readonly class SourceProfile
{
    /**
     * @param int|null    $width        Source pixel width, or null if unknown.
     * @param int|null    $height       Source pixel height, or null if unknown.
     * @param string|null $videoCodec   Source video codec name (e.g. `h264`, `hevc`).
     * @param int|null    $videoBitrate Source video bitrate in bps, or null if unknown.
     * @param string|null $audioCodec   Source audio codec name (e.g. `aac`, `ac3`).
     * @param int|null    $audioBitrate Source audio bitrate in bps, or null if unknown.
     * @param string|null $pixFmt       Source pixel format (e.g. `yuv420p`), carried for completeness.
     */
    public function __construct(
        public ?int $width = null,
        public ?int $height = null,
        public ?string $videoCodec = null,
        public ?int $videoBitrate = null,
        public ?string $audioCodec = null,
        public ?int $audioBitrate = null,
        public ?string $pixFmt = null,
    ) {
    }

    /**
     * Build a SourceProfile from A1's persisted `metadata_json['source']` blob.
     *
     * Pure array-mapping (no I/O); tolerant of missing keys and numeric strings.
     *
     * @param array<string, mixed> $source The persisted source descriptor.
     */
    public static function fromSourceMetadata(array $source): self
    {
        return new self(
            width: self::intOrNull($source['width'] ?? null),
            height: self::intOrNull($source['height'] ?? null),
            videoCodec: self::stringOrNull($source['video_codec'] ?? null),
            videoBitrate: self::intOrNull($source['video_bitrate'] ?? null),
            audioCodec: self::stringOrNull($source['audio_codec'] ?? null),
            audioBitrate: self::intOrNull($source['audio_bitrate'] ?? null),
            pixFmt: self::stringOrNull($source['pix_fmt'] ?? null),
        );
    }

    /**
     * True when the source video codec is HLS-safe H.264 (h264 / avc1 / avc).
     */
    public function isH264(): bool
    {
        return $this->videoCodec !== null
            && in_array(strtolower($this->videoCodec), ['h264', 'avc1', 'avc'], true);
    }

    /**
     * True when the source audio codec is HLS-safe AAC (aac / mp4a).
     */
    public function isAac(): bool
    {
        return $this->audioCodec !== null
            && in_array(strtolower($this->audioCodec), ['aac', 'mp4a'], true);
    }

    /**
     * How many H.264 bits it takes to match one bit of this source's codec.
     *
     * The ABR ladder never spends more than the SOURCE's bitrate on a rung —
     * spending more than the source contains is wasted bandwidth. That holds only
     * when source and output use the SAME codec. Every HLS rung is re-encoded to
     * H.264 (browser MSE decodes nothing else reliably), so a modern-codec source
     * needs MORE H.264 bits than it occupies itself to survive the transcode:
     * HEVC and VP9 are conventionally ~1.5x more efficient than H.264 at equal
     * quality, AV1 roughly 1.8x. Clamping an H.264 rung to a 1.08 Mbps HEVC
     * source's own 1.08 Mbps therefore guarantees the stream looks WORSE than the
     * file it came from — the generational efficiency gap is spent as loss.
     *
     * Returns the multiplier to apply to the source-bitrate clamp. 1.0 for H.264
     * and for any unknown codec (no evidence of an efficiency gap → do not inflate;
     * the profile ceiling still caps the result either way).
     */
    public function h264BitrateEquivalenceFactor(): float
    {
        if ($this->videoCodec === null) {
            return 1.0;
        }

        return match (strtolower($this->videoCodec)) {
            'hevc', 'h265', 'hvc1', 'hev1', 'vp9' => 1.5,
            'av1', 'av01' => 1.8,
            default => 1.0,
        };
    }

    /**
     * Coerce a mixed value to a positive-or-any int, or null.
     */
    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Coerce a mixed value to a non-empty trimmed string, or null.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }
}
