<?php

declare(strict_types=1);

namespace Phlex\Auth;

use RuntimeException;

/**
 * Thrown when an auth provider lookup fails.
 *
 * @package Phlex\Auth
 * @author Phlex Team
 * @version 1.0.0
 * @description Exception thrown when a requested auth provider is not registered.
 */
final class AuthProviderNotFoundException extends RuntimeException
{
}
