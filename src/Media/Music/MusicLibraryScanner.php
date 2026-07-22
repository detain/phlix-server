<?php

/**
 * Phlix media server component: Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Music;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ScanResult;
use Phlix\Media\Transcoding\FfmpegRunner;
use Psr\Log\LoggerInterface;
use SplFileInfo;
use Workerman\MySQL\Connection;

/**
 * MusicLibraryScanner discovers and indexes music files into the Artist→Album→Track hierarchy.
 *
 * Scans directories for audio files (mp3, flac, m4a, ogg, wav), reads metadata
 * (artist, album, title, track number, disc number, duration) — natively via
 * getID3 first (a pure-PHP tag reader, ~1-3 ms/file), falling back to
 * FFmpeg/ffprobe only when getID3 yields nothing usable — and creates
 * artist/album/track entries with a `media_items` FK.
 *
 * **Progress.** {@see self::scanDirectory()} accepts an optional
 * `(processed, total, currentPath)` callback and ticks it once per audio file
 * during the (slow) tag-reading pass, so the async scan worker can stream a real
 * percentage onto the job row instead of leaving the UI frozen. Use
 * {@see self::countAudioFiles()} to pre-compute the denominator.
 *
 * @author Phlix Development Team
 * @version 1.1.0
 * @description Scans directories for audio files and builds Artist→Album→Track hierarchy
 * @see FfmpegRunner For FFprobe metadata extraction (fallback)
 * @see ScanResult For scan operation results
 *
 * @autowire
 */
class MusicLibraryScanner
{
    /** Supported audio file extensions */
    private const AUDIO_EXTENSIONS = ['mp3', 'flac', 'm4a', 'ogg', 'wav'];

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var Connection Database connection */
    private Connection $db;

    /** @var FfmpegRunner FFprobe runner for metadata extraction (fallback) */
    private FfmpegRunner $ffmpeg;

    /** @var \getID3|null Lazily-constructed native tag reader. */
    private ?\getID3 $id3Reader = null;

    /**
     * Constructor for MusicLibraryScanner.
     *
     * @param Connection $db Database connection
     * @param FfmpegRunner $ffmpeg FFmpeg runner for metadata extraction
     * @param LoggerInterface|null $logger Optional custom logger
     */
    public function __construct(
        Connection $db,
        FfmpegRunner $ffmpeg,
        ?LoggerInterface $logger = null
    ) {
        $this->db = $db;
        $this->ffmpeg = $ffmpeg;
        $this->logger = $this->createLogger($logger);
    }

