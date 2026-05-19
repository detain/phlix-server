<?php

declare(strict_types=1);

namespace Phlex\Session\SyncPlay;

/**
 * Messages - WebSocket message types for SyncPlay protocol
 *
 * This class defines all message type constants and provides factory methods
 * for creating properly formatted SyncPlay protocol messages.
 *
 * ## Message Categories
 *
 * **Group Management Messages:**
 * - TYPE_GROUP_CREATE, TYPE_GROUP_JOIN, TYPE_GROUP_LEAVE
 * - TYPE_GROUP_STATE (sent by host to broadcast group state)
 * - TYPE_GROUP_LIST (for browsing available groups)
 *
 * **Playback Control Messages:**
 * - TYPE_PLAYBACK_PLAY, TYPE_PLAYBACK_PAUSE, TYPE_PLAYBACK_SEEK
 * - TYPE_PLAYBACK_QUEUE (host updates the playback queue)
 * - TYPE_PLAYBACK_SYNC (periodic sync broadcast from host)
 *
 * **Chat Messages:**
 * - TYPE_CHAT_MESSAGE (member chat messages)
 * - TYPE_CHAT_TYPING (typing indicator)
 *
 * **Host Management:**
 * - TYPE_HOST_TRANSFER (voluntary host transfer)
 * - TYPE_HOST_ELECT (automatic host election when host leaves)
 *
 * **Time Synchronization:**
 * - TYPE_TIME_PING, TYPE_TIME_PONG (NTP-style time sync)
 * - TYPE_TIME_SYNC (full sync state broadcast)
 *
 * **Informational:**
 * - TYPE_ERROR (error notifications)
 * - TYPE_INFO (informational messages)
 *
 * ## Protocol Version
 *
 * The current protocol version is 1. All messages include a protocol_version
 * field for future compatibility and version negotiation.
 *
 * @author Phlex Development Team
 * @copyright 2024 Phlex Media Server
 * @license Proprietary
 *
 * @see SyncPlayManager For message handling
 */
final class Messages
{
    /*
     * ========================================================================
     * GROUP MANAGEMENT MESSAGES
     * ========================================================================
     */

    /** Message type: Create a new SyncPlay group */
    public const TYPE_GROUP_CREATE = 'syncplay_group_create';

    /** Message type: Join an existing SyncPlay group */
    public const TYPE_GROUP_JOIN = 'syncplay_group_join';

    /** Message type: Leave current SyncPlay group */
    public const TYPE_GROUP_LEAVE = 'syncplay_group_leave';

    /** Message type: Full group state broadcast (from host) */
    public const TYPE_GROUP_STATE = 'syncplay_group_state';

    /** Message type: Request list of available groups */
    public const TYPE_GROUP_LIST = 'syncplay_group_list';

    /*
     * ========================================================================
     * PLAYBACK CONTROL MESSAGES
     * ========================================================================
     */

    /** Message type: Start/resume playback */
    public const TYPE_PLAYBACK_PLAY = 'syncplay_playback_play';

    /** Message type: Pause playback */
    public const TYPE_PLAYBACK_PAUSE = 'syncplay_playback_pause';

    /** Message type: Seek to new position */
    public const TYPE_PLAYBACK_SEEK = 'syncplay_playback_seek';

    /** Message type: Update playback queue */
    public const TYPE_PLAYBACK_QUEUE = 'syncplay_playback_queue';

    /** Message type: Periodic playback sync broadcast */
    public const TYPE_PLAYBACK_SYNC = 'syncplay_playback_sync';

    /*
     * ========================================================================
     * CHAT MESSAGES
     * ========================================================================
     */

    /** Message type: Chat message */
    public const TYPE_CHAT_MESSAGE = 'syncplay_chat';

    /** Message type: Typing indicator */
    public const TYPE_CHAT_TYPING = 'syncplay_typing';

    /*
     * ========================================================================
     * HOST MANAGEMENT MESSAGES
     * ========================================================================
     */

    /** Message type: Voluntary host transfer */
    public const TYPE_HOST_TRANSFER = 'syncplay_host_transfer';

    /** Message type: Automatic host election */
    public const TYPE_HOST_ELECT = 'syncplay_host_elect';

    /*
     * ========================================================================
     * TIME SYNCHRONIZATION MESSAGES
     * ========================================================================
     */

