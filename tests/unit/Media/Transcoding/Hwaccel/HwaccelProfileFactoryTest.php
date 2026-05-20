<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding\Hwaccel;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelProfileFactory;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;
use Phlix\Media\Transcoding\Hwaccel\Profiles\NvencProfile;
use Phlix\Media\Transcoding\Hwaccel\Profiles\SoftwareProfile;
use Phlix\Media\Transcoding\Hwaccel\Profiles\VaapiProfile;

class HwaccelProfileFactoryTest extends TestCase
{
    private HwaccelRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        HwaccelRegistry::reset();
        $this->registry = HwaccelRegistry::getInstance();
    }

    protected function tearDown(): void
    {
        HwaccelRegistry::reset();
        parent::tearDown();
    }

    public function test_get_profile_returns_software_for_unknown_vendor(): void
    {
        $factory = new HwaccelProfileFactory($this->registry);

        $profile = $factory->getProfile('nonexistent_vendor', 'h264');

        $this->assertInstanceOf(SoftwareProfile::class, $profile);
    }

    public function test_fallback_to_software(): void
    {
        $factory = new HwaccelProfileFactory($this->registry);

        $softwareProfile = $factory->getProfile('software', 'h264');

        $this->assertInstanceOf(SoftwareProfile::class, $softwareProfile);
        $this->assertSame('software', $softwareProfile->getVendor());
    }

    public function test_get_all_profiles(): void
    {
        $factory = new HwaccelProfileFactory($this->registry);

        $profiles = $factory->getAllProfiles();

        $this->assertIsArray($profiles);
        $this->assertContainsOnlyInstancesOf(\Phlix\Media\Transcoding\Hwaccel\Profiles\HwaccelEncoderProfileInterface::class, $profiles);
        $this->assertArrayHasKey('nvenc', $profiles);
        $this->assertArrayHasKey('vaapi', $profiles);
        $this->assertArrayHasKey('software', $profiles);
    }

    public function test_create_command_builder(): void
    {
        $factory = new HwaccelProfileFactory($this->registry);

        $builder = $factory->createCommandBuilder('software', 'h264', 'high');

        $this->assertInstanceOf(\Phlix\Media\Transcoding\Hwaccel\HwaccelCommandBuilder::class, $builder);
        $this->assertSame('high', $builder->getQualityLevel());
    }

    public function test_software_profile_direct(): void
    {
        $factory = new HwaccelProfileFactory($this->registry);

        $profile = $factory->getProfile('software', 'h264');

        $this->assertInstanceOf(SoftwareProfile::class, $profile);
        $this->assertSame('software', $profile->getVendor());
        $this->assertSame('libx264', $profile->getEncoderName('h264'));
    }

    public function test_get_profile_with_fallback_when_vendor_unavailable(): void
    {
        $factory = new HwaccelProfileFactory($this->registry);

        // Since nvenc may not be available in test environment,
        // this should fallback to whatever is available (software)
        $profile = $factory->getProfile('nvenc', 'h264');

        // If nvenc is available, it returns NvencProfile, otherwise software
        $this->assertContains(
            get_class($profile),
            [NvencProfile::class, SoftwareProfile::class]
        );
    }

    public function test_profile_has_correct_vendor_name(): void
    {
        $factory = new HwaccelProfileFactory($this->registry);

        $softwareProfile = $factory->getProfile('software', 'h264');

        $this->assertSame('software', $softwareProfile->getVendor());
    }

    public function test_all_registered_vendors_in_profiles(): void
    {
        $factory = new HwaccelProfileFactory($this->registry);
        $profiles = $factory->getAllProfiles();

        $expectedVendors = ['nvenc', 'vaapi', 'qsv', 'videotoolbox', 'amf', 'v4l2', 'software'];

        foreach ($expectedVendors as $vendor) {
            $this->assertArrayHasKey($vendor, $profiles, "Missing profile for vendor: $vendor");
        }
    }
}
