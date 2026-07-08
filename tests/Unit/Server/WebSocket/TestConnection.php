<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use Phlix\Server\WebSocket\ConnectionInterface;

/**
 * Test double for ConnectionInterface that properly tracks state.
 *
 * Unlike PHPUnit mocks of the concrete Connection class, this correctly
 * maintains the relationship between setAuthenticated() and getUserId().
 */
class TestConnection implements ConnectionInterface
{
    private string $id;

    private bool $authenticated = false;

    private ?string $userId = null;

    private ?string $sessionId = null;

    /** @var array<string, mixed> */
    private array $sessionData = [];

    /** @var list<array<array-key, mixed>> */
    private array $sentMessages = [];

    private bool $closed = false;

    private int $lastActivity = 0;

    public function __construct(string $id = 'test-conn-id')
    {
        $this->id = $id;
        $this->lastActivity = time();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function send(string|array $data): void
    {
        $this->sentMessages[] = is_array($data) ? $data : ['raw' => $data];
        $this->lastActivity = time();
    }

    public function sendMessage(string $type, array $data = []): void
    {
        $this->sentMessages[] = ['type' => $type, 'data' => $data];
    }

    public function sendFlat(string $type, array $payload): void
    {
        $this->sentMessages[] = ['type' => $type, 'payload' => $payload];
    }

    public function close(): void
    {
        $this->closed = true;
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

    /**
     * Get all messages sent via send() or sendMessage()/sendFlat().
     *
     * @return list<array<array-key, mixed>>
     */
    public function getSentMessages(): array
    {
        return $this->sentMessages;
    }

    /**
     * Get messages of a specific type sent via sendFlat.
     *
     * @param string $type Message type to filter by
     * @return list<array<string, mixed>>
     */
    public function getSentFlatMessages(string $type): array
    {
        $messages = [];
        foreach ($this->sentMessages as $msg) {
            if (($msg['type'] ?? '') === $type) {
                /** @var array<string, mixed> $payload */
                $payload = $msg['payload'] ?? $msg;
                $messages[] = $payload;
            }
        }

        return $messages;
    }

    public function wasClosed(): bool
    {
        return $this->closed;
    }
}
