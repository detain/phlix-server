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

            error_log("[DEBUG] " . date('Y-m-d H:i:s.v') . " ConnectionPool::getConnection Creating NEW connection [name={$name}] [host={$hostStr}] [pool_enabled=" . ($poolEnabled ? 'true' : 'false') . "]");

            if ($poolEnabled) {
                // Coroutine connection pool: each coroutine leases its own
                // connection for true intra-worker DB parallelism. OFF unless
                // `pool_enabled` is set — see PooledMySQLConnection. Validate
                // on a non-prod restart before trusting under load.
                self::$connections[$name] = new PooledMySQLConnection(
                    $hostStr,
                    $portInt,
                    $userStr,
                    $passStr,
                    $dbStr,
                    $poolSize,
                    $charset
                );
                error_log("[DEBUG] " . date('Y-m-d H:i:s.v') . " ConnectionPool::getConnection Created PooledMySQLConnection [name={$name}] [pool_size={$poolSize}]");
            } else {
                // Default: the single PhlixMySQLConnection subclass — re-keys
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
                error_log("[DEBUG] " . date('Y-m-d H:i:s.v') . " ConnectionPool::getConnection Created PhlixMySQLConnection [name={$name}]");
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
