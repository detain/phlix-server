<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Collection\SmartRuleVocabulary;
use Phlix\Common\Uuid;
use Phlix\Server\Http\RequestContext;
use Phlix\Stats\StatsCollector;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * ItemRepository provides data access for media items in the database.
 *
 * This repository handles all CRUD operations for media_items and media_streams
 * tables, including querying, searching, filtering by content ratings and genres,
 * and stream management.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Data access layer for media items with content filtering support
 * @see LibraryManager For library-level operations
 */
class ItemRepository
{
    /** @var Connection Database connection */
    private Connection $db;

    /**
     * Optional stats collector. When wired, item adds/removes are recorded into
     * stats_library_changes, which feeds the admin dashboard activity feed.
     * Null in unit tests / legacy callers (recording no-ops).
     *
     * @var StatsCollector|null
     */
    private ?StatsCollector $statsCollector;

    /**
     * In-worker TTL cache of the DISTINCT genre facet set, keyed by scope.
     *
     * {@see distinctGenres()} reads the `media_item_genres` join table
     * (migration 051) joined back to `media_items` — a query run fresh on every
     * genre-filter-UI load, even though the genre set changes only when items
     * are scanned or edited. This memoises the computed facet list per scope so
     * repeat loads within the TTL are served from memory without touching the
     * DB.
     *
     * Contract:
     *  - Keyed by library UUID; the unscoped (all-libraries) set uses
     *    {@see GENRE_FACET_GLOBAL_KEY}.
     *  - Each entry carries a monotonic expiry ({@see monotonicMs()} +
     *    {@see GENRE_FACET_CACHE_TTL_MS}); a stale entry is recomputed on the next
     *    read. The TTL is the ONLY cross-worker / cross-process coherence bound —
     *    a scan running in another worker cannot reach this per-worker map, so its
     *    genre changes surface here after at most one TTL window.
     *  - Same-worker writes that can change the genre set ({@see create()},
     *    {@see update()}, {@see delete()}, {@see deleteByLibrary()}) invalidate
     *    eagerly via {@see invalidateGenreFacets()} so a reader in this worker
     *    never observes its own stale write.
     *  - Bounded by {@see GENRE_FACET_CACHE_MAX} with oldest-first eviction via
     *    `array_key_first()`. This is a SECURITY control, not just a size limit:
     *    the scope key includes a caller-supplied, unvalidated `libraryId` query
     *    param (see `WebPortalRouter::getMediaFacets()`), so this map is
     *    attacker-influenced and must stay bounded in a resident Workerman worker,
     *    or an authenticated caller could grow it unbounded by cycling fabricated
     *    library ids.
     *  - Eviction is genuinely LRU (least-recently-*read*), not FIFO
     *    (least-recently-*written*): both the cache-hit path in
     *    {@see distinctGenres()} and its stale-entry recompute path `unset()` the
     *    key immediately before reassigning it, which moves it to the END of the
     *    PHP array — the position `array_key_first()` eviction never touches.
     *    Without that `unset()`, a plain value reassignment of an *existing* key
     *    leaves it in its original position, and a hot/just-refreshed scope could
     *    be evicted ahead of a genuinely cold one.
     *
     * @var array<string, array{genres: list<string>, expires_at: int}>
     */
    private array $genreFacetCache = [];

    /** @var int Genre-facet cache TTL in milliseconds (5 minutes). Genres change rarely. */
    private const GENRE_FACET_CACHE_TTL_MS = 300_000;

    /** @var int Max distinct scopes retained in the in-worker facet LRU before eviction. */
    private const GENRE_FACET_CACHE_MAX = 256;

    /**
     * Cache key standing in for the unscoped (all-libraries) genre facet set. The
     * leading NUL byte cannot collide with any real library UUID key.
     */
    private const GENRE_FACET_GLOBAL_KEY = "\0all-libraries";

    /**
     * Constructor for ItemRepository.
     *
     * @param Connection $db Database connection for media item persistence
     * @param StatsCollector|null $statsCollector Optional collector; records
     *        item add/remove changes for the admin dashboard when supplied.
     */
    public function __construct(Connection $db, ?StatsCollector $statsCollector = null)
    {
        $this->db = $db;
        $this->statsCollector = $statsCollector;
    }

    /**
     * Record a library-change stat for the admin dashboard.
     *
     * No-ops when no {@see StatsCollector} is wired. Any failure is swallowed
     * so statistics collection can never break a library scan or delete.
     *
     * @param string      $changeType   'item_added', 'item_removed', or 'library_cleared'.
     * @param string|null $mediaItemId  Affected media item UUID, if applicable.
     * @param string|null $libraryId    Owning library UUID, if known.
     */
    private function recordChange(string $changeType, ?string $mediaItemId, ?string $libraryId): void
    {
        if ($this->statsCollector === null) {
            return;
        }
        try {
            $this->statsCollector->recordLibraryChange($changeType, $mediaItemId, $libraryId);
        } catch (Throwable) {
            // Stats recording must never break library operations.
        }
    }

