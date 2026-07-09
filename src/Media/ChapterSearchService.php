<?php

/**
 * Phlix media server component: Media.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItemShaper;
use Workerman\MySQL\Connection;

/**
 * Service for searching media items by marker content (P3B-S8).
 *
 * Enables "similar content" recommendations based on marker positioning:
 * - Finds all media items with a specific marker type (intro/outro/credits/ad)
 * - Ranks by marker duration (shorter intros = better candidates)
 * - Excludes items the user has already watched
 *
 * @since 0.14.0
 */
final class ChapterSearchService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ItemRepository $itemRepository,
    ) {
    }

    /**
     * Search for media items with markers near a specific playhead position.
     *
     * Finds media items that have a marker of the specified type within
     * $aroundSeconds of the given position. Results are ranked by marker
     * duration (shorter = better for intros), excluding already-watched items.
     *
     * @param MarkerType $type       The marker type to search for
     * @param int        $positionMs Current playhead position in milliseconds
     * @param int        $aroundSec  Search window in seconds (markers within this range)
     * @param string     $userId     User ID to exclude watched items for
     * @param int        $limit      Maximum results to return
     *
     * @return array<int, array<string, mixed>> Shaped media items with marker info
     *
     * @since 0.14.0
     */
    public function searchByMarkerProximity(
        MarkerType $type,
        int $positionMs,
        int $aroundSec,
        string $userId,
        int $limit = 20,
    ): array {
        $aroundMs = $aroundSec * 1000;
        $minPosition = $positionMs - $aroundMs;
        $maxPosition = $positionMs + $aroundMs;

        // Query: find items with markers of this type within the position window,
        // ranked by marker duration (shorter = better for intros)
        $sql = <<<'SQL'
            SELECT
                mm.media_item_id,
                mm.start_time_ms,
                mm.end_time_ms,
                mm.label,
                (mm.end_time_ms - mm.start_time_ms) AS duration_ms,
                mi.name,
                mi.type,
                mi.poster_url,
                mi.backdrop_url
            FROM media_markers mm
            INNER JOIN media_items mi ON mi.id = mm.media_item_id
            WHERE mm.marker_type = ?
              AND mm.start_time_ms >= ?
              AND mm.start_time_ms <= ?
              AND mi.id NOT IN (
                  SELECT item_id FROM watch_history WHERE profile_id = ?
              )
            ORDER BY duration_ms ASC, mm.start_time_ms ASC
            LIMIT ?
        SQL;

        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query($sql, [
            $type->value,
            $minPosition,
            $maxPosition,
            $userId,
            $limit,
        ]);

        if ($rows === []) {
            return [];
        }

        // Hydrate full media items and shape them
        /** @var list<string> $itemIds */
        $itemIds = array_values(array_column($rows, 'media_item_id'));
        $items = $this->itemRepository->findByIds($itemIds);

        // Merge marker info into each item
        $markerMap = [];
        foreach ($rows as $row) {
            $id = is_string($row['media_item_id'] ?? null) ? $row['media_item_id'] : '';
            $markerMap[$id] = [
                'start_time_ms' => $row['start_time_ms'],
                'end_time_ms' => $row['end_time_ms'],
                'label' => $row['label'] ?? '',
                'duration_ms' => $row['duration_ms'],
            ];
        }

        return array_map(
            static fn(array $item): array => MediaItemShaper::shape($item),
            $items
        );
    }

    /**
     * Get markers of a specific type for a media item.
     *
     * @param string     $mediaItemId The media item ID
     * @param MarkerType $type        The marker type to filter by
     *
     * @return Marker[] Array of markers of the specified type
     *
     * @since 0.14.0
     */
    public function getMarkersByType(string $mediaItemId, MarkerType $type): array
    {
        /** @var array<array<string, mixed>> $result */
        $result = $this->db->query(
            'SELECT * FROM media_markers
             WHERE media_item_id = ? AND marker_type = ?
             ORDER BY start_time_ms ASC',
            [$mediaItemId, $type->value]
        );

        return array_map(
            fn(array $row): Marker => Marker::fromDbRow($row),
            $result
        );
    }

    /**
     * Find all media items with a specific marker type.
     *
     * Useful for discovery - e.g., find all items with intros for batch skipping.
     *
     * @param MarkerType $type        The marker type to search for
     * @param string     $userId      User ID to exclude watched items for
     * @param int        $limit       Maximum results
     * @param int        $offset      Pagination offset
     *
     * @return array<int, array<string, mixed>> Shaped media items with marker info
     *
     * @since 0.14.0
     */
    public function findAllByMarkerType(
        MarkerType $type,
        string $userId,
        int $limit = 50,
        int $offset = 0,
    ): array {
        // Exclude watched items, rank by duration (shorter intros = better)
        $sql = <<<'SQL'
            SELECT
                mm.media_item_id,
                mm.start_time_ms,
                mm.end_time_ms,
                mm.label,
                (mm.end_time_ms - mm.start_time_ms) AS duration_ms,
                mi.name,
                mi.type,
                mi.poster_url,
                mi.backdrop_url
            FROM media_markers mm
            INNER JOIN media_items mi ON mi.id = mm.media_item_id
            WHERE mm.marker_type = ?
              AND mi.id NOT IN (
                  SELECT item_id FROM watch_history WHERE profile_id = ?
              )
            ORDER BY duration_ms ASC
            LIMIT ? OFFSET ?
        SQL;

        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query($sql, [
            $type->value,
            $userId,
            $limit,
            $offset,
        ]);

        if ($rows === []) {
            return [];
        }

        /** @var list<string> $itemIds */
        $itemIds = array_values(array_column($rows, 'media_item_id'));
        $items = $this->itemRepository->findByIds($itemIds);

        return array_map(
            static fn(array $item): array => MediaItemShaper::shape($item),
            $items
        );
    }
}
