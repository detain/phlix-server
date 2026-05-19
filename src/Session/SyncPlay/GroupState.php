<?php

declare(strict_types=1);

namespace Phlex\Session\SyncPlay;

/**
 * GroupState - Manages state for a SyncPlay group
 *
 * This class encapsulates all state for a single SyncPlay watching group,
 * including member management, playback state, queue management, and chat.
 *
 * ## Playback States
 *
 * - STATE_PLAYING: Media is actively playing
 * - STATE_PAUSED: Media is paused
 * - STATE_BUFFERING: Media is buffering (waiting for data)
 * - STATE_STOPPED: No media is loaded or playback is stopped
 *
 * ## Host Election
 *
 * When the host leaves the group, a new host is automatically elected
 * based on the oldest member (longest time in the group). This ensures
 * continuity even when the original host disconnects.
 *
 * ## Position Tolerance
 *
 * Position tolerance (default 2000ms) is used to determine if a member's
 * playback position is "in sync" with the group. Members outside this
 * tolerance may need to seek to catch up.
 *
 * @author Phlex Development Team
 * @copyright 2024 Phlex Media Server
 * @license Proprietary
 *
 * @see SyncPlayManager For group lifecycle management
 * @see TimeSync For time synchronization
 */
class GroupState
{
    /**
     * Playback state: Media is actively playing.
     */
    public const STATE_PLAYING = 'playing';

    /**
     * Playback state: Media is paused.
     */
    public const STATE_PAUSED = 'paused';

    /**
     * Playback state: Media is buffering.
     */
    public const STATE_BUFFERING = 'buffering';

    /**
     * Playback state: No media loaded or playback stopped.
     */
    public const STATE_STOPPED = 'stopped';

    /**
     * Maximum number of members allowed per group.
     */
    public const MAX_MEMBERS = 50;

    /**
     * Default playback position tolerance in milliseconds.
     *
     * Members whose position differs from host by more than this
     * are considered "out of sync".
     */
    public const POSITION_TOLERANCE = 2000;

    /** @var string Unique group identifier (format: sp_*) */
    private string $id;

    /** @var string Display name of the group */
    private string $name;

    /** @var string|null SHA256 hash of the group password, null if no password */
    private ?string $passwordHash = null;

    /** @var array<string, array{name: string, connection_id: string|null, joined_at: int, is_active: bool, is_host?: bool}> Group members indexed by member ID */
    private array $members = [];

    /** @var string|null The member ID of the current host, null if no host */
    private ?string $hostId = null;

    /** @var string|null The current media item ID being played */
    private ?string $currentMediaId = null;

    /** @var int Duration of the current media in milliseconds */
    private int $currentMediaDuration = 0;

    /** @var int Current playback position in milliseconds */
    private int $playbackPosition = 0;

    /** @var string Current playback state (one of STATE_*) */
    private string $playbackState = self::STATE_STOPPED;

    /** @var array<int, array{media_id: string, media_info: array<string, mixed>, added_at: int, added_by: string|null}> Playback queue items */
    private array $playbackQueue = [];

    /** @var array<int, array{member_id: string, message: string, timestamp: int}> Chat messages (max 100 stored) */
    private array $chatMessages = [];

    /** @var int Unix timestamp when the group was created */
    private int $createdAt;

    /** @var int Unix timestamp of the last group activity */
    private int $lastActivityAt;

    /** @var int Position tolerance in milliseconds for sync detection */
    private int $positionTolerance;

    public function __construct(
        string $id,
        string $name,
        ?string $passwordHash = null,
        int $positionTolerance = self::POSITION_TOLERANCE
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->passwordHash = $passwordHash;
        $this->positionTolerance = $positionTolerance;
        $this->createdAt = time();
        $this->lastActivityAt = time();
    }

    /**
     * Get the unique group identifier.
     *
     * @return string Group ID (format: sp_*)
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the group's display name.
     *
     * @return string The group name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Check if the group is password protected.
     *
     * @return bool True if a password is required to join
     */
    public function hasPassword(): bool
    {
        return $this->passwordHash !== null;
    }

