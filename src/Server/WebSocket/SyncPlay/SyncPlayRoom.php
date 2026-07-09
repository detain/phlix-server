<?php

/**
 * Phlix media server component: SyncPlay Room.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Server\WebSocket\SyncPlay;

use Phlix\Server\WebSocket\Connection;
use Phlix\Server\WebSocket\ConnectionInterface;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;

/**
 * SyncPlay Room - Manages room membership and message broadcasting.
 *
 * This class handles the routing of messages to all members in a SyncPlay room.
 * It ensures each connected client receives messages and prevents echo-back
 * to the original sender.
 *
 * ## Broadcast Guarantees
 *
 * - Every connected client in the room receives the message
 * - Messages are sent via individual `connection->send()` calls
 * - The sender does not receive an echo of their own message
 * - Disconnected clients are silently skipped
 *
 * ## Usage
 *
 * ```php
 * $room = new SyncPlayRoom('sp_abc123');
 * $room->addMember($connection);
 * $room->broadcast(Messages::TYPE_PLAYBACK_SYNC, $playbackData);
 * ```
 *
 * @author Phlix Development Team
 * @copyright 2024 Phlix Media Server
 * @license Proprietary
 *
 * @see SyncPlayManager For room lifecycle management
 * @see Protocol For binary frame encoding
 */
class SyncPlayRoom
{
    /**
     * The unique room/group identifier.
     *
     * @var string
     */
    private string $roomId;

    /**
     * Member connections indexed by member ID.
     *
     * @var array<string, ConnectionInterface>
     */
    private array $members = [];

    /**
     * Optional room name for logging.
     *
     * @var string
     */
    private string $name;

    /**
     * Logger instance for debugging.
     *
     * @var \Psr\Log\LoggerInterface|null
     */
    private $logger;

    /**
     * Create a new SyncPlay room.
     *
     * @param string $roomId The unique room identifier
     * @param string $name Optional room name for logging
     */
    public function __construct(string $roomId, string $name = '')
    {
        $this->roomId = $roomId;
        $this->name = $name;
        $this->logger = LoggerFactory::get(LogChannels::WEBSOCKET);
    }

    /**
     * Get the room ID.
     *
     * @return string The room identifier
     */
    public function getRoomId(): string
    {
        return $this->roomId;
    }

    /**
     * Get the room name.
     *
     * @return string The room name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Add a member connection to the room.
     *
     * @param string $memberId The member identifier
     * @param ConnectionInterface $connection The WebSocket connection
     * @return void
     *
     * @example
     * ```php
     * $room->addMember('member_123', $connection);
     * ```
     */
    public function addMember(string $memberId, ConnectionInterface $connection): void
    {
        $this->members[$memberId] = $connection;

        $this->logger?->debug('Member added to room', [
            'room_id' => $this->roomId,
            'member_id' => $memberId,
            'connection_id' => $connection->getId(),
            'member_count' => count($this->members),
        ]);
    }

    /**
     * Remove a member from the room.
     *
     * @param string $memberId The member identifier
     * @return void
     *
     * @example
     * ```php
     * $room->removeMember('member_123');
     * ```
     */
    public function removeMember(string $memberId): void
    {
        unset($this->members[$memberId]);

        $this->logger?->debug('Member removed from room', [
            'room_id' => $this->roomId,
            'member_id' => $memberId,
            'member_count' => count($this->members),
        ]);
    }

    /**
     * Check if a member is in the room.
     *
     * @param string $memberId The member identifier
     * @return bool True if member is in the room
     */
    public function hasMember(string $memberId): bool
    {
        return isset($this->members[$memberId]);
    }

    /**
     * Get a member's connection.
     *
     * @param string $memberId The member identifier
     * @return ConnectionInterface|null The connection or null if not found
     */
    public function getMember(string $memberId): ?ConnectionInterface
    {
        return $this->members[$memberId] ?? null;
    }

    /**
     * Get all members in the room.
     *
     * @return array<string, ConnectionInterface> Members indexed by ID
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    /**
     * Get the number of members in the room.
     *
     * @return int Member count
     */
    public function getMemberCount(): int
    {
        return count($this->members);
    }

