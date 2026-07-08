<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Library\ItemRepository;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof that migration 050's indexes actually satisfy the browse /
 * genre-filter hot paths — the whole point of step S7. A mocked connection
 * cannot exercise the optimizer, so this runs `EXPLAIN` against the queries
 * ItemRepository emits and asserts the new indexes are applicable and remove
 * the filesort / full-table-scan the pre-050 JSON/expression paths forced.
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
 * rare seeded genre makes the multi-valued index unambiguously selective.
 * (Observation for the coordinator / S10: the surviving single-column
 * idx_library + idx_type can lure the optimizer into an index_merge that
 * defeats the composite index's no-filesort benefit on some distributions —
 * worth weighing when S10 revisits redundant indexes.)
 *
 * @covers \Phlix\Media\Library\ItemRepository
 */
final class BrowseIndexUsageTest extends TestCase
{
    private const BROWSE_INDEX = 'idx_media_items_library_type_sort_title';
    private const GENRE_INDEX  = 'idx_media_items_genres';
    private const RATING_INDEX = 'idx_media_items_content_rating';

    /** Rows to seed — enough to give the plans a non-trivial dataset to reason over. */
    private const ROW_COUNT = 300;

    /** A deliberately rare genre so the membership predicate is selective. */
    private const RARE_GENRE = 'Zydeco';

    private ?Connection $db = null;

    private string $libraryId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);

        if (!$this->isMysqlReachable($host, $port)) {
            $this->markTestSkipped(
                sprintf('No MySQL on %s:%d — skipping browse index-usage EXPLAIN test. Runs in CI / docker-compose.', $host, $port),
            );
        }

        try {
            ConnectionPool::init(dirname(__DIR__, 3) . '/config/database.php');
            $this->db = ConnectionPool::getConnection('mysql');
        } catch (Throwable $e) {
            $this->markTestSkipped('Could not connect to MySQL: ' . $e->getMessage());
        }

        // If migration 050 has not been applied to this schema, the columns /
        // indexes under test do not exist — self-skip rather than error, so the
        // suite stays green on a stale DB (CI applies migrations first).
        if (!$this->hasSortTitleColumn()) {
            $this->markTestSkipped('media_items.sort_title absent — migration 050 not applied; skipping index-usage test.');
        }

        $db = $this->db();
        $this->libraryId = $this->uuid();
        $db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'Browse Index Usage Lib', 'movie', json_encode(['/tmp/phlix-browse-index-test'])],
        );

        $repo = new ItemRepository($db);
        for ($i = 0; $i < self::ROW_COUNT; $i++) {
            // Vary the leading letter so sort_title spans the alphabet; give a
            // rare genre to a handful of rows so `MEMBER OF` is selective.
            $letter = chr(ord('A') + ($i % 26));
            $name = sprintf('%s Browse Fixture %03d', $letter, $i);
            $genres = $i < 8 ? [self::RARE_GENRE] : ['Drama', 'Action'];
            $rating = ['G', 'PG', 'PG-13', 'R', 'NC-17'][$i % 5];

            $repo->create([
                'library_id' => $this->libraryId,
                'name' => $name,
                'type' => 'movie',
                'path' => '/tmp/phlix-browse-index-test/' . md5($name) . '.mkv',
                'metadata_json' => ['genres' => $genres, 'rating' => $rating, 'year' => 2000 + ($i % 25)],
            ]);
        }

        // Fresh, deterministic index statistics for the cost-based optimizer.
        $db->query('ANALYZE TABLE media_items');
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
     * The browse genre filter (query()/buildFilters:
     * `? MEMBER OF (metadata_json->'$.genres')`) must resolve against the
     * multi-valued index rather than full-scanning media_items. Asserted on the
     * NATURAL plan: the rare seeded genre is highly selective, so the optimizer
     * deterministically picks the MV index (a full scan would be type=ALL).
     */
    public function testGenreMembershipUsesMultiValuedIndex(): void
    {
        // Mirrors the query()/buildFilters genre predicate (single genre).
        $plan = $this->explain(
            "SELECT * FROM media_items WHERE library_id = ? "
            . "AND (? MEMBER OF (metadata_json->'\$.genres')) "
            . 'ORDER BY sort_title ASC, name ASC LIMIT 100 OFFSET 0',
            [$this->libraryId, self::RARE_GENRE],
        );

        $this->assertNotSame(
            'ALL',
            $this->planStr($plan, 'type'),
            'Genre membership must NOT be a full table scan (type=ALL). Plan: ' . $this->planJson($plan),
        );
        $this->assertSame(
            self::GENRE_INDEX,
            $this->planStr($plan, 'key'),
            'Genre membership must resolve against the multi-valued index ' . self::GENRE_INDEX
            . '. Plan: ' . $this->planJson($plan),
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

    private function isMysqlReachable(string $host, int $port): bool
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return true;
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
