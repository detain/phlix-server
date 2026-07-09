<?php

/**
 * Phlix media server component: SyncPlay WebSocket Worker.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\WebSocket\Workers;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Server\WebSocket\WebSocketServer;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;

/**
 * SyncPlay WebSocket Worker - Singleton worker for SyncPlay real-time functionality.
 *
 * This worker manages synchronized playback sessions where multiple clients can
 * watch content together remotely. Only ONE instance runs per server.
 *
 * ## Singleton Pattern
 *
 * Uses static::$instance to ensure only ONE worker runs per server process.
 * This prevents duplicate timers, memory leaks, and race conditions.
 *
 * ## Timer Management
 *
 * All timers are tracked and properly cleaned up on worker stop via onWorkerStop().
 * This prevents timer leaks and ensures clean shutdown.
 *
 * @author Phlix Development Team
 * @copyright 2024 Phlix Media Server
 * @license Proprietary
 *
 * @see SyncPlayManager For group state management
 * @see SyncPlayRoom For room broadcasting
 * @see Protocol For binary frame encoding/decoding
 */
class SyncPlayWorker
{
    /**
     * Singleton instance - ensures only ONE worker runs per server.
     *
     * @var SyncPlayWorker|null
     */
    private static ?SyncPlayWorker $instance = null;

    /**
     * Whether the worker is currently running.
     *
     * Used to prevent double-start and ensure clean state during shutdown.
     *
     * @var bool
     */
    private bool $running = false;

    /**
     * The underlying Workerman worker instance.
     *
     * @var Worker|null
     */
    private ?Worker $worker = null;

    /**
     * The WebSocket server handling connections.
     *
     * @var WebSocketServer|null
     */
    private ?WebSocketServer $server = null;

    /**
     * The SyncPlay manager for group state.
     *
     * @var SyncPlayManager|null
     */
    private ?SyncPlayManager $syncPlayManager = null;

    /**
     * The message handler for WebSocket events.
     *
     * @var MessageHandler|null
     */
    private ?MessageHandler $messageHandler = null;

    /**
     * Active timer IDs for cleanup on stop.
     *
     * @var array<int>
     */
    private array $timerIds = [];

    /**
     * Server configuration.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Get the singleton SyncPlayWorker instance.
     *
     * @return SyncPlayWorker The singleton instance
     *
     * @throws \RuntimeException If worker has not been created via create()
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException(
                'SyncPlayWorker has not been created. Call create() first.'
            );
        }

        return self::$instance;
    }

    /**
     * Check if a worker instance exists.
     *
     * @return bool True if instance exists
     */
    public static function hasInstance(): bool
    {
        return self::$instance !== null;
    }

    /**
     * Create and configure the singleton SyncPlayWorker instance.
     *
     * This must be called only once per process. Subsequent calls will return
     * the existing instance.
     *
     * @param array<string, mixed> $config Server configuration with 'host', 'port', 'jwt_secret'
     * @return SyncPlayWorker The singleton instance
     *
     * @example
     * ```php
     * $worker = SyncPlayWorker::create([
     *     'host' => '0.0.0.0',
     *     'port' => 8098,
     *     'jwt_secret' => 'your-secret-key',
     * ]);
     * ```
     */
    public static function create(array $config): self
    {
        if (self::$instance !== null) {
            $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
            $logger->info('SyncPlayWorker instance already exists, returning existing instance');
            return self::$instance;
        }

        self::$instance = new self($config);
        return self::$instance;
    }

    /**
     * Private constructor - use create() to instantiate.
     *
     * @param array<string, mixed> $config Server configuration
     */
    private function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Destructor - ensures clean shutdown.
     */
    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Start the worker and run the Workerman event loop.
     *
     * @return void
     *
     * @throws \RuntimeException If worker is already running
     */
    public function run(): void
    {
        if ($this->running) {
            throw new \RuntimeException('SyncPlayWorker is already running');
        }

        if (self::$instance !== $this) {
            throw new \RuntimeException(
                'Another SyncPlayWorker instance is already running'
            );
        }

        $this->running = true;

        $host = $this->config['host'] ?? '0.0.0.0';
        $port = $this->config['port'] ?? 8098;
        $hostStr = is_string($host) ? $host : '0.0.0.0';
        $portStr = is_numeric($port) ? (string)(int)$port : '8098';

        $this->worker = new Worker("websocket://{$hostStr}:{$portStr}");
        $this->worker->onWorkerStart = [$this, 'onStart'];
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
        $this->worker->onError = [$this, 'onError'];
        $this->worker->onWorkerStop = [$this, 'onWorkerStop'];

        $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
        $logger->info('Starting SyncPlayWorker', [
            'host' => $hostStr,
            'port' => $portStr,
        ]);

        Worker::runAll();
    }

