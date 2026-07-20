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
 * AbrLadder — pure, deterministic Adaptive-Bitrate (ABR) ladder builder.
 *
 * Given a source's video/audio characteristics (a {@see SourceProfile}) and a
 * device-profile cap (looked up by name in {@see QualitySelector}), returns an
 * ordered {@see LadderResult}: the H.264 quality rungs (highest-first) plus an
 * "Original" descriptor. There is NO DB, ffprobe, filesystem, clock, or random
 * access — identical inputs always yield identical output. A5 consumes this to
 * emit the HLS master + per-variant media playlists; A4 reads each rung's
 * encode targets.
 *
 * Canonical H.264 ladder (target VIDEO bitrate, before clamping):
 *
 * | Rung  | WxH        | target kbps | Included when                  |
 * |-------|------------|-------------|--------------------------------|
 * | 240p  | 426×240    | 400         | source ≥ 240p (else fallback)  |
 * | 360p  | 640×360    | 800         | source ≥ 360p                  |
 * | 480p  | 854×480    | 1400        | source ≥ 480p                  |
 * | 720p  | 1280×720   | 2800        | source ≥ 720p                  |
 * | 1080p | 1920×1080  | 5000        | source ≥ 1080p                 |
 * | 1440p | 2560×1440  | 9000        | source ≥ 1440p                 |
 * | 2160p | 3840×2160  | 16000       | source ≥ 2160p                 |
 *
 * Clamp rules:
 *  - No upscaling: a canonical tier is skipped entirely once its height exceeds
 *    the source height (1440p/2160p therefore appear only for ≥1440p / ≥2160p
 *    sources). When its aspect-derived width would exceed the source width
 *    instead (an odd/narrow source width), only the WIDTH is clamped down to
 *    the (even-floored) source width — the rung still appears at that height.
 *    Contrast the device-profile cap below, which DROPS an over-width rung
 *    outright rather than narrowing it.
 *  - No exceeding source bitrate: each rung's target video bitrate is capped at
 *    the source video bitrate when it is known (never claim more than the source
 *    actually has).
 *  - Device-profile cap: rungs whose height exceeds the profile's max_resolution
 *    height, or whose aspect-derived width exceeds its max_resolution width, are
 *    dropped; the advertised BANDWIDTH never exceeds the profile's max_bitrate
 *    (video headroom is reserved so BANDWIDTH = maxrate + audio ≤ cap).
 *  - Anamorphic / odd aspect ratios: each rung's width is derived from the SOURCE
 *    aspect ratio (rounded to an even integer), not the canonical 16:9 width, so
 *    2.40:1 / anamorphic sources are not distorted; the height stays at the rung
 *    tier.
 *  - Always ≥1 rung: a sub-240p source (or one constrained below 240p by a
 *    narrow profile width) yields a single rung at its clamped resolution.
 *  - Unknown source dimensions: when width/height are absent/≤0 the ladder is
 *    capped conservatively at a 1080p 16:9 tier (never 1440p/2160p) so a
 *    metadata-less item is not upscaled, and no copy Original is offered.
 *  - Ordering: highest-first (the HLS master lists highest-first).
 *
 * "Original" descriptor (D4, revised): ALWAYS a distinct, playable variant.
 *  - Source is H.264 (h264/avc1/avc) + AAC (aac/mp4a), has known dimensions, and
 *    fits the profile cap → a stream-copy passthrough (`isCopy = true`) at source
 *    resolution/bitrate, labelled "Original (<h>p)". A5 emits it as an extra
 *    highest master variant; A4 serves it via `-c copy`.
 *  - Otherwise → a TRANSCODE at source resolution (clamped to the profile cap,
 *    aspect preserved; unknown dims fall back to the top rung's frame) with a
 *    source-ish video bitrate capped at the profile's video ceiling
 *    (`isCopy = false`), also labelled "Original (<h>p)". It is still emitted as
 *    its own master variant (id `original`), never dropped.
 *
 * Codecs strategy: `codecs` advertises H.264 High profile at the LOWEST level
 * whose MaxFS covers the frame's macroblock count (`ceil(w/16)*ceil(h/16)`; see
 * {@see self::H264_LEVELS}) plus AAC-LC (`mp4a.40.2`). Level is derived from the
 * macroblock COUNT, not height alone, so wide/anamorphic frames advertise a legal
 * level: 1920×1080 → L4.1 `avc1.640029`, DCI-2K 2048×1080 → L4.2 `avc1.64002A`,
 * ultrawide 2560×1080 → L5.0 `avc1.640032`, 2560×1440 → L5.0, 3840×2160 → L5.1
 * `avc1.640033`. For the copy Original the level is computed from the true source
 * WxH the same way. (A1 persists the codec NAME, not the exact encoded level, so a
 * source authored at a higher-than-necessary level is an undetectable edge —
 * candidate: persist the level in A1 later.)
 *
 * A4 coordination: A4 must encode EACH rung (and the copy path) with this exact
 * MB-derived level — NOT a flat `-level 4.1` — covering wide ≤1080-tier rungs
 * (e.g. a 2048/2560-wide 1080-tier rung is L4.2/L5.0, not L4.1) as well as the
 * 1440p/2160p rungs, so the encoded stream matches the advertised CODECS.
 *
 * All bitrates are bits/second.
 */
