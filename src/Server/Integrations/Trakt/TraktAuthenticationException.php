<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Integrations\Trakt;

/**
 * Exception for Trakt authentication failures (401 Unauthorized).
 *
 * @package Phlix\Server\Integrations\Trakt
 * @since 0.14.0
 */
final class TraktAuthenticationException extends TraktApiException
{
}
