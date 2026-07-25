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
 * Rendition — one immutable rung of an ABR ladder (or the "Original" descriptor).
 *
 * Produced by {@see AbrLadder}; consumed by A5 (HLS master + per-variant media
 * playlists) and A4 (per-rung ffmpeg encode / stream-copy passthrough). Mirrors
 * the wire contract shape `{id, label, width, height, bitrate, codecs, url}`
 * (plan §1 D6, so B1 can mirror it in `@phlix/contracts`) — `url` is filled
 * later by A5/A7 and is therefore `null` here — plus the `isOriginal` /
 * `isCopy` booleans.
 *
 * Units: every bitrate is in bits/second.
 *  - `bitrate` is the advertised PEAK bandwidth for HLS
 *    `#EXT-X-STREAM-INF:BANDWIDTH`: the video maxrate (≈1.07×target, see
 *    {@see self::MAXRATE_MULTIPLIER}) plus a ~128 kbps AAC allowance
 *    ({@see self::AUDIO_BANDWIDTH}), capped at the device profile's max_bitrate.
 *    For a stream-copy Original it is the source's real muxed bitrate when the
 *    source bitrate is known, else a canonical per-tier estimate (the source
 *    codecs/dimensions are still HLS-safe — only the bitrate is unknown). Read
 *    it via {@see self::bandwidth()} when emitting BANDWIDTH.
 *  - `videoBitrate` is the raw video encode target (`-b:v`); A4 derives
 *    `-maxrate` / `-bufsize` from {@see self::maxrate()} / {@see self::bufsize()}.
 *    For a copy rendition it is the source's real video bitrate when known,
 *    else the same canonical estimate as `bitrate` above — informational
 *    either way, since A4 uses `-c copy` and ignores it.
 *
 * `codecs` is the HLS `CODECS` string — H.264 High profile at a
 * resolution-appropriate level plus AAC-LC (`mp4a.40.2`), e.g. `avc1.640029`
 * (High@4.1, ≤1080p), `avc1.640032` (High@5.0, 1440p), `avc1.640033`
 * (High@5.1, 2160p). See {@see AbrLadder} for the height→level mapping.
 */
