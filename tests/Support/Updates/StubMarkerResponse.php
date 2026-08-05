<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Updates;

use RuntimeException;

/**
 * A PSR-7-ish response stub for the marker fetcher's `success` callback (S74).
 *
 * @package Phlix\Tests\Support\Updates
 */
final class StubMarkerResponse
{
    /**
     * @param string $body  Body to return.
     * @param bool   $throw When true, {@see getBody()} throws.
     */
    public function __construct(
        private readonly string $body = '',
        private readonly bool $throw = false,
    ) {
    }

    /**
     * @return string
     */
    public function getBody(): string
    {
        if ($this->throw) {
            throw new RuntimeException('stream detached');
        }

        return $this->body;
    }
}
