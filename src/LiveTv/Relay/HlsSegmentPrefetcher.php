<?php

/**
 * Phlix media server component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\LiveTv\Relay;

use Psr\Log\LoggerInterface;
use Workerman\Timer;

/**
 * Prefetches HLS segments ahead of playback for smoother relay performance.
 *
 * Uses an LRU cache with configurable size limit to store segments.
 * Background timers periodically fetch the next N segments from a variant
 * playlist, enabling smooth playback for remote clients.
 *
 * @since 0.12.0
 */
class HlsSegmentPrefetcher
{
    /** Default number of segments to prefetch ahead. */
    private const DEFAULT_PREFETCH_SEGMENTS = 3;

    /** Default maximum cache size in bytes (10 MB). */
    private const DEFAULT_MAX_CACHE_SIZE = 10 * 1024 * 1024;

    /** Default TTL for cached segments in seconds. */
    private const DEFAULT_TTL_SECONDS = 30;

    /** @var array<string, array{data: string, timestamp: int}> LRU segment cache keyed by URL hash */
    private array $segmentCache = [];

    /** @var array<string, int> Cache order for LRU tracking (urlHash => timestamp) */
    private array $cacheOrder = [];

    /** @var int Current cache size in bytes */
    private int $currentCacheSize = 0;

    /** @var int Maximum cache size in bytes */
    private int $maxCacheSize;

    /** @var int TTL for cached segments in seconds */
    private int $ttlSeconds;

    /** @var int Number of segments to prefetch ahead */
    private int $prefetchSegments;

    /** @var LoggerInterface|null Optional logger */
    private ?LoggerInterface $logger;

    /** @var array<string, int> Active prefetch timers keyed by sessionId */
    private array $prefetchTimers = [];

    /** @var int Next cache order index for LRU tracking */
    private int $nextCacheOrder = 0;

    /**
     * @param LoggerInterface|null $logger           Optional logger instance.
     * @param int                   $prefetchSegments Number of segments to prefetch ahead.
     * @param int                   $maxCacheSize       Maximum cache size in bytes.
     * @param int                   $ttlSeconds        TTL for cached segments.
     *
     * @since 0.12.0
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        int $prefetchSegments = self::DEFAULT_PREFETCH_SEGMENTS,
        int $maxCacheSize = self::DEFAULT_MAX_CACHE_SIZE,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
        $this->logger = $logger;
        $this->prefetchSegments = $prefetchSegments;
        $this->maxCacheSize = $maxCacheSize;
        $this->ttlSeconds = $ttlSeconds;
    }

    /**
     * Prefetch the next N segments for a variant playlist.
     *
     * Downloads segments referenced in the variant playlist and stores
     * them in the LRU cache for fast retrieval.
     *
     * @param string $variantPlaylistUrl URL of the variant playlist to prefetch.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function prefetch(string $variantPlaylistUrl): void
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Phlix Media Server/1.0',
            ],
        ]);

        $playlistContent = @file_get_contents($variantPlaylistUrl, false, $context);
        if ($playlistContent === false) {
            $this->logger?->warning('HlsSegmentPrefetcher: failed to fetch playlist', [
                'url' => $variantPlaylistUrl,
            ]);
            return;
        }

        $segments = $this->parsePlaylistSegments($playlistContent, $variantPlaylistUrl);
        $segmentsToFetch = array_slice($segments, 0, $this->prefetchSegments);

        foreach ($segmentsToFetch as $segmentUrl) {
            $this->fetchAndCacheSegment($segmentUrl);
        }
    }

    /**
     * Parse segments from a variant playlist content.
     *
     * @param string $playlistContent The m3u8 playlist content.
     * @param string $baseUrl         Base URL for resolving relative segment URLs.
     *
     * @return array<int, string> Array of segment URLs.
     *
     * @since 0.12.0
     */
    private function parsePlaylistSegments(string $playlistContent, string $baseUrl): array
    {
        $segments = [];
        $lines = explode("\n", $playlistContent);
        $baseDir = dirname($baseUrl);

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip comments and playlist headers
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            // Skip EXTINF lines (they precede segment URLs)
            if (str_starts_with($line, '#EXTINF')) {
                continue;
            }
            // This is a segment URL
            if (!str_starts_with($line, '/') && !preg_match('/^https?:/', $line)) {
                // Relative URL - resolve against base
                $segments[] = rtrim($baseDir, '/') . '/' . $line;
            } else {
                $segments[] = $line;
            }
        }

