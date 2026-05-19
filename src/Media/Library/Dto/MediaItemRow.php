<?php

declare(strict_types=1);

namespace Phlex\Media\Library\Dto;

/**
 * Typed value object representing a hydrated row from `media_items`.
 *
 * Wraps the result of an `ItemRepository` lookup with the `metadata`
 * column already JSON-decoded into a typed array, so downstream callers
 * can operate on a known shape rather than mixed.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Strongly-typed media-item row from DB hydration
 */
final class MediaItemRow
{
    /**
     * @param string $id Media item UUID
     * @param string $libraryId Owning library UUID
     * @param string $name Display name
     * @param string $type Item type (e.g. 'track', 'movie', 'episode')
     * @param string $path Absolute filesystem path
     * @param array<string, mixed> $metadata Decoded metadata blob
     * @param array<string, mixed> $raw Original row (with decoded metadata applied)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $libraryId,
        public readonly string $name,
        public readonly string $type,
        public readonly string $path,
        public readonly array $metadata,
        public readonly array $raw,
    ) {
    }

    /**
     * Hydrate a MediaItemRow from a raw DB row.
     *
     * @param array<string, mixed> $row Raw DB row from `media_items`.
     */
    public static function fromRow(array $row): self
    {
        $id = is_string($row['id'] ?? null) ? $row['id'] : '';
        $libraryId = is_string($row['library_id'] ?? null) ? $row['library_id'] : '';
        $name = is_string($row['name'] ?? null) ? $row['name'] : '';
        $type = is_string($row['type'] ?? null) ? $row['type'] : '';
        $path = is_string($row['path'] ?? null) ? $row['path'] : '';

        $metadata = self::decodeMetadata($row['metadata'] ?? ($row['metadata_json'] ?? null));
        $row['metadata'] = $metadata;

        return new self($id, $libraryId, $name, $type, $path, $metadata, $row);
    }

    /**
     * Returns the underlying row with the decoded metadata array substituted.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * Decodes the metadata column into a string-keyed array.
     *
     * @param mixed $value JSON string or already-decoded array.
     * @return array<string, mixed>
     */
    private static function decodeMetadata(mixed $value): array
    {
        $decoded = $value;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
        }
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $key => $entry) {
            if (is_string($key)) {
                $result[$key] = $entry;
            }
        }
        return $result;
    }
}
