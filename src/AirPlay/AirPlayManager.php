<?php

declare(strict_types=1);

namespace Phlex\AirPlay;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;

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
     * Create a default logger for standalone/test operation.
     *
     * @return StructuredLogger Configured logger instance
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlex_airplay_manager_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/airplay_manager.log',
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
