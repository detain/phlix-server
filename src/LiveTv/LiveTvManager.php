<?php

declare(strict_types=1);

namespace Phlex\LiveTv;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\LiveTv\Epg\SchedulesDirect\SdEpgService;
use Phlex\LiveTv\Epg\SchedulesDirect\SdEpgServiceFactory;
use Phlex\LiveTv\Tuners\Dvbt\DvbtDevice;
use Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunDevice;
use Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunTunerDriver;
use Phlex\LiveTv\Tuners\Iptv\IptvDevice;
use Phlex\LiveTv\Tuners\Iptv\IptvTunerDriver;
use Phlex\LiveTv\Tuners\TunerDriverInterface;
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
 * The manager supports multiple tuner types:
 * - HDHomeRun (network-attached TV tuners via SSDP + HTTP API)
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

    /** @var TunerDriverInterface The primary tuner driver for device discovery and streaming */
    private TunerDriverInterface $tunerDriver;

    /** @var array<string, TunerDriverInterface> Additional tuner drivers (e.g., IPTV) */
    private array $additionalDrivers = [];

    /** @var array<string, array<string, mixed>> Discovered tuners */
    private array $tuners = [];

    /** @var array<string, array{id:string,channel_id:string,tuner_id:string,started_at:int,stream_url:string}> Active tune requests keyed by tune request ID */
    private array $activeTuneRequests = [];

    /** @var SdEpgService|null Optional Schedules Direct EPG service */
    private ?SdEpgService $sdEpgService = null;

    /** @var array<string, mixed>|null Cached SD config */
    private ?array $sdConfig = null;

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
     * HDHomeRun tuner type (network-attached).
     *
     * @var string
     */
    public const TUNER_TYPE_HDHOMERUN = 'hdhomerun';

    /**
     * IPTV tuner type (M3U playlist + XMLTV).
     *
     * @var string
     */
    public const TUNER_TYPE_IPTV = 'iptv';

    /**
     * Creates a new LiveTvManager instance.
     *
     * @param Connection $db Database connection for tuner/channel persistence
     * @param ChannelManager $channelManager Channel management handler
     * @param GuideManager $guideManager Program guide handler
     * @param Recorder $recorder DVR recording handler
     * @param TunerDriverInterface $tunerDriver Tuner driver for discovery and streaming
     * @param StructuredLogger|null $logger Optional logger, defaults to Livetv channel
     * @param array<string, TunerDriverInterface> $additionalDrivers Additional tuner drivers
     * @param SdEpgService|null $sdEpgService Optional Schedules Direct EPG service
     */
    public function __construct(
        Connection $db,
        ChannelManager $channelManager,
        GuideManager $guideManager,
        Recorder $recorder,
        TunerDriverInterface $tunerDriver,
        ?StructuredLogger $logger = null,
        array $additionalDrivers = [],
        ?SdEpgService $sdEpgService = null
    ) {
        $this->db = $db;
        $this->channelManager = $channelManager;
        $this->guideManager = $guideManager;
        $this->recorder = $recorder;
        $this->tunerDriver = $tunerDriver;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);
        $this->additionalDrivers = $additionalDrivers;
        $this->sdEpgService = $sdEpgService;
    }

    /**
     * Discover available tuners on the network/system.
     *
     * Uses the configured tuner driver to discover available tuner devices.
     * For HDHomeRun, this performs SSDP discovery on the local network.
     * For IPTV, discovers configured M3U playlist sources.
     *
     * @return array Discovered tuners keyed by tuner ID
     * @throws \RuntimeException If database operations fail
     *
     * @example
     * ```php
     * $tuners = $manager->discoverTuners();
     * foreach ($tuners as $tuner) {
     *     echo "Found: {$tuner['name']} ({$tuner['type']})\n";
     * }
     * ```
     * @return array<string, array{id:string, name:string, type:string, status:string, tuner_index:int, ip_address?:string, tuner_count:int, capabilities:array<string, mixed>, tunerDevice:HdHomeRunDevice|IptvDevice|DvbtDevice}> Discovered tuners keyed by tuner ID
     */
    public function discoverTuners(): array
    {
        $this->logger->info('Starting tuner discovery');

        /** @var array<string, array{id:string, name:string, type:string, status:string, tuner_index:int, ip_address?:string, tuner_count:int, capabilities:array<string, mixed>, tunerDevice:HdHomeRunDevice|IptvDevice|DvbtDevice}> $tuners */
        $tuners = [];

        // Discover HDHomeRun tuners (primary driver)
        if ($this->tunerDriver->getName() === 'hdhomerun') {
            $devices = $this->tunerDriver->discoverDevices();
            foreach ($devices as $index => $device) {
                if (!$device instanceof HdHomeRunDevice) {
                    continue;
                }
                $tunerId = 'hdhr_' . $device->deviceId;
                $tuner = [
                    'id' => $tunerId,
                    'name' => "HDHomeRun ({$device->deviceId})",
                    'type' => self::TUNER_TYPE_HDHOMERUN,
                    'status' => self::TUNER_STATUS_IDLE,
                    'tuner_index' => $index,
                    'ip_address' => $device->ipAddress,
                    'tuner_count' => $device->tunerCount,
                    'capabilities' => [
                        'hdhomerun' => true,
                        'tuner_count' => $device->tunerCount,
                        'stream_url_template' => $device->getBaseUrl() . '/watch?channel={channel}',
                    ],
                    'tunerDevice' => $device,
                ];

                $this->registerTuner($tuner);
                $tuners[$tunerId] = $tuner;
            }
        }

        // Discover additional tuner types (e.g., IPTV)
        foreach ($this->additionalDrivers as $driver) {
            if ($driver->getName() === 'iptv') {
                $devices = $driver->discoverDevices();
                foreach ($devices as $device) {
                    if (!$device instanceof IptvDevice) {
                        continue;
                    }
                    $tunerId = 'iptv_' . $device->sourceId;
                    $tuner = [
                        'id' => $tunerId,
                        'name' => $device->name,
                        'type' => self::TUNER_TYPE_IPTV,
                        'status' => self::TUNER_STATUS_IDLE,
                        'tuner_index' => 0,
                        'tuner_count' => 1,
                        'capabilities' => [
                            'iptv' => true,
                            'tuner_count' => 1,
                            'has_epg' => $device->hasEpd(),
                            'stream_url_template' => $device->playlistUrl,
                        ],
                        'tunerDevice' => $device,
                    ];

                    $this->registerTuner($tuner);
                    $tuners[$tunerId] = $tuner;
                }
            } elseif ($driver->getName() === 'dvbt') {
                $devices = $driver->discoverDevices();
                foreach ($devices as $device) {
                    if (!$device instanceof DvbtDevice) {
                        continue;
                    }
                    $tunerId = 'dvbt_' . $device->adapterIndex . '_' . $device->frontendIndex;
                    $tuner = [
                        'id' => $tunerId,
                        'name' => "DVB-T Adapter " . $device->adapterIndex . " Frontend " . $device->frontendIndex,
                        'type' => self::TUNER_TYPE_DVB_T,
                        'status' => self::TUNER_STATUS_IDLE,
                        'tuner_index' => $device->frontendIndex,
                        'tuner_count' => 1,
                        'capabilities' => [
                            'dvbt' => true,
                            'tuner_count' => 1,
                            'modulation' => $device->modulation,
                            'frequency_min' => $device->frequencyMin,
                            'frequency_max' => $device->frequencyMax,
                        ],
                        'tunerDevice' => $device,
                    ];

                    $this->registerTuner($tuner);
                    $tuners[$tunerId] = $tuner;
                }
            }
        }

        $this->tuners = $tuners;
        $this->logger->info('Tuner discovery complete', ['count' => count($tuners)]);

        return $tuners;
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
     * For HDHomeRun tuners, triggers a channel scan via the HTTP API.
     * For DVB tuners, iterates through configured frequencies.
     *
     * @param string $tunerId The tuner ID to use for scanning
     * @param array<string, mixed> $options Scan options including:
     *   - frequencies: int[] List of frequencies (Hz) to scan (DVB only)
     *   - symbol_rate: int Symbol rate for cable/satellite (DVB only)
     * @return array<int, array<string, mixed>> Scan results with discovered channels
     * @throws \InvalidArgumentException If tuner not found
     *
     * @example
     * ```php
     * $channels = $manager->scanChannels('hdhr_12345678', []);
     * ```
     */
    public function scanChannels(string $tunerId, array $options = []): array
    {
        $tuner = $this->getTuner($tunerId);
        if (!$tuner) {
            throw new \InvalidArgumentException("Tuner not found: $tunerId");
        }

        $this->updateTunerStatus($tunerId, self::TUNER_STATUS_SCANNING);
        $this->logger->info('Starting channel scan', ['tuner_id' => $tunerId, 'type' => $tuner['type']]);

        if ($tuner['type'] === self::TUNER_TYPE_HDHOMERUN && isset($tuner['tunerDevice'])) {
            // HDHomeRun: use the tuner driver to scan
            $channels = $this->performHdHomeRunChannelScan($tuner, $options);
        } elseif ($tuner['type'] === self::TUNER_TYPE_IPTV && isset($tuner['tunerDevice'])) {
            // IPTV: use the IPTV driver to scan
            $channels = $this->performIptvChannelScan($tuner, $options);
        } else {
            // DVB: use frequency scanning
            $channels = $this->performChannelScan($tuner, $options);
        }

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
     * Perform HDHomeRun channel scan using the tuner driver.
     *
     * Uses the HDHomeRun HTTP API to trigger a scan and retrieve the channel lineup.
     *
     * @param array<string, mixed> $tuner The HDHomeRun tuner
     * @param array<string, mixed> $options Scan options (unused for HDHomeRun)
     * @return array<int, array<string, mixed>> Discovered channels
     */
    private function performHdHomeRunChannelScan(array $tuner, array $options): array
    {
        $discoveredChannels = [];

        /** @var HdHomeRunDevice $device */
        $device = $tuner['tunerDevice'];

        $lineup = $this->tunerDriver->scanChannels($device);

        foreach ($lineup as $channelInfo) {
            $channel = $this->channelManager->createChannel([
                'name' => $channelInfo['name'] ?? 'Channel ' . $channelInfo['channel_number'],
                'number' => $channelInfo['channel_number'],
                'type' => $channelInfo['type'] ?? 'off',
                'frequency' => 0,
                'tuner_id' => $tuner['id'],
                'service_id' => $channelInfo['program_id'] ?? null,
            ]);

            if ($channel) {
                $discoveredChannels[] = $channel;
            }
        }

        return $discoveredChannels;
    }

    /**
     * Perform IPTV channel scan using the tuner driver.
     *
     * Uses the M3U parser to get channel lineup and optionally
     * fetches XMLTV data for programme information.
     *
     * @param array<string, mixed> $tuner The IPTV tuner
     * @param array<string, mixed> $options Scan options (unused for IPTV)
     * @return array<int, array<string, mixed>> Discovered channels
     */
    private function performIptvChannelScan(array $tuner, array $options): array
    {
        $discoveredChannels = [];

        /** @var IptvDevice $device */
        $device = $tuner['tunerDevice'];

        // Find the IPTV driver for this tuner
        $iptvDriver = null;
        foreach ($this->additionalDrivers as $driver) {
            if ($driver->getName() === 'iptv') {
                $iptvDriver = $driver;
                break;
            }
        }

        if ($iptvDriver === null) {
            $this->logger->warning('IptvTunerDriver not found for scan');
            return $discoveredChannels;
        }

        $lineup = $iptvDriver->scanChannels($device);

        foreach ($lineup as $channelInfo) {
            $channel = $this->channelManager->createChannel([
                'name' => $channelInfo['name'] ?? 'Channel ' . $channelInfo['channel_number'],
                'number' => $channelInfo['channel_number'],
                'type' => $channelInfo['type'] ?? 'off',
                'frequency' => 0,
                'tuner_id' => $tuner['id'],
                'service_id' => null,
            ]);

            if ($channel) {
                $discoveredChannels[] = $channel;
            }
        }

        return $discoveredChannels;
    }

    /**
     * Perform the actual channel scan on a DVB tuner.
     *
     * Iterates through frequencies and extracts service information
     * from the broadcast transport stream (PAT/SDT tables).
     *
     * @param array<string, mixed> $tuner The DVB tuner to use for scanning
     * @param array<string, mixed> $options Scan options including frequencies
     * @return array<int, array<string, mixed>> Discovered channels
     */
    private function performChannelScan(array $tuner, array $options): array
    {
        $discoveredChannels = [];

        $frequenciesRaw = $options['frequencies'] ?? [474000000, 498000000, 522000000, 570000000];
        $frequencies = is_array($frequenciesRaw) ? $frequenciesRaw : [];

        foreach ($frequencies as $frequencyRaw) {
            if (!is_int($frequencyRaw) && !(is_string($frequencyRaw) && is_numeric($frequencyRaw))) {
                continue;
            }
            $frequency = (int) $frequencyRaw;
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
     * Finds an available tuner, locks onto the channel,
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

        $resolvedTunerId = is_string($tuner['id'] ?? null) ? (string) $tuner['id'] : '';

        $this->updateTunerStatus($resolvedTunerId, self::TUNER_STATUS_TUNING);

        // Generate unique tune request ID
        $tuneRequestId = $this->generateUuid();

        // Build stream URL based on tuner type
        $channelNumber = is_numeric($channel['number'] ?? null) ? (int) $channel['number'] : 0;
        $streamUrl = $this->buildStreamUrl($tuner, $channelNumber);

        $this->activeTuneRequests[$tuneRequestId] = [
            'id' => $tuneRequestId,
            'channel_id' => $channelId,
            'tuner_id' => $resolvedTunerId,
            'started_at' => time(),
            'stream_url' => $streamUrl,
        ];

        $this->updateTunerStatus($resolvedTunerId, self::TUNER_STATUS_STREAMING);

        $this->logger->info('Tuned to channel', [
            'tune_request_id' => $tuneRequestId,
            'channel_id' => $channelId,
            'tuner_id' => $resolvedTunerId,
            'stream_url' => $streamUrl,
        ]);

        return $this->activeTuneRequests[$tuneRequestId];
    }

    /**
     * Build a stream URL for the given tuner and channel.
     *
     * @param array<string, mixed> $tuner The tuner to use
     * @param int $channelNumber The channel number to tune
     * @return string The stream URL
     */
    private function buildStreamUrl(array $tuner, int $channelNumber): string
    {
        if ($tuner['type'] === self::TUNER_TYPE_HDHOMERUN && isset($tuner['tunerDevice'])) {
            /** @var HdHomeRunDevice $device */
            $device = $tuner['tunerDevice'];
            return $this->tunerDriver->getStreamUrl($device, $channelNumber);
        }

        if ($tuner['type'] === self::TUNER_TYPE_IPTV && isset($tuner['tunerDevice'])) {
            /** @var IptvDevice $device */
            $device = $tuner['tunerDevice'];

            // Find the IPTV driver for this tuner
            foreach ($this->additionalDrivers as $driver) {
                if ($driver->getName() === 'iptv') {
                    return $driver->getStreamUrl($device, $channelNumber);
                }
            }
        }

        if ($tuner['type'] === self::TUNER_TYPE_DVB_T && isset($tuner['tunerDevice'])) {
            /** @var DvbtDevice $device */
            $device = $tuner['tunerDevice'];

            // Find the DVB-T driver for this tuner
            foreach ($this->additionalDrivers as $driver) {
                if ($driver->getName() === 'dvbt') {
                    return $driver->getStreamUrl($device, $channelNumber);
                }
            }
        }

        // DVB/other tuners use internal stream URL
        $tuneRequestId = $this->generateUuid();
        return "/livetv/$tuneRequestId/stream";
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
     * Bootstrap LiveTV runtime state.
     *
     * Called once when the worker process starts. Currently delegates to
     * {@see Recorder::resumeActiveRecordings()} to reconcile DVR state
     * after a process restart (mark stale `recording` rows failed,
     * re-arm due `scheduled` rows). Returns the recorder's recovery
     * statistics so callers can log or surface them.
     *
     * @return array{
     *     resumed: int,
     *     failed: int,
     *     rearmed: int,
     *     scheduled_skipped: int
     * } Recovery stats from the Recorder.
     *
     * @since Wave 2 (post-O.7)
     */
    public function bootstrap(): array
    {
        $stats = $this->recorder->resumeActiveRecordings();
        $this->logger->info('LiveTvManager bootstrap: recorder recovery complete', $stats);
        return $stats;
    }

    /**
     * Get the Schedules Direct EPG service instance.
     *
     * @return SdEpgService|null The SD EPG service or null if not configured
     */
    public function getSdEpgService(): ?SdEpgService
    {
        return $this->sdEpgService;
    }

    /**
     * Set the Schedules Direct configuration and optionally initialize the service.
     *
     * Call this after construction if SdEpgService was not injected via constructor.
     *
     * @param array<string, mixed> $sdConfig The schedules_direct section from config/livetv.php
     * @return void
     */
    public function setSdConfig(array $sdConfig): void
    {
        $this->sdConfig = $sdConfig;
    }

    /**
     * Sync EPG data from Schedules Direct.
     *
     * Uses the configured SdEpgService to fetch and import program data.
     *
     * @param int $daysAhead Number of days ahead to fetch (default: 14)
     * @return array{imported: int, errors: int} Import statistics
     * @throws \RuntimeException If SD EPG service is not configured
     */
    public function syncSdEpG(int $daysAhead = 14): array
    {
        if ($this->sdEpgService === null) {
            // Attempt to build from config if not injected
            if ($this->sdConfig === null) {
                throw new \RuntimeException('SD EPG not configured: call setSdConfig() first');
            }

            $this->sdEpgService = SdEpgServiceFactory::build(
                $this->sdConfig,
                $this->channelManager,
                $this->guideManager,
                $this->logger
            );
        }

        $this->logger->info('Starting SD EPG sync', ['days_ahead' => $daysAhead]);

        // Get all visible channels that have a tuner_id starting with 'sd_'
        $channels = $this->channelManager->getAllChannels();
        /** @var array<int, string> $stationIds */
        $stationIds = [];

        foreach ($channels as $channel) {
            $tunerIdRaw = $channel['tuner_id'] ?? '';
            $tunerId = is_string($tunerIdRaw) ? $tunerIdRaw : '';
            if (str_starts_with($tunerId, 'sd_')) {
                $stationIds[] = substr($tunerId, 3);
            }
        }

        if (empty($stationIds)) {
            $this->logger->warning('No SD channels found for EPG sync');
            return ['imported' => 0, 'errors' => 0];
        }

        return $this->sdEpgService->syncEpg($stationIds, $daysAhead);
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