    /**
     * Called when the worker process starts.
     *
     * Initializes the SyncPlayManager, message handler, and timers.
     *
     * @return void
     */
    public function onStart(): void
    {
        $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
        $logger->info('SyncPlayWorker started', [
            'pid' => getmypid(),
        ]);

        // Initialize connection pool
        ConnectionPool::getInstance();

        // Initialize message handler
        $this->messageHandler = new MessageHandler(ConnectionPool::getInstance());

        // Initialize SyncPlay manager
        $this->syncPlayManager = new SyncPlayManager();
        $this->syncPlayManager->initialize($this->messageHandler);

        // Create WebSocket server for this worker
        $this->server = new WebSocketServer($this->config, $this->messageHandler);
        $this->server->setSyncPlayManager($this->syncPlayManager);

        // Start cleanup timer for stale connections (every 60 seconds)
        $staleConnectionTimeout = $this->config['stale_connection_timeout'] ?? 300;
        $staleConnectionTimeout = is_numeric($staleConnectionTimeout) ? (int)$staleConnectionTimeout : 300;

        $timerId = Timer::add(60, function () use ($staleConnectionTimeout): void {
            ConnectionPool::getInstance()->cleanupStaleConnections($staleConnectionTimeout);
        });
        $this->timerIds[] = $timerId;

        // Start cleanup timer for stale SyncPlay groups (every 5 minutes)
        $staleGroupTimeout = $this->config['stale_group_timeout'] ?? 3600;
        $staleGroupTimeout = is_numeric($staleGroupTimeout) ? (int)$staleGroupTimeout : 3600;

        $timerId = Timer::add(300, function () use ($staleGroupTimeout, $logger): void {
            if ($this->syncPlayManager !== null) {
                $removed = $this->syncPlayManager->cleanupStaleGroups($staleGroupTimeout);
                if ($removed > 0) {
                    $logger->info('Cleaned up stale SyncPlay groups', [
                        'removed' => $removed,
                    ]);
                }
            }
        });
        $this->timerIds[] = $timerId;

        $logger->info('SyncPlayWorker timers initialized', [
            'timer_count' => count($this->timerIds),
        ]);
    }

    /**
     * Called when a new client connects.
     *
     * @param TcpConnection $connection The Workerman TCP connection
     * @return void
     */
    public function onConnect(TcpConnection $connection): void
    {
        if ($this->server !== null) {
            $this->server->onConnect($connection);
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
        if ($this->server !== null) {
            $this->server->onMessage($connection, $data);
        }
    }

    /**
     * Called when a client disconnects.
     *
     * @param TcpConnection $connection The Workerman TCP connection
     * @return void
     */
    public function onClose(TcpConnection $connection): void
    {
        if ($this->server !== null) {
            $this->server->onClose($connection);
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
        $logger->error('SyncPlayWorker connection error', [
            'code' => $code,
            'reason' => $reason,
        ]);
    }

    /**
     * Called when the worker process stops.
     *
     * Ensures all timers are removed and cleanup is performed.
     * Uses the $this->running flag to ensure cleanup only happens once.
     *
     * @return void
     */
    public function onWorkerStop(): void
    {
        // Guard: only run cleanup once
        if (!$this->running) {
            return;
        }

        $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
        $logger->info('SyncPlayWorker stopping', [
            'pid' => getmypid(),
            'timer_count' => count($this->timerIds),
        ]);

        // Remove all timers
        foreach ($this->timerIds as $timerId) {
            Timer::del($timerId);
        }
        $this->timerIds = [];

        // Clear connection pool
        ConnectionPool::getInstance()->clear();

        $this->running = false;

        $logger->info('SyncPlayWorker stopped cleanly');
    }

    /**
     * Stop the worker gracefully.
     *
     * @return void
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        // Remove all timers
        foreach ($this->timerIds as $timerId) {
            Timer::del($timerId);
        }
        $this->timerIds = [];

        // Stop the worker if it exists
        if ($this->worker !== null) {
            $this->worker->stop();
        }
    }

    /**
     * Check if the worker is currently running.
     *
     * @return bool True if running
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Get the SyncPlay manager instance.
     *
     * @return SyncPlayManager|null The manager or null if not initialized
     */
    public function getSyncPlayManager(): ?SyncPlayManager
    {
        return $this->syncPlayManager;
    }

    /**
     * Get the message handler instance.
     *
     * @return MessageHandler|null The handler or null if not initialized
     */
    public function getMessageHandler(): ?MessageHandler
    {
        return $this->messageHandler;
    }

    /**
     * Get the WebSocket server instance.
     *
     * @return WebSocketServer|null The server or null if not initialized
     */
    public function getServer(): ?WebSocketServer
    {
        return $this->server;
    }
}