final class AbrLadder
{
    private const DEFAULT_MAX_WIDTH = 3840;
    private const DEFAULT_MAX_HEIGHT = 2160;
    private const DEFAULT_MAX_BITRATE = 100000000;

    /** Floor for a clamped video target so a tiny/absurd profile cap never zeroes it (bps). */
    private const MIN_VIDEO_BITRATE = 100000;

    /** Target video bitrate (kbps) for the lowest / sub-240p fallback rung. */
    private const LADDER_MIN_KBPS = 400;

    /** Conservative height ceiling (px) used when source dimensions are unknown. */
    private const UNKNOWN_DIMS_HEIGHT = 1080;

    /**
     * Canonical H.264 ladder: rung height (px) => target video bitrate (kbps).
     *
     * @var array<int, int>
     */
    private const LADDER = [
        240 => 400,
        360 => 800,
        480 => 1400,
        720 => 2800,
        1080 => 5000,
        1440 => 9000,
        2160 => 16000,
    ];

    /**
     * H.264 High-profile levels as `[MaxFS macroblocks, codec string]`, ascending.
     *
     * A frame's level is the LOWEST whose MaxFS ≥ its macroblock count
     * (`ceil(w/16) * ceil(h/16)`). Bitrate (MaxBR) is never binding here — every
     * rung/copy bitrate sits far below each level's ceiling — so MaxFS alone
     * selects the level. Redundant-MaxFS levels collapse to the preferred string
     * for that bucket: 4.0 & 4.1 share 8192 → 4.1 (higher MaxBR/DPB, encoder
     * default); 5.1 & 5.2 share 36864 → 5.1 (sufficient for ≤4K). `avc1.6400LL` =
     * High profile (profile_idc 0x64), no constraints (0x00), level_idc as 2 hex.
     *
     * @var array<int, array{int, string}>
     */
    private const H264_LEVELS = [
        [1620, 'avc1.64001E'],  // 3.0
        [3600, 'avc1.64001F'],  // 3.1
        [5120, 'avc1.640020'],  // 3.2
        [8192, 'avc1.640029'],  // 4.1 (preferred over 4.0)
        [8704, 'avc1.64002A'],  // 4.2
        [22080, 'avc1.640032'], // 5.0
        [36864, 'avc1.640033'], // 5.1 (preferred over 5.2)
    ];

    private readonly QualitySelector $qualitySelector;

