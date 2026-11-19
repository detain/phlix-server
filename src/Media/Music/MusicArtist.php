<?php

/**
 * Phlix media server component: Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Music;

use Phlix\Common\Util\RowMap;

/**
 * MusicArtist represents a music artist in the library.
 *
 * @property int         $id                   Unique identifier
 * @property string|null $mediaItemId          FK to media_items.id (CHAR(36) UUID) for artwork/metadata
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
        public ?string $mediaItemId = null,
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
        $id = RowMap::intFromNumeric($row, 'id', 0);
        $name = RowMap::str($row, 'name', '');
        $mediaItemId = RowMap::nullableNonEmptyStr($row, 'media_item_id');
        $sortName = RowMap::nullableStr($row, 'sort_name');
        $biography = RowMap::nullableStr($row, 'biography');
        $imageUrl = RowMap::nullableStr($row, 'image_url');
        $createdAt = RowMap::nullableDateTime($row, 'created_at');
        $updatedAt = RowMap::nullableDateTime($row, 'updated_at');

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
     * LAW POINTER (E-09 cohort 2, 2026-11-21). The media_item_id coercion
     * (S121 law + S127 six-site warning) and the datetime parsing (S143
     * anti-fabrication law) lived here as byte-identical private helpers
     * across all three music DTOs. They are now extracted to
     * {@see \Phlix\Common\Util\RowMap::nullableNonEmptyStr()} and
     * {@see \Phlix\Common\Util\RowMap::nullableDateTime()} — read the full
     * law there, including the whitespace residual (S121 r8 INFO-2) and the
     * warning that `MusicLibraryScanner` still carries THREE INLINE COPIES
     * of the media_item_id predicate (PhpToken-pinned by
     * MusicScannerInlineMediaItemIdCoercionTest, structurally invisible to
     * the DTO guards — read the pair, not either alone). The DTO-side guards
     * (MusicDtoMediaItemIdTest, MusicDtoParseDateTimeTest,
     * MusicDtoFromRowCoercionTest) exercise behavior THROUGH fromRow() and
     * keep holding after the extraction; their own text commands "change all
     * three together … or extract" — this is the extraction.
     */
}
