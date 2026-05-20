<?php

declare(strict_types=1);

namespace Phlix\Hub;

/**
 * Frames and parses relay messages over the WebSocket tunnel.
 *
 * Wire format (all integers are big-endian):
 *
 *   [1-byte type][4-byte seq (big-endian uint32)][4-byte payload_len (big-endian uint32)][payload_bytes]
 *
 * Types:
 *   TYPE_HTTP_REQUEST  = 1  — server → hub: tunneled HTTP request
 *   TYPE_HTTP_RESPONSE = 2 — hub → server: tunneled HTTP response
 *   TYPE_PING          = 3 — keep-alive probe
 *   TYPE_PONG          = 4 — keep-alive ack
 *
 * Payload is always JSON.
 *
 * @package Phlix\Hub
 * @since 0.12.0
 */
final class RelayMessageFramer
{
    public const TYPE_HTTP_REQUEST  = 1;
    public const TYPE_HTTP_RESPONSE = 2;
    public const TYPE_PING          = 3;
    public const TYPE_PONG          = 4;

    /**
     * Frame an HTTP request for transmission over the tunnel.
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

        return $this->buildFrame(self::TYPE_HTTP_REQUEST, $seq, $payload);
    }

    /**
     * Frame an HTTP response for transmission over the tunnel.
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

        return $this->buildFrame(self::TYPE_HTTP_RESPONSE, $seq, $payload);
    }

    /**
     * Frame a PING keep-alive frame.
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
        return $this->buildFrame(self::TYPE_PING, $seq, $payload);
    }

    /**
     * Frame a PONG keep-alive ack frame.
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
        return $this->buildFrame(self::TYPE_PONG, $seq, $payload);
    }

    /**
     * Parse a binary frame from the wire.
     *
     * @param string $data Raw bytes from the WebSocket connection.
     *
     * @return RelayFrame|null Parsed frame, or null if the data is incomplete
     *                        (less than minimum 9 bytes).
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
     * Build a binary frame.
     *
     * @param int    $type    Frame type constant.
     * @param int    $seq     32-bit unsigned sequence number.
     * @param string $payload JSON payload string.
     *
     * @return string Binary frame.
     */
    private function buildFrame(int $type, int $seq, string $payload): string
    {
        $len = strlen($payload);

        $result  = pack('C', $type);
        $result .= pack('N', $seq);
        $result .= pack('N', $len);
        $result .= $payload;

        return $result;
    }
}
