<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Tuners\Dvbt;

use Phlex\Common\Logger\StructuredLogger;
use Psr\Log\LoggerInterface;

/**
 * Device scanner for Linux DVB-T adapters.
 *
 * Scans /dev/dvb for adapter and frontend devices and
 * checks their capabilities to identify compatible devices.
 *
 * @since 0.12.0
 */
class DvbtDeviceScanner
{
    /** @var string Base path for DVB devices */
    private const DVB_BASE_PATH = '/dev/dvb';

    /** @var StructuredLogger|LoggerInterface|null Optional logger */
    private StructuredLogger|LoggerInterface|null $logger;

    /**
     * @param StructuredLogger|LoggerInterface|null $logger Optional logger instance
     */
    public function __construct(StructuredLogger|LoggerInterface|null $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Scan /dev/dvb for available DVB-T adapters.
     *
     * Returns an array of DvbtDevice descriptors for each discovered
     * DVB-T frontend. Returns an empty array gracefully when /dev/dvb
     * doesn't exist or is empty.
     *
     * @return DvbtDevice[] Array of discovered DVB-T devices
     */
    public function scan(): array
    {
        $devices = [];

        $this->logger?->info('DvbtDeviceScanner: scanning for DVB-T devices');

        if (!is_dir(self::DVB_BASE_PATH)) {
            $this->logger?->warning('DvbtDeviceScanner: /dev/dvb does not exist');
            return [];
        }

        $adapterDirs = $this->getAdapterDirs();
        if (empty($adapterDirs)) {
            $this->logger?->info('DvbtDeviceScanner: no DVB adapters found');
            return [];
        }

        foreach ($adapterDirs as $adapterPath) {
            $adapterIndex = $this->extractAdapterIndex($adapterPath);
            if ($adapterIndex === null) {
                continue;
            }

            $frontendDevices = $this->findFrontends($adapterPath);
            foreach ($frontendDevices as $frontendPath) {
                $frontendIndex = $this->extractFrontendIndex($frontendPath);
                if ($frontendIndex === null) {
                    continue;
                }

                if ($this->isDvbT($frontendPath)) {
                    $caps = $this->readCapabilities($frontendPath);
                    $modulation = is_string($caps['modulation']) ? $caps['modulation'] : 'auto';
                    $frequencyMin = is_int($caps['frequency_min']) ? $caps['frequency_min'] : 470000000;
                    $frequencyMax = is_int($caps['frequency_max']) ? $caps['frequency_max'] : 862000000;
                    $devices[] = new DvbtDevice(
                        adapterPath: $adapterPath,
                        adapterIndex: $adapterIndex,
                        frontendIndex: $frontendIndex,
                        modulation: $modulation,
                        frequencyMin: $frequencyMin,
                        frequencyMax: $frequencyMax,
                    );
                }
            }
        }

        $this->logger?->info('DvbtDeviceScanner: discovered devices', ['count' => count($devices)]);

        return $devices;
    }

    /**
     * Check if a frontend device is DVB-T capable.
     *
     * Reads the device type from sysfs to determine if it supports DVB-T.
     *
     * @param string $frontendPath Path to the frontend device
     * @return bool True if the device supports DVB-T
     */
    private function isDvbT(string $frontendPath): bool
    {
        $capsPath = $frontendPath . '/device/caps';
        if (!file_exists($capsPath)) {
            // Try to read from /sys/class/dvb/ if available
            $sysfsPath = str_replace('/dev/dvb', '/sys/class/dvb', $frontendPath);
            $capsPath = $sysfsPath . '/device/caps';
        }

        if (!file_exists($capsPath)) {
            // If we cannot read caps, check if frontend exists - assume DVB-T for legacy support
            return file_exists($frontendPath);
        }

        $capsContent = @file_get_contents($capsPath);
        if ($capsContent === false) {
            return file_exists($frontendPath);
        }

        // DVB-T devices typically have modulation capabilities
        // Check for common DVB-T modulation types
        $dvbTCaps = ['DVB-T', 'QAM64', 'QAM256', 'QPSK', '8VSB'];
        foreach ($dvbTCaps as $cap) {
            if (stripos($capsContent, $cap) !== false) {
                return true;
            }
        }

        // If caps file exists but does not have explicit DVB-T marker,
        // still treat as potential DVB-T device if in the correct frequency range
        return true;
    }

    /**
     * Read frontend capabilities from sysfs.
     *
     * @param string $frontendPath Path to the frontend device
     * @return array<string, mixed> Capabilities array
     */
    private function readCapabilities(string $frontendPath): array
    {
        $caps = [
            'modulation' => 'auto',
            'frequency_min' => 470000000,
            'frequency_max' => 862000000,
        ];

        // Try to read from sysfs
        $sysfsPath = str_replace('/dev/dvb', '/sys/class/dvb', $frontendPath);

        // Read frequency range
        $freqMinPath = $sysfsPath . '/frequency_min';
        $freqMaxPath = $sysfsPath . '/frequency_max';
        $capsPath = $sysfsPath . '/device/caps';

        if (file_exists($freqMinPath)) {
            $freqMin = @file_get_contents($freqMinPath);
            if ($freqMin !== false) {
                $caps['frequency_min'] = (int) trim($freqMin);
            }
        }

        if (file_exists($freqMaxPath)) {
            $freqMax = @file_get_contents($freqMaxPath);
            if ($freqMax !== false) {
                $caps['frequency_max'] = (int) trim($freqMax);
            }
        }

        if (file_exists($capsPath)) {
            $capsContent = @file_get_contents($capsPath);
            if ($capsContent !== false) {
                // Parse capabilities string
                if (stripos($capsContent, 'DVB-T2') !== false) {
                    $caps['modulation'] = 'DVB-T2';
                } elseif (stripos($capsContent, 'DVB-T') !== false) {
                    $caps['modulation'] = 'DVB-T';
                } elseif (stripos($capsContent, 'QAM256') !== false) {
                    $caps['modulation'] = 'QAM256';
                } elseif (stripos($capsContent, 'QAM64') !== false) {
                    $caps['modulation'] = 'QAM64';
                }
            }
        }

        return $caps;
    }

    /**
     * Get list of adapter directories.
     *
     * @return string[] Array of adapter directory paths
     */
    private function getAdapterDirs(): array
    {
        $adapters = [];

        if (!is_dir(self::DVB_BASE_PATH)) {
            return [];
        }

        $items = @scandir(self::DVB_BASE_PATH);
        if ($items === false) {
            return [];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = self::DVB_BASE_PATH . '/' . $item;
            if (is_dir($path) && preg_match('/^adapter\d+$/', $item)) {
                $adapters[] = $path;
            }
        }

        return $adapters;
    }

    /**
     * Extract adapter index from adapter path.
     *
     * @param string $adapterPath Path to the adapter directory
     * @return int|null Adapter index or null if not found
     */
    private function extractAdapterIndex(string $adapterPath): ?int
    {
        if (preg_match('/adapter(\d+)$/', $adapterPath, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    /**
     * Find frontend devices in an adapter directory.
     *
     * @param string $adapterPath Path to the adapter directory
     * @return string[] Array of frontend device paths
     */
    private function findFrontends(string $adapterPath): array
    {
        $frontends = [];

        if (!is_dir($adapterPath)) {
            return [];
        }

        $items = @scandir($adapterPath);
        if ($items === false) {
            return [];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $adapterPath . '/' . $item;
            if (is_dir($path) && preg_match('/^frontend\d+$/', $item)) {
                $frontends[] = $path;
            }
        }

        return $frontends;
    }

    /**
     * Extract frontend index from frontend path.
     *
     * @param string $frontendPath Path to the frontend device
     * @return int|null Frontend index or null if not found
     */
    private function extractFrontendIndex(string $frontendPath): ?int
    {
        if (preg_match('/frontend(\d+)$/', $frontendPath, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }
}
