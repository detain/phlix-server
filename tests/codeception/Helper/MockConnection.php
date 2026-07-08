<?php

declare(strict_types=1);

namespace Phlix\Tests\Codeception\Helper;

use Phlix\Server\WebSocket\ConnectionInterface;
use Workerman\Connection\TcpConnection;

/**
 * Mock WebSocket connection for e2e testing.
 *
 * Simulates a WebSocket connection for testing SyncPlay protocol flows.
 * Tracks sent and received messages for test assertions.
 */
class MockConnection implements ConnectionInterface
{
    /** @var string Unique connection identifier */
    private string $id;

    /** @var string|null Authenticated user ID */
    private ?string $userId = null;

    /** @var bool Whether connection is authenticated */
    private bool $authenticated = false;

    /** @var array<int, array<array-key, mixed>> Received messages */
    private array $receivedMessages = [];

    /** @var array<int, array<array-key, mixed>> Sent messages */
    private array $sentMessages = [];

    /** @var bool Connection open status */
    private bool $open = true;

    public function __construct(string $id, ?string $userId = null, bool $authenticated = false)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->authenticated = $authenticated;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function send(string|array $data): void
    {
        if (is_string($data)) {
            /** @var array<array-key, mixed> $data */
            $data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        }
        $this->sentMessages[] = $data;
    }

    public function sendMessage(string $type, array $data = []): void
    {
        $this->send([
            'type' => $type,
            'data' => $data,
            'timestamp' => time(),
        ]);
    }

    public function sendFlat(string $type, array $payload): void
    {
        $this->send(array_merge(
            ['type' => $type],
            $payload,
            ['timestamp' => time()]
        ));
    }

    public function close(): void
    {
        $this->open = false;
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

    public function updateActivity(): void
    {
        // No-op for mock
    }

    public function getLastActivity(): int
    {
        return time();
    }

    public function setSessionId(?string $sessionId): void
    {
        // No-op for mock
    }

    public function getSessionId(): ?string
    {
        return null;
    }

    public function set(string $key, mixed $value): void
    {
        // No-op for mock
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function remove(string $key): void
    {
        // No-op for mock
    }

    public function getAll(): array
    {
        return [];
    }

    public function getConnection(): TcpConnection
    {
        // Return a mock TcpConnection - won't be used in tests
        throw new \RuntimeException("Not implemented: MockConnection::getConnection()");
    }

    /**
     * Simulate receiving a message from the client.
     * This is used by tests to simulate server receiving client messages.
     *
     * @param array<array-key, mixed> $data
     */
    public function simulateReceive(array $data): void
    {
        $this->receivedMessages[] = $data;
    }

    /**
     * Get all messages received by this connection (server → client).
     *
     * @return array<int, array<array-key, mixed>>
     */
    public function getReceivedMessages(): array
    {
        return $this->sentMessages;
    }

    /**
     * Get all messages sent by this connection (client → server).
     *
     * @return array<int, array<array-key, mixed>>
     */
    public function getSentMessages(): array
    {
        return $this->receivedMessages;
    }

    /**
     * Check if connection is open.
     */
    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * Reset message history.
     */
    public function resetMessages(): void
    {
        $this->sentMessages = [];
        $this->receivedMessages = [];
    }
}
