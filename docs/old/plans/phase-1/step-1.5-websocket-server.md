# Step 1.5: WebSocket Server

**Phase:** 1 - Core Media Server Foundation  
**Plan File:** step-1.5-websocket-server.md  
**Objective:** Implement WebSocket handling, message protocol, and real-time event system

---

## Overview

This step implements WebSocket server capabilities for real-time communication with clients. This enables features like live progress updates, session notifications, and SyncPlay.

**Prerequisites:** Step 1.4 must be completed first.

---

## Tasks

### 1.5.1 Create WebSocket Connection Class

Create `src/Server/WebSocket/Connection.php`:
```php
<?php

namespace Phlex\Server\WebSocket;

use Workerman\Connection\TcpConnection;

class Connection
{
    private TcpConnection $connection;
    private string $id;
    private array $sessionData = [];
    private bool $authenticated = false;
    private ?string $userId = null;
    private ?string $sessionId = null;
    private int $lastActivity;

    public function __construct(TcpConnection $connection)
    {
        $this->connection = $connection;
        $this->id = spl_object_id($connection) . '-' . uniqid();
        $this->lastActivity = time();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function send(string|array $data): void
    {
        if (is_array($data)) {
            $data = json_encode($data);
        }
        $this->connection->send($data);
        $this->updateActivity();
    }

    public function sendMessage(string $type, array $data = []): void
    {
        $this->send([
            'type' => $type,
            'data' => $data,
            'timestamp' => time(),
        ]);
    }

    public function close(): void
    {
        $this->connection->close();
    }

    public function updateActivity(): void
    {
        $this->lastActivity = time();
    }

    public function getLastActivity(): int
    {
        return $this->lastActivity;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function setAuthenticated(bool $authenticated, ?string $userId = null): void
    {
        $this->authenticated = $authenticated;
        $this->userId = $userId;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function set(string $key, mixed $value): void
    {
        $this->sessionData[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->sessionData[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->sessionData[$key]);
    }

    public function remove(string $key): void
    {
        unset($this->sessionData[$key]);
    }

    public function getAll(): array
    {
        return $this->sessionData;
    }

    public function getConnection(): TcpConnection
    {
        return $this->connection;
    }
}
```

### 1.5.2 Create WebSocket Message Handler

Create `src/Server/WebSocket/MessageHandler.php`:
```php
<?php

namespace Phlex\Server\WebSocket;

class MessageHandler
{
    private array $callbacks = [];
    private ConnectionPool $connections;

    public function __construct(ConnectionPool $connections)
    {
        $this->connections = $connections;
    }

    public function on(string $event, callable $callback): void
    {
        $this->callbacks[$event] = $callback;
    }

    public function onAny(callable $callback): void
    {
        $this->callbacks['*'] = $callback;
    }

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

    public function broadcast(string $event, array $data, array $excludeIds = []): void
    {
        $message = json_encode([
            'type' => $event,
            'data' => $data,
            'timestamp' => time(),
        ]);

        foreach ($this->connections->all() as $connection) {
            if (!in_array($connection->getId(), $excludeIds)) {
                $connection->send($message);
            }
        }
    }

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

    public function getConnectionCount(): int
    {
        return $this->connections->count();
    }

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
```

### 1.5.3 Create Connection Pool for WebSocket

Create `src/Server/WebSocket/ConnectionPool.php`:
```php
<?php

namespace Phlex\Server\WebSocket;

class ConnectionPool
{
    private static ConnectionPool $instance;
    private array $connections = [];

    public static function getInstance(): ConnectionPool
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function add(Connection $connection): void
    {
        $this->connections[$connection->getId()] = $connection;
    }

    public function remove(string $id): void
    {
        unset($this->connections[$id]);
    }

    public function get(string $id): ?Connection
    {
        return $this->connections[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->connections);
    }

    public function count(): int
    {
        return count($this->connections);
    }

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

    public function clear(): void
    {
        $this->connections = [];
    }
}
```

