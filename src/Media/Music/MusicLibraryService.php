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
     * Gets a track by its ID.
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
     * @param int $limit Maximum number of artists to return (default 50)
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
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

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
     * @param list<int> $artistIds Artist ids to fetch titles for (empty = no query).
     * @return array<int, list<string>> Map of artist id to its album titles.
     */
    public function getAlbumTitlesByArtistIds(array $artistIds): array
    {
        $ids = array_values(array_unique(array_filter($artistIds, static fn(int $id): bool => $id > 0)));
        if (count($ids) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $result = $this->db->query(
            "SELECT artist_id, title FROM music_albums
             WHERE artist_id IN ({$placeholders})
             ORDER BY artist_id, title",
            $ids
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
     * Gets a page of albums joined with their artist name and indexed track count.
     *
     * Returns raw arrays (not DTOs) because the API response needs the joined
     * `artist_name` alias, which no single-table DTO carries — the same reason
     * {@see getAllTracks()} returns rows.
     *
     * `track_count` counts the `music_tracks` rows that actually exist rather than
     * echoing `music_albums.total_tracks` (a tag-declared total that can exceed
     * what was indexed), so a client never renders more rows than it can play.
     *
     * Ordering matches {@see getAllTracks()}'s first two keys (`ar.name, al.title`),
     * i.e. the DISPLAY columns — deliberately NOT `sort_title` (see the ordering
     * follow-up in `plan_updates_worklog.md`: `sort_*` has no readers and MySQL
     * sorts NULLs first).
     *
     * @param int $limit Maximum number of albums to return (clamped by {@see PageLimit}).
     * @param int $offset Number of albums to skip.
     * @return array<array<string, mixed>> Album rows with `artist_name` + `track_count`.
     */
    public function getAllAlbums(int $limit = 100, int $offset = 0): array
    {
        $limit = PageLimit::clamp($limit, PageLimit::MAX);
        $offset = PageLimit::clampOffset($offset);

        $result = $this->db->query(
            "SELECT al.*, ar.name AS artist_name, COUNT(t.id) AS track_count
             FROM music_albums al
             JOIN music_artists ar ON ar.id = al.artist_id
             LEFT JOIN music_tracks t ON t.album_id = al.id
             GROUP BY al.id, ar.name
             ORDER BY ar.name, al.title
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
     * Gets the total number of albums.
     *
     * @return int Total album count
     */
    public function getAlbumsCount(): int
    {
        return $this->countRows('music_albums');
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
     * `music_albums.title` is NOT unique (two artists may ship the same title), so
     * the first row in `artist name, title` order wins — the same
     * first-match-wins semantics the pre-S99 handler had.
     *
     * @param string $title Exact album title (case-insensitive).
     * @return array<string, mixed>|null Album row with `artist_name` + `track_count`, or null.
     */
    public function findAlbumByTitle(string $title): ?array
    {
        $result = $this->db->query(
            "SELECT al.*, ar.name AS artist_name, COUNT(t.id) AS track_count
             FROM music_albums al
             JOIN music_artists ar ON ar.id = al.artist_id
             LEFT JOIN music_tracks t ON t.album_id = al.id
             WHERE al.title = ?
             GROUP BY al.id, ar.name
             ORDER BY ar.name, al.title
             LIMIT 1",
            [$title]
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
     * Gets the tracks of a batch of albums, keyed by album id.
     *
     * ONE query for a whole page of albums (the albums API embeds each album's
     * track list), so browsing never degrades into an N+1. Rows carry the same
     * joined shape as {@see getAllTracks()} — `artist_name`, `album_name`,
     * `album_year` and the streamable `media_items.path` — so one formatter can
     * shape both endpoints.
     *
     * @param list<int> $albumIds Album ids to fetch tracks for (empty = no query).
     * @return array<int, list<array<string, mixed>>> Map of album id to its track rows,
     *         each list ordered by disc then track number.
     */
    public function getTracksByAlbumIds(array $albumIds): array
    {
        $ids = array_values(array_unique(array_filter($albumIds, static fn(int $id): bool => $id > 0)));
        if (count($ids) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $result = $this->db->query(
            "SELECT " . self::TRACK_COLUMNS . "
             FROM music_tracks t
             JOIN music_albums al ON al.id = t.album_id
             JOIN music_artists ar ON ar.id = t.artist_id
             WHERE t.album_id IN ({$placeholders})
             ORDER BY t.album_id, t.disc_number, t.track_number",
            $ids
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
            $byAlbum[$albumId][] = $typedRow;
        }

        return $byAlbum;
    }

    /**
     * Gets all tracks for an album.
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
     * @param int $limit Maximum number of tracks to return (default 100)
     * @param int $offset Number of tracks to skip (default 0)
     * @return array<array<string, mixed>> Tracks ordered by artist, album, disc, track number
     */
    public function getAllTracks(int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

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

        $result = $this->db->query("SELECT COUNT(*) as cnt FROM {$table}");

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