final readonly class Rendition
{
    /** Video peak (`-maxrate`) as a multiple of the target video bitrate. */
    public const MAXRATE_MULTIPLIER = 1.07;

    /** VBV buffer size (`-bufsize`) as a multiple of the maxrate. */
    public const BUFSIZE_MULTIPLIER = 2;

    /**
     * ABR bandwidth-gradient tolerance (a fraction, 0..1).
     *
     * Two ends of one adaptive ladder are only useful to a player when their
     * advertised BANDWIDTHs differ enough to be a real quality choice. This is
     * the minimum STEP the ladder enforces between adjacent RETAINED rungs — a
     * rung must be at least (1 − this) below the one above it — and, symmetric,
     * the tolerance below which a re-encoded "Original" of the SAME frame is an
     * indistinguishable duplicate of the top rung ({@see self::duplicatesForAbr()}).
     * 0.85 ⇒ a ≥15 % bandwidth drop is required for a rung to add value; anything
     * closer is a source-bitrate-cap collapse (see {@see AbrLadder}'s gradient
     * pruning) that gives ABR nothing to climb to.
     *
     * S49 moved the "Original" use of this tolerance from the VARIANT list to the
     * MASTER's switchable set: "Original" is now always its own variant with its
     * own media playlist ({@see LadderResult::streamVariants()}), and only its
     * appearance as an ABR *level* is suppressed when it duplicates the top rung
     * (SV-4.6, {@see \Phlix\Media\Transcoding\TranscodeManager}).
     */
    public const ABR_DUPLICATE_TOLERANCE = 0.85;

    /** AAC audio bandwidth allowance folded into the advertised BANDWIDTH (bps). */
    public const AUDIO_BANDWIDTH = 128000;

    /** HLS audio codec string (AAC-LC). */
    public const AUDIO_CODEC = 'mp4a.40.2';

    /**
     * @param string $id           Stable id, e.g. `240p`, `1080p`, `original`.
     * @param string $label        Human label, e.g. `1080p`, `Original (1080p)`.
     * @param int    $width        Pixel width (even).
     * @param int    $height       Pixel height (even).
     * @param int    $bitrate      Advertised peak BANDWIDTH in bps (video maxrate + audio).
     * @param int    $videoBitrate Target video encode bitrate (`-b:v`) in bps.
     * @param string $codecs       HLS `CODECS` string (avc1.* + mp4a.40.2).
     * @param bool   $isOriginal   True for the "Original" descriptor.
     * @param bool   $isCopy       True when served via `-c copy` (source passthrough).
     */
    public function __construct(
        public string $id,
        public string $label,
        public int $width,
        public int $height,
        public int $bitrate,
        public int $videoBitrate,
        public string $codecs,
        public bool $isOriginal,
        public bool $isCopy,
    ) {
    }

    /**
     * Advertised peak bandwidth (bps) for `#EXT-X-STREAM-INF:BANDWIDTH`.
     */
    public function bandwidth(): int
    {
        return $this->bitrate;
    }

    /**
     * Encoder `-maxrate` (bps) ≈ 1.07 × target video bitrate. Transcode rungs only.
     */
    public function maxrate(): int
    {
        return (int) round($this->videoBitrate * self::MAXRATE_MULTIPLIER);
    }

    /**
     * Encoder `-bufsize` (bps) = 2 × maxrate. Transcode rungs only.
     */
    public function bufsize(): int
    {
        return $this->maxrate() * self::BUFSIZE_MULTIPLIER;
    }

    /**
     * True when this rendition and `$other` are the SAME frame (identical
     * width×height) at an effectively identical BANDWIDTH — i.e. within
     * {@see self::ABR_DUPLICATE_TOLERANCE} of each other. Such a pair are
     * indistinguishable ABR levels that must not BOTH be advertised in a master
     * playlist: same RESOLUTION + same CODECS (codecs derive from the frame) +
     * effectively the same BANDWIDTH is exactly the shape a player collapses into
     * one level, leaving ABR no gradient to climb (the v7 low-bitrate defect).
     *
     * Used by {@see \Phlix\Media\Transcoding\TranscodeManager}'s SV-4.6 filter to
     * decide whether a TRANSCODE "Original" may be an ABR level alongside the top
     * rung. It never decides whether a variant EXISTS — every variant of a job
     * always gets its own `media_v{id}.m3u8` (S49).
     */
    public function duplicatesForAbr(self $other): bool
    {
        if ($this->width !== $other->width || $this->height !== $other->height) {
            return false;
        }

        $low = min($this->bitrate, $other->bitrate);
        $high = max($this->bitrate, $other->bitrate);

        return $high > 0 && $low >= (int) floor($high * self::ABR_DUPLICATE_TOLERANCE);
    }

    /**
     * `WIDTHxHEIGHT` string for HLS `#EXT-X-STREAM-INF:RESOLUTION`.
     */
    public function resolution(): string
    {
        return sprintf('%dx%d', $this->width, $this->height);
    }

    /**
     * Array form mirroring the wire contract (`url` stays null until A5/A7 fill it).
     *
     * @return array{
     *     id: string,
     *     label: string,
     *     width: int,
     *     height: int,
     *     bitrate: int,
     *     codecs: string,
     *     url: null,
     *     is_original: bool,
     *     is_copy: bool,
     *     video_bitrate: int
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'width' => $this->width,
            'height' => $this->height,
            'bitrate' => $this->bitrate,
            'codecs' => $this->codecs,
            'url' => null,
            'is_original' => $this->isOriginal,
            'is_copy' => $this->isCopy,
            'video_bitrate' => $this->videoBitrate,
        ];
    }
}
