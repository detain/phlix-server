<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
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
    /** @var StructuredLogger|null Logger instance for structured logging */
    private ?StructuredLogger $logger = null;

    /** @var Connection Database connection for persistence */
    private Connection $db;

    /** @var MediaScanner Scanner for discovering media files */
    private MediaScanner $scanner;

    /** @var FolderWatcher Watcher for detecting filesystem changes */
    private FolderWatcher $watcher;

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
     * @return array<string, mixed>|null Library data array with 'paths' and 'options' decoded, or null if not found
     *
     * @example
     * ```php
     * $library = $manager->getLibrary('abc-123');
     * // Returns: ['id' => 'abc-123', 'name' => 'Movies', 'type' => 'video', 'paths' => ['/mnt/media'], 'options' => [...]]
     * ```
     */
    public function getLibrary(string $id): ?array
    {
        $result = $this->db->query("SELECT * FROM libraries WHERE id = ?", [$id]);
        if (empty($result)) {
            return null;
        }
        $library = $result[0];
        $library['paths'] = json_decode($library['paths'], true);
        $library['options'] = json_decode($library['options'] ?? '{}', true);
        return $library;
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
        return array_map(function ($lib) {
            $lib['paths'] = json_decode($lib['paths'], true);
            $lib['options'] = json_decode($lib['options'] ?? '{}', true);
            return $lib;
        }, $results);
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
        $library = $this->getLibrary($libraryId);
        if (!$library) {
            throw new \InvalidArgumentException("Library not found: $libraryId");
        }

        $this->logger->info('Starting library scan', ['library_id' => $libraryId, 'name' => $library['name']]);

        foreach ($library['paths'] as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Library path does not exist', ['path' => $path]);
                continue;
            }
            $this->scanner->scan($libraryId, $path, $library['type']);
        }

        $this->logger->info('Library scan complete', ['library_id' => $libraryId]);
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
