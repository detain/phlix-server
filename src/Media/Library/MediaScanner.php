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
use Phlix\Media\Transcoding\FfmpegRunner;
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
     * Optional ffprobe runner used to read each time-based file's total
     * duration during the scan, so the player's scrubber knows the full
     * length immediately (rather than growing as an in-progress transcode
     * manifest fills). Null in tests/callers that do not wire it up; a probe
     * failure never aborts the scan.
     *
     * @var FfmpegRunner|null
     */
    private ?FfmpegRunner $ffmpeg = null;

    /**
     * Effective trailing-edition noise-suffix list applied to parsed titles
     * before metadata matching (admin-extensible via the
     * `matching.noise_suffixes` server setting, merged over the
     * `config/matching.php` default by the DI provider). Resolved once at
     * construction and forwarded to {@see SceneFilenameNormalizer::normalize()}
     * and {@see EpisodeFilenameParser::parse()}; never mutated after construction.
     * Null when the caller does not inject one — both parsers then fall back to
     * the built-in {@see \Phlix\Media\Metadata\TitleSuffixStripper::NOISE_SUFFIXES}.
     *
     * @var list<string>|null
     */
    private ?array $noiseSuffixes = null;

    /**
     * Concrete media-item types whose source files carry a meaningful total
     * playback duration worth probing during the scan. Image/book/photo types
     * have no duration and are never probed.
     *
     * @var array<int, string>
     */
    private const DURATION_PROBE_TYPES = ['video', 'movie', 'episode', 'audio'];

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
     * @param FfmpegRunner|null $ffmpeg Optional ffprobe runner; when supplied,
     *                           each time-based (video/movie/episode/audio) file
     *                           has its total duration probed during the scan and
     *                           stored under metadata_json['duration_seconds'].
     *                           Defaults to null so callers/tests not exercising
     *                           duration probing need not wire one up.
     * @param list<string>|null $noiseSuffixes Effective trailing-edition noise list
     *                           (admin-extensible via `matching.noise_suffixes`,
     *                           merged over `config/matching.php` by the DI
     *                           provider). Forwarded to the filename parsers; a
     *                           null/empty value falls back to the built-in const.
     *
     * @since 0.14.0 TrailerFinder parameter added for extras detection
     */
    public function __construct(
        Connection $db,
        ItemRepository $itemRepository,
        ?StructuredLogger $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?TrailerFinder $trailerFinder = null,
        ?FfmpegRunner $ffmpeg = null,
        ?array $noiseSuffixes = null
    ) {
        $this->db = $db;
        $this->itemRepository = $itemRepository;
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->namingOptions = $this->loadNamingOptions();
        $this->eventDispatcher = $eventDispatcher;
        $this->trailerFinder = $trailerFinder;
        $this->ffmpeg = $ffmpeg;
        // Drop a null/empty injected list so the parsers fall back to the
        // built-in const (an empty admin override must never blank the list).
        $this->noiseSuffixes = ($noiseSuffixes === null || $noiseSuffixes === [])
            ? null
            : array_values($noiseSuffixes);
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
     * @param bool   $seriesPerDirectory When true (series libraries only), each
     *               immediate child directory of $path is treated as exactly one
     *               series: the directory name supplies the authoritative series
     *               title + year, and EVERY episode file beneath it attaches to
     *               that single series regardless of its filename's title text.
     * @return void
     *
     * @example
     * ```php
     * $scanner->scan('library-123', '/mnt/media/movies', 'video');
     * ```
     */
    public function scan(
        string $libraryId,
        string $path,
        string $type,
        bool $seriesPerDirectory = false,
        ?callable $onFile = null
    ): void {
        if (!is_dir($path)) {
            $this->logger->warning('Scan path does not exist', ['path' => $path]);
            return;
        }

        $startMs = (int)(microtime(true) * 1000);
        $this->containerCache = [];
        $this->dispatchScanStarted($libraryId, $path);

        $extensions = $this->namingOptions[$type] ?? $this->namingOptions['video'];

        if ($seriesPerDirectory && $type === 'series') {
            $added = $this->scanSeriesPerDirectory($libraryId, $path, $type, $extensions, $onFile);
        } else {
            $added = $this->scanFlat($libraryId, $path, $type, $extensions, null, $onFile);
        }

        $endMs = (int)(microtime(true) * 1000);
        $this->dispatchScanCompleted($libraryId, $added, $endMs - $startMs);
    }

    /**
     * Count the media files under $path that a {@see scan()} of $type would
     * process — the files passing the extension + skip filters. Used to derive
     * the *denominator* for a scan/rescan progress percentage before the walk
     * begins. A single recursive pass; the same set is produced for both flat
     * and series-per-directory scans (both ultimately process every media file
     * beneath $path).
     *
     * @param string $path Directory to count beneath.
     * @param string $type Library type whose extension set applies.
     *
     * @return int Number of media files that would be processed (0 if absent).
     *
     * @since 0.34.0
     */
    public function countFiles(string $path, string $type): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $extensions = $this->namingOptions[$type] ?? $this->namingOptions['video'];

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->isDir()) {
                continue;
            }
            if (!in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }
            if ($this->shouldSkipFile($file->getFilename())) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * Flat (default) scan: recursively walk $path and process each media file,
     * deriving the series/movie identity from the FILENAME.
     *
     * When $forcedSeries is non-null (series-per-directory mode) every episode
     * found is slotted under that one series container instead of a
     * filename-derived series.
     *
     * When $forcedSeason is non-null (a season/specials subdirectory of a series
     * dir) every episode's season number is FORCED to it — the directory wins
     * over any season the filename also parses — so nested season-folder layouts
     * file correctly even when the filenames carry no (or a wrong) season.
     *
     * @param array<int, string> $extensions Allowed file extensions.
     * @param array{title: string, year: int|null, slug_source?: string}|null $forcedSeries
     *        Forced series identity, or null.
     * @param (callable(string): void)|null $onFile Invoked once per processed
     *        media file with its path, for streaming scan progress.
     * @param int|null $forcedSeason Directory-derived season number to force onto
     *        every episode found (0 = Specials), or null to keep filename-parsed
     *        seasons.
     * @return int Number of items added.
     */
    private function scanFlat(
        string $libraryId,
        string $path,
        string $type,
        array $extensions,
        ?array $forcedSeries,
        ?callable $onFile = null,
        ?int $forcedSeason = null
    ): int {
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

            if ($this->processFile($libraryId, $file, $type, $forcedSeries, $forcedSeason)) {
                $added++;
            }
            $scanned++;
            if ($onFile !== null) {
                $onFile($file->getPathname());
            }
        }

        $this->logger->info('Scan complete', [
            'library_id' => $libraryId,
            'path' => $path,
            'scanned' => $scanned,
            'skipped' => $skipped,
            'added' => $added,
        ]);

        return $added;
    }

    /**
     * Series-per-directory scan: treat each immediate child directory of $path as
     * exactly one series. The directory name (year stripped) is the authoritative
     * series title/year used both for grouping AND as the TMDB match hint; every
     * episode file beneath the directory attaches to that one series.
     *
     * Loose media files sitting directly under the library root (not inside a
     * series subdirectory) are handled gracefully by the normal flat path so a
     * stray file never aborts the scan.
     *
     * @param array<int, string> $extensions Allowed file extensions.
     * @param (callable(string): void)|null $onFile Invoked once per processed
     *        media file with its path, for streaming scan progress.
     * @return int Number of items added.
     */
    private function scanSeriesPerDirectory(
        string $libraryId,
        string $path,
        string $type,
        array $extensions,
        ?callable $onFile = null
    ): int {
        $added = 0;

        $entries = new \DirectoryIterator($path);
        foreach ($entries as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            if ($entry->isDir()) {
                $dirName = $entry->getFilename();
                if ($this->shouldSkipFile($dirName)) {
                    continue;
                }
                $forcedSeries = SeriesContainerNaming::fromDirectoryName($dirName);
                // Carry the FULL directory basename so the synthetic series/season
                // paths are slugged from it: two sibling folders that differ only
                // by year or punctuation ("The Office (2005)" vs
                // "The Office (2001)", "Re:Zero" vs "Re Zero") must NOT collapse
                // into one container (which would silently merge episodes).
                $forcedSeries['slug_source'] = $dirName;
                $added += $this->scanSeriesDir(
                    $libraryId,
                    $entry->getPathname(),
                    $type,
                    $extensions,
                    $forcedSeries,
                    $onFile
                );
                continue;
            }

            // A loose file directly under the library root: process it as a
            // single file via the normal (filename-derived) path so it neither
            // crashes nor gets force-grouped under a bogus series.
            if ($entry->isFile()) {
                $extension = strtolower($entry->getExtension());
                if (!in_array($extension, $extensions, true)) {
                    continue;
                }
                if ($this->shouldSkipFile($entry->getFilename())) {
                    continue;
                }
                $file = new SplFileInfo($entry->getPathname());
                if ($this->processFile($libraryId, $file, $type, null)) {
                    $added++;
                }
                if ($onFile !== null) {
                    $onFile($entry->getPathname());
                }
            }
        }

        $this->logger->info('Series-per-directory scan complete', [
            'library_id' => $libraryId,
            'path' => $path,
            'added' => $added,
        ]);

        return $added;
    }

    /**
     * Scan ONE series directory, classifying its immediate subdirectories as
     * season/specials/loose/skip and forcing the season number for episodes that
     * live inside a season or specials folder.
     *
     * Layout handled:
     *   Series (2000)/
     *     Season 1/  Specials/  OVAs/        → season-forced episode scans
     *     Movies (1993-98)/                  → loose scan (no forced season)
     *     Other Shows You'd Like, HERE/      → skipped (junk pointer dir)
     *     Series (2000) S01E01.mkv           → files directly under the series dir
     *
     * Files sitting directly under the series directory (no season subfolder)
     * keep today's behaviour: a plain (filename-derived) episode/movie scan under
     * the forced series. To avoid double-processing, the season/loose SUBDIRS are
     * scanned explicitly and the series-dir-level files are walked NON-recursively
     * (the recursive walk would otherwise re-enter the already-scanned subdirs;
     * findByPath() would dedup them, but the season assignment would be nondeterministic).
     *
     * @param array<int, string> $extensions Allowed file extensions.
     * @param array{title: string, year: int|null, slug_source?: string} $forcedSeries
     *        Folder-derived series identity for this directory.
     * @param (callable(string): void)|null $onFile Progress callback.
     * @return int Number of items added.
     */
    private function scanSeriesDir(
        string $libraryId,
        string $seriesDir,
        string $type,
        array $extensions,
        array $forcedSeries,
        ?callable $onFile = null
    ): int {
        $added = 0;

        $entries = new \DirectoryIterator($seriesDir);
        foreach ($entries as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            // Immediate subdirectory → classify as season/specials/loose/skip.
            if ($entry->isDir()) {
                $subName = $entry->getFilename();
                if ($this->shouldSkipFile($subName)) {
                    continue;
                }
                $subPath = $entry->getPathname();
                // The junk-vs-loose disambiguation needs to know whether the dir
                // holds any scannable media; compute it lazily only when the name
                // did not already resolve to season/specials.
                $classification = SeasonDirectoryClassifier::classify(
                    $subName,
                    $this->directoryHasMedia($subPath, $extensions)
                );

                switch ($classification['type']) {
                    case 'season':
                    case 'specials':
                        $season = $classification['type'] === 'specials'
                            ? 0
                            : ($classification['season'] ?? 1);
                        $added += $this->scanFlat(
                            $libraryId,
                            $subPath,
                            $type,
                            $extensions,
                            $forcedSeries,
                            $onFile,
                            $season
                        );
                        break;
                    case 'loose':
                        // Holds media but is not a season — scan without forcing a
                        // season (filename parsing / today's behaviour).
                        $added += $this->scanFlat(
                            $libraryId,
                            $subPath,
                            $type,
                            $extensions,
                            $forcedSeries,
                            $onFile
                        );
                        break;
                    case 'skip':
                    default:
                        $this->logger->debug('Skipping non-season directory in series dir', [
                            'series_dir' => $seriesDir,
                            'subdir' => $subName,
                        ]);
                        break;
                }
                continue;
            }

            // A file directly under the series directory (no season subfolder):
            // keep today's filename-derived behaviour under the forced series.
            if ($entry->isFile()) {
                $extension = strtolower($entry->getExtension());
                if (!in_array($extension, $extensions, true)) {
                    continue;
                }
                if ($this->shouldSkipFile($entry->getFilename())) {
                    continue;
                }
                $file = new SplFileInfo($entry->getPathname());
                if ($this->processFile($libraryId, $file, $type, $forcedSeries)) {
                    $added++;
                }
                if ($onFile !== null) {
                    $onFile($entry->getPathname());
                }
            }
        }

        return $added;
    }

    /**
     * Whether a directory contains at least one scannable media file anywhere
     * beneath it. Used only to disambiguate a junk pointer dir (no media → skip)
     * from a loose media dir in {@see SeasonDirectoryClassifier::classify()}.
     * Guarded so an unreadable directory never aborts the scan.
     *
     * @param array<int, string> $extensions Allowed file extensions.
     */
    private function directoryHasMedia(string $dir, array $extensions): bool
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || $file->isDir()) {
                    continue;
                }
                if (!in_array(strtolower($file->getExtension()), $extensions, true)) {
                    continue;
                }
                if ($this->shouldSkipFile($file->getFilename())) {
                    continue;
                }
                return true;
            }
        } catch (\Throwable) {
            // Unreadable dir → treat as "no media" is unsafe (would skip a real
            // season we just can't read); return true so the caller falls back to
            // 'loose' and at least attempts a scan rather than silently dropping it.
            return true;
        }

        return false;
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
     * @param array{title: string, year: int|null, slug_source?: string}|null $forcedSeries When set
     *        (series-per-directory mode), the episode is grouped under this
     *        folder-derived series identity instead of the filename-derived one.
     * @param int|null $forcedSeason When set (the file lives in a season/specials
     *        SUBDIRECTORY of a series dir), the episode's season is FORCED to this
     *        value (0 = Specials) — the directory wins over any filename-parsed
     *        season, and the file is treated as an episode even when the filename
     *        carries no SxxExx marker (it is nested under a season folder).
     *
     * @return bool True when a new item was added to the repository; false
     *              when the file was already known and was skipped.
     */
    private function processFile(
        string $libraryId,
        SplFileInfo $file,
        string $type,
        ?array $forcedSeries = null,
        ?int $forcedSeason = null
    ): bool {
        $path = $file->getPathname();

        // Check if already exists
        $existing = $this->itemRepository->findByPath($path);
        if ($existing) {
            // Re-scan: the row is already indexed, so no new item is added.
            // Backfill a missing total duration for time-based media so the
            // scrubber has the true length even for files indexed before this
            // probe existed (or before they were ever transcoded).
            $this->backfillDuration($path, $existing);
            return false; // Already scanned
        }

        // Parse naming for series/movies (extracts season/episode/episode_title).
        $metadata = $this->parseNaming($file->getFilename(), $type);

        // A season/specials SUBDIRECTORY forces the season number: the directory
        // is the authoritative season for every file beneath it, overriding any
        // season the filename also parsed (nested-season layouts often carry a
        // wrong or absent season in the filename). The file is then treated as an
        // episode even without a filename SxxExx marker — it is physically nested
        // under a season folder. A missing episode number falls back to a stable
        // per-file ordinal derived from the filename so siblings do not collide.
        if ($forcedSeason !== null && $this->isVideoContentLibrary($type)) {
            $metadata['season'] = $forcedSeason;
            if (!isset($metadata['episode']) || !is_numeric($metadata['episode'])) {
                $metadata['episode'] = 0;
            }
        }

        // An SxxExx marker in a video-content library means this file is an
        // episode: type it as such and slot it under a (find-or-created) series
        // → season parent so the library groups by show instead of dumping every
        // episode as its own top-level entry.
        $isEpisode = $this->isVideoContentLibrary($type)
            && isset($metadata['season'], $metadata['episode']);

        if ($isEpisode) {
            $mediaType = 'episode';
            $parentId = $this->resolveEpisodeParent($libraryId, $metadata, $forcedSeries);
            $name = $this->episodeName($metadata, $file);
        } else {
            $mediaType = $this->determineMediaType($file, $type);
            $parentId = null;
            $name = is_string($metadata['name'] ?? null) && $metadata['name'] !== ''
                ? $metadata['name']
                : $file->getBasename('.' . $file->getExtension());

            // Stamp a canonical dedup key on every TOP-LEVEL (parent-less) item —
            // here the movie/loose-file path. The key is title + year (no external
            // ids are known at scan time; metadata matching runs later). It both
            // persists for the later DuplicateFinder/merge pass AND drives the
            // canonical-reuse guard below, so two files whose titles slug
            // differently but key the same do not fork a duplicate top-level row.
            $movieYear = is_numeric($metadata['year'] ?? null) ? (int) $metadata['year'] : null;
            $metadata['canonical_key'] = CanonicalKey::forItem($name, $movieYear, []);
        }

        // Coerce every string to valid UTF-8 before it reaches the utf8mb4
        // columns. A scene filename carrying stray non-UTF-8 bytes (e.g. a
        // Windows-1252 0x9C) otherwise raises MySQL 1366 "Incorrect string
        // value" on INSERT — and because the loop has no per-file guard, that
        // one bad file would abort the entire (re)scan job. See toValidUtf8().
        $name = self::toValidUtf8($name);
        $path = self::toValidUtf8($path);
        $metadata = self::sanitizeMetadata($metadata);

        // Probe and store the precise total duration (seconds) for time-based
        // media so the player's scrubber knows the full length immediately. An
        // int is sanitize-safe, so it is added after sanitizeMetadata(). Never
        // overwrite a duration already present in the parsed metadata.
        if (
            in_array($mediaType, self::DURATION_PROBE_TYPES, true)
            && !(isset($metadata['duration_seconds']) && is_numeric($metadata['duration_seconds']))
        ) {
            $duration = $this->probeDurationSeconds($path);
            if ($duration !== null) {
                $metadata['duration_seconds'] = $duration;
            }
        }

        // Canonical-key fallback for the TOP-LEVEL MOVIE create path: the file's
        // own path missed findByPath() above, but a top-level movie with the SAME
        // canonical key may already exist under a different path (e.g. the same
        // film stored twice with differently-slugging titles, or re-added after a
        // move). Reuse that existing row rather than creating a second top-level
        // movie. Only applies to parent-less items with a non-empty key; episodes
        // keep their season-parent grouping untouched.
        if ($parentId === null) {
            $canonicalKey = isset($metadata['canonical_key']) && is_string($metadata['canonical_key'])
                ? $metadata['canonical_key']
                : '';
            if ($canonicalKey !== '') {
                $byCanonical = $this->itemRepository->findTopLevelByCanonical($libraryId, $mediaType, $canonicalKey);
                if (is_array($byCanonical) && isset($byCanonical['id']) && is_string($byCanonical['id'])) {
                    $this->logger->debug('Reusing existing top-level item by canonical key', [
                        'item_id' => $byCanonical['id'],
                        'canonical_key' => $canonicalKey,
                        'path' => $path,
                    ]);
                    return false;
                }
            }
        }

        // Create media item. Guard the write so a single unrepresentable row
        // (or any other per-file failure) is logged and skipped rather than
        // killing the whole library scan.
        try {
            $itemId = $this->itemRepository->create([
                'library_id' => $libraryId,
                'parent_id' => $parentId,
                'name' => $name,
                'type' => $mediaType,
                'path' => $path,
                'metadata_json' => $metadata,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Skipping media file that failed to persist', [
                'library_id' => $libraryId,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $this->logger->debug('Media file scanned', [
            'item_id' => $itemId,
            'name' => $metadata['name'] ?? 'unknown',
            'type' => $mediaType,
        ]);

        $this->dispatchMediaItemAdded((string)$itemId, $libraryId, $path, $mediaType);

        return true;
    }

    /**
     * Probe a media file's total duration in whole seconds via ffprobe.
     *
     * Returns null when no ffprobe runner is wired, when the probe fails or
     * yields no positive duration, or when any error occurs — a probe failure
     * must NEVER abort the scan, so all throwables are caught and logged.
     * Matches {@see TranscodeManager::persistProbedDuration()}'s key, type and
     * `(int) round((float) $raw)` rounding so the scan- and transcode-time
     * paths agree on the stored value.
     *
     * @param string $path Absolute filesystem path to the media file.
     * @return int|null Total duration in seconds (> 0), or null.
     */
    private function probeDurationSeconds(string $path): ?int
    {
        if ($this->ffmpeg === null) {
            return null;
        }

        try {
            $probe = $this->ffmpeg->probe($path);
            if (!is_array($probe)) {
                return null;
            }
            $format = is_array($probe['format'] ?? null) ? $probe['format'] : [];
            $rawDuration = $format['duration'] ?? null;
            if (!is_numeric($rawDuration)) {
                return null;
            }
            $duration = (int) round((float) $rawDuration);
            return $duration > 0 ? $duration : null;
        } catch (\Throwable $e) {
            $this->logger->debug('Duration probe failed; continuing scan', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Backfill a missing total duration onto an already-indexed media item.
     *
     * Invoked on re-scan for rows that {@see processFile()} would otherwise just
     * skip. Only time-based items (video/movie/episode/audio) that lack a
     * positive `duration_seconds` are probed; the existing metadata is read,
     * merged (never clobbering other keys) and written back in full via
     * {@see ItemRepository::update()}. Fully guarded so a probe or write failure
     * never aborts the scan.
     *
     * @param string               $path     Absolute filesystem path of the file.
     * @param array<string, mixed> $existing Hydrated existing media-item row.
     */
    private function backfillDuration(string $path, array $existing): void
    {
        if ($this->ffmpeg === null) {
            return;
        }

        try {
            $type = $existing['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::DURATION_PROBE_TYPES, true)) {
                return;
            }

            $meta = $this->existingMetadata($existing);
            $existingDuration = $meta['duration_seconds'] ?? null;
            if (is_numeric($existingDuration) && (int) $existingDuration > 0) {
                return; // Already has a positive duration — nothing to do.
            }

            $duration = $this->probeDurationSeconds($path);
            if ($duration === null) {
                return;
            }

            $id = $existing['id'] ?? null;
            if (!is_string($id) || $id === '') {
                return;
            }

            $meta['duration_seconds'] = $duration;
            $this->itemRepository->update($id, ['metadata_json' => $meta]);
        } catch (\Throwable $e) {
            $this->logger->debug('Duration backfill failed; continuing scan', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Coerce a string to valid UTF-8 so it can be stored in the utf8mb4 schema.
     *
     * Returns the value unchanged when it is already valid UTF-8 (the common
     * case, no allocation). Otherwise it assumes Windows-1252 — the usual source
     * of stray high bytes like 0x9C ("œ") in scene filenames — and converts,
     * falling back to dropping invalid byte sequences if that still is not
     * clean. mb-safe throughout: no byte-mask trim() that could chop a
     * multibyte character mid-sequence.
     */
    private static function toValidUtf8(string $value): string
    {
        if ($value === '' || preg_match('//u', $value) === 1) {
            return $value;
        }

        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
        if (is_string($converted) && preg_match('//u', $converted) === 1) {
            return $converted;
        }

        return (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    /**
     * Recursively coerce every string in a metadata array to valid UTF-8 so the
     * whole structure can be json_encode()d into the metadata_json column.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private static function sanitizeMetadata(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (is_string($value)) {
                $metadata[$key] = self::toValidUtf8($value);
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $metadata[$key] = self::sanitizeMetadata($value);
            }
        }

        return $metadata;
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
     * @param string               $libraryId    Owning library UUID.
     * @param array<string, mixed> $metadata     Parsed episode metadata (expects
     *                                            'name' = series title, 'season' int).
     * @param array{title: string, year: int|null, slug_source?: string}|null $forcedSeries When set
     *        (series-per-directory mode), the folder-derived series title/year is
     *        used as the authoritative series identity + TMDB match hint instead
     *        of the noisy filename-derived title; every episode beneath the same
     *        directory therefore resolves to ONE shared series container. The
     *        optional `slug_source` (the full directory basename) is what the
     *        synthetic path is slugged from, so sibling folders that differ only
     *        by year/punctuation never collide.
     * @return string The season container's media-item ID (the episode's parent).
     */
    private function resolveEpisodeParent(string $libraryId, array $metadata, ?array $forcedSeries = null): string
    {
        $slugSource = null;
        if ($forcedSeries !== null && $forcedSeries['title'] !== '') {
            $seriesName = $forcedSeries['title'];
            $seriesMeta = ['name' => $seriesName, 'series_title' => $seriesName];
            if ($forcedSeries['year'] !== null) {
                $seriesMeta['year'] = $forcedSeries['year'];
            }
            // Slug the FULL directory basename (which already carries the year)
            // rather than the bare title, so two distinct sibling directories
            // never resolve to the same synthetic container path.
            if (isset($forcedSeries['slug_source']) && is_string($forcedSeries['slug_source'])) {
                $slugSource = $forcedSeries['slug_source'];
            }
        } else {
            $seriesName = is_string($metadata['name'] ?? null) && $metadata['name'] !== ''
                ? $metadata['name']
                : 'Unknown Series';
            $seriesMeta = ['name' => $seriesName];
        }

        $season = isset($metadata['season']) && is_numeric($metadata['season'])
            ? (int) $metadata['season']
            : 0;

        // Canonical dedup key for the SERIES container (top-level): the parsed
        // series title + the folder-derived year (when known). The forced-series
        // year is the strongest signal we have at this point — episode filenames
        // carry no external ids, so the key is title-based (+ year). Persisting it
        // lets a later scan whose filename slugs differently (separators, parse
        // variance, a flat→per-directory re-scan) resolve to THIS same container
        // instead of forking a second show. Seasons are NOT keyed (they are not
        // top-level and are addressed by their stable synthetic season path).
        $seriesYear = isset($seriesMeta['year']) && is_int($seriesMeta['year'])
            ? $seriesMeta['year']
            : null;
        $seriesMeta['canonical_key'] = CanonicalKey::forItem($seriesName, $seriesYear, []);

        $seriesId = $this->findOrCreateContainer(
            $libraryId,
            'series',
            $seriesName,
            SeriesContainerNaming::seriesPath($libraryId, $seriesName, $slugSource),
            null,
            $seriesMeta
        );

        $seasonLabel = SeriesContainerNaming::seasonLabel($season);

        return $this->findOrCreateContainer(
            $libraryId,
            'season',
            $seasonLabel,
            SeriesContainerNaming::seasonPath($libraryId, $seriesName, $season, $slugSource),
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
            // Idempotency (series-per-directory activation): the synthetic series
            // path is stable across scans, so an ALREADY-scanned container would
            // otherwise never receive the folder-derived `series_title`/`year`
            // hint and the matcher would fall back to the noisy filename title.
            // When this resolve carries a hint, ensure the existing row's metadata
            // carries the current hint — merging (never clobbering tmdb_id/poster/
            // overview/…), and only writing when it is missing or has changed.
            $this->ensureContainerHint($existing['id'], $existing, $metadata);
            return $existing['id'];
        }

        // Canonical-key fallback (top-level containers only). The exact synthetic
        // path missed, but a top-level row with the SAME canonical key may already
        // exist under a DIFFERENT path — because an earlier scan slugged the title
        // differently (separators, year bleed, a parse failure, or a
        // flat→per-directory re-scan). Reuse that existing container instead of
        // forking a duplicate show, and memoise it under THIS synthetic path so
        // every later episode in this scan resolves to it without a re-query.
        // Seasons (parent_id != null) are intentionally excluded — they are
        // addressed solely by their stable synthetic season path.
        if ($parentId === null) {
            $canonicalKey = isset($metadata['canonical_key']) && is_string($metadata['canonical_key'])
                ? $metadata['canonical_key']
                : '';
            if ($canonicalKey !== '') {
                $byCanonical = $this->itemRepository->findTopLevelByCanonical($libraryId, $type, $canonicalKey);
                if (is_array($byCanonical) && isset($byCanonical['id']) && is_string($byCanonical['id'])) {
                    $this->containerCache[$syntheticPath] = $byCanonical['id'];
                    $this->ensureContainerHint($byCanonical['id'], $byCanonical, $metadata);
                    return $byCanonical['id'];
                }
            }
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
     * Idempotently stamp the folder-derived `series_title`/`year` hint onto an
     * EXISTING container's metadata so series-per-directory activation works via
     * a plain rescan (no purge required).
     *
     * Only the hint keys that the resolve supplies are considered. The existing
     * metadata is merged — unrelated keys (tmdb_id, poster_url, overview, …) are
     * preserved — and a write is issued ONLY when the hint is missing or differs
     * from what is already stored, so repeated scans of an up-to-date container
     * perform no work.
     *
     * @param string               $id       Existing container media-item ID.
     * @param array<string, mixed> $existing Hydrated existing container row.
     * @param array<string, mixed> $metadata The resolve metadata (may carry
     *                                        `series_title`/`year` hint keys).
     */
    private function ensureContainerHint(string $id, array $existing, array $metadata): void
    {
        // Nothing to stamp unless this resolve carries the folder-derived hint.
        if (!array_key_exists('series_title', $metadata)) {
            return;
        }

        $existingMeta = $this->existingMetadata($existing);

        $patch = [];
        if (($existingMeta['series_title'] ?? null) !== $metadata['series_title']) {
            $patch['series_title'] = $metadata['series_title'];
        }
        if (array_key_exists('year', $metadata)) {
            if (($existingMeta['year'] ?? null) !== $metadata['year']) {
                $patch['year'] = $metadata['year'];
            }
        }

        if ($patch === []) {
            return; // Already up to date — stay idempotent, no write.
        }

        // Merge over the existing metadata so tmdb_id/poster/overview/genres/etc.
        // are never clobbered, then persist the full blob.
        $merged = array_merge($existingMeta, $patch);
        $this->itemRepository->update($id, ['metadata_json' => $merged]);
    }

    /**
     * Extracts the decoded metadata array from a (possibly raw) container row.
     *
     * Accepts both the hydrated `metadata` key (real {@see ItemRepository}) and a
     * `metadata_json` value that may be a decoded array or a JSON string.
     *
     * @param array<string, mixed> $row Container row.
     * @return array<string, mixed> Decoded metadata (empty when none).
     */
    private function existingMetadata(array $row): array
    {
        $meta = $row['metadata'] ?? $row['metadata_json'] ?? null;
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($meta)) {
            return [];
        }
        $out = [];
        foreach ($meta as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }
        return $out;
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
        $normalized = SceneFilenameNormalizer::normalize($name, $this->noiseSuffixes);

        $metadata['raw_filename'] = $name;

        if ($normalized['title'] !== '') {
            $metadata['name'] = $normalized['title'];
        } else {
            $metadata['name'] = $name;
        }

        if ($type === 'movie' && $normalized['year'] !== null) {
            $metadata['year'] = (string) $normalized['year'];
        }

        // Episode detection across the many real-world naming styles (S01E02,
        // "S01 E02", "1x02", absolute "Show - 394"/"Show 125"). Absolute
        // numbering is only honoured in series libraries so a movie like
        // "Blade Runner 2049" is never read as episode 2049.
        $episode = EpisodeFilenameParser::parse($name, $type === 'series', $this->noiseSuffixes);
        if ($episode !== null) {
            $metadata['name'] = $episode['series'];
            $metadata['season'] = $episode['season'];
            $metadata['episode'] = $episode['episode'];
            if ($episode['episode_title'] !== null) {
                $metadata['episode_title'] = $episode['episode_title'];
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
