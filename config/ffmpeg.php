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

// HwAccelConfig is now autoloaded from src/Config/HwAccelConfig.php via PSR-4.

return [
    'ffmpeg_path' => '/usr/bin/ffmpeg',
    'ffprobe_path' => '/usr/bin/ffprobe',
    'transcode_dir' => '/var/transcodes',
    'segment_dir' => '/var/segments',
    'max_concurrent_transcodes' => 4,
    'transcode_timeout' => 7200,

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
];
