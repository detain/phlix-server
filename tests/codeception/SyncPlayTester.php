<?php

declare(strict_types=1);

namespace Phlix\Tests\Codeception;

use Phlix\Tests\Codeception\Helper\SyncPlay;

/**
 * SyncPlayTester - Actor for SyncPlay e2e tests.
 *
 * This class is the primary interface for writing SyncPlay acceptance tests.
 * It wraps the SyncPlay helper to provide a clean API for test scenarios.
 *
 * Test flow: WS connection → JWT auth → member join → playback sync → host transfer → leave
 *
 * The assertion methods below are provided at runtime by Codeception's Asserts
 * module (mixed into the actor via the generated actions trait); they are declared
 * here so static analysis can resolve them.
 *
 * @method void assertNotEmpty(mixed $actual, string $message = '')
 * @method void assertEquals(mixed $expected, mixed $actual, string $message = '')
 * @method void assertNotEquals(mixed $expected, mixed $actual, string $message = '')
 * @method void assertTrue(mixed $condition, string $message = '')
 * @method void assertFalse(mixed $condition, string $message = '')
 * @method void assertArrayHasKey(mixed $key, mixed $array, string $message = '')
 * @method void assertStringStartsWith(string $prefix, string $string, string $message = '')
 */
class SyncPlayTester
{
    private SyncPlay $helper;

    public function __construct(SyncPlay $helper)
    {
        $this->helper = $helper;
    }

    /**
     * Create and register a new mock WebSocket connection.
     */
    public function createConnection(string $userId = null, bool $authenticated = false): string
    {
        return $this->helper->createConnection($userId, $authenticated);
    }

    /**
     * Authenticate a connection with a user ID.
     */
    public function authenticateConnection(string $connectionId, string $userId): void
    {
        $this->helper->authenticateConnection($connectionId, $userId);
    }

    /**
     * Initialize the SyncPlayManager for message handling.
     */
    public function initializeManager(): void
    {
        $this->helper->initializeManager();
    }

    /**
     * Create a SyncPlay group.
     *
     * @return array{success: true, group: array<string, mixed>}|array{success: false, error: string}
     */
    public function createGroup(
        string $connectionId,
        string $groupName,
        ?string $password = null,
        ?string $memberName = null
    ): array {
        return $this->helper->createGroup($connectionId, $groupName, $password, $memberName);
    }

    /**
     * Join a SyncPlay group.
     *
     * @return array{success: true, group: array<string, mixed>}|array{success: false, error: string}
     */
    public function joinGroup(
        string $connectionId,
        string $groupId,
        ?string $password = null,
        ?string $memberName = null
    ): array {
        return $this->helper->joinGroup($connectionId, $groupId, $password, $memberName);
    }

    /**
     * Leave a SyncPlay group.
     *
     * @return array{success: true, message?: string}|array{success: false, error: string}
     */
    public function leaveGroup(string $connectionId): array
    {
        return $this->helper->leaveGroup($connectionId);
    }

    /**
     * Transfer host role to another member.
     */
    public function transferHost(string $hostConnectionId, string $newHostMemberId): void
    {
        $this->helper->transferHost($hostConnectionId, $newHostMemberId);
    }

    /**
     * Send playback play command from host.
     */
    public function sendPlaybackPlay(string $connectionId, int $position = 0): void
    {
        $this->helper->sendPlaybackPlay($connectionId, $position);
    }

    /**
     * Send playback pause command from host.
     */
    public function sendPlaybackPause(string $connectionId, int $position = 0): void
    {
        $this->helper->sendPlaybackPause($connectionId, $position);
    }

    /**
     * Get messages sent to a connection.
     *
     * @return array<int, array<array-key, mixed>>
     */
    public function getMessagesForConnection(string $connectionId, ?string $type = null): array
    {
        return $this->helper->getMessagesForConnection($connectionId, $type);
    }

    /**
     * See that a specific message type was sent to a connection.
     */
    public function seeMessageSentToConnection(string $connectionId, string $messageType): void
    {
        $this->helper->seeMessageSentToConnection($connectionId, $messageType);
    }

    /**
     * Get group state.
     *
     * @return array<string, mixed>|null
     */
    public function getGroupState(string $groupId): ?array
    {
        return $this->helper->getGroupState($groupId);
    }

    /**
     * Assert group exists.
     */
    public function assertGroupExists(string $groupId): void
    {
        $this->helper->assertGroupExists($groupId);
    }

    /**
     * Assert group does not exist.
     */
    public function assertGroupNotExists(string $groupId): void
    {
        $this->helper->assertGroupNotExists($groupId);
    }

    /**
     * Assert member is in specific group.
     */
    public function assertMemberInGroup(string $memberId, string $groupId): void
    {
        $this->helper->assertMemberInGroup($memberId, $groupId);
    }

    /**
     * Assert member is host of group.
     */
    public function assertMemberIsHost(string $memberId, string $groupId): void
    {
        $this->helper->assertMemberIsHost($memberId, $groupId);
    }

    /**
     * Assert group has expected member count.
     */
    public function assertGroupMemberCount(string $groupId, int $expectedCount): void
    {
        $this->helper->assertGroupMemberCount($groupId, $expectedCount);
    }

    /**
     * Get member ID from connection ID.
     */
    public function getMemberId(string $connectionId): ?string
    {
        return $this->helper->getMemberId($connectionId);
    }
}
