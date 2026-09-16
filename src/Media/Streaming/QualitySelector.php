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
 * Quality Selector - Device profile-based quality selection for adaptive streaming.
 *
 * Determines optimal streaming quality based on device capabilities and source
 * compatibility. Evaluates codec support, resolution limits, and bitrate constraints
 * to decide between direct play and transcoding.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description Adaptive quality selection based on device profiles and source compatibility
 * @see https://developer.mozilla.org/en-US/docs/Glossary/Adaptive_bitrate_streaming
 */
class QualitySelector
{
    /**
     * @var array<string, array{
     *     max_bitrate: int,
     *     max_resolution: array<int, int>,
     *     direct_play: array<string>,
     *     transcode: array<string>,
     *     container: array<string>,
     *     audio_codecs?: array<int, string>,
     *     max_audio_channels?: int|null,
     *     max_h264_level?: int|null,
     *     max_hevc_level?: int|null,
     *     hevc_profiles?: array<int, string>,
     *     allow_hdr?: bool,
     *     allow_anamorphic?: bool,
     *     allow_interlaced?: bool,
     *     max_bitrate_by_height?: array<int, int>,
     *     vp9_max_bitrate?: int|null,
     *     vp9_require_limited_range?: bool
     * }> Device profiles indexed by name.
     *
     * S507: the last group of keys are OPTIONAL per-profile gates. A profile that
     * omits a gate key is unconstrained on that axis, so the five pre-existing
     * profiles keep byte-identical direct-play behaviour. Only `samsung-tizen`
     * (the AD-8 dedicated profile) opts into the restrictive gates.
     */
    private array $deviceProfiles;

    /**
     * Creates a new QualitySelector with optional custom profiles.
     *
     * @param array<string, array{
     *     max_bitrate?: int,
     *     max_resolution?: array<int, int>,
     *     direct_play?: array<string>,
     *     transcode?: array<string>,
     *     container?: array<string>
     * }> $deviceProfiles Optional custom device profiles to merge with defaults
     *
     * @example
     * ```php
     * $selector = new QualitySelector(['custom-profile' => ['max_bitrate' => 5000000]]);
     * ```
     */
    public function __construct(array $deviceProfiles = [])
    {
        $this->deviceProfiles = [];
        $this->loadDefaultProfiles();
        foreach ($deviceProfiles as $name => $profile) {
            $this->deviceProfiles[$name] = $this->normalizeProfile($profile);
        }
    }

    /**
     * Normalize a partial profile, filling missing keys from the generic profile.
     *
     * @param array{
     *     max_bitrate?: int,
     *     max_resolution?: array<int, int>,
     *     direct_play?: array<string>,
     *     transcode?: array<string>,
     *     container?: array<string>
     * } $profile
     * @return array{
     *     max_bitrate: int,
     *     max_resolution: array<int, int>,
     *     direct_play: array<string>,
     *     transcode: array<string>,
     *     container: array<string>
     * }
     */
    private function normalizeProfile(array $profile): array
    {
        $generic = $this->deviceProfiles['generic'] ?? [
            'max_bitrate' => 100000000,
            'max_resolution' => [3840, 2160],
            'direct_play' => ['h264', 'h265', 'vp9'],
            'transcode' => ['h264'],
            'container' => ['mp4', 'mkv', 'webm'],
        ];

        return [
            'max_bitrate' => $profile['max_bitrate'] ?? $generic['max_bitrate'],
            'max_resolution' => $profile['max_resolution'] ?? $generic['max_resolution'],
            'direct_play' => $profile['direct_play'] ?? $generic['direct_play'],
            'transcode' => $profile['transcode'] ?? $generic['transcode'],
            'container' => $profile['container'] ?? $generic['container'],
        ];
    }