        return $segments;
    }

    /**
     * Fetch a segment and store it in the cache.
     *
     * @param string $segmentUrl URL of the segment to fetch.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function fetchAndCacheSegment(string $segmentUrl): void
    {
        $cacheKey = $this->getCacheKey($segmentUrl);

        // Skip if already cached and not expired
        if (isset($this->segmentCache[$cacheKey])) {
            if (time() - $this->segmentCache[$cacheKey]['timestamp'] < $this->ttlSeconds) {
                return;
            }
            // Expired - remove from cache
            $this->removeFromCache($cacheKey);
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Phlix Media Server/1.0',
            ],
        ]);

        $data = @file_get_contents($segmentUrl, false, $context);
        if ($data === false) {
            $this->logger?->warning('HlsSegmentPrefetcher: failed to fetch segment', [
                'url' => $segmentUrl,
            ]);
            return;
        }

        $size = strlen($data);

        // Evict old entries if needed to make room
        while ($this->currentCacheSize + $size > $this->maxCacheSize && !empty($this->cacheOrder)) {
            $this->evictOldest();
        }

        // Don't cache if single segment exceeds max cache size
        if ($size > $this->maxCacheSize) {
            $this->logger?->warning('HlsSegmentPrefetcher: segment too large to cache', [
                'url' => $segmentUrl,
                'size' => $size,
            ]);
            return;
        }

        $this->segmentCache[$cacheKey] = [
            'data' => $data,
            'timestamp' => time(),
        ];
        $this->cacheOrder[$cacheKey] = $this->nextCacheOrder++;
        $this->currentCacheSize += $size;
    }

    /**
     * Get the URL for a prefetched segment (cache hit).
     *
     * @param string $url Original segment URL.
     *
     * @return string|null Segment data or null if not cached.
     *
     * @since 0.12.0
     */
    public function getSegment(string $url): ?string
    {
        $cacheKey = $this->getCacheKey($url);

        if (!isset($this->segmentCache[$cacheKey])) {
            return null;
        }

        $cached = $this->segmentCache[$cacheKey];

        // Check if expired
        if (time() - $cached['timestamp'] >= $this->ttlSeconds) {
            $this->removeFromCache($cacheKey);
            return null;
        }

        // Update LRU order
        $this->cacheOrder[$cacheKey] = $this->nextCacheOrder++;

        return $cached['data'];
    }

    /**
     * Start prefetching for a channel (background).
     *
     * Uses a Workerman timer to periodically fetch the next segments
     * from the variant playlist.
     *
     * @param string $sessionId           Session ID for this prefetch task.
     * @param string $variantPlaylistUrl   URL of the variant playlist.
     * @param int|null $maxBandwidthKbps  Optional max bandwidth in kbps to limit prefetch to renditions at or below this rate.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function startPrefetch(string $sessionId, string $variantPlaylistUrl, ?int $maxBandwidthKbps = null): void
    {
        // Stop any existing prefetch for this session
        $this->stopPrefetch($sessionId);

        // Initial prefetch (respecting bandwidth limit if set)
        $this->prefetchWithBandwidth($variantPlaylistUrl, $maxBandwidthKbps);

        // Schedule periodic prefetch (every 5 seconds)
        $interval = 5.0;
        $timerId = Timer::add($interval, function () use ($variantPlaylistUrl, $maxBandwidthKbps): void {
            $this->prefetchWithBandwidth($variantPlaylistUrl, $maxBandwidthKbps);
        });

        $this->prefetchTimers[$sessionId] = $timerId;

        $this->logger?->info('HlsSegmentPrefetcher: started prefetch', [
            'session_id' => $sessionId,
            'playlist_url' => $variantPlaylistUrl,
            'prefetch_segments' => $this->prefetchSegments,
            'max_bandwidth_kbps' => $maxBandwidthKbps,
        ]);
    }

    /**
     * Prefetch segments from a variant playlist, optionally filtered by bandwidth.
     *
     * When maxBandwidthKbps is set, only prefetches renditions at or below that
     * bandwidth. This is used when a client has a bandwidth preference and we
     * want to prefetch only suitable renditions.
     *
     * @param string $variantPlaylistUrl  URL of the variant playlist (or master playlist for bandwidth filtering).
     * @param int|null $maxBandwidthKbps  Optional max bandwidth in kbps to filter renditions.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function prefetchWithBandwidth(string $variantPlaylistUrl, ?int $maxBandwidthKbps = null): void
    {
        if ($maxBandwidthKbps === null) {
            $this->prefetch($variantPlaylistUrl);
            return;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Phlix Media Server/1.0',
            ],
        ]);

        $playlistContent = @file_get_contents($variantPlaylistUrl, false, $context);
        if ($playlistContent === false) {
            $this->logger?->warning('HlsSegmentPrefetcher: failed to fetch playlist for bandwidth prefetch', [
                'url' => $variantPlaylistUrl,
            ]);
            return;
        }

        // Parse the playlist to find available renditions and their bandwidths
        $renditions = $this->parseRenditionsFromPlaylist($playlistContent, $variantPlaylistUrl);

        // Filter renditions to only those at or below max bandwidth
        $maxBandwidthBps = $maxBandwidthKbps * 1000;
        $filteredRenditions = [];
        foreach ($renditions as $rendition) {
            if ($rendition['bandwidth'] <= $maxBandwidthBps) {
                $filteredRenditions[] = $rendition;
            }
        }

        // Prefetch segments from filtered renditions (up to prefetchSegments total)
        $segmentsToFetch = [];
        foreach ($filteredRenditions as $rendition) {
            $variantSegments = $this->parsePlaylistSegments($rendition['playlist_url'], $variantPlaylistUrl);
            $segmentsToFetch = array_merge($segmentsToFetch, array_slice($variantSegments, 0, (int) ceil($this->prefetchSegments / count($filteredRenditions))));
            if (count($segmentsToFetch) >= $this->prefetchSegments) {
                break;
            }
        }

        $segmentsToFetch = array_slice($segmentsToFetch, 0, $this->prefetchSegments);

        foreach ($segmentsToFetch as $segmentUrl) {
            $this->fetchAndCacheSegment($segmentUrl);
        }
    }

    /**
     * Parse renditions from a master playlist content.
     *
     * Returns an array of rendition info including bandwidth and playlist URL.
     *
     * @param string $playlistContent The master playlist m3u8 content.
     * @param string $baseUrl         Base URL for resolving relative segment URLs.
     *
     * @return array<int, array{bandwidth: int, playlist_url: string}> Array of rendition info.
     *
     * @since 0.12.0
     */
    private function parseRenditionsFromPlaylist(string $playlistContent, string $baseUrl): array
    {
        $renditions = [];
        $lines = explode("\n", $playlistContent);
        $baseDir = dirname($baseUrl);
        $i = 0;
        $totalLines = count($lines);

        while ($i < $totalLines) {
            $line = trim($lines[$i]);

            // Check if this is a variant stream info line
            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $bandwidth = null;
                if (preg_match('/BANDWIDTH=(\d+)/', $line, $matches)) {
                    $bandwidth = (int) $matches[1];
                }

                // Find the URL line that follows
                $i++;
                while ($i < $totalLines) {
                    $urlLine = trim($lines[$i]);
                    if ($urlLine === '') {
                        $i++;
                        continue;
                    }
                    if (str_starts_with($urlLine, '#')) {
                        // Comments before URL are part of the variant, keep them
                        $i++;
                        continue;
                    }

                    // This is the URL - resolve relative URLs
                    if (!str_starts_with($urlLine, '/') && !preg_match('/^https?:/', $urlLine)) {
                        $urlLine = rtrim($baseDir, '/') . '/' . $urlLine;
                    }

                    if ($bandwidth !== null) {
                        $renditions[] = [
                            'bandwidth' => $bandwidth,
                            'playlist_url' => $urlLine,
                        ];
                    }
                    break;
                }
            }
            $i++;
        }

        return $renditions;
    }

    /**
     * Stop prefetching for a session.
     *
     * @param string $sessionId Session ID to stop prefetching for.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function stopPrefetch(string $sessionId): void
    {
        if (isset($this->prefetchTimers[$sessionId])) {
            Timer::del($this->prefetchTimers[$sessionId]);
            unset($this->prefetchTimers[$sessionId]);

            $this->logger?->info('HlsSegmentPrefetcher: stopped prefetch', [
                'session_id' => $sessionId,
            ]);
        }
    }

    /**
     * Evict the oldest entry from the cache.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function evictOldest(): void
    {
        if (empty($this->cacheOrder)) {
            return;
        }

        // Find oldest entry (lowest cache order value)
        asort($this->cacheOrder);
        $oldestKey = array_key_first($this->cacheOrder);

        if ($oldestKey !== null) {
            $this->removeFromCache($oldestKey);
        }
    }

    /**
     * Remove an entry from the cache.
     *
     * @param string $cacheKey Cache key to remove.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function removeFromCache(string $cacheKey): void
    {
        if (isset($this->segmentCache[$cacheKey])) {
            $this->currentCacheSize -= strlen($this->segmentCache[$cacheKey]['data']);
            unset($this->segmentCache[$cacheKey]);
        }

        if (isset($this->cacheOrder[$cacheKey])) {
            unset($this->cacheOrder[$cacheKey]);
        }
    }

    /**
     * Generate a cache key from a URL.
     *
     * @param string $url URL to hash.
     *
     * @return string Cache key.
     *
     * @since 0.12.0
     */
    private function getCacheKey(string $url): string
    {
        return hash('sha256', $url);
    }

    /**
     * Clear all cached segments.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function clearCache(): void
    {
        $this->segmentCache = [];
        $this->cacheOrder = [];
        $this->currentCacheSize = 0;
    }

    /**
     * Get current cache statistics.
     *
     * @return array{size_bytes: int, max_size_bytes: int, entries: int} Cache statistics.
     *
     * @since 0.12.0
     */
    public function getCacheStats(): array
    {
        return [
            'size_bytes' => $this->currentCacheSize,
            'max_size_bytes' => $this->maxCacheSize,
            'entries' => count($this->segmentCache),
        ];
    }
}
