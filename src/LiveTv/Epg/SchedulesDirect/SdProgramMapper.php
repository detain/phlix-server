<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Epg\SchedulesDirect;

use Phlex\LiveTv\GuideManager;

/**
 * Maps Schedules Direct data to Phlex GuideManager format.
 *
 * Converts SD schedule entries, program metadata, and station data
 * into the flat array format expected by GuideManager::upsertProgram()
 * and ChannelManager::createChannel().
 *
 * @since 0.12.0
 */
class SdProgramMapper
{
    /**
     * Map an SD schedule + program entry to a GuideManager::upsertProgram()-compatible array.
     *
     * @param array<string, mixed> $scheduleEntry SD schedule entry (from getSchedules)
     * @param array<string, mixed> $programData SD program metadata (from getPrograms)
     * @return array<string, mixed> Data suitable for GuideManager::upsertProgram()
     */
    public function map(array $scheduleEntry, array $programData): array
    {
        $programIdRaw = $scheduleEntry['programID'] ?? null;
        $channelIdRaw = $scheduleEntry['stationID'] ?? null;

        if (!is_string($programIdRaw) || !is_string($channelIdRaw)) {
            return [];
        }

        $channelId = $channelIdRaw;

        // Parse times - SD returns ISO 8601
        /** @var string|null $airDateTime */
        $airDateTime = $scheduleEntry['airDateTime'] ?? null;
        $startTime = $this->parseTime($airDateTime);
        $duration = $scheduleEntry['duration'] ?? null;
        $endTime = null;
        if (is_numeric($duration) && $startTime !== null) {
            $endTime = $startTime + ((int) $duration * 60);
        }

        // Extract program details from the nested program data
        $title = $this->getNestedValue($programData, ['title', 'title120'], 'Unknown');
        $description = $this->getNestedValue($programData, ['description', 'description169'], null);

        // Episode metadata
        $episodeTitle = $this->getNestedValue($programData, ['episodeTitle'], null);
        $episodeNumber = $this->parseEpisodeNumber($programData);
        $seasonNumber = $this->parseSeasonNumber($programData);
        $seriesId = $programData['programID'] ?? null;

        // Build series_episode string like "S01E02"
        $seriesEpisode = null;
        if ($seasonNumber !== null && $episodeNumber !== null) {
            $seriesEpisode = sprintf('S%02dE%02d', $seasonNumber, $episodeNumber);
        }

        // Ratings
        $rating = $this->extractRating($programData);

        // Year from original air date or movie year
        $year = null;
        $originalAirDate = $this->getNestedValue($programData, ['originalAirDate'], null);
        if ($originalAirDate !== null && is_string($originalAirDate) && strlen($originalAirDate) >= 4) {
            $year = (int) substr($originalAirDate, 0, 4);
        } elseif (isset($programData['metadata']) && is_array($programData['metadata'])) {
            foreach ($programData['metadata'] as $meta) {
                if (is_array($meta) && ($meta['type'] ?? '') === 'md5') {
                    $yearValue = $meta['year'] ?? null;
                    if (is_numeric($yearValue)) {
                        $year = (int) $yearValue;
                    }
                    break;
                }
            }
        }

        /** @var bool $isRepeat */
        $isRepeat = (bool) ($scheduleEntry['isRepeat'] ?? false);
        $entityType = $programData['entityType'] ?? '';
        $isFilm = is_string($entityType) && strtolower($entityType) === 'movie';

        // Category mapping
        $category = $this->mapCategory($programData);

        /** @var string $title */
        return [
            'program_id' => $this->generateProgramId($channelId, $startTime),
            'channel_id' => $channelId,
            'title' => $title,
            'description' => $description,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'category' => $category,
            'series_id' => $seriesId,
            'episode_number' => $episodeNumber,
            'episode_title' => $episodeTitle,
            'series_episode' => $seriesEpisode,
            'rating' => $rating,
            'rating_system' => GuideManager::RATING_SYSTEM_TV,
            'year' => $year,
            'is_repeat' => $isRepeat,
            'is_film' => $isFilm,
        ];
    }

    /**
     * Map SD station data to channel creation data.
     *
     * @param array<string, mixed> $station SD station entry
     * @return array<string, mixed>|null Data suitable for ChannelManager::createChannel() or null if insufficient data
     */
    public function mapStation(array $station): ?array
    {
        $stationIdRaw = $station['stationID'] ?? null;
        $name = $station['callSign'] ?? null;

        if (!is_string($stationIdRaw) || !is_string($name)) {
            return null;
        }

        $stationId = $stationIdRaw;

        // Channel number from the affiliate or logical channel number
        $numberRaw = $station['channelNumber'] ?? $station['logicalChannelNumber'] ?? 0;
        $number = is_numeric($numberRaw) ? (int) $numberRaw : 0;

        // Type is typically TV
        $type = 'tv';

        // Optional description
        $description = is_string($station['stationName'] ?? null) ? $station['stationName'] : null;

        // Icon URL if available
        $iconUrl = null;
        if (isset($station['logo']) && is_array($station['logo'])) {
            $iconUrl = is_string($station['logo']['URL'] ?? null) ? $station['logo']['URL'] : null;
        }

        return [
            'name' => $name,
            'number' => $number,
            'type' => $type,
            'frequency' => 0,
            'tuner_id' => 'sd_' . $stationId,
            'service_id' => $stationId,
            'description' => $description,
            'icon_url' => $iconUrl,
        ];
    }

