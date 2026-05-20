<?php

namespace Phlix\Tests\Unit\Media\Transcoding\Hwaccel;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;

class HwaccelCapabilityTest extends TestCase
{
    public function test_all_fields_accessible(): void
    {
        $capability = new HwaccelCapability(
            vendor: 'nvenc',
            encoder: 'h264_nvenc',
            decoder: 'hevc_cuvid',
            supports_hdr_tone_mapping: true,
            supported_codecs: ['h264', 'hevc', 'av1'],
            supported_profiles: ['baseline', 'main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 50000000,
            extra_args: ['-preset:v', 'p4'],
        );

        $this->assertEquals('nvenc', $capability->vendor);
        $this->assertEquals('h264_nvenc', $capability->encoder);
        $this->assertEquals('hevc_cuvid', $capability->decoder);
        $this->assertTrue($capability->supports_hdr_tone_mapping);
        $this->assertEquals(['h264', 'hevc', 'av1'], $capability->supported_codecs);
        $this->assertEquals(['baseline', 'main', 'high'], $capability->supported_profiles);
        $this->assertEquals(3840, $capability->max_resolution_w);
        $this->assertEquals(2160, $capability->max_resolution_h);
        $this->assertEquals(50000000, $capability->max_bitrate);
        $this->assertEquals(['-preset:v', 'p4'], $capability->extra_args);
    }

    public function test_immutable(): void
    {
        $capability = new HwaccelCapability(
            vendor: 'vaapi',
            encoder: 'hevc_vaapi',
            decoder: 'hevc_vaapi',
            supports_hdr_tone_mapping: false,
            supported_codecs: ['h264', 'hevc'],
            supported_profiles: ['main'],
            max_resolution_w: 1920,
            max_resolution_h: 1080,
            max_bitrate: 20000000,
        );

        $this->assertEquals('vaapi', $capability->vendor);

        $reflection = new \ReflectionClass($capability);
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), "Property {$property->getName()} should be readonly");
        }
    }

    public function test_supports_codec_returns_true_for_supported_codec(): void
    {
        $capability = new HwaccelCapability(
            vendor: 'nvenc',
            encoder: 'h264_nvenc',
            decoder: 'hevc_cuvid',
            supports_hdr_tone_mapping: true,
            supported_codecs: ['h264', 'hevc'],
            supported_profiles: ['main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 50000000,
        );

        $this->assertTrue($capability->supportsCodec('h264'));
        $this->assertTrue($capability->supportsCodec('HEVC'));
        $this->assertFalse($capability->supportsCodec('av1'));
    }

    public function test_supports_profile_returns_true_for_supported_profile(): void
    {
        $capability = new HwaccelCapability(
            vendor: 'nvenc',
            encoder: 'h264_nvenc',
            decoder: 'hevc_cuvid',
            supports_hdr_tone_mapping: true,
            supported_codecs: ['h264'],
            supported_profiles: ['baseline', 'main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 50000000,
        );

        $this->assertTrue($capability->supportsProfile('high'));
        $this->assertTrue($capability->supportsProfile('HIGH'));
        $this->assertFalse($capability->supportsProfile('pro'));
    }

    public function test_supports_resolution_returns_true_when_within_limits(): void
    {
        $capability = new HwaccelCapability(
            vendor: 'nvenc',
            encoder: 'h264_nvenc',
            decoder: 'hevc_cuvid',
            supports_hdr_tone_mapping: true,
            supported_codecs: ['h264'],
            supported_profiles: ['high'],
            max_resolution_w: 1920,
            max_resolution_h: 1080,
            max_bitrate: 20000000,
        );

        $this->assertTrue($capability->supportsResolution(1920, 1080));
        $this->assertTrue($capability->supportsResolution(1280, 720));
        $this->assertFalse($capability->supportsResolution(3840, 2160));
        $this->assertFalse($capability->supportsResolution(1921, 1080));
    }
}