    /** Message type: Time sync ping (client → server) */
    public const TYPE_TIME_PING = 'syncplay_time_ping';

    /** Message type: Time sync pong (server → client) */
    public const TYPE_TIME_PONG = 'syncplay_time_pong';

    /** Message type: Full time sync state broadcast */
    public const TYPE_TIME_SYNC = 'syncplay_time_sync';

    /*
     * ========================================================================
     * INFORMATIONAL MESSAGES
     * ========================================================================
     */

    /** Message type: Error notification */
    public const TYPE_ERROR = 'syncplay_error';

    /** Message type: General informational message */
    public const TYPE_INFO = 'syncplay_info';

    /**
     * Current protocol version.
     *
     * All messages must include this version. Messages with higher versions
     * will be rejected to prevent compatibility issues.
     */
    public const PROTOCOL_VERSION = 1;

    /**
     * All valid message type constants.
     *
     * Used for validation in isValidType() and validate().
     *
     * @var array<int, string>
     */
    private const VALID_TYPES = [
        self::TYPE_GROUP_CREATE,
        self::TYPE_GROUP_JOIN,
        self::TYPE_GROUP_LEAVE,
        self::TYPE_GROUP_STATE,
        self::TYPE_GROUP_LIST,
        self::TYPE_PLAYBACK_PLAY,
        self::TYPE_PLAYBACK_PAUSE,
        self::TYPE_PLAYBACK_SEEK,
        self::TYPE_PLAYBACK_QUEUE,
        self::TYPE_PLAYBACK_SYNC,
        self::TYPE_CHAT_MESSAGE,
        self::TYPE_CHAT_TYPING,
        self::TYPE_HOST_TRANSFER,
        self::TYPE_HOST_ELECT,
        self::TYPE_TIME_PING,
        self::TYPE_TIME_PONG,
        self::TYPE_TIME_SYNC,
        self::TYPE_ERROR,
        self::TYPE_INFO,
    ];

    /**
     * Check if a message type is valid.
     *
     * @param string $type The message type to validate
     * @return bool True if the type is a valid SyncPlay message type
     */
    public static function isValidType(string $type): bool
    {
        return in_array($type, self::VALID_TYPES, true);
    }

    /**
     * Get all valid message types.
     *
     * @return array<int, string> List of all valid message type constants
     */
    public static function getValidTypes(): array
    {
        return self::VALID_TYPES;
    }

    /**
     * Create a group creation request message.
     *
     * @param string $groupName The desired group name
     * @param string|null $password Optional password to protect the group
     * @return array{type: string, protocol_version: int, group_name: string, password_hash?: string, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::groupCreate('Movie Night');
     * $msg = Messages::groupCreate('Private Watch Party', 'secret');
     * ```
     */
    public static function groupCreate(string $groupName, ?string $password = null): array
    {
        $message = [
            'type' => self::TYPE_GROUP_CREATE,
            'protocol_version' => self::PROTOCOL_VERSION,
            'group_name' => $groupName,
            'timestamp' => self::getCurrentTimestamp(),
        ];

        if ($password !== null) {
            $message['password_hash'] = self::hashPassword($password);
        }

        return $message;
    }

    /**
     * Create a group join request message.
     *
     * @param string $groupId The group ID to join
     * @param string|null $password Optional password if group is protected
     * @return array{type: string, protocol_version: int, group_id: string, password_hash?: string, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::groupJoin('sp_abc123def456');
     * $msg = Messages::groupJoin('sp_abc123', 'secret');
     * ```
     */
    public static function groupJoin(string $groupId, ?string $password = null): array
    {
        $message = [
            'type' => self::TYPE_GROUP_JOIN,
            'protocol_version' => self::PROTOCOL_VERSION,
            'group_id' => $groupId,
            'timestamp' => self::getCurrentTimestamp(),
        ];

        if ($password !== null) {
            $message['password_hash'] = self::hashPassword($password);
        }

        return $message;
    }

    /**
     * Create a group leave request message.
     *
     * @param string $groupId The group ID to leave
     * @param string $memberId The member ID leaving
     * @return array{type: string, protocol_version: int, group_id: string, member_id: string, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::groupLeave('sp_abc123', 'member_456');
     * ```
     */
    public static function groupLeave(string $groupId, string $memberId): array
    {
        return [
            'type' => self::TYPE_GROUP_LEAVE,
            'protocol_version' => self::PROTOCOL_VERSION,
            'group_id' => $groupId,
            'member_id' => $memberId,
            'timestamp' => self::getCurrentTimestamp(),
        ];
    }

