<?php

namespace Phlix\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\ConnectionInterface;

/**
 * Unit tests for ConnectionPool class.
 *
 * @covers \Phlix\Server\WebSocket\ConnectionPool
 */
class ConnectionPoolTest extends TestCase
{
    /**
     * @covers \Phlix\Server\WebSocket\ConnectionPool::getInstance
     */
    public function testCanGetInstance(): void
    {
        $pool = ConnectionPool::getInstance();
        $this->assertInstanceOf(ConnectionPool::class, $pool);
    }

    /**
     * @covers \Phlix\Server\WebSocket\ConnectionPool::add
     * @covers \Phlix\Server\WebSocket\ConnectionPool::remove
     * @covers \Phlix\Server\WebSocket\ConnectionPool::get
     * @covers \Phlix\Server\WebSocket\ConnectionPool::count
     * @covers \Phlix\Server\WebSocket\ConnectionPool::clear
     */
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

    /**
     * @covers \Phlix\Server\WebSocket\ConnectionPool::findByUserId
     */
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

    /**
     * Creates a mock connection for testing.
     *
     * @param string $id The connection ID
     * @return ConnectionInterface Mock connection instance
     */
    private function createMockConnection(string $id): ConnectionInterface
    {
        return new class($id) implements ConnectionInterface {
            private string $id;
            private ?string $userId = null;
            private ?string $sessionId = null;
            private bool $authenticated = false;
            private int $lastActivity;
            private array $sessionData = [];

            public function __construct(string $id) { $this->id = $id; $this->lastActivity = time(); }
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
            public function sendMessage($type, $data = []): void {}
            public function updateActivity(): void { $this->lastActivity = time(); }
            public function set(string $key, mixed $value): void { $this->sessionData[$key] = $value; }
            public function get(string $key, mixed $default = null): mixed { return $this->sessionData[$key] ?? $default; }
            public function has(string $key): bool { return isset($this->sessionData[$key]); }
            public function remove(string $key): void { unset($this->sessionData[$key]); }
            public function getAll(): array { return $this->sessionData; }
        };
    }
}