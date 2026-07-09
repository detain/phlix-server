<?php

/**
 * Phlix media server component: Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Music;

use Phlix\Media\Library\ScanResult;
use Workerman\MySQL\Connection;

/**
 * MusicLibraryService provides access to the Artist→Album→Track music hierarchy.
 *
 * This service manages the music_artists, music_albums, and music_tracks tables
 * and provides methods for querying artists, albums, tracks, and performing scans.
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
     * @param string $path Root path to scan
     * @return ScanResult Summary of the scan operation
     *
     * @example
     * ```php
     * $result = $service->scanDirectory('/music/rock');
     * ```
     */
    public function scanDirectory(string $path): ScanResult
    {
        return $this->scanner->scanDirectory($path);
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
        $result = $this->db->query("SELECT COUNT(*) as cnt FROM music_artists");

        if (!is_array($result) || count($result) === 0) {
            return 0;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return 0;
        }

        return isset($firstRow['cnt']) && is_numeric($firstRow['cnt']) ? (int)$firstRow['cnt'] : 0;
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
                $trackCount = isset($firstRow['track_count']) && is_numeric($firstRow['track_count']) ? (int)$firstRow['track_count'] : 0;
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
}

/**
 * MusicAlbumWithTracks represents an album with its associated tracks.
 */
final readonly class MusicAlbumWithTracks
{
    /**
     * @param MusicAlbum $album The album data
     * @param MusicArtist|null $artist The album's artist (null if not found)
     * @param MusicTrack[] $tracks Tracks on the album
     */
    public function __construct(
        public MusicAlbum $album,
        public ?MusicArtist $artist,
        public array $tracks
    ) {
    }

    /**
     * Converts to array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'album' => $this->album->toArray(),
            'artist' => $this->artist?->toArray(),
            'tracks' => array_map(fn(MusicTrack $t) => $t->toArray(), $this->tracks),
        ];
    }
}

/**
 * MusicArtistWithAlbums represents an artist with their albums.
 */
final readonly class MusicArtistWithAlbums
{
    /**
     * @param MusicArtist $artist The artist data
     * @param int $albumCount Number of albums by this artist
     * @param int $trackCount Number of tracks by this artist
     * @param MusicAlbum[] $albums Albums by this artist (empty when from getAllArtists)
     */
    public function __construct(
        public MusicArtist $artist,
        public int $albumCount = 0,
        public int $trackCount = 0,
        public array $albums = []
    ) {
    }

    /**
     * Converts to array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artist' => $this->artist->toArray(),
            'album_count' => $this->albumCount,
            'track_count' => $this->trackCount,
            'albums' => array_map(fn(MusicAlbum $a) => $a->toArray(), $this->albums),
        ];
    }
}