    /**
     * @param QualitySelector|null $qualitySelector Injectable for custom profiles/tests;
     *                                              defaults to the standard device profiles.
     */
    public function __construct(?QualitySelector $qualitySelector = null)
    {
        $this->qualitySelector = $qualitySelector ?? new QualitySelector();
    }

    /**
     * Build the source-clamped ABR ladder for a device profile.
     *
     * When the source width/height are unknown (null/≤0) the ladder is capped
     * conservatively at 1080p (16:9 assumed) rather than the profile maximum, so a
     * metadata-less item is never upscaled to 1440p/2160p.
     *
     * @param SourceProfile $source      Source video/audio characteristics.
     * @param string        $profileName Device profile name (generic, mobile-low,
     *                                    mobile-high, web, tv-4k); unknown names fall
     *                                    back to `generic`.
     */
    public function build(SourceProfile $source, string $profileName = 'generic'): LadderResult
    {
        $cap = $this->resolveProfileCap($profileName);
        $maxWidth = $cap['width'];
        $maxHeight = $cap['height'];
        $maxBitrate = $cap['bitrate'];

        // Known source dimensions drive the clamp. When they are unknown/≤0 we
        // deliberately assume a conservative 1080p 16:9 ceiling (never 1440p/2160p)
        // so a metadata-less item is not upscaled to the profile's max resolution.
        $sh = $source->height;
        $sw = $source->width;
        if ($sh !== null && $sh > 0 && $sw !== null && $sw > 0) {
            $srcHeight = $sh;
            $srcWidth = $sw;
        } else {
            $srcHeight = min($maxHeight, self::UNKNOWN_DIMS_HEIGHT);
            $srcWidth = min($maxWidth, (int) round($srcHeight * 16 / 9));
        }
        $srcHeight = max($srcHeight, 2);
        $srcWidth = max($srcWidth, 2);
        $aspect = $srcWidth / $srcHeight;

        // The clamp budget is the source's own bitrate translated into H.264 terms
        // (see SourceProfile::h264BitrateEquivalenceFactor()): every rung is
        // re-encoded to H.264, so an HEVC/VP9/AV1 source needs proportionally MORE
        // bits than it occupies itself to come through the transcode intact.
        // Clamping to the raw source figure would spend the codec-generation gap
        // as quality loss and make the stream look worse than the file.
        $srcVideoBitrate = ($source->videoBitrate !== null && $source->videoBitrate > 0)
            ? (int) round($source->videoBitrate * $source->h264BitrateEquivalenceFactor())
            : null;

        // Reserve audio + maxrate headroom so the advertised BANDWIDTH ≤ profile cap.
        $profileVideoCeil = (int) floor(
            ($maxBitrate - Rendition::AUDIO_BANDWIDTH) / Rendition::MAXRATE_MULTIPLIER
        );
        $profileVideoCeil = max($profileVideoCeil, self::MIN_VIDEO_BITRATE);

        $makeRung = function (
            int $width,
            int $height,
            int $targetKbps
        ) use (
            $maxBitrate,
            $profileVideoCeil,
            $srcVideoBitrate
        ): Rendition {
            $videoBitrate = $targetKbps * 1000;
            if ($srcVideoBitrate !== null && $srcVideoBitrate < $videoBitrate) {
                $videoBitrate = $srcVideoBitrate;
            }
            $videoBitrate = min($videoBitrate, $profileVideoCeil);

            $bandwidth = (int) round($videoBitrate * Rendition::MAXRATE_MULTIPLIER)
                + Rendition::AUDIO_BANDWIDTH;
            $bandwidth = min($bandwidth, $maxBitrate);

            $label = $height . 'p';

            return new Rendition(
                id: $label,
                label: $label,
                width: $width,
                height: $height,
                bitrate: $bandwidth,
                videoBitrate: $videoBitrate,
                codecs: self::h264Codecs($width, $height),
                isOriginal: false,
                isCopy: false,
            );
        };

        $rungsAsc = [];
        $highest = null;
        foreach (self::LADDER as $canonHeight => $targetKbps) {
            if ($canonHeight > $srcHeight || $canonHeight > $maxHeight) {
                continue;
            }
            $width = self::evenDimension($canonHeight * $aspect);
            if ($width > $maxWidth) {
                continue;
            }
            if ($width > $srcWidth) {
                $width = self::evenFloor((float) $srcWidth);
            }
            $rung = $makeRung($width, $canonHeight, $targetKbps);
            $rungsAsc[] = $rung;
            $highest = $rung;
        }

        if ($highest === null) {
            // Source (or profile width) is below the lowest ladder tier: emit one
            // clamped rung at the largest resolution that fits every constraint.
            // NOTE: this rung's id is `{height}p` at the source height (e.g.
            // '144p'), i.e. a NON-canonical rung id outside the 240p..2160p set —
            // clients must treat a Rendition id as an opaque string, not a closed
            // enum (mirrored in `@phlix/contracts` `RenditionId = … | `${number}p``).
            $fallbackHeight = min($srcHeight, $maxHeight);
            if ($fallbackHeight * $aspect > $maxWidth) {
                $fallbackHeight = (int) floor($maxWidth / $aspect);
            }
            $fallbackHeight = self::evenFloor((float) max($fallbackHeight, 2));
            $fallbackWidth = self::evenDimension($fallbackHeight * $aspect);
            $fallbackWidth = min($fallbackWidth, self::evenFloor((float) $maxWidth));
            $fallbackWidth = min($fallbackWidth, self::evenFloor((float) $srcWidth));
            $highest = $makeRung($fallbackWidth, $fallbackHeight, self::LADDER_MIN_KBPS);
            $rungsAsc[] = $highest;
        }

        $renditions = $this->enforceBandwidthGradient(array_reverse($rungsAsc));
        $original = $this->buildOriginal($source, $highest, $maxWidth, $maxHeight, $maxBitrate, $profileVideoCeil);

        return new LadderResult($renditions, $original);
    }

