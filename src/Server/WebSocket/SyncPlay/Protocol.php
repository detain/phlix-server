<?php

/**
 * Phlix media server component: SyncPlay Protocol.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\WebSocket\SyncPlay;

use Phlix\Session\SyncPlay\Messages;

/**
 * SyncPlay Binary Protocol - Frame encoding and decoding.
 *
 * This class handles the binary frame format for SyncPlay WebSocket messages.
 * All multi-byte integers use BigEndian byte order.
 *
 * ## Frame Structure
 *
 * ```
 * [version:1][type:1][session_id:16][payload:N]
 * ```
 *
 * - **version**: 1 byte (uint8) - Protocol version (currently 1)
 * - **type**: 1 byte (uint8) - Message type identifier
 * - **session_id**: 16 bytes (binary) - Session identifier, null-padded
 * - **payload**: N bytes - Message-specific payload
 *
 * ## Message Types
 *
 * | Type | Name | Payload |
 * |------|------|---------|
 * | 0x01 | SYNC_PLAY_GROUP_CREATE | Variable |
 * | 0x02 | SYNC_PLAY_GROUP_JOIN | Variable |
 * | 0x03 | SYNC_PLAY_SYNC | [server_time:8][playback_position:8][playback_rate:4] |
 * | 0x04 | SYNC_PLAY_PAUSE | [server_time:8][playback_position:8] |
 * | 0x05 | SYNC_PLAY_PLAY | [server_time:8][playback_position:8] |
 * | 0x06 | SYNC_PLAY_SEEK | [server_time:8][from_position:8][to_position:8] |
 * | 0x07 | SYNC_PLAY_CHAT | Variable |
 * | 0xFF | SYNC_PLAY_ERROR | [error_code:2][message_length:2][message:N] |
 *
 * ## SYNC_PLAY_SYNC Frame (type 0x03)
 *
 * ```
 * [server_time:8][playback_position:8][playback_rate:4]
 * ```
 *
 * - **server_time**: 8 bytes (uint64) - Server timestamp in milliseconds
 * - **playback_position**: 8 bytes (uint64) - Current playback position in ms
 * - **playback_rate**: 4 bytes (uint32) - Playback rate (e.g., 1000 = 1.0x)
 *
 * @author Phlix Development Team
 * @copyright 2024 Phlix Media Server
 * @license Proprietary
 *
 * @see Messages For JSON message type definitions
 * @see SyncPlayRoom For room broadcasting
 */
final class Protocol
{
    /**
     * Protocol version byte.
     */
    public const VERSION = 1;

    /**
     * Message type: Group Create.
     */
    public const TYPE_GROUP_CREATE = 0x01;

    /**
     * Message type: Group Join.
     */
    public const TYPE_GROUP_JOIN = 0x02;

    /**
     * Message type: Playback Sync (periodic).
     */
    public const TYPE_SYNC_PLAY_SYNC = 0x03;

    /**
     * Message type: Playback Pause.
     */
    public const TYPE_PAUSE = 0x04;

    /**
     * Message type: Playback Play.
     */
    public const TYPE_PLAY = 0x05;

    /**
     * Message type: Playback Seek.
     */
    public const TYPE_SEEK = 0x06;

    /**
     * Message type: Chat Message.
     */
    public const TYPE_CHAT = 0x07;

    /**
     * Message type: Error.
     */
    public const TYPE_ERROR = 0xFF;

    /**
     * Fixed session ID length in bytes.
     */
    public const SESSION_ID_LENGTH = 16;

    /**
     * Encode a SyncPlay SYNC frame (type 0x03).
     *
     * Frame structure: `[version:1][type:1][session_id:16][server_time:8][playback_position:8][playback_rate:4]`
     *
     * @param string $sessionId 16-byte session identifier
     * @param int $serverTime Server timestamp in milliseconds
     * @param int $playbackPosition Current playback position in milliseconds
     * @param int $playbackRate Playback rate (e.g., 1000 = 1.0x)
     * @return string Binary frame
     *
     * @throws \InvalidArgumentException If session ID is not 16 bytes
     *
     * @example
     * ```php
     * $frame = Protocol::encodeSyncFrame('session1234567890', 1700000000000, 5000, 1000);
     * ```
     */
    public static function encodeSyncFrame(
        string $sessionId,
        int $serverTime,
        int $playbackPosition,
        int $playbackRate
    ): string {
        if (strlen($sessionId) > self::SESSION_ID_LENGTH) {
            throw new \InvalidArgumentException(
                'Session ID must be at most ' . self::SESSION_ID_LENGTH . ' bytes'
            );
        }

        // Pack frame header: version (1) + type (1) + session_id (16)
        $header = pack('C', self::VERSION);
        $header .= pack('C', self::TYPE_SYNC_PLAY_SYNC);
        $header .= str_pad($sessionId, self::SESSION_ID_LENGTH, "\x00");

        // Pack payload: server_time (8) + playback_position (8) + playback_rate (4) = 20 bytes
        $payload = pack('J', $serverTime);                  // uint64, BigEndian
        $payload .= pack('J', $playbackPosition);            // uint64, BigEndian
        $payload .= pack('N', $playbackRate);               // uint32, BigEndian

        return $header . $payload;
    }

