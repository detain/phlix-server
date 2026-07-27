<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Auth\Dto\UserRow;
use Phlix\Auth\Dto\WatchHistoryRow;
use Phlix\Common\Uuid;
use Phlix\Common\Util\RowMap;
use Phlix\Media\Library\MediaItemShaper;
use Phlix\Media\Library\NextUpSelector;
use Phlix\Media\RecommendationService;
use Workerman\MySQL\Connection;

/**
 * Manages watch history and progress tracking per profile.
 *
 * This class provides comprehensive watch history management including:
 * - Tracking playback progress per profile per media item
 * - Managing "continue watching" and "recently completed" queues
 * - Calculating watch time statistics (total, daily, by period)
 * - Resume position tracking for seamless playback continuation
 * - Automatic completion detection based on progress threshold
 *
 * Tick Format:
 * All position and duration values are in "ticks" - 100-nanosecond intervals.
 * This matches standard media formats (e.g., Matroska/EBML use 100ns ticks).
 * Conversion: ticks / 10000000 = seconds, ticks / 10000 = milliseconds
 *
 * Watch History Structure:
 * Each entry tracks a single media item's watch progress for a profile:
 * - position_ticks: Current playback position
 * - duration_ticks: Total media duration
 * - progress_percent: Calculated completion percentage (0-100)
 * - playback_status: Current state (playing, paused, stopped, completed)
 * - completed_at: Timestamp when progress reached completion threshold
 *
 * @package Phlix\Auth
 * @author Phlix Development Team
 * @license Proprietary
 *
 * @see UserProfileManager For profile-based access control and restrictions
 * @see PlaybackController For session-based playback state management
 */
class WatchHistory
{
    /**
     * Database connection instance.
     *
     * @var Connection
     */
    private Connection $db;

    /**
     * Recommendation service for computing because-you-watched recs (P4-S2).
     * Null when not wired — recommendation computation is best-effort.
     *
     * @var RecommendationService|null
     */
    private ?RecommendationService $recommendationService = null;

    /**
     * Playback status: Media is actively playing.
     *
     * @var string
     */
    public const STATUS_PLAYING = 'playing';

    /**
     * Playback status: Media is paused.
     *
     * @var string
     */
    public const STATUS_PAUSED = 'paused';

    /**
     * Playback status: Playback has been stopped.
     *
     * @var string
     */
    public const STATUS_STOPPED = 'stopped';

    /**
     * Playback status: Media has been completed.
     *
     * A media item is considered completed when progress_percent >= COMPLETED_THRESHOLD.
     *
     * @var string
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * Progress percentage threshold for marking as completed.
     *
     * When a media item's progress reaches or exceeds this percentage,
     * it is automatically marked as completed and the completed_at
     * timestamp is set.
     *
     * @var float
     */
    public const COMPLETED_THRESHOLD = 90.0;

    /**
     * Tick conversion factor: ticks to milliseconds.
     *
     * In this implementation, 1 tick = 0.1 milliseconds (100 microseconds).
     * Therefore 10 ticks = 1 millisecond, 10000 ticks = 1 second.
     *
     * @var int
     */
    public const TICKS_PER_MILLISECOND = 10;

    /**
     * Tick conversion factor: ticks to seconds.
     *
     * In this implementation, 1 tick = 0.1 milliseconds.
     * Therefore 10000 ticks = 1 second.
     *
     * @var int
     */
    public const TICKS_PER_SECOND = 10000;

    /**
     * Default pagination limit for history queries.
     *
     * @var int
     */
    public const DEFAULT_LIMIT = 50;

    /**
     * Default limit for continue watching queue.
     *
     * @var int
     */
    public const CONTINUE_WATCHING_LIMIT = 10;

    /**
     * Default limit for recently completed queue.
     *
     * @var int
     */
    public const RECENTLY_COMPLETED_LIMIT = 20;

    /**
     * Multiplier applied to the requested Next-Up pick `$limit` to bound how
     * many most-recently-touched STARTED series {@see getNextUp()} will scan.
     *
     * Each candidate series costs one blocking Query B round-trip
     * ({@see fetchSeriesEpisodes()}), so on this resident Workerman event loop
     * the scan MUST be bounded — otherwise a complete-watcher whose leading
     * series are all finished (finale watched → no pick) makes one sequential
     * blocking query per started series, bounded only by their total
     * started-series count. We over-scan the pick count (×3) so leading series
     * that yield no next-episode don't starve the rail, while capping the
     * worst-case fan-out. See {@see NEXT_UP_SERIES_SCAN_FLOOR}.
     *
     * @var int
     */
    public const NEXT_UP_SERIES_SCAN_MULTIPLIER = 3;

