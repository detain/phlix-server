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
use Phlix\Common\Uuid;
use Phlix\Media\Library\Dto\LibraryRow;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Music\MusicLibraryType;
use Phlix\Media\Music\BookLibraryType;
use Phlix\Media\Music\AudiobookLibraryType;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Theming\ThemeMediaFinder;
use Phlix\Theming\ThemeMediaRepository;
use Workerman\MySQL\Connection;

/**
 * LibraryManager handles media library CRUD operations and scanning coordination.
 *
 * This class provides the main interface for managing media libraries including
 * creation, updates, deletion, and scanning operations. It coordinates between
 * the database, media scanner, and folder watcher components.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Manages media library operations including creation, updates, deletion, and scanning
 * @see MediaScanner For media file scanning functionality
 * @see FolderWatcher For filesystem change detection
 * @see ItemRepository For media item persistence
 */
class LibraryManager
{
    /** @var StructuredLogger Logger instance for structured logging */
    private StructuredLogger $logger;

    /** @var Connection Database connection for persistence */
    private Connection $db;

    /** @var MediaScanner Scanner for discovering media files */
    private MediaScanner $scanner;

    /** @var FolderWatcher Watcher for detecting filesystem changes */
    private FolderWatcher $watcher;

    /** @var ThemeMediaFinder|null Finder for theme media files */
    private ?ThemeMediaFinder $themeMediaFinder = null;

    /** @var ThemeMediaRepository|null Repository for theme media caching */
    private ?ThemeMediaRepository $themeMediaRepository = null;

    /** @var MusicLibraryService Service for music library scanning */
    private MusicLibraryService $musicLibraryService;

    /**
     * @var ItemRepository|null Item persistence used by the fine-grained
     * maintenance ops (`clear_metadata` / `clear_artwork` / `delete_all`).
     * Optional/nullable for back-compat with callers (and unit tests)
     * constructed before this dependency existed; the ops that need it throw a
     * clear {@see \RuntimeException} when it is absent rather than silently no-op.
     */
    private ?ItemRepository $itemRepository;

    /**
     * @var ArtworkStorage|null Local artwork cache used by the `clear_artwork`
     * maintenance op to delete each item's cached images. Optional/nullable for
     * back-compat; {@see self::clearArtwork()} throws when it is absent.
     */
    private ?ArtworkStorage $artworkStorage;

    /**
     * Provider-fetched `metadata_json` keys that {@see self::clearMetadata()}
     * STRIPS, resetting an item to its filesystem-derived basics so a later
     * `metadata` / `metadata_refresh` job can re-fetch cleanly.
     *
     * This is a DENYLIST on purpose: it is more conservative than an allowlist
     * because any key NOT listed here (e.g. a future provider field, or a
     * filesystem/user-derived key we did not anticipate) is PRESERVED. The keys
     * below are the ones written by the metadata resolvers / matcher — every
     * {@see \Phlix\Media\Metadata\Resolution\SourceRecord::CANONICAL_FIELDS}
     * value plus the extra provider keys the TMDB/TVDB/IMDb resolvers emit
     * (still images, votes, ids, dates, provider-supplied titles, theme audio).
     *
     * DELIBERATELY PRESERVED (filesystem / probe / user derived, so NOT listed):
     *   - `name` / `title` — filename-parsed display title
     *   - `year`, `season`, `episode`, `part` — parsed from the filename
     *   - `canonical_key` — filesystem-derived dedup key
     *   - `source` — technical ffprobe summary (width/height/codecs)
     *   - `duration_seconds`, `streams` — technical probe output
     *
     * @var list<string>
     */
    private const PROVIDER_METADATA_KEYS = [
        // Artwork / media URLs (local copies are dropped by clear_artwork; the
        // remote/derived URLs are metadata text dropped here).
        'poster_url',
        'poster_path',
        'poster_srcset',
        'backdrop_url',
        // Must be stripped alongside `backdrop_url`: `MediaItemShaper::shape()`
        // PREFERS a stored `backdrop_srcset` over deriving one, so leaving it behind
        // would emit `backdrop_url = null` next to a live srcset still pointing at
        // artwork `clear_artwork` may already have deleted — and per HTML a bare
        // `srcset` is honoured, so the client would render the stale candidate
        // instead of the no-artwork fallback.
        'backdrop_srcset',
        'backdrop_path',
        'logo_url',
        'logo_path',
        'still_url',
        'still_path',
        // Trailers.
        'trailer_url',
        'trailer_key',
        'trailer_site',
        // Descriptive text.
        'overview',
        'tagline',
        'homepage',
        // People / companies.
        'cast',
        'crew',
        'actors',
        'director',
        'production_companies',
        'studio',
        'networks',
        // Taxonomy.
        'genres',
        'tags',
        // Ratings / certifications.
        'official_rating',
        'rating',
        'content_rating',
        'certification',
        // Votes / popularity.
        'vote_average',
        'vote_count',
        'imdb_rating',
        'imdb_votes',
        'popularity',
        // Provider-sourced runtime (the probe duration lives under the preserved
        // `duration_seconds` key instead).
        'runtime',
        // External identifiers.
        'tmdb_id',
        'imdb_id',
        'tvdb_id',
        'external_ids',
        // Provider dates.
        'release_date',
        'first_air_date',
        'air_date',
        // Provider-supplied alternate titles.
        'original_title',
        'original_name',
        // Series-level provider counts / status.
        'status',
        'number_of_seasons',
        'number_of_episodes',
        'spoken_languages',
        // Theme audio resolved at match time.
        'theme_audio_url',
    ];

    /** @var array<int, array<string, mixed>>|null Cached libraries list */
    private static ?array $cachedLibraries = null;

    /** @var int|null Timestamp when libraries cache was loaded */
    private static ?int $librariesCacheTimestamp = null;

    /** @var int Cache TTL in seconds (60 seconds) */
    private const LIBRARIES_CACHE_TTL = 60;

    /**
     * Page size for the item-by-item maintenance ops (`clear_metadata` /
     * `clear_artwork`), so a large library is processed in bounded batches rather
     * than loaded whole into a resident-memory worker.
     */
    private const MAINTENANCE_PAGE_SIZE = 500;