    /**
     * Verify a password against the group's password.
     *
     * If the group has no password, this always returns true.
     * Uses timing-safe comparison to prevent timing attacks.
     *
     * @param string $password The password to verify
     * @return bool True if password is correct or no password required
     */
    public function verifyPassword(string $password): bool
    {
        if ($this->passwordHash === null) {
            return true;
        }

        return hash_equals($this->passwordHash, hash('sha256', $password));
    }

    /**
     * Get all members of the group.
     *
     * @return array<string, array{name: string, connection_id: string|null, joined_at: int, is_active: bool, is_host?: bool}> Members indexed by ID
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    /**
     * Get the number of members in the group.
     *
     * @return int Member count
     */
    public function getMemberCount(): int
    {
        return count($this->members);
    }

    /**
     * Check if a member exists in the group.
     *
     * @param string $memberId The member ID to check
     * @return bool True if member exists
     */
    public function hasMember(string $memberId): bool
    {
        return isset($this->members[$memberId]);
    }

    /**
     * Get a specific member's data.
     *
     * @param string $memberId The member ID to retrieve
     * @return array{name: string, connection_id: string|null, joined_at: int, is_active: bool, is_host?: bool}|null Member data array or null if not found
     */
    public function getMember(string $memberId): ?array
    {
        return $this->members[$memberId] ?? null;
    }

    /**
     * Add a member to the group.
     *
     * @param string $memberId Unique identifier for the member
     * @param array{name?: string, connection_id?: string|null} $memberData Member data including name and optional connection_id
     * @return bool True if added successfully, false if at capacity or duplicate
     */
    public function addMember(string $memberId, array $memberData): bool
    {
        if (count($this->members) >= self::MAX_MEMBERS) {
            return false;
        }

        if (isset($this->members[$memberId])) {
            return false;
        }

        $this->members[$memberId] = [
            'name' => $memberData['name'] ?? 'Unknown',
            'connection_id' => $memberData['connection_id'] ?? null,
            'joined_at' => time(),
            'is_active' => true,
        ];

        $this->lastActivityAt = time();

        return true;
    }

    /**
     * Remove a member from the group.
     *
     * If the removed member was the host, a new host will be automatically
     * elected from the remaining members.
     *
     * @param string $memberId The member ID to remove
     * @return bool True if removed, false if member not found
     */
    public function removeMember(string $memberId): bool
    {
        if (!isset($this->members[$memberId])) {
            return false;
        }

        unset($this->members[$memberId]);

        // If host left, elect new host
        if ($this->hostId === $memberId) {
            $this->electNewHost();
        }

        $this->lastActivityAt = time();

        return true;
    }

    /**
     * Update a member's data.
     *
     * Only the fields known to the member shape are accepted; unknown keys are
     * ignored to preserve the typed structure.
     *
     * @param string $memberId The member ID to update
     * @param array<string, mixed> $updates Key-value pairs to update
     * @return bool True if updated, false if member not found
     */
    public function updateMember(string $memberId, array $updates): bool
    {
        if (!isset($this->members[$memberId])) {
            return false;
        }

        $current = $this->members[$memberId];

        if (array_key_exists('name', $updates) && is_string($updates['name'])) {
            $current['name'] = $updates['name'];
        }
        if (array_key_exists('connection_id', $updates)) {
            $rawConn = $updates['connection_id'];
            $current['connection_id'] = is_string($rawConn) ? $rawConn : null;
        }
        if (array_key_exists('joined_at', $updates) && is_int($updates['joined_at'])) {
            $current['joined_at'] = $updates['joined_at'];
        }
        if (array_key_exists('is_active', $updates) && is_bool($updates['is_active'])) {
            $current['is_active'] = $updates['is_active'];
        }
        if (array_key_exists('is_host', $updates) && is_bool($updates['is_host'])) {
            $current['is_host'] = $updates['is_host'];
        }

        $this->members[$memberId] = $current;
        $this->lastActivityAt = time();

        return true;
    }

