<?php

/**
 * Phlix media server component: Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Music;

use Phlix\Common\Http\PageLimit;
use Phlix\Media\Library\ScanResult;
use Workerman\MySQL\Connection;

/**
 * MusicLibraryService provides access to the Artist→Album→Track music hierarchy.
 *
 * This service manages the music_artists, music_albums, and music_tracks tables
 * and provides methods for querying artists, albums, tracks, and performing scans.
 *
 * **This is the ONE read path for the `/api/v1/music/*` API** (S99). The music
 * scanner writes every tag it harvests into these normalized tables and stamps
 * only `{"name","sub_type"}` into `media_items.metadata_json`, so any reader that
 * goes looking for `metadata_json.$.artist` / `.album` / `.year` finds nothing and
 * silently degrades to `'Unknown Artist'` / `'Unknown Album'` / `null`. That is
 * exactly what {@see \Phlix\Media\Library\MusicLibraryManager}'s `getArtists()` /
 * `getAlbums()` / `getTracks()` did before S99 repointed
 * {@see \Phlix\Server\Http\Controllers\MusicController} at this service.
 *
 * **Seven methods here are DEAD but retained on purpose** (S99 review r1, LOW-8):
 * `getArtist(int)`, `getAlbum(int)`, `getTrack(int)`, `getAlbumTracks(int)`,
 * `getArtistWithAlbums(int)`, `searchArtists()` and `getRecentlyAdded()` have zero
 * `src/` callers — S99 deleted the `WebPortalRouter` music GET handlers that used
 * them (they took an int PK where every client sends a name). They are kept rather
 * than deleted because they are the only references left to the `MusicAlbum` /
 * `MusicTrack` / `MusicAlbumWithTracks` DTOs, so removing them turns a one-file
 * change into a THREE-class deletion that also has to decide `phlix-contracts`'
 * `src/Music.ts`, which still mirrors those DTO shapes — i.e. a cross-repo sweep,
 * not a fix round.
 * ⚠ `MusicArtist` and `MusicArtistWithAlbums` are NOT in that set and must not be
 * deleted with them: {@see getAllArtists()} constructs both on every served
 * `GET /api/v1/music/artists` request (S99 review r2, LOW-4 — the earlier wording
 * called it a four-class deletion and named a live DTO as dead).
 *
 * ✅ **FIXED in S121 — the `media_item_id` coercion bug this docblock used to warn
 * about is gone.** All three DTOs coerced `media_item_id` with `is_numeric()`, which
 * is false for every `CHAR(36)` UUID, so the field was silently ALWAYS the fallback.
 * The two halves were NOT identical, and the difference is the point:
 * `MusicArtist`/`MusicAlbum` declared `?int` and fell back to **`null`** — a dropped
 * field, visibly absent — while `MusicTrack` declared a non-nullable `int` and fell
 * back to **`0`**, a *plausible-looking* id that no caller would recognise as
 * missing, so no DTO-based read could produce a playable track id. All three are now
 * `?string`, coerced by a per-class `mediaItemIdFromRow()` (fixed together, not just
 * the `MusicTrack` one that happened to be written down here — that partial
 * write-up is why the two siblings went unnoticed for a whole step). The
 * array-returning reads this class serves the API from (`getAllTracks()`,
 * `findTrackByMediaItemId()`, `getTracksByAlbumIds()`) never had the bug — they
 * carry the raw UUID. ⚠ `phlix-contracts`' `src/Music.ts` still declares
 * `mediaItemId: number` and is a separate, cross-repo follow-up (filed as S123);
 * nothing reads it today, and no served payload emits the field.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Service for accessing and managing music library data
 * @see MusicArtist For artist data model
 * @see MusicAlbum For album data model
 * @see MusicTrack For track data model
 * @see MusicLibraryScanner For directory scanning
 */
class MusicLibraryService
{
    /**
     * The SELECT list every track read shares.
     *
     * Aliases are API contract — {@see \Phlix\Server\Http\Controllers\MusicController}
     * shapes `artist` / `album` / `year` from `artist_name` / `album_name` /
     * `album_year`, so renaming one silently blanks a field on every track card.
     * `t.*` carries the `media_item_id` UUID that IS the track's public id.
     *
     * Deliberately does NOT select `media_items.path`: the track payload must not
     * leak the server's absolute filesystem layout over the internet-facing relay
     * (`MediaItemShaper` emits no `path` either, so music is no longer the
     * outlier). Nothing here needs a `media_items` join as a result.
     */
    private const TRACK_COLUMNS = 't.*, ar.name AS artist_name, al.title AS album_name, '
        . 'al.year AS album_year';