    /**
     * Constructor for LibraryManager.
     *
     * @param Connection $db Database connection for library persistence
     * @param MediaScanner $scanner Scanner for discovering media files in directories
     * @param FolderWatcher $watcher Watcher for detecting filesystem changes
     * @param MusicLibraryService $musicLibraryService Service for music library scanning
     * @param StructuredLogger|null $logger Optional custom logger, creates default if not provided
     * @param ItemRepository|null $itemRepository Optional item persistence for the
     *     fine-grained maintenance ops (clear_metadata / clear_artwork /
     *     delete_all). Nullable for back-compat; those ops throw when it is absent.
     * @param ArtworkStorage|null $artworkStorage Optional local artwork cache for
     *     the clear_artwork op. Nullable for back-compat.
     */
    public function __construct(
        Connection $db,
        MediaScanner $scanner,
        FolderWatcher $watcher,
        MusicLibraryService $musicLibraryService,
        ?StructuredLogger $logger = null,
        ?ItemRepository $itemRepository = null,
        ?ArtworkStorage $artworkStorage = null
    ) {
        $this->db = $db;
        $this->scanner = $scanner;
        $this->watcher = $watcher;
        $this->musicLibraryService = $musicLibraryService;
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->itemRepository = $itemRepository;
        $this->artworkStorage = $artworkStorage;
    }

    /**
     * Creates a default structured logger for the media subsystem.
     *
     * @return StructuredLogger A configured logger instance writing to temp directory
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlix_media_' . uniqid();
        mkdir($tempDir, 0755, true);

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/manager.log',
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
     * Creates a new media library (metadata only; does NOT scan inline).
     *
     * The initial scan is intentionally NOT run here — it can take minutes for
     * large folders, which would block the create HTTP request and freeze the
     * admin form. The create endpoint enqueues an async scan job (processed by
     * {@see LibraryScanWorker}) instead, so create returns immediately and the
     * UI shows live scan-status. {@see scanLibrary()} still does the actual work
     * when the worker claims the job.
     *
     * @param string $name Human-readable name for the library
     * @param string $type Media type (e.g., 'video', 'audio', 'image')
     * @param array<string> $paths Array of filesystem paths to scan for media
     * @param array<string, mixed> $options Optional library configuration options
     * @return string The generated unique identifier for the new library
     * @throws \InvalidArgumentException If database insert fails
     *
     * @example
     * ```php
     * $libraryId = $manager->createLibrary('Movies', 'video', ['/mnt/media/movies'], ['scan_interval' => 3600]);
     * ```
     */
    public function createLibrary(string $name, string $type, array $paths, array $options = []): string
    {
        $id = $this->generateUuid();

        $this->db->query(
            "INSERT INTO libraries (id, name, type, paths, options) VALUES (?, ?, ?, ?, ?)",
            [$id, $name, $type, json_encode($paths), json_encode($options)]
        );

        // Invalidate libraries cache since list has changed
        self::$cachedLibraries = null;
        self::$librariesCacheTimestamp = null;

        $this->logger->info('Library created', ['library_id' => $id, 'name' => $name, 'type' => $type]);

        // Start watching for changes. (The initial scan is enqueued as a
        // background job by the caller — NOT run inline — see the docblock.)
        $this->watcher->watch($id, $paths);

        return $id;
    }

    /**
     * Retrieves a library by its unique identifier.
     *
     * @param string $id The library's unique identifier
     * @return array<string, mixed>|null Library data array with 'paths' and 'options'
     *     decoded, or null if not found
     *
     * @example
     * ```php
     * $library = $manager->getLibrary('abc-123');
     * // Returns: ['id' => 'abc-123', 'name' => 'Movies', 'type' => 'video', 'paths' => ['/mnt/media'], 'options' =>
     * [...]]
     * ```
     */
    public function getLibrary(string $id): ?array
    {
        $row = $this->fetchLibraryRow($id);
        return $row?->toArray();
    }

    /**
     * Fetches a library and returns a typed DTO.
     *
     * @param string $id The library's unique identifier.
     */
    private function fetchLibraryRow(string $id): ?LibraryRow
    {
        $result = $this->db->query("SELECT * FROM libraries WHERE id = ?", [$id]);
        if (!is_array($result) || count($result) === 0) {
            return null;
        }
        $first = $result[0] ?? null;
        if (!is_array($first)) {
            return null;
        }
        $row = [];
        foreach ($first as $key => $value) {
            if (is_string($key)) {
                $row[$key] = $value;
            }
        }
        return LibraryRow::fromRow($row);
    }

    /**
     * Whether a library is a series library that stores each series in its own
     * top-level directory (so the directory name is the authoritative series
     * title/year for hierarchy grouping AND TMDB matching).
     *
     * Returns false for a missing library, a non-series library, or when the
     * `series_per_directory` option is absent/false.
     *
     * @param string $id The library's unique identifier.
     */
    public function seriesPerDirectory(string $id): bool
    {
        $row = $this->fetchLibraryRow($id);
        if ($row === null || $row->type !== 'series') {
            return false;
        }
        return $row->seriesPerDirectory();
    }

    /**
     * Retrieves all libraries ordered by display order and name.
     *
     * @return array<int, array<string, mixed>> Array of library data arrays with decoded paths and options
     *
     * @example
     * ```php
     * $libraries = $manager->getAllLibraries();
     * ```
     */
    public function getAllLibraries(): array
    {
        $now = time();

        // Return cached libraries if still valid
        if (
            self::$cachedLibraries !== null
            && self::$librariesCacheTimestamp !== null
            && ($now - self::$librariesCacheTimestamp) < self::LIBRARIES_CACHE_TTL
        ) {
            return self::$cachedLibraries;
        }

        $results = $this->db->query("SELECT * FROM libraries ORDER BY display_order, name");
        if (!is_array($results)) {
            self::$cachedLibraries = [];
            self::$librariesCacheTimestamp = $now;
            return [];
        }

        $out = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = [];
            foreach ($row as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }
            $out[] = LibraryRow::fromRow($normalized)->toArray();
        }