    /**
     * Prune a highest-first rung list to a strictly-decreasing BANDWIDTH set.
     *
     * The "no exceeding source bitrate" clamp in the rung builder caps EVERY
     * rung whose canonical target exceeds a low source bitrate down to that same
     * source bitrate — so a 1.2 Mbps 1080p source produces 1080p/720p/480p rungs
     * that all collapse to the ONE identical BANDWIDTH. Such a run advertises no
     * quality gradient: a player just merges the duplicates and ABR has nothing
     * to climb to. Walking highest-first, keep a rung only when its BANDWIDTH is
     * at least (1 − {@see Rendition::ABR_DUPLICATE_TOLERANCE}) below the last
     * RETAINED rung; drop the rest. Because the list is highest-RESOLUTION-first,
     * the survivor of each collapsed bandwidth cluster is its highest-resolution
     * member (the lower-resolution siblings add no value at the same bandwidth),
     * and the survivors form a strictly-decreasing gradient.
     *
     * A normal-bitrate ladder is untouched: its canonical rungs step by ≥1.65×
     * in bandwidth (400→800→1400→2800→5000… kbps targets), far beyond the 15 %
     * threshold, so none is pruned. Only a rung dragged down NEAR its neighbour
     * by the source/profile cap — exactly the degenerate case — is removed. The
     * first rung is always retained, so the ladder never empties.
     *
     * @param list<Rendition> $renditions Highest-first, non-increasing bandwidth.
     *
     * @return list<Rendition>
     */
    private function enforceBandwidthGradient(array $renditions): array
    {
        $kept = [];
        $lastBandwidth = null;
        foreach ($renditions as $rung) {
            $bandwidth = $rung->bandwidth();
            if (
                $lastBandwidth === null
                || $bandwidth <= (int) floor($lastBandwidth * Rendition::ABR_DUPLICATE_TOLERANCE)
            ) {
                $kept[] = $rung;
                $lastBandwidth = $bandwidth;
            }
        }

        return $kept;
    }

