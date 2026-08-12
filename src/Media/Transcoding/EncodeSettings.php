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
 * ⚠ **S60 is the one deploy that DOES invalidate the whole cache, deliberately.**
 * Because the fingerprint is empty at the default, moving
 * {@see self::DEFAULT_SEGMENT_FORMAT} cannot express itself here — so the
 * invalidation was done where it can be, by bumping
 * {@see TranscodeManager::JOB_KEY_VERSION} to `v10` in the same commit. Expect
 * every install's first playback of every item after that deploy to re-encode.
 * See the S60 TRAP note in {@see self::fingerprint()}.
 *
 * @package Phlix\Media\Transcoding
 * @since 1.3.0
 */
final class EncodeSettings
{
    public const PRESET_KEY = 'transcoding.preset';
    public const CRF_H264_KEY = 'transcoding.crf_h264';
    public const AUDIO_BITRATE_KEY = 'transcoding.audio_bitrate';
    public const SEGMENT_FORMAT_KEY = 'transcoding.segment_format';

    /**
     * MPEG-TS on-demand segments (`seg-v{V}-NNNNN.ts`) — the pre-S60 shipped
     * behaviour, and still the rollback target.
     */
    public const FORMAT_MPEGTS = 'mpegts';

    /** CMAF fragmented-MP4 segments (`init-v{V}.m4s` + `seg-v{V}-NNNNN.m4s`). */
    public const FORMAT_FMP4 = 'fmp4';

