<?php

declare(strict_types=1);

namespace Phlex\Auth\Dto;

use Phlex\Common\Util\RowMap;

/**
 * Typed value object representing a hydrated row from `watch_history`.
 *
 * Optionally enriched with JOINed columns from `media_items`
 * (`media_name`, `media_type`, `metadata_json`) so callers can render
 * "continue watching" / history lists without re-querying.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Strongly-typed watch-history row from DB hydration.
 * @since Wave 5b-J
 */
final class WatchHistoryRow
{
    /**
     * @param array<string, mixed> $metadata Decoded media metadata blob (may be empty).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $profileId,
        public readonly string $mediaItemId,
        public readonly int $positionTicks,
        public readonly ?int $durationTicks,
        public readonly string $playbackStatus,
        public readonly float $progressPercent,
        public readonly string $lastWatchedAt,
        public readonly ?string $createdAt,
        public readonly ?string $completedAt,
        public readonly ?string $mediaName,
        public readonly ?string $mediaType,
        public readonly array $metadata,
    ) {
    }

    /**
     * Hydrate from a raw DB row map (already narrowed via RowMap).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $durationRaw = $row['duration_ticks'] ?? null;
        $duration = is_numeric($durationRaw) ? (int) $durationRaw : null;

        $mediaName = isset($row['media_name']) && is_string($row['media_name'])
            ? $row['media_name'] : null;
        $mediaType = isset($row['media_type']) && is_string($row['media_type'])
            ? $row['media_type'] : null;

        $metadata = [];
        if (array_key_exists('metadata_json', $row)) {
            $metadata = self::decodeMetadata($row['metadata_json']);
        }

        return new self(
            id: self::asString($row['id'] ?? ''),
            profileId: self::asString($row['profile_id'] ?? ''),
            mediaItemId: self::asString($row['media_item_id'] ?? ''),
            positionTicks: self::asInt($row['position_ticks'] ?? 0),
            durationTicks: $duration,
            playbackStatus: self::asString($row['playback_status'] ?? ''),
            progressPercent: self::asFloat($row['progress_percent'] ?? 0),
            lastWatchedAt: self::asString($row['last_watched_at'] ?? ''),
            createdAt: self::nullableString($row['created_at'] ?? null),
            completedAt: self::nullableString($row['completed_at'] ?? null),
            mediaName: $mediaName,
            mediaType: $mediaType,
            metadata: $metadata,
        );
    }

    /**
     * Convert this DTO into the canonical history-entry array consumed
     * by API callers and tests.
     *
     * @return array{
     *     id: string,
     *     profile_id: string,
     *     media_item_id: string,
     *     position_ticks: int,
     *     duration_ticks: int|null,
     *     playback_status: string,
     *     progress_percent: float,
     *     last_watched_at: string,
     *     created_at: string|null,
     *     completed_at: string|null,
     *     media_name?: string,
     *     media_type?: string,
     *     metadata?: array<string, mixed>,
     *     poster_url?: string,
     *     thumbnail_url?: string,
     * }
     */
    public function toArray(): array
    {
        $entry = [
            'id' => $this->id,
            'profile_id' => $this->profileId,
            'media_item_id' => $this->mediaItemId,
            'position_ticks' => $this->positionTicks,
            'duration_ticks' => $this->durationTicks,
            'playback_status' => $this->playbackStatus,
            'progress_percent' => $this->progressPercent,
            'last_watched_at' => $this->lastWatchedAt,
            'created_at' => $this->createdAt,
            'completed_at' => $this->completedAt,
        ];

        if ($this->mediaName !== null) {
            $entry['media_name'] = $this->mediaName;
            $entry['media_type'] = $this->mediaType ?? '';

            if ($this->metadata !== []) {
                $entry['metadata'] = $this->metadata;

                if (isset($this->metadata['poster_url']) && is_string($this->metadata['poster_url'])) {
                    $entry['poster_url'] = $this->metadata['poster_url'];
                }
                if (isset($this->metadata['thumbnail_url']) && is_string($this->metadata['thumbnail_url'])) {
                    $entry['thumbnail_url'] = $this->metadata['thumbnail_url'];
                }
            }
        }

        return $entry;
    }

    /**
     * Decode the metadata JSON column into a string-keyed array.
     *
     * @return array<string, mixed>
     */
    private static function decodeMetadata(mixed $value): array
    {
        if (is_array($value)) {
            return RowMap::fromMixed($value);
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return RowMap::fromMixed($decoded);
    }

    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return self::asString($value);
    }

    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function asFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
