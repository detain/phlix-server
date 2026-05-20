<?php

declare(strict_types=1);

namespace Phlix\Server\WebSocket;

use Workerman\Connection\TcpConnection;

/**
 * Wraps a Workerman TcpConnection with additional Phlix-specific functionality.
 *
 * This class provides a higher-level interface for WebSocket connections,
 * including session data management, authentication state, and activity tracking.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description Connection wrapper with session data, authentication, and activity tracking.
 * @see ConnectionInterface For the connection contract
 * @see ConnectionPool For connection management
 */
class Connection implements ConnectionInterface
{
    /** @var TcpConnection The underlying Workerman connection */
    private TcpConnection $connection;

    /** @var string Unique connection identifier */
    private string $id;

    /** @var array<string, mixed> Session-scoped data storage */
    private array $sessionData = [];

    /** @var bool Whether this connection is authenticated */
    private bool $authenticated = false;

    /** @var string|null The authenticated user ID */
    private ?string $userId = null;

    /** @var string|null The current session ID */
    private ?string $sessionId = null;

    /** @var int Unix timestamp of last activity */
    private int $lastActivity;

    /**
     * Creates a new Connection wrapper.
     *
     * @param TcpConnection $connection The underlying Workerman TCP connection
     */
    public function __construct(TcpConnection $connection)
    {
        $this->connection = $connection;
        $this->id = spl_object_id($connection) . '-' . uniqid();
        $this->lastActivity = time();
    }

    /**
     * Gets the unique connection identifier.
     *
     * @return string Unique ID in format "{objectId}-{uniqueId}"
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Sends data to the connected client.
     *
     * @param string|array<string, mixed> $data Data to send (arrays are JSON encoded)
     * @return void
     *
     * @throws \JsonException If array data cannot be encoded
     */
    public function send(string|array $data): void
    {
        if (is_array($data)) {
            $data = json_encode($data, JSON_THROW_ON_ERROR);
        }
        $this->connection->send($data);
        $this->updateActivity();
    }

    /**
     * Sends a typed message event to the client.
     *
     * @param string $type The message type/event name
     * @param array<string, mixed> $data The event payload data
     * @return void
     */
    public function sendMessage(string $type, array $data = []): void
    {
        $this->send([
            'type' => $type,
            'data' => $data,
            'timestamp' => time(),
        ]);
    }

    /**
     * Closes the connection.
     *
     * @return void
     */
    public function close(): void
    {
        $this->connection->close();
    }

    /**
     * Updates the last activity timestamp to current time.
     *
     * @return void
     */
    public function updateActivity(): void
    {
        $this->lastActivity = time();
    }

    /**
     * Gets the last activity timestamp.
     *
     * @return int Unix timestamp of last activity
     */
    public function getLastActivity(): int
    {
        return $this->lastActivity;
    }

    /**
     * Checks if this connection is authenticated.
     *
     * @return bool True if authenticated
     */
    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    /**
     * Sets the authentication state.
     *
     * @param bool $authenticated Whether the connection is authenticated
     * @param string|null $userId The user ID if authenticated
     * @return void
     */
    public function setAuthenticated(bool $authenticated, ?string $userId = null): void
    {
        $this->authenticated = $authenticated;
        $this->userId = $userId;
    }

    /**
     * Gets the authenticated user ID.
     *
     * @return string|null User ID or null if not authenticated
     */
    public function getUserId(): ?string
    {
        return $this->userId;
    }

    /**
     * Sets the current session ID.
     *
     * @param string|null $sessionId The session ID
     * @return void
     */
    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * Gets the current session ID.
     *
     * @return string|null Session ID or null if not in a session
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Stores a value in the session data.
     *
     * @param string $key The data key
     * @param mixed $value The value to store
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->sessionData[$key] = $value;
    }

    /**
     * Retrieves a value from session data.
     *
     * @param string $key The data key
     * @param mixed $default Default value if key not found
     * @return mixed The stored value or default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->sessionData[$key] ?? $default;
    }

    /**
     * Checks if a key exists in session data.
     *
     * @param string $key The data key
     * @return bool True if key exists
     */
    public function has(string $key): bool
    {
        return isset($this->sessionData[$key]);
    }

    /**
     * Removes a key from session data.
     *
     * @param string $key The data key
     * @return void
     */
    public function remove(string $key): void
    {
        unset($this->sessionData[$key]);
    }

    /**
     * Gets all session data as an array.
     *
     * @return array<string, mixed> All session data
     */
    public function getAll(): array
    {
        return $this->sessionData;
    }

    /**
     * Gets the underlying Workerman connection.
     *
     * @return TcpConnection The raw Workerman connection
     */
    public function getConnection(): TcpConnection
    {
        return $this->connection;
    }
}
