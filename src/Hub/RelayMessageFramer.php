<?php

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
 * @since 0.12.0
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
     * @since 0.12.0
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
     * @since 0.12.0
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
     * @since 0.12.0
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
     * Decode a binary frame from the wire.
     *
     * Returns null if the data is incomplete (less than 7 bytes for the header).
     * Caller is responsible for buffering partial data across multiple read calls.
     *
     * @param string $bytes Raw bytes from the WebSocket connection.
     *
     * @return SharedRelayFrame|null Parsed frame, or null if data is incomplete.
     *
     * @since 0.12.0
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

    // -------------------------------------------------------------------------
    // Backward-compatibility methods for the old C.6 HTTP-proxy-over-WS protocol
    // These will be removed when RelayConsumer is updated in Phase 0.6
    // -------------------------------------------------------------------------

    /**
     * @deprecated Use encode() with RelayFrameType constants instead.
     */
    public const TYPE_HTTP_REQUEST = 1;

    /**
     * @deprecated Use encode() with RelayFrameType constants instead.
     */
    public const TYPE_HTTP_RESPONSE = 2;

    /**
     * @deprecated Use encode() with RelayFrameType constants instead.
     */
    public const TYPE_PING = 3;

    /**
     * @deprecated Use encode() with RelayFrameType constants instead.
     */
    public const TYPE_PONG = 4;

    /**
     * Frame an HTTP request for transmission over the tunnel (old C.6 protocol).
     *
     * @deprecated Use encode() with RelayFrameType::DATA instead.
     *
     * @param int    $seq      32-bit unsigned sequence number.
     * @param string $method   HTTP method (GET, POST, etc.).
     * @param string $path     HTTP request path.
     * @param array<string, string> $headers HTTP headers as key=>value.
     * @param string $body     HTTP request body.
     *
     * @return string Binary-encoded frame.
     *
     * @since 0.12.0
     */
    public function frameRequest(int $seq, string $method, string $path, array $headers, string $body): string
    {
        $payload = json_encode([
            'seq' => $seq,
            'method' => $method,
            'path' => $path,
            'headers' => $headers,
            'body' => $body,
        ], JSON_THROW_ON_ERROR);

        return $this->buildFrameLegacy(self::TYPE_HTTP_REQUEST, $seq, $payload);
    }

    /**
     * Frame an HTTP response for transmission over the tunnel (old C.6 protocol).
     *
     * @deprecated Use encode() with RelayFrameType::DATA instead.
     *
     * @param int    $seq          32-bit unsigned sequence number (matches request).
     * @param int    $statusCode   HTTP status code.
     * @param array<string, string> $headers HTTP headers as key=>value.
     * @param string $body         HTTP response body.
     *
     * @return string Binary-encoded frame.
     *
     * @since 0.12.0
     */
    public function frameResponse(int $seq, int $statusCode, array $headers, string $body): string
    {
        $payload = json_encode([
            'seq' => $seq,
            'status' => $statusCode,
            'headers' => $headers,
            'body' => $body,
        ], JSON_THROW_ON_ERROR);

        return $this->buildFrameLegacy(self::TYPE_HTTP_RESPONSE, $seq, $payload);
    }

    /**
     * Frame a PING keep-alive frame (old C.6 protocol).
     *
     * @deprecated Use encode() with RelayFrameType::HEARTBEAT instead.
     *
     * @param int $seq 32-bit unsigned sequence number.
     *
     * @return string Binary-encoded frame.
     *
     * @since 0.12.0
     */
    public function framePing(int $seq): string
    {
        $payload = json_encode(['seq' => $seq], JSON_THROW_ON_ERROR);
        return $this->buildFrameLegacy(self::TYPE_PING, $seq, $payload);
    }

    /**
     * Frame a PONG keep-alive ack frame (old C.6 protocol).
     *
     * @deprecated Use encode() with RelayFrameType::HEARTBEAT instead.
     *
     * @param int $seq 32-bit unsigned sequence number (matches ping).
     *
     * @return string Binary-encoded frame.
     *
     * @since 0.12.0
     */
    public function framePong(int $seq): string
    {
        $payload = json_encode(['seq' => $seq], JSON_THROW_ON_ERROR);
        return $this->buildFrameLegacy(self::TYPE_PONG, $seq, $payload);
    }

    /**
     * Parse a binary frame from the wire (old C.6 protocol).
     *
     * @deprecated Use decode() for the new multiplexed protocol.
     *
     * @param string $data Raw bytes from the WebSocket connection.
     *
     * @return RelayFrame|null Parsed frame, or null if the data is incomplete.
     *
     * @since 0.12.0
     */
    public function parse(string $data): ?RelayFrame
    {
        if (strlen($data) < 9) {
            return null;
        }

        $type = unpack('Ctype', $data);
        if ($type === false) {
            return null;
        }
        $type = $type['type'];

        $seqData = substr($data, 1, 4);
        $seqUnpacked = unpack('Nseq', $seqData);
        if ($seqUnpacked === false) {
            return null;
        }
        $seq = $seqUnpacked['seq'];

        $lenData = substr($data, 5, 4);
        $lenUnpacked = unpack('Nlen', $lenData);
        if ($lenUnpacked === false) {
            return null;
        }
        $len = $lenUnpacked['len'];

        $payloadStart = 9;
        if (strlen($data) < $payloadStart + $len) {
            return null;
        }

        $payloadBytes = substr($data, $payloadStart, $len);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($payloadBytes, true, 512, JSON_THROW_ON_ERROR);

        return new RelayFrame($type, $seq, $payload);
    }

    /**
     * Build a binary frame (old C.6 protocol).
     *
     * @deprecated Internal use only. Use encode() for the new protocol.
     *
     * @param int    $type    Frame type constant.
     * @param int    $seq     32-bit unsigned sequence number.
     * @param string $payload JSON payload string.
     *
     * @return string Binary frame.
     */
    private function buildFrameLegacy(int $type, int $seq, string $payload): string
    {
        $len = strlen($payload);

        $result  = pack('C', $type);
        $result .= pack('N', $seq);
        $result .= pack('N', $len);
        $result .= $payload;

        return $result;
    }
}
