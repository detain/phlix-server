<?php

/**
 * Phlix media server component: Database.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Database;

use Workerman\MySQL\Connection;

/**
 * Coroutine-aware MySQL connection POOL front.
 *
 * The default {@see PhlixMySQLConnection} is ONE socket shared by every
 * coroutine, serialised by a per-connection mutex (correct, but DB access is
 * effectively sequential within a worker). This front gives each coroutine its
 * OWN leased connection drawn from a bounded pool, so up to `maxSize` queries
 * run truly in parallel within a single worker — true async DB.
 *
 * Design — per-query borrow, with per-coroutine lease only inside a transaction:
 *  - A coroutine's connection is returned to the idle pool at the START of its
 *    next DB call (lazy return) whenever no transaction is open, so the hold
 *    window is the gap between consecutive DB calls rather than the coroutine's
 *    whole lifetime — a tight query loop holds nothing between iterations. A
 *    multi-statement transaction (`beginTrans … commitTrans`) keeps the lease
 *    for its whole duration, so the transaction stays affine to one connection
 *    — the design invariant that made the original per-coroutine lease
 *    desirable. Any lease still held when the coroutine finishes is returned
 *    via {@see \Swoole\Coroutine::defer()}.
 *  - Acquire creates a new connection on demand up to `maxSize`, then blocks
 *    (channel pop) until another coroutine releases one. `maxSize = 1`
 *    therefore degrades to fully-serialised single-connection behaviour.
 *  - Outside a coroutine (CLI migrations, cron) there is no concurrency, so a
 *    single dedicated connection is reused directly.
 *
 * NOTE (S339): the lease was originally held for the coroutine's WHOLE
 * lifetime, which made the pool's ceiling structural: under oversubscription
 * (more concurrent coroutines than slots) a waiter had to survive a holder's
 * entire query loop, and a slow enough holder — or slow enough box — made the
 * acquire timeout fire "pool exhausted" even though only `maxSize` queries were
 * ever in flight. Per-query borrow outside transactions removes that coupling
 * for the measured flake shape (many coroutines in tight query loops); a
 * coroutine with a long gap between DB calls still pins a slot across that gap
 * (an eager per-query return would break `INSERT → lastInsertId()`). The
 * acquire timeout is configurable ({@see __construct()}, `$acquireTimeout`).
 *
 * This front never opens its own socket: it deliberately does NOT call the
 * parent constructor (which would `connect()`); it only ever delegates the
 * literal-SQL methods Phlix uses — `query()` plus the row-returning read
 * helpers `row()`/`single()`/`column()`, the `*Trans()` family and
 * `lastInsertId()` — to a leased raw connection. It intentionally does NOT
 * delegate the fluent query-builder (`select()`/`from()`/`where()`/…): that API
 * accumulates state on a single connection instance across calls, which is
 * incompatible with per-coroutine leasing; Phlix never uses it (always the
 * `query($sql, $params)` form).
 *
 * NOTE: selected when `connections.mysql.pool_enabled` is true, which is now the
 * DEFAULT (Track S / S9) after this front was validated against the live coroutine
 * runtime under load. Setting `DB_POOL_ENABLED=0` (`pool_enabled=false`) is the
 * documented fallback: it restores the proven single {@see PhlixMySQLConnection}
 * socket serialised by its per-connection coroutine mutex.
 *
 * @since 1.7
 */
final class PooledMySQLConnection extends Connection
{
    /** @var callable():Connection Factory that opens one raw connection. */
    private $rawFactory;

    /** @var int Maximum number of raw connections the pool may open. */
    private int $maxSize;

    /**
     * How long {@see acquire()} waits for a connection before throwing
     * "pool exhausted" (seconds). 0 = fail immediately on exhaustion.
     *
     * @var float
     */
    private float $acquireTimeout;

    /** @var \Swoole\Coroutine\Channel|null Idle (released) connections, lazily created in-coroutine. */
    private ?\Swoole\Coroutine\Channel $idle = null;

    /** @var int How many raw connections have been opened (≤ maxSize). */
    private int $created = 0;

    /** @var array<int, Connection> Active leases: coroutine id → its connection. */
    private array $leases = [];

    /** @var Connection|null The single connection used on the non-coroutine (CLI) path. */
    private ?Connection $cliConn = null;

    /**
     * Tracks whether the current leased connection has an uncommitted transaction.
     * This is needed because the parent Workerman\MySQL\Connection does not expose
     * inTransaction() publicly, so we track it ourselves.
     *
     * @var array<int, bool> coroutine id → tx-pending flag
     */
    private array $txPending = [];

