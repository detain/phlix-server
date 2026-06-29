<?php

declare(strict_types=1);

namespace Phlix\Tests\Codeception\Helper;

use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Session\SyncPlay\GroupState;
use Phlix\Server\WebSocket\ConnectionInterface;
use Phlix\Server\WebSocket\ConnectionPool;
use Codeception\Module;
use RuntimeException;

/**
 * SyncPlay Helper for Codeception e2e tests.
 *
 * Provides WebSocket connection simulation for testing SyncPlay protocol
 * flows: WS connection → JWT auth → member join → playback sync →
 * host transfer → leave.
 *
 * This helper manages mock connections and tracks messages for assertions.
 */
class SyncPlay extends Module
{
    /** @var SyncPlayManager The SyncPlay manager under test */
    private SyncPlayManager $manager;

    /** @var array<string, MockConnection> Active mock connections */
    private array $connections = [];

    /** @var array<int, array{from: string, to: string, message: array}> Sent messages */
    private array $messageLog = [];

    /** @var int Connection counter for unique IDs */
    private int $connectionCounter = 0;

    /** @var array<string, string> Connection ID to Member ID mapping */
    private array $connectionToMember = [];

    protected array $requiredFields = ['host', 'port'];

    public function _before(\Codeception\TestInterface $test): void
    {
        parent::_before($test);

        // Reset state between tests
        $this->manager = new SyncPlayManager();
        $this->connections = [];
        $this->messageLog = [];
        $this->connectionToMember = [];
        $this->connectionCounter = 0;

        // Clear ConnectionPool singleton
        $pool = ConnectionPool::getInstance();
        $pool->clear();
    }

    /**
     * Create a mock WebSocket connection and register it.
     */
    public function createConnection(string $userId = null, bool $authenticated = false): string
    {
        $this->connectionCounter++;
        $connectionId = 'test-conn-' . $this->connectionCounter;

        $mockConnection = new MockConnection($connectionId, $userId, $authenticated);
        $this->connections[$connectionId] = $mockConnection;

        // Add to ConnectionPool
        $pool = ConnectionPool::getInstance();
        $pool->add($mockConnection);

        // If authenticated with userId, map the connection to member
        if ($authenticated && $userId !== null) {
            $this->connectionToMember[$connectionId] = $userId;
        }

        return $connectionId;
    }

    /**
     * Authenticate a connection with a user ID.
     */
    public function authenticateConnection(string $connectionId, string $userId): void
    {
        $connection = $this->getConnection($connectionId);
        $connection->setAuthenticated(true, $userId);
        $this->connectionToMember[$connectionId] = $userId;
    }

    /**
     * Get a mock connection by ID.
     */
    public function getConnection(string $connectionId): MockConnection
    {
        if (!isset($this->connections[$connectionId])) {
            throw new RuntimeException("Connection {$connectionId} not found");
        }
        return $this->connections[$connectionId];
    }

    /**
     * Get the SyncPlayManager instance.
     */
    public function getManager(): SyncPlayManager
    {
        return $this->manager;
    }

    /**
     * Initialize the SyncPlayManager with message handling.
     */
    public function initializeManager(): void
    {
        $this->manager->initialize(new \Phlix\Server\WebSocket\MessageHandler());
    }

    /**
     * Create a SyncPlay group (host workflow).
     *
     * @param string $connectionId The host's connection ID
     * @param string $groupName The group name
     * @param string|null $password Optional group password
     * @param string|null $memberName The host's display name
     * @return array{success: bool, group?: array, error?: string, group_id?: string}
     */
    public function createGroup(
        string $connectionId,
        string $groupName,
        ?string $password = null,
        ?string $memberName = null
    ): array {
        $connection = $this->getConnection($connectionId);
        $memberId = $this->connectionToMember[$connectionId] ?? 'member_' . $connectionId;

        $result = $this->manager->createGroup(
            $groupName,
            $password,
            $memberId,
            $memberName ?? 'Host',
            $connectionId
        );

        if ($result['success'] && isset($result['group'])) {
            $this->connectionToMember[$connectionId] = $memberId;
        }

        return $result;
    }

    /**
     * Join a SyncPlay group.
     *
     * @param string $connectionId The joining member's connection ID
     * @param string $groupId The group ID to join
     * @param string|null $password Optional group password
     * @param string|null $memberName The member's display name
     * @return array{success: bool, group?: array, error?: string}
     */
    public function joinGroup(
        string $connectionId,
        string $groupId,
        ?string $password = null,
        ?string $memberName = null
    ): array {
        $connection = $this->getConnection($connectionId);
        $memberId = $this->connectionToMember[$connectionId] ?? 'member_' . $connectionId;

        $result = $this->manager->joinGroup(
            $groupId,
            $memberId,
            $memberName ?? 'Guest',
            $password,
            $connectionId
        );

        if ($result['success']) {
            $this->connectionToMember[$connectionId] = $memberId;
        }

        return $result;
    }

    /**
     * Leave a SyncPlay group.
     *
     * @param string $connectionId The leaving member's connection ID
     * @return array{success: bool, message?: string, error?: string}
     */
    public function leaveGroup(string $connectionId): array
    {
        $memberId = $this->connectionToMember[$connectionId] ?? null;

        if ($memberId === null) {
            return ['success' => false, 'error' => 'Not in any group'];
        }

        $result = $this->manager->leaveGroup($memberId);

        if ($result['success']) {
            unset($this->connectionToMember[$connectionId]);
        }

        return $result;
    }

