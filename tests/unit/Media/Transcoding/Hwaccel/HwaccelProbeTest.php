<?php

namespace Phlex\Tests\Unit\Media\Transcoding\Hwaccel;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlex\Media\Transcoding\Hwaccel\HwaccelProbe;
use Phlex\Media\Transcoding\Hwaccel\VendorProbe\NvencProbe;
use Phlex\Media\Transcoding\Hwaccel\VendorProbe\VaapiProbe;
use Phlex\Media\Transcoding\Hwaccel\VendorProbe\SoftwareProbe;
use Phlex\Media\Transcoding\Hwaccel\VendorProbe\QsvProbe;
use Phlex\Media\Transcoding\Hwaccel\VendorProbe\VideoToolboxProbe;
use Phlex\Media\Transcoding\Hwaccel\VendorProbe\AmfProbe;
use Phlex\Media\Transcoding\Hwaccel\VendorProbe\V4L2Probe;

class HwaccelProbeTest extends TestCase
{
    public function test_probe_returns_map(): void
    {
        $probe = new HwaccelProbe('/usr/bin/ffmpeg');

        $capabilities = $probe->probe();

        $this->assertIsArray($capabilities);

        foreach ($capabilities as $vendor => $capability) {
            $this->assertIsString($vendor);
            $this->assertInstanceOf(HwaccelCapability::class, $capability);
            $this->assertEquals($vendor, $capability->vendor);
        }
    }

    public function test_is_vendor_available(): void
    {
        $probe = new HwaccelProbe('/usr/bin/ffmpeg');

        $software_available = $probe->isVendorAvailable('software');
        $nvenc_available = $probe->isVendorAvailable('nvenc');
        $invalid_available = $probe->isVendorAvailable('invalid_vendor');

        $this->assertTrue($software_available);
        $this->assertIsBool($nvenc_available);
        $this->assertFalse($invalid_available);
    }

    public function test_probe_vendor_fallback(): void
    {
        $probe = new HwaccelProbe('/usr/bin/ffmpeg');

        $software_capability = $probe->probeVendor('software');

        $this->assertInstanceOf(HwaccelCapability::class, $software_capability);
        $this->assertEquals('software', $software_capability->vendor);
    }

    public function test_probe_vendor_returns_null_for_unknown_vendor(): void
    {
        $probe = new HwaccelProbe('/usr/bin/ffmpeg');

        $result = $probe->probeVendor('nonexistent_vendor');

        $this->assertNull($result);
    }

    public function test_get_available_vendors(): void
    {
        $probe = new HwaccelProbe('/usr/bin/ffmpeg');

        $vendors = $probe->getAvailableVendors();

        $this->assertIsArray($vendors);
        $this->assertContains('software', $vendors);
    }

    public function test_get_vendor_probe(): void
    {
        $probe = new HwaccelProbe('/usr/bin/ffmpeg');

        $software_probe = $probe->getVendorProbe('software');
        $nvenc_probe = $probe->getVendorProbe('nvenc');
        $invalid_probe = $probe->getVendorProbe('invalid');

        $this->assertInstanceOf(SoftwareProbe::class, $software_probe);
        $this->assertInstanceOf(NvencProbe::class, $nvenc_probe);
        $this->assertNull($invalid_probe);
    }

    public function test_clear_cache(): void
    {
        $probe = new HwaccelProbe('/usr/bin/ffmpeg');

        $probe->probe();
        $probe->clearCache();

        $capabilities1 = $probe->probe();
        $capabilities2 = $probe->probe();

        $this->assertEquals($capabilities1, $capabilities2);
    }

    public function test_all_vendor_probes_registered(): void
    {
        $probe = new HwaccelProbe('/usr/bin/ffmpeg');

        $this->assertInstanceOf(NvencProbe::class, $probe->getVendorProbe('nvenc'));
        $this->assertInstanceOf(VaapiProbe::class, $probe->getVendorProbe('vaapi'));
        $this->assertInstanceOf(QsvProbe::class, $probe->getVendorProbe('qsv'));
        $this->assertInstanceOf(VideoToolboxProbe::class, $probe->getVendorProbe('videotoolbox'));
        $this->assertInstanceOf(AmfProbe::class, $probe->getVendorProbe('amf'));
        $this->assertInstanceOf(V4L2Probe::class, $probe->getVendorProbe('v4l2'));
        $this->assertInstanceOf(SoftwareProbe::class, $probe->getVendorProbe('software'));
    }

    public function test_probe_returns_cached_results(): void
    {
        $probe = new HwaccelProbe('/usr/bin/ffmpeg');

        $result1 = $probe->probe();
        $result2 = $probe->probe();

        $this->assertSame($result1, $result2);
    }
}
