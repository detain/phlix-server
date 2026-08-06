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
     * 🟡 **RESIDUAL, disclosed rather than closed (S121 review r8 INFO-2): whitespace
     * is neither normalised nor pinned.** There is no `trim()` here, so `' '` returns
     * `' '`, `"\0"` returns `"\0"`, and a padded `' <uuid> '` keeps its padding
     * byte-for-byte. Measured: the two mutants that would add one —
     * `is_string($value) && trim($value) !== ''` and `? trim($value) : null` — leave
     * BOTH S121 unit test files green (`MusicDtoFromRowCoercionTest` `OK (31, 51)`,
     * `MusicDtoMediaItemIdTest` `OK (27, 56)`, exit 0 each), so nothing in the suite
     * would notice either edit. This is the ONE input where the "make 'no id'
     * detectable" goal stated above does NOT hold: `' '` is a non-null, non-empty
     * string, so it survives `!== null`, `?? …` and `is_string()` exactly as `''`
     * would.
     *
     * It is disclosed rather than fixed because the driver cannot deliver it. MySQL
     * strips only the TRAILING pad spaces (paragraph above), so an all-space column
     * reads back as `''`; a LEADING space is not stripped, but `CHAR(36)` cannot hold
     * a space plus a full 36-character UUID, so a padded value in this column is
     * already not a mintable `media_items.id` — i.e. the junk this helper deliberately
     * does not validate. That leaves hand-built, cached and JSON rows as the only
     * route in, the same not-from-the-driver route as the pinned int `0`. **Adding
     * `trim()` here would be a behaviour change and is deliberately NOT part of
     * S121** — r8 rated it non-blocking INFO. A data set asserting today's behaviour
     * (`' '` → `' '`) is what would kill both mutants if it is ever worth pinning.
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
     *                     (whitespace-only is NOT "empty" here — it passes through)
     */
    private static function mediaItemIdFromRow(array $row): ?string
    {
        $value = $row['media_item_id'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Parses a `datetime` column value into a `\DateTime`, or `null` when the row
     * carries no usable timestamp.
     *
     * 🔴 **S143 — this helper used to FABRICATE a timestamp.** `new \DateTime('')`
     * does not throw: PHP's parser reads an empty or whitespace-only string as
     * "now", so `created_at => ''` produced **exactly the current second**, and
     * `'0000-00-00 00:00:00'` — MySQL's zero date, which a `NOT NULL DATETIME`
     * column yields under a non-strict SQL mode — produced **`-0001-11-30`**. Both
     * were then indistinguishable from a timestamp the row really held.
     *
     * **A fabricated timestamp is worse than a missing one, and that is the whole
     * reason this returns `null`.** A `null` is visibly absent, so anything that
     * sorts, filters or reports by date can see it is not there. A fabricated
     * "now" looks plausible forever and silently corrupts every one of those. This
     * same helper already refuses to invent a value for the integer case — an int
     * `created_at` is `null`, **not** a unix timestamp — so returning "now" here
     * was violating the codebase's own standard in one branch of one function.
     *
     * **The type guard is `\DateTimeInterface`, not `\DateTime`, and that was a
     * second, independent bug.** `\DateTimeImmutable` does **not** extend
     * `\DateTime`; both implement `\DateTimeInterface`. So a perfectly valid
     * immutable fell past `is_string()` and was dropped to `null` — and modern PHP
     * produces `\DateTimeImmutable` by default. An immutable is now converted with
     * `\DateTime::createFromInterface()`, which preserves the instant, the
     * microseconds and the timezone. A mutable `\DateTime` is still returned **by
     * identity**, and must stay that way — that is separately pinned.
     *
     * **What deliberately did NOT change: the string handed to the parser.**
     * `trim()` decides emptiness only; any non-blank value is parsed byte-for-byte
     * as before, so `' 2019-03-04 '` keeps parsing exactly as it always did.
     * Widening what parses is not this fix's business.
     *
     * ⚠ **Do not read a green "already a DateTime" identity test as coverage of
     * this path.** Before S143 the empty-input contract was pinned by NOTHING:
     * adding `&& $value !== ''` to the old string guard left the whole suite green
     * (measured on `f701b40c`, `Tests: 8978, Failures: 2`), while mutating the
     * `instanceof` reddened three. The guard's EXISTENCE was pinned; its BREADTH
     * was not. Both are now pinned by
     * {@see \Phlix\Tests\Unit\Media\Music\MusicDtoParseDateTimeTest}.
     *
     * ⚠ This helper is byte-identical in `MusicArtist`, `MusicAlbum` and
     * `MusicTrack`. Change all three together and re-check the md5s, or extract it.
     *
     * @param mixed $value A `datetime` column value, a `\DateTimeInterface`, or
     *                     anything else — everything unusable becomes `null`.
     * @return \DateTime|null `null` for a non-string, non-`\DateTimeInterface`
     *                        value; for an empty or whitespace-only string; for
     *                        MySQL's zero date (`0000-00-00…`); and for a string
     *                        the parser rejects.
     */
    private static function parseDateTime(mixed $value): ?\DateTime
    {
        if ($value instanceof \DateTime) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTime::createFromInterface($value);
        }
        if (!is_string($value)) {
            return null;
        }

        // `''` and `'   '` BOTH parse as "now", and `'0000-00-00…'` parses as year
        // -1. None of the three is a timestamp the row carried, so none may be
        // handed back as one.
        $trimmed = trim($value);
        if ($trimmed === '' || str_starts_with($trimmed, '0000-00-00')) {
            return null;
        }

        try {
            return new \DateTime($value);
        } catch (\Exception) {
            return null;
        }
    }
}