    /**
     * Loads default device profiles.
     *
     * Provides profiles for various device categories:
     * - generic: High-capability generic device
     * - mobile-low: Low-end mobile (480p max)
     * - mobile-high: High-end mobile (720p max)
     * - web: Web browser (1080p max)
     * - tv-4k: 4K TV (2160p max)
     * - samsung-tizen: Samsung Tizen TV (AD-8, S507) — UHD panel with the
     *   conservative native-decoder gates the shared `tv-4k` bucket lacked
     *   (H.264 level ≤ L5.2, SDR-only direct play with HDR10/HLG tone-mapped
     *   down, no anamorphic/interlaced direct play, HEVC Main ≤ L120, a VP9
     *   range/bitrate gate, per-tier bitrate ceilings, an explicit 2-channel
     *   audio budget, and a per-profile audio allow-list).
     */
    private function loadDefaultProfiles(): void
    {
        $defaults = [
            'generic' => [
                'max_bitrate' => 100000000,
                'max_resolution' => [3840, 2160],
                'direct_play' => ['h264', 'h265', 'vp9'],
                'transcode' => ['h264'],
                'container' => ['mp4', 'mkv', 'webm'],
            ],
            'mobile-low' => [
                'max_bitrate' => 1500000,
                'max_resolution' => [854, 480],
                'direct_play' => ['h264'],
                'transcode' => ['h264'],
                'container' => ['mp4'],
            ],
            'mobile-high' => [
                'max_bitrate' => 4000000,
                'max_resolution' => [1280, 720],
                'direct_play' => ['h264', 'h265'],
                'transcode' => ['h264'],
                'container' => ['mp4'],
            ],
            'web' => [
                'max_bitrate' => 10000000,
                'max_resolution' => [1920, 1080],
                'direct_play' => ['h264', 'vp9'],
                'transcode' => ['h264', 'vp9'],
                'container' => ['mp4', 'webm'],
            ],
            'tv-4k' => [
                'max_bitrate' => 50000000,
                'max_resolution' => [3840, 2160],
                'direct_play' => ['h264', 'h265', 'vp9'],
                'transcode' => ['h264', 'h265'],
                'container' => ['mp4', 'mkv', 'ts'],
            ],
            // S507 (AD-8): dedicated Samsung Tizen profile, split out of the
            // `tizen`/`roku` → tv-4k lump. The five base keys describe a UHD panel
            // with the SAME top-of-line ceilings as tv-4k (so a 4K Tizen is never
            // resolution-capped below what it was already served), while the
            // optional gate keys below add the missing intelligence the lump never
            // had. Every threshold is re-derived from the live decoder doctrine:
            //  - audio_codecs: Tizen's native set (AAC/AC3/E-AC3/MP3). DTS/DTS-HD/
            //    TrueHD are intentionally ABSENT so they transcode (the already-
            //    encoded AD-8 behaviour, unchanged); FLAC/Opus are dropped because
            //    Tizen cannot decode them natively.
            //  - max_audio_channels 2: matches the existing transcode 2ch downmix
            //    (TranscodeManager min(ch,2)); a multichannel bitstream direct-
            //    playing to a 2ch Tizen path would lose the extra channels, so it
            //    transcodes instead.
            //  - max_h264_level 52 = Level 5.2 in ffmpeg's level_idc (×10) units.
            //  - max_hevc_level 120 = HEVC Main-tier level_idc; hevc_profiles are
            //    the normalized Main / Main10 tiers (Main10 allowed for SDR 10-bit,
            //    with HDR gated out by allow_hdr).
            //  - allow_hdr false: SDR-only direct play — HDR10 (smpte2084) and HLG
            //    (arib-std-b67), per {@see HdrMetadata::isHdr()}, are transcoded to
            //    SDR rather than passed through.
            //  - allow_anamorphic/allow_interlaced false: reject non-square-pixel
            //    and interlaced direct play.
            //  - max_bitrate_by_height: per-device-tier ceilings (1080-class FHD
            //    Tizen 20 Mbps, UHD 4K Tizen 50 Mbps) — the AD-8 "one 50M bucket"
            //    fix. A source at/below 1080p is capped at 20M; a taller source at
            //    50M. Keyed on source height because the static profile cannot see
            //    the panel (AD-4 client feed is a separate, out-of-scope concern).
            //  - vp9_* : VP9 direct play is no longer blanket — it is bitrate-capped
            //    and, for Samsung, restricted to limited ("tv") color range.
            'samsung-tizen' => [
                'max_bitrate' => 50000000,
                'max_resolution' => [3840, 2160],
                'direct_play' => ['h264', 'h265', 'vp9'],
                'transcode' => ['h264'],
                'container' => ['mp4', 'mkv', 'ts'],
                'audio_codecs' => ['aac', 'ac3', 'eac3', 'mp3'],
                'max_audio_channels' => 2,
                'max_h264_level' => 52,
                'max_hevc_level' => 120,
                'hevc_profiles' => ['main', 'main 10'],
                'allow_hdr' => false,
                'allow_anamorphic' => false,
                'allow_interlaced' => false,
                'max_bitrate_by_height' => [1080 => 20000000, 2160 => 50000000],
                'vp9_max_bitrate' => 20000000,
                'vp9_require_limited_range' => true,
            ],
        ];

        foreach ($defaults as $name => $profile) {
            $this->deviceProfiles[$name] = $profile;
        }
    }

