<?php

/**
 * Transcoding configuration.
 *
 * @since 0.36.0
 */

return [
    /**
     * Preferred hardware accelerator to use for transcoding.
     *
     * Set to the empty string '' (the default) to auto-select based on
     * availability and priority order. '' is also the "auto-detect" sentinel
     * declared by the shared `server-settings.schema.json` for
     * `transcoding.preferred_accelerator`, so the effective value returned by
     * GET /api/v1/admin/settings matches an enum member and the admin SPA's
     * select renders it as selected rather than blank.
     *
     * Supported values: '' (auto-detect), 'cuda', 'qsv', 'vaapi',
     * 'videotoolbox', 'amf', 'opencl', 'd3d11va', 'dxva2', 'v4l2m2m'.
     *
     * Note these are FFmpeg *hwaccel* names, not encoder names — 'nvenc' is an
     * encoder and is deliberately not accepted here.
     *
     * Example env: PREFERRED_ACCELERATOR=cuda
     */
    'preferred_accelerator' => getenv('PREFERRED_ACCELERATOR') ?: '',

    /**
     * Timeout for hardware accelerator probing in seconds.
     */
    'probe_timeout' => 30,

    /**
     * Path to a test clip used for hardware accelerator acceptance testing.
     * If empty, acceptance tests will be skipped.
     */
    'test_clip_path' => '/tmp/hwaccel_probe_test.mp4',

    /**
     * Whether to include software encoding as a fallback option in accelerator lists.
     */
    'include_software_fallback' => true,

    /**
     * HDR tone mapping mode for transcoding HDR content.
     *
     * When set to 'none' (default), no tone mapping is applied.
     * When set to 'zscale', uses the zscale filter for CPU-based HDR→SDR conversion.
     * When set to 'libplacebo', uses libplacebo for high-quality tone mapping.
     *
     * Supported values: 'none', 'zscale', 'libplacebo'
     *
     * Example env: TONE_MAPPING_MODE=zscale
     */
    'tone_mapping_mode' => getenv('TONE_MAPPING_MODE') ?: 'none',

    /**
     * Whether to prefer HDR output over SDR tone mapping.
     *
     * When true, outputs HDR10 instead of tone-mapping to SDR.
     * This is useful for displays that support HDR content directly.
     *
     * Example env: PREFER_HDR_OUTPUT=true
     */
    'prefer_hdr_output' => filter_var(getenv('PREFER_HDR_OUTPUT') ?: 'false', FILTER_VALIDATE_BOOLEAN),

    /**
     * x264/x265 encoder preset — the speed/compression trade.
     *
     * Addressed by the dotted setting key `transcoding.preset`. Consumed by
     * {@see \Phlix\Media\Transcoding\EncodeSettings::preset()}, which is the
     * single source for all THREE sites in `TranscodeManager` that assemble
     * encode parameters (the ABR rendition builder, the copy-to-encode upgrade
     * branch, and the legacy single-variant path).
     *
     * Validated against `EncodeSettings::PRESETS`; an unrecognised value falls
     * back to `veryfast` rather than reaching ffmpeg, since a bad `-preset`
     * makes every transcode fail immediately.
     *
     * On hardware encoders this is honoured only once it differs from the
     * shipped default — see the comment in
     * `FfmpegRunner::buildHwaccelSegmentCommand()`.
     */
    'preset' => 'veryfast',

    /**
     * Constant Rate Factor for H.264 software encodes: lower is better quality
     * and a larger file.
     *
     * Addressed by the dotted setting key `transcoding.crf_h264`. Clamped to
     * `EncodeSettings::MIN_CRF`..`MAX_CRF` (16..40) in code — the codec accepts
     * 0..51, but 0 is lossless and 51 is unwatchable, and neither is something
     * a settings field should permit by accident.
     *
     * NB there is deliberately no `crf_h265` companion: nothing in `src/` ever
     * sets `video_codec` to `libx265`, so such a key would have no live
     * consumer.
     */
    'crf_h264' => 23,

    /**
     * AAC audio bitrate for transcoded output.
     *
     * Addressed by the dotted setting key `transcoding.audio_bitrate`. Consumed
     * by {@see \Phlix\Media\Transcoding\EncodeSettings::audioBitrate()}, the
     * single source for all FOUR sites that set it. Accepts `128k` or a bare
     * `128`; normalised to ffmpeg's `<n>k` form and clamped to 32..512 kbps.
     */
    'audio_bitrate' => '128k',

    /**
     * On-demand segment container: `mpegts` (default) or `fmp4`.
     *
     * Addressed by the dotted setting key `transcoding.segment_format` and
     * consumed by {@see \Phlix\Media\Transcoding\EncodeSettings::segmentFormat()}.
     *
     * ✅ **`fmp4` IS SERVABLE as of S310** — but it is still OFF BY DEFAULT and
     * has not been cross-client verified, so leave it at `mpegts` unless you are
     * deliberately testing the fMP4 path. S56 shipped segment PRODUCTION, S57
     * the matching playlists (`#EXT-X-MAP` + `seg-v{V}-NNNNN.m4s` at
     * `#EXT-X-VERSION:7`), S58 the DASH manifest, S59 the DASH serve trigger,
     * and S310 the HLS one: `HlsController::serveFile()` routes `.ts` AND `.m4s`
     * segments plus the three `init*.m4s` shapes through the shared
     * {@see \Phlix\Server\Http\Controllers\SegmentRequestParser}, so a flagged
     * job's init (which hls.js fetches first) and every segment after it are
     * produced on demand and served. Between S56 and S310 turning this on broke
     * playback outright; it now works, and what is unproven is the CLIENT
     * matrix, which is S60's job.
     *
     * ✅ **SETTABLE OVER THE ADMIN API as of S313** (phlix-shared v0.49.0).
     * The key is now declared in
     * `phlix-shared/schemas/server-settings.schema.json` with
     * `"enum": ["mpegts", "fmp4"]`, so `AdminSettingsController` accepts a PUT
     * of either member and rejects anything else with a per-key error.
     * Editing this file is no longer the only way to change it — which is the
     * point: S60 flips the default, and a flag that can only be flipped by
     * hand-editing a config file inside a running container has no rollback
     * path. **This literal is still the shipped default and S313 did not touch
     * it**; S60 owns the flip, together with the matching
     * `TranscodeManager::JOB_KEY_VERSION` bump.
     *
     * The value is folded into `EncodeSettings::fingerprint()` and therefore
     * into the transcode job reuse key, so a flip yields a fresh job id and a
     * fresh job directory — `.ts` and `.m4s` segments can never co-mingle, and
     * reverting the setting restores the original jobs.
     *
     * @since S56
     */
    'segment_format' => 'mpegts',
];
