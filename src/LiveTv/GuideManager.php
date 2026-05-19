<?php

declare(strict_types=1);

namespace Phlex\LiveTv;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\LiveTv\Dto\RowAccess;
use Phlex\LiveTv\Dto\RowQuery;
use Workerman\MySQL\Connection;

/**
 * Guide Manager - Electronic Program Guide (EPG) functionality.
 *
 * Provides functionality for:
 * - Program info retrieval
 * - Guide data caching
 * - Program search
 * - EPG data import/export
 *
 * ## Program Categories
 *
 * - CATEGORY_MOVIE: Feature films
 * - CATEGORY_SERIES: TV series episodes
 * - CATEGORY_NEWS: News broadcasts
 * - CATEGORY_SPORTS: Sports events
 * - CATEGORY_KIDS: Children's programming
 * - CATEGORY_MUSIC: Music broadcasts
 * - CATEGORY_EDUCATION: Educational content
 * - CATEGORY_OTHER: Miscellaneous content
 *
 * ## Rating Systems
 *
 * - RATING_SYSTEM_TV: US TV ratings (TV-Y, TV-G, etc.)
 * - RATING_SYSTEM_MPAA: MPAA film ratings (G, PG, etc.)
 * - RATING_SYSTEM_ACB: Australian Classification Board
 *
 * ## EPG Data Structure
 *
 * Each program contains:
 * - channel_id: Source channel
 * - title: Program title
 * - description: Program description
 * - start_time/end_time: Air time (Unix timestamps)
 * - category: Program category
 * - series_id: Series grouping (for episodes)
 * - episode info: episode_number, episode_title, series_episode
 * - rating: Content rating
 * - year: Release/production year
 * - is_repeat/is_film: Flags
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @see LiveTvManager For channel integration
 */
class GuideManager
{
    /** @var Connection Database connection */
    private Connection $db;

    /** @var StructuredLogger Structured logger instance */
    private StructuredLogger $logger;

    /** @var array<string, array<string, mixed>> In-memory cache for single-program lookups (keyed `program:<id>`) */
    private array $programCache = [];

    /** @var array<string, array<int, array<string, mixed>>> In-memory cache for channel program listings (keyed `channel:<id>:<start>:<end>`) */
    private array $channelCache = [];

    /** @var int Cache TTL in seconds (default: 1 hour) */
    private int $cacheTtl = 3600;

    /**
     * Movie category.
     *
     * @var string
     */
    public const CATEGORY_MOVIE = 'movie';

    /**
     * TV series episode category.
     *
     * @var string
     */
    public const CATEGORY_SERIES = 'series';

    /**
     * News broadcast category.
     *
     * @var string
     */
    public const CATEGORY_NEWS = 'news';

    /**
     * Sports category.
     *
     * @var string
     */
    public const CATEGORY_SPORTS = 'sports';

    /**
     * Children's programming category.
     *
     * @var string
     */
    public const CATEGORY_KIDS = 'kids';

    /**
     * Music broadcast category.
     *
     * @var string
     */
    public const CATEGORY_MUSIC = 'music';

    /**
     * Educational content category.
     *
     * @var string
     */
    public const CATEGORY_EDUCATION = 'education';

    /**
     * Miscellaneous content category.
     *
     * @var string
     */
    public const CATEGORY_OTHER = 'other';

    /**
     * US TV rating system.
     *
     * @var string
     */
    public const RATING_SYSTEM_TV = 'tv';

    /**
     * MPAA film rating system.
     *
     * @var string
     */
    public const RATING_SYSTEM_MPAA = 'mpaa';

    /**
     * Australian Classification Board rating system.
     *
     * @var string
     */
    public const RATING_SYSTEM_ACB = 'acb';

