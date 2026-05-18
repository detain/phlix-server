<?php

declare(strict_types=1);

namespace Phlex\LiveTv;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

/**
 * Live TV Manager - Manages tuner discovery, channel scanning, and TV functionality.
 *
 * This class provides the main interface for Live TV operations including:
 * - Tuner device discovery and management
 * - Channel scanning and tuning
 * - Integration with program guide
 *
 * ## Tuner Types
 *
 * The manager supports multiple DVB tuner types:
 * - DVB-T (Terrestrial)
 * - DVB-S (Satellite)
 * - DVB-C (Cable)
 * - ATSC (North American Terrestrial)
 *
 * ## Tuner Status Flow
 *
 * ```
 * IDLE → SCANNING → IDLE
 *       ↓
 *     TUNING → STREAMING → IDLE
 *                  ↓
 *               ERROR → IDLE
 * ```
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @see ChannelManager For channel CRUD operations
 * @see GuideManager For electronic program guide functionality
 * @see Recorder For DVR recording functionality
 */
class LiveTvManager
{
    /** @var Connection Database connection */
    private Connection $db;

    /** @var ChannelManager Channel management delegate */
    private ChannelManager $channelManager;

    /** @var GuideManager Program guide delegate */
    private GuideManager $guideManager;

    /** @var Recorder DVR recording delegate */
    private Recorder $recorder;

    /** @var StructuredLogger Structured logger instance */
    private StructuredLogger $logger;

    /** @var array<string, array{id:string,name:string,type:string,status:string,adapter:string|int,frontend:string,capabilities:array}> Discovered tuners */
    private array $tuners = [];

    /** @var array<string, array{id:string,channel_id:string,tuner_id:string,started_at:int,stream_url:string}> Active tune requests keyed by tune request ID */
    private array $activeTuneRequests = [];

    /**
     * Tuner is available and idle.
     *
     * @var string
     */
    public const TUNER_STATUS_IDLE = 'idle';

    /**
     * Tuner is performing a channel scan.
     *
     * @var string
     */
    public const TUNER_STATUS_SCANNING = 'scanning';

    /**
     * Tuner is tuning to a specific frequency.
     *
     * @var string
     */
    public const TUNER_STATUS_TUNING = 'tuning';

    /**
     * Tuner is actively streaming content.
     *
     * @var string
     */
    public const TUNER_STATUS_STREAMING = 'streaming';

    /**
     * Tuner encountered an error.
     *
     * @var string
     */
    public const TUNER_STATUS_ERROR = 'error';

    /**
     * DVB-Terrestrial tuner type.
     *
     * @var string
     */
    public const TUNER_TYPE_DVB_T = 'dvb_t';

    /**
     * DVB-Satellite tuner type.
     *
     * @var string
     */
    public const TUNER_TYPE_DVB_S = 'dvb_s';

    /**
     * DVB-Cable tuner type.
     *
     * @var string
     */
    public const TUNER_TYPE_DVB_C = 'dvb_c';

    /**
     * ATSC tuner type (North American).
     *
     * @var string
     */
    public const TUNER_TYPE_ATSC = 'atsc';

