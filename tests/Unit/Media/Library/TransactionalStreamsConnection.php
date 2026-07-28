<?php

/**
 * Phlix media server tests: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PDO;
use PDOException;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Connection double over an in-memory `media_streams` table that models the four
 * transaction properties the stream-replacement tests depend on.
 *
 * A `createMock(Connection::class)` cannot prove any of them: its `beginTrans()` /
 * `rollBackTrans()` are inert, so a test using it passes whether or not the writes
 * were wrapped. This double instead models:
 *
 *  1. **Ordering.** An ordered {@see $ops} log spanning BOTH the transaction API
 *     and the statements, so the DELETE/INSERT ordering *relative to*
 *     `begin`/`commit` can be asserted (and a probe recorded via {@see note()} can
 *     be proven to happen OUTSIDE the transaction).
 *  2. **Atomicity.** The table is snapshotted on `beginTrans()` and RESTORED on
 *     `rollBackTrans()`, which is what lets a test prove the item keeps its
 *     PREVIOUS rows after a failed insert instead of ending up empty or partial.
 *  3. **Isolation (READ COMMITTED, coarse).** Writes land in the writing
 *     connection's own view immediately — as MySQL does for the connection that
 *     issued them — but an INDEPENDENT connection sees only COMMITTED state:
 *     {@see committedRowsFor()}, or the whole reader front
 *     {@see independentReader()} which a real {@see \Phlix\Media\Library\ItemRepository}
 *     can be built on so `getItemStreams()` itself is exercised. Combined with
 *     {@see $onOp} (invoked after EVERY logged operation) a test can sample that
 *     reader at every single step of a replacement and prove no torn read exists.
 *  4. **Non-reentrancy.** `beginTrans()` throws
 *     `PDOException: There is already an active transaction` when a transaction is
 *     already open, exactly as MySQL does — `workerman/mysql` implements
 *     `beginTrans()` as a bare `PDO::beginTransaction()` and issues no SAVEPOINT
 *     anywhere. This is what lets a test state the MUST-NOT-nest precondition
 *     documented on {@see \Phlix\Media\Library\ItemRepository::replaceStreams()}
 *     DIRECTLY — nested call throws, writes nothing, outer transaction survives.
 *     Measured claim, not a guess: a nesting caller planted in
 *     `MediaScanner::persistStreams()` is ALSO caught by the op-log assertions
 *     (the extra `begin`/`commit` entries show up), so this is not the only net;
 *     it is the only one that pins the contract itself rather than one call
 *     site's statement sequence.
 *
 * WHAT IT IS NOT. This is a coarse model, not MySQL: there is no row/gap locking,
 * no deadlock or lock-wait timeout, no per-statement MVCC read view, no
 * REPEATABLE READ (an independent read sees each commit as it lands), and no
 * connection-pool or coroutine behaviour. Its non-reentrancy is likewise coarse:
 * it refuses ANY `beginTrans()` while one is open, whereas
 * {@see \Phlix\Common\Database\PhlixMySQLConnection} throws only for the coroutine
 * that already holds the transaction (a DIFFERENT coroutine would block on the
 * whole-transaction mutex instead). The throwing case is the one
 * `replaceStreams()`'s precondition is about — one unit of work nesting itself —
 * and it is the case verified against MySQL 8.0. It proves the *code shape* — that the
 * delete and every insert sit inside one transaction, that a failure rolls back,
 * that a concurrent reader never observes the intermediate state, and that a
 * nested call throws. It is NOT evidence about real engine behaviour; that is
 * established separately against MySQL 8.0 on BOTH `DB_POOL_ENABLED` modes and
 * recorded in `steps/detail-endpoint-stream-backfill.worklog.md`.
 *
 * It deliberately does NOT call the parent constructor (no socket is opened) —
 * the same technique {@see \Phlix\Common\Database\PooledMySQLConnection} uses.
 * Only the four statement shapes {@see \Phlix\Media\Library\ItemRepository}'s
 * stream methods issue are understood; anything else is logged as `other` and
 * answers with an empty result so an incidental write cannot blow up a test.
 *
 * In its OWN PSR-4 file (not inline in a test) so several test classes can share
 * it and each file holds a single class (PSR-12) — same convention as
 * {@see CollectionGateScannerRepo}.
 */
