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
 * MusicAlbum represents a music album in the library.
 *
 * @property int         $id                   Unique identifier
 * @property int|null   $mediaItemId          FK to media_items.id for artwork/metadata
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
        public ?int $mediaItemId = null,
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
        $id = isset($row['id']) && is_numeric($row['id']) ? (int)$row['id'] : 0;
        $artistId = isset($row['artist_id']) && is_numeric($row['artist_id']) ? (int)$row['artist_id'] : 0;
        $title = isset($row['title']) && is_string($row['title']) ? $row['title'] : '';
        $mediaItemId = isset($row['media_item_id']) && is_numeric($row['media_item_id']) ? (int)$row['media_item_id'] : null;
        $sortTitle = isset($row['sort_title']) && is_string($row['sort_title']) ? $row['sort_title'] : null;
        $year = isset($row['year']) && is_numeric($row['year']) ? (int)$row['year'] : null;
        $totalTracks = isset($row['total_tracks']) && is_numeric($row['total_tracks']) ? (int)$row['total_tracks'] : 0;
        $totalDiscs = isset($row['total_discs']) && is_numeric($row['total_discs']) ? (int)$row['total_discs'] : 1;
        $albumArtUrl = isset($row['album_art_url']) && is_string($row['album_art_url']) ? $row['album_art_url'] : null;
        $createdAt = isset($row['created_at']) ? self::parseDateTime($row['created_at']) : null;
        $updatedAt = isset($row['updated_at']) ? self::parseDateTime($row['updated_at']) : null;

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
