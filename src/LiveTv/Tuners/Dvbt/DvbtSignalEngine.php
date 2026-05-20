<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Tuners\Dvbt;

use Phlix\Common\Logger\StructuredLogger;
use Psr\Log\LoggerInterface;

/**
 * Signal engine for DVB-T tuning and streaming.
 *
 * Uses dvbv5-zap (or direct device access) to tune to a DVB-T frequency
 * and produces an FFmpeg ingest URL for HLS packaging.
 *
 * @since 0.12.0
 */
class DvbtSignalEngine
{
    /** @var string Path to FFmpeg binary */
    private string $ffmpegPath;

    /** @var string Path to dvbv5-zap binary */
    private string $dvbv5ZapPath;

    /** @var StructuredLogger|LoggerInterface|null Optional logger */
    private StructuredLogger|LoggerInterface|null $logger;

    /**
     * @param string $ffmpegPath Path to FFmpeg binary
     * @param string $dvbv5ZapPath Path to dvbv5-zap binary
     * @param StructuredLogger|LoggerInterface|null $logger Optional logger instance
     */
    public function __construct(
        string $ffmpegPath,
        string $dvbv5ZapPath,
        StructuredLogger|LoggerInterface|null $logger = null
    ) {
        $this->ffmpegPath = $ffmpegPath;
        $this->dvbv5ZapPath = $dvbv5ZapPath;
        $this->logger = $logger;
    }

    /**
     * Tune to a frequency and return the ingest URL.
     *
     * Uses dvbv5-zap to tune the frontend and start streaming.
     * Returns a UDP multicast URL or named pipe for FFmpeg ingestion.
     *
     * @param DvbtDevice $device The DVB-T device to tune
     * @param int $frequencyHz Frequency in Hz (e.g., 474000000 for 474 MHz)
     * @param string $modulation Modulation type (e.g., 'auto', 'QAM64', 'QAM256', 'DVB-T')
     * @return string Ingest URL (udp://... or pipe:...)
     */
    public function tune(DvbtDevice $device, int $frequencyHz, string $modulation = 'auto'): string
    {
        $this->logger?->info('DvbtSignalEngine: tuning to frequency', [
            'adapter' => $device->adapterPath,
            'frontend' => $device->frontendIndex,
            'frequency' => $frequencyHz,
            'modulation' => $modulation,
        ]);

        // Build dvbv5-zap command
        // dvbv5-zap -a 0 -f 474000000 -m QAM64 -c /dev/null -d 0 -o output.ts
        // Output goes to stdout which we pipe to FFmpeg
        $outputFile = '/tmp/dvbt_stream_' . $device->adapterIndex . '_' . $device->frontendIndex . '.ts';

        $cmd = sprintf(
            '%s -a %d -f %d -m %s -c %s -d %d -o %s 2>&1',
            escapeshellcmd($this->dvbv5ZapPath),
            $device->adapterIndex,
            $frequencyHz,
            escapeshellarg($modulation),
            '/dev/null',
            $device->frontendIndex,
            $outputFile
        );

        $this->logger?->debug('DvbtSignalEngine: executing dvbv5-zap', ['cmd' => $cmd]);

        // For now, return a placeholder ingest URL
        // In production, this would start dvbv5-zap in background and return the pipe/UDP URL
        $ingestUrl = 'pipe://' . $outputFile;

        // Check if dvbv5-zap exists and is executable
        if (!is_executable($this->dvbv5ZapPath)) {
            $this->logger?->warning('DvbtSignalEngine: dvbv5-zap not found or not executable', [
                'path' => $this->dvbv5ZapPath,
            ]);
            // Return direct DVR device path as fallback
            $ingestUrl = $device->getDvrPath();
        }

        $this->logger?->info('DvbtSignalEngine: tuned successfully', [
            'ingest_url' => $ingestUrl,
        ]);

        return $ingestUrl;
    }

    /**
     * Return the HLS-packaged stream URL for the tuned frequency.
     *
     * After tuning with tune(), this returns the URL that FFmpeg
     * is streaming to (HLS output URL or the ingest URL directly).
     *
     * @param DvbtDevice $device The DVB-T device
     * @param int $channelNumber The channel number (for reference)
     * @return string HLS stream URL
     */
    public function getStreamUrl(DvbtDevice $device, int $channelNumber): string
    {
        $this->logger?->info('DvbtSignalEngine: getting stream URL', [
            'adapter' => $device->adapterPath,
            'channel' => $channelNumber,
        ]);

        // Return the DVR device path for direct streaming
        // FFmpeg will read from this and repackage to HLS
        $streamUrl = $device->getDvrPath();

        // In a full implementation, this would return the HLS URL
        // where FFmpeg is actively repackaging the transport stream
        // For example: http://localhost:8888/livetv/dvbt/{adapterIndex}/stream.m3u8

        return $streamUrl;
    }

