<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Shared\Events\Library\LibraryScanCompleted;
use Phlix\Shared\Events\Library\LibraryScanStarted;
use Phlix\Shared\Events\Library\MediaItemAdded;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\CollectionService;
use Phlix\Media\Extras\TrailerFinder;
use Phlix\Media\MarkerService;
use Phlix\Media\MarkerType;
use Phlix\Media\Markers\ChapterMarkerService;
use Phlix\Media\Markers\MarkerService as MarkersMarkerService;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\MediaAsset\MediaAssetJob;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
use Phlix\Media\Metadata\SceneFilenameNormalizer;
use Phlix\Media\SimilarityJob;
use Phlix\Media\SimilarityJobStore;
use Phlix\Media\SimilarityService;
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

    /** @var SimilarityService|null Computes item similarity after scan; null when not wired */
    private ?SimilarityService $similarityService = null;

    /** @var CollectionService|null Syncs TMDB box-set collections after scan; null when not wired */
    private ?CollectionService $collectionService = null;

    /**
     * S33: per-scan gate for TMDB box-set auto-collection generation, set once at
     * the top of {@see scan()} from the library's
     * `options.autoCollections.enabled` flag (resolved by the caller via
     * {@see \Phlix\Media\Library\Dto\LibraryRow::autoCollectionsEnabled()}).
     *
     * Defaults to true so the historical unconditional behaviour is preserved for
     * any caller/library that does not set it; only an explicit `false` skips the
     * per-item {@see CollectionService::syncCollectionForMovie()} block. This is
     * per-scan mutable instance state, exactly like {@see $containerCache} — a
     * single {@see scan()} call runs to completion for one library before the
     * next, so it is never shared across concurrent libraries.
     *
     * @var bool
     */
    private bool $autoCollectionsEnabled = true;

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
     * SV-1.3: Optional job store for async chapter-thumbnail + trickplay
     * generation. When set, the inline generation code in processFile()
     * is replaced by a lightweight enqueue; the actual ffmpeg work runs
     * later in a bounded-concurrency background worker.
     * Null in tests/legacy callers that do not wire it up.
     *
     * @var \Phlix\Media\MediaAsset\MediaAssetJobStore|null
     */
    private \Phlix\Media\MediaAsset\MediaAssetJobStore|null $mediaAssetJobStore = null;

    /**
     * SV-2.9: Optional job store for deferred similarity computation.
     * When set, similarity computation is enqueued as a background job
     * instead of running inline. The actual similarity scoring runs in a
     * bounded-concurrency background worker, preventing O(N²) scan-path
     * behavior for large libraries. Null = inline computation (legacy mode).
     *
     * @var SimilarityJobStore|null
     */
    private ?SimilarityJobStore $similarityJobStore = null;

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
     * S8: bounded concurrency cap for {@see scanFlat()}'s coroutine ffprobe
     * fan-out (`config/ffmpeg.php`'s `max_concurrent_scan_probes`). Only ever
     * consulted when the scan actually runs inside a Swoole coroutine; the
     * non-coroutine (CLI/PHPUnit) fallback probes one file at a time
     * regardless of this value.
     *
     * @var int
     */
    private int $maxConcurrentScanProbes;

    /**
     * Concrete media-item types whose source files carry a meaningful total
     * playback duration worth probing during the scan. Image/book/photo types
     * have no duration and are never probed.
     *
     * `audiobook` belongs here: LibraryManager::scanAudiobookLibrary() scans
     * audiobook libraries through THIS scanner (`scan($id, $path, 'audiobook')`),
     * so {@see determineMediaType()} types those files `'audiobook'` and they
     * were silently skipped for duration despite being long-form audio — the
     * one type where a missing duration is most visible.
     *
     * `track` is deliberately ABSENT. It is never produced here: music
     * libraries route to MusicLibraryManager/AudioScanner
     * (see LibraryManager::scanLibrary()), which does its own tag harvesting
     * and never calls {@see processFile()}. Listing it would be dead config
     * implying a path that does not exist.
     *
     * @var array<int, string>
     */
    private const DURATION_PROBE_TYPES = ['video', 'movie', 'episode', 'audio', 'audiobook'];

    /**
     * S8 default bounded fan-out cap for {@see scanFlat()}'s concurrent
     * ffprobe pool, used when no {@see $maxConcurrentScanProbes} is injected
     * (legacy callers/tests). Mirrors `config/ffmpeg.php`'s
     * `max_concurrent_scan_probes` default.
     */
    private const DEFAULT_MAX_CONCURRENT_SCAN_PROBES = 4;

    /**
     * S8 batch size for {@see scanFlat()}'s two-phase (collect-then-process)
     * walk: candidates are processed in chunks of this size rather than
     * collecting an entire multi-thousand-file library into memory at once,
     * or (the other extreme) probing/looking-up one file at a time. Smaller
     * than {@see DuplicateFinder::DEFAULT_BATCH_SIZE} (500) because each
     * scanFlat candidate may additionally hold open an ffprobe child process
     * for the duration of a coroutine fan-out slot, which is materially
     * heavier per-row than DuplicateFinder's plain SELECT paging.
     */
    private const SCAN_BATCH_SIZE = 200;

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
     * Largest width:height (or height:width) ratio a `video` stream may claim
     * before {@see videoStreamDefects()} calls its geometry implausible and
     * {@see summarizeProbe()} declines to describe the file with it.
     *
     * ⚠ THIS IS A HEURISTIC, NOT A DECODER LIMIT. An earlier revision asserted
     * "an aspect beyond 100:1 is not a picture ffmpeg could have produced", and
     * a companion `MAX_VIDEO_DIMENSION = 65536` claimed 65,536 was "ffmpeg's OWN
     * hard ceiling (`MAX_WIDTH`/`MAX_HEIGHT` in libavcodec)". BOTH claims are
     * FALSE, and both were disproved on this box's ffmpeg 6.1.1: `-f lavfi -i
     * color=s=4096x2 -c:v ffv1` (2048:1) and `-i color=s=70000x1000 -c:v ffv1`
     * each encode, probe AND decode with exit 0. Do not reason about the bound
     * below as if ffmpeg enforced it.
     *
     * The dimension bound was DELETED for that reason: of the 18 measured broken
     * rows it fired on 0 (the not-positive rule catches the ten `1920x0` rows and
     * this ratio catches the eight `4x3841` / `4x5634` ones), while its one
     * demonstrable effect was to take `source` away from a real, decodable
     * 70000x1000 ffv1 file. This bound stays because it is the rule that earns
     * its place on the measurement.
     *
     * What it actually is: an implausibility bound sized against the MEASURED
     * library. The 37,925 codec-carrying `video` rows on production 2026-07-28
     * span aspect 1.02 – 3.00, and the broken headers are 1:960 and 1:1408.
     * 100:1 sits ~33x outside the widest real frame and outside the most extreme
     * content anyone here could name (a 3840x64 signage ribbon, 60:1), so it
     * separates the two measured populations with margin on both sides. Widening
     * or narrowing it is a judgement about content, and the warrant is that
     * measurement — re-measure before changing it.
     *
     * Nothing is DISCARDED on the strength of this bound: a stream that trips it
     * still gets its `media_streams` row (see {@see summarizeProbe()}). It only
     * loses the right to define `metadata_json['source']` — but that loss is
     * PERMANENT, so a false positive does not heal itself. Nothing re-derives an
     * existing `source`: {@see backfillItemSourceMetadata()} returns `'skipped'`
     * without probing once `duration_seconds` and `source` are both populated,
     * and even on the branch that does probe it writes `source` only when it is
     * absent; `StreamProbeBackfill::probeAndReplace()` writes `media_streams` and
     * `streams_probed_at` and never touches `metadata_json`; and
     * `scripts/backfill-source-metadata.php` reselects only items whose
     * `$.source` IS NULL. Only re-creating the item (path change, dedupe,
     * library re-add) re-derives it.
     */
    private const MAX_VIDEO_ASPECT_RATIO = 100.0;

    /**
     * `video` stream defect ids reported by {@see videoStreamDefects()} and
     * logged verbatim so an operator can tell WHICH rule fired on a given
     * stream. Only the two geometry ids disqualify a stream from becoming the
     * primary `$video`; {@see DEFECT_CODEC_ABSENT} is recorded for diagnosis and
     * changes nothing.
     */
    private const DEFECT_CODEC_ABSENT = 'codec_name_absent';
    private const DEFECT_DIMENSION_NOT_POSITIVE = 'dimension_missing_or_not_positive';
    private const DEFECT_ASPECT_BEYOND_BOUND = 'aspect_beyond_bound';

    /**
     * Per-scan cache of series/season container IDs keyed by their synthetic path,
     * so the many episodes of one show resolve to a single shared series + season
     * without a repository round-trip each time. Reset at the start of every scan.
     *
     * @var array<string, string>
     */
    private array $containerCache = [];

    /**
     * S158 — MOVED-FILE ADOPTION CANDIDATES recorded during the current
     * {@see LibraryManager::rescanLibrary()} pass, keyed by the media-item id
     * that would be re-pointed.
     *
     * `null` means adoption tracking is NOT active and nothing is ever recorded.
     * That is the default and it is deliberate: only a caller that will also run
     * {@see LibraryManager::pruneRemovedItems()} in the same pass can decide
     * whether an adoption is safe (see {@see adoptRecordedPath()}), so a plain
     * `scan` job — which never prunes — records nothing and changes nothing.
     *
     * Lifecycle is explicit and bounded: {@see beginAdoptionTracking()} opens the
     * map, the prune drains it one id at a time, and
     * {@see endAdoptionTracking()} discards whatever is left. It is an INSTANCE
     * property, never static, and it is emptied in a `finally` — so a long-lived
     * Workerman worker cannot accumulate rows across rescans.
     *
     * @var array<string, array{
     *     path: string,
     *     probe: array{
     *         duration_seconds: int|null,
     *         source: array<string, mixed>|null,
     *         streams: list<array<string, mixed>>
     *     }|null
     * }>|null
     */
    private ?array $adoptionCandidates = null;

    /**
     * Whether the candidate cap has already been reported for the current
     * tracking window, so a pathological rescan logs once rather than once per
     * file. Reset by {@see beginAdoptionTracking()}.
     */
    private bool $adoptionCapWarned = false;

    /**
     * The same one-shot latch for the PROBE budget.
     *
     * Separate from {@see $adoptionCapWarned} because the two overflows have
     * different consequences and an operator needs to be able to tell them
     * apart: the candidate cap means rows were DELETED, the probe budget means
     * rows were kept but may be MISDESCRIBED. Silence on the second one was a
     * measured review finding — the row is adopted, keeps its old
     * `source`/`duration` while pointing at a genuinely different file, and
     * never self-repairs, so nothing downstream would ever surface it.
     */
    private bool $adoptionProbeCapWarned = false;

    /**
     * Number of candidates in the current window that are carrying a probe
     * summary, so the probe budget can be enforced without walking the map.
     * Reset by {@see beginAdoptionTracking()}.
     */
    private int $adoptionProbesHeld = 0;

    /**
     * Effective ceiling on {@see $adoptionCandidates}; {@see MAX_ADOPTION_CANDIDATES}
     * unless a caller injected a different one.
     */
    private int $maxAdoptionCandidates;

    /**
     * Effective probe-retention budget; {@see MAX_ADOPTION_PROBES} unless a
     * caller injected a different one.
     */
    private int $maxAdoptionProbes;

    /**
     * Hard ceiling on {@see $adoptionCandidates} for ONE rescan pass.
     *
     * Bounds the worst case in a resident worker instead of letting it scale with
     * library size. MEASURED on this box (PHP 8.3.6, 110-character paths): a full
     * 20,000-entry map of path-only candidates is 20.6 MB of live PHP memory,
     * released in full when the window closes.
     *
     * Past the cap the extra rows behave exactly as they did before S158 — the
     * prune deletes them — and one warning says so. The number is deliberately
     * generous because what is being protected here is row IDENTITY and the user
     * data cascading off it; a library-wide reorganisation is precisely the case
     * where losing rows to a stingy cap would be most damaging.
     *
     * DEFAULT only — the effective value is {@see $maxAdoptionCandidates}, which
     * a caller may override via the constructor. That exists so the overflow
     * branch is reachable from a test with a handful of files instead of twenty
     * thousand; nothing in production passes it.
     */
    private const MAX_ADOPTION_CANDIDATES = 20000;

    /**
     * How many of those candidates may additionally retain the probe summary that
     * lets {@see adoptRecordedPath()} re-derive `duration_seconds`,
     * `metadata_json.source` and `media_streams` for a DIFFERENT physical copy.
     *
     * Separate from {@see MAX_ADOPTION_CANDIDATES} because the two properties are
     * not worth the same: the probe payload dominates the entry (MEASURED: 934
     * bytes serialized per candidate with a video + audio probe attached, and a
     * full 20,000-entry map with probes costs 65.6 MB live versus 20.6 MB
     * without). So identity is never traded for memory, and only the best-effort
     * technical re-description is. Past this budget a candidate is still recorded
     * and still adopted — it simply keeps the file-derived metadata it already
     * had, which is what the pre-review revision did for every adoption.
     *
     * Worst case with both limits: ~25 MB, transient, fully released.
     *
     * DEFAULT only — see {@see $maxAdoptionProbes} and the note on
     * {@see MAX_ADOPTION_CANDIDATES} about why it is overridable.
     */
    private const MAX_ADOPTION_PROBES = 2000;

    /**
     * Effective `scanner.ignore_patterns` list used by {@see shouldSkipFile()}.
     *
     * Never null: legacy construction substitutes a store-less instance that
     * reports the shipped defaults. Refreshed at the top of every
     * {@see scan()} / {@see countFiles()} so an admin change applies to the
     * next scan without a restart.
     */
    private ScanIgnorePatterns $ignorePatterns;

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
     * @param int|null $maxConcurrentScanProbes S8: bounded concurrency cap for
     *                           {@see scanFlat()}'s coroutine ffprobe fan-out
     *                           (`config/ffmpeg.php`'s `max_concurrent_scan_probes`).
     *                           Null/non-positive falls back to
     *                           {@see DEFAULT_MAX_CONCURRENT_SCAN_PROBES}.
     * @param SimilarityService|null $similarityService P4-S1: optional similarity
     *                           engine; when supplied, {@see computeSimilarForItem()}
     *                           is called (best-effort) after each newly scanned
     *                           item is indexed so its similarity scores are
     *                           populated without an explicit backfill run.
     * @param CollectionService|null $collectionService P4-S3: optional collection
     *                           sync; when supplied, {@see syncCollectionForMovie()}
     *                           is called (best-effort) after each newly scanned
     *                           movie is indexed so its TMDB box-set membership
     *                           is synced without an explicit backfill run.
     * @param \Phlix\Media\MediaAsset\MediaAssetJobStore|null $mediaAssetJobStore SV-1.3:
     *                           optional job store for async chapter-thumbnail +
     *                           trickplay generation. When supplied, the inline
     *                           ffmpeg generation in processFile() is replaced by
     *                           a lightweight enqueue and the actual generation
     *                           runs later in a bounded-concurrency background
     *                           worker. Null = inline generation (legacy mode).
     * @param SimilarityJobStore|null $similarityJobStore SV-2.9: optional job store
     *                           for deferred similarity computation. When supplied,
     *                           similarity computation is enqueued as a background
     *                           job instead of running inline. Null = inline
     *                           computation (legacy mode).
     * @param ScanIgnorePatterns|null $ignorePatterns Effective
     *                           `scanner.ignore_patterns` list consulted by
     *                           {@see shouldSkipFile()}. Null (legacy
     *                           construction) substitutes a store-less instance
     *                           = {@see ScanIgnorePatterns::DEFAULT_PATTERNS}.
     * @param int|null $maxAdoptionCandidates S158: ceiling on the moved-file
     *                           adoption window. Null (everything in production)
     *                           uses {@see MAX_ADOPTION_CANDIDATES}. Exposed ONLY
     *                           so the overflow branch is reachable from a test
     *                           with a handful of files rather than twenty
     *                           thousand — a test that shrank the constant
     *                           instead would be testing a file it had rewritten.
     *                           A negative value falls back to the default; 0 is
     *                           honoured and means "never adopt".
     * @param int|null $maxAdoptionProbes S158: how many of those candidates may
     *                           retain their probe summary for the file-derived
     *                           re-description. Same rationale, same null/negative
     *                           handling; 0 means "adopt, but never re-describe".
     *
     * @since 0.14.0 TrailerFinder parameter added for extras detection
     * @since 0.35.0 SimilarityService parameter added for P4-S1
     * @since 0.36.0 CollectionService parameter added for P4-S3
     * @since 0.36.0 MediaAssetJobStore parameter added for SV-1.3
     * @since 0.38.0 SimilarityJobStore parameter added for SV-2.9
     */
    public function __construct(
        Connection $db,
        ItemRepository $itemRepository,
        ?StructuredLogger $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?TrailerFinder $trailerFinder = null,
        ?FfmpegRunner $ffmpeg = null,
        ?array $noiseSuffixes = null,
        ?int $maxConcurrentScanProbes = null,
        ?SimilarityService $similarityService = null,
        ?CollectionService $collectionService = null,
        ?\Phlix\Media\MediaAsset\MediaAssetJobStore $mediaAssetJobStore = null,
        ?SimilarityJobStore $similarityJobStore = null,
        ?ScanIgnorePatterns $ignorePatterns = null,
        ?int $maxAdoptionCandidates = null,
        ?int $maxAdoptionProbes = null
    ) {
        $this->db = $db;
        $this->itemRepository = $itemRepository;
        $this->logger = $logger ?? $this->createDefaultLogger();
        // Never null internally: a legacy construction that omits it gets a
        // store-less instance, which yields ScanIgnorePatterns::DEFAULT_PATTERNS.
        $this->ignorePatterns = $ignorePatterns ?? new ScanIgnorePatterns();
        $this->namingOptions = $this->loadNamingOptions();
        $this->eventDispatcher = $eventDispatcher;
        $this->trailerFinder = $trailerFinder;
        $this->ffmpeg = $ffmpeg;
        // Drop a null/empty injected list so the parsers fall back to the
        // built-in const (an empty admin override must never blank the list).
        $this->noiseSuffixes = ($noiseSuffixes === null || $noiseSuffixes === [])
            ? null
            : array_values($noiseSuffixes);
        $this->maxConcurrentScanProbes = ($maxConcurrentScanProbes !== null && $maxConcurrentScanProbes > 0)
            ? $maxConcurrentScanProbes
            : self::DEFAULT_MAX_CONCURRENT_SCAN_PROBES;
        $this->similarityService = $similarityService;
        $this->collectionService = $collectionService;
        $this->mediaAssetJobStore = $mediaAssetJobStore;
        $this->similarityJobStore = $similarityJobStore;
        // `>= 0`, not `> 0`: zero is a meaningful setting for both (never adopt /
        // never re-describe) and is what makes the overflow branches reachable
        // from a test. Only null or a negative falls back to the constant.
        $this->maxAdoptionCandidates = ($maxAdoptionCandidates !== null && $maxAdoptionCandidates >= 0)
            ? $maxAdoptionCandidates
            : self::MAX_ADOPTION_CANDIDATES;
        $this->maxAdoptionProbes = ($maxAdoptionProbes !== null && $maxAdoptionProbes >= 0)
            ? $maxAdoptionProbes
            : self::MAX_ADOPTION_PROBES;
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
     * Loads supported file extensions by EXTENSION CLASS.
     *
     * The keys here are their own vocabulary — `video`, `audio`, `image`,
     * `book`, `audiobook` — and are NOT `libraries.type` values and NOT
     * `media_items.type` values. Note `image`, which the `media_items.type`
     * ENUM spells `photo`. Callers must translate through
     * {@see extensionsForLibraryType()} rather than indexing this map with a
     * library type directly; doing the latter is what left audiobook libraries
     * scanning for video files.
     *
     * @return array<string, array<string>> Extension class to extension list mapping
     */
    private function loadNamingOptions(): array
    {
        $audio = ['mp3', 'flac', 'aac', 'ogg', 'wav', 'm4a', 'wma', 'alac', 'opus'];

        return [
            'video' => ['mkv', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'mpg', 'mpeg', 'ts'],
            'audio' => $audio,
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'tif'],
            'book' => ['epub', 'pdf', 'cbz'],
            // Audiobooks are the audio set PLUS `.m4b`, the dominant audiobook
            // container. `m4b` is deliberately NOT added to the plain `audio`
            // list: that list describes MUSIC files, and an `.m4b` is not a
            // music track. This mirrors AudiobookScanner's own supported set
            // (`m4b`, `m4a`, `mp3` — see AudiobookScanner::…['m4b']).
            'audiobook' => array_merge($audio, ['m4b']),
        ];
    }

    /**
     * Translate a `libraries.type` value into the extension class whose list
     * {@see loadNamingOptions()} holds.
     *
     * ## Three distinct vocabularies share these words — do not conflate them
     *
     *  1. **`libraries.type`** (what `$type` is here): `movie`, `series`,
     *     `music`, `photo`, `video`, `book`, `audiobook`.
     *  2. **`media_items.type`** (the DB ENUM, 13 members): uses `photo`, NOT
     *     `image`. `$type` is ALSO passed through to
     *     {@see determineMediaType()} and ends up typing the created rows, so
     *     the value handed to {@see scan()} must stay in vocabulary 1 — it
     *     cannot simply be rewritten at the call site.
     *  3. **extension classes** (the {@see loadNamingOptions()} keys, 4
     *     members): `video`, `audio`, `image`, `book`. This is a naming-only
     *     vocabulary and uses `image`, NOT `photo`.
     *
     * ## The bug this fixes
     *
     * The lookup used to be `$this->namingOptions[$type] ?? ['video']`, mixing
     * vocabularies 1 and 3 and relying on the fallback for every mismatch.
     * `LibraryManager` hand-translated two cases before calling
     * ({@see \Phlix\Media\Library\LibraryManager::scanPhotoLibrary()} passes
     * `image`, `scanBookLibrary()` passes `book`) but `scanAudiobookLibrary()`
     * passes `audiobook`, which is not a key — so AUDIOBOOK LIBRARIES FELL
     * THROUGH TO VIDEO EXTENSIONS and scanned for `.mkv`/`.mp4`, never for the
     * `.m4b`/`.mp3` files they actually contain.
     *
     * `movie`, `series` and `video` also miss the map, but their fallback to
     * video extensions is CORRECT, so the mapping below makes that explicit
     * rather than incidental. `music` never reaches this class (LibraryManager
     * routes it to MusicLibraryService).
     *
     * @param string $type A `libraries.type` value.
     *
     * @return array<int, string> Extensions to accept for that library type.
     *
     * @since 1.6.0
     */
    private function extensionsForLibraryType(string $type): array
    {
        $class = match ($type) {
            // Audiobooks get the audio set plus `.m4b`; without this they were
            // scanned with VIDEO extensions and matched nothing they contain.
            'audiobook' => 'audiobook',
            // `music` never actually reaches this class (LibraryManager routes
            // it to MusicLibraryService), but mapping it is correct and stops
            // the next caller falling into the same video default.
            'music', 'audio' => 'audio',
            // `libraries.type` says `photo`; the extension class says `image`.
            'photo', 'image' => 'image',
            'book' => 'book',
            // movie / series / video and anything unrecognised.
            default => 'video',
        };

        return $this->namingOptions[$class] ?? $this->namingOptions['video'];
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
     * @param bool   $autoCollectionsEnabled S33: whether TMDB box-set
     *               auto-collection generation runs for this library, from its
     *               `options.autoCollections.enabled` flag. Defaults to true (the
     *               historical unconditional behaviour); pass false to skip the
     *               per-item {@see CollectionService::syncCollectionForMovie()}
     *               block entirely for this scan.
     * @return int Number of items this scan ADDED (S96(b)). Already computed for
     *             {@see self::dispatchScanCompleted()}'s `itemsAdded`; returning it
     *             is what lets {@see LibraryManager::scanLibrary()} put a truthful
     *             `items_added` on the scan-job row, which reported 0 for every
     *             successful scan because nothing propagated the number.
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
        ?callable $onFile = null,
        bool $autoCollectionsEnabled = true
    ): int {
        if (!is_dir($path)) {
            $this->logger->warning('Scan path does not exist', ['path' => $path]);
            return 0;
        }

        $startNs = hrtime(true);
        $this->containerCache = [];
        // S33: latch the per-library auto-collections toggle for this scan so the
        // per-item collection-sync gate (see processFile) can read it without
        // threading the flag through the whole scan call chain.
        $this->autoCollectionsEnabled = $autoCollectionsEnabled;
        // Re-read `scanner.ignore_patterns` once per scan (read-path class (a)
        // LIVE at scan granularity) rather than once per file.
        $this->ignorePatterns->refresh();
        $this->dispatchScanStarted($libraryId, $path);

        $extensions = $this->extensionsForLibraryType($type);

        if ($seriesPerDirectory && $type === 'series') {
            $added = $this->scanSeriesPerDirectory($libraryId, $path, $type, $extensions, $onFile);
        } else {
            $added = $this->scanFlat($libraryId, $path, $type, $extensions, null, $onFile);
        }

        $endNs = hrtime(true);
        $this->dispatchScanCompleted($libraryId, $added, (int)(($endNs - $startNs) / 1_000_000));

        return $added;
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

        // Same list the subsequent scan() will use, so the progress denominator
        // and the walk agree.
        $this->ignorePatterns->refresh();

        $extensions = $this->extensionsForLibraryType($type);

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
     *
     * @since 0.35.0 (S8) Two-phase batch processing: candidates are collected
     *        in chunks of {@see SCAN_BATCH_SIZE}, the already-scanned check is
     *        a single batched {@see ItemRepository::findPathsMap()} query
     *        instead of one {@see ItemRepository::findByPath()} per file, and
     *        brand-new files are probed via a BOUNDED CONCURRENT coroutine
     *        pool (see {@see probeManyConcurrently()}) before the rest of
     *        {@see processFile()}'s naming/dedup/create/persistStreams logic
     *        runs sequentially, in original file order, exactly as before —
     *        only the (read-only, DB-free) ffprobe call is parallelized, so
     *        find-or-create/canonical-key ordering guarantees are unaffected
     *        regardless of whether this scanFlat() call is the flat top-level
     *        scan or a season/specials/loose SUBDIRECTORY walk issued by
     *        {@see scanSeriesDir()} (`$forcedSeries !== null`) — see that
     *        method's docblock for why episode-parent creation itself stays
     *        untouched/sequential.
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

        /** @var list<SplFileInfo> $candidates */
        $candidates = [];

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

            $candidates[] = $file;
        }

        // Process in bounded chunks (SCAN_BATCH_SIZE) rather than collecting
        // an entire multi-thousand-file library's SplFileInfo list before
        // doing any work, and rather than one file at a time — see
        // SCAN_BATCH_SIZE's docblock for the size rationale.
        foreach (array_chunk($candidates, self::SCAN_BATCH_SIZE) as $batch) {
            $added += $this->processScanBatch($libraryId, $batch, $type, $forcedSeries, $forcedSeason, $onFile);
            $scanned += count($batch);
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
     * S8: process one bounded batch of already-filtered scan candidates.
     *
     * Two phases, preserving the ORIGINAL candidate order for both the
     * per-file progress callback and the sequential create/dedup logic (so
     * scan results stay stable/reproducible):
     *
     * 1. A single batched {@see ItemRepository::findPathsMap()} query
     *    determines which candidate paths are already indexed (a rescan) and
     *    which are brand new. For brand-new files whose eventual media type
     *    would need a probe (mirrors {@see processFile()}'s
     *    `DURATION_PROBE_TYPES` gate via {@see isProbeEligibleLibraryType()}),
     *    a BOUNDED coroutine pool runs their `probeSummary()` concurrently
     *    (see {@see probeManyConcurrently()}) — this is the only concurrent
     *    step; it touches no shared/DB state.
     * 2. The batch is walked once more, in original order: already-indexed
     *    paths get the SAME `backfillItemSourceMetadata()` call
     *    {@see processFile()} would have made (unchanged, sequential); brand
     *    new files call {@see processFile()} with its precomputed probe
     *    result (or `false` when this batch never probed, e.g. an
     *    image/book library), so the naming/canonical-key/create/
     *    persistStreams sequence — and any shared-parent find-or-create via
     *    {@see resolveEpisodeParent()} — happens exactly as it always has,
     *    one file at a time.
     *
     * @param list<SplFileInfo> $batch Filtered scan candidates for this chunk.
     * @param array{title: string, year: int|null, slug_source?: string}|null $forcedSeries
     * @param (callable(string): void)|null $onFile
     * @return int Number of items added in this batch.
     */
    private function processScanBatch(
        string $libraryId,
        array $batch,
        string $type,
        ?array $forcedSeries,
        ?int $forcedSeason,
        ?callable $onFile
    ): int {
        if ($batch === []) {
            return 0;
        }

        $paths = [];
        foreach ($batch as $file) {
            $paths[] = $file->getPathname();
        }

        // Scope the batch lookup to this library so the (library_id, path_hash)
        // index is used (left-prefix-first) instead of a full media_items scan.
        $existingByPath = $this->itemRepository->findPathsMap($paths, $libraryId);
        $probeEligible = $this->isProbeEligibleLibraryType($type);

        $newPaths = [];
        foreach ($batch as $file) {
            $path = $file->getPathname();
            if (!isset($existingByPath[$path]) && $probeEligible) {
                $newPaths[] = $path;
            }
        }

        // The ONLY concurrent step: a read-only, DB-free ffprobe fan-out for
        // brand-new files. Empty when nothing is probe-eligible in this batch
        // (e.g. image/book libraries), in which case this is a no-op.
        $probeResults = $this->probeManyConcurrently($newPaths);

        $added = 0;
        foreach ($batch as $file) {
            $path = $file->getPathname();

            if (isset($existingByPath[$path])) {
                // Rescan: identical to processFile()'s existing-item branch —
                // no new item is added, only a source-metadata backfill.
                $this->backfillItemSourceMetadata($existingByPath[$path]);
            } else {
                // `false` (processFile()'s "not supplied" sentinel) when this
                // path was never fanned out (non-probe-eligible library type);
                // otherwise the precomputed result, INCLUDING a legitimate
                // `null` (the probe failed for this file) — array_key_exists
                // distinguishes "probed and failed" from "never probed" so a
                // failed probe is never silently retried a second time.
                $precomputedProbe = array_key_exists($path, $probeResults)
                    ? $probeResults[$path]
                    : false;
                // processScanBatch already confirmed this path is absent via
                // findPathsMap, so pass callerConfirmedAbsent=true to skip
                // the redundant findByPath check inside processFile.
                if (
                    $this->processFile(
                        $libraryId,
                        $file,
                        $type,
                        $forcedSeries,
                        $forcedSeason,
                        $precomputedProbe,
                        true
                    )
                ) {
                    $added++;
                }
            }

            if ($onFile !== null) {
                $onFile($path);
            }
        }

        return $added;
    }

    /**
     * Whether a scanFlat()-level library type ever yields a probe-eligible
     * media type for its files, mirroring {@see processFile()}'s
     * `DURATION_PROBE_TYPES` gate WITHOUT duplicating its naming/dedup logic.
     *
     * This is decidable purely from the library `$type` (constant for an
     * entire scanFlat() call) because {@see determineMediaType()} maps every
     * video-content library type to `'movie'` and an episode is always typed
     * `'episode'` — both members of `DURATION_PROBE_TYPES` — while `'audio'`
     * and `'audiobook'` pass through unchanged (also members) and
     * `'image'`/`'book'` never are. So per-file naming does not need to run
     * before deciding whether a candidate is worth fanning out to the probe
     * pool.
     *
     * MUST stay in lockstep with `DURATION_PROBE_TYPES`: a type this gate
     * rejects never reaches the concurrent probe pool, so widening the probe
     * list alone would leave the new type probed only on the slower
     * one-at-a-time path.
     *
     * @param string $type Library type passed into {@see scanFlat()}.
     */
    private function isProbeEligibleLibraryType(string $type): bool
    {
        return $this->isVideoContentLibrary($type)
            || $type === 'audio'
            || $type === 'audiobook';
    }

    /**
     * S8: probe every given path's source characteristics, running up to
     * {@see $maxConcurrentScanProbes} {@see probeSummary()} calls
     * CONCURRENTLY when this method executes inside a Swoole coroutine — the
     * same non-blocking exec branch {@see FfmpegRunner::runProbeCommand()}
     * (S6) uses — so a large batch's ffprobes overlap instead of running one
     * after another. Outside a coroutine (PHPUnit CLI, a plain CLI scan
     * script with no Swoole event loop) this degrades to the EXACT sequential
     * behaviour that existed before S8: one `probeSummary()` call per path,
     * in order, on the calling "thread" — so nothing regresses for
     * non-coroutine callers.
     *
     * Bounded fan-out idiom: TWO separate `Swoole\Coroutine\Channel`s, kept
     * strictly apart because they serve different purposes.
     *
     * 1. A semaphore channel sized to {@see $maxConcurrentScanProbes}: a
     *    `push()` before launching each probe coroutine blocks/yields once
     *    the channel is full, and each coroutine's `pop()` on completion
     *    frees a slot for the next — this is what BOUNDS the concurrency.
     * 2. A "done" signal channel, sized to `count($paths)`, that every
     *    launched coroutine `push()`es to exactly once on completion
     *    (success, a caught exception, or the `Coroutine::create() === false`
     *    scheduling-failure branch below) — the caller then `pop()`s it
     *    exactly `count($paths)` times, blocking until every coroutine has
     *    signaled. This is functionally equivalent to a `WaitGroup::wait()`
     *    join. `\Swoole\Coroutine\WaitGroup` is deliberately NOT used here:
     *    PHPStan's bundled JetBrains PhpStorm swoole stubs (loaded when the
     *    real `ext-swoole` is not present — e.g. CI's PHPStan job runs with
     *    only `ext-json`) ship `Coroutine.stub`/`Coroutine/Channel.stub`/
     *    `Coroutine/System.stub` but NO `Coroutine/WaitGroup.stub`, so any
     *    use of that class fails `phpstan analyze --level=9` with
     *    "Instantiated class Swoole\Coroutine\WaitGroup not found" in that
     *    environment even though the real extension resolves it fine
     *    locally. `Channel` IS covered by the bundled stub (already relied
     *    on for the semaphore above), so a second channel keeps this method
     *    fully PHPStan-clean in both environments while producing the exact
     *    same join semantics as the WaitGroup it replaces.
     *
     * `probeSummary()` itself already never throws (see its docblock), but
     * the coroutine body defensively catches anyway so a future change there
     * can never leave the done-channel short a signal.
     *
     * @param list<string> $paths Absolute filesystem paths to probe. An empty
     *                             list short-circuits with no coroutine setup.
     * @return array<string, array{
     *     duration_seconds: int|null,
     *     source: array<string, mixed>|null,
     *     streams: list<array<string, mixed>>
     * }|null> Probe summary (or null on failure) keyed by input path.
     */
    private function probeManyConcurrently(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        if (!$this->coroutineFanOutAvailable()) {
            $results = [];
            foreach ($paths as $path) {
                $results[$path] = $this->probeSummary($path);
            }
            return $results;
        }

        $results = [];
        $semaphore = new \Swoole\Coroutine\Channel(max(1, $this->maxConcurrentScanProbes));
        // Sized to the exact number of completion signals it will ever
        // receive (one per path) so no producer can ever block on push().
        $done = new \Swoole\Coroutine\Channel(count($paths));

        foreach ($paths as $path) {
            // Blocks/yields once $maxConcurrentScanProbes probes are already
            // in flight — this is what bounds the concurrency, not a fixed
            // "launch N then wait" batch split.
            $semaphore->push(true);
            $cid = \Swoole\Coroutine::create(function () use ($path, &$results, $semaphore, $done): void {
                try {
                    $results[$path] = $this->probeSummary($path);
                } catch (\Throwable $e) {
                    // Defensive only — probeSummary() already catches every
                    // Throwable internally. A probe failure must never abort
                    // the scan or leave the done-channel short a signal.
                    $this->logger->debug('Concurrent scan probe failed; continuing scan', [
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                    $results[$path] = null;
                } finally {
                    $semaphore->pop();
                    $done->push(true);
                }
            });

            // Coroutine::create() returns the new coroutine ID on success or
            // `false` if it failed to SCHEDULE the coroutine at all (e.g. the
            // process-wide `max_coroutine` ceiling was hit) — in that failure
            // case the closure body above never runs, so the `push()`
            // reserved just above it on the semaphore would otherwise NEVER
            // be released, and the done-channel would never receive this
            // path's signal, so the final pop loop below would hang forever
            // waiting for it. Release/signal them here ourselves and record
            // the same `null`-on-failure outcome a thrown probe would
            // produce, so a scheduling failure degrades exactly like any
            // other single-path probe failure instead of stalling the scan.
            if ($cid === false) {
                $this->logger->debug('Failed to spawn scan-probe coroutine; continuing scan', [
                    'path' => $path,
                ]);
                $results[$path] = null;
                $semaphore->pop();
                $done->push(true);
            }
        }

        // Block until every launched coroutine (or synchronous
        // scheduling-failure fallback above) has signaled completion —
        // exactly one pop() per path, mirroring WaitGroup::wait()'s join.
        for ($i = 0, $total = count($paths); $i < $total; $i++) {
            $done->pop();
        }

        return $results;
    }

    /**
     * Whether the calling code is running inside a real Swoole coroutine
     * (production Workerman/Swoole worker), mirroring
     * {@see FfmpegRunner::runProbeCommand()}'s own guard exactly so
     * {@see probeManyConcurrently()}'s coroutine fan-out and S6's per-probe
     * non-blocking exec branch activate/deactivate together.
     */
    private function coroutineFanOutAvailable(): bool
    {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
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
     * **S8 scope note:** season/specials/loose subdirectories are still scanned
     * via {@see scanFlat()} (unchanged call below), so they DO get scanFlat()'s
     * batched already-scanned lookup and bounded-concurrent ffprobe fan-out —
     * that part is safe here because it never touches the database (a pure
     * `ffmpeg->probe()` + parse). What is deliberately NOT parallelized, in
     * this step or ever inside scanFlat(), is the naming/canonical-key/
     * create()/{@see resolveEpisodeParent()} sequence: those still run ONE
     * FILE AT A TIME, in original order, exactly as before S8. That is the
     * real hazard for series/episode content — two files racing to
     * find-or-create the SAME shared series/season container — and it is
     * unaffected by S8 because only the read-only probe step runs
     * concurrently. A genuinely concurrent episode-parent resolution (e.g.
     * memoizing in-flight find-or-creates across coroutines) is intentionally
     * left for a dedicated future step rather than bolted on here.
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
     * Two independent rules, in order:
     *  1. **dotfiles** — hardcoded and deliberately NOT configurable; whether
     *     to import `.DS_Store` is not an operator decision, so no value of
     *     `scanner.ignore_patterns` (including an empty list) can re-enable it;
     *  2. the effective `scanner.ignore_patterns` list — see
     *     {@see ScanIgnorePatterns} and `config/scanner.php`.
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

        // Skip system/partial/sample files per the effective
        // `scanner.ignore_patterns` list. Consulted AFTER the dotfile rule
        // above, which no configured value can reach or disable.
        return $this->ignorePatterns->matches($filename);
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
     * @param array{
     *     duration_seconds: int|null,
     *     source: array<string, mixed>|null,
     *     streams: list<array<string, mixed>>
     * }|null|false $precomputedProbe S8: an already-computed
     *        {@see probeSummary()} result supplied by {@see scanFlat()}'s
     *        batch/coroutine-fan-out path, so this call never re-probes the
     *        file. `false` (the default) means "no precomputed value" —
     *        behave exactly as before S8 and call {@see probeSummary()}
     *        internally when the media type warrants it. A supplied `null` is
     *        itself a LEGITIMATE outcome (the fan-out probe failed for this
     *        file) and is honoured as-is rather than triggering a second,
     *        redundant probe attempt — this is why the sentinel is `false`
     *        rather than `null` for "not supplied".
     *
     * @return bool True when a new item was added to the repository; false
     *              when the file was already known and was skipped.
     */
    private function processFile(
        string $libraryId,
        SplFileInfo $file,
        string $type,
        ?array $forcedSeries = null,
        ?int $forcedSeason = null,
        array|null|false $precomputedProbe = false,
        bool $callerConfirmedAbsent = false
    ): bool {
        $path = $file->getPathname();

        // Pre-check for existing item to determine if we need backfill.
        // When processScanBatch calls processFile for a batch-proven-absent
        // path (callerConfirmedAbsent=true), this check is redundant because
        // processScanBatch already confirmed the item doesn't exist via
        // findPathsMap. In that case upsertByPath relies on the unique-index
        // 1062 catch for race safety. For other callers (scanSeriesDir) that
        // pass callerConfirmedAbsent=false, this check is still needed.
        if (!$callerConfirmedAbsent) {
            $existing = $this->itemRepository->findByPath($path, $libraryId);
            if ($existing) {
                // Re-scan: the row is already indexed, so no new item is added.
                // Backfill any missing source technical metadata for time-based
                // media — the total duration, the compact metadata_json['source']
                // summary, and the media_streams rows — so files indexed before the
                // source probe existed (or before they were ever transcoded) still
                // gain it on a plain rescan. Fully guarded; a single ffprobe call
                // yields all three.
                $this->backfillItemSourceMetadata($existing);
                return false; // Already scanned
            }
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

        // Probe the source file ONCE for time-based media and stamp BOTH the
        // precise total duration (seconds) AND a compact technical source
        // summary (metadata_json['source'] = {width, height, video_codec,
        // video_bitrate, pix_fmt, audio_codec, audio_bitrate}) so the player's
        // scrubber knows the full length immediately and the ABR ladder can
        // later be built without re-probing on every playback start. These
        // scalar values are sanitize-safe, so they are added after
        // sanitizeMetadata(). A duration already present in the parsed metadata
        // is never overwritten. The same probe result also feeds media_streams
        // once the row is created (below), so no second ffprobe is ever issued.
        $probeSummary = null;
        if (in_array($mediaType, self::DURATION_PROBE_TYPES, true)) {
            // S8: reuse a probe already run by scanFlat()'s coroutine fan-out
            // when one was supplied, instead of probing this file a second
            // time. See the parameter docblock for the `false`-vs-`null`
            // sentinel distinction.
            $probeSummary = $precomputedProbe !== false ? $precomputedProbe : $this->probeSummary($path);
            if ($probeSummary !== null) {
                if (
                    $probeSummary['duration_seconds'] !== null
                    && !(isset($metadata['duration_seconds']) && is_numeric($metadata['duration_seconds']))
                ) {
                    $metadata['duration_seconds'] = $probeSummary['duration_seconds'];
                }
                if ($probeSummary['source'] !== null) {
                    $metadata['source'] = $probeSummary['source'];
                }
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
                    // S158: the reused row keeps its identity, so it must also keep
                    // pointing at a file that EXISTS — otherwise the prune half of the
                    // very same rescan deletes it. Nothing is written here: only the
                    // prune knows whether it would actually delete this row, so the
                    // candidate is RECORDED and the decision is made there. See
                    // recordAdoptionCandidate() and adoptRecordedPath().
                    $this->recordAdoptionCandidate($byCanonical, $path, $probeSummary);
                    $this->logger->debug('Reusing existing top-level item by canonical key', [
                        'item_id' => $byCanonical['id'],
                        'canonical_key' => $canonicalKey,
                        'path' => $path,
                    ]);
                    return false;
                }
            }
        }

        // Create or reuse media item. Guard the write so a single unrepresentable
        // row (or any other per-file failure) is logged and skipped rather than
        // killing the whole library scan. upsertByPath handles the race condition
        // where multiple scanner workers encounter the same file concurrently.
        // When callerConfirmedAbsent=true, processScanBatch already confirmed
        // absence via findPathsMap, so upsertByPath skips its pre-check.
        try {
            $itemId = $this->itemRepository->upsertByPath([
                'library_id' => $libraryId,
                'parent_id' => $parentId,
                'name' => $name,
                'type' => $mediaType,
                'path' => $path,
                'metadata_json' => $metadata,
            ], true);
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

        // Persist the video + primary audio streams derived from the SAME probe
        // taken above (no second ffprobe). Idempotent and self-guarded, so a
        // stream-write failure never aborts the scan.
        if ($probeSummary !== null && $probeSummary['streams'] !== []) {
            $this->persistStreams((string) $itemId, $probeSummary['streams']);
        }

        // SV-1.3: Enqueue chapter-thumbnail + trickplay generation as a background
        // job instead of running it inline. When $mediaAssetJobStore is wired,
        // the job is serialised to the queue file and a bounded-concurrency worker
        // processes it asynchronously after the scan completes. When not wired
        // (tests / legacy callers), generation is skipped — callers that need the
        // old inline behaviour should wire up the store.
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $supportsChapters = in_array($ext, ['mkv', 'mp4', 'webm'], true);
        if ($this->mediaAssetJobStore !== null && $this->ffmpeg !== null && $supportsChapters) {
            $duration = is_numeric($metadata['duration_seconds'] ?? null) ? (int) $metadata['duration_seconds'] : 0;
            $job = new MediaAssetJob((string) $itemId, $path, $duration);
            $this->mediaAssetJobStore->enqueue($job);
        }

        $this->dispatchMediaItemAdded((string)$itemId, $libraryId, $path, $mediaType);

        // SV-2.9: best-effort similarity computation after a new item is indexed.
        // When $similarityJobStore is wired, the computation is deferred to a
        // background job to avoid O(N²) scan-path behavior. When only
        // $similarityService is wired (legacy), the inline call is retained for
        // backward compatibility. Failures must never abort the scan.
        if ($this->similarityJobStore !== null) {
            $job = new SimilarityJob((string) $itemId, (string) $libraryId);
            $this->similarityJobStore->enqueue($job);
        } elseif ($this->similarityService !== null) {
            try {
                $this->similarityService->computeSimilarForItem((string) $itemId, (string) $libraryId);
            } catch (\Throwable $e) {
                $this->logger->debug('Similarity computation failed for item', [
                    'item_id' => $itemId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // P4-S3: best-effort TMDB collection sync after a new item is indexed.
        // Only runs when the item has a tmdb_id in metadata_json (populated by
        // LibraryMetadataMatcher in a separate metadata scan job, not at scan time).
        // This path handles items that were already matched before this scan.
        //
        // S33: gated on the per-library auto-collections toggle (latched at the
        // top of scan()). When the library's options.autoCollections.enabled is
        // false the whole block — including the findById() lookup — is skipped;
        // the default (flag absent) is true, preserving today's behaviour.
        if ($this->collectionService !== null && $this->autoCollectionsEnabled) {
            try {
                $item = $this->itemRepository->findById((string) $itemId);
                if ($item !== null) {
                    $metaRaw = $item['metadata_json'] ?? null;
                    if (is_string($metaRaw)) {
                        $decoded = json_decode($metaRaw, true);
                        $meta = is_array($decoded) ? $decoded : null;
                    } else {
                        $meta = is_array($metaRaw) ? $metaRaw : null;
                    }
                    if ($meta !== null && isset($meta['tmdb_id']) && is_numeric($meta['tmdb_id'])) {
                        $this->collectionService->syncCollectionForMovie((string) $itemId);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Collection sync failed for item', [
                    'item_id' => $itemId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    /**
     * S158 — open the moved-file ADOPTION window for one rescan pass.
     *
     * Must be paired with {@see endAdoptionTracking()} in a `finally`. While the
     * window is open, {@see processFile()}'s canonical-reuse branch RECORDS the
     * rows whose file appears to have moved; nothing is written until
     * {@see adoptRecordedPath()} is called for a specific row by the code that
     * has decided that row would otherwise be deleted.
     *
     * Only {@see LibraryManager::rescanLibrary()} opens the window, because it is
     * the only caller that runs {@see LibraryManager::pruneRemovedItems()} in the
     * same pass and can therefore make that decision.
     */
    public function beginAdoptionTracking(): void
    {
        $this->adoptionCandidates = [];
        $this->adoptionCapWarned = false;
        $this->adoptionProbeCapWarned = false;
        $this->adoptionProbesHeld = 0;
    }

    /**
     * S158 — close the adoption window and drop every candidate still in it.
     *
     * Candidates that were never adopted are simply forgotten: the row keeps the
     * path it had, which is exactly what happened before S158. Called from a
     * `finally` so an exception mid-rescan cannot leave the map (or its memory)
     * behind in a resident worker.
     */
    public function endAdoptionTracking(): void
    {
        $this->adoptionCandidates = null;
        $this->adoptionCapWarned = false;
        $this->adoptionProbeCapWarned = false;
        $this->adoptionProbesHeld = 0;
    }

    /**
     * S158 — re-point ONE top-level row at the file the current scan found for
     * it, INSTEAD of deleting it.
     *
     * ## The defect this closes
     *
     * Move a top-level file (a `movie` from any video-content library, or a
     * `photo` / `book` / `audiobook` / `audio` row — see
     * {@see determineMediaType()}; `video` is a LIBRARY type that yields `movie`,
     * not a media type this branch ever sees) to another folder and the next
     * rescan used to DELETE its row and every piece of user data hanging off it.
     * Neither half of the mechanism is wrong on its own — the damage is their
     * interaction inside ONE {@see LibraryManager::rescanLibrary()} run:
     *
     *  1. {@see processFile()}'s `findByPath()` misses — the path changed.
     *  2. The canonical-key lookup HITS (the key is title+year, deliberately
     *     path-independent), so the scanner reuses the existing row and returns
     *     `false` — correct as far as "do not mint a second top-level row for the
     *     same film" goes, but it left `media_items.path` pointing at the OLD,
     *     now-nonexistent location, because reuse skipped `upsertByPath()`.
     *  3. `rescanLibrary()` then runs {@see LibraryManager::pruneRemovedItems()}
     *     in the SAME run, which deletes that row — cascading through the
     *     `ON DELETE CASCADE` foreign keys into `user_item_data` and
     *     `watch_history`.
     *
     * Net effect: the item vanished for one rescan and came back on the next with
     * a NEW uuid, no watched flag, no favourite, no rating, no resume position.
     * Episodes were never affected — they carry a season `parent_id` and so never
     * enter the canonical branch at all.
     *
     * ## Why the decision is the PRUNE's and not this file's — review F1
     *
     * The first cut of this fix rewrote `path` from inside the scan, guarded only
     * by `!file_exists($recordedPath)`, and claimed that was "the same predicate
     * the prune uses". **It is not, and the difference lost user data.**
     * `pruneRemovedItems()` deletes only on a FOUR-way conjunction: at least one
     * configured root is a readable directory; the row's path is attributable to
     * one of those accessible roots (a non-attributable row is explicitly KEPT);
     * that owning root has ≥1 currently-present item (the per-root presence
     * guard); and only then `!file_exists()`. Three of those four are
     * whole-library aggregates that local filesystem state around one file cannot
     * decide — no extra `is_dir()`/`file_exists()` test can stand in for them,
     * because in the failing shape the abandoned root IS a readable directory.
     *
     * Measured consequence of getting that wrong (multi-root library, NAS
     * unmounted leaving an empty-but-present mountpoint, a temporary local copy
     * of the same film): the scan re-pointed the row off the SPARED NAS root onto
     * the local root, the local copy was later deleted, and the row — now sitting
     * under an accessible root with other present items — was deleted with its
     * `user_item_data` and `watch_history`. That is precisely the symptom S158
     * exists to prevent.
     *
     * So the invariant is: **adopt only where the prune would actually delete.**
     * The scan records a candidate; the prune calls this method at the one point
     * where it already knows the row is in the `gone` set of an accessible root
     * whose presence guard has opened — i.e. one statement before the `DELETE`.
     * A row the prune is SPARING is never passed here and is left completely
     * alone, reproducing master byte-for-byte. Deletion and adoption are then one
     * decision made in one place from one set of facts.
     *
     * ## What is written — review F2
     *
     * `media_items.path_hash` is a STORED generated column (migrations 072/087),
     * so MySQL recomputes it from the new `path` inside the same UPDATE and the
     * next rescan resolves the row by plain hash lookup.
     * `name`/`sort_title`/`canonical_key` are FILENAME-derived and a move does not
     * change them.
     *
     * But canonical reuse explicitly also serves "the same film stored twice",
     * so the adopted file may be a DIFFERENT physical copy — a different rip, a
     * different resolution, a different codec. `duration_seconds`,
     * `metadata_json.source` and `media_streams` are FILE-derived, they drive the
     * scrubber length, the direct-play/HEVC guard, the ABR ladder and the HLS job
     * key, and {@see backfillItemSourceMetadata()} will never repair them (it
     * returns `'skipped'` the moment duration and source are both populated). So
     * when the probe of the adopted file describes something other than what the
     * row already records, all three are re-derived from that probe.
     *
     * That probe costs NOTHING extra: {@see processFile()} already ran it for
     * this file before reaching the canonical branch (or `processScanBatch()`'s
     * coroutine fan-out did), and the summary is carried on the candidate. No
     * ffprobe is added to the ordinary scan path, and none is issued here.
     *
     * Failure is swallowed and logged, matching this file's per-file discipline:
     * one unwritable row must never abort a library scan. The realistic failure
     * is a `(library_id, path_hash)` unique-index collision from a concurrent
     * worker that indexed the new path first — in which case that other row
     * already covers the file, this one is not adopted, and the prune deletes it,
     * which is the pre-S158 behaviour rather than a new regression.
     *
     * @param string $itemId The media item the caller is about to delete.
     * @return bool True when the row was re-pointed and MUST NOT be deleted;
     *         false when there was no candidate for it, or the adoption could not
     *         be completed — in which case the caller proceeds exactly as before.
     */
    public function adoptRecordedPath(string $itemId): bool
    {
        if ($this->adoptionCandidates === null || !isset($this->adoptionCandidates[$itemId])) {
            return false;
        }

        $candidate = $this->adoptionCandidates[$itemId];
        // Consume it either way: a candidate is good for exactly one decision.
        unset($this->adoptionCandidates[$itemId]);

        $newPath = $candidate['path'];

        // The walk saw this file; the prune runs afterwards. Re-ask, with this
        // one entry cleared, so a file deleted in between is not adopted into a
        // path that is already gone again.
        clearstatcache(true, $newPath);
        if (!file_exists($newPath)) {
            return false;
        }

        try {
            $row = $this->itemRepository->findById($itemId);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not re-read a top-level item that was about to be adopted', [
                'item_id' => $itemId,
                'new_path' => $newPath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
        if ($row === null) {
            return false;
        }

        // Re-read rather than reuse the row the scan matched: a metadata job may
        // have rewritten metadata_json since, and update() replaces the whole
        // column — writing a stale blob back would silently revert that work.
        $metadata = $this->existingMetadata($row);
        $probe = $candidate['probe'];
        $redescribe = $probe !== null && $this->probeContradictsItem($metadata, $probe);

        /** @var array<string, mixed> $fields */
        $fields = ['path' => $newPath];
        if ($redescribe && $probe !== null) {
            if ($probe['duration_seconds'] !== null) {
                $metadata['duration_seconds'] = $probe['duration_seconds'];
            }
            if ($probe['source'] !== null) {
                $metadata['source'] = $probe['source'];
            }
            $fields['metadata_json'] = $metadata;
        }

        try {
            $this->itemRepository->update($itemId, $fields);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not re-point a moved top-level item at its new path', [
                'item_id' => $itemId,
                'old_path' => is_string($row['path'] ?? null) ? $row['path'] : '',
                'new_path' => $newPath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        // Streams AFTER the path write, not before: the path write is what saves
        // the row from deletion, so it must not be held hostage to a stream
        // replacement. persistStreams() is itself atomic and self-guarded, so a
        // failure here leaves the PREVIOUS stream rows in place rather than a
        // half-written set.
        if ($redescribe && $probe !== null && $probe['streams'] !== []) {
            if (!$this->persistStreams($itemId, $probe['streams'])) {
                $this->logger->warning('Adopted item kept its previous media_streams; the replacement failed', [
                    'item_id' => $itemId,
                    'new_path' => $newPath,
                ]);
            }
        }

        $this->logger->info('Top-level item followed its file to a new path instead of being pruned', [
            'item_id' => $itemId,
            'old_path' => is_string($row['path'] ?? null) ? $row['path'] : '',
            'new_path' => $newPath,
            'source_redescribed' => $redescribe,
        ]);

        return true;
    }

    /**
     * S158 — record, WITHOUT writing anything, that this top-level row's recorded
     * file is gone while the file just scanned would fit it.
     *
     * Called from {@see processFile()}'s canonical-reuse branch. Whether the
     * candidate is ever acted on is decided later and elsewhere — see
     * {@see adoptRecordedPath()} for why that separation is the fix rather than
     * an implementation detail.
     *
     * The `file_exists($recordedPath)` test here is a NARROWING pre-filter, not
     * the safety guard it used to be pretending to be. It is deliberately no
     * wider than the prune's own fourth condition, so it can only ever decline to
     * record something the prune would have spared anyway; its job is (a) to keep
     * the genuine "same film stored twice, both copies present" shape from
     * allocating a candidate at all, and (b) to keep the map small. The authority
     * is the prune.
     *
     * First candidate wins. Two files can canonically match one row in a single
     * walk; keeping the first makes the outcome depend on directory-iteration
     * order in exactly the same way the pre-S158 code did, rather than on which
     * write landed last.
     *
     * @param array<string, mixed> $existing The hydrated row
     *        {@see ItemRepository::findTopLevelByCanonical()} matched.
     * @param string $path The path of the file currently being processed —
     *        already UTF-8-scrubbed by the caller.
     * @param array{
     *     duration_seconds: int|null,
     *     source: array<string, mixed>|null,
     *     streams: list<array<string, mixed>>
     * }|null $probe The probe {@see processFile()} already took for `$path`, so
     *        the adoption can re-derive file-derived state without a second
     *        ffprobe. Null for a type that is never probed, or a failed probe.
     */
    private function recordAdoptionCandidate(array $existing, string $path, ?array $probe): void
    {
        // Tracking closed → nobody will decide → never record. A `scan` job that
        // does not prune therefore behaves exactly as it did before S158.
        if ($this->adoptionCandidates === null) {
            return;
        }

        $id = isset($existing['id']) && is_string($existing['id']) ? $existing['id'] : '';
        $recordedPath = isset($existing['path']) && is_string($existing['path']) ? $existing['path'] : '';

        if ($id === '' || $recordedPath === '' || $path === '' || $recordedPath === $path) {
            return;
        }

        if (isset($this->adoptionCandidates[$id])) {
            return;
        }

        // A long-lived Workerman worker can hold a stale stat() for this path
        // across rescans; clear just this entry so the answer reflects the
        // current on-disk state — the same thing pruneRemovedItems() achieves
        // with its whole-cache clear before asking the same question.
        clearstatcache(true, $recordedPath);
        if (file_exists($recordedPath)) {
            // Not a move: both copies are on disk. Keep the dedup behaviour.
            return;
        }

        if (count($this->adoptionCandidates) >= $this->maxAdoptionCandidates) {
            if (!$this->adoptionCapWarned) {
                $this->adoptionCapWarned = true;
                $this->logger->warning(
                    'Moved-file adoption candidate cap reached; further moved rows will be pruned as before',
                    ['cap' => $this->maxAdoptionCandidates],
                );
            }
            return;
        }

        // Identity first: the candidate is always recorded. The probe payload —
        // which is what the entry's weight is made of — is retained only within
        // its own smaller budget, so a pathological mass move degrades to "moved
        // rows keep their old technical metadata" rather than "moved rows are
        // deleted".
        //
        // That degradation is QUIET unless it is announced: the row is adopted,
        // keeps its old `source`/`duration` while pointing at a file that may be
        // a different rip entirely, and never self-repairs
        // ({@see backfillItemSourceMetadata()} returns 'skipped' once duration
        // and source are populated). So it gets the same one-shot warning the
        // candidate cap gets, with the consequence spelled out — the two
        // overflows mean different things and an operator has to be able to tell
        // which one happened.
        //
        // The wording deliberately does NOT promise survival. Being under budget
        // is not a guarantee that the row lives: adoptRecordedPath() can still
        // abandon the adoption because the file vanished again, because the row
        // cannot be re-read, or because the UPDATE collided with a concurrent
        // worker — and the prune then deletes the row and its user data exactly
        // as it did before S158. "Keeps their identity" is true only of the
        // adoptions that succeed, and the message says so.
        if ($probe !== null && $this->adoptionProbesHeld >= $this->maxAdoptionProbes) {
            $probe = null;
            if (!$this->adoptionProbeCapWarned) {
                $this->adoptionProbeCapWarned = true;
                $this->logger->warning(
                    'Moved-file adoption probe budget reached; further moved rows that are successfully '
                    . 'adopted keep their identity, but their duration/source/streams will still describe '
                    . 'the file they no longer point at. This budget does not make an adoption succeed: '
                    . 'one that fails for any other reason is still pruned, with its user data',
                    ['cap' => $this->maxAdoptionProbes],
                );
            }
        }
        if ($probe !== null) {
            $this->adoptionProbesHeld++;
        }

        $this->adoptionCandidates[$id] = ['path' => $path, 'probe' => $probe];
    }

    /**
     * Whether a fresh probe describes a DIFFERENT file from the one the row's
     * file-derived metadata already describes.
     *
     * Used to keep a plain directory move — where the adopted file IS the file
     * the row was created from — from rewriting `metadata_json` for no reason
     * (each such write flushes the whole genre-facet cache and re-syncs the
     * `media_item_genres` join rows), while still repairing the row whenever the
     * adopted copy is genuinely a different rip. A row that carries no `source`
     * at all is always treated as contradicted, so a pre-probe row gets described
     * for the first time.
     *
     * @param array<string, mixed> $metadata The row's current decoded metadata.
     * @param array{
     *     duration_seconds: int|null,
     *     source: array<string, mixed>|null,
     *     streams: list<array<string, mixed>>
     * } $probe Probe summary of the file being adopted.
     */
    private function probeContradictsItem(array $metadata, array $probe): bool
    {
        if ($probe['source'] !== null) {
            $recordedSource = isset($metadata['source']) && is_array($metadata['source'])
                ? $metadata['source']
                : null;
            // Loose compare: the recorded copy has been through a JSON
            // round-trip, so key ORDER is not a meaningful difference.
            if ($recordedSource === null || $recordedSource != $probe['source']) {
                return true;
            }
        }

        if ($probe['duration_seconds'] !== null) {
            $recordedDuration = isset($metadata['duration_seconds']) && is_numeric($metadata['duration_seconds'])
                ? (int) $metadata['duration_seconds']
                : null;
            if ($recordedDuration !== $probe['duration_seconds']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Probe a media file's source characteristics with a SINGLE ffprobe call,
     * deriving its total duration, a compact `source` summary, and the
     * media_streams rows to persist — so the scan- and backfill-time paths
     * never probe the same file twice.
     *
     * Returns null when no ffprobe runner is wired, when the probe fails, or
     * when any error occurs — a probe failure must NEVER abort the scan, so all
     * throwables are caught and logged.
     *
     * @param string $path Absolute filesystem path to the media file.
     * @return array{
     *     duration_seconds: int|null,
     *     source: array<string, mixed>|null,
     *     streams: list<array<string, mixed>>
     * }|null Combined probe summary, or null when nothing could be probed.
     */
    private function probeSummary(string $path): ?array
    {
        if ($this->ffmpeg === null) {
            return null;
        }

        try {
            $probe = $this->ffmpeg->probe($path);
            if (!is_array($probe)) {
                return null;
            }
            return self::summarizeProbe($probe);
        } catch (\Throwable $e) {
            $this->logger->debug('Source probe failed; continuing scan', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Derive the total duration, a compact source technical summary, and the
     * media_streams rows from a SINGLE {@see FfmpegRunner::probe()} result.
     * Deterministic in its return value, so one probe feeds every downstream
     * write; its ONLY side effect is a diagnostic log line per degenerate `video`
     * stream (see {@see logDegenerateVideoStreams()}), which nothing reads back.
     * Public so the lazy playback-info backfill ({@see StreamProbeBackfill})
     * derives rows from the EXACT same logic and the two paths never drift.
     *
     * The `source` summary is the fixed shape `{width, height, video_codec,
     * video_bitrate, pix_fmt, audio_codec, audio_bitrate}` (each value null when
     * the probe does not expose it), stored under `metadata_json['source']` for
     * the ABR-ladder builder. `pix_fmt` lives only there because the
     * media_streams table has no such column. `video_bitrate` prefers the stream's
     * own rate, then its Matroska `BPS` tag (see {@see streamBitrate()}), and only
     * then falls back to the whole-file bitrate (`format.bit_rate`) so the ladder
     * always has a usable source ceiling — that last fallback counts audio and
     * container overhead as if they were video, so it OVERSTATES the video rate.
     *
     * Duration rounding matches {@see TranscodeManager::persistProbedDuration()}
     * (`(int) round((float) $raw)`, positive only) so the scan- and
     * transcode-time paths agree on the stored value. `source` is null and
     * `streams` empty when the file exposes neither a video nor an audio stream.
     *
     * `streams` is the FULL stream set — EVERY video/audio/subtitle stream (not
     * just the primary video + audio) with its global ffprobe `stream_index`,
     * codec, language, bitrate, video dimensions, audio `channels`, container
     * `title` tag, and `is_default` (ffprobe `disposition.default`) — so the
     * playback-info track menus (see {@see StreamTrackShaper}) can offer every
     * audio and subtitle track. Embedded cover-art "video" streams
     * (`disposition.attached_pic`) are excluded unless the file has NO
     * non-poster video stream at all, in which case the FIRST of them keeps its
     * row (see {@see coverArtLosesItsRow()}). A poster promoted to `source`
     * because every non-poster stream had implausible geometry does NOT gain a
     * row — that is the same rule master applies, and the reason is in
     * {@see coverArtLosesItsRow()}.
     *
     * DEGENERATE "video" streams — no `codec_name`, or geometry that cannot
     * describe a frame / is implausible for the measured library (see
     * {@see videoStreamDefects()}) — are STILL PERSISTED, and
     * only lose the right to define the `source` summary. A stream whose geometry
     * is implausible is skipped when choosing the primary `$video`, so a `1920x0`
     * broken header cannot become `source` and clamp every rung of the ABR ladder
     * to zero height; a stream that merely names no codec is NOT skipped, because
     * its dimensions are still the file's real dimensions.
     *
     * THAT SPLIT IS THE WHOLE POINT, and an earlier revision got it wrong by
     * ALSO dropping the row. Rejecting a video row on these predicates destroys
     * real content: a `codec_type=video` stream with an ABSENT `codec_name` and
     * valid dimensions is exactly what ffmpeg 6.1.1 reports for a sample-entry
     * fourcc the installed build cannot map — `vvc1` (H.266), `apv1`, and every
     * Common-Encryption `encv` (Widevine / PlayReady) or FairPlay `drmi` MP4 —
     * while `codec_name='h264'` at `0x0` is what an MPEG-TS joined mid-GOP (an
     * in-progress DVR recording, a partial download) reports. Verified on real
     * files with real ffprobe. Those are content tracks, and the row is the only
     * record that the track exists, plus its dimensions, bitrate, language and
     * color metadata. A junk row, by contrast, is INERT: every reader that asks
     * "what is this item's video codec?" already skips a blank `codec`
     * (`StreamProbeBackfill::videoCodecMissing()`, the SPA's
     * `videoCodecFromStreams()`), `getVideoStreamColorMetadata()` reads the
     * LOWEST `stream_index`, and `StreamTrackShaper` never counts a video row
     * into an `0:a:N` / `0:s:N` ordinal. A dropped row, meanwhile, cannot be
     * recovered — nothing re-probes an item that already has a duration, a
     * `source` and a `streams_probed_at` stamp ({@see
     * backfillItemSourceMetadata()} returns `'skipped'`, and both
     * `StreamProbeBackfill` triggers short-circuit) — and its absence is
     * indistinguishable from "ffprobe never reported that stream". Preserving
     * the row therefore outranks not storing a junk one.
     *
     * The same asymmetry is why no cleanup migration ships with this: DELETING
     * the measured junk rows is exactly as irreversible as never writing them,
     * and the only predicate that identifies them ("a `video` row with a blank
     * codec, on an item that has another `video` row WITH a codec") also matches
     * the `encv`/`drmi`/`vvc1` content track of any file that carries an
     * unflagged poster or a second video stream. Verified on MySQL 8.0.46
     * against rows this method actually produced for such a file. An operator
     * who wants the junk gone runs a one-off DELETE against the library they
     * measured; the ledgered migration chain is the wrong place for it.
     *
     * The pre-existing `attached_pic` skip is NOT affected by that reasoning and
     * stays UNWIDENED: a poster stream is not a content track, it is a picture
     * the muxer flagged as such, and the file's first poster still keeps its row
     * when the file has no non-poster video stream at all — the one case where
     * dropping it would leave the item with zero video rows.
     *
     * @param array<string, mixed> $probe Raw ffprobe result (streams + format).
     * @return array{
     *     duration_seconds: int|null,
     *     source: array<string, mixed>|null,
     *     streams: list<array<string, mixed>>
     * }
     */
    public static function summarizeProbe(array $probe): array
    {
        $rawStreams = is_array($probe['streams'] ?? null) ? $probe['streams'] : [];
        $format = is_array($probe['format'] ?? null) ? $probe['format'] : [];

        $video = null;
        $videoIndex = null;
        $videoFallback = null;
        $videoFallbackIndex = null;
        $audio = null;
        $audioIndex = null;
        // Whether the file carries any video-type stream that is NOT flagged
        // cover art. It decides which poster rows may be persisted (see
        // {@see coverArtLosesItsRow()}), and must therefore be known for the
        // WHOLE file before the persist loop starts — a poster at index 0 is
        // reached before the content track at index 1.
        $hasNonPosterVideo = false;
        /** @var list<array{stream: array<string, mixed>, defects: list<string>}> $degenerate */
        $degenerate = [];
        foreach ($rawStreams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $codecType = $stream['codec_type'] ?? null;
            if ($codecType === 'video') {
                // Remember the first video-type stream as a fallback for a file
                // that offers nothing fit to describe it: every video stream is
                // an embedded cover art, or every non-poster one has implausible
                // geometry. Which of those two it was decides whether the
                // fallback also gets a row — see the persist loop below.
                if ($videoFallback === null) {
                    $videoFallback = $stream;
                    $videoFallbackIndex = self::intOrNull($stream['index'] ?? null);
                }
                if (!self::isAttachedPic($stream)) {
                    $hasNonPosterVideo = true;
                }
                // Computed ONCE per video stream and reused for both the
                // selection guard and the log line, so the predicate can never
                // disagree with what gets reported.
                $defects = self::videoStreamDefects($stream);
                if ($defects !== []) {
                    $degenerate[] = ['stream' => $stream, 'defects' => $defects];
                }
                // Prefer the first REAL video stream: skip an embedded poster
                // (disposition.attached_pic = 1), whose tiny 600x900 dims would
                // otherwise masquerade as the source resolution and wrongly cap
                // the ABR ladder at the thumbnail size, and skip a stream whose
                // GEOMETRY is implausible for the same reason — a `1920x0`
                // header would clamp the ladder to zero height.
                //
                // A missing `codec_name` deliberately does NOT disqualify a
                // stream here: its width/height are still the file's real
                // dimensions (a `vvc1`/`encv`/`drmi` content track probes with no
                // codec name and correct 320x240), and handing `source` to a
                // poster instead would ladder a 4K DRM film as a 600x900 mjpeg.
                if (
                    $video === null
                    && !self::isAttachedPic($stream)
                    && !self::defectsIncludeGeometry($defects)
                ) {
                    $video = $stream;
                    $videoIndex = self::intOrNull($stream['index'] ?? null);
                }
            } elseif ($audio === null && $codecType === 'audio') {
                $audio = $stream;
                $audioIndex = self::intOrNull($stream['index'] ?? null);
            }
        }

        // Only when every video-type stream is an attached picture (or has
        // implausible geometry) do we fall back to it — preserving prior
        // behavior for that edge (an item that truly has no playable video
        // stream), and guaranteeing `source` is never null for a file that has
        // any video-type stream at all.
        //
        // This promotion decides `source` ONLY. It does not hand a poster a
        // media_streams row it would not otherwise get: that is
        // {@see coverArtLosesItsRow()}'s call, and it says no whenever the file
        // has any non-poster video stream. The two were coupled once, and the
        // added row flipped `StreamProbeBackfill::videoCodecMissing()` from true
        // to false on a `1920x0`-plus-cover-art file, masking the item from
        // `ensureVideoCodecFor()` permanently.
        if ($video === null && $videoFallback !== null) {
            $video = $videoFallback;
            $videoIndex = $videoFallbackIndex;
        }

        // LOUD about every degenerate stream, because the alternative is that
        // the only evidence of a false positive is a `source` descriptor taken
        // from a different stream than ffprobe's first — invisible after the
        // fact. Costs nothing on a clean file: the list is empty and this is
        // skipped, so a full-library scan logs 18 lines for the 18 measured
        // broken headers and nothing for the other 37,907 video rows.
        if ($degenerate !== []) {
            self::logDegenerateVideoStreams($degenerate, $format, $video, $hasNonPosterVideo);
        }

        // Total duration (seconds) from the container format. Rounded and
        // positive-only to match the transcode-time persist path.
        $duration = null;
        $rawDuration = $format['duration'] ?? null;
        if (is_numeric($rawDuration)) {
            $seconds = (int) round((float) $rawDuration);
            if ($seconds > 0) {
                $duration = $seconds;
            }
        }

        // A file with neither a video nor an audio stream (image/data-only) has
        // no meaningful source summary or streams to persist.
        if ($video === null && $audio === null) {
            return ['duration_seconds' => $duration, 'source' => null, 'streams' => []];
        }

        $videoBitrate = $video !== null
            ? (self::streamBitrate($video) ?? self::intOrNull($format['bit_rate'] ?? null))
            : null;
        $audioBitrate = $audio !== null ? self::streamBitrate($audio) : null;

        $source = [
            'width' => $video !== null ? self::intOrNull($video['width'] ?? null) : null,
            'height' => $video !== null ? self::intOrNull($video['height'] ?? null) : null,
            'video_codec' => $video !== null ? self::stringOrNull($video['codec_name'] ?? null) : null,
            'video_bitrate' => $videoBitrate,
            'pix_fmt' => $video !== null ? self::stringOrNull($video['pix_fmt'] ?? null) : null,
            'audio_codec' => $audio !== null ? self::stringOrNull($audio['codec_name'] ?? null) : null,
            'audio_bitrate' => $audioBitrate,
        ];

        // Persist the FULL stream set — every video/audio/subtitle stream —
        // so the playback-info track menus can list all audio tracks and
        // subtitles. The primary video row reuses $videoBitrate (with its
        // format-level fallback) so the ABR ladder's source ceiling and the
        // stored row agree; every other row carries its own bit_rate. When the
        // primary is a cover-art stream that keeps no row, no stored row carries
        // that fallback — the ladder reads `source`, not the rows.
        $streams = [];
        $nextIndex = 0;
        foreach ($rawStreams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $codecType = $stream['codec_type'] ?? null;
            if (!in_array($codecType, ['video', 'audio', 'subtitle'], true)) {
                continue; // data/attachment streams have no playable track
            }

            // Skip embedded cover art unless it is the only "video" the file
            // has. This is the ONLY reason a video stream loses its row, and the
            // rule is EXACTLY the one master applies, so this method cannot
            // write a row master would not have written.
            //
            // A degenerate stream (no codec_name, or implausible geometry) keeps
            // its row on purpose — see the DEGENERATE paragraph in this method's
            // docblock: the shapes that trip those predicates include real
            // content tracks (`encv`/`drmi` DRM, `vvc1`, a mid-GOP `.ts`), and a
            // dropped row is unrecoverable while a junk row is inert.
            if ($codecType === 'video' && self::coverArtLosesItsRow($stream, $video, $hasNonPosterVideo)) {
                continue;
            }

            $isVideo = $codecType === 'video';
            $isAudio = $codecType === 'audio';
            $index = self::intOrNull($stream['index'] ?? null) ?? $nextIndex;
            $bitrate = ($isVideo && $stream === $video)
                ? $videoBitrate
                : self::intOrNull($stream['bit_rate'] ?? null);

            $streamData = [
                'stream_index' => $index,
                'stream_type' => $codecType,
                'codec' => self::stringOrNull($stream['codec_name'] ?? null),
                'language' => self::streamLanguage($stream),
                'bitrate' => $bitrate,
                'width' => $isVideo ? self::intOrNull($stream['width'] ?? null) : null,
                'height' => $isVideo ? self::intOrNull($stream['height'] ?? null) : null,
                'channels' => $isAudio ? self::intOrNull($stream['channels'] ?? null) : null,
                'title' => self::streamTitle($stream),
                'is_default' => self::isDefaultDisposition($stream) ? 1 : 0,
            ];

            // Persist color metadata on video streams so tone-mapping decisions
            // can be made at scan time without re-probing on every segment encode.
            if ($isVideo) {
                $streamData['color_space'] = self::stringOrNull($stream['color_space'] ?? null);
                $streamData['color_transfer'] = self::stringOrNull($stream['color_transfer'] ?? null);
                $streamData['color_primaries'] = self::stringOrNull($stream['color_primaries'] ?? null);
                // Luminance from side data tags (mastering display)
                $tags = is_array($stream['tags'] ?? null) ? $stream['tags'] : null;
                $maxLum = null;
                $avgLum = null;
                if ($tags !== null) {
                    $masteringLum = $tags['mastering_display_luminance'] ?? null;
                    if (is_string($masteringLum) && preg_match('/max:(\d+(\.\d+)?)/', $masteringLum, $lm)) {
                        $maxLum = (float) $lm[1];
                    }
                    $ambientLum = $tags['ambient_luminance'] ?? null;
                    if (is_string($ambientLum) && preg_match('/avg:(\d+(\.\d+)?)/', $ambientLum, $la)) {
                        $avgLum = (float) $la[1];
                    }
                }
                // Also check direct fields (some FFmpeg versions)
                if ($maxLum === null && isset($stream['max_luminance']) && is_numeric($stream['max_luminance'])) {
                    $maxLum = (float) $stream['max_luminance'];
                }
                if ($avgLum === null && isset($stream['avg_luminance']) && is_numeric($stream['avg_luminance'])) {
                    $avgLum = (float) $stream['avg_luminance'];
                }
                $streamData['max_luminance'] = $maxLum;
                $streamData['avg_luminance'] = $avgLum;
            }

            $streams[] = $streamData;
            $nextIndex = $index + 1;
        }

        return ['duration_seconds' => $duration, 'source' => $source, 'streams' => $streams];
    }

    /**
     * A single stream's OWN bitrate in bits/sec, or null when the probe omits it.
     *
     * Matroska stores no per-stream bitrate in its header, so ffprobe reports NO
     * `bit_rate` for an MKV stream. Falling straight through to `format.bit_rate`
     * (as this did before) charges the video with the audio's bits too — a
     * 1.08 Mbps HEVC track next to 448 kbps AC-3 reads as ~1.53 Mbps, ~40 % high,
     * which inflates every ABR rung's target and advertised BANDWIDTH.
     *
     * mkvmerge and ffmpeg do write a per-track `BPS` tag (plus language-suffixed
     * variants like `BPS-eng`) carrying the true per-stream rate, so prefer the
     * real `bit_rate`, then any `BPS*` tag. Mirrors
     * {@see \Phlix\Media\Transcoding\TranscodeManager::streamBitrate()} — the two
     * paths must agree or a rescan would shift the ladder.
     *
     * @param array<string, mixed> $stream One ffprobe stream entry.
     */
    private static function streamBitrate(array $stream): ?int
    {
        $bitrate = self::intOrNull($stream['bit_rate'] ?? null);
        if ($bitrate !== null && $bitrate > 0) {
            return $bitrate;
        }

        $tags = is_array($stream['tags'] ?? null) ? $stream['tags'] : [];
        foreach ($tags as $key => $value) {
            if (!is_string($key) || stripos($key, 'BPS') !== 0) {
                continue;
            }
            $tagged = self::intOrNull($value);
            if ($tagged !== null && $tagged > 0) {
                return $tagged;
            }
        }

        return null;
    }

    /**
     * Coerce a probe value to an int, or null when not numeric. ffprobe emits
     * many numbers as strings (e.g. bit_rate "5000000"), so both ints and
     * numeric strings are accepted.
     *
     * @param mixed $value Raw probe value.
     */
    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Coerce a probe value to a non-empty string, or null otherwise.
     *
     * @param mixed $value Raw probe value.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return (is_string($value) && $value !== '') ? $value : null;
    }

    /**
     * Whether an ffprobe stream is an embedded attached picture (cover art /
     * poster) rather than real playable video — `disposition.attached_pic = 1`.
     * Such a stream reports the poster's tiny dimensions, which must not be
     * mistaken for the source resolution when building the ABR ladder. Accepts
     * a mixed value so callers never need to pre-narrow the raw stream array.
     *
     * @param mixed $stream Raw ffprobe stream entry.
     */
    private static function isAttachedPic(mixed $stream): bool
    {
        if (!is_array($stream)) {
            return false;
        }
        $disposition = $stream['disposition'] ?? null;
        if (!is_array($disposition)) {
            return false;
        }
        $attached = $disposition['attached_pic'] ?? null;
        return is_numeric($attached) && (int) $attached === 1;
    }

    /**
     * Whether this `video` stream loses its `media_streams` row to the cover-art
     * skip. TRUE only for a flagged {@see isAttachedPic()} stream, and then only
     * when the file has some OTHER record of its video content: any non-poster
     * video-type stream at all, or (for a poster-only file) another poster that
     * was promoted ahead of this one.
     *
     * WHY IT IS NOT "`$stream !== $video`". A poster can now win the primary
     * `$video` role in a second way — every non-poster video stream having
     * implausible geometry promotes the fallback — and letting that promotion
     * also grant a row was a REGRESSION AGAINST MASTER, not a widening of it.
     * A/B'd against pristine `b07b5de3` on a file with a flagged 600x900 mjpeg
     * cover at index 0 and a codec-less `1920x0` content track at index 1:
     *
     *     master  rows [video#1 NULL, audio#2]                 videoCodecMissing = TRUE
     *     coupled rows [video#0 mjpeg, video#1 NULL, audio#2]  videoCodecMissing = FALSE
     *
     * `StreamProbeBackfill::videoCodecMissing()` takes the FIRST codec-bearing
     * video row, so the added poster row answers "this item's codec is known"
     * and `ensureVideoCodecFor()` short-circuits — the item can never be
     * re-probed, which is precisely the permanently-unrepairable outcome the
     * DEGENERATE paragraph of {@see summarizeProbe()} exists to prevent. The same
     * row also becomes the lowest `stream_index`, so
     * `ItemRepository::getVideoStreamColorMetadata()` would read the cover art's
     * color metadata instead of the content track's.
     *
     * So `source` selection and row persistence are decoupled for posters
     * exactly as they already are for degenerate streams: a poster may describe
     * the file without being recorded as one of its tracks.
     *
     * @param array<string, mixed>      $stream            The video stream being persisted.
     * @param array<string, mixed>|null $video             The promoted primary video stream.
     * @param bool                      $hasNonPosterVideo Whether the file carries any
     *                                                     video-type stream that is not
     *                                                     flagged cover art.
     */
    private static function coverArtLosesItsRow(array $stream, ?array $video, bool $hasNonPosterVideo): bool
    {
        if (!self::isAttachedPic($stream)) {
            return false;
        }

        return $hasNonPosterVideo || $stream !== $video;
    }

    /**
     * Every way in which an ffprobe `video` stream is DEGENERATE — a stream
     * ffprobe labelled `codec_type: video` whose own description is not
     * self-consistent. Returns the list of defect ids, EMPTY for a sound stream,
     * so the caller can both act on the geometry ones and report exactly which
     * rule fired.
     *
     * The defects:
     *
     *  - {@see DEFECT_CODEC_ABSENT} — `codec_name` absent, empty, or not a
     *    string, so `stringOrNull()` would store `NULL`. Purely informational:
     *    it does NOT disqualify the stream from anything. ffmpeg reports exactly
     *    this for a sample-entry fourcc the installed build cannot map, so the
     *    stream is very often real content (see {@see summarizeProbe()}).
     *  - {@see DEFECT_DIMENSION_NOT_POSITIVE} — width or height absent or ≤ 0
     *    (the real `1920x0` header on production; also `0x0` from an MPEG-TS
     *    joined mid-GOP).
     *  - {@see DEFECT_ASPECT_BEYOND_BOUND} — aspect beyond
     *    {@see MAX_VIDEO_ASPECT_RATIO}:1 either way (the real `4x3841` /
     *    `4x5634` headers, 1:960 and 1:1408).
     *
     * There is deliberately NO upper bound on a single dimension: a 70000x1000
     * ffv1 file encodes, probes and decodes with exit 0 on ffmpeg 6.1.1, and a
     * `MAX_VIDEO_DIMENSION = 65536` rule fired on 0 of the 18 measured broken
     * rows while taking `source` away from that real file. See
     * {@see MAX_VIDEO_ASPECT_RATIO}.
     *
     * Both geometry defects are the ones {@see defectsIncludeGeometry()} reports
     * and that cost a stream the primary-`$video` role, because geometry is what
     * `metadata_json['source']` and the ABR ladder are built from. NEITHER of
     * them costs a stream its `media_streams` row.
     *
     * Judges the VALUES only, so it holds whatever ffprobe's reason was. NOT a
     * misclassified `data`/`attachment` stream — those are already filtered in
     * {@see summarizeProbe()} — and not a synonym for cover art either.
     *
     * ⚠ The not-positive check must stay ABOVE the ratio, and must stay `<= 0`
     * rather than `< 0`: the production shape is `1920x0`, so relaxing it lets a
     * zero height reach `$width / $height` and the scan dies with a
     * `DivisionByZeroError`. Proved by mutation.
     *
     * Accepts a mixed value so callers never need to pre-narrow the raw stream
     * array (mirrors {@see isAttachedPic()}).
     *
     * @param mixed $stream Raw ffprobe stream entry.
     *
     * @return list<string> Defect ids, empty when the stream describes itself
     *                      consistently.
     */
    private static function videoStreamDefects(mixed $stream): array
    {
        if (!is_array($stream)) {
            return [];
        }

        $defects = [];
        if (self::stringOrNull($stream['codec_name'] ?? null) === null) {
            $defects[] = self::DEFECT_CODEC_ABSENT;
        }

        $width = self::intOrNull($stream['width'] ?? null);
        $height = self::intOrNull($stream['height'] ?? null);
        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            $defects[] = self::DEFECT_DIMENSION_NOT_POSITIVE;

            return $defects; // no usable ratio, and 0 would divide by zero
        }

        $ratio = $width / $height;
        if ($ratio > self::MAX_VIDEO_ASPECT_RATIO || $ratio < (1.0 / self::MAX_VIDEO_ASPECT_RATIO)) {
            $defects[] = self::DEFECT_ASPECT_BEYOND_BOUND;
        }

        return $defects;
    }

    /**
     * Whether a {@see videoStreamDefects()} result contains a GEOMETRY defect —
     * i.e. one that makes the stream unfit to define `metadata_json['source']`
     * and the ABR ladder built from it.
     *
     * Spelled as "anything that is not {@see DEFECT_CODEC_ABSENT}" on purpose:
     * a new geometry rule added to {@see videoStreamDefects()} is then
     * automatically honoured here, whereas an allowlist would silently ignore it.
     * Only a defect that is deliberately consequence-free belongs on the
     * exemption list.
     *
     * @param list<string> $defects
     */
    private static function defectsIncludeGeometry(array $defects): bool
    {
        foreach ($defects as $defect) {
            if ($defect !== self::DEFECT_CODEC_ABSENT) {
                return true;
            }
        }

        return false;
    }

    /**
     * Report every degenerate `video` stream ffprobe returned for one file.
     *
     * WHY THIS EXISTS. The geometry predicate in {@see videoStreamDefects()} is
     * a heuristic sized against one library's measured contents
     * ({@see MAX_VIDEO_ASPECT_RATIO}), and a false positive changes which stream
     * describes the file — permanently. Without a log the only
     * evidence would be a `source` descriptor quietly taken from a stream other
     * than ffprobe's first — indistinguishable after the fact from a file that
     * really is shaped that way. This class already makes the same call in the
     * other direction: `StreamProbeBackfill::degradeAfterWriteFailure()` masks a
     * write failure and documents that "the masking is made recoverable by being
     * LOUD".
     *
     * LEVELS, chosen for volume on a resident event loop. A full scan calls this
     * at most once per file and only for files that HAVE a degenerate stream (18
     * of 37,925 measured video rows), so:
     *
     *  - `warning` when a geometry defect actually cost the stream the primary
     *    `$video` role — the consequential case, and the one an operator must be
     *    able to audit;
     *  - `debug` otherwise: a codec-less stream (which changes nothing at all,
     *    and is the normal shape of a DRM `encv`/`drmi` title — a library full of
     *    those must not fill error.log), or a geometry defect on the stream that
     *    was promoted anyway — the file's first video-type stream, taken because
     *    the selection loop rejected every candidate and nothing better existed.
     *
     * Static because {@see summarizeProbe()} is; {@see LoggerFactory::get()} is a
     * static accessor over a per-channel singleton, so this adds no state and no
     * unbounded growth.
     *
     * `row_persisted` is not hard-coded: a degenerate stream that is ALSO
     * flagged cover art can lose its row to the pre-existing skip, so the field
     * is computed with {@see coverArtLosesItsRow()} — the same call the persist
     * loop makes, so the log and the write can never disagree about it.
     *
     * @param list<array{stream: array<string, mixed>, defects: list<string>}> $degenerate
     * @param array<string, mixed>      $format ffprobe `format` block (for `filename`).
     * @param array<string, mixed>|null $video  The stream chosen as primary, after
     *                                          the fallback promotion.
     * @param bool $hasNonPosterVideo Whether the file carries any video-type
     *                                stream that is not flagged cover art.
     */
    private static function logDegenerateVideoStreams(
        array $degenerate,
        array $format,
        ?array $video,
        bool $hasNonPosterVideo
    ): void {
        $logger = LoggerFactory::get(LogChannels::MEDIA);
        $path = self::stringOrNull($format['filename'] ?? null);

        foreach ($degenerate as $seen) {
            $stream = $seen['stream'];
            $context = [
                'path' => $path,
                'stream_index' => self::intOrNull($stream['index'] ?? null),
                'codec' => self::stringOrNull($stream['codec_name'] ?? null),
                'width' => self::intOrNull($stream['width'] ?? null),
                'height' => self::intOrNull($stream['height'] ?? null),
                'defects' => $seen['defects'],
                'source_stream_index' => $video !== null ? self::intOrNull($video['index'] ?? null) : null,
                // Stated explicitly so a reader of the log never has to guess
                // whether the row was dropped. No defect can drop it; only the
                // cover-art skip can, and only for a flagged poster.
                'row_persisted' => !self::coverArtLosesItsRow($stream, $video, $hasNonPosterVideo),
            ];

            if (self::defectsIncludeGeometry($seen['defects']) && $stream !== $video) {
                $logger->warning(
                    'ffprobe video stream has implausible geometry; not used as the source descriptor',
                    $context
                );
                continue;
            }

            $logger->debug('ffprobe reported a degenerate video stream; row persisted as-is', $context);
        }
    }

    /**
     * Extract a stream's ISO language tag (ffprobe `tags.language`), truncated
     * to the media_streams.language column width (10). Returns null when absent,
     * empty, or the "und" (undetermined) placeholder. Accepts a mixed value so
     * callers never need to pre-narrow the raw stream array.
     *
     * Truncation is character-wise (mb_substr), never byte-wise: the column is
     * VARCHAR(10) under utf8mb4, so 10 is a CHARACTER budget, and a byte-wise
     * cut of a mistagged non-ASCII value (e.g. "Deutsch (Österreich)") lands
     * mid-sequence and yields invalid UTF-8. MySQL rejects that row outright
     * with error 1366 ("Incorrect string value: '\xC3'").
     *
     * That single bad row costs the item most of its stream set, because both
     * write paths delete before they re-insert and neither is transactional:
     *
     *  - {@see StreamProbeBackfill::ensureFor()} calls `deleteStreamsByItem()`
     *    and then `addStream()` per row; its `catch (\Throwable)` stamps
     *    `markStreamsProbed()` unconditionally — it does not inspect the error.
     *    The probed-marker guard at the top of that method then means the item
     *    is never re-probed, so it keeps whatever partial set survived the throw,
     *    permanently.
     *  - {@see persistStreams()} deletes first too. It does NOT stamp on failure,
     *    so the item stays repairable, but it is left with a partial set until
     *    something rescans it.
     *
     * Note the asymmetry that let this through: `media_items` writes are cleaned
     * by `ItemRepository::toValidUtf8()`, but `ItemRepository::addStream()` binds
     * `language`/`title` straight into the INSERT with no such guard, so
     * media_streams values must already be valid UTF-8 when they arrive here.
     *
     * @param mixed $stream Raw ffprobe stream entry.
     */
    private static function streamLanguage(mixed $stream): ?string
    {
        if (!is_array($stream)) {
            return null;
        }
        $tags = $stream['tags'] ?? null;
        if (!is_array($tags)) {
            return null;
        }
        $lang = $tags['language'] ?? null;
        if (!is_string($lang) || $lang === '' || strtolower($lang) === 'und') {
            return null;
        }
        return mb_substr($lang, 0, 10, 'UTF-8');
    }

    /**
     * Extract a stream's human-readable title (ffprobe `tags.title`, e.g.
     * "Director's Commentary" / "English SDH"), truncated to the
     * media_streams.title column width (255). Returns null when absent or
     * empty. Accepts a mixed value so callers never need to pre-narrow the raw
     * stream array.
     *
     * @param mixed $stream Raw ffprobe stream entry.
     */
    private static function streamTitle(mixed $stream): ?string
    {
        if (!is_array($stream)) {
            return null;
        }
        $tags = $stream['tags'] ?? null;
        if (!is_array($tags)) {
            return null;
        }
        $title = $tags['title'] ?? null;
        if (!is_string($title) || $title === '') {
            return null;
        }
        return mb_substr($title, 0, 255, 'UTF-8');
    }

    /**
     * Whether an ffprobe stream carries the default disposition flag
     * (`disposition.default = 1`) — the container's preferred track of its
     * type, surfaced as media_streams.is_default so the player can pre-select
     * it. Accepts a mixed value so callers never need to pre-narrow the raw
     * stream array.
     *
     * @param mixed $stream Raw ffprobe stream entry.
     */
    private static function isDefaultDisposition(mixed $stream): bool
    {
        if (!is_array($stream)) {
            return false;
        }
        $disposition = $stream['disposition'] ?? null;
        if (!is_array($disposition)) {
            return false;
        }
        $flag = $disposition['default'] ?? null;
        return is_numeric($flag) && (int) $flag === 1;
    }

    /**
     * Replace a media item's media_streams rows with the freshly-probed set,
     * reporting whether the replacement fully succeeded.
     *
     * Idempotent: the item's existing stream rows are cleared first, so a
     * rescan re-inserts rather than duplicates. Fully guarded — a stream-write
     * failure is logged and never aborts the scan, but is signalled by a false
     * return so callers can leave the row repairable (see
     * {@see backfillItemSourceMetadata()}). An empty stream set, or an empty
     * item id, is a no-op success.
     *
     * ATOMIC: the clear + re-insert is one transaction
     * ({@see ItemRepository::replaceStreams()}). Two consequences that used to
     * be defects. A rescan can no longer expose a half-replaced set to a
     * concurrent browse/detail read (`streams: []` or a partial set), and a
     * write that fails part-way now leaves the item's PREVIOUS rows in place
     * instead of stranding it permanently partial — the `false` return then
     * means "nothing changed", not "half-written".
     *
     * The ffprobe that produced `$streams` happens in the caller, so no blocking
     * probe is ever held inside the transaction.
     *
     * On full success the item is also stamped `streams_probed_at` (see
     * {@see ItemRepository::markStreamsProbed()}) so the lazy playback-info
     * backfill ({@see StreamProbeBackfill}) never re-probes an item whose full
     * stream set was already persisted — including files that genuinely have
     * one audio track and no subtitles.
     *
     * @param string                     $itemId  Media item UUID.
     * @param list<array<string, mixed>> $streams Stream rows for {@see ItemRepository::addStream()}.
     * @return bool True when the streams were persisted (or there were none);
     *              false when a write failed part-way.
     */
    private function persistStreams(string $itemId, array $streams): bool
    {
        if ($itemId === '' || $streams === []) {
            return true;
        }

        try {
            $this->itemRepository->replaceStreams($itemId, $streams);
            $this->itemRepository->markStreamsProbed($itemId);
            return true;
        } catch (\Throwable $e) {
            $this->logger->debug('Persisting media streams failed; continuing scan', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Backfill source technical metadata onto ONE already-indexed media item
     * from a single fresh ffprobe: the total duration, the compact
     * `metadata_json['source']` summary, and the media_streams rows.
     *
     * Shared entry point for BOTH the incremental rescan path (invoked from
     * {@see processFile()} when a file is already indexed) and the offline
     * backfill CLI (`scripts/backfill-source-metadata.php`), so the two never
     * drift. Idempotent — an item that already has both a positive duration and
     * a `source` blob is skipped WITHOUT probing. Fully guarded: a probe or
     * write failure returns `'failed'` rather than throwing, so one bad item
     * never aborts a scan or a batch run.
     *
     * Repairability invariant: media_streams are replaced FIRST (a blanket
     * delete-then-insert). If that replacement fails part-way we write NEITHER
     * `metadata_json['source']` NOR the duration and return `'failed'`, so the
     * row is left source-less and the backfill CLI (which reselects on
     * `source IS NULL`) picks it up again on the next run rather than stranding
     * it with a populated `source` but partial/zero streams.
     *
     * @param array<string, mixed> $existing Hydrated media_items row; needs
     *                                        `id`, `type`, `path`, and either a
     *                                        decoded `metadata` or a raw
     *                                        `metadata_json`.
     * @return 'updated'|'skipped'|'failed' `'updated'` when metadata and/or
     *         streams were written; `'skipped'` when nothing was needed
     *         (already populated / not time-based / missing id or path / no
     *         ffprobe runner / probe yielded no new data); `'failed'` when the
     *         probe or a stream write failed and the item should be retried
     *         on a later run.
     */
    public function backfillItemSourceMetadata(array $existing): string
    {
        if ($this->ffmpeg === null) {
            return 'skipped';
        }

        try {
            $type = $existing['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::DURATION_PROBE_TYPES, true)) {
                return 'skipped';
            }

            $id = $existing['id'] ?? null;
            if (!is_string($id) || $id === '') {
                return 'skipped';
            }

            $path = $existing['path'] ?? null;
            if (!is_string($path) || $path === '') {
                return 'skipped';
            }

            $meta = $this->existingMetadata($existing);
            $hasDuration = isset($meta['duration_seconds'])
                && is_numeric($meta['duration_seconds'])
                && (int) $meta['duration_seconds'] > 0;
            $hasSource = isset($meta['source']) && is_array($meta['source']);
            if ($hasDuration && $hasSource) {
                return 'skipped'; // Already populated — idempotent, no probe.
            }

            $summary = $this->probeSummary($path);
            if ($summary === null) {
                return 'failed'; // Probe failed (missing/unreadable) — retry later.
            }

            // Replace media_streams FIRST from the fresh probe. If that fails we
            // must not write source/duration below, so the item stays
            // source-less and is reselected for a clean retry instead of being
            // stranded half-populated.
            if (!$this->persistStreams($id, $summary['streams'])) {
                return 'failed';
            }

            $metaChanged = false;
            if (!$hasDuration && $summary['duration_seconds'] !== null) {
                $meta['duration_seconds'] = $summary['duration_seconds'];
                $metaChanged = true;
            }
            if (!$hasSource && $summary['source'] !== null) {
                $meta['source'] = $summary['source'];
                $metaChanged = true;
            }
            if ($metaChanged) {
                $this->itemRepository->update($id, ['metadata_json' => $meta]);
                return 'updated';
            }

            // Streams persisted cleanly but there was nothing new to stamp into
            // metadata (e.g. a probe exposing no A/V source summary).
            return $summary['streams'] !== [] ? 'updated' : 'skipped';
        } catch (\Throwable $e) {
            $this->logger->debug('Source metadata backfill failed; continuing scan', [
                'path' => is_string($existing['path'] ?? null) ? $existing['path'] : '',
                'error' => $e->getMessage(),
            ]);
            return 'failed';
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
     * its own separate series). Other library types pass through unchanged,
     * EXCEPT `'image'`: that is a scanner-side label (it keys the extension set
     * in {@see loadNamingOptions()} and is what {@see LibraryManager} passes for
     * a photo library) and is NOT a member of the `media_items.type` ENUM — the
     * column calls the same concept `'photo'`. Returning it verbatim made every
     * photo INSERT fail on a strict-mode server (error 1265, "Data truncated for
     * column 'type'"), so a photo library could never produce a single row. Map
     * it across to the real ENUM member.
     *
     * @param SplFileInfo $file The file info
     * @param string $libraryType The library type ('video', 'series', 'movie', 'audio', 'image', …)
     * @return string The specific media type ('movie', 'audio', 'photo', 'book', …)
     */
    private function determineMediaType(SplFileInfo $file, string $libraryType): string
    {
        if ($this->isVideoContentLibrary($libraryType)) {
            return 'movie';
        }

        if ($libraryType === 'image') {
            return 'photo';
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

        // Scope to the library so the raw-path fallback in findByPath (containers
        // are a NON-deduped type → NULL path_hash → the fast path_hash pass always
        // misses them) is an index range on library_id, not a full-table scan.
        $existing = $this->itemRepository->findByPath($syntheticPath, $libraryId);
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

        $id = (string) $this->itemRepository->upsertByPath([
            'library_id' => $libraryId,
            'parent_id' => $parentId,
            'name' => $name,
            'type' => $type,
            'path' => $syntheticPath,
            'metadata_json' => $metadata,
        ]);

        $this->containerCache[$syntheticPath] = $id;

        // Series-container enrichment gap (F2b): leaf movie/episode items dispatch
        // {@see MediaItemAdded} at line ~1446, but series PARENTS are created here
        // and would otherwise emit no event — so anime providers (anidb/mal), which
        // match at the SERIES level, never auto-enrich. Dispatch a `series`-type
        // event on genuine creation of a top-level series container (parent_id ===
        // null, both existing-row branches above return early, so this point is
        // reached only for a freshly created container). Seasons are excluded:
        // they are not an enrichable type. The event carries the synthetic path,
        // which the background enricher never reads — it searches by the item's
        // title/year — so a non-filesystem path is harmless. Enrichment is
        // idempotent (plugin_enriched marker + needsEnrichment check), so this is
        // symmetric with the movie leaf dispatch: fired once at creation, before
        // the later metadata-scan job resolves TMDB.
        if ($type === 'series' && $parentId === null) {
            $this->dispatchMediaItemAdded($id, $libraryId, $syntheticPath, 'series');
        }

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