    /**
     * Create a group state message (broadcast by host to all members).
     *
     * @param string $groupId The group ID
     * @param array<int, array{id: string, name: string, is_host?: bool, joined_at?: int}> $members List of members
     * @param string|null $currentMediaId Current media being played
     * @param int|null $playbackPosition Current playback position in ms
     * @param string|null $playbackState Current playback state
     * @param string|null $hostId Current host's member ID
     * @return array<string, mixed> The message array
     *
     * @example
     * ```php
     * $msg = Messages::groupState('sp_abc123', $members, 'media_456', 5000, 'playing', 'host_789');
     * ```
     */
    public static function groupState(
        string $groupId,
        array $members,
        ?string $currentMediaId = null,
        ?int $playbackPosition = null,
        ?string $playbackState = null,
        ?string $hostId = null
    ): array {
        $message = [
            'type' => self::TYPE_GROUP_STATE,
            'protocol_version' => self::PROTOCOL_VERSION,
            'group_id' => $groupId,
            'members' => $members,
            'timestamp' => self::getCurrentTimestamp(),
        ];

        if ($currentMediaId !== null) {
            $message['current_media_id'] = $currentMediaId;
        }

        if ($playbackPosition !== null) {
            $message['playback_position'] = $playbackPosition;
        }

        if ($playbackState !== null) {
            $message['playback_state'] = $playbackState;
        }

        if ($hostId !== null) {
            $message['host_id'] = $hostId;
        }

        return $message;
    }

    /**
     * Create a playback play message.
     *
     * @param string $groupId The group ID
     * @param string $memberId The host's member ID
     * @param int $position Current position in milliseconds
     * @param int $serverTime Server timestamp when command was issued
     * @return array{type: string, protocol_version: int, group_id: string, member_id: string, position: int, server_time: int, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::playbackPlay('sp_abc123', 'host_789', 5000, time());
     * ```
     */
    public static function playbackPlay(
        string $groupId,
        string $memberId,
        int $position,
        int $serverTime
    ): array {
        return [
            'type' => self::TYPE_PLAYBACK_PLAY,
            'protocol_version' => self::PROTOCOL_VERSION,
            'group_id' => $groupId,
            'member_id' => $memberId,
            'position' => $position,
            'server_time' => $serverTime,
            'timestamp' => self::getCurrentTimestamp(),
        ];
    }

    /**
     * Create a playback pause message.
     *
     * @param string $groupId The group ID
     * @param string $memberId The host's member ID
     * @param int $position Current position in milliseconds
     * @param int $serverTime Server timestamp when command was issued
     * @return array{type: string, protocol_version: int, group_id: string, member_id: string, position: int, server_time: int, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::playbackPause('sp_abc123', 'host_789', 5000, time());
     * ```
     */
    public static function playbackPause(
        string $groupId,
        string $memberId,
        int $position,
        int $serverTime
    ): array {
        return [
            'type' => self::TYPE_PLAYBACK_PAUSE,
            'protocol_version' => self::PROTOCOL_VERSION,
            'group_id' => $groupId,
            'member_id' => $memberId,
            'position' => $position,
            'server_time' => $serverTime,
            'timestamp' => self::getCurrentTimestamp(),
        ];
    }

    /**
     * Create a playback seek message.
     *
     * @param string $groupId The group ID
     * @param string $memberId The host's member ID
     * @param int $fromPosition Position before seek
     * @param int $toPosition Position after seek
     * @param int $serverTime Server timestamp when command was issued
     * @return array{type: string, protocol_version: int, group_id: string, member_id: string, from_position: int, to_position: int, server_time: int, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::playbackSeek('sp_abc123', 'host_789', 5000, 10000, time());
     * ```
     */
    public static function playbackSeek(
        string $groupId,
        string $memberId,
        int $fromPosition,
        int $toPosition,
        int $serverTime
    ): array {
        return [
            'type' => self::TYPE_PLAYBACK_SEEK,
            'protocol_version' => self::PROTOCOL_VERSION,
            'group_id' => $groupId,
            'member_id' => $memberId,
            'from_position' => $fromPosition,
            'to_position' => $toPosition,
            'server_time' => $serverTime,
            'timestamp' => self::getCurrentTimestamp(),
        ];
    }

