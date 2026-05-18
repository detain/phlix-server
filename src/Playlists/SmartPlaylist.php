<?php

declare(strict_types=1);

namespace Phlex\Playlists;

/**
 * Readonly entity representing a smart playlist.
 *
 * A smart playlist auto-populates based on JSON DSL rules evaluated
 * against the media library at scan time and on folder-watch events.
 *
 * @since 0.14.0
 */
final class SmartPlaylist
{
    /**
     * @param string $id UUID identifier
     * @param string $name Display name
     * @param string $libraryId Associated library UUID
     * @param string $rulesJson JSON DSL rules
     * @param int $limit Maximum items (0 = unlimited)
     * @param string $sortBy Sort field ('addedAt', 'random', etc.)
     * @param bool $sortDesc Sort descending
     * @param \DateTimeImmutable $createdAt Creation timestamp
     * @param \DateTimeImmutable $updatedAt Last update timestamp
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $libraryId,
        public readonly string $rulesJson,
        public readonly int $limit = 0,
        public readonly string $sortBy = 'addedAt',
        public readonly bool $sortDesc = true,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        public readonly \DateTimeImmutable $updatedAt = new \DateTimeImmutable(),
    ) {
    }

    /**
     * Get the decoded rules JSON as an array.
     *
     * @return array<string, mixed> Decoded JSON DSL
     *
     * @since 0.14.0
     */
    public function getRules(): array
    {
        $decoded = json_decode($this->rulesJson, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Create a SmartPlaylist from a database row.
     *
     * @param array<string, mixed> $row Database row
     * @return self New instance
     *
     * @since 0.14.0
     */
    public static function fromRow(array $row): self
    {
        $id = is_string($row['id'] ?? null) ? $row['id'] : '';
        $name = is_string($row['name'] ?? null) ? $row['name'] : '';
        $libraryId = is_string($row['library_id'] ?? null) ? $row['library_id'] : '';
        $rulesJson = is_string($row['rules_json'] ?? null) ? $row['rules_json'] : '{}';
        $limit = is_int($row['limit'] ?? null) ? $row['limit'] : (is_numeric($row['limit'] ?? null) ? (int)$row['limit'] : 0);
        $sortBy = is_string($row['sort_by'] ?? null) ? $row['sort_by'] : 'addedAt';
        $sortDesc = (bool)($row['sort_desc'] ?? true);
        $createdAt = is_string($row['created_at'] ?? null) ? $row['created_at'] : 'now';
        $updatedAt = is_string($row['updated_at'] ?? null) ? $row['updated_at'] : 'now';

        return new self(
            id: $id,
            name: $name,
            libraryId: $libraryId,
            rulesJson: $rulesJson,
            limit: $limit,
            sortBy: $sortBy,
            sortDesc: $sortDesc,
            createdAt: new \DateTimeImmutable($createdAt),
            updatedAt: new \DateTimeImmutable($updatedAt),
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed> Array representation
     *
     * @since 0.14.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'library_id' => $this->libraryId,
            'rules_json' => $this->rulesJson,
            'rules' => $this->getRules(),
            'limit' => $this->limit,
            'sort_by' => $this->sortBy,
            'sort_desc' => $this->sortDesc,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
