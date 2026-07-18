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
     * @param string $userId The user UUID.
     * @param int $topK Maximum number of recommendations to store (default 100).
     * @return void
     */
    public function computeBecauseYouWatched(string $userId, int $topK = self::DEFAULT_TOP_K): void
    {
        if ($userId === '') {
            return;
        }

        // Get the user's watch history: distinct media items they've watched.
        $watchedItems = $this->fetchWatchedItems($userId);
        if ($watchedItems === []) {
            // No watch history — clear any existing recommendations and return.
            $this->clearRecommendations($userId);
            return;
        }

        // Aggregate similar items from all watched items.
        // Map of media_item_id => ['score' => float, 'reasons' => list<string>]
        $candidateScores = [];

        // P4-S2: Build a set of all watched item IDs so we can exclude ALL watched
        // items from recommendations (not just the current seed item). This ensures
        // we never recommend something the user has already seen.
        $watchedItemsById = array_flip($watchedItems);

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
     * Fetches the distinct media item IDs the user has watched.
     *
     * @param string $userId The user UUID.
     * @return list<string> List of media item UUIDs.
     */
    private function fetchWatchedItems(string $userId): array
    {
        // Get watch history items for this user via profile.
        // watch_history is keyed on profile_id, not user_id.
        // We need to get all profiles for the user and their watched items.
        $profileRows = $this->db->query(
            "SELECT id FROM user_profiles WHERE user_id = ?",
            [$userId]
        );

        if (!is_array($profileRows) || $profileRows === []) {
            return [];
        }

        $profileIds = array_filter(
            array_map(
                static fn($row): ?string => is_array($row) && isset($row['id']) && is_string($row['id']) ? $row['id'] :
                    null,
                $profileRows
            )
        );

        if ($profileIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($profileIds), '?'));
        $watchedRows = $this->db->query(
            "SELECT DISTINCT media_item_id
             FROM watch_history
             WHERE profile_id IN ({$placeholders})
               AND playback_status = 'completed'",
            $profileIds
        );

        if (!is_array($watchedRows) || $watchedRows === []) {
            return [];
        }

        $mapped = array_filter(
            array_map(
                static fn($row): ?string => is_array($row) && isset($row['media_item_id']) &&
                    is_string($row['media_item_id']) ? $row['media_item_id'] : null,
                $watchedRows
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
