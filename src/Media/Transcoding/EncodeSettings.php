<?php

/**
 * Phlix media server component: Media\Transcoding.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

use Phlix\Admin\SettingsRepository;

/**
 * Single source for the admin-settable software-encode tunables.
 *
 * Backs `transcoding.preset`, `transcoding.crf_h264` and
 * `transcoding.audio_bitrate`. Read-path class (a) LIVE: resolved when encode
 * parameters are assembled, so a change applies to the next transcode without
 * a restart.
 *
 * ## Why a class rather than three ctor ints
 *
 * Each value is produced at MULTIPLE independent sites in
 * {@see TranscodeManager} — three for the preset, three for the CRF, four for
 * the audio bitrate — because the legacy single-variant path, the
 * copy-to-encode upgrade branch and the ABR rendition builder each assemble
 * their own `$params` array from scratch. Wiring some and not others is the
 * half-effective failure this settings program keeps hitting: quality would
 * change on a first play and not on a re-play, or on the 720p rung and not the
 * 1080p one.
 *
 * ## The cache-key problem this class also solves
 *
 * {@see self::fingerprint()} exists because a transcode job PERSISTS the
 * parameters it was created with (`transcode_jobs.segment_params`) and
 * `TranscodeManager::findReusableJob()` returns that job for any later request
 * with the same key. The job key is
 * `sha1(mediaItemId | profileName | JOB_KEY_VERSION)` and contains none of
 * these values, so without a fingerprint an admin could change the preset,
 * press play on something already watched, and get the OLD encode forever —
 * the setting would appear to do nothing precisely for the content most likely
 * to be tested with.
 *
 * **The fingerprint is deliberately EMPTY while every value is at its shipped
 * default.** That keeps the key hash byte-identical to the pre-settings one, so
 * deploying this does NOT invalidate the existing transcode cache and cause a
 * fleet-wide re-encode. The key only diverges once an administrator actually
 * changes something, which is exactly when the old segments are stale.
 *
 * @package Phlix\Media\Transcoding
 * @since 1.3.0
 */
final class EncodeSettings
{
    public const PRESET_KEY = 'transcoding.preset';
    public const CRF_H264_KEY = 'transcoding.crf_h264';
    public const AUDIO_BITRATE_KEY = 'transcoding.audio_bitrate';

    /**
     * Shipped x264/x265 preset. Matches the literal previously hardcoded at all
     * three producer sites.
     */
    public const DEFAULT_PRESET = 'veryfast';

    /**
     * Shipped H.264 CRF. Matches the previously hardcoded literal.
     */
    public const DEFAULT_CRF_H264 = 23;

    /**
     * Shipped AAC bitrate. Matches the previously hardcoded literal.
     */
    public const DEFAULT_AUDIO_BITRATE = '128k';

    /**
     * The x264/x265 preset ladder, fastest first.
     *
     * An unrecognised value is rejected rather than passed through: a bad
     * `-preset` makes ffmpeg exit immediately, which would turn a mistyped
     * setting into "all playback is broken" with the cause buried in a
     * transcode log.
     *
     * @var list<string>
     */
    public const PRESETS = [
        'ultrafast',
        'superfast',
        'veryfast',
        'faster',
        'fast',
        'medium',
        'slow',
        'slower',
        'veryslow',
    ];

    /**
     * NVENC's preset namespace is p1 (fastest) .. p7 (slowest) — the x264 names
     * are NOT valid there and ffmpeg fails outright on them. This maps each
     * x264 preset onto its nearest NVENC rung.
     *
     * `veryfast` maps to `p4`, which is exactly what
     * {@see FfmpegRunner::buildHwaccelSegmentCommand()} hardcoded before this
     * class existed — so at the shipped default the emitted command is
     * unchanged.
     *
     * @var array<string, string>
     */
    public const NVENC_PRESET_MAP = [
        'ultrafast' => 'p1',
        'superfast' => 'p2',
        'veryfast'  => 'p4',
        'faster'    => 'p4',
        'fast'      => 'p4',
        'medium'    => 'p5',
        'slow'      => 'p6',
        'slower'    => 'p7',
        'veryslow'  => 'p7',
    ];

    /**
     * CRF bounds. 0 is lossless (enormous files) and 51 is the codec's worst
     * legal value; both extremes are accepted by ffmpeg but neither is a
     * sensible thing for a settings field to permit by accident.
     */
    public const MIN_CRF = 16;
    public const MAX_CRF = 40;

