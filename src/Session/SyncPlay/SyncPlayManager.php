<?php

declare(strict_types=1);

namespace Phlix\Session\SyncPlay;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Server\WebSocket\Connection;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\MessageHandler;

/**
 * SyncPlayManager - Main manager for SyncPlay group watching functionality
 *
 * This class orchestrates synchronized group watching sessions where multiple users
 * can watch content together remotely. The host controls playback (play, pause, seek)
 * and all members receive synchronized playback commands.
 *
 * ## Architecture Overview
 *
 * SyncPlay uses a host-controlled model where only the group host can initiate
 * playback commands. Non-host members receive these commands via WebSocket
 * broadcasts and adjust their local playback to match.
 *
 * ## Time Synchronization
 *
 * The TimeSync component handles NTP-style time synchronization to ensure all
 * clients can calculate accurate playback positions despite network latency.
 *
 * ## Group Lifecycle
 *
 * 1. Host creates group → receives sp_* group ID
 * 2. Members join using group ID and optional password
 * 3. Host controls playback → broadcasts to all members
 * 4. Members leave or host leaves → group state updated
 * 5. Empty groups are automatically cleaned up
 *
 * @author Phlix Development Team
 * @copyright 2024 Phlix Media Server
 * @license Proprietary
 *
 * @see GroupState For individual group state management
 * @see TimeSync For time synchronization logic
 * @see Messages For WebSocket message type definitions
 */
class SyncPlayManager
{
    /**
     * Default position tolerance in milliseconds.
     *
     * If a member's playback position differs from the host's by more than
     * this tolerance, they are considered "out of sync".
     */
    private const DEFAULT_POSITION_TOLERANCE = 2000;

    /**
     * Maximum number of SyncPlay groups allowed per server instance.
     *
     * This limit prevents resource exhaustion on the server.
     */
    private const MAX_GROUPS = 100;

    /**
     * Group inactivity timeout in seconds.
     *
     * Groups with no activity (no members checking in) for this duration
     * will be automatically removed during cleanup.
     */
    private const GROUP_TIMEOUT = 3600;

    /** @var array<string, GroupState> Active groups indexed by group ID */
    private array $groups = [];

    /** @var array<string, string> Member ID to group ID mapping */
    private array $memberToGroup = [];

    /** @var TimeSync Time synchronization handler for playback sync */
    private TimeSync $timeSync;

    /** @var MessageHandler|null WebSocket message handler for broadcasts */
    private ?MessageHandler $messageHandler = null;

    /** @var StructuredLogger|null Optional structured logger for debugging */
    private ?StructuredLogger $logger;

    /** @var int Position tolerance in milliseconds for sync detection */
    private int $positionTolerance;

    public function __construct(
        ?StructuredLogger $logger = null,
        int $positionTolerance = self::DEFAULT_POSITION_TOLERANCE
    ) {
        $this->logger = $logger;
        $this->positionTolerance = $positionTolerance;
        $this->timeSync = new TimeSync();
    }

    /**
     * Initialize with a message handler for broadcasting.
     *
     * Sets up the message handler and registers all WebSocket event listeners
     * for SyncPlay message types.
     *
     * @param MessageHandler $messageHandler WebSocket message handler for broadcasts
     * @return void
     *
     * @example
     * ```php
     * $manager = new SyncPlayManager();
     * $manager->initialize($messageHandler);
     * ```
     */
    public function initialize(MessageHandler $messageHandler): void
    {
        $this->messageHandler = $messageHandler;
        $this->registerMessageHandlers();
    }

