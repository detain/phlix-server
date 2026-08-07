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
 * Thrown by {@see UserProfileManager::resolveProfileIdForUser()} when a caller
 * asks for a specific `profile_id` that the authenticated account does not own.
 *
 * This is the horizontal-privilege boundary for profile-scoped data (S79/S80).
 * Once favorites, ratings, like level and watched state are keyed on
 * `user_item_data.profile_id`, a `profile_id` taken from a request body, query
 * string or path parameter is attacker-controlled input: user A supplying user
 * B's profile id would otherwise read and write B's data. The resolver therefore
 * never trusts a supplied id — it re-derives ownership from `user_profiles`
 * against the authenticated `user_id` on every call, and raises this when the
 * two do not agree.
 *
 * HTTP boundaries should answer **404**, not 403. A 403 confirms to an
 * unentitled caller that the profile exists, which is the same enumeration leak
 * {@see \Phlix\Server\Http\Controllers\ProfileTagController} avoids. The
 * distinct exception type exists so that mapping can be made without string
 * matching on a message — this repository has been burned by substring-based
 * error classifiers before (see {@see \Phlix\Common\Database\MigrationRunner}).
 *
 * @package Phlix\Auth
 * @since   S79 (profile-scoped user item data)
 */
final class ProfileNotOwnedException extends RuntimeException
{
    /** Stable error code for the HTTP boundary. */
    public const ERROR_CODE = 'profile.not_owned';

    /**
     * Build the exception for a refused (user, profile) pair.
     *
     * ⚠ The message deliberately does NOT distinguish "no such profile" from
     * "someone else's profile" — both are the same refusal, for the same
     * enumeration reason the 404 mapping exists.
     *
     * @param string $userId    The authenticated account the request ran as.
     * @param string $profileId The profile id the caller asked for.
     */
    public static function forRequestedProfile(string $userId, string $profileId): self
    {
        return new self(sprintf(
            'Profile %s is not available to user %s',
            $profileId,
            $userId
        ));
    }
}
