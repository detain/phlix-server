<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Media\Library\Dto\LibraryRow;
use Phlex\Media\Metadata\MetadataManager;
use Psr\EventDispatcher\EventDispatcherInterface;
use Workerman\MySQL\Connection;

/**
 * MusicLibraryManager orchestrates music library scanning, tag harvesting,
 * and metadata enrichment.
 *
 * This class provides the main interface for managing music libraries,
 * coordinating between the AudioScanner for tag harvesting, MetadataManager
 * for metadata enrichment, and ItemRepository for persistence.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Manages music library operations including scanning, tag parsing, and metadata enrichment
 * @see AudioScanner For audio file scanning and tag harvesting
 * @see MetadataManager For metadata provider coordination
 * @see ItemRepository For media item persistence
 */
class MusicLibraryManager
{
    /** @var StructuredLogger Logger instance for structured logging */
    private StructuredLogger $logger;

    /** @var Connection Database connection */
    private Connection $db;

    /** @var AudioScanner Scanner for discovering audio files and harvesting tags */
    private AudioScanner $scanner;

    /** @var MetadataManager Manager for metadata enrichment */
    private MetadataManager $metadata;

    /** @var ItemRepository Repository for media item persistence */
    private ItemRepository $item_repo;

    /**
     * Constructor for MusicLibraryManager.
     *
     * @param AudioScanner $scanner Scanner for discovering audio files and harvesting tags
     * @param MetadataManager $metadata Manager for metadata enrichment
     * @param ItemRepository $item_repo Repository for media item operations
     * @param Connection $db Database connection for library queries
     * @param StructuredLogger|null $logger Optional custom logger
     * @param EventDispatcherInterface|null $eventDispatcher Optional PSR-14 dispatcher
     */
    public function __construct(
        AudioScanner $scanner,
        MetadataManager $metadata,
        ItemRepository $item_repo,
        Connection $db,
        ?StructuredLogger $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        unset($eventDispatcher); // Reserved for future event-driven scan hooks.
        $this->scanner = $scanner;
        $this->metadata = $metadata;
        $this->item_repo = $item_repo;
        $this->db = $db;
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * Creates a default structured logger for the music subsystem.
     *
     * @return StructuredLogger A configured logger instance writing to temp directory
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlex_music_' . uniqid();
        mkdir($tempDir, 0755, true);

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/music_manager.log',
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
     * Full rescan of a music library: harvest tags → lookup metadata → upsert items.
     *
     * This performs a complete refresh of a music library, processing all audio
     * files to harvest tags and enrich with metadata from configured providers.
     *
     * @param string $libraryId The library's unique identifier
     * @return ScanResult Summary of the scan operation
     *
     * @example
     * ```php
     * $result = $musicManager->rescanLibrary('lib-123');
     * echo "Scanned {$result->scanned} tracks, {$result->added} added, {$result->updated} updated";
     * ```
     */
    public function rescanLibrary(string $libraryId): ScanResult
    {
        $library = $this->fetchLibraryRow($libraryId);
        if ($library === null) {
            throw new \InvalidArgumentException("Library not found: $libraryId");
        }

        $result = new ScanResult();
        $startTime = microtime(true);

        $this->logger->info('Starting music library rescan', [
            'library_id' => $libraryId,
            'name' => $library->name,
        ]);

        $basePath = $library->paths[0] ?? '';

        foreach ($library->paths as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Music library path does not exist', ['path' => $path]);
                continue;
            }

            foreach ($this->scanner->scanMusicLibrary($libraryId, $basePath, $path) as $itemData) {
                $result->scanned++;

                $itemPath = is_string($itemData['path'] ?? null) ? $itemData['path'] : '';
                $existing = $this->item_repo->findByPath($itemPath);
                if ($existing) {
                    $existingId = is_string($existing['id'] ?? null) ? $existing['id'] : '';
                    // Update existing item with new tag data
                    $this->item_repo->update($existingId, [
                        'metadata_json' => $itemData['metadata_json'] ?? null,
                    ]);
                    $result->updated++;
                } else {
                    // Create new item
                    $this->item_repo->create($itemData);
                    $result->added++;
                }
            }
        }

        $result->durationMs = (int)((microtime(true) - $startTime) * 1000);

        $this->logger->info('Music library rescan complete', [
            'library_id' => $libraryId,
            'scanned' => $result->scanned,
            'added' => $result->added,
            'updated' => $result->updated,
            'duration_ms' => $result->durationMs,
        ]);

        return $result;
    }

