<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Database;

use Phlix\Common\Database\ConnectionPool;
use PHPUnit\Framework\Assert;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * S126 — the single real-database entry gate for every test that needs MySQL.
 *
 * ## What it decides
 *
 * Two outcomes that used to be indistinguishable are now separated:
 *
 *  - **absent** — nothing is listening on the configured host/port. That is a
 *    developer box without MySQL, and it stays a `markTestSkipped()`, exactly
 *    as before.
 *  - **unusable** — something accepted a TCP connection but a trivial
 *    `SELECT 1` could not be run over it (wrong password, wrong user, a
 *    database that does not exist, a non-MySQL listener on the port). That is
 *    a broken *configuration*, not an absent database, so it is raised as
 *    {@see IntegrationDbUnusableException} and reddens the run.
 *
 * ## Why the `SELECT 1` is load-bearing, not a sanity check
 *
 * `config/database.php:34-37` resolves `pool_enabled` from
 * `getenv('DB_POOL_ENABLED') === false ? '1' : getenv('DB_POOL_ENABLED')`, so an
 * UNSET `DB_POOL_ENABLED` yields `'1'` — the pool is **on by default**, which is
 * what CI and a stock dev box run. `ConnectionPool::getConnection()` then builds
 * a {@see \Phlix\Common\Database\PooledMySQLConnection}
 * (`src/Common/Database/ConnectionPool.php:78-91`), whose constructor carries the
 * comment "Deliberately NOT calling parent::__construct() — see class docblock"
 * (`src/Common/Database/PooledMySQLConnection.php:108`) and opens **no socket**;
 * `query()` delegates to `$this->lease()` (`:128-131`), which is where a socket
 * is first opened. So merely constructing the connection proves nothing in the
 * default configuration, and a guard that only wraps `init()` + `getConnection()`
 * cannot fail there. Only `DB_POOL_ENABLED=0` builds a
 * {@see \Phlix\Common\Database\PhlixMySQLConnection}
 * (`src/Common/Database/ConnectionPool.php:105-112`), whose parent constructor
 * connects eagerly.
 *
 * Issuing a real round-trip inside the guarded block makes both modes behave
 * identically. `SELECT` is chosen because it returns rows:
 * `vendor/workerman/mysql/src/Connection.php:1852-1869` always executes the
 * statement and then switches on the first keyword only to pick a return value
 * (`select`/`show` → `fetchAll()`, `update`/`delete`/`replace` → `rowCount()`,
 * `insert` → `lastInsertId()` only when `rowCount() > 0`, anything else →
 * `null`), so a `SET`/`CREATE`/`BEGIN` round-trip would run but be
 * indistinguishable from a no-op by its return value. `SELECT 1` binds nothing
 * and has no side effects.
 *
 * ## Why absence must stay a skip
 *
 * `.github/workflows/phpunit.yml` runs two jobs. The `test` job provisions a
 * `mysql:8.0` service and applies every migration before PHPUnit, so these tests
 * execute for real there. The `test-server` job runs `tests/Unit/Server/` with
 * **no** MySQL service at all — port 3306 is closed and the guard must skip, or
 * that job goes red for a reason that is not a defect. The same applies to a
 * developer box with no MySQL installed.
 *
 * ## Probe target == connection target
 *
 * {@see host()} / {@see port()} read the *resolved* `mysql` block of
 * `config/database.php` — the very file {@see connection()} hands to
 * `ConnectionPool::init()` — rather than reading `DB_HOST`/`DB_PORT` themselves.
 * That is deliberate: if the probe resolved its target independently it could
 * disagree with the connection, and a probe against a *different* address than
 * the one the test then connects to is the S126 defect wearing a new hat. It
 * also closes the converse hazard — a healthy MySQL on a non-3306 port used to
 * make a probe pinned to 3306 skip forever.
 *
 * ⚠ Two bounds on that guarantee, both measured rather than assumed:
 *
 *  - it is about the *address*, and it holds for the address only.
 *    `ConnectionPool` caches by connection **name**, not by config path
 *    (`src/Common/Database/ConnectionPool.php:23-27` — `init()` re-news the
 *    instance but does **not** clear `self::$connections`; `:36` reads the
 *    config only `if (!isset(self::$connections[$name]))`). So the
 *    `ConnectionPool::init(self::configPath())` in {@see connection()} is a
 *    no-op for the connection it then validates whenever anything earlier in the
 *    process already cached one under `'mysql'`. `tests/Unit/Server/Core/ApplicationTest.php`
 *    does exactly that, booting `Application` against a temp config; under
 *    `executionOrder="random"` a later guard call can therefore probe
 *    `config/database.php`'s address and then `SELECT 1` over a connection built
 *    from the temp one. Latent, not live: `phpunit.xml`'s `<env>` block supplies
 *    every `DB_*`, so both configs resolve to the same server. A developer
 *    running one file with a partial environment can see them differ.
 *  - an explicit `$host`/`$port` override re-opens it for that call site.
 *
 * The `$host`/`$port` override parameters remain, for the two call sites
 * (`tests/Integration/Container/BootstrapTest.php`,
 * `tests/Unit/Server/Core/ApplicationTest.php`) that pin the historical literal
 * `127.0.0.1:3306`, and for a test like
 * `tests/Integration/Media/Transcoding/PooledConnectionConcurrencyTest.php`
 * that builds its own connection and wants the probe aimed at exactly that.
 * ⚠ An override re-opens the divergence *for that call site only*: measured with
 * `DB_PORT=33306` pointed at a listener that accepts-and-closes, the two pinned
 * files report `Skipped: 2` and `Skipped: 3` — a green skip against a provably
 * unusable configured database — while the four non-pinned files correctly
 * report errors. Overriding is therefore a decision to be made per call site,
 * never a default.
 *
 * ## Message compatibility
 *
 * Every pre-S126 call site built its skip message as
 * `No MySQL on {host}:{port} — {reason}`; `$skipReason` is that trailing part, so
 * migrated sites emit byte-identical skip text. The host/port interpolated into
 * it are whatever `config/database.php:14-15` resolved, passed through
 * unchanged — so they inherit that file's `getenv('DB_HOST') ?: '127.0.0.1'` /
 * `(int) (getenv('DB_PORT') ?: 3306)`, which is the expression the 35 private
 * copies used verbatim. Checked input by input (`'0'`, `''`, unset, `'abc'`,
 * `'0x0d3d'`, `'3307'`, `'-1'`, `'65536'`, `'3306abc'`): guard, config and the
 * old private copies agree on every one, including `DB_HOST="0"` → `127.0.0.1`
 * and `DB_PORT="0"` → `3306`.
 *
 * @see RequiresRealDatabase for the trait most tests should use.
 */
