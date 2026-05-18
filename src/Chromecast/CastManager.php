<?php

declare(strict_types=1);

namespace Phlex\Chromecast;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Session\PlaybackController;

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

    /** @var PlaybackController Phlex playback controller */
    private PlaybackController $playbackController;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var array<string, CastSession> Active sessions keyed by device ID */
    private array $sessions = [];

    /**
     * @param CastDiscovery $discovery Device discovery service
     * @param PlaybackController $playbackController Phlex playback controller
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
     * Create a default logger for standalone/test operation.
     *
     * @return StructuredLogger Configured logger instance
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlex_cast_manager_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/cast_manager.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ];

        return new StructuredLogger(LogChannels::MEDIA, $config);
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