    /**
     * Absolute floor for the started-series scan cap, independent of `$limit`,
     * so small limits still over-scan enough candidates to reliably fill the
     * rail even when some leading series yield no next-episode.
     *
     * @var int
     */
    public const NEXT_UP_SERIES_SCAN_FLOOR = 50;

    /**
     * Constructs a new WatchHistory instance.
     *
     * @param Connection $db Database connection for watch history persistence
     * @param RecommendationService|null $recommendationService Optional recommendation
     *        engine for P4-S2 background recomputation on watch completion.
     */
    public function __construct(Connection $db, ?RecommendationService $recommendationService = null)
    {
        $this->db = $db;
        $this->recommendationService = $recommendationService;
    }

    /**
     * Get watch history entries for a profile.
     *
     * Returns paginated watch history including media metadata.
     * Results are ordered by most recently watched first.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param int $limit Maximum number of entries to return (default: 50)
     * @param int $offset Number of entries to skip for pagination (default: 0)
     *
     * @return list<array<string, mixed>> Array of watch history entries with media info.
     *         Each entry contains: id, profile_id, media_item_id, position_ticks,
     *         duration_ticks, playback_status, progress_percent, last_watched_at,
     *         created_at, completed_at, and optionally media_name, media_type,
     *         metadata, poster_url, thumbnail_url when JOINed.
     *
     * @see getContinueWatching() For in-progress items only
     * @see getRecentlyCompleted() For finished items only
     */
    public function getHistory(string $profileId, int $limit = 50, int $offset = 0): array
    {
        $results = $this->db->query(
            "SELECT wh.*, mi.name as media_name, mi.type as media_type, mi.metadata_json
             FROM watch_history wh
             JOIN media_items mi ON wh.media_item_id = mi.id
             WHERE wh.profile_id = ?
             ORDER BY wh.last_watched_at DESC
             LIMIT ? OFFSET ?",
            [$profileId, $limit, $offset]
        );

        $rows = RowMap::listFromMixed($results);
        return array_map(fn(array $r): array => $this->hydrateEntry($r), $rows);
    }

    /**
     * Get continue watching items for a profile.
     *
     * Returns items that are in progress but not yet completed.
     * Useful for displaying "Resume Watching" or "Continue Watching" sections.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param int $limit Maximum number of items to return (default: 10)
     *
     * @return list<array<string, mixed>> Array of in-progress watch entries with media info.
     *                          See getHistory() return format for structure.
     *
     * @see getRecentlyCompleted() For finished items
     * @see getHistory() For all history entries
     */
    public function getContinueWatching(string $profileId, int $limit = 10): array
    {
        $results = $this->db->query(
            "SELECT wh.*, mi.name as media_name, mi.type as media_type, mi.metadata_json
             FROM watch_history wh
             JOIN media_items mi ON wh.media_item_id = mi.id
             WHERE wh.profile_id = ?
               AND wh.playback_status != 'completed'
               AND wh.progress_percent > 0
               AND wh.progress_percent < ?
             ORDER BY wh.last_watched_at DESC
             LIMIT ?",
            [$profileId, self::COMPLETED_THRESHOLD, $limit]
        );

        $rows = RowMap::listFromMixed($results);
        return array_map(fn(array $r): array => $this->hydrateEntry($r), $rows);
    }

    /**
     * Get recently completed items for a profile.
     *
     * Returns items that have been watched to completion, ordered by
     * most recently completed first.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param int $limit Maximum number of items to return (default: 20)
     *
     * @return list<array<string, mixed>> Array of completed watch entries with media info.
     *                          See getHistory() return format for structure.
     *
     * @see getContinueWatching() For in-progress items
     * @see hasWatched() To check completion status only
     */
    public function getRecentlyCompleted(string $profileId, int $limit = 20): array
    {
        $results = $this->db->query(
            "SELECT wh.*, mi.name as media_name, mi.type as media_type, mi.metadata_json
             FROM watch_history wh
             JOIN media_items mi ON wh.media_item_id = mi.id
             WHERE wh.profile_id = ?
               AND wh.playback_status = 'completed'
             ORDER BY wh.completed_at DESC
             LIMIT ?",
            [$profileId, $limit]
        );

        $rows = RowMap::listFromMixed($results);
        return array_map(fn(array $r): array => $this->hydrateEntry($r), $rows);
    }

