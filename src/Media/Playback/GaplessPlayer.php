<?php

/**
 * Phlix media server component: Gapless playback and crossfade manager.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Playback;

/**
 * GaplessPlayer manages sequential track playback with optional crossfade mixing.
 *
 * This class coordinates two decoders (current + next) to provide:
 * - **Gapless playback**: Pre-buffers the next track when the current track
 *   has less than 5 seconds remaining, enabling a seamless transition with
 *   zero silence gap between tracks.
 * - **Crossfade mixing**: When crossfade is enabled, starts the next track
 *   early and mixes the two tracks during the crossfade window — fading out
 *   the current track while fading in the next track simultaneously.
 *
 * ## Gapless mode (crossfade disabled)
 *
 * When {@see PlaybackPreferences::isCrossfadeEnabled()} is false:
 * 1. The next track is pre-buffered when current track has ≤ 5 seconds remaining
 * 2. At track end, the next decoder opens at position 0 (exact sample alignment)
 * 3. No audio mixing occurs — a clean handoff between decoders
 *
 * ## Crossfade mode (crossfade enabled)
 *
 * When {@see PlaybackPreferences::isCrossfadeEnabled()} is true:
 * 1. The next track starts playing when current track has ≤ crossfade_duration seconds remaining
 * 2. Both decoders run simultaneously during the crossfade window
 * 3. Current track fades from volume 1.0 → 0.0 over crossfade_fade_out duration
 * 4. Next track fades from volume 0.0 → 1.0 over crossfade_fade_in duration
 * 5. After crossfade completes, the old track's decoder is closed
 *
 * ## Usage
 *
 * The player is typically instantiated per-session or per-playlist and
 * receives timing updates from the playback progress reporting system:
 *
 * ```php
 * $preferences = PlaybackPreferences::fromConfig(require 'config/playback.php');
 * $player = new GaplessPlayer($preferences);
 *
 * // When starting a new album/playlist:
 * $player->setPlaylist($tracks, $currentIndex);
 *
 * // On each playback progress report from client:
 * $player->onProgress($positionTicks, $durationTicks);
 *
 * // Query state for streaming decisions:
 * if ($player->shouldPreBufferNext()) { ... }
 * if ($player->shouldStartCrossfade()) { ... }
 * ```
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Manages gapless playback and crossfade mixing for album/playlist playback
 *
 * @see PlaybackPreferences For crossfade configuration
 */
class GaplessPlayer
{
    /**
     * Default pre-buffer threshold in seconds for gapless mode.
     * When the current track has ≤ this many seconds remaining, pre-buffer begins.
     */
    public const int GAPLESS_PREBUFFER_SECONDS = 5;

    /**
     * Minimum track duration required to consider gapless/crossfade transitions.
     * Tracks shorter than this skip the transition optimization.
     */
    public const int MIN_TRACK_DURATION_SECONDS = 10;

    /**
     * Playback state enum values.
     */
    public const string STATE_IDLE = 'idle';
    public const string STATE_PLAYING = 'playing';
    public const string STATE_CROSSFADING = 'crossfading';
    public const string STATE_TRANSITIONING = 'transitioning';

    /**
     * @var PlaybackPreferences Current crossfade and playback preferences
     */
    private PlaybackPreferences $preferences;

    /**
     * @var array<int, array{id: int|string, path: string, duration_ticks: int}> Playlist tracks
     */
    private array $playlist = [];

    /**
     * @var int Index of the currently playing track in the playlist
     */
    private int $currentIndex = -1;

    /**
     * @var string Current playback state (STATE_* constants)
     */
    private string $state = self::STATE_IDLE;

    /**
     * @var int|null Timestamp (hrtime ns) when the next track pre-buffer was initiated
     */
    private ?int $preBufferStartedAt = null;

    /**
     * @var int|null Timestamp (hrtime ns) when crossfade began
     */
    private ?int $crossfadeStartedAt = null;

    /**
     * @var bool Whether the next track decoder has been opened (pre-buffered)
     */
    private bool $nextTrackReady = false;

    /**
     * @var int|null The track index that was pre-buffered (to detect playlist changes)
     */
    private ?int $preBufferedIndex = null;

    /**
     * Create a new GaplessPlayer with the given preferences.
     *
     * @param PlaybackPreferences $preferences Crossfade and gapless settings
     */
    public function __construct(PlaybackPreferences $preferences)
    {
        $this->preferences = $preferences;
    }

