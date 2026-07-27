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
 * values recorded the last time this scanner actually read its tags.**
 *
 * A skip predicate is a CACHE. A wrong one does not make the scan slow, it makes
 * it **silently miss a real change**, which is strictly worse. So the failure
 * mode is stated rather than implied:
 *
 *  - ❌ **An in-place edit that preserves BOTH mtime and size is missed.**
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
 * ## Memory is bounded, deliberately
 *
 * One `SELECT` per scan loads the library's whole track identity map, which is
 * O(library size) — the shape S95 removed from the scan buffer. It is affordable
 * here only because an entry is a path plus one short scalar string rather than
 * S95's 1,463-byte `['file' => SplFileInfo, 'meta' => [...]]`. **Measured on PHP
 * 8.3.6 with production-shaped paths (`/vault1/music/<artist>/<album>/NN -
 * <title>.mp3`, ~60 chars) at the real library size: 61,135 entries = 5,556,000
 * bytes = 5.30 MB, i.e. 90.9 bytes/entry** — 16x cheaper per entry than the map
 * S95 removed (which would be ≈89 MB for the same library) and half the ≈11.1 MB
 * ceiling S95 already accepts for its open-album window. Pinned by
 * `MusicScanSkipIndexTest::testMemoryPerEntryIsBounded()`. {@see self::MAX_ENTRIES} caps it hard regardless of
 * library size, and overflow degrades to "probe the file", which is CORRECT and
 * merely slower.
 *
 * The alternative — one indexed `SELECT` per file — was rejected because the
 * lookup key would be `media_items.path`, which has **no b-tree index**
 * (migration 001 gives `media_items` only `FULLTEXT idx_name`; the
 * `(library_id, path_hash)` unique index is added out-of-band by
 * `migrations/cleanup_072.php` and may legitimately be absent). One statement
 * against `idx_media_items_library_type` (migration 011) has a cost that can be
 * reasoned about; 61,135 statements whose plan depends on whether an optional
 * post-migration script was ever run does not.
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
     * never reached in practice; it exists so that memory is bounded by a
     * CONSTANT rather than by however large a library grows. At the measured
     * 90.9 bytes/entry the ceiling is ≈21.7 MB.
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
     * S122. `music_tracks.media_item_id` is `NOT NULL UNIQUE` (migration 011), so the
     * join is an index lookup, and its `album_id`/`artist_id` are `NOT NULL` FKs — so
     * a joined row also proves the artist and album rows exist.
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
     * Is this file byte-for-byte the same size, at the same mtime, as when its
     * tags were last read?
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
     * stamp was already current — which happens whenever the fast path is
     * switched off for the scan (an unhealed `music_*` row, or an adoptable
     * orphan) but the file itself really is unchanged.
     *
     * @param SplFileInfo $file Audio file whose stamp is about to be written.
     *
     * @return bool True when a write would change nothing.
     */
    public function isStampCurrent(SplFileInfo $file): bool
    {
        $recorded = $this->entries[$file->getPathname()] ?? null;
        if ($recorded === null) {
            return false;
        }

        $current = self::currentIdentity($file);

        return $current !== null && $current === $recorded;
    }

    /**
     * Records the identity the scanner has just persisted for a file.
     *
     * Keeps the in-memory index consistent with the database within one scan, so
     * a file the walk reaches twice (a hard link, a directory visited through a
     * symlink) is not probed twice.
     *
     * @param SplFileInfo $file Audio file just indexed.
     *
     * @return void
     */
    public function remember(SplFileInfo $file): void
    {
        $current = self::currentIdentity($file);
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