final class IntegrationDbGuard
{
    /**
     * Absolute path to the app's env-driven database config.
     *
     * The same file `ConnectionPool` is initialised with at every migrated call
     * site, so the guard and the tests connect to exactly one place.
     */
    public static function configPath(): string
    {
        return dirname(__DIR__, 3) . '/config/database.php';
    }

    /**
     * The resolved `mysql` connection block of {@see configPath()}.
     *
     * `require`, NOT `require_once`: `require_once` returns `true` rather than
     * the file's array on every call after the first, which would silently turn
     * every host/port lookup after the first into the fallback.
     *
     * @return array<string, mixed>
     */
    private static function mysqlConfig(): array
    {
        /** @var mixed $config */
        $config = require self::configPath();

        if (!is_array($config) || !is_array($config['connections'] ?? null)) {
            return [];
        }

        /** @var array<string, mixed> $connections */
        $connections = $config['connections'];

        return is_array($connections['mysql'] ?? null) ? $connections['mysql'] : [];
    }

    /**
     * Host the guard probes: whatever `config/database.php:14` resolved, which is
     * `getenv('DB_HOST') ?: '127.0.0.1'`. Read from the config rather than from
     * the environment so the probe target cannot diverge from the connection
     * target — see the class docblock.
     *
     * ⚠ The value is passed through **unchanged**. `?:` is applied by the config
     * (which is what makes `DB_HOST="0"` resolve to `127.0.0.1`), so re-applying
     * it here can only make the probe target differ from the connection target —
     * the one thing this accessor exists to prevent. The `'127.0.0.1'` below is
     * reached only when the config has no `host` key at all, i.e. when the file
     * is not the config this repo ships.
     *
     * `phpunit.xml` exports `DB_HOST=127.0.0.1` for the suite, so this is
     * `127.0.0.1` under the repo's own configuration.
     */
    public static function host(): string
    {
        /** @var mixed $host */
        $host = self::mysqlConfig()['host'] ?? null;

        return is_string($host) ? $host : '127.0.0.1';
    }

    /**
     * Port the guard probes: whatever `config/database.php:15` resolved, which is
     * `(int) (getenv('DB_PORT') ?: 3306)` — so `DB_PORT="0"` resolves to 3306.
     *
     * ⚠ No second `?:`, for the reason given on {@see host()} and because that
     * second application was measurably wrong: `DB_PORT='abc'` makes the config
     * resolve `(int) 'abc'` = `0`, so `ConnectionPool` connects to port 0 while
     * `((int) $port ?: 3306)` aimed the probe at 3306. Probe and connection then
     * targeted different ports — exactly the divergence the class docblock says
     * an un-pinned call site can no longer have. Measured across
     * `'abc'`, `'0x0d3d'`, `'0'`, `''`, unset, `'3307'`, `'-1'`, `'65536'` and
     * `'3306abc'`, this now agrees with `config/database.php` on every input, and
     * with the 35 private copies' `(int) (getenv('DB_PORT') ?: 3306)` too.
     *
     * `phpunit.xml` exports `DB_PORT=3306` for the suite.
     */
    public static function port(): int
    {
        /** @var mixed $port */
        $port = self::mysqlConfig()['port'] ?? null;

        return is_int($port) || is_string($port) ? (int) $port : 3306;
    }

