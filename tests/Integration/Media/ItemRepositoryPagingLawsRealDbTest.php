<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Media\Library\ItemRepository;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * M-SERVER-08 — REAL-MySQL proof that {@see ItemRepository}'s library-paging
 * siblings honour the class's own two paging laws:
 *
 *  (a) TOTAL ORDER under OFFSET — every `ORDER BY … LIMIT ? OFFSET ?` ends in
 *      the `id` PRIMARY KEY ({@see ItemRepository::getAllByType()} states the
 *      law: `sort_title, name` still ties on identical titles — the
 *      remake/original "Dune" case — and MySQL legitimately arranges tied rows
 *      differently per LIMIT+OFFSET, so untailied page boundaries drop and
 *      repeat rows); and
 *  (b) the S99 row ceiling — every caller-supplied `LIMIT ?` is bound through
 *      `min(max(1, $limit), ABSOLUTE_ROW_CEILING)`, so an unclamped `?limit=`
 *      cannot hydrate an unbounded result set into the resident worker.
 *
 * ## Why this test has to hit a real database
 *
 * Same law {@see \Phlix\Tests\Integration\Dlna\DlnaBrowsePagingIntegrationTest}
 * narrates for `getAllByType()`: a double models something the database does
 * not do. Only a real filesort under real LIMIT+OFFSET pressure can SHOW the
 * dropped/repeated rows; only a real 10,001-row library can prove the ceiling
 * actually caps instead of merely reformatting the SQL.
 *
 * Before the fix the siblings named by review server__dba2 M2 violated both
 * laws (`getByLibrary`, `getByType`'s tail, `getByAllowedRatings`,
 * `getByAllowedGenres`, the ceilings on `getTopLevelByLibrary` and the
 * search/recently-added trio), so every walk test below went RED at the
 * parent commit while the `getAllByType()` twin stayed green.
 *
 * Self-skips with no reachable MySQL, like every other test under
 * `tests/Integration/` (CI provisions one and applies the migrations first).
 */
final class ItemRepositoryPagingLawsRealDbTest extends TestCase
{
    use RequiresRealDatabase;

    /**
     * Rows seeded under one shared (name, sort_title, year, genre, rating)
     * tuple — every ORDER BY key the sibling methods use ties on them, so
     * only an `id` tail can make the walk total. Sized at the same 400 that
     * measured 400→372 distinct for the untailied S147 walk on MySQL 8.0.
     */
    private const TIED_ROWS = 400;

    /** Page size — deliberately NOT a divisor-friendly size (see S147 §PAGE_SIZE). */
    private const PAGE_SIZE = 50;

    /** Distinct-named rows on top, so walks also cross ordinary rows. */
    private const DISTINCT_ROWS = 60;

    /** Rows for the ceiling test: ABSOLUTE_ROW_CEILING + 1. */
    private const CEILING_ROWS = 10001;

    /** Bulk-insert chunk: 660 rows × 7 columns = 4620 placeholders, well under 65535. */
    private const BULK_CHUNK_ROWS = 660;

    private ?Connection $db = null;

    private string $libraryId = '';

    /** @var list<string> Every media_items id this test created. */
    private array $seededIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping the M-SERVER-08 paging-laws proof. Runs in CI.');

