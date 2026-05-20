<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding\Hwaccel\ToneMapping\Vendor;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\HdrMetadata;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\Vendor\VaapiToneMapper;

class VaapiToneMapperTest extends TestCase
{
    private VaapiToneMapper $toneMapper;

    protected function setUp(): void
    {
        $this->toneMapper = new VaapiToneMapper();
    }

    public function test_vendor_name(): void
    {
        $this->assertSame('vaapi', $this->toneMapper->getVendor());
    }

    public function test_supports_hardware_tone_mapping(): void
    {
        $this->assertTrue($this->toneMapper->supportsHardwareToneMapping());
    }

    public function test_vaapi_tonemap_args(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $this->toneMapper->getFilterChain($hdr);

        $this->assertStringContainsString('tonemap_vaapi', $chain->metadata_filter);
        $this->assertStringContainsString('hwupload', $chain->input_filtergraph);
        $this->assertContains('-hwaccel', $chain->ffmpeg_args);
        $this->assertContains('vaapi', $chain->ffmpeg_args);
    }

    public function test_scale_vaapi_output(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $this->toneMapper->getFilterChain($hdr);

        $this->assertStringContainsString('scale_vaapi', $chain->output_filtergraph);
    }

    public function test_hwaccel_device_in_args(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $chain = $this->toneMapper->getFilterChain($hdr);

        $this->assertContains('-hwaccel_device', $chain->ffmpeg_args);
        $this->assertContains('/dev/dri/renderD128', $chain->ffmpeg_args);
    }
}
