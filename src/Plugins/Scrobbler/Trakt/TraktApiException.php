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
 * Exception for Trakt API errors (non-auth).
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
class TraktApiException extends \RuntimeException
{
    /**
     * @param string $message Error message
     * @param int $code HTTP status code if applicable
     */
    public function __construct(string $message, int $code = 0)
    {
        parent::__construct($message, $code);
    }
}
