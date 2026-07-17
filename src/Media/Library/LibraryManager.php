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

    /** @var array<int, array<string, mixed>>|null Cached libraries list */
    private static ?array $cachedLibraries = null;

    /** @var int|null Timestamp when libraries cache was loaded */
    private static ?int $librariesCacheTimestamp = null;

    /** @var int Cache TTL in seconds (60 seconds) */
    private const LIBRARIES_CACHE_TTL = 60;

    /**
     * Constructor for LibraryManager.
     *
     * @param Connection $db Database connection for library persistence
     * @param MediaScanner $scanner Scanner for discovering media files in directories
     * @param FolderWatcher $watcher Watcher for detecting filesystem changes
     * @param MusicLibraryService $musicLibraryService Service for music library scanning
     * @param StructuredLogger|null $logger Optional custom logger, creates default if not provided
     */
    public function __construct(
        Connection $db,
        MediaScanner $scanner,
        FolderWatcher $watcher,
        MusicLibraryService $musicLibraryService,
        ?StructuredLogger $logger = null
    ) {
        $this->db = $db;
        $this->scanner = $scanner;
        $this->watcher = $watcher;
        $this->musicLibraryService = $musicLibraryService;
        $this->logger = $logger ?? $this->createDefaultLogger();
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
     * // Returns: ['id' => 'abc-123', 'name' => 'Movies', 'type' => 'video', 'paths' => ['/mnt/media'], 'options' => [...]]
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
     * @param string $libraryId The library's unique identifier
     * @return void
     * @throws \InvalidArgumentException If the library does not exist
     *
     * @example
     * ```php
     * $manager->scanLibrary('abc-123');
     * ```
     */
    public function scanLibrary(string $libraryId, ?callable $onProgress = null): void
    {
        $library = $this->fetchLibraryRow($libraryId);
        if ($library === null) {
            throw new \InvalidArgumentException("Library not found: $libraryId");
        }

        $this->logger->info('Starting library scan', ['library_id' => $libraryId, 'name' => $library->name]);

        // Route music libraries through MusicLibraryManager for tag harvesting
        if ($library->type === 'music') {
            $this->scanMusicLibrary($libraryId, $library);
            return;
        }

        // Route photo libraries through PhotoLibraryManager for EXIF extraction
        if ($library->type === 'photo') {
            $this->scanPhotoLibrary($libraryId, $library);
            return;
        }

        // Route book libraries through BookLibraryManager for EPUB/PDF/CBZ extraction
        if ($library->type === 'book') {
            $this->scanBookLibrary($libraryId, $library);
            return;
        }

        // Route audiobook libraries through AudiobookScanner for M4B chapter extraction
        if ($library->type === 'audiobook') {
            $this->scanAudiobookLibrary($libraryId, $library);
            return;
        }

        $seriesPerDirectory = $library->type === 'series' && $library->seriesPerDirectory();

        // When a progress sink is supplied, pre-count the media files across all
        // paths so the callback can report a real percentage (processed/total),
        // then stream one tick per processed file. The count walk is cheap (no
        // DB / metadata) relative to the scan itself.
        $onFile = null;
        if ($onProgress !== null) {
            $total = 0;
            foreach ($library->paths as $path) {
                if (is_dir($path)) {
                    $total += $this->scanner->countFiles($path, $library->type);
                }
            }
            $processed = 0;
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
            $this->scanner->scan($libraryId, $path, $library->type, $seriesPerDirectory, $onFile);
        }

        $this->logger->info('Library scan complete', ['library_id' => $libraryId]);

        // Scan for theme media after library content scan
        $this->scanThemeMedia($libraryId);
    }

    /**
     * Scans a music library using MusicLibraryService for tag harvesting.
     *
     * @param string $libraryId The library's unique identifier
     * @param LibraryRow $library The library data
     * @return void
     */
    private function scanMusicLibrary(string $libraryId, LibraryRow $library): void
    {
        // Music scanning is handled by MusicLibraryService which uses
        // MusicLibraryScanner for ID3/MP4 tag harvesting. This requires
        // a different scan approach than video libraries.
        foreach ($library->paths as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Music library path does not exist', ['path' => $path]);
                continue;
            }
            // Use MusicLibraryService for music scanning
            $this->musicLibraryService->scanDirectory($path);
        }

        $this->logger->info('Music library scan complete', ['library_id' => $libraryId]);
    }

    /**
     * Scans a photo library using PhotoLibraryManager for EXIF extraction.
     *
     * @param string $libraryId The library's unique identifier
     * @param LibraryRow $library The library data
     * @return void
     *
     * @since 0.16.0
     */
    private function scanPhotoLibrary(string $libraryId, LibraryRow $library): void
    {
        foreach ($library->paths as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Photo library path does not exist', ['path' => $path]);
                continue;
            }
            // Photo scanning is handled by PhotoLibraryManager which uses
            // PhotoScanner for EXIF metadata extraction.
            // For now, fall back to basic scanning.
            $this->scanner->scan($libraryId, $path, 'image');
        }

        $this->logger->info('Photo library scan complete', ['library_id' => $libraryId]);
    }

    /**
     * Scans a book library using BookScanner for EPUB/PDF/CBZ extraction.
     *
     * @param string $libraryId The library's unique identifier
     * @param LibraryRow $library The library data
     * @return void
     *
     * @since 0.17.0
     */
    private function scanBookLibrary(string $libraryId, LibraryRow $library): void
    {
        // Book scanning is handled by BookScanner for EPUB content.opf,
        // PDF metadata, and CBZ ComicInfo.xml extraction.
        foreach ($library->paths as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Book library path does not exist', ['path' => $path]);
                continue;
            }
            // Use book type for scanner
            $this->scanner->scan($libraryId, $path, 'book');
        }

        $this->logger->info('Book library scan complete', ['library_id' => $libraryId]);
    }

    /**
     * Scans an audiobook library using AudiobookScanner for M4B chapter extraction.
     *
     * @param string $libraryId The library's unique identifier
     * @param LibraryRow $library The library data
     * @return void
     *
     * @since 0.18.0
     */
    private function scanAudiobookLibrary(string $libraryId, LibraryRow $library): void
    {
        // Audiobook scanning is handled by AudiobookScanner for M4B chpl atom
        // chapter extraction and metadata harvesting.
        foreach ($library->paths as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Audiobook library path does not exist', ['path' => $path]);
                continue;
            }
            // Use audiobook type for scanner
            $this->scanner->scan($libraryId, $path, 'audiobook');
        }

        $this->logger->info('Audiobook library scan complete', ['library_id' => $libraryId]);
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
     * @param callable|null $onProgress Optional `(processed, total, path)` sink
     * @return ScanResult Result containing scan statistics
     *
     * @throws \InvalidArgumentException If the library does not exist
     */
    public function rescanLibrary(string $libraryId, array $paths = [], ?callable $onProgress = null): ScanResult
    {
        // Base manager derives paths from the library row inside scanLibrary().
        unset($paths);
        $startTime = microtime(true);

        // Item count BEFORE the scan, so added/updated can be derived as deltas.
        $before = $this->countLibraryItems($libraryId);

        // NON-DESTRUCTIVE rescan: re-scan from the filesystem WITHOUT deleting
        // first. Surviving files keep their existing rows (and UUIDs) via the
        // scanner's upsert-by-path, so all cascading user data is preserved.
        $this->scanLibrary($libraryId, $onProgress);

        // Prune only items whose source file is gone from disk, plus any now-empty
        // series/season containers.
        $removed = $this->pruneRemovedItems($libraryId);

        $after = $this->countLibraryItems($libraryId);

        // Items that existed before and were not pruned were re-scanned in place
        // (updated); the remainder of the new total are brand-new additions.
        $survivors = max(0, $before - $removed);

        $result = new ScanResult();
        $result->scanned = $after;
        $result->added = max(0, $after - $survivors);
        $result->updated = min($survivors, $after);
        $result->removed = $removed;
        $result->durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return $result;
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
     * @param string $libraryId The library's unique identifier
     * @return int Total number of rows pruned (leaves + empty containers)
     */
    private function pruneRemovedItems(string $libraryId): int
    {
        $rows = $this->db->query(
            "SELECT id, path FROM media_items WHERE library_id = ?",
            [$libraryId],
        );
        if (!is_array($rows)) {
            return 0;
        }

        $removed = 0;

        // Phase 1: prune leaf items whose real source file is gone from disk.
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
            if (!file_exists($path)) {
                $this->db->query("DELETE FROM media_items WHERE id = ?", [$id]);
                $removed++;
            }
        }

        // Phase 2: prune now-empty containers — seasons first (their parent
        // series may become childless afterwards), then series.
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
