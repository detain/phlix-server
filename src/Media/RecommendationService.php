<?php

declare(strict_types=1);

namespace Phlix\Media;

use Workerman\MySQL\Connection;

/**
 * Computes and retrieves "because you watched" recommendations for users.
 *
 * For each item in a user's watch history, gets similar items from SimilarityService,
 * aggregates scores, and stores the top-K recommendations in user_recommendations.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
final class RecommendationService
{
    private const REASON = 'because_you_watched';
    private const DEFAULT_TOP_K = 100;

    /**
     * Hard cap on the number of watched items used as similarity SEEDS by
     * {@see self::computeBecauseYouWatched()}.
     *
     * The seed count IS the query fan-out: {@see self::computeBecauseYouWatched()}
     * issues one {@see SimilarityService::getSimilar()} query per seed, inline and
     * synchronously. Unbounded (the query had no LIMIT and spans EVERY profile on
     * the account) a heavy account could stall the single-threaded Workerman event
     * loop for the duration of hundreds of round trips.
     *
     * 50 is chosen because it cannot starve the result: each seed contributes up to
     * 20 similar items, so 50 seeds yield up to 1,000 candidates — an order of
     * magnitude more than {@see self::DEFAULT_TOP_K} (100) can ever store. Seeds are
     * taken most-recently-watched first, which is also the most relevant slice.
     *
     * ⚠ This cap applies to SEEDS ONLY. It must never be used to build the
     * already-watched EXCLUSION set: capping that set would let an item the user
     * finished long ago (outside the 50 most recent completions) be recommended
     * back to them, which inverts the whole point of the feature — and gets worse
     * the longer the history is. The exclusion set therefore has its own separate,
     * deliberately UNBOUNDED query, {@see self::fetchAllWatchedItemIds()}. That is
     * safe because it is one flat indexed read with no per-row fan-out, unlike the
     * seed list where every row costs a `getSimilar()` round trip.
     *
     * ⚠ FOLLOW-UP: this is a BOUND, not a fix. Before any caller is wired to
     * {@see \Phlix\Auth\WatchHistory::updateProgress()} (which today has none, so
     * `computeBecauseYouWatched()` is unreachable), the recompute must move off the
     * request path and behind the existing job queue — {@see SimilarityJobStore} +
     * {@see SimilarityWorker} — the same way per-item similarity computation
     * already is. Even 50 synchronous queries do not belong in an HTTP handler.
     */
    public const MAX_WATCHED_SEEDS = 50;

    /** @var Connection */
    private Connection $db;

    /** @var SimilarityService */
    private SimilarityService $similarityService;

    public function __construct(Connection $db, SimilarityService $similarityService)
    {
        $this->db = $db;
        $this->similarityService = $similarityService;
    }

    /**
     * Computes because-you-watched recommendations for a user.
     *
     * Clears existing undismissed recommendations for the user, then for each
     * item in their watch history, fetches similar items from SimilarityService,
     * aggregates scores (summing scores for items suggested by multiple watched
     * items), and stores the top-K in user_recommendations.
     *
     * Two DIFFERENT watch-history lists are read here and they are deliberately
     * not the same list — see {@see self::fetchWatchedItems()} (bounded seeds) and
     * {@see self::fetchAllWatchedItemIds()} (complete exclusion set).
     *
     * @param string $userId The user UUID.
     * @param int $topK Maximum number of recommendations to store (default 100).
     * @return void
     */
    public function computeBecauseYouWatched(string $userId, int $topK = self::DEFAULT_TOP_K): void
    {
        if ($userId === '') {
            return;
        }

        // watch_history is keyed on profile_id, not user_id, so resolve the
        // account's profiles once and reuse them for both history reads below.
        $profileIds = $this->fetchProfileIds($userId);
        if ($profileIds === []) {
            $this->clearRecommendations($userId);
            return;
        }

        // Seeds: the items we look for similar items TO. Bounded — see
        // self::MAX_WATCHED_SEEDS, because each seed costs a getSimilar() query.
        $watchedItems = $this->fetchWatchedItems($profileIds);
        if ($watchedItems === []) {
            // No watch history — clear any existing recommendations and return.
            $this->clearRecommendations($userId);
            return;
        }

        // Aggregate similar items from all watched items.
        // Map of media_item_id => ['score' => float, 'reasons' => list<string>]
        $candidateScores = [];

        // P4-S2: Build a set of EVERY watched item ID so we can exclude ALL watched
        // items from recommendations (not just the current seed item). This ensures
        // we never recommend something the user has already seen.
        //
        // This intentionally does NOT reuse $watchedItems: that list is capped at
        // self::MAX_WATCHED_SEEDS, and an exclusion set built from a capped list
        // would silently recommend items the user finished before the cap window.
        $watchedItemsById = array_flip($this->fetchAllWatchedItemIds($profileIds));

        foreach ($watchedItems as $watchedItemId) {
            $similarItems = $this->similarityService->getSimilar($watchedItemId, 20);
            foreach ($similarItems as $item) {
                $id = is_string($item['id'] ?? null) ? $item['id'] : '';
                if ($id === '' || isset($watchedItemsById[$id])) {
                    continue; // Skip self, already-watched, or invalid.
                }
                $score = is_numeric($item['score'] ?? null) ? (float) $item['score'] : 0.0;
                if ($score <= 0.0) {
                    continue;
                }

                if (!isset($candidateScores[$id])) {
                    $candidateScores[$id] = ['score' => 0.0, 'reasons' => []];
                }
                $candidateScores[$id]['score'] += $score;
                $candidateScores[$id]['reasons'][] = $item['reason'] ?? self::REASON;
            }
        }

        if ($candidateScores === []) {
            $this->clearRecommendations($userId);
            return;
        }

        // Sort by aggregated score descending.
        uasort($candidateScores, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        // Take top-K.
        $topCandidates = array_slice($candidateScores, 0, $topK, true);

        // Build upsert: delete undismissed recommendations for this user,
        // then insert fresh ones.
        $this->replaceRecommendations($userId, $topCandidates);
    }

    /**
     * Returns the user's because-you-watched recommendations.
     *
     * @param string $userId The user UUID.
     * @param int $limit Maximum number to return (default 20).
     * @return list<array{id: string, title: string, posterUrl: string|null, reason: string, score: float}>
     */
    public function getBecauseYouWatched(string $userId, int $limit = 20): array
    {
        if ($limit < 1) {
            return [];
        }

        $limit = min($limit, 100);

        $results = $this->db->query(
            "SELECT r.media_item_id, r.reason, r.score,
                    m.name AS title, m.metadata_json
             FROM user_recommendations r
             JOIN media_items m ON m.id = r.media_item_id
             WHERE r.user_id = ?
               AND r.dismissed_at IS NULL
             ORDER BY r.score DESC
             LIMIT ?",
            [$userId, $limit]
        );

        if (!is_array($results)) {
            return [];
        }

        $out = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }

            $mediaItemId = is_string($row['media_item_id'] ?? null) ? $row['media_item_id'] : '';
            if ($mediaItemId === '') {
                continue;
            }

            $score = is_numeric($row['score'] ?? null) ? (float) $row['score'] : 0.0;
            $reason = is_string($row['reason'] ?? null) ? $row['reason'] : self::REASON;
            $title = is_string($row['title'] ?? null) ? $row['title'] : '';

            $posterUrl = $this->extractPosterUrl($row['metadata_json'] ?? null);

            $out[] = [
                'id' => $mediaItemId,
                'title' => $title,
                'posterUrl' => $posterUrl,
                'reason' => $reason,
                'score' => $score,
            ];
        }

        return $out;
    }

    /**
     * Dismisses a recommendation so it no longer appears.
     *
     * @param string $userId The user UUID.
     * @param string $mediaItemId The media item UUID to dismiss.
     * @return void
     */
    public function dismissRecommendation(string $userId, string $mediaItemId): void
    {
        if ($userId === '' || $mediaItemId === '') {
            return;
        }

        $this->db->query(
            "UPDATE user_recommendations
             SET dismissed_at = NOW()
             WHERE user_id = ? AND media_item_id = ?
             LIMIT 1",
            [$userId, $mediaItemId]
        );
    }

    /**
     * Fetches the profile IDs belonging to an account.
     *
     * `watch_history` is keyed on `profile_id`, not `user_id`, so every history
     * read has to go through this. It is resolved ONCE per recompute and handed to
     * both history queries so the split into two queries does not also double the
     * profile lookup.
     *
     * @param string $userId The user UUID.
     * @return list<string> Profile UUIDs, empty when the account has none.
     */
    private function fetchProfileIds(string $userId): array
    {
        $profileRows = $this->db->query(
            "SELECT id FROM user_profiles WHERE user_id = ?",
            [$userId]
        );

        if (!is_array($profileRows) || $profileRows === []) {
            return [];
        }

        $mapped = array_filter(
            array_map(
                static fn($row): ?string => is_array($row) && isset($row['id']) && is_string($row['id']) ? $row['id'] :
                    null,
                $profileRows
            )
        );

        return array_values($mapped);
    }

    /**
     * Fetches the media item IDs to use as similarity SEEDS — a BOUNDED slice.
     *
     * ⚠ This is one of TWO watch-history reads and they are NOT interchangeable:
     *
     *  - THIS method returns SEEDS: the items we search for similar items to. Its
     *    row count IS a query fan-out ({@see SimilarityService::getSimilar()} runs
     *    once per row), so it is capped at {@see self::MAX_WATCHED_SEEDS} and
     *    ordered most-recently-completed first to keep the most relevant slice.
     *  - {@see self::fetchAllWatchedItemIds()} returns the EXCLUSION set: every
     *    item the user has ever completed. It must stay UNBOUNDED, because a
     *    partial exclusion set means already-watched items get recommended.
     *
     * Do not collapse the two back into one query. Using this bounded list as the
     * exclusion set is the exact regression the split exists to prevent; using the
     * unbounded list as the seed list restores the unbounded fan-out.
     *
     * The `GROUP BY media_item_id` + `MAX(last_watched_at)` shape is deliberate.
     * Keeping the previous `SELECT DISTINCT media_item_id` and just bolting an
     * `ORDER BY last_watched_at` onto it is rejected by MySQL (error 3065: the
     * ORDER BY column is not in the SELECT list, which is incompatible with
     * DISTINCT). Grouping on the key and aggregating the timestamp gives a legal
     * ordering column and is `ONLY_FULL_GROUP_BY`-safe, which prod runs with.
     *
     * @param list<string> $profileIds Profiles of the account, non-empty.
     * @return list<string> List of media item UUIDs, newest completion first.
     */
    private function fetchWatchedItems(array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($profileIds), '?'));
        $params = array_values($profileIds);
        $params[] = self::MAX_WATCHED_SEEDS;
        $watchedRows = $this->db->query(
            "SELECT media_item_id, MAX(last_watched_at) AS last_completed_at
             FROM watch_history
             WHERE profile_id IN ({$placeholders})
               AND playback_status = 'completed'
             GROUP BY media_item_id
             ORDER BY last_completed_at DESC
             LIMIT ?",
            $params
        );

        return $this->mapMediaItemIds($watchedRows);
    }

    /**
     * Fetches EVERY media item ID the account has completed — the EXCLUSION set.
     *
     * ⚠ Deliberately UNBOUNDED, and deliberately a separate query from
     * {@see self::fetchWatchedItems()}. The two lists answer different questions:
     *
     *  - seeds (bounded)      = "what do we look for similar items to?"
     *  - exclusion (complete) = "what must never be recommended back?"
     *
     * Reusing the bounded seed list here regresses behaviour: on an account with
     * 300 completions, an item finished before the 50-seed window would no longer
     * be excluded, so it could be scored, stored in `user_recommendations`, and
     * surfaced as "Because you watched …" for something already finished. The
     * longer the history, the more of it leaks back — the opposite of the intent.
     *
     * Unbounded is safe HERE (unlike for seeds) because this is a single flat
     * indexed read with no per-row work: nothing is issued per returned row, so
     * cost is one round trip regardless of history size. Only the ID strings are
     * held, and only for the duration of the recompute — never in static state.
     *
     * NOTE: no `ORDER BY`. Ordering is meaningless for a set-membership lookup,
     * and `SELECT DISTINCT col ... ORDER BY other_col` is rejected outright by
     * MySQL (error 3065) under the `ONLY_FULL_GROUP_BY` sql_mode prod runs with.
     *
     * @param list<string> $profileIds Profiles of the account, non-empty.
     * @return list<string> Every completed media item UUID, unordered.
     */
    private function fetchAllWatchedItemIds(array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($profileIds), '?'));
        $watchedRows = $this->db->query(
            "SELECT DISTINCT media_item_id
             FROM watch_history
             WHERE profile_id IN ({$placeholders})
               AND playback_status = 'completed'",
            array_values($profileIds)
        );

        return $this->mapMediaItemIds($watchedRows);
    }

    /**
     * Coerces `media_item_id` column values out of a raw driver result set.
     *
     * @param mixed $rows Whatever the driver returned.
     * @return list<string> The non-empty string media item IDs, order preserved.
     */
    private function mapMediaItemIds(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $mapped = array_filter(
            array_map(
                static fn($row): ?string => is_array($row) && isset($row['media_item_id']) &&
                    is_string($row['media_item_id']) ? $row['media_item_id'] : null,
                $rows
            )
        );

        return array_values($mapped);
    }

    /**
     * Clears undismissed recommendations for a user.
     *
     * @param string $userId The user UUID.
     */
    private function clearRecommendations(string $userId): void
    {
        $this->db->query(
            "DELETE FROM user_recommendations WHERE user_id = ? AND dismissed_at IS NULL",
            [$userId]
        );
    }

    /**
     * Replaces undismissed recommendations for a user with fresh candidates.
     *
     * @param string $userId The user UUID.
     * @param array<string, array{score: float, reasons: list<string>}> $candidates
     */
    private function replaceRecommendations(string $userId, array $candidates): void
    {
        if ($candidates === []) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // Delete undismissed recommendations for this user.
        $this->db->query(
            "DELETE FROM user_recommendations WHERE user_id = ? AND dismissed_at IS NULL",
            [$userId]
        );

        // Insert fresh recommendations.
        $rows = [];
        $values = [];
        foreach ($candidates as $mediaItemId => $data) {
            // Pick the top contributing reason for display.
            $reason = is_string($data['reasons'][0] ?? null) ? $data['reasons'][0] : self::REASON;
            $rows[] = '(?, ?, ?, ?, ?)';
            $values[] = $userId;
            $values[] = $mediaItemId;
            $values[] = $reason;
            $values[] = round($data['score'], 3);
            $values[] = $now;
        }

        $placeholders = implode(',', $rows);
        $this->db->query(
            "INSERT INTO user_recommendations (user_id, media_item_id, reason, score, computed_at)
             VALUES {$placeholders}",
            $values
        );
    }

    /**
     * Extracts the poster URL from a metadata_json blob.
     *
     * @param mixed $metadataJson
     * @return string|null
     */
    private function extractPosterUrl(mixed $metadataJson): ?string
    {
        if (!is_string($metadataJson)) {
            return null;
        }

        $decoded = json_decode($metadataJson, true);
        if (!is_array($decoded)) {
            return null;
        }

        $posterUrl = $decoded['poster_url'] ?? null;
        if (is_string($posterUrl) && $posterUrl !== '') {
            return $posterUrl;
        }

        $images = $decoded['images'] ?? null;
        if (!is_array($images)) {
            return null;
        }

        $poster = $images['poster'] ?? null;
        if (is_array($poster) && isset($poster[0])) {
            $first = $poster[0];
            if (is_array($first) && isset($first['url']) && is_string($first['url'])) {
                return $first['url'];
            }
            if (is_string($first)) {
                return $first;
            }
        }

        return null;
    }
}
