<?php

declare(strict_types=1);

namespace Phlix\Chromecast;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Session\PlaybackController;
use Workerman\Timer;

/**
 * Active Chromecast session.
 *
 * Manages the lifecycle of casting to a Chromecast device:
 * - Launches the Default Media Receiver app
 * - Loads and controls media playback
 * - Polls position via getMediaStatus() every 5 seconds
 * - Syncs position with PlaybackController
 *
 * @since 0.12.0
 */
class CastSession
{
    /** Session state: idle */
    public const STATE_IDLE = 'idle';

    /** Session state: app launching */
    public const STATE_APP_LAUNCHING = 'app_launching';

    /** Session state: app running */
    public const STATE_APP_RUNNING = 'app_running';

    /** Session state: playing */
    public const STATE_PLAYING = 'playing';

    /** Session state: paused */
    public const STATE_PAUSED = 'paused';

    /** Session state: buffering */
    public const STATE_BUFFERING = 'buffering';

    /** Polling interval in seconds */
    private const POLL_INTERVAL = 5;

    /** @var string Unique session identifier */
    private string $sessionId;

    /** @var CastDevice Target Chromecast device */
    private CastDevice $device;

    /** @var CastApiClient HTTP client for device communication */
    private CastApiClient $client;

    /** @var PlaybackController Phlix playback controller */
    private PlaybackController $playbackController;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var string Current session state */
    private string $state = self::STATE_IDLE;

    /** @var string|null Current media URL */
    private ?string $mediaUrl = null;

    /** @var int Current position in milliseconds */
    private int $positionMs = 0;

    /** @var int|null Polling timer ID */
    private ?int $pollTimer = null;

