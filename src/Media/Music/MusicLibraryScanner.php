<?php

/**
 * Phlix media server component: Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Music;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Uuid;
use Phlix\Media\Library\ScanIgnorePatterns;
use Phlix\Media\Library\ScanResult;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Shared\Events\Library\MediaItemAdded;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SplFileInfo;
use Workerman\MySQL\Connection;

/**
 * MusicLibraryScanner discovers and indexes music files into the Artist→Album→Track hierarchy.
 *
 * Scans directories for audio files (mp3, flac, m4a, ogg, wav), reads metadata
 * (artist, album, title, track number, disc number, duration) — natively via
 * getID3 first (a pure-PHP tag reader, ~1-3 ms/file), falling back to
 * FFmpeg/ffprobe only when getID3 yields nothing usable — and creates
 * artist/album/track entries with a `media_items` FK.
 *
 * **Progress.** {@see self::scanDirectory()} accepts an optional
 * `(processed, total, currentPath)` callback and ticks it once per audio file
 * during the (slow) tag-reading pass, so the async scan worker can stream a real
 * percentage onto the job row instead of leaving the UI frozen. Use
 * {@see self::countAudioFiles()} to pre-compute the denominator.
 *
 * **Incremental flush (S95).** The scan used to be two-phase: tag-probe EVERY
 * audio file under the path into one in-memory map, and only then run the upsert
 * loop. That meant no row at all was written until the whole walk finished — ~3
 * hours on a 60k-file library — so every scan that was interrupted by a worker
 * restart lost 100% of its work and the library stayed permanently empty while
 * the progress bar looked healthy (the percentage came from the *walk*, not from
 * any write). {@see self::scanDirectory()} now buffers only a bounded window of
 * albums and flushes each one as soon as it is complete, so rows land
 * continuously and an interrupted scan keeps everything already written. See
 * {@see self::MAX_OPEN_ALBUMS} for the flush trigger and its trade-offs.
 *
 * @author Phlix Development Team
 * @version 1.1.0
 * @description Scans directories for audio files and builds Artist→Album→Track hierarchy
 * @see FfmpegRunner For FFprobe metadata extraction (fallback)
 * @see ScanResult For scan operation results
 *
 * @autowire
 */
class MusicLibraryScanner
{
    /** Supported audio file extensions */
    private const AUDIO_EXTENSIONS = ['mp3', 'flac', 'm4a', 'ogg', 'wav'];

    /**
     * How many albums may be accumulating tracks at the same time.
     *
     * THE FLUSH TRIGGER. An album is buffered under its *tag* identity
     * (`artist|album`), never under its directory, and is written out when it
     * falls out of this least-recently-touched window (or when the walk ends, or
     * when it exceeds {@see self::MAX_TRACKS_PER_FLUSH}).
     *
     * Why not "flush when the directory changes": one album is routinely spread
     * over several directories (`Album/CD1`, `Album/CD2`; a compilation filed per
     * artist), and a directory-triggered flush would write it as two separate
     * batches. Why not "flush when the album tag changes" (window of 1): a single
     * directory routinely holds several albums (singles/mixed folders, a "Various
     * Artists" record where every track carries a different `artist` tag), and a
     * window of 1 would thrash — and if the walk interleaves albums, "wait for the
     * tag to change" degenerates into buffering the whole tree again.
     *
     * A window of 32 keeps every realistic multi-disc / mixed-folder case in one
     * flush (`RecursiveIteratorIterator` visits sibling directories consecutively,
     * so `CD1` and `CD2` are adjacent in walk order) while still bounding memory.
     *
     * ACCEPTED FAILURE MODE: if one album's files are separated in walk order by
     * more than 32 *other* albums, it is evicted and later re-opened, so it is
     * written in two or more batches. That is not a duplicate and not data loss —
     * {@see self::upsertArtist()} and {@see self::upsertAlbum()} are find-or-create
     * on their natural keys and {@see self::upsertTrack()} is find-or-create on
     * `(media_items.path, library_id)`. The cost is a few extra round-trips, and
     * `music_albums.total_tracks` is recomputed from `music_tracks` on every flush
     * ({@see self::refreshAlbumTrackTotal()}) so the final count is still exact.
     */
    private const MAX_OPEN_ALBUMS = 32;

    /**
     * How many tracks a single album may buffer before it is flushed early.
     *
     * The album-keyed window above cannot bound memory on its own: one album that
     * legitimately holds every file in the tree (a single flat directory with one
     * album tag) would stay open for the whole walk. This cap turns that case into
     * a series of partial flushes of the SAME album — correct, because the album
     * upsert is find-or-create and `total_tracks` is recomputed from the table.
     *
     * 250 is far above any real album, so ordinary albums are never chunked.
     */
    private const MAX_TRACKS_PER_FLUSH = 250;

    /**
     * Ceiling on the per-scan artist find-or-create cache
     * ({@see self::upsertArtist()}).
     *
     * Now that flushes are spread across the whole walk these caches live for the
     * entire scan, so they need a bound of their own: a library whose files carry
     * no album tag produces one distinct artist/album key per FILE, which would
     * otherwise reintroduce the unbounded growth this step removed. Eviction is
     * always safe — a miss just costs one `SELECT`.
     */
    private const MAX_ARTIST_CACHE = 512;

    /** Ceiling on the per-scan album find-or-create cache ({@see self::upsertAlbum()}). */
    private const MAX_ALBUM_CACHE = 512;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var Connection Database connection */
    private Connection $db;

    /** @var FfmpegRunner FFprobe runner for metadata extraction (fallback) */
    private FfmpegRunner $ffmpeg;

    /** @var \getID3|null Lazily-constructed native tag reader. */
    private ?\getID3 $id3Reader = null;

    /**
     * @var EventDispatcherInterface|null PSR-14 dispatcher for library lifecycle
     * events. When wired (the library-scan worker path), each genuinely-new
     * track publishes a {@see MediaItemAdded} that the enabled `musicbrainz`
     * plugin subscribes for enrichment — the only music-enrichment path after
     * the native providers were removed (F4). Null in the legacy/manual
     * construction sites, where enrichment is simply not triggered.
     */
    private ?EventDispatcherInterface $eventDispatcher;

    /**
     * @var ScanIgnorePatterns Effective `scanner.ignore_patterns` list consulted
     * by {@see self::shouldSkipFile()} — the SAME live admin setting the video
     * {@see \Phlix\Media\Library\MediaScanner} reads, not a hardcoded list.
     */
    private ScanIgnorePatterns $ignorePatterns;

    /**
     * Constructor for MusicLibraryScanner.
     *
     * @param Connection $db Database connection
     * @param FfmpegRunner $ffmpeg FFmpeg runner for metadata extraction
     * @param LoggerInterface|null $logger Optional custom logger
     * @param EventDispatcherInterface|null $eventDispatcher Optional PSR-14
     *        dispatcher; when supplied, {@see MediaItemAdded} is published for
     *        each new track so plugin metadata enrichment can run.
     * @param ScanIgnorePatterns|null $ignorePatterns Effective
     *        `scanner.ignore_patterns` list; NULL degrades to a store-less
     *        instance yielding {@see ScanIgnorePatterns::DEFAULT_PATTERNS}.
     */
    public function __construct(
        Connection $db,
        FfmpegRunner $ffmpeg,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?ScanIgnorePatterns $ignorePatterns = null
    ) {
        $this->db = $db;
        $this->ffmpeg = $ffmpeg;
        $this->logger = $this->createLogger($logger);
        $this->eventDispatcher = $eventDispatcher;
        // Never null internally: a legacy construction that omits it gets a
        // store-less instance, which yields ScanIgnorePatterns::DEFAULT_PATTERNS.
        $this->ignorePatterns = $ignorePatterns ?? new ScanIgnorePatterns();
    }

