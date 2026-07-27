<?php

/**
 * Phlix media server component: WebSocket.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\WebSocket;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Ws;

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

    /** @var bool Whether the connection's send buffer is full (backpressure) */
    private bool $bufferFull = false;

    /**
     * Number of application-level pings sent since the last pong was received.
     *
     * The WebSocket worker's ping timer increments this each time it pings the
     * connection; {@see recordPong()} resets it to zero when the peer answers.
     * When it reaches the worker's non-response limit the connection is treated
     * as a half-open socket and reaped — receive-side idle tracking alone cannot
     * detect a peer that has silently vanished (S-F28).
     *
     * @var int
     */
    private int $pendingPings = 0;

    /**
     * WebSocket PING control frame header byte: FIN bit (0x80) + opcode 0x9.
     *
     * Sending an empty payload with this frame type asks the peer for a pong.
     */
    private const WS_PING_FRAME = "\x89";

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

        // Register backpressure callbacks to track buffer full state
        $connection->onBufferFull = function (TcpConnection $conn): void {
            $this->bufferFull = true;
        };
        $connection->onBufferDrain = function (TcpConnection $conn): void {
            $this->bufferFull = false;
        };
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
     * @return bool True if the data was sent successfully, false if the send buffer is full
     *
     * @throws \JsonException If array data cannot be encoded
     */
    public function send(string|array $data): bool
    {
        if (is_array($data)) {
            $data = json_encode($data, JSON_THROW_ON_ERROR);
        }

        $result = $this->connection->send($data);
        $this->updateActivity();

        // send() returns false when the send buffer is full (backpressure)
        return $result !== false;
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
     * Sends a flat canonical message without wrapping payload under 'data'.
     *
     * Used for SyncPlay messages which use the flat canonical wire format:
     * {type, ...payload, timestamp} instead of {type, data: {...}, timestamp}.
     *
     * @param string $type The message type/event name
     * @param array<string, mixed> $payload The flat event payload (sent directly, not under 'data')
     * @return void
     */
    public function sendFlat(string $type, array $payload): void
    {
        $this->send(array_merge(
            ['type' => $type],
            $payload,
            ['timestamp' => time()]
        ));
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
     * Checks if this connection's send buffer is full (backpressure condition).
     *
     * When true, the connection cannot accept more data without blocking.
     * This is set via Workerman's onBufferFull callback.
     *
     * @return bool True if the send buffer is full
     */
    public function isBufferFull(): bool
    {
        return $this->bufferFull;
    }

    /**
     * Sends an application-level WebSocket ping control frame to the peer and
     * records that a ping is now outstanding.
     *
     * The peer's pong is delivered to the worker's {@see WebSocketServer::onWebSocketPong()}
     * handler, which calls {@see recordPong()} to clear the outstanding count.
     * The frame type is restored immediately so subsequent data sends are not
     * emitted as ping frames.
     *
     * @return void
     *
     * @psalm-suppress UndefinedPropertyAssignment `websocketType` is a genuine
     *   Workerman dynamic property, not a typo: the framework sets and restores
     *   it exactly this way in vendor/workerman/workerman/src/Protocols/Websocket.php
     *   (lines 188-200). TcpConnection simply does not declare it, so no static
     *   analyser can see it.
     */
    public function ping(): void
    {
        $previousType = $this->connection->websocketType ?? Ws::BINARY_TYPE_BLOB;
        $this->connection->websocketType = self::WS_PING_FRAME;
        $this->connection->send('');
        $this->connection->websocketType = $previousType;
        $this->pendingPings++;
    }

    /**
     * Records that the peer answered a ping, clearing the outstanding count and
     * refreshing the activity timestamp.
     *
     * @return void
     */
    public function recordPong(): void
    {
        $this->pendingPings = 0;
        $this->updateActivity();
    }

    /**
     * Gets the number of pings sent since the last pong was received.
     *
     * @return int Outstanding (unanswered) ping count
     */
    public function getPendingPings(): int
    {
        return $this->pendingPings;
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
        $oldUserId = $this->userId;
        $this->authenticated = $authenticated;
        $this->userId = $userId;

        // Update the connection pool indexes if userId changed
        if ($oldUserId !== $userId) {
            ConnectionPool::getInstance()->updateIndexes($this, $oldUserId, null);
        }
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
        $oldSessionId = $this->sessionId;
        $this->sessionId = $sessionId;

        // Update the connection pool indexes if sessionId changed
        if ($oldSessionId !== $sessionId) {
            ConnectionPool::getInstance()->updateIndexes($this, null, $oldSessionId);
        }
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