    /**
     * Selects optimal quality for a source with a given device profile.
     *
     * Analyzes the source media and device capabilities to determine whether
     * direct play is possible or if transcoding is required.
     *
     * @param array{
     *     streams: array<int, array{codec_type: string, codec?: string, channels?: int, width?: int, height?: int,
     *         bitrate?: int}>,
     *     format?: array{format_name?: string}
     * } $sourceInfo Source media information from probe. One stream shape is used
     *   across selectQuality()/getVideoStream()/getAudioStream(): they all receive
     *   the same probe array, so declaring narrower per-method subsets made each
     *   hand-off look like a type error.
     * @param string $profileName Device profile name (e.g., 'generic', 'mobile-high')
     * @param array<string, mixed> $options Additional options including:
     *     - 'vendor' (string): Optional hardware vendor hint (e.g., 'nvenc', 'vaapi')
     *         When set, returns vendor-specific codec name (e.g., 'h264_nvenc') instead of 'libx264'
     *     - 'allow_hwaccel' (bool): Whether to use hardware acceleration if available
     *     - 'client_capabilities' (ClientCapabilities): Client decoder capabilities for
     *         play decisioning (SV-3.3). When provided, codecs the client cannot decode
     *         will force transcode instead of direct play.
     *
     * @return array{
     *     method: string,
     *     container: string,
     *     video_codec: string|null,
     *     audio_codec: string|null,
     *     max_resolution: array<int, int>,
     *     max_bitrate: int,
     *     vendor?: string|null
     * } Quality selection result with method and encoding parameters
     *
     * @example
     * ```php
     * $result = $selector->selectQuality($sourceInfo, 'web');
     * if ($result['method'] === 'direct') {
     *     // Stream directly without transcoding
     * } else {
     *     // Transcode with specified parameters
     * }
     * ```
     *
     * @since 0.11.0
     */
    public function selectQuality(array $sourceInfo, string $profileName, array $options = []): array
    {
        $profile = $this->deviceProfiles[$profileName] ?? $this->deviceProfiles['generic'];
        $vendor = is_string($options['vendor'] ?? null) ? $options['vendor'] : null;
        $allowHwaccel = (bool)($options['allow_hwaccel'] ?? false);
        $rawCapabilities = $options['client_capabilities'] ?? null;
        $clientCapabilities = $rawCapabilities instanceof ClientCapabilities ? $rawCapabilities : null;

        $videoStream = $this->getVideoStream($sourceInfo);
        $audioStream = $this->getAudioStream($sourceInfo);

        $canDirectPlay = $this->canDirectPlay($videoStream, $audioStream, $profile, $clientCapabilities);

        if ($canDirectPlay) {
            return [
                'method' => 'direct',
                'container' => $this->detectContainer($sourceInfo),
                'video_codec' => is_string($videoStream['codec'] ?? null) ? $videoStream['codec'] : null,
                'audio_codec' => is_string($audioStream['codec'] ?? null) ? $audioStream['codec'] : null,
                'max_resolution' => $profile['max_resolution'],
                'max_bitrate' => $profile['max_bitrate'],
                'vendor' => $vendor,
            ];
        }

        $videoCodec = $this->selectVideoCodec($vendor, $allowHwaccel);

        return [
            'method' => 'transcode',
            'container' => 'ts',
            'video_codec' => $videoCodec,
            'audio_codec' => 'aac',
            'max_resolution' => $profile['max_resolution'],
            'max_bitrate' => min($profile['max_bitrate'], 8000000),
            'vendor' => $vendor,
        ];
    }

