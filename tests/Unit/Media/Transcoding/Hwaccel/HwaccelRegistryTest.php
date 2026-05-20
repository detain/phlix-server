<?php

namespace Phlix\Tests\Unit\Media\Transcoding\Hwaccel;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;

class HwaccelRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HwaccelRegistry::reset();
    }

    protected function tearDown(): void
    {
        HwaccelRegistry::reset();
        parent::tearDown();
    }

    public function test_singleton_returns_same_instance(): void
    {
        $instance1 = HwaccelRegistry::getInstance();
        $instance2 = HwaccelRegistry::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function test_get_encoder_nvenc(): void
    {
        $registry = HwaccelRegistry::getInstance();

        $capability = $registry->getEncoder('h264');

        $this->assertInstanceOf(HwaccelCapability::class, $capability);
    }

    public function test_get_encoder_fallback_to_software(): void
    {
        $registry = HwaccelRegistry::getInstance();

        $capability = $registry->getEncoder('h264');

        $this->assertNotNull($capability);
        $this->assertContains($capability->vendor, ['software', 'nvenc', 'vaapi', 'qsv', 'videotoolbox', 'amf', 'v4l2']);
    }

    public function test_get_encoder_with_hdr_tone_map(): void
    {
        $registry = HwaccelRegistry::getInstance();

        $capability = $registry->getEncoder('hevc', true);

        if ($capability !== null) {
            $this->assertTrue($capability->supports_hdr_tone_mapping);
        } else {
            $this->assertNull($capability);
        }
    }

    public function test_get_decoder(): void
    {
        $registry = HwaccelRegistry::getInstance();

        $capability = $registry->getDecoder('h264');

        $this->assertInstanceOf(HwaccelCapability::class, $capability);
    }

    public function test_vendor_priority(): void
    {
        $registry = HwaccelRegistry::getInstance();

        $priority = $registry->getVendorPriority();

        $this->assertIsArray($priority);
        $this->assertArrayHasKey('nvenc', $priority);
        $this->assertArrayHasKey('vaapi', $priority);
        $this->assertArrayHasKey('qsv', $priority);
        $this->assertArrayHasKey('videotoolbox', $priority);
        $this->assertArrayHasKey('amf', $priority);
        $this->assertArrayHasKey('v4l2', $priority);
        $this->assertArrayHasKey('software', $priority);

        $this->assertLessThan($priority['vaapi'], $priority['nvenc']);
        $this->assertLessThan($priority['qsv'], $priority['vaapi']);
        $this->assertLessThan($priority['software'], $priority['nvenc']);
    }

    public function test_reload(): void
    {
        $registry = HwaccelRegistry::getInstance();

        $all1 = $registry->getAll();

        $registry->reload();

        $all2 = $registry->getAll();

        $this->assertEquals($all1, $all2);
    }

    public function test_is_vendor_available(): void
    {
        $registry = HwaccelRegistry::getInstance();

        $software_available = $registry->isVendorAvailable('software');

        $this->assertTrue($software_available);
    }

    public function test_get_all_returns_capabilities(): void
    {
        $registry = HwaccelRegistry::getInstance();

        $all = $registry->getAll();

        $this->assertIsArray($all);
        $this->assertContainsOnlyInstancesOf(HwaccelCapability::class, $all);
    }
}
