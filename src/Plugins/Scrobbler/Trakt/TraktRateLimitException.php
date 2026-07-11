<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * Exception thrown when Trakt API returns a rate-limit (429) response.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
final class TraktRateLimitException extends TraktApiException
{
    /**
     * @param string $message Exception message
     * @param int $retryAfter Seconds to wait before retrying (from Retry-After header)
     */
    public function __construct(
        string $message = 'Rate limit exceeded',
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message, 429);
    }
}
