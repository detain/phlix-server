<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Music\MusicArtist;
use Phlix\Media\Music\MusicAlbum;
use Phlix\Media\Music\MusicTrack;
use SplFileInfo;
use Workerman\MySQL\Connection;

/**
 * MusicScanner discovers and indexes music files into the Artist→Album→Track hierarchy.
 *
 * Scans directories for audio files (mp3, flac, m4a, ogg, wav, opus, wma),
 * reads ID3/vorbis tags using AudioScanner's tag harvesting, and upserts
 * records into the music_artists, music_albums, and music_tracks tables.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Scans directories for audio files and builds Artist→Album→Track hierarchy
 * @see AudioScanner For ID3/MP4/Vorbis tag harvesting
 * @see ScanResult For scan operation results
 */
class MusicScanner
{
    /** Supported audio file extensions */
    private const AUDIO_EXTENSIONS = ['mp3', 'flac', 'm4a', 'aac', 'alac', 'ogg', 'oga', 'opus', 'wav', 'wma'];

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var Connection Database connection */
    private Connection $db;

    /** @var AudioScanner Audio tag harvester */
    private AudioScanner $audioScanner;

    /**
     * Constructor for MusicScanner.
     *
     * @param Connection $db Database connection
     * @param AudioScanner $audioScanner Audio tag harvester
     * @param StructuredLogger|null $logger Optional custom logger
     */
    public function __construct(
        Connection $db,
        AudioScanner $audioScanner,
        ?StructuredLogger $logger = null
    ) {
        $this->db = $db;
        $this->audioScanner = $audioScanner;
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * Creates a default structured logger for the music subsystem.
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlix_music_scanner_' . uniqid();
        mkdir($tempDir, 0755, true);

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/music_scanner.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ];

