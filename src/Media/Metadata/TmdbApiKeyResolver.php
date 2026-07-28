<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Admin\SettingsRepository;

/**
 * Single resolution point for the effective TMDB API key.
 *
 * The effective key is admin-managed: it is saved from the SPA's
 * Settings → Metadata page into the `server_settings` row `tmdb.api_key`.
 * {@see SettingsRepository::getEffective()} already implements the whole
 * precedence chain — the `server_settings` override when present, otherwise
 * the `config/tmdb.php` default (which itself reads `TMDB_API_KEY`) — so a
 * consumer that calls it needs no further fallback logic.
 *
 * Before this class existed, three call sites hand-rolled a config/env-only
 * read that never consulted `server_settings` at all:
 *
 *   * `WebPortalRouter::tmdbApiKey()`,
 *   * `Application::getMediaPosterController()`,
 *   * `Application::getExtrasController()` (container-less branch).
 *
 * On a deployment where `TMDB_API_KEY` is not exported — which is the normal
 * case once the key is managed from the admin UI — every one of those
 * resolved to an empty string permanently, so the admin poster endpoints
 * behaved as if no key were configured while the UI showed a valid one.
 * Route all key reads through here so that cannot regress per call site.
 *
 * NOTE ON CACHING: callers capture the returned key into a long-lived
 * consumer (`TmdbProvider` is a per-worker DI singleton, and the poster
 * controller is built once when routes are registered). Resolution therefore
 * happens at worker start, not per request — a newly saved key applies on the
 * next worker cycle. That is why `tmdb.api_key` is declared `"restart": true`
 * in the server-settings schema; see `docs/dev/settings-restart-gap.md`.
 *
 * @since 0.46.0
 */
final class TmdbApiKeyResolver
{
    /** Dotted settings key holding the admin-managed TMDB API key. */
    public const SETTING_KEY = 'tmdb.api_key';

    /**
     * Resolve the effective TMDB API key.
     *
     * @param SettingsRepository|null $settings   Settings store. When null (legacy
     *                                            container-less construction and
     *                                            some test helpers) only the
     *                                            config/env default is available.
     * @param string|null             $configPath Absolute path to `config/tmdb.php`.
     *                                            Injectable for tests; defaults to
     *                                            the repository's own config file.
     *
     * @return string The effective key, or an empty string when none is configured.
     */
    public static function resolve(?SettingsRepository $settings, ?string $configPath = null): string
    {
        if ($settings !== null) {
            try {
                /** @var mixed $stored */
                $stored = $settings->getEffective(self::SETTING_KEY);
                if (is_string($stored) && $stored !== '') {
                    return $stored;
                }
            } catch (\Throwable) {
                // Settings store unavailable (no DB yet at boot, or a failed
                // query) — fall back to the config/env default rather than
                // failing the caller's construction.
            }
        }

        return self::fromConfig($configPath);
    }

    /**
     * Config/env-only resolution, used as the fallback when no settings store
     * is available.
     *
     * Unlike the hand-rolled readers this replaces, an empty `api_key` in
     * `config/tmdb.php` falls through to `TMDB_API_KEY` instead of being
     * returned as-is — those readers could never reach their own env fallback
     * because `config/tmdb.php` always returns a string for `api_key`.
     *
     * @param string|null $configPath Absolute path to `config/tmdb.php`.
     *
     * @return string The configured key, or an empty string.
     */
    public static function fromConfig(?string $configPath = null): string
    {
        $path = $configPath ?? dirname(__DIR__, 3) . '/config/tmdb.php';

        /** @var mixed $raw */
        $raw = @include $path;
        if (is_array($raw) && isset($raw['api_key']) && is_string($raw['api_key']) && $raw['api_key'] !== '') {
            return $raw['api_key'];
        }

        return (string) (getenv('TMDB_API_KEY') ?: '');
    }
}
