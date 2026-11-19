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
 * MusicTrack represents a music track in the library.
 *
 * @property int         $id                     Unique identifier
 * @property string|null $mediaItemId            FK to media_items.id (CHAR(36) UUID) for stream/metadata
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
        public ?string $mediaItemId,
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
        $id = RowMap::intFromNumeric($row, 'id', 0);
        $mediaItemId = RowMap::nullableNonEmptyStr($row, 'media_item_id');
        $albumId = RowMap::intFromNumeric($row, 'album_id', 0);
        $artistId = RowMap::intFromNumeric($row, 'artist_id', 0);
        $title = RowMap::str($row, 'title', '');
        $trackNumber = RowMap::nullableIntFromNumeric($row, 'track_number');
        $discNumber = RowMap::intFromNumeric($row, 'disc_number', 1);
        $durationSecs = RowMap::intFromNumeric($row, 'duration_secs', 0);
        $createdAt = RowMap::nullableDateTime($row, 'created_at');
        $updatedAt = RowMap::nullableDateTime($row, 'updated_at');

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
     * LAW POINTER (E-09 cohort 2, 2026-11-21). The media_item_id coercion
     * (S121 law + S127 six-site warning; S99 lineage for this DTO) and the
     * datetime parsing (S143 anti-fabrication law) lived here as
     * byte-identical private helpers across all three music DTOs. They are
     * now extracted to
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
