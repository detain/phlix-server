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
 * MusicAlbum represents a music album in the library.
 *
 * @property int         $id                   Unique identifier
 * @property string|null $mediaItemId          FK to media_items.id (CHAR(36) UUID) for artwork/metadata
 * @property int         $artistId             FK to music_artists.id
 * @property string      $title                Album title
 * @property string|null $sortTitle            Title for alphabetical sorting
 * @property int|null    $year                 Release year
 * @property int         $totalTracks          Number of tracks on album
 * @property int         $totalDiscs           Number of discs in album
 * @property string|null $albumArtUrl          URL to album cover art
 * @property \DateTime   $createdAt            Creation timestamp
 * @property \DateTime   $updatedAt            Last update timestamp
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Data model for music albums
 */
final readonly class MusicAlbum
{
    public function __construct(
        public int $id,
        public int $artistId,
        public string $title,
        public ?string $mediaItemId = null,
        public ?string $sortTitle = null,
        public ?int $year = null,
        public int $totalTracks = 0,
        public int $totalDiscs = 1,
        public ?string $albumArtUrl = null,
        public ?\DateTime $createdAt = null,
        public ?\DateTime $updatedAt = null,
    ) {
    }

    /**
     * Gets all tracks on this album.
     *
     * @return MusicTrack[] Tracks on this album
     */
    public function getTracks(): array
    {
        return [];
    }

    /**
     * Creates a MusicAlbum from a database row.
     *
     * @param array<string, mixed> $row Database row
     * @return self New instance
     */
    public static function fromRow(array $row): self
    {
        $id = RowMap::intFromNumeric($row, 'id', 0);
        $artistId = RowMap::intFromNumeric($row, 'artist_id', 0);
        $title = RowMap::str($row, 'title', '');
        $mediaItemId = RowMap::nullableNonEmptyStr($row, 'media_item_id');
        $sortTitle = RowMap::nullableStr($row, 'sort_title');
        $year = RowMap::nullableIntFromNumeric($row, 'year');
        $totalTracks = RowMap::intFromNumeric($row, 'total_tracks', 0);
        $totalDiscs = RowMap::intFromNumeric($row, 'total_discs', 1);
        $albumArtUrl = RowMap::nullableStr($row, 'album_art_url');
        $createdAt = RowMap::nullableDateTime($row, 'created_at');
        $updatedAt = RowMap::nullableDateTime($row, 'updated_at');

        return new self(
            id: $id,
            artistId: $artistId,
            title: $title,
            mediaItemId: $mediaItemId,
            sortTitle: $sortTitle,
            year: $year,
            totalTracks: $totalTracks,
            totalDiscs: $totalDiscs,
            albumArtUrl: $albumArtUrl,
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
            'artist_id' => $this->artistId,
            'title' => $this->title,
            'sort_title' => $this->sortTitle,
            'year' => $this->year,
            'total_tracks' => $this->totalTracks,
            'total_discs' => $this->totalDiscs,
            'album_art_url' => $this->albumArtUrl,
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
