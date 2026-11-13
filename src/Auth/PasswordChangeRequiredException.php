<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Common\Logger\AuditLogger;
use RuntimeException;

/**
 * Thrown when a user authenticates with correct credentials but has the
 * `must_change_password` flag set on their account — an ACCOUNT HOLD.
 *
 * The law is single-sourced in {@see self::refuseIfHeld()} — the shared
 * hold-guard every token-minting surface calls before it mints (SRV-M18
 * rework, review server__pm): {@see AuthManager::login()} and
 * {@see AuthManager::refreshToken()} raise it on their own typed legs;
 * {@see AuthManager::loginWithProvider()} raises it before any success
 * bookkeeping; {@see AuthManager::buildAuthResponse()} — the seam the
 * passkey finish, quick-connect approval and profile-switch mints converge
 * on — refuses through the guard as its first act; the OIDC and GitHub
 * browser callbacks mint only via their own `createTokensForUser()` after
 * the guard has passed (a held account is answered a
 * `?error=password_change_required` redirect with zero cookies); and the
 * hub-token exchange calls the guard — keyed on the LOCAL account the hub
 * principal RESOLVES to via the `RelayIdentityResolver` link, never on the
 * raw hub-side claim (SRV-M18 RW2) — before its single-token mint. The
 * OPDS HTTP-Basic surface consults the same flag (no token mints there;
 * {@see AuthManager::verifyCredentials()} answers the hold a silent null).
 * With every minter gated there is no route that can mint a session around
 * the hold.
 *
 * Carries a stable error code so the HTTP boundary can return a distinct
 * 403 with `{error: "...", code: "auth.password_change_required"}` that
 * the client UI can interpret as "this account is on hold — contact your
 * server administrator for a reset". The shipped posture is admin-reset: the
 * hold is cleared by an administrator (`bin/phlix user:reset-password`;
 * AdminUserController::resetPassword only force-changes and revokes), there is no
 * self-service change-password path for a held account. That
 * boundary mapping is SHIPPED (SRV-M17): the password-login, LDAP-login,
 * passkey-finish, quick-connect-token and profile-switch legs catch this
 * exception at the controller edge and answer exactly that 403 envelope —
 * the code is the contracts-registered member of the auth.* family, no new
 * wire codes. The OIDC/GitHub browser callbacks answer their leg's shape (a
 * `?error=password_change_required` redirect, zero cookies). Every refusal
 * stays WITHOUT a session.
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

    /**
     * The single-source ACCOUNT-HOLD guard (SRV-M18 rework, review server__pm).
     *
     * Every surface that mints a session-shaped token calls this immediately
     * before the mint; it refuses a held account with this typed exception so
     * the check itself can never drift between surfaces. When an
     * {@see AuditLogger} is at hand the refusal is audited under the stable
     * reason `password_change_required` with the surface's context stamp —
     * byte-identical to the shape {@see AuthManager::buildAuthResponse()}
     * shipped at d8c834d5; surfaces that carry no audit collaborator (the
     * plugin OAuth callbacks, the hub-token exchange) pass null and answer
     * the refusal in their own shipped voice (AUTH-channel log lines /
     * the router's central error log).
     *
     * @param UserRepository   $users   Holds the flag's sole source of truth.
     * @param AuditLogger|null $audit   Audit sink, or null (see above).
     * @param string           $userId  The account about to receive a token.
     * @param string           $context Refusal-surface stamp ('auth_response',
     *                                  'oidc_callback', 'github_callback',
     *                                  'hub_token').
     *
     * @return void Returns normally when the account is NOT held.
     *
     * @throws self When the account carries the hold — mint nothing.
     */
    public static function refuseIfHeld(
        UserRepository $users,
        ?AuditLogger $audit,
        string $userId,
        string $context,
    ): void {
        if (!$users->mustChangePassword($userId)) {
            return;
        }

        $audit?->logFailedAuth('password_change_required', [
            'user_id' => $userId,
            'context' => $context,
        ]);

        throw new self();
    }
}
