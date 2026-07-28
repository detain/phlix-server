<?php

/**
 * Phlix media server tests: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PDOException;
use Phlix\Media\Library\ItemRepository;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * `ItemRepository::replaceStreams()` — the ATOMIC media_streams replacement.
 *
 * Replacing an item's stream rows is necessarily a DELETE followed by N INSERTs
 * (the table has no unique key on `media_item_id + stream_index`). Issued as bare
 * autocommit statements that sequence had two confirmed defects:
 *
 *  1. a concurrent reader could observe the item MID-replacement — an empty or
 *     partial `streams[]` — which is genuinely reachable here: `start.php` runs 14
 *     HTTP workers, and with the default connection pool each coroutine leases its
 *     OWN connection, so the writes are not serialised even inside one worker;
 *  2. a write failure part-way through left the item PERMANENTLY partial (the old
 *     rows were already deleted and nothing repairs it).
 *
 * Both are properties of the *transaction boundary*, not of the SQL, so these
 * tests run the REAL repository against {@see TransactionalStreamsConnection} —
 * a double that actually snapshots/restores its table on begin/rollback, exposes
 * a separate COMMITTED-only view for an independent connection, and refuses a
 * nested `beginTrans()` the way MySQL does. An inert
 * `createMock(Connection::class)` would pass with or without the fix.
 *
 * Scope of the proof, stated plainly: these are UNIT tests against a coarse model
 * (see that class's "WHAT IT IS NOT"). They pin the code shape — one transaction
 * around the delete and every insert, rollback on any throwable, no observable
 * intermediate state from another connection, nesting refused. The equivalent
 * checks against real MySQL 8.0 on BOTH `DB_POOL_ENABLED` modes are recorded in
 * `steps/detail-endpoint-stream-backfill.worklog.md`; neither substitutes for the
 * other.
 *
 * @covers \Phlix\Media\Library\ItemRepository::replaceStreams
 */
class ItemRepositoryReplaceStreamsTest extends TestCase
{
    /**
     * Three replacement rows in the order the scanner's summarizeProbe() emits
     * them (video first, then audio, then subtitle).
     *
     * @return list<array<string, mixed>>
     */
    private function freshStreams(): array
    {
        return [
            ['id' => 'new-v', 'stream_index' => 0, 'stream_type' => 'video', 'codec' => 'hevc'],
            ['id' => 'new-a', 'stream_index' => 1, 'stream_type' => 'audio', 'codec' => 'aac'],
            ['id' => 'new-s', 'stream_index' => 2, 'stream_type' => 'subtitle', 'codec' => 'subrip'],
        ];
    }

    /**
     * The rows a previous probe stored — what a failed replacement must leave
     * behind, and what a successful one must remove.
     *
     * @return list<array<string, mixed>>
     */
    private function seedOldRows(TransactionalStreamsConnection $db): array
    {
        $old = [
            ['id' => 'old-v', 'media_item_id' => 'movie-1', 'stream_index' => 0,
             'stream_type' => 'video', 'codec' => 'h264'],
            ['id' => 'old-a', 'media_item_id' => 'movie-1', 'stream_index' => 1,
             'stream_type' => 'audio', 'codec' => 'ac3'],
        ];
        foreach ($old as $row) {
            $db->seed($row);
        }
        return $old;
    }

    /**
     * Criterion 1: ONE transaction wraps the DELETE and EVERY insert, and it
     * commits. The op log pins the exact ordering, so nothing can drift outside
     * the transaction later.
     */
    public function testDeleteAndEveryInsertRunInsideOneTransactionThatCommits(): void
    {
        $db = new TransactionalStreamsConnection();
        $this->seedOldRows($db);

        (new ItemRepository($db))->replaceStreams('movie-1', $this->freshStreams());

        $this->assertSame(
            ['begin', 'delete:movie-1', 'insert:new-v', 'insert:new-a', 'insert:new-s', 'commit'],
            $db->ops,
            'begin → delete → every insert → commit, with nothing outside the transaction'
        );

        // Post-commit state: exactly the fresh set, in the given order.
        $this->assertSame(
            ['new-v', 'new-a', 'new-s'],
            array_map(fn (array $row) => $row['id'], $db->rowsFor('movie-1'))
        );
    }

    /**
     * The DELETE must be the FIRST statement inside the transaction, never before
     * `begin` — a delete that escaped the transaction would still be a torn read
     * window even though the inserts were wrapped.
     */
    public function testTheDeleteIsInsideTheTransactionNotBeforeIt(): void
    {
        $db = new TransactionalStreamsConnection();
        (new ItemRepository($db))->replaceStreams('movie-1', $this->freshStreams());

        $begin = array_search('begin', $db->ops, true);
        $delete = array_search('delete:movie-1', $db->ops, true);
        $commit = array_search('commit', $db->ops, true);
        $this->assertIsInt($begin);
        $this->assertIsInt($delete);
        $this->assertIsInt($commit);
        $this->assertGreaterThan($begin, $delete, 'DELETE happens after begin');
        $this->assertLessThan($commit, $delete, 'DELETE happens before commit');
    }

