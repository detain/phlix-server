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