    /**
     * Create a playback queue update message.
     *
     * @param string $groupId The group ID
     * @param array<int, array{media_id: string, media_info?: array<string, mixed>}> $queue The updated queue
     * @return array{type: string, protocol_version: int, group_id: string, queue: array<int, array{media_id: string, media_info?: array<string, mixed>}>, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::playbackQueue('sp_abc123', [
     *     ['media_id' => 'm1', 'media_info' => ['name' => 'Movie 1']],
     *     ['media_id' => 'm2', 'media_info' => ['name' => 'Movie 2']],
     * ]);
     * ```
     */
    public static function playbackQueue(string $groupId, array $queue): array
    {
        return [
            'type' => self::TYPE_PLAYBACK_QUEUE,
            'protocol_version' => self::PROTOCOL_VERSION,
            'group_id' => $groupId,
            'queue' => $queue,
            'timestamp' => self::getCurrentTimestamp(),
        ];
    }

    /**
     * Create a playback sync request message.
     *
     * Sent by host periodically to keep all members synchronized.
     *
     * @param string $groupId The group ID
     * @param string $memberId The member ID
     * @param int $position Current position in milliseconds
     * @param bool $isPlaying Whether playback is active
     * @param int $serverTime Server timestamp
     * @return array{type: string, protocol_version: int, group_id: string, member_id: string, position: int, is_playing: bool, server_time: int, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::playbackSync('sp_abc123', 'host_789', 5000, true, time());
     * ```
     */
    public static function playbackSync(
        string $groupId,
        string $memberId,
        int $position,
        bool $isPlaying,
        int $serverTime
    ): array {
        return [
            'type' => self::TYPE_PLAYBACK_SYNC,
            'protocol_version' => self::PROTOCOL_VERSION,
            'group_id' => $groupId,
            'member_id' => $memberId,
            'position' => $position,
            'is_playing' => $isPlaying,
            'server_time' => $serverTime,
            'timestamp' => self::getCurrentTimestamp(),
        ];
    }

    /**
     * Create a chat message.
     *
     * @param string $groupId The group ID
     * @param string $memberId The sender's member ID
     * @param string $message The chat message content
     * @return array{type: string, protocol_version: int, group_id: string, member_id: string, message: string, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::chatMessage('sp_abc123', 'member_456', 'Hello everyone!');
     * ```
     */
    public static function chatMessage(string $groupId, string $memberId, string $message): array
    {
        return [
            'type' => self::TYPE_CHAT_MESSAGE,
            'protocol_version' => self::PROTOCOL_VERSION,
            'group_id' => $groupId,
            'member_id' => $memberId,
            'message' => $message,
            'timestamp' => self::getCurrentTimestamp(),
        ];
    }

    /**
     * Create a typing indicator message.
     *
     * @param string $groupId The group ID
     * @param string $memberId The member who is/isn't typing
     * @param bool $isTyping Whether the member is typing
     * @return array{type: string, protocol_version: int, group_id: string, member_id: string, is_typing: bool, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::chatTyping('sp_abc123', 'member_456', true);
     * ```
     */
    public static function chatTyping(string $groupId, string $memberId, bool $isTyping): array
    {
        return [
            'type' => self::TYPE_CHAT_TYPING,
            'protocol_version' => self::PROTOCOL_VERSION,
            'group_id' => $groupId,
            'member_id' => $memberId,
            'is_typing' => $isTyping,
            'timestamp' => self::getCurrentTimestamp(),
        ];
    }

    /**
     * Create a host transfer message (voluntary).
     *
     * @param string $groupId The group ID
     * @param string $currentHostId The current host's member ID
     * @param string $newHostId The new host's member ID
     * @return array{type: string, protocol_version: int, group_id: string, current_host_id: string, new_host_id: string, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::hostTransfer('sp_abc123', 'host_old', 'host_new');
     * ```
     */
    public static function hostTransfer(string $groupId, string $currentHostId, string $newHostId): array
    {
        return [
            'type' => self::TYPE_HOST_TRANSFER,
            'protocol_version' => self::PROTOCOL_VERSION,
            'group_id' => $groupId,
            'current_host_id' => $currentHostId,
            'new_host_id' => $newHostId,
            'timestamp' => self::getCurrentTimestamp(),
        ];
    }

