<?php

declare(strict_types=1);

namespace Phlix\Server\WebSocket;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;
use Phlix\Session\SyncPlay\SyncPlayManager;

/**
 * WebSocket server implementation for real-time communication.
 *
 * This class manages the Workerman-based WebSocket server, handling
 * client connections, message routing, and connection lifecycle events.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description WebSocket server using Workerman for real-time bidirectional communication.
 * @see Connection For WebSocket connection representation
 * @see MessageHandler For message routing
 * @see ConnectionPool For connection management
 */
class WebSocketServer
{
    /** @var Worker The underlying Workerman worker instance */
    private Worker $worker;

    /** @var MessageHandler Handles incoming WebSocket messages */
    private MessageHandler $handler;

    /** @var ConnectionPool Manages active WebSocket connections */
    private ConnectionPool $connections;

    /** @var SyncPlayManager|null SyncPlay manager for group state */
    private ?SyncPlayManager $syncPlayManager = null;

    /** @var array<string, mixed> Server configuration */
    private array $config;

    /**
     * Creates a new WebSocket server instance.
     *
     * @param array<string, mixed> $config Server configuration with 'host' and 'port' keys
     * @param MessageHandler|null $handler Optional message handler (for SP1 singletons)
     *
     * @example
     * ```php
     * $server = new WebSocketServer([
     *     'host' => '0.0.0.0',
     *     'port' => 8097,
     * ]);
     * $server->run();
     * ```
     */
    public function __construct(array $config, ?MessageHandler $handler = null)
    {
        $this->config = $config;
        $this->connections = ConnectionPool::getInstance();
        $this->handler = $handler ?? new MessageHandler($this->connections);

        $host = $config['host'] ?? '0.0.0.0';
        $port = $config['port'] ?? 8097;
        $hostStr = is_string($host) ? $host : '0.0.0.0';
        $portStr = is_numeric($port) ? (string)(int)$port : '8097';

        $this->worker = new Worker("websocket://{$hostStr}:{$portStr}");
        $this->worker->onWorkerStart = [$this, 'onStart'];
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
        $this->worker->onError = [$this, 'onError'];
    }

    /**
     * Called when the worker process starts.
     *
     * Initializes logging and starts the connection cleanup timer.
     *
     * @return void
     */
    public function onStart(): void
    {
        $host = $this->config['host'] ?? '0.0.0.0';
        $port = $this->config['port'] ?? 8097;
        $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
        $logger->info('WebSocket server started', [
            'host' => is_string($host) ? $host : '0.0.0.0',
            'port' => is_numeric($port) ? (int)$port : 8097,
        ]);

        $staleConnectionTimeoutRaw = $this->config['stale_connection_timeout'] ?? 300;
        $staleConnectionTimeout = is_numeric($staleConnectionTimeoutRaw) ? (int) $staleConnectionTimeoutRaw : 300;

        $staleGroupTimeoutRaw = $this->config['stale_group_timeout'] ?? 3600;
        $staleGroupTimeout = is_numeric($staleGroupTimeoutRaw) ? (int) $staleGroupTimeoutRaw : 3600;

        // Start cleanup timer for stale connections (every 60 seconds)
        if (function_exists('Workerman\Timer')) {
            \Workerman\Timer::add(60, function () use ($staleConnectionTimeout): void {
                $this->connections->cleanupStaleConnections($staleConnectionTimeout);
            });

            // Start cleanup timer for stale SyncPlay groups (every 5 minutes)
            $syncPlayManager = $this->syncPlayManager;
            if ($syncPlayManager !== null) {
                \Workerman\Timer::add(300, function () use ($syncPlayManager, $staleGroupTimeout): void {
                    $removed = $syncPlayManager->cleanupStaleGroups($staleGroupTimeout);
                    if ($removed > 0) {
                        $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
                        $logger->info('Cleaned up stale SyncPlay groups', [
                            'removed' => $removed,
                        ]);
                    }
                });
            }
        }
    }

