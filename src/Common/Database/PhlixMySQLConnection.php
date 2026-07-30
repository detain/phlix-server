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
 * Workerman MySQL Connection with one bug-fix applied.
 *
 * `workerman/mysql` v1.0.9 (the latest tagged release as of writing)
 * has a `bindMore()` implementation that calls `array_keys($parray)`
 * and feeds the raw integer keys (0, 1, 2, …) straight into PDO::bindParam.
 * PHP 8.x's PDO is strict: param-index zero throws
 * "PDOStatement::bindParam(): Argument #1 ($param) must be greater than
 *  or equal to 1".
 *
 * Phlix's queries use the natural `$db->query($sql, [$a, $b])` pattern
 * (positional arrays), which exercises that buggy path on every call.
 * Rather than re-key every call site to either named placeholders or
 * `[1 => $a, 2 => $b]`, we just normalise here once.
 *
 * Associative arrays (string keys, e.g. `[':id' => $id]`) pass through
 * untouched.
 */
class PhlixMySQLConnection extends Connection
{
    /**
     * Default the connection charset to utf8mb4 (the parent defaults to the
     * legacy 'utf8' alias = utf8mb3).
     *
     * The schema is utf8mb4 / utf8mb4_unicode_ci, and the parent connects with
     * native prepared statements (`PDO::ATTR_EMULATE_PREPARES = false`) plus a
     * `SET NAMES <charset>` init command. On a utf8mb3 connection MySQL 8 tags
     * every bound string parameter utf8mb3_general_ci and then REFUSES to widen
     * it into a utf8mb4_unicode_ci column on INSERT/UPDATE:
     *   "SQLSTATE[HY000] 3988: Conversion from collation utf8mb3_general_ci into
     *    utf8mb4_unicode_ci impossible for parameter".
     * Connecting as utf8mb4 keeps parameters and columns in the same character
     * set so that conversion never happens. Callers may still override.
     *
     * @param string $host
     * @param int    $port
     * @param string $user
     * @param string $password
     * @param string $db_name
     * @param string $charset
     */
    public function __construct($host, $port, $user, $password, $db_name, $charset = 'utf8mb4')
    {
        parent::__construct($host, $port, $user, $password, $db_name, $charset);
    }

    /**
     * Binary semaphore that serialises socket access across coroutines.
     *
     * Under the Swoole event loop every HTTP request runs in its own
     * coroutine, but the DI container shares ONE Connection instance across
     * all of them. `workerman/mysql` wraps a single PDO socket, and Swoole's
     * runtime hook yields the coroutine while a query waits on that socket —
     * so without a guard a second coroutine can start a query on the same
     * socket mid-flight. That produces fatals like "Socket#N has already been
     * bound to another coroutine", "2014 Cannot execute queries while other
     * unbuffered queries are active", "Invalid parameter number" and
     * "fetchAll() on null" — exactly what the admin dashboard's parallel
     * widget fetches triggered (→ worker crash → 502).
     *
     * Created lazily on first use because a Swoole\Coroutine\Channel can only
     * exist inside the coroutine runtime (not at construction / CLI time).
     *
     * @var \Swoole\Coroutine\Channel|null
     */
    private ?\Swoole\Coroutine\Channel $queryLock = null;

    /** @var int Coroutine id currently holding {@see $queryLock}, or -1 when free. */
    private int $queryLockHolder = -1;

    /**
     * Reentrant mutex that wraps each whole transaction so that concurrent
     * coroutines can NEVER interleave queries inside a transaction on the
     * shared socket.
     *
     * Created lazily on first use (same reasoning as {@see $queryLock}).
     *
     * @var \Swoole\Coroutine\Channel|null
     */
    private ?\Swoole\Coroutine\Channel $transLock = null;

    /** @var int Coroutine id currently holding {@see $transLock}, or -1 when free. */
    private int $transLockHolder = -1;

