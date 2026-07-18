<?php

/**
 * Phlix media server component: Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Music;

/**
 * MusicArtist represents a music artist in the library.
 *
 * @property int         $id                   Unique identifier
 * @property int|null    $mediaItemId          FK to media_items.id for artwork/metadata
 * @property string      $name                 Artist display name
 * @property string|null $sortName             Name for alphabetical sorting
 * @property string|null $biography            Artist biography
 * @property string|null $imageUrl             URL to artist image
 * @property \DateTime   $createdAt            Creation timestamp
 * @property \DateTime   $updatedAt            Last update timestamp
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Data model for music artists
 */
final readonly class MusicArtist
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $mediaItemId = null,
        public ?string $sortName = null,
        public ?string $biography = null,
        public ?string $imageUrl = null,
        public ?\DateTime $createdAt = null,
        public ?\DateTime $updatedAt = null,
    ) {
    }

    /**
     * Gets all albums by this artist.
     *
     * @return MusicAlbum[] Albums by this artist
     */
    public function getAlbums(): array
    {
        return [];
    }

    /**
     * Creates a MusicArtist from a database row.
     *
     * @param array<string, mixed> $row Database row
     * @return self New instance
     */
    public static function fromRow(array $row): self
    {
        $id = isset($row['id']) && is_numeric($row['id']) ? (int)$row['id'] : 0;
        $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : '';
        $mediaItemId = isset($row['media_item_id']) && is_numeric($row['media_item_id']) ? (int)$row['media_item_id'] :
            null;
        $sortName = isset($row['sort_name']) && is_string($row['sort_name']) ? $row['sort_name'] : null;
        $biography = isset($row['biography']) && is_string($row['biography']) ? $row['biography'] : null;
        $imageUrl = isset($row['image_url']) && is_string($row['image_url']) ? $row['image_url'] : null;
        $createdAt = isset($row['created_at']) ? self::parseDateTime($row['created_at']) : null;
        $updatedAt = isset($row['updated_at']) ? self::parseDateTime($row['updated_at']) : null;

        return new self(
            id: $id,
            name: $name,
            mediaItemId: $mediaItemId,
            sortName: $sortName,
            biography: $biography,
            imageUrl: $imageUrl,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    /**
     * Converts to array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'media_item_id' => $this->mediaItemId,
            'name' => $this->name,
            'sort_name' => $this->sortName,
            'biography' => $this->biography,
            'image_url' => $this->imageUrl,
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Parses a datetime string into a DateTime object.
     *
     * @param mixed $value DateTime string or object
     */
    private static function parseDateTime(mixed $value): ?\DateTime
    {
        if ($value instanceof \DateTime) {
            return $value;
        }
        if (is_string($value)) {
            try {
                return new \DateTime($value);
            } catch (\Exception) {
                return null;
            }
        }
        return null;
    }
}
