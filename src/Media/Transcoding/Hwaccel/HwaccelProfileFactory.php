<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel;

use Phlix\Media\Transcoding\Hwaccel\Profiles\AmfProfile;
use Phlix\Media\Transcoding\Hwaccel\Profiles\HwaccelEncoderProfileInterface;
use Phlix\Media\Transcoding\Hwaccel\Profiles\NvencProfile;
use Phlix\Media\Transcoding\Hwaccel\Profiles\QsvProfile;
use Phlix\Media\Transcoding\Hwaccel\Profiles\SoftwareProfile;
use Phlix\Media\Transcoding\Hwaccel\Profiles\V4L2Profile;
use Phlix\Media\Transcoding\Hwaccel\Profiles\VaapiProfile;
use Phlix\Media\Transcoding\Hwaccel\Profiles\VideoToolboxProfile;

/**
 * Factory for creating hardware encoder profile instances.
 *
 * Resolves the best profile for a vendor+codec combination, with fallback
 * to software encoding if the requested vendor is not available.
 *
 * @since 0.11.0
 */
final class HwaccelProfileFactory
{
    /** @var array<string, HwaccelEncoderProfileInterface> Registered profiles */
    private array $profiles = [];

    /** @var HwaccelRegistry Hardware acceleration registry */
    private HwaccelRegistry $registry;

    /**
     * Creates a new HwaccelProfileFactory.
     *
     * @param HwaccelRegistry $registry Hardware acceleration registry
     */
    public function __construct(HwaccelRegistry $registry)
    {
        $this->registry = $registry;
        $this->registerDefaultProfiles();
    }

    /**
     * Registers the default per-vendor profiles.
     */
    private function registerDefaultProfiles(): void
    {
        $this->profiles = [
            'nvenc' => new NvencProfile(),
            'vaapi' => new VaapiProfile(),
            'qsv' => new QsvProfile(),
            'videotoolbox' => new VideoToolboxProfile(),
            'amf' => new AmfProfile(),
            'v4l2' => new V4L2Profile(),
            'software' => new SoftwareProfile(),
        ];
    }

    /**
     * Returns the best profile for the requested vendor + codec combination.
     *
     * If the requested vendor is not available, it falls back to the next
     * vendor in priority order until it finds one that supports the requested codec.
     *
     * @param string $vendor Vendor name (e.g., 'nvenc', 'vaapi', 'software')
     * @param string $codec Codec name (e.g., 'h264', 'hevc')
     *
     * @return HwaccelEncoderProfileInterface Best matching profile
     *
     * @since 0.11.0
     */
    public function getProfile(string $vendor, string $codec): HwaccelEncoderProfileInterface
    {
        $vendor = strtolower($vendor);

        if ($vendor === 'software') {
            return $this->profiles['software'];
        }

        if ($this->registry->isVendorAvailable($vendor)) {
            if (isset($this->profiles[$vendor])) {
                $capability = $this->registry->getEncoder($codec);
                if ($capability !== null && $capability->vendor === $vendor) {
                    return $this->profiles[$vendor];
                }
            }
        }

        return $this->getFallbackProfile($codec);
    }

    /**
     * Returns all registered profiles sorted by vendor priority.
     *
     * @return array<string, HwaccelEncoderProfileInterface>
     *
     * @since 0.11.0
     */
    public function getAllProfiles(): array
    {
        $priorities = $this->registry->getVendorPriority();

        $sortCallback = static function (
            HwaccelEncoderProfileInterface $a,
            HwaccelEncoderProfileInterface $b
        ) use ($priorities): int {
            $priorityA = $priorities[$a->getVendor()] ?? 999;
            $priorityB = $priorities[$b->getVendor()] ?? 999;

            return $priorityA <=> $priorityB;
        };

        uasort($this->profiles, $sortCallback);

        return $this->profiles;
    }

    /**
     * Creates a command builder for the given job parameters.
     *
     * @param string $vendor Vendor name
     * @param string $codec Codec name
     * @param string $quality Quality level (e.g., 'ultra', 'high', 'medium', 'low')
     *
     * @return HwaccelCommandBuilder Command builder instance
     *
     * @since 0.11.0
     */
    public function createCommandBuilder(string $vendor, string $codec, string $quality): HwaccelCommandBuilder
    {
        $profile = $this->getProfile($vendor, $codec);
        $capability = $this->registry->getEncoder($codec);

        if ($capability === null) {
            $capability = new HwaccelCapability(
                vendor: 'software',
                encoder: 'libx264',
                decoder: 'libx264',
                supports_hdr_tone_mapping: false,
                supported_codecs: ['h264', 'hevc'],
                supported_profiles: ['baseline', 'main', 'high'],
                max_resolution_w: 3840,
                max_resolution_h: 2160,
                max_bitrate: 100000000,
            );
        }

        return new HwaccelCommandBuilder($profile, $capability, $quality);
    }

    /**
     * Gets the fallback profile for a codec.
     *
     * @param string $codec Codec name
     *
     * @return HwaccelEncoderProfileInterface Fallback profile
     */
    private function getFallbackProfile(string $codec): HwaccelEncoderProfileInterface
    {
        $vendor_priority = $this->registry->getVendorPriority();

        asort($vendor_priority);

        foreach (array_keys($vendor_priority) as $vendor) {
            if ($vendor === 'software') {
                continue;
            }

            if ($this->registry->isVendorAvailable($vendor)) {
                $capability = $this->registry->getEncoder($codec);
                if ($capability !== null && isset($this->profiles[$vendor])) {
                    return $this->profiles[$vendor];
                }
            }
        }

        return $this->profiles['software'];
    }
}
