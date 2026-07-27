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
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Library\Dto\LibraryRow;
use Phlix\Media\Metadata\MetadataManager;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * MusicLibraryManager orchestrates music library scanning, tag harvesting,
 * and metadata enrichment.
 *
 * This class provides the main interface for managing music libraries,
 * coordinating between the AudioScanner for tag harvesting, MetadataManager
 * for metadata enrichment, and ItemRepository for persistence.
 *
 * ⚠ **This class does NOT read the music hierarchy — `music_*` does (S97).** It
 * used to carry `getArtists()`, `getAlbums()` and `getTracks()`, which grouped raw
 * `media_items` rows by `metadata_json.$.artist` / `.album`. The music scanner
 * stamps only `{"name","sub_type"}` there, so those keys were never populated and
 * every row collapsed into one `'Unknown Artist'` / `'Unknown Album'`. S99 already
 * repointed `/api/v1/music/*` at {@see \Phlix\Media\Music\MusicLibraryService}
 * (the normalized `music_artists` / `music_albums` / `music_tracks` tables, whose
 * FKs are `NOT NULL` and enforced), leaving the three methods with zero production
 * callers; S97 deleted them so exactly one music read path exists. Add music reads
 * to `MusicLibraryService`, never here.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Manages music library operations including scanning, tag parsing, and metadata enrichment
 * @see AudioScanner For audio file scanning and tag harvesting
 * @see MetadataManager For metadata provider coordination
 * @see ItemRepository For media item persistence
 */
class MusicLibraryManager
{
    /**
     * @var LoggerInterface Logger instance.
     *
     * Typed on the PSR-3 interface, not on `StructuredLogger` — the same fix S96(a)
     * applied to {@see \Phlix\Media\Music\MusicLibraryScanner}, for the same reason
     * (review r2 F2 found this class to be a SECOND, unfixed copy of that defect). The
     * narrow type is half of the mechanism: it forced every caller that holds a plain
     * PSR-3 logger to pass `null` instead, which sent this class down the private
     * temp-dir branch. Only PSR-3 methods are used here.
     */
    private LoggerInterface $logger;

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
     * @param LoggerInterface|null $logger Optional custom logger. WIDENED from
     *        `?StructuredLogger` (review r2 F2): the narrow type made
     *        {@see \Phlix\Media\Music\MusicLibraryType::getLibraryManager()} discard any
     *        other PSR-3 logger and pass `null`, which is what put this subsystem's log
     *        into a private temp directory.
     * @param EventDispatcherInterface|null $eventDispatcher Optional PSR-14 dispatcher
     */
    public function __construct(
        AudioScanner $scanner,
        MetadataManager $metadata,
        ItemRepository $item_repo,
        Connection $db,
        ?LoggerInterface $logger = null,
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
     * The shared MEDIA-channel logger — NOT a private one in a temp directory.
     *
     * ⚠ **THIS WAS A SECOND, UNFIXED COPY OF THE S96(a) DEFECT (review r2 F2), and it
     * is why the step's acceptance criterion "no new `/tmp/phlix_music_*` dirs are
     * created" was objectively UNMET.** The old body did
     * `mkdir(sys_get_temp_dir() . '/phlix_music_' . uniqid())` on EVERY construction and
     * pointed a private `StructuredLogger` at `music_manager.log` inside it — same
     * mechanism, same subsystem, and matching the criterion's own glob. S96(a) fixed the
     * copy in `MusicLibraryScanner`; the reviewer's controlled measurement showed the
     * scanner leaking **+0** dirs per suite run while this class leaked **+11** (1,859
     * already on the dev box). Both halves of the defect were present here too: the
     * temp-dir mint AND a `?StructuredLogger` parameter that made the only caller
     * discard any other PSR-3 logger.
     *
     * Production exposure was dormant rather than absent — `MusicLibraryType::
     * getLibraryManager()` has no caller today — but `LibraryTypeInterface` is the
     * declared plugin contract for library types, so the day anything wires it, this
     * subsystem's logging would have gone back inside the unit's `PrivateTmp`.
     *
     * @return LoggerInterface The shared MEDIA channel logger. `config/logger.php`
     *         routes it to `.logs/app.log` at every level and `.logs/error.log` for
     *         errors — an install-dir path, readable without `nsenter`, surviving a
     *         restart, and creating no directory at all.
     */
    private function createDefaultLogger(): LoggerInterface
    {
        return LoggerFactory::get(LogChannels::MEDIA);
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
        // hrtime(), not microtime() — monotonic elapsed interval (review r2 F6).
        $startTime = hrtime(true);

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
                // Music tracks are type='track', one of the six types migration 087's
                // `path_hash` expression covers, so scope to the library: findByPath's
                // fast path_hash pass resolves a track, but only as a POINT lookup when
                // `library_id` (the composite index's leading column) is bound — and a
                // miss re-creates every track as a duplicate on each rescan.
                // ⚠ This comment used to call `track` a NON-deduped type with a NULL
                // path_hash — true under migration 072, FALSE since 087.
                $existing = $this->item_repo->findByPath($itemPath, $libraryId);
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

        $result->durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000.0);

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

        // Check for existing item. `track` is covered by migration 087's `path_hash`
        // expression — scope to the library so findByPath's fast pass is a point
        // lookup on `(library_id, path_hash)` instead of an unindexed scan.
        $existing = $this->item_repo->findByPath($path, $libraryId);

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
}