    /**
     * Creates a new GuideManager instance.
     *
     * @param Connection $db Database connection
     * @param StructuredLogger|null $logger Optional logger, defaults to Livetv channel
     */
    public function __construct(Connection $db, ?StructuredLogger $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);
    }

    /**
     * Get the currently airing program for a channel.
     *
     * @param string $channelId The channel identifier
     * @return array<string, mixed>|null The current program or null if none airing
     */
    public function getCurrentProgram(string $channelId): ?array
    {
        $now = time();

        $result = $this->db->query(
            "SELECT * FROM livetv_programs
             WHERE channel_id = ? AND start_time <= ? AND end_time > ?
             ORDER BY start_time DESC LIMIT 1",
            [$channelId, $now, $now]
        );

        $row = RowQuery::firstRow($result);
        if ($row === null) {
            return null;
        }

        return $this->mapProgram($row);
    }

    /**
     * Get a program by its ID.
     *
     * Uses in-memory cache for repeated lookups.
     *
     * @param string $programId The program identifier
     * @return array<string, mixed>|null The program or null if not found
     */
    public function getProgram(string $programId): ?array
    {
        if (isset($this->programCache[$programId])) {
            return $this->programCache[$programId];
        }

        $result = $this->db->query(
            "SELECT * FROM livetv_programs WHERE program_id = ?",
            [$programId]
        );

        $row = RowQuery::firstRow($result);
        if ($row === null) {
            return null;
        }

        $program = $this->mapProgram($row);
        $this->programCache[$programId] = $program;

        return $program;
    }

    /**
     * Get programs for a channel within a time range.
     *
     * Results are cached for the duration of the request.
     *
     * @param string $channelId The channel identifier
     * @param int $startTime Start timestamp (Unix)
     * @param int $endTime End timestamp (Unix)
     * @return array<int, array<string, mixed>> List of programs in time range
     *
     * @example
     * ```php
     * $programs = $guide->getProgramsForChannel('ch_1', time(), time() + 86400);
     * // Get all programs for the next 24 hours
     * ```
     */
    public function getProgramsForChannel(string $channelId, int $startTime, int $endTime): array
    {
        $cacheKey = "$channelId:$startTime:$endTime";

        if (isset($this->channelCache[$cacheKey])) {
            return $this->channelCache[$cacheKey];
        }

        $result = $this->db->query(
            "SELECT * FROM livetv_programs
             WHERE channel_id = ? AND start_time < ? AND end_time > ?
             ORDER BY start_time ASC",
            [$channelId, $endTime, $startTime]
        );

        $programs = [];
        foreach (RowQuery::rows($result) as $row) {
            $programs[] = $this->mapProgram($row);
        }

        $this->channelCache[$cacheKey] = $programs;

        return $programs;
    }

    /**
     * Get programs for multiple channels within a time range.
     *
     * Returns programs grouped by channel ID.
     *
     * @param array<string> $channelIds List of channel identifiers
     * @param int $startTime Start timestamp (Unix)
     * @param int $endTime End timestamp (Unix)
     * @return array<string, array<int, array<string, mixed>>> Programs grouped by channel
     */
    public function getProgramsForChannels(array $channelIds, int $startTime, int $endTime): array
    {
        if (empty($channelIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($channelIds), '?'));
        $params = array_merge($channelIds, [$endTime, $startTime]);

        $result = $this->db->query(
            "SELECT * FROM livetv_programs
             WHERE channel_id IN ($placeholders) AND start_time < ? AND end_time > ?
             ORDER BY channel_id, start_time ASC",
            $params
        );

        $programsByChannel = [];
        foreach (RowQuery::rows($result) as $row) {
            $channelId = RowAccess::string($row, 'channel_id');
            if (!isset($programsByChannel[$channelId])) {
                $programsByChannel[$channelId] = [];
            }
            $programsByChannel[$channelId][] = $this->mapProgram($row);
        }

        return $programsByChannel;
    }

    /**
     * Search programs by title.
     *
     * Performs a LIKE search on program titles, returning upcoming programs.
     *
     * @param string $query Search query string
     * @param int $limit Maximum number of results (default: 50)
     * @return array<int, array<string, mixed>> Matching programs
     */
    public function searchPrograms(string $query, int $limit = 50): array
    {
        $searchTerm = "%$query%";

        $result = $this->db->query(
            "SELECT * FROM livetv_programs
             WHERE title LIKE ? AND end_time > ?
             ORDER BY start_time ASC
             LIMIT ?",
            [$searchTerm, time(), $limit]
        );

        $programs = [];
        foreach (RowQuery::rows($result) as $row) {
            $programs[] = $this->mapProgram($row);
        }

        return $programs;
    }

    /**
     * Get programs by category.
     *
     * @param string $category One of the CATEGORY_* constants
     * @param int $limit Maximum number of results (default: 100)
     * @return array<int, array<string, mixed>> Programs in category
     */
    public function getProgramsByCategory(string $category, int $limit = 100): array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_programs
             WHERE category = ? AND end_time > ?
             ORDER BY start_time ASC
             LIMIT ?",
            [$category, time(), $limit]
        );

        $programs = [];
        foreach (RowQuery::rows($result) as $row) {
            $programs[] = $this->mapProgram($row);
        }

        return $programs;
    }

    /**
     * Get upcoming episodes for a series.
     *
     * @param string $seriesId The series identifier
     * @param int $limit Maximum number of results (default: 20)
     * @return array<int, array<string, mixed>> Upcoming episodes
     */
    public function getUpcomingBySeries(string $seriesId, int $limit = 20): array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_programs
             WHERE series_id = ? AND end_time > ?
             ORDER BY start_time ASC
             LIMIT ?",
            [$seriesId, time(), $limit]
        );

        $programs = [];
        foreach (RowQuery::rows($result) as $row) {
            $programs[] = $this->mapProgram($row);
        }

        return $programs;
    }

    /**
     * Add or update a program in the guide.
     *
     * @param array<string, mixed> $data Program data including:
     *   - channel_id: string Required
     *   - title: string (default: 'Unknown')
     *   - description: string|null
     *   - start_time: int (default: current time)
     *   - end_time: int (default: current time + 1 hour)
     *   - category: string (default: CATEGORY_OTHER)
     *   - series_id: string|null
     *   - episode_number: int|null
     *   - episode_title: string|null
     *   - rating_system: string (default: RATING_SYSTEM_TV)
     *   - rating: string|null
     *   - year: int|null
     *   - series_episode: string|null
     *   - is_repeat: bool (default: false)
     *   - is_film: bool (default: false)
     *   - xmltv_id: string|null (EPG/xmltv channel ID for IPTV matching)
     * @return array<string, mixed>|null The upserted program or null on failure
     */
    public function upsertProgram(array $data): ?array
    {
        $programIdRaw = $data['program_id'] ?? $this->generateUuid();
        $programId = is_string($programIdRaw) ? $programIdRaw : $this->generateUuid();
        $channelId = is_string($data['channel_id'] ?? null) ? (string) $data['channel_id'] : '';

        $this->db->query(
            "INSERT INTO livetv_programs
             (program_id, channel_id, title, description, start_time, end_time,
              category, series_id, episode_number, episode_title, rating_system,
              rating, year, series_episode, is_repeat, is_film, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = VALUES(description),
                start_time = VALUES(start_time),
                end_time = VALUES(end_time),
                category = VALUES(category),
                series_id = VALUES(series_id),
                episode_number = VALUES(episode_number),
                episode_title = VALUES(episode_title),
                rating_system = VALUES(rating_system),
                rating = VALUES(rating),
                year = VALUES(year),
                series_episode = VALUES(series_episode),
                is_repeat = VALUES(is_repeat),
                is_film = VALUES(is_film),
                updated_at = NOW()",
            [
                $programId,
                $channelId,
                $data['title'] ?? 'Unknown',
                $data['description'] ?? null,
                $data['start_time'] ?? time(),
                $data['end_time'] ?? (time() + 3600),
                $data['category'] ?? self::CATEGORY_OTHER,
                $data['series_id'] ?? null,
                $data['episode_number'] ?? null,
                $data['episode_title'] ?? null,
                $data['rating_system'] ?? self::RATING_SYSTEM_TV,
                $data['rating'] ?? null,
                $data['year'] ?? null,
                $data['series_episode'] ?? null,
                $data['is_repeat'] ?? false,
                $data['is_film'] ?? false,
            ]
        );

        // Invalidate cache
        $this->invalidateCacheForChannel($channelId);

        $this->logger->debug('Program upserted', ['program_id' => $programId, 'title' => $data['title'] ?? 'Unknown']);

        return $this->getProgram($programId);
    }

    /**
     * Delete a program from the guide.
     *
     * @param string $programId The program to delete
     * @return bool True if deleted, false if not found
     */
    public function deleteProgram(string $programId): bool
    {
        $program = $this->getProgram($programId);
        if (!$program) {
            return false;
        }

        $this->db->query("DELETE FROM livetv_programs WHERE program_id = ?", [$programId]);

        $channelId = is_string($program['channel_id'] ?? null) ? (string) $program['channel_id'] : '';
        $this->invalidateCacheForChannel($channelId);

        $this->logger->debug('Program deleted', ['program_id' => $programId]);

        return true;
    }

    /**
     * Remove programs that have ended before the cutoff date.
     *
     * @param int $daysToKeep Number of days of programs to retain (default: 7)
     * @return int Number of programs deleted
     */
    public function cleanupOldPrograms(int $daysToKeep = 7): int
    {
        $cutoff = time() - ($daysToKeep * 86400);

        $result = $this->db->query(
            "DELETE FROM livetv_programs WHERE end_time < ?",
            [$cutoff]
        );

        // Workerman MySQL\Connection::query() returns rowCount for DELETE
        // statements; fall back to 0 for unexpected return shapes.
        $deleted = is_int($result) ? $result : 0;

        if ($deleted > 0) {
            $this->logger->info('Cleaned up old programs', ['deleted' => $deleted, 'cutoff_days' => $daysToKeep]);
            $this->clearCache();
        }

        return $deleted;
    }

    /**
     * Import guide data from an external source.
     *
     * Accepts an array of program data arrays and inserts/updates each.
     * Requires channel_id, start_time, and end_time for each program.
     *
     * @param array<int, array<string, mixed>> $programs Array of program data
     * @return array{imported: int, errors: array<string>} Import results with count and errors
     *
     * @example
     * ```php
     * $result = $guide->importGuideData([
     *     ['channel_id' => 'ch_1', 'title' => 'News', 'start_time' => time(), 'end_time' => time() + 3600],
     * ]);
     * echo "Imported: {$result['imported']}, Errors: " . count($result['errors']);
     * ```
     */
    public function importGuideData(array $programs): array
    {
        $imported = 0;
        $errors = [];

        foreach ($programs as $data) {
            try {
                if (isset($data['channel_id']) && isset($data['start_time']) && isset($data['end_time'])) {
                    $this->upsertProgram($data);
                    $imported++;
                } else {
                    $errors[] = "Missing required fields: " . json_encode($data);
                }
            } catch (\Throwable $e) {
                $errors[] = "Error importing program: " . $e->getMessage();
            }
        }

        $this->logger->info('Guide data imported', ['imported' => $imported, 'errors' => count($errors)]);

        return [
            'imported' => $imported,
            'errors' => $errors,
        ];
    }

    /**
     * Export guide data for a time range.
     *
     * @param int $startTime Start timestamp (Unix)
     * @param int $endTime End timestamp (Unix)
     * @return array<int, array<string, mixed>> Programs in the time range
     */
    public function exportGuideData(int $startTime, int $endTime): array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_programs
             WHERE start_time >= ? AND start_time <= ?
             ORDER BY channel_id, start_time ASC",
            [$startTime, $endTime]
        );

        $programs = [];
        foreach (RowQuery::rows($result) as $row) {
            $programs[] = $this->mapProgram($row);
        }

        return $programs;
    }

    /**
     * Get guide data for a specific channel.
     *
     * Convenience method that fetches programs for the next N days.
     *
     * @param string $channelId The channel identifier
     * @param int $days Number of days to fetch (default: 7)
     * @return array<int, array<string, mixed>> Upcoming programs
     */
    public function getChannelGuide(string $channelId, int $days = 7): array
    {
        $startTime = time();
        $endTime = $startTime + ($days * 86400);

        return $this->getProgramsForChannel($channelId, $startTime, $endTime);
    }

    /**
     * Set the cache TTL (time-to-live).
     *
     * @param int $seconds Cache TTL in seconds
     * @return void
     */
    public function setCacheTtl(int $seconds): void
    {
        $this->cacheTtl = $seconds;
    }

    /**
     * Clear all cached program data.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->programCache = [];
        $this->channelCache = [];
        $this->logger->debug('Guide cache cleared');
    }

    /**
     * Get cache statistics.
     *
     * @return array{entries: int, ttl: int} Cache stats
     */
    public function getCacheStats(): array
    {
        return [
            'entries' => count($this->programCache) + count($this->channelCache),
            'ttl' => $this->cacheTtl,
        ];
    }

    /**
     * Invalidate cache entries for a channel.
     *
     * Currently clears all cache. A more sophisticated implementation
     * would track cache keys per channel.
     *
     * @param string $channelId The channel whose cache should be invalidated
     * @return void
     */
    private function invalidateCacheForChannel(string $channelId): void
    {
        unset($channelId); // future implementations may filter by channel
        $this->programCache = [];
        $this->channelCache = [];
    }

    /**
     * Map a database row to a program array.
     *
     * @param array<string, mixed> $row Raw database row
     * @return array<string, mixed> Normalized program data
     */
    private function mapProgram(array $row): array
    {
        $start = RowAccess::int($row, 'start_time');
        $end = RowAccess::int($row, 'end_time');

        return [
            'id' => $row['program_id'] ?? null,
            'program_id' => $row['program_id'] ?? null,
            'channel_id' => $row['channel_id'] ?? null,
            'title' => $row['title'] ?? null,
            'description' => $row['description'] ?? null,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'category' => $row['category'] ?? null,
            'series_id' => $row['series_id'] ?? null,
            'episode_number' => RowAccess::intOrNull($row, 'episode_number'),
            'episode_title' => $row['episode_title'] ?? null,
            'rating_system' => $row['rating_system'] ?? null,
            'rating' => $row['rating'] ?? null,
            'year' => RowAccess::intOrNull($row, 'year'),
            'series_episode' => $row['series_episode'] ?? null,
            'is_repeat' => RowAccess::bool($row, 'is_repeat'),
            'is_film' => RowAccess::bool($row, 'is_film'),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * Generate a unique UUID v4 string.
     *
     * @return string A UUID in the format xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
