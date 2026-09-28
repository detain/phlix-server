<?php

/**
 * Phlix media server component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub;

use InvalidArgumentException;
use Phlix\Shared\Relay\RelayFrame as SharedRelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;

/**
 * Frames and parses relay messages over the WebSocket tunnel.
 *
 * Implements the multiplexed WS relay wire protocol:
 *
 *   [4-byte sequence (uint32)][1-byte frame type][2-byte payload length (uint16)][N payload bytes]
 *
 * Maximum frame payload: 65535 bytes.
 *
 * HELLO and HELLO_ACK frames are exchanged as JSON text immediately after
 * WS upgrade (before binary mode is entered).
 *
 * @package Phlix\Hub
 * @since 0.5.0
 */
final class RelayMessageFramer implements RelayWireCodecInterface
{
    /**
     * Encode a binary frame for transmission.
     *
     * @param RelayFrameType $type    Frame type.
     * @param int            $seq     32-bit unsigned sequence number.
     * @param string         $payload Raw byte payload.
     *
     * @return string Binary-encoded frame.
     *
     * @throws InvalidArgumentException If payload exceeds 65535 bytes.
     *
     * @since 0.5.0
     */
    public function encode(RelayFrameType $type, int $seq, string $payload): string
    {
        $len = strlen($payload);

        if ($len > 65535) {
            throw new InvalidArgumentException(
                sprintf('Payload exceeds maximum length of 65535 bytes: %d', $len),
            );
        }

        $result  = pack('N', $seq);
        $result .= pack('C', $type->value);
        $result .= pack('n', $len);
        $result .= $payload;

        return $result;
    }

    /**
     * Encode a HELLO handshake message (JSON text).
     *
     * @param string $enrollmentJwt JWT from stored enrollment.
     * @param string $serverId     Server UUID.
     *
     * @return string JSON text frame (not binary-encoded).
     *
     * @since 0.5.0
     */
    public function encodeHello(string $enrollmentJwt, string $serverId): string
    {
        $payload = [
            'type' => 'hello',
            'enrollment_jwt' => $enrollmentJwt,
            'server_id' => $serverId,
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Encode a HELLO_ACK handshake response (JSON text).
     *
     * @param string $relaySessionId Relay session UUID assigned by hub.
     * @param string $tunnelId      Tunnel UUID assigned by hub.
     *
     * @return string JSON text frame (not binary-encoded).
     *
     * @since 0.5.0
     */
    public function encodeHelloAck(string $relaySessionId, string $tunnelId): string
    {
        $payload = [
            'type' => 'hello_ack',
            'relay_session_id' => $relaySessionId,
            'tunnel_id' => $tunnelId,
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Measure a complete-but-UNKNOWN-TYPE frame at the head of the buffer.
     *
     * {@see decode()}'s frozen `?SharedRelayFrame` signature cannot distinguish
     * "wait for more bytes" from "this frame's type byte is not in the enum" —
     * both surface as null. A consumer that treats every null as "incomplete"
     * stalls forever behind one frame from a newer protocol version (the frame
     * never completes further; it is simply unparseable), so the buffer head
     * must be skippable. This method is the skip oracle for that null case:
     *
     *  - returns the full wire length (7-byte header + payload) iff the header
     *    is complete, its type byte is INVALID (unknown to this build), and the
     *    whole frame's bytes are present — the caller can safely consume that
     *    many bytes and keep draining;
     *  - returns null for everything else (header incomplete, payload
     *    incomplete, or the frame decodes normally — defer to {@see decode()}).
     *
     * Deliberately NOT part of {@see RelayWireCodecInterface} (frozen); it is a
     * concrete-class seam used by the buffer-draining consumer.
     *
     * @param string   $bytes            Raw bytes from the WebSocket connection.
     * @param int|null &$skippedTypeByte Out: the unknown type byte when a length
     *                                   is returned (for logging); untouched otherwise.
     *
     * @return int|null Wire length to skip, or null when there is nothing to skip.
     *
     * @since 0.21.0
     */
    public function unknownFrameLength(string $bytes, ?int &$skippedTypeByte = null): ?int
    {
        if (strlen($bytes) < 7) {
            return null;
        }

        $typeByte = ord($bytes[4]);
        if (RelayFrameType::isValid($typeByte)) {
            // Known type — decode() owns this frame (or waits for its payload).
            return null;
        }

        $lenUnpacked = unpack('nlen', substr($bytes, 5, 2));
        if ($lenUnpacked === false) {
            return null;
        }

        $frameLen = 7 + $lenUnpacked['len'];
        if (strlen($bytes) < $frameLen) {
            // Unknown-type frame still in flight — keep buffering until its
            // declared length arrives, then it becomes skippable.
            return null;
        }

        $skippedTypeByte = $typeByte;
        return $frameLen;
    }

    /**
     * Decode a binary frame from the wire.
     *
     * Returns null if the data is incomplete (less than 7 bytes for the header)
     * OR if the frame's type byte is unknown to this build. These two cases are
     * indistinguishable through this frozen signature — use
     * {@see unknownFrameLength()} to tell a skippable unknown-type frame from a
     * partial one before deciding to wait.
     *
     * @param string $bytes Raw bytes from the WebSocket connection.
     *
     * @return SharedRelayFrame|null Parsed frame, or null if data is incomplete
     *                               or the frame type is unknown.
     *
     * @since 0.5.0
     */
    public function decode(string $bytes): ?SharedRelayFrame
    {
        if (strlen($bytes) < 7) {
            return null;
        }

        $seqData = substr($bytes, 0, 4);
        $seqUnpacked = unpack('Nseq', $seqData);
        if ($seqUnpacked === false) {
            return null;
        }
        $seq = $seqUnpacked['seq'];

        $typeByte = ord($bytes[4]);
        if (!RelayFrameType::isValid($typeByte)) {
            return null;
        }
        $type = RelayFrameType::fromValue($typeByte);

        $lenData = substr($bytes, 5, 2);
        $lenUnpacked = unpack('nlen', $lenData);
        if ($lenUnpacked === false) {
            return null;
        }
        $len = $lenUnpacked['len'];

        $payloadStart = 7;
        if (strlen($bytes) < $payloadStart + $len) {
            return null;
        }

        $payload = substr($bytes, $payloadStart, $len);

        return new SharedRelayFrame($type, $seq, $payload);
    }
}
