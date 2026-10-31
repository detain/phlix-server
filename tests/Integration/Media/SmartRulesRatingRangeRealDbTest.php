<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Media\Library\ItemRepository;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * R-SERVER-19 — REAL-MySQL proof that a `rating_range` smart rule actually
 * runs. `buildRatingRangeClause()` once emitted its aggregate predicate
 * (`COALESCE(AVG(r.score), 0) >= ?`) into the WHERE list, and every such rule
 * died at runtime with MySQL error 1111 ("Grouping operation is used
 * incorrectly") — measured live per review server__dba2 M-SERVER-08 R3. The
 * cure routes aggregates under HAVING, after the per-item GROUP BY; the
 * unit-side clause-placement pins live in
 * {@see \Phlix\Tests\Unit\Media\Library\ItemRepositoryPagingLawsTest}.
 *
 * On the parent commit EVERY test in this file dies with 1111 — the arm could
 * never run a query at all. That is the red the finding measured; here the
 * same queries must return the exactly-correct survivor sets, which a fake
 * database cannot certify (the fake happily executes AVG-in-WHERE — modelling
 * "something the database does not do", the law this repo's paging-laws suite
 * narrates for itself).
 *
 * Runs against the fully-migrated CI schema (like every Integration test);
 * all rows are uuid-scoped and removed in tearDown.
 */
final class SmartRulesRatingRangeRealDbTest extends TestCase
{
    use RequiresRealDatabase;

    /** Lane survival token (R-SERVER-19). Code-resident by design; never prose. */
    private const LANE_TOKEN = 'SRVARATINGRANGEHAVINGQ7';

    private ?Connection $db = null;

    private string $libraryId = '';

    /** @var list<string> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $ratingIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping the R-SERVER-19 rating-range HAVING proof. Runs in CI.');

        $db = $this->db;
        $this->libraryId = $this->uuid();
        $db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'SRVA Rating Rules', 'movie', json_encode(['/tmp/phlix-srva-rating'])],
        );
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            // metadata_ratings carries NO FK to media_items (052 ships indexes
            // only) — delete it explicitly, newest concern first.
            foreach ($this->ratingIds as $id) {
                $this->db->query('DELETE FROM metadata_ratings WHERE id = ?', [$id]);
            }
            foreach ($this->itemIds as $id) {
                $this->db->query('DELETE FROM media_items WHERE id = ?', [$id]);
            }
            $this->db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }

        $this->db = null;
        parent::tearDown();
    }

    public function testRatingRangeRuleRunsAndSelectsExactlyTheQualifyingItems(): void
    {
        $high = $this->seedItemWithRatings('SRVA High', [9.0, 9.5]);      // avg 9.25
        $mid = $this->seedItemWithRatings('SRVA Mid', [5.0, 6.0]);        // avg 5.5
        $unrated = $this->seedItemWithRatings('SRVA Unrated', []);        // COALESCE → 0

        $result = (new ItemRepository($this->db))->queryWithSmartRules(
            [['type' => 'rating_range', 'value' => '7-10']],
            [],
            $this->libraryId,
        );

        $this->assertSame(
            [$high],
            array_column($result['items'], 'id'),
            self::LANE_TOKEN . ': only the avg>=7 survivor rides HAVING'
        );
        $this->assertSame(1, $result['total'], 'the derived-table count must agree with the survivor set');
        $this->assertNotSame($mid, $unrated, 'seed guard');
    }

    public function testRatingRangeLowerBoundOnlyExcludesTheCoalescedUnratedItem(): void
    {
        $this->seedItemWithRatings('SRVA Low', [1.0]);
        $high = $this->seedItemWithRatings('SRVA High2', [8.0]);
        $this->seedItemWithRatings('SRVA Unrated2', []);

        $result = (new ItemRepository($this->db))->queryWithSmartRules(
            [['type' => 'rating_range', 'value' => '7']],
            [],
            $this->libraryId,
        );

        // min 7 with NO max: one HAVING arm; unrated (COALESCE 0) stays out.
        $this->assertSame([$high], array_column($result['items'], 'id'));
        $this->assertSame(1, $result['total']);
    }

    public function testRatingRangeCombinesWithOtherRulesAndLibraryScope(): void
    {
        $both = $this->seedItemWithRatings('SRVA Both', [9.0]);
        $this->seedItemWithRatings('SRVA WrongDecade', [9.0], 1950);

        $result = (new ItemRepository($this->db))->queryWithSmartRules(
            [
                ['type' => 'rating_range', 'value' => '8-10'],
                ['type' => 'decade', 'value' => '2020s'],
            ],
            [],
            $this->libraryId,
        );

        // WHERE (decade leg) AND HAVING (rating leg) must coexist with bindings
        // in clause order — a mis-merged binding list lands wrong rows.
        $this->assertSame(
            [$both],
            array_column($result['items'], 'id'),
            self::LANE_TOKEN . ': mixed WHERE+HAVING binds in clause order'
        );
        $this->assertSame(1, $result['total']);
    }

    public function testRangeWithNoSurvivorsReturnsEmptyNotError(): void
    {
        $this->seedItemWithRatings('SRVA Mid3', [4.0]);

        $result = (new ItemRepository($this->db))->queryWithSmartRules(
            [['type' => 'rating_range', 'value' => ['min' => 9.5]]],
            [],
            $this->libraryId,
        );

        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total'], 'the HAVING-carrying derived-table count answers 0 honestly');
    }

    // ------------------------------------------------------------------
    // Helpers

    /**
     * @param list<float> $scores one metadata_ratings (source=tmdb, average) row each
     * @return string the item id, for survivor-set comparison
     */
    private function seedItemWithRatings(string $name, array $scores, int $year = 2023): string
    {
        $db = $this->db;
        $this->db instanceof Connection or $this->fail('connection missing');

        $itemId = $this->uuid();
        $this->itemIds[] = $itemId;
        $db->query(
            'INSERT INTO media_items (id, library_id, name, type, path, metadata_json) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $itemId,
                $this->libraryId,
                $name,
                'movie',
                '/tmp/phlix-srva/' . $itemId,
                json_encode(['year' => $year]),
            ],
        );

        $sourceSeen = 0;
        foreach ($scores as $score) {
            // 052's UNIQUE is (media_item_id, source, rating_type) — one row per
            // source, cycled. AVG() spans every joined row either way.
            $source = $sourceSeen === 0 ? 'tmdb' : 'imdb';
            $sourceSeen++;
            $db->query(
                'INSERT INTO metadata_ratings (media_item_id, source, rating_type, score) VALUES (?, ?, ?, ?)',
                [$itemId, $source, 'average', $score],
            );
            $insertId = $db->lastInsertId();
            $this->assertIsString($insertId);
            $this->ratingIds[] = (int) $insertId;
        }

        return $itemId;
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
