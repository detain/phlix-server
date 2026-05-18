<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Playlists\LibraryUpdated;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * FolderWatcher monitors filesystem directories for media file changes.
 *
 * This class tracks multiple library paths and detects when files are added,
 * modified, or deleted by calculating directory checksums. It uses mtime-based
 * change detection for efficiency. When changes are detected, it dispatches
 * LibraryUpdated events to notify subscribers (such as SmartPlaylistRefreshHandler).
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Filesystem watcher for detecting media library changes
 * @see LibraryManager For library management operations
 * @see MediaScanner For media file scanning
 * @see SmartPlaylistRefreshHandler For handling library updates
 */
class FolderWatcher
{
    /** @var StructuredLogger|null Logger instance for structured logging */
    private ?StructuredLogger $logger = null;

    /** @var EventDispatcherInterface|null PSR-14 event dispatcher for library events */
    private ?EventDispatcherInterface $eventDispatcher = null;

    /** @var array<string, array{library_id: string, paths: array<string>}> Watched path information keyed by path */
    private array $watchedPaths = [];

    /** @var array<string, string> Directory checksum by path for change detection */
    private array $fileChecksums = [];

    /** @var int Interval in seconds between change checks */
    private int $checkInterval = 30;

    /** @var bool Whether the watcher is actively running */
    private bool $running = false;

    /**
     * Constructor for FolderWatcher.
     *
     * @param StructuredLogger|null $logger Optional custom logger, creates default if not provided
     * @param EventDispatcherInterface|null $eventDispatcher Optional PSR-14 dispatcher for LibraryUpdated events
     *
     * @since 0.14.0
     */
    public function __construct(
        ?StructuredLogger $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Creates a default structured logger for the watcher subsystem.
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
                    'path' => $tempDir . '/watcher.log',
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
     * Starts watching the specified paths for changes.
     *
     * Calculates initial checksum for each path to establish baseline.
     *
     * @param string $libraryId The library's unique identifier to associate with watched paths
     * @param array<string> $paths Array of filesystem paths to watch
     * @return void
     *
     * @example
     * ```php
     * $watcher->watch('library-123', ['/mnt/media/movies', '/mnt/media/shows']);
     * ```
     */
    public function watch(string $libraryId, array $paths): void
    {
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Cannot watch non-existent path', ['path' => $path]);
                continue;
            }

            $this->watchedPaths[$path] = [
                'library_id' => $libraryId,
                'paths' => $paths,
            ];

            // Initial checksum scan
            $this->fileChecksums[$path] = $this->calculateDirectoryChecksum($path);

            $this->logger->info('Started watching path', ['path' => $path, 'library_id' => $libraryId]);
        }
    }

    /**
     * Stops watching all paths associated with a library.
     *
     * @param string $libraryId The library's unique identifier
     * @return void
     */
    public function unwatch(string $libraryId): void
    {
        foreach ($this->watchedPaths as $path => $info) {
            if ($info['library_id'] === $libraryId) {
                unset($this->watchedPaths[$path], $this->fileChecksums[$path]);
                $this->logger->info('Stopped watching path', ['path' => $path]);
            }
        }
    }

    /**
     * Checks all watched paths for changes since last check.
     *
     * When changes are detected, dispatches LibraryUpdated events to
     * notify subscribers such as SmartPlaylistRefreshHandler.
     *
     * @return array<int, array{library_id: string, path: string, change_detected: bool}> Array of changes detected
     *
     * @example
     * ```php
     * $changes = $watcher->checkForChanges();
     * foreach ($changes as $change) {
     *     echo "Change detected in: {$change['path']}";
     * }
     * ```
     *
     * @since 0.14.0
     */
    public function checkForChanges(): array
    {
        $changes = [];

        foreach ($this->watchedPaths as $path => $info) {
            $newChecksum = $this->calculateDirectoryChecksum($path);

            if ($newChecksum !== $this->fileChecksums[$path]) {
                $libraryId = $info['library_id'];
                $changes[] = [
                    'library_id' => $libraryId,
                    'path' => $path,
                    'change_detected' => true,
                ];

                $this->fileChecksums[$path] = $newChecksum;

                // Dispatch LibraryUpdated event for smart playlist refresh
                $this->dispatchLibraryUpdated($libraryId, $path);
            }
        }

        return $changes;
    }

    /**
     * Dispatches a LibraryUpdated event to the event dispatcher.
     *
     * @param string $libraryId The library that was updated
     * @param string $path The path that triggered the update
     * @return void
     *
     * @since 0.14.0
     */
    private function dispatchLibraryUpdated(string $libraryId, string $path): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }

        $event = new LibraryUpdated($libraryId, $path);
        $this->eventDispatcher->dispatch($event);
    }

    /**
     * Calculates a checksum for a directory based on file paths and modification times.
     *
     * Uses MD5 hash of concatenated file paths and mtimes for efficient
     * change detection without content hashing.
     *
     * @param string $path The directory path to calculate checksum for
     * @return string The MD5 checksum string
     */
    private function calculateDirectoryChecksum(string $path): string
    {
        $checksum = '';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname() . ':' . $file->getMTime();
            }
        }

        sort($files);
        foreach ($files as $file) {
            $checksum .= $file;
        }

        return md5($checksum);
    }

    /**
     * Gets all currently watched paths with their library associations.
     *
     * @return array<string, array{library_id: string, paths: array<string>}> Watched paths information
     */
    public function getWatchedPaths(): array
    {
        return $this->watchedPaths;
    }

    /**
     * Sets the interval between change detection checks.
     *
     * @param int $seconds Interval in seconds
     * @return void
     */
    public function setCheckInterval(int $seconds): void
    {
        $this->checkInterval = $seconds;
    }
}
