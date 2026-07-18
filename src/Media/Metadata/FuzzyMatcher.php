<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Metadata\Dto\MetadataValue;
use Workerman\MySQL\Connection;

/**
 * Fuzzy matching service for metadata resolution.
 *
 * Provides Levenshtein-distance-based similarity search across TMDB/IMDb
 * results and manages manual match overrides stored in the database.
 *
 * @package Phlix\Media\Metadata
 * @since   0.26.0
 */
final class FuzzyMatcher
{
    /** @var Connection Async MySQL connection used for all queries. */
    private Connection $db;

    /** @var TmdbProvider TMDB provider for title searches. */
    private TmdbProvider $tmdb;

    /** @var StructuredLogger Structured logger instance. */
    private StructuredLogger $logger;

    /**
     * @param Connection     $db   Workerman MySQL connection.
     * @param TmdbProvider   $tmdb TMDB provider for searches.
     * @param StructuredLogger|null $logger Optional logger; defaults to MEDIA channel.
     *
     * @since 0.26.0
     */
    public function __construct(
        Connection $db,
        TmdbProvider $tmdb,
        ?StructuredLogger $logger = null
    ) {
        $this->db = $db;
        $this->tmdb = $tmdb;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Find similar items using Levenshtein distance on normalized titles
     * and year proximity scoring.
     *
     * Searches TMDB/IMDb for titles similar to the given title and year,
     * scores each candidate by textual similarity + year proximity,
     * and returns the top matches with confidence scores.
     *
     * @param string $title   Raw movie/series title to match.
     * @param int    $year    Release/first-air year hint.
     * @param string $provider Data source: 'tmdb' (default) or 'imdb'.
     * @param int    $limit   Maximum matches to return (clamped to [1, 50]).
     *
     * @return list<array{
     *     provider: string,
     *     provider_id: string,
     *     title: string,
     *     year: int|null,
     *     overview: string,
     *     poster_url: string|null,
     *     vote_average: float,
     *     confidence: float
     * }> Sorted by confidence descending. Empty list when no matches.
     *
     * @since 0.26.0
     */
    public function findSimilar(string $title, int $year, string $provider = 'tmdb', int $limit = 5): array
    {
        if ($title === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));

        $searchResults = $this->searchProvider($title, $year, $provider);
        if ($searchResults === []) {
            return [];
        }

        $normalizedQuery = self::normalizeForComparison($title);
        $scored = [];

        foreach ($searchResults as $result) {
            $resultTitle = MetadataValue::asString($result['title'] ?? null);
            if ($resultTitle === '') {
                continue;
            }

            $resultYear = $this->yearFromResult($result);
            $normalizedResult = self::normalizeForComparison($resultTitle);

            $levenshtein = levenshtein($normalizedQuery, $normalizedResult);
            $maxLen = max(mb_strlen($normalizedQuery, 'UTF-8'), mb_strlen($normalizedResult, 'UTF-8'));

            // Levenshtein similarity: 1 - (distance / maxLength), clamped to [0, 1]
            $textSimilarity = $maxLen > 0 ? max(0.0, 1.0 - ($levenshtein / $maxLen)) : 0.0;

            // Year proximity score: 1.0 if exact match, decay by 0.15 per year difference
            $yearDiff = abs($year - $resultYear);
            $yearScore = max(0.0, 1.0 - ($yearDiff * 0.15));

            // Combined confidence: weighted average (70% text, 30% year)
            $confidence = round(($textSimilarity * 0.7) + ($yearScore * 0.3), 2);

            $scored[] = [
                'provider' => $provider,
                'provider_id' => MetadataValue::asString($result['id'] ?? null),
                'title' => $resultTitle,
                'year' => $resultYear,
                'overview' => MetadataValue::asString($result['overview'] ?? null),
                'poster_url' => $this->imageUrl(MetadataValue::asNullableString($result['poster_path'] ?? null)),
                'vote_average' => MetadataValue::asFloat($result['vote_average'] ?? null),
                'confidence' => $confidence,
            ];
        }

        // Sort by confidence descending
        usort($scored, static fn(array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Persist a manual match override.
     *
     * When a user (or the system) explicitly chooses a match for a provider ID,
     * this records that override so future matching prefers it. Uses
     * INSERT ... ON DUPLICATE KEY UPDATE so repeated calls from the same
     * source (user/system) update rather than duplicate.
     *
     * @param string $provider     Source: 'tmdb', 'imdb', or 'anidb'.
     * @param string $providerId  The source's item id (e.g. TMDB numeric id as string).
     * @param string $mediaItemId The media_item.id this provider id maps to.
     * @param float  $confidence  Override confidence (0.00–1.00).
     * @param string $matchedBy   Who set the override: 'user' (default) or 'system'.
     *
     * @since 0.26.0
     */
    public function setManualOverride(
        string $provider,
        string $providerId,
        string $mediaItemId,
        float $confidence,
        string $matchedBy = 'user'
    ): void {
        if (!$this->isValidProvider($provider)) {
            $this->logger->warning('FuzzyMatcher: invalid provider for override', ['provider' => $provider]);
            return;
        }

        if ($providerId === '' || $mediaItemId === '') {
            $this->logger->warning('FuzzyMatcher: empty provider_id or media_item_id for override', [
                'provider_id' => $providerId,
                'media_item_id' => $mediaItemId,
            ]);
            return;
        }

        $confidence = max(0.0, min(1.0, $confidence));
        $matchedBy = $matchedBy === 'system' ? 'system' : 'user';

        $this->db->query(
            "INSERT INTO manual_match_overrides
             (provider, provider_id, media_item_id, confidence, matched_by, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                 media_item_id = VALUES(media_item_id),
                 confidence = VALUES(confidence),
                 matched_by = VALUES(matched_by),
                 created_at = NOW()",
            [$provider, $providerId, $mediaItemId, $confidence, $matchedBy]
        );

        $this->logger->debug('FuzzyMatcher: manual override saved', [
            'provider' => $provider,
            'provider_id' => $providerId,
            'media_item_id' => $mediaItemId,
            'confidence' => $confidence,
            'matched_by' => $matchedBy,
        ]);
    }

    /**
     * Retrieve a manual match override for a provider + id pair.
     *
     * Returns the override row when one exists, keyed on the unique
     * (provider, provider_id) constraint. A manual override short-circuits
     * the normal resolver search, giving trusted matches priority.
     *
     * @param string $provider  Source: 'tmdb', 'imdb', or 'anidb'.
     * @param string $providerId The source's item id.
     *
     * @return array{
     *     media_item_id: string,
     *     confidence: float,
     *     matched_by: string,
     *     created_at: string
     * }|null The override row, or null when no override exists.
     *
     * @since 0.26.0
     */
    public function getManualOverride(string $provider, string $providerId): ?array
    {
        if (!$this->isValidProvider($provider) || $providerId === '') {
            return null;
        }

        $rows = $this->db->query(
            'SELECT media_item_id, confidence, matched_by, created_at '
            . 'FROM manual_match_overrides '
            . 'WHERE provider = ? AND provider_id = ? '
            . 'LIMIT 1',
            [$provider, $providerId]
        );

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $row = $rows[0];
        if (!is_array($row)) {
            return null;
        }

        return [
            'media_item_id' => is_scalar($row['media_item_id'] ?? null) ? (string) $row['media_item_id'] : '',
            'confidence' => is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : 0.0,
            'matched_by' => is_scalar($row['matched_by'] ?? null) ? (string) $row['matched_by'] : 'user',
            'created_at' => is_scalar($row['created_at'] ?? null) ? (string) $row['created_at'] : '',
        ];
    }

    /**
     * Check whether a manual override exists for a provider/id.
     *
     * @param string $provider  Source: 'tmdb', 'imdb', or 'anidb'.
     * @param string $providerId The source's item id.
     *
     * @return bool True when an override exists.
     *
     * @since 0.26.0
     */
    public function hasManualOverride(string $provider, string $providerId): bool
    {
        return $this->getManualOverride($provider, $providerId) !== null;
    }

    /**
     * Remove a manual override.
     *
     * @param string $provider  Source: 'tmdb', 'imdb', or 'anidb'.
     * @param string $providerId The source's item id.
     *
     * @return bool True when a row was deleted.
     *
     * @since 0.26.0
     */
    public function removeManualOverride(string $provider, string $providerId): bool
    {
        if (!$this->isValidProvider($provider) || $providerId === '') {
            return false;
        }

        $affected = $this->db->query(
            'DELETE FROM manual_match_overrides WHERE provider = ? AND provider_id = ?',
            [$provider, $providerId]
        );

        return is_numeric($affected) && $affected > 0;
    }

    /**
     * Normalize a title for Levenshtein comparison.
     *
     * Strip articles, punctuation, and collapse whitespace so the
     * distance metric focuses on the meaningful title text.
     *
     * @param string $title Raw title.
     *
     * @return string Normalized title.
     */
    private static function normalizeForComparison(string $title): string
    {
        $normalized = mb_strtolower($title, 'UTF-8');
        $normalized = preg_replace('/[:\-\'\"!?\.\,\(\)\[\]]/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        foreach (['the ', 'a ', 'an '] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
            }
        }

        return $normalized;
    }

    /**
     * Search TMDB or IMDb for a title and return raw results.
     *
     * @param string $title   Search query.
     * @param int    $year    Optional year filter.
     * @param string $provider 'tmdb' or 'imdb'.
     *
     * @return array<int, array<string, mixed>> TMDB-style result rows.
      */
    private function searchProvider(string $title, int $year, string $provider): array
    {
        try {
            if ($provider === 'imdb') {
                // IMDb uses the same search endpoint; TMDB's find endpoint
                // maps IMDb ids to TMDB entries. For pure IMDb title search
                // we fall back to TMDB (IMDb doesn't expose a public search API).
                $options = $year > 0 ? ['year' => $year] : [];
                return array_values($this->tmdb->search($title, $options));
            }

            // Default: TMDB movie search
            $options = $year > 0 ? ['year' => $year] : [];
            return array_values($this->tmdb->search($title, $options));
        } catch (\Throwable $e) {
            $this->logger->warning('FuzzyMatcher: search failed', [
                'provider' => $provider,
                'title' => $title,
                'year' => $year,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Extract a 4-digit year from a TMDB result row.
     *
     * @param array<string, mixed> $result TMDB result row.
     *
     * @return int Year (YYYY), or 0 when absent.
     */
    private function yearFromResult(array $result): int
    {
        $dateKey = $result['release_date'] ?? $result['first_air_date'] ?? null;
        if (!is_string($dateKey) || $dateKey === '') {
            return 0;
        }
        if (preg_match('/^(\d{4})/', $dateKey, $m) === 1) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * Build a full TMDB image URL from a `/path.jpg` fragment, or null.
     */
    private function imageUrl(?string $path): ?string
    {
        if (!is_string($path) || $path === '') {
            return null;
        }
        return 'https://image.tmdb.org/t/p/w500' . $path;
    }

    /**
     * Validate a provider string against the known set.
     */
    private function isValidProvider(string $provider): bool
    {
        return in_array($provider, ['tmdb', 'imdb', 'anidb'], true);
    }
}
