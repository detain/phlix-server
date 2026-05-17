<?php

namespace Phlex\Dlna;

/**
 * Represents the state of a DLNA/UPnP AV Transport instance.
 *
 * Each instance maintains playback state for a single media session,
 * including transport state (playing/paused/stopped), position tracking,
 * and media metadata. Instances are keyed by InstanceID in the AVTransport service.
 *
 * @see AvTransport For the service that manages these instances
 * @see UPnP AVTransport:1 Specification For protocol details
 */
class TransportState
{
    /** @var int The transport instance identifier (usually 0) */
    private int $instanceId;

    /** @var string Current transport state (STOPPED, PLAYING, PAUSED_PLAYING, etc.) */
    private string $transportState = AvTransport::TRANSPORT_STATE_STOPPED;

    /** @var string Playback speed (e.g., "1" for normal, "2" for double speed) */
    private string $playbackSpeed = '1';

    /** @var string The current media URI being played */
    private string $mediaUri = '';

    /** @var array<string, mixed> Parsed DIDL-Lite metadata for current media */
    private array $mediaMetadata = [];

    /** @var int Media duration in ticks (100-nanosecond units) */
    private int $mediaDuration = 0;

    /** @var int Current playback position in ticks */
    private int $position = 0;

    /** @var int Current track number (for playlists) */
    private int $currentTrack = 1;

    /** @var int Total number of tracks in playlist */
    private int $nrTracks = 0;

    /** @var string Current play mode (NORMAL, REPEAT_ONE, REPEAT_ALL, SHUFFLE, RANDOM) */
    private string $playMode = 'NORMAL';

    /** @var float|null Timestamp of last state change */
    private ?float $lastChange = null;

    /**
     * Create a new transport state instance.
     *
     * @param int $instanceId The transport instance ID (0-15 typically)
     */
    public function __construct(int $instanceId)
    {
        $this->instanceId = $instanceId;
    }

    /**
     * Get the instance ID.
     *
     * @return int The transport instance identifier
     */
    public function getInstanceId(): int
    {
        return $this->instanceId;
    }

    /**
     * Get the current transport state.
     *
     * @return string Transport state (STOPPED, PLAYING, PAUSED_PLAYING, TRANSITIONING, NO_MEDIA_PRESENT)
     */
    public function getTransportState(): string
    {
        return $this->transportState;
    }

    /**
     * Set the transport state.
     *
     * @param string $state The new transport state
     * @return void
     */
    public function setTransportState(string $state): void
    {
        $this->transportState = $state;
    }

    /**
     * Get the playback speed.
     *
     * @return string Playback speed factor (e.g., "1", "2", "0.5")
     */
    public function getPlaybackSpeed(): string
    {
        return $this->playbackSpeed;
    }

    /**
     * Set the playback speed.
     *
     * @param string $speed The playback speed factor
     * @return void
     */
    public function setPlaybackSpeed(string $speed): void
    {
        $this->playbackSpeed = $speed;
    }

    /**
     * Get the current media URI.
     *
     * @return string The URI of the media being played
     */
    public function getMediaUri(): string
    {
        return $this->mediaUri;
    }

    /**
     * Set the media URI.
     *
     * @param string $uri The media URI to set
     * @return void
     */
    public function setMediaUri(string $uri): void
    {
        $this->mediaUri = $uri;
    }

    /**
     * Get the media metadata (DIDL-Lite parsed).
     *
     * @return array<string, mixed> The parsed metadata including title, artist, album, duration
     */
    public function getMediaMetadata(): array
    {
        return $this->mediaMetadata;
    }

    /**
     * Set the media metadata and extract duration.
     *
     * @param array<string, mixed> $metadata The parsed DIDL-Lite metadata
     * @return void
     */
    public function setMediaMetadata(array $metadata): void
    {
        $this->mediaMetadata = $metadata;
        $this->mediaDuration = $metadata['duration'] ?? 0;
    }

    /**
     * Get the media duration in ticks.
     *
     * @return int Duration in 100-nanosecond units
     */
    public function getMediaDuration(): int
    {
        return $this->mediaDuration;
    }

    /**
     * Get the current playback position in ticks.
     *
     * @return int Position in 100-nanosecond units
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * Set the playback position.
     *
     * @param int $position Position in 100-nanosecond units
     * @return void
     */
    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    /**
     * Get the current track number.
     *
     * @return int Current track (1-based index)
     */
    public function getCurrentTrack(): int
    {
        return $this->currentTrack;
    }

    /**
     * Set the current track number.
     *
     * @param int $track Track number (1-based)
     * @return void
     */
    public function setCurrentTrack(int $track): void
    {
        $this->currentTrack = $track;
    }

    /**
     * Get the total number of tracks.
     *
     * @return int Number of tracks in the playlist
     */
    public function getNrTracks(): int
    {
        return $this->nrTracks;
    }

    /**
     * Set the number of tracks.
     *
     * @param int $count Total number of tracks
     * @return void
     */
    public function setNrTracks(int $count): void
    {
        $this->nrTracks = $count;
    }

    /**
     * Get the current play mode.
     *
     * @return string Play mode (NORMAL, REPEAT_ONE, REPEAT_ALL, SHUFFLE, RANDOM)
     */
    public function getPlayMode(): string
    {
        return $this->playMode;
    }

    /**
     * Set the play mode.
     *
     * @param string $mode The play mode to set
     * @return void
     */
    public function setPlayMode(string $mode): void
    {
        $this->playMode = $mode;
    }

    /**
     * Get the last change timestamp.
     *
     * @return float|null Unix timestamp of last state change
     */
    public function getLastChange(): ?float
    {
        return $this->lastChange;
    }

    /**
     * Set the last change timestamp.
     *
     * @param float $timestamp Unix timestamp
     * @return void
     */
    public function setLastChange(float $timestamp): void
    {
        $this->lastChange = $timestamp;
    }

    /**
     * Check if transport is currently playing.
     *
     * @return bool True if transport state is PLAYING
     */
    public function isPlaying(): bool
    {
        return $this->transportState === AvTransport::TRANSPORT_STATE_PLAYING;
    }

    /**
     * Check if transport is currently paused.
     *
     * @return bool True if transport state is PAUSED_PLAYING
     */
    public function isPaused(): bool
    {
        return $this->transportState === AvTransport::TRANSPORT_STATE_PAUSED;
    }

    /**
     * Check if transport is currently stopped.
     *
     * @return bool True if transport state is STOPPED
     */
    public function isStopped(): bool
    {
        return $this->transportState === AvTransport::TRANSPORT_STATE_STOPPED;
    }
}