        $db = $this->db();
        $this->libraryId = $this->uuid();
        $db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'M8 Paging Laws Lib', 'movie', json_encode(['/tmp/phlix-m8-paging'])],
        );
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->libraryId !== '') {
            // ON DELETE CASCADE takes the media_items rows with the library.
            $this->db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Law (a) — total order under OFFSET, per sibling

    /**
     * 🔴 THE DECISIVE ONE: `getByLibrary()` — the finding's primary evidence
     * coordinate and the cursor `SmartPlaylistEngine::iterateItemsForLibrary()`
     * walks at 500-per-batch — must page its library exactly: the concatenated
     * pages equal the unpaged listing, row for row, nothing dropped or repeated.
     *
     * Mutation target: delete `, id ASC` from `getByLibrary()`'s ORDER BY and
     * this goes RED on a real MySQL.
     */
    public function testGetByLibraryPagedWalkReproducesTheUnpagedOrderExactly(): void
    {
        $repo = $this->seedTiedLibrary();
        $total = $repo->countByLibrary($this->libraryId);
        $this->assertSame(self::TIED_ROWS + self::DISTINCT_ROWS, $total);

        $this->assertPagedWalkTilesListing(
            array_column($repo->getByLibrary($this->libraryId, $total + 10, 0), 'id'),
            function (int $limit, int $offset) use ($repo): array {
                return $repo->getByLibrary($this->libraryId, $limit, $offset);
            },
            'getByLibrary()'
        );
    }

    /**
     * `getByType()` already carried the ceiling and the offset normalisation;
     * only the `id` tail was missing. Same walk, library+type scoped.
     */
    public function testGetByTypePagedWalkReproducesTheUnpagedOrderExactly(): void
    {
        $repo = $this->seedTiedLibrary();
        $total = $repo->countByLibrary($this->libraryId);
        $this->assertSame(self::TIED_ROWS + self::DISTINCT_ROWS, $total);

        $this->assertPagedWalkTilesListing(
            array_column($repo->getByType($this->libraryId, 'photo', $total + 10, 0), 'id'),
            function (int $limit, int $offset) use ($repo): array {
                return $repo->getByType($this->libraryId, 'photo', $limit, $offset);
            },
            'getByType()'
        );
    }

    /**
     * `getByAllowedRatings()` — the parental-gated browse. All seeded rows are
     * unrated (NULL `content_rating`), so the CASE rank is ELSE 999 on every
     * row and the whole fixture rides on the tied (sort_title, name) tail.
     */
    public function testGetByAllowedRatingsPagedWalkReproducesTheUnpagedOrderExactly(): void
    {
        $repo = $this->seedTiedLibrary();
        $total = $repo->countByLibrary($this->libraryId);
        $this->assertSame(self::TIED_ROWS + self::DISTINCT_ROWS, $total);

        $this->assertPagedWalkTilesListing(
            array_column($repo->getByAllowedRatings($this->libraryId, ['G'], $total + 10, 0), 'id'),
            function (int $limit, int $offset) use ($repo): array {
                return $repo->getByAllowedRatings($this->libraryId, ['G'], $limit, $offset);
            },
            'getByAllowedRatings()'
        );
    }

    /**
     * `getByAllowedGenres()` — the genre-allow-listed browse. Every seeded row
     * carries the single genre 'Action', so the EXISTS filter keeps them all
     * and only the `id` tail can order the walk.
     */
    public function testGetByAllowedGenresPagedWalkReproducesTheUnpagedOrderExactly(): void
    {
        $repo = $this->seedTiedLibrary();
        $total = $repo->countByLibrary($this->libraryId);
        $this->assertSame(self::TIED_ROWS + self::DISTINCT_ROWS, $total);

        $this->assertPagedWalkTilesListing(
            array_column($repo->getByAllowedGenres($this->libraryId, ['Action'], $total + 10, 0), 'id'),
            function (int $limit, int $offset) use ($repo): array {
                return $repo->getByAllowedGenres($this->libraryId, ['Action'], $limit, $offset);
            },
            'getByAllowedGenres()'
        );
    }

    // ------------------------------------------------------------------
    // Law (b) — the S99 row ceiling

    /**
     * A 10,001-row library with an unbounded caller `LIMIT` must come back at
     * most ABSOLUTE_ROW_CEILING (10,000) rows from BOTH paged top-level reads —
     * the rows still exist (COUNT says 10001), the fetch is what is bounded.
     * Before the cure these two methods returned all 10,001 hydrated rows.
     *
     * Seeded with multi-row INSERTs (not create()) — 10k repository creates
     * with per-row genre inserts would cost minutes for no extra proof.
     */
    public function testUnboundedCallerLimitIsCappedAtTheAbsoluteRowCeiling(): void
    {
        $this->seedBulkTopLevelRows(self::CEILING_ROWS);

        $repo = new ItemRepository($this->db());
        $this->assertSame(
            self::CEILING_ROWS,
            $repo->countByLibrary($this->libraryId),
            'the library must genuinely hold one row MORE than the ceiling',
        );

        $this->assertCount(
            10000,
            $repo->getByLibrary($this->libraryId, self::CEILING_ROWS, 0),
            'getByLibrary() must cap an unbounded LIMIT at ABSOLUTE_ROW_CEILING — the S99 backstop',
        );
        $this->assertCount(
            10000,
            $repo->getTopLevelByLibrary($this->libraryId, self::CEILING_ROWS, 0),
            'getTopLevelByLibrary() must cap an unbounded LIMIT at ABSOLUTE_ROW_CEILING — the S99 backstop',
        );
    }

    // ------------------------------------------------------------------
    // Shared assertions / fixtures

    /**
     * @param callable(int, int): list<array<string, mixed>> $pageFetch  (limit, offset) -> rows
     * @param list<string>                                    $reference unpaged id order
     */
    private function assertPagedWalkTilesListing(array $reference, callable $pageFetch, string $label): void
    {
        $total = count($reference);

        $paged = [];
        for ($offset = 0; $offset < $total; $offset += self::PAGE_SIZE) {
            foreach ($pageFetch(self::PAGE_SIZE, $offset) as $row) {
                $paged[] = $row['id'];
            }
        }

        $this->assertSame(
            count($paged),
            count(array_unique($paged)),
            sprintf(
                '%s repeated %d row(s). With ties on the sort keys and no `id` tail, MySQL arranges '
                . 'the tied rows differently per LIMIT+OFFSET, so pages overlap and other rows are '
                . 'never returned at all (the law stated in getAllByType\'s docblock).',
                $label,
                count($paged) - count(array_unique($paged)),
            ),
        );

        $this->assertSame(
            $reference,
            $paged,
            $label . ': paging must tile the listing exactly — the concatenated pages must equal '
            . 'the unpaged order, row for row',
        );

        foreach ($this->seededIds as $id) {
            $this->assertContains($id, $paged, $label . ': every seeded row must be reachable by paging');
        }
    }

    /**
     * 400 rows sharing (name, year, genre, unrated) + 60 distinct rows.
     *
     * @return ItemRepository repository over the seeded library
     */
    private function seedTiedLibrary(): ItemRepository
    {
        $db = $this->db();
        $repo = new ItemRepository($db);

        $metadata = json_encode(['year' => 1984, 'genres' => ['Action']]);

        for ($i = 0; $i < self::TIED_ROWS; $i++) {
            $this->seededIds[] = $repo->create([
                'library_id' => $this->libraryId,
                'name' => 'Dune',
                'type' => 'photo',
                'path' => sprintf('/tmp/phlix-m8-paging/tied-%04d.jpg', $i),
                'metadata_json' => $metadata,
            ]);
        }

        for ($i = 0; $i < self::DISTINCT_ROWS; $i++) {
            $this->seededIds[] = $repo->create([
                'library_id' => $this->libraryId,
                'name' => sprintf('%s M8 Distinct %03d', chr(ord('A') + ($i % 26)), $i),
                'type' => 'photo',
                'path' => sprintf('/tmp/phlix-m8-paging/distinct-%04d.jpg', $i),
                'metadata_json' => $metadata,
            ]);
        }

        $db->query('ANALYZE TABLE media_items');

        return $repo;
    }

    /**
     * $count parentless photo rows written with chunked multi-row INSERTs.
     * Deliberately bypasses create(): the ceiling proof needs VOLUME, not the
     * genre/facet plumbing. `sort_title` mirrors what create() would stamp for
     * the shared name so the ORDER BY pressure matches production shape.
     */
    private function seedBulkTopLevelRows(int $count): void
    {
        $db = $this->db();

        for ($start = 0; $start < $count; $start += self::BULK_CHUNK_ROWS) {
            $chunk = min(self::BULK_CHUNK_ROWS, $count - $start);
            $tuples = [];
            $bindings = [];
            for ($i = 0; $i < $chunk; $i++) {
                $n = $start + $i;
                $id = sprintf('m8ceil-%06d-0000-0000-000000', $n);
                $tuples[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $bindings,
                    $id,
                    $this->libraryId,
                    'Dune',
                    'photo',
                    sprintf('/tmp/phlix-m8-paging/ceil-%06d.jpg', $n),
                    'dune',
                    '{}'
                );
                $this->seededIds[] = $id;
            }

            $db->query(
                'INSERT INTO media_items (id, library_id, name, type, path, sort_title, metadata_json) VALUES '
                    . implode(', ', $tuples),
                $bindings
            );
        }

        $db->query('ANALYZE TABLE media_items');
    }

    private function db(): Connection
    {
        if ($this->db === null) {
            $this->fail('No database connection');
        }

        return $this->db;
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }
}
