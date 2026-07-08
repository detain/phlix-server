<?php

/**
 * Phlix media server component: Oidc.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Plugins\Oidc;

/**
 * Exception thrown when OIDC token validation fails.
 *
 * @package Phlix\Plugins\Oidc
 * @since 0.11.0
 */
final class OidcValidationException extends \RuntimeException
{
}
