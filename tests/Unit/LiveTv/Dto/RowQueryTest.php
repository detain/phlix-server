<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Dto;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Dto\ResultSet;
use Phlix\LiveTv\Dto\RowQuery;

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

    /**
     * REGRESSION GUARD (SV-3.1-rowquery): the SHAPE production actually returns.
     *
     * `PhlixMySQLConnection::query("SELECT …")` → `Workerman\MySQL\Connection::query()`
     * → `fetchAll()` returns a PLAIN `array<int, array<string, mixed>>`, never a
     * {@see ResultSet}. Before this fix `rows()`/`firstRow()`/`hasRows()` narrowed
     * ONLY on `instanceof ResultSet`, so every real SELECT yielded `[]`/`null`/`false`
     * and the entire DVR read path was inert against a live DB. These assertions
     * FAIL against the pre-fix `instanceof ResultSet`-only code and pass now.
     */
    public function testRowsAcceptsPlainArrayProductionShape(): void
    {
        $prodShape = [
            ['recording_id' => 'rec-1', 'status' => 'completed'],
            ['recording_id' => 'rec-2', 'status' => 'recording'],
        ];

        $rows = RowQuery::rows($prodShape);

        $this->assertCount(2, $rows);
        $this->assertSame('rec-1', $rows[0]['recording_id']);
        $this->assertSame('recording', $rows[1]['status']);
    }

    public function testFirstRowAcceptsPlainArrayProductionShape(): void
    {
        $prodShape = [
            ['recording_id' => 'rec-1', 'title' => 'The Evening News'],
            ['recording_id' => 'rec-2', 'title' => 'Late Show'],
        ];

        $row = RowQuery::firstRow($prodShape);

        $this->assertNotNull($row);
        $this->assertSame('rec-1', $row['recording_id']);
        $this->assertSame('The Evening News', $row['title']);
    }

    public function testFirstRowReturnsNullForEmptyPlainArrayResult(): void
    {
        // A SELECT that matched no rows returns [] in production.
        $this->assertNull(RowQuery::firstRow([]));
    }

    public function testHasRowsAcceptsPlainArrayProductionShape(): void
    {
        $this->assertTrue(RowQuery::hasRows([['recording_id' => 'rec-1']]));
        $this->assertFalse(RowQuery::hasRows([]));
    }
}