    /**
     * Selects the appropriate video codec based on vendor and hardware availability.
     *
     * @param string|null $vendor Hardware vendor hint
     * @param bool $allowHwaccel Whether to allow hardware acceleration
     *
     * @return string Video codec name
     *
     * @since 0.11.0
     */
    private function selectVideoCodec(?string $vendor, bool $allowHwaccel): string
    {
        if ($vendor === null || !$allowHwaccel) {
            return 'libx264';
        }

        return match (strtolower($vendor)) {
            'nvenc' => 'h264_nvenc',
            'vaapi' => 'h264_vaapi',
            'qsv' => 'h264_qsv',
            'videotoolbox' => 'h264_videotoolbox',
            'amf' => 'h264_amf',
            'v4l2' => 'h264_v4l2m2m',
            default => 'libx264',
        };
    }

    /**
     * Determines if direct play is possible with the given streams and profile.
     *
     * Checks video codec, audio codec, resolution, and bitrate against profile
     * constraints to determine compatibility. When clientCapabilities are
     * provided, also verifies the client can decode the audio codec. S507 (AD-8)
     * adds the opt-in per-profile source-intelligence gates (per-profile audio
     * allow-list, channel budget, H.264/HEVC level + profile, HDR/SDR, anamorphic,
     * interlaced, VP9 range, per-tier bitrate ceilings); each is inert for a
     * profile that does not declare it, so the pre-existing profiles are unchanged.
     *
     * @param array<string, mixed>|null $videoStream Video stream info (probe shape;
     *     may carry level/profile/field_order/sample_aspect_ratio/color_transfer/
     *     color_range in addition to codec/width/height/bitrate).
     * @param array<string, mixed>|null $audioStream Audio stream info (codec/channels).
     * @param array<string, mixed> $profile Device profile constraints (5 base keys +
     *     optional S507 gate keys).
     * @param ClientCapabilities|null $clientCapabilities Client decoder capabilities (SV-3.3)
     *
     * @return bool True if all constraints are satisfied for direct play
     */
    private function canDirectPlay(
        ?array $videoStream,
        ?array $audioStream,
        array $profile,
        ?ClientCapabilities $clientCapabilities = null
    ): bool {
        if (!$videoStream || !$audioStream) {
            return false;
        }

        $videoCodec = strtolower(self::str($videoStream['codec'] ?? null));
        $audioCodec = strtolower(self::str($audioStream['codec'] ?? null));

        if (!in_array($videoCodec, self::directPlayCodecs($profile), true)) {
            return false;
        }

        // S507 (AD-8): per-profile audio allow-list. Falls back to the historical
        // global set when the profile declares none, so every pre-existing profile
        // stays byte-identical while samsung-tizen restricts to its native decoders.
        $declaredAudio = self::stringListOrNull($profile['audio_codecs'] ?? null);
        $supportedAudio = $declaredAudio ?? ['aac', 'ac3', 'eac3', 'mp3', 'flac', 'opus'];
        if (!in_array($audioCodec, $supportedAudio, true)) {
            return false;
        }

        // SV-3.3: If client capabilities are provided and the client cannot decode
        // this audio codec, we must transcode to avoid silent audio.
        if ($clientCapabilities !== null && !$clientCapabilities->supportsCodec($audioCodec)) {
            return false;
        }

        // S507 (AD-8): explicit channel budget. A profile that caps channels rejects
        // a source that over-supplies them instead of silently dropping channels on
        // a direct play; no key (the default) means unlimited.
        $maxChannels = self::intOrNull($profile['max_audio_channels'] ?? null);
        if ($maxChannels !== null && self::intOr($audioStream['channels'] ?? null) > $maxChannels) {
            return false;
        }

        $width = self::intOr($videoStream['width'] ?? null);
        $height = self::intOr($videoStream['height'] ?? null);
        [$maxWidth, $maxHeight] = self::maxResolution($profile);

        if ($width > $maxWidth || $height > $maxHeight) {
            return false;
        }

        // S507 (AD-8): codec-specific source-intelligence gates. Each fires only when
        // the profile opts in AND the probe carried the relevant field, so a generic
        // probe (no level/HDR/SAR/field_order) behaves exactly as before.
        if ($this->violatesHdrGate($videoStream, $profile)) {
            return false;
        }
        if ($this->violatesAnamorphicGate($videoStream, $profile)) {
            return false;
        }
        if ($this->violatesInterlaceGate($videoStream, $profile)) {
            return false;
        }
        if ($this->violatesH264LevelGate($videoStream, $videoCodec, $profile)) {
            return false;
        }
        if ($this->violatesHevcGate($videoStream, $videoCodec, $profile)) {
            return false;
        }
        if ($this->violatesVp9Gate($videoStream, $videoCodec, $profile)) {
            return false;
        }

        // S507 (AD-8): per-device-tier bitrate ceiling keyed on source height,
        // falling back to the flat max_bitrate the pre-existing profiles rely on.
        if (self::intOr($videoStream['bitrate'] ?? null) > $this->resolveBitrateCeiling($height, $profile)) {
            return false;
        }

        return true;
    }