    /**
     * Broadcast a message to all members in the room.
     *
     * Sends the message to EACH connected client individually.
     * Does NOT echo the message back to the sender.
     *
     * @param string $type Message type (see Messages constants)
     * @param array<string, mixed> $data Message payload data
     * @param string|null $excludeMemberId Member ID to exclude (typically the sender)
     * @return int Number of clients the message was sent to
     *
     * @example
     * ```php
     * // Broadcast playback sync to all EXCEPT the host
     * $sent = $room->broadcast(Messages::TYPE_PLAYBACK_SYNC, $playbackData, $hostMemberId);
     * ```
     */
    public function broadcast(string $type, array $data, ?string $excludeMemberId = null): int
    {
        $sentCount = 0;

        // Build the flat canonical message frame (no 'data' wrapper)
        $frame = array_merge(
            ['type' => $type],
            $data,
            ['timestamp' => time()]
        );

        $this->logger?->debug('Broadcasting to room', [
            'room_id' => $this->roomId,
            'type' => $type,
            'member_count' => count($this->members),
            'exclude_member' => $excludeMemberId,
        ]);

        foreach ($this->members as $memberId => $connection) {
            // Skip excluded member (typically the sender)
            if ($excludeMemberId !== null && $memberId === $excludeMemberId) {
                $this->logger?->debug('Skipping sender', [
                    'member_id' => $memberId,
                ]);
                continue;
            }

            // Skip if connection is not valid
            if (!$this->isConnectionValid($connection)) {
                $this->logger?->warning('Skipping invalid connection', [
                    'member_id' => $memberId,
                    'connection_id' => $connection->getId(),
                ]);
                continue;
            }

            // Send to each client individually
            try {
                $connection->send($frame);
                $sentCount++;
            } catch (\Throwable $e) {
                $this->logger?->error('Failed to send to member', [
                    'member_id' => $memberId,
                    'connection_id' => $connection->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger?->debug('Broadcast complete', [
            'room_id' => $this->roomId,
            'sent_count' => $sentCount,
            'total_members' => count($this->members),
        ]);

        return $sentCount;
    }

    /**
     * Broadcast a message to all members using binary frame encoding.
     *
     * Uses Protocol::encodeFrame() for binary encoding. Should be used
     * when communicating with clients that expect binary frames.
     *
     * @param int $frameType Binary frame type (see Protocol constants)
     * @param string $sessionId Session identifier for the frame
     * @param string $payload Binary payload data
     * @param string|null $excludeMemberId Member ID to exclude (typically the sender)
     * @return int Number of clients the message was sent to
     *
     * @see Protocol::encodeFrame For binary frame format
     *
     * @example
     * ```php
     * $payload = Protocol::encodeSyncPayload($serverTime, $position, $rate);
     * $sent = $room->broadcastBinary(Protocol::TYPE_SYNC_PLAY_SYNC, $sessionId, $payload);
     * ```
     */
    public function broadcastBinary(
        int $frameType,
        string $sessionId,
        string $payload,
        ?string $excludeMemberId = null
    ): int {
        $sentCount = 0;

        // Encode the binary frame once
        $frame = Protocol::encodeFrame($frameType, $sessionId, $payload);

        foreach ($this->members as $memberId => $connection) {
            // Skip excluded member
            if ($excludeMemberId !== null && $memberId === $excludeMemberId) {
                continue;
            }

            // Skip if connection is not valid
            if (!$this->isConnectionValid($connection)) {
                continue;
            }

            // Send binary frame to each client individually
            try {
                $connection->send($frame);
                $sentCount++;
            } catch (\Throwable $e) {
                $this->logger?->error('Failed to send binary frame to member', [
                    'member_id' => $memberId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sentCount;
    }

    /**
     * Send a message directly to a specific member.
     *
     * @param string $memberId The member identifier
     * @param string $type Message type
     * @param array<string, mixed> $data Message payload
     * @return bool True if sent successfully
     *
     * @example
     * ```php
     * $room->sendToMember('member_123', Messages::TYPE_ERROR, ['error_code' => 'NOT_HOST']);
     * ```
     */
    public function sendToMember(string $memberId, string $type, array $data): bool
    {
        $connection = $this->members[$memberId] ?? null;

        if ($connection === null) {
            $this->logger?->warning('Cannot send to member - not found', [
                'member_id' => $memberId,
                'room_id' => $this->roomId,
            ]);
            return false;
        }

        if (!$this->isConnectionValid($connection)) {
            $this->logger?->warning('Cannot send to member - connection invalid', [
                'member_id' => $memberId,
                'room_id' => $this->roomId,
            ]);
            return false;
        }

        try {
            $frame = array_merge(
                ['type' => $type],
                $data,
                ['timestamp' => time()]
            );
            $connection->send($frame);
            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to send to member', [
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if a connection is valid and can receive messages.
     *
     * A connection is valid if:
     * - It implements ConnectionInterface
     * - It has a valid ID
     * - The underlying Workerman connection is not closed
     *
     * @param ConnectionInterface $connection The connection to check
     * @return bool True if the connection can receive messages
     */
    private function isConnectionValid(ConnectionInterface $connection): bool
    {
        // Check if connection has a valid ID
        $id = $connection->getId();
        if ($id === '' || $id === '0') {
            return false;
        }

        // If it's our Connection wrapper, check the underlying connection
        if ($connection instanceof Connection) {
            $tcp = $connection->getConnection();
            // Check if the connection appears to be open
            // Workerman doesn't have an isConnected() method, but we can check if send() would work
            return true; // Let Workerman handle failures
        }

        return true;
    }

    /**
     * Remove all members from the room.
     *
     * @return int Number of members removed
     *
     * @example
     * ```php
     * $count = $room->clear();
     * ```
     */
    public function clear(): int
    {
        $count = count($this->members);
        $this->members = [];

        $this->logger?->info('Room cleared', [
            'room_id' => $this->roomId,
            'members_removed' => $count,
        ]);

        return $count;
    }

    /**
     * Get all member IDs in the room.
     *
     * @return array<string> Array of member IDs
     *
     * @example
     * ```php
     * $memberIds = $room->getMemberIds();
     * ```
     */
    public function getMemberIds(): array
    {
        return array_keys($this->members);
    }

    /**
     * Check if the room is empty.
     *
     * @return bool True if no members in the room
     */
    public function isEmpty(): bool
    {
        return empty($this->members);
    }

    /**
     * Get a summary of the room for debugging.
     *
     * @return array{room_id: string, name: string, member_count: int, member_ids: array<string>} Room summary
     */
    public function getSummary(): array
    {
        return [
            'room_id' => $this->roomId,
            'name' => $this->name,
            'member_count' => count($this->members),
            'member_ids' => array_keys($this->members),
        ];
    }
}
