<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

use Phlix\Auth\WatchHistory;
use Phlix\Media\Library\MediaItem;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles two-way watch history sync between Trakt and Phlix.
 *
 * - Trakt → Phlix: Pulls Trakt watched history on schedule and writes
 *   new entries to local WatchHistory for items not yet at ≥ 90% complete.
 * - Phlix → Trakt: Pushes local WatchHistory entries (≥ 90%) to Trakt
 *   after PlaybackStopped events where completion threshold was met.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
class TraktHistorySync
{
    private readonly LoggerInterface $logger;

    /**
     * @param TraktApi $api Trakt API client
     * @param WatchHistory $watchHistory Local watch history repository
     * @param TraktSettings $settings User settings
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     */
    public function __construct(
        private readonly TraktApi $api,
        private readonly WatchHistory $watchHistory,
        private readonly TraktSettings $settings,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Sync Trakt watched history → Phlix local history.
     *
     * Pulls the user's watched history from Trakt and compares against
     * local WatchHistory entries. For items watched on Trakt but not yet
     * ≥ 90% complete in Phlix, writes a local entry.
     *
     * Uses last-write-wins based on watchedAt timestamp.
     *
     * @param string $profileId Profile ID to sync history for
     *
     * @return int Number of new entries written
     *
     * @since 0.14.0
     */
    public function syncTraktToPhlix(string $profileId): int
    {
        if (!$this->settings->isConfigured()) {
            $this->logger->debug('TraktHistorySync: plugin not configured, skipping Trakt→Phlix');
            return 0;
        }

        if (!$this->settings->syncEnabled) {
            $this->logger->debug('TraktHistorySync: sync disabled, skipping Trakt→Phlix');
            return 0;
        }

        try {
            $history = $this->api->getWatchedHistory(
                $this->settings->username,
                1,
                100,
                $this->settings->accessToken ?? ''
            );
        } catch (TraktApiException $e) {
            $this->logger->warning('TraktHistorySync: failed to fetch Trakt history', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        $written = 0;

        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mediaItemId = $this->findMediaItemId($item);
            if ($mediaItemId === null) {
                continue;
            }

            $itemMap = [];
            foreach ($item as $iKey => $iValue) {
                if (is_string($iKey)) {
                    $itemMap[$iKey] = $iValue;
                }
            }

            $existing = $this->watchHistory->getForMediaItem($profileId, $mediaItemId);

            if ($existing !== null && $existing['progress_percent'] >= WatchHistory::COMPLETED_THRESHOLD) {
                $this->logger->debug('TraktHistorySync: item already at 90%+, skipping', [
                    'media_item_id' => $mediaItemId,
                    'progress' => $existing['progress_percent'],
                ]);
                continue;
            }

            $watchedAt = $this->parseWatchedAt($itemMap);
            $durationTicks = $this->extractDurationTicks($itemMap);

            $this->watchHistory->updateProgress(
                $profileId,
                $mediaItemId,
                $durationTicks,
                $durationTicks,
                WatchHistory::STATUS_COMPLETED
            );

            $written++;
            $this->logger->debug('TraktHistorySync: wrote entry from Trakt', [
                'media_item_id' => $mediaItemId,
                'watched_at' => $watchedAt->format('c'),
            ]);
        }

        $this->logger->info('TraktHistorySync: completed Trakt→Phlix sync', [
            'profile_id' => $profileId,
            'items_written' => $written,
        ]);

        return $written;
    }

    /**
     * Sync Phlix local history → Trakt.
     *
     * Pushes local WatchHistory entries that have reached ≥ 90%
     * completion to Trakt so the user gets credit for the watch.
     *
     * @param string $mediaItemId Media item that reached 90%+ completion
     * @param string $lastWatchedAt When the item was last watched
     * @param int $positionTicks Final playback position
     * @param int|null $durationTicks Total duration
     *
     * @return bool True when successfully pushed to Trakt
     *
     * @since 0.14.0
     */
    public function syncPhlixToTrakt(string $mediaItemId, string $lastWatchedAt, int $positionTicks, ?int $durationTicks): bool
    {
        if (!$this->settings->isConfigured()) {
            $this->logger->debug('TraktHistorySync: plugin not configured, skipping Phlix→Trakt');
            return false;
        }

        if (!$this->settings->syncEnabled) {
            $this->logger->debug('TraktHistorySync: sync disabled, skipping Phlix→Trakt');
            return false;
        }

        $existing = $this->watchHistory->getForMediaItem('default', $mediaItemId);
        if ($existing === null || $existing['progress_percent'] < WatchHistory::COMPLETED_THRESHOLD) {
            $this->logger->debug('TraktHistorySync: item below 90%, skipping Phlix→Trakt', [
                'media_item_id' => $mediaItemId,
                'progress' => $existing['progress_percent'] ?? 0,
            ]);
            return false;
        }

        try {
            $item = $this->buildMediaItem($mediaItemId, $existing);
            $watchedAt = new \DateTimeImmutable($lastWatchedAt);

            $this->api->addToHistory($item, $watchedAt, $this->settings->accessToken ?? '');

            $this->logger->info('TraktHistorySync: pushed to Trakt', [
                'media_item_id' => $mediaItemId,
            ]);

            return true;
        } catch (TraktApiException $e) {
            $this->logger->warning('TraktHistorySync: failed to push to Trakt', [
                'media_item_id' => $mediaItemId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Find a local media item ID from a Trakt history entry.
     *
     * Stub implementation: matching Trakt items back to local
     * `media_items` rows requires a TMDB/TVDB/IMDB-aware lookup that is
     * not yet implemented. Returns null for every input so the sync
     * loop is effectively a no-op until the lookup is wired up.
     *
     * @param array<mixed, mixed> $item Trakt history item
     *
     * @return string|null Local media_items.id if resolved, null otherwise.
     */
    private function findMediaItemId(array $item): ?string
    {
        // Future lookup: match $item['movie']['ids'] / $item['episode']['ids']
        // (TMDB/TVDB/IMDB) against the local media_items table. Until that is
        // wired up this stub returns null and the surrounding loop is a no-op.
        if (isset($item['_resolved_media_item_id']) && is_string($item['_resolved_media_item_id'])) {
            return $item['_resolved_media_item_id'];
        }
        return null;
    }

    /**
     * Parse watched_at timestamp from a Trakt history item.
     *
     * @param array<string, mixed> $item Trakt history item
     *
     * @return \DateTimeImmutable
     */
    private function parseWatchedAt(array $item): \DateTimeImmutable
    {
        $watchedAt = $item['watched_at'] ?? null;
        if ($watchedAt !== null && is_string($watchedAt)) {
            try {
                return new \DateTimeImmutable($watchedAt);
            } catch (\Exception) {
            }
        }
        return new \DateTimeImmutable();
    }

    /**
     * Extract duration in ticks from a Trakt history item.
     *
     * @param array<string, mixed> $item Trakt history item
     *
     * @return int Duration in ticks (0 if unknown)
     */
    private function extractDurationTicks(array $item): int
    {
        $runtime = $item['runtime'] ?? null;
        if (!is_numeric($runtime)) {
            $movie = $item['movie'] ?? null;
            if (is_array($movie) && isset($movie['runtime']) && is_numeric($movie['runtime'])) {
                $runtime = $movie['runtime'];
            } else {
                $episode = $item['episode'] ?? null;
                if (is_array($episode) && isset($episode['runtime']) && is_numeric($episode['runtime'])) {
                    $runtime = $episode['runtime'];
                }
            }
        }

        if (is_numeric($runtime)) {
            $seconds = (int)$runtime;
            return $seconds * WatchHistory::TICKS_PER_SECOND;
        }
        return 0;
    }

    /**
     * Build a MediaItem from local history data.
     *
     * @param string $mediaItemId Media item UUID
     * @param array<string, mixed> $historyEntry Local watch history entry
     *
     * @return MediaItem
     */
    private function buildMediaItem(string $mediaItemId, array $historyEntry): MediaItem
    {
        $name = is_string($historyEntry['media_name'] ?? null) ? $historyEntry['media_name'] : 'Unknown';
        $type = is_string($historyEntry['media_type'] ?? null) ? $historyEntry['media_type'] : 'movie';
        $path = '';
        $metadata = is_array($historyEntry['metadata'] ?? null) ? $historyEntry['metadata'] : [];

        return new MediaItem(
            id: $mediaItemId,
            name: $name,
            type: $type,
            path: $path,
            metadata: $metadata
        );
    }
}