    /**
     * Get the "Next Up" episode picks for a profile (S36 · updates.md #43).
     *
     * Answers "which series has this profile started, and what is the next
     * unwatched episode?" — the classic Plex/Jellyfin "Next Up" rail that sits
     * beside Continue-Watching (which shows in-progress items; Next-Up shows the
     * FRESH episode you should start next).
     *
     * Design (binding — see plan_updates_worklog.md "[S36 DESIGN]"):
     * - The watched / in-progress signal is `playback_state` ONLY — NOT
     *   `user_item_data.watched` (a manual account-level toggle, never written by
     *   playback) and NOT the `watch_history` table (write-orphaned in prod).
     *   This reads `playback_state` exactly like the LIVE Continue-Watching rail
     *   ({@see \Phlix\Session\PlaybackController::getContinueWatching()}).
     * - `sessions.profile_id` is always NULL, so watch data cannot be segregated
     *   per profile — we resolve `profileId → userId` and scope by `user_id`. The
     *   caller ({@see \Phlix\Server\WebPortal\WebPortalRouter::getNextUp()}) then
     *   applies the active profile's parental RATING GATE as a post-filter, the
     *   same way the CW endpoint does.
     * - Hierarchy is `episode.parent_id → season → series`; season/episode numbers
     *   live in `metadata_json`. Ordering + next-picking is delegated to the pure
     *   {@see NextUpSelector} (a server-side port of phlix-ui `episode-order.ts`):
     *   numbered seasons only (Specials excluded), season asc, episode asc.
     *
     * @param string $profileId The active profile UUID (resolved to its user).
     * @param int    $limit      Max series to return next episodes for (1..50).
     *
     * @return list<array<string, mixed>> Shaped episode media items (signed
     *         artwork via {@see MediaItemShaper}), each annotated with its
     *         `series_id` / `series_name` and carrying the `position_ticks` /
     *         `duration_ticks` / `media_item_id` keys the CW response shape uses
     *         (position/duration are 0 — a Next-Up pick is a fresh episode).
     *         Ordered by each series' most-recent playback recency (most recently
     *         touched series first). Series whose finale is already watched
     *         contribute nothing.
     *
     * @see \Phlix\Session\PlaybackController::getContinueWatching() Sibling rail.
     * @see NextUpSelector Pure ordering + next-episode selection logic.
     */
    public function getNextUp(string $profileId, int $limit = 20): array
    {
        // Inline a validated int for the series cap; never bind LIMIT-like values
        // (emulated prepares 1064 on a bound LIMIT). Clamp defensively.
        $limit = max(1, min(50, $limit));

        $userId = $this->resolveUserIdFromProfile($profileId);
        if ($userId === null || $userId === '') {
            return [];
        }

        // Bound the candidate-series fan-out: each started series below costs one
        // blocking Query B ({@see fetchSeriesEpisodes()}) round-trip on this
        // resident event loop, so cap how many most-recently-touched series we
        // even consider. Over-scan the requested pick count so leading series that
        // yield no pick (finale already watched) don't starve the rail. NOTE: if
        // the cap is hit before $limit picks are collected, a complete-watcher
        // with more than the cap of fully-watched leading series may not see some
        // series beyond the cap — the intended bounded-work tradeoff.
        $scanCap = max($limit * self::NEXT_UP_SERIES_SCAN_MULTIPLIER, self::NEXT_UP_SERIES_SCAN_FLOOR);

        $startedSeries = $this->fetchStartedSeries($userId, $scanCap);
        if ($startedSeries === []) {
            return [];
        }

        $items = [];
        foreach ($startedSeries as $seriesRow) {
            $seriesId = UserRow::string($seriesRow, 'series_id');
            $mostRecentEpisodeId = UserRow::string($seriesRow, 'episode_id');
            if ($seriesId === null || $seriesId === '') {
                continue;
            }

            $episodeRows = $this->fetchSeriesEpisodes($userId, $seriesId);
            if ($episodeRows === []) {
                continue;
            }

            // Build the pure-selector input (season/episode numbers + watch state)
            // and a lookup back to the full DB row for shaping the chosen pick.
            $selectorInput = [];
            $rowById = [];
            foreach ($episodeRows as $row) {
                $id = UserRow::string($row, 'id');
                if ($id === null || $id === '') {
                    continue;
                }
                $meta = $this->decodeMetadata($row['metadata_json'] ?? null);
                $title = is_string($meta['episode_title'] ?? null) && ($meta['episode_title'] !== '')
                    ? $meta['episode_title']
                    : (UserRow::string($row, 'name') ?? '');

                $selectorInput[] = [
                    'id' => $id,
                    'season_number' => UserRow::intOrNull($meta, 'season'),
                    'episode_number' => UserRow::intOrNull($meta, 'episode'),
                    'title' => $title,
                    'state' => NextUpSelector::classify(
                        UserRow::string($row, 'playback_status'),
                        UserRow::int($row, 'position_ticks', 0),
                        UserRow::int($row, 'duration_ticks', 0),
                    ),
                ];
                $rowById[$id] = $row;
            }

            $next = NextUpSelector::pickNext($selectorInput, $mostRecentEpisodeId);
            if ($next === null) {
                continue;
            }

            $nextRow = $rowById[$next['id']] ?? null;
            if ($nextRow === null) {
                continue;
            }

            $items[] = $this->shapeNextEpisode($nextRow);

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * Query A (Next-Up): the distinct series a user has STARTED, most-recently
     * touched first, each with the id of its most-recent episode.
     *
     * Deduped with `ROW_NUMBER() OVER (PARTITION BY series ORDER BY updated_at
     * DESC, id DESC)` so a series with playback across many episodes/sessions
     * yields exactly one row (its latest touch). Scoped by `sessions.user_id`
     * (profile_id is always NULL). Episodes are resolved to their series via the
     * `episode → season → series` `parent_id` chain (INNER JOINs — an episode
     * without a full chain has no series and thus no "next in series").
     *
     * Capped at `$scanCap` most-recently-touched series (recency-ordered) so the
     * downstream one-query-per-series fan-out in {@see getNextUp()} is bounded on
     * this resident event loop — never the caller's total started-series count.
     *
     * @param string $userId  The resolved user UUID.
     * @param int    $scanCap Upper bound on candidate series returned (inlined as
     *                        a validated int LIMIT — see below).
     *
     * @return list<array<string, mixed>> Rows of `series_id`, `episode_id`,
     *         `updated_at`, ordered by recency (newest first), at most `$scanCap`.
     */
    private function fetchStartedSeries(string $userId, int $scanCap): array
    {
        // Inline a validated int for the scan-cap LIMIT; NEVER bind a LIMIT-like
        // value (emulated prepares raise a 1064 on a bound LIMIT in this repo —
        // the same reason getNextUp() inlines its clamped $limit). Clamp
        // defensively so a stray direct caller can never issue an unbounded scan.
        $scanCap = max(1, min(500, $scanCap));

        $result = $this->db->query(
            "SELECT ranked.series_id, ranked.episode_id, ranked.updated_at
             FROM (
                 SELECT s.id AS series_id,
                        ps.media_item_id AS episode_id,
                        ps.updated_at,
                        ROW_NUMBER() OVER (
                            PARTITION BY s.id
                            ORDER BY ps.updated_at DESC, ps.id DESC
                        ) AS rn
                 FROM playback_state ps
                 INNER JOIN sessions sess ON ps.session_id = sess.id
                 INNER JOIN media_items ep ON ps.media_item_id = ep.id AND ep.type = 'episode'
                 INNER JOIN media_items se ON ep.parent_id = se.id
                 INNER JOIN media_items s ON se.parent_id = s.id
                 WHERE sess.user_id = ?
             ) ranked
             WHERE ranked.rn = 1
             ORDER BY ranked.updated_at DESC
             LIMIT " . $scanCap,
            [$userId]
        );

        return RowMap::listFromMixed($result);
    }

    /**
     * Query B (Next-Up): every episode of a series, with this user's most-recent
     * `playback_state` per episode (LEFT JOIN — untouched episodes come back with
     * NULL playback columns = never played = an unwatched candidate).
     *
     * Deduped with `ROW_NUMBER() OVER (PARTITION BY episode ORDER BY updated_at
     * DESC, id DESC)` so multi-session playback collapses to the latest row. The
     * season + series `metadata_json` are projected so {@see shapeNextEpisode()}
     * can resolve the SERIES poster for the episode card (mirrors CW).
     *
     * @param string $userId   The resolved user UUID.
     * @param string $seriesId The series UUID.
     *
     * @return list<array<string, mixed>> One deduped row per episode.
     */
    private function fetchSeriesEpisodes(string $userId, string $seriesId): array
    {
        $result = $this->db->query(
            "SELECT ranked.id, ranked.name, ranked.type, ranked.path, ranked.parent_id,
                    ranked.created_at, ranked.updated_at, ranked.metadata_json,
                    ranked.parent_metadata_json, ranked.series_metadata_json,
                    ranked.series_id, ranked.series_name,
                    ranked.playback_status, ranked.position_ticks, ranked.duration_ticks
             FROM (
                 SELECT mi.id, mi.name, mi.type, mi.path, mi.parent_id,
                        mi.created_at, mi.updated_at, mi.metadata_json,
                        se.metadata_json AS parent_metadata_json,
                        s.metadata_json AS series_metadata_json,
                        s.id AS series_id,
                        s.name AS series_name,
                        ps.playback_status, ps.position_ticks, ps.duration_ticks,
                        ROW_NUMBER() OVER (
                            PARTITION BY mi.id
                            ORDER BY ps.updated_at DESC, ps.id DESC
                        ) AS rn
                 FROM media_items s
                 INNER JOIN media_items se ON se.parent_id = s.id
                 INNER JOIN media_items mi ON mi.parent_id = se.id AND mi.type = 'episode'
                 LEFT JOIN playback_state ps
                        ON ps.media_item_id = mi.id
                       AND ps.session_id IN (SELECT sx.id FROM sessions sx WHERE sx.user_id = ?)
                 WHERE s.id = ?
             ) ranked
             WHERE ranked.rn = 1",
            [$userId, $seriesId]
        );

        return RowMap::listFromMixed($result);
    }

    /**
     * Shape a chosen Next-Up episode row into the Continue-Watching response
     * shape (S37 consumes both rails identically).
     *
     * Resolves the SERIES poster (→ season poster → episode still) before
     * shaping — an episode's stored poster is a TMDB still, the wrong art for the
     * rail — exactly as {@see \Phlix\Session\PlaybackController::getContinueWatching()}
     * does, then re-mints the nested `metadata.poster_url` to the fresh signed
     * top-level value and attaches series context. A Next-Up pick is a fresh
     * episode, so `position_ticks` / `duration_ticks` are 0.
     *
     * @param array<string, mixed> $row A deduped episode row from {@see fetchSeriesEpisodes()}.
     *
     * @return array<string, mixed> The shaped episode media item.
     */
    private function shapeNextEpisode(array $row): array
    {
        $metadata = $this->decodeMetadata($row['metadata_json'] ?? null);

        $parentMeta = $this->decodeMetadata($row['parent_metadata_json'] ?? null);
        $seriesMeta = $this->decodeMetadata($row['series_metadata_json'] ?? null);
        $seriesPoster = is_string($seriesMeta['poster_url'] ?? null) ? $seriesMeta['poster_url'] : null;
        $seasonPoster = is_string($parentMeta['poster_url'] ?? null) ? $parentMeta['poster_url'] : null;
        if ($seriesPoster !== null && $seriesPoster !== '') {
            $metadata['poster_url'] = $seriesPoster;
        } elseif ($seasonPoster !== null && $seasonPoster !== '') {
            $metadata['poster_url'] = $seasonPoster;
        }

        $item = [
            'id' => UserRow::string($row, 'id') ?? '',
            'name' => UserRow::string($row, 'name') ?? '',
            'type' => UserRow::string($row, 'type') ?? 'episode',
            'path' => $row['path'] ?? null,
            'parent_id' => $row['parent_id'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'metadata' => $metadata,
        ];

        $shaped = MediaItemShaper::shape($item);

        // Re-mint the nested metadata poster to the fresh (re-signed) top-level
        // value, matching the CW rail so authless nested-poster loaders don't 401.
        if (is_string($shaped['poster_url'] ?? null) && ($shaped['poster_url'] !== '')) {
            $metadata['poster_url'] = $shaped['poster_url'];
        }
        $shaped['metadata'] = $metadata;

        // Match the Continue-Watching response shape. A Next-Up pick is fresh, so
        // there is no resume position.
        $shaped['position_ticks'] = 0;
        $shaped['duration_ticks'] = 0;
        $shaped['media_item_id'] = UserRow::string($row, 'id') ?? '';

        // Series context so the rail can label "Next Up: <Series> S02E01".
        $shaped['series_id'] = UserRow::string($row, 'series_id');
        $shaped['series_name'] = UserRow::string($row, 'series_name');

        return $shaped;
    }

    /**
     * Decode a `metadata_json` column into an associative array (empty on any
     * non-JSON / non-object value).
     *
     * @param mixed $raw The raw `metadata_json` column value.
     *
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get watch history entry for a specific media item on a profile.
     *
     * Returns the profile's watch progress for a single media item,
     * or null if no history exists for that item.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $mediaItemId The unique media item identifier (UUID format)
     *
     * @return array<string, mixed>|null Watch history entry array (see getHistory() format)
     *                    or null if no entry exists
     *
     * @see updateProgress() To create or update an entry
     */
    public function getForMediaItem(string $profileId, string $mediaItemId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM watch_history WHERE profile_id = ? AND media_item_id = ?",
            [$profileId, $mediaItemId]
        );

        $row = UserRow::firstFromMixed($result);
        if ($row === null) {
            return null;
        }

        return $this->hydrateEntry($row);
    }

    /**
     * Update or create watch progress for a profile and media item.
     *
     * This is the primary method for tracking playback progress.
     * It handles both creating new entries and updating existing ones.
     * Automatic completion detection occurs when progress >= COMPLETED_THRESHOLD.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $mediaItemId The unique media item identifier (UUID format)
     * @param int $positionTicks Current playback position in ticks
     *                            (100-nanosecond intervals, see class documentation)
     * @param int|null $durationTicks Total media duration in ticks (optional, uses
     *                                existing value if not provided)
     * @param string $status Playback status constant (STATUS_PLAYING, STATUS_PAUSED, etc.)
     *
     * @return array<string, mixed> Updated watch history entry with media info
     *
     * @throws \RuntimeException If the persisted entry can not be re-read after upsert.
     *
     * @example
     * // Report 30 minutes progress on a 2-hour movie
     * $entry = $watchHistory->updateProgress(
     *     'profile_123',
     *     'media_456',
     *     18000000000,  // 30 min in ticks
     *     72000000000,  // 2 hours in ticks
     *     WatchHistory::STATUS_PLAYING
     * );
     *
     * @see markCompleted() To manually mark as completed
     * @see getResumePosition() To get position for resuming
     */
    public function updateProgress(
        string $profileId,
        string $mediaItemId,
        int $positionTicks,
        ?int $durationTicks = null,
        string $status = self::STATUS_PLAYING
    ): array {
        // Get existing entry to calculate progress.
        $existing = $this->getForMediaItem($profileId, $mediaItemId);
        $wasAlreadyCompleted = $existing !== null
            && ($existing['playback_status'] ?? '') === self::STATUS_COMPLETED;

        $progressPercent = 0.0;
        if ($durationTicks && $durationTicks > 0) {
            $progressPercent = round(($positionTicks / $durationTicks) * 100, 2);
        }

        $completedAt = null;
        $newlyCompleted = false;
        if ($progressPercent >= self::COMPLETED_THRESHOLD) {
            $status = self::STATUS_COMPLETED;
            $completedAt = date('Y-m-d H:i:s');
            $newlyCompleted = !$wasAlreadyCompleted;
        }

        $now = date('Y-m-d H:i:s');

        if ($existing) {
            // Update existing entry
            $this->db->query(
                "UPDATE watch_history
                 SET position_ticks = ?,
                     duration_ticks = COALESCE(?, duration_ticks),
                     playback_status = ?,
                     progress_percent = ?,
                     last_watched_at = ?,
                     completed_at = COALESCE(?, completed_at)
                 WHERE profile_id = ? AND media_item_id = ?",
                [
                    $positionTicks,
                    $durationTicks,
                    $status,
                    $progressPercent,
                    $now,
                    $completedAt,
                    $profileId,
                    $mediaItemId,
                ]
            );
        } else {
            // Create new entry
            $id = $this->generateUuid();
            $this->db->query(
                "INSERT INTO watch_history
                 (id, profile_id, media_item_id, position_ticks, duration_ticks, playback_status,
                  progress_percent, last_watched_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $id,
                    $profileId,
                    $mediaItemId,
                    $positionTicks,
                    $durationTicks,
                    $status,
                    $progressPercent,
                    $now,
                    $completedAt,
                ]
            );
        }

        $entry = $this->getForMediaItem($profileId, $mediaItemId);
        if ($entry === null) {
            throw new \RuntimeException(
                'Failed to read back watch_history entry after upsert'
            );
        }

        // P4-S2: background step — recompute because-you-watched recommendations
        // when the user first completes a media item (not on every progress ping).
        if ($newlyCompleted && $this->recommendationService !== null) {
            $userId = $this->resolveUserIdFromProfile($profileId);
            if ($userId !== null) {
                $this->recommendationService->computeBecauseYouWatched($userId);
            }
        }

        return $entry;
    }

    /**
     * Mark a media item as completed for a profile.
     *
     * Manually sets the playback status to completed, ignoring the
     * automatic completion threshold. Use this when user explicitly
     * marks something as watched or finished.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $mediaItemId The unique media item identifier (UUID format)
     *
     * @return array<string, mixed> Updated watch history entry with status=completed
     *
     * @throws \RuntimeException If the persisted entry can not be re-read after upsert.
     *
     * @see updateProgress() For progress-based completion
     */
    public function markCompleted(string $profileId, string $mediaItemId): array
    {
        return $this->updateProgress(
            $profileId,
            $mediaItemId,
            0,
            null,
            self::STATUS_COMPLETED
        );
    }

    /**
     * Remove a media item from watch history.
     *
     * Permanently deletes the watch history entry for the specified
     * media item. This is useful for "Mark as Unwatched" functionality.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $mediaItemId The unique media item identifier (UUID format)
     *
     * @return void
     *
     * @see clearHistory() To remove all history for a profile
     */
    public function removeFromHistory(string $profileId, string $mediaItemId): void
    {
        $this->db->query(
            "DELETE FROM watch_history WHERE profile_id = ? AND media_item_id = ?",
            [$profileId, $mediaItemId]
        );
    }

    /**
     * Clear all watch history for a profile.
     *
     * Permanently deletes all watch history entries for the profile.
     * This action cannot be undone.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     *
     * @return void
     *
     * @see removeFromHistory() To remove a single entry
     */
    public function clearHistory(string $profileId): void
    {
        $this->db->query(
            "DELETE FROM watch_history WHERE profile_id = ?",
            [$profileId]
        );
    }

    /**
     * Get total watch time for a profile in seconds.
     *
     * Calculates the sum of all completed media durations for the profile.
     * Only includes items with playback_status = 'completed'.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     *
     * @return int Total watch time in seconds (0 if no history)
     *
     * @see getTodayWatchTime() For today's watch time only
     * @see getWatchTimeByDay() For historical breakdown
     */
    public function getTotalWatchTime(string $profileId): int
    {
        $row = UserRow::firstFromMixed($this->db->query(
            "SELECT SUM(duration_ticks) as total
             FROM watch_history
             WHERE profile_id = ? AND playback_status = 'completed'",
            [$profileId]
        ));

        $totalTicks = UserRow::int($row, 'total', 0);

        // Convert ticks to seconds (ticks / TICKS_PER_SECOND)
        return (int)($totalTicks / self::TICKS_PER_SECOND);
    }

    /**
     * Get watch time for today for a profile.
     *
     * Calculates total watch time for completed items watched today
     * (based on last_watched_at timestamp).
     *
     * @param string $profileId The unique profile identifier (UUID format)
     *
     * @return int Today's watch time in seconds (0 if none)
     *
     * @see getTotalWatchTime() For all-time total
     * @see getWatchTimeByDay() For multi-day breakdown
     */
    public function getTodayWatchTime(string $profileId): int
    {
        $row = UserRow::firstFromMixed($this->db->query(
            "SELECT SUM(duration_ticks) as total
             FROM watch_history
             WHERE profile_id = ?
               AND playback_status = 'completed'
               AND DATE(last_watched_at) = CURDATE()",
            [$profileId]
        ));

        $totalTicks = UserRow::int($row, 'total', 0);

        return (int)($totalTicks / self::TICKS_PER_SECOND);
    }

    /**
     * Get daily watch times for the past N days.
     *
     * Returns a keyed array mapping dates to total watch seconds,
     * useful for building watch time charts or usage statistics.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param int $days Number of past days to include (default: 7, max: 365)
     *
     * @return array<string, int> Associative array with date strings (Y-m-d) as keys
     *                          and watch time in seconds as values. Dates with no
     *                          watch activity are omitted from the array.
     *
     * @see getTotalWatchTime() For all-time total
     * @see getTodayWatchTime() For today's total only
     */
    public function getWatchTimeByDay(string $profileId, int $days = 7): array
    {
        $results = $this->db->query(
            "SELECT DATE(last_watched_at) as watch_date,
                    SUM(duration_ticks) as total_ticks
             FROM watch_history
             WHERE profile_id = ?
               AND playback_status = 'completed'
               AND last_watched_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(last_watched_at)
             ORDER BY watch_date ASC",
            [$profileId, $days]
        );

        $data = [];
        foreach (RowMap::listFromMixed($results) as $row) {
            $date = UserRow::string($row, 'watch_date');
            if ($date === null) {
                continue;
            }
            $totalTicks = UserRow::int($row, 'total_ticks', 0);
            $data[$date] = (int)($totalTicks / self::TICKS_PER_SECOND);
        }

        return $data;
    }

    /**
     * Check if a media item has been completed by a profile.
     *
     * Returns true only if the media item has been watched to completion
     * (status = completed), not just started.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $mediaItemId The unique media item identifier (UUID format)
     *
     * @return bool True if the media has been completed, false otherwise
     *
     * @see getForMediaItem() For full watch history entry
     * @see getRecentlyCompleted() For all completed items
     */
    public function hasWatched(string $profileId, string $mediaItemId): bool
    {
        $result = $this->db->query(
            "SELECT 1 FROM watch_history
             WHERE profile_id = ? AND media_item_id = ?
               AND playback_status = 'completed'",
            [$profileId, $mediaItemId]
        );

        return !empty($result);
    }

    /**
     * Get resume position for a media item.
     *
     * Returns the saved playback position where the user left off,
     * suitable for resuming playback. Returns null if the media
     * has been completed or hasn't been started.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $mediaItemId The unique media item identifier (UUID format)
     *
     * @return int|null Resume position in ticks, or null if not resumable
     *
     * @example
     * $position = $watchHistory->getResumePosition('profile_123', 'media_456');
     * if ($position !== null) {
     *     $player->seek($position);
     * }
     *
     * @see updateProgress() To save a new position
     */
    public function getResumePosition(string $profileId, string $mediaItemId): ?int
    {
        $entry = $this->getForMediaItem($profileId, $mediaItemId);

        if ($entry === null || ($entry['playback_status'] ?? null) === self::STATUS_COMPLETED) {
            return null;
        }

        $position = $entry['position_ticks'] ?? 0;
        return is_numeric($position) ? (int) $position : 0;
    }

    /**
     * Get count of items in watch history.
     *
     * Returns the total number of watch history entries for a profile,
     * regardless of completion status.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     *
     * @return int Total number of history entries (0 if none)
     *
     * @see getHistory() To retrieve the actual entries
     */
    public function getCount(string $profileId): int
    {
        $row = UserRow::firstFromMixed($this->db->query(
            "SELECT COUNT(*) as count FROM watch_history WHERE profile_id = ?",
            [$profileId]
        ));

        return UserRow::int($row, 'count', 0);
    }

    /**
     * Hydrate a database row into a watch history entry array.
     *
     * Transforms raw database records (including JOINed media info) into
     * structured arrays with properly typed values and extracted metadata.
     *
     * @param array<string, mixed> $row Raw database row from watch_history JOIN media_items
     *
     * @return array<string, mixed> Hydrated watch history entry with media metadata when available
     *
     * @internal
     */
    private function hydrateEntry(array $row): array
    {
        return WatchHistoryRow::fromRow($row)->toArray();
    }

    /**
     * Looks up the user_id for a given profile_id.
     *
     * @param string $profileId The profile UUID.
     *
     * @return string|null The user UUID, or null if not found.
     *
     * @internal
     */
    private function resolveUserIdFromProfile(string $profileId): ?string
    {
        $row = UserRow::firstFromMixed(
            $this->db->query(
                "SELECT user_id FROM user_profiles WHERE id = ?",
                [$profileId]
            )
        );

        return UserRow::string($row, 'user_id');
    }

    /**
     * Generate a UUID v4 string.
     *
     * Creates a random UUID suitable for use as a unique identifier.
     * Format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx (RFC 4122 compliant)
     *
     * @return string UUID v4 string
     *
     * @internal
     */
    private function generateUuid(): string
    {
        return Uuid::v4();
    }
}
