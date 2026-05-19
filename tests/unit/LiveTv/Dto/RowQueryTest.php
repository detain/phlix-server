<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\LiveTv\Dto;

use PHPUnit\Framework\TestCase;
use Phlex\LiveTv\Dto\ResultSet;
use Phlex\LiveTv\Dto\RowQuery;

/**
 * @covers \Phlex\LiveTv\Dto\RowQuery
 */
class RowQueryTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function makeResult(array $rows): ResultSet
    {
        return new class ($rows) extends ResultSet {
            /** @var array<int, array<string, mixed>> */
            private array $rows;

            /**
             * @param array<int, array<string, mixed>> $rows
             */
            public function __construct(array $rows)
            {
                $this->rows = array_values($rows);
                $this->num_rows = count($rows);
            }

            public function fetch(): array|false
            {
                if ($this->rows === []) {
                    return false;
                }
                return array_shift($this->rows);
            }
        };
    }

    public function testRowsReturnsAllRows(): void
    {
        $result = $this->makeResult([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ]);

        $rows = RowQuery::rows($result);

        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['id']);
        $this->assertSame('b', $rows[1]['name']);
    }

    public function testRowsReturnsEmptyForNonResultSet(): void
    {
        $this->assertSame([], RowQuery::rows(null));
        $this->assertSame([], RowQuery::rows('not a result'));
        $this->assertSame([], RowQuery::rows([]));
    }

    public function testFirstRowReturnsFirstOrNull(): void
    {
        $populated = $this->makeResult([['id' => 1], ['id' => 2]]);
        $row = RowQuery::firstRow($populated);
        $this->assertNotNull($row);
        $this->assertSame(1, $row['id']);

        $empty = $this->makeResult([]);
        $this->assertNull(RowQuery::firstRow($empty));
    }

    public function testFirstRowReturnsNullForNonResultSet(): void
    {
        $this->assertNull(RowQuery::firstRow(null));
        $this->assertNull(RowQuery::firstRow(42));
    }

    public function testHasRowsReflectsNumRows(): void
    {
        $this->assertTrue(RowQuery::hasRows($this->makeResult([['x' => 1]])));
        $this->assertFalse(RowQuery::hasRows($this->makeResult([])));
        $this->assertFalse(RowQuery::hasRows(null));
    }
}
