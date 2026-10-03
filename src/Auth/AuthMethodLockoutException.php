<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth;

/**
 * A proposed auth-method toggle set that would lock the install out.
 *
 * Thrown by {@see AuthMethodPolicy::assertSafeTransition()} when a write-set
 * touching the five `auth.<method>.enabled` toggles would leave either nobody
 * able to sign in at all (reason {@see self::REASON_ALL_METHODS_DISABLED},
 * rule R1) or an active administrator without a single usable sign-in factor
 * (reason {@see self::REASON_ADMIN_LOCKOUT}, rule R2/R2-bis).
 *
 * The HTTP surfaces translate this to 422 with the reason as the per-key error
 * string; nothing is persisted when it is thrown. Carrying the reason as a
 * constant (rather than parsing messages) keeps the mapping a total function.
 *
 * @package Phlix\Auth
 * @since 1.4.0 (F7 auth-method toggles)
 */
final class AuthMethodLockoutException extends \RuntimeException
{
    /**
     * The proposed state turns every one of the five sign-in methods off.
     */
    public const REASON_ALL_METHODS_DISABLED = 'all_methods_disabled';

    /**
     * At least one active administrator has no usable factor under the
     * proposed state.
     */
    public const REASON_ADMIN_LOCKOUT = 'admin_lockout';

    /**
     * @param string $reason        One of {@see self::REASON_ALL_METHODS_DISABLED}
     *                              or {@see self::REASON_ADMIN_LOCKOUT}.
     * @param list<string> $blockedAdminIds User ids of the active admins that
     *                              would lose their last factor (empty for the
     *                              all-disabled reason).
     */
    public function __construct(
        private readonly string $reason,
        private readonly array $blockedAdminIds = [],
    ) {
        parent::__construct($this->describeReason());
    }

    /**
     * Machine-readable violation reason (see the REASON_* constants).
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * Active admins that would be locked out — never echoed to the HTTP
     * boundary verbatim beyond a count; the ids belong in the audit log.
     *
     * @return list<string>
     */
    public function blockedAdminIds(): array
    {
        return $this->blockedAdminIds;
    }

    /**
     * Human-readable message for logs, derived from the parsed reason so the
     * two can never disagree.
     */
    private function describeReason(): string
    {
        if ($this->reason === self::REASON_ADMIN_LOCKOUT) {
            return sprintf(
                'auth.method_lockout: disabling these auth methods would leave %d active '
                    . 'administrator(s) with no usable sign-in factor',
                count($this->blockedAdminIds),
            );
        }

        return 'auth.method_lockout: at least one sign-in method must stay enabled';
    }
}