    /**
     * S507 (AD-8): HDR direct-play gate. A profile with allow_hdr=false is
     * SDR-only — HDR10 (PQ) and HLG sources must transcode (tone-mapped to SDR)
     * rather than direct-play. Transfer-function detection mirrors
     * {@see \Phlix\Media\Transcoding\Hwaccel\ToneMapping\HdrMetadata::isHdr()}.
     * Inert when the profile allows HDR (default) or the probe carried none.
     *
     * @param array<string, mixed> $videoStream
     * @param array<string, mixed> $profile
     */
    private function violatesHdrGate(array $videoStream, array $profile): bool
    {
        if (self::boolOr($profile['allow_hdr'] ?? null, true)) {
            return false;
        }
        $transfer = strtolower(self::str($videoStream['color_transfer'] ?? null));

        return in_array($transfer, ['smpte2084', 'arib-std-b67'], true);
    }

    /**
     * S507 (AD-8): anamorphic gate. Rejects non-square pixel-aspect sources when
     * allow_anamorphic=false. SAR "w:h" is anamorphic when w!=h (both positive);
     * an absent/unparseable SAR is treated as square (no violation).
     *
     * @param array<string, mixed> $videoStream
     * @param array<string, mixed> $profile
     */
    private function violatesAnamorphicGate(array $videoStream, array $profile): bool
    {
        if (self::boolOr($profile['allow_anamorphic'] ?? null, true)) {
            return false;
        }
        $sar = self::str($videoStream['sample_aspect_ratio'] ?? null);
        if ($sar === '' || !str_contains($sar, ':')) {
            return false;
        }
        [$num, $den] = array_pad(explode(':', $sar, 2), 2, '');
        if (!is_numeric($num) || !is_numeric($den) || (float) $den === 0.0) {
            return false;
        }

        return (float) $num !== (float) $den;
    }

    /**
     * S507 (AD-8): interlace gate. Rejects interlaced sources when
     * allow_interlaced=false. ffprobe flags interlaced field_order as tt/bb/tb/bt/
     * mixed; progressive/unknown/absent pass (the "IsInterlaced != true" intent,
     * failing open when the probe says nothing).
     *
     * @param array<string, mixed> $videoStream
     * @param array<string, mixed> $profile
     */
    private function violatesInterlaceGate(array $videoStream, array $profile): bool
    {
        if (self::boolOr($profile['allow_interlaced'] ?? null, true)) {
            return false;
        }
        $fieldOrder = strtolower(self::str($videoStream['field_order'] ?? null));

        return in_array($fieldOrder, ['tt', 'bb', 'tb', 'bt', 'mixed'], true);
    }

    /**
     * S507 (AD-8): H.264 level ceiling. ffmpeg reports h264 `level` as level_idc
     * (level × 10), so L5.2 is 52. Only an H.264/AVC source is measured; a missing
     * level fails open.
     *
     * @param array<string, mixed> $videoStream
     * @param array<string, mixed> $profile
     */
    private function violatesH264LevelGate(array $videoStream, string $videoCodec, array $profile): bool
    {
        if (!in_array($videoCodec, ['h264', 'avc'], true)) {
            return false;
        }
        $maxLevel = self::intOrNull($profile['max_h264_level'] ?? null);
        if ($maxLevel === null) {
            return false;
        }
        $level = self::intOrNull($videoStream['level'] ?? null);

        return $level !== null && $level > $maxLevel;
    }

