<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

use Phlex\Shared\Events\Library\LibraryScanCompleted;
use Phlex\Shared\Events\Library\LibraryScanStarted;
use Phlex\Shared\Events\Library\MediaItemAdded;
use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Workerman\MySQL\Connection;
use SplFileInfo;

/**
 * MediaScanner discovers and indexes media files from filesystem directories.
 *
 * This class recursively scans directories to find media files matching supported
 * extensions, parses naming conventions to extract metadata (year, season, episode),
 * and creates media items in the repository. It handles deduplication by checking
 * if files have already been scanned.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Filesystem scanner for discovering and indexing media files
 * @see ItemRepository For media item persistence
 * @see FolderWatcher For change detection
 */
class MediaScanner
{
    /** @var StructuredLogger|null Logger instance for structured logging */
    private ?StructuredLogger $logger = null;

    /** @var Connection Database connection */
    private Connection $db;

    /** @var array<string, array<string>> File extensions by media type */
    private array $namingOptions;

    /** @var ItemRepository Repository for media item persistence */
    private ItemRepository $itemRepository;

    /** @var EventDispatcherInterface|null PSR-14 dispatcher for library lifecycle events. */
    private ?EventDispatcherInterface $eventDispatcher;

    /**
     * Constructor for MediaScanner.
     *
     * @param Connection $db Database connection for media item persistence
     * @param ItemRepository $itemRepository Repository for media item operations
     * @param StructuredLogger|null $logger Optional custom logger, creates default if not provided
     * @param EventDispatcherInterface|null $eventDispatcher Optional PSR-14 dispatcher;
     *                                       when supplied,
     *                                       {@see LibraryScanStarted},
     *                                       {@see LibraryScanCompleted}, and
     *                                       {@see MediaItemAdded} are published
     *                                       during scans. Defaults to null so
     *                                       legacy callers and tests not exercising
     *                                       events do not need to wire one up.
     */
    public function __construct(
        Connection $db,
        ItemRepository $itemRepository,
        ?StructuredLogger $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->db = $db;
        $this->itemRepository = $itemRepository;
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->namingOptions = $this->loadNamingOptions();
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Creates a default structured logger for the scanner subsystem.
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
                    'path' => $tempDir . '/scanner.log',
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
     * Loads supported file extensions by media type.
     *
     * @return array<string, array<string>> Media type to extension list mapping
     */
    private function loadNamingOptions(): array
    {
        return [
            'video' => ['mkv', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'mpg', 'mpeg', 'ts'],
            'audio' => ['mp3', 'flac', 'aac', 'ogg', 'wav', 'm4a', 'wma', 'alac', 'opus'],
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'tif'],
        ];
    }

    /**
     * Checks if an extension represents an audio file.
     *
     * @param string $extension File extension (without dot)
     * @return bool True if the extension is a supported audio format
     */
    public function isAudioExtension(string $extension): bool
    {
        $audioExtensions = $this->namingOptions['audio'] ?? [];
        return in_array(strtolower($extension), $audioExtensions, true);
    }

    /**
     * Scans a single audio file and returns media item data.
     *
     * This method is used by AudioScanner for tag harvesting but can also
     * be called directly for single-file processing.
     *
     * @param string $libraryId The library's unique identifier
     * @param \SplFileInfo $file The file to process
     * @return array<string, mixed>|null Media item data or null if skipped
     */
    public function scanAudioFile(string $libraryId, \SplFileInfo $file): ?array
    {
        if ($this->shouldSkipFile($file->getFilename())) {
            return null;
        }

        return [
            'library_id' => $libraryId,
            'name' => $file->getBasename('.' . $file->getExtension()),
            'type' => 'track',
            'path' => $file->getPathname(),
            'metadata_json' => [],
        ];
    }

