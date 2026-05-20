<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Tuners\Dvbt;

use Phlix\LiveTv\Tuners\Dvbt\DvbtDevice;
use Phlix\LiveTv\Tuners\HdHomeRun\HdHomeRunDevice;
use Phlix\LiveTv\Tuners\Iptv\IptvDevice;
use Phlix\LiveTv\Tuners\TunerDriverInterface;
use Phlix\Common\Logger\StructuredLogger;
use Psr\Log\LoggerInterface;

/**
 * DVB-T tuner driver implementing TunerDriverInterface.
 *
 * This driver scans /dev/dvb/ for Linux DVB-T USB adapters,
 * uses dvbv5-zap for frequency tuning, and provides transport
 * stream URLs for FFmpeg HLS packaging.
 *
 * @since 0.12.0
 */
class DvbtTunerDriver implements TunerDriverInterface
{
    /** @var DvbtDeviceScanner Scanner for DVB-T devices */
    private DvbtDeviceScanner $scanner;

    /** @var DvbtSignalEngine Signal engine for tuning and streaming */
    private DvbtSignalEngine $signalEngine;

    /** @var StructuredLogger|LoggerInterface|null Optional logger */
    private StructuredLogger|LoggerInterface|null $logger;

    /**
     * @param DvbtDeviceScanner $scanner Device scanner
     * @param DvbtSignalEngine $signalEngine Signal engine for tuning
     * @param StructuredLogger|LoggerInterface|null $logger Optional logger instance
     */
    public function __construct(
        DvbtDeviceScanner $scanner,
        DvbtSignalEngine $signalEngine,
        StructuredLogger|LoggerInterface|null $logger = null
    ) {
        $this->scanner = $scanner;
        $this->signalEngine = $signalEngine;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'dvbt';
    }

    /**
     * @inheritDoc
     *
     * Uses /dev/dvb/ scanner to find DVB-T USB adapters.
     *
     * @return DvbtDevice[] Array of discovered devices
     */
    public function discoverDevices(): array
    {
        $this->logger?->info('DvbtTunerDriver: discovering devices');

        $devices = $this->scanner->scan();

        $this->logger?->info('DvbtTunerDriver: discovered devices', ['count' => count($devices)]);

        return $devices;
    }

    /**
     * @inheritDoc
     *
     * For DVB-T, returns a static channel list based on typical
     * frequency ranges. Real implementation would scan and
     * extract channel info from broadcast tables.
     *
     * @param HdHomeRunDevice|IptvDevice|DvbtDevice $device The device to query
     * @return array<int, array{channel_number:int, name:string, type:string,
     *           transport_stream_id:null, program_id:null}> Channel list
     * @throws \InvalidArgumentException If device is not a DvbtDevice
     */
    public function getChannelLineup(HdHomeRunDevice|IptvDevice|DvbtDevice $device): array
    {
        if (!$device instanceof DvbtDevice) {
            throw new \InvalidArgumentException('Expected DvbtDevice for DVB-T tuner');
        }

        $this->logger?->info('DvbtTunerDriver: getting channel lineup', [
            'adapter' => $device->adapterPath,
        ]);

        // DVB-T doesn't have a predefined channel lineup
        // Users must tune to specific frequencies for their region
        return [];
    }

    /**
     * @inheritDoc
     *
     * For DVB-T, triggers a frequency scan across the device's
     * supported frequency range to find available services.
     *
     * @param HdHomeRunDevice|IptvDevice|DvbtDevice $device The device to scan
     * @return array<int, array{channel_number:int, name:string, type:string,
     *           transport_stream_id:null, program_id:null}> Discovered channels
     * @throws \InvalidArgumentException If device is not a DvbtDevice
     */
    public function scanChannels(HdHomeRunDevice|IptvDevice|DvbtDevice $device): array
    {
        if (!$device instanceof DvbtDevice) {
            throw new \InvalidArgumentException('Expected DvbtDevice for DVB-T tuner');
        }

        $this->logger?->info('DvbtTunerDriver: scanning channels', [
            'adapter' => $device->adapterPath,
            'frequency_range' => $device->frequencyMin . '-' . $device->frequencyMax,
        ]);

        // DVB-T channel scanning would iterate through frequencies
        // and extract PAT/SDT tables from the transport stream
        // For now, return empty array - actual implementation would
        // use dvbv5-zap to scan each frequency
        return [];
    }

