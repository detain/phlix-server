<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata\ThemeMusic;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Theming\ThemeMediaFinder;
use Throwable;

/**
 * Produces the servable theme-audio URL for a media item (M3).
 *
 * Strategy (config-gated, default ON):
 *   1. LOCAL — scan the item's own directory (series/season/movie folder) for an
 *      Emby/Kodi `theme.{mp3,mp4,ogg}` via {@see ThemeMediaFinder}. If one exists,
 *      return the item-level stream URL.
 *   2. PLEX FALLBACK (series only, `source === local_then_plex`, TVDB id known) —
 *      GET `{plex_archive_base}/{tvdbId}.mp3`; on `200` cache it to
 *      `{cache_dir}/{tvdbId}.mp3` and return the item-level stream URL. Idempotent:
 *      an already-cached file is reused with no re-fetch.
 *
 * The returned URL always points at the item-level route served by
 * {@see \Phlix\Server\Http\Controllers\ThemeMusicStreamController}; the controller
 * re-resolves the concrete file (local theme or cached Plex mp3) at request time,
 * so the stored URL never leaks a filesystem path.
 *
 * **Never throws.** Every failure path (disabled config, unknown/invalid tvdb id,
 * network error, unwritable cache dir, filesystem error) is caught and returns
 * null so a theme lookup can never abort a metadata match.
 *
 * @since 0.66.0
 */
final class ThemeMusicResolver
{
    /** Item-level stream route prefix (see Application/Router registration). */
    public const STREAM_ROUTE_PREFIX = '/stream/theme-media/item/';

    private ThemeMusicConfig $config;
    private ThemeMediaFinder $finder;
    private ThemeMusicFetcherInterface $fetcher;
    private StructuredLogger $logger;

    public function __construct(
        ThemeMusicConfig $config,
        ThemeMediaFinder $finder,
        ThemeMusicFetcherInterface $fetcher,
        ?StructuredLogger $logger = null,
    ) {
        $this->config = $config;
        $this->finder = $finder;
        $this->fetcher = $fetcher;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Resolve a servable theme-audio URL for an item, or null when none applies.
     *
     * @param array{
     *     item_id?: string,
     *     type?: string,
     *     path?: ?string,
     *     tvdb_id?: string|int|null
     * } $context Item context:
     *   - `item_id` (required) — media-item id the stream URL is built from.
     *   - `type`    — item type (`series`/`season`/`episode`/`movie`/…); gates the
     *                 Plex fallback (series only).
     *   - `path`    — the item's absolute filesystem path (local theme lookup).
     *   - `tvdb_id` — TheTVDB series id (Plex fallback key); must be a positive int.
     *
     * @return string|null Item-level stream URL, or null when no theme is available.
     */
    public function resolveForItem(array $context): ?string
    {
        if (!$this->config->isActive()) {
            return null;
        }

        $itemId = isset($context['item_id']) && is_string($context['item_id']) ? $context['item_id'] : '';
        if ($itemId === '') {
            return null;
        }

        try {
            // (a) Local Emby/Kodi theme file next to the item.
            $path = $context['path'] ?? null;
            if (is_string($path) && $path !== '' && $this->hasLocalTheme($path)) {
                return $this->streamUrl($itemId);
            }

            // (b) Plex-archive fallback — series only, gated by config + a valid TVDB id.
            $type = isset($context['type']) && is_string($context['type']) ? $context['type'] : '';
            if ($type === 'series' && $this->config->allowsPlexFallback()) {
                $tvdbId = $this->normalizeTvdbId($context['tvdb_id'] ?? null);
                if ($tvdbId !== null && $this->ensurePlexCached($tvdbId)) {
                    return $this->streamUrl($itemId);
                }
            }
        } catch (Throwable $e) {
            // Theme is optional — a failure here must never break the scan.
            $this->logger->debug('ThemeMusic: resolveForItem failed; skipping', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * True when a local Emby/Kodi theme file exists for the item's directory.
     *
     * {@see ThemeMediaFinder::findForMediaItem()} scans the item's PARENT
     * directory (series/season level), matching the Emby/Kodi convention. The
     * library id is not used for a filesystem lookup, so an empty placeholder is
     * fine here.
     */
    private function hasLocalTheme(string $itemPath): bool
    {
        $media = $this->finder->findForMediaItem('', $itemPath);
        return $media !== null && $media->audio !== null;
    }

    /**
     * Ensure `{cache_dir}/{tvdbId}.mp3` exists, fetching from the Plex archive on
     * a cache miss. Returns true when the cached file is present afterwards.
     *
     * Idempotent: a non-empty existing cache file short-circuits the fetch.
     */
    private function ensurePlexCached(int $tvdbId): bool
    {
        $cachePath = $this->cachePathFor($tvdbId);

        // Reuse an already-cached file (no re-fetch).
        if (is_file($cachePath) && filesize($cachePath) > 0) {
            return true;
        }

        $url = $this->config->plexArchiveBase . '/' . $tvdbId . '.mp3';
        $body = $this->fetcher->fetch($url, $this->config->fetchTimeout);
        if ($body === null || $body === '') {
            return false;
        }

        if (!$this->ensureCacheDir()) {
            return false;
        }

        // Atomic-ish write: write to a temp file in the same dir, then rename.
        $tmp = $cachePath . '.' . bin2hex(random_bytes(6)) . '.part';
        if (@file_put_contents($tmp, $body) === false) {
            @unlink($tmp);
            $this->logger->debug('ThemeMusic: cache write failed', ['path' => $cachePath]);
            return false;
        }
        if (!@rename($tmp, $cachePath)) {
            @unlink($tmp);
            $this->logger->debug('ThemeMusic: cache rename failed', ['path' => $cachePath]);
            return false;
        }

        $this->logger->debug('ThemeMusic: cached Plex theme', [
            'tvdb_id' => $tvdbId,
            'path' => $cachePath,
        ]);
        return true;
    }

    /** Create the cache directory if absent; return whether it is a writable dir. */
    private function ensureCacheDir(): bool
    {
        $dir = $this->config->cacheDir;
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        if (!@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            $this->logger->debug('ThemeMusic: cache dir create failed', ['dir' => $dir]);
            return false;
        }
        return is_writable($dir);
    }

    /**
     * Absolute path of the cached Plex theme for a TVDB id.
     *
     * The filename is `{int}.mp3` — no user-controlled path segment (the caller
     * has already validated `$tvdbId` is a positive integer), so this is
     * traversal-safe by construction.
     */
    public function cachePathFor(int $tvdbId): string
    {
        return $this->config->cacheDir . '/' . $tvdbId . '.mp3';
    }

    /** Item-level stream URL for a media-item id. */
    private function streamUrl(string $itemId): string
    {
        return self::STREAM_ROUTE_PREFIX . rawurlencode($itemId);
    }

    /**
     * Narrow a raw tvdb-id value to a POSITIVE integer, or null.
     *
     * Security: the Plex URL is interpolated from this value, so anything that is
     * not a strictly-positive integer (letters, negatives, floats, empty) is
     * rejected — the fallback then simply does not fire.
     */
    private function normalizeTvdbId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $int = (int) $value;
            return $int > 0 ? $int : null;
        }
        return null;
    }
}
