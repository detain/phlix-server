<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Tuners\Dvbt;

/**
 * Immutable descriptor for a Linux DVB-T USB tuner device.
 *
 * Represents a DVB-T adapter discovered in /dev/dvb/ with its
 * adapter index, frontend index, modulation capabilities, and
 * frequency range.
 *
 * @since 0.12.0
 */
final class DvbtDevice
{
    /**
     * @param string $adapterPath Path to the DVB adapter device (e.g., /dev/dvb/adapter0)
     * @param int $adapterIndex Zero-based adapter index
     * @param int $frontendIndex Zero-based frontend index within the adapter
     * @param string $modulation Supported modulation (e.g., 'QAM64', 'QAM256', 'DVB-T', 'DVB-T2', 'auto')
     * @param int $frequencyMin Minimum tunable frequency in Hz
     * @param int $frequencyMax Maximum tunable frequency in Hz
     */
    public function __construct(
        public readonly string $adapterPath,
        public readonly int $adapterIndex,
        public readonly int $frontendIndex,
        public readonly string $modulation,
        public readonly int $frequencyMin,
        public readonly int $frequencyMax,
    ) {
    }

    /**
     * Get the frontend device path.
     *
     * @return string Full path to the frontend device (e.g., /dev/dvb/adapter0/frontend0)
     */
    public function getFrontendPath(): string
    {
        return $this->adapterPath . '/frontend' . $this->frontendIndex;
    }

    /**
     * Get the dvr device path for streaming.
     *
     * @return string Full path to the DVR device (e.g., /dev/dvb/adapter0/dvr0)
     */
    public function getDvrPath(): string
    {
        return $this->adapterPath . '/dvr' . $this->frontendIndex;
    }

    /**
     * Check if a given frequency is within the tunable range.
     *
     * @param int $frequencyHz Frequency in Hz to check
     * @return bool True if the frequency is within the device's range
     */
    public function isFrequencySupported(int $frequencyHz): bool
    {
        return $frequencyHz >= $this->frequencyMin && $frequencyHz <= $this->frequencyMax;
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed> Array representation
     */
    public function toArray(): array
    {
        return [
            'adapter_path' => $this->adapterPath,
            'adapter_index' => $this->adapterIndex,
            'frontend_index' => $this->frontendIndex,
            'modulation' => $this->modulation,
            'frequency_min' => $this->frequencyMin,
            'frequency_max' => $this->frequencyMax,
        ];
    }
}
