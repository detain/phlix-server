<?php

declare(strict_types=1);

namespace Phlex\Hub;

/**
 * Heartbeat result DTO for Hub connectivity check.
 *
 * @description Represents the response from a heartbeat/health check
 *             to the Hub service, indicating whether the connection is healthy.
 */
final class HeartbeatResult
{
    /**
     * @param bool        $ok        Whether the heartbeat was successful
     * @param string|null $error     Human-readable error message if unsuccessful
     * @param string|null $errorCode Machine-readable error code if unsuccessful
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null,
        public readonly ?string $errorCode = null,
    ) {
    }
}
