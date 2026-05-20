<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * Per-user settings for the Trakt scrobbler plugin.
 *
 * Stores OAuth tokens, sync preferences, and user identity.
 * Settings are serialized to JSON and stored in the plugins.settings_json
 * column, loaded via Plugin::configure() on enable.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
final class TraktSettings
{
    /**
     * @param string|null $accessToken OAuth access token (null when not authenticated)
     * @param string|null $refreshToken OAuth refresh token (null when not authenticated)
     * @param int|null $expiresAt Unix timestamp when access token expires (null when not set)
     * @param bool $syncEnabled Whether two-way history sync is enabled
     * @param int $syncIntervalMinutes How often to run Trakt→Phlix sync (in minutes)
     * @param bool $scrobbleEnabled Whether scrobbling on playback events is enabled
     * @param string $username Trakt username for attribution
     */
    public function __construct(
        public readonly ?string $accessToken = null,
        public readonly ?string $refreshToken = null,
        public readonly ?int $expiresAt = null,
        public readonly bool $syncEnabled = true,
        public readonly int $syncIntervalMinutes = 30,
        public readonly bool $scrobbleEnabled = true,
        public readonly string $username = '',
    ) {
    }

    /**
     * Create settings from an array (as loaded from DB settings JSON).
     *
     * @param array<string, mixed> $data Key-value array from settings_json
     *
     * @return self
     *
     * @since 0.14.0
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: is_string($data['access_token'] ?? null) ? $data['access_token'] : null,
            refreshToken: is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : null,
            expiresAt: is_int($data['expires_at'] ?? null) ? $data['expires_at'] : null,
            syncEnabled: is_bool($data['sync_enabled'] ?? null) ? $data['sync_enabled'] : true,
            syncIntervalMinutes: is_int($data['sync_interval_minutes'] ?? null) ? $data['sync_interval_minutes'] : 30,
            scrobbleEnabled: is_bool($data['scrobble_enabled'] ?? null) ? $data['scrobble_enabled'] : true,
            username: is_string($data['username'] ?? null) ? $data['username'] : '',
        );
    }

    /**
     * Convert settings to an array for JSON serialization.
     *
     * @return array<string, mixed>
     *
     * @since 0.14.0
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
            'sync_enabled' => $this->syncEnabled,
            'sync_interval_minutes' => $this->syncIntervalMinutes,
            'scrobble_enabled' => $this->scrobbleEnabled,
            'username' => $this->username,
        ];
    }

    /**
     * Whether the OAuth tokens are present and potentially valid.
     *
     * Note: This does not validate token expiration. Use isTokenExpired()
     * for that check.
     *
     * @return bool True when both access and refresh tokens are present
     *
     * @since 0.14.0
     */
    public function hasTokens(): bool
    {
        return $this->accessToken !== null && $this->refreshToken !== null;
    }

    /**
     * Whether the access token has expired.
     *
     * @return bool True when expired or no token exists
     *
     * @since 0.14.0
     */
    public function isTokenExpired(): bool
    {
        if ($this->expiresAt === null) {
            return $this->accessToken === null;
        }

        return time() >= $this->expiresAt;
    }

    /**
     * Whether the plugin is fully configured and ready to scrobble.
     *
     * @return bool True when hasTokens() and username is non-empty
     *
     * @since 0.14.0
     */
    public function isConfigured(): bool
    {
        return $this->hasTokens() && $this->username !== '';
    }
}
