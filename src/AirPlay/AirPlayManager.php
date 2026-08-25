<?php

/**
 * Phlix media server component: AirPlay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\AirPlay;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Uuid;

/**
 * Manages AirPlay sessions for streaming audio to AirPlay 2 devices.
 *
 * Coordinates device discovery, session creation, and lifecycle management.
 * Maintains a map of active sessions per device ID.
 *
 * @since 0.12.0
 */
class AirPlayManager
{
    /** @var AirPlayDiscovery Discovery service */
    private AirPlayDiscovery $discovery;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var array<string, AirPlaySession> Active sessions by device ID */
    private array $sessions = [];

    /**
     * @param AirPlayDiscovery  $discovery  Discovery service
     * @param StructuredLogger|null $logger Optional logger
     */
    public function __construct(
        AirPlayDiscovery $discovery,
        ?StructuredLogger $logger = null,
    ) {
        $this->discovery = $discovery;
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * The shared MEDIA-channel logger — not a private one in a temp directory.
     *
     * The old body `mkdir()`ed a `sys_get_temp_dir()/phlix_airplay_manager_<uniqid>`
     * directory on every construction and pointed a private `StructuredLogger`
     * at a log file inside it — a per-instance leak that survived for the life
     * of the worker. `LoggerFactory::get()` returns one cached instance per
     * channel, so the whole family shares a single logger.
     *
     * @return StructuredLogger The shared MEDIA channel logger, routed by
     *         `config/logger.php` to `.logs/app.log` and `.logs/error.log` —
     *         an install-dir destination that creates no directory.
     */
    private function createDefaultLogger(): StructuredLogger
    {
        return LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Discover AirPlay devices on the network.
     *
     * @return AirPlayDevice[] Array of discovered devices
     *
     * @since 0.12.0
     */
    public function discoverDevices(): array
    {
        $this->logger->debug('AirPlayManager: Discovering devices');

        $devices = $this->discovery->discoverDevices();

        $this->logger->info('AirPlayManager: Discovered devices', [
            'count' => count($devices),
        ]);

        return $devices;
    }

    /**
     * Start an AirPlay session for audio streaming.
     *
     * Creates a new session, starts streaming to the device, and begins
     * polling for position updates (if in a Workerman environment).
     *
     * @param string $deviceId    Target device ID
     * @param string $audioUrl   Audio stream URL
     * @param string $contentType MIME type (default: 'audio/mp4')
     * @param int    $duration   Content duration in seconds (0 if unknown)
     *
     * @return AirPlaySession|null New session, or null if device not found
     *
     * @since 0.12.0
     */
    public function startSession(
        string $deviceId,
        string $audioUrl,
        string $contentType = 'audio/mp4',
        int $duration = 0,
    ): ?AirPlaySession {
        // Find the device
        $devices = $this->discovery->discoverDevices();
        $device = null;
        foreach ($devices as $d) {
            if ($d->deviceId === $deviceId) {
                $device = $d;
                break;
            }
        }

        if ($device === null) {
            $this->logger->warning('AirPlayManager: Device not found', [
                'device_id' => $deviceId,
            ]);
            return null;
        }

        // Check for existing session
        if (isset($this->sessions[$deviceId])) {
            $this->logger->info('AirPlayManager: Stopping existing session', [
                'device_id' => $deviceId,
            ]);
            $this->stopSession($deviceId);
        }

        // Generate session ID
        $sessionId = $this->generateUuid();

        // Create RAOP client and session
        $raopClient = new RaopClient($device->host, $device->raopPort, $this->logger);
        $session = new AirPlaySession(
            $sessionId,
            $device,
            $raopClient,
            $this->logger,
        );

        // Start streaming
        $session->startStream($audioUrl, $contentType, $duration);

        // Store session
        $this->sessions[$deviceId] = $session;

        $this->logger->info('AirPlayManager: Session started', [
            'session_id' => $sessionId,
            'device_id' => $deviceId,
        ]);

        return $session;
    }

    /**
     * Get the active session for a device.
     *
     * @param string $deviceId Device ID
     *
     * @return AirPlaySession|null Active session or null if none
     *
     * @since 0.12.0
     */
    public function getSession(string $deviceId): ?AirPlaySession
    {
        return $this->sessions[$deviceId] ?? null;
    }

    /**
     * Stop and remove a session.
     *
     * @param string $deviceId Device ID
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function stopSession(string $deviceId): void
    {
        $session = $this->sessions[$deviceId] ?? null;
        if ($session === null) {
            return;
        }

        try {
            $session->stop();
        } catch (\Throwable $e) {
            $this->logger->warning('AirPlayManager: Error stopping session', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
        }

        unset($this->sessions[$deviceId]);

        $this->logger->info('AirPlayManager: Session stopped', [
            'device_id' => $deviceId,
        ]);
    }

    /**
     * Get all active sessions.
     *
     * @return array<string, AirPlaySession> Active sessions keyed by device ID
     *
     * @since 0.12.0
     */
    public function getActiveSessions(): array
    {
        return $this->sessions;
    }

    /**
     * Generate a UUID v4 string.
     *
     * @return string UUID
     */
    private function generateUuid(): string
    {
        return Uuid::v4();
    }
}