    /**
     * Parse ISO 8601 datetime string to Unix timestamp.
     *
     * @param string|null $isoDatetime ISO 8601 datetime string
     * @return int|null Unix timestamp or null on parse failure
     */
    private function parseTime(?string $isoDatetime): ?int
    {
        if ($isoDatetime === null) {
            return null;
        }

        // SD uses ISO 8601 with Z suffix (UTC)
        // Handle formats like "2024-01-15T14:00:00Z"
        try {
            $dateTime = new \DateTimeImmutable($isoDatetime);
            return $dateTime->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Extract the first available value from nested keys.
     *
     * @param array<string, mixed> $data Data array to search
     * @param array<int, string> $keys Ordered list of keys to try
     * @param mixed $default Default value if no key is found
     * @return mixed Found value or default
     */
    private function getNestedValue(array $data, array $keys, mixed $default): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }
        return $default;
    }

    /**
     * Parse episode number from program data.
     *
     * @param array<string, mixed> $programData SD program data
     * @return int|null Episode number or null if not an episode
     */
    private function parseEpisodeNumber(array $programData): ?int
    {
        // SD uses 0-indexed episode numbers
        $episode = $programData['episodeNumber'] ?? null;
        if ($episode === null || !is_numeric($episode)) {
            return null;
        }
        return (int) $episode + 1; // Convert to 1-indexed
    }

    /**
     * Parse season number from program data.
     *
     * @param array<string, mixed> $programData SD program data
     * @return int|null Season number or null
     */
    private function parseSeasonNumber(array $programData): ?int
    {
        $seasonNumber = $programData['seasonNumber'] ?? null;
        if ($seasonNumber === null || !is_numeric($seasonNumber)) {
            return null;
        }
        return (int) $seasonNumber;
    }

    /**
     * Extract the first content rating from program data.
     *
     * @param array<string, mixed> $programData SD program data
     * @return string|null Rating string (e.g., "TV-G", "PG-13") or null
     */
    private function extractRating(array $programData): ?string
    {
        // Try parental guide ratings first
        $contentRatings = $programData['contentRating'] ?? $programData['contentRatings'] ?? null;

        if ($contentRatings === null) {
            return null;
        }

        if (!is_array($contentRatings)) {
            $contentRatings = [$contentRatings];
        }

        foreach ($contentRatings as $rating) {
            if (is_array($rating)) {
                $val = $rating['value'] ?? $rating['rating'] ?? null;
                if ($val !== null && is_string($val)) {
                    return $val;
                }
            } elseif (is_string($rating)) {
                return $rating;
            }
        }

        return null;
    }

    /**
     * Map SD program data to a GuideManager category constant.
     *
     * @param array<string, mixed> $programData SD program data
     * @return string One of the GuideManager::CATEGORY_* constants
     */
    private function mapCategory(array $programData): string
    {
        $genres = $programData['genres'] ?? [];
        if (!is_array($genres)) {
            $genres = [$genres];
        }

        /** @var array<int, string> $genreLabels */
        $genreLabels = array_map('strtolower', array_filter($genres, 'is_string'));

        if (in_array('movie', $genreLabels, true)) {
            return GuideManager::CATEGORY_MOVIE;
        }
        if (in_array('sports', $genreLabels, true)) {
            return GuideManager::CATEGORY_SPORTS;
        }
        if (in_array('news', $genreLabels, true)) {
            return GuideManager::CATEGORY_NEWS;
        }
        if (in_array('children', $genreLabels, true) || in_array('kids', $genreLabels, true)) {
            return GuideManager::CATEGORY_KIDS;
        }
        if (in_array('music', $genreLabels, true)) {
            return GuideManager::CATEGORY_MUSIC;
        }
        if (in_array('education', $genreLabels, true)) {
            return GuideManager::CATEGORY_EDUCATION;
        }
        if (in_array('series', $genreLabels, true) || ($programData['entityType'] ?? '') === 'episode') {
            return GuideManager::CATEGORY_SERIES;
        }

        return GuideManager::CATEGORY_OTHER;
    }

    /**
     * Generate a deterministic program ID from channel and start time.
     *
     * @param string $channelId SD channel/station ID
     * @param int|null $startTime Start timestamp
     * @return string Deterministic program ID
     */
    private function generateProgramId(string $channelId, ?int $startTime): string
    {
        $unique = $channelId . '_' . ($startTime ?? time());
        return md5($unique);
    }
}