    /**
     * Register WebSocket message handlers for all SyncPlay message types.
     *
     * This method binds the message handler to handle incoming WebSocket messages
     * for group management and playback control.
     *
     * @return void
     */
    private function registerMessageHandlers(): void
    {
        if ($this->messageHandler === null) {
            return;
        }

        $handler = function (Connection $connection, array $payload) {
            $this->handleMessage($connection, $payload);
        };

        $this->messageHandler->on(Messages::TYPE_GROUP_CREATE, $handler);
        $this->messageHandler->on(Messages::TYPE_GROUP_JOIN, $handler);
        $this->messageHandler->on(Messages::TYPE_GROUP_LEAVE, $handler);
        $this->messageHandler->on(Messages::TYPE_PLAYBACK_PLAY, $handler);
        $this->messageHandler->on(Messages::TYPE_PLAYBACK_PAUSE, $handler);
        $this->messageHandler->on(Messages::TYPE_PLAYBACK_SEEK, $handler);
        $this->messageHandler->on(Messages::TYPE_PLAYBACK_QUEUE, $handler);
        $this->messageHandler->on(Messages::TYPE_CHAT_MESSAGE, $handler);
        $this->messageHandler->on(Messages::TYPE_CHAT_TYPING, $handler);
        $this->messageHandler->on(Messages::TYPE_TIME_PING, $handler);
    }

