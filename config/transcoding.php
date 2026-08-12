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
     * On-demand segment container: `fmp4` (default since S60) or `mpegts`.
     *
     * Addressed by the dotted setting key `transcoding.segment_format` and
     * consumed by {@see \Phlix\Media\Transcoding\EncodeSettings::segmentFormat()}.
     * This literal MUST stay equal to
     * {@see \Phlix\Media\Transcoding\EncodeSettings::DEFAULT_SEGMENT_FORMAT} —
     * this file is what `SettingsRepository::getDefault()` reads when no admin
     * override row exists, and the constant is what the encode path falls back
     * to, so a drift between them means the effective value the admin API
     * reports is not the one the encoder uses.
     *
     * ## Why the default is `fmp4` (S60)
     *
     * S56 shipped segment PRODUCTION, S57 the matching playlists (`#EXT-X-MAP`
     * + `seg-v{V}-NNNNN.m4s` at `#EXT-X-VERSION:7`), S58 the DASH manifest, S59
     * the DASH serve trigger, S310 the HLS one (`HlsController::serveFile()`
     * routes `.ts` AND `.m4s` segments plus the three `init*.m4s` shapes through
     * {@see \Phlix\Server\Http\Controllers\SegmentRequestParser}), S313 made
     * this key settable over the admin API, and S315 put a real headless Chrome
     * running hls.js in front of the real `/hls/{job_id}/{file}` route and
     * played an fMP4 presentation whose every byte the controller produced on
     * demand. One segment set now serves both HLS and DASH.
     *
     * ## ⚠ ROLLBACK — read this before deploying, not after
     *
     * **Preferred route (no restart, no redeploy, no container edit): PUT the
     * setting.** S313 declared the key in
     * `phlix-shared/schemas/server-settings.schema.json` with
     * `"enum": ["mpegts", "fmp4"]`, so:
     *
     * ```
     * PUT /api/v1/admin/settings   {"transcoding.segment_format": "mpegts"}
     * ```
     *
     * is accepted, and anything else is rejected per-key. `restart: false` is
     * honest here — `EncodeSettings::read()` resolves the value at ENCODE time,
     * so the next transcode picks it up. Playback already in progress is
     * unaffected: a job's container is committed at creation and read back from
     * its own persisted `segment_params`, never from the live setting.
     *
     * What the rollback costs, precisely: the value is folded into
     * `EncodeSettings::fingerprint()` and therefore into the job reuse key, so
     * you get a **fresh job id and a fresh job directory** of `.ts` segments —
     * anything played after the change re-encodes once. `.ts` and `.m4s` can
     * never co-mingle in one directory. It does NOT return you to the
     * pre-S60 job directories: S60 also bumped
     * `TranscodeManager::JOB_KEY_VERSION` to `v10`, so those are orphaned
     * either way and are reclaimed by `sweepSegmentCache()` on the usual idle /
     * LRU terms.
     *
     * **Second route (a redeploy): revert the S60 commit.** That restores this
     * literal AND `EncodeSettings::DEFAULT_SEGMENT_FORMAT` AND `JOB_KEY_VERSION`
     * to `v9` together — all three, or the revert does nothing.
     *
     * ⚠ **Reverting only the two defaults is a SILENT NO-OP, not an extra
     * re-encode.** `EncodeSettings::fingerprint()` is empty whenever every
     * setting is at its shipped default, *whatever that default is*, so a revert
     * of the two literals changes no component of
     * `sha1(media|profile|JOB_KEY_VERSION . fingerprint())`. The key stays
     * byte-identical to the one the existing fMP4 jobs were inserted under,
     * `TranscodeManager::findReusableJob()` returns those jobs, and they keep
     * serving `.m4s` playlists and `.m4s` segments on a box whose config now says
     * `mpegts`. Only never-before-played items get `.ts`, so the result is a
     * mixed fleet — and an operator re-testing on content they already played
     * sees fMP4 still playing and concludes the revert failed to deploy. Reverting
     * `JOB_KEY_VERSION` to `v9` as well is what actually moves the key.
     * (Measured in `tests/Unit/Media/Transcoding/TranscodeManagerRollbackKeyTest.php`.)
     *
     * ## What the flip costs on first deploy
     *
     * `JOB_KEY_VERSION` v9 → v10 orphans every install's existing segment cache.
     * Expect the first playback of every item to re-encode: a CPU burst, extra
     * first-play latency, and both generations of job directory on disk until
     * the LRU sweep reclaims the old ones (3 h idle by default, or sooner under
     * the byte budget).
     *
     * @since S56
     */
    'segment_format' => 'fmp4',
];