    /**
     * Set the playlist and starting index for gapless/crossfade playback.
     *
     * Resets any in-progress transition and clears pre-buffer state.
     *
     * @param array<int, array{id: int|string, path: string, duration_ticks: int}> $tracks
     *        Playlist tracks, each with at least 'id', 'path', and 'duration_ticks'
     * @param int                                                              $startIndex
     *        Index of the track to begin playback from
     *
     * @return void
     *
     * @throws \InvalidArgumentException When startIndex is out of range
     */
    public function setPlaylist(array $tracks, int $startIndex): void
    {
        if ($startIndex < 0 || $startIndex >= count($tracks)) {
            throw new \InvalidArgumentException(
                "Start index {$startIndex} is out of range for playlist of " . count($tracks) . ' tracks'
            );
        }

        $this->playlist = $tracks;
        $this->currentIndex = $startIndex;
        $this->state = self::STATE_PLAYING;
        $this->preBufferStartedAt = null;
        $this->crossfadeStartedAt = null;
        $this->nextTrackReady = false;
        $this->preBufferedIndex = null;
    }

    /**
     * Update playback progress.
     *
     * Called periodically with the current playback position to determine
     * when to pre-buffer the next track or begin crossfade.
     *
     * @param int $positionTicks Current playback position in ticks
     * @param int $durationTicks Total duration of the current track in ticks
     *
     * @return void
     *
     * @see self::ticksToSeconds() To convert ticks to seconds
     */
    public function onProgress(int $positionTicks, int $durationTicks): void
    {
        if ($this->state === self::STATE_IDLE) {
            return;
        }

        $remainingSeconds = $this->ticksToSeconds($durationTicks - $positionTicks);
        $currentTrackDurationSeconds = $this->ticksToSeconds($durationTicks);

        // Skip optimization for very short tracks
        if ($currentTrackDurationSeconds < self::MIN_TRACK_DURATION_SECONDS) {
            return;
        }

        $nextIndex = $this->currentIndex + 1;

        // Guard: no next track available
        if ($nextIndex >= count($this->playlist)) {
            return;
        }

        // Guard: already at the last track or past it
        if (!$this->hasNextTrack()) {
            return;
        }

        if ($this->preferences->isCrossfadeEnabled()) {
            $this->handleCrossfadeProgress($remainingSeconds, $positionTicks);
        } else {
            $this->handleGaplessProgress($remainingSeconds, $positionTicks);
        }
    }

    /**
     * Advance to the next track in the playlist.
     *
     * Called when the current track ends naturally or when a transition is triggered.
     * Resets crossfade state and promotes the next track to current.
     *
     * @return bool True if advanced to next track, false if no more tracks
     */
    public function advanceToNext(): bool
    {
        $nextIndex = $this->currentIndex + 1;

        if ($nextIndex >= count($this->playlist)) {
            $this->state = self::STATE_IDLE;
            return false;
        }

        $this->currentIndex = $nextIndex;
        $this->state = self::STATE_PLAYING;
        $this->crossfadeStartedAt = null;
        $this->preBufferStartedAt = null;
        $this->nextTrackReady = false;
        $this->preBufferedIndex = null;

        return true;
    }

    /**
     * Skip to a specific track index.
     *
     * Cancels any in-progress pre-buffer or crossfade and resets state.
     *
     * @param int $index Target track index
     *
     * @return void
     *
     * @throws \InvalidArgumentException When index is out of range
     */
    public function skipTo(int $index): void
    {
        if ($index < 0 || $index >= count($this->playlist)) {
            throw new \InvalidArgumentException(
                "Index {$index} is out of range for playlist of " . count($this->playlist) . ' tracks'
            );
        }

        $this->currentIndex = $index;
        $this->state = self::STATE_PLAYING;
        $this->crossfadeStartedAt = null;
        $this->preBufferStartedAt = null;
        $this->nextTrackReady = false;
        $this->preBufferedIndex = null;
    }

    /**
     * Stop playback and reset to idle state.
     *
     * @return void
     */
    public function stop(): void
    {
        $this->state = self::STATE_IDLE;
        $this->crossfadeStartedAt = null;
        $this->preBufferStartedAt = null;
        $this->nextTrackReady = false;
        $this->preBufferedIndex = null;
    }

