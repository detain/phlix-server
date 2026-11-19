<?php

/**
 * Phlix media server component: Dto.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Auth\Dto;

use Phlix\Common\Util\RowMap;

/**
 * Typed value object representing a hydrated row from `watch_history`.
 *
 * Optionally enriched with JOINed columns from `media_items`
 * (`media_name`, `media_type`, `metadata_json`) so callers can render
 * "continue watching" / history lists without re-querying.
 *
 * @author Phlix Development Team
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
        // E-09: this DTO's private asString/nullableString/asInt/asFloat
        // helpers were further hand-rolled members of the estate's
        // coercion families and now live in the RowMap convention table
        // WITHOUT semantic change. Their legacy contracts are the
        // SCALAR-CAST family here: 55 hydrates to "55" (where the strict
        // Webhook records emit 0), numeric strings truncate through
        // (int)/(float), and a non-scalar nullable timestamp becomes ''
        // rather than null — the drift E-09 documents, preserved, not
        // harmonized. Byte-identity pin:
        // tests/Unit/Auth/Dto/WatchHistoryRowByteIdentityTest.php.
        // The metadata_json decode keeps its array_key_exists() presence
        // test verbatim: key presence (not just nullness) selects whether
        // the metadata block exists at all, which scalar accessors do not
        // model (out of pilot scope).
        $mediaName = RowMap::nullableStr($row, 'media_name');
        $mediaType = RowMap::nullableStr($row, 'media_type');

        $metadata = [];
        if (array_key_exists('metadata_json', $row)) {
            $metadata = self::decodeMetadata($row['metadata_json']);
        }

        return new self(
            id: RowMap::stringFromScalar($row, 'id', ''),
            profileId: RowMap::stringFromScalar($row, 'profile_id', ''),
            mediaItemId: RowMap::stringFromScalar($row, 'media_item_id', ''),
            positionTicks: RowMap::intFromNumeric($row, 'position_ticks', 0),
            durationTicks: RowMap::nullableIntFromNumeric($row, 'duration_ticks'),
            playbackStatus: RowMap::stringFromScalar($row, 'playback_status', ''),
            progressPercent: RowMap::floatFromNumeric($row, 'progress_percent', 0.0),
            lastWatchedAt: RowMap::stringFromScalar($row, 'last_watched_at', ''),
            createdAt: RowMap::nullableStringFromScalar($row, 'created_at'),
            completedAt: RowMap::nullableStringFromScalar($row, 'completed_at'),
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
}