    /**
     * Get the current host's member ID.
     *
     * @return string|null The host's member ID, or null if no host
     */
    public function getHostId(): ?string
    {
        return $this->hostId;
    }

    /**
     * Set a member as the group host.
     *
     * @param string $hostId The member ID to set as host
     * @return bool True if set successfully, false if member not found
     */
    public function setHost(string $hostId): bool
    {
        if (!isset($this->members[$hostId])) {
            return false;
        }

        $this->hostId = $hostId;
        $this->members[$hostId]['is_host'] = true;

        $this->lastActivityAt = time();

        return true;
    }

    /**
     * Elect a new host when the current host leaves.
     *
     * The new host is selected based on the oldest member (earliest joined).
     * If no members remain, returns null.
     *
     * @return string|null The new host's member ID, or null if group is empty
     */
    public function electNewHost(): ?string
    {
        if (empty($this->members)) {
            $this->hostId = null;
            return null;
        }

        // Get the oldest member as fallback
        $oldestMember = null;
        $oldestTime = PHP_INT_MAX;

        foreach ($this->members as $id => $member) {
            $joinedAt = $member['joined_at'] ?? 0;
            if ($joinedAt < $oldestTime) {
                $oldestTime = $joinedAt;
                $oldestMember = $id;
            }
        }

        if ($this->hostId !== null && isset($this->members[$this->hostId])) {
            $this->members[$this->hostId]['is_host'] = false;
        }

        $this->hostId = $oldestMember;

        if ($oldestMember !== null) {
            $this->members[$oldestMember]['is_host'] = true;
        }

        return $this->hostId;
    }

    /**
     * Check if a member is the group host.
     *
     * @param string $memberId The member ID to check
     * @return bool True if the member is the host
     */
    public function isHost(string $memberId): bool
    {
        return $this->hostId === $memberId;
    }

    /**
     * Get the current media item ID.
     *
     * @return string|null The media ID or null if no media is loaded
     */
    public function getCurrentMediaId(): ?string
    {
        return $this->currentMediaId;
    }

    /**
     * Get the duration of the current media.
     *
     * @return int Duration in milliseconds
     */
    public function getCurrentMediaDuration(): int
    {
        return $this->currentMediaDuration;
    }

    /**
     * Get the current playback position.
     *
     * @return int Position in milliseconds
     */
    public function getPlaybackPosition(): int
    {
        return $this->playbackPosition;
    }

    /**
     * Get the current playback state.
     *
     * @return string One of STATE_PLAYING, STATE_PAUSED, STATE_BUFFERING, STATE_STOPPED
     */
    public function getPlaybackState(): string
    {
        return $this->playbackState;
    }

    /**
     * Check if media is currently playing.
     *
     * @return bool True if playback state is STATE_PLAYING
     */
    public function isPlaying(): bool
    {
        return $this->playbackState === self::STATE_PLAYING;
    }

    /**
     * Set the current media item to play.
     *
     * @param string|null $mediaId The media item ID, or null to clear
     * @param int $duration Duration in milliseconds (default: 0)
     * @return void
     */
    public function setCurrentMedia(?string $mediaId, int $duration = 0): void
    {
        $this->currentMediaId = $mediaId;
        $this->currentMediaDuration = $duration;
        $this->playbackPosition = 0;
        $this->playbackState = self::STATE_STOPPED;
        $this->lastActivityAt = time();
    }

    /**
     * Update the playback state and position.
     *
     * @param string $state One of STATE_PLAYING, STATE_PAUSED, STATE_BUFFERING, STATE_STOPPED
     * @param int $position Current position in milliseconds
     * @return void
     */
    public function updatePlayback(string $state, int $position): void
    {
        $this->playbackState = $state;
        $this->playbackPosition = $position;
        $this->lastActivityAt = time();
    }

