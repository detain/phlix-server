<?php

/**
 * Hardware acceleration configuration.
 *
 * This file is the SINGLE SOURCE OF TRUTH for hardware acceleration settings.
 *
 * Configuration precedence:
 * - `hwaccel.php` (which re-exports `hwaccel_base.php`) provides the base
 *   hwaccel settings (enabled, prefer_hardware, vendor_priority, probe_timeout,
 *   test_clip_path, fallback_to_software)
 * - `transcoding.php` provides tone-map mode and preferred accelerator settings
 *   which are merged into the returned config at runtime via HwAccelConfig::get()
 *
 * Both files are read through {@see \Phlix\Config\EffectiveConfig::file()}, so
 * an admin `server_settings` override for `hwaccel.*` / `transcoding.*` is
 * applied here rather than being silently ignored — that is what makes those
 * keys' schema `"restart": true` promise true. Reading `hwaccel.php` (not
 * `hwaccel_base.php`) is deliberate: the `hwaccel.*` dotted keys resolve
 * against `config/hwaccel.php` in {@see \Phlix\Admin\SettingsRepository}, so
 * defaults and overrides now come from exactly the same file.
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
     * {@see EffectiveConfig::generation()} the cache above was built against.
     * `-1` = "no cache". Re-bootstrapping the overlay bumps the generation and
     * therefore invalidates this cache automatically, which matters because
     * `config/ffmpeg.php` calls `get()` while `config/server.php` is being
     * `include`d in the MASTER process — i.e. before any worker has read the
     * `server_settings` overrides. Without the generation check, every forked
     * child would inherit the master's pre-overlay merge.
     */
    private static int $mergedGeneration = -1;

    /**
     * Get the merged hardware acceleration configuration.
     *
     * This combines the base hwaccel settings from `config/hwaccel.php` with
     * the tone-mapping and accelerator preference settings from
     * `config/transcoding.php` — both read through {@see EffectiveConfig} so
     * admin overrides apply. The result is cached until the overlay is
     * re-bootstrapped.
     *
     * @return array<string, mixed> The merged configuration array
     */
    public static function get(): array
    {
        $generation = EffectiveConfig::generation();
        if (self::$mergedConfig !== null && self::$mergedGeneration === $generation) {
            return self::$mergedConfig;
        }

        // Base hwaccel configuration + any `hwaccel.*` admin override.
        $hwaccelBase = EffectiveConfig::file('hwaccel');

        // Transcoding config (tone-map mode, preferred accelerator) + any
        // `transcoding.*` admin override.
        $transcodingConfig = EffectiveConfig::file('transcoding');

        // Merge transcoding settings into hwaccel config
        // These override or supplement the base hwaccel settings
        $merged = array_merge($hwaccelBase, [
            // From transcoding.php - preferred accelerator (e.g., 'cuda', 'qsv', 'vaapi')
            'preferred_accelerator' => $transcodingConfig['preferred_accelerator'] ?? null,

            // From transcoding.php - HDR tone mapping mode ('none', 'zscale', 'libplacebo')
            'tone_mapping_mode' => $transcodingConfig['tone_mapping_mode'] ?? 'none',

            // From transcoding.php - prefer HDR output over SDR tone mapping
            'prefer_hdr_output' => $transcodingConfig['prefer_hdr_output'] ?? false,

            // From transcoding.php - probe timeout (ensure consistency).
            //
            // NOT AN ADMIN SETTING. `hwaccel.probe_timeout` was removed from
            // server-settings.schema.json in phlix-shared v0.26.0 because this
            // merged value has NO CONSUMER: HwaccelRegistry is built via
            // getInstance() with no config (FfmpegRunner.php:1359) and its
            // initialize() hands HwaccelProbe only a binary path, while the
            // actual probe timeouts are the hardcoded
            // ShellTimeout::FFMPEG_TIMEOUT (10s) / ::GPU_TOOL_TIMEOUT (5s)
            // constants used by the seven VendorProbe classes. The `??` below
            // also never falls through, since transcoding.php always declares
            // the key. Kept only so the merged array shape is unchanged for
            // any caller that reads it defensively — do NOT re-expose it as a
            // setting without first threading a value all the way to
            // ShellTimeout::exec(). See docs/dev/settings-restart-gap.md.
            'probe_timeout' => $transcodingConfig['probe_timeout'] ?? ($hwaccelBase['probe_timeout'] ?? 30),

            // From transcoding.php - test clip path (ensure consistency)
            'test_clip_path' => $transcodingConfig['test_clip_path']
                ?? ($hwaccelBase['test_clip_path'] ?? '/tmp/hwaccel_probe_test.mp4'),

            // From transcoding.php - include software fallback.
            //
            // NOT AN ADMIN SETTING. `transcoding.include_software_fallback` was
            // removed from server-settings.schema.json in phlix-shared v0.27.0
            // for the same reason as `probe_timeout` above: this merged value
            // has NO CONSUMER. The merged array reaches exactly two places, and
            // neither reads this key:
            //   1. FfmpegRunner::setConfig() (Application.php:2971,
            //      TranscodeServicesProvider.php:149) — FfmpegRunner reads only
            //      tone_mapping_mode, prefer_hdr_output, preferred_accelerator,
            //      enabled and prefer_hardware from $this->config, and its
            //      getConfig() accessor has no caller in src/.
            //   2. HwaccelRegistry — whose software-fallback decision
            //      (HwaccelRegistry.php:160,206) reads the SEPARATE
            //      `fallback_to_software` key, sourced from
            //      config/hwaccel_base.php and re-asserted below.
            // So the toggle was inert in both directions. Kept only so the
            // merged array shape is unchanged for any caller that reads it
            // defensively — do NOT re-expose it as a setting. If a
            // software-fallback toggle is wanted, expose
            // `hwaccel.fallback_to_software`, which IS genuinely consumed.
            // See docs/dev/settings-restart-gap.md.
            'include_software_fallback' => $transcodingConfig['include_software_fallback'] ?? true,
        ]);

        // Keep base hwaccel settings not in transcoding
        $merged['vendor_priority'] = $hwaccelBase['vendor_priority'] ?? [];
        $merged['fallback_to_software'] = $hwaccelBase['fallback_to_software'] ?? true;
        $merged['enabled'] = $hwaccelBase['enabled'] ?? true;
        $merged['prefer_hardware'] = $hwaccelBase['prefer_hardware'] ?? true;

        self::$mergedGeneration = $generation;
        self::$mergedConfig = $merged;
        return $merged;
    }

    /**
     * Reset the cached config (useful for testing).
     */
    public static function reset(): void
    {
        self::$mergedConfig = null;
        self::$mergedGeneration = -1;
    }
}
