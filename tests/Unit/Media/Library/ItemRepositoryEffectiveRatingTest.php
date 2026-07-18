<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Media\Library\ItemRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Covers the effective-rating parent-walk used by the parental gate:
 * own rating shortcut, series inheritance (episode → season → series), the
 * batch id resolver, and the bounded-depth guard against a corrupt parent cycle.
 */
class ItemRepositoryEffectiveRatingTest extends TestCase
{
    /**
     * Build a mock Connection whose `query()` answers `WHERE id IN (...)` from a
     * fixture map (id => row). Every other query returns []. `$rounds` counts the
     * IN-queries run so a test can assert the walk is bounded.
     *
     * @param array<string, array<string, mixed>> $rowsById
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function connectionFor(array $rowsById, int &$rounds = 0)
    {
        $rounds = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $bindings = []) use ($rowsById, &$rounds): array {
                if (!str_contains($sql, 'IN (')) {
                    return [];
                }
                $rounds++;
                $out = [];
                foreach ($bindings as $id) {
                    if (isset($rowsById[$id])) {
                        $out[] = $rowsById[$id];
                    }
                }
                return $out;
            }
        );
        return $db;
    }

    public function testOwnRatingOnRowNeedsNoQuery(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');
        $repo = new ItemRepository($db);

        $this->assertSame('R', $repo->effectiveContentRating(['id' => 'm1', 'content_rating' => 'R']));
    }

    public function testRowWithNoParentAndNoRatingIsNull(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');
        $repo = new ItemRepository($db);

        $this->assertNull($repo->effectiveContentRating(['id' => 'm1', 'content_rating' => null]));
    }

    public function testEpisodeInheritsSeriesRatingViaWalk(): void
    {
        // episode(null) -> season(null) -> series('TV-14')
        $db = $this->connectionFor([
            'season-1' => ['id' => 'season-1', 'content_rating' => null, 'parent_id' => 'series-1'],
            'series-1' => ['id' => 'series-1', 'content_rating' => 'TV-14', 'parent_id' => null],
        ]);
        $repo = new ItemRepository($db);

        $row = ['id' => 'ep-1', 'content_rating' => null, 'parent_id' => 'season-1'];
        $this->assertSame('TV-14', $repo->effectiveContentRating($row));
    }

    public function testBatchResolvesMixedEffectiveRatings(): void
    {
        $db = $this->connectionFor([
            'movie-1' => ['id' => 'movie-1', 'content_rating' => 'PG', 'parent_id' => null],
            'ep-1' => ['id' => 'ep-1', 'content_rating' => null, 'parent_id' => 'series-1'],
            'series-1' => ['id' => 'series-1', 'content_rating' => 'R', 'parent_id' => null],
            'orphan-1' => ['id' => 'orphan-1', 'content_rating' => null, 'parent_id' => null],
        ]);
        $repo = new ItemRepository($db);

        $map = $repo->effectiveContentRatingsForIds(['movie-1', 'ep-1', 'orphan-1', 'missing-1']);

        $this->assertSame('PG', $map['movie-1']);
        $this->assertSame('R', $map['ep-1']);
        $this->assertNull($map['orphan-1']);
        $this->assertNull($map['missing-1']); // not in DB → null
    }

    public function testParentCycleIsBounded(): void
    {
        // a -> b -> a (corrupt cycle): must terminate and yield null, not loop.
        $rounds = 0;
        $db = $this->connectionFor([
            'a' => ['id' => 'a', 'content_rating' => null, 'parent_id' => 'b'],
            'b' => ['id' => 'b', 'content_rating' => null, 'parent_id' => 'a'],
        ], $rounds);
        $repo = new ItemRepository($db);

        $this->assertNull($repo->effectiveContentRatingsForIds(['a'])['a']);
        // Bounded by MAX_RATING_INHERITANCE_DEPTH (8) — never unbounded.
        $this->assertLessThanOrEqual(8, $rounds);
    }

    public function testEmptyIdsReturnsEmpty(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');
        $repo = new ItemRepository($db);

        $this->assertSame([], $repo->effectiveContentRatingsForIds([]));
    }

    /**
     * Finding 2 mechanism: the A-Z / index bucket query threads the parental cap
     * so bucket counts reflect the capped rows. The Application /media/index
     * handler merges the resolved cap into these same params.
     */
    public function testValueBucketsThreadsParentalCap(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, array $p = []) use (&$captured): array {
            $captured[] = ['sql' => $sql, 'params' => $p];
            return [];
        });
        $repo = new ItemRepository($db);

        $repo->valueBuckets('name', [
            'allowedRatings' => ['G', 'PG', 'PG-13'],
            'allowUnrated' => true,
        ], 'lib-1');

        $this->assertNotEmpty($captured);
        $sql = $captured[0]['sql'];
        $params = $captured[0]['params'];
        $this->assertStringContainsString('content_rating IN', $sql);
        $this->assertContains('PG-13', $params);
        $this->assertNotContains('R', $params);
    }

    public function testValueBucketsWithoutCapAppliesNoRatingFilter(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, array $p = []) use (&$captured): array {
            $captured[] = $sql;
            return [];
        });
        $repo = new ItemRepository($db);

        $repo->valueBuckets('name', [], 'lib-1');

        $this->assertNotEmpty($captured);
        $this->assertStringNotContainsString('content_rating IN', $captured[0]);
    }
}
