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
     * Supports two message formats:
     * - Flat canonical (SyncPlay): {type, protocol_version, timestamp, ...payload}
     * - Deprecated Tizen envelope (dashboard): {type, data: {...}, timestamp}
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

        // SV-4.7: authentication gate. Privileged events (SyncPlay control,
        // dashboard subscription, playback/session updates — see
        // WebSocketEvents::isPrivileged) may only be dispatched for an
        // authenticated connection. An unauthenticated client attempting a
        // privileged event is rejected with a NOT_AUTHENTICATED-shaped error and
        // the message is NOT dispatched. Public events (ping/pong/auth_request/
        // connected) always pass. This is the coarse connection-level gate; the
        // per-event SyncPlay handlers keep their own finer-grained checks.
        if (WebSocketEvents::isPrivileged($event) && !$connection->isAuthenticated()) {
            $connection->sendFlat(\Phlix\Session\SyncPlay\Messages::TYPE_ERROR, [
                'error_code' => 'NOT_AUTHENTICATED',
                'message' => 'Authentication required',
            ]);
            return;
        }

        // Handle subscribe_dashboard event - keep BC with deprecated {type,data} envelope
        if ($event === WebSocketEvents::SUBSCRIBE_DASHBOARD) {
            $payload = $message['data'] ?? [];
            $this->connections->add($connection);
            $payloadMap = [];
            if (is_array($payload)) {
                foreach ($payload as $pKey => $pValue) {
                    if (is_string($pKey)) {
                        $payloadMap[$pKey] = $pValue;
                    }
                }
            }
            $this->handleSubscribeDashboard($connection, $payloadMap);
            return;
        }

        // For flat canonical messages (SyncPlay), use the whole message as payload.
        // Tolerant unwrap for deprecated {type,data} envelope for BC.
        // Syncplay messages have protocol_version but no 'data' key.
        $hasDataKey = array_key_exists('data', $message);
        $payload = $hasDataKey
            ? $message['data']
            : $message;

        // Validate protocol_version for flat canonical messages
        if (!$hasDataKey && isset($message['protocol_version'])) {
            $protocolVersion = $message['protocol_version'];
            if (!is_int($protocolVersion) || $protocolVersion > \Phlix\Session\SyncPlay\Messages::PROTOCOL_VERSION) {
                $connection->sendFlat(\Phlix\Session\SyncPlay\Messages::TYPE_ERROR, [
                    'error_code' => 'PROTOCOL_VERSION_MISMATCH',
                    'message' => 'Unsupported protocol version',
                ]);
                return;
            }
        }

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
     * Respects backpressure by skipping connections with full send buffers,
     * preventing a slow client from blocking the broadcast loop.
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
                // Skip connections with full send buffers (backpressure)
                if (!$this->isConnectionBufferFull($connection)) {
                    $connection->send($message);
                }
            }
        }
    }

    /**
     * Sends a message to all connections for a specific user.
     *
     * A user may have multiple connections across devices.
     * Uses indexed lookup for O(1) user connection retrieval.
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

        foreach ($this->connections->findByUserId($userId) as $connection) {
            // Skip connections with full send buffers (backpressure)
            if (!$this->isConnectionBufferFull($connection)) {
                $connection->send($message);
            }
        }
    }

    /**
     * Sends a message to all connections in a specific session.
     * Uses indexed lookup for O(1) session connection retrieval.
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

        foreach ($this->connections->findBySessionId($sessionId) as $connection) {
            // Skip connections with full send buffers (backpressure)
            if (!$this->isConnectionBufferFull($connection)) {
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
     * Checks if a connection's send buffer is full (backpressure condition).
     *
     * When a connection's send buffer reaches maxSendBufferSize, Workerman
     * triggers the onBufferFull callback which sets the bufferFull flag.
     * We check this flag to skip sending to slow clients during broadcast.
     *
     * @param ConnectionInterface $connection The connection to check
     * @return bool True if the connection's buffer is full
     */
    private function isConnectionBufferFull(ConnectionInterface $connection): bool
    {
        if ($connection instanceof Connection) {
            return $connection->isBufferFull();
        }

        return false;
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
