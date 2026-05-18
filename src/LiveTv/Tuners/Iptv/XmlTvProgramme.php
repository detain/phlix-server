<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Tuners\Iptv;

/**
 * Immutable value object representing a single XMLTV programme entry.
 *
 * Encapsulates programme information extracted from an XMLTV guide file,
 * including timing, metadata, and content rating.
 *
 * @since 0.12.0
 */
final class XmlTvProgramme
{
    /**
     * @param string $channelId The channel this programme belongs to (xmltv-id)
     * @param int $startTime Programme start time as Unix timestamp
     * @param int $endTime Programme end time as Unix timestamp
     * @param string $title Programme title
     * @param string|null $description Programme description/synopsis
     * @param string|null $category Programme category/genre
     * @param string|null $episodeNum Episode number (e.g., "S01E02" or "2")
     * @param string|null $rating Content rating (e.g., "TV-PG", "PG-13")
     * @param int|null $year Production/release year
     */
    public function __construct(
        public readonly string $channelId,
        public readonly int $startTime,
        public readonly int $endTime,
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly ?string $category = null,
        public readonly ?string $episodeNum = null,
        public readonly ?string $rating = null,
        public readonly ?int $year = null,
    ) {
    }

    /**
     * Get the duration of this programme in seconds.
     *
     * @return int Duration in seconds
     */
    public function getDuration(): int
    {
        return $this->endTime - $this->startTime;
    }

    /**
     * Check if this programme is currently airing.
     *
     * @param int|null $now Optional timestamp to check against (defaults to current time)
     * @return bool True if currently airing
     */
    public function isAiring(?int $now = null): bool
    {
        $now = $now ?? time();
        return $this->startTime <= $now && $this->endTime > $now;
    }

    /**
     * Convert to array representation for database storage.
     *
     * @return array<string, mixed> Array representation
     */
    public function toArray(): array
    {
        return [
            'channel_id' => $this->channelId,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'episode_num' => $this->episodeNum,
            'rating' => $this->rating,
            'year' => $this->year,
        ];
    }
}
