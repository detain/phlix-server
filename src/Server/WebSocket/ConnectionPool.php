<?php

/**
 * Phlix media server component: WebSocket.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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

    /** @var array<int, ConnectionInterface> Connections indexed by TcpConnection object ID for O(1) lookup */
    private array $connectionsByObjectId = [];

    /** @var array<string, array<string, ConnectionInterface>> Connections indexed by userId */
    private array $connectionsByUserId = [];

    /** @var array<string, array<string, ConnectionInterface>> Connections indexed by sessionId */
    private array $connectionsBySessionId = [];

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

        // Index by TcpConnection object ID for O(1) findConnection lookup
        if ($connection instanceof Connection) {
            $tcpConnection = $connection->getConnection();
            $objectId = spl_object_id($tcpConnection);
            $this->connectionsByObjectId[$objectId] = $connection;

            // Index by userId for O(1) sendToUser lookup
            $userId = $connection->getUserId();
            if ($userId !== null) {
                $this->connectionsByUserId[$userId] ??= [];
                $this->connectionsByUserId[$userId][$connection->getId()] = $connection;
            }

            // Index by sessionId for O(1) sendToSession lookup
            $sessionId = $connection->getSessionId();
            if ($sessionId !== null) {
                $this->connectionsBySessionId[$sessionId] ??= [];
                $this->connectionsBySessionId[$sessionId][$connection->getId()] = $connection;
            }
        }
    }

    /**
     * Removes a connection from the pool.
     *
     * @param string $id The connection ID to remove
     * @return void
     */
    public function remove(string $id): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null) {
            return;
        }

        unset($this->connections[$id]);

        // Remove from TcpConnection object ID index
        if ($connection instanceof Connection) {
            $tcpConnection = $connection->getConnection();
            $objectId = spl_object_id($tcpConnection);
            unset($this->connectionsByObjectId[$objectId]);

            // Remove from userId index
            $userId = $connection->getUserId();
            if ($userId !== null) {
                unset($this->connectionsByUserId[$userId][$id]);
                if ($this->connectionsByUserId[$userId] === []) {
                    unset($this->connectionsByUserId[$userId]);
                }
            }

            // Remove from sessionId index
            $sessionId = $connection->getSessionId();
            if ($sessionId !== null) {
                unset($this->connectionsBySessionId[$sessionId][$id]);
                if ($this->connectionsBySessionId[$sessionId] === []) {
                    unset($this->connectionsBySessionId[$sessionId]);
                }
            }
        }
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
     * Gets a connection by TcpConnection object ID (O(1) lookup).
     *
     * @param \Workerman\Connection\TcpConnection $tcpConnection The Workerman TCP connection
     * @return ConnectionInterface|null The connection or null if not found
     */
    public function getByObjectId(\Workerman\Connection\TcpConnection $tcpConnection): ?ConnectionInterface
    {
        $objectId = spl_object_id($tcpConnection);

        return $this->connectionsByObjectId[$objectId] ?? null;
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
     * Uses indexed lookup for O(1) retrieval when the connection is a
     * {@see Connection} instance (which updates indexes on userId change).
     * Falls back to linear scan for non-Connection test doubles.
     *
     * @param string $userId The user ID to search for
     * @return array<ConnectionInterface> Array of matching connections
     */
    public function findByUserId(string $userId): array
    {
        // Fast path: indexed lookup for real Connection instances
        if (isset($this->connectionsByUserId[$userId])) {
            return array_values($this->connectionsByUserId[$userId]);
        }

        // Fallback: linear scan for test doubles that don't extend Connection
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
     * Uses indexed lookup for O(1) retrieval when the connection is a
     * {@see Connection} instance (which updates indexes on sessionId change).
     * Falls back to linear scan for non-Connection test doubles.
     *
     * @param string $sessionId The session ID to search for
     * @return array<ConnectionInterface> Array of matching connections
     */
    public function findBySessionId(string $sessionId): array
    {
        // Fast path: indexed lookup for real Connection instances
        if (isset($this->connectionsBySessionId[$sessionId])) {
            return array_values($this->connectionsBySessionId[$sessionId]);
        }

        // Fallback: linear scan for test doubles that don't extend Connection
        $found = [];
        foreach ($this->connections as $connection) {
            if ($connection->getSessionId() === $sessionId) {
                $found[] = $connection;
            }
        }
        return $found;
    }

    /**
     * Updates the indexes for a connection after its userId or sessionId changes.
     *
     * Called by the connection itself when setAuthenticated() or setSessionId() is invoked.
     *
     * @param Connection $connection The connection to re-index
     * @param string|null $oldUserId The previous userId (null if not previously set)
     * @param string|null $oldSessionId The previous sessionId (null if not previously set)
     * @return void
     */
    public function updateIndexes(Connection $connection, ?string $oldUserId, ?string $oldSessionId): void
    {
        $connectionId = $connection->getId();
        $newUserId = $connection->getUserId();
        $newSessionId = $connection->getSessionId();

        // Update userId index: remove from old, add to new
        if ($oldUserId !== null && $oldUserId !== $newUserId) {
            unset($this->connectionsByUserId[$oldUserId][$connectionId]);
            if ($this->connectionsByUserId[$oldUserId] === []) {
                unset($this->connectionsByUserId[$oldUserId]);
            }
        }
        if ($newUserId !== null && $newUserId !== $oldUserId) {
            $this->connectionsByUserId[$newUserId] ??= [];
            $this->connectionsByUserId[$newUserId][$connectionId] = $connection;
        }

        // Update sessionId index: remove from old, add to new
        if ($oldSessionId !== null && $oldSessionId !== $newSessionId) {
            unset($this->connectionsBySessionId[$oldSessionId][$connectionId]);
            if ($this->connectionsBySessionId[$oldSessionId] === []) {
                unset($this->connectionsBySessionId[$oldSessionId]);
            }
        }
        if ($newSessionId !== null && $newSessionId !== $oldSessionId) {
            $this->connectionsBySessionId[$newSessionId] ??= [];
            $this->connectionsBySessionId[$newSessionId][$connectionId] = $connection;
        }
    }

    /**
     * Removes connections that have been idle too long.
     *
     * Sends a timeout message to stale connections before closing them.
     * Uses a two-pass approach to avoid race conditions: first identifies
     * stale connections, then closes and removes them after iteration completes.
     *
     * @param int $maxIdleTime Maximum idle time in seconds (default: 300 = 5 minutes)
     * @return void
     */
    public function cleanupStaleConnections(int $maxIdleTime = 300): void
    {
        $now = time();

        // First pass: identify stale connection IDs (avoids modifying array during iteration)
        $staleIds = [];
        foreach ($this->connections as $id => $connection) {
            if ($now - $connection->getLastActivity() > $maxIdleTime) {
                $staleIds[] = $id;
            }
        }

        // Second pass: close and remove stale connections
        foreach ($staleIds as $id) {
            $connection = $this->connections[$id] ?? null;
            if ($connection === null) {
                // Connection was already removed (e.g., client disconnected during cleanup)
                continue;
            }
            $connection->sendMessage('timeout', ['message' => 'Connection timed out']);
            $connection->close();
            $this->remove($id);
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