        return new StructuredLogger(LogChannels::MEDIA, $config);
    }

    /**
     * Scans a directory tree for audio files and builds the Artist→Album→Track hierarchy.
     *
     * Recursively walks the given path, harvests tags from each audio file,
     * and upserts the data into music_artists, music_albums, and music_tracks tables.
     *
     * @param string $path Root path to scan
     * @return ScanResult Summary of the scan operation
     *
     * @example
     * ```php
     * $scanner = new MusicScanner($db, $audioScanner);
     * $result = $scanner->scanDirectory('/music/rock');
     * echo "Scanned {$result->scanned}, added {$result->added}, updated {$result->updated}";
     * ```
     */
    public function scanDirectory(string $path): ScanResult
    {
        $result = new ScanResult();
        $startTime = hrtime(true);

        if (!is_dir($path) || !is_readable($path)) {
            $this->logger->warning('Scan path is not accessible', ['path' => $path]);
            return $result;
        }

        $this->logger->info('Starting music directory scan', ['path' => $path]);

        // First pass: group files by (artist, album) to count tracks
        $albumMap = $this->groupFilesByAlbum($path);

        // Track artist/album IDs to handle the hierarchy
        $artistCache = [];
        $albumCache = [];

        foreach ($albumMap as $albumKey => $albumData) {
            $result->scanned++;

            $artistName = $albumData['artist'];
            $albumTitle = $albumData['album'];
            $year = $albumData['year'];
            $files = $albumData['files'];

            // Upsert artist
            $artistId = $this->upsertArtist($artistName, $artistCache);
            if ($artistId === null) {
                $this->logger->warning('Failed to upsert artist', ['artist' => $artistName]);
                continue;
            }

            // Count total tracks for this album
            $totalTracks = count($files);

            // Upsert album
            $albumId = $this->upsertAlbum($artistId, $albumTitle, $year, $totalTracks, $albumCache);
            if ($albumId === null) {
                $this->logger->warning('Failed to upsert album', ['album' => $albumTitle]);
                continue;
            }

            // Upsert tracks
            foreach ($files as $fileInfo) {
                $trackResult = $this->upsertTrack($albumId, $fileInfo);
                if ($trackResult === 'added') {
                    $result->added++;
                } elseif ($trackResult === 'updated') {
                    $result->updated++;
                }
            }
        }

        $result->durationMs = (int)((hrtime(true) - $startTime) / 1_000_000.0);

        $this->logger->info('Music directory scan complete', [
            'path' => $path,
            'scanned' => $result->scanned,
            'added' => $result->added,
            'updated' => $result->updated,
            'duration_ms' => $result->durationMs,
        ]);

        return $result;
    }

    /**
     * Groups audio files by artist and album.
     *
     * @param string $path Root path to scan
     * @return array<string, array{artist:string, album:string, year:?int, files:array<SplFileInfo>}>
     */
    private function groupFilesByAlbum(string $path): array
    {
        /** @var array<string, array{artist:string, album:string, year:?int, files:array<SplFileInfo>}> $albumMap */
        $albumMap = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!($file instanceof SplFileInfo)) {
                continue;
            }

            if ($file->isDir()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                continue;
            }

            if ($this->shouldSkipFile($file->getFilename())) {
                continue;
            }

            // Harvest tags from file
            $tags = $this->audioScanner->harvestTags($file->getPathname());

            // Determine artist and album from tags
            $artist = $this->extractTagString($tags, 'artist') ?? 'Unknown Artist';
            $album = $this->extractTagString($tags, 'album') ?? $file->getBasename();
            $year = $this->extractTagInt($tags, 'year');

            // Use artist+album as key to group files
            $key = md5($artist . '|' . $album);

            if (!isset($albumMap[$key])) {
                $albumMap[$key] = [
                    'artist' => $artist,
                    'album' => $album,
                    'year' => $year,
                    'files' => [],
                ];
            }

            $albumMap[$key]['files'][] = $file;
        }

        // Sort files within each album by track number
        foreach ($albumMap as $key => $albumData) {
            usort($albumMap[$key]['files'], function (SplFileInfo $a, SplFileInfo $b) {
                $tagsA = $this->audioScanner->harvestTags($a->getPathname());
                $tagsB = $this->audioScanner->harvestTags($b->getPathname());

                $trackA = $this->extractTagInt($tagsA, 'track_number') ?? 0;
                $trackB = $this->extractTagInt($tagsB, 'track_number') ?? 0;

                // If track numbers are equal, compare by filename
                if ($trackA === $trackB) {
                    return strcmp($a->getFilename(), $b->getFilename());
                }

                return $trackA - $trackB;
            });
        }

        return $albumMap;
    }

    /**
     * Determines if a file should be skipped during scanning.
     *
     * Skips hidden files (starting with .) and common non-music files.
     *
     * @param string $filename File name to check
     * @return bool True if file should be skipped
     */
    private function shouldSkipFile(string $filename): bool
    {
        // Skip hidden files
        if ($filename === '.' || $filename === '..') {
            return true;
        }

        if (str_starts_with($filename, '.')) {
            return true;
        }

        // Skip common non-music patterns
        $skipPatterns = ['folder.jpg', 'folder.png', 'album.jpg', 'album.png', 'cover.jpg', 'cover.png'];
        $lowerFilename = strtolower($filename);
        if (in_array($lowerFilename, $skipPatterns, true)) {
            return true;
        }

        return false;
    }

    /**
     * Upserts an artist into the database.
     *
     * @param string $name Artist name
     * @param array<string, int> $cache Artist cache to avoid duplicate queries
     * @return int|null Artist ID or null on failure
     */
    private function upsertArtist(string $name, array &$cache): ?int
    {
        // Check cache first
        $cacheKey = strtolower($name);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        // Check if artist exists
        $existing = $this->db->query(
            "SELECT id FROM music_artists WHERE name = ?",
            [$name]
        );

        if (is_array($existing) && count($existing) > 0) {
            $firstRow = $existing[0];
            $id = is_array($firstRow) && isset($firstRow['id']) && is_numeric($firstRow['id']) ? (int)$firstRow['id'] : 0;
            $cache[$cacheKey] = $id;
            return $id;
        }

        // Insert new artist
        $sortName = $this->generateSortName($name);
        $result = $this->db->query(
            "INSERT INTO music_artists (name, sort_name) VALUES (?, ?)",
            [$name, $sortName]
        );

        if ($result === false) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();
        $cache[$cacheKey] = $id;

        $this->logger->debug('Upserted artist', ['id' => $id, 'name' => $name]);

        return $id;
    }

    /**
     * Upserts an album into the database.
     *
     * @param int $artistId Artist ID
     * @param string $title Album title
     * @param int|null $year Release year
     * @param int $totalTracks Total number of tracks
     * @param array<string, int> $cache Album cache key by "artistId|title"
     * @return int|null Album ID or null on failure
     */
    private function upsertAlbum(int $artistId, string $title, ?int $year, int $totalTracks, array &$cache): ?int
    {
        // Check cache first
        $cacheKey = $artistId . '|' . strtolower($title);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        // Check if album exists for this artist
        $existing = $this->db->query(
            "SELECT id FROM music_albums WHERE artist_id = ? AND title = ?",
            [$artistId, $title]
        );

        if (is_array($existing) && count($existing) > 0) {
            $firstRow = $existing[0];
            $id = is_array($firstRow) && isset($firstRow['id']) && is_numeric($firstRow['id']) ? (int)$firstRow['id'] : 0;

            // Update existing album with new track count and year
            $this->db->query(
                "UPDATE music_albums SET total_tracks = ?, year = COALESCE(?, year) WHERE id = ?",
                [$totalTracks, $year, $id]
            );

            $cache[$cacheKey] = $id;
            return $id;
        }

        // Insert new album
        $sortTitle = $this->generateSortName($title);
        $result = $this->db->query(
            "INSERT INTO music_albums (artist_id, title, sort_title, year, total_tracks) VALUES (?, ?, ?, ?, ?)",
            [$artistId, $title, $sortTitle, $year, $totalTracks]
        );

        if ($result === false) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();
        $cache[$cacheKey] = $id;

        $this->logger->debug('Upserted album', ['id' => $id, 'title' => $title, 'artist_id' => $artistId]);

        return $id;
    }

    /**
     * Upserts a track into the database.
     *
     * @param int $albumId Album ID
     * @param SplFileInfo $file Audio file info
     * @return string 'added', 'updated', or 'skipped'
     */
    private function upsertTrack(int $albumId, SplFileInfo $file): string
    {
        $path = $file->getPathname();
        $tags = $this->audioScanner->harvestTags($path);

        $title = $this->extractTagString($tags, 'title') ?? $file->getBasename('.' . $file->getExtension());
        $trackNumber = $this->extractTagInt($tags, 'track_number') ?? 1;
        $discNumber = $this->extractTagInt($tags, 'disc_number') ?? 1;
        $durationSeconds = $this->extractTagInt($tags, 'duration_secs') ?? 0;

        // Check if track exists by audio file path
        $existing = $this->db->query(
            "SELECT id, title, track_number, disc_number, duration_seconds FROM music_tracks WHERE audio_file_path = ?",
            [$path]
        );

        if (is_array($existing) && count($existing) > 0) {
            $existingTrack = $existing[0];
            $existingId = is_array($existingTrack) && isset($existingTrack['id']) && is_numeric($existingTrack['id']) ? (int)$existingTrack['id'] : 0;

            // Check if anything changed
            $existingTitle = is_array($existingTrack) ? ($existingTrack['title'] ?? '') : '';
            $existingTrackNum = is_array($existingTrack) && isset($existingTrack['track_number']) && is_numeric($existingTrack['track_number']) ? (int)$existingTrack['track_number'] : 1;
            $existingDiscNum = is_array($existingTrack) && isset($existingTrack['disc_number']) && is_numeric($existingTrack['disc_number']) ? (int)$existingTrack['disc_number'] : 1;
            $existingDuration = is_array($existingTrack) && isset($existingTrack['duration_seconds']) && is_numeric($existingTrack['duration_seconds']) ? (int)$existingTrack['duration_seconds'] : 0;

            if ($existingTitle === $title
                && $existingTrackNum === $trackNumber
                && $existingDiscNum === $discNumber
                && $existingDuration === $durationSeconds
            ) {
                return 'skipped';
            }

            // Update existing track
            $this->db->query(
                "UPDATE music_tracks SET title = ?, track_number = ?, disc_number = ?, duration_seconds = ? WHERE id = ?",
                [$title, $trackNumber, $discNumber, $durationSeconds, $existingId]
            );

            return 'updated';
        }

        // Insert new track
        $result = $this->db->query(
            "INSERT INTO music_tracks (album_id, title, track_number, disc_number, duration_seconds, audio_file_path) VALUES (?, ?, ?, ?, ?, ?)",
            [$albumId, $title, $trackNumber, $discNumber, $durationSeconds, $path]
        );

        if ($result === false) {
            return 'skipped';
        }

        $this->logger->debug('Upserted track', ['album_id' => $albumId, 'title' => $title]);

        return 'added';
    }

    /**
     * Extracts a string tag value safely.
     *
     * @param array<string, mixed> $tags Tag data
     * @param string $key Tag key
     * @return string|null Extracted value or null
     */
    private function extractTagString(array $tags, string $key): ?string
    {
        $value = $tags[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value) && count($value) > 0) {
            $first = array_values($value)[0];
            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        return null;
    }

    /**
     * Extracts an integer tag value safely.
     *
     * @param array<string, mixed> $tags Tag data
     * @param string $key Tag key
     * @return int|null Extracted value or null
     */
    private function extractTagInt(array $tags, string $key): ?int
    {
        $value = $tags[$key] ?? null;

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_float($value)) {
            return (int)$value > 0 ? (int)$value : null;
        }

        if (is_string($value) && is_numeric($value)) {
            $intVal = (int)$value;
            return $intVal > 0 ? $intVal : null;
        }

        return null;
    }

    /**
     * Generates a sort-friendly name from a display name.
     *
     * Strips leading "The ", "A ", "An " for better sorting.
     *
     * @param string $name Display name
     * @return string Sort-friendly name
     */
    private function generateSortName(string $name): string
    {
        // Strip common leading articles
        $name = preg_replace('/^(the|a|an)\s+/i', '', $name) ?? '';
        return trim($name);
    }
}
