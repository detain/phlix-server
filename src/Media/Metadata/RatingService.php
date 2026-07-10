<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Workerman\MySQL\Connection;

/**
 * Service for managing metadata ratings from TMDB, IMDb, and user sources.
 *
 * Provides upsert semantics so the same source+type combination is updated
 * rather than duplicated, and an aggregation method that computes a weighted
 * average across all sources and upserts it as an Average rating.
 */
class RatingService
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Find all ratings for a given media item.
     *
     * @param string $mediaItemId The media item UUID
     * @return array<int, Rating> Ordered list of ratings (newest first)
     */
    /**
     * @return array<int, Rating>
     */
    public function findByMediaItem(string $mediaItemId): array
    {
        /** @var array<int, array<string, mixed>> $stmt */
        $stmt = $this->db->query(
            'SELECT id, media_item_id, source, rating_type, score, votes, created_at, updated_at
             FROM metadata_ratings
             WHERE media_item_id = ?
             ORDER BY updated_at DESC',
            [$mediaItemId]
        );

        $ratings = [];
        foreach ($stmt as $row) {
            /** @var array<string, mixed> $row */
            $ratings[] = Rating::fromDbRow($row);
        }

        return $ratings;
    }

    /**
     * Upsert a single rating record.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE so concurrent writes from multiple
     * workers never create duplicates — the unique key on (media_item_id, source,
     * rating_type) guarantees exactly one row per source+type combination.
     *
     * @param string $mediaItemId The media item UUID
     * @param RatingSource $source The rating source
     * @param RatingType $ratingType The type of rating
     * @param float $score The rating score (0.0–10.0)
     * @param int|null $votes Optional vote count (for source ratings)
     * @return Rating The persisted rating record
     */
    public function upsert(
        string $mediaItemId,
        RatingSource $source,
        RatingType $ratingType,
        float $score,
        ?int $votes = null,
    ): Rating {
        // Guard: TMDB/IMDb sources should carry votes; user sources may omit
        if ($votes !== null && $votes < 0) {
            throw new \InvalidArgumentException('Vote count cannot be negative');
        }

        $score = match (true) {
            $score < 0.0 => 0.0,
            $score > 10.0 => 10.0,
            default => round($score, 1),
        };

        $this->db->query(
            'INSERT INTO metadata_ratings (media_item_id, source, rating_type, score, votes)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE score = VALUES(score), votes = VALUES(votes)',
            [
                $mediaItemId,
                $source->value,
                $ratingType->value,
                (string) $score,
                $votes,
            ]
        );

        // Re-fetch to return the canonical persisted row with timestamps
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id, media_item_id, source, rating_type, score, votes, created_at, updated_at
             FROM metadata_ratings
             WHERE media_item_id = ? AND source = ? AND rating_type = ?',
            [$mediaItemId, $source->value, $ratingType->value]
        );

        if ($rows === [] || ($rows[0] ?? []) === []) {
            throw new \RuntimeException('Rating upsert failed: row not found after insert');
        }

        /** @var array<string, mixed> $firstRow */
        $firstRow = $rows[0];
        return Rating::fromDbRow($firstRow);
    }

    /**
     * Compute and persist a weighted-average rating across all sources.
     *
     * The aggregate score is a votes-weighted mean of all individual source
     * ratings. Sources with no vote count contribute their score at weight 1.
     * The result is stored as a RatingType::Average row for the given item.
     *
     * @param string $mediaItemId The media item UUID
     * @return void
     */
    public function aggregate(string $mediaItemId): void
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT score, votes FROM metadata_ratings WHERE media_item_id = ?',
            [$mediaItemId]
        );

        if ($rows === []) {
            return;
        }

        $totalScore = 0.0;
        $totalWeight = 0.0;

        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $score = is_numeric($row['score'] ?? null) ? (float) $row['score'] : 0.0;
            $votes = isset($row['votes']) && is_int($row['votes']) ? $row['votes'] : 1;

            $totalScore += $score * $votes;
            $totalWeight += $votes;
        }

        if ($totalWeight <= 0.0) {
            return;
        }

        $weightedAverage = round($totalScore / $totalWeight, 1);

        // Persist as the aggregate row; votes are the sum across all sources
        /** @var array<array<string, mixed>> $rows */
        $totalVotes = array_reduce(
            $rows,
            static fn(int $sum, array $row): int => $sum + (isset($row['votes']) && is_int($row['votes']) ? $row['votes'] : 0),
            0
        );

        $this->upsert(
            $mediaItemId,
            RatingSource::Aggregate,
            RatingType::Average,
            $weightedAverage,
            $totalVotes,
        );

        // P1-S1: Also write the denormalized aggregate to media_items so that
        // ItemRepository::query() can use the indexed rating_score column instead
        // of a costly LEFT JOIN + AVG + GROUP BY on every rating sort/filter.
        $this->db->query(
            'UPDATE media_items SET rating_score = ?, rating_votes = ? WHERE id = ?',
            [(string) $weightedAverage, $totalVotes, $mediaItemId]
        );
    }
}