    /**
     * Create a host election message (automatic).
     *
     * @param string $groupId The group ID
     * @param string $electedId The newly elected host's member ID
     * @param string $electedBy The member who initiated the election
     * @return array{type: string, protocol_version: int, group_id: string, elected_id: string, elected_by: string, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::hostElect('sp_abc123', 'member_456', 'old_host');
     * ```
     */
    public static function hostElect(string $groupId, string $electedId, string $electedBy): array
    {
        return [
            'type' => self::TYPE_HOST_ELECT,
            'protocol_version' => self::PROTOCOL_VERSION,
            'group_id' => $groupId,
            'elected_id' => $electedId,
            'elected_by' => $electedBy,
            'timestamp' => self::getCurrentTimestamp(),
        ];
    }

    /**
     * Create a time sync ping message.
     *
     * @param int $clientTime Current local timestamp in milliseconds
     * @return array{type: string, protocol_version: int, client_time: int, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::timePing((int)(microtime(true) * 1000));
     * ```
     */
    public static function timePing(int $clientTime): array
    {
        return [
            'type' => self::TYPE_TIME_PING,
            'protocol_version' => self::PROTOCOL_VERSION,
            'client_time' => $clientTime,
            'timestamp' => self::getCurrentTimestamp(),
        ];
    }

    /**
     * Create a time sync pong message.
     *
     * @param int $clientTime Original client timestamp from ping
     * @param int $serverTime Server timestamp when ping was received
     * @return array{type: string, protocol_version: int, client_time: int, server_time: int, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::timePong(1700000000000, 1700000000015);
     * ```
     */
    public static function timePong(int $clientTime, int $serverTime): array
    {
        return [
            'type' => self::TYPE_TIME_PONG,
            'protocol_version' => self::PROTOCOL_VERSION,
            'client_time' => $clientTime,
            'server_time' => $serverTime,
            'timestamp' => self::getCurrentTimestamp(),
        ];
    }

