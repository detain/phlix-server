<?php

/**
 * Phlix media server component: Roku.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Roku;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Uuid;
use Phlix\Session\PlaybackController;

/**
 * Manages Roku sessions.
 *
 * Provides a facade for discovering devices and managing active
 * "send to Roku" sessions. Maps device IDs to active RokuSession instances.
 *
 * @since 0.12.0
 */
class RokuManager
{
    /** @var RokuDiscovery Device discovery service */
    private RokuDiscovery $discovery;

    /** @var PlaybackController Phlix playback controller */
    private PlaybackController $playbackController;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var array<string, RokuSession> Active sessions keyed by device ID */
    private array $sessions = [];

    /**
     * @param RokuDiscovery $discovery Device discovery service
     * @param PlaybackController $playbackController Phlix playback controller
     * @param StructuredLogger|null $logger Optional logger instance
     *
     * @since 0.12.0
     */
    public function __construct(
        RokuDiscovery $discovery,
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
     * The old body `mkdir()`ed a `sys_get_temp_dir()/phlix_roku_manager_<uniqid>`
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
     * Discover Roku devices on the network.
     *
     * @return RokuDevice[] Array of discovered devices
     *
     * @since 0.12.0
     */
    public function discoverDevices(): array
    {
        $this->logger->info('Discovering Roku devices');

        $devices = $this->discovery->discoverDevices();

        $this->logger->info('Discovered {count} Roku devices', [
            'count' => count($devices),
        ]);

        return $devices;
    }

    /**
     * Start a "send to Roku" session for a media item.
     *
     * Creates a new RokuSession, discovers the device, creates ECP client,
     * and launches media playback.
     *
     * @param string $deviceId Target device ID
     * @param string $mediaUrl Media URL to send
     * @param string $mimeType MIME content type
     * @param string $title Media title for display
     * @param string $thumbnail Thumbnail URL
     *
     * @return RokuSession|null New session or null on failure
     *
     * @since 0.12.0
     */
    public function startSession(
        string $deviceId,
        string $mediaUrl,
        string $mimeType,
        string $title,
        string $thumbnail
    ): ?RokuSession {
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

        // Create ECP client for this device
        $client = new RokuEcpClient($device->host, $device->port, $this->logger);

        // Create session
        $session = new RokuSession(
            $sessionId,
            $device,
            $client,
            $this->playbackController,
            $this->logger
        );

        // Play media
        try {
            $session->playMedia($mediaUrl, $mimeType, $title, $thumbnail);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to play media on device', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // Store session
        $this->sessions[$deviceId] = $session;

        $this->logger->info('Roku session started', [
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
     * @return RokuSession|null Active session or null if none
     *
     * @since 0.12.0
     */
    public function getSession(string $deviceId): ?RokuSession
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

        $this->logger->info('Roku session stopped', [
            'device_id' => $deviceId,
        ]);
    }

    /**
     * Get all active sessions.
     *
     * @return RokuSession[] Array of active sessions
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
