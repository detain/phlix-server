<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Auth;

use RuntimeException;

/**
 * Thrown when a user authenticates with correct credentials but has the
 * `must_change_password` flag set on their account — an ACCOUNT HOLD.
 *
 * Shipped refuse-sites (SRV-M18, review server__pm): {@see AuthManager::login()}
 * and {@see AuthManager::refreshToken()} raise it on their own typed legs;
 * {@see AuthManager::loginWithProvider()} raises it before any success
 * bookkeeping; and {@see AuthManager::buildAuthResponse()} — the single seam
 * every remaining minter (passkey finish, quick-connect approval, profile
 * switch) converges on — refuses at the gate itself, so no route can mint a
 * session around the hold.
 *
 * Carries a stable error code so the HTTP boundary can return a distinct
 * 403 with `{error: "...", code: "auth.password_change_required"}` that
 * the client UI can interpret as "show the password change form". That
 * boundary mapping is NOT shipped (SRV-M17): until it lands the served
 * legs answer unmapped 500s / the ldap arm's fail-closed 503 — always
 * WITHOUT a session.
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
            'Your password must be changed before you can sign in. Ask your server administrator to reset it.'
        );
    }
}