        self::$cachedLibraries = $out;
        self::$librariesCacheTimestamp = $now;
        return $out;
    }

    /**
     * Updates library properties (name, paths, or options).
     *
     * @param string $id The library's unique identifier
     * @param array<string, mixed> $data Associative array of fields to update
     * @return void
     *
     * @example
     * ```php
     * $manager->updateLibrary('abc-123', ['name' => 'New Name', 'options' => ['scan_interval' => 7200]]);
     * ```
     */
    public function updateLibrary(string $id, array $data): void
    {
        $sets = [];
        $values = [];

        if (isset($data['name'])) {
            $sets[] = 'name = ?';
            $values[] = $data['name'];
        }
        if (isset($data['paths'])) {
            $sets[] = 'paths = ?';
            $values[] = json_encode($data['paths']);
        }
        if (isset($data['options'])) {
            $sets[] = 'options = ?';
            $values[] = json_encode($data['options']);
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $id;
        $this->db->query(
            "UPDATE libraries SET " . implode(', ', $sets) . " WHERE id = ?",
            $values
        );

        // Invalidate libraries cache since list has changed
        self::$cachedLibraries = null;
        self::$librariesCacheTimestamp = null;

        $this->logger->info('Library updated', ['library_id' => $id]);
    }

    /**
     * Deletes a library and optionally its associated media items.
     *
     * @param string $id The library's unique identifier
     * @return void
     */
    public function deleteLibrary(string $id): void
    {
        $this->db->query("DELETE FROM libraries WHERE id = ?", [$id]);

        // Invalidate libraries cache since list has changed
        self::$cachedLibraries = null;
        self::$librariesCacheTimestamp = null;

        $this->logger->info('Library deleted', ['library_id' => $id]);
    }

    /**
     * Initiates a scan of all paths in the library to discover media files.
     *
     * **Returns a {@see ScanResult} (S96(b)).** It used to return `void`, so
     * {@see LibraryScanWorker} had nothing to write and `library_scan_jobs
     * .items_added` stayed 0 for every successful scan — the reason "is this scan
     * writing anything?" had to be answered by reading `music_artists.created_at`
     * timestamps. Only the counters each scanner actually knows are filled:
     *
     *  - `added` — from {@see MediaScanner::scan()} (video/series/photo/book/
     *    audiobook) or summed over the per-path {@see ScanResult}s of the music
     *    scanner. For a music library that is new TRACKS; container artist/album rows
     *    are not counted (contrast {@see self::rescanLibrary()}, whose `added` is a
     *    row-count delta over ALL types — the two answers differ by design and both
     *    are documented where they are written).
     *  - `failed` — files a scanner could not index ({@see ScanResult::$failed});
     *    music only today, since the video scanner has no per-file failure counter.
     *  - `scanned` — files walked, when a progress sink made the count available.
     *
     * `updated`/`removed` are deliberately left at 0: this method neither prunes nor
     * has a definition of "updated" the video path can supply, and
     * {@see LibraryScanWorker} must never write `items_updated` from here — that
     * column doubles as the progress NUMERATOR, so overwriting it with a semantic
     * "updated" count would drop the UI percentage to ~0 % at completion.
     *
     * @param string $libraryId The library's unique identifier
     * @param callable|null $onProgress Optional
     *        `(int $processed, int $total, string $currentPath, array $counts): void`
     *        sink. The 4th argument is the live counter snapshot for scanners that
     *        report one (music); a 3-parameter sink is unaffected.
     * @return ScanResult Counters this scan can honestly report (see above).
     * @throws \InvalidArgumentException If the library does not exist
     *
     * @example
     * ```php
     * $manager->scanLibrary('abc-123');
     * ```
     */
    public function scanLibrary(string $libraryId, ?callable $onProgress = null): ScanResult
    {
        $library = $this->fetchLibraryRow($libraryId);
        if ($library === null) {
            throw new \InvalidArgumentException("Library not found: $libraryId");
        }

        $this->logger->info('Starting library scan', ['library_id' => $libraryId, 'name' => $library->name]);

        $result = new ScanResult();

        // Route music libraries through MusicLibraryManager for tag harvesting
        if ($library->type === 'music') {
            return $this->scanMusicLibrary($libraryId, $library, $onProgress);
        }

        // Route photo libraries through PhotoLibraryManager for EXIF extraction
        if ($library->type === 'photo') {
            $result->added = $this->scanPhotoLibrary($libraryId, $library);
            return $result;
        }

        // Route book libraries through BookLibraryManager for EPUB/PDF/CBZ extraction
        if ($library->type === 'book') {
            $result->added = $this->scanBookLibrary($libraryId, $library);
            return $result;
        }

        // Route audiobook libraries through AudiobookScanner for M4B chapter extraction
        if ($library->type === 'audiobook') {
            $result->added = $this->scanAudiobookLibrary($libraryId, $library);
            return $result;
        }

        $seriesPerDirectory = $library->type === 'series' && $library->seriesPerDirectory();

        // S33: per-library toggle for TMDB box-set auto-collection generation.
        // Default (flag absent) is enabled, preserving the historical
        // unconditional behaviour; an explicit stored `false` skips the scanner's
        // per-item collection-sync block for this library.
        $autoCollectionsEnabled = $library->autoCollectionsEnabled();

        // When a progress sink is supplied, pre-count the media files across all
        // paths so the callback can report a real percentage (processed/total),
        // then stream one tick per processed file. The count walk is cheap (no
        // DB / metadata) relative to the scan itself.
        $onFile = null;
        $processed = 0;
        if ($onProgress !== null) {
            $total = 0;
            foreach ($library->paths as $path) {
                if (is_dir($path)) {
                    $total += $this->scanner->countFiles($path, $library->type);
                }
            }
            $onFile = function (string $currentPath) use (&$processed, $total, $onProgress): void {
                $processed++;
                $onProgress($processed, $total, $currentPath);
            };
        }

        foreach ($library->paths as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Library path does not exist', ['path' => $path]);
                continue;
            }
            $result->added += $this->scanner->scan(
                $libraryId,
                $path,
                $library->type,
                $seriesPerDirectory,
                $onFile,
                $autoCollectionsEnabled
            );
        }

        $result->scanned = $processed;

        $this->logger->info('Library scan complete', [
            'library_id' => $libraryId,
            'added' => $result->added,
            'scanned' => $result->scanned,
        ]);

        // Scan for theme media after library content scan
        $this->scanThemeMedia($libraryId);

        return $result;
    }

    /**
     * Scans a music library using MusicLibraryService for tag harvesting.
     *
     * Music scanning uses {@see MusicLibraryScanner} for native (getID3) tag
     * harvesting rather than the video {@see MediaScanner}. When a progress sink
     * is supplied we pre-count the audio files across every path (the cheap walk
     * MusicLibraryService::countFiles() runs, no tag reading) so the scanner's
     * per-path ticks can be offset into a single library-wide `processed/total`
     * percentage — matching the video path and the shape the scan-job row expects.
     *
     * **The library-wide counters are accumulated the same way the percentage is
     * (S96(b)).** Each `scanDirectory()` call reports counters for ITS path only, so
     * a two-path library would otherwise make `items_added` jump backwards to 0 when
     * the second path starts. The returned per-path {@see ScanResult}s are summed
     * into the value this method returns, and the live 4th-argument snapshot the
     * scanner streams is offset by the same running base before it is forwarded.
     *
     * @param string        $libraryId  The library's unique identifier
     * @param LibraryRow    $library    The library data
     * @param callable|null $onProgress Optional
     *        `(int $processed, int $total, string $currentPath, array $counts): void` sink.
     * @return ScanResult Summed counters across every scanned path.
     */
    private function scanMusicLibrary(
        string $libraryId,
        LibraryRow $library,
        ?callable $onProgress = null
    ): ScanResult {
        $paths = $library->paths;
        $result = new ScanResult();

        if ($onProgress === null) {
            foreach ($paths as $path) {
                if (!is_dir($path)) {
                    $this->logger->warning('Music library path does not exist', ['path' => $path]);
                    continue;
                }
                $this->accumulate($result, $this->musicLibraryService->scanDirectory($path, null, $libraryId));
            }
            $this->logMusicScanComplete($libraryId, $result);
            return $result;
        }

        // Pre-count each path once: the sum is the denominator, and each path's
        // count offsets the running total as we advance from path to path.
        $counts = [];
        $total = 0;
        foreach ($paths as $i => $path) {
            $count = is_dir($path) ? $this->musicLibraryService->countFiles($path) : 0;
            $counts[$i] = $count;
            $total += $count;
        }

        $base = 0;
        $onScanProgress = function (
            int $processedInPath,
            int $totalInPath,
            string $currentPath,
            array $pathCounts = []
        ) use (
            &$base,
            $result,
            $total,
            $onProgress
        ): void {
            unset($totalInPath);
            // Offset the path-local counters by everything already scanned, exactly
            // as $base offsets $processedInPath.
            $live = [
                'added' => $result->added + (int) ($pathCounts['added'] ?? 0),
                'updated' => $result->updated + (int) ($pathCounts['updated'] ?? 0),
                'failed' => $result->failed + (int) ($pathCounts['failed'] ?? 0),
            ];
            $onProgress($base + $processedInPath, $total, $currentPath, $live);
        };

        foreach ($paths as $i => $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Music library path does not exist', ['path' => $path]);
                continue;
            }
            $this->accumulate(
                $result,
                $this->musicLibraryService->scanDirectory($path, $onScanProgress, $libraryId)
            );
            $base += $counts[$i];
        }

        $this->logMusicScanComplete($libraryId, $result);

        return $result;
    }

    /**
     * Adds one path's counters into the library-wide total.
     *
     * `durationMs` is deliberately NOT summed — the caller times the whole scan.
     *
     * @param ScanResult $total Accumulator, mutated in place.
     * @param ScanResult $one   One path's result.
     * @return void
     */
    private function accumulate(ScanResult $total, ScanResult $one): void
    {
        $total->scanned += $one->scanned;
        $total->added += $one->added;
        $total->updated += $one->updated;
        $total->removed += $one->removed;
        $total->failed += $one->failed;
    }

    /**
     * Logs the music-scan completion line, at ERROR when files were lost (S96(a)+(f)).
     *
     * ⚠ ERROR, not warning (review r1 MED-2). It was `warning`, which meant the
     * library-wide "this scan lost files" line reached only `.logs/app.log` — the file
     * that also carries every per-entity `debug` line the same scan emits — while
     * `config/logger.php` gates the dedicated `.logs/error.log` at `error`. The level
     * now tracks actual data loss, matching {@see MusicLibraryScanner}'s per-path
     * summary and its per-album/per-track loss lines, so one grep of one clean file
     * answers "did the last scan lose anything?".
     *
     * @param string     $libraryId Library UUID.
     * @param ScanResult $result    Library-wide counters.
     * @return void
     */
    private function logMusicScanComplete(string $libraryId, ScanResult $result): void
    {
        $context = [
            'library_id' => $libraryId,
            'scanned' => $result->scanned,
            'added' => $result->added,
            'updated' => $result->updated,
            'failed' => $result->failed,
        ];

        if ($result->failed > 0) {
            $this->logger->error('Music library scan complete with failed files', $context);
            return;
        }

        $this->logger->info('Music library scan complete', $context);
    }

    /**
     * Scans a photo library using PhotoLibraryManager for EXIF extraction.
     *
     * @param string $libraryId The library's unique identifier
     * @param LibraryRow $library The library data
     * @return int Number of items added (S96(b) — reaches `items_added`).
     *
     * @since 0.16.0
     */
    private function scanPhotoLibrary(string $libraryId, LibraryRow $library): int
    {
        $added = 0;

        foreach ($library->paths as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Photo library path does not exist', ['path' => $path]);
                continue;
            }
            // Photo scanning is handled by PhotoLibraryManager which uses
            // PhotoScanner for EXIF metadata extraction.
            // For now, fall back to basic scanning.
            // NB: 'image' is the SCANNER's library-type label; the media_items.type
            // ENUM member is `photo` (see the type-ENUM landmine).
            $added += $this->scanner->scan($libraryId, $path, 'image');
        }

        $this->logger->info('Photo library scan complete', ['library_id' => $libraryId, 'added' => $added]);

        return $added;
    }

    /**
     * Scans a book library using BookScanner for EPUB/PDF/CBZ extraction.
     *
     * @param string $libraryId The library's unique identifier
     * @param LibraryRow $library The library data
     * @return int Number of items added (S96(b) — reaches `items_added`).
     *
     * @since 0.17.0
     */
    private function scanBookLibrary(string $libraryId, LibraryRow $library): int
    {
        $added = 0;

        // Book scanning is handled by BookScanner for EPUB content.opf,
        // PDF metadata, and CBZ ComicInfo.xml extraction.
        foreach ($library->paths as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Book library path does not exist', ['path' => $path]);
                continue;
            }
            // Use book type for scanner
            $added += $this->scanner->scan($libraryId, $path, 'book');
        }

        $this->logger->info('Book library scan complete', ['library_id' => $libraryId, 'added' => $added]);

        return $added;
    }

    /**
     * Scans an audiobook library using AudiobookScanner for M4B chapter extraction.
     *
     * @param string $libraryId The library's unique identifier
     * @param LibraryRow $library The library data
     * @return int Number of items added (S96(b) — reaches `items_added`).
     *
     * @since 0.18.0
     */
    private function scanAudiobookLibrary(string $libraryId, LibraryRow $library): int
    {
        $added = 0;

        // Audiobook scanning is handled by AudiobookScanner for M4B chpl atom
        // chapter extraction and metadata harvesting.
        foreach ($library->paths as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Audiobook library path does not exist', ['path' => $path]);
                continue;
            }
            // Use audiobook type for scanner
            $added += $this->scanner->scan($libraryId, $path, 'audiobook');
        }

        $this->logger->info('Audiobook library scan complete', ['library_id' => $libraryId, 'added' => $added]);

        return $added;
    }

    /**
     * Non-destructively rescans a library from the filesystem.
     *
     * **This method used to `DELETE FROM media_items WHERE library_id = ?` before
     * rescanning — which cascaded through the `ON DELETE CASCADE` foreign keys on
     * `user_item_data` (watch progress, favorites, ratings, watched status) and
     * the watch-history / continue-watching tables, PERMANENTLY erasing every
     * user's data (and all fetched TMDB metadata) for the library on every
     * rescan.** It no longer deletes first.
     *
     * Instead it:
     *  1. re-scans from disk exactly like {@see self::scanLibrary()} — the scanner
     *     upserts by path (race-safe `path_hash` unique index), so a file that
     *     still exists updates its EXISTING row IN PLACE, keeping the same UUID
     *     and therefore every `user_item_data` / watch row that references it; then
     *  2. prunes ONLY the items whose source file no longer exists on disk (so
     *     genuinely-removed media is cleaned up), plus any series/season container
     *     left empty by that pruning — see {@see self::pruneRemovedItems()}.
     *
     * `scanLibrary()` derives the configured paths from the library row and routes
     * music / photo / book / audiobook libraries to their specialised scanners, so
     * a single base rescan correctly refreshes every library type (including a
     * streamed per-file progress percentage when `$onProgress` is supplied).
     *
     * The `$paths` argument is accepted only for signature compatibility with the
     * media-specific subclass managers ({@see AudiobookLibraryManager},
     * {@see BookLibraryManager}) that scan an explicit path list; the base
     * implementation resolves paths from the library row itself.
     *
     * @param string        $libraryId  The library's unique identifier
     * @param array<string> $paths      Ignored by the base manager (see above)
     * @param callable|null $onProgress Optional `(processed, total, path, counts)` sink
     * @return ScanResult Result containing scan statistics. ⚠ `added`/`updated` here
     *         are ROW-COUNT DELTAS over every `media_items` type (so for music they
     *         include artist and album container rows), unlike
     *         {@see self::scanLibrary()}'s `added`, which is the scanner's own count
     *         of new leaf items. `failed` is passed straight through from the inner
     *         scan.
     *
     * @throws \InvalidArgumentException If the library does not exist
     */
    public function rescanLibrary(string $libraryId, array $paths = [], ?callable $onProgress = null): ScanResult
    {
        // Base manager derives paths from the library row inside scanLibrary().
        unset($paths);
        $startTime = microtime(true);

        // Resolve the library's configured root paths up front so pruning can
        // verify storage is actually present before deleting anything (see
        // pruneRemovedItems()). Fetching here (null-safe) does not change the
        // missing-library behaviour: scanLibrary() still throws below.
        $library = $this->fetchLibraryRow($libraryId);
        $rootPaths = $library !== null ? $library->paths : [];

        // Item count BEFORE the scan, so added/updated can be derived as deltas.
        $before = $this->countLibraryItems($libraryId);

        // NON-DESTRUCTIVE rescan: re-scan from the filesystem WITHOUT deleting
        // first. Surviving files keep their existing rows (and UUIDs) via the
        // scanner's upsert-by-path, so all cascading user data is preserved.
        $scan = $this->scanLibrary($libraryId, $onProgress);

        // Prune only items whose source file is gone from disk, plus any now-empty
        // series/season containers — but ONLY when the library storage is actually
        // accessible, so a temporarily-unmounted root does not wipe the library.
        $removed = $this->pruneRemovedItems($libraryId, $rootPaths);

        $after = $this->countLibraryItems($libraryId);

        // Items that existed before and were not pruned were re-scanned in place
        // (updated); the remainder of the new total are brand-new additions.
        $survivors = max(0, $before - $removed);

        $result = new ScanResult();
        $result->scanned = $after;
        $result->added = max(0, $after - $survivors);
        $result->updated = min($survivors, $after);
        $result->removed = $removed;
        // S96(f): carried straight through from the inner scan — a rescan that
        // skipped files must not report clean success just because the row-count
        // deltas above happen to balance.
        $result->failed = $scan->failed;
        $result->durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return $result;
    }

    /**
     * Prune ONLY the items whose source file is gone from disk (plus any
     * now-empty series/season containers), WITHOUT a full rescan.
     *
     * This is the non-destructive `prune` maintenance op. It reuses the exact
     * same {@see self::pruneRemovedItems()} pass rescan runs — with every
     * data-loss safety guard intact (stat-cache clear, accessible-root
     * computation, most-specific-root attribution, and the per-root presence
     * guard that refuses to bulk-delete a root with zero present items). Nothing
     * is scanned or re-fetched; this is purely the cleanup half of a rescan.
     *
     * @param string $libraryId The library's unique identifier.
     * @return int Total number of rows pruned (gone leaves + emptied containers).
     * @throws \InvalidArgumentException If the library does not exist.
     */
    public function pruneLibrary(string $libraryId): int
    {
        $library = $this->fetchLibraryRow($libraryId);
        if ($library === null) {
            throw new \InvalidArgumentException("Library not found: $libraryId");
        }

        // Delegate to the shared prune pass with the library's configured roots so
        // the per-root presence guard can verify storage is actually mounted
        // before deleting anything — identical safety to the rescan path.
        $removed = $this->pruneRemovedItems($libraryId, $library->paths);

        $this->logger->info('Library prune complete', [
            'library_id' => $libraryId,
            'removed' => $removed,
        ]);

        return $removed;
    }

    /**
     * Reset every item in a library to its filesystem-derived basics, so a later
     * `metadata` / `metadata_refresh` job can re-fetch cleanly (the
     * `clear_metadata` maintenance op).
     *
     * For each item this:
     *  1. strips the provider-fetched keys ({@see self::PROVIDER_METADATA_KEYS} —
     *     poster/backdrop/logo urls, trailers, overview, cast/crew, genres/tags,
     *     ratings/votes, still_url, external ids, provider dates/titles, …) from
     *     `metadata_json`, PRESERVING the filesystem/probe/user-derived keys
     *     (name/title/year/season/episode/canonical_key/source/duration/streams);
     *  2. NULLs the `metadata_refreshed_at` column so the item is treated as
     *     un-matched again; and
     *  3. clears the materialized `content_rating` column — done automatically by
     *     {@see ItemRepository::update()}, which re-derives that column from the
     *     (now rating-free) blob whenever `metadata_json` is written.
     *
     * The item ROWS themselves — their `path`, filename-derived title, type, and
     * series/season parent hierarchy — are preserved, as is ALL `user_item_data`
     * and watch history (no rows are deleted; the update never cascades). Genre
     * join-table rows are re-synced by {@see ItemRepository::update()} to match
     * the (now empty) genre set, keeping the derived index consistent.
     *
     * Iterates in bounded pages so a large library does not load every row into a
     * resident-memory worker at once. Ordering is stable (no title/sort_title is
     * touched and no rows are removed), so OFFSET paging is safe here.
     *
     * @param string        $libraryId  The library's unique identifier.
     * @param callable|null $onProgress Optional `(processed, total)` progress sink.
     * @return int Number of items reset.
     * @throws \RuntimeException          If no {@see ItemRepository} was injected.
     * @throws \InvalidArgumentException  If the library does not exist.
     */
    public function clearMetadata(string $libraryId, ?callable $onProgress = null): int
    {
        if ($this->itemRepository === null) {
            throw new \RuntimeException('clearMetadata requires an ItemRepository dependency');
        }

        $library = $this->fetchLibraryRow($libraryId);
        if ($library === null) {
            throw new \InvalidArgumentException("Library not found: $libraryId");
        }

        $total = $this->countLibraryItems($libraryId);
        $processed = 0;
        $offset = 0;

        while (true) {
            $items = $this->itemRepository->getByLibrary($libraryId, self::MAINTENANCE_PAGE_SIZE, $offset);
            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                $id = is_string($item['id'] ?? null) ? $item['id'] : '';
                if ($id === '') {
                    continue;
                }

                $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
                $stripped = self::stripProviderMetadata($metadata);

                // Writing metadata_json re-derives content_rating (now NULL, no
                // rating survives the strip) and re-syncs the genre join table;
                // metadata_refreshed_at is explicitly NULLed so the item counts
                // as un-matched for the next metadata job.
                $this->itemRepository->update($id, [
                    'metadata_json' => $stripped,
                    'metadata_refreshed_at' => null,
                ]);

                $processed++;
                if ($onProgress !== null) {
                    $onProgress($processed, $total);
                }
            }

            if (count($items) < self::MAINTENANCE_PAGE_SIZE) {
                break;
            }
            $offset += self::MAINTENANCE_PAGE_SIZE;
        }

        $this->logger->info('Library metadata cleared', [
            'library_id' => $libraryId,
            'items' => $processed,
        ]);

        return $processed;
    }

    /**
     * Delete the locally cached artwork for every item in a library, freeing disk
     * (the `clear_artwork` maintenance op). The next metadata match re-downloads
     * whatever artwork it needs.
     *
     * For each item this removes the item's artwork directory via
     * {@see ArtworkStorage::deleteItemArtwork()} (which jails the path through the
     * same sanitised `itemDir()` logic — no traversal) and, when an item's
     * `poster_url` / `logo_url` points at the LOCAL served artwork endpoint
     * (`/api/v1/artwork/…`), NULLs just those two keys so they re-derive on the
     * next match. Remote provider URLs and all other metadata text are left
     * untouched, and NO user data or watch history is affected.
     *
     * @param string        $libraryId  The library's unique identifier.
     * @param callable|null $onProgress Optional `(processed, total)` progress sink.
     * @return int Number of items whose artwork cache was cleared.
     * @throws \RuntimeException          If no {@see ArtworkStorage} was injected.
     * @throws \InvalidArgumentException  If the library does not exist.
     */
    public function clearArtwork(string $libraryId, ?callable $onProgress = null): int
    {
        if ($this->artworkStorage === null) {
            throw new \RuntimeException('clearArtwork requires an ArtworkStorage dependency');
        }
        if ($this->itemRepository === null) {
            throw new \RuntimeException('clearArtwork requires an ItemRepository dependency');
        }

        $library = $this->fetchLibraryRow($libraryId);
        if ($library === null) {
            throw new \InvalidArgumentException("Library not found: $libraryId");
        }

        $total = $this->countLibraryItems($libraryId);
        $processed = 0;
        $offset = 0;

        while (true) {
            $items = $this->itemRepository->getByLibrary($libraryId, self::MAINTENANCE_PAGE_SIZE, $offset);
            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                $id = is_string($item['id'] ?? null) ? $item['id'] : '';
                if ($id === '') {
                    continue;
                }

                // Remove the on-disk cache (path-jailed inside ArtworkStorage).
                $this->artworkStorage->deleteItemArtwork($id);

                // NULL only LOCAL poster/logo URLs so they re-derive on re-match;
                // remote URLs and every other metadata key are left untouched.
                $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
                $updated = self::clearLocalArtworkUrls($metadata);
                if ($updated !== null) {
                    $this->itemRepository->update($id, ['metadata_json' => $updated]);
                }

                $processed++;
                if ($onProgress !== null) {
                    $onProgress($processed, $total);
                }
            }

            if (count($items) < self::MAINTENANCE_PAGE_SIZE) {
                break;
            }
            $offset += self::MAINTENANCE_PAGE_SIZE;
        }

        $this->logger->info('Library artwork cleared', [
            'library_id' => $libraryId,
            'items' => $processed,
        ]);

        return $processed;
    }

    /**
     * DESTRUCTIVE: remove EVERY item in a library (the `delete_all` maintenance
     * op, i.e. the old rescan behaviour extracted into an explicit operation).
     *
     * Runs `DELETE FROM media_items WHERE library_id = ?`, which cascades through
     * the `ON DELETE CASCADE` foreign keys on `user_item_data` (watch progress,
     * favorites, ratings) and the watch-history tables — that cascade is the
     * INTENDED, explicit meaning of this op. It is deliberately gated behind an
     * explicit confirmation at the controller layer; the library ROW itself is
     * kept (only its items are removed), so a subsequent scan re-populates it.
     *
     * Delegates to {@see ItemRepository::deleteByLibrary()} when the repository
     * is wired (so the genre-facet cache + stats change are recorded), falling
     * back to a direct parameterised DELETE otherwise.
     *
     * @param string $libraryId The library's unique identifier.
     * @return int Number of items that existed (and were therefore deleted).
     * @throws \InvalidArgumentException If the library does not exist.
     */
    public function deleteAllItems(string $libraryId): int
    {
        $library = $this->fetchLibraryRow($libraryId);
        if ($library === null) {
            throw new \InvalidArgumentException("Library not found: $libraryId");
        }

        $count = $this->countLibraryItems($libraryId);

        if ($this->itemRepository !== null) {
            $this->itemRepository->deleteByLibrary($libraryId);
        } else {
            $this->db->query("DELETE FROM media_items WHERE library_id = ?", [$libraryId]);
        }

        $this->logger->warning('Library items deleted (delete_all)', [
            'library_id' => $libraryId,
            'removed' => $count,
        ]);

        return $count;
    }

    /**
     * Strip the provider-fetched keys ({@see self::PROVIDER_METADATA_KEYS}) from a
     * decoded `metadata_json` array, preserving every other (filesystem / probe /
     * user-derived, or simply unrecognised) key.
     *
     * @param array<string, mixed> $metadata Decoded metadata_json.
     * @return array<string, mixed> The metadata with provider keys removed.
     */
    private static function stripProviderMetadata(array $metadata): array
    {
        foreach (self::PROVIDER_METADATA_KEYS as $key) {
            unset($metadata[$key]);
        }

        return $metadata;
    }

    /**
     * NULL out `poster_url` / `logo_url` when (and only when) they reference the
     * LOCAL served-artwork endpoint, so they re-derive after the cache is dropped.
     *
     * @param array<string, mixed> $metadata Decoded metadata_json.
     * @return array<string, mixed>|null The mutated metadata when a local URL was
     *     cleared, or null when nothing changed (so the caller can skip the write).
     */
    private static function clearLocalArtworkUrls(array $metadata): ?array
    {
        $changed = false;
        foreach (['poster_url', 'logo_url'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && str_contains($value, '/api/v1/artwork/')) {
                unset($metadata[$key]);
                $changed = true;
            }
        }

        return $changed ? $metadata : null;
    }

    /**
     * Prunes items whose source file no longer exists on disk, then removes any
     * series/season containers left empty by that pruning.
     *
     * Leaf items (movies, episodes, tracks, books, …) carry a real filesystem
     * `path`; a synthetic container row (`series`/`season`) is addressed by a
     * `series:`/`season:` synthetic path (see {@see SeriesContainerNaming}) and is
     * NEVER checked against the filesystem — it is pruned in phase 2 only once it
     * has no remaining children, so a fully-removed show does not leave orphan
     * season/series shells behind.
     *
     * Pruning is data-loss-safe by design (mirroring mature media servers, which
     * never bulk-delete a folder that merely looks empty). A leaf is a prune
     * candidate only when {@see file_exists()} is false — but that same signal
     * fires for BOTH "the file was genuinely deleted" AND "the whole storage
     * volume is unreachable right now" (an unmounted NAS/SMB/USB share, whose
     * mountpoint DIRECTORY usually persists as an empty dir so `is_dir()` still
     * returns true and cannot distinguish the two). Deleting on that ambiguous
     * signal would cascade through the `ON DELETE CASCADE` foreign keys into
     * `user_item_data` (watch progress, favorites, ratings) and the watch-history
     * tables. The algorithm therefore:
     *  1. clears stat caches so is_dir()/file_exists()/realpath() reflect current
     *     on-disk state (a long-lived worker can hold stale stats across rescans);
     *  2. computes ACCESSIBLE roots (configured roots where {@see is_dir()} holds)
     *     — if NONE, pruning is skipped entirely (0 removed);
     *  3. attributes each leaf to its owning root by the MOST-SPECIFIC (longest)
     *     matching accessible-root prefix, so nested roots (`/mnt` plus an
     *     unmounted `/mnt/nas`) attribute items under `/mnt/nas` to that child
     *     root — not to the accessible `/mnt` parent. A leaf matching no
     *     accessible prefix has unavailable storage and is always kept;
     *  4. applies the PER-ROOT PRESENCE GUARD: a root's gone items are pruned only
     *     if that root has AT LEAST ONE currently-present item. A root with ZERO
     *     present items is skipped entirely — it is indistinguishable between an
     *     unmounted-mountpoint leftover and a legitimately-emptied folder, and we
     *     refuse to bulk-delete either.
     *
     * IMPORTANT intended tradeoff: a library (or root) that has been legitimately
     * fully emptied on disk will RETAIN its last items across a rescan — they are
     * never pruned here. Intentional full clears are performed by the separate,
     * explicit "delete all items" operation, NOT by rescan. This is the safe
     * price of never cascade-erasing user data on an ambiguous unmount.
     *
     * @param string        $libraryId The library's unique identifier
     * @param array<string> $rootPaths The library's configured root paths
     * @return int Total number of rows pruned (leaves + empty containers)
     */
    private function pruneRemovedItems(string $libraryId, array $rootPaths): int
    {
        // A long-lived Workerman worker can hold stale stat() results across
        // rescans; clear them so is_dir()/file_exists()/realpath() below reflect
        // the current on-disk state (e.g. a root that has since been mounted).
        clearstatcache(true);

        // Map normalized ACCESSIBLE-root prefix => canonical root key. For each
        // configured root that is currently a readable directory, register both
        // the raw configured path and its realpath (each with a trailing
        // separator), so a present file's resolved path OR a now-missing file's
        // raw stored path can be matched — and so "/mnt/foo" never matches
        // "/mnt/foobar". Both prefixes resolve to the SAME canonical root key so
        // per-root tallying is consistent regardless of which one matched.
        $sep = DIRECTORY_SEPARATOR;
        $prefixToRoot = [];
        foreach ($rootPaths as $root) {
            if (!is_string($root) || $root === '' || !is_dir($root)) {
                continue;
            }
            $rootKey = rtrim($root, $sep) . $sep;
            $prefixToRoot[$rootKey] = $rootKey;
            $real = realpath($root);
            if (is_string($real)) {
                $prefixToRoot[rtrim($real, $sep) . $sep] = $rootKey;
            }
        }

        // Guard: refuse to prune when no configured root is currently present and
        // readable — the storage is likely unmounted/unavailable, and deleting
        // every item would cascade-erase all user watch data.
        if ($prefixToRoot === []) {
            $this->logger->warning(
                'Skipping prune — no library root is currently accessible; refusing to delete items',
                ['library_id' => $libraryId, 'paths' => $rootPaths],
            );
            return 0;
        }

        // Match the MOST-SPECIFIC (longest) prefix first so a leaf under a nested
        // root is attributed to that child root, not its accessible parent.
        $prefixes = array_keys($prefixToRoot);
        usort($prefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        $rows = $this->db->query(
            "SELECT id, path FROM media_items WHERE library_id = ?",
            [$libraryId],
        );
        if (!is_array($rows)) {
            return 0;
        }

        // Attribute each leaf to its owning accessible root and tally present vs
        // gone per root, WITHOUT deleting yet.
        /** @var array<string, array{present:int, gone:list<string>}> $perRoot */
        $perRoot = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = is_string($row['id'] ?? null) ? $row['id'] : null;
            $path = is_string($row['path'] ?? null) ? $row['path'] : '';
            if ($id === null || $path === '') {
                continue;
            }
            if ($this->isSyntheticContainerPath($path)) {
                continue;
            }

            // Resolve the item's real path when the file is still present; fall
            // back to the raw stored path when the file is gone (realpath()
            // returns false for a missing file).
            $resolved = realpath($path);
            $candidate = is_string($resolved) ? $resolved : $path;

            $owningRoot = null;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($candidate, $prefix)) {
                    $owningRoot = $prefixToRoot[$prefix];
                    break; // prefixes sorted longest-first → most specific
                }
            }

            // The root this item lives under is unavailable → never delete; its
            // files are simply unreachable right now, not removed.
            if ($owningRoot === null) {
                continue;
            }

            if (!isset($perRoot[$owningRoot])) {
                $perRoot[$owningRoot] = ['present' => 0, 'gone' => []];
            }
            if (file_exists($path)) {
                $perRoot[$owningRoot]['present']++;
            } else {
                $perRoot[$owningRoot]['gone'][] = $id;
            }
        }

        // Per-root presence guard: only prune a root's gone items when that root
        // still has ≥1 present item. A root whose every attributed item is
        // missing is skipped entirely — it could be an unmounted-mountpoint
        // leftover OR a legitimately-emptied folder, and we refuse to
        // bulk-delete either (data-loss-safe; intentional full clears go through
        // the explicit "delete all items" op, not rescan).
        $toDelete = [];
        foreach ($perRoot as $rootKey => $tally) {
            if ($tally['present'] === 0) {
                if ($tally['gone'] !== []) {
                    $this->logger->warning(
                        'Skipping prune for root with no present files; refusing to bulk-delete '
                        . '(possible unmount or legitimately-emptied folder)',
                        [
                            'library_id' => $libraryId,
                            'root' => $rootKey,
                            'items_spared' => count($tally['gone']),
                        ],
                    );
                }
                continue;
            }
            foreach ($tally['gone'] as $id) {
                $toDelete[] = $id;
            }
        }

        $removed = 0;

        // Phase 1: prune leaf items whose real source file is gone from disk and
        // whose owning root still has present items.
        foreach ($toDelete as $id) {
            $this->db->query("DELETE FROM media_items WHERE id = ?", [$id]);
            $removed++;
        }

        // Phase 2: prune now-empty containers — seasons first (their parent
        // series may become childless afterwards), then series. NOT EXISTS
        // protects any children that were kept by the presence guard above.
        $removed += $this->pruneEmptyContainers($libraryId);

        return $removed;
    }

    /**
     * Removes series/season container rows that no longer have any children,
     * seasons before series so a series emptied by season pruning is also cleaned
     * up in the same pass (the hierarchy is at most series → season → episode).
     *
     * @param string $libraryId The library's unique identifier
     * @return int Number of empty container rows removed
     */
    private function pruneEmptyContainers(string $libraryId): int
    {
        $removed = 0;

        foreach (['season', 'series'] as $containerType) {
            $childless = $this->db->query(
                "SELECT c.id FROM media_items c"
                . " WHERE c.library_id = ? AND c.type = ?"
                . " AND NOT EXISTS ("
                . "     SELECT 1 FROM media_items ch WHERE ch.parent_id = c.id"
                . " )",
                [$libraryId, $containerType],
            );
            if (!is_array($childless)) {
                continue;
            }
            foreach ($childless as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = is_string($row['id'] ?? null) ? $row['id'] : null;
                if ($id === null) {
                    continue;
                }
                $this->db->query("DELETE FROM media_items WHERE id = ?", [$id]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Whether a `media_items.path` is a synthetic series/season container address
     * ({@see SeriesContainerNaming::seriesPath()} / `seasonPath()`) rather than a
     * real filesystem path, so container rows are never file-existence checked.
     *
     * @param string $path The stored path column value.
     */
    private function isSyntheticContainerPath(string $path): bool
    {
        return str_starts_with($path, 'series:') || str_starts_with($path, 'season:');
    }

    /**
     * Counts the `media_items` rows currently persisted for a library.
     *
     * @param string $libraryId The library's unique identifier
     * @return int Number of items, or 0 when the count row is unavailable
     */
    private function countLibraryItems(string $libraryId): int
    {
        $rows = $this->db->query(
            "SELECT COUNT(*) AS item_count FROM media_items WHERE library_id = ?",
            [$libraryId],
        );
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return 0;
        }

        $count = $rows[0]['item_count'] ?? 0;

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Sets the theme media finder and repository for theme scanning.
     *
     * @param ThemeMediaFinder $finder Theme media finder instance
     * @param ThemeMediaRepository $repository Theme media repository instance
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function setThemeMediaComponents(
        ThemeMediaFinder $finder,
        ThemeMediaRepository $repository
    ): void {
        $this->themeMediaFinder = $finder;
        $this->themeMediaRepository = $repository;
    }

    /**
     * Scans for and caches theme media for a library.
     *
     * @param string $libraryId The library identifier
     * @return void
     *
     * @since 0.14.0
     */
    public function scanThemeMedia(string $libraryId): void
    {
        if ($this->themeMediaFinder === null || $this->themeMediaRepository === null) {
            return;
        }

        $library = $this->fetchLibraryRow($libraryId);
        if ($library === null) {
            return;
        }

        foreach ($library->paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $themeMedia = $this->themeMediaFinder->findForLibrary($libraryId, $path);
            if ($themeMedia !== null) {
                $this->themeMediaRepository->upsert($themeMedia);
            }
        }
    }

    /**
     * Generates a v4 UUID for library and item identifiers.
     *
     * @return string A formatted UUID string (xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx)
     */
    private function generateUuid(): string
    {
        return Uuid::v4();
    }

    /**
     * Clears the static libraries cache.
     *
     * Used primarily for testing to ensure each test starts with a clean cache state.
     * Also clears the cache timestamp.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cachedLibraries = null;
        self::$librariesCacheTimestamp = null;
    }
}