    /**
     * Decode a SyncPlay SYNC frame (type 0x03).
     *
     * @param string $frame Binary frame
     * @return array{session_id: string, server_time: int, playback_position: int, playback_rate: int} Decoded data
     *
     * @throws \UnexpectedValueException If frame is invalid or wrong type
     *
     * @example
     * ```php
     * $data = Protocol::decodeSyncFrame($frame);
     * echo "Position: " . $data['playback_position'] . "ms";
     * ```
     */
    public static function decodeSyncFrame(string $frame): array
    {
        $minLength = 1 + 1 + self::SESSION_ID_LENGTH + 8 + 8 + 4; // 38 bytes
        if (strlen($frame) < $minLength) {
            throw new \UnexpectedValueException(
                'Frame too short: expected at least ' . $minLength . ' bytes, got ' . strlen($frame)
            );
        }

        $offset = 0;

        // Read version
        $version = unpack('@' . $offset . 'Cversion', $frame);
        $offset += 1;
        if (($version['version'] ?? 0) !== self::VERSION) {
            throw new \UnexpectedValueException(
                'Unsupported protocol version: ' . ($version['version'] ?? 'unknown')
            );
        }

        // Read type
        $type = unpack('@' . $offset . 'Ctype', $frame);
        $offset += 1;
        if (($type['type'] ?? 0) !== self::TYPE_SYNC_PLAY_SYNC) {
            throw new \UnexpectedValueException(
                'Expected type 0x03 (SYNC_PLAY_SYNC), got 0x' . sprintf('%02X', $type['type'] ?? 0)
            );
        }

        // Read session_id (16 bytes)
        $sessionId = substr($frame, $offset, self::SESSION_ID_LENGTH);
        $sessionId = rtrim($sessionId, "\x00");
        $offset += self::SESSION_ID_LENGTH;

        // Read server_time (8 bytes, uint64 BigEndian)
        $serverTimeData = unpack('@' . $offset . 'Jserver_time', $frame);
        $offset += 8;

        // Read playback_position (8 bytes, uint64 BigEndian)
        $positionData = unpack('@' . $offset . 'Jplayback_position', $frame);
        $offset += 8;

        // Read playback_rate (4 bytes, uint32 BigEndian)
        $rateData = unpack('@' . $offset . 'Nplayback_rate', $frame);

        return [
            'session_id' => $sessionId,
            'server_time' => (int) ($serverTimeData['server_time'] ?? 0),
            'playback_position' => (int) ($positionData['playback_position'] ?? 0),
            'playback_rate' => (int) ($rateData['playback_rate'] ?? 0),
        ];
    }

    /**
     * Encode a generic SyncPlay frame.
     *
     * @param int $type Message type constant
     * @param string $sessionId Session identifier (max 16 bytes)
     * @param string $payload Binary payload data
     * @return string Binary frame
     *
     * @throws \InvalidArgumentException If session ID is too long
     *
     * @example
     * ```php
     * $frame = Protocol::encodeFrame(Protocol::TYPE_GROUP_CREATE, $sessionId, $jsonPayload);
     * ```
     */
    public static function encodeFrame(int $type, string $sessionId, string $payload): string
    {
        if (strlen($sessionId) > self::SESSION_ID_LENGTH) {
            throw new \InvalidArgumentException(
                'Session ID must be at most ' . self::SESSION_ID_LENGTH . ' bytes'
            );
        }

        $header = pack('C', self::VERSION);
        $header .= pack('C', $type);
        $header .= str_pad($sessionId, self::SESSION_ID_LENGTH, "\x00");

        return $header . $payload;
    }

