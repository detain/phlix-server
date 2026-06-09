<?php

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Shared\Events\Library\LibraryScanCompleted;
use Phlix\Shared\Events\Library\LibraryScanStarted;
use Phlix\Shared\Events\Library\MediaItemAdded;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Extras\TrailerFinder;
use Phlix\Media\Metadata\SceneFilenameNormalizer;
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
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Filesystem scanner for discovering and indexing media files
 * @see ItemRepository For media item persistence
 * @see FolderWatcher For change detection
 */
class MediaScanner
{
    /** @var StructuredLogger Logger instance for structured logging */
    private StructuredLogger $logger;

    /** @var Connection Database connection */
    private Connection $db;

    /** @var array<string, array<string>> File extensions by media type */
    private array $namingOptions;

    /** @var ItemRepository Repository for media item persistence */
    protected ItemRepository $itemRepository;

    /** @var EventDispatcherInterface|null PSR-14 dispatcher for library lifecycle events. */
    private ?EventDispatcherInterface $eventDispatcher;

    /** @var TrailerFinder|null Finder for local trailers */
    private ?TrailerFinder $trailerFinder = null;

    /**
     * Library types whose files hold episodic/movie video content and should be
     * organised into a series → season → episode hierarchy when filenames carry
     * an `SxxExx` marker. Other library types (audio, image, book, …) are passed
     * through unchanged.
     *
     * @var array<int, string>
     */
    private const VIDEO_CONTENT_LIBRARY_TYPES = ['video', 'series', 'movie'];

    /**
     * Per-scan cache of series/season container IDs keyed by their synthetic path,
     * so the many episodes of one show resolve to a single shared series + season
     * without a repository round-trip each time. Reset at the start of every scan.
     *
     * @var array<string, string>
     */
    private array $containerCache = [];

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
     * @param TrailerFinder|null $trailerFinder Optional trailer finder for extras detection
     *
     * @since 0.14.0 TrailerFinder parameter added for extras detection
     */
    public function __construct(
        Connection $db,
        ItemRepository $itemRepository,
        ?StructuredLogger $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?TrailerFinder $trailerFinder = null
    ) {
        $this->db = $db;
        $this->itemRepository = $itemRepository;
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->namingOptions = $this->loadNamingOptions();
        $this->eventDispatcher = $eventDispatcher;
        $this->trailerFinder = $trailerFinder;
    }

    /**
     * Creates a default structured logger for the scanner subsystem.
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
            'book' => ['epub', 'pdf', 'cbz'],
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
     * Checks if an extension represents an image/photo file.
     *
     * @param string $extension File extension (without dot)
     * @return bool True if the extension is a supported image format
     *
     * @since 0.16.0
     */
    public function isImageExtension(string $extension): bool
    {
        $imageExtensions = $this->namingOptions['image'] ?? [];
        return in_array(strtolower($extension), $imageExtensions, true);
    }

