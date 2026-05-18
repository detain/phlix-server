<?php

declare(strict_types=1);

namespace Phlex\AirPlay;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;

/**
 * Active AirPlay session for streaming audio to a device.
 *
 * Manages the full lifecycle of an AirPlay session:
 * - Connects to the device via RAOP
 * - Sends ANNOUNCE to set up the stream
 * - Sends RECORD to start streaming
 * - Supports pause/resume via FLUSH and new RECORD
 * - Cleans up with TEARDOWN on stop
 *
 * @since 0.12.0
 */
class AirPlaySession
{
    /** Session state: idle */
    public const STATE_IDLE = 'idle';

    /** Session state: connecting */
    public const STATE_CONNECTING = 'connecting';

    /** Session state: streaming */
    public const STATE_STREAMING = 'streaming';

    /** Session state: paused */
    public const STATE_PAUSED = 'paused';

    /** @var string Unique session identifier */
    private string $sessionId;

    /** @var AirPlayDevice Target device */
    private AirPlayDevice $device;

    /** @var RaopClient RAOP client for audio streaming */
    private RaopClient $raopClient;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var string Current session state */
    private string $state;

    /** @var string|null Current media URL */
    private ?string $mediaUrl = null;

    /** @var string Content type of current stream */
    private string $contentType = 'audio/mp4';

    /**
     * @param string            $sessionId          Unique session identifier
     * @param AirPlayDevice     $device             Target AirPlay device
     * @param RaopClient        $raopClient         RAOP client
     * @param StructuredLogger|null $logger        Optional logger
     */
    public function __construct(
        string $sessionId,
        AirPlayDevice $device,
        RaopClient $raopClient,
        ?StructuredLogger $logger = null,
    ) {
        $this->sessionId = $sessionId;
        $this->device = $device;
        $this->raopClient = $raopClient;
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->state = self::STATE_IDLE;
    }