    /**
     * Decode a generic SyncPlay frame header (without payload).
     *
     * Returns the version, type, and session_id for inspection without
     * parsing the full payload.
     *
     * @param string $frame Binary frame
     * @return array{version: int, type: int, session_id: string} Decoded header
     *
     * @throws \UnexpectedValueException If frame is too short
     *
     * @example
     * ```php
     * $header = Protocol::decodeFrameHeader($frame);
     * if ($header['type'] === Protocol::TYPE_SYNC_PLAY_SYNC) {
     *     $data = Protocol::decodeSyncFrame($frame);
     * }
     * ```
     */
    public static function decodeFrameHeader(string $frame): array
    {
        $minLength = 1 + 1 + self::SESSION_ID_LENGTH; // 18 bytes
        if (strlen($frame) < $minLength) {
            throw new \UnexpectedValueException(
                'Frame too short: expected at least ' . $minLength . ' bytes, got ' . strlen($frame)
            );
        }

        $offset = 0;

        // Read version
        $version = unpack('@' . $offset . 'Cversion', $frame);
        $offset += 1;

        // Read type
        $type = unpack('@' . $offset . 'Ctype', $frame);
        $offset += 1;

        // Read session_id (16 bytes)
        $sessionId = substr($frame, $offset, self::SESSION_ID_LENGTH);
        $sessionId = rtrim($sessionId, "\x00");

        return [
            'version' => (int) ($version['version'] ?? 0),
            'type' => (int) ($type['type'] ?? 0),
            'session_id' => $sessionId,
        ];
    }

    /**
     * Encode an error frame.
     *
     * Frame structure: `[version:1][type:0xFF][session_id:16][error_code:2][message_length:2][message:N]`
     *
     * @param string $sessionId 16-byte session identifier
     * @param string $errorCode Short error code (max 2 characters)
     * @param string $errorMessage Error message
     * @return string Binary frame
     *
     * @example
     * ```php
     * $frame = Protocol::encodeErrorFrame($sessionId, 'NF', 'Group not found');
     * ```
     */
    public static function encodeErrorFrame(
        string $sessionId,
        string $errorCode,
        string $errorMessage
    ): string {
        if (strlen($sessionId) > self::SESSION_ID_LENGTH) {
            throw new \InvalidArgumentException(
                'Session ID must be at most ' . self::SESSION_ID_LENGTH . ' bytes'
            );
        }

        $header = pack('C', self::VERSION);
        $header .= pack('C', self::TYPE_ERROR);
        $header .= str_pad($sessionId, self::SESSION_ID_LENGTH, "\x00");

        $errorCodePadded = str_pad(substr($errorCode, 0, 2), 2, "\x00");
        $messageLength = strlen($errorMessage);
        $messageLengthPacked = pack('n', $messageLength); // uint16 BigEndian

        return $header . $errorCodePadded . $messageLengthPacked . $errorMessage;
    }

    /**
     * Decode an error frame.
     *
     * @param string $frame Binary frame
     * @return array{session_id: string, error_code: string, error_message: string} Decoded data
     *
     * @throws \UnexpectedValueException If frame is invalid or wrong type
     */
    public static function decodeErrorFrame(string $frame): array
    {
        $header = self::decodeFrameHeader($frame);

        if ($header['type'] !== self::TYPE_ERROR) {
            throw new \UnexpectedValueException(
                'Expected type 0xFF (ERROR), got 0x' . sprintf('%02X', $header['type'])
            );
        }

        $offset = 1 + 1 + self::SESSION_ID_LENGTH; // Skip header

        // Read error_code (2 bytes)
        $errorCodeRaw = substr($frame, $offset, 2);
        $errorCode = rtrim($errorCodeRaw, "\x00");
        $offset += 2;

        // Read message_length (2 bytes)
        $messageLengthData = unpack('@' . $offset . 'nlength', $frame);
        $messageLength = (int) ($messageLengthData['length'] ?? 0);
        $offset += 2;

        // Read message
        $errorMessage = substr($frame, $offset, $messageLength);

        return [
            'session_id' => $header['session_id'],
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ];
    }

    /**
     * Get the message type name from type byte.
     *
     * @param int $type Message type byte
     * @return string Human-readable type name
     *
     * @example
     * ```php
     * echo Protocol::getTypeName(0x03); // "SYNC_PLAY_SYNC"
     * ```
     */
    public static function getTypeName(int $type): string
    {
        return match ($type) {
            self::TYPE_GROUP_CREATE => 'GROUP_CREATE',
            self::TYPE_GROUP_JOIN => 'GROUP_JOIN',
            self::TYPE_SYNC_PLAY_SYNC => 'SYNC_PLAY_SYNC',
            self::TYPE_PAUSE => 'PAUSE',
            self::TYPE_PLAY => 'PLAY',
            self::TYPE_SEEK => 'SEEK',
            self::TYPE_CHAT => 'CHAT',
            self::TYPE_ERROR => 'ERROR',
            default => 'UNKNOWN(0x' . sprintf('%02X', $type) . ')',
        };
    }

