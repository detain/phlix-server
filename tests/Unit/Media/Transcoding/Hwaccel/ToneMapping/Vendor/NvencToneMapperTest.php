<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding\Hwaccel\ToneMapping\Vendor;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\HdrMetadata;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\Vendor\NvencToneMapper;

class NvencToneMapperTest extends TestCase
{
    private NvencToneMapper $toneMapper;

    protected function setUp(): void
    {
        $this->toneMapper = new NvencToneMapper();
    }

    public function test_vendor_name(): void
    {
        $this->assertSame('nvenc', $this->toneMapper->getVendor());
    }

    public function test_supports_hardware_tone_mapping(): void
    {
        $this->assertTrue($this->toneMapper->supportsHardwareToneMapping());
    }

    public function test_scale_cuda_filter(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $this->toneMapper->getFilterChain($hdr);

        $this->assertStringContainsString('scale_cuda', $chain->output_filtergraph);
        $this->assertStringContainsString('hwupload', $chain->input_filtergraph);
        $this->assertStringContainsString('tonemap_cuda', $chain->output_filtergraph);
    }

    public function test_zscale_fallback(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $this->toneMapper->getFilterChain($hdr);

        // zscale should be present as fallback
        $this->assertStringContainsString('zscale', $chain->metadata_filter);
        $this->assertStringContainsString('transfer=bt709', $chain->metadata_filter);
    }

    public function test_tonemap_cuda_parameters(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 4000.0,
            avg_luminance: 500.0
        );

        $chain = $this->toneMapper->getFilterChain($hdr);

        // Should have hable tone mapping and transfer parameters
        $this->assertStringContainsString('tonemap=hable', $chain->output_filtergraph);
        $this->assertStringContainsString('transfer=smpte2084', $chain->output_filtergraph);
        $this->assertStringContainsString('primaries=bt2020', $chain->output_filtergraph);
    }

    public function test_desaturation_parameter(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 5000.0,
            avg_luminance: 500.0
        );

        $chain = $this->toneMapper->getFilterChain($hdr);

        // Should have desaturation parameter
        $this->assertStringContainsString('desat=', $chain->output_filtergraph);
    }

    public function test_extra_hw_frames_in_args(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $this->toneMapper->getFilterChain($hdr);

        $this->assertContains('-extra_hw_frames', $chain->ffmpeg_args);
        $this->assertContains('3', $chain->ffmpeg_args);
    }
}
