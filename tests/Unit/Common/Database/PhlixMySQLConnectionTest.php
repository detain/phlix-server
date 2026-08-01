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
     * The NON-COROUTINE path must still commit for real.
     *
     * {@see PhlixMySQLConnection::commitTrans()} gained a
     * `$transLockHolder !== $cid` guard on 2026-07-31 that returns `true`
     * WITHOUT committing. It sits below the `$cid < 0` early return, so on the
     * CLI path (migrations, `bin/phlix`, cron) it must never be reached — if it
     * ever were, `$cid` and the default `$transLockHolder` are both `-1`, the
     * guard would fall through, and `releaseTransLock()` would `push()` to a
     * `Swoole\Coroutine\Channel` outside the coroutine runtime — a fatal
     * `Swoole\Error: API must be called in the coroutine`. This pins the
     * whole CLI lifecycle instead: the row is durable, the transaction is closed
     * and the mutex channel was never created at all.
     */
    public function testCommitTransOutsideACoroutineStillCommits(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (v TEXT)');
        $conn = $this->connectionWithHandle($pdo);

        $conn->beginTrans();
        $pdo->exec("INSERT INTO t (v) VALUES ('kept')");
        $this->assertTrue($conn->commitTrans(), 'the CLI path must delegate to the vendor commit');

        $this->assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn(),
            'a commit outside a coroutine must be durable, not a guarded no-op'
        );
        $this->assertFalse($pdo->inTransaction(), 'the transaction must be closed');
        $this->assertSame(
            0,
            self::transLockId($conn),
            'the CLI path must not create the coroutine mutex at all'
        );
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
     * The whole-transaction mutex must survive being HANDED ON.
     *
     * `Swoole\Coroutine\Channel::push()` resumes a parked consumer
     * SYNCHRONOUSLY, inside the push (measured on swoole 6.2.1: `Channel::push
     * → pop_coroutine: resume consumer cid=2 → on_resume from cid=1 to cid=2`).
     * So by the time a releasing coroutine's `push(true)` returns, the next
     * owner has already popped the token and written itself into
     * `$transLockHolder`. Any shared-state write the releaser performs AFTER the
     * push therefore lands on the NEW owner, not on itself. Until 2026-07-31
     * `commitTrans()`/`rollBackTrans()` did exactly that: they set
     * `$this->transLock = null` after pushing, discarding the very channel the
     * new owner and every other parked coroutine were using as the mutex. The
     * new owner's own release then found `$transLock === null` and pushed
     * nothing, so the token was never returned and every coroutine still parked
     * in `beginTrans()`'s `pop()` waited forever — a resident-worker hang, and
     * a later arrival minted a SECOND, independent channel and opened a
     * transaction concurrently with the current holder.
     *
     * WIDTH MATTERS for the HANG: the handoff strands the coroutines queued
     * BEHIND the one that is handed the token, and at width 2 there are none.
     * Measured on the unfixed class, `Coroutine::sleep()` standing in for a
     * statement round trip:
     *   width  2 → 0 of 2 stranded   (the hang is invisible here)
     *   width  8 → 6 of 8 stranded
     *   width 16 → 14 of 16 stranded
     * so the widths below include the production default (`DB_POOL_SIZE=8`) and
     * twice it, not just a pair. The channel-identity assertion is the sharper
     * detector — it catches the replaced mutex at width 2 as well (2 distinct
     * channels observed), before any coroutine has had a chance to hang — and
     * the two are asserted together because they fail for the same reason.
     *
     * @requires extension swoole
     */
    public function testTheTransactionMutexSurvivesBeingHandedOn(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        foreach ([2, 8, 16] as $width) {
            $r = self::runSharedTransactionWorkload($width);

            $this->assertSame(
                [],
                $r['stranded'],
                "width {$width}: coroutine(s) " . json_encode($r['stranded'])
                . ' were still parked in beginTrans() after 3 s — the mutex token was never '
                . 'returned. Completed: ' . json_encode($r['completed'])
            );
            $this->assertCount(
                $width,
                $r['completed'],
                "width {$width}: every transaction must run to completion"
            );
            $this->assertSame(
                [],
                $r['errors'],
                "width {$width}: no transaction may fail — " . json_encode($r['errors'])
            );
            $this->assertSame(
                1,
                $r['maxConcurrentHolders'],
                "width {$width}: two coroutines held the transaction at once"
            );
            $this->assertSame(
                1,
                count($r['lockIds']),
                "width {$width}: the mutex channel must be created once and never replaced, but "
                . count($r['lockIds']) . ' distinct channels were observed — coroutines holding '
                . 'different channels are not mutually excluded at all'
            );
            $this->assertNotContains(
                0,
                $r['lockIds'],
                "width {$width}: \$transLock was NULL while a transaction was open"
            );
        }
    }

    /**
     * A coroutine that no longer owns the mutex must not end the new owner's
     * transaction.
     *
     * This is the shape {@see PhlixMySQLConnection::execute()} creates on every
     * failed statement inside a transaction: `execute()` rolls back on the
     * caller's behalf — which RELEASES the whole-transaction mutex, handing it
     * synchronously to a coroutine parked in `beginTrans()` — and then rethrows,
     * so the caller's `catch` runs its own defensive `rollBackTrans()` when it
     * is already a STALE releaser. Measured on the unfixed class (the two
     * schedules are forced deterministically by the `$staleReleaserYields`
     * knob, not sampled):
     *   B's BEGIN not yet landed → holder is silently reset to -1 under B, and a
     *     third coroutine's `beginTrans()` sails past the holder check onto a
     *     fresh channel and dies with `PDOException: There is already an active
     *     transaction`;
     *   B's BEGIN landed        → B's transaction is ROLLED BACK by the stale
     *     releaser and B's own `commitTrans()` throws `PDOException: There is
     *     no active transaction`.
     * Both schedules are asserted here.
     */
    public function testAStaleReleaserCannotEndTheNewOwnersTransaction(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        foreach (['rollBackTrans', 'commitTrans'] as $staleCall) {
            foreach ([true, false] as $staleReleaserYields) {
                $label = $staleCall . ', ' . ($staleReleaserYields
                    ? 'fired after the new owner\'s BEGIN landed'
                    : 'fired while the new owner\'s BEGIN is still in flight');

                $r = self::runStaleReleaserWorkload($staleReleaserYields, $staleCall);

                $this->assertNull(
                    $r['newOwnerError'],
                    "{$label}: the new owner's transaction must survive — got {$r['newOwnerError']}"
                );
                $this->assertSame(
                    1,
                    $r['committedRows'],
                    "{$label}: the new owner's committed row must be durable"
                );
                $this->assertNull(
                    $r['thirdError'],
                    "{$label}: a third coroutine must wait for the mutex, not fail — got {$r['thirdError']}"
                );
                $this->assertSame(
                    1,
                    $r['maxConcurrentHolders'],
                    "{$label}: a third coroutine opened a transaction while the new owner held one"
                );
                $this->assertSame(
                    0,
                    $r['wedged'],
                    "{$label}: {$r['wedged']} coroutine(s) were still blocked after 3 s — a stale "
                    . 'releaser that pushes a second token into the capacity-1 mutex channel blocks '
                    . 'in push() forever, and the coroutine it wakes owns a transaction it never opened'
                );
            }
        }
    }

    /**
     * A coroutine that NEVER held the mutex must not end another coroutine's
     * transaction either.
     *
     * Same guard, different — and more reachable — shape than
     * {@see testAStaleReleaserCannotEndTheNewOwnersTransaction}:
     * {@see PhlixMySQLConnection::execute()} calls `rollBackTrans()` on ANY
     * failed statement, including one issued by a coroutine that has no
     * transaction of its own. On the shared socket (`DB_POOL_ENABLED=0`) that
     * lands on whichever coroutine's transaction happens to be open — and
     * `query()` lets such a statement through without taking the per-query lock
     * whenever `$transNesting > 0`, so it does not even have to wait for one.
     *
     * Measured on the unfixed class with this exact schedule: the outsider's
     * `rollBackTrans()` returned `true`, the owner's INSERT was GONE
     * (`COUNT(*) = 0`) and the owner's `commitTrans()` died with
     * `PDOException: There is no active transaction`.
     */
    public function testACoroutineThatNeverHeldTheMutexCannotEndAnothersTransaction(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (who TEXT)');
        $conn = self::connectionOver($pdo);

        $ownerError = null;
        $outsiderReturn = null;
        $holderAfterOutsider = null;
        $ownerCid = null;
        $done = 0;

        \Swoole\Coroutine\run(static function () use (
            $conn,
            $pdo,
            &$ownerError,
            &$outsiderReturn,
            &$holderAfterOutsider,
            &$ownerCid,
            &$done
        ): void {
            $gate = new \Swoole\Coroutine\Channel(1);

            \Swoole\Coroutine\go(static function () use ($conn, $pdo, $gate, &$ownerError, &$ownerCid, &$done): void {
                try {
                    $conn->beginTrans();
                    $ownerCid = \Swoole\Coroutine::getCid();
                    $pdo->exec("INSERT INTO t (who) VALUES ('owner')");
                    $gate->push(true);
                    \Swoole\Coroutine::sleep(0.05);   // the outsider's failed statement lands here
                    $conn->commitTrans();
                } catch (\Throwable $e) {
                    $ownerError = $e::class . ': ' . $e->getMessage();
                }
                $done++;
            });

            $outsider = \Swoole\Coroutine\go(static function () use (
                $conn,
                $gate,
                &$outsiderReturn,
                &$holderAfterOutsider,
                &$done
            ): void {
                $gate->pop();
                // Exactly what execute()'s catch does after a failed statement.
                $outsiderReturn = $conn->rollBackTrans();
                $holderAfterOutsider = self::transLockHolder($conn);
                $done++;
            });

            \Swoole\Coroutine\go(static function () use ($outsider, &$done): void {
                for ($t = 0; $t < 30 && $done < 2; $t++) {
                    \Swoole\Coroutine::sleep(0.1);
                }
                if (\Swoole\Coroutine::exists($outsider)) {
                    \Swoole\Coroutine::cancel($outsider);
                }
            });
        });

        $this->assertSame(2, $done, 'both coroutines must finish within 3 s');
        $this->assertTrue($outsiderReturn, 'a non-holder rollback must report success without acting');
        $this->assertNull(
            $ownerError,
            "the owner's transaction must survive the outsider's rollback — got {$ownerError}"
        );
        $this->assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn(),
            "the owner's committed row must be durable: a non-holder must issue no ROLLBACK"
        );
        $this->assertSame(
            $ownerCid,
            $holderAfterOutsider,
            'the outsider must not clear the owner\'s $transLockHolder'
        );
    }

    /**
     * A `beginTrans()` the DRIVER REFUSES must hand the mutex back — on the same
     * channel object.
     *
     * `parent::beginTrans()` can report failure by returning false rather than
     * throwing, and that path took the mutex before finding out. Until
     * 2026-07-31 it released the token and then set `$transLock = null`, so the
     * channel every parked coroutine was waiting on was discarded and the next
     * arrival minted a second, independent mutex — the fork described on
     * {@see PhlixMySQLConnection::commitTrans()}. Measured on the unfixed class
     * with this schedule: `$transLock` was NULL after the failed begin (so the
     * next `beginTrans()` created a fresh channel). Post-fix: one channel, its
     * single token back in it, holder cleared.
     */
    public function testABeginTransTheDriverRefusesHandsTheMutexBack(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $conn = self::connectionOver(new class extends \PDO {
            public function __construct()
            {
                parent::__construct('sqlite::memory:');
            }

            public function beginTransaction(): bool
            {
                return false;   // the driver reports failure without throwing
            }

            public function inTransaction(): bool
            {
                return false;
            }
        });

        $first = null;
        $second = null;
        $lockIds = [];
        $holderAfter = null;
        $tokensAfter = null;
        $wedged = true;

        \Swoole\Coroutine\run(static function () use (
            $conn,
            &$first,
            &$second,
            &$lockIds,
            &$holderAfter,
            &$tokensAfter,
            &$wedged
        ): void {
            $gate = new \Swoole\Coroutine\Channel(1);

            \Swoole\Coroutine\go(static function () use ($conn, $gate, &$first, &$lockIds): void {
                $first = $conn->beginTrans();
                $lockIds[] = self::transLockId($conn);
                $gate->push(true);
            });

            $waiter = \Swoole\Coroutine\go(static function () use (
                $conn,
                $gate,
                &$second,
                &$lockIds,
                &$holderAfter,
                &$tokensAfter,
                &$wedged
            ): void {
                $gate->pop();
                try {
                    // Must not park forever: the refused begin has to give the token back.
                    $second = $conn->beginTrans();
                    $lockIds[] = self::transLockId($conn);
                    $holderAfter = self::transLockHolder($conn);
                    $tokensAfter = self::transLockTokens($conn);
                    $wedged = false;
                } catch (\Throwable) {
                    // Same reasoning as
                    // {@see testAThrowingEndOfTransactionStillReleasesTheMutex}:
                    // the only cancel comes from the watchdog once `$wedged` is
                    // already the verdict, and a cancelled waiter is refused the
                    // mutex by design — so leave `$wedged` true rather than
                    // replacing the diagnosis with a fatal.
                }
            });

            \Swoole\Coroutine\go(static function () use ($waiter, &$wedged): void {
                for ($t = 0; $t < 30 && $wedged; $t++) {
                    \Swoole\Coroutine::sleep(0.1);
                }
                if (\Swoole\Coroutine::exists($waiter)) {
                    \Swoole\Coroutine::cancel($waiter);
                }
            });
        });

        $this->assertFalse($wedged, 'the second coroutine was still parked in beginTrans() after 3 s');
        $this->assertFalse($first, 'the refused begin must report failure');
        $this->assertFalse($second, 'the second refused begin must report failure too');
        $this->assertSame(
            1,
            count(array_unique($lockIds)),
            'the failed begin must not replace the mutex channel, but '
            . count(array_unique($lockIds)) . ' distinct channels were observed'
        );
        $this->assertNotContains(0, $lockIds, '$transLock was nulled by the failed begin');
        $this->assertSame(-1, $holderAfter, 'a failed begin must leave no holder recorded');
        $this->assertSame(1, $tokensAfter, 'the single mutex token must be back in the channel');
    }

    /**
     * A `beginTrans()` whose PARENT THROWS must hand the mutex back too.
     *
     * The returns-`false` twin above was handled from the start; the throwing
     * twin was not, and it is the reachable one: `beginTrans()`'s own `@throws`
     * names "a connect failure from the parent", and `workerman/mysql` rethrows
     * anything that is not 2006/2013 out of
     * `Connection::beginTrans()` (`vendor/workerman/mysql/src/Connection.php`
     * ~:1991), so one MySQL blip produces it.
     *
     * Nothing else releases the mutex on that path. Every coroutine-reachable
     * call site in `src/` writes `$db->beginTrans();` OUTSIDE its `try`, so the
     * throwing coroutine runs no `rollBackTrans()` of its own, and the holder
     * guards added on 2026-07-31 correctly refuse the accidental cross-coroutine
     * rescue the unfixed class used to get from an unrelated coroutine's
     * defensive rollback. Measured on the shared socket (`DB_POOL_ENABLED=0`)
     * with this schedule and no `try`/`catch` around the parent call: the next
     * coroutine was still parked after 3 s and only the watchdog's `cancel()`
     * freed it — i.e. one connect failure wedges every later transaction on that
     * connection until the worker restarts, which is the exact failure class
     * {@see PhlixMySQLConnection::commitTrans()} puts its release in a `finally`
     * to prevent, one method above.
     *
     * The ordering is forced, not sampled: the handle signals the gate from
     * INSIDE `beginTransaction()` (so the mutex is provably held), the waiter
     * then queues, and only afterwards does the handle throw.
     */
    public function testABeginTransWhoseParentThrowsHandsTheMutexBack(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $gate = new \Swoole\Coroutine\Channel(1);
        $conn = self::connectionOver(new class ($gate) extends \PDO {
            private int $calls = 0;

            public function __construct(private \Swoole\Coroutine\Channel $gate)
            {
                parent::__construct('sqlite::memory:');
                parent::setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            }

            public function beginTransaction(): bool
            {
                if ($this->calls++ === 0) {
                    // The mutex is held by the calling coroutine at this point.
                    $this->gate->push(true);
                    \Swoole\Coroutine::sleep(0.02);   // the waiter parks in pop() here
                    $e = new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
                    // A real connect failure carries errorInfo, and the vendor's
                    // catch READS $e->errorInfo[1] to spot 2006/2013 before
                    // rethrowing; 2002 is neither, so it rethrows. Populating it
                    // is what a live driver does — and it keeps this path free of
                    // the "array offset on null" warning the nested-begin tests
                    // have to silence.
                    $e->errorInfo = ['HY000', 2002, 'Connection refused'];
                    throw $e;
                }

                return parent::beginTransaction();
            }
        });

        $firstError = null;
        $secondError = null;
        $secondRan = false;
        $lockIds = [];
        $holderWhileHeld = null;
        $tokensWhileHeld = null;
        $holderAfter = null;
        $tokensAfter = null;
        $wedged = false;

        \Swoole\Coroutine\run(static function () use (
            $conn,
            $gate,
            &$firstError,
            &$secondError,
            &$secondRan,
            &$lockIds,
            &$holderWhileHeld,
            &$tokensWhileHeld,
            &$holderAfter,
            &$tokensAfter,
            &$wedged
        ): void {
            \Swoole\Coroutine\go(static function () use ($conn, &$firstError, &$lockIds): void {
                try {
                    $conn->beginTrans();
                } catch (\PDOException $e) {
                    // The harness's catch, NOT a caller's: no call site in src/
                    // has one, which is why the connection must free the mutex
                    // itself. Its job here is only to keep the throw from
                    // escaping the coroutine.
                    $firstError = $e->getMessage();
                }
                $lockIds[] = self::transLockId($conn);
            });

            $waiter = \Swoole\Coroutine\go(static function () use (
                $conn,
                $gate,
                &$secondError,
                &$secondRan,
                &$lockIds,
                &$holderWhileHeld,
                &$tokensWhileHeld
            ): void {
                $gate->pop();
                try {
                    $conn->beginTrans();
                    $lockIds[] = self::transLockId($conn);
                    $holderWhileHeld = self::transLockHolder($conn);
                    $tokensWhileHeld = self::transLockTokens($conn);
                    $conn->commitTrans();
                    $secondRan = true;
                } catch (\Throwable $e) {
                    $secondError = $e::class . ': ' . $e->getMessage();
                }
            });

            \Swoole\Coroutine\go(static function () use ($waiter, &$secondRan, &$secondError, &$wedged): void {
                for ($t = 0; $t < 30 && !$secondRan && $secondError === null; $t++) {
                    \Swoole\Coroutine::sleep(0.1);
                }
                if (\Swoole\Coroutine::exists($waiter)) {
                    // Record the verdict BEFORE cancelling: cancel() unparks the
                    // `pop()`, so anything measured afterwards is the RESCUE.
                    $wedged = true;
                    \Swoole\Coroutine::cancel($waiter);
                }
            });

            \Swoole\Coroutine\go(static function () use ($conn, &$holderAfter, &$tokensAfter): void {
                \Swoole\Coroutine::sleep(0.2);
                $holderAfter = self::transLockHolder($conn);
                $tokensAfter = self::transLockTokens($conn);
            });
        });

        $this->assertIsString($firstError, 'the parent failure must still reach the caller');
        $this->assertStringContainsString('Connection refused', $firstError);
        $this->assertFalse(
            $wedged,
            'beginTrans() threw and the mutex was never handed back — the next coroutine was still '
            . 'parked in beginTrans() after 3 s, i.e. one connect failure wedges every later '
            . 'transaction on this connection until the worker restarts'
        );
        $this->assertNull($secondError, "the next coroutine must get a working mutex — got {$secondError}");
        $this->assertTrue($secondRan, 'the next coroutine must be able to open a transaction');
        $this->assertSame(
            1,
            count(array_unique($lockIds)),
            'the throwing begin must not replace the mutex channel, but '
            . count(array_unique($lockIds)) . ' distinct channels were observed'
        );
        $this->assertNotContains(0, $lockIds, '$transLock was nulled by the throwing begin');
        $this->assertIsInt($holderWhileHeld);
        $this->assertGreaterThan(0, $holderWhileHeld, 'the next coroutine must be the recorded holder');
        $this->assertSame(0, $tokensWhileHeld, 'the token must be OUT of the channel while it is held');
        $this->assertSame(-1, $holderAfter, 'no holder may be left recorded once both coroutines are done');
        $this->assertSame(1, $tokensAfter, 'the single mutex token must be back in the channel');
    }

    /**
     * A coroutine CANCELLED while parked in the mutex must not become its holder.
     *
     * `beginTrans()` used to discard `pop()`'s return value, and `pop()` returns
     * `false` rather than the token when the parked coroutine is cancelled. The
     * cancellation is a REAL trigger, not a test-only one: Workerman's Swoole
     * event driver cancels EVERY live coroutine on worker stop
     * (`Workerman\Events\Swoole::stop()`,
     * `vendor/workerman/workerman/src/Events/Swoole.php:231-233` — the only
     * `Coroutine::cancel` in the whole of `vendor/`), `start.php:127` selects
     * that driver whenever the swoole extension is loaded, and both shipped
     * signals reach it: `ExecStop=SIGTERM` and `ExecReload=SIGUSR1`. Note
     * SIGUSR1 is Workerman's **non-graceful** reload
     * (`gracefulStop = ($signal === SIGUSR2)`), so the cancel fires without
     * waiting for in-flight work — it is not the "graceful reload" path an
     * earlier version of this comment named.
     *
     * WHICH CONFIGURATION the defect needs, because the trigger being live does
     * not make it live everywhere: a coroutine can only be PARKED in
     * `$transLock`'s `pop()` when the socket is contended, i.e. under
     * `DB_POOL_ENABLED=0`. In the default pooled mode `PooledMySQLConnection`
     * leases each coroutine a `PhlixMySQLConnection` of its own, so `$transLock`
     * is per-lease and nobody ever parks on it — and production evaluates
     * `pool_enabled` to `true` (read off the box 2026-08-01), so this is NOT a
     * prod-as-configured defect. It IS reachable in the documented
     * `DB_POOL_ENABLED=0` opt-out, which is the mode this whole mutex exists to
     * serve, and that is why the acquisition is checked.
     *
     * Measured on the unfixed class with this schedule (shared socket): the
     * cancelled waiter walked on, wrote ITSELF into `$transLockHolder`, and the
     * live owner's `commitTrans()` then hit the 2026-07-31 holder guard and
     * returned `true` WITHOUT committing — the transaction left open, the token
     * never returned (`tokens=0`, holder = the cancelled cid forever). A silent
     * lost commit at shutdown, which is precisely when a half-finished unit of
     * work is least visible. The acquisition is now checked, so the cancelled
     * waiter raises instead of stealing ownership.
     */
    public function testACancelledWaiterCannotStealTheTransactionMutex(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (who TEXT)');
        $conn = self::connectionOver($pdo);

        $ownerCid = null;
        $ownerError = null;
        $ownerCommit = null;
        $waiterError = null;
        $waiterOpened = false;
        $holderWhileOwnerHolds = null;

        \Swoole\Coroutine\run(static function () use (
            $conn,
            $pdo,
            &$ownerCid,
            &$ownerError,
            &$ownerCommit,
            &$waiterError,
            &$waiterOpened,
            &$holderWhileOwnerHolds
        ): void {
            $gate = new \Swoole\Coroutine\Channel(1);
            $parked = false;

            // The real owner.
            \Swoole\Coroutine\go(static function () use (
                $conn,
                $pdo,
                $gate,
                &$ownerCid,
                &$ownerError,
                &$ownerCommit,
                &$holderWhileOwnerHolds
            ): void {
                try {
                    $conn->beginTrans();
                    $ownerCid = \Swoole\Coroutine::getCid();
                    $pdo->exec("INSERT INTO t (who) VALUES ('owner')");
                    $gate->push(true);                 // the waiter may queue now
                    \Swoole\Coroutine::sleep(0.05);    // the cancel lands in here
                    $holderWhileOwnerHolds = self::transLockHolder($conn);
                    $ownerCommit = $conn->commitTrans();
                } catch (\Throwable $e) {
                    $ownerError = $e::class . ': ' . $e->getMessage();
                }
            });

            // The waiter that gets cancelled while parked in the mutex.
            $waiter = \Swoole\Coroutine\go(static function () use (
                $conn,
                $gate,
                &$parked,
                &$waiterError,
                &$waiterOpened
            ): void {
                $gate->pop();
                // Set immediately before the call: nothing between this line and
                // the mutex `pop()` inside beginTrans() yields, so one scheduler
                // turn after this flag is visible the waiter is provably parked.
                $parked = true;
                try {
                    $conn->beginTrans();
                    $waiterOpened = true;
                    $conn->commitTrans();
                } catch (\Throwable $e) {
                    $waiterError = $e::class . ': ' . $e->getMessage();
                }
            });

            \Swoole\Coroutine\go(static function () use ($waiter, &$parked): void {
                for ($t = 0; $t < 300 && !$parked; $t++) {
                    \Swoole\Coroutine::sleep(0.001);
                }
                \Swoole\Coroutine::sleep(0.001);   // the waiter is now inside pop()
                if (\Swoole\Coroutine::exists($waiter)) {
                    \Swoole\Coroutine::cancel($waiter);
                }
            });
        });

        // Ordered root-cause-first: the ownership theft is what makes the rest
        // happen, so it is the assertion a regression should report.
        $this->assertSame(
            $ownerCid,
            $holderWhileOwnerHolds,
            'the cancelled waiter stole ownership of the mutex from the live holder'
        );
        $this->assertFalse(
            $pdo->inTransaction(),
            "the owner's COMMIT must really have run: once ownership is stolen the holder guard "
            . "fires against the TRUE owner, so commitTrans() returns true and leaves the "
            . 'transaction open — a silent lost commit'
        );
        $this->assertSame(-1, self::transLockHolder($conn), 'the mutex must be left free');
        $this->assertSame(1, self::transLockTokens($conn), 'the single mutex token must be back');
        $this->assertNull($ownerError, "the owner's transaction must survive — got {$ownerError}");
        $this->assertTrue($ownerCommit, "the owner's commitTrans() must report success");
        $this->assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn(),
            "the owner's row must be durable"
        );
        $this->assertFalse($waiterOpened, 'a cancelled waiter must not open a transaction');
        $this->assertIsString($waiterError, 'a cancelled waiter must be refused the mutex, loudly');
        $this->assertStringContainsString(
            'Could not acquire the transaction mutex',
            $waiterError,
            'the cancelled waiter must fail on ACQUISITION, not later on the shared socket — got '
            . $waiterError
        );
    }

    /**
     * A COMMIT or ROLLBACK that THROWS must still hand the mutex on.
     *
     * `parent::commitTrans()` is `PDO::commit()` and `rollBackParent()` is
     * `PDO::rollBack()`; both raise on a connection lost mid-statement (2013),
     * which is why {@see \Phlix\Tests\Unit\Media\Library\TransactionalStreamsConnection}
     * models a throwing COMMIT at all. Before 2026-07-31 the mutex was pushed
     * back only on the success path, so a throwing COMMIT left the token with a
     * coroutine that no longer had a transaction and every later `beginTrans()`
     * on that connection waited forever — the failure was a wedged worker, not
     * a failed request. The release now runs from a `finally`.
     *
     * The watchdog is what makes a regression FAIL rather than hang the suite.
     */
    public function testAThrowingEndOfTransactionStillReleasesTheMutex(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        foreach (['commitTrans', 'rollBackTrans'] as $ending) {
            $conn = self::connectionOver(new class extends \PDO {
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
                    throw new \PDOException('SQLSTATE[HY000]: 2013 Lost connection during query');
                }

                public function inTransaction(): bool
                {
                    return true;
                }

                public function rollBack(): bool
                {
                    throw new \PDOException('SQLSTATE[HY000]: 2013 Lost connection during query');
                }
            });

            $secondOwnerRan = false;
            $firstThrew = false;
            $wedged = false;

            \Swoole\Coroutine\run(static function () use (
                $conn,
                $ending,
                &$secondOwnerRan,
                &$firstThrew,
                &$wedged
            ): void {
                $gate = new \Swoole\Coroutine\Channel(1);

                \Swoole\Coroutine\go(static function () use ($conn, $ending, $gate, &$firstThrew): void {
                    $conn->beginTrans();
                    $gate->push(true);
                    \Swoole\Coroutine::sleep(0.02);   // the next coroutine parks in pop()
                    try {
                        $conn->{$ending}();
                    } catch (\PDOException) {
                        $firstThrew = true;           // what the caller's catch sees
                    }
                });

                $second = \Swoole\Coroutine\go(static function () use ($conn, $gate, &$secondOwnerRan): void {
                    $gate->pop();
                    try {
                        $conn->beginTrans();
                        $secondOwnerRan = true;
                    } catch (\Throwable) {
                        // Only the watchdog below cancels this coroutine, and only
                        // once it has already recorded the wedge — and a CANCELLED
                        // waiter is now refused the mutex loudly (see
                        // {@see testACancelledWaiterCannotStealTheTransactionMutex}).
                        // Swallowing keeps the verdict in `$wedged` instead of
                        // turning an already-diagnosed wedge into an
                        // uncaught-exception fatal with no message.
                    }
                });

                \Swoole\Coroutine\go(static function () use ($second, &$secondOwnerRan, &$wedged): void {
                    for ($t = 0; $t < 30 && !$secondOwnerRan; $t++) {
                        \Swoole\Coroutine::sleep(0.1);
                    }
                    if (\Swoole\Coroutine::exists($second)) {
                        // Record the verdict BEFORE cancelling: cancel() makes the
                        // parked `pop()` return false and the coroutine carries on,
                        // so `$secondOwnerRan` alone would be set by the RESCUE and
                        // report a wedged mutex as healthy.
                        $wedged = true;
                        \Swoole\Coroutine::cancel($second);
                    }
                });
            });

            $this->assertTrue($firstThrew, "{$ending}(): the vendor failure must still reach the caller");
            $this->assertFalse(
                $wedged,
                "{$ending}() threw and the mutex was never handed on — the next coroutine was still "
                . 'parked in beginTrans() after 3 s, i.e. the worker is wedged, not merely erroring'
            );
            $this->assertTrue($secondOwnerRan, "{$ending}(): the next coroutine must get the mutex");
        }
    }

    /**
     * CONTROL for the DEFAULT (pooled) mode — `DB_POOL_ENABLED` is unset/1 in
     * production, and {@see \Phlix\Common\Database\PooledMySQLConnection} leases
     * a raw {@see PhlixMySQLConnection} per coroutine, so `$transLock` is
     * per-lease and the handoff above cannot arise. This runs the SAME
     * production-width workload through the pooled front to pin that.
     *
     * It passes on the unfixed class too (that is what "exempt" means); its job
     * is to catch a regression in the other direction — a change to the release
     * path that breaks the mode the fix is not aimed at. `maxSize=1` is included
     * because it is the pooled configuration closest to the shared socket.
     *
     * @requires extension swoole
     */
    public function testThePooledFrontIsUnaffectedAtProductionWidth(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        foreach ([1, 8] as $poolSize) {
            $r = self::runPooledTransactionWorkload(8, $poolSize);

            $this->assertSame([], $r['stranded'], "pool_size={$poolSize}: coroutines were stranded");
            $this->assertCount(8, $r['completed'], "pool_size={$poolSize}: all 8 must complete");
            $this->assertSame([], $r['errors'], "pool_size={$poolSize}: " . json_encode($r['errors']));
        }
    }

    /**
     * $workers coroutines, ONE shared connection — i.e. exactly what
     * `DB_POOL_ENABLED=0` gives every coroutine in a worker.
     *
     * Each worker runs `beginTrans() → work → commitTrans()`. The PDO handle is
     * a real in-memory SQLite one (so a mutual-exclusion failure surfaces as
     * PDO's own "cannot start a transaction within a transaction", not as a
     * counter this test invented) wrapped so that `beginTransaction()` yields —
     * the deterministic stand-in for the coroutine switch a runtime-hooked MySQL
     * socket performs mid-round-trip. A watchdog cancels anything still parked
     * after 3 s so a regression FAILS instead of hanging the suite.
     *
     * @return array{completed: list<int>, stranded: list<int>, errors: list<string>,
     *               maxConcurrentHolders: int, lockIds: list<int>}
     */
    private static function runSharedTransactionWorkload(int $workers): array
    {
        $conn = self::yieldingConnection();

        /** @var array{holders:int, maxConcurrentHolders:int, completed:list<int>,
         *             stranded:list<int>, errors:list<string>, lockIds:list<int>} $state */
        $state = [
            'holders' => 0,
            'maxConcurrentHolders' => 0,
            'completed' => [],
            'stranded' => [],
            'errors' => [],
            'lockIds' => [],
        ];
        /** @var array<int, int> $pending */
        $pending = [];

        \Swoole\Coroutine\run(static function () use ($conn, $workers, &$state, &$pending): void {
            for ($i = 0; $i < $workers; $i++) {
                \Swoole\Coroutine\go(static function () use ($i, $conn, &$state, &$pending): void {
                    $pending[$i] = \Swoole\Coroutine::getCid();
                    try {
                        $conn->beginTrans();
                        $state['lockIds'][] = self::transLockId($conn);
                        $state['holders']++;
                        $state['maxConcurrentHolders'] = max(
                            $state['maxConcurrentHolders'],
                            $state['holders']
                        );
                        \Swoole\Coroutine::sleep(0.002);   // a statement round trip
                        $state['holders']--;
                        $conn->commitTrans();
                        $state['completed'][] = $i;
                    } catch (\Throwable $e) {
                        $state['errors'][] = $i . ':' . $e::class . ': ' . $e->getMessage();
                    } finally {
                        unset($pending[$i]);
                    }
                });
            }

            \Swoole\Coroutine\go(static function () use (&$state, &$pending): void {
                for ($t = 0; $t < 30 && $pending !== []; $t++) {
                    \Swoole\Coroutine::sleep(0.1);
                }
                foreach ($pending as $i => $cid) {
                    $state['stranded'][] = $i;
                    \Swoole\Coroutine::cancel($cid);
                }
            });
        });

        sort($state['completed']);
        sort($state['stranded']);
        $state['lockIds'] = array_values(array_unique($state['lockIds']));

        return [
            'completed' => $state['completed'],
            'stranded' => $state['stranded'],
            'errors' => $state['errors'],
            'maxConcurrentHolders' => $state['maxConcurrentHolders'],
            'lockIds' => $state['lockIds'],
        ];
    }

    /**
     * The same production-width workload, but through the DEFAULT pooled front.
     *
     * @return array{completed: list<int>, stranded: list<int>, errors: list<string>}
     */
    private static function runPooledTransactionWorkload(int $workers, int $poolSize): array
    {
        $front = new \Phlix\Common\Database\PooledMySQLConnection(
            'unused',
            0,
            'unused',
            'unused',
            'unused',
            $poolSize,
            'utf8mb4',
            static fn (): Connection => self::yieldingConnection()
        );

        /** @var array{completed:list<int>, stranded:list<int>, errors:list<string>} $state */
        $state = ['completed' => [], 'stranded' => [], 'errors' => []];
        /** @var array<int, int> $pending */
        $pending = [];

        \Swoole\Coroutine\run(static function () use ($front, $workers, &$state, &$pending): void {
            for ($i = 0; $i < $workers; $i++) {
                \Swoole\Coroutine\go(static function () use ($i, $front, &$state, &$pending): void {
                    $pending[$i] = \Swoole\Coroutine::getCid();
                    try {
                        $front->beginTrans();
                        \Swoole\Coroutine::sleep(0.002);
                        $front->commitTrans();
                        $state['completed'][] = $i;
                    } catch (\Throwable $e) {
                        $state['errors'][] = $i . ':' . $e::class . ': ' . $e->getMessage();
                    } finally {
                        unset($pending[$i]);
                    }
                });
            }

            \Swoole\Coroutine\go(static function () use (&$state, &$pending): void {
                for ($t = 0; $t < 30 && $pending !== []; $t++) {
                    \Swoole\Coroutine::sleep(0.1);
                }
                foreach ($pending as $i => $cid) {
                    $state['stranded'][] = $i;
                    \Swoole\Coroutine::cancel($cid);
                }
            });
        });

        sort($state['completed']);
        sort($state['stranded']);

        return $state;
    }

    /**
     * Reproduce `execute()`'s internal rollback followed by the caller's
     * defensive one, with a third coroutine arriving afterwards.
     *
     * @param bool $staleReleaserYields Whether the stale releaser fires AFTER
     *        the new owner's BEGIN has landed (true) or while it is still in
     *        flight (false). Both are real schedules; the knob makes each one
     *        deterministic instead of a coin toss.
     * @param 'commitTrans'|'rollBackTrans' $staleCall Which call the stale
     *        releaser makes. `rollBackTrans` is the shape today's callers
     *        actually produce (a `catch` that rolls back after `execute()`
     *        already did); `commitTrans` is the same hazard through the other
     *        method — no caller reaches it today, but the guard exists on both
     *        because both would otherwise push a second token into a
     *        capacity-1 channel and block in `push()` forever.
     *
     * @return array{newOwnerError: ?string, thirdError: ?string, wedged: int,
     *               committedRows: int, maxConcurrentHolders: int}
     */
    private static function runStaleReleaserWorkload(
        bool $staleReleaserYields,
        string $staleCall = 'rollBackTrans'
    ): array {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (who TEXT)');
        $conn = self::connectionOver($pdo);

        /** @var array{newOwnerError: ?string, thirdError: ?string, holders:int,
         *             maxConcurrentHolders:int, done:int, wedged:int} $state */
        $state = [
            'newOwnerError' => null,
            'thirdError' => null,
            'holders' => 0,
            'maxConcurrentHolders' => 0,
            'done' => 0,
            'wedged' => 0,
        ];

        \Swoole\Coroutine\run(static function () use (
            $conn,
            $pdo,
            $staleReleaserYields,
            $staleCall,
            &$state
        ): void {
            $gate = new \Swoole\Coroutine\Channel(1);
            /** @var list<int> $actors */
            $actors = [];

            // The coroutine whose statement failed inside its transaction.
            $actors[] = \Swoole\Coroutine\go(static function () use (
                $conn,
                $gate,
                $staleReleaserYields,
                $staleCall,
                &$state
            ): void {
                $conn->beginTrans();
                $gate->push(true);
                \Swoole\Coroutine::sleep(0.02);      // the next owner parks in pop()
                $conn->rollBackTrans();              // execute()'s internal rollback
                if ($staleReleaserYields) {
                    \Swoole\Coroutine::sleep(0.03);  // the new owner's BEGIN lands here
                }
                $conn->{$staleCall}();               // the caller's catch — now STALE
                $state['done']++;
            });

            // The coroutine that is handed the mutex.
            $actors[] = \Swoole\Coroutine\go(static function () use ($conn, $pdo, $gate, &$state): void {
                $gate->pop();
                try {
                    $conn->beginTrans();
                    $state['holders']++;
                    $state['maxConcurrentHolders'] = max(
                        $state['maxConcurrentHolders'],
                        $state['holders']
                    );
                    \Swoole\Coroutine::sleep(0.05);  // its first statement is in flight
                    $pdo->exec("INSERT INTO t (who) VALUES ('new-owner')");
                    $state['holders']--;
                    $conn->commitTrans();
                } catch (\Throwable $e) {
                    $state['newOwnerError'] = $e::class . ': ' . $e->getMessage();
                }
                $state['done']++;
            });

            // An unrelated third coroutine, arriving while the new owner holds it.
            $actors[] = \Swoole\Coroutine\go(static function () use ($conn, &$state): void {
                \Swoole\Coroutine::sleep(0.06);
                try {
                    $conn->beginTrans();
                    $state['holders']++;
                    $state['maxConcurrentHolders'] = max(
                        $state['maxConcurrentHolders'],
                        $state['holders']
                    );
                    $state['holders']--;
                    $conn->commitTrans();
                } catch (\Throwable $e) {
                    $state['thirdError'] = $e::class . ': ' . $e->getMessage();
                }
                $state['done']++;
            });

            \Swoole\Coroutine\go(static function () use (&$state, &$actors): void {
                for ($t = 0; $t < 30 && $state['done'] < 3; $t++) {
                    \Swoole\Coroutine::sleep(0.1);
                }
                // Record the verdict BEFORE cancelling: cancel() makes a parked
                // `pop()` return false and the coroutine then runs to completion,
                // so counting finishers afterwards would credit the RESCUE.
                $state['wedged'] = 3 - $state['done'];
                // The cids are DERIVED, never literals. Swoole does not reset its
                // coroutine-id counter per `Coroutine\run()` — this workload runs
                // 4x per test method, so the three actors are [2,3,4] only on the
                // first invocation in the process and [7,8,9], [12,13,14], … after
                // that (measured). Hard-coded ids therefore cancelled nothing on
                // 3 of the 4 invocations, leaking parked coroutines and printing
                // swoole's "all coroutines are asleep - deadlock!" banner instead
                // of the watchdog's own verdict.
                foreach ($actors as $cid) {
                    if (\Swoole\Coroutine::exists($cid)) {
                        \Swoole\Coroutine::cancel($cid);
                    }
                }
            });
        });

        return [
            'newOwnerError' => $state['newOwnerError'],
            'thirdError' => $state['thirdError'],
            'committedRows' => (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn(),
            'maxConcurrentHolders' => $state['maxConcurrentHolders'],
            'wedged' => $state['wedged'],
        ];
    }

    /**
     * A connection over a REAL in-memory SQLite handle whose
     * `beginTransaction()` yields, standing in for the coroutine switch a
     * runtime-hooked MySQL socket performs inside the BEGIN round trip.
     */
    private static function yieldingConnection(): PhlixMySQLConnection
    {
        return self::connectionOver(new class extends \PDO {
            public function __construct()
            {
                parent::__construct('sqlite::memory:');
                parent::setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            }

            public function beginTransaction(): bool
            {
                $started = parent::beginTransaction();
                \Swoole\Coroutine::sleep(0.001);
                return $started;
            }
        });
    }

    /** Build a connection over a supplied PDO handle without opening a socket. */
    private static function connectionOver(\PDO $pdo): PhlixMySQLConnection
    {
        return new class ($pdo) extends PhlixMySQLConnection {
            /**
             * @psalm-suppress MissingParentConstructorCall Intentional: no socket.
             */
            public function __construct(\PDO $pdo)
            {
                $prop = new ReflectionProperty(parent::class, 'pdo');
                $prop->setAccessible(true);
                $prop->setValue($this, $pdo);
            }
        };
    }

    /** Identity of the connection's transaction-mutex channel, or 0 when null. */
    private static function transLockId(PhlixMySQLConnection $conn): int
    {
        $prop = new ReflectionProperty(PhlixMySQLConnection::class, 'transLock');
        $prop->setAccessible(true);
        $lock = $prop->getValue($conn);

        return is_object($lock) ? spl_object_id($lock) : 0;
    }

    /** Coroutine id the connection currently records as the transaction holder. */
    private static function transLockHolder(PhlixMySQLConnection $conn): int
    {
        $prop = new ReflectionProperty(PhlixMySQLConnection::class, 'transLockHolder');
        $prop->setAccessible(true);
        $holder = $prop->getValue($conn);

        return is_int($holder) ? $holder : -2;
    }

    /** Tokens currently sitting in the mutex channel (1 = free), or -1 when null. */
    private static function transLockTokens(PhlixMySQLConnection $conn): int
    {
        $prop = new ReflectionProperty(PhlixMySQLConnection::class, 'transLock');
        $prop->setAccessible(true);
        $lock = $prop->getValue($conn);

        return $lock instanceof \Swoole\Coroutine\Channel ? $lock->length() : -1;
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
     * NB two coroutines is BELOW the width at which the handoff defect fixed on
     * 2026-07-31 was observable — see
     * {@see testTheTransactionMutexSurvivesBeingHandedOn}, which is why that one
     * runs at 8 and 16 as well.
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
