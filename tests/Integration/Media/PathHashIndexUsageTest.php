<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Media\Library\ItemRepository;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * SV-0.8 real-DB proof that the scanner's path lookups
 * ({@see ItemRepository::findByPath()} / {@see ItemRepository::findPathsMap()})
 * actually resolve through the `(library_id, path_hash)` unique index (migration
 * 072's column + migration 096's index) instead of full-scanning `media_items` on every scan
 * / rescan. A mocked connection cannot exercise the optimizer, so this runs
 * `EXPLAIN` against the queries those methods emit and asserts the composite
 * index is applicable and — when forced — provides a non-full-scan access path.
 *
 * The bug this guards against: before SV-0.8, `findPathsMap` bound only
 * `path_hash IN (...)` and omitted `library_id`. The migration-072 index leads
 * with `library_id`, so a predicate that does not bind the leading column
 * cannot use the index left-prefix-first — the hot batch path full-scanned
 * `media_items` (type=ALL) for every scan batch. SV-0.8 leads the predicate
 * with `library_id = ?`, restoring index use.
 *
 * INDEX AVAILABILITY: as of `migrations/096_path_hash_unique_index.sql` (S152)
 * `run-migrations.php` DOES create the UNIQUE index, so on a current database
 * the self-heal below is a no-op. It is kept for databases whose chain has not
 * reached 096 — historically the index was created only by the manual
 * `migrations/cleanup_072.php` (kept out of a plain migration because a DB with
 * pre-existing duplicate paths would make an inline `ADD UNIQUE INDEX` fail with
 * 1062 — see 072's header), and 087 dropped it. So this test creates the index
 * itself when absent (idempotently, on its own freshly-seeded rows) and drops it
 * again in tearDown only if it was the creator, leaving the schema as it found
 * it. If pre-existing data prevents the unique index, the test self-skips rather
 * than failing — the index-usage claim is unprovable without the index, but that
 * is an environment gap, not a code defect. That the migration chain leaves the
 * index in place is asserted separately, by
 * {@see PathHashUniqueIndexPresentTest}.
 *
 * Like {@see BrowseIndexUsageTest}, with no reachable MySQL the test self-skips.
 *
 */
final class PathHashIndexUsageTest extends TestCase
{
    use RequiresRealDatabase;

    private const PATH_HASH_INDEX = 'idx_media_items_library_path_hash';

    /** Rows to seed — enough to give a full scan a real cost to beat. */
    private const ROW_COUNT = 300;

    private const PATH_PREFIX = '/tmp/phlix-path-hash-index-test/';

    private ?Connection $db = null;

    private string $libraryId = '';

    /** True only when THIS test created the unique index (so tearDown drops it). */
    private bool $createdIndex = false;

    /** @var list<string> the absolute paths this test seeded, for the IN(...) probe */
    private array $seededPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase(
            'skipping path_hash index-usage EXPLAIN test. Runs in CI / docker-compose.',
        );

        // Migration 072 adds the path_hash generated column; without it there is
        // nothing to index. Self-skip rather than error on a stale schema.
        if (!$this->hasPathHashColumn()) {
            $this->markTestSkipped(
                'media_items.path_hash absent — migration 072 not applied; skipping index-usage test.',
            );
        }

        $db = $this->db();
        $this->libraryId = $this->uuid();
        $db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'Path Hash Index Usage Lib', 'movie', json_encode([rtrim(self::PATH_PREFIX, '/')])],
        );

        $repo = new ItemRepository($db);
        for ($i = 0; $i < self::ROW_COUNT; $i++) {
            $name = sprintf('Path Hash Fixture %03d', $i);
            // A real, non-empty path of a deduped type (movie) → the generated
            // path_hash column is non-NULL and therefore covered by the index.
            $path = self::PATH_PREFIX . md5($name) . '.mkv';
            $this->seededPaths[] = $path;
            $repo->create([
                'library_id' => $this->libraryId,
                'name' => $name,
                'type' => 'movie',
                'path' => $path,
                'metadata_json' => ['year' => 2000 + ($i % 25)],
            ]);
        }

        // The migration chain adds the unique index as of 096, so this is a
        // no-op on a current database. Ensure it exists anyway (pre-096 chains)
        // so the optimizer actually has it to use; self-skip if pre-existing
        // data prevents a unique constraint.
        $this->ensurePathHashIndex();

        // Fresh, deterministic index statistics for the cost-based optimizer.
        $db->query('ANALYZE TABLE media_items');
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            if ($this->createdIndex) {
                // Restore the schema to how we found it (the index is a
                // post-migration artifact, not part of run-migrations.php).
                try {
                    $this->db->query('ALTER TABLE media_items DROP INDEX ' . self::PATH_HASH_INDEX);
                } catch (Throwable) {
                    // Best-effort cleanup; a leftover index is harmless (it is
                    // exactly what production carries).
                }
            }
            if ($this->libraryId !== '') {
                // ON DELETE CASCADE removes the seeded media_items rows.
                $this->db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
            }
        }
        parent::tearDown();
    }

    /**
     * The batched scanner lookup ({@see ItemRepository::findPathsMap()}:
     * `library_id = ? AND path_hash IN (...)`) must resolve through the
     * `(library_id, path_hash)` index — NOT a full table scan. This is the hot
     * path SV-0.8 fixed: without the leading `library_id`, the composite index
     * is unusable and the batch full-scans `media_items`.
     */
    public function testFindPathsMapUsesLibraryPathHashIndexNotFullScan(): void
    {
        $probePaths = array_slice($this->seededPaths, 0, 5);
        $hashes = array_map('sha1', $probePaths);
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));

        $sql = "SELECT * FROM media_items WHERE library_id = ? AND path_hash IN ({$placeholders})";
        $params = array_merge([$this->libraryId], $hashes);

        // The index must be APPLICABLE to the batched lookup (in possible_keys).
        $natural = $this->explain($sql, $params);
        $this->assertStringContainsString(
            self::PATH_HASH_INDEX,
            $this->planStr($natural, 'possible_keys'),
            'The (library_id, path_hash) index must be APPLICABLE to the batched findPathsMap lookup '
            . '(present in possible_keys). Plan: ' . $this->planJson($natural),
        );

        // Structural proof: forcing the index makes it the chosen key and yields
        // a non-full-scan (range) access path — never type=ALL.
        $forced = $this->explain(
            "SELECT * FROM media_items FORCE INDEX (" . self::PATH_HASH_INDEX . ") "
            . "WHERE library_id = ? AND path_hash IN ({$placeholders})",
            $params,
        );
        $this->assertSame(
            self::PATH_HASH_INDEX,
            $this->planStr($forced, 'key'),
            'Forcing the composite index must make it the chosen key. Plan: ' . $this->planJson($forced),
        );
        $this->assertNotSame(
            'ALL',
            $this->planStr($forced, 'type'),
            'The batched path lookup must NOT be a full table scan (type=ALL) — it is a range scan on the '
            . 'composite index. Plan: ' . $this->planJson($forced),
        );
    }

    /**
     * The single-row lookup ({@see ItemRepository::findByPath()}:
     * `library_id = ? AND path_hash = ? AND path = ?`) must resolve through the
     * same index as a point/ref lookup — not a full scan.
     */
    public function testFindByPathUsesLibraryPathHashIndexNotFullScan(): void
    {
        $path = $this->seededPaths[0];
        $sql = 'SELECT * FROM media_items WHERE library_id = ? AND path_hash = ? AND path = ?';
        $params = [$this->libraryId, sha1($path), $path];

        $natural = $this->explain($sql, $params);
        $this->assertStringContainsString(
            self::PATH_HASH_INDEX,
            $this->planStr($natural, 'possible_keys'),
            'The (library_id, path_hash) index must be APPLICABLE to findByPath (present in possible_keys). '
            . 'Plan: ' . $this->planJson($natural),
        );

        $forced = $this->explain(
            'SELECT * FROM media_items FORCE INDEX (' . self::PATH_HASH_INDEX . ') '
            . 'WHERE library_id = ? AND path_hash = ? AND path = ?',
            $params,
        );
        $this->assertSame(
            self::PATH_HASH_INDEX,
            $this->planStr($forced, 'key'),
            'Forcing the composite index must make it the chosen key for findByPath. Plan: ' . $this->planJson($forced),
        );
        $this->assertNotSame(
            'ALL',
            $this->planStr($forced, 'type'),
            'findByPath must NOT be a full table scan (type=ALL). Plan: ' . $this->planJson($forced),
        );
    }

    /**
     * Correctness with the real generated column: a seeded row is found by its
     * hash, and a path that also exists in a DIFFERENT library is NOT returned
     * for this library (the SV-0.8 library-scoping correctness win, not just a
     * performance one).
     */
    public function testFindPathsMapScopesToLibraryAgainstRealHashColumn(): void
    {
        $repo = new ItemRepository($this->db());

        // Same absolute path, but created in a SECOND library.
        $otherLibraryId = $this->uuid();
        $sharedPath = self::PATH_PREFIX . 'shared-across-libraries.mkv';
        $this->db()->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$otherLibraryId, 'Other Lib', 'movie', json_encode([rtrim(self::PATH_PREFIX, '/')])],
        );
        try {
            $repo->create([
                'library_id' => $otherLibraryId,
                'name' => 'Shared Path Movie',
                'type' => 'movie',
                'path' => $sharedPath,
                'metadata_json' => [],
            ]);

            // Ask for the shared path scoped to OUR library — it belongs to the
            // other library, so it must be absent from the map.
            $map = $repo->findPathsMap([$sharedPath], $this->libraryId);
            $this->assertArrayNotHasKey(
                $sharedPath,
                $map,
                'a path that exists only in a different library must not be reported as present in this one',
            );

            // A genuinely-seeded path in OUR library IS found.
            $ourPath = $this->seededPaths[0];
            $mapOurs = $repo->findPathsMap([$ourPath], $this->libraryId);
            $this->assertArrayHasKey($ourPath, $mapOurs, 'a real path in this library must be found via its hash');
        } finally {
            $this->db()->query('DELETE FROM libraries WHERE id = ?', [$otherLibraryId]);
        }
    }

    /**
     * SV-0.8 HIGH-finding regression against the REAL generated column: a
     * NON-deduped type (season/photo) has a NULL `path_hash` — verify that
     * directly, then confirm BOTH {@see ItemRepository::findByPath()} and
     * {@see ItemRepository::findPathsMap()} still resolve the row by its raw path
     * (the fast `path_hash = SHA1(?)` pass cannot see a NULL hash). Before the
     * raw-path fallback these lookups silently missed every non-deduped row and
     * the scanner forked a fresh DUPLICATE (a new empty season, or a full photo/
     * audiobook set) on every rescan. Pins Finding 3's recommendation to seed a
     * series/season/photo case, which the deduped-only fixtures could not catch.
     *
     * NOTE ON `photo`: the image case MUST use type `photo`. `media_items.type`
     * is an ENUM (migrations 001 → 011 → 034) whose image member is `photo` —
     * there is no `image` member, so seeding `'image'` makes MySQL reject the
     * INSERT with error 1265 ("Data truncated for column 'type'") under strict
     * mode. `image` is a scanner-side argument label only. The single source of
     * truth for the member list is now `Phlix\Media\MediaItemType::ALL`, which
     * `MediaItemShaper::VALID_TYPES` aliases and which
     * `Phlix\Tests\Unit\Media\MediaItemTypeDriftTest` pins against the ENUM parsed
     * out of the migration SQL (S102).
     */
    public function testFindByPathAndFindPathsMapResolveNullHashTypesByRawPath(): void
    {
        $repo = new ItemRepository($this->db());

        // A series container + a season under it (parent_id != null) + a photo —
        // all NON-deduped types whose generated path_hash is NULL.
        $seriesPath = self::PATH_PREFIX . 'series:' . $this->libraryId . ':null-hash-show';
        $seasonPath = self::PATH_PREFIX . 'season:' . $this->libraryId . ':null-hash-show:1';
        $photoPath = self::PATH_PREFIX . 'gallery/photo-' . md5('null-hash') . '.jpg';

        $seriesId = $repo->create([
            'library_id' => $this->libraryId,
            'name' => 'Null Hash Show',
            'type' => 'series',
            'path' => $seriesPath,
            'metadata_json' => [],
        ]);
        $seasonId = $repo->create([
            'library_id' => $this->libraryId,
            'parent_id' => $seriesId,
            'name' => 'Season 1',
            'type' => 'season',
            'path' => $seasonPath,
            'metadata_json' => ['season' => 1],
        ]);
        $photoId = $repo->create([
            'library_id' => $this->libraryId,
            'name' => 'Null Hash Photo',
            'type' => 'photo',
            'path' => $photoPath,
            'metadata_json' => [],
        ]);

        // 1. The generated column really IS NULL for these types (the root cause).
        foreach ([$seasonId, $photoId] as $id) {
            $row = $this->db()->row('SELECT path_hash FROM media_items WHERE id = ?', [$id]);
            $this->assertIsArray($row);
            $this->assertArrayHasKey('path_hash', $row);
            $this->assertNull(
                $row['path_hash'],
                'non-deduped types (season/photo) must have a NULL generated path_hash',
            );
        }

        // 2. findByPath resolves them despite the NULL hash (raw-path fallback) —
        //    NOT a silent miss that would fork a duplicate on rescan.
        $foundSeason = $repo->findByPath($seasonPath, $this->libraryId);
        $this->assertIsArray($foundSeason, 'NULL-hash season must be resolved by findByPath, not silently missed');
        $this->assertSame($seasonId, $foundSeason['id']);

        $foundPhoto = $repo->findByPath($photoPath, $this->libraryId);
        $this->assertIsArray($foundPhoto, 'NULL-hash photo must be resolved by findByPath, not silently missed');
        $this->assertSame($photoId, $foundPhoto['id']);

        // 3. findPathsMap resolves them in one batch via its raw-path fallback
        //    pass — mixed with a deduped movie that resolves in the fast pass.
        $dedupedMoviePath = $this->seededPaths[0];
        $map = $repo->findPathsMap([$dedupedMoviePath, $seasonPath, $photoPath], $this->libraryId);
        $this->assertArrayHasKey($dedupedMoviePath, $map, 'the deduped movie resolves via the fast path_hash pass');
        $this->assertArrayHasKey($seasonPath, $map, 'NULL-hash season must appear in the batch map (fallback pass)');
        $this->assertArrayHasKey($photoPath, $map, 'NULL-hash photo must appear in the batch map (fallback pass)');
        $this->assertSame($seasonId, $map[$seasonPath]['id']);
        $this->assertSame($photoId, $map[$photoPath]['id']);
    }

    /**
     * Ensure the `(library_id, path_hash)` unique index exists for the duration
     * of this test. Mirrors migration 096's / cleanup_072.php's statement. Self-skips when
     * pre-existing data would violate uniqueness (index-usage is unprovable
     * without the index, but that is an environment gap).
     */
    private function ensurePathHashIndex(): void
    {
        if ($this->hasPathHashIndex()) {
            return; // Already there (migration 096, or cleanup_072.php, ran on this DB).
        }

        try {
            $this->db()->query(
                'ALTER TABLE media_items ADD UNIQUE INDEX ' . self::PATH_HASH_INDEX . ' (library_id, path_hash)',
            );
            $this->createdIndex = true;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate key name')) {
                // Raced/already present — fine, and we are NOT the creator.
                return;
            }
            $this->markTestSkipped(
                'Could not create the (library_id, path_hash) unique index on this DB (pre-existing '
                . 'duplicate paths?) — index-usage unprovable here: ' . $msg,
            );
        }
    }

    private function hasPathHashIndex(): bool
    {
        try {
            $rows = $this->db()->query(
                "SHOW INDEX FROM media_items WHERE Key_name = ?",
                [self::PATH_HASH_INDEX],
            );

            return is_array($rows) && $rows !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Run EXPLAIN and return the first (driving-table) plan row.
     *
     * `Connection::query()` only returns rows when the statement's leading
     * keyword is `select`/`show`; `EXPLAIN` falls through to `return null`. So
     * use `Connection::row()`, which always fetches (see the documented
     * `$db->query() must start with SELECT` gotcha) — same primitive
     * {@see BrowseIndexUsageTest} uses.
     *
     * @param list<mixed> $params
     * @return array<string, mixed>
     */
    private function explain(string $sql, array $params): array
    {
        $row = $this->db()->row('EXPLAIN ' . $sql, $params);
        $this->assertIsArray($row, 'EXPLAIN returned no plan row for: ' . $sql);

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function planStr(array $plan, string $key): string
    {
        $value = $plan[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function planJson(array $plan): string
    {
        $json = json_encode($plan);

        return $json === false ? '<unencodable plan>' : $json;
    }

    /** The connection, guaranteed non-null (setUp skips the test otherwise). */
    private function db(): Connection
    {
        $this->assertInstanceOf(Connection::class, $this->db);

        return $this->db;
    }

    private function hasPathHashColumn(): bool
    {
        try {
            $rows = $this->db()->query("SHOW COLUMNS FROM media_items LIKE 'path_hash'");

            return is_array($rows) && $rows !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