### 1.5.4 Create WebSocket Server

Create `src/Server/WebSocket/WebSocketServer.php`:
```php
<?php

namespace Phlex\Server\WebSocket;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;

class WebSocketServer
{
    private Worker $worker;
    private MessageHandler $handler;
    private ConnectionPool $connections;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connections = ConnectionPool::getInstance();
        $this->handler = new MessageHandler($this->connections);
        
        $host = $config['host'] ?? '0.0.0.0';
        $port = $config['port'] ?? 8097;
        
        $this->worker = new Worker("websocket://{$host}:{$port}");
        $this->worker->onWorkerStart = [$this, 'onStart'];
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
        $this->worker->onError = [$this, 'onError'];
    }

    public function onStart(): void
    {
        $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
        $logger->info('WebSocket server started', [
            'host' => $this->config['host'] ?? '0.0.0.0',
            'port' => $this->config['port'] ?? 8097,
        ]);

        // Start cleanup timer for stale connections
        if (function_exists('Workerman\Timer')) {
            \Workerman\Timer::add(60, function () {
                $this->connections->cleanupStaleConnections(300);
            });
        }
    }

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

    public function onMessage(TcpConnection $connection, string $data): void
    {
        $wsConnection = $this->findConnection($connection);
        
        if (!$wsConnection) {
            return;
        }

        $this->handler->handle($wsConnection, $data);
    }

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

    public function onError(TcpConnection $connection, int $code, string $reason): void
    {
        $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
        $logger->error('WebSocket error', [
            'code' => $code,
            'reason' => $reason,
        ]);
    }

    private function findConnection(TcpConnection $connection): ?Connection
    {
        $objectId = spl_object_id($connection);
        foreach ($this->connections->all() as $wsConnection) {
            if (spl_object_id($wsConnection->getConnection()) === $objectId) {
                return $wsConnection;
            }
        }
        return null;
    }

    public function getHandler(): MessageHandler
    {
        return $this->handler;
    }

    public function run(): void
    {
        Worker::runAll();
    }
}
```

### 1.5.5 Create WebSocket Event Types

Create `src/Server/WebSocket/Events.php`:
```php
<?php

namespace Phlex\Server\WebSocket;

/**
 * WebSocket event type constants.
 */
final class WebSocketEvents
{
    // Connection events
    public const CONNECTED = 'connected';
    public const DISCONNECTED = 'disconnected';
    public const CLIENT_DISCONNECTED = 'client_disconnected';
    
    // Authentication events
    public const AUTH_REQUEST = 'auth_request';
    public const AUTH_SUCCESS = 'auth_success';
    public const AUTH_FAILURE = 'auth_failure';
    
    // Session events
    public const SESSION_START = 'session_start';
    public const SESSION_END = 'session_end';
    public const SESSION_JOIN = 'session_join';
    public const SESSION_LEAVE = 'session_leave';
    
    // Playback events
    public const PLAYBACK_START = 'playback_start';
    public const PLAYBACK_PAUSE = 'playback_pause';
    public const PLAYBACK_STOP = 'playback_stop';
    public const PLAYBACK_PROGRESS = 'playback_progress';
    public const PLAYBACK_SEEK = 'playback_seek';
    
    // SyncPlay events
    public const SYNCPLAY_CREATE_GROUP = 'syncplay_create_group';
    public const SYNCPLAY_JOIN_GROUP = 'syncplay_join_group';
    public const SYNCPLAY_LEAVE_GROUP = 'syncplay_leave_group';
    public const SYNCPLAY_SYNC_STATE = 'syncplay_sync_state';
    public const SYNCPLAY_SYNC_REQUEST = 'syncplay_sync_request';
    
    // General events
    public const ERROR = 'error';
    public const PING = 'ping';
    public const PONG = 'pong';
    public const NOTIFICATION = 'notification';
    public const LIBRARY_UPDATED = 'library_updated';

    private function __construct()
    {
        // Prevent instantiation
    }
}
```