    /**
     * Create an error message.
     *
     * @param string $code Error code (e.g., 'NOT_IN_GROUP', 'INVALID_PASSWORD')
     * @param string $message Human-readable error message
     * @param array<string, mixed>|null $details Optional additional error details
     * @return array{type: string, protocol_version: int, error_code: string, message: string, details?: array<string, mixed>, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::error('GROUP_FULL', 'Cannot join: group is full');
     * $msg = Messages::error('HANDLER_ERROR', 'Internal error', ['trace' => '...']);
     * ```
     */
    public static function error(string $code, string $message, ?array $details = null): array
    {
        $error = [
            'type' => self::TYPE_ERROR,
            'protocol_version' => self::PROTOCOL_VERSION,
            'error_code' => $code,
            'message' => $message,
            'timestamp' => self::getCurrentTimestamp(),
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return $error;
    }

    /**
     * Create an info message.
     *
     * @param string $message The informational message
     * @param array<string, mixed>|null $data Optional additional data
     * @return array{type: string, protocol_version: int, message: string, data?: array<string, mixed>, timestamp: int} The message array
     *
     * @example
     * ```php
     * $msg = Messages::info('Welcome to the group!');
     * $msg = Messages::info('User joined', ['member_id' => 'member_123']);
     * ```
     */
    public static function info(string $message, ?array $data = null): array
    {
        $info = [
            'type' => self::TYPE_INFO,
            'protocol_version' => self::PROTOCOL_VERSION,
            'message' => $message,
            'timestamp' => self::getCurrentTimestamp(),
        ];

        if ($data !== null) {
            $info['data'] = $data;
        }

        return $info;
    }

    /**
     * Validate a message structure.
     *
     * Checks for required fields (type, protocol_version) and validates
     * message-type-specific required fields.
     *
     * @param array<string, mixed> $message The message to validate
     * @return array{valid: bool, errors: array<int, string>} Validation result with any errors
     *
     * @example
     * ```php
     * $result = Messages::validate($message);
     * if (!$result['valid']) {
     *     foreach ($result['errors'] as $error) {
     *         echo "Validation error: $error\n";
     *     }
     * }
     * ```
     */
    public static function validate(array $message): array
    {
        $errors = [];

        if (!isset($message['type'])) {
            $errors[] = 'Missing required field: type';
            return ['valid' => false, 'errors' => $errors];
        }

        $type = $message['type'];
        if (!is_string($type)) {
            $errors[] = 'Invalid message type: not a string';
            return ['valid' => false, 'errors' => $errors];
        }

        if (!self::isValidType($type)) {
            $errors[] = 'Invalid message type: ' . $type;
        }

        if (!isset($message['protocol_version'])) {
            $errors[] = 'Missing required field: protocol_version';
        } else {
            $protocolVersion = $message['protocol_version'];
            if (!is_int($protocolVersion)) {
                $errors[] = 'Protocol version must be an integer';
            } elseif ($protocolVersion > self::PROTOCOL_VERSION) {
                $errors[] = 'Protocol version mismatch: expected ' . self::PROTOCOL_VERSION . ', got ' . $protocolVersion;
            }
        }

        if (in_array($type, [self::TYPE_GROUP_CREATE, self::TYPE_GROUP_JOIN], true)) {
            if (empty($message['group_name'] ?? $message['group_id'] ?? '')) {
                $errors[] = 'Missing group identifier';
            }
        }

        if (in_array($type, [self::TYPE_GROUP_LEAVE, self::TYPE_PLAYBACK_PLAY, self::TYPE_PLAYBACK_PAUSE, self::TYPE_PLAYBACK_SEEK], true)) {
            if (empty($message['member_id'] ?? '')) {
                $errors[] = 'Missing member_id';
            }
        }

        if (in_array($type, [self::TYPE_PLAYBACK_PLAY, self::TYPE_PLAYBACK_PAUSE, self::TYPE_PLAYBACK_SEEK], true)) {
            if (!isset($message['position'])) {
                $errors[] = 'Missing playback position';
            }
            if (!isset($message['server_time'])) {
                $errors[] = 'Missing server_time';
            }
        }

        if ($type === self::TYPE_CHAT_MESSAGE) {
            if (empty($message['message'] ?? '')) {
                $errors[] = 'Missing chat message content';
            }
        }

        return ['valid' => count($errors) === 0, 'errors' => $errors];
    }

    /**
     * Serialize a message to JSON string.
     *
     * @param array<string, mixed> $message The message to serialize
     * @return string JSON-encoded message
     * @throws \JsonException If encoding fails
     *
     * @example
     * ```php
     * $json = Messages::serialize(Messages::groupCreate('Test'));
     * ```
     */
    public static function serialize(array $message): string
    {
        return json_encode($message, JSON_THROW_ON_ERROR);
    }

    /**
     * Deserialize a message from JSON string.
     *
     * Parses JSON and validates the message structure.
     *
     * @param string $json JSON string to parse
     * @return array{valid: bool, message?: array<string, mixed>, error?: string} Parsed result
     *
     * @example
     * ```php
     * $result = Messages::deserialize('{"type": "syncplay_group_create", ...}');
     * if ($result['valid']) {
     *     $message = $result['message'];
     * }
     * ```
     */
    public static function deserialize(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                return ['valid' => false, 'error' => 'Invalid message format'];
            }

            // Narrow to array<string, mixed> by discarding any int keys.
            $message = [];
            foreach ($decoded as $key => $value) {
                if (is_string($key)) {
                    $message[$key] = $value;
                }
            }

            $validation = self::validate($message);

            if (!$validation['valid']) {
                return ['valid' => false, 'error' => implode(', ', $validation['errors'])];
            }

            return ['valid' => true, 'message' => $message];
        } catch (\JsonException $e) {
            return ['valid' => false, 'error' => 'JSON parse error: ' . $e->getMessage()];
        }
    }

    /**
     * Get current timestamp in milliseconds.
     *
     * @return int Current Unix timestamp in milliseconds
     */
    private static function getCurrentTimestamp(): int
    {
        return (int)(microtime(true) * 1000);
    }

    /**
     * Hash a password using SHA256.
     *
     * @param string $password Plaintext password
     * @return string 64-character hex hash
     */
    private static function hashPassword(string $password): string
    {
        return hash('sha256', $password);
    }

    /**
     * Get the protocol version.
     *
     * @return int Current protocol version
     */
    public static function getProtocolVersion(): int
    {
        return self::PROTOCOL_VERSION;
    }
}