    /**
     * Convert JSON message to binary frame.
     *
     * Convenience method that takes a JSON message array and encodes
     * it into the appropriate binary frame based on message type.
     *
     * @param array<string, mixed> $message JSON message from Messages class
     * @param string $sessionId Session identifier
     * @return string Binary frame
     *
     * @throws \InvalidArgumentException If message type is not supported
     *
     * @example
     * ```php
     * $jsonMsg = Messages::playbackSync('sp_abc', 'host', 5000, true, time());
     * $frame = Protocol::jsonToBinary($jsonMsg, $sessionId);
     * ```
     */
    public static function jsonToBinary(array $message, string $sessionId): string
    {
        $type = is_string($message['type'] ?? null) ? $message['type'] : '';
        /** @var array<string, mixed> $payload */
        $payload = is_array($message['payload'] ?? null) ? $message['payload'] : [];

        $binaryType = match ($type) {
            Messages::TYPE_GROUP_CREATE => self::TYPE_GROUP_CREATE,
            Messages::TYPE_GROUP_JOIN => self::TYPE_GROUP_JOIN,
            Messages::TYPE_PLAYBACK_SYNC => self::TYPE_SYNC_PLAY_SYNC,
            Messages::TYPE_PLAYBACK_PAUSE => self::TYPE_PAUSE,
            Messages::TYPE_PLAYBACK_PLAY => self::TYPE_PLAY,
            Messages::TYPE_PLAYBACK_SEEK => self::TYPE_SEEK,
            Messages::TYPE_CHAT_MESSAGE => self::TYPE_CHAT,
            default => throw new \InvalidArgumentException('Unsupported message type: ' . (string) $type),
        };

        // For types with fixed payloads, encode them
        $binaryPayload = match ($binaryType) {
            self::TYPE_SYNC_PLAY_SYNC => self::encodeSyncPayload(
                self::intFromMixed($payload['server_time'] ?? 0),
                self::intFromMixed($payload['position'] ?? 0),
                self::intFromMixed($payload['playback_rate'] ?? 1000)
            ),
            default => json_encode($message, JSON_THROW_ON_ERROR),
        };

        return self::encodeFrame($binaryType, $sessionId, $binaryPayload);
    }

    /**
     * Coerce a mixed value to an int.
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

    /**
     * Encode SYNC_PLAY_SYNC payload only (without header).
     *
     * @param int $serverTime Server timestamp in milliseconds
     * @param int $playbackPosition Current playback position in milliseconds
     * @param int $playbackRate Playback rate (e.g., 1000 = 1.0x)
     * @return string Binary payload (20 bytes)
     */
    private static function encodeSyncPayload(
        int $serverTime,
        int $playbackPosition,
        int $playbackRate
    ): string {
        $payload = pack('J', $serverTime);
        $payload .= pack('J', $playbackPosition);
        $payload .= pack('N', $playbackRate);
        return $payload;
    }

    /**
     * Check if a frame type uses variable-length payload.
     *
     * @param int $type Message type byte
     * @return bool True if variable-length payload
     */
    public static function isVariableLengthPayload(int $type): bool
    {
        return in_array($type, [
            self::TYPE_GROUP_CREATE,
            self::TYPE_GROUP_JOIN,
            self::TYPE_CHAT,
        ], true);
    }

    /**
     * Get the minimum frame length for a given type.
     *
     * @param int $type Message type byte
     * @return int Minimum frame length in bytes
     */
    public static function getMinFrameLength(int $type): int
    {
        $headerLength = 1 + 1 + self::SESSION_ID_LENGTH; // 18 bytes

        $payloadLength = match ($type) {
            self::TYPE_SYNC_PLAY_SYNC => 20, // server_time(8) + position(8) + rate(4)
            self::TYPE_PAUSE => 16,           // server_time(8) + position(8)
            self::TYPE_PLAY => 16,            // server_time(8) + position(8)
            self::TYPE_SEEK => 24,            // server_time(8) + from(8) + to(8)
            self::TYPE_ERROR => 4,            // error_code(2) + message_length(2)
            default => 0,                     // Variable length
        };

        return $headerLength + $payloadLength;
    }
}
