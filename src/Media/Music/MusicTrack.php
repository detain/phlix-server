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
 * MusicTrack represents a music track in the library.
 *
 * @property int         $id                     Unique identifier
 * @property int         $mediaItemId            FK to media_items.id for stream/metadata
 * @property int         $albumId                FK to music_albums.id
 * @property int         $artistId               FK to music_artists.id (denormalized for queries)
 * @property string      $title                  Track title
 * @property int|null    $trackNumber            Position within album
 * @property int         $discNumber             Disc number within album set
 * @property int         $durationSecs           Duration in seconds
 * @property \DateTime   $createdAt              Creation timestamp
 * @property \DateTime   $updatedAt              Last update timestamp
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Data model for music tracks
 */
final readonly class MusicTrack
{
    public function __construct(
        public int $id,
        public int $mediaItemId,
        public int $albumId,
        public int $artistId,
        public string $title,
        public ?int $trackNumber = null,
        public int $discNumber = 1,
        public int $durationSecs = 0,
        public ?\DateTime $createdAt = null,
        public ?\DateTime $updatedAt = null,
    ) {
    }

    /**
     * Creates a MusicTrack from a database row.
     *
     * @param array<string, mixed> $row Database row
     * @return self New instance
     */
    public static function fromRow(array $row): self
    {
        $id = isset($row['id']) && is_numeric($row['id']) ? (int)$row['id'] : 0;
        $mediaItemId = isset($row['media_item_id']) && is_numeric($row['media_item_id']) ? (int)$row['media_item_id'] :
            0;
        $albumId = isset($row['album_id']) && is_numeric($row['album_id']) ? (int)$row['album_id'] : 0;
        $artistId = isset($row['artist_id']) && is_numeric($row['artist_id']) ? (int)$row['artist_id'] : 0;
        $title = isset($row['title']) && is_string($row['title']) ? $row['title'] : '';
        $trackNumber = isset($row['track_number']) && is_numeric($row['track_number']) ? (int)$row['track_number'] :
            null;
        $discNumber = isset($row['disc_number']) && is_numeric($row['disc_number']) ? (int)$row['disc_number'] : 1;
        $durationSecs = isset($row['duration_secs']) && is_numeric($row['duration_secs']) ?
            (int)$row['duration_secs'] : 0;
        $createdAt = isset($row['created_at']) ? self::parseDateTime($row['created_at']) : null;
        $updatedAt = isset($row['updated_at']) ? self::parseDateTime($row['updated_at']) : null;

        return new self(
            id: $id,
            mediaItemId: $mediaItemId,
            albumId: $albumId,
            artistId: $artistId,
            title: $title,
            trackNumber: $trackNumber,
            discNumber: $discNumber,
            durationSecs: $durationSecs,
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
            'album_id' => $this->albumId,
            'artist_id' => $this->artistId,
            'title' => $this->title,
            'track_number' => $this->trackNumber,
            'disc_number' => $this->discNumber,
            'duration_secs' => $this->durationSecs,
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
