<?php

declare(strict_types=1);

namespace Phlex\Plugins\Scrobbler\Trakt;

/**
 * Exception for Trakt authentication failures (401 Unauthorized).
 *
 * @package Phlex\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
final class TraktAuthenticationException extends TraktApiException
{
}
