<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

use Phlix\Auth\WatchHistory;
use Phlix\Media\Library\MediaItem;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

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
    /** Items requested per watched-history page (Trakt caps a page at 1000). */
    private const WATCHED_HISTORY_PAGE_SIZE = 100;

    /**
     * Defensive upper bound on watched-history pages fetched per sync run.
     *
     * Normal termination is by the reported `X-Pagination-Page-Count` and/or a
     * short final page; this cap only exists so a missing/malformed pagination
     * header can never spin the page loop forever. At the page size above it
     * covers up to 20,000 watched items — well beyond any realistic account —
     * and a hit is logged.
     */
    private const MAX_HISTORY_PAGES = 200;

    /**
     * Coroutine-safe backoff between watched-history pages, in milliseconds.
     *
     * A small proactive pause (on top of the 429 exponential backoff inside
     * {@see TraktApi::getWatchedHistory()}) to stay gentle on Trakt's rate limit
     * when walking a multi-page history.
     */
    private const INTER_PAGE_DELAY_MS = 250;

    private readonly LoggerInterface $logger;

    /**
     * @param TraktApi $api Trakt API client
     * @param WatchHistory $watchHistory Local watch history repository
     * @param TraktSettings $settings User settings
     * @param Connection $db Database connection for media item lookups
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     */
    public function __construct(
        private readonly TraktApi $api,
        private readonly WatchHistory $watchHistory,
        private readonly TraktSettings $settings,
        private readonly Connection $db,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Sync Trakt watched history + in-progress playback → Phlix local history.
     *
     * Reconciles two Trakt sources into the local {@see WatchHistory} model:
     *
     *  1. Watched history (`/users/{user}/watched`) → fully-watched entries are
     *     marked completed locally.
     *  2. Playback progress (`/sync/playback`) → in-progress entries write a
     *     resume position (position/duration ticks + the model's derived
     *     percent), NOT a forced 100%.
     *
     * Both sources are guarded by last-write-wins: a Trakt entry only overwrites
     * the local record when the Trakt timestamp (`watched_at` / `paused_at`) is
     * newer than the local `last_watched_at`, so a periodic pull can never roll
     * back a fresher local resume position. A locally-completed item is never
     * downgraded to in-progress.
     *
     * Each source fetch is independently fault-tolerant: a failure fetching one
     * source (or exhausting its rate-limit backoff) is logged and contributes 0,
     * without discarding the other source's writes.
     *
     * @param string $profileId Profile ID to sync history for
     *
     * @return int Number of local entries written (completions + resumes)
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

        $written = $this->reconcileWatchedHistory($profileId)
            + $this->reconcilePlaybackProgress($profileId);

        $this->logger->info('TraktHistorySync: completed Trakt→Phlix sync', [
            'profile_id' => $profileId,
            'items_written' => $written,
        ]);

        return $written;
    }

    /**
     * Reconcile Trakt fully-watched history into local completions.
     *
     * Walks the user's full watched history one page at a time (SV-3.6d) rather
     * than only the first 100 items: it fetches page 1, learns the total page
     * count from Trakt's `X-Pagination-Page-Count` header (surfaced by
     * {@see TraktApi::getWatchedHistory()}), then fetches pages 2..N, reconciling
     * every page with the same last-write-wins logic. Termination is defensive
     * and layered: the reported page count bounds the loop, a short/empty final
     * page ends it early (and covers a missing/malformed header via
     * loop-until-short-page), and a hard {@see self::MAX_HISTORY_PAGES} cap
     * guarantees the loop can never spin forever. A coroutine-safe backoff is
     * inserted between page fetches (mirroring the 429 idiom in
     * {@see TraktApi::getWatchedHistory()}) to stay gentle on the rate limit.
     *
     * A fetch failure on any page is logged and ends the walk, preserving the
     * reconciliations already written by earlier pages rather than discarding
     * them.
     *
     * @param string $profileId Profile to reconcile into.
     *
     * @return int Number of entries marked completed across all pages.
     */
    private function reconcileWatchedHistory(string $profileId): int
    {
        $limit = self::WATCHED_HISTORY_PAGE_SIZE;
        $written = 0;
        $page = 1;
        // Effective upper bound; kept at the cap until (and unless) page 1's
        // header narrows it, so a missing header falls back to short-page.
        $upperBound = self::MAX_HISTORY_PAGES;

        while (true) {
            try {
                $result = $this->api->getWatchedHistory(
                    $this->settings->username,
                    $page,
                    $limit,
                    $this->settings->accessToken ?? ''
                );
            } catch (TraktApiException $e) {
                $this->logger->warning('TraktHistorySync: failed to fetch Trakt watched history page', [
                    'page' => $page,
                    'items_written_so_far' => $written,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            $items = $result['items'];

            if ($page === 1) {
                $reported = $result['pageCount'];
                if ($reported >= 1) {
                    $upperBound = min($reported, self::MAX_HISTORY_PAGES);
                    if ($reported > self::MAX_HISTORY_PAGES) {
                        $this->logger->warning(
                            'TraktHistorySync: watched-history page count exceeds cap, truncating',
                            [
                                'reported_page_count' => $reported,
                                'cap' => self::MAX_HISTORY_PAGES,
                            ]
                        );
                    }
                }
                // $reported < 1 (header absent/unparseable): keep the cap as the
                // ceiling and rely on the short-page check below to terminate.
            }

            $written += $this->reconcileWatchedPage($profileId, $items);

            // A short/empty page is the last page regardless of any reported
            // count — this also terminates the loop-until-short-page fallback
            // when the pagination header was missing.
            if (count($items) < $limit) {
                break;
            }

            if ($page >= $upperBound) {
                if ($page >= self::MAX_HISTORY_PAGES) {
                    $this->logger->warning('TraktHistorySync: hit max watched-history page cap', [
                        'cap' => self::MAX_HISTORY_PAGES,
                        'items_written' => $written,
                    ]);
                }
                break;
            }

            $page++;
            // Coroutine-safe backoff before the next page fetch.
            $this->sleepBetweenPages();
        }

        return $written;
    }

    /**
     * Reconcile a single page of Trakt watched-history items into completions.
     *
     * Applies the same per-item last-write-wins reconciliation used before the
     * SV-3.6d page loop was added: for each item that maps to a local media item
     * and is not already completed locally, marks the local entry completed —
     * unless an older Trakt watch would clobber a fresher local in-progress
     * position.
     *
     * @param string $profileId Profile to reconcile into.
     * @param array<mixed> $items One page of Trakt watched-history items.
     *
     * @return int Number of entries marked completed on this page.
     */
    private function reconcileWatchedPage(string $profileId, array $items): int
    {
        $written = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mediaItemId = $this->findMediaItemId($item);
            if ($mediaItemId === null) {
                continue;
            }

            $itemMap = $this->stringKeyedMap($item);
            $existing = $this->watchHistory->getForMediaItem($profileId, $mediaItemId);

            // Idempotent: already completed locally — nothing to upgrade.
            if ($existing !== null && $this->isLocallyCompleted($existing)) {
                continue;
            }

            // Last-write-wins: do not clobber a fresher local (in-progress)
            // position with an older Trakt "watched" event.
            $watchedAt = $this->parseWatchedAt($itemMap);
            if (!$this->traktSupersedes($watchedAt, $existing)) {
                $this->logger->debug('TraktHistorySync: local record newer than Trakt watch, skipping', [
                    'media_item_id' => $mediaItemId,
                ]);
                continue;
            }

            // Trakt's /watched response omits `runtime` (no extended=full), so
            // the duration is usually unknown here. Pass null (not 0) for an
            // unknown duration so updateProgress's `COALESCE(?, duration_ticks)`
            // PRESERVES any previously-known duration when upgrading an
            // in-progress row to completed — matching WatchHistory::markCompleted()
            // (position 0, duration null → status='completed'/progress=0, the
            // "watched" shape the app already uses). A 0 would satisfy COALESCE
            // as a real value and clobber the stored duration. When a runtime IS
            // present it is used as both position and duration (a fully-watched
            // 100% shape).
            $durationTicks = $this->extractDurationTicks($itemMap);
            $knownDuration = $durationTicks > 0 ? $durationTicks : null;
            $this->watchHistory->updateProgress(
                $profileId,
                $mediaItemId,
                $knownDuration ?? 0,
                $knownDuration,
                WatchHistory::STATUS_COMPLETED
            );

            $written++;
            $this->logger->debug('TraktHistorySync: marked completed from Trakt history', [
                'media_item_id' => $mediaItemId,
                'watched_at' => $watchedAt->format('c'),
            ]);
        }

        return $written;
    }

    /**
     * Coroutine-safe pause between watched-history page fetches.
     *
     * Mirrors the exact 429-backoff idiom in {@see TraktApi::getWatchedHistory()}:
     * `\Co\sleep` yields to the event loop so the resident worker keeps serving
     * other connections during the pause (the pull runs inside the Swoole
     * Coroutine::create() timer callback wired in start.php); `usleep` is the
     * fallback for non-Swoole contexts (unit tests / plain CLI).
     */
    private function sleepBetweenPages(): void
    {
        if (function_exists('\Co\sleep')) {
            \Co\sleep(self::INTER_PAGE_DELAY_MS / 1_000);
        } else {
            usleep(self::INTER_PAGE_DELAY_MS * 1_000);
        }
    }

    /**
     * Reconcile Trakt in-progress playback into local resume positions.
     *
     * Writes the resume position (position/duration ticks) for items Trakt
     * reports as in-progress rather than a forced completion. Guarded by
     * last-write-wins and never downgrades a locally-completed item.
     *
     * A resume position is only meaningful with a known absolute duration.
     * Trakt's playback response omits runtime unless `extended=full` is
     * requested (this pull does not request it, mirroring getWatchedHistory),
     * so the duration is taken from a locally-known source (a prior local play's
     * `duration_ticks`, or the scanner's `metadata_json.duration_seconds`).
     * When no local duration is available the item is skipped rather than
     * persisting a bogus seek position.
     *
     * @param string $profileId Profile to reconcile into.
     *
     * @return int Number of resume positions written.
     */
    private function reconcilePlaybackProgress(string $profileId): int
    {
        try {
            $playback = $this->api->getPlaybackProgress($this->settings->accessToken ?? '');
        } catch (TraktApiException $e) {
            $this->logger->warning('TraktHistorySync: failed to fetch Trakt playback progress', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        $written = 0;

        foreach ($playback as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mediaItemId = $this->findMediaItemId($item);
            if ($mediaItemId === null) {
                continue;
            }

            $itemMap = $this->stringKeyedMap($item);

            $progressPercent = $this->extractProgressPercent($itemMap);
            if ($progressPercent === null || $progressPercent <= 0.0) {
                continue;
            }

            $existing = $this->watchHistory->getForMediaItem($profileId, $mediaItemId);

            // Never downgrade a locally-completed item back to in-progress.
            if ($existing !== null && $this->isLocallyCompleted($existing)) {
                continue;
            }

            // Last-write-wins: do not overwrite a fresher local position with an
            // older Trakt paused_at.
            $pausedAt = $this->parsePausedAt($itemMap);
            if (!$this->traktSupersedes($pausedAt, $existing)) {
                continue;
            }

            $durationTicks = $this->resolvePlaybackDurationTicks($mediaItemId, $existing);
            if ($durationTicks <= 0) {
                $this->logger->debug('TraktHistorySync: no known duration, skipping resume position', [
                    'media_item_id' => $mediaItemId,
                    'trakt_progress' => $progressPercent,
                ]);
                continue;
            }

            $positionTicks = (int) round($durationTicks * ($progressPercent / 100.0));
            $this->watchHistory->updateProgress(
                $profileId,
                $mediaItemId,
                $positionTicks,
                $durationTicks,
                WatchHistory::STATUS_PAUSED
            );

            $written++;
            $this->logger->debug('TraktHistorySync: wrote resume position from Trakt', [
                'media_item_id' => $mediaItemId,
                'progress_percent' => $progressPercent,
                'paused_at' => $pausedAt->format('c'),
            ]);
        }

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
    public function syncPhlixToTrakt(
        string $mediaItemId,
        string $lastWatchedAt,
        int $positionTicks,
        ?int $durationTicks
    ): bool {
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
     * Matches TMDB, TVDB, or IMDB IDs from the Trakt history item against
     * the local media_items.metadata_json column to resolve the local ID.
     *
     * @param array<mixed, mixed> $item Trakt history item with movie or episode ids
     *
     * @return string|null Local media_items.id if resolved, null otherwise.
     */
    private function findMediaItemId(array $item): ?string
    {
        $movie = $item['movie'] ?? null;
        $episode = $item['episode'] ?? null;

        $ids = null;
        if (is_array($movie) && isset($movie['ids']) && is_array($movie['ids'])) {
            $ids = $movie['ids'];
        } elseif (is_array($episode) && isset($episode['ids']) && is_array($episode['ids'])) {
            $ids = $episode['ids'];
        }

        if ($ids === null) {
            return null;
        }

        // Early exit for pre-resolved ID (test seam)
        if (isset($item['_resolved_media_item_id']) && is_string($item['_resolved_media_item_id'])) {
            return $item['_resolved_media_item_id'];
        }

        // TMDB is most reliable for movies, TVDB for shows, IMDB is universal fallback
        foreach (['tmdb', 'tvdb', 'imdb'] as $idType) {
            if (!isset($ids[$idType])) {
                continue;
            }
            $externalIdRaw = $ids[$idType];
            if (!is_scalar($externalIdRaw) || (is_string($externalIdRaw) && $externalIdRaw === '')) {
                continue;
            }

            $mediaItemId = $this->findMediaItemIdByExternalId($idType, (string) $externalIdRaw);
            if ($mediaItemId !== null) {
                return $mediaItemId;
            }
        }

        return null;
    }

    /**
     * Look up a local media item ID by external ID.
     *
     * The metadata_json column stores external IDs like:
     * {"tmdb_id": "123", "imdb_id": "tt123", "tvdb_id": "456"}
     *
     * Uses JSON_EXTRACT (MySQL 5.7+) for reliable extraction with
     * parameterized queries to prevent SQL injection.
     *
     * @param string $idType tmdb, tvdb, or imdb
     * @param string $externalId The external ID value
     *
     * @return string|null Local media_items.id if found, null otherwise.
     */
    private function findMediaItemIdByExternalId(string $idType, string $externalId): ?string
    {
        // Use JSON_EXTRACT with parameterized query for safe lookup
        $jsonPath = '$.' . $idType . '_id';
        $result = $this->db->query(
            "SELECT id FROM media_items WHERE JSON_EXTRACT(metadata_json, ?) = ? LIMIT 1",
            [$jsonPath, $externalId]
        );

        if (!is_array($result)) {
            return null;
        }

        $row = $result[0] ?? null;
        if (!is_array($row) || !array_key_exists('id', $row)) {
            return null;
        }

        $id = $row['id'];
        if (!is_string($id) && !is_numeric($id)) {
            return null;
        }

        return (string) $id;
    }

    /**
     * Narrow a raw Trakt item to a string-keyed map for timestamp/field parsing.
     *
     * @param array<mixed, mixed> $item Raw Trakt item (may carry int keys).
     *
     * @return array<string, mixed>
     */
    private function stringKeyedMap(array $item): array
    {
        $map = [];
        foreach ($item as $key => $value) {
            if (is_string($key)) {
                $map[$key] = $value;
            }
        }
        return $map;
    }

    /**
     * Parse the `watched_at` timestamp from a Trakt history item.
     *
     * @param array<string, mixed> $item Trakt history item
     *
     * @return \DateTimeImmutable
     */
    private function parseWatchedAt(array $item): \DateTimeImmutable
    {
        return $this->parseTraktTimestamp($item, 'watched_at');
    }

    /**
     * Parse the `paused_at` timestamp from a Trakt playback item.
     *
     * @param array<string, mixed> $item Trakt playback item
     *
     * @return \DateTimeImmutable
     */
    private function parsePausedAt(array $item): \DateTimeImmutable
    {
        return $this->parseTraktTimestamp($item, 'paused_at');
    }

    /**
     * Parse an ISO-8601 timestamp field from a Trakt item.
     *
     * Falls back to "now" for a missing/invalid value, preserving the prior
     * defensive-write behavior: a malformed Trakt timestamp is treated as the
     * newest, so the item is not silently dropped by last-write-wins.
     *
     * @param array<string, mixed> $item Trakt item
     * @param string $field Field name (`watched_at` or `paused_at`)
     *
     * @return \DateTimeImmutable
     */
    private function parseTraktTimestamp(array $item, string $field): \DateTimeImmutable
    {
        $value = $item[$field] ?? null;
        if (is_string($value) && $value !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
            }
        }
        return new \DateTimeImmutable();
    }

    /**
     * Whether a Trakt event should overwrite the local record (last-write-wins).
     *
     * Only overwrites when the Trakt timestamp is strictly newer than the local
     * `last_watched_at`, so a periodic pull never rolls back a fresher local
     * resume position.
     *
     * Limitation: the local `last_watched_at` is a naive DATETIME written by
     * {@see WatchHistory::updateProgress()} via `date()` in PHP's default
     * timezone, while Trakt timestamps carry an explicit UTC offset; both parse
     * to correct absolute instants as long as the process default timezone is
     * stable between the local write and this read (it is within a deployment).
     * If the local model has no comparable timestamp the guard defers to the
     * caller's status guards (which already refuse to downgrade a completed
     * item), so the fallback stays defensively correct.
     *
     * @param \DateTimeImmutable $traktTs Trakt event timestamp.
     * @param array<string, mixed>|null $existing Local watch-history entry, if any.
     *
     * @return bool True when the Trakt event supersedes the local record.
     */
    private function traktSupersedes(\DateTimeImmutable $traktTs, ?array $existing): bool
    {
        if ($existing === null) {
            return true;
        }
        $localTs = $this->parseLocalTimestamp($existing);
        if ($localTs === null) {
            return true;
        }
        return $traktTs > $localTs;
    }

    /**
     * Parse the local `last_watched_at` timestamp from a watch-history entry.
     *
     * @param array<string, mixed> $existing Local watch-history entry.
     *
     * @return \DateTimeImmutable|null Parsed timestamp, or null when absent/invalid.
     */
    private function parseLocalTimestamp(array $existing): ?\DateTimeImmutable
    {
        $raw = $existing['last_watched_at'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Whether a local watch-history entry is already completed.
     *
     * Checks the explicit completed status (covers manual "mark watched", which
     * carries progress_percent 0) as well as the progress threshold.
     *
     * @param array<string, mixed> $existing Local watch-history entry.
     *
     * @return bool
     */
    private function isLocallyCompleted(array $existing): bool
    {
        $status = $existing['playback_status'] ?? null;
        if (is_string($status) && $status === WatchHistory::STATUS_COMPLETED) {
            return true;
        }
        $progress = $existing['progress_percent'] ?? null;
        return is_numeric($progress) && (float) $progress >= WatchHistory::COMPLETED_THRESHOLD;
    }

    /**
     * Extract the in-progress percent (0-100) from a Trakt playback item.
     *
     * @param array<string, mixed> $item Trakt playback item.
     *
     * @return float|null Clamped percent, or null when absent/invalid.
     */
    private function extractProgressPercent(array $item): ?float
    {
        $raw = $item['progress'] ?? null;
        if (!is_numeric($raw)) {
            return null;
        }
        $percent = (float) $raw;
        if ($percent < 0.0) {
            return null;
        }
        return min($percent, 100.0);
    }

    /**
     * Resolve an absolute duration (ticks) for a resume-position computation.
     *
     * Priority: the local entry's stored `duration_ticks` (from a prior play),
     * then the scanner's `media_items.metadata_json.duration_seconds`. Returns 0
     * when no local duration is known (the caller then skips the item).
     *
     * @param string $mediaItemId Resolved local media item id.
     * @param array<string, mixed>|null $existing Local watch-history entry, if any.
     *
     * @return int Duration in ticks (0 when unknown).
     */
    private function resolvePlaybackDurationTicks(string $mediaItemId, ?array $existing): int
    {
        if ($existing !== null) {
            $stored = $existing['duration_ticks'] ?? null;
            if (is_numeric($stored) && (int) $stored > 0) {
                return (int) $stored;
            }
        }
        return $this->lookupDurationTicksFromMetadata($mediaItemId);
    }

    /**
     * Look up a media item's duration (ticks) from its scanned metadata.
     *
     * Reads `metadata_json.$.duration_seconds` (populated by the scanner from the
     * ffprobe container duration) and converts seconds → ticks.
     *
     * @param string $mediaItemId Local media_items.id.
     *
     * @return int Duration in ticks (0 when unknown).
     */
    private function lookupDurationTicksFromMetadata(string $mediaItemId): int
    {
        $result = $this->db->query(
            "SELECT JSON_EXTRACT(metadata_json, '$.duration_seconds') AS dur
             FROM media_items WHERE id = ? LIMIT 1",
            [$mediaItemId]
        );

        if (!is_array($result)) {
            return 0;
        }
        $row = $result[0] ?? null;
        if (!is_array($row)) {
            return 0;
        }
        $dur = $row['dur'] ?? null;
        if (!is_numeric($dur)) {
            return 0;
        }
        $seconds = (int) $dur;
        return $seconds > 0 ? $seconds * WatchHistory::TICKS_PER_SECOND : 0;
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