final class TransactionalStreamsConnection extends Connection
{
    /**
     * Ordered log of everything that happened, e.g.
     * `['begin', 'delete:m1', 'insert:s-1', 'insert:s-2', 'commit']`.
     *
     * @var list<string>
     */
    public array $ops = [];

    /**
     * When set to N (1-based), the Nth INSERT throws — simulating a
     * mid-replacement write failure (duplicate key, oversized value, lost
     * connection). The rows already inserted stay in the writer's view until a
     * rollback restores the snapshot, exactly like the real engine.
     */
    public ?int $failOnInsert = null;

    /**
     * Throwable the {@see $failOnInsert} INSERT raises. Defaults to a
     * `PDOException` (what {@see \Phlix\Common\Database\PhlixMySQLConnection}
     * actually throws); set a non-PDO throwable to pin that the replacement's
     * `catch (Throwable)` breadth is load-bearing.
     */
    public ?Throwable $insertFailure = null;

    /**
     * When set, `commitTrans()` logs `commit-failed` and throws it INSTEAD of
     * committing — the table is left exactly as the (still open) transaction had
     * it, so a subsequent `rollBackTrans()` restores the snapshot.
     *
     * Models a COMMIT that fails, which is not a hypothetical shape on this
     * stack: {@see \Phlix\Common\Database\PhlixMySQLConnection::commitTrans()}
     * clears `transNesting`/`transLockHolder` BEFORE calling the vendor's
     * `commitTrans()`, so a commit that throws without being rolled back leaves
     * the whole-transaction mutex un-pushed and every other coroutine on that
     * connection blocked forever in `beginTrans()`.
     */
    public ?Throwable $commitFailure = null;

    /**
     * Called with the op label after EVERY logged operation, including
     * `begin`/`commit`/`rollback`. The hook is how a test observes the table
     * mid-transaction — typically by reading through {@see independentReader()}.
     *
     * @var (callable(string): void)|null
     */
    public $onOp = null;

    /**
     * The writing connection's own view of `media_streams`: uncommitted changes
     * included, as MySQL shows them to the connection that made them.
     *
     * @var list<array<string, mixed>>
     */
    private array $rows = [];

    /**
     * The last COMMITTED state — what an independent connection observes.
     *
     * @var list<array<string, mixed>>
     */
    private array $committed = [];

    /**
     * Table contents captured by `beginTrans()`, restored by `rollBackTrans()`.
     * Null when no transaction is open.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $snapshot = null;

    /** How many INSERTs have been attempted (drives {@see $failOnInsert}). */
    private int $insertCount = 0;

    /**
     * @psalm-suppress MissingParentConstructorCall Intentional: no socket.
     */
    public function __construct()
    {
        // No parent::__construct() — this double never connects.
    }

    /**
     * Seed a row that a PREVIOUS scan/probe would have stored (already committed),
     * so a rollback can be shown to bring it back and an independent reader can be
     * shown to keep seeing it.
     *
     * @param array<string, mixed> $row
     */
    public function seed(array $row): void
    {
        $this->rows[] = $row;
        $this->committed = $this->rows;
    }

    /**
     * Record a non-SQL event (e.g. "the ffprobe ran") into the SAME ordered log,
     * so its position relative to `begin`/`commit` is assertable.
     */
    public function note(string $label): void
    {
        $this->record($label);
    }

    /**
     * A SEPARATE logical connection onto the same data, which — like any other
     * MySQL connection — can only see COMMITTED rows. Build a real
     * {@see \Phlix\Media\Library\ItemRepository} on it to exercise the actual
     * `getItemStreams()` read path a concurrent request would take.
     */
    public function independentReader(): CommittedStreamsViewConnection
    {
        return new CommittedStreamsViewConnection($this);
    }

    /**
     * Current rows for one item as the WRITING connection sees them (uncommitted
     * changes included), in `stream_index` order.
     *
     * @return list<array<string, mixed>>
     */
    public function rowsFor(string $itemId): array
    {
        return self::forItem($this->rows, $itemId);
    }

    /**
     * Current rows for one item as an INDEPENDENT connection sees them: committed
     * state only, in `stream_index` order.
     *
     * @return list<array<string, mixed>>
     */
    public function committedRowsFor(string $itemId): array
    {
        return self::forItem($this->committed, $itemId);
    }

