<?php

/**
 * Phlix media server component: Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Music;

use Psr\Log\LoggerInterface;
use SplFileInfo;
use Workerman\MySQL\Connection;

/**
 * The `(mtime, size)` identity of every already-indexed music file in one
 * library, so a rescan can decide **without opening the file** that nothing
 * changed. **S122(a).**
 *
 * ## Why this exists — the measurement, not a hunch
 *
 * The first-ever completed scan of the production music library
 * (`steps/vault-sshfs-read-perf-diagnostic.worklog.md`) ran at **2.78 files/s =
 * 360 ms/file ⇒ 6.1 hours** for 61,135 files, and the job row said
 * `items_added = 0`, `items_updated = 6,550`: **54,585 of 61,135 files were
 * unchanged and were re-read in full anyway.** Each of those re-reads costs
 * **568 KB across ~60 scattered `fread`/`fseek` regions** through getID3, over
 * an sshfs mount whose backing store is a single rotational spindle at
 * `r_await` 10.58 ms. Not reading an unchanged file at all is therefore the
 * single largest lever in the whole music cluster — larger than every mount
 * option and remote sysctl in that diagnostic combined, all of which have since
 * been applied and none of which touch this.
 *
 * ## THE PREDICATE, and its failure mode — READ THIS BEFORE CHANGING IT
 *
 * **Skip iff the file's `mtime` AND its `size` are both byte-identical to the
 * values the file had IMMEDIATELY BEFORE this scanner last read its tags.**
 *
 * ⚠ **"IMMEDIATELY BEFORE" — not "at the moment the tags were parsed", and
 * emphatically not "at the moment the row was written". That asymmetry IS the
 * safety argument, and getting it wrong was a data-loss defect (review r1 B1).**
 * {@see MusicLibraryScanner::scanDirectory()} stat's the file via
 * {@see self::stampValues()} one statement ABOVE `probeMetadata()` and then
 * CARRIES those two integers through the album buffer into the write; nothing
 * downstream re-stat's the file. The version that stat'ed at write time was
 * measurably wrong: the probe→flush window is at least
 * {@see MusicLibraryScanner::MAX_OPEN_ALBUMS} albums (≈400 files at production
 * ratios) and is unbounded for an album whose tracks are spread across the tree —
 * S95 groups on TAG identity precisely so a multi-directory album stays in ONE
 * flush — so an ORDINARY tag write landing inside that window was stamped with
 * the POST-edit identity against PRE-edit tags, and every later scan then skipped
 * the file forever.
 *
 * Stamping the PRE-read identity cannot do that. An edit that races the read
 * leaves the stamp OLDER than the content, so the next scan compares a stale
 * `(mtime, size)` against the new one, sees a mismatch, and re-reads. **The error
 * direction is a redundant read, never a missed change** — which is the only
 * direction a cache predicate is allowed to be wrong in.
 *
 * A skip predicate is a CACHE. A wrong one does not make the scan slow, it makes
 * it **silently miss a real change**, which is strictly worse. So the failure
 * mode is stated rather than implied:
 *
 *  - ❌ **An in-place edit that preserves BOTH mtime and size is missed** —
 *    "preserves" meaning "relative to the stat taken just before the last read".
 *    Concretely: a tag editor that rewrites a padded ID3v2 frame region to the
 *    same byte length and then restores the original mtime (`touch -r`), a
 *    restore-from-backup with `--preserve=timestamps` over different content, or
 *    two writes inside one mtime tick where the second restores the original
 *    size. Every one of those requires the writer to deliberately preserve the
 *    timestamp; an ordinary tag write moves mtime.
 *  - ✅ **mtime OR size differing is enough** to force a re-read — the predicate
 *    is a conjunction of equalities, so either half failing means "changed".
 *  - ✅ Escape hatch: the recorded identity lives in
 *    `media_items.metadata_json`, so `UPDATE media_items SET metadata_json =
 *    JSON_REMOVE(metadata_json, '$.file_mtime', '$.file_size') WHERE library_id
 *    = '…' AND type = 'track'` forces a full re-probe of a library on the next
 *    scan, and deleting the library's rows forces a first-scan. Both are
 *    operator-reachable without a code change and without touching the files.
 *
 * Why not a content hash: reading enough of the file to hash it IS the 568 KB
 * read this class exists to avoid. Why not mtime alone: a truncating or padding
 * write that lands inside one mtime tick would be missed, and `size` is free —
 * the same `stat()` returns both.
 *
 * ## Why the full path is the map key, and not `md5($path, true)`
 *
 * A 16-byte binary digest would save ≈40 bytes per entry, and it would also
 * introduce a way for the scanner to skip *the wrong file*: two colliding paths
 * would share one `(mtime, size)` record. The probability is astronomically
 * small, but the CONSEQUENCE is the exact class of bug this docblock is written
 * to prevent — a silent miss — and 2.4 MB across a 61,135-file library is not
 * worth buying it. The key is the verbatim path.
 *
 * ## Memory: the RETAINED map is bounded, the load's TRANSIENT peak is not
 *
 * One `SELECT` per scan loads the library's whole track identity map, which is
 * O(library size) — the shape S95 removed from the scan buffer. It is affordable
 * because an entry is a path plus one short scalar string rather than S95's
 * 1,463-byte `['file' => SplFileInfo, 'meta' => [...]]`. Two figures, both
 * measured on PHP 8.3.6 at the real production library size (61,135 rows) with
 * production-shaped 56-character path keys — but they are NOT equally exact:
 *
 *  - **The RETAINED figure reproduces to the byte.** It is a
 *    `memory_get_usage()` delta over a map this process is the sole owner of,
 *    and it is the only half the test asserts.
 *  - **The TRANSIENT peak is APPROXIMATE and BASELINE-DEPENDENT.**
 *    `memory_get_peak_usage()` is process-wide and monotonic, so what a run
 *    reports depends on what the process had already allocated before the load:
 *    review r2 re-measured the same load at 38,609,696 B peak-vs-peak and
 *    40,303,064 B peak-vs-usage. Read it as "≈37 MiB, ≈3.4x the retained map",
 *    never as an exact quantity — and note that nothing asserts it (see below).
 *
 * | quantity | measured |
 * |---|---|
 * | RETAINED by the map once `load()` has returned (exact) | **11,424,960 B = 10.90 MiB = 186.9 B/entry** |
 * | TRANSIENT peak INSIDE `load()` (approximate) | **≈38,527,368 B ≈ 36.74 MiB** |
 *
 * ⚠ **The figure this docblock used to quote — "5,556,000 bytes = 5.30 MB, i.e.
 * 90.9 bytes/entry" — is ≈2x too low, and it was an artefact of HOW it was
 * measured (review r1 B3).** It reproduces exactly, but only when the path strings
 * are allocated by the caller BEFORE the measurement starts, so the map's keys are
 * shared by refcount with the row set and are invisible to the delta. Production
 * never has that condition: `$rows` dies with `load()` and the map is then the sole
 * owner of every key. Three claims that rested on the wrong number are corrected
 * with it: the retained map is **the same size as** the ≈11.1 MB open-album ceiling
 * S95 already accepts, not half of it; an entry is **7.8x** cheaper than the
 * 1,463 B entry S95 removed (≈89 MB for this library), not 16x; and
 * {@see self::MAX_ENTRIES} is **≈44.3 MiB**, not ≈21.7 MB. The DESIGN survives the
 * correction — 10.9 MiB in a scan worker is still bounded and still affordable —
 * only the arithmetic and the measurement method were wrong.
 *
 * ⚠ **{@see self::MAX_ENTRIES} bounds RETENTION ONLY.** The driver materialises the
 * whole result set before this class can refuse an entry, so the transient `$rows`
 * is 2.4x the retained map (≈25.84 MiB of row arrays for 61,135 rows) and it scales
 * with the LIBRARY, not with the cap: measured at exactly 250,000 rows, retention
 * stops at 44.33 MiB (exact) while the load peaks at **≈153.54 MiB** — a peak, so
 * it carries the baseline-dependence caveat stated above. Bounding that too
 * would mean chunking the SELECT, which buys nothing at any library size that
 * exists and is therefore not done — but the claim made here is "retention is
 * bounded by a constant", not "memory is".
 *
 * Both retained figures are pinned by
 * `MusicScanSkipIndexTest::testMemoryPerEntryIsBounded()`, which builds its rows
 * INSIDE the connection double so that nothing outside the map holds a path string
 * — the whole point of B3. Overflowing the cap degrades to "probe the file", which
 * is CORRECT and merely slower.
 *
 * The alternative — replacing this one bulk load with one `SELECT` per file — was
 * rejected because the lookup key would be `media_items.path`, which has **no
 * b-tree index** and cannot get one (`varchar(1000)` utf8mb4 = 4,000 bytes, over
 * InnoDB's 3,072-byte key limit on its own; migration 001 gives `media_items` only
 * `FULLTEXT idx_name`). The only usable point key is `(library_id, path_hash)`,
 * which is added out-of-band by `migrations/cleanup_072.php` and may legitimately
 * be absent. One statement against `idx_media_items_library_type` (migration 011)
 * has a cost that can be reasoned about; 61,135 statements whose plan depends on
 * whether an optional post-migration script was ever run does not.
 *
 * ⚠ **S151 changed the ARITHMETIC of that trade-off, not the conclusion.** The
 * per-file lookup this class exists to avoid ALSO runs, unavoidably, inside
 * {@see \Phlix\Media\Music\MusicLibraryScanner::findExistingTrackMediaItemId()} for
 * every file the skip index does not skip — and until S151 it was `ref` over ~48.5k
 * rows (0.71–0.86 s each, measured on production), which made it the dominant cost
 * of a music scan. It is now a `const`/`rows=1` lookup through the same
 * `(library_id, path_hash)` index. That does NOT make this bulk load redundant: the
 * skip index avoids OPENING the file, which is a different and larger cost, and it
 * still degrades gracefully to "probe the file" when the optional index is missing.
 *
 * ## Resident-memory note
 *
 * Nothing here is `static`. One instance belongs to one `scanDirectory()` call
 * and is dropped with it, so a long-lived scan worker retains nothing between
 * jobs. {@see self::reset()} exists for the reuse case.
 *
 * @package Phlix\Media\Music
 * @since 1.2.0
 */
