<?php

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
final class PhlixMySQLConnection extends Connection
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
        parent::connect();
        if ($this->pdo instanceof \PDO) {
            $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
            $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
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
