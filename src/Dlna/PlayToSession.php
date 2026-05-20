<?php

declare(strict_types=1);

namespace Phlix\Dlna;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Session\PlaybackController;
use Workerman\Timer;

/**
 * Active "play to" session with a remote DLNA renderer.
 *
 * Manages the full lifecycle of sending media to a DLNA renderer:
 * - Sets the media URI via SetAVTransportURI
 * - Controls playback (play, pause, stop, seek)
 * - Polls renderer position via getPositionInfo() every 5 seconds
 * - Syncs position with local PlaybackController
 *
 * Uses Workerman Timer for background polling while session is active.
 *
 * @since 0.12.0
 */
class PlayToSession
{
    /** Session state: idle (no media set) */
    public const STATE_IDLE = 'idle';

    /** Session state: buffering (transitioning to playing) */
    public const STATE_BUFFERING = 'buffering';

    /** Session state: playing */
    public const STATE_PLAYING = 'playing';

    /** Session state: paused */
    public const STATE_PAUSED = 'paused';

    /** Session state: stopped */
    public const STATE_STOPPED = 'stopped';

    /** Polling interval in seconds */
    private const POLL_INTERVAL = 5;

    /** @var string Unique session identifier */
    private string $sessionId;

    /** @var string Renderer identifier (UDN) */
    private string $rendererId;

    /** @var string Renderer friendly name */
    private string $rendererName;

    /** @var RendererControlClient HTTP SOAP client for renderer control */
    private RendererControlClient $client;

    /** @var PlaybackController Phlix playback controller for position sync */
    private PlaybackController $playbackController;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var string Current session state */
    private string $state = self::STATE_IDLE;

    /** @var string|null Current media item ID */
    private ?string $itemId = null;

    /** @var string|null Current media URI */
    private ?string $uri = null;

    /** @var int Current position in ticks */
    private int $position = 0;

    /** @var int|null Polling timer ID */
    private ?int $pollTimer = null;

    /** @var callable|null State change callback */
    private $onStateChange = null;

    /**
     * @param string $sessionId Unique session identifier
     * @param string $rendererId Renderer identifier (UDN)
     * @param string $rendererName Renderer friendly name
     * @param RendererControlClient $client HTTP SOAP client for renderer control
     * @param PlaybackController $playbackController Phlix playback controller
     * @param StructuredLogger|null $logger Optional logger instance
     *
     * @since 0.12.0
     */
    public function __construct(
        string $sessionId,
        string $rendererId,
        string $rendererName,
        RendererControlClient $client,
        PlaybackController $playbackController,
        ?StructuredLogger $logger = null
    ) {
        $this->sessionId = $sessionId;
        $this->rendererId = $rendererId;
        $this->rendererName = $rendererName;
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
        $tempDir = sys_get_temp_dir() . '/phlix_dlna_play_to_session_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/play_to_session.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ];

        return new StructuredLogger(LogChannels::DLNA, $config);
    }

