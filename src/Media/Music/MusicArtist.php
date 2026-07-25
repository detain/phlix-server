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
        $id = isset($row['id']) && is_numeric($row['id']) ? (int)$row['id'] : 0;
        $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : '';
        $mediaItemId = self::mediaItemIdFromRow($row);
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
     * Coerces `music_artists.media_item_id` — a `CHAR(36)` UUID — into `?string`.
     *
     * ⚠ This replaces `is_numeric($row['media_item_id']) ? (int)… : null`, which
     * is **false for every UUID**, so the field was silently ALWAYS `null` (S121).
     * Confirmed against real MySQL: the column is `char(36)` (migration 070) and
     * `workerman/mysql` hands it back as a PHP `string`, while the sibling
     * `id` / `artist_id` / `album_id` columns really are `int unsigned` — which is
     * why they keep their `is_numeric()` guards and this one must not.
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
