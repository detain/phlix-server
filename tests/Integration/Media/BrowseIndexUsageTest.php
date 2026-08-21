<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Media\Library\ItemRepository;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use Phlix\Tests\Support\FixtureIdGenerator;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof that migrations 050/051's indexes actually satisfy the browse
 * / genre-filter hot paths — the whole point of steps S7/S7b. A mocked
 * connection cannot exercise the optimizer, so this runs `EXPLAIN` against the
 * queries ItemRepository emits and asserts the new indexes are applicable and
 * remove the filesort / full-table-scan the pre-050 JSON/expression paths
 * forced.
 *
 * The genre check specifically exercises migration 051's `media_item_genres`
 * join table rather than migration 050's original multi-valued functional
 * index (`idx_media_items_genres`, on `media_items` directly): that MVI
 * reproducibly triggered real InnoDB purge-thread errors
 * (`[MY-012869] Record in index ... was not found on update`) under sustained
 * create/cascade-delete churn — CI's single round already logged 73 error
 * lines, and a dedicated stress test at realistic scan-churn volume (50
 * rounds x 300 rows = 15,000 rows) escalated this to 29,900, recurring
 * continuously across the whole run rather than a single isolated burst — so
 * S7b replaced it with an ordinary join table before that MVI could ever ship
 * to production. `? MEMBER OF (metadata_json->'$.genres')` no longer exists
 * anywhere in `ItemRepository`; the equivalent predicate is now an `EXISTS`
 * correlated subquery against `media_item_genres`.
 *
 * CI applies all migrations to the `phlix_test` MySQL service before the suite
 * (see phpunit.yml); locally — with no reachable MySQL — the test self-skips,
 * mirroring {@see SortTitleOrderingTest}.
 *
 * ON PLAN STABILITY (why some checks FORCE the index): MySQL's cost-based
 * optimizer's *natural* index choice for the browse listing is stats- and
 * data-distribution-dependent — on small/uniform fixtures it may prefer a full
 * scan, or an index_merge intersect of the pre-existing single-column
 * idx_library / idx_type (which then filesorts), over the new composite index.
 * That instability is about the PLANNER, not the index design. So for the
 * no-filesort claim we assert two deterministic, distribution-independent
 * properties: (1) the new index is APPLICABLE to the query (it appears in
 * `possible_keys`), and (2) it is STRUCTURALLY CAPABLE of satisfying the
 * ORDER BY without a filesort (forcing it yields `Extra` free of "filesort").
 * The genre membership predicate IS asserted on the natural plan because the
 * rare seeded genre makes the join table's index unambiguously selective —
 * empirically verified (this session) against both MySQL 8.4.10 (prod's
 * version) and 8.0.46 (CI's version): the optimizer semi-join-converts the
 * `EXISTS` and drives from `media_item_genres` first via
 * `idx_media_item_genres_genre` (`type=ref`), then joins back to
 * `media_items` by its `PRIMARY` key — deterministic on both versions with
 * this fixture's row counts/selectivity.
 * (Observation for the coordinator / S10: the surviving single-column
 * idx_library + idx_type can lure the optimizer into an index_merge that
 * defeats the composite index's no-filesort benefit on some distributions —
 * worth weighing when S10 revisits redundant indexes.)
 */
final class BrowseIndexUsageTest extends TestCase
{
    use RequiresRealDatabase;

    private const BROWSE_INDEX = 'idx_media_items_library_type_sort_title';
    private const RATING_INDEX = 'idx_media_items_content_rating';

    /**
     * The `media_item_genres` join table's standalone genre index (migration
     * 051), which replaced the migration 050 multi-valued functional index
     * `idx_media_items_genres` on `media_items` after that MVI reproducibly
     * triggered real InnoDB purge-thread errors under sustained
     * create/cascade-delete churn (see migration 051's comment header for the
     * full incident: CI's single round already logged 73 `[MY-012869]` error
     * lines; a dedicated stress test at realistic scan-churn volume — 50
     * rounds x 300 rows — escalated this to 29,900, recurring continuously
     * across the whole run rather than a single isolated burst).
     */
    private const GENRE_INDEX = 'idx_media_item_genres_genre';

    /** The join table's composite PRIMARY KEY (media_item_id, genre) — the
     *  other plausible access path the optimizer may pick for the genre
     *  membership predicate instead of {@see GENRE_INDEX}. */
    private const GENRE_TABLE_PRIMARY_KEY = 'PRIMARY';

    /** Rows to seed — enough to give the plans a non-trivial dataset to reason over. */
    private const ROW_COUNT = 300;

    /** A deliberately rare genre so the membership predicate is selective. */
    private const RARE_GENRE = 'Zydeco';

    private ?Connection $db = null;

    private string $libraryId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase(
            'skipping browse index-usage EXPLAIN test. Runs in CI / docker-compose.',
        );

        // If migration 050 has not been applied to this schema, the columns /
        // indexes under test do not exist — self-skip rather than error, so the
        // suite stays green on a stale DB (CI applies migrations first).
        if (!$this->hasSortTitleColumn()) {
            $this->markTestSkipped('media_items.sort_title absent — migration 050 not applied; skipping index-usage test.');
        }

        // Same self-skip guard for migration 051's join table, checked
        // separately from hasSortTitleColumn() — both migrations ship together
        // in this program, but a stale/partially-migrated schema (or a future
        // divergence) should still self-skip cleanly rather than error.
        if (!$this->hasMediaItemGenresTable()) {
            $this->markTestSkipped('media_item_genres absent — migration 051 not applied; skipping index-usage test.');
        }

        $db = $this->db();
        $this->libraryId = $this->fixtureId();
        $db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'Browse Index Usage Lib', 'movie', json_encode(['/tmp/phlix-browse-index-test'])],
        );

        $repo = new ItemRepository($db);
        for ($i = 0; $i < self::ROW_COUNT; $i++) {
            // Vary the leading letter so sort_title spans the alphabet; give a
            // rare genre to a handful of rows so the genre membership test
            // (media_item_genres) is selective.
            $letter = chr(ord('A') + ($i % 26));
            $name = sprintf('%s Browse Fixture %03d', $letter, $i);
            $genres = $i < 8 ? [self::RARE_GENRE] : ['Drama', 'Action'];
            $rating = ['G', 'PG', 'PG-13', 'R', 'NC-17'][$i % 5];

            $repo->create([
                // Supply the id explicitly (S111). Without it ItemRepository falls
                // back to Uuid::v4(), which is mt_rand()-based — that fallback, not
                // this class's own helper, is what produced the colliding
                // `media_items.PRIMARY` values under a pinned --random-order-seed.
                'id' => $this->fixtureId(),
                'library_id' => $this->libraryId,
                'name' => $name,
                'type' => 'movie',
                'path' => '/tmp/phlix-browse-index-test/' . md5($name) . '.mkv',
                'metadata_json' => ['genres' => $genres, 'rating' => $rating, 'year' => 2000 + ($i % 25)],
            ]);
        }

        // Fresh, deterministic index statistics for the cost-based optimizer.
        $db->query('ANALYZE TABLE media_items');
        $db->query('ANALYZE TABLE media_item_genres');
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->libraryId !== '') {
            // ON DELETE CASCADE removes the media_items rows with the parent library.
            $this->db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
        parent::tearDown();
    }

    /**
     * The library-browse listing (getByType: `library_id = ? AND type = ?`
     * ORDER BY sort_title, name) must be satisfiable from the composite index
     * with NO filesort — the core S7 performance win.
     *
     * Two complementary, deterministic checks (see the class-level note on why
     * the *natural* plan choice is NOT asserted): the composite index is
     * APPLICABLE to the browse query (it appears in `possible_keys`), and it is
     * STRUCTURALLY CAPABLE of feeding the ORDER BY without a filesort (forcing
     * it yields `Extra` with no "filesort" and the index as the chosen key).
     * The pre-050 state had neither the column nor the index, so the ORDER BY
     * always filesorted; this proves 050 removed that.
     */
    public function testBrowseListingUsesCompositeIndexWithoutFilesort(): void
    {
        // Mirrors ItemRepository::getByType() verbatim (titleOrder() = "sort_title ASC, name ASC").
        $browseSql = 'SELECT * FROM media_items WHERE library_id = ? AND type = ? '
            . 'ORDER BY sort_title ASC, name ASC LIMIT 100 OFFSET 0';

        $natural = $this->explain($browseSql, [$this->libraryId, 'movie']);
        $this->assertStringContainsString(
            self::BROWSE_INDEX,
            $this->planStr($natural, 'possible_keys'),
            'The (library_id, type, sort_title, name) composite index must be APPLICABLE to the browse '
            . 'listing (present in possible_keys). Plan: ' . $this->planJson($natural),
        );

        // Structural proof: when the composite index IS used, the ORDER BY is
        // satisfied from the index — no filesort.
        $forced = $this->explain(
            'SELECT * FROM media_items FORCE INDEX (' . self::BROWSE_INDEX . ') '
            . 'WHERE library_id = ? AND type = ? ORDER BY sort_title ASC, name ASC LIMIT 100 OFFSET 0',
            [$this->libraryId, 'movie'],
        );
        $this->assertSame(
            self::BROWSE_INDEX,
            $this->planStr($forced, 'key'),
            'Forcing the composite index must make it the chosen key. Plan: ' . $this->planJson($forced),
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'filesort',
            $this->planStr($forced, 'Extra'),
            'With the composite index, the browse ORDER BY must NOT filesort (it is satisfied by the '
            . 'index). Plan: ' . $this->planJson($forced),
        );
    }

    /**
     * The browse genre filter (query()/buildFilters: an `EXISTS` correlated
     * subquery against the `media_item_genres` join table, migration 051)
     * must resolve against that table's index rather than full-scanning
     * either `media_items` or `media_item_genres`. Migration 051 replaced
     * migration 050's multi-valued functional index over
     * `metadata_json.$.genres` after that index reproducibly triggered real
     * InnoDB purge-thread errors under sustained churn (see this class's and
     * migration 051's docblocks for the full incident).
     *
     * Asserted on the NATURAL plan: the rare seeded genre is highly
     * selective, so the optimizer deterministically semi-join-converts the
     * `EXISTS` and drives from `media_item_genres` first (a full scan of
     * either table would be `type=ALL`). Empirically verified (this session)
     * against MySQL 8.4.10 (prod's version) and 8.0.46 (CI's version): both
     * pick `idx_media_item_genres_genre` (`type=ref`) as the first
     * (driving-table) plan row, which is exactly the row {@see explain()}
     * returns.
     */
    public function testGenreMembershipUsesJoinTableIndex(): void
    {
        // Mirrors the query()/buildFilters genre predicate (single genre): an
        // EXISTS correlated subquery against media_item_genres.
        $plan = $this->explain(
            'SELECT * FROM media_items WHERE library_id = ? '
            . 'AND (EXISTS ('
            . '    SELECT 1 FROM media_item_genres mig'
            . '     WHERE mig.media_item_id = media_items.id'
            . '       AND mig.genre IN (?)'
            . ')) '
            . 'ORDER BY sort_title ASC, name ASC LIMIT 100 OFFSET 0',
            [$this->libraryId, self::RARE_GENRE],
        );

        $this->assertNotSame(
            'ALL',
            $this->planStr($plan, 'type'),
            'Genre membership must NOT be a full table scan (type=ALL). Plan: ' . $this->planJson($plan),
        );
        $this->assertSame(
            'mig',
            $this->planStr($plan, 'table'),
            'Expected the media_item_genres join table (aliased mig) to be the driving table of this '
            . 'plan row (the optimizer semi-join-converts the EXISTS and drives from the selective side '
            . 'first). Plan: ' . $this->planJson($plan),
        );
        $this->assertContains(
            $this->planStr($plan, 'key'),
            [self::GENRE_INDEX, self::GENRE_TABLE_PRIMARY_KEY],
            'Genre membership must resolve against media_item_genres via its standalone genre index ('
            . self::GENRE_INDEX . ') or its PRIMARY KEY (media_item_id, genre) — both plain B-tree indexes, '
            . 'unlike the multi-valued functional index migration 051 removed. Plan: ' . $this->planJson($plan),
        );
    }

    /**
     * The parental-control / rating filter (`content_rating IN (...)`) must have
     * the materialized-column index available instead of the pre-050 JSON
     * extraction that could never be indexed. Asserted deterministically:
     * the index is APPLICABLE (in `possible_keys`) and STRUCTURALLY provides a
     * non-full-scan range access path (forcing it yields a range scan, not
     * type=ALL). Which index the optimizer naturally picks when both a
     * library predicate and a rating predicate are present is cost-dependent
     * and not asserted (see the class-level note).
     */
    public function testRatingFilterHasContentRatingIndexAvailable(): void
    {
        $natural = $this->explain(
            'SELECT * FROM media_items WHERE library_id = ? AND content_rating IN (?, ?) '
            . 'ORDER BY sort_title ASC, name ASC LIMIT 100 OFFSET 0',
            [$this->libraryId, 'NC-17', 'R'],
        );
        $this->assertStringContainsString(
            self::RATING_INDEX,
            $this->planStr($natural, 'possible_keys'),
            'The content_rating index must be APPLICABLE to the rating filter (present in possible_keys). '
            . 'Plan: ' . $this->planJson($natural),
        );

        $forced = $this->explain(
            'SELECT * FROM media_items FORCE INDEX (' . self::RATING_INDEX . ') '
            . 'WHERE library_id = ? AND content_rating IN (?, ?) '
            . 'ORDER BY sort_title ASC, name ASC LIMIT 100 OFFSET 0',
            [$this->libraryId, 'NC-17', 'R'],
        );
        $this->assertSame(
            self::RATING_INDEX,
            $this->planStr($forced, 'key'),
            'Forcing the content_rating index must make it the chosen key. Plan: ' . $this->planJson($forced),
        );
        $this->assertNotSame(
            'ALL',
            $this->planStr($forced, 'type'),
            'The content_rating index must provide a non-full-scan access path (not type=ALL). Plan: '
            . $this->planJson($forced),
        );
    }

    /**
     * S111 pin: no fixture id is reproducible from a pinned `mt_rand()` seed, and
     * every id this run inserted is distinct.
     *
     * Reproduced before the fix on a real MySQL 8.0: two separate processes run with
     * `--random-order-seed=4242` emitted byte-identical sets of all 300
     * `media_items` ids, and planting one of them beforehand made the run die with
     * `Duplicate entry 'ac4ac379-…' for key 'media_items.PRIMARY'` — the exact
     * failure this step exists to remove.
     *
     * Asserts the property, not the implementation: re-seeding `mt_rand()` to a
     * fixed value between two calls must NOT yield the same id.
     */
    public function testFixtureIdsAreRunUniqueAndNotDerivedFromMtRand(): void
    {
        // (1) The ids actually inserted by this run are all distinct.
        $rows = $this->db()->query(
            'SELECT id FROM media_items WHERE library_id = ?',
            [$this->libraryId],
        );
        $ids = array_column(is_array($rows) ? $rows : [], 'id');
        $this->assertCount(self::ROW_COUNT, $ids, 'the fixture must have inserted every row');
        $this->assertCount(
            self::ROW_COUNT,
            array_unique($ids),
            'every inserted media_items id must be distinct',
        );

        // (1b) …and they came from fixtureId(), not from ItemRepository's
        //      mt_rand()-based Uuid::v4() fallback. fixtureId() puts a monotonic
        //      counter in the final 12-hex field, so this setUp's rows carry a
        //      CONSECUTIVE run of counter values. Uuid::v4() fills that field with
        //      48 random bits, which cannot be consecutive. This is the assertion
        //      that reddens if the explicit 'id' argument is dropped.
        $counters = array_map(
            static fn (string $id): int => (int) hexdec(substr($id, -12)),
            $ids,
        );
        sort($counters);
        $this->assertSame(
            range($counters[0], $counters[0] + self::ROW_COUNT - 1),
            $counters,
            'media_items ids must come from fixtureId() (consecutive counters), not Uuid::v4()',
        );

        // (2) Pinning mt_rand's seed must not reproduce an id. This is exactly what
        //     the old mt_rand()-based helper failed, and it reddens if either the
        //     helper or the ItemRepository::create() 'id' argument regresses.
        mt_srand(4242);
        $first = $this->fixtureId();
        mt_srand(4242);
        $second = $this->fixtureId();
        $this->assertNotSame(
            $first,
            $second,
            'a pinned mt_rand seed must not reproduce a fixture id (S111)',
        );

        // (2b) S334 — (2) is satisfiable by the counter alone: if the CSPRNG
        //      prefix regressed to a constant, a pinned-seed re-run would
        //      reinstate the cross-run collisions S111 removed while every
        //      existing assertion stayed green. Compare only the random half
        //      (positions 0-17 = the 18 CSPRNG hex chars; the '-' separators and
        //      v4 '4' nibble are constants), so the counter field cannot mask a
        //      regression. Mirrors the unit-level guard in
        //      {@see \Phlix\Tests\Unit\Support\FixtureIdGeneratorTest}.
        $this->assertNotSame(
            substr($first, 0, 18),
            substr($second, 0, 18),
            'the CSPRNG half of a fixture id must vary even under a pinned mt_rand seed (S334)',
        );

        // (3) Still a well-formed CHAR(36) v4-shaped UUID.
        $this->assertSame(36, strlen($first));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}$/',
            $first,
        );
    }

    /**
     * Run EXPLAIN and return the first (driving-table) plan row as an assoc array.
     *
     * `Connection::query()` only returns fetched rows when the statement's
     * leading keyword is `select`/`show` (see `workerman/mysql`'s `query()` —
     * every other keyword, including `explain`, falls through to `return
     * null`, regardless of whether the statement actually produces a result
     * set). `Connection::row()` has no such gate — it always calls
     * `fetch()` — so it is the correct primitive for a row-returning
     * statement this library doesn't special-case. Mirrors the existing
     * `$db->query() must start with SELECT` gotcha documented in memory.
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
     * Read one EXPLAIN plan field as a string (scalar → string, else '').
     *
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

    private function hasSortTitleColumn(): bool
    {
        try {
            $rows = $this->db()->query("SHOW COLUMNS FROM media_items LIKE 'sort_title'");

            return is_array($rows) && $rows !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether migration 051's `media_item_genres` join table exists on this
     * schema. A separate guard from {@see hasSortTitleColumn()} (migration
     * 050) since the two migrations are independently discoverable/applicable
     * — a schema could in principle have one without the other.
     */
    private function hasMediaItemGenresTable(): bool
    {
        try {
            $rows = $this->db()->query("SHOW TABLES LIKE 'media_item_genres'");

            return is_array($rows) && $rows !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A `CHAR(36)` fixture id that is unique per ROW and per RUN, drawn from the
     * CSPRNG and a counter — never from `mt_rand()` (S111).
     *
     * PHPUnit's `--random-order-seed` calls `mt_srand()`, which is the standard way
     * to reproduce an order-dependent failure. The previous implementation of this
     * helper — and `ItemRepository`'s `Uuid::v4()` fallback, which used to supply the
     * `media_items` ids because the fixture passed none — were both `mt_rand()`-based,
     * so a pinned seed made every fixture id in the process byte-for-byte
     * reproducible. Any row surviving an earlier same-seed run then collided with
     * `Duplicate entry '…' for key 'media_items.PRIMARY'`, i.e. the debugging tool
     * itself was unusable on this class.
     *
     * Two independent sources, so uniqueness does not rest on either alone:
     *  - a run-unique prefix from `random_bytes()`, which `mt_srand()` cannot steer;
     *  - a per-row monotonic counter in the final field, which makes intra-run
     *    uniqueness provable rather than merely probable.
     *
     * The output keeps the v4/variant-8 nibbles so it is shaped like every other id
     * in the schema.
     *
     * S334 — the implementation now lives in {@see FixtureIdGenerator} so a
     * unit-level test can probe the real generator without a MySQL server (the
     * S111 pin's CSPRNG half is asserted there and in (2b) above).
     */
    private function fixtureId(): string
    {
        return FixtureIdGenerator::generate();
    }
}
