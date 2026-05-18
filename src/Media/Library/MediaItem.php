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
        if (isset($row['metadata_json'])) {
            $decoded = json_decode($row['metadata_json'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        return new self(
            id: $row['id'],
            name: $row['name'],
            type: $row['type'],
            path: $row['path'],
            metadata: $metadata
        );
    }
}
