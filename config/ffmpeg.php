<?php

/**
 * FFmpeg configuration.
 *
 * This file contains ffmpeg-specific settings (paths, timeouts, concurrent limits)
 * as well as a deprecated hwaccel bridge.
 *
 * @deprecated The 'hwaccel' key below is deprecated. It now delegates to
 *             \Phlix\Config\HwAccelConfig::get() which provides the SINGLE SOURCE
 *             OF TRUTH for hardware acceleration settings. The method returns a
 *             merged config that combines hwaccel base settings with transcoding.php
 *             tone-map and preferred accelerator settings.
 *
 *             For runtime config, always use:
 *               $config = \Phlix\Config\HwAccelConfig::get();
 *
 *             Or in DI/container contexts that read from ffmpeg.php config array:
 *               $config['hwaccel'] = \Phlix\Config\HwAccelConfig::get();
 *
 * @since 0.1.0
 */

declare(strict_types=1);

use Phlix\Common\Env\EnvConfig;

// HwAccelConfig is now autoloaded from src/Config/HwAccelConfig.php via PSR-4.

return [
    'ffmpeg_path' => '/usr/bin/ffmpeg',
    'ffprobe_path' => '/usr/bin/ffprobe',
    'transcode_dir' => '/var/transcodes',
    'segment_dir' => '/var/segments',
    'max_concurrent_transcodes' => 4,
    'transcode_timeout' => 7200,

    /**
     * playback-lag wave C: thread cap for the SOFTWARE libx264 segment encoder
     * (emitted as `-threads N` by FfmpegRunner::buildSegmentCommand() on the
     * libx264 branch only — never the copy branch, the audio builders, or any
     * hw-accel builder).
     *
     * WHY (the honest version): this is CONTENTION FAIRNESS, not throughput
     * magic. Left alone, x264 sizes its thread pool to the whole machine — and
     * the HEVC/hybrid decoder chain spawns its own pool on top — so each
     * concurrent software segment encode auto-launches roughly (cores + 2)
     * runnable threads. FIELD-hub-phlix measured the consequence on a
     * GPU-less 12-core prod host: 3.2–4.7 s per 6 s segment at the default
     * in-flight mix (worst 7.9 s on a segment the reader itself was waiting
     * for), i.e. every encode finishes, but each one runs 2–4x slower than it
     * alone would, and segments cross the client's fragment timeout that
     * produces the seek-cascade overlay. Capping per-encode threads leaves
     * cores in reserve for the OTHER encodes sharing the box; the sum of the
     * encodes' aggregate progress is unchanged, but each individual segment
     * lands predictably inside its own wall-time budget. Setting 0 (or a
     * negative) disables the flag entirely and restores x264's auto-sizing.
     *
     * Default: floor(cores / 3), floored at 2 — thirds because the measured
     * prod mix runs roughly one decode pool + one encode pool + headroom per
     * active encode; the 2 floor keeps even a 1–2 core box from serializing
     * x264 into a single-thread stall. Env override:
     * PHLIX_SEGMENT_ENCODE_THREADS (the `"0"`-survives rule, M-SERVER-34 —
     * this is why the helper is EnvConfig::integer and not `getenv() ?: `).
     */
    'segment_encode_threads' => EnvConfig::integer(
        'PHLIX_SEGMENT_ENCODE_THREADS',
        max(2, intdiv(EnvConfig::availableCpuCount(), 3))
    ),

    /**
     * perf #5: thread cap for the SOFTWARE decoder of a software segment encode.
     *
     * Emitted as an INPUT option, `-threads N` before `-i`, so it sizes the
     * decoder's frame-thread pool. `segment_encode_threads` above caps only the
     * output side (the x264 pool); without this the decoder auto-sizes to the whole
     * machine on every concurrent encode. Applies to the software builder's
     * non-copy video path only: a stream copy decodes nothing, and the hardware
     * decode paths are not touched.
     *
     * MEASURED (hub.phlix, 12-core EPYC, 30 s of 1080p H.264 at -ss 1800, output
     * `-threads 4` held fixed, two passes): decoder auto 10.3 s, 1 thread 10.4-10.6 s,
     * 2 threads 10.3-10.7 s, 4 threads 10.1-10.6 s wall. Every setting is within
     * run-to-run noise, so this cap showed no benefit on that clip.
     *
     * Default 0: the flag is off and the decoder auto-sizes, which is identical to the
     * behaviour before this knob existed. Enabling it is an opt-in for hosts that run
     * many concurrent software encodes of 4K-class sources, where the decoder's
     * auto-sized pool is expected to contend. Measure on your own host before enabling
     * it. A positive value bounds the decoder's frame-thread pool; 0 or a negative
     * value disables the flag. The env value is read by EnvConfig::integer like every
     * other integer knob, so a non-numeric value reads as its (int) cast (`abc` is 0,
     * which is off; `4x` is 4). Env override: PHLIX_SEGMENT_DECODE_THREADS.
     */
    'segment_decode_threads' => EnvConfig::integer('PHLIX_SEGMENT_DECODE_THREADS', 0),

    /**
     * perf #11: directory for the persisted, cross-worker ffprobe cache
     * ({@see \Phlix\Media\Transcoding\ProbeCache}). One small JSON file per media
     * path, keyed by realpath and validated by size + mtime, so it survives worker
     * restarts and is shared by all 14 HTTP workers. Without it each worker re-probes
     * every file once and the first request after a restart pays for it.
     *
     * Entries are written atomically (mode 0600) and bounded: entries over 64 KiB
     * are not stored. A background timer prunes the directory (never on a probe request)
     * only while it holds more than 10000 entries, down to 9000. One worker prunes per
     * interval, because all workers share the directory. A directory that cannot be
     * created or written is a cache miss, never a request failure.
     *
     * Trust: the cache is used only when the check passes. The directory must be a real
     * directory (not a symlink), owned by the effective uid of the Phlix process, with no
     * group or other write bit. No ancestor up to / may be writable by group or other
     * unless it has the sticky bit. The check runs once per worker at build. Each read and
     * write re-checks the directory and its device and inode, and a replaced directory
     * disables the cache for that process. Another local user could otherwise plant probe
     * results that drive copy/transcode decisions. A failed check disables the disk cache
     * (one warning per worker), and the server probes as before.
     *
     * Residual race: PHP has no openat(2), so a directory swapped in the microseconds
     * between the check and the open or rename is not closed. The device-and-inode check
     * catches a swap that is still in place at the next operation. Use the system temp
     * directory only where it passes the check. Point PHLIX_PROBE_CACHE_DIR at a
     * persistent path owned by the service user (e.g. under /var/phlix) to keep the cache
     * across reboots. A reboot only costs re-probing.
     */
    'probe_cache_dir' => EnvConfig::text('PHLIX_PROBE_CACHE_DIR', sys_get_temp_dir() . '/phlix-ffprobe-cache'),

    // S8: bounded fan-out cap for MediaScanner::scanFlat()'s concurrent ffprobe
    // pool (only active inside a Swoole coroutine — see MediaScanner). Mirrors
    // the max_concurrent_transcodes knob's style/placement.
    'max_concurrent_scan_probes' => 4,

    /**
     * Hardware acceleration settings.
     *
     * @deprecated Use \Phlix\Config\HwAccelConfig::get() instead.
     *             This key now delegates to hwaccel.php for the authoritative config.
     *             The merged config combines hwaccel base settings with
     *             transcoding.php tone-map mode and preferred accelerator settings.
     */
    'hwaccel' => \Phlix\Config\HwAccelConfig::get(),

    'hwaccel_profiles' => require __DIR__ . '/hwaccel_profiles.php',
    'subtitles' => require __DIR__ . '/subtitles.php',
    'dash' => [
        'enabled' => true,
        'segment_dir' => '/var/segments',
        'default_codecs' => [
            'video' => 'avc1.64001f',
            'audio' => 'mp4a.40.2',
        ],
    ],

    /**
     * SV-3.3: Loudness normalization settings (ebur128/loudnorm filter).
     *
     * When enabled, audio transcode jobs will apply loudness normalization.
     * Target values follow EBU R128 standard:
     *   - Podcast/Online: I=-16, LRA=11, TP=-1.5
     *   - Broadcast: I=-23, LRA=7, TP=-2.0
     *   - Streaming (Netflix-style): I=-27, LRA=2.0, TP=-2.0
     *
     * Set to null to disable, or an array with 'I', 'LRA', 'TP' keys to enable.
     *
     * OPERATOR NOTE: loudnorm applies ONLY to RE-ENCODED audio. Any rung that
     * copies the source audio stream (`-c:a copy`, e.g. the `original` variant)
     * and every direct-play session bypass loudness normalization BY DESIGN —
     * you cannot apply an audio filter to a copied (non-decoded) stream. To
     * normalize such content it must be transcoded (audio re-encode), not copied.
     */
    'loudness' => [
        'enabled' => false,
        'I' => -16,
        'LRA' => 11,
        'TP' => -1.5,
    ],
];
