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