    /**
     * Set the playback position without changing state.
     *
     * Used during synchronization when receiving seek commands.
     *
     * @param int $position New position in milliseconds
     * @return void
     */
    public function setPlaybackPosition(int $position): void
    {
        $this->playbackPosition = $position;
        $this->lastActivityAt = time();
    }

    /**
     * Get the current playback queue.
     *
     * @return array<int, array{media_id: string, media_info: array<string, mixed>, added_at: int, added_by: string|null}> Queue items
     */
    public function getPlaybackQueue(): array
    {
        return $this->playbackQueue;
    }

    /**
     * Add an item to the playback queue.
     *
     * @param string $mediaId The media item ID to add
     * @param array<string, mixed> $mediaInfo Additional media information (title, thumbnail, etc.)
     * @return void
     */
    public function addToQueue(string $mediaId, array $mediaInfo): void
    {
        $this->playbackQueue[] = [
            'media_id' => $mediaId,
            'media_info' => $mediaInfo,
            'added_at' => time(),
            'added_by' => $this->hostId,
        ];
        $this->lastActivityAt = time();
    }

    /**
     * Remove an item from the playback queue.
     *
     * @param string $mediaId The media ID to remove
     * @return bool True if found and removed
     */
    public function removeFromQueue(string $mediaId): bool
    {
        foreach ($this->playbackQueue as $index => $item) {
            if ($item['media_id'] === $mediaId) {
                array_splice($this->playbackQueue, $index, 1);
                $this->lastActivityAt = time();
                return true;
            }
        }
        return false;
    }

    /**
     * Clear all items from the playback queue.
     *
     * @return void
     */
    public function clearQueue(): void
    {
        $this->playbackQueue = [];
        $this->lastActivityAt = time();
    }

    /**
     * Get the next item in the queue without removing it.
     *
     * @return array{media_id: string, media_info: array<string, mixed>, added_at: int, added_by: string|null}|null The first queue item or null if queue is empty
     */
    public function getNextInQueue(): ?array
    {
        return $this->playbackQueue[0] ?? null;
    }

    /**
     * Get recent chat messages.
     *
     * Returns the most recent messages up to the specified limit.
     * Messages are returned in chronological order (oldest first).
     *
     * @param int $limit Maximum number of messages to return (default: 50, max: 100)
     * @return array<int, array{member_id: string, message: string, timestamp: int}> Chat messages
     */
    public function getChatMessages(int $limit = 50): array
    {
        return array_slice($this->chatMessages, -$limit);
    }

    /**
     * Add a chat message to the group chat.
     *
     * Messages are stored in a rolling buffer of up to 100 messages.
     * Older messages are automatically discarded when the limit is exceeded.
     *
     * @param string $memberId The ID of the member sending the message
     * @param string $message The chat message content
     * @return void
     */
    public function addChatMessage(string $memberId, string $message): void
    {
        $this->chatMessages[] = [
            'member_id' => $memberId,
            'message' => $message,
            'timestamp' => time(),
        ];

        // Keep only last 100 messages
        if (count($this->chatMessages) > 100) {
            array_shift($this->chatMessages);
        }

        $this->lastActivityAt = time();
    }

    /**
     * Get the timestamp when the group was created.
     *
     * @return int Unix timestamp
     */
    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    /**
     * Get the timestamp of the last group activity.
     *
     * Activity includes member joins/leaves, playback commands, and chat messages.
     * Used for stale group cleanup.
     *
     * @return int Unix timestamp
     */
    public function getLastActivityAt(): int
    {
        return $this->lastActivityAt;
    }

    /**
     * Get the position tolerance setting.
     *
     * @return int Tolerance in milliseconds
     */
    public function getPositionTolerance(): int
    {
        return $this->positionTolerance;
    }