    /**
     * Upsert a single track by path.
     *
     * If the track already exists, it will be updated with the latest tag data.
     * Metadata enrichment will be attempted from configured providers.
     *
     * @param string $libraryId The library's unique identifier
     * @param string $path Absolute filesystem path to the audio file
     * @return MediaItem|null The upserted media item, or null if the file doesn't exist
     *
     * @example
     * ```php
     * $item = $musicManager->upsertTrack('lib-123', '/music/artist/album/track.mp3');
     * if ($item) {
     *     echo "Upserted: {$item->name}";
     * }
     * ```
     */
    public function upsertTrack(string $libraryId, string $path): ?MediaItem
    {
        if (!file_exists($path)) {
            $this->logger->warning('Track file does not exist', ['path' => $path]);
            return null;
        }

        // Harvest tags from the file
        $tags = $this->scanner->harvestTags($path);
        if (empty($tags)) {
            $this->logger->debug('No tags harvested from file', ['path' => $path]);
            return null;
        }

        // Build metadata array
        $metadata = $this->buildMetadataFromTags($tags, $path);

        // Check for existing item
        $existing = $this->item_repo->findByPath($path);

        if ($existing) {
            $existingId = is_string($existing['id'] ?? null) ? $existing['id'] : '';
            $existingName = is_string($existing['name'] ?? null) ? $existing['name'] : '';
            $tagTitle = isset($tags['title']) && is_string($tags['title']) ? $tags['title'] : null;
            // Update existing
            $this->item_repo->update($existingId, [
                'name' => $tagTitle ?? $existingName,
                'metadata_json' => json_encode($metadata),
            ]);
            $itemId = $existingId;
        } else {
            $tagTitle = isset($tags['title']) && is_string($tags['title']) ? $tags['title'] : null;
            $fallbackName = pathinfo($path, PATHINFO_FILENAME);
            // Create new
            $itemId = $this->item_repo->create([
                'library_id' => $libraryId,
                'name' => $tagTitle ?? $fallbackName,
                'type' => 'track',
                'path' => $path,
                'metadata_json' => $metadata,
            ]);
        }

        // Attempt metadata enrichment
        $this->enrichTrackMetadata($itemId, $tags);

        $row = $this->item_repo->findById($itemId);
        if ($row === null) {
            return null;
        }
        return $this->mediaItemFromRow($row);
    }

    /**
     * Hydrates a {@see MediaItem} value object from an ItemRepository row.
     *
     * @param array<string, mixed> $row Raw repository row.
     */
    private function mediaItemFromRow(array $row): MediaItem
    {
        $id = is_string($row['id'] ?? null) ? $row['id'] : '';
        $name = is_string($row['name'] ?? null) ? $row['name'] : '';
        $type = is_string($row['type'] ?? null) ? $row['type'] : '';
        $path = is_string($row['path'] ?? null) ? $row['path'] : '';

        $metadata = [];
        $rawMeta = $row['metadata'] ?? null;
        if (is_array($rawMeta)) {
            foreach ($rawMeta as $key => $value) {
                if (is_string($key)) {
                    $metadata[$key] = $value;
                }
            }
        }

        return new MediaItem(
            id: $id,
            name: $name,
            type: $type,
            path: $path,
            metadata: $metadata,
        );
    }

