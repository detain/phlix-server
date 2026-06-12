<?php

declare(strict_types=1);

namespace Phlix\Auth;

use RuntimeException;

/**
 * Thrown by {@see AuthManager::register()} when the `auth.signup_mode` setting
 * is 'disabled': self-service registration is turned off and no user is created.
 *
 * The HTTP boundary maps this to 403 with the error code `auth.signups_disabled`.
 *
 * @package Phlix\Auth
 * @since   S1 (signup approval gate)
 */
final class SignupDisabledException extends RuntimeException
{
    /** Stable error code surfaced to API clients. */
    public const ERROR_CODE = 'auth.signups_disabled';

    public function __construct(string $message = 'Signups are currently disabled.')
    {
        parent::__construct($message);
    }
}