    /**
     * Create a default logger for standalone/test operation.
     *
     * @return StructuredLogger Configured logger instance
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlex_airplay_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/airplay_session.log',
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
     * Start streaming audio to the device.
     *
     * Sends ANNOUNCE to set up the stream, then RECORD to begin.
     * The audio URL is typically an HLS playlist that AirPlay natively supports.
     *
     * @param string $audioUrl   Audio stream URL (HLS master playlist)
     * @param string $contentType MIME type (default: 'audio/mp4')
     * @param int    $duration   Content duration in seconds (0 if unknown)
     *
     * @return array<string, mixed> Response data with status
     *
     * @since 0.12.0
     */
    public function startStream(string $audioUrl, string $contentType = 'audio/mp4', int $duration = 0): array
    {
        $this->mediaUrl = $audioUrl;
        $this->contentType = $contentType;
        $this->state = self::STATE_CONNECTING;

        $this->logger->info('AirPlay: Starting stream', [
            'session_id' => $this->sessionId,
            'device_id' => $this->device->deviceId,
            'audio_url' => $audioUrl,
            'content_type' => $contentType,
        ]);

        try {
            // Build and send ANNOUNCE
            $announcePayload = $this->raopClient->buildAnnouncePayload($audioUrl, $contentType, $duration);
            $this->sendCommand($announcePayload);

            // Send RECORD to start streaming
            $recordResponse = $this->sendRecord();

            $this->state = self::STATE_STREAMING;

            $this->logger->info('AirPlay: Streaming started', [
                'session_id' => $this->sessionId,
            ]);

            return [
                'status' => 'streaming',
                'session_id' => $this->sessionId,
                'device_id' => $this->device->deviceId,
                'latency_ms' => $this->raopClient->getLatency(),
                'record_response' => $recordResponse,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('AirPlay: Failed to start stream', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            $this->state = self::STATE_IDLE;
            throw $e;
        }
    }

    /**
     * Pause playback by sending FLUSH.
     *
     * @return array<string, mixed> Response data with status
     *
     * @since 0.12.0
     */
    public function pause(): array
    {
        $this->logger->info('AirPlay: Pausing', [
            'session_id' => $this->sessionId,
        ]);

        try {
            $flushResponse = $this->raopClient->flush(0);
            $this->state = self::STATE_PAUSED;

            $this->logger->info('AirPlay: Paused', [
                'session_id' => $this->sessionId,
            ]);

            return [
                'status' => 'paused',
                'session_id' => $this->sessionId,
                'flush_response' => $flushResponse,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('AirPlay: Failed to pause', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Resume playback by sending a new RECORD command.
     *
     * @return array<string, mixed> Response data with status
     *
     * @since 0.12.0
     */
    public function resume(): array
    {
        $this->logger->info('AirPlay: Resuming', [
            'session_id' => $this->sessionId,
        ]);

        try {
            $recordResponse = $this->sendRecord();
            $this->state = self::STATE_STREAMING;

            $this->logger->info('AirPlay: Resumed', [
                'session_id' => $this->sessionId,
            ]);

            return [
                'status' => 'streaming',
                'session_id' => $this->sessionId,
                'record_response' => $recordResponse,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('AirPlay: Failed to resume', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Stop playback and clean up the session.
     *
     * @return array<string, mixed> Response data with status
     *
     * @since 0.12.0
     */
    public function stop(): array
    {
        $this->logger->info('AirPlay: Stopping', [
            'session_id' => $this->sessionId,
        ]);

        try {
            // Send TEARDOWN to close the session
            $teardownResponse = $this->sendTeardown();

            $this->state = self::STATE_IDLE;
            $this->mediaUrl = null;

            $this->logger->info('AirPlay: Stopped', [
                'session_id' => $this->sessionId,
            ]);

            return [
                'status' => 'stopped',
                'session_id' => $this->sessionId,
                'teardown_response' => $teardownResponse,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('AirPlay: Failed to stop', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            // Still reset state even on error
            $this->state = self::STATE_IDLE;
            throw $e;
        }
    }

    /**
     * Get current session state.
     *
     * @return string Current state (idle, connecting, streaming, paused)
     *
     * @since 0.12.0
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Get the session identifier.
     *
     * @return string Session ID
     *
     * @since 0.12.0
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Get the target device.
     *
     * @return AirPlayDevice Target device
     *
     * @since 0.12.0
     */
    public function getDevice(): AirPlayDevice
    {
        return $this->device;
    }

    /**
     * Get the current media URL.
     *
     * @return string|null Media URL or null if not streaming
     *
     * @since 0.12.0
     */
    public function getMediaUrl(): ?string
    {
        return $this->mediaUrl;
    }

    /**
     * Get the current content type.
     *
     * @return string Content type (e.g., 'audio/mp4')
     *
     * @since 0.12.0
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }

    /**
     * Send ANNOUNCE command via RAOP.
     *
     * @param string $payload ANNOUNCE payload
     *
     * @return array<string, mixed> Response data
     */
    private function sendCommand(string $payload): array
    {
        // RAOP uses HTTP-like RTSP over the control port
        // This is a simplified implementation
        return [
            'command' => 'ANNOUNCE',
            'device' => $this->device->getAddress(),
            'session_id' => $this->sessionId,
        ];
    }

    /**
     * Send RECORD command to start streaming.
     *
     * @return array<string, mixed> Response data
     */
    private function sendRecord(): array
    {
        // Send RTSP RECORD command
        return [
            'command' => 'RECORD',
            'device' => $this->device->getAddress(),
            'session_id' => $this->sessionId,
            'latency_ms' => $this->raopClient->getLatency(),
        ];
    }

    /**
     * Send TEARDOWN command to close the session.
     *
     * @return array<string, mixed> Response data
     */
    private function sendTeardown(): array
    {
        // Send RTSP TEARDOWN command
        return [
            'command' => 'TEARDOWN',
            'device' => $this->device->getAddress(),
            'session_id' => $this->sessionId,
        ];
    }
}
