<?php

declare(strict_types=1);

namespace Phlix\Server\WebSocket;

/**
 * Handles WebSocket message routing and event dispatching.
 *
 * This class processes incoming WebSocket messages, routes them to
 * registered event handlers, and provides broadcasting capabilities.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description Message handler for routing WebSocket events to registered callbacks.
 * @see Connection For connection representation
 * @see ConnectionPool For connection management
 * @see WebSocketEvents For available event types
 */
class MessageHandler
{
    /** @var MessageHandler|null Singleton instance */
    private static ?MessageHandler $instance = null;

    /** @var array<string, callable> Registered event callbacks */
    private array $callbacks = [];

    /** @var ConnectionPool The connection pool for routing messages */
    private ConnectionPool $connections;

    /** @var callable|null Callback to get current now-playing data */
    private $nowPlayingProvider = null;

    /**
     * Creates a new MessageHandler instance.
     *
     * @param ConnectionPool $connections The connection pool to use
     */
    public function __construct(ConnectionPool $connections)
    {
        $this->connections = $connections;
        self::$instance = $this;
    }

    /**
     * Gets the singleton MessageHandler instance.
     *
     * @return MessageHandler|null The instance or null if not yet created
     */
    public static function getInstance(): ?MessageHandler
    {
        return self::$instance;
    }

    /**
     * Sets the provider callback for dashboard now-playing data.
     *
     * The callback should return an array of now-playing session data.
     *
     * @param callable $provider Callback that returns now-playing array
     * @return void
     */
    public function setNowPlayingProvider(callable $provider): void
    {
        $this->nowPlayingProvider = $provider;
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

        if (!is_array($message) || !isset($message['type']) || !is_string($message['type'])) {
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

        // Handle subscribe_dashboard event
        if ($event === 'subscribe_dashboard') {
            $payloadMap = [];
            if (is_array($payload)) {
                foreach ($payload as $pKey => $pValue) {
                    if (is_string($pKey)) {
                        $payloadMap[$pKey] = $pValue;
                    }
                }
            }
            $this->handleSubscribeDashboard($connection, $payloadMap);
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
        ], JSON_THROW_ON_ERROR);

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
        ], JSON_THROW_ON_ERROR);

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
        ], JSON_THROW_ON_ERROR);

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

    /**
     * Handles the subscribe_dashboard WebSocket event.
     *
     * When a client subscribes to dashboard updates, this sends the current
     * now-playing state immediately, and the client will receive live updates
     * when playback starts/stops.
     *
     * @param Connection $connection The connection that sent the message
     * @param array<string, mixed> $payload Event payload (unused for now)
     * @return void
     */
    private function handleSubscribeDashboard(Connection $connection, array $payload): void
    {
        $nowPlaying = [];

        if ($this->nowPlayingProvider !== null) {
            $nowPlaying = ($this->nowPlayingProvider)();
        }

        $connection->sendMessage(WebSocketEvents::DASHBOARD_NOW_PLAYING, [
            'now_playing' => $nowPlaying,
            'subscribed' => true,
        ]);
    }

    /**
     * Broadcasts current now-playing state to all subscribed dashboard clients.
     *
     * Call this method when playback state changes to notify all
     * subscribed dashboard views of the update.
     *
     * @param array<int, array<string, mixed>> $nowPlaying Current now-playing data
     * @return void
     */
    public function broadcastNowPlaying(array $nowPlaying): void
    {
        $this->broadcast(WebSocketEvents::DASHBOARD_NOW_PLAYING, [
            'now_playing' => $nowPlaying,
        ]);
    }

    /**
     * Re-broadcasts current now-playing state to all dashboard subscribers.
     *
     * Calls the nowPlayingProvider (if set) to get fresh data, then broadcasts
     * to all connected dashboard clients. Use this when playback state changes.
     *
     * @return void
     */
    public function rebroadcastNowPlaying(): void
    {
        $nowPlaying = [];
        if ($this->nowPlayingProvider !== null) {
            $nowPlaying = ($this->nowPlayingProvider)();
        }

        $this->broadcastNowPlaying($nowPlaying);
    }
}
