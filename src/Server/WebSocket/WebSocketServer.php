<?php

/**
 * Phlix media server component: WebSocket.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\WebSocket;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Phlix\Auth\JwtHandler;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Stats\Metrics\MetricsCollector;

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

    /** @var MetricsCollector|null Metrics collector for connection tracking */
    private ?MetricsCollector $metrics = null;

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

        // S-F28: application-level liveness. Workerman 5.x does not expose a
        // Worker-level pingInterval/pingNotResponseLimit (that lived in
        // GatewayWorker), so we drive server-side pings from a timer in onStart()
        // and reap connections whose peers stop answering. Binding the pong
        // callback here lets the WS protocol layer notify us when a peer replies.
        // onWebSocketPong is a dynamic Workerman worker callback (Worker is
        // #[AllowDynamicProperties]); the Websocket protocol reads it as
        // $connection->worker->onWebSocketPong.
        // @phpstan-ignore-next-line property.notFound
        $this->worker->onWebSocketPong = [$this, 'onWebSocketPong'];
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
        if (class_exists(\Workerman\Timer::class)) {
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

            // S-F28: application-level ping timer. Each tick pings every live
            // connection and reaps any whose peer has not answered within the
            // non-response limit — this detects half-open sockets that the
            // receive-side-only stale-connection reaper cannot see.
            $pingIntervalRaw = $this->config['ping_interval'] ?? 30;
            $pingInterval = is_numeric($pingIntervalRaw) ? (int) $pingIntervalRaw : 30;
            if ($pingInterval < 1) {
                $pingInterval = 30;
            }

            $pingLimitRaw = $this->config['ping_not_response_limit'] ?? 2;
            $pingLimit = is_numeric($pingLimitRaw) ? (int) $pingLimitRaw : 2;
            if ($pingLimit < 1) {
                $pingLimit = 2;
            }

            \Workerman\Timer::add($pingInterval, function () use ($pingLimit): void {
                $this->pingConnections($pingLimit);
            });
        }
    }

    /**
     * Ping every live connection and reap those whose peer has stopped answering.
     *
     * Armed on a periodic timer by {@see onStart()}. A connection that has not
     * answered {@see $notRespondedLimit} consecutive pings is treated as a
     * half-open socket and closed + removed from the pool; every other
     * connection is pinged (incrementing its outstanding-ping count until a pong
     * resets it via {@see onWebSocketPong()}).
     *
     * @param int $notRespondedLimit Outstanding-ping count at which a connection
     *                               is considered dead and reaped.
     * @return void
     */
    public function pingConnections(int $notRespondedLimit): void
    {
        foreach ($this->connections->all() as $wsConnection) {
            if (!$wsConnection instanceof Connection) {
                continue;
            }

            if ($wsConnection->getPendingPings() >= $notRespondedLimit) {
                // Half-open socket: pings went unanswered past the limit. Reap it.
                $wsConnection->close();
                $this->connections->remove($wsConnection->getId());
                continue;
            }

            $wsConnection->ping();
        }
    }

    /**
     * Called by the WebSocket protocol layer when a peer answers a ping.
     *
     * Clears the connection's outstanding-ping count so the ping timer does not
     * reap a peer that is alive and responding.
     *
     * @param TcpConnection $connection The Workerman TCP connection that ponged.
     * @param string        $data       The pong payload (echo of the ping data).
     * @return void
     */
    public function onWebSocketPong(TcpConnection $connection, string $data): void
    {
        $wsConnection = $this->findConnection($connection);
        if ($wsConnection !== null) {
            $wsConnection->recordPong();
        }
    }

    /**
     * Called when a new client connects.
     *
     * Validates JWT token from query string if present, then creates
     * a Connection wrapper, adds it to the pool, and sends a welcome
     * message with the connection ID.
     *
     * @param TcpConnection $connection The Workerman TCP connection
     * @return void
     */
    public function onConnect(TcpConnection $connection): void
    {
        $token = $_GET['token'] ?? null;
        $userId = null;

        if (is_string($token) && $token !== '') {
            $jwtSecret = $this->config['jwt_secret'] ?? null;
            if (is_string($jwtSecret) && $jwtSecret !== '') {
                $jwtHandler = new JwtHandler($jwtSecret);
                $payload = $jwtHandler->validateToken($token);
                if ($payload === null) {
                    $connection->close();
                    return;
                }
                $sub = $payload['sub'] ?? null;
                $userId = is_string($sub) ? $sub : null;
            }
        }

        $wsConnection = new Connection($connection);

        if (is_string($userId)) {
            $wsConnection->setAuthenticated(true, $userId);
        }

        $this->connections->add($wsConnection);

        $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
        $logger->debug('New WebSocket connection', [
            'connection_id' => $wsConnection->getId(),
            'authenticated' => $wsConnection->isAuthenticated(),
            'user_id' => $wsConnection->getUserId(),
        ]);

        $wsConnection->sendMessage('connected', [
            'connection_id' => $wsConnection->getId(),
            'timestamp' => time(),
        ]);

        // S2 metrics: track the new connection if metrics is enabled.
        if ($this->metrics !== null) {
            $this->metrics->openConnection(
                $wsConnection->getId(),
                'websocket',
                $wsConnection->getUserId(),
                $connection->getRemoteIp(),
                null,
                null,
            );
        }
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

            // S2 metrics: record the FINAL cumulative byte counts. We deliberately
            // do NOT closeConnection() here — that unset the registry row before the
            // next flush could persist it, so a WebSocket's bytes never reached
            // metrics_connections. Leaving the final touch in place lets the coming
            // flush write the real totals; the flush service then TTL-prunes the now
            // idle row (its last_seen_at ages past connection_ttl) from the registry
            // AND the table, so it drops out of the live panel ~ttl seconds after
            // close without leaking worker memory.
            if ($this->metrics !== null) {
                $this->metrics->touchConnection(
                    $wsConnection->getId(),
                    $connection->bytesRead,
                    $connection->bytesWritten,
                );
            }
        }
    }

    /**
     * Push the current cumulative byte counts of every live WebSocket connection
     * into the metrics registry.
     *
     * Armed on a periodic timer by start.php's WS worker (between flushes) so the
     * admin live-connection panel reflects real per-connection throughput for the
     * whole connection lifetime — previously the only touch was in
     * {@see onClose()}, so an open connection showed a permanent zero row and its
     * bytes were then deleted before any flush could persist them. No-op when the
     * collector is absent; the collector itself no-ops when metrics is disabled.
     *
     * @return void
     */
    public function touchActiveConnections(): void
    {
        if ($this->metrics === null) {
            return;
        }
        foreach ($this->connections->all() as $wsConnection) {
            if (!$wsConnection instanceof Connection) {
                continue;
            }
            $tcp = $wsConnection->getConnection();
            $this->metrics->touchConnection(
                $wsConnection->getId(),
                $tcp->bytesRead,
                $tcp->bytesWritten,
            );
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
        $wsConnection = $this->connections->getByObjectId($connection);

        return $wsConnection instanceof Connection ? $wsConnection : null;
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
     * Sets the metrics collector for connection tracking.
     *
     * @param MetricsCollector $metrics The metrics collector
     * @return void
     */
    public function setMetricsCollector(MetricsCollector $metrics): void
    {
        $this->metrics = $metrics;
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