    /**
     * Coroutine ids whose exit-return defer has already been registered. The
     * defer is armed exactly once per coroutine (on its first acquisition);
     * re-arming it on every lazy re-acquire would push the same connection
     * once per arm when the coroutine finally exits.
     *
     * @var array<int, true>
     */
    private array $deferArmed = [];

    /**
     * @param string                              $host
     * @param int                                 $port
     * @param string                              $user
     * @param string                              $password
     * @param string                              $database
     * @param int                                 $maxSize    Pool ceiling (clamped to ≥ 1).
     * @param string                              $charset
     * @param (callable():Connection)|null $rawFactory Override for tests; defaults
     *        to opening a real {@see PhlixMySQLConnection}.
     * @param float                               $acquireTimeout Seconds to wait for a
     *        connection before throwing "pool exhausted" (clamped to ≥ 0; 0 = fail
     *        immediately). Default 10.0 preserves the historic behaviour.
     *
     * @psalm-suppress MissingParentConstructorCall Intentional: the front must
     *        not open a socket of its own; every query is delegated to a lease.
     */
    public function __construct(
        string $host,
        int $port,
        string $user,
        string $password,
        string $database,
        int $maxSize = 8,
        string $charset = 'utf8mb4',
        ?callable $rawFactory = null,
        float $acquireTimeout = 10.0
    ) {
        // Deliberately NOT calling parent::__construct() — see class docblock.
        $this->maxSize = max(1, $maxSize);
        $this->acquireTimeout = max(0.0, $acquireTimeout);
        $this->rawFactory = $rawFactory ?? static function () use (
            $host,
            $port,
            $user,
            $password,
            $database,
            $charset
        ): Connection {
            return new PhlixMySQLConnection($host, $port, $user, $password, $database, $charset);
        };
    }