    /**
     * Decide the "Original" descriptor — ALWAYS a genuine, playable variant.
     *
     * Stream-copy passthrough when the source is HLS-safe (H.264 + AAC, known
     * dims, fits the profile cap); otherwise a TRANSCODE at the source resolution
     * (clamped to the profile cap, aspect preserved) with a source-ish video
     * bitrate (the source's own bitrate when known, else the canonical tier
     * target, capped at the profile's video ceiling). Either way the descriptor
     * keeps `id = 'original'` and is emitted as a distinct master variant by
     * {@see LadderResult::streamVariants()}, so "Original" is always selectable —
     * for a transcoding source it is the closest-to-source rendition rather than
     * being silently dropped.
     */
    private function buildOriginal(
        SourceProfile $source,
        Rendition $topRung,
        int $maxWidth,
        int $maxHeight,
        int $maxBitrate,
        int $profileVideoCeil
    ): Rendition {
        $sh = $source->height;
        $sw = $source->width;

        if (
            $sh !== null && $sh > 0 && $sw !== null && $sw > 0
            && $source->isH264() && $source->isAac()
            && $sh <= $maxHeight && $sw <= $maxWidth
        ) {
            $height = self::evenFloor((float) $sh);
            $width = self::evenFloor((float) $sw);
            $videoBitrate = ($source->videoBitrate !== null && $source->videoBitrate > 0)
                ? $source->videoBitrate
                : self::canonicalTargetKbps($height) * 1000;
            $audioBitrate = ($source->audioBitrate !== null && $source->audioBitrate > 0)
                ? $source->audioBitrate
                : Rendition::AUDIO_BANDWIDTH;
            // For stream copy, bandwidth is the actual source bitrate without
            // profile cap (no transcode = profile constraints don't apply).
            $bandwidth = $videoBitrate + $audioBitrate;

            return new Rendition(
                id: 'original',
                label: sprintf('Original (%dp)', $height),
                width: $width,
                height: $height,
                bitrate: $bandwidth,
                videoBitrate: $videoBitrate,
                codecs: self::h264Codecs($sw, $sh),
                isOriginal: true,
                isCopy: true,
            );
        }

        // Transcode "Original": source resolution (clamped to the profile cap,
        // aspect preserved) + source-ish bitrate (capped sanely). Unknown source
        // dims fall back to the top clamped rung's frame — the best stand-in for
        // "source resolution" we can offer without inventing dimensions.
        if ($sh !== null && $sh > 0 && $sw !== null && $sw > 0) {
            $width = $sw;
            $height = $sh;
            if ($height > $maxHeight) {
                $width = (int) round($width * $maxHeight / $height);
                $height = $maxHeight;
            }
            if ($width > $maxWidth) {
                $height = (int) round($height * $maxWidth / $width);
                $width = $maxWidth;
            }
            $width = self::evenFloor((float) $width);
            $height = self::evenFloor((float) $height);
        } else {
            $width = $topRung->width;
            $height = $topRung->height;
        }

        // This branch is a TRANSCODE to H.264, so the source's bitrate needs the
        // same codec-equivalence translation the rungs get (the stream-copy branch
        // above keeps the raw figure — a copy really does reproduce it exactly).
        $videoBitrate = ($source->videoBitrate !== null && $source->videoBitrate > 0)
            ? (int) round($source->videoBitrate * $source->h264BitrateEquivalenceFactor())
            : self::canonicalTargetKbps($height) * 1000;
        $videoBitrate = min($videoBitrate, $profileVideoCeil);
        $videoBitrate = max($videoBitrate, self::MIN_VIDEO_BITRATE);

        $bandwidth = (int) round($videoBitrate * Rendition::MAXRATE_MULTIPLIER)
            + Rendition::AUDIO_BANDWIDTH;
        $bandwidth = min($bandwidth, $maxBitrate);

        return new Rendition(
            id: 'original',
            label: sprintf('Original (%dp)', $height),
            width: $width,
            height: $height,
            bitrate: $bandwidth,
            videoBitrate: $videoBitrate,
            codecs: self::h264Codecs($width, $height),
            isOriginal: true,
            isCopy: false,
        );
    }

