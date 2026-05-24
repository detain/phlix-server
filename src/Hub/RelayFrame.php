<?php

declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Shared\Relay\RelayFrameType;

/**
 * Immutable parsed relay frame from the wire.
 *
 * @package Phlix\Hub
 * @since 0.12.0
 */
final class RelayFrame
{
    /**
     * @param int                                      $type    Frame type (TYPE_HTTP_REQUEST, etc.) or RelayFrameType value.
     * @param int                                      $seq     32-bit unsigned sequence number.
     * @param array<string, mixed>|array<string, mixed> $payload Decoded JSON payload.
     */
    public function __construct(
        public readonly int $type,
        public readonly int $seq,
        public readonly array $payload,
    ) {
    }

    /**
     * Returns true if this is an HTTP request frame.
     *
     * @return bool
     *
     * @since 0.12.0
     */
    public function isRequest(): bool
    {
        return $this->type === RelayMessageFramer::TYPE_HTTP_REQUEST;
    }

    /**
     * Returns true if this is an HTTP response frame.
     *
     * @return bool
     *
     * @since 0.12.0
     */
    public function isResponse(): bool
    {
        return $this->type === RelayMessageFramer::TYPE_HTTP_RESPONSE;
    }

    /**
     * Returns true if this is a PING frame.
     *
     * @return bool
     *
     * @since 0.12.0
     */
    public function isPing(): bool
    {
        return $this->type === RelayMessageFramer::TYPE_PING;
    }

    /**
     * Returns true if this is a PONG frame.
     *
     * @return bool
     *
     * @since 0.12.0
     */
    public function isPong(): bool
    {
        return $this->type === RelayMessageFramer::TYPE_PONG;
    }

    /**
     * Returns true if this is a CLIENT_CONNECT frame (new multiplexed protocol).
     *
     * @return bool
     *
     * @since 0.12.0
     */
    public function isClientConnect(): bool
    {
        return $this->type === RelayFrameType::CLIENT_CONNECT->value;
    }

    /**
     * Returns true if this is a CLIENT_DISCONNECT frame (new multiplexed protocol).
     *
     * @return bool
     *
     * @since 0.12.0
     */
    public function isClientDisconnect(): bool
    {
        return $this->type === RelayFrameType::CLIENT_DISCONNECT->value;
    }

    /**
     * Returns true if this is a DATA frame (new multiplexed protocol).
     *
     * @return bool
     *
     * @since 0.12.0
     */
    public function isData(): bool
    {
        return $this->type === RelayFrameType::DATA->value;
    }

    /**
     * Returns true if this is a HEARTBEAT frame (new multiplexed protocol).
     *
     * @return bool
     *
     * @since 0.12.0
     */
    public function isHeartbeat(): bool
    {
        return $this->type === RelayFrameType::HEARTBEAT->value;
    }
}
