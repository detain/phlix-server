<?php

/**
 * Phlix media server component: Access.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Access;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;

/**
 * Answers the one authorization question the parental-controls API asks:
 * **may this account manage this profile's restrictions?** (S208)
 *
 * ## The rule, written down
 *
 * A caller may read or modify a profile's access schedules, blocked/allowed
 * tags and stream limits when EITHER:
 *
 *  1. the profile belongs to the caller's own account
 *     (`user_profiles.user_id === $userId`) — the account holder configures the
 *     restrictions for the child profiles under their own account; or
 *  2. the caller is a server admin (`users.is_admin = 1 AND status='active'`,
 *     via {@see UserRepository::findAdminById()}) — this is the branch the admin
 *     SPA's Parental Controls screen runs on, because an admin edits *other*
 *     users' profiles.
 *
 * Everything else is refused. Refusals are reported by the calling controller
 * as **404, never 403**: a 403 would confirm to an unentitled caller that the
 * profile exists.
 *
 * ## Why this is a separate class rather than a nullable middleware
 *
 * {@see \Phlix\Server\Http\Controllers\LibraryController} carries an
 * `?AdminMiddleware $adminMiddleware = null` set through a setter, and its
 * `requireAdmin()` **allows the request** when that property is null. That
 * shape fails OPEN, and PHP-DI's `autowire()` silently skips optional
 * constructor parameters, so a nullable dependency here would be one wiring
 * mistake away from re-opening the exact hole S208 closes. This policy is a
 * REQUIRED constructor dependency of every parental-controls controller: if it
 * cannot be built, construction throws loudly instead of degrading to "allow".
 *
 * @package Phlix\Access
 * @since   S208
 */
final class ProfileAccessPolicy
{
    /**
     * @param UserProfileManager $profiles Resolves `{profileId}` → owning `user_id`.
     * @param UserRepository     $users    Admin-row lookup for the escalation branch.
     */
    public function __construct(
        private readonly UserProfileManager $profiles,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * May `$userId` manage `$profileId`'s restrictions?
     *
     * Returns false — not an exception — for an unknown profile, so the caller
     * can answer 404 uniformly for "no such profile" and "not yours".
     *
     * The owner check runs FIRST so the ordinary self-service case costs one
     * query and, more importantly, never emits an
     * {@see \Phlix\Common\Logger\AuditLogger} permission-denied entry for a user
     * touching their own profile.
     *
     * @param string|null $userId    Authenticated account id (`Request::$userId`).
     * @param string      $profileId Profile id from the request path.
     *
     * @return bool True when the request may proceed.
     */
    public function canManageProfile(?string $userId, string $profileId): bool
    {
        if ($userId === null || $userId === '' || $profileId === '') {
            return false;
        }

        $profile = $this->profiles->findById($profileId);
        if ($profile === null) {
            return false;
        }

        $owner = $profile['user_id'] ?? null;
        if (is_string($owner) && $owner !== '' && hash_equals($owner, $userId)) {
            return true;
        }

        return $this->users->findAdminById($userId) !== null;
    }
}