    /**
     * Enriches track metadata using configured providers.
     *
     * @param string $itemId The media item ID
     * @param array<string, mixed> $tags Harvested tags
     * @return void
     */
    private function enrichTrackMetadata(string $itemId, array $tags): void
    {
        try {
            // Try to refresh metadata from providers
            $this->metadata->refreshItemMetadata($itemId);
        } catch (\Throwable $e) {
            $this->logger->warning('Metadata enrichment failed', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Builds a metadata array from harvested tags.
     *
     * @param array<string, mixed> $tags Raw tag data
     * @param string $path File path
     * @return array<string, mixed> Formatted metadata for storage
     */
    private function buildMetadataFromTags(array $tags, string $path): array
    {
        $metadata = [
            'file_size' => filesize($path) ?: 0,
            'file_mtime' => filemtime($path) ?: 0,
        ];

        foreach (
            ['title', 'artist', 'album', 'album_artist', 'year', 'genre',
                     'track_number', 'disc_number', 'duration_secs', 'bitrate',
                     'sample_rate', 'channels', 'composer', 'comment'] as $field
        ) {
            if (isset($tags[$field])) {
                $metadata[$field] = $tags[$field];
            }
        }

        return $metadata;
    }

    /**
     * Retrieves a library by its unique identifier.
     *
     * @param string $id The library's unique identifier
     * @return array<string, mixed>|null Library data array with 'paths' and 'options' decoded
     */
    public function getLibrary(string $id): ?array
    {
        return $this->fetchLibraryRow($id)?->toArray();
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
     * Gets all artists from a music library.
     *
     * Groups tracks by artist name from metadata and returns artist list.
     *
     * @param string $libraryId The library's unique identifier
     * @return array<int, array<string, mixed>> Array of artist data
     */
    public function getArtists(string $libraryId): array
    {
        $items = $this->item_repo->getByType($libraryId, 'track');

        $artists = [];
        foreach ($items as $item) {
            $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
            $rawArtist = $metadata['artist'] ?? 'Unknown Artist';
            $artist = is_string($rawArtist) ? $rawArtist : 'Unknown Artist';
            if (!isset($artists[$artist])) {
                $artists[$artist] = [
                    'name' => $artist,
                    'album_count' => 0,
                    'track_count' => 0,
                    'albums' => [],
                ];
            }
            $artists[$artist]['track_count']++;
            $rawAlbum = $metadata['album'] ?? 'Unknown Album';
            $album = is_string($rawAlbum) ? $rawAlbum : 'Unknown Album';
            if (!in_array($album, $artists[$artist]['albums'], true)) {
                $artists[$artist]['albums'][] = $album;
                $artists[$artist]['album_count']++;
            }
        }

        return array_values($artists);
    }

    /**
     * Gets all albums from a music library.
     *
     * Groups tracks by album name from metadata and returns album list.
     *
     * @param string $libraryId The library's unique identifier
     * @return array<int, array<string, mixed>> Array of album data
     */
    public function getAlbums(string $libraryId): array
    {
        $items = $this->item_repo->getByType($libraryId, 'track');

        $albums = [];
        foreach ($items as $item) {
            $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
            $rawAlbum = $metadata['album'] ?? 'Unknown Album';
            $album = is_string($rawAlbum) ? $rawAlbum : 'Unknown Album';
            $rawArtist = $metadata['artist'] ?? 'Unknown Artist';
            $artist = is_string($rawArtist) ? $rawArtist : 'Unknown Artist';

            $key = $album . ' - ' . $artist;
            if (!isset($albums[$key])) {
                $albums[$key] = [
                    'name' => $album,
                    'artist' => $artist,
                    'year' => $metadata['year'] ?? null,
                    'track_count' => 0,
                    'tracks' => [],
                ];
            }
            $albums[$key]['track_count']++;
            $albums[$key]['tracks'][] = $item;
        }

        return array_values($albums);
    }

    /**
     * Gets all tracks from a music library.
     *
     * @param string $libraryId The library's unique identifier
     * @param int $limit Maximum number of tracks to return
     * @param int $offset Pagination offset
     * @return array<int, array<string, mixed>> Array of track data
     */
    public function getTracks(string $libraryId, int $limit = 100, int $offset = 0): array
    {
        return $this->item_repo->getByType($libraryId, 'track', $limit, $offset);
    }
}