    /**
     * Absolute ceiling on the child rows ONE batched embedded-list query returns.
     *
     * The listing endpoints embed a child list per parent row
     * ({@see getTracksByAlbumIds()} per album, {@see getAlbumTitlesByArtistIds()}
     * per artist). Clamping the PARENT page to {@see PageLimit::MAX} bounds
     * nothing about the children: 100 albums may legitimately hold 30,000 tracks,
     * and `/api/v1/music` is not in the hub's `STREAMING_BODY_PREFIXES`, so the
     * whole JSON body is buffered in memory by the relay worker AND the HTTP
     * worker — both resident Workerman processes shared with every other tenant.
     * Every embedded track also costs one `hash_hmac()` signed URL on the event
     * loop ({@see \Phlix\Server\Http\Controllers\MusicController::formatTrack()}).
     *
     * 2,000 was chosen against measured production data, not guessed: the live
     * library's worst 100-album window holds **989** tracks (5,091 albums /
     * 29,245 tracks, max 125 tracks in any one album), and its worst 100-artist
     * window holds **400** album titles — **396** once the per-parent window
     * below is applied. So **this batch ceiling never engages on today's library**
     * (the per-parent window does: see {@see MAX_EMBEDDED_ROWS_PER_PARENT}), while
     * a pathological one is capped at ~1.4 MB / ~2,000 HMAC mints instead of being
     * unbounded.
     *
     * ⚠ **What it bounds, and what it does NOT** (S99 review r2, LOW-3). It bounds
     * the response body, the HMAC count and PHP memory — all three measured on a
     * 100-album × 300-track (30,000-row) fixture with production's average name
     * lengths: **2,000 rows / 1,415.84 KB / ~10 ms of HMAC**, against 30,000 rows /
     * ~20 MB / ~155 ms unbounded. It does **not** bound the DATABASE read:
     * `ROW_NUMBER()` is computed over the full partition inside a derived table, so
     * `WHERE r.rn <= ?` and `LIMIT ?` are applied only AFTER MySQL has materialized
     * every matching row. At that 30,000-row fan-out the windowed query costs about
     * the same as the unbounded one it replaced — it returns 2,000 rows instead of
     * 30,000 and is still no cheaper (measured twice, in opposite directions: median
     * **171 ms vs 150 ms** over 5 alternating samples here, 179–184 ms vs 229 ms in
     * review r2) — plus an internal temp table proportional to the whole fan-out.
     * Latent today (production's real 989 rows cost ~9 ms), but if it ever matters
     * the DB-side bound is a per-album lateral/derived rewrite, not a bigger
     * constant.
     *
     * NB the KB figure is the wire body: `Response::json()` encodes with
     * `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` (`Response.php:135`), which is
     * ~1.7× a compact encode of the same payload (measured 827 KB compact). An
     * earlier "~860 KB" here was a compact-encode figure and understated the bytes
     * that actually cross the relay.
     */
    public const MAX_EMBEDDED_ROWS = 2000;

    /**
     * Default per-parent window inside a batched embedded list.
     *
     * Applied as `ROW_NUMBER() OVER (PARTITION BY <parent>)`, so the cap is
     * per album / per artist rather than "the first N rows of the batch" — a
     * plain batch `LIMIT` would let the first few albums eat the whole budget
     * and hand the tail of the page an EMPTY track list, which reads as a
     * broken album rather than a truncated one.
     *
     * ⚠ **This one DOES truncate production today** (S99 review r2, MED-2 — an
     * earlier note here claimed "today's library never truncates", which is true of
     * {@see MAX_EMBEDDED_ROWS} and false of this window). It covers **5,089 of
     * production's 5,091 albums** and **2,194 of its 2,197 artists** in full;
     * the exceptions are 2 albums (`~`, Elton John, 125 tracks;
     * `Hello World - The Motown Solo Collection (3CD Set)`, Michael Jackson, 109)
     * and 3 artists (Michael Jackson 142 albums, Def Leppard 109, Green Day 104).
     * Nothing about that is silent: the list endpoints carry `tracks_truncated` /
     * `albums_truncated` beside the TRUE `track_count` / `album_count`, and the two
     * DETAIL endpoints raise the window to {@see MAX_EMBEDDED_ROWS} so a long
     * compilation or discography comes back whole.
     */
    public const MAX_EMBEDDED_ROWS_PER_PARENT = 100;

    /** @var Connection Database connection */
    private Connection $db;

    /** @var MusicLibraryScanner Scanner for discovering and indexing audio files */
    private MusicLibraryScanner $scanner;

    /**
     * Constructor for MusicLibraryService.
     *
     * @param Connection $db Database connection
     * @param MusicLibraryScanner $scanner Scanner for directory operations
     */
    public function __construct(
        Connection $db,
        MusicLibraryScanner $scanner
    ) {
        $this->db = $db;
        $this->scanner = $scanner;
    }

    /**
     * Scans a directory tree for audio files and builds the Artist→Album→Track hierarchy.
     *
     * @param string        $path       Root path to scan
     * @param callable|null $onProgress Optional `(int $processed, int $total, string $currentPath): void`
     *                                  sink, forwarded to the scanner so a scan job can stream progress.
     * @param string|null   $libraryId  Owning library UUID, forwarded so the scanner can stamp
     *                                  `media_items.library_id` and carry it on the MediaItemAdded event.
     * @return ScanResult Summary of the scan operation
     *
     * @example
     * ```php
     * $result = $service->scanDirectory('/music/rock', null, $libraryId);
     * ```
     */
    public function scanDirectory(string $path, ?callable $onProgress = null, ?string $libraryId = null): ScanResult
    {
        return $this->scanner->scanDirectory($path, $onProgress, $libraryId);
    }

    /**
     * Counts the scannable audio files under a path (the progress denominator).
     *
     * @param string $path Root path to count under.
     * @return int Number of audio files {@see scanDirectory()} would process.
     */
    public function countFiles(string $path): int
    {
        return $this->scanner->countAudioFiles($path);
    }

    /**
     * Gets an artist by their ID.
     *
     * ⚠ DEAD as of S99 — no `src/` caller; retained deliberately (see the class
     * docblock, "Seven methods here are DEAD but retained on purpose").
     *
     * @param int $id Artist ID
     * @return MusicArtist|null Artist data or null if not found
     *
     * @example
     * ```php
     * $artist = $service->getArtist(42);
     * ```
     */
    public function getArtist(int $id): ?MusicArtist
    {
        $result = $this->db->query(
            "SELECT * FROM music_artists WHERE id = ?",
            [$id]
        );

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return null;
        }

