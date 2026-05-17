<?php

declare(strict_types=1);

namespace Phlex\Server\WebSocket;

/**
 * Handles WebSocket message routing and event dispatching.
 *
 * This class processes incoming WebSocket messages, routes them to
 * registered event handlers, and provides broadcasting capabilities.
 *
 * @author Phlex Media Server Team
 * @version 1.0.0
 * @description Message handler for routing WebSocket events to registered callbacks.
 * @see Connection For connection representation
 * @see ConnectionPool For connection management
 * @see WebSocketEvents For available event types
 */
class MessageHandler
{
    /** @var array<string, callable> Registered event callbacks */
    private array $callbacks = [];

    /** @var ConnectionPool The connection pool for routing messages */
    private ConnectionPool $connections;

    /**
     * Creates a new MessageHandler instance.
     *
     * @param ConnectionPool $connections The connection pool to use
     */
    public function __construct(ConnectionPool $connections)
    {
        $this->connections = $connections;
    }

    /**
     * Registers a callback for a specific event type.
     *
     * @param string $event The event name to listen for
     * @param callable $callback The callback function (Connection, array): void
     * @return void
     *
     * @example
     * ```php
     * $handler->on('playback_start', function($conn, $payload) {
     *     // Handle playback start
     * });
     * ```
     */
    public function on(string $event, callable $callback): void
    {
        $this->callbacks[$event] = $callback;
    }

    /**
     * Registers a wildcard callback that handles all events.
     *
     * @param callable $callback The callback function (Connection, string $event, array $payload): void
     * @return void
     *
     * @example
     * ```php
     * $handler->onAny(function($conn, $event, $payload) {
     *     // Handle any event
     * });
     * ```
     */
    public function onAny(callable $callback): void
    {
        $this->callbacks['*'] = $callback;
    }

    /**
     * Handles an incoming WebSocket message.
     *
     * Parses the JSON message, extracts event type and payload,
     * and dispatches to the appropriate handler.
     *
     * @param Connection $connection The connection that sent the message
     * @param string $data Raw message data (expected JSON)
     * @return void
     *
     * @throws \JsonException If message is not valid JSON
     */
    public function handle(Connection $connection, string $data): void
    {
        $message = json_decode($data, true);

        if (!$message || !isset($message['type'])) {
            $connection->sendMessage('error', ['message' => 'Invalid message format']);
            return;
        }

        $event = $message['type'];
        $payload = $message['data'] ?? [];

        $this->connections->add($connection);

        // Call specific event handler
        if (isset($this->callbacks[$event])) {
            try {
                ($this->callbacks[$event])($connection, $payload);
            } catch (\Throwable $e) {
                $connection->sendMessage('error', [
                    'message' => 'Handler error: ' . $e->getMessage(),
                ]);
            }
        } elseif (isset($this->callbacks['*'])) {
            // Wildcard handler
            ($this->callbacks['*'])($connection, $event, $payload);
        }
    }

    /**
     * Broadcasts a message to all connected clients.
     *
     * @param string $event The event type to broadcast
     * @param array<string, mixed> $data The event data
     * @param array<string> $excludeIds Connection IDs to exclude from broadcast
     * @return void
     *
     * @example
     * ```php
     * $handler->broadcast('notification', ['message' => 'Server updating'], ['conn-1']);
     * ```
     */
    public function broadcast(string $event, array $data, array $excludeIds = []): void
    {
        $message = json_encode([
            'type' => $event,
            'data' => $data,
            'timestamp' => time(),
        ]);

        foreach ($this->connections->all() as $connection) {
            if (!in_array($connection->getId(), $excludeIds, true)) {
                $connection->send($message);
            }
        }
    }

    /**
     * Sends a message to all connections for a specific user.
     *
     * A user may have multiple connections across devices.
     *
     * @param string $userId The user ID to send to
     * @param string $event The event type
     * @param array<string, mixed> $data The event data
     * @return void
     */
    public function sendToUser(string $userId, string $event, array $data): void
    {
        $message = json_encode([
            'type' => $event,
            'data' => $data,
            'timestamp' => time(),
        ]);

        foreach ($this->connections->all() as $connection) {
            if ($connection->getUserId() === $userId) {
                $connection->send($message);
            }
        }
    }

    /**
     * Sends a message to all connections in a specific session.
     *
     * @param string $sessionId The session ID to send to
     * @param string $event The event type
     * @param array<string, mixed> $data The event data
     * @return void
     */
    public function sendToSession(string $sessionId, string $event, array $data): void
    {
        $message = json_encode([
            'type' => $event,
            'data' => $data,
            'timestamp' => time(),
        ]);

        foreach ($this->connections->all() as $connection) {
            if ($connection->getSessionId() === $sessionId) {
                $connection->send($message);
            }
        }
    }

    /**
     * Gets the total number of active connections.
     *
     * @return int Connection count
     */
    public function getConnectionCount(): int
    {
        return $this->connections->count();
    }

    /**
     * Gets the number of authenticated connections.
     *
     * @return int Authenticated connection count
     */
    public function getAuthenticatedCount(): int
    {
        $count = 0;
        foreach ($this->connections->all() as $connection) {
            if ($connection->isAuthenticated()) {
                $count++;
            }
        }
        return $count;
    }
}