final class MusicScanSkipIndex
{
    /**
     * JSON keys inside `media_items.metadata_json` holding the recorded identity.
     *
     * These names are NOT invented here — `AudioScanner::…` (`:218-219`) and
     * `MusicLibraryManager` (`:334-335`) already write `file_size` / `file_mtime`
     * into the metadata of the rows THEY create, so this is the established
     * convention for exactly this datum and a `media_items` column ALTER is not
     * needed. Reusing the convention also means the two sibling scanners' rows
     * are readable by this index for free.
     */
    public const KEY_MTIME = 'file_mtime';

    /** @see self::KEY_MTIME */
    public const KEY_SIZE = 'file_size';

    /**
     * Hard ceiling on retained entries.
     *
     * 250,000 is ≈4x the 61,135-file production music library, so the cap is
     * never reached in practice; it exists so that RETAINED memory is bounded by a
     * CONSTANT rather than by however large a library grows. Measured at exactly
     * 250,000 entries on PHP 8.3.6: **46,485,840 B = 44.33 MiB retained**
     * (185.9 B/entry — the same per-entry cost as the 61,135-entry case, see the
     * class docblock). The pre-r1 docblock said ≈21.7 MB; that came from the
     * understated 90.9 B/entry figure and was wrong by the same ≈2x.
     *
     * ⚠ It does NOT bound the load's transient peak — the result set is
     * materialised in full before an entry can be refused, measured at **153.54
     * MiB** for 250,000 rows. See the class docblock.
     *
     * Overflow is safe by construction: a file with no entry is simply probed,
     * which is precisely today's behaviour. The cap can only ever make the scan
     * slower, never wrong.
     */
    public const MAX_ENTRIES = 250_000;