    /** @var list<string> The accepted `transcoding.segment_format` values. */
    public const SEGMENT_FORMATS = [self::FORMAT_MPEGTS, self::FORMAT_FMP4];

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
     * Shipped on-demand segment container. **S60 flipped this to
     * {@see self::FORMAT_FMP4}**; it was {@see self::FORMAT_MPEGTS} from the
     * beginning of the project until then.
     *
     * ## What the flip changes, and what it does NOT
     *
     * A job created from here on writes `#EXT-X-MAP:URI="init-v{V}.m4s"` +
     * `seg-v{V}-NNNNN.m4s` media playlists at `#EXT-X-VERSION:7`, publishes a
     * DASH `manifest.mpd` beside them, and serves both through
     * {@see TranscodeManager::ensureSegment()}. MPEG-TS is NOT removed — it is
     * one PUT away (`transcoding.segment_format = mpegts`, declared in
     * phlix-shared's `server-settings.schema.json` since S313) and every
     * `.ts` code path is still live and still tested.
     *
     * ## ⚠ This constant is NOT "what an unstamped job used"
     *
     * Every job created before S60 persisted a `segment_params` with **no**
     * `segment_format` key, because {@see TranscodeManager::computeSegmentParams()}
     * only ever wrote the key for `fmp4`. Those jobs hold `.ts` bytes on disk.
     * So "the persisted params said nothing" must resolve to
     * {@see self::FORMAT_MPEGTS} as a LITERAL, never to this constant — see
     * {@see TranscodeManager::segmentFormatOf()}. Resolving an unstamped job
     * through this constant would, at the instant of the flip, re-label every
     * pre-existing MPEG-TS job on disk as fMP4: its playlists would regenerate
     * naming `.m4s`, and every `.ts` request from a player mid-session would
     * 404 after wasting an encode.
     *
     * @since S60 (was `FORMAT_MPEGTS` since S56)
     */
    public const DEFAULT_SEGMENT_FORMAT = self::FORMAT_FMP4;

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
     * The effective on-demand segment container.
     *
     * ## ⚠ `fmp4` IS THE DEFAULT AS OF S60
     *
     * S56 delivered segment PRODUCTION, S57 the matching HLS playlists, S58 the
     * DASH manifest, S59 the DASH serve trigger, S310 the HLS one, S313 made the
     * key settable over the admin API and S315 proved hls.js plays an fMP4
     * presentation served entirely by the real `/hls/{job_id}/{file}` route. With
     * this at {@see self::FORMAT_FMP4} a job writes media playlists carrying
     * `#EXT-X-MAP:URI="init-v{V}.m4s"` + `seg-v{V}-NNNNN.m4s` entries at
     * `#EXT-X-VERSION:7`, and both serve paths route those names through
     * {@see TranscodeManager::ensureSegment()} via the shared
     * {@see \Phlix\Server\Http\Controllers\SegmentRequestParser} — including the
     * init, which maps to index 0 of its own rendition and is what a client
     * fetches FIRST.
     *
     * ⚠ Before S310 this paragraph said the opposite, and it was right to:
     * `HlsController::serveFile()` matched `/^seg-v…\.ts$/` only, so an `.m4s`
     * request never reached the producer and `init-v{V}.m4s` was never created
     * at all. Turning the flag on then yielded a job whose playlists were
     * correct and whose every segment request 404'd.
     *
     * ## Rollback
     *
     * `mpegts` is NOT removed and is one admin PUT away — S313 (phlix-shared
     * v0.49.0) declares the key in `server-settings.schema.json` with
     * `"enum": ["mpegts", "fmp4"]`, so `AdminSettingsController` accepts a PUT of
     * either member and rejects anything else. That enum is
     * {@see self::SEGMENT_FORMATS}, and
     * `tests/Unit/Media/Transcoding/SegmentFormatSchemaEnumDriftTest.php` fails
     * if either side moves alone. Setting it back to `mpegts` changes
     * {@see self::fingerprint()} (see the S60 note there), so the next
     * `ensureHlsJob()` gets a different key, a fresh job id and a fresh
     * directory of `.ts` segments.
     *
     * An unrecognised value falls back to the shipped default rather than
     * reaching the encode path, for the same reason a bad `-preset` does.
     *
     * @return self::FORMAT_* One of {@see self::SEGMENT_FORMATS}.
     *
     * @since S56
     */
    public function segmentFormat(): string
    {
        $configured = $this->read(self::SEGMENT_FORMAT_KEY);
        if (!is_string($configured)) {
            return self::DEFAULT_SEGMENT_FORMAT;
        }

        // ⚠ S60. This was `=== FORMAT_FMP4 ? FORMAT_FMP4 : DEFAULT_SEGMENT_FORMAT`,
        // which was correct only while the default WAS mpegts: it folded "the
        // admin explicitly chose mpegts" into "fall back to the default". The
        // moment S60 flipped the default that expression made
        // `transcoding.segment_format = mpegts` return `fmp4` — i.e. it deleted
        // the rollback path, silently, in the one method the rollback goes
        // through. Each member of {@see self::SEGMENT_FORMATS} is now named
        // explicitly and only an UNRECOGNISED value reaches the default.
        return match (strtolower(trim($configured))) {
            self::FORMAT_FMP4 => self::FORMAT_FMP4,
            self::FORMAT_MPEGTS => self::FORMAT_MPEGTS,
            default => self::DEFAULT_SEGMENT_FORMAT,
        };
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
        $format = $this->segmentFormat();

        if (
            $preset === self::DEFAULT_PRESET
            && $crf === self::DEFAULT_CRF_H264
            && $audio === self::DEFAULT_AUDIO_BITRATE
            && $format === self::DEFAULT_SEGMENT_FORMAT
        ) {
            return '';
        }

        // S56: the segment container is folded in as a SUFFIX that is empty at
        // the shipped default, NOT as a fourth always-present hash field. Two
        // properties depend on that, and both would be lost by hashing
        // `$preset|$crf|$audio|$format` unconditionally:
        //
        //  1. An install that has already moved the preset/CRF/bitrate keeps its
        //     EXISTING fingerprint byte-for-byte while the container is at the
        //     default, so merely deploying S56 invalidates nothing anywhere.
        //     (That is also why S56 needs no JOB_KEY_VERSION bump: this method
        //     supplies the invalidation, and only at the moment of the flip.)
        //  2. Flipping the container is still guaranteed to change the key even
        //     on such an install — `slow|23|128k` and `slow|23|128k|fmp4` are
        //     different strings — so `.ts` and `.m4s` can never share a job dir.
        //
        // ⚠ S60 TRAP — SPRUNG, and disarmed by the JOB_KEY_VERSION bump.
        //
        // S60 flipped DEFAULT_SEGMENT_FORMAT to `fmp4`, so the suffix collapsed
        // back to '' for fmp4 and this method went back to returning the MPEGTS
        // value for the new default — which would have re-matched every
        // pre-existing MPEG-TS job and served `.ts` bytes against `.m4s`
        // playlists. What prevents that is
        // {@see TranscodeManager::JOB_KEY_VERSION}, bumped `v9` → `v10` in the
        // same commit: the key is `sha1(media|profile|VERSION . fingerprint())`,
        // so `…|v9` and `…|v10` cannot collide however this method behaves.
        //
        // ⚠ REVERTING THE FLIP MUST ALSO REVERT THE BUMP. Restoring
        // DEFAULT_SEGMENT_FORMAT to `mpegts` while leaving JOB_KEY_VERSION at
        // `v10` re-orphans every v10 job for a second time — a second fleet-wide
        // re-encode for no benefit. (An operator rolling back over the ADMIN API
        // instead — `transcoding.segment_format = mpegts`, the supported route —
        // touches neither constant: the suffix becomes `|mpegts`, the
        // fingerprint becomes non-empty, and they get a fresh `.ts` job. See
        // `config/transcoding.php`'s rollback block.)
        $suffix = $format === self::DEFAULT_SEGMENT_FORMAT ? '' : ('|' . $format);

        return substr(sha1($preset . '|' . $crf . '|' . $audio . $suffix), 0, 12);
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
