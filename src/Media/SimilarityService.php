<?php

declare(strict_types=1);

namespace Phlix\Media;

use Phlix\Media\Library\ItemRepository;
use Workerman\MySQL\Connection;

/**
 * Computes and retrieves item-similarity scores using a weighted combination
 * of genre overlap (Jaccard), person overlap (Jaccard), rating proximity,
 * and year proximity.
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


    /** Weights for each similarity component (must sum to 1.0). */
    private const WEIGHT_GENRE = 0.35;
    private const WEIGHT_ACTOR = 0.25;
    private const WEIGHT_DIRECTOR = 0.15;
    private const WEIGHT_RATING = 0.15;
    private const WEIGHT_YEAR = 0.10;

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
     * @param string $mediaItemId The source item to compute similarities for.
     * @return void
     */
    public function computeSimilarForItem(string $mediaItemId): void
    {
        $sourceItem = $this->itemRepository->findById($mediaItemId);
        if ($sourceItem === null) {
            return;
        }

        $sourceMetadata = $this->extractMetadata($sourceItem);
        if (!$this->hasCompleteMetadata($sourceMetadata)) {
            return;
        }

        // Fetch all items with complete metadata, excluding the source item itself.
        $candidates = $this->fetchItemsWithCompleteMetadata($mediaItemId);

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
     * @return list<array{id: string, title: string, posterUrl: string|null, score: float, reason: string}>
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
     * @param string $excludeId Item ID to exclude from results.
     * @return list<array<string, mixed>>
     */
    private function fetchItemsWithCompleteMetadata(string $excludeId): array
    {
        // Use a subquery approach to filter items that have all required metadata.
        // We use JSON_EXTRACT for rating/year and JSON_LENGTH for the arrays.
        // Actors/directors can be either array of strings or array of objects with 'name'.
        $results = $this->db->query(
            "SELECT id, metadata_json
             FROM media_items
             WHERE id != ?
               AND JSON_LENGTH(metadata_json, '\$.genres') > 0
               AND JSON_LENGTH(metadata_json, '\$.actors') > 0
               AND JSON_LENGTH(metadata_json, '\$.directors') > 0
               AND JSON_EXTRACT(metadata_json, '\$.rating') IS NOT NULL
               AND JSON_EXTRACT(metadata_json, '\$.year') IS NOT NULL
               AND JSON_TYPE(JSON_EXTRACT(metadata_json, '\$.rating')) = 'DOUBLE'
               AND JSON_TYPE(JSON_EXTRACT(metadata_json, '\$.year')) = 'INT'",
            [$excludeId]
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
     * Computes the weighted similarity between two metadata sets.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array{score: float, reason: string}
     */
    private function computeSimilarity(array $a, array $b): array
    {
        $genreSim = $this->jaccardSimilarity(
            $this->normalizeStringArray($a['genres'] ?? []),
            $this->normalizeStringArray($b['genres'] ?? [])
        );

        $actorSim = $this->jaccardSimilarity(
            $this->extractNames($a['actors'] ?? []),
            $this->extractNames($b['actors'] ?? [])
        );

        $directorSim = $this->jaccardSimilarity(
            $this->extractNames($a['directors'] ?? []),
            $this->extractNames($b['directors'] ?? [])
        );

        $ratingSim = $this->ratingSimilarity(
            $this->toFloat($a['rating'] ?? null),
            $this->toFloat($b['rating'] ?? null)
        );

        $yearSim = $this->yearSimilarity(
            $this->toInt($a['year'] ?? null),
            $this->toInt($b['year'] ?? null)
        );

        $score = ($genreSim * self::WEIGHT_GENRE)
            + ($actorSim * self::WEIGHT_ACTOR)
            + ($directorSim * self::WEIGHT_DIRECTOR)
            + ($ratingSim * self::WEIGHT_RATING)
            + ($yearSim * self::WEIGHT_YEAR);

        // Determine the dominant reason (the individual component with highest contribution).
        $contributions = [
            self::REASON_GENRE => $genreSim * self::WEIGHT_GENRE,
            self::REASON_ACTOR => $actorSim * self::WEIGHT_ACTOR,
            self::REASON_DIRECTOR => $directorSim * self::WEIGHT_DIRECTOR,
            self::REASON_RATING => $ratingSim * self::WEIGHT_RATING,
            self::REASON_YEAR => $yearSim * self::WEIGHT_YEAR,
        ];

        arsort($contributions);
        $reason = (string) array_key_first($contributions);

        return [
            'score' => round($score, 3),
            'reason' => $reason,
        ];
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
     * Jaccard similarity coefficient: |A ∩ B| / |A ∪ B|.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return float
     */
    private function jaccardSimilarity(array $a, array $b): float
    {
        if ($a === [] && $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Rating similarity: 1 - |r1-r2|/10 (rating scale 0-10).
     *
     * @param float $r1
     * @param float $r2
     * @return float
     */
    private function ratingSimilarity(float $r1, float $r2): float
    {
        $diff = abs($r1 - $r2) / 10.0;

        return max(0.0, 1.0 - $diff);
    }

    /**
     * Year proximity: 1 - |y1-y2|/100 (capped at 0).
     *
     * @param int $y1
     * @param int $y2
     * @return float
     */
    private function yearSimilarity(int $y1, int $y2): float
    {
        if ($y1 === 0 || $y2 === 0) {
            return 0.0;
        }

        $diff = abs($y1 - $y2) / 100.0;

        return max(0.0, 1.0 - $diff);
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