    /**
     * @inheritDoc
     *
     * Returns the transport stream URL for the specified channel number.
     *
     * For DVB-T, the channel number is used as an index into the
     * frequency list or to determine the frequency to tune.
     *
     * @param HdHomeRunDevice|IptvDevice|DvbtDevice $device The device to use
     * @param int $channelNumber The channel number to tune
     * @return string The stream URL (DVR device path or HLS URL)
     * @throws \InvalidArgumentException If device is not a DvbtDevice
     */
    public function getStreamUrl(HdHomeRunDevice|IptvDevice|DvbtDevice $device, int $channelNumber): string
    {
        if (!$device instanceof DvbtDevice) {
            throw new \InvalidArgumentException('Expected DvbtDevice for DVB-T tuner');
        }

        $this->logger?->info('DvbtTunerDriver: getting stream URL', [
            'adapter' => $device->adapterPath,
            'channel' => $channelNumber,
        ]);

        // For DVB-T, channel number maps to frequency
        // Common European frequencies (UHF channel numbers -> frequency in Hz)
        $frequenciesByChannel = $this->getCommonFrequencies();

        // Get frequency based on channel number (1-indexed)
        $freqIndex = $channelNumber - 1;
        if ($freqIndex < 0 || $freqIndex >= count($frequenciesByChannel)) {
            // Use default frequency if channel out of range
            $frequencyHz = 474000000; // Default: UHF channel 21
        } else {
            $frequencyHz = $frequenciesByChannel[$freqIndex];
        }

        // Tune to frequency and return stream URL
        return $this->signalEngine->getStreamUrl($device, $channelNumber);
    }

    /**
     * Get common DVB-T frequencies in Hz.
     *
     * These are typical European UHF frequencies for DVB-T.
     * Real implementation would use region-specific frequency tables.
     *
     * @return int[] Array of frequencies in Hz
     */
    private function getCommonFrequencies(): array
    {
        // European UHF channel frequencies (MHz -> Hz)
        return [
            474000000,  // UHF 21
            482000000,  // UHF 22
            490000000,  // UHF 23
            498000000,  // UHF 24
            506000000,  // UHF 25
            514000000,  // UHF 26
            522000000,  // UHF 27
            530000000,  // UHF 28
            538000000,  // UHF 29
            546000000,  // UHF 30
            554000000,  // UHF 31
            562000000,  // UHF 32
            570000000,  // UHF 33
            578000000,  // UHF 34
            586000000,  // UHF 35
            594000000,  // UHF 36
            602000000,  // UHF 37
            610000000,  // UHF 38
            618000000,  // UHF 39
            626000000,  // UHF 40
            634000000,  // UHF 41
            642000000,  // UHF 42
            650000000,  // UHF 43
            658000000,  // UHF 44
            666000000,  // UHF 45
            674000000,  // UHF 46
            682000000,  // UHF 47
            690000000,  // UHF 48
            698000000,  // UHF 49
            706000000,  // UHF 50
            714000000,  // UHF 51
            722000000,  // UHF 52
            730000000,  // UHF 53
            738000000,  // UHF 54
            746000000,  // UHF 55
            754000000,  // UHF 56
            762000000,  // UHF 57
            770000000,  // UHF 58
            778000000,  // UHF 59
            786000000,  // UHF 60
            794000000,  // UHF 61
            802000000,  // UHF 62
            810000000,  // UHF 63
            818000000,  // UHF 64
            826000000,  // UHF 65
            834000000,  // UHF 66
            842000000,  // UHF 67
            850000000,  // UHF 68
            858000000,  // UHF 69
        ];
    }
}