    /**
     * Check if a member's position is in sync with the group.
     *
     * When playback is active, compares the member's position against
     * the host's position within the tolerance threshold. Always returns
     * true when not playing (paused/stopped positions don't need sync).
     *
     * @param int $memberPosition The member's playback position in milliseconds
     * @return bool True if in sync or not playing, false if out of sync
     */
    public function isInSync(int $memberPosition): bool
    {
        if ($this->playbackState !== self::STATE_PLAYING) {
            return true;
        }

        return abs($memberPosition - $this->playbackPosition) <= $this->positionTolerance;
    }

    /**
     * Get the full group state for broadcasting to clients.
     *
     * Returns a comprehensive state array including members list,
     * playback info, queue, and timestamps.
     *
     * @return array<string, mixed> Full group state
     *
     * @example
     * ```php
     * $state = $group->getState();
     * // ['group_id' => 'sp_abc123', 'group_name' => 'Movie Night', 'members' => [...], ...]
     * ```
     */
    public function getState(): array
    {
        $membersList = [];
        foreach ($this->members as $id => $member) {
            $membersList[] = [
                'id' => $id,
                'name' => $member['name'] ?? 'Unknown',
                'is_host' => $id === $this->hostId,
                'joined_at' => $member['joined_at'] ?? time(),
            ];
        }

        return [
            'group_id' => $this->id,
            'group_name' => $this->name,
            'member_count' => $this->getMemberCount(),
            'members' => $membersList,
            'host_id' => $this->hostId,
            'current_media_id' => $this->currentMediaId,
            'current_media_duration' => $this->currentMediaDuration,
            'playback_position' => $this->playbackPosition,
            'playback_state' => $this->playbackState,
            'queue' => $this->playbackQueue,
            'created_at' => $this->createdAt,
            'last_activity_at' => $this->lastActivityAt,
        ];
    }