    /**
     * @param string $sessionId Unique session identifier
     * @param CastDevice $device Chromecast device
     * @param CastApiClient $client HTTP client for Cast protocol
     * @param PlaybackController $playbackController Phlix playback controller
     * @param StructuredLogger|null $logger Optional logger instance
     *
     * @since 0.12.0
     */
    public function __construct(
        string $sessionId,
        CastDevice $device,
        CastApiClient $client,
        PlaybackController $playbackController,
        ?StructuredLogger $logger = null
    ) {
        $this->sessionId = $sessionId;
        $this->device = $device;
        $this->client = $client;
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
        $tempDir = sys_get_temp_dir() . '/phlix_cast_session_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/cast_session.log',
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
     * Launch the Default Media Receiver app.
     *
     * @return array<string, mixed> Launch response with transport ID
     *
     * @since 0.12.0
     */
    public function launchApp(): array
    {
        $this->state = self::STATE_APP_LAUNCHING;

        $this->logger->info('Launching Default Media Receiver', [
            'session_id' => $this->sessionId,
            'device_id' => $this->device->deviceId,
        ]);

        try {
            $result = $this->client->launchApp(CastApiClient::APP_ID_DEFAULT);

            $this->state = self::STATE_APP_RUNNING;

            $this->logger->info('App launched successfully', [
                'session_id' => $this->sessionId,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to launch app', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            $this->state = self::STATE_IDLE;
            throw $e;
        }
    }

    /**
     * Load and play a media item (HLS or MP3).
     *
     * @param string $mediaUrl Media URL to cast
     * @param string $mimeType MIME content type
     * @param int $duration Duration in seconds (0 if unknown)
     * @param string $title Media title for display
     * @param string $thumbnail Thumbnail URL
     *
     * @return array<string, mixed> Load response
     *
     * @since 0.12.0
     */
    public function loadMedia(
        string $mediaUrl,
        string $mimeType,
        int $duration = 0,
        string $title = '',
        string $thumbnail = ''
    ): array {
        $this->mediaUrl = $mediaUrl;
        $this->state = self::STATE_BUFFERING;

        $metadata = [];
        if ($title !== '') {
            $metadata['title'] = $title;
        }
        if ($thumbnail !== '') {
            $metadata['thumb'] = $thumbnail;
        }

        $this->logger->info('Loading media on Chromecast', [
            'session_id' => $this->sessionId,
            'media_url' => $mediaUrl,
            'mime_type' => $mimeType,
        ]);

        try {
            $result = $this->client->loadMedia($mediaUrl, $mimeType, $metadata);

            $this->state = self::STATE_PLAYING;
            $this->startPolling();

            $this->logger->info('Media loaded and playing', [
                'session_id' => $this->sessionId,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load media', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            $this->state = self::STATE_IDLE;
            throw $e;
        }
    }

    /**
     * Resume playback.
     *
     * @return array<string, mixed> Command response
     *
     * @since 0.12.0
     */
    public function play(): array
    {
        $this->logger->info('Sending play command', [
            'session_id' => $this->sessionId,
        ]);

        try {
            $result = $this->client->sendMediaCommand('PLAY');

            $this->state = self::STATE_PLAYING;
            $this->startPolling();

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to play', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Pause playback.
     *
     * @return array<string, mixed> Command response
     *
     * @since 0.12.0
     */
    public function pause(): array
    {
        $this->logger->info('Sending pause command', [
            'session_id' => $this->sessionId,
        ]);

        try {
            $result = $this->client->sendMediaCommand('PAUSE');

            $this->state = self::STATE_PAUSED;
            $this->stopPolling();

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to pause', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Stop playback.
     *
     * @return array<string, mixed> Command response
     *
     * @since 0.12.0
     */
    public function stop(): array
    {
        $this->logger->info('Sending stop command', [
            'session_id' => $this->sessionId,
        ]);

        try {
            $result = $this->client->sendMediaCommand('STOP');

            $this->state = self::STATE_IDLE;
            $this->stopPolling();
            $this->positionMs = 0;
            $this->mediaUrl = null;

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to stop', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Seek to position in milliseconds.
     *
     * @param int $positionMs Position in milliseconds
     *
     * @return array<string, mixed> Command response
     *
     * @since 0.12.0
     */
    public function seek(int $positionMs): array
    {
        $this->positionMs = $positionMs;

        $this->logger->info('Sending seek command', [
            'session_id' => $this->sessionId,
            'position_ms' => $positionMs,
        ]);

        try {
            // Convert milliseconds to seconds for Chromecast
            $positionSec = (int)($positionMs / 1000);
            $result = $this->client->sendMediaCommand('SEEK', ['currentTime' => $positionSec]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to seek', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get current media status.
     *
     * @return array<string, mixed> Media status response
     *
     * @since 0.12.0
     */
    public function getMediaStatus(): array
    {
        try {
            $status = $this->client->getMediaStatus();

            // Parse currentTime (seconds) and playerState
            if (isset($status['status']) && is_array($status['status'])) {
                $statusArray = $status['status'];
                $statusItem = $statusArray[0] ?? $statusArray;

                if (is_array($statusItem)) {
                    // Extract position in milliseconds
                    if (isset($statusItem['currentTime']) && is_numeric($statusItem['currentTime'])) {
                        $this->positionMs = (int)(((float)$statusItem['currentTime']) * 1000);
                    }

                    // Update state from playerState
                    $playerStateRaw = $statusItem['playerState'] ?? 'UNKNOWN';
                    $playerState = is_string($playerStateRaw) ? $playerStateRaw : 'UNKNOWN';
                    $this->updateStateFromPlayerState($playerState);

                    // Report progress to playback controller
                    $this->reportProgress();
                }
            }

            return $status;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get media status', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get current session state.
     *
     * @return string Current state (idle, app_launching, app_running, playing, paused, buffering)
     *
     * @since 0.12.0
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Get session ID.
     *
     * @return string Session identifier
     *
     * @since 0.12.0
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Get target device.
     *
     * @return CastDevice Target device
     *
     * @since 0.12.0
     */
    public function getDevice(): CastDevice
    {
        return $this->device;
    }

    /**
     * Update internal state from Chromecast playerState string.
     *
     * @param string $playerState Chromecast playerState value
     *
     * @return void
     */
    private function updateStateFromPlayerState(string $playerState): void
    {
        $newState = match ($playerState) {
            'PLAYING' => self::STATE_PLAYING,
            'PAUSED' => self::STATE_PAUSED,
            'BUFFERING' => self::STATE_BUFFERING,
            'IDLE' => self::STATE_IDLE,
            default => $this->state,
        };

        if ($newState !== $this->state) {
            $this->state = $newState;
            $this->logger->debug('Session state changed', [
                'session_id' => $this->sessionId,
                'state' => $this->state,
            ]);
        }
    }

    /**
     * Start polling media status.
     *
     * @return void
     */
    private function startPolling(): void
    {
        if ($this->pollTimer !== null) {
            return;
        }

        try {
            $this->pollTimer = Timer::add(self::POLL_INTERVAL, function (): void {
                $this->getMediaStatus();
            });

            $this->logger->debug('Started position polling', [
                'session_id' => $this->sessionId,
                'interval' => self::POLL_INTERVAL,
            ]);
        } catch (\Throwable $e) {
            // Timer not available (not in Workerman environment)
            $this->logger->debug('Position polling not available', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stop polling media status.
     *
     * @return void
     */
    private function stopPolling(): void
    {
        if ($this->pollTimer === null) {
            return;
        }

        try {
            Timer::del($this->pollTimer);
        } catch (\Throwable $e) {
            // Ignore timer errors when not in Workerman environment
        }
        $this->pollTimer = null;

        $this->logger->debug('Stopped position polling', [
            'session_id' => $this->sessionId,
        ]);
    }

    /**
     * Report progress to PlaybackController.
     *
     * @return void
     */
    private function reportProgress(): void
    {
        if ($this->mediaUrl === null) {
            return;
        }

        // Convert milliseconds to ticks (1 tick = 100 nanoseconds)
        $positionTicks = $this->positionMs * 10000;

        try {
            $this->playbackController->reportProgress(
                $this->sessionId,
                '', // itemId not available in Chromecast sessions
                $positionTicks,
                0, // Duration unknown from position info
                $this->state === self::STATE_PAUSED
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to report progress', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
