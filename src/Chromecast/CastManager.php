<?php

/**
 * Phlix media server component: Chromecast.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Chromecast;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Uuid;
use Phlix\Session\PlaybackController;

/**
 * Manages Chromecast sessions.
 *
 * Provides a facade for discovering devices and managing active
 * cast sessions. Maps device IDs to active CastSession instances.
 *
 * @since 0.12.0
 */
class CastManager
{
    /** @var CastDiscovery Device discovery service */
    private CastDiscovery $discovery;

    /** @var PlaybackController Phlix playback controller */
    private PlaybackController $playbackController;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var array<string, CastSession> Active sessions keyed by device ID */
    private array $sessions = [];

    /**
     * @param CastDiscovery $discovery Device discovery service
     * @param PlaybackController $playbackController Phlix playback controller
     * @param StructuredLogger|null $logger Optional logger instance
     *
     * @since 0.12.0
     */
    public function __construct(
        CastDiscovery $discovery,
        PlaybackController $playbackController,
        ?StructuredLogger $logger = null
    ) {
        $this->discovery = $discovery;
        $this->playbackController = $playbackController;
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * The shared MEDIA-channel logger — not a private one in a temp directory.
     *
     * The old body `mkdir()`ed a `sys_get_temp_dir()/phlix_cast_manager_<uniqid>`
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
     * Discover Chromecast devices on the network.
     *
     * @return CastDevice[] Array of discovered devices
     *
     * @since 0.12.0
     */
    public function discoverDevices(): array
    {
        $this->logger->info('Discovering Chromecast devices');

        $devices = $this->discovery->discoverDevices();

        $this->logger->info('Discovered {count} Chromecast devices', [
            'count' => count($devices),
        ]);

        return $devices;
    }

    /**
     * Start a cast session for a media item.
     *
     * Creates a new CastSession, launches the Default Media Receiver,
     * loads the media, and returns the active session.
     *
     * @param string $deviceId Target device ID
     * @param string $mediaUrl Media URL to cast
     * @param string $mimeType MIME content type
     * @param string $title Media title for display
     * @param int $duration Duration in seconds (0 if unknown)
     *
     * @return CastSession|null New session or null on failure
     *
     * @since 0.12.0
     */
    public function startSession(
        string $deviceId,
        string $mediaUrl,
        string $mimeType,
        string $title,
        int $duration
    ): ?CastSession {
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
            $this->logger->error('Device not found', ['device_id' => $deviceId]);
            return null;
        }

        // Stop existing session for this device
        if (isset($this->sessions[$deviceId])) {
            $this->stopSession($deviceId);
        }

        // Generate session ID
        $sessionId = $this->generateUuid();

        // Create CastApiClient for this device
        $client = new CastApiClient($device->host, $device->port, $this->logger);

        // Create session
        $session = new CastSession(
            $sessionId,
            $device,
            $client,
            $this->playbackController,
            $this->logger
        );

        // Launch app
        try {
            $session->launchApp();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to launch app on device', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // Load media
        try {
            $session->loadMedia($mediaUrl, $mimeType, $duration, $title);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load media on device', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // Store session
        $this->sessions[$deviceId] = $session;

        $this->logger->info('Cast session started', [
            'session_id' => $sessionId,
            'device_id' => $deviceId,
            'media_url' => $mediaUrl,
        ]);

        return $session;
    }

    /**
     * Get the active session for a device.
     *
     * @param string $deviceId Device ID
     *
     * @return CastSession|null Active session or null if none
     *
     * @since 0.12.0
     */
    public function getSession(string $deviceId): ?CastSession
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
        if (!isset($this->sessions[$deviceId])) {
            return;
        }

        $session = $this->sessions[$deviceId];

        try {
            $session->stop();
        } catch (\Throwable $e) {
            $this->logger->warning('Error stopping session', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
        }

        unset($this->sessions[$deviceId]);

        $this->logger->info('Cast session stopped', [
            'device_id' => $deviceId,
        ]);
    }

    /**
     * Get all active sessions.
     *
     * @return CastSession[] Array of active sessions
     *
     * @since 0.12.0
     */
    public function getActiveSessions(): array
    {
        return array_values($this->sessions);
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
