<?php

declare(strict_types=1);

namespace Phlix\Server\WebSocket;

/**
 * Manages active WebSocket connections in a thread-safe manner.
 *
 * This class implements a singleton pattern to provide global
 * access to the connection pool from anywhere in the application.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description Singleton connection pool for managing active WebSocket connections.
 * @see Connection For the connection wrapper class
 * @see ConnectionInterface For the connection contract
 */
class ConnectionPool
{
    /** @var ConnectionPool Singleton instance */
    private static ConnectionPool $instance;

    /** @var array<string, ConnectionInterface> Active connections indexed by ID */
    private array $connections = [];

    /**
     * Gets the singleton ConnectionPool instance.
     *
     * @return ConnectionPool The singleton instance
     *
     * @description Returns the global connection pool for managing all active WebSocket connections.
     */
    public static function getInstance(): ConnectionPool
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Adds a connection to the pool.
     *
     * @param ConnectionInterface $connection The connection to add
     * @return void
     */
    public function add(ConnectionInterface $connection): void
    {
        $this->connections[$connection->getId()] = $connection;
    }

    /**
     * Removes a connection from the pool.
     *
     * @param string $id The connection ID to remove
     * @return void
     */
    public function remove(string $id): void
    {
        unset($this->connections[$id]);
    }

    /**
     * Gets a connection by ID.
     *
     * @param string $id The connection ID to look up
     * @return ConnectionInterface|null The connection or null if not found
     */
    public function get(string $id): ?ConnectionInterface
    {
        return $this->connections[$id] ?? null;
    }

    /**
     * Gets all active connections.
     *
     * @return array<ConnectionInterface> Array of all connections
     */
    public function all(): array
    {
        return array_values($this->connections);
    }

    /**
     * Gets the total number of active connections.
     *
     * @return int Connection count
     */
    public function count(): int
    {
        return count($this->connections);
    }

    /**
     * Finds all connections for a specific user.
     *
     * A user may have multiple connections (e.g., multiple devices).
     *
     * @param string $userId The user ID to search for
     * @return array<ConnectionInterface> Array of matching connections
     */
    public function findByUserId(string $userId): array
    {
        $found = [];
        foreach ($this->connections as $connection) {
            if ($connection->getUserId() === $userId) {
                $found[] = $connection;
            }
        }
        return $found;
    }

    /**
     * Finds all connections in a specific session.
     *
     * @param string $sessionId The session ID to search for
     * @return array<ConnectionInterface> Array of matching connections
     */
    public function findBySessionId(string $sessionId): array
    {
        $found = [];
        foreach ($this->connections as $connection) {
            if ($connection->getSessionId() === $sessionId) {
                $found[] = $connection;
            }
        }
        return $found;
    }

    /**
     * Removes connections that have been idle too long.
     *
     * Sends a timeout message to stale connections before closing them.
     *
     * @param int $maxIdleTime Maximum idle time in seconds (default: 300 = 5 minutes)
     * @return void
     */
    public function cleanupStaleConnections(int $maxIdleTime = 300): void
    {
        $now = time();
        foreach ($this->connections as $id => $connection) {
            if ($now - $connection->getLastActivity() > $maxIdleTime) {
                $connection->sendMessage('timeout', ['message' => 'Connection timed out']);
                $connection->close();
                $this->remove($id);
            }
        }
    }

    /**
     * Removes all connections from the pool.
     *
     * @return void
     *
     * @description Clears all connections. Useful for testing or server shutdown.
     */
    public function clear(): void
    {
        $this->connections = [];
    }
}
