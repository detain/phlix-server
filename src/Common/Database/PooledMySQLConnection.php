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
 * Design — per-coroutine lease (NOT per-query borrow):
 *  - A coroutine leases a raw {@see PhlixMySQLConnection} on its first DB call
 *    and keeps it for the coroutine's whole lifetime, so a multi-statement
 *    transaction (`beginTrans … commitTrans`) is automatically affine to one
 *    connection. The lease is returned to the idle pool via
 *    {@see \Swoole\Coroutine::defer()} when the coroutine finishes.
 *  - Acquire creates a new connection on demand up to `maxSize`, then blocks
 *    (channel pop) until another coroutine releases one. `maxSize = 1`
 *    therefore degrades to fully-serialised single-connection behaviour.
 *  - Outside a coroutine (CLI migrations, cron) there is no concurrency, so a
 *    single dedicated connection is reused directly.
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
     * @param string                              $host
     * @param int                                 $port
     * @param string                              $user
     * @param string                              $password
     * @param string                              $database
     * @param int                                 $maxSize    Pool ceiling (clamped to ≥ 1).
     * @param string                              $charset
     * @param (callable():Connection)|null $rawFactory Override for tests; defaults
     *        to opening a real {@see PhlixMySQLConnection}.
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
        ?callable $rawFactory = null
    ) {
        // Deliberately NOT calling parent::__construct() — see class docblock.
        $this->maxSize = max(1, $maxSize);
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
        return $this->lease()->lastInsertId();
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
        $cid = $this->currentCoroutineId();
        if ($cid < 0) {
            return $this->cliConn ??= ($this->rawFactory)();
        }

        if (isset($this->leases[$cid])) {
            return $this->leases[$cid];
        }

        $conn = $this->acquire();
        $this->leases[$cid] = $conn;
        \Swoole\Coroutine::defer(function () use ($cid): void {
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

        return $conn;
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
            $deadline = hrtime(true) + 10_000_000_000; // 10 s ns
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
            throw new \RuntimeException('pool exhausted: could not acquire a connection within 10 s');
        }

        if (!$this->idle->isEmpty()) {
            /** @var Connection $conn */
            $conn = $this->idle->pop();
            if (!$this->isConnectionAlive($conn)) {
                // Dead connection removed from pool — not added back.
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
        $conn = $this->idle->pop(10.0);
        if ($conn === false) {
            throw new \RuntimeException('pool exhausted: no idle connection available after 10 s');
        }
        return $conn;
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