    /**
     * @param string                        $query
     * @param array<int|string, mixed>|null $params
     * @param int                           $fetchmode
     * @return mixed
     */
    public function query($query = '', $params = null, $fetchmode = PDO::FETCH_ASSOC)
    {
        $sql = trim((string) $query);
        $args = is_array($params) ? array_values($params) : [];

        if (str_starts_with($sql, 'DELETE FROM media_streams')) {
            $itemId = isset($args[0]) && is_string($args[0]) ? $args[0] : '';
            $before = count($this->rows);
            $this->rows = array_values(array_filter(
                $this->rows,
                static fn (array $row): bool => ($row['media_item_id'] ?? null) !== $itemId
            ));
            $this->autocommit();
            $this->record('delete:' . $itemId);
            return $before - count($this->rows);
        }

        if (str_starts_with($sql, 'INSERT INTO media_streams')) {
            $this->insertCount++;
            $id = isset($args[0]) && is_string($args[0]) ? $args[0] : '';
            if ($this->failOnInsert !== null && $this->insertCount === $this->failOnInsert) {
                $this->record('insert-failed:' . $id);
                throw $this->insertFailure
                    ?? new PDOException('simulated media_streams insert failure');
            }
            $this->rows[] = [
                'id' => $id,
                'media_item_id' => $args[1] ?? null,
                'stream_index' => $args[2] ?? null,
                'stream_type' => $args[3] ?? null,
                'codec' => $args[4] ?? null,
            ];
            $this->autocommit();
            $this->record('insert:' . $id);
            return $id;
        }

        if (str_starts_with($sql, 'SELECT * FROM media_streams')) {
            $itemId = isset($args[0]) && is_string($args[0]) ? $args[0] : '';
            $rows = $this->rowsFor($itemId);
            $this->record('select:' . $itemId);
            return $rows;
        }

        if (str_starts_with($sql, 'UPDATE media_items SET streams_probed_at')) {
            $this->record('mark:' . (isset($args[0]) && is_string($args[0]) ? $args[0] : ''));
            return 1;
        }

        $this->record('other');
        return [];
    }

    /**
     * @throws PDOException When a transaction is already open — transactions do
     *         NOT nest on either connection class Phlix wires.
     */
    public function beginTrans(): bool
    {
        if ($this->snapshot !== null) {
            // Deliberately thrown BEFORE the op is logged and before the snapshot
            // is touched, matching MySQL: the nested call is refused outright and
            // the open transaction's state is left exactly as it was.
            throw new PDOException('There is already an active transaction');
        }
        $this->snapshot = $this->rows;
        $this->record('begin');
        return true;
    }

    /**
     * @throws Throwable When {@see $commitFailure} is set — nothing is committed
     *         and the transaction stays open, so a rollback can still restore it.
     */
    public function commitTrans(): bool
    {
        if ($this->commitFailure !== null) {
            $this->record('commit-failed');
            throw $this->commitFailure;
        }
        $this->snapshot = null;
        $this->committed = $this->rows;
        $this->record('commit');
        return true;
    }

    public function rollBackTrans(): bool
    {
        if ($this->snapshot !== null) {
            $this->rows = $this->snapshot;
            $this->snapshot = null;
        }
        $this->record('rollback');
        return true;
    }

    /**
     * Outside a transaction every statement is its own transaction, so the write
     * is immediately visible to other connections.
     */
    private function autocommit(): void
    {
        if ($this->snapshot === null) {
            $this->committed = $this->rows;
        }
    }

    /**
     * Append to the op log and fire {@see $onOp}. The hook runs AFTER the table
     * mutation and the log append, so an observer sees the state that operation
     * produced.
     */
    private function record(string $op): void
    {
        $this->ops[] = $op;
        if ($this->onOp !== null) {
            ($this->onOp)($op);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function forItem(array $rows, string $itemId): array
    {
        $found = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['media_item_id'] ?? null) === $itemId
        ));
        usort($found, static function (array $a, array $b): int {
            return (int) ($a['stream_index'] ?? 0) <=> (int) ($b['stream_index'] ?? 0);
        });
        return $found;
    }
}