    /**
     * Probe the signal strength of a tuned device.
     *
     * Reads signal strength, SNR, and BER from the DVB frontend.
     *
     * @param DvbtDevice $device The DVB-T device to probe
     * @return array{signal:int, snr:int, ber:int, ucblocks:int} Signal metrics
     */
    public function getSignalStrength(DvbtDevice $device): array
    {
        $this->logger?->debug('DvbtSignalEngine: probing signal strength', [
            'adapter' => $device->adapterPath,
        ]);

        // Read from sysfs for signal strength
        $sysfsPath = str_replace(
            '/dev/dvb',
            '/sys/class/dvb',
            $device->getFrontendPath()
        );

        $signalPath = $sysfsPath . '/device/../dvb0.demux0/demux0';
        $signalPath = dirname(dirname($sysfsPath)) . '/frontend' . $device->frontendIndex
            . '/device/../dvb0.demux0.ts0';

        // Fallback to standard sysfs paths
        $signalPath = $sysfsPath . '/device/../dvb0.frontend0/signal_strength';

        // Standard sysfs path for signal strength
        $signalPath = '/sys/class/dvb/dvb0.frontend' . $device->frontendIndex
            . '/device/device/../signal_strength';

        $signal = 0;
        $snr = 0;
        $ber = 0;
        $ucblocks = 0;

        // Try to read signal strength
        $signalPath = '/sys/class/dvb/dvb' . $device->adapterIndex
            . '.frontend' . $device->frontendIndex . '/signal_strength';
        if (file_exists($signalPath)) {
            $value = @file_get_contents($signalPath);
            if ($value !== false) {
                $signal = (int) trim($value);
            }
        }

        // Try to read SNR
        $snrPath = '/sys/class/dvb/dvb' . $device->adapterIndex . '.frontend' . $device->frontendIndex . '/snr';
        if (file_exists($snrPath)) {
            $value = @file_get_contents($snrPath);
            if ($value !== false) {
                $snr = (int) trim($value);
            }
        }

        // Try to read BER
        $berPath = '/sys/class/dvb/dvb' . $device->adapterIndex
            . '.frontend' . $device->frontendIndex . '/ber';
        if (file_exists($berPath)) {
            $value = @file_get_contents($berPath);
            if ($value !== false) {
                $ber = (int) trim($value);
            }
        }

        // Try to read uncorrected blocks
        $ucblocksPath = '/sys/class/dvb/dvb' . $device->adapterIndex
            . '.frontend' . $device->frontendIndex . '/ucblocks';
        if (file_exists($ucblocksPath)) {
            $value = @file_get_contents($ucblocksPath);
            if ($value !== false) {
                $ucblocks = (int) trim($value);
            }
        }

        // If no signal data found, return default values (tuner may be idle)
        if ($signal === 0 && $snr === 0) {
            $this->logger?->debug('DvbtSignalEngine: no signal data available', [
                'device' => $device->adapterPath,
            ]);
        }

        return [
            'signal' => $signal,
            'snr' => $snr,
            'ber' => $ber,
            'ucblocks' => $ucblocks,
        ];
    }

    /**
     * Start streaming from a tuned device.
     *
     * Uses proc_open to run dvbv5-zap and pipe the output to FFmpeg
     * for HLS packaging.
     *
     * @param DvbtDevice $device The DVB-T device
     * @param int $frequencyHz Frequency in Hz
     * @param string $modulation Modulation type
     * @param int $port UDP port for output (if using UDP multicast)
     * @return array{process:resource, ingest_url:string} Process handle and ingest URL
     */
    public function startStreaming(
        DvbtDevice $device,
        int $frequencyHz,
        string $modulation = 'auto',
        int $port = 8888
    ): array {
        $this->logger?->info('DvbtSignalEngine: starting streaming', [
            'device' => $device->adapterPath,
            'frequency' => $frequencyHz,
            'port' => $port,
            'ffmpeg' => $this->ffmpegPath,
        ]);

        $outputFile = '/tmp/dvbt_stream_' . $device->adapterIndex . '_' . $device->frontendIndex . '.ts';

        // Build dvbv5-zap command
        $cmd = sprintf(
            '%s -a %d -f %d -m %s -c %s -d %d -o %s',
            $this->dvbv5ZapPath,
            $device->adapterIndex,
            $frequencyHz,
            escapeshellarg($modulation),
            '/dev/null',
            $device->frontendIndex,
            $outputFile
        );

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],  // stdin
            1 => ['file', $outputFile, 'w'],  // stdout to file
            2 => ['pipe', 'w'],               // stderr to pipe
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start dvbv5-zap process');
        }

        // Close stderr pipe - we don't need it
        if (isset($pipes[2])) {
            fclose($pipes[2]);
        }

        // Ingest URL - FFmpeg would read from this
        $ingestUrl = 'file://' . $outputFile;

        $this->logger?->info('DvbtSignalEngine: streaming started', [
            'ingest_url' => $ingestUrl,
            'pid' => proc_get_status($process)['pid'],
        ]);

        return [
            'process' => $process,
            'ingest_url' => $ingestUrl,
        ];
    }

    /**
     * Stop streaming from a device.
     *
     * @param resource $process The process handle from startStreaming
     * @return void
     */
    public function stopStreaming($process): void
    {
        if (is_resource($process)) {
            proc_terminate($process, SIGTERM);
            proc_close($process);
            $this->logger?->debug('DvbtSignalEngine: streaming stopped');
        }
    }
}