    /**
     * Checks if an extension represents a book file.
     *
     * @param string $extension File extension (without dot)
     * @return bool True if the extension is a supported book format
     *
     * @since 0.17.0
     */
    public function isBookExtension(string $extension): bool
    {
        $bookExtensions = $this->namingOptions['book'] ?? [];
        return in_array(strtolower($extension), $bookExtensions, true);
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
        $this->containerCache = [];
        $this->dispatchScanStarted($libraryId, $path);

        $extensions = $this->namingOptions[$type] ?? $this->namingOptions['video'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $scanned = 0;
        $skipped = 0;
        $added = 0;

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
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

        // Parse naming for series/movies (extracts season/episode/episode_title).
        $metadata = $this->parseNaming($file->getFilename(), $type);

        // An SxxExx marker in a video-content library means this file is an
        // episode: type it as such and slot it under a (find-or-created) series
        // → season parent so the library groups by show instead of dumping every
        // episode as its own top-level entry.
        $isEpisode = $this->isVideoContentLibrary($type)
            && isset($metadata['season'], $metadata['episode']);

        if ($isEpisode) {
            $mediaType = 'episode';
            $parentId = $this->resolveEpisodeParent($libraryId, $metadata);
            $name = $this->episodeName($metadata, $file);
        } else {
            $mediaType = $this->determineMediaType($file, $type);
            $parentId = null;
            $name = is_string($metadata['name'] ?? null) && $metadata['name'] !== ''
                ? $metadata['name']
                : $file->getBasename('.' . $file->getExtension());
        }

        // Create media item
        $itemId = $this->itemRepository->create([
            'library_id' => $libraryId,
            'parent_id' => $parentId,
            'name' => $name,
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
     * Determines the media type for a NON-episodic file.
     *
     * Episode detection happens in {@see processFile()} before this is called.
     * Any video-content library ('video', 'series', 'movie') defaults its loose,
     * non-episodic files to 'movie' — a file sitting in a series library that
     * carries no `SxxExx` marker becomes a top-level movie rather than a bogus
     * `type='series'` row (the prior behaviour that made every episode look like
     * its own separate series). Other library types pass through unchanged.
     *
     * @param SplFileInfo $file The file info
     * @param string $libraryType The library type ('video', 'series', 'movie', 'audio', 'image', …)
     * @return string The specific media type ('movie', 'audio', 'image', 'book', …)
     */
    private function determineMediaType(SplFileInfo $file, string $libraryType): string
    {
        if ($this->isVideoContentLibrary($libraryType)) {
            return 'movie';
        }

        return $libraryType;
    }

    /**
     * Whether a library type holds movie/episode video content that should be
     * organised into a series → season → episode hierarchy.
     *
     * @param string $libraryType The library type.
     * @return bool True for 'video', 'series' and 'movie' libraries.
     */
    private function isVideoContentLibrary(string $libraryType): bool
    {
        return in_array($libraryType, self::VIDEO_CONTENT_LIBRARY_TYPES, true);
    }

    /**
     * Resolves (find-or-creating) the season container an episode belongs under,
     * creating the owning series container first when needed.
     *
     * Containers are addressed by a deterministic synthetic path
     * (`series:<libraryId>:<slug>` / `season:<libraryId>:<slug>:<n>`) so repeated
     * scans and the many episodes of one show all resolve to the same rows via
     * {@see ItemRepository::findByPath()} — no schema or unique-key changes
     * needed, and incremental rescans attach new episodes to existing shows.
     *
     * @param string               $libraryId Owning library UUID.
     * @param array<string, mixed> $metadata  Parsed episode metadata (expects
     *                                         'name' = series title, 'season' int).
     * @return string The season container's media-item ID (the episode's parent).
     */
    private function resolveEpisodeParent(string $libraryId, array $metadata): string
    {
        $seriesName = is_string($metadata['name'] ?? null) && $metadata['name'] !== ''
            ? $metadata['name']
            : 'Unknown Series';
        $season = isset($metadata['season']) && is_numeric($metadata['season'])
            ? (int) $metadata['season']
            : 0;

        $seriesId = $this->findOrCreateContainer(
            $libraryId,
            'series',
            $seriesName,
            SeriesContainerNaming::seriesPath($libraryId, $seriesName),
            null,
            ['name' => $seriesName]
        );

        $seasonLabel = SeriesContainerNaming::seasonLabel($season);

        return $this->findOrCreateContainer(
            $libraryId,
            'season',
            $seasonLabel,
            SeriesContainerNaming::seasonPath($libraryId, $seriesName, $season),
            $seriesId,
            ['name' => $seasonLabel, 'season' => $season]
        );
    }

    /**
     * Finds an existing container row by its synthetic path or creates one,
     * memoising the ID for the duration of the scan.
     *
     * @param string               $libraryId     Owning library UUID.
     * @param string               $type          'series' or 'season'.
     * @param string               $name          Display name.
     * @param string               $syntheticPath Deterministic addressing path.
     * @param string|null          $parentId      Parent container ID (null for a series).
     * @param array<string, mixed> $metadata      Container metadata.
     * @return string The container's media-item ID.
     */
    private function findOrCreateContainer(
        string $libraryId,
        string $type,
        string $name,
        string $syntheticPath,
        ?string $parentId,
        array $metadata
    ): string {
        if (isset($this->containerCache[$syntheticPath])) {
            return $this->containerCache[$syntheticPath];
        }

        $existing = $this->itemRepository->findByPath($syntheticPath);
        if (is_array($existing) && isset($existing['id']) && is_string($existing['id'])) {
            $this->containerCache[$syntheticPath] = $existing['id'];
            return $existing['id'];
        }

        $id = (string) $this->itemRepository->create([
            'library_id' => $libraryId,
            'parent_id' => $parentId,
            'name' => $name,
            'type' => $type,
            'path' => $syntheticPath,
            'metadata_json' => $metadata,
        ]);

        $this->containerCache[$syntheticPath] = $id;

        return $id;
    }

    /**
     * Picks a human-readable name for an episode row: its episode title when the
     * filename carried one, else the raw filename base (kept distinct per episode
     * rather than repeating the series title).
     *
     * @param array<string, mixed> $metadata Parsed episode metadata.
     * @param SplFileInfo          $file     The source file.
     * @return string Episode display name.
     */
    private function episodeName(array $metadata, SplFileInfo $file): string
    {
        if (is_string($metadata['episode_title'] ?? null) && $metadata['episode_title'] !== '') {
            return $metadata['episode_title'];
        }
        if (is_string($metadata['raw_filename'] ?? null) && $metadata['raw_filename'] !== '') {
            return $metadata['raw_filename'];
        }
        return $file->getBasename('.' . $file->getExtension());
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

        $name = pathinfo($filename, PATHINFO_FILENAME);
        $normalized = SceneFilenameNormalizer::normalize($name);

        $metadata['raw_filename'] = $name;

        if ($normalized['title'] !== '') {
            $metadata['name'] = $normalized['title'];
        } else {
            $metadata['name'] = $name;
        }

        if ($type === 'movie' && $normalized['year'] !== null) {
            $metadata['year'] = (string) $normalized['year'];
        }

        // Series pattern: Series S01E01 or Series - S01E01 - Episode Title
        if (preg_match('/^(.+?)\s*S(\d{2})E(\d{2})/i', $name, $matches)) {
            // Normalise scene separators (dots/underscores → spaces) and strip any
            // trailing separators so "24.", "24 -" etc. collapse to "24".
            $seriesTitle = (string) preg_replace('/[._]+/', ' ', $matches[1]);
            $seriesTitle = (string) preg_replace('/\s+/', ' ', $seriesTitle);
            $seriesTitle = trim($seriesTitle, " -._\t\n");
            $metadata['name'] = $seriesTitle !== '' ? $seriesTitle : trim($matches[1]);
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
        $first = $rows[0] ?? null;
        if (!is_array($first)) {
            return '';
        }
        $name = $first['name'] ?? '';
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

    /**
     * Check if a directory contains a Trailers/ subfolder or trailer files.
     *
     * Detects both:
     * - <mediaDir>/Trailers/ subfolder
     * - <mediaDir>/<name>-trailer.mkv files at same level
     *
     * @param string $mediaDir The media directory path
     * @param string $mediaFilename The main media filename
     *
     * @return bool True if trailers are detected
     *
     * @since 0.14.0
     */
    public function hasTrailers(string $mediaDir, string $mediaFilename): bool
    {
        // Use trailerFinder if available for better detection
        if ($this->trailerFinder !== null) {
            $trailers = $this->trailerFinder->findLocalTrailers($mediaDir, $mediaFilename);
            return count($trailers) > 0;
        }

        // Fallback: check for Trailers/ subfolder
        $trailersFolder = rtrim($mediaDir, '/') . '/Trailers';
        if (is_dir($trailersFolder)) {
            return $this->hasTrailerFiles($trailersFolder);
        }

        // Check for same-level trailer files
        $baseName = pathinfo($mediaFilename, PATHINFO_FILENAME);
        $extensions = $this->namingOptions['video'] ?? [];
        foreach ($extensions as $ext) {
            $trailerFile = $mediaDir . '/' . $baseName . '-trailer.' . $ext;
            if (file_exists($trailerFile)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a directory contains trailer video files.
     *
     * @param string $dir The directory to check
     *
     * @return bool True if trailer files are found
     */
    private function hasTrailerFiles(string $dir): bool
    {
        $extensions = $this->namingOptions['video'] ?? [];
        $iterator = new \DirectoryIterator($dir);

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (in_array($extension, $extensions, true)) {
                // Check if filename contains trailer-like suffix
                $baseName = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                $lowerName = strtolower($baseName);
                if (
                    str_contains($lowerName, 'trailer')
                    || str_contains($lowerName, 'teaser')
                    || str_contains($lowerName, 'clip')
                    || str_contains($lowerName, 'featurette')
                    || str_contains($lowerName, 'behind')
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}