    /**
     * Serialize group state for persistence.
     *
     * Creates an array representation of the group state that can be
     * stored and later restored using deserialize().
     *
     * @return array<string, mixed> Serialized group state
     *
     * @see deserialize() For restoring serialized state
     */
    public function serialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'password_hash' => $this->passwordHash,
            'members' => $this->members,
            'host_id' => $this->hostId,
            'current_media_id' => $this->currentMediaId,
            'current_media_duration' => $this->currentMediaDuration,
            'playback_position' => $this->playbackPosition,
            'playback_state' => $this->playbackState,
            'playback_queue' => $this->playbackQueue,
            'chat_messages' => $this->chatMessages,
            'created_at' => $this->createdAt,
            'last_activity_at' => $this->lastActivityAt,
            'position_tolerance' => $this->positionTolerance,
        ];
    }

    /**
     * Restore a group state from serialized data.
     *
     * Reconstructs a GroupState instance from data previously created
     * by serialize().
     *
     * @param array<string, mixed> $data Serialized group state
     * @return self Restored group state instance
     *
     * @see serialize() For creating serializable state
     */
    public static function deserialize(array $data): self
    {
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;
        if (!is_string($id) || !is_string($name)) {
            throw new \InvalidArgumentException('GroupState::deserialize requires string id and name');
        }

        $passwordHashRaw = $data['password_hash'] ?? null;
        $passwordHash = is_string($passwordHashRaw) ? $passwordHashRaw : null;

        $positionToleranceRaw = $data['position_tolerance'] ?? self::POSITION_TOLERANCE;
        $positionTolerance = is_int($positionToleranceRaw) ? $positionToleranceRaw : self::POSITION_TOLERANCE;

        $group = new self($id, $name, $passwordHash, $positionTolerance);

        $group->members = self::deserializeMembers($data['members'] ?? []);

        $hostIdRaw = $data['host_id'] ?? null;
        $group->hostId = is_string($hostIdRaw) ? $hostIdRaw : null;

        $currentMediaIdRaw = $data['current_media_id'] ?? null;
        $group->currentMediaId = is_string($currentMediaIdRaw) ? $currentMediaIdRaw : null;

        $currentMediaDurationRaw = $data['current_media_duration'] ?? 0;
        $group->currentMediaDuration = is_int($currentMediaDurationRaw) ? $currentMediaDurationRaw : 0;

        $playbackPositionRaw = $data['playback_position'] ?? 0;
        $group->playbackPosition = is_int($playbackPositionRaw) ? $playbackPositionRaw : 0;

        $playbackStateRaw = $data['playback_state'] ?? self::STATE_STOPPED;
        $group->playbackState = is_string($playbackStateRaw) ? $playbackStateRaw : self::STATE_STOPPED;

        $group->playbackQueue = self::deserializeQueue($data['playback_queue'] ?? []);
        $group->chatMessages = self::deserializeChatMessages($data['chat_messages'] ?? []);

        $createdAtRaw = $data['created_at'] ?? null;
        $group->createdAt = is_int($createdAtRaw) ? $createdAtRaw : time();

        $lastActivityRaw = $data['last_activity_at'] ?? null;
        $group->lastActivityAt = is_int($lastActivityRaw) ? $lastActivityRaw : time();

        return $group;
    }

    /**
     * Narrow a raw `members` payload into the typed members shape.
     *
     * @param mixed $raw
     * @return array<string, array{name: string, connection_id: string|null, joined_at: int, is_active: bool, is_host?: bool}>
     */
    private static function deserializeMembers(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $member) {
            if (!is_string($key) || !is_array($member)) {
                continue;
            }

            $name = $member['name'] ?? 'Unknown';
            $connId = $member['connection_id'] ?? null;
            $joinedAt = $member['joined_at'] ?? 0;
            $isActive = $member['is_active'] ?? true;

            $entry = [
                'name' => is_string($name) ? $name : 'Unknown',
                'connection_id' => is_string($connId) ? $connId : null,
                'joined_at' => is_int($joinedAt) ? $joinedAt : 0,
                'is_active' => is_bool($isActive) ? $isActive : true,
            ];

            if (isset($member['is_host']) && is_bool($member['is_host'])) {
                $entry['is_host'] = $member['is_host'];
            }

            $out[$key] = $entry;
        }

        return $out;
    }

    /**
     * Narrow a raw `playback_queue` payload into the typed queue shape.
     *
     * @param mixed $raw
     * @return array<int, array{media_id: string, media_info: array<string, mixed>, added_at: int, added_by: string|null}>
     */
    private static function deserializeQueue(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mediaId = $item['media_id'] ?? null;
            if (!is_string($mediaId)) {
                continue;
            }
            $mediaInfo = $item['media_info'] ?? [];
            $addedAt = $item['added_at'] ?? 0;
            $addedBy = $item['added_by'] ?? null;

            $mediaInfoOut = [];
            if (is_array($mediaInfo)) {
                foreach ($mediaInfo as $infoKey => $infoValue) {
                    if (is_string($infoKey)) {
                        $mediaInfoOut[$infoKey] = $infoValue;
                    }
                }
            }

            $out[] = [
                'media_id' => $mediaId,
                'media_info' => $mediaInfoOut,
                'added_at' => is_int($addedAt) ? $addedAt : 0,
                'added_by' => is_string($addedBy) ? $addedBy : null,
            ];
        }

        return $out;
    }

    /**
     * Narrow a raw `chat_messages` payload into the typed chat shape.
     *
     * @param mixed $raw
     * @return array<int, array{member_id: string, message: string, timestamp: int}>
     */
    private static function deserializeChatMessages(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $memberId = $msg['member_id'] ?? null;
            $message = $msg['message'] ?? null;
            $timestamp = $msg['timestamp'] ?? null;
            if (is_string($memberId) && is_string($message) && is_int($timestamp)) {
                $out[] = [
                    'member_id' => $memberId,
                    'message' => $message,
                    'timestamp' => $timestamp,
                ];
            }
        }

        return $out;
    }

    /**
     * Create a SHA256 hash of a password.
     *
     * @param string $password The plaintext password
     * @return string 64-character hex string (SHA256)
     */
    public static function hashPassword(string $password): string
    {
        return hash('sha256', $password);
    }
}
