<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * PhotoLibraryManager orchestrates photo library scanning and EXIF metadata extraction.
 *
 * This class coordinates between PhotoScanner and ItemRepository to perform
 * full library rescans and single-photo upserts. It handles the complete pipeline
 * from filesystem scanning through EXIF extraction to database persistence.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Orchestrates photo library scanning with EXIF extraction
 * @see PhotoScanner For photo discovery and EXIF extraction
 * @see ItemRepository For media item persistence
 * @since 0.16.0
 */
class PhotoLibraryManager
{
    /** @var StructuredLogger|LoggerInterface Logger instance */
    private StructuredLogger|LoggerInterface $logger;

    /**
     * Constructor for PhotoLibraryManager.
     *
     * @param PhotoScanner $scanner Scanner for discovering photos and extracting EXIF
     * @param ItemRepository $itemRepo Repository for media item persistence
     * @param StructuredLogger|LoggerInterface|null $logger Optional logger
     */
    public function __construct(
        private readonly PhotoScanner $scanner,
        private readonly ItemRepository $itemRepo,
        StructuredLogger|LoggerInterface|null $logger = null
    ) {
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * Creates a default structured logger for the photo subsystem.
     *
     * @return StructuredLogger A configured logger instance
     *
     * @since 0.16.0
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlex_photo_' . uniqid();
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
     * Result of a library scan operation.
     *
     * @since 0.16.0
     */
    public const SCAN_COMPLETED = 'completed';
    public const SCAN_FAILED = 'failed';

    /**
     * Performs a full rescan of a photo library.
     *
     * This method clears all existing items from the library and rescans
     * the filesystem, extracting EXIF metadata from each photo.
     *
     * @param string $libraryId The library's unique identifier
     * @param string $libraryPath Filesystem path to the library root
     * @return PhotoScanResult Scan result with counts and status
     *
     * @since 0.16.0
     */
    public function rescanLibrary(string $libraryId, string $libraryPath): PhotoScanResult
    {
        $result = new PhotoScanResult();

        if (!is_dir($libraryPath)) {
            $this->logger->warning('Photo library path does not exist', [
                'library_id' => $libraryId,
                'path' => $libraryPath,
            ]);
            $result->status = self::SCAN_FAILED;
            $result->errorMessage = "Library path does not exist: {$libraryPath}";
            return $result;
        }

        $this->logger->info('Starting photo library scan', [
            'library_id' => $libraryId,
            'path' => $libraryPath,
        ]);

        $startMs = (int)(microtime(true) * 1000);

        try {
            // Scan and upsert each photo
            foreach ($this->scanner->scanPhotoLibrary($libraryPath, $libraryId) as $item) {
                /** @var string */
                $path = $item['path'];
                $existingItem = $this->itemRepo->findByPath($path);

                if ($existingItem !== null) {
                    // Update existing item
                    /** @var string */
                    $existingId = $existingItem['id'];
                    $this->itemRepo->update($existingId, [
                        'metadata_json' => $item['metadata_json'],
                    ]);
                    $result->itemsUpdated++;
                } else {
                    // Create new item
                    $this->itemRepo->create($item);
                    $result->itemsAdded++;
                }
            }

            $result->status = self::SCAN_COMPLETED;
        } catch (\Throwable $e) {
            $this->logger->error('Photo library scan failed', [
                'library_id' => $libraryId,
                'error' => $e->getMessage(),
            ]);
            $result->status = self::SCAN_FAILED;
            $result->errorMessage = $e->getMessage();
        }

        $endMs = (int)(microtime(true) * 1000);
        $result->durationMs = $endMs - $startMs;

        $this->logger->info('Photo library scan complete', [
            'library_id' => $libraryId,
            'added' => $result->itemsAdded,
            'updated' => $result->itemsUpdated,
            'duration_ms' => $result->durationMs,
        ]);

        return $result;
    }

    /**
     * Upserts a single photo into the library.
     *
     * Extracts EXIF metadata and creates or updates the media item.
     *
     * @param string $libraryId The library's unique identifier
     * @param string $path Absolute filesystem path to the photo
     * @return array<string, mixed>|null The upserted media item or null on failure
     *
     * @since 0.16.0
     */
    public function upsertPhoto(string $libraryId, string $path): ?array
    {
        if (!file_exists($path)) {
            $this->logger->warning('Photo file does not exist', ['path' => $path]);
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!$this->scanner->isPhotoExtension($extension)) {
            $this->logger->warning('Not a supported photo format', [
                'path' => $path,
                'extension' => $extension,
            ]);
            return null;
        }

        $exif = $this->scanner->harvestExif($path);
        $name = pathinfo($path, PATHINFO_FILENAME);

        $existingItem = $this->itemRepo->findByPath($path);

        if ($existingItem !== null) {
            /** @var string */
            $existingId = $existingItem['id'];
            $this->itemRepo->update($existingId, [
                'metadata_json' => $exif,
            ]);
            return $this->itemRepo->findById($existingId);
        }

        $id = $this->itemRepo->create([
            'library_id' => $libraryId,
            'name' => $name,
            'type' => 'photo',
            'path' => $path,
            'metadata_json' => $exif,
        ]);

        /** @var string */
        $idStr = $id;
        return $this->itemRepo->findById($idStr);
    }

    /**
     * Gets photos grouped by date for album creation.
     *
     * @param string $libraryId The library's unique identifier
     * @return array<string, list<array<string, mixed>>> Photos grouped by date string
     *
     * @since 0.16.0
     */
    public function getPhotosGroupedByDate(string $libraryId): array
    {
        $items = $this->itemRepo->getByLibrary($libraryId, 10000, 0);
        $grouped = [];

        foreach ($items as $item) {
            if ($item['type'] !== 'photo') {
                continue;
            }

            /** @var array<string, mixed> */
            $metadata = $item['metadata'] ?? [];
            $dateTaken = $metadata['date_taken_unix'] ?? null;
            /** @var int|null */
            $dateTimestamp = null;
            if ($dateTaken !== null && is_numeric($dateTaken)) {
                /** @var int|float */
                $numericDate = $dateTaken;
                $dateTimestamp = (int)$numericDate;
            }
            $dateKey = $dateTimestamp !== null
                ? date('Y-m-d', $dateTimestamp)
                : 'Unknown';

            if (!isset($grouped[$dateKey])) {
                $grouped[$dateKey] = [];
            }

            $grouped[$dateKey][] = $item;
        }

        return $grouped;
    }

    /**
     * Gets the scanner instance.
     *
     * @return PhotoScanner The photo scanner
     *
     * @since 0.16.0
     */
    public function getScanner(): PhotoScanner
    {
        return $this->scanner;
    }
}
