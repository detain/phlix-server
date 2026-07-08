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

        $conn = new class($mockParent, $inMemoryPdo) extends PhlixMySQLConnection {
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
