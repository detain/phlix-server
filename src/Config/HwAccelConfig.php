<?php

/**
 * Hardware acceleration configuration.
 *
 * This file is the SINGLE SOURCE OF TRUTH for hardware acceleration settings.
 *
 * Configuration precedence:
 * - `hwaccel_base.php` provides the base hwaccel settings (enabled, prefer_hardware,
 *   vendor_priority, probe_timeout, test_clip_path, fallback_to_software)
 * - `transcoding.php` provides tone-map mode and preferred accelerator settings
 *   which are merged into the returned config at runtime via HwAccelConfig::get()
 *
 * Usage:
 *   // Get the authoritative merged config (RECOMMENDED)
 *   $mergedConfig = \Phlix\Config\HwAccelConfig::get();
 *
 * @since 0.11.0
 */

declare(strict_types=1);

namespace Phlix\Config;

/**
 * Hardware acceleration configuration provider.
 *
 * Provides a merged configuration that combines hwaccel base settings
 * with transcoding-specific settings (tone_mapping_mode, preferred_accelerator).
 * This is the single source of truth for hardware acceleration at runtime.
 *
 * @since 0.11.0
 */
final class HwAccelConfig
{
    /** @var array<string, mixed>|null Cached merged config instance */
    private static ?array $mergedConfig = null;

    /**
     * Get the merged hardware acceleration configuration.
     *
     * This combines the base hwaccel settings from hwaccel_base.php with the
     * tone-mapping and accelerator preference settings from transcoding.php.
     * The result is cached after the first call.
     *
     * @return array<string, mixed> The merged configuration array
     */
    public static function get(): array
    {
        if (self::$mergedConfig !== null) {
            return self::$mergedConfig;
        }

        // Base hwaccel configuration (from config/hwaccel_base.php relative to project root)
        $configDir = dirname(__DIR__, 2) . '/config';
        $hwaccelBase = require $configDir . '/hwaccel_base.php';

        // Load transcoding config for tone-map mode and preferred accelerator
        $transcodingConfig = [];
        $transcodingPath = $configDir . '/transcoding.php';
        if (is_file($transcodingPath) && is_readable($transcodingPath)) {
            $transcodingConfig = include $transcodingPath;
            if (!is_array($transcodingConfig)) {
                $transcodingConfig = [];
            }
        }

        // Merge transcoding settings into hwaccel config
        // These override or supplement the base hwaccel settings
        $merged = array_merge($hwaccelBase, [
            // From transcoding.php - preferred accelerator (e.g., 'cuda', 'qsv', 'vaapi')
            'preferred_accelerator' => $transcodingConfig['preferred_accelerator'] ?? null,

            // From transcoding.php - HDR tone mapping mode ('none', 'zscale', 'libplacebo')
            'tone_mapping_mode' => $transcodingConfig['tone_mapping_mode'] ?? 'none',

            // From transcoding.php - prefer HDR output over SDR tone mapping
            'prefer_hdr_output' => $transcodingConfig['prefer_hdr_output'] ?? false,

            // From transcoding.php - probe timeout (ensure consistency)
            'probe_timeout' => $transcodingConfig['probe_timeout'] ?? $hwaccelBase['probe_timeout'],

            // From transcoding.php - test clip path (ensure consistency)
            'test_clip_path' => $transcodingConfig['test_clip_path'] ?? $hwaccelBase['test_clip_path'],

            // From transcoding.php - include software fallback
            'include_software_fallback' => $transcodingConfig['include_software_fallback'] ?? true,
        ]);

        // Keep base hwaccel settings not in transcoding
        $merged['vendor_priority'] = $hwaccelBase['vendor_priority'];
        $merged['fallback_to_software'] = $hwaccelBase['fallback_to_software'];
        $merged['enabled'] = $hwaccelBase['enabled'];
        $merged['prefer_hardware'] = $hwaccelBase['prefer_hardware'];

        self::$mergedConfig = $merged;
        return $merged;
    }

    /**
     * Reset the cached config (useful for testing).
     */
    public static function reset(): void
    {
        self::$mergedConfig = null;
    }
}
