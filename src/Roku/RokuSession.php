<?php

declare(strict_types=1);

namespace Phlix\Roku;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Session\PlaybackController;
use Workerman\Timer;

/**
 * Active Roku "send to" session.
 *
 * Manages the full lifecycle of sending media to a Roku device:
 * - Launches the built-in MediaPlayer channel if needed
 * - Sends media for playback
 * - Controls playback (play, pause, stop) via ECP keypress commands
 * - Polls player state every 5 seconds via Workerman Timer
 * - Syncs position with local PlaybackController
 *
 * @since 0.12.0
 */
class RokuSession
{
    /** Session state: idle */
    public const STATE_IDLE = 'idle';

    /** Session state: launching channel */
    public const STATE_LAUNCHING = 'launching';

    /** Session state: playing */
    public const STATE_PLAYING = 'playing';

    /** Session state: paused */
    public const STATE_PAUSED = 'paused';

    /** Polling interval in seconds */
    private const POLL_INTERVAL = 5;

    /** @var string Unique session identifier */
    private string $sessionId;

    /** @var RokuDevice Target Roku device */
    private RokuDevice $device;

    /** @var RokuEcpClient HTTP ECP client */
    private RokuEcpClient $client;

    /** @var PlaybackController Phlix playback controller */
    private PlaybackController $playbackController;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var string Current session state */
    private string $state = self::STATE_IDLE;

    /** @var string|null Current media URL */
    private ?string $mediaUrl = null;

    /** @var int Current position in ticks */
    private int $positionTicks = 0;

    /** @var int|null Polling timer ID */
    private ?int $pollTimer = null;

    /**
     * @param string $sessionId Unique session identifier
     * @param RokuDevice $device Target Roku device
     * @param RokuEcpClient $client HTTP ECP client
     * @param PlaybackController $playbackController Phlix playback controller
     * @param StructuredLogger|null $logger Optional logger instance
     *
     * @since 0.12.0
     */
    public function __construct(
        string $sessionId,
        RokuDevice $device,
        RokuEcpClient $client,
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
        $tempDir = sys_get_temp_dir() . '/phlix_roku_session_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/roku_session.log',
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
     * Play media on the Roku device.
     *
     * Launches the built-in MediaPlayer channel first (if needed),
     * then sends the media URL and metadata for playback.
     *
     * @param string $mediaUrl Media URL to play
     * @param string $mimeType MIME content type
     * @param string $title Media title for display
     * @param string $thumbnail Thumbnail URL
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function playMedia(string $mediaUrl, string $mimeType, string $title, string $thumbnail): array
    {
        $this->mediaUrl = $mediaUrl;
        $this->state = self::STATE_LAUNCHING;

        $this->logger->info('Playing media on Roku', [
            'session_id' => $this->sessionId,
            'device_id' => $this->device->deviceId,
            'media_url' => $mediaUrl,
            'mime_type' => $mimeType,
        ]);

        try {
            // Play media - this launches MediaPlayer and sends the media
            $result = $this->client->playMedia($mediaUrl, $mimeType, $title, $thumbnail);

            $this->state = self::STATE_PLAYING;
            $this->startPolling();

            $this->logger->info('Media playing on Roku', [
                'session_id' => $this->sessionId,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to play media on Roku', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            $this->state = self::STATE_IDLE;
            throw $e;
        }
    }

    /**
     * Send a keypress command.
     *
     * @param string $key Key name to send
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function sendKey(string $key): array
    {
        $this->logger->info('Sending key to Roku', [
            'session_id' => $this->sessionId,
            'key' => $key,
        ]);

        try {
            return $this->client->sendKeypress($key);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send key to Roku', [
                'session_id' => $this->sessionId,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Pause playback.
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function pause(): array
    {
        $this->logger->info('Pausing playback', [
            'session_id' => $this->sessionId,
        ]);

        $result = $this->sendKey('Pause');

        $this->state = self::STATE_PAUSED;
        $this->stopPolling();

        return $result;
    }

    /**
     * Resume playback.
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function play(): array
    {
        $this->logger->info('Resuming playback', [
            'session_id' => $this->sessionId,
        ]);

        $result = $this->sendKey('Play');

        $this->state = self::STATE_PLAYING;
        $this->startPolling();

        return $result;
    }

    /**
     * Stop playback.
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function stop(): array
    {
        $this->logger->info('Stopping playback', [
            'session_id' => $this->sessionId,
        ]);

        try {
            $result = $this->sendKey('Back');

            $this->state = self::STATE_IDLE;
            $this->stopPolling();
            $this->positionTicks = 0;
            $this->mediaUrl = null;

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to stop playback', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get the current player state from the device.
     *
     * @return array<string, mixed> Player state
     *
     * @since 0.12.0
     */
    public function getPlayerState(): array
    {
        try {
            $state = $this->client->getPlayerState();

            // Update internal position if available
            if (isset($state['position'])) {
                // Position is in seconds, convert to ticks (1 tick = 100 nanoseconds)
                $position = is_numeric($state['position']) ? (int)$state['position'] : 0;
                $this->positionTicks = $position * 10000000;
                $this->reportProgress();
            }

            // Update state from player
            if (isset($state['state'])) {
                $playerState = is_string($state['state']) ? $state['state'] : 'Unknown';
                $this->updateStateFromPlayerState($playerState);
            }

            return $state;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get player state', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get current session state.
     *
     * @return string Current state (idle, launching, playing, paused)
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
     * @return RokuDevice Target device
     *
     * @since 0.12.0
     */
    public function getDevice(): RokuDevice
    {
        return $this->device;
    }

    /**
     * Update internal state from player state string.
     *
     * @param string $playerState Player state from ECP
     *
     * @return void
     */
    private function updateStateFromPlayerState(string $playerState): void
    {
        $newState = match (strtolower($playerState)) {
            'play' => self::STATE_PLAYING,
            'pause' => self::STATE_PAUSED,
            'stop', 'idle' => self::STATE_IDLE,
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
     * Start polling player state.
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
                $this->getPlayerState();
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
     * Stop polling player state.
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

        try {
            $this->playbackController->reportProgress(
                $this->sessionId,
                '',
                $this->positionTicks,
                0, // Duration unknown from player state
                $this->state === self::STATE_PAUSED
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to report progress', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
