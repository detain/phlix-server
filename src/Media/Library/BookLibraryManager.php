<?php

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Psr\Log\LoggerInterface;

/**
 * BookLibraryManager orchestrates book library scanning and metadata upsert.
 *
 * This class coordinates between the BookScanner for file discovery and
 * metadata extraction, and the ItemRepository for persistence. It handles
 * full library rescans and single-item upserts.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Orchestrates book library scanning and metadata enrichment
 * @since 0.17.0
 */
class BookLibraryManager
{
    /** @var BookScanner Scanner for discovering book files */
    private BookScanner $scanner;

    /** @var ItemRepository Repository for media item persistence */
    private ItemRepository $itemRepo;

    /** @var StructuredLogger|null Logger instance */
    private ?StructuredLogger $logger;

    /**
     * Constructor for BookLibraryManager.
     *
     * @param BookScanner $scanner Book scanner for file discovery and metadata extraction
     * @param ItemRepository $itemRepo Repository for media item persistence
     * @param LoggerInterface|null $logger Optional logger
     *
     * @since 0.17.0
     */
    public function __construct(
        BookScanner $scanner,
        ItemRepository $itemRepo,
        ?LoggerInterface $logger = null
    ) {
        $this->scanner = $scanner;
        $this->itemRepo = $itemRepo;
        $this->logger = $logger instanceof StructuredLogger ? $logger : null;
    }

    /**
     * Rescans a book library, removing existing items and re-importing.
     *
     * This performs a full refresh of the library:
     * 1. Removes all existing media items for the library
     * 2. Scans the library paths for book files
     * 3. Extracts metadata from each file
     * 4. Creates new media items in the repository
     *
     * @param string $libraryId The library's unique identifier
     * @param array<string> $paths Array of filesystem paths to scan
     * @return ScanResult Result containing scan statistics
     *
     * @since 0.17.0
     */
    public function rescanLibrary(string $libraryId, array $paths): ScanResult
    {
        $startTime = microtime(true);

        // Remove existing items
        $this->itemRepo->deleteByLibrary($libraryId);

        $scanned = 0;
        $added = 0;
        $errors = 0;

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                $this->logger?->warning('Library path does not exist', [
                    'library_id' => $libraryId,
                    'path' => $path,
                ]);
                continue;
            }

            foreach ($this->scanner->scanBookLibrary($libraryId, $path) as $item) {
                $scanned++;
                try {
                    $itemId = $this->itemRepo->create($item);
                    $added++;
                    $this->logger?->debug('Book scanned', [
                        'item_id' => $itemId,
                        'name' => $item['name'],
                        'path' => $item['path'],
                    ]);
                } catch (\Throwable $e) {
                    $errors++;
                    $this->logger?->error('Failed to create book item', [
                        'path' => $item['path'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        $result = new ScanResult();
        $result->scanned = $scanned;
        $result->added = $added;
        $result->updated = 0;
        $result->durationMs = $durationMs;

        return $result;
    }

    /**
     * Upserts a single book, extracting and updating its metadata.
     *
     * If the book already exists (by path), updates its metadata.
     * Otherwise, creates a new media item.
     *
     * @param string $libraryId The library's unique identifier
     * @param string $path Absolute filesystem path to the book file
     * @return array<string, mixed>|null The created/updated media item, or null on failure
     *
     * @since 0.17.0
     */
    public function upsertBook(string $libraryId, string $path): ?array
    {
        // Check if already exists
        $existing = $this->itemRepo->findByPath($path);

        // Determine file extension and harvest metadata
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $metadata = match ($extension) {
            'epub' => $this->scanner->harvestEpub($path),
            'pdf' => $this->scanner->harvestPdf($path),
            'cbz' => $this->scanner->harvestCbz($path),
            default => [],
        };

        if (empty($metadata) && $extension !== 'epub' && $extension !== 'pdf' && $extension !== 'cbz') {
            return null;
        }

        $itemData = [
            'library_id' => $libraryId,
            'name' => $metadata['title'] ?? pathinfo($path, PATHINFO_FILENAME),
            'type' => 'book',
            'path' => $path,
            'metadata_json' => $metadata,
        ];

        if ($existing !== null) {
            // Update existing item
            /** @var string $existingId */
            $existingId = $existing['id'];
            $this->itemRepo->update($existingId, $itemData);
            $itemId = $existingId;
        } else {
            // Create new item
            $itemId = $this->itemRepo->create($itemData);
        }

        return $this->itemRepo->findById($itemId);
    }
}
