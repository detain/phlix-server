<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding\Hwaccel;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelProfileFactory;
use Phlix\Media\Transcoding\Hwaccel\Profiles\NvencProfile;
use Phlix\Media\Transcoding\Hwaccel\Profiles\VaapiProfile;
use Phlix\Media\Transcoding\Hwaccel\Profiles\SoftwareProfile;

class HwaccelEncoderProfileTest extends TestCase
{
    public function test_interface_all_methods_defined(): void
    {
        $profile = new NvencProfile();

        $this->assertTrue(method_exists($profile, 'getVendor'));
        $this->assertTrue(method_exists($profile, 'getEncoderName'));
        $this->assertTrue(method_exists($profile, 'getInputDeviceArgs'));
        $this->assertTrue(method_exists($profile, 'getCodecArg'));
        $this->assertTrue(method_exists($profile, 'getQualityArgs'));
        $this->assertTrue(method_exists($profile, 'getFilterArgs'));
        $this->assertTrue(method_exists($profile, 'getMaxConcurrent'));
    }

    public function test_software_profile_encoder_name(): void
    {
        $profile = new SoftwareProfile();

        $this->assertSame('software', $profile->getVendor());
        $this->assertSame('libx264', $profile->getEncoderName('h264'));
        $this->assertSame('libx265', $profile->getEncoderName('hevc'));
    }

    public function test_nvenc_profile_preset_mapping(): void
    {
        $profile = new NvencProfile();

        $highArgs = $profile->getQualityArgs('high', 5000000);
        $this->assertStringContainsString('-preset p4', $highArgs);
        $this->assertStringContainsString('-tune zerolatency', $highArgs);
        $this->assertStringContainsString('-b:v 5000000', $highArgs);
    }

    public function test_vaapi_profile_device_args(): void
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
            max_bitrate: 50000000,
            extra_args: ['device' => '/dev/dri/renderD128']
        );

        $args = $profile->getInputDeviceArgs($capability);

        $this->assertStringContainsString('-vaapi_device', $args);
        $this->assertStringContainsString('/dev/dri/renderD128', $args);
    }

    public function test_nvenc_profile_encoder_name_for_codec(): void
    {
        $profile = new NvencProfile();

        $this->assertSame('h264_nvenc', $profile->getEncoderName('h264'));
        $this->assertSame('hevc_nvenc', $profile->getEncoderName('hevc'));
        $this->assertSame('av1_nvenc', $profile->getEncoderName('av1'));
    }

    public function test_software_profile_empty_input_device_args(): void
    {
        $profile = new SoftwareProfile();
        $capability = new HwaccelCapability(
            vendor: 'software',
            encoder: 'libx264',
            decoder: 'libx264',
            supports_hdr_tone_mapping: false,
            supported_codecs: ['h264'],
            supported_profiles: ['baseline', 'main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 100000000
        );

        $args = $profile->getInputDeviceArgs($capability);

        $this->assertSame('', $args);
    }

    public function test_profile_filter_args(): void
    {
        $profile = new NvencProfile();

        $args = $profile->getFilterArgs(['deinterlace', 'denoise']);

        $this->assertStringContainsString('yadif', $args);
        $this->assertStringContainsString('hqdn3d', $args);
    }

    public function test_profile_max_concurrent(): void
    {
        $nvenc = new NvencProfile();
        $vaapi = new VaapiProfile();
        $software = new SoftwareProfile();

        $this->assertSame(3, $nvenc->getMaxConcurrent());
        $this->assertSame(4, $vaapi->getMaxConcurrent());
        $this->assertSame(0, $software->getMaxConcurrent());
    }
}
