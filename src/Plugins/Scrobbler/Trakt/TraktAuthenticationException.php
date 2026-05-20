<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * Exception for Trakt authentication failures (401 Unauthorized).
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
final class TraktAuthenticationException extends TraktApiException
{
}
