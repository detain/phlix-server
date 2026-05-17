<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Transcoding\Hwaccel\ToneMapping;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlex\Media\Transcoding\Hwaccel\HwaccelRegistry;
use Phlex\Media\Transcoding\Hwaccel\ToneMapping\HdrMetadata;
use Phlex\Media\Transcoding\Hwaccel\ToneMapping\HwaccelToneMapper;
use Phlex\Media\Transcoding\Hwaccel\ToneMapping\ToneMapperFactory;

class HwaccelToneMapperTest extends TestCase
{
    public function test_detect_hdr_from_probe(): void
    {
        // HwaccelRegistry is final, so we test via the factory directly
        $factory = new ToneMapperFactory();

        $probeResult = [
            'streams' => [
                [
                    'codec_type' => 'video',
                    'color_space' => 'bt2020nc',
                    'color_transfer' => 'smpte2084',
                    'color_primaries' => 'bt2020',
                    'tags' => [
                        'mastering_display_luminance' => 'min:0.0500 max:1000',
                        'ambient_luminance' => 'avg:200',
                    ],
                ],
            ],
        ];

        // Use the tone mapper directly with the factory
        $toneMapper = new HwaccelToneMapper(HwaccelRegistry::getInstance(), $factory);

        $hdr = $toneMapper->detectHdrFromProbe($probeResult);

        $this->assertInstanceOf(HdrMetadata::class, $hdr);
        $this->assertTrue($hdr->isHdr());
        $this->assertSame('bt2020nc', $hdr->color_space);
        $this->assertSame('smpte2084', $hdr->color_transfer);
        $this->assertSame('bt2020', $hdr->color_primaries);
    }

    public function test_detect_hdr_from_probe_returns_null_for_sdr(): void
    {
        $factory = new ToneMapperFactory();
        $toneMapper = new HwaccelToneMapper(HwaccelRegistry::getInstance(), $factory);

        $probeResult = [
            'streams' => [
                [
                    'codec_type' => 'video',
                    'color_space' => 'bt709',
                    'color_transfer' => 'bt709',
                    'color_primaries' => 'bt709',
                ],
            ],
        ];

        $hdr = $toneMapper->detectHdrFromProbe($probeResult);

        $this->assertNull($hdr);
    }

    public function test_get_filter_chain_nvenc(): void
    {
        $factory = new ToneMapperFactory();
        $toneMapper = new HwaccelToneMapper(HwaccelRegistry::getInstance(), $factory);

        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $toneMapper->getFilterChain('nvenc', $hdr);

        $this->assertFalse($chain->isEmpty());
        $this->assertStringContainsString('tonemap_cuda', $chain->output_filtergraph);
        $this->assertStringContainsString('hwupload', $chain->input_filtergraph);
    }

    public function test_get_filter_chain_vaapi(): void
    {
        $factory = new ToneMapperFactory();
        $toneMapper = new HwaccelToneMapper(HwaccelRegistry::getInstance(), $factory);

        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $toneMapper->getFilterChain('vaapi', $hdr);

        $this->assertFalse($chain->isEmpty());
        $this->assertStringContainsString('tonemap_vaapi', $chain->metadata_filter);
    }

    public function test_get_filter_chain_videotoolbox(): void
    {
        $factory = new ToneMapperFactory();
        $toneMapper = new HwaccelToneMapper(HwaccelRegistry::getInstance(), $factory);

        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $toneMapper->getFilterChain('videotoolbox', $hdr);

        // VideoToolbox falls back to software zscale
        $this->assertFalse($chain->isEmpty());
        $this->assertStringContainsString('zscale', $chain->metadata_filter);
    }

    public function test_vendor_supports_hw_tonemap_videotoolbox_is_false(): void
    {
        $factory = new ToneMapperFactory();
        $toneMapper = new HwaccelToneMapper(HwaccelRegistry::getInstance(), $factory);

        // VideoToolbox always returns false per design
        $this->assertFalse($toneMapper->vendorSupportsHwToneMap('videotoolbox'));
    }

    public function test_vendor_supports_hw_tonemap_v4l2_is_false(): void
    {
        $factory = new ToneMapperFactory();
        $toneMapper = new HwaccelToneMapper(HwaccelRegistry::getInstance(), $factory);

        // V4L2 always returns false per design
        $this->assertFalse($toneMapper->vendorSupportsHwToneMap('v4l2'));
    }

    public function test_get_filter_chain_qsv(): void
    {
        $factory = new ToneMapperFactory();
        $toneMapper = new HwaccelToneMapper(HwaccelRegistry::getInstance(), $factory);

        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $toneMapper->getFilterChain('qsv', $hdr);

        $this->assertFalse($chain->isEmpty());
        $this->assertStringContainsString('vpp_tonemap', $chain->output_filtergraph);
    }

    public function test_get_filter_chain_software(): void
    {
        $factory = new ToneMapperFactory();
        $toneMapper = new HwaccelToneMapper(HwaccelRegistry::getInstance(), $factory);

        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $toneMapper->getFilterChain('software', $hdr);

        $this->assertFalse($chain->isEmpty());
        $this->assertStringContainsString('zscale', $chain->metadata_filter);
        $this->assertStringContainsString('transfer=bt709', $chain->metadata_filter);
    }

    public function test_get_tone_mapper_with_fallback_returns_software_for_unsupported_vendor(): void
    {
        $factory = new ToneMapperFactory();
        $toneMapper = new HwaccelToneMapper(HwaccelRegistry::getInstance(), $factory);

        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        // Since 'unknown_vendor' doesn't support HW tonemap, should fall back to software
        $toneMapperWithFallback = $toneMapper->getToneMapperWithFallback('unknown_vendor');
        $chain = $toneMapperWithFallback->getFilterChain($hdr);

        $this->assertFalse($chain->isEmpty());
        $this->assertStringContainsString('zscale', $chain->metadata_filter);
    }

    public function test_get_tone_mapper_returns_correct_vendor_tone_mapper(): void
    {
        $factory = new ToneMapperFactory();

        $nvencMapper = $factory->getToneMapper('nvenc');
        $this->assertSame('nvenc', $nvencMapper->getVendor());

        $vaapiMapper = $factory->getToneMapper('vaapi');
        $this->assertSame('vaapi', $vaapiMapper->getVendor());

        $qsvMapper = $factory->getToneMapper('qsv');
        $this->assertSame('qsv', $qsvMapper->getVendor());

        $softwareMapper = $factory->getToneMapper('software');
        $this->assertSame('software', $softwareMapper->getVendor());
    }
}