    /**
     * Simulate host transferring ownership to another member.
     *
     * @param string $hostConnectionId The current host's connection ID
     * @param string $newHostMemberId The member ID of the new host
     * @return void
     */
    public function transferHost(string $hostConnectionId, string $newHostMemberId): void
    {
        $connection = $this->getConnection($hostConnectionId);
        $memberId = $this->connectionToMember[$hostConnectionId] ?? null;

        if ($memberId === null) {
            throw new RuntimeException("Host connection not in any group");
        }

        $groupId = $this->manager->getMemberGroup($memberId);
        if ($groupId === null) {
            throw new RuntimeException("Host not in any group");
        }

        // Get the group and set new host
        $group = $this->manager->getGroupState($groupId);
        if ($group === null) {
            throw new RuntimeException("Group not found");
        }

        // Use reflection to call the private handleHostTransfer
        $reflection = new \ReflectionMethod($this->manager, 'handleHostTransfer');
        $reflection->setAccessible(true);

        $payload = ['new_host_id' => $newHostMemberId];
        $reflection->invoke($this->manager, $connection, $payload);
    }

    /**
     * Simulate playback play command from host.
     *
     * @param string $connectionId The host's connection ID
     * @param int $position Playback position in milliseconds
     * @return void
     */
    public function sendPlaybackPlay(string $connectionId, int $position = 0): void
    {
        $connection = $this->getConnection($connectionId);

        $reflection = new \ReflectionMethod($this->manager, 'handlePlaybackPlay');
        $reflection->setAccessible(true);

        $reflection->invoke($this->manager, $connection, [
            'position' => $position,
            'server_time' => time(),
        ]);
    }

    /**
     * Simulate playback pause command from host.
     *
     * @param string $connectionId The host's connection ID
     * @param int $position Playback position in milliseconds
     * @return void
     */
    public function sendPlaybackPause(string $connectionId, int $position = 0): void
    {
        $connection = $this->getConnection($connectionId);

        $reflection = new \ReflectionMethod($this->manager, 'handlePlaybackPause');
        $reflection->setAccessible(true);

        $reflection->invoke($this->manager, $connection, [
            'position' => $position,
            'server_time' => time(),
        ]);
    }

    /**
     * Get messages sent to a specific connection.
     *
     * @param string $connectionId The connection ID to check
     * @param string|null $type Optional message type filter
     * @return array The messages sent to this connection
     */
    public function getMessagesForConnection(string $connectionId, ?string $type = null): array
    {
        $connection = $this->getConnection($connectionId);
        $messages = $connection->getReceivedMessages();

        if ($type !== null) {
            $messages = array_filter($messages, fn($m) => ($m['type'] ?? '') === $type);
        }

        return array_values($messages);
    }

    /**
     * Assert that a connection received a message of a specific type.
     */
    public function seeMessageSentToConnection(string $connectionId, string $messageType): void
    {
        $messages = $this->getMessagesForConnection($connectionId);

        $found = false;
        foreach ($messages as $msg) {
            if (($msg['type'] ?? '') === $messageType) {
                $found = true;
                break;
            }
        }

        $this->assert($found, "Message type {$messageType} was sent to connection {$connectionId}");
    }

    /**
     * Get the group state for a specific group.
     */
    public function getGroupState(string $groupId): ?array
    {
        return $this->manager->getGroupState($groupId);
    }

    /**
     * Get all messages in the message log.
     */
    public function getMessageLog(): array
    {
        return $this->messageLog;
    }

    /**
     * Assert group exists.
     */
    public function assertGroupExists(string $groupId): void
    {
        $state = $this->manager->getGroupState($groupId);
        $this->assert($state !== null, "Group {$groupId} should exist");
    }

    /**
     * Assert group does not exist.
     */
    public function assertGroupNotExists(string $groupId): void
    {
        $state = $this->manager->getGroupState($groupId);
        $this->assert($state === null, "Group {$groupId} should not exist");
    }

    /**
     * Assert member is in group.
     */
    public function assertMemberInGroup(string $memberId, string $groupId): void
    {
        $actualGroupId = $this->manager->getMemberGroup($memberId);
        $this->assert(
            $actualGroupId === $groupId,
            "Member {$memberId} should be in group {$groupId}, but is in " . ($actualGroupId ?? 'none')
        );
    }

    /**
     * Assert member is host of group.
     */
    public function assertMemberIsHost(string $memberId, string $groupId): void
    {
        $state = $this->manager->getGroupState($groupId);
        $this->assert(
            $state !== null && ($state['host_id'] ?? '') === $memberId,
            "Member {$memberId} should be host of group {$groupId}"
        );
    }

    /**
     * Assert group has expected member count.
     */
    public function assertGroupMemberCount(string $groupId, int $expectedCount): void
    {
        $state = $this->manager->getGroupState($groupId);
        $this->assert(
            $state !== null && ($state['member_count'] ?? 0) === $expectedCount,
            "Group {$groupId} should have {$expectedCount} members, has " . ($state['member_count'] ?? 0)
        );
    }

    /**
     * Get member ID from connection ID.
     */
    public function getMemberId(string $connectionId): ?string
    {
        return $this->connectionToMember[$connectionId] ?? null;
    }

    /**
     * Simple assertion helper.
     */
    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }
}