    /**
     * Called when a new client connects.
     *
     * Creates a Connection wrapper, adds it to the pool, and sends
     * a welcome message with the connection ID.
     *
     * @param TcpConnection $connection The Workerman TCP connection
     * @return void
     */
    public function onConnect(TcpConnection $connection): void
    {
        $wsConnection = new Connection($connection);
        $this->connections->add($wsConnection);

        $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
        $logger->debug('New WebSocket connection', [
            'connection_id' => $wsConnection->getId(),
        ]);

        // Send welcome message
        $wsConnection->sendMessage('connected', [
            'connection_id' => $wsConnection->getId(),
            'timestamp' => time(),
        ]);
    }

    /**
     * Called when a message is received from a client.
     *
     * @param TcpConnection $connection The Workerman TCP connection
     * @param string $data The raw message data
     * @return void
     */
    public function onMessage(TcpConnection $connection, string $data): void
    {
        $wsConnection = $this->findConnection($connection);

        if (!$wsConnection) {
            return;
        }

        $this->handler->handle($wsConnection, $data);
    }

    /**
     * Called when a client disconnects.
     *
     * Removes the connection from the pool and broadcasts disconnection
     * to other clients if the user was authenticated.
     *
     * @param TcpConnection $connection The Workerman TCP connection
     * @return void
     */
    public function onClose(TcpConnection $connection): void
    {
        $wsConnection = $this->findConnection($connection);

        if ($wsConnection) {
            $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
            $logger->info('WebSocket connection closed', [
                'connection_id' => $wsConnection->getId(),
                'user_id' => $wsConnection->getUserId(),
                'authenticated' => $wsConnection->isAuthenticated(),
            ]);

            // Notify SyncPlay manager to vacate member from group
            if ($this->syncPlayManager !== null) {
                $this->syncPlayManager->onConnectionClose($wsConnection->getId());
            }

            $this->connections->remove($wsConnection->getId());

            // Broadcast disconnection if authenticated
            if ($wsConnection->isAuthenticated()) {
                $this->handler->broadcast('client_disconnected', [
                    'connection_id' => $wsConnection->getId(),
                    'user_id' => $wsConnection->getUserId(),
                ], [$wsConnection->getId()]);
            }
        }
    }

    /**
     * Called when a connection error occurs.
     *
     * @param TcpConnection $connection The Workerman TCP connection
     * @param int $code Error code
     * @param string $reason Error reason description
     * @return void
     */
    public function onError(TcpConnection $connection, int $code, string $reason): void
    {
        $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
        $logger->error('WebSocket error', [
            'code' => $code,
            'reason' => $reason,
        ]);
    }

    /**
     * Finds the Connection wrapper for a TcpConnection.
     *
     * @param TcpConnection $connection The Workerman TCP connection
     * @return Connection|null The Connection wrapper or null if not found
     */
    private function findConnection(TcpConnection $connection): ?Connection
    {
        $objectId = spl_object_id($connection);
        foreach ($this->connections->all() as $wsConnection) {
            if (!$wsConnection instanceof Connection) {
                continue;
            }
            if (spl_object_id($wsConnection->getConnection()) === $objectId) {
                return $wsConnection;
            }
        }
        return null;
    }

    /**
     * Gets the message handler for this server.
     *
     * @return MessageHandler The message handler instance
     */
    public function getHandler(): MessageHandler
    {
        return $this->handler;
    }

    /**
     * Sets the SyncPlay manager for group state management.
     *
     * This must be called before onStart() to enable the stale groups
     * cleanup timer.
     *
     * @param \Phlix\Session\SyncPlay\SyncPlayManager $manager The SyncPlay manager
     * @return void
     */
    public function setSyncPlayManager(\Phlix\Session\SyncPlay\SyncPlayManager $manager): void
    {
        $this->syncPlayManager = $manager;
    }

    /**
     * Starts the WebSocket server.
     *
     * This method blocks as it runs the Workerman event loop.
     *
     * @return void
     */
    public function run(): void
    {
        Worker::runAll();
    }
}
