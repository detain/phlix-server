<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Config;

use Phlix\Config\HwAccelConfig;
use PHPUnit\Framework\TestCase;

/**
 * SV-0.2 regression: the three hwaccel config sources are reconciled into a
 * single merged source of truth via {@see HwAccelConfig::get()}, and
 * config/ffmpeg.php's deprecated `hwaccel` block delegates to it (no
 * contradictory `enabled` flags at runtime).
 */
final class HwAccelConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HwAccelConfig::reset();
    }

    protected function tearDown(): void
    {
        HwAccelConfig::reset();
        parent::tearDown();
    }

    /**
     * The merged config exposes base settings AND the transcoding-sourced
     * keys in one flat array.
     */
    public function test_get_returns_single_merged_config(): void
    {
        $merged = HwAccelConfig::get();

        // Base settings (from hwaccel_base.php).
        $this->assertArrayHasKey('enabled', $merged);
        $this->assertArrayHasKey('prefer_hardware', $merged);
        $this->assertArrayHasKey('vendor_priority', $merged);
        $this->assertArrayHasKey('fallback_to_software', $merged);

        // Transcoding-sourced settings (from transcoding.php).
        $this->assertArrayHasKey('tone_mapping_mode', $merged);
        $this->assertArrayHasKey('preferred_accelerator', $merged);
        $this->assertArrayHasKey('prefer_hdr_output', $merged);
    }

    /**
     * `enabled` is sourced from hwaccel_base.php (authoritative), NOT the
     * legacy ffmpeg.php literal that used to say false.
     */
    public function test_enabled_sourced_from_hwaccel_base(): void
    {
        $base = require dirname(__DIR__, 3) . '/config/hwaccel_base.php';
        $merged = HwAccelConfig::get();

        $this->assertSame($base['enabled'], $merged['enabled']);
    }

    /**
     * tone_mapping_mode and preferred_accelerator flow from transcoding.php.
     */
    public function test_tone_map_and_preferred_sourced_from_transcoding(): void
    {
        $transcoding = require dirname(__DIR__, 3) . '/config/transcoding.php';
        $merged = HwAccelConfig::get();

        $this->assertSame($transcoding['tone_mapping_mode'], $merged['tone_mapping_mode']);
        $this->assertSame($transcoding['preferred_accelerator'], $merged['preferred_accelerator']);
    }

    /**
     * config/ffmpeg.php's `hwaccel` block delegates to HwAccelConfig::get()
     * verbatim — so there is exactly ONE `enabled` flag at runtime and no
     * contradictory sources.
     */
    public function test_ffmpeg_config_hwaccel_delegates_to_single_source(): void
    {
        $ffmpeg = require dirname(__DIR__, 3) . '/config/ffmpeg.php';

        $this->assertArrayHasKey('hwaccel', $ffmpeg);
        $this->assertIsArray($ffmpeg['hwaccel']);
        $this->assertSame(HwAccelConfig::get(), $ffmpeg['hwaccel']);
        // No contradictory enabled flag: ffmpeg.php's hwaccel.enabled === the
        // authoritative merged value.
        $this->assertSame(HwAccelConfig::get()['enabled'], $ffmpeg['hwaccel']['enabled']);
    }

    /**
     * config/hwaccel.php delegates to the same base as HwAccelConfig, so the
     * historical duplicate source cannot drift from the merged config's base.
     */
    public function test_hwaccel_php_shares_base_with_merged_config(): void
    {
        $hwaccel = require dirname(__DIR__, 3) . '/config/hwaccel.php';
        $merged = HwAccelConfig::get();

        $this->assertSame($merged['enabled'], $hwaccel['enabled']);
        $this->assertSame($merged['prefer_hardware'], $hwaccel['prefer_hardware']);
        $this->assertSame($merged['vendor_priority'], $hwaccel['vendor_priority']);
    }

    /**
     * The merged config is cached after the first call (same array returned).
     */
    public function test_config_is_cached(): void
    {
        $first = HwAccelConfig::get();
        $second = HwAccelConfig::get();

        $this->assertSame($first, $second);
    }
}