    /**
     * Creates a structured logger for the music subsystem.
     */
    private function createLogger(?LoggerInterface $logger): StructuredLogger
    {
        if ($logger instanceof StructuredLogger) {
            return $logger;
        }

        $tempDir = sys_get_temp_dir() . '/phlix_music_scanner_' . uniqid();
        mkdir($tempDir, 0755, true);

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/music_scanner.log',
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
     * Counts the audio files under a path that {@see self::scanDirectory()}
     * would process — using the SAME extension + skip filters — so a caller can
     * pre-compute the progress denominator without reading any tags.
     *
     * @param string $path Root path to count under.
     * @return int Number of scannable audio files (0 when the path is unreadable).
     */
    public function countAudioFiles(string $path): int
    {
        if (!is_dir($path) || !is_readable($path)) {
            return 0;
        }

        // Re-read `scanner.ignore_patterns` once per walk (read-path class (a))
        // so the count denominator uses the same effective skip list the scan
        // will apply.
        $this->ignorePatterns->refresh();

        $count = 0;
        foreach ($this->audioFileIterator($path) as $file) {
            unset($file);
            $count++;
        }

        return $count;
    }

    /**
     * Scans a directory tree for audio files and builds the Artist→Album→Track hierarchy.
     *
     * Recursively walks the given path, reads metadata from each audio file,
     * and upserts the data into music_artists, music_albums, and music_tracks tables
     * with corresponding media_items entries.
     *
     * **Writes are incremental (S95).** Files are grouped by their `artist|album`
     * tag identity into a bounded window of at most {@see self::MAX_OPEN_ALBUMS}
     * albums; each album is upserted the moment it leaves that window, so rows
     * start appearing within the first few hundred files instead of only after the
     * whole tree has been tag-probed. Consequences that matter:
     *
     *  - **Interrupting the scan keeps every row already flushed.** Nothing is
     *    wrapped in an outer transaction (deliberately — see below), so each
     *    album's inserts are already committed when the worker dies.
     *  - **A re-scan resumes.** Artists/albums are find-or-create on their natural
     *    keys and tracks are find-or-create on `(media_items.path, library_id)`, so
     *    a second pass adds only what is missing and re-adds nothing.
     *  - **Memory is flat**, not proportional to the tree: at most
     *    `MAX_OPEN_ALBUMS × MAX_TRACKS_PER_FLUSH` = 32 × 250 = 8,000 buffered
     *    files at any instant. One buffered entry
     *    (`['file' => SplFileInfo, 'meta' => [...]]`) measures **1,463 bytes**
     *    retained on PHP 8.3.6 with ~60-character paths, so the real ceiling is
     *    **≈11.1 MB** (11,656,488 B measured with 7,968 entries open) — and it is
     *    reached ONLY by the pathological shape where 32 album keys are interleaved
     *    right up to the per-album chunk limit. Flat in library size, against one
     *    such entry per FILE for the whole-tree map this replaced (≈89 MB for the
     *    61,135-file path that motivated the step). Pinned by
     *    {@see \Phlix\Tests\Unit\Media\Music\MusicLibraryScannerTest::testMemoryStaysBoundedAcrossALargeTree()}.
     *
     * **No transactions, on purpose.** Per-album transactions would buy nothing
     * here (each album is a handful of autocommitted statements and partial
     * durability is exactly what makes an interrupted scan resumable) while risking
     * a nested `START TRANSACTION` inside a caller that already opened one — a
     * failure mode this codebase has already hit in production.
     *
     * **Orphan adoption is gated by ONE query per scan.** The per-entity lookups
     * that reclaim a previous scan's orphaned artist/album `media_items` rows
     * ({@see self::findAdoptableArtistMediaItemId()}) hit an unindexed
     * `media_items.name`, so they are only issued when
     * {@see self::hasAdoptableMusicMediaItem()} has established that this library
     * has at least one adoptable row at all — see that method for the measurement.
     *
     * @param string        $path       Root path to scan
     * @param callable|null $onProgress Optional `(int $processed, int $total, string $currentPath): void`
     *                                  sink, ticked once per audio file during the tag-reading pass.
     *                                  Still exactly one tick per file, so the scan
     *                                  worker's write throttle is unaffected.
     * @param string|null   $libraryId  Owning library UUID. Stamped onto every
     *                                  `media_items` row this scan creates and
     *                                  carried on the {@see MediaItemAdded} event.
     *                                  NULL only for the legacy manual-path scan
     *                                  endpoint (no library context).
     * @return ScanResult Summary of the scan operation. `scanned` counts audio
     *                    FILES read (matching {@see ScanResult}'s documented
     *                    "total number of files scanned" and
     *                    {@see self::countAudioFiles()}); it previously counted
     *                    album groups, which is no longer even well defined now
     *                    that one album can be flushed in several batches.
     *
     * @example
     * ```php
     * $scanner = new MusicLibraryScanner($db, $ffmpeg);
     * $result = $scanner->scanDirectory('/music/rock', null, $libraryId);
     * echo "Scanned {$result->scanned}, added {$result->added}, updated {$result->updated}";
     * ```
     */
    public function scanDirectory(string $path, ?callable $onProgress = null, ?string $libraryId = null): ScanResult
    {
        $result = new ScanResult();
        $startTime = hrtime(true);

        // Guard: path must be accessible
        if (!is_dir($path) || !is_readable($path)) {
            $this->logger->warning('Scan path is not accessible', ['path' => $path]);
            return $result;
        }

        // Re-read `scanner.ignore_patterns` once per scan (read-path class (a)).
        $this->ignorePatterns->refresh();

        $this->logger->info('Starting music directory scan', ['path' => $path]);

        // Progress denominator. Only paid for when a sink is wired.
        $total = $onProgress !== null ? $this->countAudioFiles($path) : 0;

        // ONE query decides whether this scan needs the per-entity orphan-adoption
        // lookups at all. On a healthy library the answer is "no" and every artist
        // and album then skips an unindexed `media_items.name` probe.
        //
        // FAIL SAFE, not fail fast: this runs outside every catch in the walk, so a
        // transient error here would otherwise abort a multi-hour scan that has
        // nothing wrong with it (measured — a fault injected on this statement was
        // the ONE position of a 40-statement fault sweep that escaped
        // scanDirectory()). Degrading to "do the per-entity lookups" costs time and
        // nothing else.
        try {
            $mayAdopt = $this->hasAdoptableMusicMediaItem($libraryId);
        } catch (\Throwable $gateError) {
            $this->logger->warning('Orphan-adoption gate failed; using the per-entity lookups', [
                'path' => $path,
                'error' => $gateError->getMessage(),
            ]);
            $mayAdopt = true;
        }

        // Track artist/album IDs to handle the hierarchy. Bounded (see the
        // MAX_*_CACHE constants) because they now live for the whole walk.
        /** @var array<string, array{id:int, media_item_id:string|null}> $artistCache */
        $artistCache = [];
        /** @var array<string, array{id:int, media_item_id:string|null}> $albumCache */
        $albumCache = [];

        /**
         * Albums currently accumulating tracks, keyed by `md5(artist|album)`.
         *
         * @var array<string, array{artist:string, album:string, year:?int,
         *     files:list<array{file:SplFileInfo, meta:array<string, mixed>}>}> $open
         */
        $open = [];

        /**
         * Eviction order for $open: the SAME keys, in least-recently-touched
         * order. Kept separate from $open on purpose — re-inserting a key to move
         * it to the end of a PHP array is how the LRU order is maintained, and
         * doing that on $open itself would leave a second reference alive and turn
         * every subsequent `files[] =` append into a copy-on-write deep copy of
         * the buffered album (O(n²) per album).
         *
         * @var array<string, true> $recency
         */
        $recency = [];

        $processed = 0;

        foreach ($this->audioFileIterator($path) as $file) {
            $processed++;
            $result->scanned++;
            if ($onProgress !== null) {
                $onProgress($processed, $total, $file->getPathname());
            }

            // Read metadata from file (getID3 first, ffprobe fallback). This is
            // the slow part of the walk, and the only place tags are read: the
            // flush below reuses what we cache alongside the file here.
            $metadata = $this->probeMetadata($file->getPathname());

            $extension = strtolower($file->getExtension());

            // Determine artist and album from tags
            $artist = is_string($metadata['artist']) ? $metadata['artist'] : 'Unknown Artist';
            $album = is_string($metadata['album']) ? $metadata['album'] : $file->getBasename('.' . $extension);
            $year = is_numeric($metadata['year']) ? (int)$metadata['year'] : null;

            // Group on the album's TAG identity, not its directory — that is what
            // keeps a multi-directory album in one flush.
            $key = md5($artist . '|' . $album);

            if (!isset($open[$key])) {
                $open[$key] = [
                    'artist' => $artist,
                    'album' => $album,
                    'year' => $year,
                    'files' => [],
                ];
            }

            $open[$key]['files'][] = ['file' => $file, 'meta' => $metadata];

            // Touch: re-inserting moves the MOST-recently-used key to the END of
            // $recency, so array_key_first() below yields the least-recently-used.
            unset($recency[$key]);
            $recency[$key] = true;

            // Bound 1 — a single album may not buffer without limit.
            if (count($open[$key]['files']) >= self::MAX_TRACKS_PER_FLUSH) {
                $this->flushAlbum($open[$key], $artistCache, $albumCache, $libraryId, $result, $mayAdopt);
                unset($open[$key], $recency[$key]);
                continue;
            }

            // Bound 2 — at most MAX_OPEN_ALBUMS albums stay open; the
            // least-recently-touched one is written out to make room.
            while (count($open) > self::MAX_OPEN_ALBUMS) {
                $oldest = array_key_first($recency);
                if (!is_string($oldest) || !isset($open[$oldest])) {
                    break;
                }
                $this->flushAlbum($open[$oldest], $artistCache, $albumCache, $libraryId, $result, $mayAdopt);
                unset($open[$oldest], $recency[$oldest]);
            }
        }

        // Terminal flush: whatever the walk left open.
        foreach (array_keys($open) as $key) {
            $this->flushAlbum($open[$key], $artistCache, $albumCache, $libraryId, $result, $mayAdopt);
            unset($open[$key], $recency[$key]);
        }

        $result->durationMs = (int)((hrtime(true) - $startTime) / 1_000_000.0);

        $this->logger->info('Music directory scan complete', [
            'path' => $path,
            'scanned' => $result->scanned,
            'added' => $result->added,
            'updated' => $result->updated,
            'duration_ms' => $result->durationMs,
        ]);

        return $result;
    }

    /**
     * Writes ONE accumulated album to the database: its artist row, its album row,
     * every buffered track, then the album's authoritative `total_tracks`.
     *
     * This is the body that used to run once per entry of a whole-tree map; it now
     * runs as soon as an album leaves {@see self::scanDirectory()}'s open window,
     * which is what makes a music scan resumable.
     *
     * Defensive by design: a single malformed album or track must not abort the
     * walk. The DB layer throws on error (it does not return `false`), so without
     * the catch one unexpected row would kill the rest of the library index — and
     * with incremental flushing that would now also discard albums the walk has
     * not reached yet. The granularity is deliberately per TRACK, and
     * `total_tracks` is refreshed from a `finally`, so a bad file costs exactly
     * that file and never leaves the album's advertised count below the rows it
     * actually has.
     *
     * @param array{artist:string, album:string, year:?int,
     *     files:list<array{file:SplFileInfo, meta:array<string, mixed>}>} $albumData
     * @param array<string, array{id:int, media_item_id:string|null}> $artistCache
     * @param array<string, array{id:int, media_item_id:string|null}> $albumCache
     * @param string|null $libraryId Owning library UUID.
     * @param ScanResult  $result    Accumulates added/updated counts.
     * @param bool $mayAdopt Whether this library has any orphaned artist/album
     *        `media_items` row worth looking for — decided ONCE per scan by
     *        {@see self::hasAdoptableMusicMediaItem()} and threaded through as an
     *        argument rather than kept on `$this`, so nothing about one scan can
     *        leak into another in a resident worker.
     * @return void
     */
    private function flushAlbum(
        array $albumData,
        array &$artistCache,
        array &$albumCache,
        ?string $libraryId,
        ScanResult $result,
        bool $mayAdopt
    ): void {
        $artistName = $albumData['artist'];
        $albumTitle = $albumData['album'];

        try {
            $year = $albumData['year'];
            $files = $albumData['files'];

            // Early exit: skip if no valid artist name
            if ($artistName === '' || $artistName === 'Unknown Artist') {
                $this->logger->debug('Skipping album with unknown artist', ['album' => $albumTitle]);
                return;
            }

            // Sort this batch by track number using the CACHED metadata, so tracks
            // are inserted in playing order.
            usort($files, static function (array $a, array $b): int {
                $trackA = is_numeric($a['meta']['track_number'] ?? null) ? (int)$a['meta']['track_number'] : 0;
                $trackB = is_numeric($b['meta']['track_number'] ?? null) ? (int)$b['meta']['track_number'] : 0;

                // If track numbers are equal, compare by filename
                if ($trackA === $trackB) {
                    return strcmp($a['file']->getFilename(), $b['file']->getFilename());
                }

                return $trackA - $trackB;
            });

            // Upsert artist and get media_item_id
            $artistResult = $this->upsertArtist($artistName, $artistCache, $libraryId, $mayAdopt);
            if ($artistResult === null) {
                $this->logger->warning('Failed to upsert artist', ['artist' => $artistName]);
                return;
            }

            $artistId = $artistResult['id'];
            $artistMediaItemId = $artistResult['media_item_id'];

            // Upsert album
            $albumResult = $this->upsertAlbum(
                $artistId,
                $artistMediaItemId,
                $albumTitle,
                $year,
                $albumCache,
                $libraryId,
                $mayAdopt
            );
            if ($albumResult === null) {
                $this->logger->warning('Failed to upsert album', ['album' => $albumTitle]);
                return;
            }

            $albumId = $albumResult['id'];
            $albumMediaItemId = $albumResult['media_item_id'];

            // Upsert tracks (metadata already read during the walk — no re-probe).
            try {
                foreach ($files as $fileInfo) {
                    // Per-TRACK guard. Without it one unreadable/constraint-
                    // violating file aborted the whole album, silently abandoning
                    // every track after it (measured: 2 of 3 written), and the
                    // album's total_tracks was left at whatever the row already
                    // held. A bad file must cost exactly that file.
                    try {
                        $trackResult = $this->upsertTrack(
                            $albumId,
                            $albumMediaItemId,
                            $artistId,
                            $fileInfo['file'],
                            $fileInfo['meta'],
                            $libraryId
                        );
                    } catch (\Throwable $trackError) {
                        $this->logger->error('Skipping track after error during indexing', [
                            'album' => $albumTitle,
                            'artist' => $artistName,
                            'path' => $fileInfo['file']->getPathname(),
                            'error' => $trackError->getMessage(),
                        ]);
                        continue;
                    }

                    if ($trackResult === 'added') {
                        $result->added++;
                    } elseif ($trackResult === 'updated') {
                        $result->updated++;
                    }
                }
            } finally {
                // Recompute total_tracks from what is actually persisted. This is
                // the single writer of that column, and it is what makes a chunked
                // or re-opened album end up with the right count instead of the
                // size of whichever batch happened to be flushed last.
                //
                // In a `finally` on purpose: as the LAST statement of the `try` it
                // was skipped whenever the loop threw, which left an album that
                // HAS track rows advertising total_tracks = 0 — and
                // MusicLibraryService::getArtistWithAlbums() sums that column, so
                // the artist page reported 0 tracks for a populated album and
                // nothing ever healed it. The column must never be less true than
                // the rows.
                $this->refreshAlbumTrackTotal($albumId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Skipping album after error during indexing', [
                'album' => $albumTitle,
                'artist' => $artistName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sets `music_albums.total_tracks` to the number of `music_tracks` rows the
     * album actually has.
     *
     * Called after every album flush. Deriving the count from the table instead of
     * from the in-memory batch is what keeps it correct when an album is written in
     * more than one batch (chunked by {@see self::MAX_TRACKS_PER_FLUSH}, or evicted
     * and re-opened by {@see self::MAX_OPEN_ALBUMS}) and when a previous scan was
     * interrupted part-way through the same album.
     *
     * @param int $albumId `music_albums.id`.
     * @return void
     */
    private function refreshAlbumTrackTotal(int $albumId): void
    {
        $this->db->query(
            "UPDATE music_albums a
                SET a.total_tracks = (SELECT COUNT(*) FROM music_tracks t WHERE t.album_id = a.id)
              WHERE a.id = ?",
            [$albumId]
        );
    }

    /**
     * Stores a find-or-create result in a bounded, insertion-ordered cache.
     *
     * These caches only ever save round-trips — {@see self::upsertArtist()} and
     * {@see self::upsertAlbum()} both fall back to `SELECT`-then-`INSERT` on a miss
     * — so evicting the oldest entry can never change what gets written. Bounding
     * them is what keeps a 60k-file walk's memory flat in the pathological case
     * where every file yields a distinct album key (an untagged library, where the
     * album title falls back to the filename).
     *
     * @param array<string, array{id:int, media_item_id:string|null}> $cache Cache, by reference.
     * @param string $key Cache key.
     * @param array{id:int, media_item_id:string|null} $value Value to remember.
     * @param int $max Maximum number of retained entries.
     * @return array{id:int, media_item_id:string|null} The value, for direct return by the caller.
     */
    private function cacheRemember(array &$cache, string $key, array $value, int $max): array
    {
        $cache[$key] = $value;

        while (count($cache) > $max) {
            $oldest = array_key_first($cache);
            if (!is_string($oldest) || $oldest === $key) {
                break;
            }
            unset($cache[$oldest]);
        }

        return $value;
    }

    /**
     * Yields every scannable audio file under a path (extension + skip filters
     * applied), so counting and grouping share one definition of "in scope".
     *
     * @param string $path Root path to walk.
     * @return \Generator<int, SplFileInfo>
     */
    private function audioFileIterator(string $path): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!($file instanceof SplFileInfo) || $file->isDir()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                continue;
            }

            if ($this->shouldSkipFile($file->getFilename())) {
                continue;
            }

            yield $file;
        }
    }

    /**
     * Reads metadata from an audio file: getID3 (native, fast) first, then
     * ffprobe, then a filename-derived fallback that always succeeds.
     *
     * @param string $path Absolute filesystem path
     * @return array<string, mixed> Metadata including artist, album, title, track_number, disc_number, duration
     */
    private function probeMetadata(string $path): array
    {
        $viaId3 = $this->probeViaGetId3($path);
        if ($viaId3 !== null) {
            return $viaId3;
        }

        $viaFfprobe = $this->probeViaFfprobe($path);
        if ($viaFfprobe !== null) {
            return $viaFfprobe;
        }

        return $this->fallbackMetadataFromFilename($path);
    }

    /**
     * Reads tags natively with getID3.
     *
     * @param string $path Absolute filesystem path
     * @return array<string, mixed>|null Mapped metadata, or null when getID3 read
     *                                   nothing usable (so a fallback should run).
     */
    protected function probeViaGetId3(string $path): ?array
    {
        try {
            $info = $this->getId3Reader()->analyze($path);
            if (!is_array($info)) {
                return null;
            }

            $comments = isset($info['comments']) && is_array($info['comments']) ? $info['comments'] : [];

            return $this->mapId3Comments($comments, $info);
        } catch (\Throwable $e) {
            $this->logger->debug('getID3 read failed, will try ffprobe', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Maps a getID3 `comments` block (its format-agnostic merged tag view) onto
     * the scanner's canonical metadata shape.
     *
     * @param array<string, mixed> $comments getID3 `$info['comments']`
     * @param array<string, mixed> $info     Full getID3 analyze() result (for playtime)
     * @return array<string, mixed>|null Canonical metadata, or null when no
     *                                   identifying tag (artist/album/title) is present.
     */
    protected function mapId3Comments(array $comments, array $info): ?array
    {
        $artist = $this->firstComment($comments, 'artist');
        $albumArtist = $this->firstComment($comments, 'band', 'album_artist', 'albumartist');
        $album = $this->firstComment($comments, 'album');
        $title = $this->firstComment($comments, 'title');

        // No identifying tag → let ffprobe try, then filename fallback.
        if (($artist ?? $albumArtist) === null && $album === null && ($title === null || $title === '')) {
            return null;
        }

        $duration = 0;
        if (isset($info['playtime_seconds']) && is_numeric($info['playtime_seconds'])) {
            $duration = (int)floor((float)$info['playtime_seconds']);
        }

        return [
            'artist' => $artist ?? $albumArtist,
            'album' => $album,
            'title' => $title,
            'track_number' => $this->parseLeadingInt($this->firstComment($comments, 'track_number', 'track')),
            'disc_number' => $this->parseLeadingInt($this->firstComment($comments, 'part_of_a_set', 'disc')) ?? 1,
            'duration_secs' => $duration,
            'year' => $this->parseYear($this->firstComment($comments, 'year', 'date', 'recording_time')),
            'genre' => $this->firstComment($comments, 'genre'),
        ];
    }

    /**
     * Returns the first non-empty string value among the given comment keys.
     *
     * @param array<string, mixed> $comments getID3 comments block (values are arrays)
     * @param string               ...$keys  Candidate keys, in priority order
     * @return string|null
     */
    private function firstComment(array $comments, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $comments[$key] ?? null;
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Parses a leading integer from a raw tag value, tolerating the "3/12"
     * (position/total) form used for track and disc numbers.
     *
     * @param string|null $raw Raw tag value.
     * @return int|null Parsed integer, or null when not numeric.
     */
    private function parseLeadingInt(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $part = str_contains($raw, '/') ? explode('/', $raw, 2)[0] : $raw;
        $part = trim($part);

        return is_numeric($part) ? (int)$part : null;
    }

    /**
     * Extracts a 4-digit year from a raw date tag.
     *
     * @param string|null $raw Raw tag value.
     * @return int|null Parsed year, or null when none is present.
     */
    private function parseYear(?string $raw): ?int
    {
        if ($raw !== null && preg_match('/(\d{4})/', $raw, $matches) === 1) {
            return (int)$matches[1];
        }

        return null;
    }

    /**
     * Reads metadata from an audio file using FFprobe (fallback path).
     *
     * @param string $path Absolute filesystem path
     * @return array<string, mixed>|null Metadata, or null when ffprobe read
     *                                   nothing usable (so filename fallback runs).
     */
    protected function probeViaFfprobe(string $path): ?array
    {
        try {
            $probeResult = $this->ffmpeg->probe($path);

            if ($probeResult === null) {
                return null;
            }

            // Extract format-level tags
            $format = $probeResult['format'] ?? [];
            $tags = $format['tags'] ?? [];

            if (!is_array($tags)) {
                return null;
            }

            $trackNumber = $this->parseLeadingInt(is_string($tags['track'] ?? null) ? $tags['track'] : null);
            $discNumber = $this->parseLeadingInt(is_string($tags['disc'] ?? null) ? $tags['disc'] : null) ?? 1;

            $durationSecs = 0;
            if (isset($format['duration']) && is_numeric($format['duration'])) {
                $durationSecs = (int)floor((float)$format['duration']);
            }

            $year = $this->parseYear(is_string($tags['date'] ?? null) ? $tags['date'] : null);

            $artist = is_string($tags['artist'] ?? null)
                ? $tags['artist']
                : (is_string($tags['album_artist'] ?? null) ? $tags['album_artist'] : null);

            return [
                'artist' => $artist,
                'album' => is_string($tags['album'] ?? null) ? $tags['album'] : null,
                'title' => is_string($tags['title'] ?? null) ? $tags['title'] : null,
                'track_number' => $trackNumber,
                'disc_number' => $discNumber,
                'duration_secs' => $durationSecs,
                'year' => $year,
                'genre' => is_string($tags['genre'] ?? null) ? $tags['genre'] : null,
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('FFprobe failed, falling back to filename parsing', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extracts basic metadata from filename when tag reading fails.
     *
     * @param string $path Absolute filesystem path
     * @return array<string, mixed> Basic metadata
     */
    private function fallbackMetadataFromFilename(string $path): array
    {
        $filename = pathinfo($path, PATHINFO_FILENAME);

        return [
            'artist' => null,
            'album' => null,
            'title' => $filename,
            'track_number' => null,
            'disc_number' => 1,
            'duration_secs' => 0,
            'year' => null,
            'genre' => null,
        ];
    }

    /**
     * Lazily constructs the native getID3 reader (disabling the expensive
     * md5/sha1 hashing we do not need for tag harvesting).
     *
     * `protected` so tests can inject a fake reader.
     */
    protected function getId3Reader(): \getID3
    {
        if ($this->id3Reader === null) {
            $reader = new \getID3();
            $reader->option_md5_data = false;
            $reader->option_md5_data_source = false;
            $reader->option_sha1_data = false;
            $reader->encoding = 'UTF-8';
            $this->id3Reader = $reader;
        }

        return $this->id3Reader;
    }

    /**
     * Determines if a file should be skipped during scanning.
     *
     * Two rules, in order:
     *  1. the hardcoded dotfile rule (hidden files) — deliberately not operator
     *     configurable, matching {@see \Phlix\Media\Library\MediaScanner};
     *  2. the effective `scanner.ignore_patterns` list — the SAME live admin
     *     setting the video scanner consults, via {@see ScanIgnorePatterns}.
     *
     * Non-audio artwork (folder.jpg, cover.png, …) no longer needs a bespoke
     * skip list here: {@see self::audioFileIterator()} already excludes anything
     * whose extension is not in {@see self::AUDIO_EXTENSIONS}.
     *
     * @param string $filename File name to check
     * @return bool True if file should be skipped
     */
    private function shouldSkipFile(string $filename): bool
    {
        // Skip hidden files
        if ($filename === '.' || $filename === '..') {
            return true;
        }

        if (str_starts_with($filename, '.')) {
            return true;
        }

        // Consult the effective, admin-configurable ignore-pattern list.
        return $this->ignorePatterns->matches($filename);
    }

    /**
     * Upserts an artist into the database with a corresponding media_item.
     *
     * @param string $name Artist name
     * @param array<string, array{id:int, media_item_id:string|null}> $cache Artist cache to avoid duplicate queries
     * @param string|null $libraryId Owning library UUID stamped onto a new media_item.
     * @param bool $mayAdopt Whether the one-per-scan gate found an adoptable
     *        orphan ({@see self::hasAdoptableMusicMediaItem()}). FALSE skips the
     *        unindexed adoption lookup; defaults to TRUE so an omission degrades to
     *        "correct but slower", never to "leaks an orphan".
     * @return array{id: int, media_item_id: string|null}|null Artist ID and media_item_id or null on failure
     */
    private function upsertArtist(
        string $name,
        array &$cache,
        ?string $libraryId = null,
        bool $mayAdopt = true
    ): ?array {
        // Check cache first (Early Exit)
        $cacheKey = strtolower($name);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        // Check if artist exists
        $existing = $this->db->query(
            "SELECT id, media_item_id FROM music_artists WHERE name = ?",
            [$name]
        );

        if (is_array($existing) && count($existing) > 0) {
            $firstRow = $existing[0];
            if (is_array($firstRow)) {
                $id = isset($firstRow['id']) && is_numeric($firstRow['id']) ? (int)$firstRow['id'] : 0;
                $mediaItemId = isset($firstRow['media_item_id']) && is_string($firstRow['media_item_id'])
                    && $firstRow['media_item_id'] !== '' ? $firstRow['media_item_id'] : null;

                return $this->cacheRemember(
                    $cache,
                    $cacheKey,
                    ['id' => $id, 'media_item_id' => $mediaItemId],
                    self::MAX_ARTIST_CACHE
                );
            }
        }

        // Generate sort name
        $sortName = $this->generateSortName($name);

        // Adopt an orphan from an interrupted scan before minting a new
        // media_item, or the orphan leaks forever and every rescan adds another —
        // see findAdoptableArtistMediaItemId() for the window and the measurement.
        // Gated on $mayAdopt: the lookup scans an unindexed `media_items.name`, so
        // on a library with no orphans at all it is pure cost (measured: 5.078 ms per
        // artist — 31 s to 98 s over a first scan of the 7k-entity prod music
        // library) and hasAdoptableMusicMediaItem() has already ruled it out with a
        // single 158 ms query.
        //
        // KNOWN GAP, pre-existing and OWNED BY S96 (not S95): createMediaItem()
        // swallows its own Throwable and returns '', so a transient failure here
        // inserts the music_artists row with media_item_id = NULL — and because the
        // natural-key branch above returns whatever is stored, no later scan ever
        // backfills it (verified: still NULL after two clean rescans). That artist
        // stays artwork-less and invisible to any media_items-driven path. Deliberately
        // left unchanged here; S95 does not alter this behaviour.
        $mediaItemId = ($mayAdopt ? $this->findAdoptableArtistMediaItemId($name, $libraryId) : null)
            ?? $this->createMediaItem('artist', $name, null, $libraryId);

        // Insert new artist
        $result = $this->db->query(
            "INSERT INTO music_artists (name, sort_name, media_item_id) VALUES (?, ?, ?)",
            [$name, $sortName, $mediaItemId !== '' ? $mediaItemId : null]
        );

        if ($result === false) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();

        $this->logger->debug('Upserted artist', ['id' => $id, 'name' => $name, 'media_item_id' => $mediaItemId]);

        return $this->cacheRemember(
            $cache,
            $cacheKey,
            ['id' => $id, 'media_item_id' => $mediaItemId !== '' ? $mediaItemId : null],
            self::MAX_ARTIST_CACHE
        );
    }

    /**
     * Upserts an album into the database with a corresponding media_item.
     *
     * @param int $artistId Artist ID
     * @param string|null $artistMediaItemId Artist's `media_items` id. NOT written
     *        to the album row (S97 owns the `parent_id` hierarchy); used only to
     *        keep orphan adoption from crossing artists — see
     *        {@see self::findAdoptableAlbumMediaItemId()}.
     * @param string $title Album title
     * @param int|null $year Release year
     * @param array<string, array{id:int, media_item_id:string|null}> $cache Album cache key by "artistId|title"
     * @param string|null $libraryId Owning library UUID stamped onto a new media_item.
     * @param bool $mayAdopt Whether the one-per-scan gate found an adoptable
     *        orphan ({@see self::hasAdoptableMusicMediaItem()}). FALSE skips the
     *        unindexed adoption lookup; defaults to TRUE so an omission degrades to
     *        "correct but slower", never to "leaks an orphan".
     * @return array{id: int, media_item_id: string|null}|null Album ID and media_item_id or null on failure
     */
    private function upsertAlbum(
        int $artistId,
        ?string $artistMediaItemId,
        string $title,
        ?int $year,
        array &$cache,
        ?string $libraryId = null,
        bool $mayAdopt = true
    ): ?array {
        // Check cache first (Early Exit)
        $cacheKey = $artistId . '|' . strtolower($title);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        // Check if album exists for this artist
        $existing = $this->db->query(
            "SELECT id, media_item_id FROM music_albums WHERE artist_id = ? AND title = ?",
            [$artistId, $title]
        );

        if (is_array($existing) && count($existing) > 0) {
            $firstRow = $existing[0];
            if (is_array($firstRow)) {
                $id = isset($firstRow['id']) && is_numeric($firstRow['id']) ? (int)$firstRow['id'] : 0;
                $mediaItemId = isset($firstRow['media_item_id']) && is_string($firstRow['media_item_id'])
                    && $firstRow['media_item_id'] !== '' ? $firstRow['media_item_id'] : null;

                // Refresh the year only. `total_tracks` is owned exclusively by
                // refreshAlbumTrackTotal(), which derives it from music_tracks
                // after every flush — the in-memory batch size is no longer a
                // valid answer now that one album can be flushed in several
                // batches.
                $this->db->query(
                    "UPDATE music_albums SET year = COALESCE(?, year) WHERE id = ?",
                    [$year, $id]
                );

                return $this->cacheRemember(
                    $cache,
                    $cacheKey,
                    ['id' => $id, 'media_item_id' => $mediaItemId],
                    self::MAX_ALBUM_CACHE
                );
            }
        }

        // Generate sort title
        $sortTitle = $this->generateSortName($title);

        // Adopt an orphan from an interrupted scan before minting a new media_item
        // (see findAdoptableAlbumMediaItemId()), gated on the one-per-scan orphan
        // probe exactly as in upsertArtist() — 4.03 ms per album otherwise (17.1 ms
        // as the reviewer measured it), paid once per album for the whole library.
        // The same S96-owned NULL media_item_id gap documented in upsertArtist()
        // applies here verbatim.
        $mediaItemId = ($mayAdopt
            ? $this->findAdoptableAlbumMediaItemId($title, $libraryId, $artistMediaItemId)
            : null)
            ?? $this->createMediaItem('album', $title, null, $libraryId);

        // Insert new album. total_tracks defaults to 0 and is set by
        // refreshAlbumTrackTotal() once this flush's tracks are persisted.
        $result = $this->db->query(
            "INSERT INTO music_albums (artist_id, media_item_id, title, sort_title, year)
             VALUES (?, ?, ?, ?, ?)",
            [$artistId, $mediaItemId !== '' ? $mediaItemId : null, $title, $sortTitle, $year]
        );

        if ($result === false) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();

        $this->logger->debug('Upserted album', ['id' => $id, 'title' => $title, 'artist_id' => $artistId]);

        return $this->cacheRemember(
            $cache,
            $cacheKey,
            ['id' => $id, 'media_item_id' => $mediaItemId !== '' ? $mediaItemId : null],
            self::MAX_ALBUM_CACHE
        );
    }

    /**
     * Upserts a track into the database with a corresponding media_item.
     *
     * Stable identity is the track's file path within its library (the
     * `media_items.path` + `library_id`). The existing row is looked up by that
     * identity BEFORE any id is minted, so a rescan reuses the existing
     * `media_items` + `music_tracks` pair and takes the updated/skipped branch
     * instead of inserting a fresh duplicate every pass. A {@see MediaItemAdded}
     * event is dispatched ONLY when a genuinely-new track is inserted — never on
     * an update or a no-op skip.
     *
     * @param int $albumId Album ID
     * @param string|null $albumMediaItemId Album's media_item_id for linking
     * @param int $artistId Artist ID (denormalized for queries)
     * @param SplFileInfo $file Audio file info
     * @param array<string, mixed> $metadata Tags already read during grouping (no re-probe)
     * @param string|null $libraryId Owning library UUID (stamped on a new media_item + event).
     * @return string 'added', 'updated', or 'skipped'
     */
    private function upsertTrack(
        int $albumId,
        ?string $albumMediaItemId,
        int $artistId,
        SplFileInfo $file,
        array $metadata,
        ?string $libraryId = null
    ): string {
        unset($albumMediaItemId);

        $path = $file->getPathname();

        $title = is_string($metadata['title'] ?? null) && $metadata['title'] !== ''
            ? $metadata['title']
            : $file->getBasename('.' . $file->getExtension());
        $trackNumber = is_numeric($metadata['track_number'] ?? null) ? (int)$metadata['track_number'] : 1;
        $discNumber = is_numeric($metadata['disc_number'] ?? null) ? (int)$metadata['disc_number'] : 1;
        $durationSecs = is_numeric($metadata['duration_secs'] ?? null) ? (int)$metadata['duration_secs'] : 0;

        // Idempotency: find an EXISTING media_items row for this file path within
        // the library BEFORE minting a new id. If found, reuse it and take the
        // update/skip branch — never a fresh insert (the old code minted an id
        // first and then queried music_tracks by that never-matching id, which
        // duplicated every track on every rescan and leaked media_items rows).
        $existingMediaItemId = $this->findExistingTrackMediaItemId($path, $libraryId);

        if ($existingMediaItemId !== null) {
            $existing = $this->db->query(
                "SELECT id, title, track_number, disc_number, duration_secs
                 FROM music_tracks WHERE media_item_id = ?",
                [$existingMediaItemId]
            );

            if (is_array($existing) && count($existing) > 0 && is_array($existing[0])) {
                $existingTrack = $existing[0];
                $existingId = isset($existingTrack['id']) && is_numeric($existingTrack['id']) ?
                    (int)$existingTrack['id'] : 0;

                // Check if anything changed
                $existingTitle = is_string($existingTrack['title'] ?? null) ? $existingTrack['title'] : '';
                $existingTrackNum = isset($existingTrack['track_number']) &&
                    is_numeric($existingTrack['track_number']) ? (int)$existingTrack['track_number'] : 1;
                $existingDiscNum = isset($existingTrack['disc_number']) && is_numeric($existingTrack['disc_number']) ?
                    (int)$existingTrack['disc_number'] : 1;
                $existingDuration = isset($existingTrack['duration_secs']) &&
                    is_numeric($existingTrack['duration_secs']) ? (int)$existingTrack['duration_secs'] : 0;

                if (
                    $existingTitle === $title
                    && $existingTrackNum === $trackNumber
                    && $existingDiscNum === $discNumber
                    && $existingDuration === $durationSecs
                ) {
                    return 'skipped';
                }

                // Update existing track (no new media_item, no event).
                $this->db->query(
                    "UPDATE music_tracks SET title = ?, track_number = ?, disc_number = ?, duration_secs = ?
                     WHERE id = ?",
                    [$title, $trackNumber, $discNumber, $durationSecs, $existingId]
                );

                return 'updated';
            }

            // Rare: the media_item exists but its music_tracks row is missing
            // (a partial prior scan). Reuse the existing media_item id for the
            // track insert — the media item was already "added", so no event.
            $result = $this->db->query(
                "INSERT INTO music_tracks
                 (media_item_id, album_id, artist_id, title, track_number, disc_number, duration_secs)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$existingMediaItemId, $albumId, $artistId, $title, $trackNumber, $discNumber, $durationSecs]
            );

            return $result === false ? 'skipped' : 'updated';
        }

        // Genuinely new track: mint the media_item, insert, and announce it.
        $mediaItemId = $this->createMediaItem('track', $title, $path, $libraryId);
        if ($mediaItemId === '') {
            return 'skipped';
        }

        // Insert new track
        $result = $this->db->query(
            "INSERT INTO music_tracks
             (media_item_id, album_id, artist_id, title, track_number, disc_number, duration_secs)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$mediaItemId, $albumId, $artistId, $title, $trackNumber, $discNumber, $durationSecs]
        );

        if ($result === false) {
            return 'skipped';
        }

        $this->logger->debug('Upserted track', ['album_id' => $albumId, 'title' => $title,
            'media_item_id' => $mediaItemId]);

        // F4: the only music-enrichment trigger after native providers were
        // removed — the musicbrainz plugin subscribes MediaItemAdded. Dispatched
        // only for a genuinely-new track insert.
        $this->dispatchMediaItemAdded($mediaItemId, $libraryId, $path, 'track');

        return 'added';
    }

    /**
     * Finds the `media_items.id` for a track already indexed at this file path
     * within the given library, or NULL when none exists.
     *
     * The scoping mirrors the `(library_id, path_hash)` unique index (migrations
     * 072/087): a track's identity is its path inside its owning library.
     *
     * @param string $path Absolute filesystem path of the audio file.
     * @param string|null $libraryId Owning library UUID (null-safe matched).
     * @return string|null Existing media_items UUID, or null when unseen.
     */
    private function findExistingTrackMediaItemId(string $path, ?string $libraryId): ?string
    {
        return $this->firstMediaItemId($this->db->query(
            "SELECT id FROM media_items WHERE type = 'track' AND path = ? AND library_id <=> ? LIMIT 1",
            [$path, $libraryId]
        ));
    }

    /**
     * Finds an ORPHANED artist `media_items` row this scanner already minted for
     * `$name` — one that no `music_artists` row points at — so it can be adopted
     * instead of leaked.
     *
     * WHY THIS EXISTS. {@see self::upsertArtist()} writes the `media_items` row one
     * autocommitted statement BEFORE the matching `music_artists` row. A worker
     * restart inside that one-statement window leaves a `media_items` row with no
     * sibling, and the upsert finds-or-creates on the NATURAL key (`name`), so
     * without this lookup the next scan mints a SECOND row and the first is never
     * reclaimed — measured: two clean rescans left 2 artist `media_items` for 1
     * `music_artists` row. Incremental flushing opens that window once per album
     * across a multi-hour scan instead of once per never-durable walk, so the
     * residue would accumulate. `media_items` counts BY TYPE are what the music
     * read path and the stats maps report, so the leak is user-visible.
     *
     * This deliberately mirrors {@see self::findExistingTrackMediaItemId()} plus
     * {@see self::upsertTrack()}'s reuse branch, which is why the track window is
     * already self-healing.
     *
     * MATCHING IS COLLATION-INSENSITIVE, AND THAT IS THE DECISION. `media_items.name`
     * is `utf8mb4_unicode_ci`, so `mi.name = ?` adopts across case and accent:
     * an orphan named `Björk` IS adopted for the tag `Bjork`, as are `ABBA`/`abba`.
     * That is deliberate — `music_artists`' `UNIQUE KEY uk_name (name)` (migration
     * 065) is over a column of the same collation, so the schema already treats both
     * spellings as ONE artist and the natural-key branch in
     * {@see self::upsertArtist()} would have found the row had it existed;
     * requiring a binary match here would leak the orphan instead. The one
     * consequence to know about: `media_items.name` then keeps the EARLIER spelling
     * while `music_artists.name` holds the later one, so the generic
     * `media_items`-driven surfaces (`/api/v1/media?type=artist`, the DLNA bridge)
     * can display a different variant from the music read path. Same for albums.
     *
     * RESIDUE THIS DOES NOT RECLAIM — the leak is closed for the window it names,
     * not universally: (a) `LIMIT 1` adopts exactly ONE orphan per natural key, so
     * if two orphans somehow share a name the second stays unreferenced forever —
     * every later scan short-circuits on the natural-key branch before reaching this
     * lookup (measured: `media_items[artist] = 2` against `music_artists = 1` after
     * two clean rescans). The scanner alone cannot produce that state — an
     * interruption re-adopts the SAME orphan rather than minting a second. (b) The
     * lookup is scoped `mi.library_id <=> ?` while `music_artists` has NO
     * `library_id`, so an orphan minted in library L1 is leaked permanently once ANY
     * other library creates the `music_artists` row for that name (measured: scan L2,
     * then rescan L1 → `media_items[artist] = 2` against `music_artists = 1`). That
     * needs two music libraries sharing an artist, which prod does not have.
     *
     * @param string $name Artist name, as stored in `media_items.name`.
     * @param string|null $libraryId Owning library UUID (null-safe matched).
     * @return string|null An adoptable media_items UUID, or null when none exists.
     */
    private function findAdoptableArtistMediaItemId(string $name, ?string $libraryId): ?string
    {
        return $this->firstMediaItemId($this->db->query(
            "SELECT mi.id
               FROM media_items mi
               LEFT JOIN music_artists ma ON ma.media_item_id = mi.id
              WHERE mi.type = 'artist' AND mi.name = ? AND mi.path = ''
                AND mi.library_id <=> ? AND ma.id IS NULL
              LIMIT 1",
            [$name, $libraryId]
        ));
    }

    /**
     * Finds an ORPHANED album `media_items` row this scanner already minted for
     * `$title` — one that no `music_albums` row points at — so it can be adopted
     * instead of leaked. The artist/`music_artists` counterpart is
     * {@see self::findAdoptableArtistMediaItemId()}, which documents the window, the
     * measurement, the collation rule and the residue this does not reclaim; all of
     * it applies here verbatim.
     *
     * ⚠ THE ARTIST CONSTRAINT IS LOAD-BEARING FOR S97, NOT DECORATION. Two artists
     * can legitimately share an album title (`Greatest Hits`). Today an album's
     * `media_items` row carries nothing artist-specific — `path = ''`,
     * `metadata_json = {sub_type, name}`, and **this scanner never writes
     * `parent_id`** (S97 owns that hierarchy) — so `title` alone picks a row that is
     * indistinguishable from a freshly minted one and the counts stay exact
     * (measured: two artists × `Greatest Hits` → 2 albums / 2 `media_items[album]`).
     * The moment S97 starts parenting these rows that stops being true: a
     * title-only predicate would hand artist B an album row parented to artist A,
     * and `ma.id IS NULL` cannot catch it because the row genuinely is unreferenced.
     * Hence `parent_id IS NULL OR parent_id = <this artist>`: it is exactly today's
     * behaviour while every orphan is unparented, and it fails SAFE (mint a fresh
     * row, no mis-parenting) as soon as S97 sets the column. **The constraint S97
     * must honour: an album `media_items` row may only be adopted by the artist it
     * is parented to.**
     *
     * @param string $title Album title, as stored in `media_items.name`.
     * @param string|null $libraryId Owning library UUID (null-safe matched).
     * @param string|null $artistMediaItemId This album's artist's `media_items` id;
     *        an orphan already parented to a DIFFERENT artist is not adoptable.
     *        NULL (the artist's own media_item failed — S96(e)) restricts adoption
     *        to unparented orphans.
     * @return string|null An adoptable media_items UUID, or null when none exists.
     */
    private function findAdoptableAlbumMediaItemId(
        string $title,
        ?string $libraryId,
        ?string $artistMediaItemId
    ): ?string {
        return $this->firstMediaItemId($this->db->query(
            "SELECT mi.id
               FROM media_items mi
               LEFT JOIN music_albums ma ON ma.media_item_id = mi.id
              WHERE mi.type = 'album' AND mi.name = ? AND mi.path = ''
                AND mi.library_id <=> ? AND ma.id IS NULL
                AND (mi.parent_id IS NULL OR mi.parent_id = ?)
              LIMIT 1",
            [$title, $libraryId, $artistMediaItemId]
        ));
    }

    /**
     * Answers, in ONE query per scan, whether this library holds any orphaned
     * artist/album `media_items` row at all — i.e. whether the per-entity adoption
     * lookups are worth issuing.
     *
     * WHY THIS GATE EXISTS. `media_items` has no b-tree index on `name` (migration
     * 001 gives it only `FULLTEXT idx_name`), so
     * {@see self::findAdoptableArtistMediaItemId()} and
     * {@see self::findAdoptableAlbumMediaItemId()} degrade to a scan of the whole
     * `type` partition. Measured on a prod-shaped population (2,153 `artist` +
     * 5,091 `album` + 29,245 `track` rows in one library, warm, MySQL 8.0.46,
     * durable defaults) — **5.078 ms per artist and 4.03 ms per album**, so a first
     * scan of that library spends `2,153 × 5.078 + 5,091 × 4.03 ≈ **31.4 s**` on
     * adoption alone; the S95 reviewer measured the same artist figure (5.2 ms) and
     * a slower album one (17.1 ms → ≈98 s) on a loaded box, so treat 31 s as the
     * floor, not the ceiling. Either way it grows as O(n²) in albums and is spent for
     * NOTHING whenever there are no orphans, which is the normal case.
     *
     * This probe replaces all of it with **one** statement per scan: **158.4 ms** on
     * the same population (the clean case is its worst case — it must prove the
     * absence of an orphan, so it visits every artist/album row), i.e. a 190×
     * reduction, and one `scanDirectory()` call per library PATH rather than per
     * entity. It is deliberately the simple form: the optimizer picks `idx_library`
     * over `idx_media_items_library_type` here, and rewriting it as a `UNION ALL` of
     * two single-`type` branches measured 42.8 ms — kept in reserve rather than
     * shipped, because 116 ms once per path is nothing against the 31 s it saves and
     * the readable predicate is the one people will have to maintain.
     *
     * ACCEPTED BEHAVIOUR. The answer is taken once, before the walk: an orphan
     * created *by this scan's own crash* is irrelevant (the scan is over) and an
     * orphan created by a CONCURRENT writer is not reclaimed until the next scan —
     * one scan cycle later, exactly like every other adoption. `library_id <=> NULL`
     * matches nothing (the column is NOT NULL), so the legacy no-library scan path
     * skips both lookups outright, which is what it effectively did anyway.
     *
     * @param string|null $libraryId Owning library UUID (null-safe matched).
     * @return bool TRUE when at least one adoptable artist/album row exists.
     */
    private function hasAdoptableMusicMediaItem(?string $libraryId): bool
    {
        // An 'artist' row can only ever be referenced from music_artists and an
        // 'album' row only from music_albums, so requiring BOTH sides to be NULL is
        // simply "unreferenced by either", in one pass over the type partition.
        return $this->firstMediaItemId($this->db->query(
            "SELECT mi.id
               FROM media_items mi
               LEFT JOIN music_artists ar ON ar.media_item_id = mi.id
               LEFT JOIN music_albums al ON al.media_item_id = mi.id
              WHERE mi.type IN ('artist', 'album') AND mi.path = ''
                AND mi.library_id <=> ? AND ar.id IS NULL AND al.id IS NULL
              LIMIT 1",
            [$libraryId]
        )) !== null;
    }

    /**
     * Extracts the `id` of the first returned row as a non-empty string.
     *
     * The `IS NULL` half of the adoption lookups above is NOT optional:
     * `music_artists.media_item_id` and `music_albums.media_item_id` are both
     * `NULL UNIQUE` (migration 065), so adopting a row another music row already
     * references would fail that INSERT on a duplicate key and lose the whole
     * album. `path = ''` keeps the lookups to rows THIS scanner minted — it stores
     * no path for artists and albums, unlike tracks.
     *
     * @param mixed $rows Whatever the DB layer returned for a `SELECT id …`.
     * @return string|null The id, or null when the query matched nothing.
     */
    private function firstMediaItemId(mixed $rows): ?string
    {
        if (is_array($rows) && count($rows) > 0 && is_array($rows[0])) {
            $id = $rows[0]['id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }

    /**
     * Publish {@see MediaItemAdded} for a genuinely-new track.
     *
     * No-op when no dispatcher is wired (legacy/manual construction) or the
     * library id is unknown — the enrichment contract requires a real library id.
     *
     * @param string $mediaItemId UUID of the newly-persisted track media_item.
     * @param string|null $libraryId Owning library UUID.
     * @param string $path Absolute filesystem path of the source file.
     * @param string $type Concrete media-item type ('track').
     * @return void
     */
    private function dispatchMediaItemAdded(
        string $mediaItemId,
        ?string $libraryId,
        string $path,
        string $type
    ): void {
        if ($this->eventDispatcher === null || $libraryId === null || $libraryId === '') {
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
     * Creates a media_item entry for a music entity.
     *
     * The `type` column is a strict ENUM whose audio members are `artist`,
     * `album`, and `track` — so the sub-type IS the media_items type. (A
     * `music_`-prefixed value is not a valid ENUM member and, under
     * STRICT_TRANS_TABLES, a hard "Data truncated" error.) The finer-grained
     * label is preserved in `metadata_json.sub_type`.
     *
     * The `media_items.id` is a CHAR(36) UUID with no DB-side default and
     * `library_id` is NOT NULL, so BOTH must be supplied on insert — a bare
     * INSERT that omits them cannot succeed against the real schema.
     *
     * @param string $subType Subtype: 'artist', 'album', or 'track' (also the media_items type)
     * @param string $name Display name
     * @param string|null $path File path (for tracks)
     * @param string|null $libraryId Owning library UUID (stamped into the NOT NULL library_id column)
     * @return string The media_item UUID ('' on failure)
     */
    private function createMediaItem(
        string $subType,
        string $name,
        ?string $path = null,
        ?string $libraryId = null
    ): string {
        $type = $subType;
        $metadata = [
            'sub_type' => $subType,
            'name' => $name,
        ];
        $id = Uuid::v4();

        try {
            $result = $this->db->query(
                "INSERT INTO media_items (id, library_id, type, name, path, metadata_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [$id, $libraryId, $type, $name, $path ?? '', json_encode($metadata)]
            );

            if ($result === false) {
                $this->logger->error('Failed to create media_item', ['type' => $type, 'name' => $name]);
                return '';
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create media_item', [
                'type' => $type,
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            return '';
        }

        return $id;
    }

    /**
     * Generates a sort-friendly name from a display name.
     *
     * Strips leading "The ", "A ", "An " for better sorting.
     *
     * @param string $name Display name
     * @return string Sort-friendly name
     */
    private function generateSortName(string $name): string
    {
        // Strip common leading articles
        $stripped = preg_replace('/^(the|a|an)\s+/i', '', $name);
        return trim((string)$stripped);
    }
}