    /**
     * Scans a directory for media files and creates items in the repository.
     *
     * Recursively iterates through all files in the given path, filters by
     * supported extensions for the media type, skips hidden/system files,
     * and creates media items for discovered files.
     *
     * @param string $libraryId The library's unique identifier
     * @param string $path Filesystem path to scan
     * @param string $type Media type ('video', 'audio', 'image')
     * @return void
     *
     * @example
     * ```php
     * $scanner->scan('library-123', '/mnt/media/movies', 'video');
     * ```
     */
    public function scan(string $libraryId, string $path, string $type): void
    {
        if (!is_dir($path)) {
            $this->logger->warning('Scan path does not exist', ['path' => $path]);
            return;
        }

        $startMs = (int)(microtime(true) * 1000);
        $this->dispatchScanStarted($libraryId, $path);

        $extensions = $this->namingOptions[$type] ?? $this->namingOptions['video'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $scanned = 0;
        $skipped = 0;
        $added = 0;

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $extensions)) {
                $skipped++;
                continue;
            }

            // Skip hidden files and system files
            if ($this->shouldSkipFile($file->getFilename())) {
                $skipped++;
                continue;
            }

            if ($this->processFile($libraryId, $file, $type)) {
                $added++;
            }
            $scanned++;
        }

        $this->logger->info('Scan complete', [
            'library_id' => $libraryId,
            'path' => $path,
            'scanned' => $scanned,
            'skipped' => $skipped,
            'added' => $added,
        ]);

        $endMs = (int)(microtime(true) * 1000);
        $this->dispatchScanCompleted($libraryId, $added, $endMs - $startMs);
    }

    /**
     * Determines if a file should be skipped during scanning.
     *
     * @param string $filename The filename to check
     * @return bool True if the file should be skipped
     */
    protected function shouldSkipFile(string $filename): bool
    {
        // Skip hidden files
        if (str_starts_with($filename, '.')) {
            return true;
        }

        // Skip system files
        $skipPatterns = ['.part', '.tmp', '_unpack', '.download', '.!ut'];
        foreach ($skipPatterns as $pattern) {
            if (str_contains($filename, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Processes a single media file and creates a media item.
     *
     * @param string $libraryId The library's unique identifier
     * @param SplFileInfo $file The file to process
     * @param string $type The media type
     *
     * @return bool True when a new item was added to the repository; false
     *              when the file was already known and was skipped.
     */
    private function processFile(string $libraryId, SplFileInfo $file, string $type): bool
    {
        $path = $file->getPathname();

        // Check if already exists
        $existing = $this->itemRepository->findByPath($path);
        if ($existing) {
            return false; // Already scanned
        }

        // Determine media type
        $mediaType = $this->determineMediaType($file, $type);

        // Parse naming for series/movies
        $metadata = $this->parseNaming($file->getFilename(), $mediaType);

        // Create media item
        $itemId = $this->itemRepository->create([
            'library_id' => $libraryId,
            'name' => $metadata['name'] ?? $file->getBasename('.' . $file->getExtension()),
            'type' => $mediaType,
            'path' => $path,
            'metadata_json' => $metadata,
        ]);

        $this->logger->debug('Media file scanned', [
            'item_id' => $itemId,
            'name' => $metadata['name'] ?? 'unknown',
            'type' => $mediaType,
        ]);

        $this->dispatchMediaItemAdded((string)$itemId, $libraryId, $path, $mediaType);

        return true;
    }

    /**
     * Determines the specific media type from file and library type.
     *
     * @param SplFileInfo $file The file info
     * @param string $libraryType The library type ('video', 'audio', 'image')
     * @return string The specific media type ('movie', 'episode', 'track', etc.)
     */
    private function determineMediaType(SplFileInfo $file, string $libraryType): string
    {
        if ($libraryType !== 'video') {
            return $libraryType;
        }

        // Could add series episode detection here
        return 'movie';
    }

    /**
     * Parses filename to extract metadata based on naming conventions.
     *
     * Supports:
     * - Movies: "Movie Name (Year)" or "Movie Name.Year"
     * - Series: "Series S01E01" or "Series - S01E01 - Episode Title"
     *
     * @param string $filename The filename to parse (without path)
     * @param string $type The media type
     * @return array<string, mixed> Extracted metadata (name, year, season, episode, episode_title)
     */
    private function parseNaming(string $filename, string $type): array
    {
        $metadata = [];

        // Remove extension
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // Movie pattern: Movie Name (Year) or Movie Name.Year
        if ($type === 'movie') {
            if (preg_match('/(.+?)\s*[\(\[(\s*(\d{4})\s*\)\]\)]/', $name, $matches)) {
                $metadata['name'] = trim($matches[1]);
                $metadata['year'] = $matches[3] ?? null;
            } else {
                $metadata['name'] = $name;
            }
        }

        // Series pattern: Series S01E01 or Series - S01E01 - Episode Title
        if (preg_match('/^(.+?)\s*S(\d{2})E(\d{2})/i', $name, $matches)) {
            $metadata['name'] = trim($matches[1]);
            $metadata['season'] = (int)$matches[2];
            $metadata['episode'] = (int)$matches[3];

            // Extract episode title if present
            if (preg_match('/E\d{2}\s*-\s*(.+)$/', $name, $titleMatch)) {
                $metadata['episode_title'] = trim($titleMatch[1]);
            }
        }

        return $metadata;
    }

    /**
     * Best-effort lookup of a library's human-readable name for the
     * `LibraryScanStarted` event. Falls back to the empty string when
     * the row cannot be resolved (e.g. tests that mock the DB).
     *
     * @param string $libraryId Library UUID.
     *
     * @return string Library `name` column value or empty string.
     */
    private function lookupLibraryName(string $libraryId): string
    {
        try {
            $rows = $this->db->query(
                "SELECT name FROM libraries WHERE id = ? LIMIT 1",
                [$libraryId]
            );
        } catch (\Throwable) {
            return '';
        }
        if (!is_array($rows) || $rows === []) {
            return '';
        }
        $name = $rows[0]['name'] ?? '';
        return is_string($name) ? $name : '';
    }

    /**
     * Emit {@see LibraryScanStarted}.
     *
     * @param string $libraryId Library UUID.
     * @param string $path      Absolute scan path.
     *
     * @return void
     */
    private function dispatchScanStarted(string $libraryId, string $path): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }
        $this->eventDispatcher->dispatch(new LibraryScanStarted(
            libraryId: $libraryId,
            libraryName: $this->lookupLibraryName($libraryId),
            path: $path,
        ));
    }

    /**
     * Emit {@see LibraryScanCompleted}.
     *
     * Updated / removed counts are currently zero because A.2 ships the
     * dispatch site without re-plumbing the upsert / cleanup paths;
     * subsequent phases will populate the full tallies.
     *
     * @param string $libraryId  Library UUID.
     * @param int    $added      Number of items added during this scan.
     * @param int    $durationMs Wall-clock duration of the scan, in
     *                           milliseconds.
     *
     * @return void
     */
    private function dispatchScanCompleted(string $libraryId, int $added, int $durationMs): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }
        $this->eventDispatcher->dispatch(new LibraryScanCompleted(
            libraryId: $libraryId,
            itemsAdded: $added,
            itemsUpdated: 0,
            itemsRemoved: 0,
            durationMs: $durationMs,
        ));
    }

    /**
     * Emit {@see MediaItemAdded}.
     *
     * @param string $mediaItemId UUID of the newly-persisted item.
     * @param string $libraryId   Owning library UUID.
     * @param string $path        Absolute filesystem path of the source file.
     * @param string $type        Concrete media-item type (movie, episode, …).
     *
     * @return void
     */
    private function dispatchMediaItemAdded(
        string $mediaItemId,
        string $libraryId,
        string $path,
        string $type
    ): void {
        if ($this->eventDispatcher === null) {
            return;
        }
        $this->eventDispatcher->dispatch(new MediaItemAdded(
            mediaItemId: $mediaItemId,
            libraryId: $libraryId,
            path: $path,
            type: $type,
        ));
    }
}