    /**
     * Criterion 2: a failing INSERT rolls back, so the item keeps its OLD rows
     * instead of ending up empty (delete applied, no inserts) or partial (delete
     * plus some inserts). The failure is re-thrown so the caller's own error
     * contract still fires.
     */
    public function testFailedInsertRollsBackAndKeepsTheItemsPreviousRows(): void
    {
        $db = new TransactionalStreamsConnection();
        $old = $this->seedOldRows($db);
        $db->failOnInsert = 2; // the audio insert fails, after the video insert landed

        $caught = null;
        try {
            (new ItemRepository($db))->replaceStreams('movie-1', $this->freshStreams());
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(PDOException::class, $caught, 'the write failure is re-thrown, never swallowed');
        $this->assertSame(
            ['begin', 'delete:movie-1', 'insert:new-v', 'insert-failed:new-a', 'rollback'],
            $db->ops,
            'no further inserts are attempted and the transaction is rolled back'
        );
        $this->assertNotContains('commit', $db->ops);

        // THE point of the fix: the item is neither empty nor partial.
        $this->assertSame($old, $db->rowsFor('movie-1'), 'the previously-stored rows survive intact');
    }

    /**
     * A failure on the FIRST insert (nothing new landed yet) must still restore
     * the deleted rows — the "item ends up with zero streams" shape the media
     * detail endpoint surfaced as `streams: []`.
     */
    public function testFailureOnTheFirstInsertStillRestoresTheDeletedRows(): void
    {
        $db = new TransactionalStreamsConnection();
        $old = $this->seedOldRows($db);
        $db->failOnInsert = 1;

        try {
            (new ItemRepository($db))->replaceStreams('movie-1', $this->freshStreams());
            $this->fail('replaceStreams() must re-throw the insert failure');
        } catch (PDOException) {
            // expected
        }

        $this->assertSame($old, $db->rowsFor('movie-1'));
        $this->assertSame(['begin', 'delete:movie-1', 'insert-failed:new-v', 'rollback'], $db->ops);
    }

    /**
     * Criterion 2b: the failure need NOT be a `PDOException` for the rollback to
     * happen. `replaceStreams()` catches `Throwable` on purpose, and narrowing that
     * to `PDOException` used to leave every test green — so this pins the breadth
     * directly. A non-PDO throwable escaping without a rollback would, on
     * {@see \Phlix\Common\Database\PhlixMySQLConnection}, leave the transaction
     * open AND its whole-transaction mutex held, wedging every other coroutine on
     * that connection. The shape used is the real one: `PooledMySQLConnection`
     * raises a plain `RuntimeException('pool exhausted…')`.
     *
     * Reachability stated honestly rather than inflated: today nothing in `src/`
     * converts warnings to exceptions, `PhlixMySQLConnection::execute()` only ever
     * raises `PDOException`, and the pool's `RuntimeException` can only fire from
     * the coroutine's FIRST lease — which here is `beginTrans()`, outside the
     * `try`. So this pins the method's declared contract (any `Throwable` rolls
     * back), not a live defect. Confirmed against real MySQL 8.0 on BOTH
     * `DB_POOL_ENABLED` modes: a non-PDO throwable raised inside the insert loop
     * is re-thrown unchanged, an independent connection still reads the previous
     * rows, and the connection stays usable (see the worklog).
     */
    public function testANonPdoThrowableDuringTheWriteAlsoRollsBack(): void
    {
        $db = new TransactionalStreamsConnection();
        $old = $this->seedOldRows($db);
        $db->failOnInsert = 2;
        $db->insertFailure = new \RuntimeException('pool exhausted mid-replacement');

        $caught = null;
        try {
            (new ItemRepository($db))->replaceStreams('movie-1', $this->freshStreams());
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $caught, 'the failure is re-thrown unchanged');
        $this->assertNotInstanceOf(PDOException::class, $caught, 'and it is genuinely NOT a PDOException');
        $this->assertContains('rollback', $db->ops, 'a non-PDO throwable must roll back too');
        $this->assertNotContains('commit', $db->ops);
        $this->assertSame($old, $db->rowsFor('movie-1'), 'the previously-stored rows survive intact');
        $this->assertSame($old, $db->committedRowsFor('movie-1'), 'and nothing was committed');
    }

    /**
     * A failing COMMIT must roll back too — the one failure point that is not an
     * INSERT, and the one with the worst consequence on this stack.
     *
     * `commitTrans()` therefore has to stay INSIDE the `try`. Moving it out (a
     * plausible "tidy-up" refactor) left the whole 7,614-test suite green before
     * this test existed, and it is not a cosmetic difference:
     * {@see \Phlix\Common\Database\PhlixMySQLConnection::commitTrans()} sets
     * `transNesting = 0` and `transLockHolder = -1` and only THEN calls the
     * vendor's `commitTrans()`; if that throws and nothing rolls back, the mutex
     * push never runs and every other coroutine on that connection blocks forever
     * in `beginTrans()`'s `$this->transLock->pop()` — a resident-worker deadlock,
     * not a slowdown. `rollBackTrans()`'s outermost branch is what pushes the lock
     * back, so "the catch rolls back" is the property that keeps the worker alive.
     *
     * (The clearing order in `PhlixMySQLConnection` is filed separately and is not
     * touched here; this test pins the CALLER's side of it.)
     */
    public function testAFailingCommitRollsBackInsteadOfLeavingTheTransactionOpen(): void
    {
        $db = new TransactionalStreamsConnection();
        $old = $this->seedOldRows($db);
        $db->commitFailure = new PDOException(
            'SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query'
        );

        $caught = null;
        try {
            (new ItemRepository($db))->replaceStreams('movie-1', $this->freshStreams());
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(PDOException::class, $caught, 'the commit failure is re-thrown');
        $this->assertSame(
            ['begin', 'delete:movie-1', 'insert:new-v', 'insert:new-a', 'insert:new-s',
             'commit-failed', 'rollback'],
            $db->ops,
            'the failed commit is followed by a rollback, never left open'
        );
        $this->assertSame($old, $db->rowsFor('movie-1'), 'the rollback restored the previous rows');
        $this->assertSame($old, $db->committedRowsFor('movie-1'), 'and nothing was committed');
    }

    /**
     * THE defect this whole fix exists to close: a concurrent reader on ANOTHER
     * connection must never observe the item mid-replacement — not `streams: []`
     * (delete applied, no inserts yet) and not a partial set.
     *
     * Widened rather than sampled: the reader is an INDEPENDENT connection running
     * the REAL {@see ItemRepository::getItemStreams()}, and it is read after EVERY
     * single operation the writer performs, so there is no timing window left to
     * miss. The only two states it may ever see are the pre-delete set and the
     * post-commit set.
     *
     * What this does and does NOT prove. It proves the CODE SHAPE: every statement
     * of the replacement is inside one transaction, so nothing an independent
     * connection can read changes until the commit. It is NOT evidence about
     * InnoDB — the double models isolation coarsely (no MVCC read view, no
     * REPEATABLE READ). The engine-level claim is established separately and
     * A/B-ed against the pre-fix body on real MySQL 8.0 in BOTH `DB_POOL_ENABLED`
     * modes — a forked reader on its own connection saw only `2,200` after the
     * fix and every count from `0` to `200` (including the empty set) before it.
     * See `steps/detail-endpoint-stream-backfill.worklog.md`.
     */
    public function testAConcurrentReaderOnAnotherConnectionNeverSeesATornSet(): void
    {
        $db = new TransactionalStreamsConnection();
        $this->seedOldRows($db);

        // Five replacement rows, so a non-atomic sequence would expose SIX distinct
        // intermediate states rather than one easily-missed instant.
        $fresh = [];
        foreach (['new-v', 'new-a1', 'new-a2', 'new-s1', 'new-s2'] as $i => $id) {
            $fresh[] = ['id' => $id, 'stream_index' => $i, 'stream_type' => 'video', 'codec' => 'hevc'];
        }

        $reader = new ItemRepository($db->independentReader());
        /** @var list<list<string>> $observed */
        $observed = [];
        $sample = static function () use ($reader, &$observed): void {
            $observed[] = array_values(array_map(
                static fn (array $row): string => is_string($row['id'] ?? null) ? $row['id'] : '?',
                $reader->getItemStreams('movie-1')
            ));
        };
        $sample(); // before anything happens
        $db->onOp = $sample;

        (new ItemRepository($db))->replaceStreams('movie-1', $fresh);

        $before = ['old-v', 'old-a'];
        $after = ['new-v', 'new-a1', 'new-a2', 'new-s1', 'new-s2'];

        $this->assertGreaterThanOrEqual(8, count($observed), 'sampled at every step: begin, delete, 5 inserts, commit');
        $this->assertNotContains([], $observed, 'the `streams: []` torn read the detail endpoint surfaced');
        foreach ($observed as $i => $seen) {
            $this->assertContains(
                $seen,
                [$before, $after],
                'observation #' . $i . ' is neither the pre-delete set nor the post-commit set: '
                . json_encode($seen)
            );
        }
        // Both endpoints of the transition really were observed, so the test is not
        // passing merely because nothing ever changed.
        $this->assertSame($before, $observed[0]);
        $this->assertSame($after, $observed[count($observed) - 1]);
    }

    /**
     * A ROLLED-BACK replacement is equally invisible: the reader sees the old set
     * throughout and still sees it afterwards.
     */
    public function testAConcurrentReaderNeverSeesAnythingFromARolledBackReplacement(): void
    {
        $db = new TransactionalStreamsConnection();
        $old = $this->seedOldRows($db);
        $db->failOnInsert = 2;

        $reader = new ItemRepository($db->independentReader());
        /** @var list<list<string>> $observed */
        $observed = [];
        $db->onOp = static function () use ($reader, &$observed): void {
            $observed[] = array_values(array_map(
                static fn (array $row): string => is_string($row['id'] ?? null) ? $row['id'] : '?',
                $reader->getItemStreams('movie-1')
            ));
        };

        try {
            (new ItemRepository($db))->replaceStreams('movie-1', $this->freshStreams());
        } catch (PDOException) {
            // expected
        }

        $this->assertNotSame([], $observed);
        foreach ($observed as $i => $seen) {
            $this->assertSame(['old-v', 'old-a'], $seen, 'observation #' . $i . ' leaked a rolled-back state');
        }
        $this->assertSame($old, $db->committedRowsFor('movie-1'));
    }

    /**
     * The MUST-NOT-NEST precondition documented on `replaceStreams()`, enforced
     * rather than merely commented. `beginTrans()` is NOT reentrant on either
     * connection class Phlix wires — `workerman/mysql` issues a bare
     * `PDO::beginTransaction()` and no SAVEPOINT anywhere — so MySQL answers a
     * nested call with `There is already an active transaction`. A caller that
     * violated this would otherwise pass CI and silently stop persisting streams.
     *
     * Also pins the safety property that makes the violation non-destructive: the
     * `beginTrans()` call sits OUTSIDE the try, so the throw happens before the
     * DELETE, `replaceStreams()`'s own catch never runs, and the OUTER transaction
     * is neither rolled back nor left wedged.
     */
    public function testCallingItInsideAnOpenTransactionThrowsAndWritesNothing(): void
    {
        $db = new TransactionalStreamsConnection();
        $old = $this->seedOldRows($db);

        $db->beginTrans(); // some other unit of work owns a transaction

        $caught = null;
        try {
            (new ItemRepository($db))->replaceStreams('movie-1', $this->freshStreams());
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(PDOException::class, $caught);
        $this->assertSame('There is already an active transaction', $caught->getMessage());
        $this->assertSame(['begin'], $db->ops, 'no delete, no insert, and no rollback of the outer transaction');
        $this->assertSame($old, $db->rowsFor('movie-1'), 'the item keeps every row it had');

        // The outer transaction is still intact and usable.
        $this->assertTrue($db->commitTrans());
        $this->assertSame($old, $db->committedRowsFor('movie-1'));
    }

    /** Another item's rows are untouched by a replacement (the DELETE is keyed). */
    public function testAnotherItemsRowsAreUntouched(): void
    {
        $db = new TransactionalStreamsConnection();
        $this->seedOldRows($db);
        $db->seed(['id' => 'other-v', 'media_item_id' => 'movie-2', 'stream_index' => 0,
                   'stream_type' => 'video', 'codec' => 'vp9']);

        (new ItemRepository($db))->replaceStreams('movie-1', $this->freshStreams());

        $this->assertSame(
            ['other-v'],
            array_map(fn (array $row) => $row['id'], $db->rowsFor('movie-2'))
        );
    }

    /**
     * An EMPTY replacement set is a no-op that opens NO transaction and issues NO
     * statement: an empty probe result must never wipe an item's good rows. Both
     * callers guarded this before the method existed; the guard now lives with
     * the write.
     */
    public function testEmptyStreamSetIsANoOpAndOpensNoTransaction(): void
    {
        $db = new TransactionalStreamsConnection();
        $old = $this->seedOldRows($db);

        (new ItemRepository($db))->replaceStreams('movie-1', []);

        $this->assertSame([], $db->ops, 'no begin, no delete — nothing at all');
        $this->assertSame($old, $db->rowsFor('movie-1'));
    }

    /** An empty item id is a no-op too (never a table-wide statement). */
    public function testEmptyItemIdIsANoOp(): void
    {
        $db = new TransactionalStreamsConnection();
        $old = $this->seedOldRows($db);

        (new ItemRepository($db))->replaceStreams('', $this->freshStreams());

        $this->assertSame([], $db->ops);
        $this->assertSame($old, $db->rowsFor('movie-1'));
    }
}