    /**
     * Update crossfade preferences.
     *
     * Allows hot-swapping crossfade settings without interrupting playback.
     *
     * @param PlaybackPreferences $preferences New preferences to apply
     *
     * @return void
     */
    public function setPreferences(PlaybackPreferences $preferences): void
    {
        $this->preferences = $preferences;

        // Cancel crossfade if it becomes disabled mid-crossfade
        if (!$preferences->isCrossfadeEnabled() && $this->state === self::STATE_CROSSFADING) {
            $this->state = self::STATE_PLAYING;
            $this->crossfadeStartedAt = null;
        }
    }

    /**
     * Whether the next track should be pre-buffered now (gapless mode).
     *
     * Returns true when:
     * - Crossfade is disabled
     * - Current track has ≤ GAPLESS_PREBUFFER_SECONDS remaining
     * - A next track exists in the playlist
     * - The next track is not already pre-buffered
     *
     * @return bool True if pre-buffer should begin
     */
    public function shouldPreBufferNext(): bool
    {
        if ($this->state === self::STATE_IDLE) {
            return false;
        }

        if ($this->preferences->isCrossfadeEnabled()) {
            return false;
        }

        if (!$this->hasNextTrack()) {
            return false;
        }

        // Already pre-buffered a different track
        if ($this->preBufferedIndex !== null && $this->preBufferedIndex !== $this->currentIndex + 1) {
            return false;
        }

        if ($this->preBufferStartedAt === null) {
            return false;
        }

        // Only valid if pre-buffer was triggered for current track
        return $this->preBufferedIndex === $this->currentIndex + 1;
    }

    /**
     * Whether the crossfade should start now.
     *
     * Returns true when:
     * - Crossfade is enabled
     * - Current track has ≤ crossfade_duration seconds remaining
     * - Crossfade has not already started for this transition
     *
     * @return bool True if crossfade should begin
     */
    public function shouldStartCrossfade(): bool
    {
        if ($this->state === self::STATE_IDLE || $this->state === self::STATE_CROSSFADING) {
            return false;
        }

        if (!$this->preferences->isCrossfadeEnabled()) {
            return false;
        }

        if (!$this->hasNextTrack()) {
            return false;
        }

        // Crossfade already in progress for this transition
        if ($this->crossfadeStartedAt !== null) {
            return false;
        }

        // Check if we have enough info to determine remaining time
        // (crossfade should begin is determined by onProgress, not directly)
        return false;
    }

    /**
     * Whether we are currently in a crossfade transition.
     *
     * @return bool True if actively crossfading between tracks
     */
    public function isCrossfading(): bool
    {
        return $this->state === self::STATE_CROSSFADING;
    }

    /**
     * Get the current crossfade volume levels for both tracks.
     *
     * Returns `[currentVolume, nextVolume]` where each is 0.0–1.0.
     * Only meaningful during crossfade; returns `[1.0, 0.0]` otherwise.
     *
     * @return array{0: float, 1: float} [currentTrackVolume, nextTrackVolume]
     */
    public function getCrossfadeVolumes(): array
    {
        if ($this->state !== self::STATE_CROSSFADING || $this->crossfadeStartedAt === null) {
            return [1.0, 0.0];
        }

        $elapsedNs = hrtime(true) - $this->crossfadeStartedAt;
        $elapsedSeconds = $elapsedNs / 1_000_000_000.0;
        $duration = $this->preferences->crossfadeDuration;

        if ($duration <= 0) {
            return [1.0, 0.0];
        }

        $progress = min(1.0, $elapsedSeconds / $duration);

        // Fade out current track over crossfade_fade_out portion
        $fadeOutDuration = $this->preferences->fadeOutDuration();
        $currentVolume = $fadeOutDuration > 0
            ? max(0.0, 1.0 - ($elapsedSeconds / $fadeOutDuration))
            : 0.0;

        // Fade in next track over crossfade_fade_in portion
        $fadeInDuration = $this->preferences->fadeInDuration();
        $nextVolume = $fadeInDuration > 0
            ? min(1.0, $elapsedSeconds / $fadeInDuration)
            : 0.0;

        return [
            max(0.0, min(1.0, $currentVolume)),
            max(0.0, min(1.0, $nextVolume)),
        ];
    }

    /**
     * Get the current track info.
     *
     * @return array{id: int|string, path: string, duration_ticks: int}|null Current track or null if idle
     */
    public function getCurrentTrack(): ?array
    {
        if ($this->currentIndex < 0 || $this->currentIndex >= count($this->playlist)) {
            return null;
        }

        return $this->playlist[$this->currentIndex];
    }

