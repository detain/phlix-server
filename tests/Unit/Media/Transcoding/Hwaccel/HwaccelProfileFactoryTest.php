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

    /**
     * An UNKNOWN vendor must never resolve to its own (nonexistent) profile: the
     * factory walks the vendor-priority chain and falls back to the BEST AVAILABLE
     * profile for the codec. Which vendor that is is environment-dependent —
     * measured on the S354 box (2026-08-25): with working nvenc present the
     * fallback resolves `NvencProfile` (this is the W11 "Hwaccel env flake": the
     * previous expectation hardcoded `SoftwareProfile`, which is only what a
     * GPU-less CI runner sees). The expectation is therefore DERIVED from the
     * registry the factory consults, so the same assertion holds on GPU boxes and
     * GPU-less runners without weakening the unknown-vendor contract.
     */
    public function test_get_profile_falls_back_to_best_available_for_unknown_vendor(): void
    {
        $factory = new HwaccelProfileFactory($this->registry);

        $profile = $factory->getProfile('nonexistent_vendor', 'h264');

        // The factory's own fallback contract, re-derived from the registry state:
        // walk the priority order, skip software, take the first AVAILABLE vendor
        // that can encode h264 — exactly what getFallbackProfile() does.
        $expected = 'software';
        $h264Encoder = $this->registry->getEncoder('h264');
        foreach ($factory->getAllProfiles() as $vendor => $candidate) {
            if ($vendor === 'software') {
                continue;
            }
            if (
                $this->registry->isVendorAvailable($vendor)
                && $h264Encoder !== null
                && $h264Encoder->vendor === $vendor
            ) {
                $expected = $vendor;
                break;
            }
        }

        $this->assertInstanceOf(
            \Phlix\Media\Transcoding\Hwaccel\Profiles\HwaccelEncoderProfileInterface::class,
            $profile
        );
        $this->assertSame($expected, $profile->getVendor());
        $this->assertNotSame('nonexistent_vendor', $profile->getVendor());
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

        $this->assertContainsOnlyInstancesOf(\Phlix\Media\Transcoding\Hwaccel\Profiles\HwaccelEncoderProfileInterface::class, $profiles);
        $this->assertArrayHasKey('nvenc', $profiles);
        $this->assertArrayHasKey('vaapi', $profiles);
        $this->assertArrayHasKey('software', $profiles);
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
