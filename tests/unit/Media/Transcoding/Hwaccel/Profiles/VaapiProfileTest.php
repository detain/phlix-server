<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Transcoding\Hwaccel\Profiles;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlex\Media\Transcoding\Hwaccel\Profiles\VaapiProfile;

class VaapiProfileTest extends TestCase
{
    private function createCapability(): HwaccelCapability
    {
        return new HwaccelCapability(
            vendor: 'vaapi',
            encoder: 'h264_vaapi',
            decoder: 'h264_vaapi',
            supports_hdr_tone_mapping: false,
            supported_codecs: ['h264', 'hevc'],
            supported_profiles: ['main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 40000000,
            extra_args: ['device' => '/dev/dri/renderD128']
        );
    }

    public function test_vaapi_device_arg(): void
    {
        $profile = new VaapiProfile();
        $capability = $this->createCapability();

        $args = $profile->getInputDeviceArgs($capability);

        $this->assertStringContainsString('-vaapi_device', $args);
        $this->assertStringContainsString('/dev/dri/renderD128', $args);
    }

    public function test_get_encoder_name_h264(): void
    {
        $profile = new VaapiProfile();

        $this->assertSame('h264_vaapi', $profile->getEncoderName('h264'));
    }

    public function test_get_encoder_name_hevc(): void
    {
        $profile = new VaapiProfile();

        $this->assertSame('hevc_vaapi', $profile->getEncoderName('hevc'));
    }

    public function test_get_codec_arg(): void
    {
        $profile = new VaapiProfile();
        $capability = $this->createCapability();

        $args = $profile->getCodecArg($capability, 'h264');

        $this->assertStringContainsString('-c:v h264_vaapi', $args);
        $this->assertStringContainsString('-rc_mode CQP', $args);
    }

    public function test_get_quality_args(): void
    {
        $profile = new VaapiProfile();

        $args = $profile->getQualityArgs('medium', 2500000);

        $this->assertStringContainsString('-rc_mode VBR', $args);
        $this->assertStringContainsString('-maxrate 2500000', $args);
    }

    public function test_get_filter_args(): void
    {
        $profile = new VaapiProfile();

        $args = $profile->getFilterArgs(['deinterlace']);

        $this->assertStringContainsString('format=nv12', $args);
        $this->assertStringContainsString('hwupload', $args);
        $this->assertStringContainsString('deinterlace', $args);
    }

    public function test_get_max_concurrent(): void
    {
        $profile = new VaapiProfile();

        $this->assertSame(4, $profile->getMaxConcurrent());
    }

    public function test_vendor_name(): void
    {
        $profile = new VaapiProfile();

        $this->assertSame('vaapi', $profile->getVendor());
    }

    public function test_custom_device(): void
    {
        $profile = new VaapiProfile();
        $capability = new HwaccelCapability(
            vendor: 'vaapi',
            encoder: 'h264_vaapi',
            decoder: 'h264_vaapi',
            supports_hdr_tone_mapping: false,
            supported_codecs: ['h264'],
            supported_profiles: ['main'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 40000000,
            extra_args: ['device' => '/dev/dri/renderD129']
        );

        $args = $profile->getInputDeviceArgs($capability);

        $this->assertStringContainsString('/dev/dri/renderD129', $args);
    }
}