    /**
     * Recorded identity by verbatim absolute path.
     *
     * Value shape is `"<mtime>:<size>"` — one short interned-ish scalar rather
     * than a two-element array, which halves the per-entry bucket overhead. It
     * is compared as a string, never parsed back, so no numeric coercion can
     * make two different identities compare equal.
     *
     * @var array<string, string>
     */
    private array $entries = [];

    /** Whether {@see self::load()} has run for this instance. */
    private bool $loaded = false;

    /** Whether {@see self::MAX_ENTRIES} stopped the load short. */
    private bool $truncated = false;

    /**
     * @param Connection      $db     Database connection.
     * @param LoggerInterface $logger Shared MEDIA-channel logger — the same one
     *        {@see MusicLibraryScanner} resolves, so a truncated or failed load
     *        is visible in `.logs/app.log` rather than nowhere (S96(a)).
     */
    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Loads every already-indexed track identity for one library, in ONE query.
     *
     * A NULL `$libraryId` loads NOTHING and leaves the index empty, so the
     * legacy no-library scan path (`POST /api/v1/music/scan`) keeps exactly its
     * current behaviour: `media_items.library_id` is `NOT NULL`, so there is no
     * row such a scan could match, and issuing `library_id <=> NULL` would be a
     * full-table scan that provably returns nothing.
     *
     * The statement is `library_id = ? AND type = 'track'` — the leading-column
     * prefix of `idx_media_items_library_type` (migration 011) — and NOT
     * `library_id <=> ?`, so the plan does not depend on the optimizer's
     * treatment of the null-safe operator.
     *
     * ⚠ **THE `JOIN music_tracks` IS LOAD-BEARING. IT IS NOT AN OPTIMISATION AND
     * DELETING IT TURNS A RETRY INTO PERMANENT DATA LOSS.** "Indexed" has to mean
     * *both* rows exist, because the two are written by separate autocommitted
     * statements: {@see MusicLibraryScanner::upsertTrack()} mints the `media_items`
     * row — carrying the stamp, in the same INSERT — and only then inserts into
     * `music_tracks`. If that second statement writes nothing, the file is NOT in the
     * library, yet a `media_items`-only index would hold a matching stamp for it,
     * skip it on the next scan, and never create the missing track row. The join makes
     * such a row invisible here, so the file is probed, takes
     * `upsertTrack()`'s reuse branch, and the loss is retried exactly as it was before
     * S122. `music_tracks.media_item_id` is `NOT NULL UNIQUE`
     * (`migrations/065_music_library.sql:74` — review r1 non-blocking 3; this used to
     * cite migration 011, which contains only the `idx_media_items_library_type` index
     * quoted above and no `music_tracks` table at all), so the join is an index lookup,
     * and its `album_id`/`artist_id` are `NOT NULL` FKs — so a joined row also proves
     * the artist and album rows exist.
     *
     * FAILS OPEN, deliberately: a throw here leaves the index empty, which makes
     * every file "unknown" and therefore probed. That is today's behaviour, so a
     * transient DB error costs a slow scan and never a wrong one. It must not
     * abort a scan that has nothing else wrong with it — the same rule
     * {@see MusicLibraryScanner::scanDirectory()}'s orphan-adoption gate follows,
     * and for the same measured reason.
     *
     * @param string|null $libraryId Owning library UUID.
     *
     * @return void
     */
    public function load(?string $libraryId): void
    {
        $this->reset();
        $this->loaded = true;

        if ($libraryId === null || $libraryId === '') {
            return;
        }

        try {
            $rows = $this->db->query(
                "SELECT mi.path AS path,"
                . " mi.metadata_json->>'$." . self::KEY_MTIME . "' AS file_mtime,"
                . " mi.metadata_json->>'$." . self::KEY_SIZE . "' AS file_size"
                . " FROM media_items mi"
                . " JOIN music_tracks mt ON mt.media_item_id = mi.id"
                . " WHERE mi.library_id = ? AND mi.type = 'track'",
                [$libraryId]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Music scan skip index failed to load; every file will be re-read', [
                'library_id' => $libraryId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $path = $row['path'] ?? null;
            $mtime = $row['file_mtime'] ?? null;
            $size = $row['file_size'] ?? null;

            // A row that was never stamped (every pre-S122 row, and every row
            // written by a scan that lost the file) has no identity to compare,
            // so it is left out and the file is probed.
            if (!is_string($path) || $path === '' || !is_numeric($mtime) || !is_numeric($size)) {
                continue;
            }

            if (count($this->entries) >= self::MAX_ENTRIES) {
                $this->truncated = true;
                break;
            }

            $this->entries[$path] = self::identity((int) $mtime, (int) $size);
        }

        if ($this->truncated) {
            $this->logger->warning('Music scan skip index hit its entry cap; the remainder will be re-read', [
                'library_id' => $libraryId,
                'max_entries' => self::MAX_ENTRIES,
            ]);
        }
    }

    /**
     * Is this file byte-for-byte the same size, at the same mtime, as it was
     * IMMEDIATELY BEFORE its tags were last read?
     *
     * The "immediately before" is not pedantry — it is the property that makes a
     * false TRUE impossible for an ordinary tag write, and getting it wrong was
     * review r1's B1 data-loss defect. See the predicate section of the class
     * docblock and {@see self::stampValues()}.
     *
     * Returns FALSE for anything it cannot prove — an unloaded index, an unknown
     * path, a `stat()` that failed. "Unproven" must mean "read it", never "skip
     * it": every FALSE costs one file's read time, every wrong TRUE loses a
     * change until the file is touched again.
     *
     * `SplFileInfo::getMTime()`/`getSize()` are served from the stat the
     * directory walk already performed — sshfs's `dir_cache` prefetches every
     * entry's attributes with the `readdir`, measured at 0.8 ms per DIRECTORY
     * and 0.005 ms per warm `stat` — so this check adds no round trip of its
     * own. Both throw `RuntimeException` when the stat fails (a file deleted
     * between `readdir` and here), which is caught and answered FALSE.
     *
     * @param SplFileInfo $file Audio file the walk is positioned on.
     *
     * @return bool True only when the recorded identity matches exactly.
     */
    public function isUnchanged(SplFileInfo $file): bool
    {
        if (!$this->loaded || $this->entries === []) {
            return false;
        }

        $recorded = $this->entries[$file->getPathname()] ?? null;
        if ($recorded === null) {
            return false;
        }

        $current = self::currentIdentity($file);

        return $current !== null && $current === $recorded;
    }

    /**
     * Does the index already hold exactly this identity for this path?
     *
     * Used to suppress a pointless `UPDATE` when the scanner probed a file whose
     * stamp was already current. See
     * {@see MusicLibraryScanner::stampFileIdentity()} for the ONE scan shape on
     * which that can actually happen — it is narrower than this docblock used to
     * claim (review r1 B2).
     *
     * @param SplFileInfo $file Audio file whose stamp is about to be written.
     * @param array{0: int, 1: int}|null $values Identity to compare, as captured
     *        by {@see self::stampValues()} BEFORE the file's tags were read. NULL
     *        means "stat the file now", which is only correct for a caller that has
     *        not read the file yet — see {@see self::remember()}. ⚠ Measured: passing
     *        it changes only whether ONE redundant `UPDATE` is issued for a file edited
     *        between its probe and its flush — both branches leave the row holding the
     *        pre-read identity — so a mutation that ignores it leaves the suite green.
     *        It is passed for consistency with the value being written, not for a
     *        correctness difference this class can demonstrate.
     *
     * @return bool True when a write would change nothing.
     */
    public function isStampCurrent(SplFileInfo $file, ?array $values = null): bool
    {
        $recorded = $this->entries[$file->getPathname()] ?? null;
        if ($recorded === null) {
            return false;
        }

        $current = $values === null ? self::currentIdentity($file) : self::identity($values[0], $values[1]);

        return $current !== null && $current === $recorded;
    }

    /**
     * Records the identity the scanner has just persisted for a file, so the map
     * agrees with the row for the rest of the scan.
     *
     * ⚠ **The "so a file the walk reaches twice is not probed twice" rationale that
     * used to be here is WRONG, measured.** The map is keyed by verbatim path, and
     * `RecursiveDirectoryIterator` (SKIP_DOTS + LEAVES_ONLY, as
     * {@see MusicLibraryScanner::audioFileIterator()} builds it) does NOT descend a
     * symlinked directory — verified: a tree with `top/linked -> ../real` yields
     * `real/a.mp3` exactly once and nothing under `top/linked`, and a hard link
     * appears as its own distinct path, i.e. its own key. So no walk yields the same
     * KEY twice and this method saves no probe. What it actually buys is that
     * {@see self::isStampCurrent()} can suppress a redundant `UPDATE` later in the same
     * scan, and that anything consulting the map mid-walk sees what the database
     * holds rather than something staler.
     *
     * @param SplFileInfo $file Audio file just indexed.
     * @param array{0: int, 1: int}|null $values The identity that was actually
     *        WRITTEN — i.e. the one {@see self::stampValues()} captured before the
     *        tags were read. **Production always passes this**, so that the map agrees
     *        with the row. NULL means "stat the file now" and is for callers that have
     *        not read the file at all (a test seeding the map directly).
     *        ⚠ **Scope, measured rather than asserted:** unlike the DB stamp, passing
     *        this is invariant hygiene rather than a demonstrated correctness fix —
     *        mutating the production call sites back to `remember($file)` leaves the
     *        suite green, because the map is keyed by verbatim path and no walk yields
     *        the same path twice (`RecursiveDirectoryIterator` does not descend
     *        symlinks). It is passed because a map that disagrees with the row it
     *        mirrors is a trap for whoever consults it next, not because a test can
     *        currently catch it.
     *
     * @return void
     */
    public function remember(SplFileInfo $file, ?array $values = null): void
    {
        $current = $values === null ? self::currentIdentity($file) : self::identity($values[0], $values[1]);
        if ($current === null) {
            return;
        }

        if (!isset($this->entries[$file->getPathname()]) && count($this->entries) >= self::MAX_ENTRIES) {
            return;
        }

        $this->entries[$file->getPathname()] = $current;
    }

    /**
     * The `(mtime, size)` pair to stamp into `metadata_json` for a file, or NULL
     * when the file could not be stat'ed.
     *
     * ⚠ **WHERE THIS IS CALLED FROM IS PART OF THE CORRECTNESS ARGUMENT (review r1
     * B1).** It must be called in the walk, immediately BEFORE `probeMetadata()`,
     * and the returned pair carried to the write. `SplFileInfo` does not memoise its
     * `stat()` — measured: a second `getMTime()` on the SAME instance returns the NEW
     * value after the file has changed — so calling this again at write time reads a
     * genuinely later state of the file and stamps post-edit bytes against pre-edit
     * tags. See {@see self::isUnchanged()} and the predicate section of the class
     * docblock.
     *
     * @param SplFileInfo $file Audio file being indexed.
     *
     * @return array{0: int, 1: int}|null `[mtime, size]`.
     */
    public static function stampValues(SplFileInfo $file): ?array
    {
        try {
            return [$file->getMTime(), $file->getSize()];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Number of retained entries. */
    public function count(): int
    {
        return count($this->entries);
    }

    /** Whether {@see self::MAX_ENTRIES} stopped the load short. */
    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    /** Whether {@see self::load()} has run. */
    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /**
     * Drops every entry.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->entries = [];
        $this->loaded = false;
        $this->truncated = false;
    }

    /**
     * The canonical string form of one recorded identity.
     *
     * @param int $mtime Unix mtime.
     * @param int $size  Size in bytes.
     *
     * @return string
     */
    private static function identity(int $mtime, int $size): string
    {
        return $mtime . ':' . $size;
    }

    /**
     * The live identity of a file, or NULL when it cannot be stat'ed.
     *
     * @param SplFileInfo $file Audio file.
     *
     * @return string|null
     */
    private static function currentIdentity(SplFileInfo $file): ?string
    {
        $values = self::stampValues($file);

        return $values === null ? null : self::identity($values[0], $values[1]);
    }
}
