<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel;

use Phlex\Media\Transcoding\Hwaccel\VendorProbe\AmfProbe;
use Phlex\Media\Transcoding\Hwaccel\VendorProbe\NvencProbe;
use Phlex\Media\Transcoding\Hwaccel\VendorProbe\QsvProbe;
use Phlex\Media\Transcoding\Hwaccel\VendorProbe\SoftwareProbe;
use Phlex\Media\Transcoding\Hwaccel\VendorProbe\V4L2Probe;
use Phlex\Media\Transcoding\Hwaccel\VendorProbe\VaapiProbe;
use Phlex\Media\Transcoding\Hwaccel\VendorProbe\VideoToolboxProbe;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Hardware acceleration probe that detects available GPU encoders.
 *
 * Runs a suite of vendor-specific probes to detect available hardware
 * acceleration support at startup. Results are cached and used by
 * HwaccelRegistry for codec decision making.
 *
 * @since 0.11.0
 */
class HwaccelProbe
{
    /** @var array<string, VendorProbeInterface> */
    private array $vendor_probes;

    private string $ffmpeg_path;

    private LoggerInterface $logger;

    /** @var array<string, HwaccelCapability>|null */
    private ?array $cached_capabilities = null;

    /**
     * Creates a new HwaccelProbe instance.
     *
     * @param string $ffmpeg_path Path to the FFmpeg binary
     * @param LoggerInterface|null $logger Optional PSR logger for debug output
     */
    public function __construct(string $ffmpeg_path, ?LoggerInterface $logger = null)
    {
        $this->ffmpeg_path = $ffmpeg_path;
        $this->logger = $logger ?? new NullLogger();

        $this->vendor_probes = [
            'nvenc' => new NvencProbe(),
            'vaapi' => new VaapiProbe(),
            'qsv' => new QsvProbe(),
            'videotoolbox' => new VideoToolboxProbe(),
            'amf' => new AmfProbe(),
            'v4l2' => new V4L2Probe(),
            'software' => new SoftwareProbe(),
        ];
    }

    /**
     * Runs the full probe suite and returns capabilities keyed by vendor name.
     *
     * @return array<string, HwaccelCapability> Map of vendor name to capability
     *
     * @since 0.11.0
     */
    public function probe(): array
    {
        if ($this->cached_capabilities !== null) {
            return $this->cached_capabilities;
        }

        $capabilities = [];

        foreach ($this->vendor_probes as $vendor => $probe) {
            $this->logger->debug('Probing vendor', ['vendor' => $vendor]);

            try {
                $capability = $probe->probe($this->ffmpeg_path, $this->logger);

                if ($capability !== null) {
                    $capabilities[$vendor] = $capability;
                    $this->logger->info('Vendor available', [
                        'vendor' => $vendor,
                        'encoder' => $capability->encoder,
                        'codecs' => $capability->supported_codecs,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Vendor probe failed', [
                    'vendor' => $vendor,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->cached_capabilities = $capabilities;

        return $capabilities;
    }

    /**
     * Quick check: is a specific vendor available?
     *
     * @param string $vendor Vendor name to check (e.g., 'nvenc', 'vaapi', 'qsv')
     *
     * @return bool True if the vendor is available
     *
     * @since 0.11.0
     */
    public function isVendorAvailable(string $vendor): bool
    {
        $capabilities = $this->probe();

        return isset($capabilities[$vendor]);
    }

    /**
     * Run vendor-specific acceptance test (encode a 1-second test clip).
     *
     * @param string $vendor Vendor name to probe
     * @param string $test_clip_path Path to a test clip for encoding
     *
     * @return HwaccelCapability|null Capability if the vendor is available and passes the test, null otherwise
     *
     * @since 0.11.0
     */
    public function probeVendor(string $vendor, string $test_clip_path = ''): ?HwaccelCapability
    {
        if (!isset($this->vendor_probes[$vendor])) {
            $this->logger->warning('Unknown vendor', ['vendor' => $vendor]);
            return null;
        }

        $probe = $this->vendor_probes[$vendor];

        if (!$probe->isAvailable()) {
            $this->logger->debug('Vendor not available', ['vendor' => $vendor]);
            return null;
        }

        if ($test_clip_path !== '' && file_exists($test_clip_path)) {
            if (!$probe->runAcceptanceTest($this->ffmpeg_path, $test_clip_path, $this->logger)) {
                $this->logger->warning('Vendor acceptance test failed', ['vendor' => $vendor]);
                return null;
            }
        }

        return $probe->probe($this->ffmpeg_path, $this->logger);
    }

    /**
     * Gets all available vendor names.
     *
     * @return array<string>
     *
     * @since 0.11.0
     */
    public function getAvailableVendors(): array
    {
        $capabilities = $this->probe();

        return array_keys($capabilities);
    }

    /**
     * Gets a specific vendor probe by name.
     *
     * @param string $vendor Vendor name
     *
     * @return VendorProbeInterface|null
     *
     * @since 0.11.0
     */
    public function getVendorProbe(string $vendor): ?VendorProbeInterface
    {
        return $this->vendor_probes[$vendor] ?? null;
    }

    /**
     * Clears the cached probe results to force a re-probe.
     *
     * @since 0.11.0
     */
    public function clearCache(): void
    {
        $this->cached_capabilities = null;
    }
}
