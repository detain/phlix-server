<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Auth;

use RuntimeException;

/**
 * Thrown by {@see AuthManager::login()} and {@see AuthManager::refreshToken()}
 * when a user authenticates with correct credentials but has the
 * `must_change_password` flag set on their account.
 *
 * Carries a stable error code so the HTTP boundary can return a distinct
 * 403 with `{error: "...", code: "auth.password_change_required"}` that
 * the client UI can interpret as "show the password change form".
 *
 * @package Phlix\Auth
 * @since   S7+F1
 */
final class PasswordChangeRequiredException extends RuntimeException
{
    public const ERROR_CODE = 'auth.password_change_required';

    /** @var string Stable error code for the HTTP boundary. */
    public string $errorCode;

    public function __construct()
    {
        $this->errorCode = self::ERROR_CODE;
        parent::__construct(
            'Your password must be changed before you can access the system. Please use the password reset link.'
        );
    }
}