    /**
     * Handle incoming WebSocket message from a client connection.
     *
     * Routes the message to the appropriate handler based on message type.
     * All exceptions are caught and reported back to the client as error messages.
     *
     * @param Connection $connection The WebSocket connection that sent the message
     * @param array<string, mixed> $payload The decoded message payload
     * @return void
     *
     * @see Messages For all valid message type constants
     */
    private function handleMessage(Connection $connection, array $payload): void
    {
        $type = $payload['type'] ?? '';

        try {
            switch ($type) {
                case Messages::TYPE_GROUP_CREATE:
                    $this->handleGroupCreate($connection, $payload);
                    break;

                case Messages::TYPE_GROUP_JOIN:
                    $this->handleGroupJoin($connection, $payload);
                    break;

                case Messages::TYPE_GROUP_LEAVE:
                    $this->handleGroupLeave($connection, $payload);
                    break;

                case Messages::TYPE_PLAYBACK_PLAY:
                    $this->handlePlaybackPlay($connection, $payload);
                    break;

                case Messages::TYPE_PLAYBACK_PAUSE:
                    $this->handlePlaybackPause($connection, $payload);
                    break;

                case Messages::TYPE_PLAYBACK_SEEK:
                    $this->handlePlaybackSeek($connection, $payload);
                    break;

                case Messages::TYPE_PLAYBACK_QUEUE:
                    $this->handlePlaybackQueue($connection, $payload);
                    break;

                case Messages::TYPE_CHAT_MESSAGE:
                    $this->handleChatMessage($connection, $payload);
                    break;

                case Messages::TYPE_TIME_PING:
                    $this->handleTimePing($connection, $payload);
                    break;

                default:
                    $this->sendError($connection, 'UNKNOWN_MESSAGE', 'Unknown message type');
            }
        } catch (\Throwable $e) {
            $this->sendError($connection, 'HANDLER_ERROR', $e->getMessage());
            $this->log('error', 'Message handler error', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create a new SyncPlay group.
     *
     * Creates a new group with the specified name and optional password protection.
     * If memberId and memberName are provided, the creator is automatically added
     * as the first member and designated as the host.
     *
     * @param string $name The display name for the group (max 255 chars recommended)
     * @param string|null $password Optional password to protect the group (null for open groups)
     * @param string|null $memberId The member ID of the group creator (null if not joining)
     * @param string|null $memberName The display name of the creator (defaults to 'Host')
     * @return array{success: true, group: array<string, mixed>}|array{success: false, error: string} Result with group state or error
     *
     * @example
     * ```php
     * // Create a group without password
     * $result = $manager->createGroup('Movie Night');
     *
     * // Create a protected group with the creator as host
     * $result = $manager->createGroup('Private Watch Party', 'secret123', 'user_1', 'Host');
     * ```
     */
    public function createGroup(string $name, ?string $password = null, ?string $memberId = null, ?string $memberName = null): array
    {
        if (count($this->groups) >= self::MAX_GROUPS) {
            return ['success' => false, 'error' => 'Maximum group limit reached'];
        }

        $groupId = $this->generateGroupId();
        $passwordHash = $password !== null ? GroupState::hashPassword($password) : null;

        $group = new GroupState(
            $groupId,
            $name,
            $passwordHash,
            $this->positionTolerance
        );

        // Add creator as first member and host
        if ($memberId !== null) {
            $group->addMember($memberId, [
                'name' => $memberName ?? 'Host',
                'connection_id' => null,
            ]);
            $group->setHost($memberId);
            $this->memberToGroup[$memberId] = $groupId;
        }

        $this->groups[$groupId] = $group;

        $this->log('info', 'Group created', [
            'group_id' => $groupId,
            'name' => $name,
        ]);

        return [
            'success' => true,
            'group' => $group->getState(),
        ];
    }

    /**
     * Join an existing SyncPlay group.
     *
     * Adds a member to the specified group. If the group requires a password,
     * it must be provided and verified before joining succeeds.
     *
     * @param string $groupId The group ID to join (format: sp_*)
     * @param string $memberId Unique identifier for the member joining
     * @param string $memberName Display name for the member
     * @param string|null $password Optional password if group is protected
     * @return array{success: true, group: array<string, mixed>}|array{success: false, error: string} Result with group state or error
     *
     * @example
     * ```php
     * $result = $manager->joinGroup('sp_abc123def456', 'member_2', 'Guest User');
     *
     * // Join a password-protected group
     * $result = $manager->joinGroup('sp_abc123', 'member_2', 'Guest', 'secret');
     * ```
     */
    public function joinGroup(string $groupId, string $memberId, string $memberName, ?string $password = null): array
    {
        $group = $this->groups[$groupId] ?? null;

        if ($group === null) {
            return ['success' => false, 'error' => 'Group not found'];
        }

        if ($group->hasPassword() && !$group->verifyPassword($password ?? '')) {
            return ['success' => false, 'error' => 'Invalid password'];
        }

        if ($group->getMemberCount() >= GroupState::MAX_MEMBERS) {
            return ['success' => false, 'error' => 'Group is full'];
        }

        if ($group->hasMember($memberId)) {
            return ['success' => false, 'error' => 'Already a member of this group'];
        }

        $memberData = [
            'name' => $memberName,
            'connection_id' => null,
        ];

        if (!$group->addMember($memberId, $memberData)) {
            return ['success' => false, 'error' => 'Failed to join group'];
        }

        $this->memberToGroup[$memberId] = $groupId;

        $this->log('info', 'Member joined group', [
            'group_id' => $groupId,
            'member_id' => $memberId,
        ]);

        // Broadcast join to group
        $this->broadcastToGroup($groupId, Messages::TYPE_INFO, [
            'message' => "{$memberName} joined the group",
            'member_id' => $memberId,
            'member_name' => $memberName,
        ], [$memberId]);

        return [
            'success' => true,
            'group' => $group->getState(),
        ];
    }

    /**
     * Remove a member from their current SyncPlay group.
     *
     * If the member is the host and other members remain, a new host will be
     * automatically elected (oldest member). If no members remain, the group
     * is deleted.
     *
     * @param string $memberId The ID of the member leaving
     * @return array{success: true, message?: string}|array{success: false, error: string} Result with message or error
     *
     * @example
     * ```php
     * $result = $manager->leaveGroup('member_2');
     * ```
     */
    public function leaveGroup(string $memberId): array
    {
        $groupId = $this->memberToGroup[$memberId] ?? null;

        if ($groupId === null) {
            return ['success' => false, 'error' => 'Not in any group'];
        }

        $group = $this->groups[$groupId] ?? null;

        if ($group === null) {
            unset($this->memberToGroup[$memberId]);
            return ['success' => true];
        }

        $memberRecord = $group->getMember($memberId);
        $memberName = $memberRecord['name'] ?? 'Unknown';
        $wasHost = $group->isHost($memberId);

        $group->removeMember($memberId);
        unset($this->memberToGroup[$memberId]);

        // Clean up empty groups
        if ($group->getMemberCount() === 0) {
            unset($this->groups[$groupId]);
            $this->log('info', 'Group removed (empty)', ['group_id' => $groupId]);
        } elseif ($wasHost) {
            // Broadcast host change
            $newHostId = $group->getHostId();
            $this->broadcastToGroup($groupId, Messages::TYPE_HOST_ELECT, [
                'elected_id' => $newHostId,
                'elected_by' => $memberId,
            ]);
        }

        $this->log('info', 'Member left group', [
            'group_id' => $groupId,
            'member_id' => $memberId,
        ]);

        return [
            'success' => true,
            'message' => "{$memberName} left the group",
        ];
    }

    /**
     * Get the current state of a SyncPlay group.
     *
     * Returns the full group state including members, playback info, and queue.
     *
     * @param string $groupId The group ID to retrieve
     * @return array<string, mixed>|null The group state array, or null if group not found
     *
     * @see GroupState::getState() For the structure of the returned array
     */
    public function getGroupState(string $groupId): ?array
    {
        $group = $this->groups[$groupId] ?? null;
        return $group?->getState();
    }

    /**
     * List all available SyncPlay groups.
     *
     * Returns a summary of all groups including member count, password protection,
     * current media, and playback state. Does not include password-protected
     * details unless verified.
     *
     * @return array<int, array{id: string, name: string, member_count: int, has_password: bool, current_media: string|null, is_playing: bool}> Array of group summaries
     *
     * @example
     * ```php
     * $groups = $manager->listGroups();
     * foreach ($groups as $group) {
     *     echo "{$group['name']}: {$group['member_count']} members\n";
     * }
     * ```
     */
    public function listGroups(): array
    {
        $list = [];

        foreach ($this->groups as $id => $group) {
            $list[] = [
                'id' => $id,
                'name' => $group->getName(),
                'member_count' => $group->getMemberCount(),
                'has_password' => $group->hasPassword(),
                'current_media' => $group->getCurrentMediaId(),
                'is_playing' => $group->isPlaying(),
            ];
        }

        return $list;
    }

    /**
     * Handle playback play command from a group host.
     *
     * Only the host can initiate playback commands. The position is updated
     * and broadcast to all other group members.
     *
     * @param Connection $connection The WebSocket connection
     * @param array<string, mixed> $payload Payload containing member_id, position, server_time
     * @return void
     *
     * @fires Messages::TYPE_PLAYBACK_PLAY Broadcast to group members
     */
    private function handlePlaybackPlay(Connection $connection, array $payload): void
    {
        $memberId = self::stringFromMixed($payload['member_id'] ?? null);
        if ($memberId === '') {
            $this->sendError($connection, 'NOT_IN_GROUP', 'You are not in a group');
            return;
        }
        $groupId = $this->memberToGroup[$memberId] ?? null;
        $group = $groupId !== null ? ($this->groups[$groupId] ?? null) : null;

        if ($group === null || $groupId === null) {
            $this->sendError($connection, 'NOT_IN_GROUP', 'You are not in a group');
            return;
        }

        if (!$group->isHost($memberId)) {
            $this->sendError($connection, 'NOT_HOST', 'Only the host can control playback');
            return;
        }

        $position = self::intFromMixed($payload['position'] ?? 0);
        $serverTime = self::intFromMixed($payload['server_time'] ?? time());

        $group->updatePlayback(GroupState::STATE_PLAYING, $position);

        $this->broadcastToGroup($groupId, Messages::TYPE_PLAYBACK_PLAY, [
            'member_id' => $memberId,
            'position' => $position,
            'server_time' => $serverTime,
        ], [$memberId]);
    }

    /**
     * Handle playback pause command from a group host.
     *
     * @param Connection $connection The WebSocket connection
     * @param array<string, mixed> $payload Payload containing member_id, position, server_time
     * @return void
     *
     * @fires Messages::TYPE_PLAYBACK_PAUSE Broadcast to group members
     */
    private function handlePlaybackPause(Connection $connection, array $payload): void
    {
        $memberId = self::stringFromMixed($payload['member_id'] ?? null);
        if ($memberId === '') {
            $this->sendError($connection, 'NOT_IN_GROUP', 'You are not in a group');
            return;
        }
        $groupId = $this->memberToGroup[$memberId] ?? null;
        $group = $groupId !== null ? ($this->groups[$groupId] ?? null) : null;

        if ($group === null || $groupId === null) {
            $this->sendError($connection, 'NOT_IN_GROUP', 'You are not in a group');
            return;
        }

        if (!$group->isHost($memberId)) {
            $this->sendError($connection, 'NOT_HOST', 'Only the host can control playback');
            return;
        }

        $position = self::intFromMixed($payload['position'] ?? 0);
        $serverTime = self::intFromMixed($payload['server_time'] ?? time());

        $group->updatePlayback(GroupState::STATE_PAUSED, $position);

        $this->broadcastToGroup($groupId, Messages::TYPE_PLAYBACK_PAUSE, [
            'member_id' => $memberId,
            'position' => $position,
            'server_time' => $serverTime,
        ], [$memberId]);
    }

    /**
     * Handle playback seek command from a group host.
     *
     * @param Connection $connection The WebSocket connection
     * @param array<string, mixed> $payload Payload containing member_id, from_position, to_position, server_time
     * @return void
     *
     * @fires Messages::TYPE_PLAYBACK_SEEK Broadcast to group members
     */
    private function handlePlaybackSeek(Connection $connection, array $payload): void
    {
        $memberId = self::stringFromMixed($payload['member_id'] ?? null);
        if ($memberId === '') {
            $this->sendError($connection, 'NOT_IN_GROUP', 'You are not in a group');
            return;
        }
        $groupId = $this->memberToGroup[$memberId] ?? null;
        $group = $groupId !== null ? ($this->groups[$groupId] ?? null) : null;

        if ($group === null || $groupId === null) {
            $this->sendError($connection, 'NOT_IN_GROUP', 'You are not in a group');
            return;
        }

        if (!$group->isHost($memberId)) {
            $this->sendError($connection, 'NOT_HOST', 'Only the host can control playback');
            return;
        }

        $fromPosition = self::intFromMixed($payload['from_position'] ?? 0);
        $toPosition = self::intFromMixed($payload['to_position'] ?? 0);
        $serverTime = self::intFromMixed($payload['server_time'] ?? time());

        $group->setPlaybackPosition($toPosition);

        $this->broadcastToGroup($groupId, Messages::TYPE_PLAYBACK_SEEK, [
            'member_id' => $memberId,
            'from_position' => $fromPosition,
            'to_position' => $toPosition,
            'server_time' => $serverTime,
        ], [$memberId]);
    }

    /**
     * Handle playback queue update from a group host.
     *
     * Replaces the group's current playback queue with the provided queue items.
     *
     * @param Connection $connection The WebSocket connection
     * @param array<string, mixed> $payload Payload containing member_id, queue array
     * @return void
     *
     * @fires Messages::TYPE_PLAYBACK_QUEUE Broadcast to group members
     */
    private function handlePlaybackQueue(Connection $connection, array $payload): void
    {
        $memberId = self::stringFromMixed($payload['member_id'] ?? null);
        if ($memberId === '') {
            $this->sendError($connection, 'NOT_IN_GROUP', 'You are not in a group');
            return;
        }
        $groupId = $this->memberToGroup[$memberId] ?? null;
        $group = $groupId !== null ? ($this->groups[$groupId] ?? null) : null;

        if ($group === null || $groupId === null) {
            $this->sendError($connection, 'NOT_IN_GROUP', 'You are not in a group');
            return;
        }

        if (!$group->isHost($memberId)) {
            $this->sendError($connection, 'NOT_HOST', 'Only the host can modify the queue');
            return;
        }

        $queueRaw = $payload['queue'] ?? [];

        // Update queue
        $group->clearQueue();
        if (is_array($queueRaw)) {
            foreach ($queueRaw as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $mediaId = $item['media_id'] ?? null;
                if (!is_string($mediaId)) {
                    continue;
                }
                $mediaInfoRaw = $item['media_info'] ?? [];
                $mediaInfo = [];
                if (is_array($mediaInfoRaw)) {
                    foreach ($mediaInfoRaw as $infoKey => $infoValue) {
                        if (is_string($infoKey)) {
                            $mediaInfo[$infoKey] = $infoValue;
                        }
                    }
                }
                $group->addToQueue($mediaId, $mediaInfo);
            }
        }

        $this->broadcastToGroup($groupId, Messages::TYPE_PLAYBACK_QUEUE, [
            'queue' => $group->getPlaybackQueue(),
        ]);
    }

    /**
     * Handle incoming chat message from a group member.
     *
     * Broadcasts the chat message to all other group members along with
     * the sender's name and timestamp.
     *
     * @param Connection $connection The WebSocket connection
     * @param array<string, mixed> $payload Payload containing member_id, message
     * @return void
     *
     * @fires Messages::TYPE_CHAT_MESSAGE Broadcast to group members (excluding sender)
     */
    private function handleChatMessage(Connection $connection, array $payload): void
    {
        $memberId = self::stringFromMixed($payload['member_id'] ?? null);
        if ($memberId === '') {
            $this->sendError($connection, 'NOT_IN_GROUP', 'You are not in a group');
            return;
        }
        $groupId = $this->memberToGroup[$memberId] ?? null;
        $group = $groupId !== null ? ($this->groups[$groupId] ?? null) : null;

        if ($group === null || $groupId === null) {
            $this->sendError($connection, 'NOT_IN_GROUP', 'You are not in a group');
            return;
        }

        $message = self::stringFromMixed($payload['message'] ?? '');
        $memberRecord = $group->getMember($memberId);
        $memberName = $memberRecord['name'] ?? 'Unknown';

        if (trim($message) === '') {
            return;
        }

        $group->addChatMessage($memberId, $message);

        $this->broadcastToGroup($groupId, Messages::TYPE_CHAT_MESSAGE, [
            'member_id' => $memberId,
            'member_name' => $memberName,
            'message' => $message,
            'timestamp' => time(),
        ]);
    }

    /**
     * Handle time synchronization ping from a client.
     *
     * Processes the ping and returns a pong with server timestamp for
     * calculating network latency and clock offset.
     *
     * @param Connection $connection The WebSocket connection
     * @param array<string, mixed> $payload Payload containing client_time
     * @return void
     *
     * @see TimeSync::processPing() For ping/pong protocol details
     */
    private function handleTimePing(Connection $connection, array $payload): void
    {
        $pong = $this->timeSync->processPing($payload);
        $connection->sendMessage(Messages::TYPE_TIME_PONG, $pong);
    }

    /**
     * Handle group creation request via WebSocket.
     *
     * Creates a new group with the requesting member as the host.
     *
     * @param Connection $connection The WebSocket connection
     * @param array<string, mixed> $payload Payload containing member_id, member_name, group_name, password
     * @return void
     *
     * @fires Messages::TYPE_GROUP_STATE Sent to the creating member on success
     * @fires Messages::TYPE_ERROR Sent on failure
     */
    private function handleGroupCreate(Connection $connection, array $payload): void
    {
        $memberId = self::stringFromMixed($payload['member_id'] ?? $connection->getId());
        $memberName = self::stringFromMixed($payload['member_name'] ?? 'Host');
        $groupName = self::stringFromMixed($payload['group_name'] ?? 'New Group');
        $password = self::stringOrNullFromMixed($payload['password'] ?? null);

        $result = $this->createGroup($groupName, $password, $memberId, $memberName);

        if ($result['success'] === true) {
            $connection->sendMessage(Messages::TYPE_GROUP_STATE, [
                'group' => $result['group'],
                'your_id' => $memberId,
            ]);
        } else {
            $this->sendError($connection, 'CREATE_FAILED', $result['error']);
        }
    }

    /**
     * Handle group join request via WebSocket.
     *
     * @param Connection $connection The WebSocket connection
     * @param array<string, mixed> $payload Payload containing group_id, member_id, member_name, password
     * @return void
     *
     * @fires Messages::TYPE_GROUP_STATE Sent to the joining member on success
     * @fires Messages::TYPE_ERROR Sent on failure
     */
    private function handleGroupJoin(Connection $connection, array $payload): void
    {
        $groupId = self::stringFromMixed($payload['group_id'] ?? '');
        $memberId = self::stringFromMixed($payload['member_id'] ?? $connection->getId());
        $memberName = self::stringFromMixed($payload['member_name'] ?? 'User');
        $password = self::stringOrNullFromMixed($payload['password'] ?? null);

        $result = $this->joinGroup($groupId, $memberId, $memberName, $password);

        if ($result['success'] === true) {
            $connection->sendMessage(Messages::TYPE_GROUP_STATE, [
                'group' => $result['group'],
                'your_id' => $memberId,
            ]);
        } else {
            $this->sendError($connection, 'JOIN_FAILED', $result['error']);
        }
    }

    /**
     * Handle group leave request via WebSocket.
     *
     * @param Connection $connection The WebSocket connection
     * @param array<string, mixed> $payload Payload containing member_id
     * @return void
     *
     * @fires Messages::TYPE_INFO Sent on success
     * @fires Messages::TYPE_ERROR Sent on failure
     */
    private function handleGroupLeave(Connection $connection, array $payload): void
    {
        $memberId = self::stringFromMixed($payload['member_id'] ?? null);
        if ($memberId === '') {
            $this->sendError($connection, 'LEAVE_FAILED', 'Not in any group');
            return;
        }

        $result = $this->leaveGroup($memberId);

        if ($result['success'] === true) {
            $connection->sendMessage(Messages::TYPE_INFO, [
                'message' => $result['message'] ?? 'Left group',
            ]);
        } else {
            $this->sendError($connection, 'LEAVE_FAILED', $result['error']);
        }
    }

    /**
     * Broadcast a message to all members of a group.
     *
     * Sends a WebSocket message to all connected group members except those
     * in the excludeIds list.
     *
     * @param string $groupId The group ID to broadcast to
     * @param string $type The message type (see Messages constants)
     * @param array<string, mixed> $data The message data
     * @param array<string> $excludeIds Member IDs to exclude from the broadcast
     * @return void
     *
     * @see Messages For valid message type constants
     */
    private function broadcastToGroup(string $groupId, string $type, array $data, array $excludeIds = []): void
    {
        if ($this->messageHandler === null) {
            return;
        }

        $group = $this->groups[$groupId] ?? null;
        if ($group === null) {
            return;
        }

        foreach ($group->getMembers() as $memberId => $member) {
            if (in_array($memberId, $excludeIds, true)) {
                continue;
            }

            $connectionId = $member['connection_id'] ?? null;
            if ($connectionId !== null) {
                $this->messageHandler->sendToSession($connectionId, $type, $data);
            }
        }
    }

    /**
     * Send an error message to a specific connection.
     *
     * @param Connection $connection The WebSocket connection to send to
     * @param string $code Error code (e.g., 'NOT_IN_GROUP', 'NOT_HOST')
     * @param string $message Human-readable error message
     * @return void
     *
     * @see Messages::TYPE_ERROR For the error message format
     */
    private function sendError(Connection $connection, string $code, string $message): void
    {
        $connection->sendMessage(Messages::TYPE_ERROR, [
            'code' => $code,
            'message' => $message,
        ]);
    }

    /**
     * Get the TimeSync instance for this manager.
     *
     * The TimeSync component handles NTP-style time synchronization to ensure
     * accurate playback position calculation across network latency.
     *
     * @return TimeSync The time synchronization handler
     *
     * @see TimeSync For time synchronization details
     */
    public function getTimeSync(): TimeSync
    {
        return $this->timeSync;
    }

    /**
     * Get the group ID that a member is currently in.
     *
     * @param string $memberId The member ID to look up
     * @return string|null The group ID if found, null if member is not in any group
     *
     * @example
     * ```php
     * $groupId = $manager->getMemberGroup('member_123');
     * if ($groupId !== null) {
     *     // Member is in a group
     * }
     * ```
     */
    public function getMemberGroup(string $memberId): ?string
    {
        return $this->memberToGroup[$memberId] ?? null;
    }

    /**
     * Generate a unique group ID
     */
    private function generateGroupId(): string
    {
        return 'sp_' . bin2hex(random_bytes(8));
    }

    /**
     * Clean up stale/inactive groups.
     *
     * Removes groups that have had no activity (no members checking in) for
     * longer than the specified timeout. Members are notified before removal.
     *
     * @param int $timeout Timeout in seconds (default: 3600 = 1 hour)
     * @return int The number of groups removed
     *
     * @example
     * ```php
     * // Clean up groups inactive for more than 2 hours
     * $removed = $manager->cleanupStaleGroups(7200);
     * echo "Removed {$removed} stale groups";
     * ```
     */
    public function cleanupStaleGroups(int $timeout = self::GROUP_TIMEOUT): int
    {
        $now = time();
        $removed = 0;

        foreach ($this->groups as $id => $group) {
            if ($now - $group->getLastActivityAt() > $timeout) {
                // Notify members
                $this->broadcastToGroup($id, Messages::TYPE_INFO, [
                    'message' => 'Group timed out due to inactivity',
                ]);

                // Remove all members
                foreach ($group->getMembers() as $memberId => $member) {
                    unset($this->memberToGroup[$memberId]);
                }

                unset($this->groups[$id]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Get statistics about the SyncPlay subsystem.
     *
     * @return array{total_groups: int, total_members: int, time_sync_status: array{offset: int, latency: int, drift_rate: float, is_stable: bool, sample_count: int, last_sync: float}} Statistics
     *
     * @see TimeSync::getStatus() For the structure of time_sync_status
     */
    public function getStats(): array
    {
        return [
            'total_groups' => count($this->groups),
            'total_members' => count($this->memberToGroup),
            'time_sync_status' => $this->timeSync->getStatus(),
        ];
    }

    /**
     * Log a message using the configured logger.
     *
     * If no logger is configured, messages are silently discarded.
     *
     * @param string $level Log level (info, warning, error, etc.)
     * @param string $message Log message
     * @param array<string, mixed> $context Additional context data
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->log($level, "[SyncPlay] {$message}", $context);
    }

    /**
     * Coerce a mixed value to a string. Numeric values are stringified; null
     * and non-scalars collapse to the empty string. Centralises the WebSocket
     * payload narrowing so callers can rely on a typed string.
     */
    private static function stringFromMixed(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        return '';
    }

    /**
     * Coerce a mixed value to a nullable string. Null and non-stringable
     * values become null; otherwise behaves like {@see self::stringFromMixed()}.
     */
    private static function stringOrNullFromMixed(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return null;
    }

    /**
     * Coerce a mixed value to an int. Numeric strings and floats are accepted;
     * everything else falls back to 0.
     */
    private static function intFromMixed(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return 0;
    }
}
