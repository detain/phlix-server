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
        $id = isset($row['id']) && is_numeric($row['id']) ? (int)$row['id'] : 0;
        $mediaItemId = self::mediaItemIdFromRow($row);
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
     * Coerces `music_tracks.media_item_id` — a `CHAR(36)` UUID — into `?string`.
     *
     * ⚠ This replaces `is_numeric($row['media_item_id']) ? (int)… : 0`, which is
     * **false for every UUID**, so the field was silently ALWAYS `0` — the defect
     * `MusicLibraryService`'s class docblock has described since S99 (S121).
     * Confirmed against real MySQL: the column is `char(36)` (migration 070) and
     * `workerman/mysql` hands it back as a PHP `string`, while the sibling
     * `id` / `album_id` / `artist_id` columns really are `int unsigned` — which is
     * why they keep their `is_numeric()` guards and this one must not.
     *
     * `music_tracks.media_item_id` is `NOT NULL`, so `null` here does not mean "no
     * artwork yet" (as it legitimately does on {@see MusicArtist} /
     * {@see MusicAlbum}, whose columns are NULLable): it means the row handed to
     * `fromRow()` did not carry the column at all.
     *
     * **Why `null` and not `''`, stated precisely.** It is NOT that `''` would be
     * caught by anything, and in particular it does NOT "build a broken URL that
     * `null` would not": at PHPStan level 9 a `string|null` flows silently through
     * `"/media/{$id}/stream"`, `'/media/' . $id . '/stream'` and
     * `sprintf('/media/%s/stream', $id)` alike — only a strict `string` parameter
     * fires — and `null` interpolates to the byte-identical `/media//stream`. The
     * real reason is semantic: `''` is a non-null string, so it SURVIVES the
     * `!== null` / `?? …` / `is_string()` tests callers actually write and then
     * propagates as though it were a playable id, whereas `null` is filtered by
     * those same tests. Choosing `null` makes "no id" detectable; `''` would make it
     * undetectable. It also preserves the improvement over the old `0` fallback,
     * which was likewise a plausible-looking id.
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
     * 🔴 **But that backstop covers 3 of the 5 sites, NOT all five.** It can only see
     * classes that declare a `mediaItemId` **property**; the scanner's two copies are
     * inline **local variables**, which is precisely the shape that produced two of
     * the five sites — so **those two are pinned by NO test at all** and this docblock
     * plus the grep recipe above are their only protection. Do not read "mechanical
     * backstop" as covering them. Closing that gap needs a source-text or AST guard
     * written against the POST-S96 scanner (S96 rewrites that file by ~448 lines, so
     * writing one now would guarantee rework); it is filed as step **S127**.
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