    /**
     * Resolve the device-profile cap (falls back to `generic`, then to safe
     * built-in defaults if a custom selector has no `generic`).
     *
     * @return array{width: int, height: int, bitrate: int}
     */
    private function resolveProfileCap(string $profileName): array
    {
        $profile = $this->qualitySelector->getProfile($profileName)
            ?? $this->qualitySelector->getProfile('generic')
            ?? [
                'max_bitrate' => self::DEFAULT_MAX_BITRATE,
                'max_resolution' => [self::DEFAULT_MAX_WIDTH, self::DEFAULT_MAX_HEIGHT],
                'direct_play' => [],
                'transcode' => [],
                'container' => [],
            ];

        $width = $profile['max_resolution'][0] ?? self::DEFAULT_MAX_WIDTH;
        $height = $profile['max_resolution'][1] ?? self::DEFAULT_MAX_HEIGHT;
        $bitrate = $profile['max_bitrate'];

        return [
            'width' => $width > 0 ? $width : self::DEFAULT_MAX_WIDTH,
            'height' => $height > 0 ? $height : self::DEFAULT_MAX_HEIGHT,
            'bitrate' => $bitrate > 0 ? $bitrate : self::DEFAULT_MAX_BITRATE,
        ];
    }

    /**
     * The canonical target bitrate (kbps) of the highest ladder tier ≤ $height,
     * used only to estimate a copy Original's BANDWIDTH when the source bitrate
     * is unknown.
     */
    private static function canonicalTargetKbps(int $height): int
    {
        $best = self::LADDER_MIN_KBPS;
        foreach (self::LADDER as $rungHeight => $kbps) {
            if ($rungHeight <= $height) {
                $best = $kbps;
            }
        }

        return $best;
    }

    /**
     * The HLS `CODECS` string for a frame of the given width×height: H.264 High
     * profile at the lowest level whose MaxFS covers the frame's macroblock count
     * (see {@see self::H264_LEVELS}), plus AAC-LC. Deriving the level from the
     * macroblock count — not height alone — keeps wide/anamorphic frames (e.g.
     * DCI-2K 2048×1080 → L4.2, ultrawide 2560×1080 → L5.0) from advertising an
     * illegal (too-low) level.
     */
    private static function h264Codecs(int $width, int $height): string
    {
        $macroblocks = (int) (ceil($width / 16.0) * ceil($height / 16.0));
        // Default to the top table entry (L5.1, covers ≤4K) as the >MaxFS fallback.
        $video = 'avc1.640033';
        foreach (self::H264_LEVELS as [$maxFs, $codec]) {
            if ($macroblocks <= $maxFs) {
                $video = $codec;
                break;
            }
        }

        return $video . ',' . Rendition::AUDIO_CODEC;
    }

    /**
     * Round a dimension to the nearest even integer ≥ 2 (H.264 requires even
     * width/height; even keeps chroma subsampling valid).
     */
    private static function evenDimension(float $value): int
    {
        $rounded = (int) round($value / 2.0) * 2;

        return max($rounded, 2);
    }

    /**
     * Floor a dimension to an even integer ≥ 2. Used at clamp-to-source/profile
     * sites so the result can never exceed the (possibly odd) bound it clamps to —
     * `1279 → 1278`, never `1280` — preserving the strict "≤ source/profile"
     * invariant (and, for `-c copy`, an advertised RESOLUTION that never overstates
     * the true frame).
     */
    private static function evenFloor(float $value): int
    {
        $floored = (int) floor($value / 2.0) * 2;

        return max($floored, 2);
    }
}
