<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Dlna;

use Phlix\Dlna\ContentDirectory;
use Phlix\Dlna\LibraryBridge;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S147 — REAL-MySQL proof that a DLNA renderer can page a root container all the
 * way to its last row, and that the pages tile the container exactly.
 *
 * ## Why this test has to hit a real database
 *
 * The unit tests for this change drive in-memory doubles whose callbacks honour
 * `LIMIT`/`OFFSET` faithfully, so they prove the OFFSET ARITHMETIC in
 * {@see LibraryBridge::getLibraryItems()} — which type a global index lands in,
 * how a page straddles a type boundary. What they cannot prove is the half that
 * lives in SQL: that `ORDER BY … LIMIT ? OFFSET ?` returns a stable, total order
 * so consecutive pages tile the set without dropping or repeating a row. A
 * double that returns `array_slice()` of a PHP array is a total order by
 * construction — it would pass against a query that has no tiebreak at all,
 * which is exactly the "the double models something the database does not do"
 * trap that let a reverted `SELECT` through the whole unit suite on S145.
 *
 * ## The defect this pins, measured
 *
 * {@see ItemRepository::getAllByType()} orders by `sort_title, name`. Those are
 * NOT unique — a remake and its original are both "Dune". With ties present,
 * MySQL is free to return the tied rows in a different arrangement for each
 * `OFFSET`, because the sort strategy it picks (bounded priority queue vs full
 * sort) depends on `LIMIT + OFFSET`. Measured here on MySQL 8.0.46 with 400 rows
 * sharing one name, paged 50 at a time: **400 rows came back, only 372 of them
 * distinct** — 28 rows silently repeated and 28 never seen at all. Appending the
 * `id` PRIMARY KEY makes the order total and the same walk returns 400/400.
 *
 * Self-skips with no reachable MySQL, like every other test under
 * `tests/Integration/` (CI provisions one and applies the migrations first).
 *
 */
