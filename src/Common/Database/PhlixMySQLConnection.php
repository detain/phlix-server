<?php

/**
 * Phlix media server component: Database.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Common\Database;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Shared\Database\MysqlError;
use Phlix\Shared\Database\MysqlRetryPolicy;
use Phlix\Shared\Database\MysqlTxLockState;
use Phlix\Shared\Database\TxEnd;
use Phlix\Shared\Database\UpsertSyntax;
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
     * be USED inside the coroutine runtime: `push()`/`pop()` raise
     * `Swoole\Error: API must be called in the coroutine` outside one, and the
     * creation here pushes the initial token. (Constructing one outside a
     * coroutine does in fact succeed on swoole 6.2.1 — measured — so it is the
     * push, not the `new`, that forces the laziness.)
     *
     * @var \Swoole\Coroutine\Channel|null
     */
    private ?\Swoole\Coroutine\Channel $queryLock = null;

    /** @var int Coroutine id currently holding {@see $queryLock}, or -1 when free. */
    private int $queryLockHolder = -1;

    /**
     * Binary semaphore that wraps each whole transaction, so that no two
     * coroutines can have a transaction OPEN at the same time on the shared
     * socket, and (with the holder ledger in {@see $txState}) so that only the coroutine
     * that opened one may end it.
     *
     * NOT reentrant. A second `beginTrans()` in the same coroutine throws
     * instead of opening an inner scope, and no `SAVEPOINT` is ever issued —
     * see {@see beginTrans()}, whose docblock carries the measurement. (Until
     * 2026-07-31 this said "Reentrant mutex", three lines from a docblock
     * stating the opposite in capitals; PR #594 exists to remove exactly that
     * class of claim from this file.)
     *
     * It does NOT, on its own, stop other coroutines from interleaving
     * individual QUERIES inside a transaction: MySQL transaction state belongs
     * to the CONNECTION, so a foreign statement that {@see query()} lets
     * through onto the shared handle still participates in the open transaction
     * and shares its outcome (a permanent test pins this). What this mutex
     * guarantees is mutual exclusion between whole TRANSACTIONS plus
     * single-owner commit/rollback; `query()`'s short-circuit carries the
     * holder-identity term (TXMUTEX-A A4, 2026-11-21, now the shared
     * {@see MysqlTxLockState::holdingTransactionFor()} predicate), so while a
     * transaction is open only the holder skips the per-query lock and every
     * other caller serializes behind it. The destructive half — a non-holder's
     * failed statement ending the holder's transaction — is blocked by the
     * holder guards in {@see commitTrans()}/{@see rollBackTrans()}.
     *
     * Created lazily on first use (same reasoning as {@see $queryLock}).
     *
     * @var \Swoole\Coroutine\Channel|null
     */
    private ?\Swoole\Coroutine\Channel $transLock = null;

    /**
     * The transaction-mutex state machine (shared SSOT, phlix-shared v0.57
     * charter Option-B). It carries what were this class's private
     * `$transLockHolder`, `$transNesting` and `$txPoisoned` fields — the
     * holder identity (FREE_HOLDER = -1 sentinel preserved), the 0/1
     * nesting flag (kept as a counter so the depth guards stay reachable
     * if savepoint reentrancy is ever implemented), and the one-shot
     * poison mark with its refusal wording. The Channel above stays here:
     * the machine models the LEDGER and takes the park/push callables,
     * so no Swoole type leaks into shared code and the hand-off order
     * (clear-holder-before-push, design §1.5) stays one implementation
     * for both consumer repos.
     *
     * Lazily minted: property defaults cannot carry `new` on the estate's
     * PHP 8.3 floor, and overriding the parent's 6-argument constructor
     * would churn the pinned `testConstructorSignature` contract.
     */
    private ?MysqlTxLockState $txState = null;

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
        //
        // ⚠ S314 — DO NOT "FIX" THIS BY EDITING vendor/workerman/mysql. Until
        // S314 the working tree carried a hand-edit of
        // vendor/workerman/mysql/src/Connection.php:1719 swapping
        // `PDO::MYSQL_ATTR_INIT_COMMAND` for `\Pdo\Mysql::ATTR_INIT_COMMAND`.
        // It was deleted, on evidence: both constants are 1002; production runs
        // the PRISTINE constant on PHP 8.5 with `error_reporting=22527`, whose
        // E_DEPRECATED bit is CLEAR, so the deprecation never fires there; CI
        // has always installed pristine because vendor/ is gitignored; and the
        // set_error_handler() above already swallows it version-independently.
        // The edit also made the vendor file depend on symfony/polyfill-php84's
        // `Pdo\Mysql` stub, since that class does not exist on PHP 8.3 — the
        // project's own declared minimum. A vendor hand-edit is invisible to
        // git, to phpcs, to PHPStan and to Psalm, and it is silently reverted by
        // the next `composer install`. If this deprecation ever needs a real
        // fix, send walkor/mysql a PR guarded on `PHP_VERSION_ID >= 80400`.
        set_error_handler(static fn (): bool => true, E_DEPRECATED);
        try {
            parent::connect();
        } finally {
            restore_error_handler();
        }
        if ($this->pdo instanceof \PDO) {
            $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
            $this->pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, true);
            // W2-UPSERT: detect MySQL vs MariaDB so UpsertSyntax::adapt() picks the
            // row-alias or VALUES() upsert form. Failure keeps the prior flavor
            // (default: row alias), which is WRONG on MariaDB — so it is logged.
            try {
                $stmt = $this->pdo->query('SELECT VERSION()');
                /** @var mixed $version */
                $version = $stmt !== false ? $stmt->fetchColumn() : false;
                if (is_string($version)) {
                    UpsertSyntax::detect($version);
                }
            } catch (\Throwable $e) {
                $this->warnFlavorDetectionFailed($e);
            }
        }
    }

    /**
     * Log (never throw) a failed `SELECT VERSION()` flavor probe: the connection
     * is still usable, but upserts keep the previously detected flavor.
     */
    private function warnFlavorDetectionFailed(\Throwable $e): void
    {
        try {
            LoggerFactory::get(LogChannels::DATABASE)->warning(
                'Database flavor detection (SELECT VERSION()) failed; keeping the prior upsert syntax flavor',
                ['error' => $e->getMessage()],
            );
        } catch (\Throwable) {
            // Logging must never turn a usable connection into a failed one.
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
     * The defensive `rollBackTrans()` such a caller runs in its `catch` is
     * inert. On the two paths where this connection object is that caller's
     * alone — outside a coroutine, and in the DEFAULT pooled mode, where each
     * coroutine leases a {@see PhlixMySQLConnection} of its own behind
     * {@see PooledMySQLConnection} so `$transLock` is never contended — it is
     * inert because there is nothing left open. On the single shared socket
     * (`DB_POOL_ENABLED=0`, the opt-out fallback `config/database.php`
     * documents) it is inert because by then the mutex has been HANDED ON, not
     * merely dropped: the internal rollback releases it with `push(true)`, and
     * swoole resumes a coroutine parked in {@see beginTrans()}'s `pop()`
     * SYNCHRONOUSLY inside that push (measured on 6.2.1), so that coroutine has
     * already written itself into `$transLockHolder` before the internal
     * rollback even finishes — and {@see rollBackTrans()}'s holder guard then
     * turns the caller's second call into a no-op instead of letting it clobber
     * the new owner. Until 2026-07-31 there was no such guard and that second
     * call really did overwrite `$transNesting`/`$transLockHolder` and roll the
     * new owner's transaction back; see that method's docblock.
     *
     * Since TXMUTEX-A A1 the gone (2006/2013) path splits by transaction
     * state: in AUTOCOMMIT it keeps the vendor's one-shot retry on a fresh
     * socket, but INSIDE an open transaction it does NOT retry — it drops the
     * socket, rolls back, poisons the connection, and throws with a message
     * that says so; {@see commitTrans()} then refuses exactly once
     * (see the `$txState` field docblock — the machine owns the mark). The design rationale is in
     * /tmp/opencode/refactor-exec-1119/DESIGN-TX-MUTEX.md §P2.3-1.1.
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
            // TXMUTEX-B retrofit: the driver-code read AND the gone/tx split
            // now come from the shared tables (MysqlError::driverCode lifted
            // at S1; the retry classification is MysqlRetryPolicy::classify).
            // Verdict-to-action mapping stays byte-faithful to the landed
            // arms: LOST_INSIDE_TRANSACTION -> the A1 refuse+poison arm;
            // LOST_AUTOCOMMIT -> the vendor's one-shot retry; everything
            // else -> the blanket arm, which ALWAYS rolls the transaction
            // back. It deliberately does NOT consult
            // rollsBackWholeTransaction(): the surgical 1205 semantics that
            // predicate models were the owner-pending Q-A decision (design
            // §Q-A); ratified 2026-11-21 (DECISIONS.md entry 11): the
            // else-arm deliberately does NOT consult it, as landed.
            $verdict = MysqlRetryPolicy::classify(
                MysqlError::driverCode($e->errorInfo) ?? 0,
                $this->pdo instanceof \PDO && $this->pdo->inTransaction(),
            );
            if ($verdict->marksPoisoned()) {
                // TXMUTEX-A A1 (design §P2.3-1.1, port of the hub's arm):
                // a gone-away INSIDE an open transaction is NOT retried.
                // The first attempt may have been received and applied by
                // the server before the socket died, so replaying it risks
                // a double-apply, and the transaction it belonged to is
                // unrecoverable either way. Drop the dead socket, unwind
                // the transaction bookkeeping (which also hands the mutex
                // on if we hold it), poison the connection so the next
                // commitTrans() REFUSES, and say so in the message —
                // wording shared verbatim with the hub (the machine
                // suffix const is that wording, in one place now).
                $this->closeConnection();
                $this->rollBackTrans();
                $this->txState()->markPoisoned();
                $suffix = $verdict->lostInTransactionSuffix();
                throw new \PDOException(
                    'SQL:' . $this->lastSQL() . ' ' . $e->getMessage() . ($suffix ?? ''),
                    (int) $e->getCode()
                );
            }
            if ($verdict->replaysStatement()) {
                // "MySQL server has gone away" in autocommit — drop the dead
                // socket and retry once.
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
                // TXMUTEX-A A7 (design §P2.5-1.7): this wrapper used to drop
                // the driver evidence — no previous, and `errorInfo` left
                // empty — so `MysqlError::driverCode()` was blind on our own
                // throw and callers fell back to message parsing. Carry both;
                // the rendered message and the SQLSTATE-as-int code are
                // unchanged.
                $wrapped = new \PDOException(
                    'SQL:' . $this->lastSQL() . ' ' . $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
                $wrapped->errorInfo = $e->errorInfo;
                throw $wrapped;
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
     * While a transaction is open on this connection (a `beginTrans()` without
     * its matching `commitTrans()`/`rollBackTrans()`) this method SKIPS the
     * per-query lock entirely — the machine's open-transaction flag short-circuits straight to
     * the parent. What that is worth depends on the mode, and the two modes are
     * OPPOSITE:
     *
     * - DEFAULT pooled mode (`DB_POOL_ENABLED=1`, what production runs): every
     *   coroutine leases a {@see PhlixMySQLConnection} of its own behind
     *   {@see PooledMySQLConnection}, so the transaction ledger and `$transLock` are
     *   per-lease and nothing is shared. No other coroutine's query CAN
     *   interleave inside our transaction, and skipping the lock is simply free.
     * - Shared socket (`DB_POOL_ENABLED=0`, the opt-out fallback in
     *   `config/database.php`): one instance serves every coroutine, so
     *   the machine's ledger is SHARED state. The short-circuit therefore carries
     *   the holder-identity term as well (`holdingTransactionFor()` — both A4 terms, 2026-11-21):
     *   only the coroutine that OWNS the whole-transaction mutex skips the
     *   per-query lock, and every other caller takes it, so foreign statements
     *   can no longer trample each other's prepare/execute sequence on the
     *   shared handle. No term can lift the connection-level fact that a
     *   foreign statement run while a transaction is open PARTICIPATES in that
     *   transaction (MySQL transaction state belongs to the connection, not to
     *   any mutex) — a permanent test pins that residual; it is the caveat this
     *   fallback mode documents. What the destructive half was — a foreign
     *   statement failing and {@see execute()}'s rollback ending the holder's
     *   transaction — stays blocked by the holder guards in
     *   {@see commitTrans()}/{@see rollBackTrans()}. On this mode reads now
     *   serialize behind the per-query lock while transactions are open;
     *   production runs the pooled mode above, where the term is always true
     *   and the skip is free.
     *
     * (Until 2026-08-01 this paragraph claimed the opposite: that holding the
     * mutex for the whole transaction "prevents concurrent coroutines from
     * interleaving queries inside our transaction". On the shared socket it does
     * not. That is the very claim removed from {@see $transLock}'s docblock on
     * 2026-07-31 — it was corrected there first and left standing here, in the
     * method the corrected text points AT, which is the class of contradiction
     * PR #594 exists to delete from this file.)
     *
     * NB a raw `query('START TRANSACTION')` is not an equivalent way to open a
     * transaction either, for a DIFFERENT reason: it leaves the machine's nesting flag at
     * 0, so the transaction is invisible to this class. Every following
     * statement then takes and RELEASES the per-query lock — serialised, but
     * still landing inside the open transaction — and neither the
     * whole-transaction mutex nor the holder guards ever apply to it. Use
     * `beginTrans()`.
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

        // Skip the per-query lock ONLY while THIS coroutine is the one whose
        // transaction holds the whole-transaction mutex — re-taking the per-query
        // lock there would deadlock the holder against itself (its transaction
        // statements never acquired it, so there is nothing to release).
        // The holder-identity term (machine holdingTransactionFor(), carrying
        // BOTH the nesting flag and the holder comparison — TXMUTEX-A A4's
        // two terms) is what makes the short-circuit
        // honest: nesting alone is shared state, so on the single shared
        // socket (`DB_POOL_ENABLED=0`) a bare nesting test fired for EVERY
        // coroutine while ANY of them held the transaction, and unrelated
        // statements then ran on the shared handle with NO lock at all —
        // foreign-against-foreign, trampling each other's prepare/execute
        // sequence. With the term (TXMUTEX-A A4, 2026-11-21) only the holder
        // skips; every other caller takes the per-query lock, so foreign
        // statements serialize. (What the term does NOT do — and cannot: MySQL
        // transaction state is a property of the CONNECTION, so a foreign
        // statement that takes the lock still participates in the holder's open
        // transaction and shares its outcome. That exposure is the documented
        // fallback-mode caveat in the {@see $transLock} field docblock below and
        // in the paragraph above; a permanent test at the tip pins it.) In the
        // DEFAULT pooled mode this instance is one coroutine's exclusive lease,
        // so the caller IS always the holder and the skip is simply free.
        if ($this->txState()->holdingTransactionFor($cid)) {
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
     * the nesting ledger exactly as they were (so the outer scope still commits
     * durably and the connection is reusable afterwards — both measured).
     *
     * Outside a coroutine the mutex is not applicable; delegation to the parent
     * is sufficient (no concurrency). The non-nesting rule is identical there —
     * PDO itself enforces it.
     *
     * A throw from the parent NEVER strands the mutex: the acquisition below is
     * checked and the parent call is wrapped, so this method either returns
     * holding the mutex with nesting 1 (markOpen), or leaves the mutex exactly
     * as it found it. See the comments on those two hunks for the measurements.
     *
     * @return bool
     *
     * @throws \PDOException When this coroutine already has a transaction open
     *         ("There is already an active transaction"), on a connect failure
     *         from the parent, or when the whole-transaction mutex could not be
     *         acquired because this coroutine was cancelled while waiting for
     *         it (a worker stop cancels every live coroutine).
     */
    public function beginTrans(): bool
    {
        $cid = $this->currentCoroutineId();
        if ($cid < 0) {
            // TXMUTEX-A A1: opening a transaction clears the poison — the
            // statement that found the socket dead is not part of this scope.
            $opened = parent::beginTrans();
            $this->txState()->clearPoison();
            return $opened;
        }

        // Same coroutine, transaction already open: NOT reentrant. The parent
        // throws "There is already an active transaction" here — deliberately,
        // so a nesting caller fails fast instead of deadlocking on the mutex it
        // is itself holding. The ledger therefore never gets past nesting 1.
        // The gate reads the machine's holder (TXMUTEX-B retrofit): the
        // vendor call is kept verbatim so the refusal stays the vendor's own
        // PDOException (message, code and errorInfo) — the machine's
        // nested-begin pre-throw carries the same wording but is message-only,
        // and this class's permanent tests catch the vendor instance.
        if ($this->txState()->holder() === $cid) {
            $result = parent::beginTrans();
            if ($result) {
                $this->txState()->markOpen();
            }
            return $result;
        }

        // Acquisition must be CHECKED before this coroutine records itself as
        // the holder. `pop()` returns false instead of the token when the
        // waiting coroutine is CANCELLED while parked — and that is not
        // hypothetical: Workerman's Swoole event driver cancels EVERY live
        // coroutine on worker stop (`Workerman\Events\Swoole::stop()`,
        // `vendor/workerman/workerman/src/Events/Swoole.php:231-233`, and
        // `start.php:127` selects that driver), so a `systemctl stop` (SIGTERM)
        // or `reload` (SIGUSR1 — Workerman's NON-graceful path, which does not
        // wait for in-flight work) can hit a coroutine parked here. Parking here
        // at all needs the CONTENDED shared socket, i.e. `DB_POOL_ENABLED=0`; in
        // the default pooled mode each coroutine leases its own connection, so
        // `$transLock` is never contended. Walking on would install a NON-owner in
        // the holder ledger and steal ownership from the live holder, whose
        // `commitTrans()` would then take the guard below and return true
        // WITHOUT committing — a silent lost commit, with the transaction left
        // open and the token never returned. Measured on this class before the
        // check: `tokens=0`, holder = the cancelled cid forever. Throwing here
        // instead writes nothing and holds nothing, and it costs no working
        // behaviour: the cancelled coroutine went on to raise "There is already
        // an active transaction" from the parent one line further on anyway
        // (measured) — or worse, if the holder's own BEGIN had not landed yet,
        // to open a SECOND transaction on the shared socket. Either way this
        // replaces a late, destructive failure with an early, inert one.
        // TXMUTEX-B retrofit: the checked pop, the refusal wording (machine
        // CANCELLED_ACQUIRE_MESSAGE, byte-identical to the inline literal this
        // replaces) and the bookkeeping-before-park law are the shared
        // MysqlTxLockState::acquire(); the Channel itself stays consumer
        // plumbing handed in as the park closure.
        $lock = $this->transLock();
        $this->txState()->acquire($cid, static fn(): bool => $lock->pop() !== false);

        // A parent that THROWS must still hand the mutex back. This is the same
        // rule {@see commitTrans()} states for a COMMIT that throws, and it is
        // load-bearing for the same reason: nothing else would ever release it.
        // Every coroutine-reachable call site in `src/` writes
        // `$db->beginTrans();` OUTSIDE their `try` (the one site inside a `try`
        // is `MediaDedupePathsCommand`, which is CLI and never takes the mutex),
        // so the throwing coroutine runs no `rollBackTrans()` of its own, and the
        // holder guards in {@see commitTrans()}/{@see rollBackTrans()} correctly
        // refuse a rescue from any OTHER coroutine — so on the shared socket
        // (`DB_POOL_ENABLED=0`) one connect failure would otherwise leave a
        // worker that can never open another transaction until it is restarted
        // (measured: the next coroutine stayed parked indefinitely). Releasing
        // is safe on both throw shapes: after a failed reconnect no transaction
        // exists, and on "There is already an active transaction" the open
        // transaction is not ours (nesting is still 0). The holder check
        // keeps the release single and self-owned — the acquisition above
        // guarantees it is us, and parent::beginTrans() can yield.
        // The throw arm passes clearPoison: false: a BEGIN that never opened
        // must not spend the one-shot refusal budget (landed shape in both
        // consumer repos — see the machine's abandonIfHeld provenance note).
        try {
            $result = parent::beginTrans();
        } catch (\Throwable $e) {
            $this->txState()->abandonIfHeld($cid, $this->pushTransToken(...), clearPoison: false);
            throw $e;
        }

        if ($result) {
            $this->txState()->markOpen();
        } else {
            $this->txState()->abandonIfHeld($cid, $this->pushTransToken(...));
        }
        // TXMUTEX-A A1: the non-throwing exit of the holder path — poison is
        // history once this coroutine stands up its own transaction (mirrors
        // the hub's clear after parent begin; the falsy fold above already
        // cleared it via abandonIfHeld, this line is that fall-through shape).
        $this->txState()->clearPoison();

        return $result;
    }

    /**
     * The whole-transaction mutex, created on first use and NEVER replaced.
     *
     * The channel IS the mutex: a coroutine owns the transaction exactly while
     * it holds the single token, and every other coroutine is parked in
     * {@see beginTrans()}'s `pop()` ON THAT CHANNEL OBJECT. Replacing the field
     * therefore does not reset the mutex, it FORKS it — the parked coroutines
     * keep waiting on the old channel while new arrivals mint a second one and
     * run concurrently, and the token in the old channel can never be returned
     * because the release path only ever pushes to the field.
     *
     * Until 2026-07-31 `commitTrans()`/`rollBackTrans()` (and this method's
     * failure path) did exactly that: they set `$transLock = null` right AFTER
     * pushing the token back. Swoole resumes a parked consumer synchronously
     * inside `Channel::push()` (measured on 6.2.1), so the null-out always
     * landed on a channel a NEW owner had already taken, and that owner's own
     * release then found `null` and pushed nothing. Measured on the unfixed
     * class with 8 concurrent transactions on one shared connection: 6 of the 8
     * coroutines waited forever. Creating it lazily is still required — a
     * `Swoole\Coroutine\Channel` can only be USED inside the coroutine runtime
     * and this method pushes the initial token (`push()` outside a coroutine
     * raises `Swoole\Error: API must be called in the coroutine`; the `new`
     * itself succeeds on swoole 6.2.1, measured, so it is the push that forces
     * the laziness) — but from the first `beginTrans()` on it is a fixed object.
     */
    private function transLock(): \Swoole\Coroutine\Channel
    {
        if ($this->transLock === null) {
            $lock = new \Swoole\Coroutine\Channel(1);
            $lock->push(true);
            $this->transLock = $lock;
        }

        return $this->transLock;
    }

    /**
     * The shared transaction-mutex state machine (TXMUTEX-B retrofit). One
     * ledger object per connection, lazily minted outside any coroutine —
     * the machine performs no I/O and holds no Swoole type, so unlike the
     * Channel above it needs no coroutine runtime to come into existence.
     */
    private function txState(): MysqlTxLockState
    {
        if ($this->txState === null) {
            $this->txState = new MysqlTxLockState();
        }

        return $this->txState;
    }

    /**
     * Hand the whole-transaction mutex token on. MUST be the last thing a
     * releasing coroutine does to the shared ledger — and it is: the machine
     * performs its own holder-free and poison-clear BEFORE invoking this
     * push, and Swoole resumes a coroutine parked in {@see beginTrans()}'s
     * `pop()` SYNCHRONOUSLY inside `push()`, so the next owner has already
     * written itself into the ledger by the time the push returns. (This is
     * the token half of the retired releaseTransLock(); the holder write
     * moved into MysqlTxLockState — TXMUTEX-B retrofit.)
     *
     * Callers only reach this once {@see commitTrans()}/{@see rollBackTrans()}
     * have established that this coroutine is the recorded holder, so the
     * token is pushed exactly once per acquisition and the channel's
     * capacity of 1 is never exceeded.
     */
    private function pushTransToken(): void
    {
        $this->transLock()->push(true);
    }

    /**
     * Commit the current transaction and release the whole-transaction mutex.
     *
     * There is only ever ONE scope to exit: transactions do not nest here (see
     * {@see beginTrans()}), so the machine's nesting flag is 0 or 1 and the `> 1` branch
     * below is UNREACHABLE today. It is kept as a guard rather than deleted so
     * that an inner commit could never release another scope's mutex if
     * savepoint reentrancy were ever implemented in `beginTrans()`.
     *
     * Only the coroutine the machine records as holder may commit — see
     * {@see rollBackTrans()}, which carries the reasoning for both.
     *
     * NOT a reversal of PR #594, which is easy to misread it as. The guard #594
     * deliberately WITHHELD from this method is the null-PDO-handle guard
     * ({@see rollBackParent()}'s `if (!$this->pdo instanceof \PDO)`), on the
     * grounds that a silent "commit succeeded" on a dead socket would be a lie
     * where a silent "rollback succeeded" is the truth. That decision still
     * stands and is still visible below: `parent::commitTrans()` is called
     * UNCONDITIONALLY, so a commit with no handle still fails loudly. The
     * holder-identity (claimCommit INERT) guard in the body is orthogonal to all of
     * that — it is about WHICH coroutine's transaction this is, not about
     * whether the socket is alive.
     *
     * The non-holder return is `true` rather than `false` deliberately. A throw
     * is wrong here because the stale call arrives from inside a caller's
     * `catch` and would replace the original exception; `false` would be
     * marginally more honest than `true`. As of 2026-07-31 the choice is inert
     * either way: no caller anywhere outside `src/Common/Database/` inspects the
     * return value of `commitTrans()`/`rollBackTrans()` at all (verified by
     * grep), so it is an API-honesty question, not a control-flow one. THAT IS
     * THE FACT THIS RESTS ON — if a caller ever starts checking the boolean,
     * revisit this and return `false` for a non-holder.
     *
     * @return bool
     */
    public function commitTrans(): bool
    {
        $cid = $this->currentCoroutineId();

        // TXMUTEX-B retrofit: the whole ladder — stranger-inert ABOVE the
        // poison check (the A1 order: a non-holder returns without
        // touching — or clearing — the holder's poison), the one-shot
        // refusal consuming the mark, the CLI pass-through, the depth
        // guard, and the outermost zeroing that happens BEFORE the vendor
        // call — is the shared machine's claimCommit(). The landed arm
        // prose, carried here so the law stays readable at the seam:
        // a connection execute() poisoned by losing the socket
        // mid-transaction must not COMMIT onto the replacement socket —
        // the server never saw most of the work. Refuse once (the
        // machine's POISONED_COMMIT_MESSAGE, the hub wording verbatim)
        // and clear, so the next transaction is evaluated on its own
        // merits. The refusal does NOT release the mutex: holder and
        // nesting stand for the caller's catch-rollback (the server law;
        // the hub's refusal finally DOES release it — the real repo
        // divergence, kept per-repo at register row F-1, never
        // harmonized).
        $claim = $this->txState()->claimCommit($cid);

        if ($claim === TxEnd::INERT) {
            return true;
        }

        if ($claim === TxEnd::POISON_REFUSED) {
            throw new \PDOException(MysqlTxLockState::POISONED_COMMIT_MESSAGE);
        }

        if ($claim === TxEnd::PASS_THROUGH) {
            return parent::commitTrans();
        }

        if ($claim === TxEnd::NESTED) {
            // Unreachable while beginTrans() refuses to nest (see its
            // docblock): an inner commit must never release the outer
            // scope's mutex.
            $result = parent::commitTrans();
            if ($result) {
                $this->txState()->decrement();
            }
            return $result;
        }

        // Outermost (CLOSE): claimCommit already moved the nesting flag to
        // 0 — the landed position of that write, ahead of the vendor call.
        // The hand-off is in a `finally` because a COMMIT that throws (a
        // lost connection, say) must still free the mutex — otherwise every
        // later transaction on this connection blocks forever. handOff()
        // clears the poison, frees the holder, and pushes LAST (§1.5) —
        // exactly the landed finally order.
        try {
            $result = parent::commitTrans();
        } finally {
            $this->txState()->handOff($this->pushTransToken(...));
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
     * (see {@see rollBackParent()}).
     *
     * ONLY THE RECORDED HOLDER MAY END THE TRANSACTION. That is what the
     * claimRollback INERT guard below is for, and on the shared-socket
     * fallback (`DB_POOL_ENABLED=0`) it is load-bearing rather than defensive:
     * the defensive `rollBackTrans()` a caller runs in its `catch` reaches this
     * method AFTER {@see execute()} has already rolled back on the caller's
     * behalf and released the mutex — and released does not mean dropped, it
     * means HANDED ON, because swoole resumes a coroutine parked in
     * {@see beginTrans()}'s `pop()` synchronously inside `push()` (measured on
     * 6.2.1) and nothing in the unwind that follows yields. So by the time the
     * `catch` runs, another coroutine owns the mutex. Without the guard the
     * outermost branch would write `$transNesting = 0` / `$transLockHolder = -1`
     * over that coroutine's bookkeeping and then issue a real `ROLLBACK` on its
     * transaction once its `BEGIN` had landed (one shared PDO handle). Measured
     * on the unguarded class, forcing each schedule deterministically: the new
     * owner's `commitTrans()` died with "There is no active transaction", and a
     * third coroutine walked past the cleared holder into a concurrent
     * transaction. With the guard a non-holder issues no statement and writes
     * nothing, so that `catch` really is inert — in every mode, not just the two
     * where this object serves one coroutine at a time (outside a coroutine, and
     * in the default pooled mode where each coroutine has its own lease).
     *
     * Corollary, relied on by nothing but worth stating: a coroutine that opened
     * a transaction with a raw `query('START TRANSACTION')` instead of
     * {@see beginTrans()} is not the recorded holder, so this method will not
     * roll it back. No caller does that (`PathDeduper` was the last one and was
     * converted on 2026-07-28); the tracked API is the only supported way in.
     *
     * @return bool
     */
    public function rollBackTrans(): bool
    {
        $cid = $this->currentCoroutineId();
        if ($cid < 0) {
            $result = $this->rollBackParent();
            // TXMUTEX-A A1: rollback is an explicit fresh start — the poison
            // (if any) is consumed without a refusal, mirroring beginTrans.
            // The clear runs AFTER the vendor rollback, exactly landed: the
            // machine's claimRollback CLI arm clears before the vendor call,
            // so this arm bypasses the claim to keep the landed order
            // (register row D-3) — no observable difference in reachable
            // states, but zero-behavior law keeps the site, not the prose.
            $this->txState()->clearPoison();
            return $result;
        }

        // TXMUTEX-B retrofit: stranger-INERT (the stale releaser: a
        // rollBackTrans() that reaches here AFTER execute() already rolled
        // back and handed the mutex on must issue no statement and write
        // nothing — see the docblock for why both halves of that matter),
        // the depth guard, and the outermost zeroing are the shared
        // claimRollback(); the finally hand-off is the machine's handOff(),
        // which clears poison, frees the holder and pushes LAST (§1.5) in
        // the landed order.
        $claim = $this->txState()->claimRollback($cid);

        if ($claim === TxEnd::INERT) {
            return true;
        }

        if ($claim === TxEnd::NESTED) {
            // Unreachable while beginTrans() refuses to nest (see its docblock):
            // an inner rollback must never release the outer scope's mutex.
            $result = $this->rollBackParent();
            if ($result) {
                $this->txState()->decrement();
            }
            return $result;
        }

        // Outermost (CLOSE): end the transaction, then hand the mutex on. The
        // release is in a `finally` so a throwing ROLLBACK cannot strand the
        // mutex.
        try {
            $result = $this->rollBackParent();
        } finally {
            $this->txState()->handOff($this->pushTransToken(...));
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
        //
        // This `pop()` is UNCHECKED on purpose, and the asymmetry with
        // {@see beginTrans()}'s checked one is a deferral, not an oversight. The
        // hazard is identical (see that comment for the trigger, and for the fact
        // that it needs the shared socket, `DB_POOL_ENABLED=0`): a cancelled
        // waiter walks on and returns `true` to {@see query()} (measured
        // `got=true`), stealing `$queryLockHolder` from the live holder, so
        // `query()`'s `finally` then pushes a SPURIOUS token — measured
        // `holder=-1 tokens=1` while the true holder is still mid-statement —
        // and mutual exclusion is broken for every later coroutine too, not just
        // the cancelled one. It is NOT fixed here because the fix is not
        // symmetric with `beginTrans()`'s: throwing out of `query()` changes the
        // failure surface of every DB read on this connection, and the
        // release-side half lives inside `query()`'s own `finally`. The half
        // that used to defer on it — the missing holder-identity
        // term in `query()`'s short-circuit, without which a non-holder
        // querying DURING somebody's transaction never reached this method at
        // all — shipped at TXMUTEX-A A4 (2026-11-21), so the pair is no longer
        // blocked on its own completeness; the failure-surface change and the
        // fallback-mode read fan-out measurement remain the open half. The
        // original finding's worklog (finding F1 in
        // `steps/fix-unpooled-txn-mutex-race.worklog.md`) was lost — the file
        // no longer exists anywhere in the repo — and the executed evidence for
        // both this hazard and its `beginTrans()` twin now lives in this
        // connection's permanent tests.
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