    /**
     * S507 (AD-8): HEVC Main-tier + level ceiling. Only an HEVC source is measured.
     * Rejects a profile outside the normalized allow-list (e.g. 'Main 10' →
     * 'main 10') and a level above max_hevc_level (level_idc, so a Main ≤ L120
     * ceiling). Absent profile/level fails open.
     *
     * @param array<string, mixed> $videoStream
     * @param array<string, mixed> $profile
     */
    private function violatesHevcGate(array $videoStream, string $videoCodec, array $profile): bool
    {
        if (!in_array($videoCodec, ['hevc', 'h265', 'hvc1', 'hev1'], true)) {
            return false;
        }
        $allowed = self::stringListOrNull($profile['hevc_profiles'] ?? null);
        if ($allowed !== null) {
            $hevcProfile = strtolower(trim(self::str($videoStream['profile'] ?? null)));
            if ($hevcProfile !== '' && !in_array($hevcProfile, $allowed, true)) {
                return true;
            }
        }
        $maxLevel = self::intOrNull($profile['max_hevc_level'] ?? null);
        if ($maxLevel !== null) {
            $level = self::intOrNull($videoStream['level'] ?? null);
            if ($level !== null && $level > $maxLevel) {
                return true;
            }
        }

        return false;
    }

    /**
     * S507 (AD-8): VP9 gate. No longer blanket direct-play — bitrate-capped and,
     * when vp9_require_limited_range is set, restricted to limited ("tv"/"mpeg")
     * colour range. An unstated range fails open (generic probes omit it).
     *
     * @param array<string, mixed> $videoStream
     * @param array<string, mixed> $profile
     */
    private function violatesVp9Gate(array $videoStream, string $videoCodec, array $profile): bool
    {
        if ($videoCodec !== 'vp9') {
            return false;
        }
        $maxBitrate = self::intOrNull($profile['vp9_max_bitrate'] ?? null);
        if ($maxBitrate !== null && self::intOr($videoStream['bitrate'] ?? null) > $maxBitrate) {
            return true;
        }
        if (self::boolOr($profile['vp9_require_limited_range'] ?? null, false)) {
            $range = strtolower(self::str($videoStream['color_range'] ?? null));
            if ($range !== '' && !in_array($range, ['tv', 'mpeg', 'limited'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * S507 (AD-8): effective direct-play bitrate ceiling for a source of the given
     * height. With per-tier ceilings (max_bitrate_by_height) declared, the smallest
     * tier at/above the source height applies; without them, the flat max_bitrate
     * (the pre-existing behaviour for every other profile).
     *
     * @param array<string, mixed> $profile
     */
    private function resolveBitrateCeiling(int $height, array $profile): int
    {
        $tiers = self::intMapOrNull($profile['max_bitrate_by_height'] ?? null);
        if ($tiers === null || $tiers === []) {
            return self::intOr($profile['max_bitrate'] ?? null);
        }
        $applicable = [];
        $all = [];
        foreach ($tiers as $tierHeight => $bitrate) {
            $all[] = $bitrate;
            if ($tierHeight >= $height) {
                $applicable[] = $bitrate;
            }
        }
        // Taller than every declared tier → the largest tier's ceiling; otherwise the
        // smallest ceiling that still covers the source height.
        return $applicable === [] ? max($all) : min($applicable);
    }

    // ------------------------------------------------------------------
    // S507 (AD-8) boundary readers. Profiles and probe streams arrive as
    // untyped arrays; these coerce each value to its concrete type at the
    // edge (parse, don't cast-mixed) so the gate logic stays total-typed
    // under PHPStan level 9. An absent/malformed value yields the neutral
    // default, which is exactly the byte-identical legacy behaviour.
    // ------------------------------------------------------------------

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function intOr(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function boolOr(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : $default;
    }

    /**
     * Normalise a codec/range/profile token list to lowercased strings, or null
     * when the value is not a list at all (so callers distinguish "unset" from
     * "declared but empty").
     *
     * @return array<int, string>|null
     */
    private static function stringListOrNull(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $out[] = strtolower(trim((string) $item));
            }
        }

        return $out;
    }

    /**
     * The profile's direct-play codec list (a required key; empty when absent).
     *
     * @param array<string, mixed> $profile
     *
     * @return array<int, string>
     */
    private static function directPlayCodecs(array $profile): array
    {
        return self::stringListOrNull($profile['direct_play'] ?? null) ?? [];
    }

    /**
     * Parse a height => bitrate tier map, or null when the profile declares none.
     *
     * @return array<int, int>|null
     */
    private static function intMapOrNull(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $key => $bitrate) {
            if (is_numeric($bitrate)) {
                $out[(int) $key] = (int) $bitrate;
            }
        }

        return $out;
    }

    /**
     * The profile's [maxWidth, maxHeight] resolution cap pair (0 when malformed).
     *
     * @param array<string, mixed> $profile
     *
     * @return array{int, int}
     */
    private static function maxResolution(array $profile): array
    {
        $res = $profile['max_resolution'] ?? null;

        return [
            is_array($res) ? self::intOr($res[0] ?? null) : 0,
            is_array($res) ? self::intOr($res[1] ?? null) : 0,
        ];
    }

    /**
     * Extracts video stream information from source info.
     *
     * @param array{streams?: array<int, array{codec_type?: string, codec?: string, channels?: int, width?: int,
     *     height?: int, bitrate?: int}>, format?: array{format_name?: string}} $sourceInfo Source media information
     *
     * @return array{codec_type?: string, codec?: string, channels?: int, width?: int, height?: int,
     *     bitrate?: int}|null First video stream found or null
     */
    private function getVideoStream(array $sourceInfo): ?array
    {
        foreach ($sourceInfo['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                return $stream;
            }
        }
        return null;
    }

    /**
     * Extracts audio stream information from source info.
     *
     * @param array{streams?: array<int, array{codec_type?: string, codec?: string, channels?: int, width?: int,
     *     height?: int, bitrate?: int}>, format?: array{format_name?: string}} $sourceInfo Source media information
     *
     * @return array{codec_type?: string, codec?: string, channels?: int, width?: int, height?: int,
     *     bitrate?: int}|null First audio stream found or null
     */
    private function getAudioStream(array $sourceInfo): ?array
    {
        foreach ($sourceInfo['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'audio') {
                return $stream;
            }
        }
        return null;
    }

    /**
     * Detects container format from source format info.
     *
     * @param array{streams?: array<int, array<string, mixed>>, format?: array{format_name?: string}} $sourceInfo Source
     * media information
     *
     * @return string Detected container type (mkv, mp4, webm, ts, mp4)
     */
    private function detectContainer(array $sourceInfo): string
    {
        $format = $sourceInfo['format'] ?? [];
        $formatName = strtolower($format['format_name'] ?? '');

        if (str_contains($formatName, 'matroska')) {
            return 'mkv';
        }
        if (str_contains($formatName, 'mp4')) {
            return 'mp4';
        }
        if (str_contains($formatName, 'webm')) {
            return 'webm';
        }
        if (str_contains($formatName, 'mpegts')) {
            return 'ts';
        }

        return 'mp4';
    }

    /**
     * Registers a custom device profile.
     *
     * @param string $name Profile name
     * @param array{
     *     max_bitrate?: int,
     *     max_resolution?: array<int, int>,
     *     direct_play?: array<string>,
     *     transcode?: array<string>,
     *     container?: array<string>
     * } $profile Profile definition
     *
     * @example
     * ```php
     * $selector->registerProfile('smart-tv', [
     *     'max_bitrate' => 20000000,
     *     'max_resolution' => [1920, 1080],
     *     'direct_play' => ['h264', 'h265'],
     * ]);
     * ```
     */
    public function registerProfile(string $name, array $profile): void
    {
        $this->deviceProfiles[$name] = $this->normalizeProfile($profile);
    }

    /**
     * Gets a device profile by name.
     *
     * @param string $name Profile name
     *
     * @return array{
     *     max_bitrate: int,
     *     max_resolution: array<int, int>,
     *     direct_play: array<string>,
     *     transcode: array<string>,
     *     container: array<string>,
     *     audio_codecs?: array<int, string>,
     *     max_audio_channels?: int|null,
     *     max_h264_level?: int|null,
     *     max_hevc_level?: int|null,
     *     hevc_profiles?: array<int, string>,
     *     allow_hdr?: bool,
     *     allow_anamorphic?: bool,
     *     allow_interlaced?: bool,
     *     max_bitrate_by_height?: array<int, int>,
     *     vp9_max_bitrate?: int|null,
     *     vp9_require_limited_range?: bool
     * }|null Profile definition (5 base keys always; the S507 gate keys only for
     *   profiles that opt into them, e.g. samsung-tizen), or null if not found.
     */
    public function getProfile(string $name): ?array
    {
        return $this->deviceProfiles[$name] ?? null;
    }
}