### 1.5.6 Create Unit Tests

Create `tests/unit/Server/WebSocket/ConnectionPoolTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlex\Server\WebSocket\ConnectionPool;

class ConnectionPoolTest extends TestCase
{
    public function testCanGetInstance(): void
    {
        $pool = ConnectionPool::getInstance();
        $this->assertInstanceOf(ConnectionPool::class, $pool);
    }

    public function testCanAddAndRemoveConnection(): void
    {
        $pool = ConnectionPool::getInstance();
        $pool->clear();
        
        // Create mock connection
        $connection = $this->createMockConnection('test-1');
        $pool->add($connection);
        
        $this->assertEquals(1, $pool->count());
        $this->assertNotNull($pool->get('test-1'));
        
        $pool->remove('test-1');
        $this->assertEquals(0, $pool->count());
    }

    public function testCanFindByUserId(): void
    {
        $pool = ConnectionPool::getInstance();
        $pool->clear();
        
        $conn1 = $this->createMockConnection('conn-1');
        $conn1->setUserId('user-1');
        $pool->add($conn1);
        
        $conn2 = $this->createMockConnection('conn-2');
        $conn2->setUserId('user-2');
        $pool->add($conn2);
        
        $found = $pool->findByUserId('user-1');
        $this->assertCount(1, $found);
        $this->assertEquals('conn-1', $found[0]->getId());
    }

    private function createMockConnection(string $id)
    {
        return new class($id) {
            private string $id;
            private ?string $userId = null;
            private ?string $sessionId = null;
            private bool $authenticated = false;
            private int $lastActivity = time();
            
            public function __construct(string $id) { $this->id = $id; }
            public function getId(): string { return $this->id; }
            public function getUserId(): ?string { return $this->userId; }
            public function setUserId(?string $userId): void { $this->userId = $userId; }
            public function getSessionId(): ?string { return $this->sessionId; }
            public function setSessionId(?string $sessionId): void { $this->sessionId = $sessionId; }
            public function isAuthenticated(): bool { return $this->authenticated; }
            public function setAuthenticated(bool $a, ?string $u = null): void { $this->authenticated = $a; $this->userId = $u; }
            public function getLastActivity(): int { return $this->lastActivity; }
            public function send($data): void {}
            public function close(): void {}
            public function sendMessage($type, $data): void {}
        };
    }
}
```

Create `tests/unit/Server/WebSocket/MessageHandlerTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlex\Server\WebSocket\MessageHandler;
use Phlex\Server\WebSocket\ConnectionPool;

class MessageHandlerTest extends TestCase
{
    public function testCanRegisterCallback(): void
    {
        $pool = new ConnectionPool();
        $handler = new MessageHandler($pool);
        
        $called = false;
        $handler->on('test_event', function ($conn, $payload) use (&$called) {
            $called = true;
        });
        
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testCanBroadcast(): void
    {
        $pool = ConnectionPool::getInstance();
        $pool->clear();
        
        $handler = new MessageHandler($pool);
        
        // Should not throw
        $handler->broadcast('test_event', ['data' => 'value']);
    }
}
```

---

## Verification

After completing all tasks:

1. Run the unit tests:
```bash
cd /home/sites/phlex
./vendor/bin/phpunit tests/unit/Server/WebSocket/ --testdox
```

2. Verify all classes exist:
```bash
ls -la /home/sites/phlex/src/Server/WebSocket/
```

---

## Git Workflow

After verification, commit your changes:

```bash
cd /home/sites/phlex
git checkout -b step-1.5-websocket-server
git add .
git commit -m "Step 1.5: Implement WebSocket server with message handler"
unset GITHUB_TOKEN
gh pr create --title "Step 1.5: WebSocket Server" --body "Implements WebSocket server including Connection, ConnectionPool, MessageHandler, and WebSocketServer classes."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 1.R: Phase 1 Review** (`plans/phase-1/step-1.R-phase-review.md`).