    /**
     * Whether the coroutine holding {@see $transLock} currently has a
     * transaction open: 0 = none, 1 = open.
     *
     * Despite the name it is a FLAG, not a depth counter — it can never exceed
     * 1, because transactions do not nest on this stack: a second
     * `beginTrans()` throws instead of opening an inner scope
     * (see {@see beginTrans()}). It is kept as an `int` so the
     * `commitTrans()`/`rollBackTrans()` guards can be written as depth checks
     * and stay correct if savepoint reentrancy is ever actually implemented.
     *
     * @var int
     */
    private int $transNesting = 0;

    /**
     * Force emulated + fully-buffered prepared statements.
     *
     * The parent connects with NATIVE prepares (`PDO::ATTR_EMULATE_PREPARES =
     * false`). Under the Swoole event loop the MySQL socket is coroutine-hooked
     * (mysqlnd uses PHP streams), so a query yields the coroutine while it waits
     * on the socket. With native prepares each statement keeps per-statement
     * server-side state on that socket, and that state leaks across coroutine
     * switches — even when queries are serialised by the mutex below — leaving
     * the connection wedged so the next `prepare()` silently returns `false`
     * ("Call to a member function bindParam() on false") or the bound params
     * desync ("HY093 Invalid parameter number") under concurrent requests.
     *
     * Emulated prepares keep `prepare()` purely client-side (no socket round
     * trip, so it cannot fail at the socket), and buffered queries fetch every
     * result row immediately, so no pending unbuffered result survives a yield.
     * Diagnosed + fixed first on phlix-hub (150 concurrent requests → zero
     * corruption); applied here for parity since this connection class runs the
     * same native-prepare path under the same coroutine runtime.
     * Parameterisation stays injection-safe (PDO still quotes bound values) and
     * the connection charset is utf8mb4 (see the constructor).
     *
     * @return void
     */
    protected function connect()
    {
        // PHP 8.5 emits E_DEPRECATED from workerman/mysql's parent::connect()
        // (the PDO MySQL init-command attribute) on EVERY connect. That
        // deprecation would otherwise reach the process-global error handler and
        // — under the Swoole coroutine runtime, where set_error_handler() is not
        // coroutine-scoped — be captured by a concurrent coroutine's Monolog
        // log-write window, turning a successful log write into a spurious
        // "Writing to the log file failed" throw -> HTTP 500. Swallow just the
        // deprecation for the duration of the vendor connect (set + restore are
        // synchronous around this one call; see the class-level fix in
        // StructuredLogger for the robust, coroutine-safe backstop). This is a
        // best-effort, version-independent silencing of the vendor deprecation;
        // FIX 1 (WhatFailureGroupHandler) remains the authoritative guard.
        set_error_handler(static fn (): bool => true, E_DEPRECATED);
        try {
            parent::connect();
        } finally {
            restore_error_handler();
        }
        if ($this->pdo instanceof \PDO) {
            $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
            $this->pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    /**
     * Prepare + bind + execute with TYPE-AWARE binding.
     *
     * Emulated prepares (see {@see connect()}) send bound params as STRINGS by
     * default, so `LIMIT ?`/`OFFSET ?` become `LIMIT '50'` and MySQL 1064-errors
     * (this codebase has many `LIMIT ? OFFSET ?` queries). This override mirrors
     * the parent's `execute()` — including the one-shot reconnect on MySQL
     * "server has gone away" (2006/2013) — but binds each parameter with its
     * natural PDO type via {@see pdoParamType()} so integers stay unquoted. The
     * parent's `clearSQuery()` is private, so its single line is inlined as
     * `$this->sQuery = null`.
     *
     * CAUTION — this rolls back ON THE CALLER'S BEHALF. Both failure paths call
     * `$this->rollBackTrans()` before rethrowing, so a statement that fails
     * inside a caller's transaction ENDS that transaction (and, in a coroutine,
     * releases the whole-transaction mutex) whether or not the caller knows. A
     * caller that catches the exception and carries on is then writing in
     * AUTOCOMMIT with nothing to warn it. The rule that keeps this safe — and the
     * one every transactional caller in this codebase already follows — is to
     * treat ANY throw from a statement between `beginTrans()` and
     * `commitTrans()` as fatal to the whole unit of work: roll back and abandon
     * it.
     *
     * The defensive `rollBackTrans()` such a caller runs in its `catch` is inert
     * on the two paths where this connection object is that caller's alone:
     * outside a coroutine, and in the DEFAULT pooled mode, where each coroutine
     * leases a {@see PhlixMySQLConnection} of its own behind
     * {@see PooledMySQLConnection} so `$transLock` is never contended. It is NOT
     * unconditionally inert on the single shared socket (`DB_POOL_ENABLED=0` —
     * the opt-out fallback `config/database.php` documents). Nothing is pushed
     * twice there either (this one already nulled `$transLock`) and the vendor's
     * `rollBackTrans()` checks `PDO::inTransaction()` first — but by then the
     * mutex has been HANDED ON, not merely dropped: the internal rollback
     * releases it with `$lock->push(true)`, and swoole resumes a coroutine
     * parked in {@see beginTrans()}'s `$this->transLock->pop()` SYNCHRONOUSLY
     * inside that push (measured on 6.2.1), so that coroutine has already
     * written itself into `$transLockHolder` before the internal rollback even
     * finishes. Nothing in the unwind that follows yields, so the caller's
     * `catch` is entered with the new owner installed, and its second
     * `rollBackTrans()` takes the outermost branch and overwrites
     * `$transNesting`/`$transLockHolder` — clearing THAT coroutine's
     * bookkeeping, and rolling its transaction back for real if its `BEGIN` has
     * landed by then (one shared PDO handle). That window is PRE-EXISTING; this
     * docblock records it, nothing here changes it.
     *
     * @param string $query
     * @param mixed  $parameters
     * @return void
     */
    protected function execute($query, $parameters = '')
    {
        try {
            $this->prepareAndBind($query, $parameters);
            $this->success = $this->sQuery instanceof \PDOStatement && $this->sQuery->execute();
        } catch (\PDOException $e) {
            $errno = (is_array($e->errorInfo) && isset($e->errorInfo[1])) ? (int) $e->errorInfo[1] : 0;
            if ($errno === 2006 || $errno === 2013) {
                // "MySQL server has gone away" — drop the dead socket and retry once.
                $this->closeConnection();
                try {
                    $this->prepareAndBind($query, $parameters);
                    $this->success = $this->sQuery instanceof \PDOStatement && $this->sQuery->execute();
                } catch (\PDOException $ex) {
                    $this->rollBackTrans();
                    throw $ex;
                }
            } else {
                $this->rollBackTrans();
                throw new \PDOException('SQL:' . $this->lastSQL() . ' ' . $e->getMessage(), (int) $e->getCode());
            }
        }
        $this->parameters = [];
    }

    /**
     * Prepare $query and bind the accumulated parameters with their natural PDO
     * type. Reconnects if the PDO handle is missing. Replaces the parent's
     * private clearSQuery() with an inline `$this->sQuery = null`.
     *
     * @param mixed $parameters
     */
    private function prepareAndBind(string $query, mixed $parameters): void
    {
        if (!$this->pdo instanceof \PDO) {
            $this->connect();
        }
        if (!$this->pdo instanceof \PDO) {
            throw new \PDOException('PDO connection is not available.');
        }
        $this->sQuery = null;
        $statement = $this->pdo->prepare($query);
        if (!$statement instanceof \PDOStatement) {
            throw new \PDOException('Failed to prepare SQL statement.');
        }
        $this->sQuery = $statement;
        if (is_array($parameters)) {
            $this->bindMore($parameters);
        }
        /** @var mixed $param */
        foreach ($this->parameters as $param) {
            if (!is_array($param)) {
                continue;
            }
            $placeholder = $param[0] ?? null;
            if (!is_int($placeholder) && !is_string($placeholder)) {
                continue;
            }
            /** @var mixed $value */
            $value = $param[1] ?? null;
            $statement->bindValue($placeholder, $value, $this->pdoParamType($value));
        }
    }

    /**
     * Map a PHP value to the PDO bind type that keeps it correctly typed under
     * emulated prepares (integers stay unquoted so `LIMIT ?`/`OFFSET ?` work).
     *
     * @param mixed $value
     */
    private function pdoParamType(mixed $value): int
    {
        return match (true) {
            is_int($value)  => \PDO::PARAM_INT,
            is_bool($value) => \PDO::PARAM_BOOL,
            $value === null => \PDO::PARAM_NULL,
            default         => \PDO::PARAM_STR,
        };
    }

    /**
     * Run a query under the per-connection coroutine mutex so the shared
     * socket is never used by two coroutines at once. `query()` performs the
     * full prepare→execute→fetch internally, so holding the lock across it
     * makes each query atomic with respect to every other coroutine.
     *
     * Outside a coroutine (CLI migrations, cron) there is no concurrency, so
     * we run directly. The lock is reentrant per coroutine, so a query issued
     * while this coroutine already holds it (nested call) cannot deadlock.
     *
     * When inside a transaction (a `beginTrans()` without its matching
     * `commitTrans()`/`rollBackTrans()`), the mutex is held for the entire
     * transaction rather than per-query, preventing concurrent coroutines from
     * interleaving queries inside our transaction. NB this is why a raw
     * `query('START TRANSACTION')` is not an equivalent way to open one: it
     * leaves `$transNesting` at 0, so every following statement takes and
     * RELEASES the per-query lock and another coroutine's queries can land
     * inside the transaction.
     *
     * @param string                          $query
     * @param array<int|string, mixed>|null    $params
     * @param int                              $fetchmode
     * @return mixed
     */
    public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        $cid = $this->currentCoroutineId();
        if ($cid < 0) {
            return parent::query($query, $params, $fetchmode);
        }

        // Inside a transaction: the transaction lock (acquired in beginTrans)
        // is already held, so just run the query.  The per-query lock is NOT
        // released between queries — it stays pinned until commit/rollback.
        if ($this->transNesting > 0) {
            return parent::query($query, $params, $fetchmode);
        }

        $acquired = $this->acquireQueryLock($cid);
        try {
            return parent::query($query, $params, $fetchmode);
        } finally {
            if ($acquired) {
                $this->releaseQueryLock();
            }
        }
    }

    /**
     * Begin a transaction, acquiring the whole-transaction mutex so that no
     * other coroutine can interleave queries inside this transaction.
     *
     * TRANSACTIONS DO NOT NEST — callers MUST NOT open one inside another.
     * `workerman/mysql` implements `beginTrans()` as a bare
     * `PDO::beginTransaction()` (`vendor/workerman/mysql/src/Connection.php`
     * ~:1991) and no `SAVEPOINT` statement is issued anywhere in that package
     * or by this class, so a second `beginTrans()` before the matching
     * `commitTrans()`/`rollBackTrans()` throws
     * `PDOException: There is already an active transaction`. Verified against
     * MySQL 8.0.46 in BOTH `DB_POOL_ENABLED` modes and on both the coroutine and
     * the CLI path, with the server's own general log confirming zero SAVEPOINT
     * statements are ever sent. (Until 2026-07-28 this docblock claimed nested
     * calls issued savepoints and held the mutex to the outermost commit. They
     * never did — the claim was read as proof that this class is nesting-safe,
     * which is how it earned this correction. {@see
     * \Phlix\Media\Library\ItemRepository::replaceStreams()} shows how a
     * transactional unit of work states the MUST-NOT-nest precondition.)
     *
     * The same-coroutine branch below therefore exists to FAIL FAST, not to
     * support nesting: without it a nested call would `pop()` a channel this very
     * coroutine has already emptied and hang the worker forever. The throw is
     * strictly better — it is catchable, it lands BEFORE any statement of the
     * nested unit of work, and it leaves the outer transaction, its mutex and
     * `$transNesting` exactly as they were (so the outer scope still commits
     * durably and the connection is reusable afterwards — both measured).
     *
     * Outside a coroutine the mutex is not applicable; delegation to the parent
     * is sufficient (no concurrency). The non-nesting rule is identical there —
     * PDO itself enforces it.
     *
     * @return bool
     *
     * @throws \PDOException When this coroutine already has a transaction open
     *         ("There is already an active transaction"), or on a connect
     *         failure from the parent.
     */
    public function beginTrans(): bool
    {
        $cid = $this->currentCoroutineId();
        if ($cid < 0) {
            return parent::beginTrans();
        }

        // Same coroutine, transaction already open: NOT reentrant. The parent
        // throws "There is already an active transaction" here — deliberately,
        // so a nesting caller fails fast instead of deadlocking on the mutex it
        // is itself holding. $transNesting therefore never gets past 1.
        if ($this->transLockHolder === $cid) {
            $result = parent::beginTrans();
            if ($result) {
                $this->transNesting++;
            }
            return $result;
        }

        if ($this->transLock === null) {
            $this->transLock = new \Swoole\Coroutine\Channel(1);
            $this->transLock->push(true);
        }
        $this->transLock->pop();
        $this->transLockHolder = $cid;

        $result = parent::beginTrans();
        if ($result) {
            $this->transNesting = 1;
        } else {
            $this->transLockHolder = -1;
            // phpstan-ignore-line notIdentical.alwaysTrue -- PHPStan loses
            // track of the null-assignment below when beginTrans() (an impure
            // method) controls the failure path.  The logic is sound.
            if ($this->transLock !== null) { // @phpstan-ignore-line
                $this->transLock->push(true);
                $this->transLock = null;
            }
        }

        return $result;
    }

    /**
     * Commit the current transaction and release the whole-transaction mutex.
     *
     * There is only ever ONE scope to exit: transactions do not nest here (see
     * {@see beginTrans()}), so `$transNesting` is 0 or 1 and the `> 1` branch
     * below is UNREACHABLE today. It is kept as a guard rather than deleted so
     * that an inner commit could never release another scope's mutex if
     * savepoint reentrancy were ever implemented in `beginTrans()`.
     *
     * @return bool
     */
    public function commitTrans(): bool
    {
        $cid = $this->currentCoroutineId();
        if ($cid < 0) {
            return parent::commitTrans();
        }

        // Unreachable while beginTrans() refuses to nest (see its docblock):
        // an inner commit must never release the outer scope's mutex.
        if ($this->transNesting > 1) {
            $result = parent::commitTrans();
            if ($result) {
                $this->transNesting--;
            }
            return $result;
        }

        // Outermost: release the transaction mutex.
        $this->transNesting = 0;
        $this->transLockHolder = -1;
        $result = parent::commitTrans();
        $lock = $this->transLock;
        if ($lock !== null) {
            $lock->push(true);
            $this->transLock = null;
        }

        return $result;
    }

    /**
     * Roll back the current transaction and release the whole-transaction mutex.
     *
     * As with {@see commitTrans()} there is only ever ONE scope to exit —
     * transactions do not nest here (see {@see beginTrans()}), so the `> 1`
     * branch below is UNREACHABLE today and kept only as a guard.
     *
     * Safe to call when no transaction is open (the vendor checks
     * `PDO::inTransaction()` first) and when the PDO handle is already gone
     * (see {@see rollBackParent()}). That makes the defensive `rollBackTrans()`
     * in a caller's `catch` inert whenever this object serves one coroutine at a
     * time — outside a coroutine, and in the default pooled mode, where each
     * coroutine has its own lease. On the shared-socket fallback
     * (`DB_POOL_ENABLED=0`) it is NOT free after {@see execute()} has already
     * rolled back on the caller's behalf: the outermost branch below writes
     * `$transNesting = 0` / `$transLockHolder = -1` unconditionally, before
     * consulting anything, so it clears the bookkeeping of whichever coroutine
     * took the mutex when that EARLIER rollback released it — and "in the
     * meantime" can be zero yields, because the `$lock->push(true)` below hands
     * the mutex straight on rather than merely dropping it: swoole resumes a
     * coroutine waiting in {@see beginTrans()}'s `pop()` synchronously, inside
     * the push. See the CAUTION paragraph on {@see execute()}; pre-existing,
     * documented here rather than fixed.
     *
     * @return bool
     */
    public function rollBackTrans(): bool
    {
        $cid = $this->currentCoroutineId();
        if ($cid < 0) {
            return $this->rollBackParent();
        }

        // Unreachable while beginTrans() refuses to nest (see its docblock):
        // an inner rollback must never release the outer scope's mutex.
        if ($this->transNesting > 1) {
            $result = $this->rollBackParent();
            if ($result) {
                $this->transNesting--;
            }
            return $result;
        }

        // Outermost: release the transaction mutex.
        $this->transNesting = 0;
        $this->transLockHolder = -1;
        $result = $this->rollBackParent();
        $lock = $this->transLock;
        if ($lock !== null) {
            $lock->push(true);
            $this->transLock = null;
        }

        return $result;
    }

    /**
     * Delegate to the vendor's `rollBackTrans()`, but only while there is still
     * a PDO handle to roll back on.
     *
     * `parent::rollBackTrans()` dereferences `$this->pdo` unconditionally
     * (`Connection.php` ~:2023 → `$this->pdo->inTransaction()`), so it fatals
     * with `Error: Call to a member function inTransaction() on null` whenever
     * the handle is gone. That is reachable from {@see execute()}: its
     * 2006/2013 ("server has gone away") branch calls `closeConnection()`, which
     * sets `$this->pdo = null`, and then — if the RECONNECT also fails, i.e.
     * the server is still down — the catch calls `rollBackTrans()` on a
     * handle-less connection. Because `Error` is not a `PDOException`, it
     * REPLACES the real "connection refused" cause with a null dereference and
     * escapes every `catch (\PDOException)` on the way out; observed in
     * unpooled mode as `ItemRepository::markStreamsProbed()` dying with
     * "Call to a member function inTransaction() on null".
     *
     * With no handle there is also nothing to roll back — the server discards
     * the transaction when the socket dies — so report success and let the real
     * cause propagate.
     */
    private function rollBackParent(): bool
    {
        if (!$this->pdo instanceof \PDO) {
            return true;
        }
        return parent::rollBackTrans();
    }

    /**
     * Current Swoole coroutine id, or -1 when not running inside a coroutine
     * (e.g. CLI scripts) or when the extension is absent.
     */
    private function currentCoroutineId(): int
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            return -1;
        }
        $cid = \Swoole\Coroutine::getCid();
        return is_int($cid) ? $cid : -1;
    }

    /**
     * Acquire the query mutex for the given coroutine. Returns true when this
     * call took the lock (caller must release), false when the coroutine
     * already held it (reentrant — caller must NOT release).
     */
    private function acquireQueryLock(int $cid): bool
    {
        if ($this->queryLockHolder === $cid) {
            return false;
        }
        if ($this->queryLock === null) {
            $this->queryLock = new \Swoole\Coroutine\Channel(1);
            $this->queryLock->push(true);
        }
        // Blocks (yields the coroutine) until the single token is available.
        $this->queryLock->pop();
        $this->queryLockHolder = $cid;
        return true;
    }

    /** Release the query mutex, waking the next waiting coroutine. */
    private function releaseQueryLock(): void
    {
        $this->queryLockHolder = -1;
        if ($this->queryLock !== null) {
            $this->queryLock->push(true);
        }
    }

    /**
     * Signature matches the parent's declared docblock (`@param array`).
     * Workerman's execute() defaults `$parameters = ""` and forwards the
     * string straight into bindMore(); the parent then no-ops on
     * non-array input. We mirror that escape hatch here so the parent
     * call stays type-safe for PHPStan.
     *
     * @param array<int|string, mixed> $parray
     */
    public function bindMore($parray): void
    {
        if (!is_array($parray)) {
            // Defensive: keep the no-op behaviour the parent has when
            // execute() forwards its empty-string default.
            return;
        }
        if ($parray !== [] && array_is_list($parray)) {
            // re-key [0=>'a', 1=>'b'] → [1=>'a', 2=>'b']
            $parray = array_combine(range(1, count($parray)), $parray);
        }
        parent::bindMore($parray);
    }
}
