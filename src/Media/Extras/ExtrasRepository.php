<?php

declare(strict_types=1);

namespace Phlix\Media\Extras;

use Workerman\MySQL\Connection;

/**
 * ExtrasRepository handles data access for media_extras table.
 *
 * @since 0.14.0
 */
class ExtrasRepository
{
    /** @var Connection Database connection */
    private Connection $db;

    /**
     * @param Connection $db Database connection
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Get all extras for a media item.
     *
     * @param string $mediaItemId The media item ID
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByMediaItemId(string $mediaItemId): array
    {
        /** @var array<array<string, mixed>> $result */
        $result = $this->db->query(
            "SELECT * FROM media_extras WHERE media_item_id = ? ORDER BY extra_type, title",
            [$mediaItemId]
        );

        return is_array($result) ? $result : [];
    }

    /**
     * Get trailers only for a media item.
     *
     * @param string $mediaItemId The media item ID
     *
     * @return array<int, array<string, mixed>>
     */
    public function findTrailersByMediaItemId(string $mediaItemId): array
    {
        /** @var array<array<string, mixed>> $result */
        $result = $this->db->query(
            "SELECT * FROM media_extras WHERE media_item_id = ? AND extra_type = 'trailer' ORDER BY title",
            [$mediaItemId]
        );

        return is_array($result) ? $result : [];
    }

    /**
     * Get non-trailer extras for a media item.
     *
     * @param string $mediaItemId The media item ID
     *
     * @return array<int, array<string, mixed>>
     */
    public function findNonTrailerExtrasByMediaItemId(string $mediaItemId): array
    {
        /** @var array<array<string, mixed>> $result */
        $result = $this->db->query(
            "SELECT * FROM media_extras WHERE media_item_id = ? AND extra_type != 'trailer' ORDER BY extra_type, title",
            [$mediaItemId]
        );

        return is_array($result) ? $result : [];
    }

    /**
     * Delete all extras for a media item.
     *
     * @param string $mediaItemId The media item ID
     *
     * @return void
     */
    public function deleteByMediaItemId(string $mediaItemId): void
    {
        $this->db->query("DELETE FROM media_extras WHERE media_item_id = ?", [$mediaItemId]);
    }

    /**
     * Insert a new extra.
     *
     * @param array<string, mixed> $data Extra data
     *
     * @return string The inserted ID
     */
    public function insert(array $data): string
    {
        $id = is_string($data['id'] ?? null) ? $data['id'] : $this->generateUuid();

        $this->db->query(
            "INSERT INTO media_extras (id, media_item_id, title, extra_type, source,
              url, file_path, duration, quality, cached_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $data['media_item_id'],
                $data['title'],
                $data['extra_type'],
                $data['source'],
                $data['url'],
                $data['file_path'] ?? '',
                $data['duration'] ?? 0,
                $data['quality'] ?? 0,
                $data['cached_at'] ?? date('Y-m-d H:i:s'),
            ]
        );

        return $id;
    }

    /**
     * Batch insert extras.
     *
     * @param array<int, array<string, mixed>> $extras Array of extra data
     *
     * @return void
     */
    public function batchInsert(array $extras): void
    {
        foreach ($extras as $extra) {
            $this->insert($extra);
        }
    }

    /**
     * Upsert an extra (insert or update on duplicate title+type).
     *
     * @param array<string, mixed> $data Extra data
     *
     * @return void
     */
    public function upsert(array $data): void
    {
        $id = $data['id'] ?? $this->generateUuid();
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            "INSERT INTO media_extras (id, media_item_id, title, extra_type, source,
              url, file_path, duration, quality, cached_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                source = VALUES(source),
                url = VALUES(url),
                file_path = VALUES(file_path),
                duration = VALUES(duration),
                quality = VALUES(quality),
                cached_at = VALUES(cached_at)",
            [
                $id,
                $data['media_item_id'],
                $data['title'],
                $data['extra_type'],
                $data['source'],
                $data['url'],
                $data['file_path'] ?? '',
                $data['duration'] ?? 0,
                $data['quality'] ?? 0,
                $now,
            ]
        );
    }

    /**
     * Check if cached extras are still valid (within TTL).
     *
     * @param string $mediaItemId The media item ID
     * @param int $ttlSeconds Cache TTL in seconds (default 24 hours)
     *
     * @return bool True if cache is valid
     */
    public function isCacheValid(string $mediaItemId, int $ttlSeconds = 86400): bool
    {
        $result = $this->db->query(
            "SELECT MAX(cached_at) as latest FROM media_extras WHERE media_item_id = ?",
            [$mediaItemId]
        );

        if (!is_array($result) || count($result) === 0) {
            return false;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return false;
        }

        $latest = $firstRow['latest'] ?? null;
        if (!is_string($latest)) {
            return false;
        }

        $cachedAt = strtotime($latest);
        if ($cachedAt === false) {
            return false;
        }

        $now = time();

        return ($now - $cachedAt) < $ttlSeconds;
    }

    /**
     * Generate a UUID for extra records.
     *
     * @return string UUID string
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