    /**
     * Creates a new LiveTvManager instance.
     *
     * @param Connection $db Database connection for tuner/channel persistence
     * @param ChannelManager $channelManager Channel management handler
     * @param GuideManager $guideManager Program guide handler
     * @param Recorder $recorder DVR recording handler
     * @param StructuredLogger|null $logger Optional logger, defaults to Livetv channel
     */
    public function __construct(
        Connection $db,
        ChannelManager $channelManager,
        GuideManager $guideManager,
        Recorder $recorder,
        ?StructuredLogger $logger = null
    ) {
        $this->db = $db;
        $this->channelManager = $channelManager;
        $this->guideManager = $guideManager;
        $this->recorder = $recorder;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);
    }

    /**
     * Discover available tuners on the system.
     *
     * Scans the system for DVB tuner devices and registers them in the database.
     * On Linux systems, this checks /dev/dvb for available adapters.
     *
     * @return array Discovered tuners
     * @throws \RuntimeException If database operations fail
     *
     * @example
     * ```php
     * $tuners = $manager->discoverTuners();
     * foreach ($tuners as $tuner) {
     *     echo "Found: {$tuner['name']} ({$tuner['type']})\n";
     * }
     * ```
     */
    public function discoverTuners(): array
    {
        $this->logger->info('Starting tuner discovery');

        $tuners = $this->scanForTuners();

        foreach ($tuners as $tuner) {
            $this->registerTuner($tuner);
        }

        $this->tuners = $tuners;
        $this->logger->info('Tuner discovery complete', ['count' => count($tuners)]);

        return $tuners;
    }

    /**
     * Scan the system for available tuner devices.
     *
     * Searches /dev/dvb for DVB adapter devices and returns their information.
     * Each discovered device is checked for a frontend0 device.
     *
     * @return array Tuners found
     *
     * @example
     * ```php
     * $tuners = $this->scanForTuners();
     * // Returns: [['id' => 'dvb_0', 'name' => 'DVB Adapter 0', ...], ...]
     * ```
     */
    private function scanForTuners(): array
    {
        $tuners = [];

        $dvbBase = '/dev/dvb';
        if (is_dir($dvbBase)) {
            $adapters = glob("$dvbBase/adapter*");
            foreach ($adapters as $adapter) {
                if (is_dir($adapter)) {
                    $adapterNum = preg_replace('/[^0-9]/', '', $adapter);
                    $frontend = "$adapter/frontend0";

                    if (file_exists($frontend)) {
                        $tuners[] = [
                            'id' => "dvb_$adapterNum",
                            'name' => "DVB Adapter $adapterNum",
                            'type' => $this->detectDvbType($frontend),
                            'status' => self::TUNER_STATUS_IDLE,
                            'adapter' => $adapterNum,
                            'frontend' => $frontend,
                            'capabilities' => $this->getTunerCapabilities($frontend),
                        ];
                    }
                }
            }
        }

        return $tuners;
    }

    /**
     * Detect the DVB type (terrestrial, satellite, cable).
     *
     * Reads the frontend device capabilities to determine the modulation type.
     * In production, this would read from /sys/class/dvb/dvbX.frontend0/caps.
     *
     * @param string $frontendPath Path to the frontend device
     * @return string One of TUNER_TYPE_DVB_T, TUNER_TYPE_DVB_S, TUNER_TYPE_DVB_C, or TUNER_TYPE_ATSC
     */
    private function detectDvbType(string $frontendPath): string
    {
        return self::TUNER_TYPE_DVB_T;
    }

    /**
     * Get tuner capabilities from frontend device.
     *
     * Returns the frequency range and symbol rate capabilities of the tuner.
     * In production, these values are read from the kernel DVB driver.
     *
     * @param string $frontendPath Path to the frontend device
     * @return array<string, int> Capabilities including frequency_min, frequency_max, symbol_rate_min, symbol_rate_max
     */
    private function getTunerCapabilities(string $frontendPath): array
    {
        return [
            'frequency_min' => 45000000,
            'frequency_max' => 862000000,
            'symbol_rate_min' => 1000000,
            'symbol_rate_max' => 45000000,
        ];
    }

    /**
     * Register a tuner in the database.
     *
     * Stores tuner information for persistence and later retrieval.
     * Uses ON DUPLICATE KEY UPDATE to handle re-discovery gracefully.
     *
     * @param array<string, mixed> $tuner Tuner data to register
     * @return void
     */
    private function registerTuner(array $tuner): void
    {
        $this->db->query(
            "INSERT INTO livetv_tuners (tuner_id, name, type, status, capabilities, discovered_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type), status = VALUES(status)",
            [
                $tuner['id'],
                $tuner['name'],
                $tuner['type'],
                $tuner['status'],
                json_encode($tuner['capabilities']),
            ]
        );
    }

    /**
     * Scan for available channels using a specific tuner.
     *
     * Performs a channel scan by iterating through configured frequencies
     * and detecting broadcast services on each frequency.
     *
     * @param string $tunerId The tuner ID to use for scanning
     * @param array<string, mixed> $options Scan options including:
     *   - frequencies: int[] List of frequencies (Hz) to scan
     *   - symbol_rate: int Symbol rate for cable/satellite
     * @return array<int, array<string, mixed>> Scan results with discovered channels
     * @throws \InvalidArgumentException If tuner not found
     *
     * @example
     * ```php
     * $channels = $manager->scanChannels('dvb_0', [
     *     'frequencies' => [474000000, 498000000, 522000000]
     * ]);
     * ```
     */
    public function scanChannels(string $tunerId, array $options = []): array
    {
        $tuner = $this->getTuner($tunerId);
        if (!$tuner) {
            throw new \InvalidArgumentException("Tuner not found: $tunerId");
        }

        $this->updateTunerStatus($tunerId, self::TUNER_STATUS_SCANNING);
        $this->logger->info('Starting channel scan', ['tuner_id' => $tunerId]);

        $channels = $this->performChannelScan($tuner, $options);

        $this->updateTunerStatus($tunerId, self::TUNER_STATUS_IDLE);
        $this->logger->info('Channel scan complete', ['tuner_id' => $tunerId, 'channels_found' => count($channels)]);

        return $channels;
    }

    /**
     * Get a tuner by its ID.
     *
     * @param string $tunerId The unique tuner identifier
     * @return array<string, mixed>|null The tuner data or null if not found
     *
     * @example
     * ```php
     * $tuner = $manager->getTuner('dvb_0');
     * if ($tuner !== null) {
     *     echo "Tuner: {$tuner['name']} is {$tuner['status']}";
     * }
     * ```
     */
    public function getTuner(string $tunerId): ?array
    {
        foreach ($this->tuners as $tuner) {
            if ($tuner['id'] === $tunerId) {
                return $tuner;
            }
        }
        return null;
    }

    /**
     * Get all registered tuners.
     *
     * @return array<string, array<string, mixed>> All discovered tuners
     */
    public function getTuners(): array
    {
        return $this->tuners;
    }

    /**
     * Update tuner status in database and local cache.
     *
     * @param string $tunerId The tuner identifier
     * @param string $status New status (one of TUNER_STATUS_* constants)
     * @return void
     */
    private function updateTunerStatus(string $tunerId, string $status): void
    {
        $this->db->query(
            "UPDATE livetv_tuners SET status = ?, updated_at = NOW() WHERE tuner_id = ?",
            [$status, $tunerId]
        );

        foreach ($this->tuners as &$tuner) {
            if ($tuner['id'] === $tunerId) {
                $tuner['status'] = $status;
                break;
            }
        }
    }

    /**
     * Perform the actual channel scan on a tuner.
     *
     * Iterates through frequencies and extracts service information
     * from the broadcast transport stream (PAT/SDT tables).
     *
     * @param array<string, mixed> $tuner The tuner to use for scanning
     * @param array<string, mixed> $options Scan options including frequencies
     * @return array<int, array<string, mixed>> Discovered channels
     */
    private function performChannelScan(array $tuner, array $options): array
    {
        $discoveredChannels = [];

        $frequencies = $options['frequencies'] ?? [474000000, 498000000, 522000000, 570000000];

        foreach ($frequencies as $frequency) {
            $services = $this->scanFrequency($tuner, $frequency);
            foreach ($services as $service) {
                $channel = $this->channelManager->createChannel([
                    'name' => $service['name'],
                    'number' => $service['number'],
                    'type' => $service['type'],
                    'frequency' => $frequency,
                    'tuner_id' => $tuner['id'],
                    'service_id' => $service['id'],
                ]);

                if ($channel) {
                    $discoveredChannels[] = $channel;
                }
            }
        }

        return $discoveredChannels;
    }

    /**
     * Scan a specific frequency for broadcast services.
     *
     * In a real implementation, this would:
     * 1. Tune the frontend to the specified frequency
     * 2. Read the Program Association Table (PAT) to find services
     * 3. Read Service Description Table (SDT) for service names/types
     *
     * @param array<string, mixed> $tuner The tuner to use
     * @param int $frequency Frequency in Hz
     * @return array<int, array{id:string, name:string, number:int, type:string}> Discovered services
     */
    private function scanFrequency(array $tuner, int $frequency): array
    {
        return [];
    }

    /**
     * Tune to a channel and start streaming.
     *
     * Finds an available tuner, locks onto the channel frequency,
     * and returns a stream URL for playback.
     *
     * @param string $channelId The channel ID to tune to
     * @param string|null $tunerId Optional specific tuner ID (uses any available if null)
     * @return array{id:string, channel_id:string, tuner_id:string, started_at:int, stream_url:string} Tune result
     * @throws \InvalidArgumentException If channel not found
     * @throws \RuntimeException If no tuner is available
     *
     * @example
     * ```php
     * $result = $manager->tuneToChannel('channel_123');
     * echo "Stream: {$result['stream_url']}";
     * ```
     */
    public function tuneToChannel(string $channelId, ?string $tunerId = null): array
    {
        $channel = $this->channelManager->getChannel($channelId);
        if (!$channel) {
            throw new \InvalidArgumentException("Channel not found: $channelId");
        }

        // Find an available tuner
        $tuner = $this->findAvailableTuner($tunerId);
        if (!$tuner) {
            throw new \RuntimeException('No available tuner');
        }

        $this->updateTunerStatus($tuner['id'], self::TUNER_STATUS_TUNING);

        // Generate unique tune request ID
        $tuneRequestId = $this->generateUuid();

        $this->activeTuneRequests[$tuneRequestId] = [
            'id' => $tuneRequestId,
            'channel_id' => $channelId,
            'tuner_id' => $tuner['id'],
            'started_at' => time(),
            'stream_url' => "/livetv/$tuneRequestId/stream",
        ];

        $this->updateTunerStatus($tuner['id'], self::TUNER_STATUS_STREAMING);

        $this->logger->info('Tuned to channel', [
            'tune_request_id' => $tuneRequestId,
            'channel_id' => $channelId,
            'tuner_id' => $tuner['id'],
        ]);

        return $this->activeTuneRequests[$tuneRequestId];
    }

    /**
     * Find an available tuner for tuning.
     *
     * First checks for the preferred tuner if specified,
     * otherwise searches for any idle tuner.
     *
     * @param string|null $preferredTunerId Optional preferred tuner ID
     * @return array<string, mixed>|null Available tuner or null if none found
     */
    private function findAvailableTuner(?string $preferredTunerId = null): ?array
    {
        if ($preferredTunerId) {
            foreach ($this->tuners as $tuner) {
                if ($tuner['id'] === $preferredTunerId && $tuner['status'] === self::TUNER_STATUS_IDLE) {
                    return $tuner;
                }
            }
            return null;
        }

        foreach ($this->tuners as $tuner) {
            if ($tuner['status'] === self::TUNER_STATUS_IDLE) {
                return $tuner;
            }
        }

        return null;
    }

    /**
     * Stop tuning and release the tuner.
     *
     * Updates the tuner status back to IDLE and removes
     * the active tune request.
     *
     * @param string $tuneRequestId The tune request to stop
     * @return void
     *
     * @example
     * ```php
     * $manager->stopTuning($tuneRequestId);
     * ```
     */
    public function stopTuning(string $tuneRequestId): void
    {
        if (!isset($this->activeTuneRequests[$tuneRequestId])) {
            return;
        }

        $request = $this->activeTuneRequests[$tuneRequestId];
        $this->updateTunerStatus($request['tuner_id'], self::TUNER_STATUS_IDLE);

        unset($this->activeTuneRequests[$tuneRequestId]);

        $this->logger->info('Stopped tuning', ['tune_request_id' => $tuneRequestId]);
    }

    /**
     * Get current tune request status.
     *
     * @param string $tuneRequestId The tune request ID
     * @return array<string, mixed>|null The tune request data or null if not found
     */
    public function getTuneRequest(string $tuneRequestId): ?array
    {
        return $this->activeTuneRequests[$tuneRequestId] ?? null;
    }

    /**
     * Get all active tune requests.
     *
     * @return array<int, array<string, mixed>> List of active tune requests
     */
    public function getActiveTuneRequests(): array
    {
        return array_values($this->activeTuneRequests);
    }

    /**
     * Get the ChannelManager instance.
     *
     * @return ChannelManager The channel manager for this LiveTV instance
     */
    public function getChannelManager(): ChannelManager
    {
        return $this->channelManager;
    }

    /**
     * Get the GuideManager instance.
     *
     * @return GuideManager The guide manager for this LiveTV instance
     */
    public function getGuideManager(): GuideManager
    {
        return $this->guideManager;
    }

    /**
     * Get the Recorder instance.
     *
     * @return Recorder The recorder for this LiveTV instance
     */
    public function getRecorder(): Recorder
    {
        return $this->recorder;
    }

    /**
     * Generate a unique UUID v4 string.
     *
     * @return string A UUID in the format xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
