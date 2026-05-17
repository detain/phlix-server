<?php

/**
 * Hardware encoder profile configuration.
 *
 * Maps quality levels to bitrate and preset settings for each hardware
 * accelerator vendor. These profiles are consumed by HwaccelCommandBuilder
 * when constructing FFmpeg commands.
 *
 * @since 0.11.0
 */

return [
    /**
     * NVIDIA NVENC encoder profiles.
     * Preset: p1 (fastest) to p7 (slowest), with p4-p5 being good quality/performance balance.
     * B-frames disabled for low latency streaming.
     */
    'nvenc' => [
        'ultra' => ['bitrate' => 8000000, 'preset' => 'p3', 'bframes' => 0],
        'high' => ['bitrate' => 5000000, 'preset' => 'p4', 'bframes' => 0],
        'medium' => ['bitrate' => 2500000, 'preset' => 'p5', 'bframes' => 0],
        'low' => ['bitrate' => 1000000, 'preset' => 'p6', 'bframes' => 0],
    ],

    /**
     * VAAPI (Video Acceleration API) encoder profiles.
     * Uses Intel/AMD GPUs via VA-API on Linux.
     * Rate control: CQP (constant quantization) or VBR (variable bitrate).
     */
    'vaapi' => [
        'ultra' => ['bitrate' => 8000000, 'preset' => 'fast', 'bframes' => 0],
        'high' => ['bitrate' => 5000000, 'preset' => 'fast', 'bframes' => 0],
        'medium' => ['bitrate' => 2500000, 'preset' => 'medium', 'bframes' => 0],
        'low' => ['bitrate' => 1000000, 'preset' => 'slow', 'bframes' => 0],
    ],

    /**
     * Intel Quick Sync Video (QSV) encoder profiles.
     * Preset mapping: ultrafast to veryslow for speed/quality trade-off.
     * Supports look-ahead and B-frame manipulation.
     */
    'qsv' => [
        'ultra' => ['bitrate' => 8000000, 'preset' => 'veryfast', 'bframes' => 0],
        'high' => ['bitrate' => 5000000, 'preset' => 'faster', 'bframes' => 0],
        'medium' => ['bitrate' => 2500000, 'preset' => 'fast', 'bframes' => 0],
        'low' => ['bitrate' => 1000000, 'preset' => 'medium', 'bframes' => 0],
    ],

    /**
     * Apple VideoToolbox encoder profiles (macOS).
     * Preset options: real-time, fast, balanced, quality.
     * Unlimited concurrent encodes on Apple Silicon.
     */
    'videotoolbox' => [
        'ultra' => ['bitrate' => 8000000, 'preset' => 'fast', 'bframes' => 0],
        'high' => ['bitrate' => 5000000, 'preset' => 'balanced', 'bframes' => 0],
        'medium' => ['bitrate' => 2500000, 'preset' => 'quality', 'bframes' => 0],
        'low' => ['bitrate' => 1000000, 'preset' => 'real-time', 'bframes' => 0],
    ],

    /**
     * AMD AMF (Advanced Media Framework) encoder profiles.
     * Preset options: speed, balanced, quality.
     * B-frames typically not supported in hardware.
     */
    'amf' => [
        'ultra' => ['bitrate' => 8000000, 'preset' => 'speed', 'bframes' => 0],
        'high' => ['bitrate' => 5000000, 'preset' => 'balanced', 'bframes' => 0],
        'medium' => ['bitrate' => 2500000, 'preset' => 'quality', 'bframes' => 0],
        'low' => ['bitrate' => 1000000, 'preset' => 'speed', 'bframes' => 0],
    ],

    /**
     * Video4Linux2 (V4L2) encoder profiles.
     * Uses Linux kernel V4L2 request API.
     * Limited concurrent encodes, variable performance.
     */
    'v4l2' => [
        'ultra' => ['bitrate' => 8000000, 'preset' => 'fast', 'bframes' => 0],
        'high' => ['bitrate' => 5000000, 'preset' => 'medium', 'bframes' => 0],
        'medium' => ['bitrate' => 2500000, 'preset' => 'slow', 'bframes' => 0],
        'low' => ['bitrate' => 1000000, 'preset' => 'ultrafast', 'bframes' => 0],
    ],

    /**
     * Software encoder profiles (libx264/libx265).
     * CRF-based quality control with preset for speed/quality trade-off.
     * Unlimited concurrent encodes (CPU-bound).
     */
    'software' => [
        'ultra' => ['bitrate' => 8000000, 'crf' => 18, 'preset' => 'slow'],
        'high' => ['bitrate' => 5000000, 'crf' => 20, 'preset' => 'medium'],
        'medium' => ['bitrate' => 2500000, 'crf' => 23, 'preset' => 'medium'],
        'low' => ['bitrate' => 1000000, 'crf' => 26, 'preset' => 'fast'],
    ],
];