    /**
     * Creates a structured logger for the music subsystem.
     */
    private function createLogger(?LoggerInterface $logger): StructuredLogger
    {
        if ($logger instanceof StructuredLogger) {
            return $logger;
        }

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
     * Counts the audio files under a path that {@see self::scanDirectory()}
     * would process — using the SAME extension + skip filters — so a caller can
     * pre-compute the progress denominator without reading any tags.
     *
     * @param string $path Root path to count under.
     * @return int Number of scannable audio files (0 when the path is unreadable).
     */
    public function countAudioFiles(string $path): int
    {
        if (!is_dir($path) || !is_readable($path)) {
            return 0;
        }

        $count = 0;
        foreach ($this->audioFileIterator($path) as $file) {
            unset($file);
            $count++;
        }

        return $count;
    }

    /**
     * Scans a directory tree for audio files and builds the Artist→Album→Track hierarchy.
     *
     * Recursively walks the given path, reads metadata from each audio file,
     * and upserts the data into music_artists, music_albums, and music_tracks tables
     * with corresponding media_items entries.
     *
     * @param string        $path       Root path to scan
     * @param callable|null $onProgress Optional `(int $processed, int $total, string $currentPath): void`
     *                                  sink, ticked once per audio file during the tag-reading pass.
     * @return ScanResult Summary of the scan operation
     *
     * @example
     * ```php
     * $scanner = new MusicLibraryScanner($db, $ffmpeg);
     * $result = $scanner->scanDirectory('/music/rock');
     * echo "Scanned {$result->scanned}, added {$result->added}, updated {$result->updated}";
     * ```
     */
    public function scanDirectory(string $path, ?callable $onProgress = null): ScanResult
    {
        $result = new ScanResult();
        $startTime = hrtime(true);

        // Guard: path must be accessible
        if (!is_dir($path) || !is_readable($path)) {
            $this->logger->warning('Scan path is not accessible', ['path' => $path]);
            return $result;
        }

        $this->logger->info('Starting music directory scan', ['path' => $path]);

        // Group files by (artist, album) to count tracks and process efficiently.
        // Progress is ticked here, during the tag-reading pass (the slow part).
        $total = $onProgress !== null ? $this->countAudioFiles($path) : 0;
        $albumMap = $this->groupFilesByAlbum($path, $total, $onProgress);

        // Track artist/album IDs to handle the hierarchy
        /** @var array<string, array{id:int, media_item_id:int|null}> $artistCache */
        $artistCache = [];
        /** @var array<string, array{id:int, media_item_id:int|null}> $albumCache */
        $albumCache = [];

        foreach ($albumMap as $albumKey => $albumData) {
            unset($albumKey);

            // Defensive: a single malformed album/track must not abort the whole
            // scan. The DB layer throws on error (it does not return false), so
            // without this an unexpected row kills the entire library index.
            try {
                $result->scanned++;

                $artistName = $albumData['artist'];
                $albumTitle = $albumData['album'];
                $year = $albumData['year'];
                $files = $albumData['files'];

                // Early exit: skip if no valid artist name
                if ($artistName === '' || $artistName === 'Unknown Artist') {
                    $this->logger->debug('Skipping album with unknown artist', ['album' => $albumTitle]);
                    continue;
                }

                // Upsert artist and get media_item_id
                $artistResult = $this->upsertArtist($artistName, $artistCache);
                if ($artistResult === null) {
                    $this->logger->warning('Failed to upsert artist', ['artist' => $artistName]);
                    continue;
                }

                $artistId = $artistResult['id'];
                $artistMediaItemId = $artistResult['media_item_id'];

                // Count total tracks for this album
                $totalTracks = count($files);

                // Upsert album
                $albumResult = $this->upsertAlbum(
                    $artistId,
                    $artistMediaItemId,
                    $albumTitle,
                    $year,
                    $totalTracks,
                    $albumCache
                );
                if ($albumResult === null) {
                    $this->logger->warning('Failed to upsert album', ['album' => $albumTitle]);
                    continue;
                }

                $albumId = $albumResult['id'];
                $albumMediaItemId = $albumResult['media_item_id'];

                // Upsert tracks (metadata already read during grouping — no re-probe).
                foreach ($files as $fileInfo) {
                    $trackResult = $this->upsertTrack(
                        $albumId,
                        $albumMediaItemId,
                        $artistId,
                        $fileInfo['file'],
                        $fileInfo['meta']
                    );
                    if ($trackResult === 'added') {
                        $result->added++;
                    } elseif ($trackResult === 'updated') {
                        $result->updated++;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('Skipping album after error during indexing', [
                    'album' => $albumData['album'] ?? '(unknown)',
                    'artist' => $albumData['artist'] ?? '(unknown)',
                    'error' => $e->getMessage(),
                ]);
                continue;
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
     * Yields every scannable audio file under a path (extension + skip filters
     * applied), so counting and grouping share one definition of "in scope".
     *
     * @param string $path Root path to walk.
     * @return \Generator<int, SplFileInfo>
     */
    private function audioFileIterator(string $path): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!($file instanceof SplFileInfo) || $file->isDir()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                continue;
            }

            if ($this->shouldSkipFile($file->getFilename())) {
                continue;
            }

            yield $file;
        }
    }

    /**
     * Groups audio files by artist and album, reading each file's tags exactly
     * once and caching the result alongside the file (so the sort below and the
     * later track upsert never re-read tags).
     *
     * @param string        $path       Root path to scan
     * @param int           $total      Progress denominator (audio file count)
     * @param callable|null $onProgress Optional `(processed, total, currentPath)` sink
     * @return array<string, array{artist:string, album:string, year:?int,
     *     files:list<array{file:SplFileInfo, meta:array<string, mixed>}>}>
     */
    private function groupFilesByAlbum(string $path, int $total, ?callable $onProgress): array
    {
        /** @var array<string, array{artist:string, album:string, year:?int,
         *     files:list<array{file:SplFileInfo, meta:array<string, mixed>}>}> $albumMap */
        $albumMap = [];
        $processed = 0;

        foreach ($this->audioFileIterator($path) as $file) {
            $processed++;
            if ($onProgress !== null) {
                $onProgress($processed, $total, $file->getPathname());
            }

            // Read metadata from file (getID3 first, ffprobe fallback).
            $metadata = $this->probeMetadata($file->getPathname());

            $extension = strtolower($file->getExtension());

            // Determine artist and album from tags
            $artist = is_string($metadata['artist']) ? $metadata['artist'] : 'Unknown Artist';
            $album = is_string($metadata['album']) ? $metadata['album'] : $file->getBasename('.' . $extension);
            $year = is_numeric($metadata['year']) ? (int)$metadata['year'] : null;

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

            $albumMap[$key]['files'][] = ['file' => $file, 'meta' => $metadata];
        }

        // Sort files within each album by track number using the CACHED metadata.
        foreach ($albumMap as $key => $albumData) {
            unset($albumData); // Suppress unused warning
            usort($albumMap[$key]['files'], function (array $a, array $b): int {
                $trackA = is_numeric($a['meta']['track_number'] ?? null) ? (int)$a['meta']['track_number'] : 0;
                $trackB = is_numeric($b['meta']['track_number'] ?? null) ? (int)$b['meta']['track_number'] : 0;

                // If track numbers are equal, compare by filename
                if ($trackA === $trackB) {
                    return strcmp($a['file']->getFilename(), $b['file']->getFilename());
                }

                return $trackA - $trackB;
            });
        }

        return $albumMap;
    }

    /**
     * Reads metadata from an audio file: getID3 (native, fast) first, then
     * ffprobe, then a filename-derived fallback that always succeeds.
     *
     * @param string $path Absolute filesystem path
     * @return array<string, mixed> Metadata including artist, album, title, track_number, disc_number, duration
     */
    private function probeMetadata(string $path): array
    {
        $viaId3 = $this->probeViaGetId3($path);
        if ($viaId3 !== null) {
            return $viaId3;
        }

        $viaFfprobe = $this->probeViaFfprobe($path);
        if ($viaFfprobe !== null) {
            return $viaFfprobe;
        }

        return $this->fallbackMetadataFromFilename($path);
    }

    /**
     * Reads tags natively with getID3.
     *
     * @param string $path Absolute filesystem path
     * @return array<string, mixed>|null Mapped metadata, or null when getID3 read
     *                                   nothing usable (so a fallback should run).
     */
    protected function probeViaGetId3(string $path): ?array
    {
        try {
            $info = $this->getId3Reader()->analyze($path);
            if (!is_array($info)) {
                return null;
            }

            $comments = isset($info['comments']) && is_array($info['comments']) ? $info['comments'] : [];

            return $this->mapId3Comments($comments, $info);
        } catch (\Throwable $e) {
            $this->logger->debug('getID3 read failed, will try ffprobe', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Maps a getID3 `comments` block (its format-agnostic merged tag view) onto
     * the scanner's canonical metadata shape.
     *
     * @param array<string, mixed> $comments getID3 `$info['comments']`
     * @param array<string, mixed> $info     Full getID3 analyze() result (for playtime)
     * @return array<string, mixed>|null Canonical metadata, or null when no
     *                                   identifying tag (artist/album/title) is present.
     */
    protected function mapId3Comments(array $comments, array $info): ?array
    {
        $artist = $this->firstComment($comments, 'artist');
        $albumArtist = $this->firstComment($comments, 'band', 'album_artist', 'albumartist');
        $album = $this->firstComment($comments, 'album');
        $title = $this->firstComment($comments, 'title');

        // No identifying tag → let ffprobe try, then filename fallback.
        if (($artist ?? $albumArtist) === null && $album === null && ($title === null || $title === '')) {
            return null;
        }

        $duration = 0;
        if (isset($info['playtime_seconds']) && is_numeric($info['playtime_seconds'])) {
            $duration = (int)floor((float)$info['playtime_seconds']);
        }

        return [
            'artist' => $artist ?? $albumArtist,
            'album' => $album,
            'title' => $title,
            'track_number' => $this->parseLeadingInt($this->firstComment($comments, 'track_number', 'track')),
            'disc_number' => $this->parseLeadingInt($this->firstComment($comments, 'part_of_a_set', 'disc')) ?? 1,
            'duration_secs' => $duration,
            'year' => $this->parseYear($this->firstComment($comments, 'year', 'date', 'recording_time')),
            'genre' => $this->firstComment($comments, 'genre'),
        ];
    }

    /**
     * Returns the first non-empty string value among the given comment keys.
     *
     * @param array<string, mixed> $comments getID3 comments block (values are arrays)
     * @param string               ...$keys  Candidate keys, in priority order
     * @return string|null
     */
    private function firstComment(array $comments, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $comments[$key] ?? null;
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Parses a leading integer from a raw tag value, tolerating the "3/12"
     * (position/total) form used for track and disc numbers.
     *
     * @param string|null $raw Raw tag value.
     * @return int|null Parsed integer, or null when not numeric.
     */
    private function parseLeadingInt(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $part = str_contains($raw, '/') ? explode('/', $raw, 2)[0] : $raw;
        $part = trim($part);

        return is_numeric($part) ? (int)$part : null;
    }

    /**
     * Extracts a 4-digit year from a raw date tag.
     *
     * @param string|null $raw Raw tag value.
     * @return int|null Parsed year, or null when none is present.
     */
    private function parseYear(?string $raw): ?int
    {
        if ($raw !== null && preg_match('/(\d{4})/', $raw, $matches) === 1) {
            return (int)$matches[1];
        }

        return null;
    }

    /**
     * Reads metadata from an audio file using FFprobe (fallback path).
     *
     * @param string $path Absolute filesystem path
     * @return array<string, mixed>|null Metadata, or null when ffprobe read
     *                                   nothing usable (so filename fallback runs).
     */
    protected function probeViaFfprobe(string $path): ?array
    {
        try {
            $probeResult = $this->ffmpeg->probe($path);

            if ($probeResult === null) {
                return null;
            }

            // Extract format-level tags
            $format = $probeResult['format'] ?? [];
            $tags = $format['tags'] ?? [];

            if (!is_array($tags)) {
                return null;
            }

            $trackNumber = $this->parseLeadingInt(is_string($tags['track'] ?? null) ? $tags['track'] : null);
            $discNumber = $this->parseLeadingInt(is_string($tags['disc'] ?? null) ? $tags['disc'] : null) ?? 1;

            $durationSecs = 0;
            if (isset($format['duration']) && is_numeric($format['duration'])) {
                $durationSecs = (int)floor((float)$format['duration']);
            }

            $year = $this->parseYear(is_string($tags['date'] ?? null) ? $tags['date'] : null);

            $artist = is_string($tags['artist'] ?? null)
                ? $tags['artist']
                : (is_string($tags['album_artist'] ?? null) ? $tags['album_artist'] : null);

            return [
                'artist' => $artist,
                'album' => is_string($tags['album'] ?? null) ? $tags['album'] : null,
                'title' => is_string($tags['title'] ?? null) ? $tags['title'] : null,
                'track_number' => $trackNumber,
                'disc_number' => $discNumber,
                'duration_secs' => $durationSecs,
                'year' => $year,
                'genre' => is_string($tags['genre'] ?? null) ? $tags['genre'] : null,
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('FFprobe failed, falling back to filename parsing', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extracts basic metadata from filename when tag reading fails.
     *
     * @param string $path Absolute filesystem path
     * @return array<string, mixed> Basic metadata
     */
    private function fallbackMetadataFromFilename(string $path): array
    {
        $filename = pathinfo($path, PATHINFO_FILENAME);

        return [
            'artist' => null,
            'album' => null,
            'title' => $filename,
            'track_number' => null,
            'disc_number' => 1,
            'duration_secs' => 0,
            'year' => null,
            'genre' => null,
        ];
    }

    /**
     * Lazily constructs the native getID3 reader (disabling the expensive
     * md5/sha1 hashing we do not need for tag harvesting).
     *
     * `protected` so tests can inject a fake reader.
     */
    protected function getId3Reader(): \getID3
    {
        if ($this->id3Reader === null) {
            $reader = new \getID3();
            $reader->option_md5_data = false;
            $reader->option_md5_data_source = false;
            $reader->option_sha1_data = false;
            $reader->encoding = 'UTF-8';
            $this->id3Reader = $reader;
        }

        return $this->id3Reader;
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
        if (in_array(strtolower($filename), $skipPatterns, true)) {
            return true;
        }

        return false;
    }

    /**
     * Upserts an artist into the database with a corresponding media_item.
     *
     * @param string $name Artist name
     * @param array<string, array{id:int, media_item_id:int|null}> $cache Artist cache to avoid duplicate queries
     * @return array{id: int, media_item_id: int|null}|null Artist ID and media_item_id or null on failure
     */
    private function upsertArtist(string $name, array &$cache): ?array
    {
        // Check cache first (Early Exit)
        $cacheKey = strtolower($name);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        // Check if artist exists
        $existing = $this->db->query(
            "SELECT id, media_item_id FROM music_artists WHERE name = ?",
            [$name]
        );

        if (is_array($existing) && count($existing) > 0) {
            $firstRow = $existing[0];
            if (is_array($firstRow)) {
                $id = isset($firstRow['id']) && is_numeric($firstRow['id']) ? (int)$firstRow['id'] : 0;
                $mediaItemId = isset($firstRow['media_item_id']) && is_numeric($firstRow['media_item_id']) ?
                    (int)$firstRow['media_item_id'] : null;
                $cache[$cacheKey] = ['id' => $id, 'media_item_id' => $mediaItemId];
                return $cache[$cacheKey];
            }
        }

        // Generate sort name
        $sortName = $this->generateSortName($name);

        // Create media_item for artwork/metadata
        $mediaItemId = $this->createMediaItem('artist', $name);

        // Insert new artist
        $result = $this->db->query(
            "INSERT INTO music_artists (name, sort_name, media_item_id) VALUES (?, ?, ?)",
            [$name, $sortName, $mediaItemId > 0 ? $mediaItemId : null]
        );

        if ($result === false) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();
        $cache[$cacheKey] = ['id' => $id, 'media_item_id' => $mediaItemId > 0 ? $mediaItemId : null];

        $this->logger->debug('Upserted artist', ['id' => $id, 'name' => $name, 'media_item_id' => $mediaItemId]);

        return $cache[$cacheKey];
    }

    /**
     * Upserts an album into the database with a corresponding media_item.
     *
     * @param int $artistId Artist ID
     * @param int|null $artistMediaItemId Artist's media_item_id for linking
     * @param string $title Album title
     * @param int|null $year Release year
     * @param int $totalTracks Total number of tracks
     * @param array<string, array{id:int, media_item_id:int|null}> $cache Album cache key by "artistId|title"
     * @return array{id: int, media_item_id: int|null}|null Album ID and media_item_id or null on failure
     */
    private function upsertAlbum(
        int $artistId,
        ?int $artistMediaItemId,
        string $title,
        ?int $year,
        int $totalTracks,
        array &$cache
    ): ?array {
        unset($artistMediaItemId);

        // Check cache first (Early Exit)
        $cacheKey = $artistId . '|' . strtolower($title);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        // Check if album exists for this artist
        $existing = $this->db->query(
            "SELECT id, media_item_id FROM music_albums WHERE artist_id = ? AND title = ?",
            [$artistId, $title]
        );

        if (is_array($existing) && count($existing) > 0) {
            $firstRow = $existing[0];
            if (is_array($firstRow)) {
                $id = isset($firstRow['id']) && is_numeric($firstRow['id']) ? (int)$firstRow['id'] : 0;
                $mediaItemId = isset($firstRow['media_item_id']) && is_numeric($firstRow['media_item_id']) ?
                    (int)$firstRow['media_item_id'] : null;

                // Update existing album with new track count and year
                $this->db->query(
                    "UPDATE music_albums SET total_tracks = ?, year = COALESCE(?, year) WHERE id = ?",
                    [$totalTracks, $year, $id]
                );

                $cache[$cacheKey] = ['id' => $id, 'media_item_id' => $mediaItemId];
                return $cache[$cacheKey];
            }
        }

        // Generate sort title
        $sortTitle = $this->generateSortName($title);

        // Create media_item for artwork/metadata
        $mediaItemId = $this->createMediaItem('album', $title);

        // Insert new album
        $result = $this->db->query(
            "INSERT INTO music_albums (artist_id, media_item_id, title, sort_title, year, total_tracks)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$artistId, $mediaItemId > 0 ? $mediaItemId : null, $title, $sortTitle, $year, $totalTracks]
        );

        if ($result === false) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();
        $cache[$cacheKey] = ['id' => $id, 'media_item_id' => $mediaItemId > 0 ? $mediaItemId : null];

        $this->logger->debug('Upserted album', ['id' => $id, 'title' => $title, 'artist_id' => $artistId]);

        return $cache[$cacheKey];
    }

    /**
     * Upserts a track into the database with a corresponding media_item.
     *
     * @param int $albumId Album ID
     * @param int|null $albumMediaItemId Album's media_item_id for linking
     * @param int $artistId Artist ID (denormalized for queries)
     * @param SplFileInfo $file Audio file info
     * @param array<string, mixed> $metadata Tags already read during grouping (no re-probe)
     * @return string 'added', 'updated', or 'skipped'
     */
    private function upsertTrack(
        int $albumId,
        ?int $albumMediaItemId,
        int $artistId,
        SplFileInfo $file,
        array $metadata
    ): string {
        unset($albumMediaItemId);

        $path = $file->getPathname();

        $title = is_string($metadata['title'] ?? null) && $metadata['title'] !== ''
            ? $metadata['title']
            : $file->getBasename('.' . $file->getExtension());
        $trackNumber = is_numeric($metadata['track_number'] ?? null) ? (int)$metadata['track_number'] : 1;
        $discNumber = is_numeric($metadata['disc_number'] ?? null) ? (int)$metadata['disc_number'] : 1;
        $durationSecs = is_numeric($metadata['duration_secs'] ?? null) ? (int)$metadata['duration_secs'] : 0;

        // Create media_item for the track stream
        $mediaItemId = $this->createMediaItem('track', $title, $path);

        // Check if track exists by media_item_id
        $existing = $this->db->query(
            "SELECT id, title, track_number, disc_number, duration_secs FROM music_tracks WHERE media_item_id = ?",
            [$mediaItemId]
        );

        if (is_array($existing) && count($existing) > 0) {
            $existingTrack = $existing[0];
            if (is_array($existingTrack)) {
                $existingId = isset($existingTrack['id']) && is_numeric($existingTrack['id']) ?
                    (int)$existingTrack['id'] : 0;

                // Check if anything changed
                $existingTitle = is_string($existingTrack['title'] ?? null) ? $existingTrack['title'] : '';
                $existingTrackNum = isset($existingTrack['track_number']) &&
                    is_numeric($existingTrack['track_number']) ? (int)$existingTrack['track_number'] : 1;
                $existingDiscNum = isset($existingTrack['disc_number']) && is_numeric($existingTrack['disc_number']) ?
                    (int)$existingTrack['disc_number'] : 1;
                $existingDuration = isset($existingTrack['duration_secs']) &&
                    is_numeric($existingTrack['duration_secs']) ? (int)$existingTrack['duration_secs'] : 0;

                if (
                    $existingTitle === $title
                    && $existingTrackNum === $trackNumber
                    && $existingDiscNum === $discNumber
                    && $existingDuration === $durationSecs
                ) {
                    return 'skipped';
                }

                // Update existing track
                $this->db->query(
                    "UPDATE music_tracks SET title = ?, track_number = ?, disc_number = ?, duration_secs = ?
                     WHERE id = ?",
                    [$title, $trackNumber, $discNumber, $durationSecs, $existingId]
                );

                return 'updated';
            }
        }

        // Insert new track
        $result = $this->db->query(
            "INSERT INTO music_tracks
             (media_item_id, album_id, artist_id, title, track_number, disc_number, duration_secs)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$mediaItemId, $albumId, $artistId, $title, $trackNumber, $discNumber, $durationSecs]
        );

        if ($result === false) {
            return 'skipped';
        }

        $this->logger->debug('Upserted track', ['album_id' => $albumId, 'title' => $title,
            'media_item_id' => $mediaItemId]);

        return 'added';
    }

    /**
     * Creates a media_item entry for a music entity.
     *
     * The `type` column is a strict ENUM whose audio members are `artist`,
     * `album`, and `track` — so the sub-type IS the media_items type. (A
     * `music_`-prefixed value is not a valid ENUM member and, under
     * STRICT_TRANS_TABLES, a hard "Data truncated" error.) The finer-grained
     * label is preserved in `metadata_json.sub_type`.
     *
     * @param string $subType Subtype: 'artist', 'album', or 'track' (also the media_items type)
     * @param string $name Display name
     * @param string|null $path File path (for tracks)
     * @return int The media_item ID (0 on failure)
     */
    private function createMediaItem(string $subType, string $name, ?string $path = null): int
    {
        $type = $subType;
        $metadata = [
            'sub_type' => $subType,
            'name' => $name,
        ];

        try {
            $result = $this->db->query(
                "INSERT INTO media_items (type, name, path, metadata_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())",
                [$type, $name, $path ?? '', json_encode($metadata)]
            );

            if ($result === false) {
                $this->logger->error('Failed to create media_item', ['type' => $type, 'name' => $name]);
                return 0;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create media_item', [
                'type' => $type,
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        return (int)$this->db->lastInsertId();
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
        $stripped = preg_replace('/^(the|a|an)\s+/i', '', $name);
        return trim((string)$stripped);
    }
}
