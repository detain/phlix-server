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
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Uuid;
use Phlix\Config\EffectiveConfig;
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
 * `(processed, total, currentPath, counts)` callback and ticks it once per audio
 * file during the (slow) tag-reading pass, so the async scan worker can stream a
 * real percentage onto the job row instead of leaving the UI frozen. Use
 * {@see self::countAudioFiles()} to pre-compute the denominator.
 *
 * ⚠ The 4th parameter is S96(b)'s live counter snapshot and this paragraph omitted
 * it until the 2026-08-02 AC audit, contradicting the S96 bullet 12 lines below
 * that calls it "the fourth argument". A **3-parameter sink is still valid** —
 * PHP ignores surplus arguments to a user-defined function, which is what keeps
 * the pre-S96 video/photo/book sinks working — so the omission was incomplete
 * rather than false, but a reader who wrote a new sink from this line alone would
 * have shipped one that reports `items_added: 0` forever.
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
 * **Observability (S96).** A music scan can now be diagnosed from the outside,
 * which it could not before — the reason four consecutive diagnoses of the empty
 * Music library were wrong:
 *
 *  - every log line goes to the shared MEDIA channel (`.logs/app.log`), never to
 *    a private `sys_get_temp_dir()` directory inside the unit's `PrivateTmp`
 *    ({@see self::createLogger()});
 *  - the progress sink carries the LIVE `added`/`updated`/`failed` counters as its
 *    fourth argument, so `library_scan_jobs.items_added` answers "is this scan
 *    writing anything?" with one field read instead of `music_artists.created_at`
 *    archaeology ({@see self::scanDirectory()});
 *  - a file the scan could not index increments {@see ScanResult::$failed} instead
 *    of vanishing ({@see self::flushAlbum()}).
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

    /**
     * Validated MPEG frames getID3 must see before it trusts an MP3 stream.
     *
     * getID3's own default is 50 and its own docblock says "Lower this number to
     * 5-20 for faster scanning". Measured effect of 50 → 10 on a 16.6 KB MP3:
     * 455,654 → 139,931 bytes read, 61 → 21 reads, 59 → 19 seeks, with an
     * IDENTICAL `playtime_seconds`. Full option rationale and the trade-off are in
     * {@see self::getId3Reader()}.
     */
    private const MP3_VALID_CHECK_FRAMES = 10;

    /**
     * @var LoggerInterface Logger instance.
     *
     * Typed on the PSR-3 interface, not on `StructuredLogger`, because the
     * whole point of S96(a) is that a CALLER-SUPPLIED logger is honoured. The old
     * `StructuredLogger` property type is what made {@see self::createLogger()}
     * silently discard anything else and build its own private one — see there.
     * Only PSR-3 methods are used in this class.
     */
    private LoggerInterface $logger;

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
     * @param LoggerInterface|null $logger Optional custom logger. NULL resolves to
     *        the shared MEDIA-channel logger (`.logs/app.log`), NOT to a private
     *        temp-directory log — see {@see self::createLogger()} for the S96(a)
     *        incident that rule comes from.
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
     * Resolves the logger this scanner writes to: the injected one, else the
     * shared MEDIA-channel logger every other media class uses.
     *
     * ⚠ S96(a) — THIS METHOD IS WHY THE EMPTY MUSIC LIBRARY WENT UNDIAGNOSED
     * ACROSS FOUR WRONG DIAGNOSES. It used to `mkdir()` a
     * `sys_get_temp_dir()/phlix_music_scanner_<uniqid>` directory PER CONSTRUCTION
     * and point a fresh `StructuredLogger` at a `music_scanner.log` inside it. Two
     * consequences, both fatal to diagnosis:
     *
     *  1. **Nothing reached `.logs/app.log`.** The `phlix-server` unit runs with
     *     `PrivateTmp`, so that directory lives in a per-unit mount namespace:
     *     unreadable without `nsenter`-ing the MainPID, and destroyed when the unit
     *     restarts. Every "Skipping album …" / "Skipping track …" / "Failed to
     *     create media_item" line the scanner ever emitted on production went there.
     *  2. **It leaked a directory per instantiation** — 66 on production, 6,346 on
     *     the dev box, one more for every process that resolves this class.
     *
     * The trigger was that the old signature accepted ONLY a `StructuredLogger` and
     * discarded anything else, while `MediaServicesProvider`'s registration passed
     * NO logger at all — so the temp-dir branch was not a fallback, it was the only
     * path. Both halves are fixed: the container now names the `logger.media`
     * constructor parameter explicitly, and this method resolves to
     * {@see LoggerFactory::get()} (the same MEDIA channel, which `config/logger.php`
     * routes to `.logs/app.log` at every level and `.logs/error.log` for errors)
     * instead of minting a private one.
     *
     * @param LoggerInterface|null $logger Caller-supplied logger, or null.
     * @return LoggerInterface The logger to use — never one backed by a temp dir.
     */
    private function createLogger(?LoggerInterface $logger): LoggerInterface
    {
        return $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Did an `INSERT` issued through {@see Connection::query()} write no row?
     *
     * **THE MEASURED CONTRACT (real MySQL 8.0.46, `PhlixMySQLConnection`, review r2
     * F1).** This method exists because the file used to contradict itself:
     * {@see self::flushAlbum()}'s docblock asserted "the DB layer throws on error (it
     * does not return `false`)" while **five** call sites in **four** methods branched on
     * `=== false` — {@see self::upsertArtist()}, {@see self::upsertAlbum()},
     * {@see self::upsertTrack()} (twice) and {@see self::createMediaItem()}, counted at
     * `master` `ffc41739` (`:1083`, `:1206`, `:1324`, `:1341`, `:1712`), all five now on
     * this helper. Earlier prose in this docblock and in the worklog said "three" and
     * "all four" (review r3 finding 6); the substance was right and only the count was
     * wrong, but this is the file's third doc-accuracy finding, so the number is now the
     * measured one. Both halves of the original contradiction were half-right, and the
     * truth is a three-outcome contract:
     *
     * | outcome of the statement | `query()` returns |
     * |---|---|
     * | INSERT wrote 1 row | `lastInsertId()` **as a string** |
     * | INSERT wrote 0 rows (e.g. `INSERT IGNORE` that ignored) | **`null`** |
     * | any real SQL error (dup key, bad ENUM, unknown column) | **THROWS** `PDOException` |
     *
     * So: the "throws on error" half is CORRECT — `Connection::execute()` re-throws
     * (`vendor/workerman/mysql/src/Connection.php:1777-1783`), which is why r2's S4
     * control scenario is charged correctly by the per-track `catch`. The `=== false`
     * half was WRONG twice over: this client never returns `false`, and the check
     * therefore missed the one falsy value it CAN return, `null`.
     *
     * ⚠ **DO NOT "SIMPLIFY" THIS TO `if (!$result)`.** `media_items` has a UUID primary
     * key and no `AUTO_INCREMENT`, so a SUCCESSFUL insert there returns the string
     * `'0'` — measured, not theorised — which is falsy in PHP. A truthiness test would
     * report every successful `media_items` write as a failure. `false` is kept in the
     * comparison for a different client (or a test double) that reports failure that
     * way; it costs nothing and it is what r2's S3 scenario models.
     *
     * ⚠ **ALL THREE OUTCOMES ARE PINNED, EACH ON ITS OWN (review r3 finding 1).** They
     * have to be asserted individually, because a suite that only ever produces `false`
     * leaves the `null` arm — the ONLY falsy value this client actually returns — dead in
     * test: r3 deleted `|| $result === null` and the whole scanner + library + command +
     * integration selection stayed byte-identically GREEN. The pins are
     * `MusicLibraryScannerTest::testTheInsertResultContractIsPinnedOnBothOfItsFalsyArms()`
     * (the value table, via reflection: `null` ⇒ true, `false` ⇒ true, `'0'` ⇒ FALSE) and
     * `…::testAnInsertThatReturnsNullIsChargedAsALossOnEveryPath()` (the production
     * consequence, driven through `MusicSchemaConnection::returnNullFor()`). The double
     * now returns the measured shapes — `'0'` for a `media_items` insert, a string id for
     * an `AUTO_INCREMENT` table — so the `'0'` warning above is falsifiable on a DB-less
     * box too, not only against real MySQL.
     *
     * ⚠ **THE THREE-OUTCOME TABLE ABOVE IS THE `INSERT` CONTRACT. IT IS NOT THE ONLY
     * CONTRACT (review r4).** `Connection::query()` picks its return by the statement's
     * leading keyword (`vendor/workerman/mysql/src/Connection.php:1854-1869`): `insert` →
     * `lastInsertId()`/`null`, `update`/`delete`/`replace` → `rowCount()` (an **int**, `0`
     * for "wrote nothing"), `select`/`show` → `fetchAll()`, anything else → `null`. Five of
     * this helper's six call sites are insert results; the sixth
     * ({@see self::backfillMusicMediaItemId()}, the `UPDATE … SET media_item_id`) is an
     * UPDATE, where the `is_int($affected) && $affected < 1` half of that site's guard is the
     * half production trips **for the statement as written today**.
     *
     * ⚠ **AND `null` IS NOT UNREACHABLE FOR AN UPDATE — THAT CLAIM (fix r4's) WAS TOO STRONG
     * (review r5).** The keyword is recognised only when the statement's first
     * *space*-delimited token `trim()`s down to it: `Connection::query()` splits with
     * `explode(" ", $query)` (`:1854`), then `strtolower(trim($rawStatement[0]))` (`:1856`).
     * Measured against real MySQL 8.0.46 at r5 — `"UPDATE\nmusic_artists SET …"` → **`null`**,
     * `"UPDATE\tmusic_artists SET …"` (tab, NO space) → **`null`**, a leading block comment
     * then `UPDATE …` → **`null`**, while the verbatim single-line statement → `int 0`/`int 1`.
     * ⚠ *"followed by a single space"* (r5's phrasing) is not the rule, and review r6 measured
     * the difference: `"UPDATE\t music_artists SET …"` — tab THEN space — splits to `"UPDATE\t"`,
     * which `:1856` `trim()`s back to `update`, so THAT layout is an `int`. So reformatting
     * that `UPDATE` into a heredoc would silently move the site onto the `null` arm. That is
     * exactly why the helper is needed there and must NOT be deleted as dead code — and
     * equally why it is not sufficient on its own. See the comment at that site; both halves
     * are required, and each is pinned separately. Assume nothing about a keyword — or a
     * whitespace layout — you have not measured.
     *
     * ⚠ **Six OTHER sites in `src/` consume an insert result with the same `=== false`
     * check this replaced, and they are NOT fixed here** — `StreamSessionService`,
     * `DbLoginRateLimitStore`, `DbOidcStateStore`, `DbOAuth2StateStore`,
     * `DbTraktOAuthStateStore`, `DbLastfmOAuthStateStore`. They are tracked as step
     * **S131**, which is also where this helper is to be promoted somewhere all eleven
     * sites can share it. None of them is a *falsy* test, so the `'0'` hazard does not
     * bite them; the defect there is only the `null` blindness.
     *
     * @param mixed $result Whatever `query()` returned for an INSERT.
     * @return bool True when the statement demonstrably wrote nothing.
     */
    private static function statementWroteNothing(mixed $result): bool
    {
        return $result === false || $result === null;
    }

    /**
     * Counts the audio files under a path that {@see self::scanDirectory()}
     * would process — using the SAME extension + skip filters — so a caller can
     * pre-compute the progress denominator without reading any tags.
     *
     * **S96(d) — YES, THE TREE IS WALKED THREE TIMES, AND THAT IS THE RIGHT TRADE.
     * MEASURED, DO NOT "OPTIMISE" IT AWAY.**
     *
     * ⚠ **This said TWICE until the S95/S96/S121 AC audit (2026-08-02), and it had been
     * wrong since S122.** S96(d) weighed exactly two walks — this one and the tag-reading
     * walk. S122(b) then added a THIRD, the read-ahead lookahead in
     * {@see self::scanDirectory()} (`$lookahead`), which is a second independent
     * traversal of the same tree used to warm the page cache. It is gated on
     * `MusicScanPrefetcher::poolSize() > 0`, i.e. on `config/scanner.php`'s
     * `music_read_concurrency`, whose **shipped default is 4** — so three walks is the
     * DEFAULT configuration, not an edge case. Only `music_read_concurrency = 1` gets
     * the two-walk shape this paragraph used to describe. The count is now pinned
     * against the source by
     * {@see \Phlix\Tests\Unit\Media\Music\MusicLibraryScannerTest::testTheWalkCountInThisDocblockMatchesTheSource()},
     * so a fourth walk cannot land while this text still says three.
     *
     * The trade is unchanged by the third walk, and S122 measured it on the same terms:
     * the lookahead reads no tags either, and `RecursiveIteratorIterator` is
     * deterministic over an unchanged tree, so the two cursors agree.
     *
     * This walk costs **0.0069 ms/file**
     * (median of 5 runs over a warm 10,000-file synthetic tree on PHP 8.3.6): ≈0.4 s
     * for the 61,135-file production music path, against a scan that took **4 h 09 m**
     * to its first durable write — about 0.003 % of the job. Removing the second walk
     * needs one of three things, and every one of them costs more than it saves:
     *
     *  1. materialise the file list so the walk happens once — which reintroduces the
     *     one-entry-per-file retention S95 removed (measured 1,463 B/entry ⇒ ≈89 MB
     *     for that path, unbounded in library size);
     *  2. drop the denominator and stream progress without a total — which is the
     *     percentage the admin scan UI renders, i.e. the thing that made a stalled
     *     scan visible at all;
     *  3. cache a previous run's count — stale by construction, and no help at all on
     *     a FIRST scan, which is the case that hurt.
     *
     * No tags are read here (no getID3, no ffprobe), which is what makes it cheap:
     * the expensive part of a music scan is tag probing, not directory traversal.
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
     * **Orphan adoption is gated by ONE query per scan, and the gate FAILS OPEN.**
     * The per-entity lookups that reclaim a previous scan's orphaned artist/album
     * `media_items` rows ({@see self::findAdoptableArtistMediaItemId()}) hit an
     * unindexed `media_items.name`, so they are only issued when
     * {@see self::hasAdoptableMusicMediaItem()} has established that this library
     * has at least one adoptable row at all — see that method for the measurement.
     * Because that answer is taken BEFORE the walk, `$mayAdopt` is threaded by
     * REFERENCE all the way down to {@see self::upsertArtist()} /
     * {@see self::upsertAlbum()}, which set it back to TRUE the moment they leave a
     * minted `media_items` row unreferenced — otherwise a caught mid-walk write
     * failure would leak that row permanently the next time the same artist or
     * album title recurs in the SAME scan.
     *
     * **The progress sink also carries the LIVE counters (S96(b)).** Its fourth
     * argument is `array{added:int, updated:int, failed:int}` — a snapshot of this
     * scan's `ScanResult` at that tick — which is what lets
     * {@see \Phlix\Media\Library\LibraryScanWorker} keep
     * `library_scan_jobs.items_added` truthful WHILE the walk runs instead of
     * leaving it at 0 for four hours. It is a fourth argument rather than a second
     * callback precisely so it costs no extra DB write: the worker folds it into the
     * progress UPDATE it was already throttling. PHP ignores surplus arguments to a
     * user-defined function, so a 3-parameter sink (every pre-S96 caller, and the
     * legacy `POST /api/v1/music/scan` path) keeps working untouched.
     *
     * @param string        $path       Root path to scan
     * @param callable|null $onProgress Optional
     *                                  `(int $processed, int $total, string $currentPath, array $counts): void`
     *                                  sink, ticked once per audio file during the tag-reading pass.
     *                                  Still exactly one tick per file, so the scan
     *                                  worker's write throttle is unaffected.
     *                                  `$counts` is {@see ScanResult::progressCounts()}
     *                                  taken at that tick.
     * @param string|null   $libraryId  Owning library UUID. Stamped onto every
     *                                  `media_items` row this scan creates and
     *                                  carried on the {@see MediaItemAdded} event.
     *                                  NULL only for the legacy manual-path scan
     *                                  endpoint (no library context).
     * @param bool $readEveryFile S145 — THE FULL-READ MODE. When TRUE
     *        {@see self::canSkip()} refuses every skip, so every audio file is opened and
     *        reaches {@see self::upsertTrack()}. That is the whole mechanism, and it
     *        exists because an `upsertTrack()`-only fix is COSMETIC: the skip at the top
     *        of the walk `continue`s before `probeMetadata()` and before the file is
     *        buffered for {@see self::flushAlbum()}, so an already-stamped, unchanged file
     *        never reaches the repair. Measured on production: 29,134 of 61,111 tracks
     *        (47.7 %, rising) already carry a stamp and would be skipped by an ordinary
     *        rescan.
     *
     *        ⚠ **S148: the mode is a `canSkip()` gate, NOT an unloaded index.** S145
     *        implemented it by skipping {@see MusicScanSkipIndex::load()}, which forced
     *        the read but also made {@see MusicScanSkipIndex::isStampCurrent()}
     *        permanently false — so the healing pass ALSO issued one no-op `JSON_SET`
     *        UPDATE per file it read. The index is now loaded in this mode and consulted
     *        for stamping only. Reading every file and rewriting every row are two
     *        different things and only the first one is the point.
     *
     *        ⚠ **This is NOT a filesystem-stat backfill**, which is explicitly rejected
     *        in {@see self::upsertTrack()} and is the S122 review-r1-B1 data-loss shape.
     *        Every file this mode stamps is a file it just READ, and the stamp is still
     *        the identity taken BEFORE the read.
     *
     *        ⚠ A PARAMETER, never a setter. This scanner is `@autowire`d from the
     *        container and can outlive one scan inside a Workerman worker, so a mode
     *        held on `$this` would leak from a healing rescan into every later scan.
     *
     *        The cost is real and deliberate: the last completed full read of the
     *        production music library took **9 h 55 m** against minutes for an ordinary
     *        rescan. (An earlier "~3.5 h" here was an estimate presented as a
     *        measurement. S151 removed what that run's dominant cost turned out to be —
     *        the per-file existence lookup in
     *        {@see self::findExistingTrackMediaItemId()} — so the figure should fall
     *        sharply, but the post-S151 wall clock is UNMEASURED.) It is reached
     *        from the existing `rescan` job type only
     *        ({@see \Phlix\Media\Library\LibraryManager::rescanLibrary()}).
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
    public function scanDirectory(
        string $path,
        ?callable $onProgress = null,
        ?string $libraryId = null,
        bool $readEveryFile = false
    ): ScanResult {
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
        // NOT a one-way decision: $mayAdopt is passed BY REFERENCE from here down
        // through flushAlbum() into upsertArtist()/upsertAlbum(), which flip it back
        // to true as soon as one of them leaves a freshly minted media_items row
        // unreferenced. It only ever moves false -> true (an orphan that exists stays
        // adoptable until something adopts it), so the worst case is the pre-gate
        // cost, never a leak.
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

        // ── S122(a): the second one-query-per-scan gate. ────────────────────────
        //
        // The unchanged-file fast path skips a file BEFORE probeMetadata(), which
        // means flushAlbum() never runs for that file — and flushAlbum() is where
        // S96(e)'s NULL-media_item_id backfill and the orphan adoption live. On a
        // library with something to heal, skipping everything would therefore make
        // the heal never happen: exactly S96's acceptance criterion ("a music_* row
        // whose media_item_id is NULL is backfilled on the NEXT scan rather than
        // staying NULL forever") silently regressed.
        //
        // So the fast path is enabled ONLY on a library with nothing to heal:
        // no adoptable orphan ($mayAdopt === false) AND no NULL media_item_id. Both
        // are answered by one query each, before the walk, in the same fail-open
        // style as the gate above — and $mayAdopt is re-read PER FILE below, so a
        // mid-walk write failure that leaves a fresh orphan switches the fast path
        // off for the remainder of the scan rather than banking a stale "healthy".
        //
        // That is also why the steady state is fast and the exceptional state is
        // correct: an unchanged, healthy library is precisely the 6.1-hour case.
        try {
            $needsHealing = $this->hasUnhealedMusicMediaItem();
        } catch (\Throwable $healGateError) {
            // FAIL SAFE = do NOT skip. A skip we cannot justify is a change we may
            // silently miss; a probe we did not need is only slow.
            $this->logger->warning('Music heal gate failed; the unchanged-file fast path is disabled', [
                'path' => $path,
                'error' => $healGateError->getMessage(),
            ]);
            $needsHealing = true;
        }

        // ── S145: the third gate, and the only one an OPERATOR controls. ────────
        //
        // The full-read mechanism is {@see self::canSkip()} answering FALSE for the
        // whole scan, so every file is probed, every file reaches upsertTrack(), and
        // every file is still legitimately stamped — the stamp is taken before the read,
        // from the file this pass actually opened.
        //
        // ⚠ **S148 MOVED THE GATE OFF `load()` AND ONTO `canSkip()`, AND THAT MOVE IS
        // THE WHOLE STEP.** S145 implemented the mode by NOT LOADING the index. With the
        // index unloaded {@see MusicScanSkipIndex::isStampCurrent()} can never suppress
        // anything, so `stampFileIdentity()` issued a `JSON_SET` UPDATE for EVERY file
        // read — 61,135 redundant row rewrites per healing pass on the production
        // library, none of which changed a single byte. Loading the index instead costs
        // one SELECT and the measured 10.90 MiB it retains, and buys back every one of
        // those UPDATEs. It cannot weaken the reach guarantee, because the index is now
        // consulted ONLY for the stamping decision: `canSkip()` is hard-false for the
        // entire scan, so no loaded entry can suppress a READ. (`isUnchanged()` is still
        // consulted by the read-ahead below — through the same `canSkip()` — which is
        // why the two cannot disagree about which files get probed.)
        //
        // The load is deliberately UNCONDITIONAL in this mode rather than inheriting the
        // heal/adopt gate below it. That gate exists because a loaded index plus a
        // mistaken `canSkip()` is how S96(e)'s heal stops happening; here `canSkip()`
        // cannot be mistaken, so the objection does not apply — and a healing rescan is
        // precisely the scan on which an unhealed row is most likely to be present, i.e.
        // exactly the run that would otherwise pay all 61,135 UPDATEs.
        //
        // ⚠ The bypass is EXPLICIT on purpose. An automatic gate was considered and
        // rejected: the only DB-visible fingerprint of the defect (an album owning zero
        // tracks) is NOT self-clearing, because a healed track leaves its old album as a
        // fresh shell. A gate keyed on it would latch the fast path off permanently and
        // re-create the 6.1-hour scan S122 exists to prevent. An operator asking for a
        // full re-read is a decision, not an inference.
        $skipIndex = new MusicScanSkipIndex($this->db, $this->logger);
        if ($readEveryFile || (!$mayAdopt && !$needsHealing)) {
            $skipIndex->load($libraryId);
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
         * `mtime`/`size` are the S122(a) identity STAT'ED BEFORE THE TAG READ and
         * carried here so the write can stamp it (review r1 B1 — see the skip below).
         *
         * They are two FLAT INTS rather than a nested `['stamp' => [m, s]]`, and that
         * is a measured choice, not a style one. An isolated 7,968-entry buffer (the
         * worst case `MAX_OPEN_ALBUMS × MAX_TRACKS_PER_FLUSH` allows), PHP 8.3.6:
         *
         * | entry shape | B/entry | total |
         * |---|---|---|
         * | `['file' =>, 'meta' =>]` (pre-B1) | 1,520.1 | 12,112,536 |
         * | **+ `'mtime' =>`, `'size' =>` ints (this)** | **1,513.0** | **12,055,192** |
         * | + `'stamp' => [m, s]` array | 1,729.0 | 13,776,280 |
         *
         * Two int-valued keys fit in the 8 buckets PHP has ALREADY allocated for this
         * array's minimum hash table, so they cost nothing measurable (−7 B/entry, i.e.
         * allocator noise). A nested 2-element array is a second `zend_array` per entry:
         * **+208.9 B/entry = +1,721,088 B = +1.64 MiB**, which would push the peak past
         * the 13,600,000 B ceiling
         * {@see \Phlix\Tests\Unit\Media\Music\MusicLibraryScannerTest::testMemoryStaysBoundedAcrossALargeTree()}
         * asserts and break S95's documented 1,463 B/entry bound.
         *
         * @var array<string, array{artist:string, album:string, year:?int,
         *     files:list<array{file:SplFileInfo, meta:array<string, mixed>,
         *     mtime:int|null, size:int|null}>}> $open
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

        /**
         * Audio files discarded by {@see self::flushAlbum()}'s "unknown artist"
         * rule, reported in the completion summary below (S96(f)).
         *
         * Deliberately NOT folded into {@see ScanResult::$failed}: nothing failed,
         * a documented scan POLICY dropped those files, and a rescan drops them
         * again (the fix for THAT is its own step). Conflating the two would make
         * `items_failed` alarm on an untagged library. Counted here rather than
         * logged per album so an untagged library costs one line, not thousands.
         *
         * @var int $skippedNoArtist
         */
        $skippedNoArtist = 0;

        /**
         * Files the S122(a) fast path recognised as unchanged and did not open.
         *
         * The headline number of this step: on the production library 54,585 of
         * 61,135 files were unchanged and were re-read anyway, at 568 KB and ~60
         * scattered reads each. Reported in the completion summary so "did the fast
         * path actually engage?" is answerable from `.logs/app.log` without a
         * profiler — and so a 0 here on a rescan is visible as the anomaly it is.
         *
         * Deliberately NOT folded into {@see ScanResult::$failed} or `$updated`:
         * nothing failed and nothing changed. It is the same accounting choice
         * S96(f) made for `$skippedNoArtist`, for the same reason.
         *
         * @var int $skippedUnchanged
         */
        $skippedUnchanged = 0;

        /**
         * Tracks this scan moved to a different album or artist (S145).
         *
         * The operator's evidence that a healing rescan did something. Every one of
         * these is ALSO counted in {@see ScanResult::$updated} — this number says how
         * much of that total was parentage repair rather than tag edits, which matters
         * because the first `readEveryFile` scan of the production library is expected
         * to report `updated` in the thousands and that must not read as an alarm.
         *
         * Deliberately ONE summary number and NOT a per-track log line: per-track
         * logging in this walk is 61k lines per scan and is banned for that reason.
         *
         * @var int $reparented
         */
        $reparented = 0;

        // ── S122(b): raise reads-in-flight against the mount from 1 to <= 4. ────
        //
        // A pool of reader processes runs LOOKAHEAD files ahead of this walk and
        // warms the page cache for files it is about to probe. Capped at 4 because
        // 4 measured 1.73x and 8 measured 0.59x — see MusicScanPrefetcher, which
        // carries the table and enforces the cap.
        //
        // $lookahead is a SECOND, independent walk of the same tree. That is cheaper
        // than it sounds and much simpler than buffering: S96(d) measured the walk at
        // 0.0069 ms/file (~0.4 s for 61,135 files, ~0.003 % of the job), it reads no
        // tags, and RecursiveIteratorIterator is deterministic over an unchanged
        // tree, so the two cursors agree. If the tree changes mid-scan they diverge
        // and some prefetches are wasted — which costs nothing correctness-wise,
        // because the pool cannot influence what is indexed.
        //
        // ⚠ NOT CREATED AT ALL WHEN THE POOL IS OFF (review r1 non-blocking 1). With
        // `music_read_concurrency = 1` the pool has no children, so every submit() is a
        // no-op — but the second walk still ran, issuing a readdir/getattr per entry.
        // That knob exists precisely for a `direct_io` mount, i.e. the case where extra
        // filesystem round trips are most expensive, and `config/scanner.php` promised
        // it was "byte-for-byte the pre-S122 scanner". It is now: no pool, no walk.
        $prefetcher = new MusicScanPrefetcher($this->logger, $this->readConcurrency());
        $prefetcher->open();
        $lookahead = $prefetcher->poolSize() > 0 ? $this->audioFileIterator($path) : null;
        $lookaheadPos = 0;

        try {
            foreach ($this->audioFileIterator($path) as $file) {
                $processed++;
                $result->scanned++;
                if ($onProgress !== null) {
                    // The 4th argument is the LIVE counter snapshot (S96(b)). Surplus
                    // arguments to a user-defined function are ignored by PHP, so a
                    // pre-S96 3-parameter sink is unaffected.
                    $onProgress($processed, $total, $file->getPathname(), $result->progressCounts());
                }

                // Keep the reader pool supplied, skipping files it would waste a read on.
                // `$lookahead === null` is the pool-disabled case: no second walk at all.
                while (
                    $lookahead !== null
                    && $lookaheadPos < $processed + MusicScanPrefetcher::LOOKAHEAD
                    && $lookahead->valid()
                ) {
                    $ahead = $lookahead->current();
                    $lookahead->next();
                    $lookaheadPos++;
                    if (!($ahead instanceof SplFileInfo)) {
                        continue;
                    }
                    if (!$this->canSkip($mayAdopt, $readEveryFile) || !$skipIndex->isUnchanged($ahead)) {
                        $prefetcher->submit($ahead->getPathname());
                    }
                }

                // ── S122(a): THE SKIP. Note where it is — BEFORE probeMetadata(), which
                // is the 568 KB / ~60-scattered-read / 114.9 ms-of-ffprobe call this whole
                // step exists to not make. Anything that moves this below the probe
                // silently deletes the entire benefit while leaving every test green.
                //
                // $mayAdopt is re-read here, not captured before the loop, so an orphan
                // created by a caught mid-walk write failure disables the fast path for
                // the rest of the scan (see the gate above).
                if ($this->canSkip($mayAdopt, $readEveryFile) && $skipIndex->isUnchanged($file)) {
                    $skippedUnchanged++;
                    continue;
                }

                // ── S122(a) / review r1 B1: THE STAMP IS TAKEN HERE, BEFORE THE READ,
                // AND CARRIED TO THE WRITE. Do not move it, and do not re-stat the file
                // at write time.
                //
                // `SplFileInfo` does not memoise its stat (measured: a second
                // getMTime() on the SAME instance returns the NEW value once the file
                // has changed), so a stat taken in flushAlbum() -> upsertTrack() is a
                // genuinely LATER observation of the file than the tags buffered below.
                // The window between the two is at least MAX_OPEN_ALBUMS albums (≈400
                // files) and is UNBOUNDED for an album whose tracks are spread across
                // the tree. An ordinary tag write inside that window was therefore
                // stamped with its POST-edit identity against PRE-edit tags, and every
                // later scan skipped the file forever — reproduced end to end, and the
                // reason this line exists.
                //
                // Stamping the PRE-read identity is safe in the only direction that
                // matters: an edit racing the read leaves the stamp OLDER than the
                // content, so the next scan re-reads. A redundant read, never a missed
                // change.
                $stamp = MusicScanSkipIndex::stampValues($file);

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

                $open[$key]['files'][] = [
                    'file' => $file,
                    'meta' => $metadata,
                    'mtime' => $stamp === null ? null : $stamp[0],
                    'size' => $stamp === null ? null : $stamp[1],
                ];

                // Touch: re-inserting moves the MOST-recently-used key to the END of
                // $recency, so array_key_first() below yields the least-recently-used.
                unset($recency[$key]);
                $recency[$key] = true;

                // Bound 1 — a single album may not buffer without limit.
                if (count($open[$key]['files']) >= self::MAX_TRACKS_PER_FLUSH) {
                    $this->flushAlbum(
                        $open[$key],
                        $artistCache,
                        $albumCache,
                        $libraryId,
                        $result,
                        $mayAdopt,
                        $skippedNoArtist,
                        $skipIndex,
                        $reparented
                    );
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
                    $this->flushAlbum(
                        $open[$oldest],
                        $artistCache,
                        $albumCache,
                        $libraryId,
                        $result,
                        $mayAdopt,
                        $skippedNoArtist,
                        $skipIndex,
                        $reparented
                    );
                    unset($open[$oldest], $recency[$oldest]);
                }
            }

            // Terminal flush: whatever the walk left open.
            foreach (array_keys($open) as $key) {
                $this->flushAlbum(
                    $open[$key],
                    $artistCache,
                    $albumCache,
                    $libraryId,
                    $result,
                    $mayAdopt,
                    $skippedNoArtist,
                    $skipIndex,
                    $reparented
                );
                unset($open[$key], $recency[$key]);
            }
        } finally {
            // The pool must not outlive the scan, whatever ends it. A reader also
            // exits on stdin EOF, so even a SIGKILLed worker leaves nothing behind.
            $prefetchStats = $prefetcher->stats();
            $prefetcher->close();
        }

        $result->durationMs = (int)((hrtime(true) - $startTime) / 1_000_000.0);

        // S96(f): `failed` and `skipped_no_artist` are ALWAYS in the summary, even
        // at 0, so "this scan lost nothing" is a positive statement in the log
        // rather than the absence of one. A partial scan used to be indistinguishable
        // from a clean one from out here.
        // S122: `skipped_unchanged` is ALWAYS present for the same reason — it is how
        // an operator confirms from one log line that the fast path engaged, and a 0
        // on a rescan of a settled library is the anomaly worth noticing.
        $summary = [
            'path' => $path,
            'scanned' => $result->scanned,
            'added' => $result->added,
            'updated' => $result->updated,
            'failed' => $result->failed,
            'skipped_no_artist' => $skippedNoArtist,
            'skipped_unchanged' => $skippedUnchanged,
            // S145: ALWAYS present, for the same reason the two above are. `reparented`
            // is how an operator sees that a healing rescan repaired mis-filed tracks
            // (and why `updated` spiked), and `read_every_file` is how they confirm the
            // scan they asked for was actually the full-read one — a `false` here beside
            // a `reparented` of 0 says the healing scan never ran, which is precisely
            // the failure the skip index would otherwise hide.
            'reparented' => $reparented,
            'read_every_file' => $readEveryFile,
            'skip_index_entries' => $skipIndex->count(),
            'readers_in_flight' => $prefetchStats['readers_in_flight'],
            'prefetched' => $prefetchStats['submitted'],
            // Review r1 non-blocking 2: `dropped` was documented as being FOR this
            // summary and then never emitted anywhere. It is the one number that says
            // the pool was saturated — a large value means the walk was outrunning the
            // readers, i.e. the read-ahead is not buying what it is meant to buy — so
            // leaving it uncollected made the whole counter pointless.
            'prefetch_dropped' => $prefetchStats['dropped'],
            'duration_ms' => $result->durationMs,
        ];

        // THREE levels, keyed on whether files were LOST or merely skipped by policy
        // (review r1 MED-2 — this line used to be `warning` for both cases, so the
        // one summary an operator actually reads never reached `.logs/error.log`,
        // which `config/logger.php` gates at `error`):
        //
        //   failed > 0            → ERROR   the scan lost files. Same level as the
        //                                   per-album/per-track loss lines, so the
        //                                   summary and its causes land in the same
        //                                   clean file.
        //   skipped_no_artist > 0 → WARNING a documented scan POLICY discarded album
        //                                   groups with no artist tag. Not an error:
        //                                   nothing malfunctioned, a rescan drops the
        //                                   same files again, and routing an untagged
        //                                   library into error.log would train the
        //                                   operator to ignore it.
        //   otherwise             → INFO    "this scan lost nothing" stated positively.
        if ($result->failed > 0) {
            $this->logger->error('Music directory scan complete with skipped files', $summary);
        } elseif ($skippedNoArtist > 0) {
            $this->logger->warning('Music directory scan complete with skipped files', $summary);
        } else {
            $this->logger->info('Music directory scan complete', $summary);
        }

        return $result;
    }

    /**
     * May this scan take the S122(a) unchanged-file fast path right now?
     *
     * ONE expression, in ONE place, consulted by both the skip itself and the
     * read-ahead lookahead — so the two can never disagree about which files will
     * be probed. Splitting it would let the pool warm files the walk skips (waste)
     * or, worse, let the skip fire where the lookahead assumed a probe.
     *
     * The `$needsHealing` half is folded in for the ORDINARY scan: outside the
     * full-read mode the index is only ever LOADED when the heal gate said the library
     * is clean ({@see self::scanDirectory()}), so an unhealed library reaches here with
     * an empty index and {@see MusicScanSkipIndex::isUnchanged()} answers FALSE for
     * every file. What remains is the half that can change DURING the walk.
     *
     * @param bool $mayAdopt Live orphan-adoption flag, which
     *        {@see self::upsertArtist()} / {@see self::upsertAlbum()} can flip to
     *        TRUE mid-walk after a caught write failure. Once an orphan exists, the
     *        scan must go back to flushing albums so it can be adopted — so the fast
     *        path switches OFF for the remainder rather than banking a stale
     *        "healthy" answer taken before the walk.
     * @param bool $readEveryFile S148 — THE FULL-READ MODE'S ONLY GATE. S145 implemented
     *        that mode by leaving the index unloaded, which forced the read but ALSO
     *        made the stamp-suppression in {@see self::stampFileIdentity()} dead, so a
     *        healing pass rewrote every row it read (61,135 on production, all no-ops).
     *        The mode is now expressed HERE instead: the index is loaded and consulted
     *        for STAMPING, while this method refuses every skip.
     *
     *        ⚠ **THIS IS THE REACH GUARANTEE AND IT IS NOT NEGOTIABLE.** S145 exists
     *        because a retagged track filed under the wrong album/artist can only be
     *        repaired by opening the file: the skip `continue`s BEFORE
     *        `probeMetadata()`, so a skipped file never reaches `upsertTrack()` at all.
     *        Anything that lets a `rescan` skip a read re-breaks S145 —
     *        {@see \Phlix\Tests\Unit\Media\Music\MusicScanReparentTest::testTheFullReadModeHealsAMisParentedTrackWhoseFileNeverChanged()}
     *        and the probe counts in
     *        {@see \Phlix\Tests\Integration\Media\MusicRetagReparentIntegrationTest}
     *        are the executable form of that sentence.
     * @return bool
     */
    private function canSkip(bool $mayAdopt, bool $readEveryFile): bool
    {
        return !$mayAdopt && !$readEveryFile;
    }

    /**
     * Reads-in-flight target for the read-ahead pool, from `config/scanner.php`,
     * clamped to the measured-safe ceiling.
     *
     * `protected` so a test can pin the pool at 1 without touching config — and so
     * a deployment can never exceed {@see MusicScanPrefetcher::MAX_READERS},
     * because the clamp lives in that class and not in the config file.
     *
     * `config/scanner.php` is deliberately NOT composed into `config/server.php`, so
     * it must be read through {@see EffectiveConfig::file()} — a plain
     * `$appConfig['scanner']` lookup resolves to nothing and an override would never
     * arrive (see that file's own header).
     *
     * @return int A value in `[1, MusicScanPrefetcher::MAX_READERS]`.
     */
    protected function readConcurrency(): int
    {
        try {
            return MusicScanPrefetcher::configuredReaders(EffectiveConfig::file('scanner'));
        } catch (\Throwable) {
            // Config resolution must never be able to fail a scan. The default IS
            // the measured optimum, so degrading to it is not a penalty.
            return MusicScanPrefetcher::DEFAULT_READERS;
        }
    }

    /**
     * Does any `music_artists`/`music_albums` row still carry
     * `media_item_id IS NULL` — i.e. does this database have an S96(e) heal
     * outstanding?
     *
     * ONE query per scan, and it exists purely to keep S122(a) from regressing
     * S96(e). The heal runs inside {@see self::upsertArtist()} /
     * {@see self::upsertAlbum()}, which only run when an album is FLUSHED, which only
     * happens for files the walk actually probes. A library where every file is
     * skipped therefore never heals — and S96's acceptance criterion is precisely
     * that such a row "is backfilled on the next scan rather than staying NULL
     * forever". So: while anything is unhealed, no file is skipped, every album is
     * flushed, and the heal happens exactly as S96 built it. Once the heal has
     * landed, this returns FALSE forever after and the fast path is available again.
     *
     * ⚠ **DELIBERATELY NOT SCOPED TO ONE LIBRARY.** `music_artists` and
     * `music_albums` have no `library_id` column at all (migration 065), so there is
     * nothing to scope BY. The consequence is stated rather than hidden: one unhealed
     * row anywhere disables the fast path for every music library on the box. That
     * errs towards "slow but correct", it is self-limiting (the same scan that is
     * slowed is the one that heals the row), and prod has a single music library.
     *
     * Both columns are `NULL UNIQUE` (migration 065), so `media_item_id IS NULL` is
     * an index lookup rather than a table scan; NULLs sort to the front of a b-tree,
     * so the affirmative answer is found immediately and the negative answer visits
     * only the NULL-prefix (empty) region.
     *
     * @return bool TRUE when at least one music row still needs its `media_item_id`.
     */
    private function hasUnhealedMusicMediaItem(): bool
    {
        $rows = $this->db->query(
            "SELECT 1 AS unhealed FROM music_artists WHERE media_item_id IS NULL"
            . " UNION ALL"
            . " SELECT 1 AS unhealed FROM music_albums WHERE media_item_id IS NULL"
            . " LIMIT 1",
            []
        );

        return is_array($rows) && count($rows) > 0;
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
     * walk. A real SQL error THROWS (measured — see
     * {@see self::statementWroteNothing()} for the full three-outcome contract and for
     * why this docblock's old flat claim "it does not return `false`" was only
     * half-right), so without the catch one unexpected row would kill the rest of the
     * library index — and with incremental flushing that would now also discard albums
     * the walk has not reached yet. The granularity is deliberately per TRACK, and
     * `total_tracks` is refreshed from a `finally`, so a bad file costs exactly
     * that file and never leaves the album's advertised count below the rows it
     * actually has.
     *
     * ⚠ **A THROW IS NOT THE ONLY WAY TO LOSE A FILE (review r2 F1, HIGH).** Two loss
     * shapes never reach the `catch` at all: `createMediaItem()` swallows its own
     * `\Throwable` and returns `''`, and an INSERT can report that it wrote nothing.
     * {@see self::upsertTrack()} now returns `'failed'` for both, distinct from the
     * BENIGN `'skipped'` it returns for an unchanged row, and the track loop charges
     * `'failed'` to {@see ScanResult::$failed} and logs the path at `error`. Before
     * that split, a scan that lost every file it read was indistinguishable from a
     * clean rescan of an unchanged library.
     *
     * @param array{artist:string, album:string, year:?int,
     *     files:list<array{file:SplFileInfo, meta:array<string, mixed>, mtime:int|null,
     *     size:int|null}>} $albumData `mtime`/`size` are the S122(a) identity the WALK
     *     stat'ed before reading the file's tags, carried here rather than re-stat'ed —
     *     see {@see self::scanDirectory()} and review r1 B1.
     * @param array<string, array{id:int, media_item_id:string|null}> $artistCache
     * @param array<string, array{id:int, media_item_id:string|null}> $albumCache
     * **Every way out of this method that loses files now counts them (S96(f)).**
     * {@see ScanResult::$failed} is incremented by the number of audio FILES the
     * album's failure cost — one for a single bad track, the whole un-written
     * remainder when the artist or album row could not be written or the outer catch
     * fires. Before this the counts were silently correct-but-short: S95's `finally`
     * left `total_tracks` consistent with the rows that DID land, so a partial album
     * was indistinguishable from a complete one in both the API response and the DB.
     *
     * @param string|null $libraryId Owning library UUID.
     * @param ScanResult  $result    Accumulates added/updated/FAILED counts.
     * @param bool $mayAdopt Whether this library has any orphaned artist/album
     *        `media_items` row worth looking for — decided ONCE per scan by
     *        {@see self::hasAdoptableMusicMediaItem()} and threaded through as an
     *        argument rather than kept on `$this`, so nothing about one scan can
     *        leak into another in a resident worker. BY REFERENCE: this method's own
     *        catch below is what lets the scan continue after a failed write, so it
     *        is also what can leave an orphan mid-walk;
     *        {@see self::upsertArtist()} / {@see self::upsertAlbum()} set the flag
     *        back to true in that case and it must reach
     *        {@see self::scanDirectory()}'s local, or the rest of the scan keeps
     *        adoption switched off and leaks the row for good.
     * @param int $skippedNoArtist BY REFERENCE tally of files dropped by the
     *        unknown-artist rule below, reported once in
     *        {@see self::scanDirectory()}'s completion summary. Separate from
     *        `$result->failed` on purpose — see the declaration there.
     * @param MusicScanSkipIndex|null $skipIndex S122(a) mtime/size index, passed
     *        through to {@see self::upsertTrack()} so a file that has just been read
     *        records the identity that lets the NEXT scan skip it. NULL only for the
     *        legacy construction sites that call this method without one, where the
     *        stamp is simply not written (correct, merely not accelerated).
     * @param int $reparented BY REFERENCE tally of tracks moved to a different album or
     *        artist (S145), forwarded to {@see self::upsertTrack()} and reported once in
     *        {@see self::scanDirectory()}'s completion summary. Same accounting shape as
     *        `$skippedNoArtist`: one summary number rather than 61k log lines.
     * @return void
     */
    private function flushAlbum(
        array $albumData,
        array &$artistCache,
        array &$albumCache,
        ?string $libraryId,
        ScanResult $result,
        bool &$mayAdopt,
        int &$skippedNoArtist,
        ?MusicScanSkipIndex $skipIndex = null,
        int &$reparented = 0
    ): void {
        $artistName = $albumData['artist'];
        $albumTitle = $albumData['album'];

        // Hoisted out of the `try` so the outer catch can charge the un-written
        // remainder of THIS album to $result->failed (S96(f)). `$handled` counts the
        // files the track loop has already accounted for, added or failed, so a
        // throw between two tracks cannot double-count either side.
        $files = $albumData['files'];
        $handled = 0;

        /**
         * **S148 — the vacated albums this flush emptied, DEDUPED. A SET, not a list.**
         *
         * {@see self::upsertTrack()} used to call {@see self::refreshAlbumTrackTotal()}
         * inline, once per moved TRACK. Re-parenting is a per-ALBUM event in practice —
         * a retagged album moves all N of its tracks off the same row — so an N-track
         * album issued **N byte-identical recounts of one row**, each of them a
         * correlated `COUNT(*)` over `music_tracks`. Recording the id here and
         * recounting once in the `finally` below makes it exactly one per vacated album
         * per flush, and the LAST one is the only one whose answer was ever kept
         * anyway.
         *
         * Deferring is also strictly more correct than the inline call: by the time the
         * `finally` runs, every track this flush moves has already moved, so the count
         * is taken once against the settled table instead of N times against a table
         * mid-migration.
         *
         * Hoisted out of the `try` for the same reason `$handled` is — the `finally`
         * must see whatever the loop managed to record before it threw.
         *
         * ⚠ Keyed by album id with a `true` value so a repeat costs nothing; iterated
         * with `array_keys()`, never `array_unique()` on a list.
         *
         * @var array<int, true> $vacatedAlbums
         */
        $vacatedAlbums = [];

        try {
            $year = $albumData['year'];

            // Early exit: skip if no valid artist name
            if ($artistName === '' || $artistName === 'Unknown Artist') {
                $skippedNoArtist += count($files);
                $this->logger->debug('Skipping album with unknown artist', [
                    'album' => $albumTitle,
                    'files' => count($files),
                ]);
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
                // The whole album is lost with its artist, so charge every file.
                $result->failed += count($files);
                // ⚠ ERROR, not warning (review r1 MED-2). `config/logger.php` routes
                // only `error`-and-above to the dedicated `.logs/error.log`, so at
                // `warning` the WHOLE-ALBUM losses were buried in app.log while the
                // per-TRACK loss below (one file) got the clean file to itself — the
                // severity ladder ran backwards against actual data loss. Level now
                // tracks loss: every path that drops files logs at `error`, and
                // `files_lost` says how many.
                $this->logger->error('Failed to upsert artist', [
                    'artist' => $artistName,
                    'album' => $albumTitle,
                    'files_lost' => count($files),
                ]);
                return;
            }

            $artistId = $artistResult['id'];

            // Upsert album. S97: the artist's `media_items` id is deliberately NOT
            // passed down any more — nothing below this line writes or reads
            // `media_items.parent_id` for music, the `music_*` foreign keys are the
            // hierarchy. `$artistResult['media_item_id']` is still populated (S96(e)
            // heals it) and remains the artist row's artwork anchor.
            $albumResult = $this->upsertAlbum(
                $artistId,
                $albumTitle,
                $year,
                $albumCache,
                $libraryId,
                $mayAdopt
            );
            if ($albumResult === null) {
                $result->failed += count($files);
                // ERROR for the same reason as the artist branch above (MED-2): this
                // loses the entire album, so it must reach `.logs/error.log`.
                $this->logger->error('Failed to upsert album', [
                    'album' => $albumTitle,
                    'artist' => $artistName,
                    'files_lost' => count($files),
                ]);
                return;
            }

            $albumId = $albumResult['id'];

            // Upsert tracks (metadata already read during the walk — no re-probe).
            // S97: `$albumResult['media_item_id']` is NOT forwarded — a track's
            // parentage is `music_tracks.album_id`/`artist_id` (NOT NULL, enforced
            // FKs), never `media_items.parent_id`.
            try {
                foreach ($files as $fileInfo) {
                    // Per-TRACK guard. Without it one unreadable/constraint-
                    // violating file aborted the whole album, silently abandoning
                    // every track after it (measured: 2 of 3 written), and the
                    // album's total_tracks was left at whatever the row already
                    // held. A bad file must cost exactly that file.
                    // Re-pair the two flat ints the walk buffered (see $open's docblock:
                    // they are flat to keep the buffer's per-entry cost at zero). A pair
                    // is only allocated here, one track at a time, and dies with the
                    // call. `null` for either half means the walk could not stat the
                    // file, which means nothing is stamped — the safe direction.
                    $stampMtime = $fileInfo['mtime'] ?? null;
                    $stampSize = $fileInfo['size'] ?? null;
                    $stamp = is_int($stampMtime) && is_int($stampSize) ? [$stampMtime, $stampSize] : null;

                    try {
                        $trackResult = $this->upsertTrack(
                            $albumId,
                            $artistId,
                            $fileInfo['file'],
                            $fileInfo['meta'],
                            $libraryId,
                            $skipIndex,
                            $stamp,
                            $reparented,
                            $vacatedAlbums
                        );
                    } catch (\Throwable $trackError) {
                        // Accounted for either way: this file is done being tried
                        // (so the outer catch must not charge it again) and it cost
                        // exactly one failed file.
                        $handled++;
                        $result->failed++;
                        $this->logger->error('Skipping track after error during indexing', [
                            'album' => $albumTitle,
                            'artist' => $artistName,
                            'path' => $fileInfo['file']->getPathname(),
                            'error' => $trackError->getMessage(),
                        ]);
                        continue;
                    }

                    $handled++;

                    if ($trackResult === 'added') {
                        $result->added++;
                    } elseif ($trackResult === 'updated') {
                        $result->updated++;
                    } elseif ($trackResult === 'failed') {
                        // ⚠ THE r2 HIGH FINDING. This branch did not exist: upsertTrack()
                        // returned 'skipped' for a LOST file and for a BENIGN unchanged
                        // one alike, so nothing was charged and the scan closed at `info`
                        // with items_failed = 0. Measured before the split: five files
                        // lost reported `scanned=5 added=0 updated=0 failed=0`, which is
                        // byte-identical to a rescan of an unchanged library.
                        //
                        // The log line lives HERE rather than in upsertTrack() because
                        // this is where the PATH is in hand: `createMediaItem()`'s own
                        // error line carries only the type and the title, so on the S2
                        // shape an operator could not tell WHICH file was dropped, and on
                        // the "wrote nothing" shape there was no line at any level.
                        $result->failed++;
                        $this->logger->error('Track was not indexed', [
                            'album' => $albumTitle,
                            'artist' => $artistName,
                            'path' => $fileInfo['file']->getPathname(),
                            'reason' => 'the media_item or music_tracks row was not written',
                        ]);
                    }
                    // NB: 'skipped' is the remaining case and is deliberately charged to
                    // NOTHING — it means the row already exists and nothing changed, i.e.
                    // every file of every unchanged library on every rescan.
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
                //
                // S145 + S148 — AND THE VACATED ALBUMS, once each. Every album this
                // flush moved a track OFF is recounted here rather than inside
                // {@see self::upsertTrack()}, so a retagged 12-track album costs ONE
                // recount of the row it emptied instead of twelve identical ones.
                //
                // The union puts the album being FLUSHED first and de-duplicates:
                // `$albumId` can only also appear in `$vacatedAlbums` if a track moved
                // off the album it moved onto, which is not reachable (the id is
                // compared for INEQUALITY before it is recorded), so the `+` is
                // belt-and-braces against a future edit rather than live logic.
                //
                // ⚠ **EVERY RECOUNT IS ATTEMPTED, AND ONE THROW MUST NOT STRAND THE
                // REST (review r1 non-blocking 3).** Straight-line calls here were a
                // regression against S145, where the vacated recount ran inline inside
                // the per-TRACK `try`/`catch` and was therefore already independent.
                // Measured with the flushed album's own recount made to throw and three
                // tracks re-parented off `Album A`: the drain never ran, `Album A` kept
                // `total_tracks = 3` while owning 0 rows, and the scan still reported
                // `failed = 0`. Nothing heals that afterwards — the emptied album is
                // never flushed again — and getArtistWithAlbums() sums the column, so
                // the artist page over-counts permanently. Pinned by {@see
                // \Phlix\Tests\Integration\Media\MusicScanWriteAmplificationIntegrationTest::testAThrowFromTheFlushedAlbumsOwnRecountStillRecountsTheVacatedAlbum()}.
                //
                // The FIRST failure is re-thrown once every id has been attempted, so
                // the outer `catch` still logs it exactly as before — the fix changes
                // what gets attempted, not what gets reported. (PHP chains an exception
                // thrown from a `finally` onto whatever the `try` threw, so the original
                // is preserved as `getPrevious()`.)
                $recountError = null;

                foreach (array_keys([$albumId => true] + $vacatedAlbums) as $recountId) {
                    try {
                        $this->refreshAlbumTrackTotal($recountId);
                    } catch (\Throwable $recountFailure) {
                        $recountError ??= $recountFailure;
                    }
                }

                if ($recountError !== null) {
                    throw $recountError;
                }
            }
        } catch (\Throwable $e) {
            // Whatever the track loop never reached is lost with this album. The
            // early `return`s above have already charged their own files and left
            // $handled at 0, so they cannot reach this line twice — a `return` does
            // not throw.
            $lost = max(0, count($files) - $handled);
            $result->failed += $lost;
            $this->logger->error('Skipping album after error during indexing', [
                'album' => $albumTitle,
                'artist' => $artistName,
                'files_lost' => $lost,
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
     * ⚠ **S122(c) — `analyze()` DOES NOT POPULATE `$info['comments']`, AND THAT MADE
     * THIS WHOLE METHOD DEAD CODE ON PRODUCTION. MEASURED.** getID3 puts a format's
     * tags in `$info['tags'][<tagtype>]` (`getid3.php:1675`, inside `HandleAllTags()`);
     * the merged, format-agnostic `$info['comments']` view is built by
     * `getid3_lib::CopyTagsToComments()`, which **no getID3 module and no part of
     * `analyze()` ever calls** — it is a helper the CALLER is expected to invoke
     * (`getid3.php:1762`, and `grep -rn CopyTagsToComments vendor/james-heinrich/getid3`
     * returns only its own declaration and that forwarder). So `$info['comments']` came
     * back holding, at most, the key `picture`:
     *
     *  - measured on an ffmpeg-written MP3 (artist/album/title/track/date/genre, ID3v2.3):
     *    `array_keys($info['comments'])` = **`[]`**, while
     *    `array_keys($info['tags']['id3v2'])` = `[artist, album, title, track_number,
     *    year, genre, encoder_settings, totaltracks]`;
     *  - measured on a file WITH cover art: `array_keys($info['comments'])` =
     *    **`['picture']`** — because `HandleAllTags()` writes pictures straight into
     *    `$info['comments']['picture']` (`getid3.php:1716`) and nothing else.
     *
     * {@see self::mapId3Comments()} therefore found `artist`, `band`, `album` and `title`
     * all NULL for **every file**, returned NULL, and {@see self::probeMetadata()} fell
     * through to ffprobe for **100 % of the library** — after paying getID3's full read.
     * The class docblock's "getID3 first (a pure-PHP tag reader, ~1-3 ms/file), falling
     * back to FFmpeg/ffprobe only when getID3 yields nothing usable" described the
     * INTENT; the code never delivered it. Measured cost of that, warm, on a LOCAL
     * 4.17 MB MP3 (20 iterations each):
     *
     * | probe | ms/file | read syscalls |
     * |---|---|---|
     * | `ffprobe` subprocess (what actually ran) | **114.9** | **232** (`strace -c`) |
     * | tuned getID3 (what was meant to run) | **2.2** | — |
     *
     * i.e. **52x**, ≈1.95 h of pure ffprobe over the 61,135-file production library on
     * warm LOCAL disk, before any sshfs latency is added. `CopyTagsToComments()` is
     * additive and duplicate-suppressing (`getid3.lib.php:1570-1640`), so calling it
     * cannot clobber a `comments` block that is already populated, and
     * {@see self::mapId3Comments()}'s contract is unchanged — which is why the fix is
     * here and not in the mapper.
     *
     * ## ⚠ THIS CHANGES THE METADATA SOURCE FOR 100 % OF THE LIBRARY — SAY SO
     *
     * Review r1 non-blocking 7. Because getID3 previously produced nothing, EVERY tag
     * in the database today came from ffprobe. From this change on, every tag comes from
     * getID3 and ffprobe is reached only for a file getID3 cannot read at all. Two
     * consequences an operator will see and must not mistake for a fault:
     *
     *  1. **The first rescan after deploy reports ~every file as `updated`**, not
     *     `skipped`. {@see self::upsertTrack()} compares title/track/disc/duration
     *     (plus album/artist parentage since S145), and
     *     `duration_secs` is now derived differently (below), so the comparison fails
     *     for most rows even though nothing on disk changed. `items_added` stays 0 —
     *     nothing is duplicated, the same `(path, library_id)` rows are reused — and the
     *     rescan after THAT one is the fast, all-skipped scan.
     *  2. **`duration_secs` comes from getID3 `playtime_seconds`, not from ffprobe's
     *     container duration.** Both are floored to whole seconds, and the two differ by
     *     **exactly one MPEG frame — 1152/44100 = 0.0261 s** — on every Xing-header MP3
     *     measured (getID3 excludes the VBR-header frame from the audio region; ffprobe
     *     counts it). Measured on the committed fixture: getID3 **2.0115** vs ffprobe
     *     **2.0376**. So for the ≈2.6 % of files whose true length lands inside that
     *     26 ms window, the displayed duration moves by 1 second. Cosmetic, and
     *     one-directional (getID3 is never the longer of the two).
     *
     * **Is getID3 LESS accurate than ffprobe? Measured: no — it is equal or better.**
     * 11 fixtures (`libmp3lame` CBR 128k/320k, VBR `-q:a 4`/`-q:a 9`, VBR with the
     * Xing/LAME header stripped, a 5-minute VBR file whose first 60 s are near-silence,
     * plus flac / aac-m4a / vorbis-ogg / pcm wav, and the committed fixture):
     *
     * | shape | true | getID3 | ffprobe |
     * |---|---|---|---|
     * | flac / m4a / ogg / wav | 37.000 | **37.0000** | 37.0000 |
     * | mp3 CBR + VBR with Xing | 37.000 | 37.0155 | 37.0416 |
     * | **mp3 VBR, Xing header STRIPPED** | 37.000 | **37.0047** | **35.6591 ✗** |
     * | **mp3 VBR, no Xing, silence-then-loud, 5 min** | 300.000 | **299.6459** | **627.467 ✗ (2.09x)** |
     *
     * On a headerless VBR stream ffprobe extrapolates the whole file from the leading
     * frames' bitrate and can be wrong by minutes; getID3 is within 0.35 s. So the
     * revival IMPROVES duration accuracy on the one shape where the two disagree
     * materially. There is no measured case where getID3 is the less accurate of the two.
     *
     * The `options_audio_mp3_mp3_valid_check_frames = 10` trade-off documented in
     * {@see self::getId3Reader()} does not bear on this: `playtime_seconds` was
     * **byte-identical at 10, 20, 50 and 200 frames on all 11 fixtures, with zero
     * getID3 warnings and zero errors at either setting**, because that option gates a
     * frame-chain VALIDITY scan (`module.audio.mp3.php:1172`, inside
     * `RecursiveFrameScanning()`) and not the duration derivation
     * (`module.audio.mp3.php:175-180`).
     *
     * @param string $path Absolute filesystem path
     * @return array<string, mixed>|null Mapped metadata, or null when getID3 read
     *                                   nothing usable (so a fallback should run).
     */
    protected function probeViaGetId3(string $path): ?array
    {
        try {
            $reader = $this->getId3Reader();
            $info = $reader->analyze($path);
            if (!is_array($info)) {
                return null;
            }

            // Build the merged tag view this class has always read. Without this the
            // block is empty and every file falls through to ffprobe — see above.
            $reader->CopyTagsToComments($info);

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
     * Lazily constructs the native getID3 reader, configured to read as little as
     * a tag harvest needs.
     *
     * **S122(c) — EVERY OPTION HERE IS SET FROM A MEASUREMENT, AND THE MEASUREMENTS
     * ARE BELOW SO THEY CAN BE CHECKED.** Byte/read/seek counts were taken through a
     * counting stream wrapper — the same technique `MusicScanTagReadCostTest` uses, so
     * every row is falsifiable in CI on a DB-less box — on two fixtures:
     *
     *  - **SYNTH**: a hand-built 4.17 MB MP3 with textbook-perfect CBR frames and a
     *    404 KB `APIC` frame. Isolates the cover-art cost.
     *  - **REAL**: `tests/Fixtures/Media/Music/tagged-short.mp3`, 16.6 KB, written by
     *    ffmpeg with a Xing header and no art. ⚠ This one is the representative shape: a
     *    real encoder's output sends getID3 down its recursive frame-scanning path, which
     *    is where the diagnostic's "~60 `fread`/`fseek` per MP3" figure comes from. The
     *    synthetic file validates in one pass at 21 reads / 11 seeks and would have made
     *    the frame-count option look worthless.
     *
     * | fixture | options | bytes read | reads | seeks | bytes retained |
     * |---|---|---|---|---|---|
     * | SYNTH 4.17 MB + 404 KB art | pre-S122 | 598,585 | 76 | 11 | **1,891,240** |
     * | SYNTH 4.17 MB + 404 KB art | **these** | 582,201 | 74 | 9 | **33,256** |
     * | REAL 16.6 KB, no art | pre-S122 | 455,654 | 61 | 59 | 41,488 |
     * | REAL 16.6 KB, no art | **these** | **139,931** | **21** | **19** | 31,384 |
     *
     * So on a real file this is **−69 % bytes, −66 % reads and −68 % seeks**; on a long
     * art-carrying file it is only −2.7 % bytes, because ≈404 KB of that is the
     * unconditional ID3v2 read described next.
     *
     * ⚠ **CORRECTION TO THE DIAGNOSTIC (`steps/vault-sshfs-read-perf-diagnostic.worklog.md`
     * §1), MEASURED: `option_save_attachments = false` does NOT reduce bytes read.** The
     * diagnostic correctly identified ≈404 KB of discarded cover art per file, but the
     * read is **unconditional and contiguous** — `module.tag.id3v2.php:139` does
     * `$framedata = $this->fread($sizeofframes)` for the WHOLE frame region before any
     * frame is inspected, and `option_save_attachments` is only consulted afterwards, at
     * `:1448`, to decide whether to KEEP the picture. Measured: 598,585 bytes read with
     * the option either way, to the byte. What it does buy is real but is memory, not
     * I/O: **1,891,240 → 33,256 bytes retained per analyse, a 98.2 % cut** (the APIC
     * payload plus its base64/`getimagesizefromstring` derivatives), which in a resident
     * scan worker is the difference between 1.9 MB of churn per file and none.
     * **Removing the 404 KB read itself would require patching `vendor/`, which is
     * forbidden** (a hand-edited vendor file is invisible to CI forever). The bytes
     * lever for that file is S122(a): an unchanged file is not opened at all.
     *
     * Per option:
     *
     *  - `option_md5_data` / `option_md5_data_source` / `option_sha1_data` — pre-existing.
     *    Hashing the audio payload would read the ENTIRE file.
     *  - `option_save_attachments = false` — see the correction above. Also
     *    `getID3::ATTACHMENTS_NONE`, spelled as the literal `false` the vendor
     *    `=== false` comparison at `:1448` requires.
     *  - `option_tags_html = false` — suppresses `$info['tags_html']`, an
     *    HTML-entity-encoded SECOND copy of every tag built by
     *    `getid3_lib::recursiveMultiByteCharString2HTML()`. Nothing in this codebase
     *    reads `tags_html` (`grep -rn tags_html src/` is empty), so it was pure cost.
     *  - `options_audio_mp3_mp3_valid_check_frames = 10` — vendor default 50, and the
     *    vendor's own docblock says "Lower this number to 5-20 for faster scanning".
     *    This is the 51 x `fread(226)` frame scan the diagnostic counted. It is where
     *    the REAL-fixture numbers above come from: 455,654 → 139,931 bytes and 59 → 19
     *    seeks, because a real encoder's stream sends getID3 into recursive frame
     *    scanning and the 50-frame requirement then forces repeated re-reads.
     *    ⚠ TRADE-OFF, stated, and NARROWER than it first looked. The option gates a
     *    frame-chain VALIDITY scan (`module.audio.mp3.php:1172`, inside
     *    `RecursiveFrameScanning()`), not the duration derivation
     *    (`module.audio.mp3.php:175-180`), so it can only reach `playtime_seconds`
     *    indirectly — via `:1561-1563`, where failing to find N consecutive valid frames
     *    after a VBR header decides whether that header is trusted. Measured across 11
     *    fixtures (CBR 128k/320k, VBR q4/q9, VBR with the Xing header STRIPPED, a
     *    5-minute silence-then-loud headerless VBR, flac/m4a/ogg/wav, and the committed
     *    fixture): `playtime_seconds` is **byte-identical at 10, 20, 50 and 200 frames,
     *    with zero warnings and zero errors at every setting.** 10 is inside the vendor's
     *    own recommended range, and duration is display metadata — playback seeks on the
     *    container, never on this figure. See {@see self::probeViaGetId3()} for the full
     *    accuracy table, including the two shapes where getID3 beats ffprobe outright.
     *
     * NOT changed, and why: `option_tags_process` must stay TRUE — it is what runs
     * `HandleAllTags()` (`getid3.php:790`), without which `$info['tags']` is never built
     * and {@see self::probeViaGetId3()} has nothing to merge. `option_tag_id3v1` stays
     * TRUE because it is the legacy fallback for files with no ID3v2 at all.
     * `option_tag_apetag` / `option_tag_lyrics3` stay TRUE: switching them off removes
     * two of the four end-of-file seek regions but measured only −313 bytes, and an
     * APE-or-Lyrics3-only file would silently lose its artist/album — a tag-loss risk
     * bought for nothing.
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
            // Read the cover art (unavoidable, see above) but do not RETAIN it.
            $reader->option_save_attachments = false;
            // No second, HTML-escaped copy of every tag; nothing reads it.
            $reader->option_tags_html = false;
            // 50 -> 10 validated MPEG frames. The vendor recommends 5-20 for speed.
            $reader->options_audio_mp3_mp3_valid_check_frames = self::MP3_VALID_CHECK_FRAMES;
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
     *        "correct but slower", never to "leaks an orphan". BY REFERENCE, and set
     *        to TRUE here whenever this call leaves a minted `media_items` row that
     *        no `music_artists` row points at — see the `finally` below.
     * @return array{id: int, media_item_id: string|null}|null Artist ID and media_item_id or null on failure
     */
    private function upsertArtist(
        string $name,
        array &$cache,
        ?string $libraryId = null,
        bool &$mayAdopt = true
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

                // S96(e): a NULL here is a row a previous scan wrote after its
                // media_item mint failed. Heal it instead of returning NULL forever.
                if ($mediaItemId === null) {
                    $mediaItemId = $this->backfillMusicMediaItemId(
                        'music_artists',
                        $id,
                        'artist',
                        $name,
                        $libraryId,
                        $mayAdopt,
                        fn(): ?string => $mayAdopt
                            ? $this->findAdoptableArtistMediaItemId($name, $libraryId)
                            : null
                    );
                }

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
        // CLOSED BY S96(e), was: createMediaItem() swallows its own Throwable and
        // returns '', so a transient failure here inserted the music_artists row with
        // media_item_id = NULL, and because the natural-key branch above returned
        // whatever was stored, NO later scan ever backfilled it (measured: still NULL
        // after two clean rescans) — that artist stayed artwork-less and invisible to
        // every media_items-driven path. The natural-key branch above now backfills,
        // which is also the only route by which case (c) of
        // findAdoptableArtistMediaItemId()'s RESIDUE list can ever be reclaimed.
        $adopted = $mayAdopt ? $this->findAdoptableArtistMediaItemId($name, $libraryId) : null;
        $mediaItemId = $adopted ?? $this->createMediaItem('artist', $name, null, $libraryId);

        // THE ORPHAN WINDOW, and why the `finally` below is not decoration. When we
        // minted (rather than adopted) the row, `media_items` now holds an artist row
        // that NOTHING references, and it stays that way until the INSERT below has
        // both succeeded AND carried the minted id. Every way out of that window
        // leaves the orphan behind:
        //
        //   * the INSERT throws            -> flushAlbum()'s outer catch logs
        //                                     "Skipping album …" and THE SCAN CARRIES ON;
        //   * the INSERT returns false     -> `return null` below, also non-fatal;
        //   * createMediaItem() reported
        //     failure for a row the server
        //     actually committed           -> media_item_id is bound NULL, so even a
        //                                     successful INSERT does not reference it.
        //
        // Since hasAdoptableMusicMediaItem() decided $mayAdopt BEFORE the walk, none
        // of those would re-enable adoption on their own — and the next time this same
        // artist name comes round (prod averages ≈2.3 albums per artist, so that is
        // the normal case, not a corner) the natural-key SELECT above is still empty,
        // a SECOND media_items row is minted, and the first becomes unreachable
        // FOREVER: every later scan short-circuits on the natural key before the
        // adoption lookup. Measured on real MySQL: media_items[artist] = 2 against
        // music_artists = 1, surviving two clean rescans. So fail the gate OPEN.
        $referenced = false;

        try {
            // Insert new artist
            $result = $this->db->query(
                "INSERT INTO music_artists (name, sort_name, media_item_id) VALUES (?, ?, ?)",
                [$name, $sortName, $mediaItemId !== '' ? $mediaItemId : null]
            );

            // Same contract as everywhere else in this class (r2 F1): a real error
            // throws, and `null` is the client's "wrote nothing" signal. Falling through
            // on `null` would read `lastInsertId()` for a row that does not exist and
            // hand back a bogus artist id, which every track of the album would then
            // reference. Returning null charges the whole album, once, at `error`.
            if (self::statementWroteNothing($result)) {
                return null;
            }

            // The music_artists row exists AND points at the id we minted; nothing
            // after this statement can orphan it.
            $referenced = $mediaItemId !== '';

            $id = (int)$this->db->lastInsertId();

            $this->logger->debug('Upserted artist', ['id' => $id, 'name' => $name, 'media_item_id' => $mediaItemId]);

            return $this->cacheRemember(
                $cache,
                $cacheKey,
                ['id' => $id, 'media_item_id' => $mediaItemId !== '' ? $mediaItemId : null],
                self::MAX_ARTIST_CACHE
            );
        } finally {
            if ($adopted === null && !$referenced) {
                $mayAdopt = true;
            }
        }
    }

    /**
     * Upserts an album into the database with a corresponding media_item.
     *
     * ⚠ S97: this used to take the artist's `media_items` id as its second argument,
     * purely to scope orphan adoption. It is gone because the adoption predicate no
     * longer scopes by artist — `media_items.parent_id` is never written for music at
     * all, so there was nothing to scope against. See
     * {@see self::findAdoptableAlbumMediaItemId()} for the invariant that replaced it.
     *
     * @param int $artistId Artist ID
     * @param string $title Album title
     * @param int|null $year Release year
     * @param array<string, array{id:int, media_item_id:string|null}> $cache Album cache key by "artistId|title"
     * @param string|null $libraryId Owning library UUID stamped onto a new media_item.
     * @param bool $mayAdopt Whether the one-per-scan gate found an adoptable
     *        orphan ({@see self::hasAdoptableMusicMediaItem()}). FALSE skips the
     *        unindexed adoption lookup; defaults to TRUE so an omission degrades to
     *        "correct but slower", never to "leaks an orphan". BY REFERENCE, and set
     *        to TRUE here whenever this call leaves a minted `media_items` row that
     *        no `music_albums` row points at — see the `finally` below and the same
     *        window documented at length in {@see self::upsertArtist()}.
     * @return array{id: int, media_item_id: string|null}|null Album ID and media_item_id or null on failure
     */
    private function upsertAlbum(
        int $artistId,
        string $title,
        ?int $year,
        array &$cache,
        ?string $libraryId = null,
        bool &$mayAdopt = true
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

                // S96(e), artist twin above: heal a media_item_id left NULL by an
                // earlier scan whose mint failed, instead of returning NULL forever.
                if ($mediaItemId === null) {
                    $mediaItemId = $this->backfillMusicMediaItemId(
                        'music_albums',
                        $id,
                        'album',
                        $title,
                        $libraryId,
                        $mayAdopt,
                        fn(): ?string => $mayAdopt
                            ? $this->findAdoptableAlbumMediaItemId($title, $libraryId)
                            : null
                    );
                }

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
        // The NULL media_item_id gap this used to point at is closed by S96(e) in
        // the natural-key branch above, exactly as in upsertArtist().
        $adopted = $mayAdopt
            ? $this->findAdoptableAlbumMediaItemId($title, $libraryId)
            : null;
        $mediaItemId = $adopted ?? $this->createMediaItem('album', $title, null, $libraryId);

        // Same orphan window as upsertArtist(), same fail-open, and it is reachable
        // WITHOUT any artist recurring: MAX_TRACKS_PER_FLUSH chunks one oversized
        // album into several flushes of the SAME album key inside ONE scan, so a
        // failed first `INSERT INTO music_albums` is met again a few hundred files
        // later. Read upsertArtist()'s block for the full enumeration.
        $referenced = false;

        try {
            // Insert new album. total_tracks defaults to 0 and is set by
            // refreshAlbumTrackTotal() once this flush's tracks are persisted.
            $result = $this->db->query(
                "INSERT INTO music_albums (artist_id, media_item_id, title, sort_title, year)
                 VALUES (?, ?, ?, ?, ?)",
                [$artistId, $mediaItemId !== '' ? $mediaItemId : null, $title, $sortTitle, $year]
            );

            // See upsertArtist() — a `null` return here would mint a bogus album id.
            if (self::statementWroteNothing($result)) {
                return null;
            }

            $referenced = $mediaItemId !== '';

            $id = (int)$this->db->lastInsertId();

            $this->logger->debug('Upserted album', ['id' => $id, 'title' => $title, 'artist_id' => $artistId]);

            return $this->cacheRemember(
                $cache,
                $cacheKey,
                ['id' => $id, 'media_item_id' => $mediaItemId !== '' ? $mediaItemId : null],
                self::MAX_ALBUM_CACHE
            );
        } finally {
            if ($adopted === null && !$referenced) {
                $mayAdopt = true;
            }
        }
    }

    /**
     * Gives an existing `music_artists`/`music_albums` row the `media_item_id` it
     * should have had — adopting an orphan if one is available, otherwise minting a
     * fresh `media_items` row. **S96(e).**
     *
     * WHY THIS EXISTS. {@see self::createMediaItem()} swallows its own `Throwable`
     * and returns `''`, so ONE transient failure during ONE scan wrote the
     * `music_*` row with `media_item_id = NULL`. Nothing ever repaired it: both
     * upserts find-or-create on their NATURAL key (`music_artists.name`,
     * `music_albums(artist_id, title)`), so every later scan found that row, returned
     * its NULL and short-circuited BEFORE the adoption lookup — measured by the S95
     * reviewer as still NULL after two clean rescans. And nothing failed loudly,
     * because migration 065 declares both columns `NULL UNIQUE`. The consequence is
     * a permanently artwork-less artist/album that no `media_items`-driven surface
     * (`/api/v1/media?type=artist`, the DLNA bridge, the stats type maps) can see.
     * S95 widened the exposure: with the write path spread across a multi-hour walk,
     * one hiccup poisons one artist/album for good instead of being retried at a
     * single terminal flush.
     *
     * This is also the ONLY route by which case (c) of
     * {@see self::findAdoptableArtistMediaItemId()}'s RESIDUE list — an orphan from a
     * mint the server committed but `createMediaItem()` reported as failed — can ever
     * be reclaimed, since it is driven from OUTSIDE the natural-key short-circuit.
     *
     * `AND media_item_id IS NULL` in the UPDATE is not decoration: it makes the
     * backfill idempotent and keeps a concurrent writer's id from being clobbered,
     * which matters because the column is UNIQUE — two rows carrying one id would
     * fail an INSERT elsewhere and lose a whole album.
     *
     * Costs nothing on a healthy library: the callers only reach this when a stored
     * `media_item_id` is genuinely NULL.
     *
     * @param 'music_artists'|'music_albums' $table Table holding the row to heal.
     *        A literal union, not caller data, and matched to a constant SQL string
     *        below — nothing is interpolated into the statement.
     * @param int $rowId `music_artists.id` / `music_albums.id`.
     * @param 'artist'|'album' $type `media_items.type` to mint (also the sub_type).
     * @param string $name Display name (artist name / album title).
     * @param string|null $libraryId Owning library UUID.
     * @param bool $mayAdopt BY REFERENCE, same contract as
     *        {@see self::upsertArtist()}: set back to TRUE whenever this call leaves
     *        a freshly minted `media_items` row that nothing references, so the rest
     *        of the scan keeps hunting for orphans instead of leaking them.
     * @param \Closure(): ?string $adopt Orphan lookup for this entity, already gated
     *        on `$mayAdopt` by the caller (each entity type has its own predicate;
     *        the album one additionally asserts the S97 no-parent invariant — see
     *        {@see self::findAdoptableAlbumMediaItemId()}).
     * @return string|null The id now stored on the row, or NULL when it could not be
     *         healed this pass (the next scan retries — nothing is lost).
     */
    private function backfillMusicMediaItemId(
        string $table,
        int $rowId,
        string $type,
        string $name,
        ?string $libraryId,
        bool &$mayAdopt,
        \Closure $adopt
    ): ?string {
        $adopted = $adopt();
        $mediaItemId = $adopted ?? $this->createMediaItem($type, $name, null, $libraryId);

        if ($mediaItemId === '') {
            // The mint failed and said so. It may STILL have committed a row
            // (createMediaItem() cannot tell), so re-open the adoption gate: that is
            // what lets the next encounter reclaim it rather than mint a rival.
            $mayAdopt = true;
            return null;
        }

        $sql = match ($table) {
            'music_artists' => 'UPDATE music_artists SET media_item_id = ? WHERE id = ? AND media_item_id IS NULL',
            'music_albums' => 'UPDATE music_albums SET media_item_id = ? WHERE id = ? AND media_item_id IS NULL',
        };

        $referenced = false;

        try {
            $affected = $this->db->query($sql, [$mediaItemId, $rowId]);
            // ⚠ `statementWroteNothing()`, not a bare `=== false` (review r3 finding 4).
            // This guard was written before the contract was measured and it errs in the
            // PERMISSIVE direction: `null === false` is false and `is_int(null)` is false,
            // so a `null` fell straight through to `$referenced = true` and logged
            // "Backfilled a NULL media_item_id" at `info` for a heal that never happened —
            // the exact inverse of the r2 HIGH finding, on the same file.
            //
            // ⚠ **DO NOT DELETE THE `is_int(...)` HALF AS REDUNDANT — IT IS THE HALF THIS
            // STATEMENT REACHES AS WRITTEN (review r4).** The return domain is per statement
            // KEYWORD: `Connection::query()` returns `rowCount()` when the leading keyword
            // is `update` (`vendor/workerman/mysql/src/Connection.php:1859-1860`, after
            // `trim()` at `:1835`), so this UPDATE reports "wrote nothing" as **`int 0`**.
            // Measured against real MySQL 8.0.46: the row the `AND media_item_id IS NULL`
            // predicate excludes → `int 0`; a row it lets through → `int 1`; a re-run →
            // `int 0`; a matched row whose value does not change → `int 0`; an unknown
            // column → THROWS.
            //
            // ⚠ **AND DO NOT DELETE `statementWroteNothing()` AS DEAD CODE EITHER: `null` IS
            // REACHABLE HERE (review r5 corrects fix r4's "never `null`").** The keyword is
            // recognised only when the statement's first SPACE-delimited token `trim()`s down
            // to it — `Connection.php:1854` splits with `explode(" ", $query)`, `:1856` does
            // `strtolower(trim($rawStatement[0]))` — so the moment the `$sql` above is
            // reformatted (a heredoc, a leading comment, a tab) the statement misses the
            // `update` branch and the client returns `null` from `:1866`. Measured on real
            // MySQL 8.0.46 at r5: `"UPDATE\nmusic_artists SET …"` → `null`,
            // `"UPDATE\tmusic_artists …"` (tab, NO space) → `null`, a leading block comment →
            // `null`. ⚠ Review r6 narrows r5's "a single space": `"UPDATE\t music_artists …"`
            // (tab THEN space) splits to `"UPDATE\t"`, which `:1856` trims back to `update`, so
            // that layout still reaches the int arm. A bare `createMock(Connection::class)`
            // hands back `null` too. So **BOTH halves are load-bearing**: drop the int half
            // and every row the predicate excludes gets a false `info` heal today; drop the
            // `null` half and the next reformat of those `match` arms reintroduces the r2 HIGH
            // finding silently.
            //
            // Each arm is pinned on its own by
            // `MusicLibraryScannerTest::testABackfillUpdateThatWroteNothingIsNotReportedAsHealed()`
            // (scenario (A) = `int 0`, scenario (B) = `null`), and every row of the per-keyword
            // table — `select`/`show` → list, `insert` → string, `update`/`delete`/`replace` →
            // int, anything else (INCLUDING a reformatted `UPDATE`) → `null` — by
            // `…::testTheSchemaDoubleModelsTheClientsPerKeywordReturnDomain()`, which asserts
            // all four rows since r5 (it asserted three while claiming four) and, since r6,
            // every whitespace layout of the `update` row that its own table names AS
            // REACHABLE. ⚠ Review r7 INFO-1: this signpost used to drop the qualifier its own
            // target is careful to state. That table has SIX rows, and the sixth — a leading-
            // whitespace `update`, i.e. the driver's OUTER `trim()` at `Connection.php:1835` —
            // is asserted by nothing and is labelled `NO — unreachable` there, because the
            // double's `query()` `ltrim()`s before the keyword is ever derived. Removing that
            // outer `trim()` leaves the suite GREEN (measured at r7), which is exactly why the
            // table states the row instead of claiming a pin for it.
            if (self::statementWroteNothing($affected) || (is_int($affected) && $affected < 1)) {
                // Not applied — either the statement wrote nothing or another writer got
                // there first. Report NULL and let the next scan try again.
                return null;
            }

            $referenced = true;

            $this->logger->info('Backfilled a NULL media_item_id on a music row', [
                'table' => $table,
                'row_id' => $rowId,
                'type' => $type,
                'name' => $name,
                'media_item_id' => $mediaItemId,
                'adopted' => $adopted !== null,
            ]);

            return $mediaItemId;
        } finally {
            if ($adopted === null && !$referenced) {
                $mayAdopt = true;
            }
        }
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
     * ⚠ S97: this used to take the album's `media_items` id as its second argument
     * ("for linking"), which it then `unset()` without ever reading. It is gone: a
     * track is linked to its album by `music_tracks.album_id` — `INT UNSIGNED NOT
     * NULL` with an enforced FK and `ON DELETE CASCADE` — and
     * `media_items.parent_id` is never written for music. There is nothing to link.
     *
     * @param int $albumId Album ID
     * @param int $artistId Artist ID (denormalized for queries)
     * @param SplFileInfo $file Audio file info
     * @param array<string, mixed> $metadata Tags already read during grouping (no re-probe)
     * @param string|null $libraryId Owning library UUID (stamped on a new media_item + event).
     * @param MusicScanSkipIndex|null $skipIndex S122(a). When supplied, `$stamp` is
     *        recorded into `media_items.metadata_json` on every outcome that leaves the
     *        row CONSISTENT WITH THE FILE JUST READ — `'added'`, `'updated'` and
     *        `'skipped'` — and on none that does not (`'failed'`). That is the whole
     *        cache-validity argument: the stamp means "the tags now indexed are the
     *        tags this file had at this mtime and size", so it may only be written by
     *        a pass that actually read those tags.
     * @param array{0: int, 1: int}|null $stamp The `(mtime, size)`
     *        {@see self::scanDirectory()} stat'ed IMMEDIATELY BEFORE reading this
     *        file's tags, or NULL when it could not be stat'ed (nothing is stamped
     *        then). ⚠ **This is an argument and not a fresh stat for a measured reason
     *        (review r1 B1).** Re-stat'ing here reads the file as it is at FLUSH time,
     *        which can be hundreds of files — or a whole multi-hour walk, for an album
     *        spread across the tree — after the tags were read. An ordinary tag write
     *        in that window was then stamped post-edit against pre-edit tags and
     *        skipped forever. Pinned by
     *        {@see \Phlix\Tests\Unit\Media\Music\MusicScanUnchangedSkipTest::testAFileEditedBetweenItsProbeAndItsFlushIsReReadOnTheNextScan()}.
     * @param int $reparented BY REFERENCE tally of tracks this call moved to a different
     *        album or artist (S145), surfaced once in {@see self::scanDirectory()}'s
     *        completion summary as `reparented`. A re-parented track is ALSO charged to
     *        {@see ScanResult::$updated} — this counter says how much of that number is
     *        parentage repair rather than tag edits. Deliberately NOT a per-track log
     *        line: a healing scan of the production library re-parents thousands of
     *        files and per-track logging is banned for exactly that reason (see the
     *        `'added'` branch below).
     * @param array<int, true> $vacatedAlbums BY REFERENCE set of `music_albums.id`s this
     *        flush has emptied — every album a track was moved OFF. **S148.** This method
     *        used to recount the vacated album inline, once per moved TRACK, which meant
     *        a retagged N-track album issued N identical recounts of the same row.
     *        Recording the id instead lets {@see self::flushAlbum()} recount each vacated
     *        album exactly once, after every move in the flush has landed. The `[]`
     *        default matches the two arguments before it and keeps the parameter list
     *        callable without it; {@see self::flushAlbum()} is the only caller and always
     *        passes one, so a mutation that drops the argument leaves the set undrained
     *        and the vacated album's count stale — which is what the tests assert on.
     *
     * @return string One of FOUR outcomes. ⚠ `'skipped'` and `'failed'` used to be the
     *         SAME value, and that collision was review r2's HIGH finding: a scan that
     *         silently lost five files was byte-identical to a benign rescan of an
     *         unchanged library on every surface S96 built (`scanned=5 added=0
     *         updated=0 failed=0`, summary at `info`, `items_failed = 0`), which is
     *         exactly what migration 095 exists to make impossible.
     *
     *          - `'added'`   a new `media_items` + `music_tracks` pair was written;
     *          - `'updated'` an existing row was refreshed in place;
     *          - `'skipped'` BENIGN no-op — the row exists and nothing changed, WHERE
     *                        "nothing" now includes its album and artist (S145). This is
     *                        the common case on every rescan, so it must never be
     *                        charged to {@see ScanResult::$failed} (an unchanged
     *                        library would otherwise report every file as an error);
     *          - `'failed'`  the file was NOT indexed: it has no `music_tracks` row and
     *                        the scan lost it. {@see self::flushAlbum()} charges exactly
     *                        one file and logs the path at `error`.
     */
    private function upsertTrack(
        int $albumId,
        int $artistId,
        SplFileInfo $file,
        array $metadata,
        ?string $libraryId = null,
        ?MusicScanSkipIndex $skipIndex = null,
        ?array $stamp = null,
        int &$reparented = 0,
        array &$vacatedAlbums = []
    ): string {
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
            // S145: `album_id`/`artist_id` are SELECTed because the change predicate
            // below compares them. Without them in hand, a file whose ALBUM or ARTIST
            // tag changed is indistinguishable from an unchanged one and the row stays
            // filed under its old album forever (measured on production: 310 albums
            // owning zero tracks, and essentially every album minted after the initial
            // import was such a shell).
            $existing = $this->db->query(
                "SELECT id, album_id, artist_id, title, track_number, disc_number, duration_secs
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
                // S145: parentage joins the predicate. Same is_numeric -> (int) shape as
                // the four above; `music_tracks.album_id`/`artist_id` are INT UNSIGNED
                // NOT NULL with enforced FKs (migration 065), so there is no NULL case —
                // the only reachable failure is WRONG BUT VALID, which is precisely why
                // nothing ever surfaced it.
                $existingAlbumId = isset($existingTrack['album_id']) &&
                    is_numeric($existingTrack['album_id']) ? (int)$existingTrack['album_id'] : 0;
                $existingArtistId = isset($existingTrack['artist_id']) &&
                    is_numeric($existingTrack['artist_id']) ? (int)$existingTrack['artist_id'] : 0;

                if (
                    $existingTitle === $title
                    && $existingTrackNum === $trackNumber
                    && $existingDiscNum === $discNumber
                    && $existingDuration === $durationSecs
                    && $existingAlbumId === $albumId
                    && $existingArtistId === $artistId
                ) {
                    // S122(a): THE BACKFILL PATH, and the reason the first rescan after
                    // this step ships is still slow while every later one is fast. Every
                    // pre-S122 row carries no `(mtime, size)`, so nothing can be skipped
                    // until something records it — and this branch is where an unchanged
                    // library records it, one statement per file, once.
                    //
                    // What makes stamping sound here is NOT that this is the "unchanged"
                    // branch — that reasoning was wrong (review r1 B1) and is what let a
                    // flush-time stat write a post-edit identity against the pre-edit
                    // tags this branch just compared. It is sound because `$stamp` is the
                    // identity the walk observed BEFORE the read: it can only be older
                    // than the bytes those tags came from, never newer.
                    //
                    // ✅ S145 CLOSED THE HOLE THIS PARAGRAPH USED TO DESCRIBE. Until then
                    // the predicate above compared only title/track/disc/duration, so a
                    // file whose ARTIST or ALBUM tag changed while those four stayed
                    // identical returned 'skipped' here and `music_tracks.album_id` /
                    // `artist_id` kept pointing at the OLD album — permanently, because
                    // nothing else in the codebase writes those two columns outside the
                    // two INSERTs below. Parentage is now part of the predicate, so such
                    // a file falls through to the UPDATE instead of reaching this line.
                    //
                    // Reaching this branch therefore now means MORE than "the four fields
                    // match": it means the row is correct in every column the scan can
                    // observe, which is what makes stamping-and-returning honest.
                    //
                    // ⚠ There is deliberately NO filesystem backfill migration. Stat'ing
                    // 61,135 files and recording today's mtime for a row whose indexed
                    // tags might predate a change would make the next scan skip a file
                    // that really did change — manufacturing the exact silent miss this
                    // predicate is designed to avoid. Paying one slow rescan is the
                    // honest price.
                    $this->stampFileIdentity($existingMediaItemId, $file, $skipIndex, $stamp);

                    return 'skipped';
                }

                // Update existing track (no new media_item, no event).
                //
                // ⚠ S145 — COLUMN ORDER IS PART OF THE CONTRACT. `album_id`/`artist_id`
                // are APPENDED after `duration_secs` and `id` stays LAST. Any other
                // arrangement is equally correct SQL and gratuitously breaks the two
                // in-memory doubles, which index the row id positionally.
                $this->db->query(
                    "UPDATE music_tracks SET title = ?, track_number = ?, disc_number = ?, duration_secs = ?,
                            album_id = ?, artist_id = ?
                     WHERE id = ?",
                    [$title, $trackNumber, $discNumber, $durationSecs, $albumId, $artistId, $existingId]
                );

                // ── S145 + S148: TWO SEPARATE QUESTIONS, TWO SEPARATE GUARDS. ────────
                //
                // "Did this track move?" and "is there a vacated album row to recount?"
                // are not the same question, and S145 answered both with one `if/elseif`
                // chain that required `$existingAlbumId > 0`. That under-reported
                // `reparented`: a row whose `album_id` read as 0 — the "column absent or
                // unreadable" coercion shape above — genuinely moved and was counted only
                // if its ARTIST had also changed. (`music_tracks.album_id` is
                // `INT UNSIGNED NOT NULL` with an enforced FK, so a real 0 is currently
                // unreachable; the point is that the COUNTER must not depend on a guard
                // that exists for the RECOUNT, because the day the shape becomes
                // reachable the operator's only evidence silently goes quiet.)
                $albumMoved = $existingAlbumId !== $albumId;
                $artistMoved = $existingArtistId !== $artistId;

                // The counter: any change of parentage, whatever the old ids were.
                if ($albumMoved || $artistMoved) {
                    $reparented++;
                }

                // THE VACATED ALBUM. `flushAlbum()`'s `finally` refreshes only the album
                // being flushed, i.e. the one the track just moved TO. Without this the
                // album the track just LEFT advertises a `total_tracks` one too high
                // forever, and `MusicLibraryService::getArtistWithAlbums()` sums that
                // column onto the artist page — so healing the parentage would have
                // traded one wrong number for another.
                //
                // ⚠ S148 — RECORDED, NOT RECOUNTED. This used to call
                // {@see self::refreshAlbumTrackTotal()} right here, once per moved TRACK,
                // so a retagged 12-track album issued 12 identical correlated `COUNT(*)`
                // recounts of the one row it emptied. `flushAlbum()` now drains this set
                // once, in the same `finally` that recounts the album being flushed.
                //
                // The `> 0` guard stays HERE, where it belongs: 0 is the "column absent /
                // unreadable" shape and is not a `music_albums.id`, so recounting it
                // would `UPDATE … WHERE a.id = 0` and touch nothing.
                if ($albumMoved && $existingAlbumId > 0) {
                    $vacatedAlbums[$existingAlbumId] = true;
                }

                $this->stampFileIdentity($existingMediaItemId, $file, $skipIndex, $stamp);

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

            // `'failed'`, NOT `'skipped'` (r2 HIGH): the media_item exists but the
            // track row does not, so this file is still not indexed.
            if (self::statementWroteNothing($result)) {
                // NO stamp: the file is NOT indexed, so recording "already indexed at
                // this mtime" would make the next scan skip a file it has never
                // successfully read. Every `'failed'` return in this method is stampless
                // for that reason.
                return 'failed';
            }

            $this->stampFileIdentity($existingMediaItemId, $file, $skipIndex, $stamp);

            return 'updated';
        }

        // Genuinely new track: mint the media_item, insert, and announce it.
        //
        // S122(a): the identity goes in on the SAME INSERT that creates the row rather
        // than in a follow-up UPDATE. That halves the statements on the first-scan path
        // (61,135 of them) and, more importantly, means there is no window in which a
        // track row exists without its stamp — a crash in such a window would leave a
        // row that no later scan can ever skip.
        $mediaItemId = $this->createMediaItem(
            'track',
            $title,
            $path,
            $libraryId,
            $skipIndex !== null ? self::stampMetadata($stamp) : []
        );
        if ($mediaItemId === '') {
            // THE production-reachable loss shape (r2's S2). createMediaItem() catches
            // its own \Throwable, so a DB error there never reaches flushAlbum()'s
            // per-track catch — this return is the only signal that a file was lost.
            return 'failed';
        }

        // Insert new track
        $result = $this->db->query(
            "INSERT INTO music_tracks
             (media_item_id, album_id, artist_id, title, track_number, disc_number, duration_secs)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$mediaItemId, $albumId, $artistId, $title, $trackNumber, $discNumber, $durationSecs]
        );

        if (self::statementWroteNothing($result)) {
            // The media_item was minted but the track row was not written, so the file
            // is not in the library. Charged, not silently dropped (r2 HIGH / S3).
            //
            // ⚠ THE `media_items` ROW DOES NOW CARRY A STAMP — it went in on the INSERT
            // above — AND THAT IS WHY {@see MusicScanSkipIndex::load()} JOINS
            // `music_tracks`. Without that join this exact shape would be a permanent
            // data loss instead of a retry: the next scan would find a matching stamp,
            // skip the file, and never create the missing `music_tracks` row. With it,
            // a `media_items` row that has no track row is not in the index at all, so
            // the file is probed, falls into the reuse branch above, and the loss is
            // retried exactly as it was before this step.
            return 'failed';
        }

        // The CARRIED identity, not a fresh stat, so the in-memory map ends up holding
        // exactly what the row now holds.
        //
        // ⚠ SCOPE OF THIS ONE, STATED HONESTLY: unlike the DB stamp above, this is
        // INVARIANT HYGIENE and not a demonstrated data-loss fix. Measured — mutating it
        // back to `remember($file)` leaves the whole suite GREEN — because the map is
        // only re-consulted for a path the walk reaches TWICE in one scan, and the map is
        // keyed by verbatim path while `RecursiveDirectoryIterator` does not descend
        // symlinks, so no walk yields the same key twice. It is passed anyway because
        // "the map agrees with the row" is the property that makes the map safe for the
        // next reader to consult, and a map that silently disagreed would be a trap.
        $skipIndex?->remember($file, $stamp);

        // ⚠ DELIBERATELY NOT LOGGED — DO NOT RE-ADD A PER-TRACK LINE HERE
        // (review r1 MED-3). There was a `logger->debug('Upserted track', …)` on this
        // line. It was written when this logger wrote into a `sys_get_temp_dir()`
        // directory nobody could read (S96(a)), so its volume was invisible; the moment
        // (a) routed it to `.logs/app.log` (handler level `debug`) it became the
        // dominant content of that file. Measured: 14,585 lines / ≈2.4 MiB from ONE
        // unit-test file's synthetic trees, and ≈61k lines ≈ 10 MiB per full scan of
        // the production library — ~89 % of everything the scan emits, burying the
        // per-album and per-track LOSS lines this step exists to surface.
        //
        // Nothing diagnostic is lost ON THE PATH THAT HAS A JOB ROW — and review r2 F4
        // is right that the claim has to be scoped, because two live callers have no job
        // row at all. Precisely:
        //
        //  * WITH a scan job (the admin UI, the `library-scan` worker — i.e. every scan a
        //    user can start from the app): "is this scan writing?" is answered
        //    authoritatively and continuously by `library_scan_jobs.items_added`, and the
        //    walk position by `current_path`, both written every
        //    `LibraryScanWorker::PROGRESS_WRITE_EVERY` = 25 files. A stall localises to
        //    within 25 files AND NAMES A FILE — strictly better than the deleted line,
        //    which named a file only after it had already succeeded.
        //  * WITHOUT a sink — `POST /api/v1/music/scan` (`WebPortalRouter::
        //    scanMusicDirectory()`) and `php bin/phlix library:scan` — there is no job
        //    row, so the finest granularity left is ONE ALBUM: the per-artist/per-album
        //    `debug` lines below, ≈8.6 files per line at production ratios (61,135 files
        //    across ≈4,959 albums + 2,153 artists). That localises a stall to an album,
        //    not to a file. Both callers are synchronous and operator-initiated, and both
        //    now print/return the counters when they finish, so the case this costs is
        //    narrow: a getID3-over-sshfs stall (the S122 shape) started from one of those
        //    two entry points. The fix for that is to pass a sink from those callers, not
        //    to restore a 61k-line-per-scan success log — recorded as the follow-up.
        //
        // In every case the track itself is durably recorded in `music_tracks`, which is
        // a better record than a log line about it.
        //
        // The per-ARTIST and per-ALBUM debug lines are kept on purpose: their
        // cardinality is bounded by the library's album count (≈5k, ≈1.2 MiB) rather
        // than its file count, and the album is the FLUSH UNIT S95 introduced, so one
        // line per flush boundary is a proportionate trace at the same granularity as
        // the loss lines. The rule this leaves behind: a per-entity line recording
        // SUCCESS is redundant with the row it just committed; a per-entity line
        // recording LOSS is the only record there is, and those all stay (at `error`).

        // F4: the only music-enrichment trigger after native providers were
        // removed — the musicbrainz plugin subscribes MediaItemAdded. Dispatched
        // only for a genuinely-new track insert.
        $this->dispatchMediaItemAdded($mediaItemId, $libraryId, $path, 'track');

        return 'added';
    }

    /**
     * The `metadata_json` fragment recording a file's `(mtime, size)` identity.
     *
     * Empty when the walk could not stat the file — in which case nothing is stamped
     * and the file is simply probed again next time, which is the safe direction.
     *
     * ⚠ Takes the ALREADY-CAPTURED pair rather than an `SplFileInfo` to stat, so that
     * no write site can accidentally re-stat the file at flush time. That was review
     * r1's B1 defect; see {@see self::upsertTrack()}'s `$stamp` parameter.
     *
     * @param array{0: int, 1: int}|null $values `[mtime, size]` as stat'ed by the walk
     *        immediately before the file's tags were read.
     * @return array<string, int> `{file_mtime, file_size}`, or `[]`.
     */
    private static function stampMetadata(?array $values): array
    {
        if ($values === null) {
            return [];
        }

        return [
            MusicScanSkipIndex::KEY_MTIME => $values[0],
            MusicScanSkipIndex::KEY_SIZE => $values[1],
        ];
    }

    /**
     * Records, on an EXISTING `media_items` row, the `(mtime, size)` the file had
     * IMMEDIATELY BEFORE its tags were read — the datum
     * {@see MusicScanSkipIndex::isUnchanged()} decides on. **S122(a).**
     *
     * Only ever called from an outcome that leaves the database consistent with the
     * file just read (`'added'`, `'updated'`, `'skipped'`), never from `'failed'`.
     * That asymmetry IS the cache-validity rule: the stamp asserts "the tags now
     * indexed are this file's tags at this mtime and size", which only a pass that
     * actually read those tags can assert.
     *
     * The pair is an ARGUMENT, stat'ed by the walk one statement above
     * `probeMetadata()`. It is not re-stat'ed here, and re-stat'ing here is the review
     * r1 B1 data-loss defect — see {@see self::upsertTrack()}'s `$stamp` parameter for
     * the window and the reproduction.
     *
     * `JSON_SET` rather than a read-modify-write: one statement, no round trip to
     * fetch the existing document, and no way for two concurrent writers to clobber
     * each other's unrelated keys. `COALESCE(metadata_json, JSON_OBJECT())` is
     * required because `JSON_SET(NULL, …)` returns NULL — the column is nullable and a
     * row written by another code path may legitimately have no document, and without
     * the COALESCE this statement would ERASE `sub_type`/`name` for such a row.
     *
     * ## When the `isStampCurrent()` suppression below can actually fire — MEASURED
     *
     * ⚠ **This docblock used to claim the write is "skipped entirely … whenever the
     * fast path is switched off for the scan (an unhealed row, or an adoptable orphan)
     * but the files themselves are unchanged", and that "keeps the exceptional, slow
     * scan from also issuing 61,135 pointless UPDATEs". That was FALSE, and review r1
     * B2 measured it false.** {@see self::scanDirectory()} calls
     * {@see MusicScanSkipIndex::load()} when `$readEveryFile || (!$mayAdopt &&
     * !$needsHealing)` — the `$readEveryFile` disjunct is S148's and is what makes case
     * 2 below possible. So on an ORDINARY scan of a library with something to heal, i.e.
     * exactly the two scans that claim named, the index is still EMPTY,
     * `isStampCurrent()` returns false for every file, and that slow scan issues one
     * `JSON_SET` per file after all. Measured on 5 unchanged files with an unhealed
     * `music_artists` row: **5 probes and 5 `JSON_SET` UPDATEs**, now pinned by
     * {@see \Phlix\Tests\Unit\Media\Music\MusicScanUnchangedSkipTest::testWithTheFastPathOffEveryUnchangedFileIsStillProbedAndStillReStamped()}.
     *
     * The suppression covers TWO paths, and S148 added the second:
     *
     *  1. **The mid-walk flip.** The index WAS loaded (healthy library, fast path on)
     *     and `$mayAdopt` then flipped to TRUE mid-walk because a caught write failure
     *     left an unreferenced `media_items` row. From that point
     *     {@see self::canSkip()} answers false, so the remaining unchanged files are
     *     probed even though the index still holds their current identity — and each of
     *     those probes would otherwise issue an UPDATE that changes nothing. Measured on
     *     the 40-album flip fixture: **40 files probed, 32 stamp UPDATEs issued** (1 file
     *     lost with the album whose write was made to fail, 7 suppressed by this check),
     *     pinned by {@see
     *     \Phlix\Tests\Unit\Media\Music\MusicScanUnchangedSkipTest::testTheStampSuppressionFiresOnTheMidWalkFlipPath()}.
     *  2. 🔑 **The full-read healing rescan — S148, and the big one.**
     *     {@see self::scanDirectory()}'s `$readEveryFile` mode now LOADS the index and
     *     gates {@see self::canSkip()} instead, precisely so that this check can fire on
     *     every unchanged file of a scan that deliberately reads all of them. Before
     *     S148 the mode was implemented by not loading the index, so a healing pass over
     *     the production library issued **61,135 `JSON_SET` UPDATEs that changed
     *     nothing** — one per file, on a multi-hour job. Pinned by
     *     {@see \Phlix\Tests\Integration\Media\MusicScanWriteAmplificationIntegrationTest}
     *     against real MySQL, because the number that matters is "how many UPDATEs did
     *     the server receive" and an in-memory double cannot be asked that question about
     *     production.
     *
     * The heal/adopt gate on {@see MusicScanSkipIndex::load()} still stands for the
     * ORDINARY scan: there, a loaded index plus a mistaken `canSkip()` is how S96(e)'s
     * heal stops happening, and the load's measured **36.74 MiB transient / 10.90 MiB
     * retained** (see {@see MusicScanSkipIndex}) would be spent for nothing. In the
     * full-read mode `canSkip()` is hard-false for the whole scan, so no loaded entry can
     * suppress a READ and the objection does not apply.
     *
     * Failure is swallowed at `debug`: an unwritten stamp costs one file's read on the
     * next scan and nothing else, so it must not turn a healthy per-track outcome into
     * a `'failed'` — the counter S96(f) built means "this file is not in the library",
     * and a file with a stale stamp certainly is.
     *
     * @param string $mediaItemId Existing `media_items.id` for the file.
     * @param SplFileInfo $file Audio file just read (for the map key and the log line).
     * @param MusicScanSkipIndex|null $skipIndex Index to keep in step, or NULL to do
     *        nothing at all (the legacy construction sites).
     * @param array{0: int, 1: int}|null $values `[mtime, size]` captured by the walk
     *        before the read. NULL means "not stat'able", so nothing is written.
     * @return void
     */
    private function stampFileIdentity(
        string $mediaItemId,
        SplFileInfo $file,
        ?MusicScanSkipIndex $skipIndex,
        ?array $values
    ): void {
        if ($skipIndex === null || $mediaItemId === '') {
            return;
        }

        if ($skipIndex->isStampCurrent($file, $values)) {
            return;
        }

        $stamp = self::stampMetadata($values);
        if ($stamp === []) {
            return;
        }

        try {
            $this->db->query(
                "UPDATE media_items SET metadata_json = JSON_SET(COALESCE(metadata_json, JSON_OBJECT()),"
                . " '$." . MusicScanSkipIndex::KEY_MTIME . "', ?,"
                . " '$." . MusicScanSkipIndex::KEY_SIZE . "', ?) WHERE id = ?",
                [$stamp[MusicScanSkipIndex::KEY_MTIME], $stamp[MusicScanSkipIndex::KEY_SIZE], $mediaItemId]
            );
        } catch (\Throwable $e) {
            $this->logger->debug('Could not record the file identity for a track', [
                'media_item_id' => $mediaItemId,
                'path' => $file->getPathname(),
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $skipIndex->remember($file, $values);
    }

    /**
     * Finds the `media_items.id` for a track already indexed at this file path
     * within the given library, or NULL when none exists.
     *
     * The scoping mirrors the `(library_id, path_hash)` unique index (migrations
     * 072/087): a track's identity is its path inside its owning library.
     *
     * ## S151 — WHY THE HASH PREDICATE IS HERE, AND WHY `path = ?` STAYS
     *
     * `media_items.path` has **no b-tree index** and cannot get one: it is
     * `varchar(1000) utf8mb4` = 4,000 bytes, over InnoDB's 3,072-byte key limit on
     * its own. Without the hash predicate the planner can only use the FIRST column
     * of `idx_media_items_library_path_hash` — `library_id`, cardinality **3** — and
     * then hand-filters the rest. Measured on the production library (warm,
     * `SQL_NO_CACHE`, 4 identical repeats):
     *
     * | form                            | EXPLAIN type | key_len | rows examined | duration            |
     * |---------------------------------|--------------|---------|---------------|---------------------|
     * | `path = ?` alone (pre-S151)     | `ref`        | 144     | **48,512**    | 0.714–0.864 s       |
     * | `path_hash = ? AND path = ?`    | **`const`**  | 305     | **1**         | 0.00022–0.00052 s   |
     *
     * This method runs **once per audio file** — 61,122 times on that library — so the
     * pre-S151 form cost ≈13 h of pure query time and was the DOMINANT cost of a music
     * scan, ahead of the tag reading everyone assumed was the bottleneck.
     *
     * ⚠ **MySQL will NOT substitute the index for you.** Generated-column index
     * substitution fires only when the WHERE clause contains the column's *exact*
     * generating expression. `path = '…'` never triggers it, which is how a column that
     * exists, is indexed and is ~3,500× faster sat unused. Verified by `EXPLAIN`, not
     * assumed — see `tests/Integration/Media/Music/MusicTrackPathHashLookupTest`, which
     * EXPLAINs the statement this method actually emits. (`PathHashIndexUsageTest` is a
     * different, pre-existing test covering {@see \Phlix\Media\Library\ItemRepository}.)
     *
     * ⚠ **THE `const` PLAN REQUIRES THE `idx_media_items_library_path_hash` UNIQUE
     * INDEX, AND THE MIGRATION CHAIN DOES CREATE IT — as of S152, not before.**
     * Migration 087 (087:59-60) still does `DROP INDEX
     * idx_media_items_library_path_hash`, but `migrations/096_path_hash_unique_index.sql`
     * sorts after it and re-adds the index from inside
     * `scripts/run-migrations.php`. Re-measured on a database built by the chain
     * ALONE (MySQL 8.0.46, 2026-08-02): `const` / key_len 305 / rows 1.
     *
     * 🔴 **This paragraph used to say the exact opposite, and until 096 it was
     * TRUE** — the index was re-added only by `migrations/cleanup_072.php`
     * (now cleanup_072.php:147-153), a MANUAL post-deploy finalizer, so a fresh
     * install planned exactly as the pre-S151 form did (`ref` / `idx_library` /
     * key_len 144 / rows ≈ 404) while production, where the `const` plan was
     * measured, looked correct. A measurement on one environment proved nothing
     * about the other; that split WAS the S152 defect. `cleanup_072.php` now owns
     * de-duplication only — do not move index creation back out of the chain.
     *
     * ⚠ **`path = ?` is NOT redundant and must not be removed.** The row is already
     * being fetched by the time it is evaluated, so it is free, and it turns "a SHA-1
     * collision is unlikely" into "a SHA-1 collision cannot return the wrong row".
     *
     * ⚠ **`path_hash` is NULL for 7 of the 13 `type` ENUM members** — its generating
     * expression (migration 087) covers only `episode, movie, audio, book, track,
     * audiobook`. This call site is safe **because the statement itself pins
     * `type = 'track'`**, a covered member (measured on production:
     * `SUM(path_hash IS NULL) = 0` across all 61,122 track rows). Do NOT copy this
     * predicate onto a lookup whose type is dynamic or is one of the uncovered members
     * (`series, season, album, artist, music, video, photo`) — there it would return a
     * fast WRONG answer (matching nothing) instead of a slow right one. That is why the
     * sibling artist/album lookups in this class were deliberately left alone.
     *
     * ⚠ PHP `sha1()` hashes the raw string bytes; MySQL's `SHA1()` hashes the column's
     * utf8mb4 bytes. They agree only while PHP hands over UTF-8 — which it does, paths
     * come from the filesystem verbatim. An ASCII-only fixture cannot tell the two
     * behaviours apart, so the guarding test uses non-ASCII paths (`Sigur Rós`,
     * `Björk`, CJK) against real MySQL.
     *
     * ## ⚠ TWO PASSES, LIKE {@see \Phlix\Media\Library\ItemRepository::findByPath()}
     *
     * Pass 1 is the hash lookup above. Pass 2 repeats the search on the raw `path`,
     * and it is NOT optional (review finding 2): `path_hash` is a GENERATED column, so
     * a hash-only lookup is silently blind on any database where migration 087 has not
     * run, or ran only in part. 087 is two statements — `DROP INDEX` then
     * `MODIFY COLUMN` — a migration that fails is not recorded by the runner, and
     * `docker/docker-entrypoint.sh:9` runs migrations with `|| true`; so "072 applied,
     * 087 not" is a reachable state, and in it every `track` row has
     * `path_hash IS NULL`. `NULL = <hash>` is never true, so pass 1 would miss EVERY
     * file, the caller would fall through to `createMediaItem('track', …)` on each one,
     * and nothing would catch it. ⚠ Note the reason carefully: the runner is
     * CONTINUE-AND-REPORT, so a failed 087 does NOT stop
     * `migrations/096_path_hash_unique_index.sql` from running and re-adding the unique
     * index. The index is therefore present — it just does not constrain anything here,
     * because `track` is outside migration 072's `CASE` list (072:40 covers only
     * `episode, movie, audio, book`; 087:66 is what adds `track, audiobook`). Every
     * track's `path_hash` stays NULL, and MySQL never collides NULLs under a UNIQUE
     * index. That is a duplicated
     * track library (61 k rows on the reference install), from a deployment ordering
     * accident. The pre-S151 raw lookup was immune to the state of `path_hash`; this
     * keeps that immunity while still taking the fast path whenever the hash is there.
     *
     * ⚠ **Pass 2 costs nothing on the workload S151 was measured on, and it CANNOT
     * regress the pre-S151 baseline.** It runs only when pass 1 MISSES, i.e. only for
     * a file this library has never indexed. On a rescan — the 9 h 55 m case this step
     * exists to fix — every lookup hits pass 1 and pass 2 never executes at all. On a
     * FIRST scan every lookup misses, so each file pays one fast index probe plus the
     * same unindexed `path = ?` scan it paid before S151: strictly the pre-S151 cost
     * plus a `const` lookup, never worse than the code being replaced.
     *
     * @param string $path Absolute filesystem path of the audio file.
     * @param string|null $libraryId Owning library UUID (null-safe matched).
     * @return string|null Existing media_items UUID, or null when unseen.
     */
    private function findExistingTrackMediaItemId(string $path, ?string $libraryId): ?string
    {
        // Pass 1 — the indexed point lookup this step exists to introduce.
        $existing = $this->firstMediaItemId($this->db->query(
            "SELECT id FROM media_items"
            . " WHERE type = 'track' AND path_hash = ? AND path = ? AND library_id <=> ? LIMIT 1",
            [sha1($path), $path, $libraryId]
        ));

        if ($existing !== null) {
            return $existing;
        }

        // Pass 2 — raw-path fallback. Correctness must not depend on a migration
        // having completed; see the docblock. Reached only on a miss.
        return $this->firstMediaItemId($this->db->query(
            "SELECT id FROM media_items"
            . " WHERE type = 'track' AND path = ? AND library_id <=> ? LIMIT 1",
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
     * two clean rescans). ⚠ Getting there takes something other than this scanner
     * running normally — but NOT for the reason an earlier revision of this paragraph
     * gave ("the scanner alone cannot produce that state; an interruption re-adopts
     * the SAME orphan rather than minting a second"). That was wrong: a write failure
     * between the mint and the `music_artists` INSERT is CAUGHT, the scan carries on,
     * and it meets the same artist again a few albums later. What actually holds the
     * property is that adoption is forced back ON for the rest of the scan the moment
     * a mint is left unreferenced ({@see self::upsertArtist()}'s `finally`) and
     * re-probed at the start of the next one
     * ({@see self::hasAdoptableMusicMediaItem()}), so the second encounter ADOPTS the
     * first orphan instead of minting a rival. A concurrent writer, manual surgery,
     * or case (b) below can still produce it. (b) The
     * lookup is scoped `mi.library_id <=> ?` while `music_artists` has NO
     * `library_id`, so an orphan minted in library L1 is leaked permanently once ANY
     * other library creates the `music_artists` row for that name (measured: scan L2,
     * then rescan L1 → `media_items[artist] = 2` against `music_artists = 1`). That
     * needs two music libraries sharing an artist, which prod does not have.
     * (c) An orphan from a mint the server COMMITTED but
     * {@see self::createMediaItem()} REPORTED as failed (it swallows its own
     * Throwable and returns `''`). ✅ **CLOSED BY S96(e).** It used to be
     * unreclaimable BY CONSTRUCTION, not merely unreclaimed: the `music_artists` row
     * was written for the natural key with `media_item_id = NULL`, so the natural-key
     * branch of {@see self::upsertArtist()} found that row on every later encounter,
     * returned its NULL and short-circuited BEFORE this lookup was reached — no scan,
     * however clean, could route to the adoption path for that name again. (The
     * `finally` there re-opens the `$mayAdopt` FLAG, so the rest of the scan keeps
     * hunting for OTHER orphans, but it never reclaimed THIS row.) Measured with a
     * commit-then-throw fault at every statement position of a two-artists /
     * one-album-title fixture: an orphan was left at 3 of 27 positions and survived
     * two clean rescans. That NULL is now healed by
     * {@see self::backfillMusicMediaItemId()}, which is driven from INSIDE the
     * natural-key branch and calls this very lookup first — so the committed orphan is
     * adopted rather than joined by a rival. The album twin is the same mechanism with
     * `music_albums` + {@see self::upsertAlbum()}, and was measured on both sides
     * (2 of the 3 positions are artist mints, 1 is an album mint).
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
     * ⚠ `AND mi.parent_id IS NULL` IS AN **ENFORCED INVARIANT**, NOT A NO-OP FILTER
     * (S97). It used to read `(mi.parent_id IS NULL OR mi.parent_id = ?)`, scoped by
     * the album's artist, because S97 was still expected to parent these rows. **S97
     * decided the opposite and the decision is settled: the `music_*` tables are the
     * one authoritative music hierarchy, and `media_items.parent_id` is NEVER written
     * for `artist` / `album` / `track`.** (Verified read-only on production: artist
     * 4,656 / album 10,966 / track 61,105 rows, **0 of 76,727 carrying a
     * `parent_id`**, matching `music_*` exactly. Reasoning in
     * `plan_updates_worklog.md`, 2026-07-27.)
     *
     * So the predicate no longer scopes anything — it **asserts**. Read it as:
     * *a music `media_items` row must never carry a parent; a row that does is not
     * one of ours, so it is not adoptable.* Two artists sharing an album title
     * (`Greatest Hits`) are still handled correctly without artist scoping, exactly
     * as they are today: an album's `media_items` row carries nothing
     * artist-specific (`path = ''`, `metadata_json = {sub_type, name}`), so `title`
     * alone picks a row that is indistinguishable from a freshly minted one and the
     * counts stay exact (measured: two artists × `Greatest Hits` → 2 albums /
     * 2 `media_items[album]`).
     *
     * **The new bound, stated deliberately** (the S95 review asked for it to be
     * re-derived rather than inherited): correctness here needs *"the orphan I adopt
     * is interchangeable with a row I would mint right now"*. The old artist-scoped
     * form bought that under a hypothetical future in which `parent_id` distinguished
     * album rows. Under S97's actual verdict nothing distinguishes them — every
     * album `media_items` row is `{type:'album', name:<title>, path:'',
     * library_id:<lib>, parent_id:NULL}` — so title + library + unreferenced is
     * already the complete identity, and the parent check is pure defence: it fails
     * **SAFE** (mint a fresh row) against any row some other writer parented, and it
     * is the tripwire that would catch a regression re-introducing music parenting.
     * `ma.id IS NULL` cannot do that job: a mis-parented row genuinely is
     * unreferenced.
     *
     * ⚠ If a future step ever revisits this and DOES write `parent_id` for music, it
     * must re-add artist scoping **and** write the column in the SAME `INSERT` that
     * creates the row — never a second statement — or the window between them hands
     * an unparented row to whichever artist reaches it first. That constraint is why
     * option A was rejected on the adoption path; see the worklog before touching it.
     *
     * @param string $title Album title, as stored in `media_items.name`.
     * @param string|null $libraryId Owning library UUID (null-safe matched).
     * @return string|null An adoptable media_items UUID, or null when none exists.
     */
    private function findAdoptableAlbumMediaItemId(
        string $title,
        ?string $libraryId
    ): ?string {
        return $this->firstMediaItemId($this->db->query(
            "SELECT mi.id
               FROM media_items mi
               LEFT JOIN music_albums ma ON ma.media_item_id = mi.id
              WHERE mi.type = 'album' AND mi.name = ? AND mi.path = ''
                AND mi.library_id <=> ? AND ma.id IS NULL
                AND mi.parent_id IS NULL
              LIMIT 1",
            [$title, $libraryId]
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
     * THE ANSWER IS TAKEN ONCE, BEFORE THE WALK — SO IT MUST BE ABLE TO FAIL OPEN.
     * Enumerating what can create an orphan *after* this query has run, because
     * getting that list wrong is precisely how the gate first shipped with a hole:
     *
     *  1. **This scan's own CAUGHT write failure — the one that matters.**
     *     {@see self::upsertArtist()} / {@see self::upsertAlbum()} mint the
     *     `media_items` row one autocommitted statement BEFORE the matching `music_*`
     *     row, and a failure in between is caught by {@see self::flushAlbum()}, which
     *     logs "Skipping album …" and lets THE SCAN CARRY ON. The same artist name
     *     (≈2.3 albums per artist on prod) or the same chunked album key then recurs
     *     later in the SAME scan, finds nothing on the natural key, and — with
     *     adoption still switched off — mints a rival row while the first becomes
     *     permanently unreachable. Both methods therefore set `$mayAdopt` back to TRUE
     *     by reference, up through `flushAlbum()` into
     *     {@see self::scanDirectory()}'s local, whenever they leave a minted row
     *     unreferenced. That is what makes taking the answer once safe; without it,
     *     measured on real MySQL: `media_items[artist] = 2` against
     *     `music_artists = 1`, surviving two clean rescans.
     *  2. **This scan's own CRASH** — irrelevant, the scan is over, and the next scan
     *     re-probes this gate.
     *  3. **A CONCURRENT writer** — not reclaimed until the next scan, one cycle
     *     later, exactly like every other adoption.
     *  4. **A failed mint whose row the server nevertheless committed**
     *     (`createMediaItem()` reports '' but the INSERT landed) — the same `finally`
     *     as (1) DOES fire, because "referenced" is defined as *the `music_*` row
     *     carries the minted id*, not merely *the INSERT succeeded*. ⚠ Be precise
     *     about what that buys, because an earlier revision of this item said only
     *     "covered by the same `finally` as (1)" and that collapsed two true halves
     *     into one false claim. The `finally` re-opens the `$mayAdopt` FLAG, so the
     *     rest of the scan resumes looking for orphans — which is what keeps the gate
     *     safe. It does NOT reclaim THIS row by itself: the `music_*` row now holds
     *     the natural key with `media_item_id = NULL`, so every later lookup
     *     short-circuits on the natural key before the adoption query is reached
     *     (measured: an orphan is left at 3 of 27 commit-then-throw positions and
     *     survived two clean rescans). ✅ S96(e) closed that: the natural-key branch
     *     now calls {@see self::backfillMusicMediaItemId()}, which issues the adoption
     *     lookup from inside the short-circuit and hands the committed orphan to the
     *     row that should have had it — see case (c) of the RESIDUE list in
     *     {@see self::findAdoptableArtistMediaItemId()}.
     *
     * Note that the track path needs none of this: an orphaned `track` row is found
     * by PATH ({@see self::findExistingTrackMediaItemId()}), which is indexed, never
     * gated, and reused by {@see self::upsertTrack()} — the track window is
     * self-healing on every pass.
     *
     * `library_id <=> NULL` matches nothing (the column is NOT NULL), so the legacy
     * no-library scan path skips both lookups outright, which is what it effectively
     * did anyway.
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
     * @param array<string, int> $extraMetadata Additional `metadata_json` keys merged
     *        in — S122(a) uses it for `file_mtime`/`file_size`, so a new track's
     *        identity is recorded by the SAME statement that creates the row rather
     *        than a follow-up UPDATE. `sub_type` and `name` are written LAST and
     *        therefore cannot be overwritten by a caller.
     * @return string The media_item UUID ('' on failure)
     */
    private function createMediaItem(
        string $subType,
        string $name,
        ?string $path = null,
        ?string $libraryId = null,
        array $extraMetadata = []
    ): string {
        $type = $subType;
        // The two authoritative keys are assigned unconditionally, so a caller can
        // never override them. (Review r1 non-blocking 4: this used to be a `+` union
        // with the same two keys followed by these two assignments, which made the
        // union dead code and implied it was doing something.)
        $metadata = $extraMetadata;
        $metadata['sub_type'] = $subType;
        $metadata['name'] = $name;
        $id = Uuid::v4();

        try {
            $result = $this->db->query(
                "INSERT INTO media_items (id, library_id, type, name, path, metadata_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [$id, $libraryId, $type, $name, $path ?? '', json_encode($metadata)]
            );

            // ⚠ `statementWroteNothing()`, not `!$result`: a successful INSERT into
            // `media_items` returns the string `'0'` (UUID primary key, no
            // AUTO_INCREMENT — measured), so a truthiness test here would report every
            // successful mint as a failure. It also catches the `null` this client
            // really can return, which the old `=== false` could not (r2 F1).
            if (self::statementWroteNothing($result)) {
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
