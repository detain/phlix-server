<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use Phlix\Common\Database\PooledMySQLConnection;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Unit coverage for the parts of {@see PooledMySQLConnection} that DON'T need a
 * live coroutine scheduler: the non-coroutine (CLI) lease path, delegation of
 * every public method to the leased connection, and the injected raw-connection
 * factory seam. Most of the in-coroutine pool path (idle channel, per-coroutine
 * lease, defer-release) requires the Swoole runtime and is validated separately
 * on a live restart — see the class docblock — except for the dead-idle-
 * connection eviction path covered below, which runs real coroutines in-process
 * ({@see \Swoole\Coroutine\go()} + {@see \Swoole\Event::wait()}), mirroring the
 * pattern used by {@see PhlixMySQLConnectionTest::testConcurrentTransactionsDoNotInterleave()}.
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

    /**
     * Dead-idle-connection FD-churn regression (mirrors phlix-hub commit
     * a203070 — the identical twin class there had the same gap).
     *
     * When {@see PooledMySQLConnection::acquire()}'s `SELECT 1` liveness probe
     * finds an idle connection dead, it must call {@see Connection::closeConnection()}
     * on it BEFORE discarding it and opening a replacement — otherwise the
     * (possibly not-fully-dead) socket file descriptor lingers until GC instead
     * of being released immediately, churning FDs under connection drop bursts
     * (idle timeout / failover).
     *
     * Coroutine A leases the only connection (maxSize=1), then ends — its
     * lease is returned to the idle pool via `Coroutine::defer`. The test then
     * marks that connection dead (simulating the DB having dropped it while
     * idle) and runs coroutine B, which must: evict + close() the dead
     * connection, then open and use a brand-new one.
     *
     * @requires extension swoole
     */
    public function testDeadIdleConnectionIsClosedBeforeEviction(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        /** @var list<object{alive: bool, closes: int}> $created */
        $created = [];
        $factory = static function () use (&$created): Connection {
            $conn = new class extends Connection {
                public bool $alive = true;
                public int $closes = 0;

                public function __construct()
                {
                    // Deliberately NOT calling parent::__construct() — no
                    // real socket, mirroring PooledMySQLConnection itself.
                }

                /**
                 * @param string                        $query
                 * @param array<int|string, mixed>|null  $params
                 * @param int                            $fetchmode
                 * @return mixed
                 */
                public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
                {
                    if ($query === 'SELECT 1') {
                        if (!$this->alive) {
                            throw new \RuntimeException('server has gone away');
                        }
                        return [['1' => 1]];
                    }
                    return [];
                }

                public function closeConnection(): void
                {
                    $this->closes++;
                }
            };
            $created[] = $conn;
            return $conn;
        };

        $pool = $this->pool($factory, 1);

        // Coroutine A leases the only connection and immediately ends; its
        // lease is returned to idle via Coroutine::defer.
        \Swoole\Coroutine\go(static function () use ($pool): void {
            $pool->query('SELECT A');
        });
        \Swoole\Event::wait();

        self::assertCount(1, $created, 'coroutine A must have opened exactly one connection');

        // Simulate the DB dropping the now-idle connection.
        // S128: $created is annotated as a list of object{alive: bool, closes: int} so the
        // assertions below can read those fields. PHPStan treats an object SHAPE as
        // read-only, and the concrete type is an anonymous class built inside $factory,
        // which cannot be named. The write is load-bearing — it IS how this test
        // simulates the server dropping an idle connection.
        // @phpstan-ignore assign.propertyReadOnly
        $created[0]->alive = false;

        // Coroutine B must evict the dead idle connection (closing it) and
        // open a fresh replacement to serve its query.
        \Swoole\Coroutine\go(static function () use ($pool): void {
            $pool->query('SELECT B');
        });
        \Swoole\Event::wait();

        self::assertCount(2, $created, 'a replacement connection must be opened for coroutine B');
        self::assertSame(
            1,
            $created[0]->closes,
            'the evicted dead connection must be closeConnection()\'d, not merely dropped (FD-churn fix)'
        );
        self::assertSame(0, $created[1]->closes, 'the fresh connection must not itself be closed');
    }

    /**
     * S339 — connection-pool exhaustion under oversubscription is STRUCTURAL,
     * and the fix is per-query borrow outside transactions.
     *
     * Pre-fix, a coroutine held its lease for its WHOLE lifetime
     * ({@see PooledMySQLConnection::lease()} returned it to the idle pool only
     * via `Coroutine::defer()`, i.e. on coroutine exit). Under oversubscription
     * (more coroutines than pool slots) a waiter therefore had to survive a
     * holder's ENTIRE query loop; when the loop was slow enough — or the box
     * slow enough — the acquire timeout fired
     * ("pool exhausted: no idle connection available after N s") even though
     * only `maxSize` queries were ever in flight. Post-fix the connection is
     * returned to the pool on the coroutine's NEXT DB call whenever no
     * transaction is open (lazy return), so a waiter waits for ONE query.
     *
     * This test drives that distinction deterministically with fake
     * connections: poolSize=2, 6 coroutines × 4 queries, each query sleeping
     * 100 ms, acquire timeout 0.5 s. Pre-fix, a holder's loop is 4×0.1 = 0.4 s
     * and the tail waiter must survive ~2 holder loops (0.8 s) > 0.5 s → the
     * timeout fires. Post-fix, waiters wait ≤ 1 query (0.1 s) and all 6
     * complete. The pool's own counters ({@see PooledMySQLConnection::poolStats()})
     * stay bounded in both shapes — proving the failure is the lease LIFETIME,
     * not the accounting.
     *
     * @requires extension swoole
     */
    public function testOversubscribedCoroutinesDoNotExhaustThePoolWhenLoopsAreSlow(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $querySleep = 0.1;      // per-query duration on the fake connection
        $acquireTimeout = 0.5;  // must exceed one query (post-fix) but not two holder loops (pre-fix)
        $coros = 6;
        $queriesPer = 4;
        $poolSize = 2;

        /** @var list<object{queries: int}> $created */
        $created = [];
        $factory = static function () use ($querySleep, &$created): Connection {
            $conn = new class ($querySleep) extends Connection {
                public int $queries = 0;

                public function __construct(public float $querySleep)
                {
                    // Deliberately NOT calling parent::__construct() — no real
                    // socket, mirroring PooledMySQLConnection itself.
                }

                /**
                 * @param string                        $query
                 * @param array<int|string, mixed>|null  $params
                 * @param int                            $fetchmode
                 * @return mixed
                 */
                public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
                {
                    $this->queries++;
                    if ($query === 'SELECT 1') {
                        return [['1' => 1]]; // liveness probe — not a real query
                    }
                    \Swoole\Coroutine::sleep($this->querySleep);
                    return [];
                }

                public function closeConnection(): void
                {
                }

                public function beginTrans(): bool
                {
                    return true;
                }

                public function commitTrans(): bool
                {
                    return true;
                }

                public function rollBackTrans(): bool
                {
                    return true;
                }

                public function lastInsertId()
                {
                    return '1';
                }
            };
            $created[] = $conn;
            return $conn;
        };

        $pool = new PooledMySQLConnection('h', 3306, 'u', 'p', 'db', $poolSize, 'utf8mb4', $factory, $acquireTimeout);

        $errors = [];
        $maxCreated = 0;
        $completed = 0;
        \Swoole\Coroutine\run(static function () use (
            $pool,
            $coros,
            $queriesPer,
            &$errors,
            &$maxCreated,
            &$completed
        ): void {
            $wg = new \Swoole\Coroutine\WaitGroup();
            for ($i = 0; $i < $coros; $i++) {
                $wg->add();
                \Swoole\Coroutine::create(static function () use (
                    $pool,
                    $queriesPer,
                    &$errors,
                    &$maxCreated,
                    &$completed,
                    $wg
                ): void {
                    try {
                        for ($q = 0; $q < $queriesPer; $q++) {
                            $pool->query('SELECT 42');
                            $stats = $pool->poolStats();
                            $maxCreated = max($maxCreated, $stats['created']);
                        }
                        $completed++;
                    } catch (Throwable $e) {
                        $errors[] = $e->getMessage();
                    } finally {
                        $wg->done();
                    }
                });
            }
            $wg->wait();
            $pool->closeConnection();
        });

        $this->assertSame(
            [],
            $errors,
            'oversubscribed slow loops must not exhaust the pool: ' . implode(' | ', $errors)
        );
        $this->assertSame($coros, $completed, 'every coroutine must complete all its queries');
        $this->assertLessThanOrEqual(
            $poolSize,
            $maxCreated,
            'the pool must never exceed its ceiling (no accounting leak)'
        );
        $this->assertCount($poolSize, $created, 'the pool must open at most maxSize connections');
    }

    /**
     * S339 review round 1 #1 — the lazy return must NOT fire from
     * {@see PooledMySQLConnection::lastInsertId()}.
     *
     * The id resolves on the connection that performed the preceding INSERT,
     * which is still this coroutine's current lease. If the lazy return fired
     * on the lastInsertId() call itself, a waiter parked on the idle channel
     * would be handed the INSERT's connection (Channel::push resumes a waiter
     * synchronously) and lastInsertId() would read a FOREIGN connection's id.
     *
     * Deterministic shape: poolSize=2, three coroutines. A INSERTs then sleeps
     * (holding its lease), B holds the second connection, C parks as a waiter.
     * With the bug, A's lastInsertId() releases its connection to C, C INSERTs
     * on it, and A's id resolves to C's INSERT — wrong. With the fix, A's
     * lastInsertId() keeps the lease and resolves on its own INSERT.
     *
     * @requires extension swoole
     */
    public function testLastInsertIdResolvesOnTheInsertingConnectionEvenUnderContention(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        /** @var list<object{lastId: string}> $created */
        $created = [];
        $factory = static function () use (&$created): Connection {
            $conn = new class extends Connection {
                public string $lastId = '';

                public function __construct()
                {
                    // Deliberately NOT calling parent::__construct() — no real
                    // socket, mirroring PooledMySQLConnection itself.
                }

                /**
                 * @param string                        $query
                 * @param array<int|string, mixed>|null  $params
                 * @param int                            $fetchmode
                 * @return mixed
                 */
                public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
                {
                    if ($query === 'SELECT 1') {
                        return [['1' => 1]]; // liveness probe — not a real query
                    }
                    if (str_starts_with($query, 'INSERT ')) {
                        $this->lastId = 'id-' . trim(substr($query, 7));
                    }
                    return [];
                }

                public function lastInsertId()
                {
                    return $this->lastId;
                }

                public function closeConnection(): void
                {
                }

                public function beginTrans(): bool
                {
                    return true;
                }

                public function commitTrans(): bool
                {
                    return true;
                }

                public function rollBackTrans(): bool
                {
                    return true;
                }
            };
            $created[] = $conn;
            return $conn;
        };

        $pool = new PooledMySQLConnection('h', 3306, 'u', 'p', 'db', 2, 'utf8mb4', $factory);

        $aId = null;
        $errors = [];
        \Swoole\Coroutine\run(static function () use ($pool, &$aId, &$errors): void {
            $wg = new \Swoole\Coroutine\WaitGroup();

            $wg->add();
            \Swoole\Coroutine::create(static function () use ($pool, &$aId, &$errors, $wg): void {
                try {
                    $pool->query('INSERT A');
                    \Swoole\Coroutine::sleep(0.2); // let B hold the 2nd connection and C park
                    $aId = $pool->lastInsertId();
                } catch (Throwable $e) {
                    $errors[] = 'A: ' . $e->getMessage();
                } finally {
                    $wg->done();
                }
            });

            $wg->add();
            \Swoole\Coroutine::create(static function () use ($pool, &$errors, $wg): void {
                try {
                    $pool->query('SELECT B');
                    \Swoole\Coroutine::sleep(0.5); // hold the 2nd connection so C parks
                    $pool->query('SELECT B2'); // lazy release
                } catch (Throwable $e) {
                    $errors[] = 'B: ' . $e->getMessage();
                } finally {
                    $wg->done();
                }
            });

            $wg->add();
            \Swoole\Coroutine::create(static function () use ($pool, &$errors, $wg): void {
                try {
                    // Parks until a connection is released; with the pre-fix
                    // lazy return this coroutine INSERTs on A's connection.
                    $pool->query('INSERT C');
                } catch (Throwable $e) {
                    $errors[] = 'C: ' . $e->getMessage();
                } finally {
                    $wg->done();
                }
            });

            $wg->wait();
            $pool->closeConnection();
        });

        $this->assertSame([], $errors, 'no coroutine may error: ' . implode(' | ', $errors));
        $this->assertSame(
            'id-A',
            $aId,
            'lastInsertId must resolve on the INSERTing connection even when a waiter is parked'
        );
        $this->assertCount(2, $created, 'the pool must stay within its ceiling');
    }

    /**
     * S339 — the refused-vs-hung distinction, dead-dependency control.
     *
     * The exhaustion message ("pool exhausted: no idle connection available
     * after N s") is a REFUSED acquisition: the pop timeout fired. It must be
     * distinguishable from a dead dependency, which must surface immediately
     * as the connect error instead of making every caller burn the full
     * acquireTimeout and then blame the pool.
     *
     * @requires extension swoole
     */
    public function testADeadDependencySurfacesImmediatelyNotAsPoolExhaustion(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $pool = new PooledMySQLConnection(
            'h',
            3306,
            'u',
            'p',
            'db',
            2,
            'utf8mb4',
            static function (): Connection {
                throw new \RuntimeException('Connection refused: 127.0.0.1:9');
            },
            10.0
        );

        $elapsed = null;
        $message = null;
        \Swoole\Coroutine\run(static function () use ($pool, &$elapsed, &$message): void {
            $t0 = hrtime(true);
            try {
                $pool->query('SELECT 1');
            } catch (Throwable $e) {
                $elapsed = (hrtime(true) - $t0) / 1e9;
                $message = $e->getMessage();
            } finally {
                $pool->closeConnection();
            }
        });

        $this->assertNotNull($message, 'a dead dependency must throw, not hang');
        $this->assertStringContainsString('Connection refused', (string) $message);
        $this->assertLessThan(
            1.0,
            (float) $elapsed,
            'a dead dependency must fail immediately, not burn the acquire timeout and report pool exhaustion'
        );
    }
}
