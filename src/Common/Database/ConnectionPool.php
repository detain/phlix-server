<?php

declare(strict_types=1);

namespace Phlex\Common\Database;

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

            self::$connections[$name] = new Connection(
                is_scalar($host) ? (string)$host : '',
                is_numeric($port) ? (int)$port : 3306,
                is_scalar($username) ? (string)$username : '',
                is_scalar($password) ? (string)$password : '',
                is_scalar($database) ? (string)$database : ''
            );
        }
        return self::$connections[$name];
    }

    public static function closeAll(): void
    {
        foreach (self::$connections as $connection) {
            $connection->closeConnection();
        }
        self::$connections = [];
    }
}