    /**
     * @param string                          $query
     * @param array<int|string, mixed>|null    $params
     * @param int                              $fetchmode
     * @return mixed
     */
    public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        return $this->lease()->query($query, $params, $fetchmode);
    }

    /**
     * Fetch a single row for a literal SQL string.
     *
     * Unlike {@see query()}, the parent's {@see Connection::row()} always fetches
     * a row regardless of the leading keyword, so it is the correct primitive for
     * a row-returning statement `query()` doesn't special-case (e.g. `EXPLAIN`).
     * Delegated like {@see query()} because it is stateless w.r.t. the fluent
     * builder when called with an explicit `$query`.
     *
     * @param string                        $query
     * @param array<int|string, mixed>|null  $params
     * @param int                            $fetchmode
     * @return mixed
     *
     * @psalm-suppress LessSpecificImplementedReturnType The parent docblock claims
     *   `array`, but Connection::row() returns PDOStatement::fetch(), which yields
     *   `false` when there is no row. `mixed` is the honest type; narrowing it to
     *   match the parent would hide that falsy result from every caller.
     */
    public function row($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        return $this->lease()->row($query, $params, $fetchmode);
    }

    /**
     * Fetch a single scalar value for a literal SQL string. Delegated like
     * {@see query()}.
     *
     * @param string                        $query
     * @param array<int|string, mixed>|null  $params
     * @return mixed
     *
     * @psalm-suppress LessSpecificImplementedReturnType The parent docblock claims
     *   `string`, but Connection::single() returns PDOStatement::fetchColumn(),
     *   which yields `false` when there is no row. `mixed` is the honest type.
     */
    public function single($query = '', $params = null)
    {
        return $this->lease()->single($query, $params);
    }

    /**
     * Fetch a single column (list of values) for a literal SQL string.
     * Delegated like {@see query()}.
     *
     * @param string                        $query
     * @param array<int|string, mixed>|null  $params
     * @return mixed
     *
     * @psalm-suppress LessSpecificImplementedReturnType The parent docblock claims
     *   `array`, but the pooled lease can also surface a non-array result. `mixed`
     *   is the honest type; see row()/single() above for the same reasoning.
     */
    public function column($query = '', $params = null)
    {
        return $this->lease()->column($query, $params);
    }

    public function beginTrans(): bool
    {
        $conn = $this->lease();
        $cid = $this->currentCoroutineId();
        $result = $conn->beginTrans();
        if ($result && $cid >= 0) {
            $this->txPending[$cid] = true;
        }
        return $result;
    }

    public function commitTrans(): bool
    {
        $conn = $this->lease();
        $cid = $this->currentCoroutineId();
        $result = $conn->commitTrans();
        if ($cid >= 0) {
            unset($this->txPending[$cid]);
        }
        return $result;
    }

    public function rollBackTrans(): bool
    {
        $conn = $this->lease();
        $cid = $this->currentCoroutineId();
        $result = $conn->rollBackTrans();
        if ($cid >= 0) {
            unset($this->txPending[$cid]);
        }
        return $result;
    }

    /**
     * @return string
     */
    public function lastInsertId()
    {
        // Must NOT trigger the lazy return: the id resolves on the connection
        // that performed the preceding INSERT, which is still this coroutine's
        // current lease. If the lazy return fired here, a parked waiter would
        // take the INSERT's connection (Channel::push resumes a waiter
        // synchronously) and this call would read a FOREIGN connection's id.
        // The connection is released by the coroutine's NEXT normal DB call.
        return $this->leaseWithoutLazyReturn()->lastInsertId();
    }

    /**
     * Close every connection the pool owns (CLI, idle and leased). Best-effort;
     * called on graceful worker shutdown.
     *
     * Guards Swoole Channel operations with a coroutine context check because
     * this may be called from signal handlers or destructors during worker
     * shutdown when no coroutine context is active.
     */
    public function closeConnection(): void
    {
        if ($this->cliConn !== null) {
            $this->cliConn->closeConnection();
            $this->cliConn = null;
        }
        // Only access Swoole Channel APIs when inside a coroutine context.
        // Calling Channel::isEmpty()/pop() outside a coroutine fatals with
        // "API must be called in the coroutine" during SIGTERM shutdown.
        if ($this->idle !== null && $this->currentCoroutineId() >= 0) {
            while (!$this->idle->isEmpty()) {
                $conn = $this->idle->pop(0.001);
                if ($conn instanceof Connection) {
                    $conn->closeConnection();
                }
            }
        }
        foreach ($this->leases as $conn) {
            $conn->closeConnection();
        }
        $this->leases = [];
        $this->created = 0;
    }

    /**
     * Resolve the connection this caller should use: the coroutine's existing
     * lease, a freshly-acquired one (registered for return on coroutine end),
     * or the shared CLI connection when not inside a coroutine.
     */
    private function lease(): Connection
    {
        return $this->leaseFor($this->currentCoroutineId(), true);
    }

    /**
     * Like {@see lease()}, but never triggers the lazy return: the coroutine's
     * current lease is kept (only acquired if absent). Used by
     * {@see lastInsertId()}, which must resolve on the connection that
     * performed the preceding INSERT even when waiters are parked.
     */
    private function leaseWithoutLazyReturn(): Connection
    {
        return $this->leaseFor($this->currentCoroutineId(), false);
    }

    /**
     * Resolve the connection this caller should use: the coroutine's existing
     * lease, a freshly-acquired one (registered for return on coroutine end),
     * or the shared CLI connection when not inside a coroutine.
     *
     * When `$lazyReturn` is true (every normal DB call), a coroutine's previous
     * non-transactional lease is returned to the idle pool BEFORE a fresh one
     * is taken. S339 — per-query borrow outside transactions: the hold window
     * becomes the gap between consecutive DB calls (a tight query loop holds
     * nothing between iterations) instead of the coroutine's whole lifetime.
     * The return is LAZY — at the START of the next DB call, not immediately
     * after the query — so the INSERT → lastInsertId() pattern still resolves
     * on the INSERT's connection ({@see lastInsertId()} also skips the return
     * for the same reason). Residual limitation, stated honestly: a coroutine
     * with a LONG gap between DB calls (query → minutes of unrelated work →
     * query) still pins its slot across that gap; only transactions are exempt
     * by design, and a per-query EAGER return would break lastInsertId().
     *
     * @param int  $cid        Coroutine id (≥ 0 on this path).
     * @param bool $lazyReturn Whether to return the previous non-transactional
     *                         lease before acquiring.
     */
    private function leaseFor(int $cid, bool $lazyReturn): Connection
    {
        if ($cid < 0) {
            return $this->cliConn ??= ($this->rawFactory)();
        }

        if ($lazyReturn && isset($this->leases[$cid]) && !$this->hasPendingTransaction($cid)) {
            $returned = $this->leases[$cid];
            unset($this->leases[$cid]);
            if ($this->idle === null) {
                // Unreachable: a lease only exists after acquire(), which
                // initialises the idle channel. Closed rather than stranded so
                // the pool can never leak a connection if that invariant
                // ever changes.
                $returned->closeConnection();
                $this->created = max(0, $this->created - 1);
            } else {
                $this->idle->push($returned);
            }
        }

        if (isset($this->leases[$cid])) {
            return $this->leases[$cid];
        }

        $conn = $this->acquire();
        $this->leases[$cid] = $conn;
        if (!isset($this->deferArmed[$cid])) {
            $this->deferArmed[$cid] = true;
            \Swoole\Coroutine::defer(function () use ($cid): void {
                unset($this->deferArmed[$cid]);
                $released = $this->leases[$cid] ?? null;
                $hadPendingTx = isset($this->txPending[$cid]) && $this->txPending[$cid];
                unset($this->leases[$cid], $this->txPending[$cid]);
                if ($released === null || $this->idle === null) {
                    return;
                }
                // Never return a dirty (open-transaction) connection to the pool.
                // Rollback any uncommitted transaction before reuse — an interrupted
                // coroutine must not poison the next lessee.
                if ($hadPendingTx) {
                    $released->rollBackTrans();
                }
                $this->idle->push($released);
            });
        }

        return $conn;
    }

    /**
     * Whether the given coroutine currently has an open transaction, i.e. must
     * keep its lease (transaction affinity) instead of returning it lazily.
     */
    private function hasPendingTransaction(int $cid): bool
    {
        return isset($this->txPending[$cid]) && $this->txPending[$cid];
    }

    /**
     * Take an idle connection, open a new one while under the ceiling, or block
     * until another coroutine releases one.
     */
    private function acquire(): Connection
    {
        $this->idle ??= new \Swoole\Coroutine\Channel($this->maxSize);

        // Guard: Skip Channel access if not in a coroutine (SIGTERM shutdown)
        if ($this->currentCoroutineId() < 0) {
            // Non-coroutine path: bounded poll loop (no recursion, no stack growth).
            // CLI/cron never has true parallelism, so polling is acceptable.
            $deadline = hrtime(true) + (int) ($this->acquireTimeout * 1_000_000_000);
            while (hrtime(true) < $deadline) {
                if ($this->created < $this->maxSize) {
                    $this->created++;
                    try {
                        return ($this->rawFactory)();
                    } catch (\Throwable $e) {
                        $this->created--;
                        throw $e;
                    }
                }
                usleep(100_000); // 100 ms between polls
            }
            throw new \RuntimeException(sprintf(
                'pool exhausted: could not acquire a connection within %g s',
                $this->acquireTimeout
            ));
        }

        if (!$this->idle->isEmpty()) {
            /** @var Connection $conn */
            $conn = $this->idle->pop();
            if (!$this->isConnectionAlive($conn)) {
                // Dead connection removed from pool — not added back. Close it
                // first so its (possibly not-fully-dead) socket FD is released
                // immediately instead of lingering until GC; otherwise a burst
                // of DB-side connection drops (idle timeout/failover) churns FDs.
                $conn->closeConnection();
                $this->created--;
                return $this->acquire();
            }
            return $conn;
        }

        if ($this->created < $this->maxSize) {
            // Reserve the slot BEFORE the connect yields, so concurrent
            // acquirers can't collectively exceed maxSize.
            $this->created++;
            try {
                return ($this->rawFactory)();
            } catch (\Throwable $e) {
                $this->created--;
                throw $e;
            }
        }

        // Pool exhausted — block-wait with timeout. Swoole yields to the
        // scheduler while waiting, so other coroutines remain runnable.
        // pop() returns false on timeout (Swoole 4.x) or throws (Swoole 5+).
        /** @var Connection|false $conn */
        $conn = $this->idle->pop($this->acquireTimeout);
        if ($conn === false) {
            throw new \RuntimeException(sprintf(
                'pool exhausted: no idle connection available after %g s',
                $this->acquireTimeout
            ));
        }
        return $conn;
    }

    /**
     * Snapshot of the pool's own counters, for observability and the S339
     * exhaustion reproduction. Best-effort: the coroutine scheduler can
     * interleave between the individual reads, so treat the values as a
     * point-in-time sample, not a lock.
     *
     * `idle` is derived as `created − leased` rather than read from the Swoole
     * Channel, because every created connection is either leased, sitting in
     * the idle channel, or transiently being liveness-probed by acquire() —
     * so the arithmetic (clamped at 0) is the channel contents plus the probe,
     * and it is readable outside a coroutine too (Channel methods are not).
     *
     * @return array{maxSize: int, created: int, leased: int, idle: int}
     */
    public function poolStats(): array
    {
        $leased = count($this->leases);
        return [
            'maxSize' => $this->maxSize,
            'created' => $this->created,
            'leased' => $leased,
            'idle' => max(0, $this->created - $leased),
        ];
    }

    /**
     * Quick sanity-check that a pooled connection is still usable.
     *
     * A connection can go dead if the DB server closed it (idle timeout, OOM,
     * failover) while it sat in the idle pool. Returning a dead connection to
     * the next lessee would cause a cryptic "server has gone away" on the first
     * query. Running a cheap SELECT 1 round-trip detects this without requiring
     * a full reconnect.
     */
    private function isConnectionAlive(Connection $conn): bool
    {
        try {
            $conn->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Current Swoole coroutine id, or -1 when not in a coroutine / no Swoole.
     */
    private function currentCoroutineId(): int
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            return -1;
        }
        $cid = \Swoole\Coroutine::getCid();
        return is_int($cid) ? $cid : -1;
    }
}
