<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Transcoding\Hwaccel;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlex\Media\Transcoding\Hwaccel\HwaccelCommandBuilder;
use Phlex\Media\Transcoding\Hwaccel\Profiles\NvencProfile;
use Phlex\Media\Transcoding\Hwaccel\Profiles\VaapiProfile;
use Phlex\Media\Transcoding\Hwaccel\Profiles\SoftwareProfile;

class HwaccelCommandBuilderTest extends TestCase
{
    private function createNvencCapability(): HwaccelCapability
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

    private function createVaapiCapability(): HwaccelCapability
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

    public function test_build_simple_command(): void
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

        $builder = new HwaccelCommandBuilder($profile, $capability, 'medium');
        $cmd = $builder
            ->setInput('/input.mkv')
            ->setOutput('/output.mp4')
            ->build();

        $this->assertStringContainsString('ffmpeg', $cmd);
        $this->assertStringContainsString('/input.mkv', $cmd);
        $this->assertStringContainsString('/output.mp4', $cmd);
        $this->assertStringContainsString('-y', $cmd);
    }

    public function test_add_filter(): void
    {
        $profile = new NvencProfile();
        $capability = $this->createNvencCapability();

        $builder = new HwaccelCommandBuilder($profile, $capability, 'high');
        $cmd = $builder
            ->setInput('/input.mkv')
            ->setOutput('/output.mp4')
            ->addFilter('deinterlace')
            ->build();

        $this->assertStringContainsString('yadif', $cmd);
    }

    public function test_set_quality_level(): void
    {
        $profile = new NvencProfile();
        $capability = $this->createNvencCapability();

        $builder = new HwaccelCommandBuilder($profile, $capability, 'medium');
        $this->assertSame('medium', $builder->getQualityLevel());

        $builder->setQualityLevel('high');
        $this->assertSame('high', $builder->getQualityLevel());
    }

    public function test_build_nvenc_command(): void
    {
        $profile = new NvencProfile();
        $capability = $this->createNvencCapability();

        $builder = new HwaccelCommandBuilder($profile, $capability, 'high');
        $cmd = $builder
            ->setInput('/input.mkv')
            ->setOutput('/output.mp4')
            ->setVideoCodec('h264')
            ->setBitrate(5000000)
            ->setResolution(1920, 1080)
            ->build();

        $this->assertStringContainsString('hwaccel cuda', $cmd);
        $this->assertStringContainsString('h264_nvenc', $cmd);
        $this->assertStringContainsString('-preset p4', $cmd);
        $this->assertStringContainsString('-tune zerolatency', $cmd);
    }

    public function test_build_vaapi_command(): void
    {
        $profile = new VaapiProfile();
        $capability = $this->createVaapiCapability();

        $builder = new HwaccelCommandBuilder($profile, $capability, 'medium');
        $cmd = $builder
            ->setInput('/input.mkv')
            ->setOutput('/output.mp4')
            ->setVideoCodec('h264')
            ->build();

        $this->assertStringContainsString('vaapi_device', $cmd);
        $this->assertStringContainsString('h264_vaapi', $cmd);
    }

    public function test_build_requires_input_path(): void
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

        $builder = new HwaccelCommandBuilder($profile, $capability, 'medium');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Input path must be set');
        $builder->setOutput('/output.mp4')->build();
    }

    public function test_build_requires_output_path(): void
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

        $builder = new HwaccelCommandBuilder($profile, $capability, 'medium');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Output path must be set');
        $builder->setInput('/input.mkv')->build();
    }

    public function test_fluent_interface(): void
    {
        $profile = new NvencProfile();
        $capability = $this->createNvencCapability();

        $builder = new HwaccelCommandBuilder($profile, $capability, 'medium');

        $result = $builder
            ->setInput('/input.mkv')
            ->setOutput('/output.mp4')
            ->setVideoCodec('h264')
            ->setAudioCodec('aac')
            ->setBitrate(5000000)
            ->setResolution(1920, 1080)
            ->setQualityLevel('high')
            ->addFilter('deinterlace')
            ->addExtraArgs(['-movflags', '+faststart']);

        $this->assertSame($builder, $result);
        $this->assertInstanceOf(HwaccelCommandBuilder::class, $result);
    }

    public function test_get_profile_and_capability(): void
    {
        $profile = new NvencProfile();
        $capability = $this->createNvencCapability();

        $builder = new HwaccelCommandBuilder($profile, $capability, 'high');

        $this->assertSame($profile, $builder->getProfile());
        $this->assertSame($capability, $builder->getCapability());
    }
}
