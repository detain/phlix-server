<?php

/**
 * Phlix media server component: Gapless playback and crossfade management.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Playback;

use Phlix\Auth\UserRepository;
use Phlix\Media\Transcoding\CrossfadeGenerator;
use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * Manages gapless playback and crossfade preferences for users.
 *
 * This service:
 * - Loads user's crossfade/gapless preferences from user_settings
 * - Provides the GaplessPlayer instance for session-based playback tracking
 * - Provides the CrossfadeGenerator for server-side crossfade segment production
 *
 * @see PlaybackPreferences For the preference data structure
 * @see GaplessPlayer For client-side gapless/crossfade state machine
 * @see CrossfadeGenerator For server-side crossfade FFmpeg command generation
 */
class GaplessPlaybackManager
{
    /**
     * @var UserRepository|null
     */
    private ?UserRepository $userRepository;

    /**
     * @var FfmpegRunner FFmpeg runner for crossfade command generation
     */
    private FfmpegRunner $ffmpegRunner;

    /**
     * @var array<string, GaplessPlayer> Per-session gapless players
     */
    private array $players = [];

    /**
     * @var CrossfadeGenerator|null Lazily-created crossfade generator
     */
    private ?CrossfadeGenerator $crossfadeGenerator = null;

    /**
     * @var array<string, PlaybackPreferences> Cache of loaded preferences per user
     */
    private array $preferencesCache = [];

    /**
     * Create a new GaplessPlaybackManager.
     *
     * @param UserRepository|null $userRepository Optional user repository for settings
     * @param FfmpegRunner        $ffmpegRunner  FFmpeg runner for crossfade commands
     */
    public function __construct(
        ?UserRepository $userRepository,
        FfmpegRunner $ffmpegRunner
    ) {
        $this->userRepository = $userRepository;
        $this->ffmpegRunner = $ffmpegRunner;
    }

    /**
     * Get playback preferences for a user.
     *
     * Reads from user_settings.playback_preferences JSON column.
     * Falls back to defaults when userId is empty or settings not found.
     *
     * @param string $userId User UUID
     *
     * @return PlaybackPreferences User's playback preferences
     */
    public function getPreferences(string $userId): PlaybackPreferences
    {
        if ($userId === '') {
            return PlaybackPreferences::fromRaw(0, 0.3, 0.3);
        }

        // Check cache first
        if (isset($this->preferencesCache[$userId])) {
            return $this->preferencesCache[$userId];
        }

        // Try to load from user repository
        if ($this->userRepository !== null) {
            $settings = $this->userRepository->getSettings($userId);
            if (is_array($settings) && isset($settings['playback_preferences'])) {
                $stored = is_string($settings['playback_preferences'])
                    ? json_decode($settings['playback_preferences'], true)
                    : $settings['playback_preferences'];

                if (is_array($stored)) {
                    $prefs = PlaybackPreferences::fromRaw(
                        $stored['crossfadeDuration'] ?? null,
                        $stored['crossfadeFadeOut'] ?? null,
                        $stored['crossfadeFadeIn'] ?? null
                    );
                    $this->preferencesCache[$userId] = $prefs;
                    return $prefs;
                }
            }
        }

        // Fallback to defaults
        $prefs = PlaybackPreferences::fromRaw(0, 0.3, 0.3);
        $this->preferencesCache[$userId] = $prefs;
        return $prefs;
    }

    /**
     * Get the GaplessPlayer for a session.
     *
     * Creates a new player if one doesn't exist for this session.
     * The player tracks playlist progress and determines when to pre-buffer
     * or start crossfade based on the user's preferences.
     *
     * @param string $sessionId Session UUID
     * @param string $userId    User UUID (used to load preferences)
     *
     * @return GaplessPlayer The gapless player for this session
     */
    public function getPlayer(string $sessionId, string $userId): GaplessPlayer
    {
        if (!isset($this->players[$sessionId])) {
            $prefs = $this->getPreferences($userId);
            $this->players[$sessionId] = new GaplessPlayer($prefs);
        }

        return $this->players[$sessionId];
    }

    /**
     * Get the CrossfadeGenerator for producing crossfade segments.
     *
     * The generator creates FFmpeg commands for crossfade mixing between
     * tracks when crossfade is enabled in the user's preferences.
     *
     * @return CrossfadeGenerator Crossfade command generator
     */
    public function getCrossfadeGenerator(): CrossfadeGenerator
    {
        if ($this->crossfadeGenerator === null) {
            $this->crossfadeGenerator = new CrossfadeGenerator($this->ffmpegRunner);
        }

        return $this->crossfadeGenerator;
    }

    /**
     * Check if crossfade is enabled for a user.
     *
     * @param string $userId User UUID
     *
     * @return bool True if crossfade duration > 0
     */
    public function isCrossfadeEnabled(string $userId): bool
    {
        return $this->getPreferences($userId)->isCrossfadeEnabled();
    }

    /**
     * Check if gapless playback should be used.
     *
     * Gapless is used when crossfade is disabled but the user wants
     * seamless transitions between tracks.
     *
     * @param string $userId User UUID
     *
     * @return bool True if gapless should be used (crossfade disabled)
     */
    public function isGaplessEnabled(string $userId): bool
    {
        $prefs = $this->getPreferences($userId);

        return !$prefs->isCrossfadeEnabled();
    }

    /**
     * Build a crossfade command for two tracks.
     *
     * Uses the CrossfadeGenerator to create an FFmpeg command that mixes
     * the end of track A with the start of track B.
     *
     * @param string $trackAPath         Path to the outgoing track
     * @param string $trackBPath         Path to the incoming track
     * @param string $outputPath         Path for the crossfaded output
     * @param string $userId             User UUID (for preferences)
     * @param array<string, mixed> $params Additional FFmpeg parameters
     *
     * @return string Complete FFmpeg crossfade command
     */
    public function buildCrossfadeCommand(
        string $trackAPath,
        string $trackBPath,
        string $outputPath,
        string $userId,
        array $params = []
    ): string {
        $prefs = $this->getPreferences($userId);

        return $this->getCrossfadeGenerator()->buildCrossfadeWithPreferences(
            $trackAPath,
            $trackBPath,
            $outputPath,
            $prefs,
            $params
        );
    }

    /**
     * Clear the player for a session (e.g., when playback stops).
     *
     * @param string $sessionId Session UUID
     *
     * @return void
     */
    public function clearPlayer(string $sessionId): void
    {
        unset($this->players[$sessionId]);
    }

    /**
     * Clear the preferences cache (e.g., when settings are updated).
     *
     * @param string|null $userId User UUID to clear, or null to clear all
     *
     * @return void
     */
    public function clearCache(?string $userId = null): void
    {
        if ($userId === null) {
            $this->preferencesCache = [];
        } else {
            unset($this->preferencesCache[$userId]);
        }
    }
}
