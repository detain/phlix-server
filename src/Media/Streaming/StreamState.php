<?php

declare(strict_types=1);

namespace Phlix\Media\Streaming;

/**
 * Stream State - Tracks playback state for a streaming session.
 *
 * Encapsulates all state information for an active media stream including
 * playback position, status, and streaming method details. Provides
 * convenience methods for time conversion and progress calculation.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description Playback state container with position tracking and time conversion
 * @see StreamManager For stream lifecycle management
 */
class StreamState
{
    // Playback status constants
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_PLAYING = 'playing';
    public const STATUS_PAUSED = 'paused';

    /** @var string Unique stream identifier */
    public string $id;

    /** @var string Media item identifier */
    public string $mediaItemId;

    /** @var string Client session identifier */
    public string $sessionId;

    /** @var string Owner user identifier */
    public string $userId;

    /** @var int Current playback position in 100-nanosecond ticks */
    public int $positionTicks;

    /** @var int Total duration in 100-nanosecond ticks */
    public int $durationTicks;

    /** @var string Current playback status (stopped|playing|paused) */
    public string $status;

    /** @var string Playback method (direct|transcode) */
    public string $playMethod;

    /** @var array<int, array{stream_index: int, stream_type: string, codec: string}> Requested streams */
    public array $requestedStreams = [];

    /** @var array<int, array{stream_index: int, stream_type: string, codec: string}> Actual streams used */
    public array $actualStreams = [];

    /** @var string|null Transcode job ID if transcoding is needed */
    public ?string $transcodeJobId = null;

    /** @var string|null Direct stream URL if direct play is used */
    public ?string $directStreamUrl = null;

    /** @var int|null Subtitle stream index to burn in (null = no burn-in) */
    public ?int $subtitleBurnInIndex = null;

    /** @var bool Whether to force subtitle burn-in (ignore soft-subtitle preference) */
    public bool $forceSubtitleBurnIn = false;

    /** @var float Timestamp when playback started (microtime) */
    public float $startedAt;

    /** @var float|null Timestamp when playback was paused (microtime) */
    public ?float $pausedAt = null;

    /**
     * Creates a new StreamState instance.
     *
     * Initializes default values for all properties. Sets status to 'stopped',
     * position and duration to 0, and generates a startedAt timestamp.
     */
    public function __construct()
    {
        $this->status = self::STATUS_STOPPED;
        $this->positionTicks = 0;
        $this->durationTicks = 0;
        $this->startedAt = microtime(true);
        $this->id = '';
        $this->mediaItemId = '';
        $this->sessionId = '';
        $this->userId = '';
        $this->playMethod = '';
    }

    /**
     * Converts state to array representation.
     *
     * @return array{
     *     id: string,
     *     media_item_id: string,
     *     session_id: string,
     *     user_id: string,
     *     position_ticks: int,
     *     duration_ticks: int,
     *     status: string,
     *     play_method: string,
     *     requested_streams: array<int, array{stream_index: int, stream_type: string, codec: string}>,
     *     actual_streams: array<int, array{stream_index: int, stream_type: string, codec: string}>,
     *     transcode_job_id: string|null
     * } Array representation of stream state
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'media_item_id' => $this->mediaItemId,
            'session_id' => $this->sessionId,
            'user_id' => $this->userId,
            'position_ticks' => $this->positionTicks,
            'duration_ticks' => $this->durationTicks,
            'status' => $this->status,
            'play_method' => $this->playMethod,
            'requested_streams' => $this->requestedStreams,
            'actual_streams' => $this->actualStreams,
            'transcode_job_id' => $this->transcodeJobId,
        ];
    }

    /**
     * Gets current position in seconds.
     *
     * Converts from 100-nanosecond ticks to decimal seconds.
     *
     * @return float Position in seconds
     *
     * @example
     * ```php
     * $seconds = $state->getPositionSeconds(); // 125.5
     * ```
     */
    public function getPositionSeconds(): float
    {
        return $this->positionTicks / 10000000;
    }

    /**
     * Gets duration in seconds.
     *
     * Converts from 100-nanosecond ticks to decimal seconds.
     *
     * @return float Duration in seconds
     */
    public function getDurationSeconds(): float
    {
        return $this->durationTicks / 10000000;
    }

    /**
     * Gets playback progress as a percentage.
     *
     * @return float Progress percentage (0-100), or 0 if duration is 0
     */
    public function getProgressPercent(): float
    {
        if ($this->durationTicks === 0) {
            return 0;
        }
        return ($this->positionTicks / $this->durationTicks) * 100;
    }

    /**
     * Checks if the stream is in an active state.
     *
     * @return bool True if status is 'playing' or 'paused'
     */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PLAYING, self::STATUS_PAUSED], true);
    }

    /**
     * Starts or resumes playback.
     *
     * Sets status to 'playing'. If resuming from pause, adjusts the startedAt
     * timestamp to account for pause duration so that elapsed time calculations
     * remain accurate.
     */
    public function play(): void
    {
        $this->status = self::STATUS_PLAYING;
        if ($this->pausedAt !== null) {
            $pauseDuration = microtime(true) - $this->pausedAt;
            $this->startedAt += $pauseDuration;
            $this->pausedAt = null;
        }
    }

    /**
     * Pauses playback.
     *
     * Sets status to 'paused' and records the pause timestamp for elapsed
     * time calculation when playback resumes.
     */
    public function pause(): void
    {
        $this->status = self::STATUS_PAUSED;
        $this->pausedAt = microtime(true);
    }

    /**
     * Stops playback completely.
     *
     * Sets status to 'stopped'. Does not reset position - the final position
     * is preserved for potential resume.
     */
    public function stop(): void
    {
        $this->status = self::STATUS_STOPPED;
    }

    /**
     * Seeks to a position within the media.
     *
     * Clamps the position to valid bounds (0 to duration). Negative values
     * are clamped to 0, values beyond duration are clamped to duration.
     *
     * @param int $positionTicks Target position in 100-nanosecond ticks
     *
     * @example
     * ```php
     * $state->seek(600000000); // Seek to 60 seconds (60 * 10000000 ticks)
     * ```
     */
    public function seek(int $positionTicks): void
    {
        $this->positionTicks = max(0, min($positionTicks, $this->durationTicks));
    }
}
