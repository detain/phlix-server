<?php

declare(strict_types=1);

namespace Phlix\Media;

use Phlix\Media\Library\ItemRepository;
use Workerman\MySQL\Connection;

/**
 * Computes and retrieves item-similarity scores using cosine similarity
 * over unified feature vectors containing genre weights, actor weights,
 * director weights, decade, and rating.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
final class SimilarityService
{
    private const REASON_GENRE = 'genre';
    private const REASON_ACTOR = 'actor';
    private const REASON_DIRECTOR = 'director';
    private const REASON_RATING = 'rating';
    private const REASON_YEAR = 'year';

    /** Weights for each feature group (applied to vector dimensions). */
    private const WEIGHT_GENRE = 0.35;
    private const WEIGHT_ACTOR = 0.25;
    private const WEIGHT_DIRECTOR = 0.15;
    private const WEIGHT_RATING = 0.15;
    private const WEIGHT_YEAR = 0.10;

    /** Rating scale maximum for normalization. */
    private const RATING_SCALE = 10.0;

    /** @var Connection */
    private Connection $db;

    /** @var ItemRepository */
    private ItemRepository $itemRepository;

    public function __construct(Connection $db, ItemRepository $itemRepository)
    {
        $this->db = $db;
        $this->itemRepository = $itemRepository;
    }

    /**
     * Computes similarity scores for one source item against all other items
     * that have complete metadata (genres, actors, directors, rating, year).
     *
     * Scores are stored in `item_similar`, replacing any pre-existing rows for
     * the same `media_item_id`.
     *
     * SV-2.9: when a `$libraryId` is supplied the candidate set is bounded to
     * that library, so the background {@see \Phlix\Media\SimilarityWorker} does
     * not re-run the O(N²) full-table JSON scan the original scan-path hot loop
     * did. Passing null preserves the legacy full-catalogue behaviour for the
     * backfill script and any caller that still wants a cross-library set.
     *
     * @param string      $mediaItemId The source item to compute similarities for.
     * @param string|null $libraryId   Optional library UUID to bound the candidate
     *                                 search to a single library (SV-2.9).
     * @return void
     */
    public function computeSimilarForItem(string $mediaItemId, ?string $libraryId = null): void
    {
        $sourceItem = $this->itemRepository->findById($mediaItemId);
        if ($sourceItem === null) {
            return;
        }

        $sourceMetadata = $this->extractMetadata($sourceItem);
        if (!$this->hasCompleteMetadata($sourceMetadata)) {
            return;
        }

        // Fetch all items with complete metadata, excluding the source item
        // itself. When a library is supplied the candidate set is bounded to it.
        $candidates = $this->fetchItemsWithCompleteMetadata($mediaItemId, $libraryId);

        // Delete old similarity rows for this source item before inserting new ones.
        $this->db->query('DELETE FROM item_similar WHERE media_item_id = ?', [$mediaItemId]);

        $now = date('Y-m-d H:i:s');
        $rows = [];

        foreach ($candidates as $candidate) {
            $candidateMetadata = $this->extractMetadata($candidate);
            if (!$this->hasCompleteMetadata($candidateMetadata)) {
                continue;
            }

            $similarity = $this->computeSimilarity($sourceMetadata, $candidateMetadata);
            if ($similarity['score'] <= 0.0) {
                continue;
            }

            $candidateId = is_string($candidate['id'] ?? null) ? $candidate['id'] : '';
            if ($candidateId === '') {
                continue;
            }

            $rows[] = [
                'media_item_id' => $mediaItemId,
                'similar_item_id' => $candidateId,
                'score' => $similarity['score'],
                'reason' => $similarity['reason'],
                'computed_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($rows), '(?, ?, ?, ?, ?)'));
        $values = [];
        foreach ($rows as $row) {
            $values[] = $row['media_item_id'];
            $values[] = $row['similar_item_id'];
            $values[] = $row['score'];
            $values[] = $row['reason'];
            $values[] = $row['computed_at'];
        }

        $this->db->query(
            "INSERT INTO item_similar (media_item_id, similar_item_id, score, reason, computed_at)
             VALUES {$placeholders}",
            $values
        );
    }

    /**
     * Returns the top-K most similar items for a given media item.
     *
     * @param string $mediaItemId The source item.
     * @param int $limit Maximum number of results (default 10).
     * @return list<array{
     *     id: string,
     *     title: string,
     *     posterUrl: string|null,
     *     year: int|null,
     *     score: float,
     *     reason: string
     * }>
     *         `year` is emitted for every row (from extractYear()) but was absent
     *         from this shape, so consumers that read it were reported as
     *         accessing an undeclared offset.
     */
    public function getSimilar(string $mediaItemId, int $limit = 10): array
    {
        if ($limit < 1) {
            return [];
        }

        $limit = min($limit, 100);

        $results = $this->db->query(
            "SELECT s.similar_item_id, s.score, s.reason,
                    m.name AS title, m.metadata_json
             FROM item_similar s
             JOIN media_items m ON m.id = s.similar_item_id
             WHERE s.media_item_id = ?
             ORDER BY s.score DESC
             LIMIT ?",
            [$mediaItemId, $limit]
        );

        if (!is_array($results)) {
            return [];
        }

        $out = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }

            $similarItemId = is_string($row['similar_item_id'] ?? null) ? $row['similar_item_id'] : '';
            if ($similarItemId === '') {
                continue;
            }

            $score = is_numeric($row['score'] ?? null) ? (float) $row['score'] : 0.0;
            $reason = is_string($row['reason'] ?? null) ? $row['reason'] : self::REASON_GENRE;
            $title = is_string($row['title'] ?? null) ? $row['title'] : '';

            $posterUrl = $this->extractPosterUrl($row['metadata_json'] ?? null);
            $year = $this->extractYear($row['metadata_json'] ?? null);

            $out[] = [
                'id' => $similarItemId,
                'title' => $title,
                'posterUrl' => $posterUrl,
                'year' => $year,
                'score' => $score,
                'reason' => $reason,
            ];
        }

        return $out;
    }

    /**
     * Extracts a clean metadata array from a hydrated media item row.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function extractMetadata(array $item): array
    {
        $raw = $item['metadata'] ?? null;
        if (!is_array($raw)) {
            $rawJson = $item['metadata_json'] ?? null;
            if (is_string($rawJson)) {
                $raw = json_decode($rawJson, true);
            }
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * Returns true when the metadata carries all fields needed for similarity.
     *
     * @param array<string, mixed> $metadata
     * @return bool
     */
    private function hasCompleteMetadata(array $metadata): bool
    {
        return $this->hasNonEmptyArray($metadata, 'genres')
            && $this->hasNonEmptyArray($metadata, 'actors')
            && $this->hasNonEmptyArray($metadata, 'directors')
            && is_numeric($metadata['rating'] ?? null)
            && is_numeric($metadata['year'] ?? null);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function hasNonEmptyArray(array $metadata, string $key): bool
    {
        $value = $metadata[$key] ?? null;

        return is_array($value) && count($value) > 0;
    }

    /**
     * Fetches all items that have complete metadata, excluding the given item.
     *
     * SV-2.9: when a `$libraryId` is supplied the result set is bounded with a
     * `library_id = ?` predicate so the candidate scan is per-library rather
     * than a full-table decode of every catalogued item.
     *
     * @param string      $excludeId Item ID to exclude from results.
     * @param string|null $libraryId Optional library UUID to bound the scan.
     * @return list<array<string, mixed>>
     */
    private function fetchItemsWithCompleteMetadata(string $excludeId, ?string $libraryId = null): array
    {
        // Use a subquery approach to filter items that have all required metadata.
        // We use JSON_EXTRACT for rating/year and JSON_LENGTH for the arrays.
        // Actors/directors can be either array of strings or array of objects with 'name'.
        $libraryClause = '';
        $params = [$excludeId];
        if ($libraryId !== null && $libraryId !== '') {
            $libraryClause = ' AND library_id = ?';
            $params[] = $libraryId;
        }

        $results = $this->db->query(
            "SELECT id, metadata_json
             FROM media_items
             WHERE id != ?{$libraryClause}
               AND JSON_LENGTH(metadata_json, '\$.genres') > 0
               AND JSON_LENGTH(metadata_json, '\$.actors') > 0
               AND JSON_LENGTH(metadata_json, '\$.directors') > 0
               AND JSON_EXTRACT(metadata_json, '\$.rating') IS NOT NULL
               AND JSON_EXTRACT(metadata_json, '\$.year') IS NOT NULL
               AND JSON_TYPE(JSON_EXTRACT(metadata_json, '\$.rating')) = 'DOUBLE'
               AND JSON_TYPE(JSON_EXTRACT(metadata_json, '\$.year')) = 'INT'",
            $params
        );

        if (!is_array($results)) {
            return [];
        }

        $out = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Computes vector-based cosine similarity between two metadata sets.
     *
     * P4: Builds a unified feature vector for each item containing ALL features:
     * genres, actors, directors (as weighted binary dimensions), rating (normalized),
     * and year (normalized). Then computes a SINGLE cosine similarity score.
     *
     * This is true vector-based cosine similarity, not a weighted sum of
     * per-feature similarities. It properly accounts for the relative
     * magnitude of each feature group in the overall similarity score.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array{score: float, reason: string}
     */
    private function computeSimilarity(array $a, array $b): array
    {
        // Extract feature arrays
        $genresA = $this->normalizeStringArray($a['genres'] ?? []);
        $genresB = $this->normalizeStringArray($b['genres'] ?? []);
        $actorsA = $this->extractNames($a['actors'] ?? []);
        $actorsB = $this->extractNames($b['actors'] ?? []);
        $directorsA = $this->extractNames($a['directors'] ?? []);
        $directorsB = $this->extractNames($b['directors'] ?? []);

        // Categorical feature counts (for magnitude calculation)
        $genreCountA = count($genresA);
        $genreCountB = count($genresB);
        $actorCountA = count($actorsA);
        $actorCountB = count($actorsB);
        $directorCountA = count($directorsA);
        $directorCountB = count($directorsB);

        // Intersection counts for categorical features
        $genreIntersection = $genreCountA > 0 && $genreCountB > 0
            ? count(array_intersect($genresA, $genresB))
            : 0;
        $actorIntersection = $actorCountA > 0 && $actorCountB > 0
            ? count(array_intersect($actorsA, $actorsB))
            : 0;
        $directorIntersection = $directorCountA > 0 && $directorCountB > 0
            ? count(array_intersect($directorsA, $directorsB))
            : 0;

        // Continuous features
        $ratingA = $this->toFloat($a['rating'] ?? null);
        $ratingB = $this->toFloat($b['rating'] ?? null);
        $yearA = $this->toInt($a['year'] ?? null);
        $yearB = $this->toInt($b['year'] ?? null);

        // Normalized continuous feature values (0-1 scale)
        $normRatingA = $ratingA / self::RATING_SCALE;
        $normRatingB = $ratingB / self::RATING_SCALE;
        $normYearA = $this->normalizeYear($yearA);
        $normYearB = $this->normalizeYear($yearB);

        // Build unified vectors with weighted components
        // Vector structure: [genres (W_GENRE each), actors (W_ACTOR each), directors (W_DIRECTOR each), rating, year]
        // The weighted dot product for categorical features:
        //   dot = sum(W_group * W_group) for each shared feature = intersection * W_group^2
        // For continuous: W_feature^2 * normalized_value

        $dotProduct = ($genreIntersection * self::WEIGHT_GENRE * self::WEIGHT_GENRE)
            + ($actorIntersection * self::WEIGHT_ACTOR * self::WEIGHT_ACTOR)
            + ($directorIntersection * self::WEIGHT_DIRECTOR * self::WEIGHT_DIRECTOR)
            + ($normRatingA * $normRatingB * self::WEIGHT_RATING * self::WEIGHT_RATING)
            + ($normYearA * $normYearB * self::WEIGHT_YEAR * self::WEIGHT_YEAR);

        // Magnitude squared for each vector:
        // ||v||^2 = sum(W_group^2 for each feature in item) + sum(W_feature^2 * normalized_value^2)
        // For categorical: count * W_group^2
        // For continuous: W_feature^2 * normalized_value^2

        $magSqA = ($genreCountA * self::WEIGHT_GENRE * self::WEIGHT_GENRE)
            + ($actorCountA * self::WEIGHT_ACTOR * self::WEIGHT_ACTOR)
            + ($directorCountA * self::WEIGHT_DIRECTOR * self::WEIGHT_DIRECTOR)
            + ($normRatingA * $normRatingA * self::WEIGHT_RATING * self::WEIGHT_RATING)
            + ($normYearA * $normYearA * self::WEIGHT_YEAR * self::WEIGHT_YEAR);

        $magSqB = ($genreCountB * self::WEIGHT_GENRE * self::WEIGHT_GENRE)
            + ($actorCountB * self::WEIGHT_ACTOR * self::WEIGHT_ACTOR)
            + ($directorCountB * self::WEIGHT_DIRECTOR * self::WEIGHT_DIRECTOR)
            + ($normRatingB * $normRatingB * self::WEIGHT_RATING * self::WEIGHT_RATING)
            + ($normYearB * $normYearB * self::WEIGHT_YEAR * self::WEIGHT_YEAR);

        $magnitudeProduct = sqrt($magSqA) * sqrt($magSqB);

        if ($magnitudeProduct === 0.0) {
            return ['score' => 0.0, 'reason' => self::REASON_GENRE];
        }

        $score = $dotProduct / $magnitudeProduct;

        // Determine dominant reason based on each group's contribution to the dot product.
        // Each group's contribution = (intersection * W^2) for categorical, or
        // (normA * normB * W^2) for continuous features.
        $genreContribution = $genreIntersection * self::WEIGHT_GENRE * self::WEIGHT_GENRE;
        $actorContribution = $actorIntersection * self::WEIGHT_ACTOR * self::WEIGHT_ACTOR;
        $directorContribution = $directorIntersection * self::WEIGHT_DIRECTOR * self::WEIGHT_DIRECTOR;
        $ratingContribution = $normRatingA * $normRatingB * self::WEIGHT_RATING * self::WEIGHT_RATING;
        $yearContribution = $normYearA * $normYearB * self::WEIGHT_YEAR * self::WEIGHT_YEAR;

        $contributions = [
            self::REASON_GENRE => $genreContribution,
            self::REASON_ACTOR => $actorContribution,
            self::REASON_DIRECTOR => $directorContribution,
            self::REASON_RATING => $ratingContribution,
            self::REASON_YEAR => $yearContribution,
        ];

        arsort($contributions);
        $reason = (string) array_key_first($contributions);

        return [
            'score' => round($score, 3),
            'reason' => $reason,
        ];
    }

    /**
     * Normalize year to a 0-1 scale based on a reasonable range.
     *
     * @param int $year
     * @return float
     */
    private function normalizeYear(int $year): float
    {
        if ($year === 0) {
            return 0.0;
        }

        // Assume year range of 1900-2100 (200 year span)
        $normalized = ($year - 1900) / 200.0;

        return max(0.0, min(1.0, $normalized));
    }

    /**
     * Normalizes a raw genre/actor/director value into a list of non-empty strings.
     * Handles both flat string arrays and arrays of objects with a `name` key.
     *
     * @param mixed $raw
     * @return list<string>
     */
    private function normalizeStringArray(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            } elseif (is_array($item) && isset($item['name']) && is_string($item['name']) && $item['name'] !== '') {
                $out[] = $item['name'];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Extracts a flat list of names from actors/directors arrays.
     *
     * @param mixed $raw
     * @return list<string>
     */
    private function extractNames(mixed $raw): array
    {
        return $this->normalizeStringArray($raw);
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

        // Support both $.images.poster[0].url and $.poster_url shapes.
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

    /**
     * Extracts the year from a metadata_json blob.
     *
     * @param mixed $metadataJson
     * @return int|null
     */
    private function extractYear(mixed $metadataJson): ?int
    {
        if (!is_string($metadataJson)) {
            return null;
        }

        $decoded = json_decode($metadataJson, true);
        if (!is_array($decoded)) {
            return null;
        }

        $year = $decoded['year'] ?? null;
        if (is_numeric($year)) {
            return (int) $year;
        }

        return null;
    }

    /**
     * Safely converts a mixed value to float.
     *
     * @param mixed $value
     * @return float
     */
    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Safely converts a mixed value to int.
     *
     * @param mixed $value
     * @return int
     */
    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
