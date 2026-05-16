<?php

declare(strict_types=1);

namespace Phlex\Common\Events\Auth;

use Phlex\Common\Events\AbstractEvent;

/**
 * Fired when a user session ends.
 *
 * Fired by: explicit logout handler in `\Phlex\Auth\AuthManager` (added
 * in this step) plus the session-expiry / token-revocation paths added
 * in subsequent phases. The `reason` field disambiguates the three
 * paths so listeners can react appropriately (e.g., only notify the user
 * on "revoked").
 * Typical listener: presence integrations (clear "online"), audit-log
 * writer, security-alert notifier ("your session was revoked"), hub-side
 * session mirror.
 *
 * Manifest alias (Phase A.4 loader): `phlex.user.logged_out`.
 *
 * @package Phlex\Common\Events\Auth
 * @since 0.10.0
 */
final class UserLoggedOut extends AbstractEvent
{
    /**
     * "User explicitly logged out via the client / API." Used by the
     * standard logout endpoint.
     */
    public const REASON_EXPLICIT = 'explicit';

    /**
     * "Session token expired naturally." Used when the JWT TTL elapses.
     */
    public const REASON_EXPIRED = 'expired';

    /**
     * "Session was revoked by an admin or by the user from another
     * device." Used when an active session is forcibly terminated.
     */
    public const REASON_REVOKED = 'revoked';

    /**
     * @param string $userId    UUID of the user whose session ended.
     * @param string $sessionId UUID / opaque ID identifying the session
     *                          that ended.
     * @param string $reason    One of {@see REASON_EXPLICIT},
     *                          {@see REASON_EXPIRED}, {@see REASON_REVOKED}.
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $sessionId,
        public readonly string $reason,
    ) {
        parent::__construct();
    }
}
