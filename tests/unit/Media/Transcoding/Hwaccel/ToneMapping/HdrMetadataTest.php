<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Transcoding\Hwaccel\ToneMapping;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Transcoding\Hwaccel\ToneMapping\HdrMetadata;

class HdrMetadataTest extends TestCase
{
    public function test_is_hdr_pq(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $this->assertTrue($hdr->isHdr());
        $this->assertTrue($hdr->isPq());
        $this->assertFalse($hdr->isHlg());
    }

    public function test_is_hdr_hlg(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'arib-std-b67',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $this->assertTrue($hdr->isHdr());
        $this->assertFalse($hdr->isPq());
        $this->assertTrue($hdr->isHlg());
    }

    public function test_is_hdr_false_for_bt709(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt709',
            color_transfer: 'bt709',
            color_primaries: 'bt709',
            max_luminance: 100.0,
            avg_luminance: 50.0
        );

        $this->assertFalse($hdr->isHdr());
        $this->assertFalse($hdr->isPq());
        $this->assertFalse($hdr->isHlg());
    }

    public function test_max_luminance_accessible(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 4000.0,
            avg_luminance: 500.0
        );

        $this->assertSame(4000.0, $hdr->max_luminance);
        $this->assertSame(500.0, $hdr->avg_luminance);
    }

    public function test_desaturation_for_low_luminance(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 800.0,
            avg_luminance: 200.0
        );

        // Below 1000 nits should have no desaturation
        $this->assertSame(0.0, $hdr->getDesaturation());
    }

    public function test_desaturation_for_high_luminance(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 10000.0,
            avg_luminance: 500.0
        );

        // High luminance should have desaturation
        $this->assertGreaterThan(0.0, $hdr->getDesaturation());
        $this->assertLessThanOrEqual(1.0, $hdr->getDesaturation());
    }

    public function test_default_luminance_values(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020'
        );

        $this->assertSame(1000.0, $hdr->max_luminance);
        $this->assertSame(200.0, $hdr->avg_luminance);
    }

    public function test_color_properties_accessible(): void
    {
        $hdr = new HdrMetadata(
            color_space: 'bt2020nc',
            color_transfer: 'smpte2084',
            color_primaries: 'bt2020',
            max_luminance: 1000.0,
            avg_luminance: 200.0
        );

        $this->assertSame('bt2020nc', $hdr->color_space);
        $this->assertSame('smpte2084', $hdr->color_transfer);
        $this->assertSame('bt2020', $hdr->color_primaries);
    }
}
