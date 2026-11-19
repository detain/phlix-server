<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Common\Util\RowMap;

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

        // E-09 cohort 5a: RowMap fold of the pre-fold ??-guarded strict
        // ternaries (fallbacks transcribed; the metadata_json walker above is
        // kept verbatim); byte-identity pinned by the legacy-oracle vectors in
        // tests/Unit/Media/Metadata/Writer/MetadataWriteWorkerTest.php.
        $id = RowMap::str($row, 'id', '');
        $name = RowMap::str($row, 'name', '');
        $type = RowMap::str($row, 'type', '');
        $path = RowMap::str($row, 'path', '');

        return new self(
            id: $id,
            name: $name,
            type: $type,
            path: $path,
            metadata: $metadata
        );
    }
}