    /**
     * Skip when MySQL is absent; raise when it is present but unusable; otherwise
     * return the shared pool connection.
     *
     * @param string      $skipReason Trailing part of the skip message, e.g.
     *                                `'skipping music DTO media_item_id test. Runs in CI.'`
     * @param string|null $host       Override the probe host (defaults to {@see host()}).
     * @param int|null    $port       Override the probe port (defaults to {@see port()}).
     */
    public static function connection(string $skipReason, ?string $host = null, ?int $port = null): Connection
    {
        $host ??= self::host();
        $port ??= self::port();

        // ⚠ skipUnlessListening() MUST stay ABOVE the try, never inside it.
        // `Assert::markTestSkipped()` throws `SkippedWithMessageException`, which
        // `extends AssertionFailedError extends Exception` — so the
        // `catch (Throwable)` below WOULD catch it, and every legitimate "no
        // MySQL on this box" skip would be converted into a false
        // `IntegrationDbUnusableException`, reddening the `test-server` CI job
        // that deliberately runs with no MySQL service. Nothing inside the try
        // can reach `Assert`, a mock or an assertion, which is what makes the
        // catch safe as written.
        self::skipUnlessListening($host, $port, $skipReason);

        try {
            ConnectionPool::init(self::configPath());
            $db = ConnectionPool::getConnection('mysql');
            // The round-trip that makes the pooled and unpooled modes agree —
            // see the class docblock. Binds nothing, returns a row set.
            $db->query('SELECT 1');

            return $db;
        } catch (Throwable $e) {
            throw self::unusable($host, $port, $e);
        }
    }

    /**
     * The same gate, for tests that open their own connection instead of using
     * `ConnectionPool`'s shared one (e.g. a recording subclass, or a pool front
     * built by hand for a concurrency test).
     *
     * @param string      $skipReason Trailing part of the skip message.
     * @param string|null $host       Override the probe host (defaults to {@see host()}).
     * @param int|null    $port       Override the probe port (defaults to {@see port()}).
     */
    public static function requireHealthyDatabase(
        string $skipReason,
        ?string $host = null,
        ?int $port = null
    ): void {
        self::connection($skipReason, $host, $port);
    }

    /**
     * TCP-reachability probe. Unchanged in behaviour from the 35 private copies
     * S126 replaced: `@fsockopen` with a 1.0s timeout, closed immediately.
     *
     * A `false` return means "nothing accepted a connection", which is the only
     * thing this can prove. Everything else is decided by the round-trip in
     * {@see connection()}.
     */
    private static function isListening(string $host, int $port): bool
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.0);

        if ($sock === false) {
            return false;
        }

        fclose($sock);

        return true;
    }

    /**
     * `Assert::markTestSkipped()` is `final public static … : never`
     * (`vendor/phpunit/phpunit/src/Framework/Assert.php:2304`), so it can be
     * reached from this static helper without a `TestCase` instance.
     */
    private static function skipUnlessListening(string $host, int $port, string $skipReason): void
    {
        if (self::isListening($host, $port)) {
            return;
        }

        Assert::markTestSkipped(sprintf('No MySQL on %s:%d — %s', $host, $port, $skipReason));
    }

    private static function unusable(string $host, int $port, Throwable $previous): IntegrationDbUnusableException
    {
        return new IntegrationDbUnusableException(
            sprintf(
                'S126 INTEGRATION DB GUARD: something on %s:%d accepted a TCP connection but no query '
                . 'could be run over it (%s). That is NOT "no MySQL on this box", so it is REPORTED '
                . 'instead of skipped — skipping here would report success without ever touching a '
                . 'database. Most often it is a DB_* / config problem: check DB_USER / DB_PASSWORD / '
                . 'DB_DATABASE / DB_HOST / DB_PORT and run `php scripts/run-migrations.php`. This guard '
                . 'cannot tell that apart from a transient condition (a non-MySQL listener on the port, '
                . 'or a connect timeout on a loaded box), so read the driver message above first.',
                $host,
                $port,
                $previous->getMessage(),
            ),
            0,
            $previous,
        );
    }
}