    /**
     * Finds a media item by its unique identifier.
     *
     * @param string $id The media item's unique identifier
     * @return array<string, mixed>|null The hydrated media item array or null if not found
     */
    public function findById(string $id): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM media_items WHERE id = ?",
            [$id]
        );

        $row = $this->firstRow($result);
        if ($row === null) {
            return null;
        }

        $item = $this->hydrateItem($row);

        // P5-S2: apply profile tag restrictions — if the item is blocked by
        // the current profile's tags, treat it as not found (return null).
        $filtered = $this->filterItemsByTags([$item]);
        return $filtered[0] ?? null;
    }

    /**
     * Finds multiple media items by their unique identifiers.
     *
     * @param array<int, string> $ids Array of media item UUIDs
     * @return list<array<string, mixed>> Hydrated media items (preserves order of input IDs)
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE id IN ({$placeholders})",
            $ids
        );

        $rows = $this->hydrateRows($results);

        // Preserve order of input IDs
        $indexed = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? '';
            if (!is_string($id)) {
                continue;
            }
            $indexed[$id] = $row;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($indexed[$id])) {
                $ordered[] = $indexed[$id];
            }
        }

        // P5-S2: apply profile tag restrictions — filter out any items blocked
        // by the current profile's tags. Items that pass the filter keep their
        // original relative order.
        return $this->filterItemsByTags($ordered);
    }

    /**
     * Finds a media item by its filesystem path.
     *
     * Uses the `(library_id, path_hash)` unique index when libraryId is
     * provided, falling back to a path-only scan for non-deduped types
     * (where path_hash is NULL and the index cannot be used). The SHA1
     * collision risk is mitigated by verifying the raw path as a tiebreak.
     *
     * @param string      $path     The absolute filesystem path to the media file
     * @param string|null $libraryId Optional library scope for index optimization.
     *                               When provided, enables use of the path_hash index.
     * @return array<string, mixed>|null The hydrated media item array or null if not found
     */
    public function findByPath(string $path, ?string $libraryId = null): ?array
    {
        $hash = sha1($path);
        if ($libraryId !== null) {
            // Use the indexed (library_id, path_hash) lookup with path tiebreak
            $result = $this->db->query(
                "SELECT * FROM media_items WHERE library_id = ? AND path_hash = ? AND path = ?",
                [$libraryId, $hash, $path]
            );
        } else {
            // Fall back to path-only lookup for callers without library context
            $result = $this->db->query(
                "SELECT * FROM media_items WHERE path_hash = ? AND path = ?",
                [$hash, $path]
            );
        }

        $row = $this->firstRow($result);
        if ($row === null) {
            return null;
        }

        return $this->hydrateItem($row);
    }

    /**
     * Find an existing media item by path, or create it — race-safe.
     *
     * Resolves the duplicate-row race that exists when multiple scanner workers
     * try to create the same media item concurrently: the naive find-then-create
     * pattern inserts two rows when the second worker passes the existence check
     * before the first worker's INSERT commits.
     *
     * Logic:
     *  1. Look the item up by path. If it already exists, return its ID —
     *     no write, no side effects.
     *  2. Otherwise delegate to {@see create()}, which performs the INSERT *and*
     *     all of its side effects (media_item_genres sync, stats change record,
     *     genre-facet cache invalidation, UTF-8 path/name scrubbing, sort_title,
     *     canonical_key). We deliberately reuse create() rather than duplicating
     *     that logic so an upsert-created row is byte-for-byte identical to a
     *     create()-created one.
     *  3. If create()'s INSERT fails because a concurrent worker inserted the
     *     same path first (MySQL 1062 against the `(library_id, path_hash)`
     *     unique index from migration 072), swallow the error and return the
     *     row that now exists. Any other failure is re-thrown unchanged.
     *
     * NB: the 1062 branch can only fire once migration 072's unique index is in
     * place; before that there is no path constraint to violate, so step 1 is the
     * only guard. That is intentional — the index is the durable guarantee and
     * this method is forward-compatible with it.
     *
     * @param array<string, mixed> $data Media item data (same shape as {@see create()}).
     *                                   Required keys: library_id, name, type, path.
     * @param bool $callerConfirmedAbsent When the caller has ALREADY established
     *        (via its own {@see findByPath()}) that no row exists for this path,
     *        pass true to skip the redundant pre-check and go straight to the
     *        race-safe create. The scanner's per-file path does exactly this.
     *        Leave false (default) for callers that have not looked the path up —
     *        e.g. container creation, whose rows are exempt from the
     *        `(library_id, path_hash)` unique index and therefore cannot rely on
     *        the 1062 catch below for de-duplication.
     * @return string The ID of the existing or newly created media item.
     * @throws Throwable When create() fails for any reason other than a losing
     *                   concurrent insert on the same path.
     */
    public function upsertByPath(array $data, bool $callerConfirmedAbsent = false): string
    {
        $path = isset($data['path']) && is_string($data['path']) ? $data['path'] : '';
        $libraryId = isset($data['library_id']) && is_string($data['library_id']) ? $data['library_id'] : null;

        if (!$callerConfirmedAbsent) {
            $existing = $this->findByPath($path, $libraryId);
            if (is_array($existing) && isset($existing['id']) && is_string($existing['id'])) {
                return $existing['id'];
            }
        }

        try {
            return $this->create($data);
        } catch (Throwable $e) {
            // A concurrent worker inserted the same path between our existence
            // check and the INSERT (unique-index violation). Reuse their row.
            $raced = $this->findByPath($path, $libraryId);
            if (is_array($raced) && isset($raced['id']) && is_string($raced['id'])) {
                return $raced['id'];
            }
            // The insert failed for some other reason — surface it.
            throw $e;
        }
    }

    /**
     * Batch lookup of media items by filesystem path — a single
     * `WHERE library_id = ? AND path_hash IN (SHA1(?), ...)` query rather than
     * one {@see findByPath()} call per candidate.
     *
     * When `$libraryId` is supplied the predicate leads with `library_id = ?`
     * so the `(library_id, path_hash)` unique index from migration 072 is used
     * left-prefix-first (an index scan, not a table scan). Omitting the
     * `library_id` — the pre-fix behaviour — left the composite index's leading
     * column unbound, so MySQL could not use it and the hot batch path
     * full-scanned `media_items` on every scan/rescan. The scanner always scans
     * one library at a time and passes its id (see {@see MediaScanner::processScanBatch()}).
     * The optional fallback (no `$libraryId`) mirrors {@see findByPath()} and is
     * retained for callers without library context.
     *
     * The indexed `path_hash` column is the STORED SHA1 of the path. Each
     * returned row's *raw* path is verified for exact membership in the input
     * list, so a hypothetical SHA1 collision (a different path hashing to the
     * same value) can never leak a foreign row into the map.
     *
     * Used by {@see MediaScanner::scanFlat()} (S8) to determine, for a whole
     * batch of scan candidates at once, which ones are already indexed
     * (rescan → backfill only) versus brand new (probe + create), avoiding an
     * N+1 query pattern on every scan/rescan.
     *
     * @param array<int, string> $paths Absolute filesystem paths to look up.
     *                                  Duplicates are harmless (the map is
     *                                  keyed by path, so a repeat collapses).
     * @param string|null $libraryId Optional library scope. When provided,
     *                               enables use of the `(library_id, path_hash)`
     *                               index and scopes the result to that library
     *                               (a path that also exists in a *different*
     *                               library is correctly excluded).
     * @return array<string, array<string, mixed>> Hydrated media item rows
     *         keyed by their `path` column. Paths with no matching row are
     *         simply absent from the map (never a null entry). Empty input
     *         short-circuits to `[]` without querying (a malformed
     *         `IN ()` would otherwise be sent to MySQL).
     */
    public function findPathsMap(array $paths, ?string $libraryId = null): array
    {
        if ($paths === []) {
            return [];
        }

        // Compute SHA1 hashes in PHP for use with the indexed path_hash column
        $hashes = array_map('sha1', $paths);
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));

        if ($libraryId !== null) {
            // Lead with library_id so the (library_id, path_hash) index is used.
            $results = $this->db->query(
                "SELECT * FROM media_items WHERE library_id = ? AND path_hash IN ({$placeholders})",
                array_merge([$libraryId], $hashes)
            );
        } else {
            // Fall back to a path_hash-only lookup for callers without a library.
            $results = $this->db->query(
                "SELECT * FROM media_items WHERE path_hash IN ({$placeholders})",
                $hashes
            );
        }

        // Set of requested raw paths for O(1) exact-membership verification —
        // the true raw-path tiebreak against an astronomically rare SHA1
        // collision (a foreign path that hashed into our IN-set).
        $pathSet = array_flip($paths);

        $map = [];
        foreach ($this->hydrateRows($results) as $row) {
            $path = $row['path'] ?? null;
            if (is_string($path) && $path !== '' && isset($pathSet[$path])) {
                $map[$path] = $row;
            }
        }

        return $map;
    }

    /**
     * Finds a TOP-LEVEL (parent-less) media item whose stored canonical dedup
     * key matches, scoped to one library and type.
     *
     * This is the canonical-key fallback for {@see MediaScanner} when an exact
     * synthetic-path / file-path lookup misses: two rows whose titles slug
     * differently (separators, year bleed, a parse failure, a flat→per-directory
     * re-scan) but resolve to the SAME {@see CanonicalKey::forItem()} are in fact
     * the same show/film, so the scanner reuses the existing container instead of
     * creating a second top-level row.
     *
     * Since migration 043 the key is a first-class, INDEXED `canonical_key`
     * column (the source of truth, kept in lockstep with the
     * `metadata_json.canonical_key` blob by {@see create()}/{@see update()}). The
     * match reads that column directly so the index
     * `(library_id, type, canonical_key)` is used — a `JSON_EXTRACT` predicate
     * could never be index-covered. The value is bound with a positional
     * placeholder (colon-free, parameterised — no SQL injection). Restricting to
     * `parent_id IS NULL` keeps the match to true containers (series/movie),
     * never a season/episode.
     *
     * @param string $libraryId    Owning library UUID — scope the match to one library.
     * @param string $type         Container type ('series' or 'movie').
     * @param string $canonicalKey The {@see CanonicalKey::forItem()} value to match.
     * @return array<string, mixed>|null The first hydrated matching top-level item,
     *         or null when no match / an empty key is given.
     */
    public function findTopLevelByCanonical(string $libraryId, string $type, string $canonicalKey): ?array
    {
        // An empty key is never a meaningful match (a title with nothing
        // alphanumeric and no year/external id) — short-circuit so we never
        // collapse unrelated unkeyable rows together.
        if ($canonicalKey === '') {
            return null;
        }

        $result = $this->db->query(
            "SELECT * FROM media_items
             WHERE library_id = ?
               AND type = ?
               AND parent_id IS NULL
               AND canonical_key = ?
             LIMIT 1",
            [$libraryId, $type, $canonicalKey]
        );

        $row = $this->firstRow($result);
        if ($row === null) {
            return null;
        }

        return $this->hydrateItem($row);
    }

    /**
     * Pages the TOP-LEVEL (parent-less) items of one library in a FIXED batch.
     *
     * This is the paging primitive {@see DuplicateFinder} streams a library
     * through to find merge candidates: it returns one bounded batch of
     * containers/movies (`parent_id IS NULL`) ordered by a stable key (`id`) so
     * successive `offset` pages neither skip nor repeat a row, and the whole
     * library never materialises in memory at once (resident-memory safe in the
     * long-lived worker). Ordering by `id` (not the article-stripped title) is
     * deliberate — paging only needs a stable, total order, and `id` is the
     * primary key, so it is the cheapest non-skewing cursor.
     *
     * @param string $libraryId Owning library UUID.
     * @param int    $limit     Maximum rows in this batch.
     * @param int    $offset    Rows to skip (page * limit).
     * @return array<int, array<string, mixed>> Hydrated top-level rows (may be
     *         fewer than $limit on the final page; empty past the end).
     */
    public function getTopLevelByLibrary(string $libraryId, int $limit = 500, int $offset = 0): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items
             WHERE library_id = ? AND parent_id IS NULL
             ORDER BY id ASC
             LIMIT ? OFFSET ?",
            [$libraryId, $limit, $offset]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Counts ALL descendants of a media item — its direct children plus every
     * deeper level (so a series counts its seasons AND every episode).
     *
     * Used by {@see DuplicateFinder} to designate the "primary" of a duplicate
     * group as the richest container (the 100-episode show beats the 1-episode
     * shell). A single recursive walk over `parent_id` returns the full subtree
     * size in ONE query per item (no per-level N+1); the tree is shallow
     * (series → season → episode), so the recursion is bounded.
     *
     * @param string $itemId The media item whose descendants to count.
     * @return int Total number of descendant rows (0 for a leaf/movie).
     */
    public function countDescendants(string $itemId): int
    {
        // The statement MUST start with `SELECT`: the workerman/mysql driver
        // (`PhlixMySQLConnection`/base `Connection::query()`) decides whether to
        // FETCH a result set by inspecting the leading keyword. A statement that
        // begins with `WITH` is NOT recognised as row-returning, so `query()`
        // silently returns NULL instead of the count rows → `extractCount()`
        // yields 0 for every item (live-DB outage; CI missed it because the 1.3
        // tests drove an in-memory double, never the real SQL). Wrapping the
        // recursive CTE in an outer `SELECT COUNT(*) FROM (... ) AS t` keeps the
        // arbitrary-depth recursion while making the driver fetch the rows.
        $result = $this->db->query(
            "SELECT COUNT(*) AS count FROM (
                 WITH RECURSIVE descendants AS (
                     SELECT id FROM media_items WHERE parent_id = ?
                     UNION ALL
                     SELECT mi.id
                     FROM media_items mi
                     JOIN descendants d ON mi.parent_id = d.id
                 )
                 SELECT id FROM descendants
             ) AS t",
            [$itemId]
        );

        return $this->extractCount($result);
    }

    /**
     * Finds all child items of a parent media item.
     *
     * @param string $parentId The parent media item's unique identifier
     * @return array<int, array<string, mixed>> Array of hydrated child media items ordered by name
     */
    public function findByParent(string $parentId): array
    {
        // Children are a season/episode drill-down, not an alphabetical browse
        // listing — they keep raw-name ordering (the UI re-sorts episodes by
        // season/episode number), so the article-stripping rule is intentionally
        // NOT applied here. It is reserved for the top-level listings + A-Z rail.
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE parent_id = ? ORDER BY name",
            [$parentId]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Finds all child items for multiple parent media items in a single query.
     *
     * @param array<int, string> $parentIds Array of parent media item UUIDs
     * @return array<string, array<int, array<string, mixed>>> Map of parent_id => children array
     */
    public function findByParents(array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE parent_id IN ({$placeholders}) ORDER BY parent_id, name",
            $parentIds
        );

        if (!is_array($results)) {
            return [];
        }

        $children = [];
        foreach ($results as $row) {
            $normalized = $this->normalizeRow($row);
            if ($normalized !== null) {
                $hydrated = $this->hydrateItem($normalized);
                $parentIdRaw = $hydrated['parent_id'] ?? null;
                if (!is_string($parentIdRaw)) {
                    continue;
                }
                $parentId = $parentIdRaw;
                if (!isset($children[$parentId])) {
                    $children[$parentId] = [];
                }
                $children[$parentId][] = $hydrated;
            }
        }

        return $children;
    }

    /**
     * Gets media items by type within a library with pagination.
     *
     * @param string $libraryId The library's unique identifier
     * @param string $type The media type filter (e.g., 'movie', 'series', 'audio')
     * @param int $limit Maximum number of items to return
     * @param int $offset Number of items to skip for pagination
     * @return array<int, array<string, mixed>> Array of hydrated media items
     */
    public function getByType(string $libraryId, string $type, int $limit = 100, int $offset = 0): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE library_id = ? AND type = ? ORDER BY " . self::titleOrder() . " LIMIT ? OFFSET ?",
            [$libraryId, $type, $limit, $offset]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Gets all media items of a specific type across all libraries.
     *
     * @param string $type The media type filter (e.g., 'movie', 'audio', 'image')
     * @param int $limit Maximum number of items to return
     * @param int $offset Number of items to skip for pagination
     * @return array<int, array<string, mixed>> Array of hydrated media items
     *
     * @since 0.12.0
     */
    public function getAllByType(string $type, int $limit = 100, int $offset = 0): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE type = ? ORDER BY " . self::titleOrder() . " LIMIT ? OFFSET ?",
            [$type, $limit, $offset]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Counts all media items of a specific type across all libraries.
     *
     * @param string $type The media type to count
     * @return int Number of items of the given type
     *
     * @since 0.12.0
     */
    public function countAllByType(string $type): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM media_items WHERE type = ?",
            [$type]
        );

        return $this->extractCount($result);
    }

    /**
     * Gets all media items within a library with pagination.
     *
     * @param string $libraryId The library's unique identifier
     * @param int $limit Maximum number of items to return
     * @param int $offset Number of items to skip for pagination
     * @return array<int, array<string, mixed>> Array of hydrated media items
     */
    public function getByLibrary(string $libraryId, int $limit = 100, int $offset = 0): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE library_id = ? ORDER BY " . self::titleOrder() . " LIMIT ? OFFSET ?",
            [$libraryId, $limit, $offset]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Sanitizes a user search query for MySQL FULLTEXT boolean mode.
     *
     * FULLTEXT boolean mode treats `+ - > < ( ) ~ * " @` as special operators.
     * Queries like "C++" cause syntax errors because "++" is interpreted as
     * increment operator. Similarly, email addresses or random strings with
     * many operators cause errors.
     *
     * This method strips problematic operators that are unlikely to be
     * intentional search modifiers when they appear in user queries, while
     * preserving legitimate +word (must contain) syntax.
     *
     * @param string $query Raw user search input
     * @return string Sanitized query safe for FULLTEXT AGAINST(? IN BOOLEAN MODE)
     */
    private function sanitizeFulltextQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        // Use a separate variable to avoid PHPStan type inference issues
        // with preg_replace return type tracking through reassignment
        $sanitized = $query;

        // Strip consecutive + signs that are unlikely to be intentional "must contain"
        // operators (e.g., "C++" → "C+", "C+++" → "C++")
        // But keep single + at start of words (word+ means "must contain word")
        $result = preg_replace('/\+\++/', '+', $sanitized);
        $sanitized = is_string($result) ? $result : $sanitized;

        // Remove leading + signs that aren't followed by a word character
        // (these are almost certainly accidental, like "++foobar" or just "++")
        $result = preg_replace('/^\+\s*/', '', $sanitized);
        $sanitized = is_string($result) ? $result : $sanitized;

        // Remove standalone + or - operators that aren't preceded by a word
        // (e.g., "+ +" → "+" keeps one, " - " at start → removed)
        $result = preg_replace('/(?<!\w)[+\-]+(?!\w)/', ' ', $sanitized);
        $sanitized = is_string($result) ? $result : $sanitized;

        // Remove other problematic operators: < > ( ) ~ * " @
        // These are rarely used intentionally in simple search queries
        $sanitized = str_replace(['<', '>', '(', ')', '~', '*', '"', '@'], ' ', $sanitized);

        // Collapse multiple spaces
        $result = preg_replace('/\s+/', ' ', $sanitized);
        $sanitized = is_string($result) ? $result : $sanitized;

        return trim($sanitized);
    }

    /**
     * Performs full-text search on media item names.
     *
     * Raw user input is passed to a FULLTEXT … AGAINST(… IN BOOLEAN MODE)
     * match after sanitization to handle problematic characters like `C++`
     * which would otherwise cause a syntax error. Boolean mode treats
     * `+ - > < ( ) ~ * " @` as operators, so an unbalanced or operator-only
     * query (e.g. an email address) makes MySQL raise a "syntax error in
     * fulltext search expression". The query is sanitized to remove/replace
     * these operators before the query, falling back to LIKE only when
     * the sanitized query is empty.
     *
     * @param string $query The search query for full-text matching
     * @param int $limit Maximum number of results to return
     * @return array<int, array<string, mixed>> Array of hydrated media items matching the query
     */
    public function search(string $query, int $limit = 50): array
    {
        // Sanitize the query to handle C++, email addresses, etc.
        $sanitizedQuery = $this->sanitizeFulltextQuery($query);

        // If sanitization removed everything, fall back to LIKE immediately
        if ($sanitizedQuery === '') {
            return $this->searchFuzzy($query, $limit);
        }

        try {
            $results = $this->db->query(
                "SELECT * FROM media_items WHERE MATCH(name) AGAINST(? IN BOOLEAN MODE) LIMIT ?",
                [$sanitizedQuery, $limit]
            );

            return $this->hydrateRows($results);
        } catch (\Throwable) {
            return $this->searchFuzzy($query, $limit);
        }
    }

    /**
     * Performs fuzzy/partial string matching on media item names.
     *
     * @param string $query The partial string to search for
     * @param int $limit Maximum number of results to return
     * @return array<int, array<string, mixed>> Array of hydrated media items matching the query
     */
    public function searchFuzzy(string $query, int $limit = 50): array
    {
        $escapedQuery = '%' . addcslashes($query, '%_') . '%';
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE name LIKE ? LIMIT ?",
            [$escapedQuery, $limit]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Creates a new media item in the database.
     *
     * Also invalidates the affected library's cached genre facet set (and the
     * all-libraries scope) via {@see invalidateGenreFacets()}, since the new
     * item's `metadata_json.$.genres` may introduce a genre not previously
     * seen. {@see batchCreate()} inherits this since it calls this method.
     *
     * @param array<string, mixed> $data Media item data including library_id, name, type, path, and optionally metadata_json
     * @return string The unique identifier of the created media item
     * @throws \InvalidArgumentException If required fields are missing
     */
    public function create(array $data): string
    {
        $idCandidate = $data['id'] ?? null;
        $id = is_string($idCandidate) ? $idCandidate : $this->generateUuid();
        $metadataJson = isset($data['metadata_json'])
            ? (is_array($data['metadata_json']) ? json_encode($data['metadata_json']) : $data['metadata_json'])
            : '{}';

        // The indexed `canonical_key` column (migration 043) is the source of
        // truth for findTopLevelByCanonical(). Mirror the value the Step 1.2
        // scanner already stamps into `metadata_json.canonical_key` so the
        // column stays in lockstep with the blob without changing any scanner
        // call site. NULL when absent/blank (an unkeyable row).
        $canonicalKey = self::extractCanonicalKey($data['metadata_json'] ?? null);

        // Materialize the article-stripped sort key (migration 050) so listings
        // can ORDER BY the indexed `sort_title` column instead of the per-row
        // SortTitle::sqlExpression() CASE that forced a filesort. Derived from the
        // SAME scrubbed name the row stores via SortTitle::from(), whose output is
        // branch-for-branch identical to that SQL expression, so the materialized
        // order matches the historical one exactly. NULL for a non-string name.
        $scrubbedName = self::toValidUtf8($data['name'] ?? null);
        $sortTitle = is_string($scrubbedName) ? SortTitle::from($scrubbedName) : null;

        // Materialize the content rating (migration 050) so rating filters/sorts
        // hit the indexed `content_rating` column instead of a JSON extraction.
        $contentRating = self::extractContentRating($data['metadata_json'] ?? null);

        $this->db->query(
            "INSERT INTO media_items (id, library_id, parent_id, name, type, path, canonical_key, sort_title, content_rating, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $data['library_id'],
                $data['parent_id'] ?? null,
                $scrubbedName,
                $data['type'],
                self::toValidUtf8($data['path'] ?? null),
                $canonicalKey,
                $sortTitle,
                $contentRating,
                $metadataJson,
            ]
        );

        // Populate the `media_item_genres` join table (migration 051) with the
        // metadata_json.$.genres this row was just created with. Uses
        // insertGenreRows() (no preceding DELETE) rather than syncGenreRows() —
        // a freshly-created item can never have pre-existing join-table rows,
        // so the DELETE syncGenreRows() always issues would be a guaranteed
        // no-op here (see insertGenreRows()'s docblock).
        $this->insertGenreRows($id, self::extractGenres($data['metadata_json'] ?? null));

        $libraryId = isset($data['library_id']) && is_string($data['library_id'])
            ? $data['library_id']
            : null;
        $this->recordChange('item_added', $id, $libraryId);

        // A new item may introduce a genre not previously present — drop the
        // affected library's facet cache (and the all-libraries scope). A null
        // library flushes all scopes to stay correct.
        $this->invalidateGenreFacets($libraryId);

        return $id;
    }

    /**
     * Guarantee a value is valid UTF-8 before it reaches a utf8mb4 column.
     *
     * The `media_items.name`/`path` columns are utf8mb4; a value that is not
     * valid UTF-8 (a genuinely non-UTF-8 / Windows-1252 filesystem name, or a
     * multibyte sequence broken by upstream byte-wise string handling) fails
     * to insert with MySQL error 1366 ("Incorrect string value"). Valid UTF-8
     * — the overwhelming majority — is returned untouched; otherwise invalid
     * byte sequences are dropped so the write cannot fail. Non-strings pass
     * through unchanged.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function toValidUtf8(mixed $value): mixed
    {
        if (!is_string($value) || $value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $scrubbed = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        return is_string($scrubbed) ? $scrubbed : $value;
    }

    /**
     * Extract the `canonical_key` value from a `metadata_json` payload for the
     * indexed `canonical_key` column (migration 043).
     *
     * Accepts the same shapes `create()`/`update()` accept for `metadata_json`:
     * an already-decoded `array<string, mixed>` (the scanner path) or a raw JSON
     * string. Returns the trimmed string key when present and non-blank, else
     * `null` so the column stays NULL for unkeyable rows (a title with nothing
     * alphanumeric and no year/external id). The blob itself is never mutated —
     * the key is only COPIED into the column.
     *
     * @param mixed $metadataJson Array, JSON string, or anything else (→ null).
     */
    private static function extractCanonicalKey(mixed $metadataJson): ?string
    {
        if (is_string($metadataJson)) {
            $decoded = json_decode($metadataJson, true);
            $metadataJson = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($metadataJson)) {
            return null;
        }

        $key = $metadataJson['canonical_key'] ?? null;
        if (!is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key === '' ? null : $key;
    }

    /**
     * Extract the content rating from a `metadata_json` payload for the
     * materialized, indexed `content_rating` column (migration 050).
     *
     * Mirrors the value the old query path read via
     * `JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.rating'))`: the string under
     * `$.rating` when present, else `null`. Accepts the same shapes
     * `create()`/`update()` accept for `metadata_json` — an already-decoded
     * `array<string, mixed>` (the scanner path) or a raw JSON string. Only a
     * string rating is materialized; any other shape (missing, array, bool)
     * yields `null` so the column stays NULL, exactly as the JSON extraction did.
     * The blob itself is never mutated — the rating is only COPIED into the
     * column. Also used by scripts/backfill-sort-metadata.php so the offline and
     * live paths derive the column identically.
     *
     * @param mixed $metadataJson Array, JSON string, or anything else (→ null).
     */
    public static function extractContentRating(mixed $metadataJson): ?string
    {
        if (is_string($metadataJson)) {
            $decoded = json_decode($metadataJson, true);
            $metadataJson = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($metadataJson)) {
            return null;
        }

        $rating = $metadataJson['rating'] ?? null;

        return is_string($rating) ? $rating : null;
    }

    /**
     * Extract the genre list from a `metadata_json` payload for the
     * `media_item_genres` join table (migration 051).
     *
     * Mirrors {@see extractContentRating()}'s shape-handling: accepts the same
     * shapes `create()`/`update()` accept for `metadata_json` — an
     * already-decoded `array<string, mixed>` (the scanner path) or a raw JSON
     * string. Reads `$.genres`; anything other than an array yields `[]`.
     * Non-empty-string elements only survive the filter (matching
     * {@see distinctGenres()}'s existing non-empty-string assumption) and the
     * result is re-indexed (`array_values`) so it is always a plain `list`.
     *
     * @param mixed $metadataJson Array, JSON string, or anything else (→ []).
     * @return list<string>
     */
    private static function extractGenres(mixed $metadataJson): array
    {
        if (is_string($metadataJson)) {
            $decoded = json_decode($metadataJson, true);
            $metadataJson = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($metadataJson)) {
            return [];
        }

        $raw = $metadataJson['genres'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            $raw,
            static fn (mixed $g): bool => is_string($g) && $g !== ''
        ));
    }

    /**
     * Keep the `media_item_genres` join table (migration 051) in lockstep with
     * an item's `metadata_json.$.genres` array whenever the blob is (re)written
     * on an EXISTING item.
     *
     * `metadata_json.$.genres` remains the canonical source of truth (API
     * responses, {@see \Phlix\Media\Library\MediaItemShaper} etc. all read it
     * directly, unchanged by this method) — this table is purely a DERIVED
     * secondary index used by {@see buildFilters()}, {@see getByAllowedGenres()},
     * and {@see distinctGenres()} to avoid the multi-valued functional index
     * migration 051 replaces (see that migration's comment header for the full
     * InnoDB-purge-thread rationale).
     *
     * Always deletes the item's existing rows first, then delegates to
     * {@see insertGenreRows()} to re-insert the current genre set. Used by
     * {@see update()} only — an existing item may already hold rows from a
     * PRIOR write that the new genre set must replace. {@see create()} calls
     * {@see insertGenreRows()} directly instead: a freshly-created item (either
     * a newly generated UUID, or a caller-supplied one that by definition must
     * not already exist as a live row, since the INSERT would otherwise fail on
     * the primary key) can never have pre-existing `media_item_genres` rows —
     * cascade delete already guarantees no orphans survive a prior life of that
     * id — so the DELETE this method always issues would be a guaranteed no-op
     * there, one extra query per created item for no effect (Reviewer
     * finding, S7b fix round).
     *
     * NOT wrapped in a transaction: the brief window between the DELETE and the
     * INSERT is a pre-existing-shape tradeoff flagged by the Reviewer as an
     * Info-level note (this repo has no transaction precedent elsewhere in
     * `src/Media/`); left as-is per that review, see the S7b Fixer worklog
     * entry for the full rationale.
     *
     * @param string        $itemId The media item whose genre rows to sync.
     * @param list<string>  $genres The item's current genre set (may be empty).
     */
    private function syncGenreRows(string $itemId, array $genres): void
    {
        $this->db->query('DELETE FROM media_item_genres WHERE media_item_id = ?', [$itemId]);

        $this->insertGenreRows($itemId, $genres);
    }

    /**
     * Insert an item's genre set into `media_item_genres` (migration 051) with
     * NO preceding DELETE — for {@see create()} only, where the item is
     * guaranteed to have no pre-existing rows (see {@see syncGenreRows()}'s
     * docblock). {@see update()} must use {@see syncGenreRows()} instead, since
     * an existing item's PRIOR genre rows need clearing first.
     *
     * A single batched INSERT — never one query per genre (see this repo's
     * "Batch Queries for N+1 Prevention" convention). De-duplicating up front
     * avoids a duplicate-key error against the table's `(media_item_id, genre)`
     * PRIMARY KEY should the metadata blob ever repeat a genre string. No-ops
     * (issues no query at all) when `$genres` is empty.
     *
     * @param string        $itemId The media item whose genre rows to insert.
     * @param list<string>  $genres The item's genre set (may be empty).
     */
    private function insertGenreRows(string $itemId, array $genres): void
    {
        $unique = array_values(array_unique($genres));
        if ($unique === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($unique), '(?, ?)'));
        $values = [];
        foreach ($unique as $genre) {
            $values[] = $itemId;
            $values[] = $genre;
        }

        $this->db->query(
            "INSERT INTO media_item_genres (media_item_id, genre) VALUES {$placeholders}",
            $values
        );
    }

    /**
     * Updates an existing media item's properties.
     *
     * When `$data` includes `metadata_json` (the only field genres live under),
     * this flushes the entire cached genre facet set via
     * {@see invalidateGenreFacets(null)} — the owning library isn't part of
     * `$data`, so a full flush is the only way to stay correct without an extra
     * lookup query. Any other field update leaves the facet cache warm. Also
     * re-syncs the `media_item_genres` join table (migration 051) via
     * {@see syncGenreRows()} when `metadata_json` is present.
     *
     * @param string $id The media item's unique identifier
     * @param array<string, mixed> $data Associative array of fields to update
     * @return void
     */
    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];
        // Captured only when $data carries a `metadata_json` key; null means
        // "no metadata_json write in this call" so the join-table sync below is
        // skipped entirely (an empty array() is a legitimate "clear all genres").
        $genresToSync = null;

        foreach ($data as $key => $value) {
            if ($key === 'metadata_json') {
                $genresToSync = self::extractGenres($value);
            }


            // Keep the indexed `canonical_key` column (migration 043) in lockstep
            // with `metadata_json.canonical_key` whenever the metadata blob is
            // (re)written, so the column stays the source of truth for
            // findTopLevelByCanonical(). The caller may pass it explicitly too,
            // in which case its own `canonical_key` set wins (handled by the
            // normal loop below) — but a metadata_json update derives the column
            // unless the caller already set it.
            if (
                $key === 'metadata_json'
                && !array_key_exists('canonical_key', $data)
            ) {
                $sets[] = 'canonical_key = ?';
                $values[] = self::extractCanonicalKey($value);
            }

            // Keep the materialized `content_rating` column (migration 050) in
            // lockstep with metadata_json.$.rating whenever the blob is
            // (re)written, so rating filters/sorts never read a stale column. An
            // explicit `content_rating` in $data wins (handled by the normal loop).
            if (
                $key === 'metadata_json'
                && !array_key_exists('content_rating', $data)
            ) {
                $sets[] = 'content_rating = ?';
                $values[] = self::extractContentRating($value);
            }

            // Keep the materialized `sort_title` column (migration 050) in lockstep
            // with `name` whenever the display name changes, so listings ORDER BY
            // the indexed column. Derived from the scrubbed name via
            // SortTitle::from() to match what the row stores. An explicit
            // `sort_title` in $data wins (handled by the normal loop).
            if ($key === 'name' && !array_key_exists('sort_title', $data)) {
                $scrubbedName = self::toValidUtf8($value);
                $sets[] = 'sort_title = ?';
                $values[] = is_string($scrubbedName) ? SortTitle::from($scrubbedName) : null;
            }

            $sets[] = "$key = ?";
            if ($key === 'metadata_json' && is_array($value)) {
                $value = json_encode($value);
            } elseif ($key === 'name' || $key === 'path') {
                $value = self::toValidUtf8($value);
            }
            $values[] = $value;
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $id;

        $this->db->query(
            "UPDATE media_items SET " . implode(', ', $sets) . " WHERE id = ?",
            $values
        );

        // Genres live in metadata_json.$.genres, so only a metadata_json rewrite
        // can change the facet set. The owning library isn't part of $data, so
        // flush every scope (null) to guarantee correctness.
        if (array_key_exists('metadata_json', $data)) {
            $this->invalidateGenreFacets(null);
        }

        // Re-sync the media_item_genres join table (migration 051) whenever
        // metadata_json was part of this update.
        if ($genresToSync !== null) {
            $this->syncGenreRows($id, $genresToSync);
        }
    }

    /**
     * Deletes a media item by its identifier.
     *
     * Also invalidates the cached genre facet set via
     * {@see invalidateGenreFacets()} — scoped to the owning library when it
     * could be resolved (only when a {@see StatsCollector} is wired), else a
     * full flush (`null`), since a removed item may have held the last row
     * bearing some genre.
     *
     * @param string $id The media item's unique identifier
     * @return void
     */
    public function delete(string $id): void
    {
        // Capture the owning library before the row is gone so the change can
        // be attributed (cheap single-row lookup, only when a collector is wired).
        $libraryId = null;
        if ($this->statsCollector !== null) {
            $rows = $this->db->query("SELECT library_id FROM media_items WHERE id = ?", [$id]);
            $first = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
            $lib = $first['library_id'] ?? null;
            if (is_string($lib)) {
                $libraryId = $lib;
            }
        }

        $this->db->query("DELETE FROM media_items WHERE id = ?", [$id]);

        $this->recordChange('item_removed', $id, $libraryId);

        // Removing an item may drop the last row bearing some genre. Invalidate
        // the owning library when known (resolved above only if a StatsCollector
        // is wired); otherwise flush all scopes (null) to stay correct.
        $this->invalidateGenreFacets($libraryId);
    }

    /**
     * Deletes all media items belonging to a specific library.
     *
     * Also invalidates that library's cached genre facet set and the
     * all-libraries scope (clearing a library shrinks the global genre set
     * too) via {@see invalidateGenreFacets()}.
     *
     * @param string $libraryId The library's unique identifier
     * @return void
     */
    public function deleteByLibrary(string $libraryId): void
    {
        $this->db->query("DELETE FROM media_items WHERE library_id = ?", [$libraryId]);

        // One aggregate change row rather than one per deleted item — bulk
        // library clears would otherwise flood stats_library_changes.
        $this->recordChange('library_cleared', null, $libraryId);

        // Clearing a library empties its genre set (and shrinks the
        // all-libraries set) — drop both scopes.
        $this->invalidateGenreFacets($libraryId);
    }

    /**
     * Counts media items of a specific type within a library.
     *
     * @param string $libraryId The library's unique identifier
     * @param string $type The media type to count
     * @return int The number of items matching the criteria
     */
    public function countByType(string $libraryId, string $type): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM media_items WHERE library_id = ? AND type = ?",
            [$libraryId, $type]
        );

        return $this->extractCount($result);
    }

    /**
     * Gets recently added media items from a library.
     *
     * @param string $libraryId The library's unique identifier
     * @param int $limit Maximum number of items to return
     * @return array<int, array<string, mixed>> Array of recently added hydrated media items
     */
    public function getRecentlyAdded(string $libraryId, int $limit = 20): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE library_id = ? ORDER BY created_at DESC LIMIT ?",
            [$libraryId, $limit]
        );

        return $this->hydrateRows($results);
    }

    /**
     * Gets all streams associated with a media item.
     *
     * @param string $itemId The media item's unique identifier
     * @return array<int, array<string, mixed>> Array of stream data arrays
     */
    public function getItemStreams(string $itemId): array
    {
        $result = $this->db->query(
            "SELECT * FROM media_streams WHERE media_item_id = ? ORDER BY stream_index",
            [$itemId]
        );

        if (!is_array($result)) {
            return [];
        }

        $rows = [];
        foreach ($result as $row) {
            if (is_array($row)) {
                // StreamTrackShaper reads the ffprobe-shaped `disposition` key
                // (a bare numeric default flag) — surface the stored is_default
                // column (migration 071) under that name so the shaper honours
                // the container's default track without a schema-coupled read.
                if (!array_key_exists('disposition', $row) && array_key_exists('is_default', $row)) {
                    $row['disposition'] = $row['is_default'];
                }
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Adds a stream to a media item.
     *
     * @param string $itemId The media item's unique identifier
     * @param array<string, mixed> $streamData Stream data including stream_index, stream_type, codec, etc.
     * @return string The unique identifier of the created stream
     */
    public function addStream(string $itemId, array $streamData): string
    {
        $idCandidate = $streamData['id'] ?? null;
        $id = is_string($idCandidate) ? $idCandidate : $this->generateUuid();
        $isDefaultRaw = $streamData['is_default'] ?? 0;
        $isDefault = is_numeric($isDefaultRaw) ? (int) $isDefaultRaw : 0;

        $this->db->query(
            "INSERT INTO media_streams
                (id, media_item_id, stream_index, stream_type, codec, language,
                 bitrate, channels, width, height, title, is_default,
                 color_space, color_transfer, color_primaries,
                 max_luminance, avg_luminance)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $itemId,
                $streamData['stream_index'],
                $streamData['stream_type'],
                $streamData['codec'] ?? null,
                $streamData['language'] ?? null,
                $streamData['bitrate'] ?? null,
                $streamData['channels'] ?? null,
                $streamData['width'] ?? null,
                $streamData['height'] ?? null,
                $streamData['title'] ?? null,
                $isDefault,
                $streamData['color_space'] ?? null,
                $streamData['color_transfer'] ?? null,
                $streamData['color_primaries'] ?? null,
                is_numeric($streamData['max_luminance'] ?? null)
                    ? (float) $streamData['max_luminance'] : null,
                is_numeric($streamData['avg_luminance'] ?? null)
                    ? (float) $streamData['avg_luminance'] : null,
            ]
        );

        return $id;
    }

    /**
     * Deletes every media_streams row belonging to a media item.
     *
     * Lets the scanner make stream persistence idempotent: on rescan the item's
     * existing stream rows are cleared and re-inserted from a fresh probe rather
     * than duplicated (the table has no unique key on media_item_id +
     * stream_index). No-op when the item has no streams.
     *
     * @param string $itemId The media item's unique identifier
     * @return void
     */
    public function deleteStreamsByItem(string $itemId): void
    {
        $this->db->query("DELETE FROM media_streams WHERE media_item_id = ?", [$itemId]);
    }

    /**
     * Stamps a media item's streams_probed_at marker (migration 071), recording
     * that its FULL media_streams set was persisted from a real ffprobe.
     *
     * Guards the lazy playback-info backfill ({@see \Phlix\Media\Library\StreamProbeBackfill}):
     * an item whose rows legitimately hold one audio track and zero subtitles
     * would otherwise look "unprobed" and be re-probed on every playback-info
     * request. Called by the scanner after every successful stream replacement
     * and by the lazy backfill itself (including on probe failure, to prevent
     * retry loops).
     *
     * @param string $itemId The media item's unique identifier
     * @return void
     */
    public function markStreamsProbed(string $itemId): void
    {
        $this->db->query(
            "UPDATE media_items SET streams_probed_at = NOW() WHERE id = ?",
            [$itemId]
        );
    }

    /**
     * Gets the intro marker columns for a media item.
     *
     * @param string $itemId The media item's unique identifier
     * @return array{start_seconds: int|null, end_seconds: int|null, confidence: int|null}|null
     *
     * @since 0.12.0
     */
    public function getIntroMarker(string $itemId): ?array
    {
        $result = $this->db->query(
            "SELECT intro_start_seconds, intro_end_seconds, intro_confidence FROM media_items WHERE id = ?",
            [$itemId]
        );

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return null;
        }

        $introStart = $firstRow['intro_start_seconds'] ?? null;
        $introEnd = $firstRow['intro_end_seconds'] ?? null;
        $introConf = $firstRow['intro_confidence'] ?? null;

        return [
            'start_seconds' => is_int($introStart) || is_float($introStart) ? (int) $introStart : null,
            'end_seconds' => is_int($introEnd) || is_float($introEnd) ? (int) $introEnd : null,
            'confidence' => is_int($introConf) || is_float($introConf) ? (int) $introConf : null,
        ];
    }

    /**
     * Gets the outro marker columns for a media item.
     *
     * @param string $itemId The media item's unique identifier
     * @return array{start_seconds: int|null, end_seconds: int|null, confidence: int|null}|null
     *
     * @since 0.12.0
     */
    public function getOutroMarker(string $itemId): ?array
    {
        $result = $this->db->query(
            "SELECT outro_start_seconds, outro_end_seconds, outro_confidence FROM media_items WHERE id = ?",
            [$itemId]
        );

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return null;
        }

        $outroStart = $firstRow['outro_start_seconds'] ?? null;
        $outroEnd = $firstRow['outro_end_seconds'] ?? null;
        $outroConf = $firstRow['outro_confidence'] ?? null;

        return [
            'start_seconds' => is_int($outroStart) || is_float($outroStart) ? (int) $outroStart : null,
            'end_seconds' => is_int($outroEnd) || is_float($outroEnd) ? (int) $outroEnd : null,
            'confidence' => is_int($outroConf) || is_float($outroConf) ? (int) $outroConf : null,
        ];
    }

    /**
     * Gets the chapters JSON for a media item.
     *
     * @param string $itemId The media item's unique identifier
     * @return array<mixed, mixed>|null
     *
     * @since 0.12.0
     */
    public function getChapters(string $itemId): ?array
    {
        $result = $this->db->query(
            "SELECT chapters_json FROM media_items WHERE id = ?",
            [$itemId]
        );

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $firstRow = $result[0];
        if (!is_array($firstRow)) {
            return null;
        }

        $chaptersJson = $firstRow['chapters_json'] ?? null;
        if ($chaptersJson === null) {
            return null;
        }

        if (is_string($chaptersJson)) {
            $chapters = json_decode($chaptersJson, true);
        } else {
            $chapters = $chaptersJson;
        }

        return is_array($chapters) ? $chapters : null;
    }

    /**
     * Updates the marker columns for a media item.
     *
     * @param string $itemId The media item's unique identifier
     * @param array<string, mixed> $markerData Marker data with optional keys:
     *   intro_start_seconds, intro_end_seconds, intro_confidence,
     *   outro_start_seconds, outro_end_seconds, outro_confidence,
     *   chapters_json
     *
     * @since 0.12.0
     */
    public function updateMarkers(string $itemId, array $markerData): void
    {
        $sets = [];
        $values = [];

        $markerColumns = [
            'intro_start_seconds',
            'intro_end_seconds',
            'intro_confidence',
            'outro_start_seconds',
            'outro_end_seconds',
            'outro_confidence',
        ];

        foreach ($markerColumns as $col) {
            if (array_key_exists($col, $markerData)) {
                $sets[] = "{$col} = ?";
                $values[] = $markerData[$col];
            }
        }

        if (isset($markerData['chapters_json'])) {
            $sets[] = "chapters_json = ?";
            $chapters = $markerData['chapters_json'];
            if (is_array($chapters)) {
                $chapters = json_encode($chapters);
            }
            $values[] = $chapters;
        }

        if (isset($markerData['trickplay_sprite_path'])) {
            $sets[] = "trickplay_sprite_path = ?";
            $values[] = $markerData['trickplay_sprite_path'];
        }
        if (isset($markerData['trickplay_timeline_path'])) {
            $sets[] = "trickplay_timeline_path = ?";
            $values[] = $markerData['trickplay_timeline_path'];
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $itemId;

        $this->db->query(
            "UPDATE media_items SET " . implode(', ', $sets) . " WHERE id = ?",
            $values
        );
    }

    /**
     * Batch creates multiple media items.
     *
     * @param array<int, array<string, mixed>> $items Array of media item data arrays
     * @return array<string> Array of created media item identifiers
     */
    public function batchCreate(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            $ids[] = $this->create($item);
        }

        return $ids;
    }

    /**
     * Content rating order mapping from least to most restrictive.
     *
     * @var array<string, int> Rating string to numeric order mapping
     */
    public const RATING_ORDER = [
        'G' => 1,
        'PG' => 2,
        'PG-13' => 3,
        'R' => 4,
        'NC-17' => 5,
        'X' => 6,
        'UNRATED' => 7,
    ];

    /**
     * Get items filtered by allowed content ratings.
     *
     * @param string $libraryId Library to filter
     * @param array<string> $allowedRatings Array of allowed rating strings (e.g., ['G', 'PG'])
     * @param int $limit Max items to return
     * @param int $offset Pagination offset
     * @return array<int, array<string, mixed>> Filtered media items ordered by rating restriction level
     */
    public function getByAllowedRatings(string $libraryId, array $allowedRatings, int $limit = 100, int $offset = 0): array
    {
        // Build CASE expression for rating order comparison. Reads the indexed,
        // materialized `content_rating` column (migration 050) rather than a
        // per-row JSON extraction.
        $ratingCases = [];
        foreach (self::RATING_ORDER as $rating => $order) {
            $ratingCases[] = "WHEN content_rating = '{$rating}' THEN {$order}";
        }
        $ratingOrderSql = 'CASE ' . implode(' ', $ratingCases) . ' ELSE 999 END';

        // Build rating filter
        $ratingPlaceholders = implode(',', array_fill(0, count($allowedRatings), '?'));

        // Rating restriction first, then an article-insensitive alphabetical tiebreak.
        $orderBy = $ratingOrderSql . ', ' . self::titleOrder();

        // Filter on the indexed `content_rating` column; a NULL column means the
        // item carries no rating (the old `JSON_EXTRACT(...) IS NULL` case).
        $results = $this->db->query(
            "SELECT * FROM media_items
             WHERE library_id = ?
               AND (
                   content_rating IN ({$ratingPlaceholders})
                   OR content_rating IS NULL
               )
             ORDER BY {$orderBy}
             LIMIT ? OFFSET ?",
            array_merge([$libraryId], $allowedRatings, [$limit, $offset])
        );

        return $this->hydrateRows($results);
    }

    /**
     * Get items filtered by a maximum content rating.
     *
     * @param string $libraryId Library to filter
     * @param string $maxRating Maximum allowed rating (e.g., 'R' excludes NC-17 and X)
     * @param int $limit Max items to return
     * @param int $offset Pagination offset
     * @return array<int, array<string, mixed>> Filtered media items
     */
    public function getByMaxRating(string $libraryId, string $maxRating, int $limit = 100, int $offset = 0): array
    {
        $maxOrder = self::RATING_ORDER[$maxRating] ?? 4;

        // Get all ratings up to and including maxRating
        $allowedRatings = [];
        foreach (self::RATING_ORDER as $rating => $order) {
            if ($order <= $maxOrder) {
                $allowedRatings[] = $rating;
            }
        }

        return $this->getByAllowedRatings($libraryId, $allowedRatings, $limit, $offset);
    }

    /**
     * Check if a media item's rating is within allowed ratings.
     *
     * @param string $itemId Media item ID to check
     * @param array<string> $allowedRatings Array of allowed rating strings
     * @return bool True if rating is allowed or item not found (safe default)
     */
    public function isRatingAllowed(string $itemId, array $allowedRatings): bool
    {
        $item = $this->findById($itemId);
        if (!$item) {
            return false;
        }

        $metadata = $item['metadata'] ?? null;
        $rating = is_array($metadata) && isset($metadata['rating'])
            ? $metadata['rating']
            : 'UNRATED';

        if ($rating === 'UNRATED') {
            return in_array('UNRATED', $allowedRatings);
        }

        return in_array($rating, $allowedRatings);
    }

    /**
     * Get items filtered by allowed genres.
     *
     * Reads the normalized `media_item_genres` join table (migration 051)
     * rather than a multi-valued functional index over `metadata_json.$.genres`
     * — that MVI reproducibly triggered real InnoDB purge-thread errors
     * (`[MY-012869]`) under sustained scan/rescan/re-match churn (confirmed via
     * a dedicated stress test; see migration 051's comment header), so it was
     * replaced with this ordinary, join-table-backed `EXISTS` test. The join
     * table is kept in sync by {@see insertGenreRows()}/{@see syncGenreRows()}
     * from `create()`/`update()` — `metadata_json.$.genres` remains the
     * canonical source of truth.
     *
     * `media_item_genres.genre` is `utf8mb4_bin` (case/accent-SENSITIVE), so
     * this `IN (...)` comparison is an exact-value match — the same semantics
     * the pre-051 `? MEMBER OF (metadata_json->'$.genres')` predicate had
     * (JSON string membership is exact-value). Do not add a query-time
     * `COLLATE` override here: it is unnecessary (the column already carries
     * the desired collation) and would defeat `idx_media_item_genres_genre`.
     *
     * @param string $libraryId Library to filter
     * @param array<string> $allowedGenres Array of allowed genre strings
     * @param int $limit Max items to return
     * @param int $offset Pagination offset
     * @return array<int, array<string, mixed>> Filtered media items
     */
    public function getByAllowedGenres(string $libraryId, array $allowedGenres, int $limit = 100, int $offset = 0): array
    {
        if (empty($allowedGenres)) {
            return $this->getByLibrary($libraryId, $limit, $offset);
        }

        // Items with at least one genre in the allowed set, OR items that carry
        // no genres at all (preserves the previous
        // `JSON_EXTRACT(metadata_json, '$.genres') IS NULL` fallback semantics —
        // an ungenred item is never filtered out by a genre allow-list).
        $genrePlaceholders = implode(',', array_fill(0, count($allowedGenres), '?'));

        $orderBy = self::titleOrder();

        $results = $this->db->query(
            "SELECT * FROM media_items
             WHERE library_id = ?
               AND (
                   EXISTS (
                       SELECT 1 FROM media_item_genres mig
                        WHERE mig.media_item_id = media_items.id
                          AND mig.genre IN ({$genrePlaceholders})
                   )
                   OR NOT EXISTS (
                       SELECT 1 FROM media_item_genres mig2
                        WHERE mig2.media_item_id = media_items.id
                   )
               )
             ORDER BY {$orderBy}
             LIMIT ? OFFSET ?",
            array_merge([$libraryId], array_values($allowedGenres), [$limit, $offset])
        );

        return $this->hydrateRows($results);
    }

    /**
     * Hydrates a database row with decoded metadata.
     *
     * @param array<string, mixed> $row Database row with metadata_json field
     * @return array<string, mixed> Row with added 'metadata' key containing decoded JSON
     */
    private function hydrateItem(array $row): array
    {
        $row['metadata_json'] = $row['metadata_json'] ?? '{}';
        if (is_string($row['metadata_json'])) {
            $row['metadata'] = json_decode($row['metadata_json'], true) ?? [];
        } else {
            $row['metadata'] = $row['metadata_json'];
        }
        return $row;
    }

    /**
     * Hydrates a list of raw DB rows into media item arrays, filtering out any
     * non-array entries that the database driver might return as `mixed`.
     *
     * @param mixed $results Raw result set from {@see Connection::query()}.
     * @return list<array<string, mixed>> Hydrated rows.
     */
    private function hydrateRows(mixed $results): array
    {
        if (!is_array($results)) {
            return [];
        }
        $out = [];
        foreach ($results as $row) {
            $normalized = $this->normalizeRow($row);
            if ($normalized !== null) {
                $out[] = $this->hydrateItem($normalized);
            }
        }
        return $out;
    }

    /**
     * Returns the first row of a query result if present and array-typed.
     *
     * @param mixed $results Raw result set from {@see Connection::query()}.
     * @return array<string, mixed>|null First row or null.
     */
    private function firstRow(mixed $results): ?array
    {
        if (!is_array($results) || count($results) === 0) {
            return null;
        }
        return $this->normalizeRow($results[0] ?? null);
    }

    /**
     * Coerces a single raw query row into a string-keyed associative array.
     *
     * @param mixed $row Raw row value.
     * @return array<string, mixed>|null
     */
    private function normalizeRow(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $out = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Finds series that have episodes without intro markers.
     *
     * An episode is considered "unfingerprinted" when intro_start_seconds is NULL.
     * Only returns shows that have at least one episode needing fingerprinting.
     *
     * @param int $limit Maximum number of show IDs to return (default 20)
     *
     * @return array<string> Array of show/series media item IDs with unfingerprinted episodes
     *
     * @since 0.12.0
     */
    public function findShowsWithUnfingerprintedEpisodes(int $limit = 20): array
    {
        $result = $this->db->query(
            "SELECT DISTINCT parent_id as show_id
             FROM media_items
             WHERE type IN ('episode', '_episode')
               AND parent_id IS NOT NULL
               AND intro_start_seconds IS NULL
             LIMIT ?",
            [$limit]
        );

        if (!is_array($result)) {
            return [];
        }

        $showIds = [];
        foreach ($result as $row) {
            if (is_array($row) && isset($row['show_id']) && is_string($row['show_id'])) {
                $showIds[] = $row['show_id'];
            }
        }

        return $showIds;
    }

    /**
     * Queries media items with flexible filtering, sorting, and pagination.
     *
     * Honors the library-query schema params over metadata_json, building on the
     * existing getByAllowedGenres() (an `EXISTS` test against the
     * `media_item_genres` join table, migration 051) and search() (FULLTEXT/LIKE)
     * patterns. All filter conditions are AND-combined; array-valued filters
     * (genres, ratings, actors) use OR logic within the array.
     *
     * @param array<string, mixed> $params Query parameters:
     *   - search (string|null): Full-text or fuzzy name search
     *   - genres (string[]|null): Filter to items with any of these genres
     *   - yearFrom (int|null): Minimum release year (inclusive)
     *   - yearTo (int|null): Maximum release year (inclusive)
     *   - ratings (string[]|null): Filter to items with any of these ratings
     *   - actors (string[]|null): Filter to items featuring any of these actors
     *   - sort (string): Sort field — name|year|rating|date_added|runtime (default: name)
     *   - order (string): Sort direction — asc|desc (default: asc)
     *   - limit (int): Max items to return 1-100 (default: 50)
     *   - offset (int): Items to skip for pagination (default: 0)
     *   - parentId (string|null): Scope to the direct children of one item (its
     *     seasons/episodes) — drives the series detail drill-down
     *   - topLevel (bool): Return only parent-less items (movies + series),
     *     excluding seasons/episodes — drives Browse rails/library grids. Ignored
     *     when `search` is set (so search still spans the whole library). Mutually
     *     exclusive with `parentId` (parentId wins).
     * @param string|null $libraryId Optional library ID to scope results to one library
     *
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     *
     * @since 0.13.0
     */
    public function query(array $params, ?string $libraryId = null): array
    {
        $filters = $this->buildFilters($params, $libraryId);
        $wheres = $filters['wheres'];
        $bindings = $filters['bindings'];
        $ratingJoin = $filters['ratingJoin'];
        $minRating = $filters['minRating'];

        $sortRaw = isset($params['sort']) && is_scalar($params['sort']) ? (string) $params['sort'] : 'name';
        $orderRaw = isset($params['order']) && is_scalar($params['order']) ? (string) $params['order'] : 'asc';
        $sort = $this->normalizeSortField($sortRaw);
        $order = $this->normalizeSortOrder($orderRaw);
        $limit = $this->normalizeLimit($params['limit'] ?? 50);
        $offset = $this->normalizeOffset($params['offset'] ?? 0);

        // P1-S2: When sort=rating, use the indexed rating_score column directly
        // instead of the costly LEFT JOIN + AVG() + GROUP BY approach. The
        // rating_score column on media_items is kept in sync by RatingService
        // and indexed for efficient ORDER BY / WHERE queries.
        $sortIsRating = $sort === 'rating_sort';

        $baseWhere = implode(' AND ', $wheres);
        $orderClause = $this->buildOrderClause($sort, $order);

        // P1-S2: Rating sort uses the denormalized rating_score column (indexed).
        // Items with no rating (rating_score IS NULL) sort to the end.
        if ($sortIsRating) {
            $orderClause = 'rating_score DESC, ' . self::titleOrder('desc');
        }

        // P1-S2: Rating filter uses the denormalized rating_score column (indexed).
        // Items with no rating (rating_score IS NULL) are excluded by the WHERE clause.
        if ($minRating !== null) {
            $wheres[] = 'rating_score >= ?';
            $bindings[] = $minRating;
            $baseWhere = implode(' AND ', $wheres);
        }

        // Note: $ratingJoin from buildFilters() is no longer used for rating
        // sort/filter; we use the indexed media_items.rating_score instead.
        // The buildFilters() method no longer sets $ratingJoin for minRating.

        $countSql = 'SELECT COUNT(*) as count FROM media_items WHERE ' . $baseWhere;
        $countResult = $this->db->query($countSql, $bindings);
        $total = $this->extractCount($countResult);

        $selectSql = 'SELECT * FROM media_items WHERE ' . $baseWhere . " ORDER BY {$orderClause} LIMIT ? OFFSET ?";
        $fetchBindings = array_merge($bindings, [$limit, $offset]);
        $results = $this->db->query($selectSql, $fetchBindings);

        /** @var list<array<string, mixed>> $items */
        $items = $this->hydrateRows($results);

        // P5-S2: filter items by profile tag restrictions (blocked/allowed tags).
        // $total must stay the COUNT(*) of the whole result set, only reduced by
        // what the tag filter removed from THIS page — overwriting it with
        // count($items) (== the page size) made the sparse library grid size its
        // virtual list at one page and truncate the library view.
        $pageCount = count($items);
        $items = $this->doFilterItemsByTags($items);
        $total = max(0, $total - ($pageCount - count($items)));

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Returns distinct value + count pairs for a given field, suitable for
     * passing to {@see IndexBuckets::build()} to produce the media index rail.
     *
     * The same expression is used in both GROUP BY and ORDER BY (via the shared
     * private helper methods), so they can never drift apart. The result is
     * sorted by bucket value {asc|desc} and bounded at 200 rows.
     *
     * @param string              $field     One of: name|year|rating|runtime|date_added.
     *                                       Unknown fields default to 'name'.
     * @param array<string, mixed> $params   Same media-query params as {@see self::query()}.
     * @param string|null         $libraryId Optional library UUID to scope results.
     *
     * @return list<array{value: string|int, count: int}> Bucket values with counts,
     *                sorted by value {asc|desc} as specified. The caller applies
     *                {@see IndexBuckets::build()} to produce cumulative offsets.
     */
    public function valueBuckets(string $field, array $params, ?string $libraryId = null): array
    {
        $order = isset($params['order']) && is_scalar($params['order'])
            ? strtolower((string) $params['order'])
            : 'asc';
        $desc = $order === 'desc';

        $bucketExpr = match ($field) {
            'name' => self::letterExpression(),
            'year' => self::yearValueExpression(),
            'rating' => self::ratingValueExpression(),
            'runtime' => self::runtimeValueExpression(),
            'date_added' => 'DATE(created_at)',
            'genre' => self::genrePrimaryExpression(),
            'artist' => self::artistValueExpression(),
            default => self::letterExpression(),
        };

        // The ORDER BY expression uses the private helper (includes ASC/DESC).
        // The GROUP BY uses the bare bucket expression.  They share the same
        // underlying column/expression so can never drift apart.
        $orderByExpr = match ($field) {
            'name' => self::letterExpression() . ($desc ? ' DESC' : ' ASC'),
            'year' => $this->yearSortExpression($desc),
            'rating' => $this->ratingSortExpression($desc),
            'runtime' => $this->runtimeSortExpression($desc),
            'date_added' => $this->createdAtSortExpression($desc),
            'genre' => self::genrePrimaryExpression() . ($desc ? ' DESC' : ' ASC'),
            'artist' => $this->artistSortExpression($desc),
            default => self::letterExpression() . ($desc ? ' DESC' : ' ASC'),
        };

        ['wheres' => $wheres, 'bindings' => $bindings] = $this->buildFilters($params, $libraryId);

        $sql = "SELECT {$bucketExpr} AS bucket_value, COUNT(*) AS item_count"
            . ' FROM media_items'
            . ' WHERE ' . implode(' AND ', $wheres)
            . " GROUP BY {$bucketExpr}"
            . " ORDER BY {$orderByExpr}"
            . ' LIMIT 200';

        $rows = $this->db->query($sql, $bindings);

        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rawValue = $row['bucket_value'];
                // Normalise to int|string (mysql YEAR() → int; others → string).  A
                // NULL from a buggy row is cast to '' so the return shape is always
                // int|string per the docblock and never mixed.
                $value = is_int($rawValue) ? $rawValue : (is_string($rawValue) ? $rawValue : '');
                $count = isset($row['item_count']) && is_numeric($row['item_count'])
                    ? (int) $row['item_count']
                    : 0;
                $out[] = ['value' => $value, 'count' => $count];
            }
        }

        return $out;
    }

    /** RELEASE year from metadata (NOT the row's created date). */
    private static function yearValueExpression(): string
    {
        return "CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.year')) AS SIGNED)";
    }
    /** Content-rating string (G/PG/…) — the materialized, indexed column (migration 050). */
    private static function ratingValueExpression(): string
    {
        return 'content_rating';
    }
    /** Runtime minutes from metadata. */
    private static function runtimeValueExpression(): string
    {
        return "CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.runtime')) AS SIGNED)";
    }
    /** Primary artist name from metadata (music libraries). */
    private static function artistValueExpression(): string
    {
        return "JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.artist'))";
    }

    /**
     * Year ORDER BY fragment — the RELEASE year (was YEAR(created_at), which
     * filed every recently-added title under one "this year" bucket).
     */
    private function yearSortExpression(bool $desc): string
    {
        return self::yearValueExpression() . ($desc ? ' DESC' : ' ASC');
    }

    /** Rating ORDER BY fragment (`rating_sort` was never a real column). */
    private function ratingSortExpression(bool $desc): string
    {
        return self::ratingValueExpression() . ($desc ? ' DESC' : ' ASC');
    }

    /** Runtime ORDER BY fragment (`runtime_sort` was never a real column). */
    private function runtimeSortExpression(bool $desc): string
    {
        return self::runtimeValueExpression() . ($desc ? ' DESC' : ' ASC');
    }

    /** Artist ORDER BY fragment (music libraries). */
    private function artistSortExpression(bool $desc): string
    {
        return self::artistValueExpression() . ($desc ? ' DESC' : ' ASC');
    }

    /**
     * created_at expression fragment — shared by GROUP BY and ORDER BY
     * so they can never drift apart.
     *
     * @param bool $desc Sort descending?
     * @return string SQL expression fragment, e.g. "created_at DESC"
     */
    private function createdAtSortExpression(bool $desc): string
    {
        $dir = $desc ? 'DESC' : 'ASC';

        return "created_at {$dir}";
    }

    /**
     * Build the WHERE clause + bindings shared by {@see self::query()} and
     * {@see self::letterCounts()} from the public media-query params. Sorting and
     * paging are NOT included (callers add those).
     *
     * @param array<string, mixed> $params
     *
     * @return array{wheres: list<string>, bindings: list<mixed>, ratingJoin: string, minRating: float|null}
     */
    private function buildFilters(array $params, ?string $libraryId): array
    {
        $wheres = ['1=1'];
        $bindings = [];
        $ratingJoin = '';
        $minRating = null;

        if ($libraryId !== null) {
            $wheres[] = 'library_id = ?';
            $bindings[] = $libraryId;
        }

        $search = isset($params['search']) && is_string($params['search']) ? $params['search'] : null;
        $genres = isset($params['genres']) && is_array($params['genres']) ? $params['genres'] : null;
        $yearFrom = isset($params['yearFrom']) && is_numeric($params['yearFrom']) ? (int) $params['yearFrom'] : null;
        $yearTo = isset($params['yearTo']) && is_numeric($params['yearTo']) ? (int) $params['yearTo'] : null;
        $ratings = isset($params['ratings']) && is_array($params['ratings']) ? $params['ratings'] : null;
        $actors = isset($params['actors']) && is_array($params['actors']) ? $params['actors'] : null;
        $companies = isset($params['companies']) && is_array($params['companies']) ? $params['companies'] : null;
        $match = isset($params['match']) && is_string($params['match']) ? $params['match'] : null;

        $parentId = isset($params['parentId']) && is_string($params['parentId']) && $params['parentId'] !== ''
            ? $params['parentId']
            : null;
        $topLevel = ($params['topLevel'] ?? false) === true;

        // Hierarchy scope. `parentId` (a series detail drill-down → its
        // seasons/episodes) wins over `topLevel`. `topLevel` restricts to
        // parent-less items (movies + series) so a series library shows shows,
        // not a flat dump of every episode — but it yields to an active search
        // so a title search still spans the whole library, episodes included.
        if ($parentId !== null) {
            $wheres[] = 'parent_id = ?';
            $bindings[] = $parentId;
        } elseif ($topLevel && ($search === null || $search === '')) {
            $wheres[] = 'parent_id IS NULL';
        }

        if ($search !== null && $search !== '') {
            $searchBindings = $this->buildSearchBindings($search);
            $wheres[] = $searchBindings['where'];
            $bindings = array_merge($bindings, $searchBindings['params']);
        }

        if ($genres !== null && count($genres) > 0) {
            $validGenres = array_values(array_filter(
                $genres,
                static fn (mixed $g): bool => is_string($g) && $g !== ''
            ));
            if (count($validGenres) > 0) {
                // Membership test against the `media_item_genres` join table
                // (migration 051), which replaced the migration 050 multi-valued
                // functional index over metadata_json.$.genres — that MVI
                // reproducibly triggered real InnoDB purge-thread errors under
                // sustained create/delete churn (see migration 051's comment
                // header). This EXISTS correlated subquery uses the join table's
                // plain PRIMARY KEY / idx_media_item_genres_genre B-tree index
                // instead. metadata_json.$.genres remains the canonical source
                // of truth; the join table is kept in sync by
                // insertGenreRows()/syncGenreRows() from create()/update().
                // `media_item_genres.genre` is
                // `utf8mb4_bin` (case/accent-SENSITIVE), so this `IN (...)`
                // reproduces the pre-051 `? MEMBER OF (...)` exact-match
                // semantics — do not add a query-time COLLATE override (the
                // column already carries it, and an override would defeat the
                // index).
                $genrePlaceholders = implode(',', array_fill(0, count($validGenres), '?'));
                $wheres[] = "EXISTS (
                    SELECT 1 FROM media_item_genres mig
                     WHERE mig.media_item_id = media_items.id
                       AND mig.genre IN ({$genrePlaceholders})
                )";
                $bindings = array_merge($bindings, $validGenres);
            }
        }

        if ($yearFrom !== null) {
            $wheres[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, "$.year")) AS SIGNED) >= ?';
            $bindings[] = $yearFrom;
        }

        if ($yearTo !== null) {
            $wheres[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, "$.year")) AS SIGNED) <= ?';
            $bindings[] = $yearTo;
        }

        if ($ratings !== null && count($ratings) > 0) {
            $ratingPlaceholders = implode(',', array_fill(0, count($ratings), '?'));
            // Filter on the indexed, materialized `content_rating` column
            // (migration 050) instead of a per-row JSON extraction.
            $wheres[] = "content_rating IN ({$ratingPlaceholders})";
            $bindings = array_merge($bindings, $ratings);
        }

        if ($actors !== null && count($actors) > 0) {
            $actorWheres = [];
            foreach ($actors as $actor) {
                if (is_string($actor) && $actor !== '') {
                    $escapedActor = addcslashes($actor, '%_');
                    // Match each actor array element independently (JSON_SEARCH over
                    // '$.actors[*]') so the LIKE can't span the serialized "," boundary
                    // between two names the way a flat JSON_EXTRACT+LIKE could. Cover
                    // BOTH stored shapes: the flat ["Name", …] list ('$.actors[*]')
                    // and the legacy TMDB [{name, …}, …] objects ('$.actors[*].name'),
                    // so the filter works before AND after a metadata re-match.
                    $actorWheres[] = "(JSON_SEARCH(metadata_json, 'one', ?, NULL, '\$.actors[*]') IS NOT NULL"
                        . " OR JSON_SEARCH(metadata_json, 'one', ?, NULL, '\$.actors[*].name') IS NOT NULL)";
                    $bindings[] = '%' . $escapedActor . '%';
                    $bindings[] = '%' . $escapedActor . '%';
                }
            }
            if (count($actorWheres) > 0) {
                $wheres[] = '(' . implode(' OR ', $actorWheres) . ')';
            }
        }

        if ($companies !== null && count($companies) > 0) {
            $companyWheres = [];
            foreach ($companies as $company) {
                if (is_string($company) && $company !== '') {
                    $escapedCompany = addcslashes($company, '%_');
                    // Match each production_companies array element by name via
                    // JSON_SEARCH over '$.production_companies[*].name' (the rich
                    // shape this feature adds), OR the legacy single '$.studio'
                    // string (exact match), so the filter works before AND after
                    // a metadata re-match. Multiple companies combine as OR (any).
                    $companyWheres[] = "(JSON_SEARCH(metadata_json, 'one', ?, NULL,"
                        . " '\$.production_companies[*].name') IS NOT NULL"
                        . " OR JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.studio')) = ?)";
                    $bindings[] = '%' . $escapedCompany . '%';
                    $bindings[] = $company;
                }
            }
            if (count($companyWheres) > 0) {
                $wheres[] = '(' . implode(' OR ', $companyWheres) . ')';
            }
        }

        // Match status. `metadata_refreshed_at` (migration 031) is stamped when
        // LibraryMetadataMatcher last enriched the item; NULL means it has never
        // been matched. Lets the UI surface "unmatched" items that still need a
        // metadata pass (or "matched" ones to review).
        if ($match === 'matched') {
            $wheres[] = 'metadata_refreshed_at IS NOT NULL';
        } elseif ($match === 'unmatched') {
            $wheres[] = 'metadata_refreshed_at IS NULL';
        }

        // Minimum numeric rating filter (e.g. ?minRating=7.5). Uses the
        // metadata_ratings table (migration 052) which holds TMDB/IMDb/user
        // scores. Items with no rating row get COALESCE(avg_rating, 0) = 0,
        // so they are excluded when minRating > 0.
        $minRatingRaw = isset($params['minRating']) && is_numeric($params['minRating'])
            ? (float) $params['minRating']
            : null;

        if ($minRatingRaw !== null) {
            $minRating = $minRatingRaw;
            // ratingJoin is built here but used in query() which adds GROUP BY.
            // We build it eagerly so callers that don't need GROUP BY can ignore it.
            $ratingJoin = 'LEFT JOIN metadata_ratings r ON r.media_item_id = media_items.id';
        }

        // array_values keeps `bindings` a positional list after the array_merge
        // calls above (they can widen the inferred key type).
        return [
            'wheres' => $wheres,
            'bindings' => array_values($bindings),
            'ratingJoin' => $ratingJoin,
            'minRating' => $minRating,
        ];
    }

    /**
     * Per-first-letter counts for the current query — drives the A-Z jump rail.
     * Honors the SAME filters as {@see self::query()} (via {@see self::buildFilters()}),
     * grouping by the uppercased first character of the article-stripped sort key
     * (so "The Plot" counts under P, mirroring the ORDER BY in {@see self::query()}).
     * Letters are returned unordered; the caller assigns cumulative offsets in the
     * list's sort order.
     *
     * @param array<string, mixed> $params Same media-query params as query().
     *
     * @return list<array{letter: string, count: int}>
     */
    public function letterCounts(array $params, ?string $libraryId = null): array
    {
        ['wheres' => $wheres, 'bindings' => $bindings] = $this->buildFilters($params, $libraryId);

        // Bucket by the first letter of the article-stripped sort key (so
        // "The Plot" counts under P), matching the ORDER BY in self::query()
        // so the cumulative letter offsets line up with the grid.
        $letterExpr = self::letterExpression();
        $sql = "SELECT {$letterExpr} AS letter, COUNT(*) AS n FROM media_items WHERE "
            . implode(' AND ', $wheres) . ' GROUP BY letter';
        $rows = $this->db->query($sql, $bindings);

        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rawLetter = $row['letter'] ?? null;
                // An empty sort key (a name that is only an article like "The ",
                // or all whitespace) yields LEFT('',1)='' — bucket it under '#'
                // (where the empty key also sorts, first) instead of dropping it,
                // so the rail's cumulative offsets stay aligned with the grid.
                // The router (getLetterIndex) likewise folds every non-A-Z letter
                // into '#'. NOTE: an accented/non-Latin INITIAL letter (after
                // stripping, e.g. "Élan" or a Cyrillic title) is returned as-is
                // and folded to '#' by the router, yet sorts in its unicode_ci
                // position in the grid — a pre-existing rail/grid skew for
                // multilingual libraries, out of scope for the article rule.
                $letter = is_string($rawLetter) && $rawLetter !== '' ? $rawLetter : '#';
                $count = isset($row['n']) && is_numeric($row['n']) ? (int) $row['n'] : 0;
                $out[] = ['letter' => $letter, 'count' => $count];
            }
        }

        return $out;
    }

    /**
     * The DISTINCT, sorted set of genres present across media items — the
     * authoritative genre facet list for the media-filter UI.
     *
     * Reads the normalized `media_item_genres` join table (migration 051)
     * rather than unnesting `metadata_json.$.genres` set-side via `JSON_TABLE`
     * on every call (the pre-051 approach, and the original P1 finding this
     * was written to fix — an unbounded `SELECT *` scan). The join table is
     * kept in sync with `metadata_json.$.genres` — still the canonical source
     * of truth — by {@see insertGenreRows()}/{@see syncGenreRows()} from
     * `create()`/`update()`, so this DISTINCT read is now a plain indexed join
     * instead of a per-row JSON
     * unnest. `media_item_genres.genre` only ever holds non-empty strings (see
     * {@see extractGenres()}'s filter on the write path), so — unlike the old
     * JSON_TABLE query — this SELECT needs no `genre IS NOT NULL`/`genre <> ''`
     * guard; there is nothing else to filter out.
     *
     * DELIBERATE COLLATION CHOICE (S7b fix round): `media_item_genres.genre`
     * is declared `utf8mb4_bin` (case/accent-SENSITIVE) so the genre
     * *filtering* predicates ({@see getByAllowedGenres()}, {@see
     * buildFilters()}'s genre block) exactly reproduce the pre-051
     * `? MEMBER OF (metadata_json->'$.genres')` exact-match semantics — see
     * migration 051's comment header. Left unchecked, that same `_bin` column
     * would ALSO make this facet-list DISTINCT case/accent-sensitive (two
     * differently-cased spellings of the same genre would list as two facets)
     * — a NEW behavior change this method never had (its pre-051 `JSON_TABLE`
     * read used the connection's default case-insensitive collation, and this
     * facet list is user/UI-facing, not a filter predicate). The query below
     * explicitly re-asserts `COLLATE utf8mb4_unicode_ci` on the selected/
     * ordered `genre` value so the facet list's DISTINCT/ORDER BY stay
     * case-insensitive exactly as before, independent of the now
     * case-sensitive storage/index collation used for filtering.
     *
     * Scoped to one library when `$libraryId` is given (bound, never
     * interpolated); otherwise it spans every library — the caller is
     * responsible for the auth gate that decides which libraries the request
     * may see (mirrors {@see self::query()}, which is likewise unscoped by
     * default and gated at the route).
     *
     * @param string|null $libraryId Optional library UUID to scope the facet
     *                                set to one library.
     *
     * @return list<string> Distinct genre names, ascending, de-duplicated,
     *                       non-empty. Empty when no items / no genres.
     */
    public function distinctGenres(?string $libraryId = null): array
    {
        // Served from the in-worker TTL cache when a fresh entry exists so the
        // media_item_genres join query below runs at most once per scope per TTL
        // window (see $genreFacetCache). Cache misses/stale entries fall through
        // and recompute.
        $cacheKey = $libraryId ?? self::GENRE_FACET_GLOBAL_KEY;
        $now = $this->monotonicMs();

        $cached = $this->genreFacetCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expires_at'] > $now) {
            // LRU touch: move to the MRU position so hot scopes outlive eviction.
            unset($this->genreFacetCache[$cacheKey]);
            $this->genreFacetCache[$cacheKey] = $cached;
            return $cached['genres'];
        }

        // Read the join table + its owning media_items row (needed only to scope
        // by library_id) and let DISTINCT + ORDER BY run set-side, exactly as the
        // JSON_TABLE version did, but now over the indexed `media_item_genres`
        // table instead of unnesting metadata_json per row.
        $wheres = [];
        $bindings = [];

        if ($libraryId !== null) {
            $wheres[] = 'mi.library_id = ?';
            $bindings[] = $libraryId;
        }

        $whereSql = $wheres === [] ? '1=1' : implode(' AND ', $wheres);

        // `COLLATE utf8mb4_unicode_ci` re-asserted here (NOT relying on the
        // column's own now-`utf8mb4_bin` collation, adopted for the exact-match
        // filter predicates) so this facet list's DISTINCT/ORDER BY stay
        // case-insensitive exactly as the pre-051 JSON_TABLE read was — see this
        // method's docblock for why the storage collation and the facet-list
        // collation are deliberately allowed to differ.
        $sql = "SELECT DISTINCT mig.genre COLLATE utf8mb4_unicode_ci AS genre
                FROM media_item_genres mig
                JOIN media_items mi ON mi.id = mig.media_item_id
                WHERE {$whereSql}
                ORDER BY genre ASC";

        $rows = $this->db->query($sql, $bindings);

        $genres = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['genre']) && is_string($row['genre']) && $row['genre'] !== '') {
                    $genres[] = $row['genre'];
                }
            }
        }

        // Populate the in-worker cache and bound its size with oldest-first
        // eviction — the scope key can carry a caller-supplied libraryId, so the
        // map must never grow unbounded in a resident worker. unset() first: a
        // plain value-only reassignment of an EXISTING key (the stale-entry
        // recompute path) leaves that key in its ORIGINAL array position in PHP,
        // NOT the end — without the unset(), array_key_first() below could evict
        // a genuinely-stale scope while a just-recomputed (freshest) entry stays
        // stuck near the front and gets dropped first on a subsequent overflow.
        unset($this->genreFacetCache[$cacheKey]);
        $this->genreFacetCache[$cacheKey] = [
            'genres' => $genres,
            'expires_at' => $now + self::GENRE_FACET_CACHE_TTL_MS,
        ];
        if (count($this->genreFacetCache) > self::GENRE_FACET_CACHE_MAX) {
            $oldest = array_key_first($this->genreFacetCache);
            if ($oldest !== null) {
                unset($this->genreFacetCache[$oldest]);
            }
        }

        return $genres;
    }

    /**
     * Invalidate cached genre facets so the next {@see distinctGenres()} call
     * recomputes from the DB.
     *
     * Call after any write that can change the genre set. Pass the owning library
     * to drop just that scope plus the all-libraries scope (which spans it); pass
     * null to flush every scope when the affected library is unknown (e.g. an
     * {@see update()} that rewrites `metadata_json`, or a single-item
     * {@see delete()} whose owning library was not resolved).
     *
     * In-worker only: this clears the calling worker's map. Cross-worker /
     * cross-process readers (a scan runs in its own worker) re-converge via the
     * cache TTL — see the {@see $genreFacetCache} contract.
     *
     * @param string|null $libraryId Owning library UUID, or null to flush all scopes.
     */
    public function invalidateGenreFacets(?string $libraryId = null): void
    {
        if ($libraryId === null) {
            $this->genreFacetCache = [];
            return;
        }

        // A library-scoped write also changes the all-libraries facet set, so drop
        // both the library's own scope and the global scope.
        unset(
            $this->genreFacetCache[$libraryId],
            $this->genreFacetCache[self::GENRE_FACET_GLOBAL_KEY]
        );
    }

    /**
     * Monotonic millisecond clock for the genre-facet cache TTL — immune to
     * wall-clock jumps (NTP / DST), per the repo's `hrtime(true)` convention.
     */
    private function monotonicMs(): int
    {
        return (int) (hrtime(true) / 1_000_000);
    }

    /**
     * Normalizes the sort field to a safe column name.
     *
     * @param string $sort Raw sort field from query param
     * @return string Safe column name (always one of: name, year, rating, date_added, runtime)
     */
    private function normalizeSortField(string $sort): string
    {
        return match ($sort) {
            'year' => 'year_sort',
            'rating' => 'rating_sort',
            'date_added' => 'created_at',
            'runtime' => 'runtime_sort',
            'genre' => 'genre_sort',
            'artist' => 'artist_sort',
            default => 'name',
        };
    }

    /**
     * SQL expression for an item's PRIMARY (first) genre — shared by the genre
     * sort ORDER BY and the genre index buckets so they file items identically.
     *
     * Intentionally UNCHANGED by migrations 050/051: this reads
     * `metadata_json.$.genres[0]` directly via `JSON_EXTRACT` — a plain
     * per-row value extraction for sort/bucket display, never a membership
     * filter — so it never touched the multi-valued functional index 051
     * removed and has no join-table equivalent to migrate to.
     */
    private static function genrePrimaryExpression(): string
    {
        return "JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.genres[0]'))";
    }

    /**
     * Normalizes the sort order to 'asc' or 'desc'.
     *
     * @param string $order Raw order param
     * @return string Normalized order
     */
    private function normalizeSortOrder(string $order): string
    {
        return strtolower($order) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Normalizes the limit to an integer between 1 and 100.
     *
     * @param mixed $limit Raw limit value
     * @return int Normalized limit
     */
    private function normalizeLimit(mixed $limit): int
    {
        $l = is_numeric($limit) ? (int) $limit : 50;
        if ($l < 1) {
            return 1;
        }
        if ($l > 100) {
            return 100;
        }
        return $l;
    }

    /**
     * Normalizes the offset to a non-negative integer.
     *
     * @param mixed $offset Raw offset value
     * @return int Normalized offset
     */
    private function normalizeOffset(mixed $offset): int
    {
        $o = is_numeric($offset) ? (int) $offset : 0;
        return $o < 0 ? 0 : $o;
    }

    /**
     * Builds the WHERE clause and bindings for a search parameter.
     *
     * Uses MySQL FULLTEXT search with boolean mode, falling back to a LIKE-based
     * scan when FULLTEXT raises a syntax error (e.g. operator-only queries).
     *
     * @param string $search Search query
     * @return array{where: string, params: array<string>}
     */
    private function buildSearchBindings(string $search): array
    {
        $escapedSearch = addcslashes($search, '%_');

        return [
            'where' => '(MATCH(name) AGAINST(? IN BOOLEAN MODE) OR name LIKE ?)',
            'params' => [$search, '%' . $escapedSearch . '%'],
        ];
    }

    /**
     * Builds the ORDER BY clause from a normalized sort field and order.
     *
     * Uses CASE expressions to map string ratings to numeric sort order, matching
     * the existing RATING_ORDER mapping used by getByAllowedRatings().
     *
     * @param string $sort Normalized sort field
     * @param string $order Sort direction
     * @return string Safe ORDER BY clause
     */
    private function buildOrderClause(string $sort, string $order): string
    {
        $direction = $order === 'desc' ? 'DESC' : 'ASC';

        // Secondary alphabetical tiebreak ignores a leading article too (so two
        // items with the same year/rating/runtime still file "The Plot" under P).
        $titleTie = self::titleOrder($direction);

        if ($sort === 'year_sort') {
            return "CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.year')) AS SIGNED) {$direction}, {$titleTie}";
        }

        if ($sort === 'rating_sort') {
            $ratingCases = [];
            foreach (self::RATING_ORDER as $rating => $orderVal) {
                // Map the materialized `content_rating` column (migration 050) to
                // its restriction rank rather than extracting from the JSON blob.
                $ratingCases[] = "WHEN content_rating = '{$rating}' THEN {$orderVal}";
            }
            $ratingOrderSql = 'CASE ' . implode(' ', $ratingCases) . ' ELSE 999 END';
            return "{$ratingOrderSql} {$direction}, {$titleTie}";
        }

        if ($sort === 'runtime_sort') {
            return "CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.runtime')) AS SIGNED) {$direction}, {$titleTie}";
        }

        if ($sort === 'genre_sort') {
            // File each item under its primary (first) genre, alphabetically.
            return self::genrePrimaryExpression() . " {$direction}, {$titleTie}";
        }

        if ($sort === 'artist_sort') {
            return self::artistValueExpression() . " {$direction}, {$titleTie}";
        }

        // Default name sort files "The Plot" under P. `date_added` (→ created_at)
        // and any other safe column keep their natural ordering.
        if ($sort === 'name') {
            return $titleTie;
        }

        return "{$sort} {$direction}";
    }

    /**
     * `ORDER BY` fragment for an article-insensitive alphabetical listing: the
     * article-stripped sort key first (so "The Plot" files under P), then the raw
     * `name` as a stable tiebreaker for distinct titles that share a sort key.
     *
     * @param string $direction 'asc'/'desc' (any case); anything else → ASC.
     * @return string e.g. "TRIM(CASE … END) ASC, name ASC".
     */
    private static function titleOrder(string $direction = 'ASC'): string
    {
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        // Order by the materialized, indexed `sort_title` column (migration 050)
        // — value-identical to the old SortTitle::sqlExpression() runtime CASE, so
        // the order is unchanged, but now index-satisfiable (no per-row function).
        // The `name` tiebreak keeps distinct titles that share a sort key stably
        // paged; it is the trailing key of idx_media_items_library_type_sort_title
        // so the listing sorts with no filesort.
        return "sort_title {$dir}, name {$dir}";
    }

    /**
     * SQL expression for the uppercased first letter of an item's sort key — the
     * A-Z jump-rail bucket. Reads the materialized `sort_title` column
     * (migration 050) instead of recomputing SortTitle::letterSqlExpression()'s
     * CASE per row, so the grid ORDER BY and the letter buckets share the same
     * indexed column and can never drift.
     */
    private static function letterExpression(): string
    {
        return 'UPPER(LEFT(sort_title, 1))';
    }

    /**
     * Extracts a `count` aggregate from a `SELECT COUNT(*) as count` result set.
     *
     * @param mixed $results Raw result set from {@see Connection::query()}.
     */
    private function extractCount(mixed $results): int
    {
        $row = $this->firstRow($results);
        if ($row === null) {
            return 0;
        }
        $count = $row['count'] ?? 0;
        if (is_int($count)) {
            return $count;
        }
        if (is_string($count) && is_numeric($count)) {
            return (int) $count;
        }
        if (is_float($count)) {
            return (int) $count;
        }
        return 0;
    }

    /**
     * Query media items by smart-collection rules.
     *
     * Applies one or more rule descriptors (type + value) against the media_items
     * table using the SQL clause builders in {@see SmartRuleVocabulary}.
     * Combines all rules with AND logic. Optionally scoped to a single library.
     *
     * @param array<array{type: string, value: mixed}> $rules     List of rule descriptors
     * @param array<string, mixed>                    $params    Optional params (sort, order, limit, offset)
     * @param string|null                             $libraryId Optional library UUID scope
     *
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function queryWithSmartRules(array $rules, array $params = [], ?string $libraryId = null): array
    {
        if ($rules === []) {
            return ['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0];
        }

        $wheres = ['1=1'];
        $bindings = [];
        $needsRatingJoin = false;

        foreach ($rules as $rule) {
            $type = isset($rule['type']) && is_string($rule['type']) ? $rule['type'] : '';
            if ($type === '') {
                continue;
            }

            if (!isset(SmartRuleVocabulary::RULES[$type])) {
                continue;
            }

            $ruleParams = ['value' => $rule['value'] ?? null];
            $result = (SmartRuleVocabulary::RULES[$type])($ruleParams);

            foreach ($result['wheres'] as $where) {
                $wheres[] = $where;
            }
            foreach ($result['bindings'] as $binding) {
                $bindings[] = $binding;
            }

            if (isset($result['needsRatingJoin']) && $result['needsRatingJoin'] === true) {
                $needsRatingJoin = true;
            }
        }

        if ($libraryId !== null) {
            $wheres[] = 'library_id = ?';
            $bindings[] = $libraryId;
        }

        $baseWhere = implode(' AND ', $wheres);

        $sortRaw = isset($params['sort']) && is_scalar($params['sort']) ? (string) $params['sort'] : 'name';
        $orderRaw = isset($params['order']) && is_scalar($params['order']) ? (string) $params['order'] : 'asc';
        $sort = $this->normalizeSortField($sortRaw);
        $order = $this->normalizeSortOrder($orderRaw);
        $limit = $this->normalizeLimit($params['limit'] ?? 50);
        $offset = $this->normalizeOffset($params['offset'] ?? 0);
        $orderClause = $this->buildOrderClause($sort, $order);

        if ($needsRatingJoin) {
            $ratingJoin = 'LEFT JOIN metadata_ratings r ON r.media_item_id = media_items.id';

            $countSql = "SELECT COUNT(DISTINCT media_items.id) as count"
                . " FROM media_items {$ratingJoin} WHERE {$baseWhere}";
            $countResult = $this->db->query($countSql, $bindings);
            $total = $this->extractCount($countResult);

            // Rating sort: ORDER BY average numeric score from metadata_ratings.
            if ($sort === 'rating_sort') {
                $orderClause = 'avg_rating DESC, ' . self::titleOrder('desc');
            }

            $selectSql = "SELECT media_items.*, AVG(r.score) AS avg_rating"
                . " FROM media_items {$ratingJoin} WHERE {$baseWhere}"
                . " GROUP BY media_items.id"
                . " ORDER BY {$orderClause} LIMIT ? OFFSET ?";
            $fetchBindings = array_merge($bindings, [$limit, $offset]);
            $results = $this->db->query($selectSql, $fetchBindings);
        } else {
            $countSql = 'SELECT COUNT(*) as count FROM media_items WHERE ' . $baseWhere;
            $countResult = $this->db->query($countSql, $bindings);
            $total = $this->extractCount($countResult);

            $selectSql = 'SELECT * FROM media_items WHERE ' . $baseWhere . " ORDER BY {$orderClause} LIMIT ? OFFSET ?";
            $fetchBindings = array_merge($bindings, [$limit, $offset]);
            $results = $this->db->query($selectSql, $fetchBindings);
        }

        return [
            'items' => $this->hydrateRows($results),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Generates a v4 UUID for media item and stream identifiers.
     *
     * @return string A formatted UUID string (xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx)
     */
    private function generateUuid(): string
    {
        return Uuid::v4();
    }

    /**
     * Filter items by profile tag restrictions (P5-S2).
     *
     * Checks the current profile's tag restrictions from profile_tags table:
     * - Blocked tags: items containing any blocked tag are excluded
     * - Allowed tags: if an allow-list exists, only items with at least one
     *   allowed tag are included (after blocked tag filtering)
     *
     * Tags are read from the item's tags_json column (JSON array of strings).
     *
     * @param list<array<string, mixed>> $items Hydrated media items to filter.
     *
     * @return list<array<string, mixed>> Filtered items.
     */
    private function doFilterItemsByTags(array $items): array
    {
        $profileId = RequestContext::getProfileId();
        if ($profileId === null) {
            return $items;
        }

        // Get blocked and allowed tags for this profile
        /** @var array<array<string, mixed>> $blockedRows */
        $blockedRows = $this->db->query(
            'SELECT tag FROM profile_tags WHERE profile_id = ? AND tag_type = ?',
            [$profileId, 'blocked'],
        );

        /** @var array<array<string, mixed>> $allowedRows */
        $allowedRows = $this->db->query(
            'SELECT tag FROM profile_tags WHERE profile_id = ? AND tag_type = ?',
            [$profileId, 'allowed'],
        );

        // Build tag arrays
        $blockedTags = [];
        if (is_array($blockedRows)) {
            foreach ($blockedRows as $row) {
                if (is_array($row) && isset($row['tag']) && is_string($row['tag'])) {
                    $blockedTags[] = $row['tag'];
                }
            }
        }

        $allowedTags = [];
        if (is_array($allowedRows)) {
            foreach ($allowedRows as $row) {
                if (is_array($row) && isset($row['tag']) && is_string($row['tag'])) {
                    $allowedTags[] = $row['tag'];
                }
            }
        }

        // No restrictions at all
        if ($blockedTags === [] && $allowedTags === []) {
            return $items;
        }

        // Filter items
        $filtered = [];
        foreach ($items as $item) {
            if (!$this->itemMatchesTagRestrictions($item, $blockedTags, $allowedTags)) {
                continue;
            }
            $filtered[] = $item;
        }

        /** @var list<array<string, mixed>> */
        return $filtered;
    }

    /**
     * Filter a list of items by the current profile's tag restrictions (P5-S2).
     *
     * Public wrapper around {@see self::filterItemsByTags()} so callers
     * (controllers, other services) can apply tag filtering to arbitrary
     * item sets without duplicating the restriction logic.
     *
     * @param list<array<string, mixed>> $items Hydrated media items to filter.
     *
     * @return list<array<string, mixed>> Filtered items.
     */
    public function filterItemsByTags(array $items): array
    {
        return $this->doFilterItemsByTags($items);
    }

    /**
     * Check if a single item matches the tag restrictions.
     *
     * @param array<string, mixed> $item         Media item to check.
     * @param list<string>          $blockedTags  Blocked tags for the profile.
     * @param list<string>          $allowedTags  Allowed tags for the profile.
     *
     * @return bool True if the item passes tag restrictions.
     */
    private function itemMatchesTagRestrictions(array $item, array $blockedTags, array $allowedTags): bool
    {
        // Get tags from the item's metadata_json['tags']
        $metadata = $item['metadata'] ?? null;
        $itemTags = [];

        if (is_array($metadata) && isset($metadata['tags']) && is_array($metadata['tags'])) {
            foreach ($metadata['tags'] as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $itemTags[] = $tag;
                }
            }
        }

        // Check blocked tags first - if item has any blocked tag, it's excluded
        if ($blockedTags !== []) {
            foreach ($itemTags as $itemTag) {
                if (in_array($itemTag, $blockedTags, true)) {
                    return false;
                }
            }
        }

        // If there's an allowed list, item must have at least one allowed tag
        if ($allowedTags !== []) {
            foreach ($itemTags as $itemTag) {
                if (in_array($itemTag, $allowedTags, true)) {
                    return true;
                }
            }
            return false; // Item has no allowed tag
        }

        return true;
    }
}
