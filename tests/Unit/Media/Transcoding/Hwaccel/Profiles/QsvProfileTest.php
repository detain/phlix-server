<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding\Hwaccel\Profiles;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\Profiles\QsvProfile;

class QsvProfileTest extends TestCase
{
    private function createCapability(): HwaccelCapability
    {
        return new HwaccelCapability(
            vendor: 'qsv',
            encoder: 'h264_qsv',
            decoder: 'h264_qsv',
            supports_hdr_tone_mapping: false,
            supported_codecs: ['h264', 'hevc', 'av1'],
            supported_profiles: ['baseline', 'main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 30000000,
            extra_args: ['device' => '/dev/dri/renderD128']
        );
    }

    public function test_qsv_device_arg(): void
    {
        $profile = new QsvProfile();
        $capability = $this->createCapability();

        $args = $profile->getInputDeviceArgs($capability);

        $this->assertStringContainsString('-qsv_device', $args);
        $this->assertStringContainsString('/dev/dri/renderD128', $args);
    }

    public function test_get_encoder_name_h264(): void
    {
        $profile = new QsvProfile();

        $this->assertSame('h264_qsv', $profile->getEncoderName('h264'));
    }

    public function test_get_encoder_name_hevc(): void
    {
        $profile = new QsvProfile();

        $this->assertSame('hevc_qsv', $profile->getEncoderName('hevc'));
    }

    public function test_get_encoder_name_av1(): void
    {
        $profile = new QsvProfile();

        $this->assertSame('av1_qsv', $profile->getEncoderName('av1'));
    }

    public function test_get_quality_args(): void
    {
        $profile = new QsvProfile();

        $args = $profile->getQualityArgs('high', 5000000);

        $this->assertStringContainsString('-preset faster', $args);
        $this->assertStringContainsString('-bf 0', $args);
    }

    public function test_get_filter_args(): void
    {
        $profile = new QsvProfile();

        $args = $profile->getFilterArgs(['deinterlace', 'denoise']);

        $this->assertStringContainsString('deinterlace', $args);
        $this->assertStringContainsString('hqdn3d', $args);
    }

    public function test_get_max_concurrent(): void
    {
        $profile = new QsvProfile();

        $this->assertSame(6, $profile->getMaxConcurrent());
    }

    public function test_vendor_name(): void
    {
        $profile = new QsvProfile();

        $this->assertSame('qsv', $profile->getVendor());
    }

    public function test_bitrate_args(): void
    {
        $profile = new QsvProfile();

        $args = $profile->getQualityArgs('high', 5000000);

        $this->assertStringContainsString('-b:v 5000000', $args);
        $this->assertStringContainsString('-maxrate', $args);
    }
}
