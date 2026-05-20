<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\ToneMapping;

use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;

/**
 * Main orchestrator for HDR to SDR tone-mapping operations.
 *
 * Coordinates vendor selection, tone mapper invocation, and
 * capability checking for hardware-accelerated tone-mapping.
 *
 * @since 0.11.0
 */
final class HwaccelToneMapper
{
    /** @var HwaccelRegistry Hardware acceleration registry */
    private HwaccelRegistry $registry;

    /** @var ToneMapperFactory Tone mapper factory */
    private ToneMapperFactory $factory;

    /**
     * Creates a new HwaccelToneMapper.
     *
     * @param HwaccelRegistry $registry Hardware acceleration registry
     * @param ToneMapperFactory|null $factory Optional tone mapper factory (creates default if null)
     */
    public function __construct(
        HwaccelRegistry $registry,
        ?ToneMapperFactory $factory = null
    ) {
        $this->registry = $registry;
        $this->factory = $factory ?? new ToneMapperFactory();
    }

    /**
     * Detects HDR metadata from ffprobe probe result.
     *
     * Extracts color_space, color_transfer, color_primaries, and luminance
     * information from ffprobe JSON output.
     *
     * @param array<string, mixed> $probeResult FFprobe JSON result
     *
     * @return HdrMetadata|null HDR metadata or null if not HDR source
     *
     * @since 0.11.0
     */
    public function detectHdrFromProbe(array $probeResult): ?HdrMetadata
    {
        // Find the video stream
        $videoStream = null;
        $streams = $probeResult['streams'] ?? [];
        if (is_array($streams)) {
            foreach ($streams as $stream) {
                if (!is_array($stream)) {
                    continue;
                }
                if (($stream['codec_type'] ?? '') === 'video') {
                    $videoStream = $stream;
                    break;
                }
            }
        }

        if ($videoStream === null) {
            return null;
        }

        $colorSpace = is_string($videoStream['color_space'] ?? null) ? $videoStream['color_space'] : '';
        $colorTransfer = is_string($videoStream['color_transfer'] ?? null) ? $videoStream['color_transfer'] : '';
        $colorPrimaries = is_string($videoStream['color_primaries'] ?? null) ? $videoStream['color_primaries'] : '';

        // Default luminance values if not present
        $maxLuminance = 1000.0;
        $avgLuminance = 200.0;

        // Try to extract luminance from side data or tags
        $tags = $videoStream['tags'] ?? null;
        if (is_array($tags)) {
            $masteringLuminance = $tags['mastering_display_luminance'] ?? null;
            if (is_string($masteringLuminance)) {
                // Format: "min:0.0500 max:1000"
                if (preg_match('/max:(\d+(\.\d+)?)/', $masteringLuminance, $matches)) {
                    $maxLuminance = (float) $matches[1];
                }
            }

            $ambientLuminance = $tags['ambient_luminance'] ?? null;
            if (is_string($ambientLuminance)) {
                // Format: "avg:200"
                if (preg_match('/avg:(\d+(\.\d+)?)/', $ambientLuminance, $matches)) {
                    $avgLuminance = (float) $matches[1];
                }
            }
        }

        // Also check for max_luminance directly (some FFmpeg versions)
        if (isset($videoStream['max_luminance']) && is_numeric($videoStream['max_luminance'])) {
            $maxLuminance = (float) $videoStream['max_luminance'];
        }

        if (isset($videoStream['avg_luminance']) && is_numeric($videoStream['avg_luminance'])) {
            $avgLuminance = (float) $videoStream['avg_luminance'];
        }

        // Create HDR metadata object
        $hdr = new HdrMetadata(
            color_space: $colorSpace ?: 'bt2020nc',
            color_transfer: $colorTransfer ?: 'smpte2084',
            color_primaries: $colorPrimaries ?: 'bt2020',
            max_luminance: $maxLuminance,
            avg_luminance: $avgLuminance,
        );

        // Return null if not actually HDR
        if (!$hdr->isHdr()) {
            return null;
        }

        return $hdr;
    }

    /**
     * Generates the tone-mapping filter chain for the given vendor and HDR source.
     *
     * @param string $vendor Vendor name (e.g., 'nvenc', 'vaapi', 'qsv', 'videotoolbox', 'amf', 'v4l2', 'software')
     * @param HdrMetadata $hdr HDR source metadata
     *
     * @return ToneMapFilterChain Filter chain to apply
     *
     * @since 0.11.0
     */
    public function getFilterChain(string $vendor, HdrMetadata $hdr): ToneMapFilterChain
    {
        $toneMapper = $this->factory->getToneMapper($vendor);

        return $toneMapper->getFilterChain($hdr);
    }

    /**
     * Returns true if the given vendor supports hardware-accelerated tone-mapping.
     *
     * Checks against the hardware registry capability.
     *
     * @param string $vendor Vendor name
     *
     * @return bool True if hardware tone-mapping is supported
     *
     * @since 0.11.0
     */
    public function vendorSupportsHwToneMap(string $vendor): bool
    {
        // VideoToolbox and V4L2 never support HW tone mapping
        if (in_array($vendor, ['videotoolbox', 'v4l2'], true)) {
            return false;
        }

        $capabilities = $this->registry->getAll();

        if (!isset($capabilities[$vendor])) {
            return false;
        }

        return $capabilities[$vendor]->supports_hdr_tone_mapping;
    }

    /**
     * Gets the appropriate tone mapper for a vendor, with fallback to software.
     *
     * If the vendor doesn't support hardware tone-mapping, returns
     * a software tone mapper instead.
     *
     * @param string $vendor Vendor name
     *
     * @return HwaccelToneMapperInterface Tone mapper instance
     *
     * @since 0.11.0
     */
    public function getToneMapperWithFallback(string $vendor): HwaccelToneMapperInterface
    {
        if (!$this->vendorSupportsHwToneMap($vendor)) {
            return $this->factory->getToneMapper('software');
        }

        return $this->factory->getToneMapper($vendor);
    }
}
