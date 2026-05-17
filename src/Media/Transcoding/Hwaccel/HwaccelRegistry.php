<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel;

/**
 * Singleton registry for hardware acceleration capabilities.
 *
 * Holds the result of the hardware probe and exposes methods to query
 * available encoders and decoders by codec. Uses vendor priority for
 * fallback selection.
 *
 * @since 0.11.0
 */
final class HwaccelRegistry
{
    private static ?HwaccelRegistry $instance = null;

    /** @var array<string, HwaccelCapability> Map of vendor name to capability */
    private array $capabilities = [];

    /** @var array<string, int> Vendor priority (lower = higher priority) */
    private array $vendor_priority = [];

    /** @var bool Whether the registry has been initialized */
    private bool $initialized = false;

    /** @var string Path to FFmpeg binary */
    private string $ffmpeg_path;

    /** @var array<string, mixed> Config options */
    private array $config;

    /**
     * Returns the singleton instance, initializing on first access.
     *
     * @return self
     *
     * @since 0.11.0
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Resets the singleton instance (for testing purposes).
     *
     * @since 0.11.0
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Creates a new HwaccelRegistry.
     *
     * @param string $ffmpeg_path Path to FFmpeg binary
     * @param array<string, mixed> $config Configuration options
     */
    private function __construct(string $ffmpeg_path = '/usr/bin/ffmpeg', array $config = [])
    {
        $this->ffmpeg_path = $ffmpeg_path;
        $this->config = array_merge([
            'enabled' => true,
            'prefer_hardware' => true,
            'vendor_priority' => [
                'nvenc' => 0,
                'vaapi' => 1,
                'qsv' => 2,
                'videotoolbox' => 3,
                'amf' => 4,
                'v4l2' => 5,
                'software' => 100,
            ],
            'probe_timeout' => 30,
            'test_clip_path' => '/tmp/hwaccel_probe_test.mp4',
            'fallback_to_software' => true,
        ], $config);

        /** @var array<string, int> $vendor_priority */
        $vendor_priority = $this->config['vendor_priority'];
        $this->vendor_priority = $vendor_priority;
    }

    /**
     * Initializes the registry by running the hardware probe.
     *
     * @since 0.11.0
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        if (!$this->config['enabled']) {
            $this->initialized = true;
            return;
        }

        $probe = new HwaccelProbe($this->ffmpeg_path);
        $this->capabilities = $probe->probe();

        $this->initialized = true;
    }

    /**
     * Returns the best available encoder for the requested codec.
     *
     * @param string $codec Codec name (e.g., 'h264', 'hevc', 'av1')
     * @param bool $require_hdr_tone_map If true, only return capabilities with HDR tone mapping support
     *
     * @return HwaccelCapability|null Best matching capability or null if none found
     *
     * @since 0.11.0
     */
    public function getEncoder(string $codec, bool $require_hdr_tone_map = false): ?HwaccelCapability
    {
        $this->initialize();

        if ($this->capabilities === []) {
            return null;
        }

        $codec = strtolower($codec);
        $candidates = [];

        foreach ($this->capabilities as $vendor => $capability) {
            if (!$capability->supportsCodec($codec)) {
                continue;
            }

            if ($require_hdr_tone_map && !$capability->supports_hdr_tone_mapping) {
                continue;
            }

            $priority = $this->vendor_priority[$vendor] ?? 999;

            $candidates[$vendor] = [
                'capability' => $capability,
                'priority' => $priority,
            ];
        }

        if ($candidates === []) {
            if ($this->config['fallback_to_software'] && !$require_hdr_tone_map) {
                return $this->capabilities['software'] ?? null;
            }

            return null;
        }

        usort($candidates, fn($a, $b) => $a['priority'] <=> $b['priority']);

        return $candidates[0]['capability'];
    }

    /**
     * Returns the best available decoder for the requested codec.
     *
     * @param string $codec Codec name (e.g., 'h264', 'hevc')
     *
     * @return HwaccelCapability|null Best matching capability or null if none found
     *
     * @since 0.11.0
     */
    public function getDecoder(string $codec): ?HwaccelCapability
    {
        $this->initialize();

        if ($this->capabilities === []) {
            return null;
        }

        $codec = strtolower($codec);
        $candidates = [];

        foreach ($this->capabilities as $vendor => $capability) {
            if (!$capability->supportsCodec($codec)) {
                continue;
            }

            $priority = $this->vendor_priority[$vendor] ?? 999;

            $candidates[$vendor] = [
                'capability' => $capability,
                'priority' => $priority,
            ];
        }

        if ($candidates === []) {
            return $this->config['fallback_to_software']
                ? ($this->capabilities['software'] ?? null)
                : null;
        }

        usort($candidates, fn($a, $b) => $a['priority'] <=> $b['priority']);

        return $candidates[0]['capability'];
    }

    /**
     * Returns all registered capabilities sorted by preference (fastest first).
     *
     * @return array<string, HwaccelCapability>
     *
     * @since 0.11.0
     */
    public function getAll(): array
    {
        $this->initialize();

        return $this->capabilities;
    }

    /**
     * Returns the vendor priority order for fallback.
     *
     * @return array<string, int>
     *
     * @since 0.11.0
     */
    public function getVendorPriority(): array
    {
        $this->initialize();

        return $this->vendor_priority;
    }

    /**
     * Reloads probe results (e.g., after GPU hotplug).
     *
     * @since 0.11.0
     */
    public function reload(): void
    {
        $this->capabilities = [];
        $this->initialized = false;

        $this->initialize();
    }

    /**
     * Checks if a vendor is available.
     *
     * @param string $vendor Vendor name
     *
     * @return bool
     *
     * @since 0.11.0
     */
    public function isVendorAvailable(string $vendor): bool
    {
        $this->initialize();

        return isset($this->capabilities[$vendor]);
    }
}
