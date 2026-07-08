<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use Phlix\Common\Database\PooledMySQLConnection;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit coverage for the parts of {@see PooledMySQLConnection} that DON'T need a
 * live coroutine scheduler: the non-coroutine (CLI) lease path, delegation of
 * every public method to the leased connection, and the injected raw-connection
 * factory seam. The in-coroutine pool path (idle channel, per-coroutine lease,
 * defer-release) requires the Swoole runtime and is validated separately on a
 * live restart — see the class docblock.
 */
final class PooledMySQLConnectionTest extends TestCase
{
    /**
     * Build a pool whose raw connections come from the given factory, so tests
     * never open a real socket.
     *
     * @param callable():Connection $factory
     */
    private function pool(callable $factory, int $maxSize = 4): PooledMySQLConnection
    {
        return new PooledMySQLConnection('h', 3306, 'u', 'p', 'db', $maxSize, 'utf8mb4', $factory);
    }

    public function testQueryDelegatesToLeasedConnection(): void
    {
        $raw = $this->createMock(Connection::class);
        $raw->expects($this->once())
            ->method('query')
            ->with('SELECT 1', [1, 2])
            ->willReturn([['ok' => 1]]);

        $pool = $this->pool(static fn (): Connection => $raw);

        $this->assertSame([['ok' => 1]], $pool->query('SELECT 1', [1, 2]));
    }

    public function testNonCoroutineReusesASingleConnection(): void
    {
        $calls = 0;
        $raw = $this->createMock(Connection::class);
        $raw->method('query')->willReturn([]);

        // Outside a coroutine every call must reuse the one CLI connection, so
        // the factory is invoked exactly once across many queries.
        $pool = $this->pool(static function () use (&$calls, $raw): Connection {
            $calls++;
            return $raw;
        });

        $pool->query('SELECT 1');
        $pool->query('SELECT 2');
        $pool->query('SELECT 3');

        $this->assertSame(1, $calls, 'CLI path should open exactly one connection');
    }

    public function testRowSingleAndColumnDelegateToLeasedConnection(): void
    {
        // S9: the pool front is now the DEFAULT connection, so it must be a
        // faithful drop-in for the row-returning literal-SQL read helpers too —
        // not just query(). row() in particular is the only primitive that
        // fetches a row for statements query() doesn't special-case (e.g.
        // EXPLAIN); before this delegation existed, a caller hitting the pooled
        // connection's un-constructed parent row() crashed with a socket connect
        // (SQLSTATE[HY000] [2002]) instead of running the query.
        $raw = $this->createMock(Connection::class);
        $raw->expects($this->once())
            ->method('row')
            ->with('EXPLAIN SELECT 1', [7])
            ->willReturn(['id' => 1, 'type' => 'ref']);
        $raw->expects($this->once())
            ->method('single')
            ->with('SELECT COUNT(*) FROM t WHERE x = ?', [3])
            ->willReturn('5');
        $raw->expects($this->once())
            ->method('column')
            ->with('SELECT name FROM t', null)
            ->willReturn(['a', 'b']);

        $pool = $this->pool(static fn (): Connection => $raw);

        $this->assertSame(['id' => 1, 'type' => 'ref'], $pool->row('EXPLAIN SELECT 1', [7]));
        $this->assertSame('5', $pool->single('SELECT COUNT(*) FROM t WHERE x = ?', [3]));
        $this->assertSame(['a', 'b'], $pool->column('SELECT name FROM t'));
    }

    public function testTransactionMethodsDelegate(): void
    {
        $raw = $this->createMock(Connection::class);
        $raw->expects($this->once())->method('beginTrans')->willReturn(true);
        $raw->expects($this->once())->method('commitTrans')->willReturn(true);
        $raw->expects($this->once())->method('rollBackTrans')->willReturn(true);
        $raw->expects($this->once())->method('lastInsertId')->willReturn('42');

        $pool = $this->pool(static fn (): Connection => $raw);

        $this->assertTrue($pool->beginTrans());
        $this->assertTrue($pool->commitTrans());
        $this->assertTrue($pool->rollBackTrans());
        $this->assertSame('42', $pool->lastInsertId());
    }

    public function testCloseConnectionClosesTheCliConnection(): void
    {
        $raw = $this->createMock(Connection::class);
        $raw->method('query')->willReturn([]);
        $raw->expects($this->once())->method('closeConnection');

        $pool = $this->pool(static fn (): Connection => $raw);
        $pool->query('SELECT 1'); // opens the CLI connection
        $pool->closeConnection();
    }

    public function testIsTypeCompatibleWithWorkermanConnection(): void
    {
        // Every Phlix service type-hints Workerman\MySQL\Connection; the pool
        // front MUST satisfy that hint.
        $pool = $this->pool(fn (): Connection => $this->createMock(Connection::class));
        $this->assertInstanceOf(Connection::class, $pool);
    }
}
