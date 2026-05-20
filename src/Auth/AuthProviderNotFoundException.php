<?php

declare(strict_types=1);

namespace Phlix\Auth;

use RuntimeException;

/**
 * Thrown when an auth provider lookup fails.
 *
 * @package Phlix\Auth
 * @author Phlix Team
 * @version 1.0.0
 * @description Exception thrown when a requested auth provider is not registered.
 */
final class AuthProviderNotFoundException extends RuntimeException
{
}
