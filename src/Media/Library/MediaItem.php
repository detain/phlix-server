<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

/**
 * Simple media item representation for music.
 *
 * @property string $id Item ID
 * @property string $name Item name
 * @property string $type Item type (track)
 * @property string $path File path
 * @property array<string, mixed> $metadata Metadata from tags
 */
final class MediaItem
{
    /**
     * @param array<string, mixed> $metadata Metadata decoded from `metadata_json`.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly string $path,
        public readonly array $metadata = []
    ) {
    }

    /**
     * Creates a MediaItem from a database row.
     *
     * @param array<string, mixed> $row Database row
     * @return self New MediaItem instance
     */
    public static function fromRow(array $row): self
    {
        $metadata = [];
        $rawJson = $row['metadata_json'] ?? null;
        if (is_string($rawJson)) {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($key)) {
                        $metadata[$key] = $value;
                    }
                }
            }
        }

        $id = is_string($row['id'] ?? null) ? $row['id'] : '';
        $name = is_string($row['name'] ?? null) ? $row['name'] : '';
        $type = is_string($row['type'] ?? null) ? $row['type'] : '';
        $path = is_string($row['path'] ?? null) ? $row['path'] : '';

        return new self(
            id: $id,
            name: $name,
            type: $type,
            path: $path,
            metadata: $metadata
        );
    }
}