final class DlnaBrowsePagingIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    /**
     * Rows seeded under one shared name.
     *
     * Large enough, at {@see PAGE_SIZE}, to reproduce MySQL's page-size-dependent
     * tie arrangement; small enough to seed in a second.
     */
    private const TIED_ROWS = 400;

    /**
     * Page size for the paged walk.
     *
     * ⚠ Deliberately small, and deliberately NOT a divisor-friendly round number
     * relative to the fixture: at 50 the un-tiebroken query drops and repeats
     * rows on this fixture, at 100 it happens not to. A test that only walked in
     * 100s would go green against the broken ORDER BY and prove nothing.
     */
    private const PAGE_SIZE = 50;

    /** Distinct-named rows seeded on top, so the walk also crosses ordinary rows. */
    private const DISTINCT_ROWS = 60;

    private ?Connection $db = null;

    private string $libraryId = '';

    /** @var list<string> Every media_items id this test created. */
    private array $seededIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping the S147 DLNA paging proof. Runs in CI.');

        $db = $this->db();
        $this->libraryId = $this->uuid();
        $db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'S147 DLNA Paging Lib', 'movie', json_encode(['/tmp/phlix-s147-paging'])],
        );

        $repo = new ItemRepository($db);

        // The tie block: one name for every row, so `sort_title, name` cannot
        // order them and only the `id` tiebreak can.
        for ($i = 0; $i < self::TIED_ROWS; $i++) {
            $this->seededIds[] = $repo->create([
                'library_id' => $this->libraryId,
                'name' => 'Dune',
                'type' => 'photo',
                'path' => sprintf('/tmp/phlix-s147-paging/tied-%04d.jpg', $i),
            ]);
        }

        // Ordinary distinct rows either side of the tie block.
        for ($i = 0; $i < self::DISTINCT_ROWS; $i++) {
            $this->seededIds[] = $repo->create([
                'library_id' => $this->libraryId,
                'name' => sprintf('%s S147 Distinct %03d', chr(ord('A') + ($i % 26)), $i),
                'type' => 'photo',
                'path' => sprintf('/tmp/phlix-s147-paging/distinct-%04d.jpg', $i),
            ]);
        }

        $db->query('ANALYZE TABLE media_items');
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->libraryId !== '') {
            // ON DELETE CASCADE takes the media_items rows with the library.
            $this->db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
        parent::tearDown();
    }

    /**
     * 🔴 THE DECISIVE ONE: paging `getAllByType()` reproduces the unpaged listing
     * EXACTLY — same rows, same order, nothing dropped, nothing repeated.
     *
     * Asserted against the unpaged listing rather than against the seeded set,
     * because `getAllByType()` has no library filter: any `photo` row another
     * fixture left behind is legitimately part of both listings, and comparing
     * the two makes the assertion independent of what else is in the schema.
     *
     * Mutation target: delete `, id ASC` from `ItemRepository::getAllByType()`'s
     * `ORDER BY` and this goes RED on a real MySQL while every unit test stays
     * green.
     */
    public function testPagingByTypeReproducesTheUnpagedOrderExactly(): void
    {
        $repo = new ItemRepository($this->db());

        $total = $repo->countAllByType('photo');
        $this->assertGreaterThanOrEqual(
            self::TIED_ROWS + self::DISTINCT_ROWS,
            $total,
            'the fixture must be present',
        );

        $unpaged = array_column($repo->getAllByType('photo', $total + 10, 0), 'id');
        $this->assertCount($total, $unpaged, 'the unpaged listing is the reference order');

        $paged = [];
        for ($offset = 0; $offset < $total; $offset += self::PAGE_SIZE) {
            foreach ($repo->getAllByType('photo', self::PAGE_SIZE, $offset) as $row) {
                $paged[] = $row['id'];
            }
        }

        $this->assertSame(
            count($paged),
            count(array_unique($paged)),
            sprintf(
                'the paged walk repeated %d row(s). With ties on (sort_title, name) and no `id` '
                . 'tiebreak, MySQL arranges the tied rows differently per LIMIT+OFFSET, so pages '
                . 'overlap and other rows are never returned at all.',
                count($paged) - count(array_unique($paged)),
            ),
        );

        $this->assertSame(
            $unpaged,
            $paged,
            'paging must tile the listing exactly: the concatenated pages must equal the unpaged '
            . 'order, row for row',
        );

        foreach ($this->seededIds as $id) {
            $this->assertContains($id, $paged, 'every seeded row must be reachable by paging');
        }
    }

    /**
     * The acceptance criterion, end to end through the public `Browse` action: a
     * renderer is told a total, walks it with `StartingIndex`, and reaches the
     * LAST row.
     *
     * Before S147 this was impossible for any container past the first page —
     * `getLibraryItems()` took no offset and `browseChildren()` applied
     * `StartingIndex` in PHP to a list already cut at `LIMIT 100`, so a
     * `StartingIndex` beyond 100 returned an empty page while `TotalMatches`
     * still advertised the whole container.
     */
    public function testABrowseCanBePagedToTheLastRowOfTheContainer(): void
    {
        $repo = new ItemRepository($this->db());
        $bridge = new LibraryBridge($repo, $this->createMock(HlsStreamer::class));
        $cd = new ContentDirectory($repo);
        $cd->setLibraryBridge($bridge);

        $first = $cd->browse('library-photos', 'BrowseDirectChildren', '*', 0, self::PAGE_SIZE, '');
        $total = $first['TotalMatches'] ?? 0;

        $this->assertIsInt($total);
        $this->assertGreaterThan(
            self::PAGE_SIZE,
            $total,
            'the fixture must exceed one page or this proves nothing',
        );
        $this->assertSame(
            $repo->countAllByType('photo'),
            $total,
            'TotalMatches must be the container total, not the size of the page just returned',
        );

        $walked = [];
        $numberReturned = 0;
        for ($offset = 0; $offset < $total; $offset += self::PAGE_SIZE) {
            $page = $cd->browse('library-photos', 'BrowseDirectChildren', '*', $offset, self::PAGE_SIZE, '');

            $this->assertGreaterThan(
                0,
                $page['NumberReturned'] ?? 0,
                sprintf('StartingIndex %d returned an empty page — the container is not pageable', $offset),
            );
            $numberReturned += is_int($page['NumberReturned'] ?? null) ? $page['NumberReturned'] : 0;

            $didl = is_string($page['Result'] ?? null) ? $page['Result'] : '';
            preg_match_all('/<item id="([^"]+)"|<container id="([^"]+)"/', $didl, $matches);
            foreach ($matches[1] as $index => $itemId) {
                $walked[] = $itemId !== '' ? $itemId : $matches[2][$index];
            }
        }

        $this->assertSame($total, $numberReturned, 'the pages must sum to the advertised total');
        $this->assertSame($total, count($walked), 'every advertised child must appear in some page');
        $this->assertSame(count($walked), count(array_unique($walked)), 'no row may appear in two pages');

        $lastAdvertised = array_column($repo->getAllByType('photo', $total + 10, 0), 'id');
        $this->assertSame(
            end($lastAdvertised),
            end($walked),
            'the LAST child of the container must be reachable — the whole point of S147',
        );
    }

    /**
     * `findByParentPage()` pages a `parent_id` drill-down with the same
     * guarantee, so the series/season branch of the browse is not left behind.
     */
    public function testAParentDrillDownPagesWithoutDroppingOrRepeatingARow(): void
    {
        $repo = new ItemRepository($this->db());

        $parentId = $repo->create([
            'library_id' => $this->libraryId,
            'name' => 'S147 Season',
            'type' => 'season',
            'path' => '/tmp/phlix-s147-paging/season',
        ]);
        $this->seededIds[] = $parentId;

        // Same-name children: `ORDER BY name` alone cannot order these either.
        for ($i = 0; $i < 120; $i++) {
            $this->seededIds[] = $repo->create([
                'library_id' => $this->libraryId,
                'parent_id' => $parentId,
                'name' => 'Episode',
                'type' => 'episode',
                'path' => sprintf('/tmp/phlix-s147-paging/ep-%04d.mkv', $i),
            ]);
        }

        $total = $repo->countByParent($parentId);
        $this->assertSame(120, $total);

        $walked = [];
        for ($offset = 0; $offset < $total; $offset += 25) {
            foreach ($repo->findByParentPage($parentId, 25, $offset) as $row) {
                $walked[] = $row['id'];
            }
        }

        $this->assertCount($total, $walked);
        $this->assertSame(count($walked), count(array_unique($walked)), 'no episode may be paged twice');
        $this->assertSame(
            array_column($repo->findByParentPage($parentId, $total, 0), 'id'),
            $walked,
            'the paged walk must equal the single-page listing exactly',
        );
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
