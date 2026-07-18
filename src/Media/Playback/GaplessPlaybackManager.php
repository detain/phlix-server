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

/**
 * Resolves a user's gapless/crossfade playback preferences.
 *
 * Gapless and crossfade transitions are implemented entirely client-side
 * (phlix-ui). The server's only remaining role is to surface each user's
 * stored preference values so the client can honor them: this service loads
 * the crossfade/gapless preferences from `user_settings` and caches them.
 *
 * @see PlaybackPreferences For the preference data structure
 */
class GaplessPlaybackManager
{
    /**
     * @var UserRepository|null
     */
    private ?UserRepository $userRepository;

    /**
     * @var array<string, PlaybackPreferences> Cache of loaded preferences per user
     */
    private array $preferencesCache = [];

    /**
     * Create a new GaplessPlaybackManager.
     *
     * @param UserRepository|null $userRepository Optional user repository for settings
     */
    public function __construct(
        ?UserRepository $userRepository
    ) {
        $this->userRepository = $userRepository;
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
}
