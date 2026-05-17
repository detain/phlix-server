<?php

namespace Phlex\Common\Database;

use Workerman\MySQL\Connection;

class ConnectionPool
{
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
            $connConfig = $config['connections'][$name];

            self::$connections[$name] = new Connection(
                $connConfig['host'],
                $connConfig['port'],
                $connConfig['username'],
                $connConfig['password'],
                $connConfig['database']
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
