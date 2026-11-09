<?php

/**
 * Phlix media server component: Dvbt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

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
     * Stream output files allocated by startStreaming(), keyed by the int
     * cast of the live proc_open resource, so stopStreaming() unlinks
     * exactly the temp its process owned (SH-018).
     *
     * @var array<int, string>
     */
    private array $streamFilesByProcess = [];

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

        // SH-018: race-safely allocated (0600 from birth, unpredictable name)
        // instead of the old predictable
        // dvbt_stream_{adapter}_{frontend}.ts (fixed under the temp dir)
        // — a world-writable-/tmp symlink-preemption overwrite target for any
        // local co-tenant. This allocation is a placeholder 0-byte file for
        // the planned pipeline; tune() launches nothing (legacy scaffold
        // behavior, kept verbatim except that its command text is now built by
        // the SAME shared argv builder as the live path, rendered for display
        // only — the pre-cure code had two divergent sprintf escapers).
        $outputFile = $this->allocateStreamFile($device);

        $cmd = implode(' ', array_map(
            'escapeshellarg',
            $this->zapArgv($device, $frequencyHz, $modulation, $outputFile)
        )) . ' 2>&1';

        $this->logger?->debug('DvbtSignalEngine: planning dvbv5-zap', ['cmd' => $cmd]);

        // The allocation above PROVES the name is obtainable race-safely; the
        // placeholder itself is released immediately because tune() launches
        // nothing (a live launcher, startStreaming(), allocates its OWN file
        // and keeps it for the process lifetime). The legacy scaffold's URL
        // was always aspirational — it referenced a file nothing had created
        // — and this keeps that behavior, except the path is no longer a
        // predictable symlink-bait.
        @unlink($outputFile);

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

        // SH-018: race-safe 0600 temp file (see allocateStreamFile()), and the
        // process is launched from the shared ARGV ARRAY via proc_open's
        // shell-free form — the pre-cure shell string interpolated
        // $this->dvbv5ZapPath RAW (the sibling builder wrapped it in
        // escapeshellcmd; two builders, two postures, one divergence).
        $outputFile = $this->allocateStreamFile($device);

        $argv = $this->zapArgv($device, $frequencyHz, $modulation, $outputFile);

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],  // stdin
            1 => ['file', $outputFile, 'w'],  // stdout to the allocated file
            2 => ['pipe', 'w'],               // stderr to pipe
        ];

        $process = proc_open($argv, $descriptors, $pipes);

        if (!is_resource($process)) {
            @unlink($outputFile);
            throw new \RuntimeException('Failed to start dvbv5-zap process');
        }

        // Close stderr pipe - we don't need it
        if (isset($pipes[2])) {
            fclose($pipes[2]);
        }

        // Track the file against the live process so stopStreaming() can
        // unlink it; without this the old fixed name at least got truncated
        // on reuse, while unique temp files would accumulate per tune.
        $this->streamFilesByProcess[(int) $process] = $outputFile;

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
            // SH-018: remove the per-stream temp file we allocated for this
            // exact process handle (the old predictable name made this a
            // no-op implicitly; with unique temps, stopping must clean up).
            $key = (int) $process;
            if (isset($this->streamFilesByProcess[$key])) {
                @unlink($this->streamFilesByProcess[$key]);
                unset($this->streamFilesByProcess[$key]);
            }
            $this->logger?->debug('DvbtSignalEngine: streaming stopped');
        }
    }

    /**
     * The single dvbv5-zap argv builder both call sites share (SH-018).
     *
     * Pre-cure there were TWO sprintf shell strings, and the second one
     * interpolated the configured binary path RAW while the first wrapped it
     * in escapeshellcmd — the defense-in-depth divergence the review named.
     * The cure goes one step further than a shared escaper: the live path
     * (startStreaming) hands this ARGV ARRAY straight to proc_open(), which
     * PHP executes WITHOUT a shell, so no escaping question exists at all.
     * tune() — a legacy scaffold that only PLANS a command — renders the
     * argv per-element through escapeshellarg purely for its debug log.
     *
     * @return list<string>
     */
    private function zapArgv(DvbtDevice $device, int $frequencyHz, string $modulation, string $outputFile): array
    {
        return [
            $this->dvbv5ZapPath,
            '-a', (string) $device->adapterIndex,
            '-f', (string) $frequencyHz,
            '-m', $modulation,
            '-c', '/dev/null',
            '-d', (string) $device->frontendIndex,
            '-o', $outputFile,
        ];
    }

    /**
     * Race-safely allocate this stream's output file (SH-018).
     *
     * The pre-cure name `dvbt_stream_{adapter}_{frontend}.ts` (fixed under
     * the system temp dir) was fully predictable: on a host with local co-
     * tenants and a world-writable /tmp (anything but a single-tenant
     * container), another user could pre-create the path as a symlink and have the server's write follow it over an
     * arbitrary file. tempnam() is the PHP-side mkstemp: O_EXCL create with
     * mode 0600 regardless of umask and an unpredictable name, so there is
     * no preemption window and no symlink to follow.
     *
     * The name still carries the adapter/frontend pair for debuggability
     * (integer-derived, so no traversal), and ownership is verified before
     * the path is ever returned: a file we do not own is evidence something
     * else got there first, and the engine refuses to write through it
     * ("owner uid check if reading back" — nothing in-tree reads the file
     * back in-process; FFmpeg consumes it — but the check makes the 0600
     * birthright enforceable rather than assumed).
     *
     * @throws \RuntimeException When no private temp file can be created.
     */
    private function allocateStreamFile(DvbtDevice $device): string
    {
        $dir = sys_get_temp_dir();
        $prefix = sprintf('phlix-dvbt-a%d-f%d-', $device->adapterIndex, $device->frontendIndex);

        $tmp = @tempnam($dir, $prefix);
        if ($tmp === false) {
            throw new \RuntimeException('DvbtSignalEngine: failed to allocate DVB-T stream output file in ' . $dir);
        }

        // tempnam is 0600-from-birth; the explicit chmod keeps the guarantee
        // stated even if a future platform deviates (same belt-and-suspenders
        // as Ed25519KeyManager::writePrivateKeyAtomically()).
        @chmod($tmp, 0600);

        if (function_exists('posix_geteuid') && fileowner($tmp) !== posix_geteuid()) {
            $owner = fileowner($tmp);
            @unlink($tmp);
            throw new \RuntimeException(
                'DvbtSignalEngine: allocated stream file is not owned by this process'
                    . ' (owner ' . var_export($owner, true) . ', euid ' . posix_geteuid() . ')'
            );
        }

        return $tmp;
    }
}
