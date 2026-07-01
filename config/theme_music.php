<?php

/**
 * Theme-music (M3) producer configuration.
 *
 * Drives {@see \Phlix\Media\Metadata\ThemeMusicResolver}, which populates the
 * `metadata_json.theme_audio_url` slot on series/season/episode/movie items at
 * metadata-match time. The resolver is local-first (Emby/Kodi
 * `theme.{mp3,mp4,ogg}` next to the item) and, for series with a known TheTVDB
 * id, falls back to the public Plex theme archive
 * (`{plex_archive_base}/{tvdbId}.mp3`), caching the fetched file under
 * `cache_dir`. Movies never use the Plex fallback (local-theme only).
 *
 * All lookups degrade to null on any network/filesystem failure — a missing or
 * unreachable theme never breaks a scan. The whole feature is gated by
 * `enabled` / `source` below (both default ON).
 *
 * Env overrides:
 *   PHLIX_THEME_MUSIC_ENABLED   1|true|yes|on / 0|false|no|off  (default: on)
 *   PHLIX_THEME_MUSIC_SOURCE    local_then_plex | local_only | off
 *   PHLIX_THEME_MUSIC_CACHE_DIR absolute or project-relative cache directory
 *   PHLIX_THEME_MUSIC_TIMEOUT   fetch timeout in seconds (integer)
 *
 * @since 0.66.0
 */

declare(strict_types=1);

$parseBool = static function (string|false $raw, bool $default): bool {
    if ($raw === false || $raw === '') {
        return $default;
    }
    return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
};

$enabled = $parseBool(getenv('PHLIX_THEME_MUSIC_ENABLED'), true);

$sourceRaw = getenv('PHLIX_THEME_MUSIC_SOURCE');
$source = is_string($sourceRaw) ? strtolower(trim($sourceRaw)) : '';
if (!in_array($source, ['local_then_plex', 'local_only', 'off'], true)) {
    $source = 'local_then_plex';
}

$cacheDir = getenv('PHLIX_THEME_MUSIC_CACHE_DIR');
if ($cacheDir === false || $cacheDir === '') {
    // Sits under the project's writable `var/` tree (same sandbox allowance as
    // transcodes/plugin caches). `var/themes` already exists next to it.
    $cacheDir = dirname(__DIR__) . '/var/theme-music';
}

$timeoutRaw = getenv('PHLIX_THEME_MUSIC_TIMEOUT');
$timeout = (is_string($timeoutRaw) && is_numeric($timeoutRaw) && (int) $timeoutRaw > 0)
    ? (int) $timeoutRaw
    : 8;

return [
    /** Master gate. When false the resolver always returns null (no theme). */
    'enabled' => $enabled,

    /**
     * Producer strategy:
     *   'local_then_plex' — local Emby/Kodi theme file, else Plex archive (series).
     *   'local_only'      — local theme file only; never fetch from the network.
     *   'off'             — never produce a theme url (same effect as enabled=false).
     */
    'source' => $source,

    /** Base URL of the public Plex theme archive (series fallback, by TVDB id). */
    'plex_archive_base' => 'https://tvthemes.plexapp.com',

    /** Directory fetched Plex themes are cached to (`{cache_dir}/{tvdbId}.mp3`). */
    'cache_dir' => rtrim($cacheDir, '/'),

    /** Outbound fetch timeout in seconds for the Plex-archive request. */
    'fetch_timeout_seconds' => $timeout,
];
