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
 * Thrown by {@see AuthManager::login()} when a user authenticates with the
 * correct password but the account is not 'active' — i.e. it is awaiting
 * administrator approval ('pending') or has been suspended ('disabled').
 *
 * Carries a stable error code so the HTTP boundary can distinguish the two
 * cases (both map to 403):
 *   - pending  → {@see self::ERROR_PENDING}  (`auth.account_pending`)
 *   - disabled → {@see self::ERROR_DISABLED} (`auth.account_disabled`)
 *
 * @package Phlix\Auth
 * @since   S1 (signup approval gate)
 */
final class AccountInactiveException extends RuntimeException
{
    public const ERROR_PENDING  = 'auth.account_pending';
    public const ERROR_DISABLED = 'auth.account_disabled';

    /** @var string Stable error code (one of the ERROR_* constants). */
    public string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        $this->errorCode = $errorCode;
        parent::__construct($message);
    }

    /**
     * Build the exception for a given (non-active) account status.
     *
     * @param string $status The user's `status` column value.
     */
    public static function forStatus(string $status): self
    {
        if ($status === 'disabled') {
            return new self(
                self::ERROR_DISABLED,
                'Your account has been disabled. Please contact an administrator.'
            );
        }

        return new self(
            self::ERROR_PENDING,
            'Your account is awaiting administrator approval.'
        );
    }
}