        /** @var array<string, mixed> $typedRow */
        $typedRow = $firstRow;
        return MusicArtist::fromRow($typedRow);
    }

    /**
     * Gets an album by its ID, including its tracks.
     *
     * ⚠ DEAD as of S99 — no `src/` caller; retained deliberately (see the class
     * docblock). The API serves album detail from {@see findAlbumByTitle()} +
     * {@see getTracksByAlbumIds()} instead, because that path is batched AND
     * row-capped and this one is neither. (Until S121 there was a second reason —
     * `MusicTrack` could not carry the UUID a client needs to play a track. That
     * one is fixed: `MusicTrack::$mediaItemId` is now the `?string` UUID.)
     *
     * @param int $id Album ID
     * @return MusicAlbumWithTracks|null Album data with tracks or null if not found
     *
     * @example
     * ```php
     * $album = $service->getAlbum(42);
     * // $album->album->title, $album->tracks
     * ```
     */
    public function getAlbum(int $id): ?MusicAlbumWithTracks
    {
        // Get album
        $albumResult = $this->db->query(
            "SELECT * FROM music_albums WHERE id = ?",
            [$id]
        );

        if (!is_array($albumResult) || count($albumResult) === 0) {
            return null;
        }

        $firstRow = $albumResult[0];
        if (!is_array($firstRow)) {
            return null;
        }
        /** @var array<string, mixed> $typedRow */
        $typedRow = $firstRow;
        $album = MusicAlbum::fromRow($typedRow);

        // Get artist
        $artist = $this->getArtist($album->artistId);

        // Get tracks
        $trackResults = $this->db->query(
            "SELECT * FROM music_tracks WHERE album_id = ? ORDER BY disc_number, track_number",
            [$id]
        );

        $tracks = [];
        if (is_array($trackResults)) {
            foreach ($trackResults as $trackRow) {
                if (is_array($trackRow)) {
                    /** @var array<string, mixed> $typedTrackRow */
                    $typedTrackRow = $trackRow;
                    $tracks[] = MusicTrack::fromRow($typedTrackRow);
                }
            }
        }

        return new MusicAlbumWithTracks($album, $artist, $tracks);
    }

    /**
     * Gets a track by its ID (the internal `music_tracks.id`, NOT the public UUID).
     *
     * ⚠ DEAD as of S99 — no `src/` caller; retained deliberately (see the class
     * docblock). The API keys tracks by `media_items.id`, i.e.
     * {@see findTrackByMediaItemId()}.
     *
     * @param int $id Track ID
     * @return MusicTrack|null Track data or null if not found
     *
     * @example
     * ```php
     * $track = $service->getTrack(42);
     * ```
     */
    public function getTrack(int $id): ?MusicTrack
    {
        $result = $this->db->query(
            "SELECT * FROM music_tracks WHERE id = ?",
            [$id]
        );

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return null;
        }

        /** @var array<string, mixed> $typedRow */
        $typedRow = $firstRow;
        return MusicTrack::fromRow($typedRow);
    }

    /**
     * Searches for artists by name.
     *
     * ⚠ DEAD as of S99 — no `src/` caller; retained deliberately (see the class
     * docblock). Note it is also UNBOUNDED (no `LIMIT`), so it must gain a
     * {@see PageLimit} clamp before it is ever wired to a route.
     *
     * @param string $query Search query
     * @return MusicArtist[] Matching artists
     *
     * @example
     * ```php
     * $artists = $service->searchArtists('beatles');
     * ```
     */
    public function searchArtists(string $query): array
    {
        $result = $this->db->query(
            "SELECT * FROM music_artists WHERE name LIKE ? ORDER BY name",
            ['%' . $query . '%']
        );

        if (!is_array($result)) {
            return [];
        }

        /** @var array<array<string, mixed>> $rows */
        $rows = $result;

        return array_map(
            fn(array $row): MusicArtist => MusicArtist::fromRow($row),
            $rows
        );
    }

    /**
     * Gets artists with recently added albums.
     *
     * ⚠ DEAD as of S99 — no `src/` caller; retained deliberately (see the class
     * docblock). Its hand-rolled `max(1, min(100, …))` clamp is left as-is rather
     * than routed through {@see PageLimit} precisely because nothing calls it:
     * the live listings were unified in this round instead.
     *
     * @param int $limit Maximum number of artists to return (default 20)
     * @return array{artists: MusicArtist[], albums: MusicAlbum[], tracks: MusicTrack[]}
     *   Recently added items grouped by type
     *
     * @example
     * ```php
     * $recent = $service->getRecentlyAdded(10);
     * // $recent['artists'], $recent['albums'], $recent['tracks']
     * ```
     */
    public function getRecentlyAdded(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        // Get recently added artists
        $artistResult = $this->db->query(
            "SELECT * FROM music_artists ORDER BY created_at DESC LIMIT ?",
            [$limit]
        );
        /** @var array<array<string, mixed>> $artistRows */
        $artistRows = is_array($artistResult) ? $artistResult : [];
        $artists = array_map(
            fn(array $row): MusicArtist => MusicArtist::fromRow($row),
            $artistRows
        );

        // Get recently added albums
        $albumResult = $this->db->query(
            "SELECT * FROM music_albums ORDER BY created_at DESC LIMIT ?",
            [$limit]
        );
        /** @var array<array<string, mixed>> $albumRows */
        $albumRows = is_array($albumResult) ? $albumResult : [];
        $albums = array_map(
            fn(array $row): MusicAlbum => MusicAlbum::fromRow($row),
            $albumRows
        );

        // Get recently added tracks
        $trackResult = $this->db->query(
            "SELECT * FROM music_tracks ORDER BY created_at DESC LIMIT ?",
            [$limit]
        );
        /** @var array<array<string, mixed>> $trackRows */
        $trackRows = is_array($trackResult) ? $trackResult : [];
        $tracks = array_map(
            fn(array $row): MusicTrack => MusicTrack::fromRow($row),
            $trackRows
        );

        return [
            'artists' => $artists,
            'albums' => $albums,
            'tracks' => $tracks,
        ];
    }

    /**
     * Gets all artists with their album counts.
     *
     * @param int $limit Maximum number of artists to return (clamped by {@see PageLimit}).
     * @param int $offset Number of artists to skip (default 0)
     * @return MusicArtistWithAlbums[] Artists with album data
     *
     * @example
     * ```php
     * $artists = $service->getAllArtists();
     * foreach ($artists as $artistData) {
     *     echo "{$artistData->artist->name}: {$artistData->albumCount} albums";
     * }
     * ```
     */
    public function getAllArtists(int $limit = 50, int $offset = 0): array
    {
        // ONE clamp policy for all three listings (S99 review r1, LOW-5): this
        // used to hand-roll `max(1, min(100, …))` while getAllAlbums() used
        // PageLimit, so a future change to PageLimit::MAX would have moved one
        // and silently left the others behind.
        $limit = PageLimit::clamp($limit, PageLimit::MAX);
        $offset = PageLimit::clampOffset($offset);

        $result = $this->db->query(
            "SELECT a.*,
                    COUNT(DISTINCT al.id) AS album_count,
                    COUNT(DISTINCT t.id) AS track_count
             FROM music_artists a
             LEFT JOIN music_albums al ON al.artist_id = a.id
             LEFT JOIN music_tracks t ON t.album_id = al.id
             GROUP BY a.id
             ORDER BY a.name
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        if (!is_array($result)) {
            return [];
        }

        /** @var array<array<string, mixed>> $rows */
        $rows = $result;

        return array_map(function (array $row): MusicArtistWithAlbums {
            /** @var array<string, mixed> $typedRow */
            $typedRow = $row;
            $albumCount = is_numeric($typedRow['album_count'] ?? null) ? (int)$typedRow['album_count'] : 0;
            $trackCount = is_numeric($typedRow['track_count'] ?? null) ? (int)$typedRow['track_count'] : 0;
            return new MusicArtistWithAlbums(
                artist: MusicArtist::fromRow($typedRow),
                albumCount: $albumCount,
                trackCount: $trackCount
            );
        }, $rows);
    }

    /**
     * Gets the total number of artists.
     *
     * @return int Total artist count
     */
    public function getArtistsCount(): int
    {
        return $this->countRows('music_artists');
    }

    /**
     * Finds one artist by their exact display name, with album/track counts.
     *
     * The name-keyed counterpart of {@see getAllArtists()}: `music_artists` has an
     * AUTO_INCREMENT PK the clients never see, so `GET /api/v1/music/artists/{mbid}`
     * passes the artist **name** as the identity (see `phlix-ui` `client.ts`
     * `getArtist(mbid)` and the `/app/music/artist/:name` route). Matching is
     * case-insensitive because `music_artists.name` is `utf8mb4_unicode_ci`, which
     * preserves the `strcasecmp()` behaviour of the pre-S99 handler.
     *
     * Counts use the same `COUNT(DISTINCT …)` expressions as {@see getAllArtists()}
     * so a list row and a detail row can never disagree.
     *
     * @param string $name Exact artist display name (case-insensitive).
     * @return array{id: int, name: string, image_url: string|null, album_count: int, track_count: int}|null
     *         Artist summary row, or null when no artist carries that name.
     *
     * @example
     * ```php
     * $artist = $service->findArtistByName('Pink Floyd');
     * ```
     */
    public function findArtistByName(string $name): ?array
    {
        $result = $this->db->query(
            "SELECT a.id, a.name, a.image_url,
                    COUNT(DISTINCT al.id) AS album_count,
                    COUNT(DISTINCT t.id) AS track_count
             FROM music_artists a
             LEFT JOIN music_albums al ON al.artist_id = a.id
             LEFT JOIN music_tracks t ON t.album_id = al.id
             WHERE a.name = ?
             GROUP BY a.id, a.name, a.image_url
             LIMIT 1",
            [$name]
        );

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return null;
        }

        return [
            'id' => is_numeric($firstRow['id'] ?? null) ? (int) $firstRow['id'] : 0,
            'name' => is_string($firstRow['name'] ?? null) ? $firstRow['name'] : '',
            'image_url' => is_string($firstRow['image_url'] ?? null) ? $firstRow['image_url'] : null,
            'album_count' => is_numeric($firstRow['album_count'] ?? null) ? (int) $firstRow['album_count'] : 0,
            'track_count' => is_numeric($firstRow['track_count'] ?? null) ? (int) $firstRow['track_count'] : 0,
        ];
    }

    /**
     * Gets the album titles for a batch of artists, keyed by artist id.
     *
     * ONE query for the whole page — the artists API response carries an `albums`
     * array of titles per artist, and asking per artist would be a textbook N+1
     * against a resident-memory worker (see CLAUDE.md "Batch Queries for N+1
     * Prevention").
     *
     * **Row-capped**, exactly like {@see getTracksByAlbumIds()} and for the same
     * reason: clamping the artist page to 100 says nothing about how many albums
     * those artists hold. `ROW_NUMBER()` windows the list per artist and the outer
     * `ORDER BY rn` makes truncation round-robin, so a long discography can never
     * starve a later artist of its titles.
     *
     * Production's worst 100-artist window is 400 titles, so the 2,000-row batch
     * ceiling never engages — but the **per-artist window does**: three artists hold
     * more than {@see MAX_EMBEDDED_ROWS_PER_PARENT} albums (Michael Jackson 142,
     * Def Leppard 109, Green Day 104). That is surfaced, not silent — the caller
     * compares `count()` against the TRUE `album_count` and the response carries
     * `albums_truncated` — and `MusicController::getArtist()` passes
     * {@see MAX_EMBEDDED_ROWS} so artist DETAIL returns the whole discography.
     *
     * Like {@see getTracksByAlbumIds()}, the window is computed over the full
     * partition inside a derived table, so the cap bounds the RESPONSE, not the
     * database read (see {@see MAX_EMBEDDED_ROWS}).
     *
     * @param list<int> $artistIds Artist ids to fetch titles for (empty = no query).
     * @param int $perArtistLimit Titles per artist (clamped to
     *        `[1, self::MAX_EMBEDDED_ROWS]`). The artist DETAIL endpoint passes
     *        {@see MAX_EMBEDDED_ROWS} because it asks for exactly one artist and
     *        must not truncate a long discography.
     * @return array<int, list<string>> Map of artist id to its album titles,
     *         each list ordered by title. The list is NOT de-duplicated, so its
     *         `count()` is comparable to `album_count` even when an artist has two
     *         albums with the same title.
     */
    public function getAlbumTitlesByArtistIds(
        array $artistIds,
        int $perArtistLimit = self::MAX_EMBEDDED_ROWS_PER_PARENT
    ): array {
        $ids = array_values(array_unique(array_filter($artistIds, static fn(int $id): bool => $id > 0)));
        if (count($ids) === 0) {
            return [];
        }

        $perArtist = max(1, min($perArtistLimit, self::MAX_EMBEDDED_ROWS));
        $batchLimit = min(count($ids) * $perArtist, self::MAX_EMBEDDED_ROWS);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $result = $this->db->query(
            "SELECT r.artist_id, r.title FROM (
                 SELECT al.artist_id, al.title,
                        ROW_NUMBER() OVER (
                            PARTITION BY al.artist_id ORDER BY al.title, al.id
                        ) AS rn
                 FROM music_albums al
                 WHERE al.artist_id IN ({$placeholders})
             ) r
             WHERE r.rn <= ?
             ORDER BY r.rn, r.artist_id
             LIMIT ?",
            array_merge($ids, [$perArtist, $batchLimit])
        );

        if (!is_array($result)) {
            return [];
        }

        /** @var array<int, list<string>> $byArtist */
        $byArtist = [];
        foreach ($result as $row) {
            if (!is_array($row)) {
                continue;
            }
            $artistId = is_numeric($row['artist_id'] ?? null) ? (int) $row['artist_id'] : 0;
            $title = is_string($row['title'] ?? null) ? $row['title'] : '';
            if ($artistId === 0) {
                continue;
            }
            $byArtist[$artistId][] = $title;
        }

        return $byArtist;
    }

    /**
     * Gets an artist with their albums.
     *
     * ⚠ DEAD as of S99 — no `src/` caller; retained deliberately (see the class
     * docblock). The API serves artist detail from {@see findArtistByName()} +
     * {@see getAlbumTitlesByArtistIds()}. Note the album fan-out here is also
     * UNBOUNDED, so it needs the {@see MAX_EMBEDDED_ROWS} treatment before use.
     *
     * @param int $id Artist ID
     * @return MusicArtistWithAlbums|null Artist with albums or null if not found
     *
     * @example
     * ```php
     * $artistData = $service->getArtistWithAlbums(42);
     * // $artistData->artist->name, $artistData->albums
     * ```
     */
    public function getArtistWithAlbums(int $id): ?MusicArtistWithAlbums
    {
        // Get artist
        $artist = $this->getArtist($id);
        if ($artist === null) {
            return null;
        }

        // Get album count
        $countResult = $this->db->query(
            "SELECT COUNT(*) as cnt, COALESCE(SUM(total_tracks), 0) as track_count
             FROM music_albums WHERE artist_id = ?",
            [$id]
        );

        $albumCount = 0;
        $trackCount = 0;
        if (is_array($countResult) && count($countResult) > 0) {
            $firstRow = $countResult[0];
            if (is_array($firstRow)) {
                $albumCount = isset($firstRow['cnt']) && is_numeric($firstRow['cnt']) ? (int)$firstRow['cnt'] : 0;
                $trackCount = isset($firstRow['track_count']) && is_numeric($firstRow['track_count']) ?
                    (int)$firstRow['track_count'] : 0;
            }
        }

        // Get albums
        $albumResult = $this->db->query(
            "SELECT * FROM music_albums WHERE artist_id = ? ORDER BY year DESC, title",
            [$id]
        );

        /** @var array<array<string, mixed>> $albumRows */
        $albumRows = is_array($albumResult) ? $albumResult : [];
        $albums = array_map(
            fn(array $row): MusicAlbum => MusicAlbum::fromRow($row),
            $albumRows
        );

        return new MusicArtistWithAlbums($artist, $albumCount, $trackCount, $albums);
    }

    /**
     * Gets a page of albums joined with their artist name, optionally one artist only.
     *
     * Returns raw arrays (not DTOs) because the API response needs the joined
     * `artist_name` alias, which no single-table DTO carries — the same reason
     * {@see getAllTracks()} returns rows.
     *
     * **`$artistName` is the drill-down filter** (`GET /api/v1/music/albums?artist=`,
     * S99 review r1 MED-2). Without it a client has to fetch page 1 of the whole
     * album list and filter locally, which on the live library means page 1 spans
     * only 23 of 2,197 artists — so 77 of the 100 artists on screen drilled down to
     * an empty album list. Filtering here resolves the artist through
     * `music_artists.uk_name` and the albums through `music_albums.idx_artist`:
     * measured on production, **0.95 ms filtered vs 134 ms unfiltered**.
     *
     * **Track counts are NOT joined here** (S99 review r1, LOW-10). The previous
     * `LEFT JOIN music_tracks … GROUP BY` forced MySQL to aggregate all 29,245
     * track rows into a temporary table before it could sort and take 100 albums:
     * measured **134 ms** on production, and identical at `OFFSET 4900`, i.e. per
     * browse request on a resident event loop. The same page costs **41 ms** without
     * the aggregate, and {@see getTrackCountsByAlbumIds()} then counts only the
     * page's own albums off `idx_album`.
     *
     * Ordering matches {@see getAllTracks()}'s first two keys (`ar.name, al.title`),
     * i.e. the DISPLAY columns — deliberately NOT `sort_title` (see the ordering
     * follow-up in `plan_updates_worklog.md`: `sort_*` has no readers and MySQL
     * sorts NULLs first). `al.id` is appended as a tiebreaker so paging is stable:
     * 2,622 of production's 5,091 albums share a title with another album, and an
     * ambiguous sort key can otherwise show the same row on two pages.
     *
     * @param int $limit Maximum number of albums to return (clamped by {@see PageLimit}).
     * @param int $offset Number of albums to skip.
     * @param string|null $artistName Exact artist name (case-insensitive) to
     *        restrict the page to; null/'' = every artist.
     * @return array<array<string, mixed>> Album rows with `artist_name`.
     */
    public function getAllAlbums(int $limit = 100, int $offset = 0, ?string $artistName = null): array
    {
        $limit = PageLimit::clamp($limit, PageLimit::MAX);
        $offset = PageLimit::clampOffset($offset);

        $where = '';
        /** @var list<string|int> $params */
        $params = [];
        if ($artistName !== null && $artistName !== '') {
            $where = ' WHERE ar.name = ?';
            $params[] = $artistName;
        }
        $params[] = $limit;
        $params[] = $offset;

        $result = $this->db->query(
            "SELECT al.*, ar.name AS artist_name
             FROM music_albums al
             JOIN music_artists ar ON ar.id = al.artist_id" . $where . "
             ORDER BY ar.name, al.title, al.id
             LIMIT ? OFFSET ?",
            $params
        );

        if (!is_array($result)) {
            return [];
        }

        /** @var array<array<string, mixed>> $rows */
        $rows = $result;

        return $rows;
    }

    /**
     * Gets the total number of albums, or of one artist's albums.
     *
     * @param string|null $artistName Exact artist name (case-insensitive) to count
     *        within, matching {@see getAllAlbums()}'s filter so `total` describes
     *        the same set the page came from; null/'' = every album.
     * @return int Total album count
     */
    public function getAlbumsCount(?string $artistName = null): int
    {
        if ($artistName === null || $artistName === '') {
            return $this->countRows('music_albums');
        }

        return $this->firstCount($this->db->query(
            "SELECT COUNT(*) as cnt
             FROM music_albums al
             JOIN music_artists ar ON ar.id = al.artist_id
             WHERE ar.name = ?",
            [$artistName]
        ));
    }

    /**
     * Counts the indexed tracks of a batch of albums, keyed by album id.
     *
     * ONE indexed query for a whole page (`idx_album`), replacing the aggregate
     * {@see getAllAlbums()} used to carry — see that method's LOW-10 note.
     *
     * Counts the `music_tracks` rows that actually exist rather than echoing
     * `music_albums.total_tracks` (a tag-declared total that can exceed what was
     * indexed), so a client never renders more rows than it can play. It is also
     * the TRUE total the embedded, row-capped track list is compared against to
     * expose truncation ({@see getTracksByAlbumIds()}).
     *
     * Needs no row cap: the result is at most one row per requested album id.
     *
     * @param list<int> $albumIds Album ids to count tracks for (empty = no query).
     * @return array<int, int> Map of album id to its indexed track count.
     */
    public function getTrackCountsByAlbumIds(array $albumIds): array
    {
        $ids = array_values(array_unique(array_filter($albumIds, static fn(int $id): bool => $id > 0)));
        if (count($ids) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $result = $this->db->query(
            "SELECT album_id, COUNT(*) AS track_count FROM music_tracks
             WHERE album_id IN ({$placeholders})
             GROUP BY album_id",
            $ids
        );

        if (!is_array($result)) {
            return [];
        }

        $counts = [];
        foreach ($result as $row) {
            if (!is_array($row)) {
                continue;
            }
            $albumId = is_numeric($row['album_id'] ?? null) ? (int) $row['album_id'] : 0;
            if ($albumId === 0) {
                continue;
            }
            $counts[$albumId] = is_numeric($row['track_count'] ?? null) ? (int) $row['track_count'] : 0;
        }

        return $counts;
    }

    /**
     * Finds one album by its exact title, joined with its artist name.
     *
     * The title-keyed counterpart of {@see getAllAlbums()}: albums have an
     * AUTO_INCREMENT PK the clients never see, so `GET /api/v1/music/albums/{mbid}`
     * passes the album **title** as the identity (see `phlix-ui` `client.ts`
     * `getAlbum(mbid)` and the `/app/music/album/:name` route). Matching is
     * case-insensitive via the `utf8mb4_unicode_ci` collation, preserving the
     * `strcasecmp()` behaviour of the pre-S99 handler.
     *
     * **A title is not an identity, and this method no longer pretends otherwise**
     * (S99 review r1, MED-3). `music_albums.title` carries only the non-unique
     * `idx_title`, and on the live library **2,622 of 5,091 albums (51.5%) share a
     * title with another album** — `Featuring Freshness` appears 35 times. Two
     * things follow:
     *
     * 1. `$artistName` disambiguates (`GET /api/v1/music/albums/{title}?artist=`).
     *    With it the lookup is exact for every album whose (artist, title) pair is
     *    unique, which is the whole library bar same-artist re-releases.
     * 2. Without it the winner is DETERMINISTIC rather than arbitrary: lowest
     *    `al.id` among the alphabetically-first `artist_name`. Before this round the
     *    `ORDER BY ar.name, al.title` left all 35 `Featuring Freshness` rows tied,
     *    so which one came back was up to InnoDB. It is still not necessarily the
     *    album the user clicked — that needs `?artist=` (or an album id, deferred
     *    to S108, which is already re-keying music away from path segments) — but
     *    it is now reproducible and documented instead of silently random.
     *
     * Track counts come from {@see getTrackCountsByAlbumIds()} (one source, see
     * {@see getAllAlbums()}'s LOW-10 note), so this row carries no `track_count`.
     *
     * @param string $title Exact album title (case-insensitive).
     * @param string|null $artistName Exact artist name (case-insensitive) to
     *        disambiguate a shared title; null/'' = deterministic first match.
     * @return array<string, mixed>|null Album row with `artist_name`, or null.
     */
    public function findAlbumByTitle(string $title, ?string $artistName = null): ?array
    {
        $where = 'WHERE al.title = ?';
        $params = [$title];
        if ($artistName !== null && $artistName !== '') {
            $where .= ' AND ar.name = ?';
            $params[] = $artistName;
        }

        $result = $this->db->query(
            "SELECT al.*, ar.name AS artist_name
             FROM music_albums al
             JOIN music_artists ar ON ar.id = al.artist_id
             " . $where . "
             ORDER BY ar.name, al.title, al.id
             LIMIT 1",
            $params
        );

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return null;
        }

        /** @var array<string, mixed> $typedRow */
        $typedRow = $firstRow;

        return $typedRow;
    }

    /**
     * Gets the tracks of a batch of albums, keyed by album id — batched AND capped.
     *
     * ONE query for a whole page of albums (the albums API embeds each album's
     * track list), so browsing never degrades into an N+1. Rows carry the same
     * joined shape as {@see getAllTracks()} — `artist_name`, `album_name` and
     * `album_year`. Like every track read here it deliberately carries NO
     * `media_items.path`: see {@see TRACK_COLUMNS}, which does not select it.
     *
     * **The row cap is a security bound, not a nicety** (S99 review r1, HIGH-1).
     * `listAlbums` clamps the ALBUM page to {@see PageLimit::MAX}; that says
     * nothing about how many tracks those albums hold, and this query had no
     * `LIMIT` at all — 100 albums holding 30,000 tracks meant 30,000 rows,
     * 30,000 `hash_hmac()` mints on the event loop and ~12 MB of JSON buffered
     * whole in BOTH shared hub workers (`/api/v1/music` is not in the hub's
     * `STREAMING_BODY_PREFIXES`). Two bounds now apply:
     *
     * - a per-album window (`ROW_NUMBER() OVER (PARTITION BY t.album_id)`), so
     *   the cap is per album rather than "the first N rows of the batch";
     * - an absolute batch ceiling of {@see MAX_EMBEDDED_ROWS}.
     *
     * Both bound the response, the HMAC count and PHP memory — **not** the database
     * read: the window is computed over the full partition inside a derived table,
     * so `WHERE r.rn <= ?` and `LIMIT ?` only apply after MySQL has materialized the
     * whole fan-out. See {@see MAX_EMBEDDED_ROWS} for the measurements.
     *
     * The outer `ORDER BY r.rn` makes the ceiling degrade ROUND-ROBIN — every
     * album gets its track 1 before any album gets its track 2 — because a plain
     * `LIMIT` over `ORDER BY album_id` would hand the tail of the page an empty
     * list, and an album with zero embedded tracks reads to a client as broken
     * rather than truncated. Grouping below therefore still yields each album's
     * rows in disc/track order (`rn` ascending IS that order).
     *
     * Truncation is never silent: the caller compares `count()` against the true
     * {@see getTrackCountsByAlbumIds()} value and the response carries both
     * `track_count` and `tracks_truncated`.
     *
     * @param list<int> $albumIds Album ids to fetch tracks for (empty = no query).
     * @param int $perAlbumLimit Tracks per album (clamped to
     *        `[1, self::MAX_EMBEDDED_ROWS]`). The album DETAIL endpoint passes
     *        {@see MAX_EMBEDDED_ROWS} because it asks for exactly one album and
     *        must not truncate a long compilation (production's longest album has
     *        125 tracks).
     * @return array<int, list<array<string, mixed>>> Map of album id to its track rows,
     *         each list ordered by disc then track number.
     */
    public function getTracksByAlbumIds(
        array $albumIds,
        int $perAlbumLimit = self::MAX_EMBEDDED_ROWS_PER_PARENT
    ): array {
        $ids = array_values(array_unique(array_filter($albumIds, static fn(int $id): bool => $id > 0)));
        if (count($ids) === 0) {
            return [];
        }

        $perAlbum = max(1, min($perAlbumLimit, self::MAX_EMBEDDED_ROWS));
        $batchLimit = min(count($ids) * $perAlbum, self::MAX_EMBEDDED_ROWS);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $result = $this->db->query(
            "SELECT r.* FROM (
                 SELECT " . self::TRACK_COLUMNS . ",
                        ROW_NUMBER() OVER (
                            PARTITION BY t.album_id ORDER BY t.disc_number, t.track_number, t.id
                        ) AS rn
                 FROM music_tracks t
                 JOIN music_albums al ON al.id = t.album_id
                 JOIN music_artists ar ON ar.id = t.artist_id
                 WHERE t.album_id IN ({$placeholders})
             ) r
             WHERE r.rn <= ?
             ORDER BY r.rn, r.album_id
             LIMIT ?",
            array_merge($ids, [$perAlbum, $batchLimit])
        );

        if (!is_array($result)) {
            return [];
        }

        /** @var array<int, list<array<string, mixed>>> $byAlbum */
        $byAlbum = [];
        foreach ($result as $row) {
            if (!is_array($row)) {
                continue;
            }
            $albumId = is_numeric($row['album_id'] ?? null) ? (int) $row['album_id'] : 0;
            if ($albumId === 0) {
                continue;
            }
            /** @var array<string, mixed> $typedRow */
            $typedRow = $row;
            // `rn` is the windowing artefact, not part of the row contract.
            unset($typedRow['rn']);
            $byAlbum[$albumId][] = $typedRow;
        }

        return $byAlbum;
    }

    /**
     * Gets all tracks for an album.
     *
     * ⚠ DEAD as of S99 — no `src/` caller; retained deliberately (see the class
     * docblock). The API embeds album tracks via {@see getTracksByAlbumIds()},
     * which is batched AND row-capped; this one is neither.
     *
     * @param int $albumId Album ID
     * @return MusicTrack[] Tracks ordered by disc number and track number
     */
    public function getAlbumTracks(int $albumId): array
    {
        $result = $this->db->query(
            "SELECT * FROM music_tracks WHERE album_id = ? ORDER BY disc_number, track_number",
            [$albumId]
        );

        if (!is_array($result)) {
            return [];
        }

        /** @var array<array<string, mixed>> $rows */
        $rows = $result;

        return array_map(
            fn(array $row): MusicTrack => MusicTrack::fromRow($row),
            $rows
        );
    }

    /**
     * Gets all tracks with pagination.
     *
     * Returns raw arrays with artist_name and album_name included for the API response.
     *
     * @param int $limit Maximum number of tracks to return (clamped by {@see PageLimit}).
     * @param int $offset Number of tracks to skip (default 0)
     * @return array<array<string, mixed>> Tracks ordered by artist, album, disc, track number
     */
    public function getAllTracks(int $limit = 100, int $offset = 0): array
    {
        // One clamp policy for all three listings — see getAllArtists().
        $limit = PageLimit::clamp($limit, PageLimit::MAX);
        $offset = PageLimit::clampOffset($offset);

        $result = $this->db->query(
            // NB: the album's column is `title` (music_albums has NO `name`
            // column — see migration 065); only music_artists has `name`.
            // Selecting `al.name` made every /api/v1/music/tracks call fail with
            // "Unknown column 'al.name' in 'field list'" (SQLSTATE 42S22). The
            // `AS album_name` output alias is part of the API contract
            // (MusicController::formatTrack reads $row['album_name']) and
            // must stay.
            "SELECT " . self::TRACK_COLUMNS . "
             FROM music_tracks t
             JOIN music_albums al ON al.id = t.album_id
             JOIN music_artists ar ON ar.id = t.artist_id
             ORDER BY ar.name, al.title, t.disc_number, t.track_number
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        if (!is_array($result)) {
            return [];
        }

        /** @var array<array<string, mixed>> $rows */
        $rows = $result;

        return $rows;
    }

    /**
     * Finds one track by the `media_items` UUID the API exposes as its id.
     *
     * `music_tracks.id` is an internal AUTO_INCREMENT PK; every client keys tracks
     * by the `media_items` UUID (`GET /api/v1/music/tracks/{id}`, the signed
     * `/media/{id}/stream` URL, and `sessions.current_media_id` for now-playing),
     * and `music_tracks.media_item_id` is `UNIQUE`, so this is a single-row index
     * lookup. It replaces the pre-S99
     * {@see \Phlix\Server\Http\Controllers\MusicController} helper that linear-scanned
     * the first 1,000 rows of each library and therefore 404'd — i.e. refused to
     * play — every track past the 1,000th.
     *
     * @param string $mediaItemId `media_items.id` UUID of the track.
     * @return array<string, mixed>|null Joined track row, or null when unknown.
     */
    public function findTrackByMediaItemId(string $mediaItemId): ?array
    {
        if ($mediaItemId === '') {
            return null;
        }

        $result = $this->db->query(
            "SELECT " . self::TRACK_COLUMNS . "
             FROM music_tracks t
             JOIN music_albums al ON al.id = t.album_id
             JOIN music_artists ar ON ar.id = t.artist_id
             WHERE t.media_item_id = ?
             LIMIT 1",
            [$mediaItemId]
        );

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return null;
        }

        /** @var array<string, mixed> $typedRow */
        $typedRow = $firstRow;

        return $typedRow;
    }

    /**
     * Gets the total number of tracks.
     *
     * @return int Total track count
     */
    public function getTracksCount(): int
    {
        return $this->countRows('music_tracks');
    }

    /**
     * Counts every row of one of this service's own tables.
     *
     * `$table` is never caller-supplied — the three call sites pass a literal
     * `music_*` name — so there is no interpolation hazard here; the guard below
     * makes that structural rather than a matter of trust.
     *
     * @param string $table One of the `music_*` table names.
     * @return int Row count, or 0 when the count row is unavailable.
     */
    private function countRows(string $table): int
    {
        if (!in_array($table, ['music_artists', 'music_albums', 'music_tracks'], true)) {
            return 0;
        }

        return $this->firstCount($this->db->query("SELECT COUNT(*) as cnt FROM {$table}"));
    }

    /**
     * Reads the `cnt` column out of a single-row `SELECT COUNT(*) as cnt` result.
     *
     * @param mixed $result Whatever the driver returned for the count query.
     * @return int Row count, or 0 when the count row is unavailable.
     */
    private function firstCount(mixed $result): int
    {
        if (!is_array($result) || count($result) === 0) {
            return 0;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return 0;
        }

        return isset($firstRow['cnt']) && is_numeric($firstRow['cnt']) ? (int)$firstRow['cnt'] : 0;
    }
}
