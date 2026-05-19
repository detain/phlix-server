<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Media\Library\Dto\LibraryRow;
use Phlex\Media\Music\MusicLibraryType;
use Phlex\Media\Music\BookLibraryType;
use Phlex\Media\Music\AudiobookLibraryType;
use Phlex\Theming\ThemeMediaFinder;
use Phlex\Theming\ThemeMediaRepository;
use Workerman\MySQL\Connection;

/**
 * LibraryManager handles media library CRUD operations and scanning coordination.
 *
 * This class provides the main interface for managing media libraries including
 * creation, updates, deletion, and scanning operations. It coordinates between
 * the database, media scanner, and folder watcher components.
 *
 * @author Phlex Development Team
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

    /**
     * Constructor for LibraryManager.
     *
     * @param Connection $db Database connection for library persistence
     * @param MediaScanner $scanner Scanner for discovering media files in directories
     * @param FolderWatcher $watcher Watcher for detecting filesystem changes
     * @param StructuredLogger|null $logger Optional custom logger, creates default if not provided
     */
    public function __construct(
        Connection $db,
        MediaScanner $scanner,
        FolderWatcher $watcher,
        ?StructuredLogger $logger = null
    ) {
        $this->db = $db;
        $this->scanner = $scanner;
        $this->watcher = $watcher;
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * Creates a default structured logger for the media subsystem.
     *
     * @return StructuredLogger A configured logger instance writing to temp directory
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlex_media_' . uniqid();
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
     * Creates a new media library and initiates initial scan.
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

        $this->logger->info('Library created', ['library_id' => $id, 'name' => $name, 'type' => $type]);

        // Initial scan
        $this->scanLibrary($id);

        // Start watching for changes
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
        $results = $this->db->query("SELECT * FROM libraries ORDER BY display_order, name");
        if (!is_array($results)) {
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
    public function scanLibrary(string $libraryId): void
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

        foreach ($library->paths as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Library path does not exist', ['path' => $path]);
                continue;
            }
            $this->scanner->scan($libraryId, $path, $library->type);
        }

        $this->logger->info('Library scan complete', ['library_id' => $libraryId]);

        // Scan for theme media after library content scan
        $this->scanThemeMedia($libraryId);
    }

    /**
     * Scans a music library using AudioScanner for tag harvesting.
     *
     * @param string $libraryId The library's unique identifier
     * @param LibraryRow $library The library data
     * @return void
     */
    private function scanMusicLibrary(string $libraryId, LibraryRow $library): void
    {
        // Music scanning is handled by MusicLibraryManager which uses
        // AudioScanner for ID3/MP4 tag harvesting. This requires
        // a different scan approach than video libraries.
        //
        // For now, fall back to basic scanning. The AudioScanner
        // will handle tag harvesting when available.
        foreach ($library->paths as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Music library path does not exist', ['path' => $path]);
                continue;
            }
            // Use audio type for scanner
            $this->scanner->scan($libraryId, $path, 'audio');
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
     * Clears all media items from a library and rescans from filesystem.
     *
     * @param string $libraryId The library's unique identifier
     * @return void
     */
    public function rescanLibrary(string $libraryId): void
    {
        // Remove existing items
        $this->db->query("DELETE FROM media_items WHERE library_id = ?", [$libraryId]);

        // Rescan
        $this->scanLibrary($libraryId);
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
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
