<?php

declare(strict_types=1);

namespace Phlex\Hub;

/**
 * Immutable parsed relay frame from the wire.
 *
 * @package Phlex\Hub
 * @since 0.12.0
 */
final class RelayFrame
{
    /**
     * @param int                                      $type    Frame type (TYPE_HTTP_REQUEST, etc.).
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
}
