<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding\Hwaccel\ToneMapping\Vendor;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\HdrMetadata;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\Vendor\QsvToneMapper;

class QsvToneMapperTest extends TestCase
{
    private QsvToneMapper $toneMapper;

    protected function setUp(): void
    {
        $this->toneMapper = new QsvToneMapper();
    }

    public function test_vendor_name(): void
    {
        $this->assertSame('qsv', $this->toneMapper->getVendor());
    }

    public function test_supports_hardware_tone_mapping(): void
    {
        $this->assertTrue($this->toneMapper->supportsHardwareToneMapping());
    }

    public function test_vpp_tone_mapping_args(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $this->toneMapper->getFilterChain($hdr);

        $this->assertStringContainsString('vpp_tonemap', $chain->output_filtergraph);
        $this->assertStringContainsString('mode=1', $chain->output_filtergraph);
    }

    public function test_scale_qsv_in_output(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $this->toneMapper->getFilterChain($hdr);

        $this->assertStringContainsString('scale_qsv', $chain->output_filtergraph);
        $this->assertStringContainsString('format=nv12', $chain->output_filtergraph);
    }

    public function test_qsv_device_in_args(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $this->toneMapper->getFilterChain($hdr);

        $this->assertContains('-hwaccel', $chain->ffmpeg_args);
        $this->assertContains('qsv', $chain->ffmpeg_args);
        $this->assertContains('-qsv_device', $chain->ffmpeg_args);
        $this->assertContains('/dev/dri/renderD128', $chain->ffmpeg_args);
    }

    public function test_zscale_fallback_in_metadata(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $this->toneMapper->getFilterChain($hdr);

        $this->assertStringContainsString('zscale', $chain->metadata_filter);
        $this->assertStringContainsString('transfer=bt709', $chain->metadata_filter);
    }
}
