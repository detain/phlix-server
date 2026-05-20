<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding\Hwaccel\Profiles;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\Profiles\NvencProfile;

class NvencProfileTest extends TestCase
{
    private function createCapability(): HwaccelCapability
    {
        return new HwaccelCapability(
            vendor: 'nvenc',
            encoder: 'h264_nvenc',
            decoder: 'h264_cuvid',
            supports_hdr_tone_mapping: true,
            supported_codecs: ['h264', 'hevc', 'av1'],
            supported_profiles: ['baseline', 'main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 50000000,
            extra_args: ['device_index' => 0]
        );
    }

    public function test_preset_p1_is_fastest(): void
    {
        $profile = new NvencProfile();
        $capability = $this->createCapability();

        $args = $profile->getQualityArgs('ultra', 8000000);

        $this->assertStringContainsString('-preset p3', $args);
        $this->assertStringContainsString('-tune zerolatency', $args);
    }

    public function test_bframes_disabled_by_default(): void
    {
        $profile = new NvencProfile();

        $args = $profile->getQualityArgs('high', 5000000);

        $this->assertStringContainsString('-bf 0', $args);
    }

    public function test_get_encoder_name_h264(): void
    {
        $profile = new NvencProfile();

        $this->assertSame('h264_nvenc', $profile->getEncoderName('h264'));
    }

    public function test_get_encoder_name_hevc(): void
    {
        $profile = new NvencProfile();

        $this->assertSame('hevc_nvenc', $profile->getEncoderName('hevc'));
    }

    public function test_get_input_device_args(): void
    {
        $profile = new NvencProfile();
        $capability = $this->createCapability();

        $args = $profile->getInputDeviceArgs($capability);

        $this->assertStringContainsString('-hwaccel cuda', $args);
        $this->assertStringContainsString('-hwaccel_device 0', $args);
    }

    public function test_get_filter_args_deinterlace(): void
    {
        $profile = new NvencProfile();

        $args = $profile->getFilterArgs(['deinterlace']);

        $this->assertStringContainsString('yadif', $args);
    }

    public function test_get_max_concurrent(): void
    {
        $profile = new NvencProfile();

        $this->assertSame(3, $profile->getMaxConcurrent());
    }

    public function test_get_preset_for_quality(): void
    {
        $profile = new NvencProfile();

        $this->assertSame('p3', $profile->getPresetForQuality('ultra'));
        $this->assertSame('p4', $profile->getPresetForQuality('high'));
        $this->assertSame('p5', $profile->getPresetForQuality('medium'));
        $this->assertSame('p6', $profile->getPresetForQuality('low'));
    }

    public function test_vendor_name(): void
    {
        $profile = new NvencProfile();

        $this->assertSame('nvenc', $profile->getVendor());
    }

    public function test_bitrate_and_rate_control(): void
    {
        $profile = new NvencProfile();

        $args = $profile->getQualityArgs('high', 5000000);

        $this->assertStringContainsString('-b:v 5000000', $args);
        $this->assertStringContainsString('-maxrate', $args);
        $this->assertStringContainsString('-bufsize', $args);
    }
}
