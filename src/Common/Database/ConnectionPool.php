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

class ConnectionPool
{
    /** @var array<string, Connection> */
    private static array $connections = [];
    private static string $configPath = '';
    private static ?ConnectionPool $instance = null;

    public static function init(string $configPath): void
    {
        self::$configPath = $configPath;
        self::$instance = new self();
    }

    public static function getInstance(): ?ConnectionPool
    {
        return self::$instance;
    }

    public static function getConnection(string $name = 'mysql'): Connection
    {
        if (!isset(self::$connections[$name])) {
            if (self::$configPath === '' || !is_file(self::$configPath)) {
                throw new \RuntimeException(
                    'ConnectionPool has no database config path — call ConnectionPool::init($path) '
                    . 'or set `db_config_path` in the app config before resolving Connection.'
                );
            }
            $config = include self::$configPath;
            if (!is_array($config) || !isset($config['connections']) || !is_array($config['connections'])) {
                throw new \RuntimeException('Invalid database config at ' . self::$configPath);
            }
            $connConfig = $config['connections'][$name] ?? null;
            if (!is_array($connConfig)) {
                throw new \RuntimeException(sprintf('Connection "%s" not configured', $name));
            }

            $host = $connConfig['host'] ?? '';
            $port = $connConfig['port'] ?? 3306;
            $username = $connConfig['username'] ?? '';
            $password = $connConfig['password'] ?? '';
            $database = $connConfig['database'] ?? '';
            $charset = isset($connConfig['charset']) && is_scalar($connConfig['charset'])
                ? (string) $connConfig['charset']
                : 'utf8mb4';
            $poolEnabled = (bool) ($connConfig['pool_enabled'] ?? false);
            $poolSize = is_numeric($connConfig['pool_size'] ?? null)
                ? (int) $connConfig['pool_size']
                : 8;

            $hostStr = is_scalar($host) ? (string)$host : '';
            $portInt = is_numeric($port) ? (int)$port : 3306;
            $userStr = is_scalar($username) ? (string)$username : '';
            $passStr = is_scalar($password) ? (string)$password : '';
            $dbStr = is_scalar($database) ? (string)$database : '';

            // R14-07: the three [DEBUG] error_log() lines that used to bracket
            // construction are gone. They wrote raw to stderr on the cold path
            // of EVERY worker (bypassing LoggerFactory, channels and rotation),
            // disclosed config-shaped values (host, pool size) unasked, and no
            // test or runbook consumed them. This block is once-per-name-per-
            // process anyway — if construction itself fails, the driver
            // exception surfaces at the call site regardless.

            if ($poolEnabled) {
                // Coroutine connection pool: each coroutine leases its own
                // connection for true intra-worker DB parallelism. This is the
                // SHIPPING DEFAULT — config/database.php resolves `pool_enabled`
                // to ON unless DB_POOL_ENABLED explicitly opts out (the `===
                // false` env dance documented there), in which case the
                // single-connection mutex path below is the fallback. See
                // PooledMySQLConnection for the lease semantics.
                //
                // POOL MATH (R19-16), keyed to MySQL's `max_connections`:
                // every WORKER PROCESS owns its own pool, so the server-wide
                // ceiling is (workers × pool_size) plus the single-connection
                // consumers. Out of the box that is the HTTP fleet (start.php:
                // count = 14) × pool_size (default 8) ≈ 112, plus one pooled
                // pool each for the WebSocket (:8097), hub-heartbeat, background-
                // timer and relay-tunnel workers (each count = 1), plus
                // migrations/scripts/CLI connections — call it ~140 at defaults.
                // Keep MySQL `max_connections` comfortably ABOVE that sum (and
                // remember other applications may share the server); raising
                // DB_POOL_SIZE multiplies by 14 per step, so tune pool_size
                // before you tune max_connections down, and never treat
                // (workers × pool_size) as the only tenant of the connection
                // budget.
                self::$connections[$name] = new PooledMySQLConnection(
                    $hostStr,
                    $portInt,
                    $userStr,
                    $passStr,
                    $dbStr,
                    $poolSize,
                    $charset
                );
            } else {
                // Opt-out fallback (DB_POOL_ENABLED=0 / pool_enabled false — the
                // inverse of the shipping default above): the single
                // PhlixMySQLConnection subclass — re-keys
                // positional bind arrays (workerman/mysql v1.0.9 bindMore() bug
                // on PHP 8.x) and serialises cross-coroutine access via its
                // per-connection mutex. Type-compatible with the parent.
                // Pass the configured charset (utf8mb4) so bound params match
                // the utf8mb4_unicode_ci schema — without it the parent falls
                // back to utf8mb3 and INSERTs fail with MySQL error 3988.
                self::$connections[$name] = new PhlixMySQLConnection(
                    $hostStr,
                    $portInt,
                    $userStr,
                    $passStr,
                    $dbStr,
                    $charset
                );
            }
        }
        return self::$connections[$name];
    }

    /**
     * Instance proxy for getConnection — enables DI injection while
     * preserving the static pool semantics underneath.
     *
     * @return Connection
     */
    public function getPooledConnection(string $name = 'mysql'): Connection
    {
        return self::getConnection($name);
    }

    public static function closeAll(): void
    {
        foreach (self::$connections as $connection) {
            $connection->closeConnection();
        }
        self::$connections = [];
    }

    /**
     * Chains a DB-connection cleanup onto the worker's onWorkerStop hook.
     *
     * Under the Swoole event loop, a coroutine-hooked PDO socket that is still
     * open when the process reaches RSHUTDOWN is torn down outside any
     * coroutine context and fatals the worker ("Uncaught Swoole\Error: API
     * must be called in the coroutine" with the DB pool, "Couldn't execute
     * method Error::__toString" without it). onWorkerStop still runs inside a
     * coroutine, so closing every connection there lets the process exit
     * cleanly. Any onWorkerStop already assigned at call time is preserved
     * and invoked first.
     *
     * @param \Workerman\Worker $worker Worker declared before runAll().
     */
    public static function armWorkerStopCleanup(\Workerman\Worker $worker): void
    {
        $previous = $worker->onWorkerStop;
        $worker->onWorkerStop = static function (\Workerman\Worker $w) use ($previous): void {
            if (is_callable($previous)) {
                $previous($w);
            }
            // Close client connections BEFORE draining the pool: their onClose
            // handlers may write to the DB (e.g. relay-session teardown) and
            // would otherwise re-create a hooked PDO connection after closeAll,
            // which then fatals at RSHUTDOWN. Worker::stop() only closes
            // connections AFTER onWorkerStop, so do it here first.
            foreach ($w->connections as $connection) {
                $connection->close();
            }
            self::closeAll();
        };
    }
}
