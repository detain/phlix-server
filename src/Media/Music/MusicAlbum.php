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
        $id = isset($row['id']) && is_numeric($row['id']) ? (int)$row['id'] : 0;
        $artistId = isset($row['artist_id']) && is_numeric($row['artist_id']) ? (int)$row['artist_id'] : 0;
        $title = isset($row['title']) && is_string($row['title']) ? $row['title'] : '';
        $mediaItemId = self::mediaItemIdFromRow($row);
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
     * Coerces `music_albums.media_item_id` — a `CHAR(36)` UUID — into `?string`.
     *
     * ⚠ This replaces `is_numeric($row['media_item_id']) ? (int)… : null`, which
     * is **false for every UUID**, so the field was silently ALWAYS `null` (S121).
     * Confirmed against real MySQL: the column is `char(36)` (migration 070) and
     * `workerman/mysql` hands it back as a PHP `string`, while the sibling
     * `id` / `artist_id` columns really are `int unsigned` — which is why they keep
     * their `is_numeric()` guards and this one must not.
     *
     * `null` is a LEGITIMATE value here, not merely a parse failure: the column is
     * NULLable and the music scanner writes NULL when its `createMediaItem()` mint
     * fails, backfilling the row on a later pass. So an absent key, a SQL NULL and
     * an empty string all collapse to `null` — never to `''`.
     *
     * **Why `null` and not `''`, stated precisely.** It is NOT that `''` would be
     * caught by anything: at PHPStan level 9 a `string|null` flows silently through
     * `"…{$id}…"`, `'…' . $id`, and `sprintf('%s', $id)` alike (only a strict
     * `string` parameter fires), and `null` interpolates to exactly the same empty
     * segment `''` would. The real reason is semantic: `''` is a non-null string, so
     * it SURVIVES the `!== null` / `?? …` / `is_string()` tests callers actually
     * write and then propagates as though it were an id, whereas `null` is filtered
     * by those same tests. Choosing `null` makes "no id" detectable; `''` would make
     * it undetectable.
     *
     * `''` is not the only junk a `CHAR(36)` column can hold — it can hold any
     * 1–36-character string, e.g. `'0'` or a truncated UUID, and this helper accepts
     * all of them (format validation is deliberately NOT the DTO's job; the FK to
     * `media_items` is). What IS measured: MySQL strips a `CHAR` value's trailing
     * pad spaces on read, so an all-space value arrives as `''` and is rejected here.
     *
     * ⚠ **The sweep is FIVE sites, not three — one grep is a false all-clear.**
     * `grep -rn mediaItemIdFromRow src/` finds only these three DTO helpers. The
     * same predicate is ALSO inlined twice in `MusicLibraryScanner`, inside
     * `upsertArtist()` and `upsertAlbum()`; find those with
     * `grep -rn "media_item_id'\] !== ''" src/`, which matches exactly those two and
     * none of these three. (No line numbers on purpose — that file is being
     * rewritten.) A reader who runs only the first grep, counts three and calls the
     * sweep complete has repeated the mistake that CREATED S121: the defect was
     * written down for `MusicTrack` alone and both siblings were missed. The
     * mechanical backstop is
     * {@see \Phlix\Tests\Unit\Media\Music\MusicDtoMediaItemIdTest}, which globs
     * `src/Media/Music/` and reflects EVERY class declaring a `mediaItemId`
     * property, so a fourth DTO cannot land with the old coercion unnoticed.
     *
     * @param array<string, mixed> $row Database row
     * @return string|null The UUID, or null when the column is absent/NULL/empty
     */
    private static function mediaItemIdFromRow(array $row): ?string
    {
        $value = $row['media_item_id'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
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
