<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

use Phlex\Common\Logger\StructuredLogger;
use Psr\Log\LoggerInterface;

/**
 * AudiobookLibraryManager orchestrates audiobook library scanning and metadata upsert.
 *
 * Extends BookLibraryManager for audiobook-specific logic including chapter extraction
 * and per-user progress tracking.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Orchestrates audiobook library scanning and progress tracking
 * @since 0.18.0
 * @see BookLibraryManager For the parent class
 * @see AudiobookScanner For M4B chapter extraction
 * @see AudiobookProgressStore For per-user progress tracking
 */
class AudiobookLibraryManager extends BookLibraryManager
{
    /** @var AudiobookScanner Scanner for discovering audiobook files */
    private AudiobookScanner $scanner;

    /** @var ItemRepository Repository for media item persistence */
    private ItemRepository $itemRepo;

    /** @var AudiobookProgressStore Progress store for per-user tracking */
    private AudiobookProgressStore $progressStore;

    /** @var StructuredLogger|null Logger instance */
    private ?StructuredLogger $logger;

    /**
     * Constructor for AudiobookLibraryManager.
     *
     * @param AudiobookScanner $scanner Audiobook scanner for file discovery and chapter extraction
     * @param ItemRepository $itemRepo Repository for media item persistence
     * @param AudiobookProgressStore $progressStore Progress store for per-user tracking
     * @param LoggerInterface|null $logger Optional logger
     *
     * @since 0.18.0
     */
    public function __construct(
        AudiobookScanner $scanner,
        ItemRepository $itemRepo,
        AudiobookProgressStore $progressStore,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($scanner, $itemRepo, $logger);

        $this->scanner = $scanner;
        $this->itemRepo = $itemRepo;
        $this->progressStore = $progressStore;
        $this->logger = $logger instanceof StructuredLogger ? $logger : null;
    }

    /**
     * Rescans an audiobook library, removing existing items and re-importing.
     *
     * This performs a full refresh of the library:
     * 1. Removes all existing media items for the library
     * 2. Scans the library paths for audiobook files
     * 3. Extracts metadata and chapters from each file
     * 4. Creates new media items in the repository
     *
     * @param string $libraryId The library's unique identifier
     * @param array<string> $paths Array of filesystem paths to scan
     * @return ScanResult Result containing scan statistics
     *
     * @since 0.18.0
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

            foreach ($this->scanner->scanAudiobookLibrary($libraryId, $path) as $item) {
                $scanned++;
                try {
                    $itemId = $this->itemRepo->create($item);
                    $added++;
                    $this->logger?->debug('Audiobook scanned', [
                        'item_id' => $itemId,
                        'name' => $item['name'],
                        'path' => $item['path'],
                    ]);
                } catch (\Throwable $e) {
                    $errors++;
                    $this->logger?->error('Failed to create audiobook item', [
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
     * Upserts a single audiobook, extracting and updating its metadata and chapters.
     *
     * If the audiobook already exists (by path), updates its metadata.
     * Otherwise, creates a new media item with chapters stored in metadata_json.
     *
     * @param string $libraryId The library's unique identifier
     * @param string $path Absolute filesystem path to the audiobook file
     * @return array<string, mixed>|null The created/updated media item, or null on failure
     *
     * @since 0.18.0
     */
    public function upsertAudiobook(string $libraryId, string $path): ?array
    {
        // Check if already exists
        $existing = $this->itemRepo->findByPath($path);

        // Determine file extension and harvest metadata + chapters
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!$this->scanner->isAudiobookExtension($extension)) {
            return null;
        }

        $metadata = $this->scanner->harvestAudiobookMetadata($path);
        $chapters = $this->scanner->harvestChapters($path);
        $metadata['chapters'] = $chapters;

        $itemData = [
            'library_id' => $libraryId,
            'name' => $metadata['title'] ?? pathinfo($path, PATHINFO_FILENAME),
            'type' => 'audiobook',
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

    /**
     * Gets user's progress for an audiobook.
     *
     * @param string $userId The user's unique identifier
     * @param string $audiobookId The audiobook's unique identifier
     * @return AudiobookProgress The user's progress (fresh progress if none exists)
     *
     * @since 0.18.0
     */
    public function getProgress(string $userId, string $audiobookId): AudiobookProgress
    {
        $progress = $this->progressStore->getProgress($userId, $audiobookId);

        if ($progress === null) {
            return AudiobookProgress::fresh($audiobookId, $userId);
        }

        return $progress;
    }

    /**
     * Saves user's progress for an audiobook.
     *
     * @param string $userId The user's unique identifier
     * @param string $audiobookId The audiobook's unique identifier
     * @param AudiobookProgress $progress The progress to save
     * @return void
     *
     * @since 0.18.0
     */
    public function saveProgress(string $userId, string $audiobookId, AudiobookProgress $progress): void
    {
        $this->progressStore->saveProgress($progress);
    }
}
