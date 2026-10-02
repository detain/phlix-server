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

    /**
     * Consecutive failed media-status polls tolerated before teardown (Device-M1).
     *
     * {@see getMediaStatus()} swallows every throwable and answers `[]`, so an
     * unreachable Chromecast used to poll forever as an orphan timer (the PlayTo
     * 3-strike from d052b488 already closed this class for DLNA). `[]` is also
     * what a device with nothing loaded parses to — same honest outcome: there
     * is no cast left to report.
     */
    private const MAX_CONSECUTIVE_POLL_FAILURES = 3;

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

    /** @var int Consecutive failed polls since the last success (Device-M1) */
    private int $consecutivePollFailures = 0;

    /** @var callable(CastSession): void|null Self-teardown notifier (Device-M1) */
    private $onSelfDestruct = null;

    /** @var callable(): bool|null Shared-store liveness touch (Device-M1); false = row gone */
    private $onTouch = null;

    /** @var bool Whether the self-destruct notifier has already fired (fires at most once) */
    private bool $destructFired = false;

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
     * The shared MEDIA-channel logger — not a private one in a temp directory.
     *
     * The old body `mkdir()`ed a `sys_get_temp_dir()/phlix_cast_session_<uniqid>`
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
            $this->consecutivePollFailures = 0;
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
     * Register the manager-side self-teardown notifier (Device-M1).
     *
     * Fired at most once from {@see abandon()} so the owning manager evicts this
     * object from its map and deletes the shared-store row.
     *
     * @param callable(CastSession): void $handler
     *
     * @return void
     *
     * @since 1.5.0
     */
    public function setSelfDestructHandler(callable $handler): void
    {
        $this->onSelfDestruct = $handler;
    }

    /**
     * Register the shared-store liveness touch (Device-M1).
     *
     * Called after every successful poll; `false` means this session's
     * `casting_sessions` row is gone (replaced/swept) and the local object must
     * {@see abandon()} WITHOUT sending any device command.
     *
     * @param callable(): bool $handler
     *
     * @return void
     *
     * @since 1.5.0
     */
    public function setTouchHandler(callable $handler): void
    {
        $this->onTouch = $handler;
    }

    /**
     * Re-attach constructor: restore the media URL that gates progress
     * reporting, WITHOUT sending any device command. Position is re-derived
     * from the device on the next poll — the device is the truth.
     *
     * @param string $mediaUrl Media URL the session casts
     *
     * @return void
     *
     * @since 1.5.0
     */
    public function restoreMediaContext(string $mediaUrl): void
    {
        $this->mediaUrl = $mediaUrl;
    }

    /**
     * Re-attach constructor: resume media-status polling on a rebuilt session
     * (public seam over the private {@see startPolling()}; re-attaching from a
     * status/control request must never relaunch the receiver app).
     *
     * @return void
     *
     * @since 1.5.0
     */
    public function resumePolling(): void
    {
        $this->startPolling();
    }

    /**
     * Abandon the session WITHOUT touching the device (Device-M1).
     *
     * Used both by the strike horizon (device unreachable — a Stop command
     * would only fail again) and the row-gone path (the device may be serving a
     * REPLACEMENT session whose playback a Stop would kill). Local timer and
     * context only, then the self-destruct notifier.
     *
     * @return void
     *
     * @since 1.5.0
     */
    public function abandon(): void
    {
        $this->consecutivePollFailures = 0;
        $this->stopPolling();
        $this->state = self::STATE_IDLE;
        $this->fireSelfDestruct();
    }

    /**
     * Fire the self-destruct notifier at most once.
     */
    private function fireSelfDestruct(): void
    {
        if ($this->onSelfDestruct === null || $this->destructFired) {
            return;
        }

        $this->destructFired = true;
        ($this->onSelfDestruct)($this);
    }

    /**
     * One poll tick with orphan discipline (Device-M1).
     *
     * {@see getMediaStatus()} cannot throw — its contract is `[]` on any
     * failure — so the empty answer doubles as the strike signal, mirroring the
     * DLNA session's `MAX_CONSECUTIVE_POLL_FAILURES` teardown. After a healthy
     * poll, the shared-store touch answers whether this row still exists; a
     * `false` means another worker's start replaced us (or the sweep collected
     * us) and this object must evict itself rather than poll forever.
     */
    private function pollMediaStatusOnce(): void
    {
        $status = $this->getMediaStatus();

        if ($status === []) {
            $this->consecutivePollFailures++;

            if ($this->consecutivePollFailures >= self::MAX_CONSECUTIVE_POLL_FAILURES) {
                $this->logger->error('Chromecast media-status polling failed repeatedly, ending session', [
                    'session_id' => $this->sessionId,
                    'device_id' => $this->device->deviceId,
                ]);
                $this->abandon();
            }

            return;
        }

        $this->consecutivePollFailures = 0;

        if ($this->onTouch !== null && !($this->onTouch)()) {
            $this->logger->info('Cast session row gone, abandoning local session', [
                'session_id' => $this->sessionId,
                'device_id' => $this->device->deviceId,
            ]);
            $this->abandon();
        }
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
                $this->pollMediaStatusOnce();
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