    /**
     * Audio-bitrate bounds in kbps. Below ~32k AAC is unpleasant at any sample
     * rate; above 512k there is nothing left to gain for stereo playback.
     */
    public const MIN_AUDIO_KBPS = 32;
    public const MAX_AUDIO_KBPS = 512;

    public function __construct(
        private readonly ?SettingsRepository $settings = null,
    ) {
    }

    /**
     * The effective x264/x265 preset name.
     *
     * @return string One of {@see self::PRESETS}.
     */
    public function preset(): string
    {
        $configured = $this->read(self::PRESET_KEY);
        if (!is_string($configured)) {
            return self::DEFAULT_PRESET;
        }

        $normalised = strtolower(trim($configured));

        return in_array($normalised, self::PRESETS, true) ? $normalised : self::DEFAULT_PRESET;
    }

    /**
     * The effective preset for a hardware encoder of `$vendor`.
     *
     * NVENC uses its own p1..p7 namespace; the other vendors in this codebase
     * are already driven with x264-style names, so they receive the preset
     * unchanged.
     *
     * @param string $vendor Hwaccel vendor id, e.g. `nvenc`, `vaapi`, `qsv`.
     */
    public function presetForVendor(string $vendor): string
    {
        $preset = $this->preset();

        if ($vendor === 'nvenc') {
            return self::NVENC_PRESET_MAP[$preset] ?? 'p4';
        }

        return $preset;
    }

    /**
     * The effective H.264 CRF, clamped to {@see self::MIN_CRF}..{@see self::MAX_CRF}.
     */
    public function crfH264(): int
    {
        $configured = $this->read(self::CRF_H264_KEY);

        $value = match (true) {
            is_int($configured) => $configured,
            is_string($configured) && is_numeric($configured) => (int) $configured,
            default => self::DEFAULT_CRF_H264,
        };

        return max(self::MIN_CRF, min(self::MAX_CRF, $value));
    }

    /**
     * The effective AAC bitrate as an ffmpeg-ready string, e.g. `128k`.
     *
     * Accepts `128k`, `128K` or a bare `128`, and always emits the normalised
     * `<n>k` form. Anything unparseable falls back to the shipped default
     * rather than reaching the command line — an invalid `-b:a` is another way
     * to make every transcode fail.
     */
    public function audioBitrate(): string
    {
        $configured = $this->read(self::AUDIO_BITRATE_KEY);

        if (is_int($configured)) {
            return $this->clampKbps($configured);
        }

        if (!is_string($configured)) {
            return self::DEFAULT_AUDIO_BITRATE;
        }

        $trimmed = strtolower(trim($configured));
        if (preg_match('/^(\d+)k?$/', $trimmed, $m) !== 1) {
            return self::DEFAULT_AUDIO_BITRATE;
        }

        return $this->clampKbps((int) $m[1]);
    }

    /**
     * A short token identifying this settings combination, or `''` when every
     * value is at its shipped default.
     *
     * Folded into {@see TranscodeManager}'s job key so that changing a setting
     * produces a different key and therefore a fresh encode, while leaving the
     * key untouched — and the existing cache valid — on an install that has
     * never changed one.
     *
     * @see self for the full rationale.
     */
    public function fingerprint(): string
    {
        $preset = $this->preset();
        $crf = $this->crfH264();
        $audio = $this->audioBitrate();

        if (
            $preset === self::DEFAULT_PRESET
            && $crf === self::DEFAULT_CRF_H264
            && $audio === self::DEFAULT_AUDIO_BITRATE
        ) {
            return '';
        }

        return substr(sha1($preset . '|' . $crf . '|' . $audio), 0, 12);
    }

    /**
     * Clamp a kbps figure and render it in ffmpeg's `<n>k` form.
     */
    private function clampKbps(int $kbps): string
    {
        return max(self::MIN_AUDIO_KBPS, min(self::MAX_AUDIO_KBPS, $kbps)) . 'k';
    }

    /**
     * Read one key, degrading to null on any failure so a settings-store
     * outage can never break transcoding.
     */
    private function read(string $key): mixed
    {
        if ($this->settings === null) {
            return null;
        }

        try {
            return $this->settings->getEffective($key);
        } catch (\Throwable) {
            return null;
        }
    }
}
