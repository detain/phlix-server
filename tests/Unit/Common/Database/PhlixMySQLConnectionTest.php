<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use Phlix\Common\Database\PhlixMySQLConnection;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\MySQL\Connection;

/**
 * Unit coverage for {@see PhlixMySQLConnection} transaction mutex.
 *
 * Since the mutex is only meaningful inside a Swoole coroutine runtime, these
 * tests verify:
 * 1. The class has the expected transaction-lifecycle methods with correct signatures
 * 2. The transaction-lock properties are declared and default-initialized correctly
 * 3. The non-coroutine path delegates to the parent (verified via mock)
 *
 * Concurrent/serialization behaviour inside coroutines is validated separately
 * via integration tests on a live Swoole worker restart.
 *
 * @see PhlixMySQLConnection::beginTrans()
 * @see PhlixMySQLConnection::commitTrans()
 * @see PhlixMySQLConnection::rollBackTrans()
 * @see PhlixMySQLConnection::query()
 */
final class PhlixMySQLConnectionTest extends TestCase
{
    /**
     * Verify the transaction-lock properties are declared on the class.
     *
     * This uses reflection to check property DECLARATIONS only (names and types),
     * not values — so it never instantiates the class and thus never opens a
     * connection socket.
     */
    public function testTransactionLockPropertiesAreDeclared(): void
    {
        // These property names must match the private fields in PhlixMySQLConnection.
        $expectedProperties = [
            'queryLock' => \Swoole\Coroutine\Channel::class,
            'queryLockHolder' => 'int',
            'transLock' => \Swoole\Coroutine\Channel::class,
            'transLockHolder' => 'int',
            'transNesting' => 'int',
        ];

        $class = PhlixMySQLConnection::class;
        foreach ($expectedProperties as $name => $expectedType) {
            $prop = new ReflectionProperty($class, $name);
            $prop->setAccessible(true);

            $type = $prop->getType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $type);
            $this->assertSame(
                $expectedType,
                $type->getName(),
                "Property \${$name} must be declared as {$expectedType}"
            );
        }
    }

    /**
     * Verify beginTrans(), commitTrans() and rollBackTrans() are declared
     * public on the class (they override the parent's abstract/public
     * counterparts from Workerman\MySQL\Connection).
     */
    public function testTransactionMethodsArePublic(): void
    {
        $class = PhlixMySQLConnection::class;

        foreach (['beginTrans', 'commitTrans', 'rollBackTrans'] as $method) {
            $reflection = new \ReflectionMethod($class, $method);
            $this->assertTrue(
                $reflection->isPublic(),
                "Method {$method}() must be public"
            );
        }
    }

    /**
     * Verify query() is still public (it carries the per-query mutex logic).
     */
    public function testQueryMethodIsPublic(): void
    {
        $reflection = new \ReflectionMethod(PhlixMySQLConnection::class, 'query');
        $this->assertTrue($reflection->isPublic(), 'query() must be public');
    }

    /**
     * Verify the constructor accepts exactly 6 parameters with the expected
     * defaults (charset defaults to 'utf8mb4', others required).
     * Uses reflection only — does not open a connection.
     */
    public function testConstructorSignature(): void
    {
        $ctor = new \ReflectionMethod(PhlixMySQLConnection::class, '__construct');
        $params = $ctor->getParameters();

        $this->assertCount(6, $params, 'Constructor must accept exactly 6 parameters');

        $this->assertSame('host', $params[0]->getName());
        $this->assertSame('port', $params[1]->getName());
        $this->assertSame('user', $params[2]->getName());
        $this->assertSame('password', $params[3]->getName());
        $this->assertSame('db_name', $params[4]->getName());
        $this->assertSame('charset', $params[5]->getName());

        $this->assertTrue($params[5]->isDefaultValueAvailable());
        $this->assertSame('utf8mb4', $params[5]->getDefaultValue());
    }

    /**
     * A second `beginTrans()` on a connection that already has one open THROWS.
     * It does not open an inner scope, and no SAVEPOINT is issued anywhere.
     *
     * This pins the contract the class docblocks claimed the opposite of until
     * 2026-07-28 ("nested transactions issue MySQL SAVEPOINTs and the mutex is
     * held until the outermost commit"). Nothing in `workerman/mysql` issues a
     * savepoint — `beginTrans()` is a bare `PDO::beginTransaction()` — so the
     * false claim was readable as proof that the class is nesting-safe, and it
     * was read that way once already.
     *
     * Uses a REAL in-memory PDO rather than a mock precisely because the refusal
     * comes from PDO itself (`pdo_dbh.c`, driver-independent), so this is the
     * same mechanism MySQL 8.0.46 exhibits — measured on both connection classes
     * and both `DB_POOL_ENABLED` modes, see
     * `steps/fix-savepoint-docblocks.worklog.md`.
     *
     * This is the NON-coroutine path (`Coroutine::getCid() < 0`), which
     * delegates straight to the parent.
     */
    public function testANestedBeginTransThrowsInsteadOfOpeningAnInnerScope(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $conn = $this->connectionWithHandle($pdo);

        $this->assertTrue($conn->beginTrans(), 'the OUTER transaction must open');

        $thrown = self::captureVendorNullOffsetWarning(static function () use ($conn): ?\Throwable {
            try {
                $conn->beginTrans();
                return null;
            } catch (\Throwable $e) {
                return $e;
            }
        });

        $this->assertInstanceOf(
            \PDOException::class,
            $thrown,
            'a nested beginTrans() must throw — it does NOT create a savepoint'
        );
        $this->assertStringContainsString('There is already an active transaction', $thrown->getMessage());
        $this->assertTrue(
            $pdo->inTransaction(),
            'the refused nested call must leave the OUTER transaction untouched'
        );
    }

    /**
     * Same contract inside a coroutine, where the class takes its own
     * `transLockHolder === $cid` branch instead of delegating straight to the
     * parent — and where getting it wrong is worse than a throw.
     *
     * That branch exists to FAIL FAST: a nested call that fell through to the
     * mutex would `pop()` a channel the calling coroutine has already emptied
     * and hang the worker forever. So the test also fails on a DEADLOCK rather
     * than hanging the suite: a watchdog coroutine cancels the worker coroutine
     * if the nested call has not returned within 3 s.
     *
     * Finally it pins that nothing leaks: the outer transaction still commits
     * and the connection accepts a fresh transaction afterwards (i.e. the
     * whole-transaction mutex was neither double-released nor stranded).
     */
    public function testANestedBeginTransInsideACoroutineThrowsWithoutDeadlocking(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $pdo = new \PDO('sqlite::memory:');
        $conn = $this->connectionWithHandle($pdo);

        $thrown = null;
        $reusable = false;
        $deadlocked = false;
        $finished = false;

        \Swoole\Coroutine\run(static function () use (
            $conn,
            &$thrown,
            &$reusable,
            &$deadlocked,
            &$finished
        ): void {
            $worker = \Swoole\Coroutine::getCid();
            \Swoole\Coroutine\go(static function () use ($worker, &$deadlocked, &$finished): void {
                // Poll in slices so the watchdog costs ~100 ms on the happy path
                // instead of holding the scheduler open for the full timeout.
                for ($i = 0; $i < 30 && !$finished; $i++) {
                    \Swoole\Coroutine::sleep(0.1);
                }
                if (!$finished) {
                    $deadlocked = true;
                    \Swoole\Coroutine::cancel($worker);
                }
            });

            $conn->beginTrans();
            $thrown = self::captureVendorNullOffsetWarning(static function () use ($conn): ?\Throwable {
                try {
                    $conn->beginTrans();
                    return null;
                } catch (\Throwable $e) {
                    return $e;
                }
            });
            $conn->commitTrans();
            $reusable = $conn->beginTrans();
            $conn->rollBackTrans();
            $finished = true;
        });

        $this->assertFalse(
            $deadlocked,
            'a nested beginTrans() blocked instead of throwing — the same coroutine waited on the '
            . 'transaction mutex it already holds, which wedges the worker permanently'
        );
        $this->assertInstanceOf(\PDOException::class, $thrown, 'a nested beginTrans() must throw');
        $this->assertStringContainsString('There is already an active transaction', $thrown->getMessage());
        $this->assertTrue($reusable, 'the connection must still be usable after the refused nested call');
    }

    /**
     * The rollback that {@see PhlixMySQLConnection::execute()} performs on the
     * caller's behalf must not fatal when the PDO handle is already gone.
     *
     * `parent::rollBackTrans()` dereferences `$this->pdo` unconditionally, and
     * `execute()`'s "server has gone away" branch calls `closeConnection()`
     * (which nulls it) before retrying — so when the RECONNECT also fails, the
     * catch used to raise `Error: Call to a member function inTransaction() on
     * null`, an error type that is not a `PDOException` and therefore replaces
     * the real connect failure on its way out of every `catch (\PDOException)`.
     * Observed in unpooled mode as `ItemRepository::markStreamsProbed()` dying
     * with exactly that message.
     */
    public function testRollBackTransDoesNotFatalWhenThePdoHandleIsGone(): void
    {
        $conn = $this->connectionWithHandle(null);

        $this->assertTrue(
            $conn->rollBackTrans(),
            'rollBackTrans() on a handle-less connection must be a no-op, not a null dereference'
        );
    }

    /**
     * …and the handle-less guard must not have turned rollback into a no-op for
     * the normal case: with a live handle the transaction is really rolled back.
     */
    public function testRollBackTransStillRollsBackWithALiveHandle(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (v TEXT)');
        $conn = $this->connectionWithHandle($pdo);

        $conn->beginTrans();
        $pdo->exec("INSERT INTO t (v) VALUES ('doomed')");
        $this->assertTrue($conn->rollBackTrans());

        $this->assertSame(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn(),
            'the rolled-back INSERT must be gone'
        );
        $this->assertFalse($pdo->inTransaction());
    }

    /**
     * Build a connection over a supplied PDO handle (or none) WITHOUT opening a
     * socket: the constructor deliberately skips `parent::__construct()`, the
     * same technique {@see \Phlix\Common\Database\PooledMySQLConnection} uses.
     */
    private function connectionWithHandle(?\PDO $pdo): PhlixMySQLConnection
    {
        return new class ($pdo) extends PhlixMySQLConnection {
            /**
             * @psalm-suppress MissingParentConstructorCall Intentional: no socket.
             */
            public function __construct(?\PDO $pdo)
            {
                if ($pdo === null) {
                    return;
                }
                $prop = new ReflectionProperty(parent::class, 'pdo');
                $prop->setAccessible(true);
                $prop->setValue($this, $pdo);
            }
        };
    }

    /**
     * Run $body with the one PHP warning this vendor path emits silenced.
     *
     * `workerman/mysql`'s `beginTrans()` catch block reads `$e->errorInfo[1]` to
     * recognise a "server has gone away" code, but PDO's "There is already an
     * active transaction" exception carries NO `errorInfo`, so the read warns
     * ("Trying to access array offset on null",
     * `vendor/workerman/mysql/src/Connection.php` ~:2000). It is vendor
     * behaviour on the exact path these tests exist to pin, and `phpunit.xml`
     * sets `failOnWarning="true"` — so it is suppressed HERE, narrowly, for one
     * call, and the handler is restored immediately.
     *
     * @param callable(): ?\Throwable $body
     */
    private static function captureVendorNullOffsetWarning(callable $body): ?\Throwable
    {
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            return $body();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Verify that concurrent coroutines cannot interleave queries inside a
     * transaction. Coroutine A acquires the transaction lock first; coroutine
     * B's beginTrans() must block on the mutex until A releases it. The
     * resulting query log must be two contiguous blocks (one per txn), never
     * interleaved.
     *
     * This is a light-weight behavioral test: it verifies the LOCKING
     * side-effect via a signal channel rather than subclassing a final class.
     *
     * @requires extension swoole
     */
    public function testConcurrentTransactionsDoNotInterleave(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        // Build a mock parent so we can record queries without a real socket.
        $mockParent = $this->createMock(Connection::class);
        /** @var list<array{cid: int, sql: string, ts: int}> $log */
        $log = [];

        $mockParent->method('beginTrans')->willReturn(true);
        $mockParent->method('commitTrans')->willReturn(true);
        $mockParent->method('rollBackTrans')->willReturn(true);
        $mockParent->method('query')->willReturnCallback(
            static function (string $sql) use (&$log): array {
                $log[] = [
                    'cid' => \Swoole\Coroutine::getCid(),
                    'sql' => $sql,
                    'ts'  => hrtime(true),
                ];
                return [['id' => 1]];
            }
        );

        // Anonymous subclass — does NOT call parent::__construct() so the
        // PDO socket is never opened.  We wire up a minimal in-memory PDO
        // instance (SQLite) so that Connection::beginTrans() / commitTrans()
        // can execute their real logic (including the mutex state changes)
        // without crashing on a null PDO.
        $inMemoryPdo = new class extends \PDO {
            public function __construct()
            {
                parent::__construct('sqlite::memory:');
            }
            public function beginTransaction(): bool
            {
                return true;
            }
            public function commit(): bool
            {
                return true;
            }
            public function rollBack(): bool
            {
                return true;
            }
        };

        $conn = new class ($mockParent, $inMemoryPdo) extends PhlixMySQLConnection {
            public function __construct(
                private Connection $mock,
                \PDO $pdo,
            ) {
                // Skipping parent::__construct() keeps $this->pdo null, so we
                // inject the in-memory PDO via reflection.
                $pdoProp = new \ReflectionProperty(parent::class, 'pdo');
                $pdoProp->setAccessible(true);
                $pdoProp->setValue($this, $pdo);
            }
            public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
            {
                return $this->mock->query($query, $params, $fetchmode);
            }
            // beginTrans/commitTrans/rollBackTrans are NOT overridden — they
            // use the real PhlixMySQLConnection mutex logic via parent:: calls.
        };

        $aStarted = new \Swoole\Coroutine\Channel(1);

        // Coroutine A: acquires the trans lock first.
        \Swoole\Coroutine\go(static function () use ($conn, $aStarted): void {
            $conn->beginTrans();         // Acquires transLock.
            $aStarted->push(true);       // Signal B it can try beginTrans.
            // B's beginTrans() will block on transLock because A holds it.
            $conn->query('SELECT 1 FROM txn_a');
            $conn->query('SELECT 2 FROM txn_a');
            $conn->commitTrans();       // Releases transLock.
        });

        // Coroutine B: tries to beginTrans — must block until A commits.
        \Swoole\Coroutine\go(static function () use ($conn, $aStarted): void {
            $aStarted->pop();           // Wait until A has acquired the lock.
            // This call BLOCKS on transLock until A commits.
            $conn->beginTrans();
            $conn->query('SELECT 1 FROM txn_b');
            $conn->query('SELECT 2 FROM txn_b');
            $conn->commitTrans();
        });

        // Give coroutines time to finish.
        \Swoole\Event::wait();

        // Both transactions must have completed.
        $this->assertCount(
            4,
            $log,
            'Expected 4 queries — 2 from each transaction. Log: '
            . json_encode(array_column($log, 'sql'))
        );

        // Extract the sequence of coroutine IDs in call order.
        $callCids = array_column($log, 'cid');

        // Group log indices by coroutine.
        $cidGroups = [];
        foreach ($callCids as $i => $cid) {
            $this->assertIsInt($cid);
            $cidGroups[$cid][] = $i;
        }
        $this->assertCount(
            2,
            $cidGroups,
            'Exactly two coroutines must appear in the log'
        );

        $groups = array_values($cidGroups);
        $firstBlock = $groups[0];
        $secondBlock = $groups[1];

        // Both queries of each txn must form a contiguous block.
        $this->assertCount(2, $firstBlock);
        $this->assertCount(2, $secondBlock);

        // The two blocks must not interleave: all of txn 1's indices must
        // precede all of txn 2's indices.
        $this->assertLessThan(
            $secondBlock[0],
            $firstBlock[1],
            'Transaction query blocks must not interleave'
        );
    }
}
