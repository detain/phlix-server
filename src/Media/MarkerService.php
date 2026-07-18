<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license MIT
 */

declare(strict_types=1);

namespace Phlix\Media;

final class MarkerService
{
    /**
     * @param \Workerman\MySQL\Connection $db Database connection
     */
    public function __construct(
        private \Workerman\MySQL\Connection $db,
    ) {
    }

    /**
     * Find all markers for a media item.
     *
     * @param string $mediaItemId The media item ID
     * @return Marker[] Array of markers ordered by start time
     */
    public function findByMediaItem(string $mediaItemId): array
    {
        /** @var array<array<string, mixed>> $result */
        $result = $this->db->query(
            'SELECT * FROM media_markers WHERE media_item_id = ? ORDER BY start_time_ms ASC',
            [$mediaItemId]
        );
        return array_map(
            fn(array $row): Marker => Marker::fromDbRow($row),
            $result
        );
    }

    /**
     * Insert or update a marker.
     *
     * @param string $mediaItemId The media item ID
     * @param MarkerType $type The marker type
     * @param int $startMs Start time in milliseconds
     * @param int $endMs End time in milliseconds
     * @param string $label Label/title for the marker
     * @param string|null $userId Owning user ID (null for legacy system markers)
     * @param string|null $thumbnailPath Optional thumbnail file path
     * @return Marker The created/updated marker
     */
    public function upsert(
        string $mediaItemId,
        MarkerType $type,
        int $startMs,
        int $endMs,
        string $label,
        ?string $userId = null,
        ?string $thumbnailPath = null
    ): Marker {
        $this->db->query(
            'INSERT INTO media_markers
             (media_item_id, marker_type, start_time_ms, end_time_ms, label, user_id, thumbnail_path)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               marker_type = VALUES(marker_type),
               start_time_ms = VALUES(start_time_ms),
               end_time_ms = VALUES(end_time_ms),
               label = VALUES(label),
               user_id = VALUES(user_id),
               thumbnail_path = VALUES(thumbnail_path)',
            [$mediaItemId, $type->value, $startMs, $endMs, $label, $userId, $thumbnailPath]
        );
        $id = (int) $this->db->lastInsertId();
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query('SELECT * FROM media_markers WHERE id = ?', [$id]);
        if (!is_array($rows) || count($rows) === 0) {
            throw new \RuntimeException('Failed to retrieve inserted marker');
        }
        return Marker::fromDbRow($rows[0]);
    }

    /**
     * Delete a marker by ID.
     *
     * @param int $id The marker ID to delete
     */
    public function delete(int $id): void
    {
        $this->db->query('DELETE FROM media_markers WHERE id = ?', [$id]);
    }
}