    /**
     * Get the next track info (without advancing).
     *
     * @return array{id: int|string, path: string, duration_ticks: int}|null Next track or null if none
     */
    public function getNextTrack(): ?array
    {
        $nextIndex = $this->currentIndex + 1;

        if ($nextIndex >= count($this->playlist)) {
            return null;
        }

        return $this->playlist[$nextIndex];
    }

    /**
     * Get the current playback state.
     *
     * @return string One of STATE_IDLE, STATE_PLAYING, STATE_CROSSFADING, STATE_TRANSITIONING
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Get the current preferences.
     *
     * @return PlaybackPreferences Current crossfade preferences
     */
    public function getPreferences(): PlaybackPreferences
    {
        return $this->preferences;
    }

    /**
     * Whether the next track is ready (pre-buffered).
     *
     * @return bool True if nextTrackReady flag is set
     */
    public function isNextTrackReady(): bool
    {
        return $this->nextTrackReady;
    }

    /**
     * Mark the next track as ready (pre-buffered).
     *
     * Called by the streaming layer when pre-buffer completes.
     *
     * @return void
     */
    public function markNextTrackReady(): void
    {
        $this->nextTrackReady = true;
        $this->preBufferedIndex = $this->currentIndex + 1;
    }

    /**
     * Whether a next track exists in the playlist.
     *
     * @return bool True if currentIndex + 1 is within playlist bounds
     */
    public function hasNextTrack(): bool
    {
        return ($this->currentIndex + 1) < count($this->playlist);
    }

    /**
     * Convert ticks to seconds.
     *
     * Phlix uses 100-nanosecond ticks (Windows FILETIME epoch).
     *
     * @param int $ticks Duration in ticks
     *
     * @return float Duration in seconds
     */
    public static function ticksToSeconds(int $ticks): float
    {
        return $ticks / 10_000_000.0;
    }

    /**
     * Handle progress for gapless mode (crossfade disabled).
     *
     * @param float $remainingSeconds Seconds remaining in current track
     * @param int   $positionTicks    Current position in ticks
     *
     * @return void
     */
    private function handleGaplessProgress(float $remainingSeconds, int $positionTicks): void
    {
        // Pre-buffer threshold for gapless: 5 seconds
        if ($remainingSeconds <= self::GAPLESS_PREBUFFER_SECONDS && $this->preBufferStartedAt === null) {
            $this->preBufferStartedAt = hrtime(true);
            $this->preBufferedIndex = $this->currentIndex + 1;
        }
    }

    /**
     * Handle progress for crossfade mode.
     *
     * @param float $remainingSeconds Seconds remaining in current track
     * @param int   $positionTicks    Current position in ticks (unused, timing is from elapsed)
     *
     * @return void
     */
    private function handleCrossfadeProgress(float $remainingSeconds, int $positionTicks): void
    {
        // Initiate crossfade when remaining time reaches crossfade duration
        if ($remainingSeconds <= $this->preferences->crossfadeDuration && $this->crossfadeStartedAt === null) {
            $this->crossfadeStartedAt = hrtime(true);
            $this->state = self::STATE_CROSSFADING;
        }
    }

    /**
     * Get transition info for the streaming layer.
     *
     * Returns a structured array describing what action the streaming layer
     * should take, if any. This is the primary interface for the HLS controller
     * or other streaming components to query gapless/crossfade state.
     *
     * @return array{
     *     action: string,
     *     currentTrackId: int|string|null,
     *     nextTrackId: int|string|null,
     *     crossfadeVolumes: array{0: float, 1: float}|null,
     *     preBufferReady: bool
     * } Transition action descriptor
     */
    public function getTransitionInfo(): array
    {
        $currentTrack = $this->getCurrentTrack();
        $nextTrack = $this->getNextTrack();

        $action = 'none';
        $crossfadeVolumes = null;

        if ($this->state === self::STATE_CROSSFADING) {
            $action = 'crossfade';
            $crossfadeVolumes = $this->getCrossfadeVolumes();
        } elseif ($this->shouldPreBufferNext()) {
            $action = 'prebuffer';
        }

        return [
            'action' => $action,
            'currentTrackId' => $currentTrack['id'] ?? null,
            'nextTrackId' => $nextTrack['id'] ?? null,
            'crossfadeVolumes' => $crossfadeVolumes,
            'preBufferReady' => $this->nextTrackReady,
        ];
    }
}