    /**
     * Set the media item to play on the renderer.
     *
     * @param string $itemId Media item ID
     * @param string $uri Media URI (HLS URL)
     * @param string $metadata DIDL-Lite metadata
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function setMediaItem(string $itemId, string $uri, string $metadata): void
    {
        $this->itemId = $itemId;
        $this->uri = $uri;
        $this->position = 0;

        $this->logger->info('Setting media item on renderer', [
            'session_id' => $this->sessionId,
            'renderer_id' => $this->rendererId,
            'item_id' => $itemId,
            'uri' => $uri,
        ]);

        $result = $this->client->setAvTransportUri($uri, $metadata);

        if (isset($result['Error'])) {
            $this->logger->error('Failed to set media URI', [
                'error' => $result['Error'],
            ]);
            $this->setState(self::STATE_IDLE);
            return;
        }

        $this->setState(self::STATE_BUFFERING);
    }

    /**
     * Start playback.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function play(): void
    {
        $this->logger->info('Starting playback', [
            'session_id' => $this->sessionId,
            'renderer_id' => $this->rendererId,
        ]);

        $result = $this->client->play();

        if (isset($result['Error'])) {
            $this->logger->error('Failed to start playback', [
                'error' => $result['Error'],
            ]);
            return;
        }

        $this->setState(self::STATE_PLAYING);
        $this->startPolling();
    }

    /**
     * Pause playback.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function pause(): void
    {
        $this->logger->info('Pausing playback', [
            'session_id' => $this->sessionId,
            'renderer_id' => $this->rendererId,
        ]);

        $result = $this->client->pause();

        if (isset($result['Error'])) {
            $this->logger->error('Failed to pause playback', [
                'error' => $result['Error'],
            ]);
            return;
        }

        $this->setState(self::STATE_PAUSED);
        $this->stopPolling();
    }

    /**
     * Stop playback.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function stop(): void
    {
        $this->logger->info('Stopping playback', [
            'session_id' => $this->sessionId,
            'renderer_id' => $this->rendererId,
        ]);

        $result = $this->client->stop();

        if (isset($result['Error'])) {
            $this->logger->error('Failed to stop playback', [
                'error' => $result['Error'],
            ]);
            return;
        }

        $this->setState(self::STATE_STOPPED);
        $this->stopPolling();
        $this->position = 0;
    }

    /**
     * Seek to position.
     *
     * @param int $positionTicks Position in ticks (100-nanosecond units)
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function seek(int $positionTicks): void
    {
        $this->position = $positionTicks;

        // Convert ticks to HH:MM:SS format
        $target = $this->ticksToTimeString($positionTicks);

        $this->logger->info('Seeking', [
            'session_id' => $this->sessionId,
            'renderer_id' => $this->rendererId,
            'position_ticks' => $positionTicks,
            'target' => $target,
        ]);

        $result = $this->client->seek($target);

        if (isset($result['Error'])) {
            $this->logger->error('Failed to seek', [
                'error' => $result['Error'],
            ]);
            return;
        }
    }

    /**
     * Get current session state.
     *
     * @return string Current state (idle, buffering, playing, paused, stopped)
     *
     * @since 0.12.0
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Get current position in ticks.
     *
     * @return int Position in ticks
     *
     * @since 0.12.0
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * Sync position from renderer.
     *
     * Polls the renderer for current position via getPositionInfo()
     * and updates local state. Also reports to PlaybackController.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function syncFromRenderer(): void
    {
        $result = $this->client->getPositionInfo();

        if (isset($result['Error'])) {
            $this->logger->warning('Failed to get position info', [
                'error' => $result['Error'],
            ]);
            return;
        }

        // Parse RelTime from response
        $relTimeRaw = $result['RelTime'] ?? $result['relTime'] ?? null;
        $relTime = is_string($relTimeRaw) ? $relTimeRaw : '00:00:00';
        $newPosition = $this->timeStringToTicks($relTime);

        if ($newPosition !== $this->position) {
            $this->position = $newPosition;
            $this->reportProgress();
        }
    }

    /**
     * Get the renderer ID.
     *
     * @return string Renderer ID (UDN)
     *
     * @since 0.12.0
     */
    public function getRendererId(): string
    {
        return $this->rendererId;
    }

    /**
     * Get the renderer name.
     *
     * @return string Renderer friendly name
     *
     * @since 0.12.0
     */
    public function getRendererName(): string
    {
        return $this->rendererName;
    }

    /**
     * Get the session ID.
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
     * Set state change callback.
     *
     * @param callable $callback Callback function(state: string): void
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function onStateChange(callable $callback): void
    {
        $this->onStateChange = $callback;
    }

    /**
     * Destroy the session and clean up.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function destroy(): void
    {
        $this->stopPolling();
        $this->stop();
        $this->setState(self::STATE_IDLE);
        $this->itemId = null;
        $this->uri = null;
    }

    /**
     * Set the session state.
     *
     * @param string $state New state
     *
     * @return void
     */
    private function setState(string $state): void
    {
        if ($this->state === $state) {
            return;
        }

        $this->state = $state;

        $this->logger->debug('Session state changed', [
            'session_id' => $this->sessionId,
            'state' => $state,
        ]);

        if ($this->onStateChange !== null) {
            ($this->onStateChange)($state);
        }
    }

    /**
     * Start polling renderer position.
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
                $this->syncFromRenderer();
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
     * Stop polling renderer position.
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
        if ($this->itemId === null || $this->uri === null) {
            return;
        }

        try {
            $this->playbackController->reportProgress(
                $this->sessionId,
                $this->itemId,
                $this->position,
                0, // Duration unknown from position info
                $this->state === self::STATE_PAUSED
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to report progress', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convert ticks to HH:MM:SS time string.
     *
     * @param int $ticks Position in ticks
     *
     * @return string Time string in HH:MM:SS format
     */
    private function ticksToTimeString(int $ticks): string
    {
        if ($ticks <= 0) {
            return '00:00:00';
        }

        // Convert ticks to seconds (1 second = 10000000 ticks)
        $totalSeconds = (int)($ticks / 10000000);

        $hours = floor($totalSeconds / 3600);
        $minutes = floor(($totalSeconds % 3600) / 60);
        $seconds = $totalSeconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * Convert HH:MM:SS time string to ticks.
     *
     * @param string $time Time string in HH:MM:SS format
     *
     * @return int Position in ticks
     */
    private function timeStringToTicks(string $time): int
    {
        $parts = explode(':', $time);

        if (count($parts) !== 3) {
            return 0;
        }

        $hours = (int)$parts[0];
        $minutes = (int)$parts[1];
        $seconds = (int)$parts[2];

        $totalSeconds = $hours * 3600 + $minutes * 60 + $seconds;

        return $totalSeconds * 10000000;
    }
}
