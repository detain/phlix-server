<?php

declare(strict_types=1);

/**
 * Authentication configuration.
 *
 * Holds the boot-time defaults for the auth subsystem. Admin-editable
 * overrides are layered on top via the server-settings store
 * ({@see \Phlix\Admin\SettingsRepository}); the *effective* value is the
 * override when present, else the default declared here.
 *
 * The dotted setting key for the signup mode is `auth.signup_mode`
 * (the `auth` file segment + the `signup_mode` array key), declared in
 * the shared `server-settings.schema.json` allow-list.
 *
 * @since S1 (signup approval gate)
 */

return [
    /**
     * Controls what happens when a (non-first) user registers:
     *   - 'open'     — create an active user and issue tokens immediately.
     *   - 'approval' — create a PENDING user, issue NO tokens; an admin must
     *                  approve before the account can log in or see media.
     *   - 'disabled' — reject the registration with HTTP 403; no user is created.
     *
     * The very first registered user is ALWAYS created active + admin
     * regardless of this setting so a fresh install can bootstrap.
     */
    'signup_mode' => 'approval',

    /**
     * Password policy.
     *
     * Addressed by the dotted setting key `auth.password.min_length`
     * (the `auth` file segment + the `password` -> `min_length` path).
     */
    'password' => [
        /**
         * Minimum characters required in a user password.
         *
         * Enforced by {@see \Phlix\Auth\PasswordPolicy}, which is the single
         * check behind self-service registration AND both administrator
         * password paths. `PasswordPolicy::ABSOLUTE_MIN_LENGTH` clamps this
         * from below, so lowering it here (or via an override) cannot weaken
         * the policy past the historical baseline of 8.
         */
        'min_length' => 8,
    ],

    /**
     * Access-token lifetime in seconds (1 hour).
     *
     * Addressed by the dotted setting key `auth.access_ttl`. Read at MINT time
     * by {@see \Phlix\Auth\TokenTtlPolicy} via {@see \Phlix\Auth\JwtHandler},
     * which is the single source the token's `exp` claim, the auth response's
     * `expires_in` and the session cookie's `Max-Age` all derive from.
     *
     * `TokenTtlPolicy::MIN_ACCESS_TTL`/`MAX_ACCESS_TTL` clamp this in code, so
     * an out-of-range value here (or in an override) cannot mint a token that
     * expires instantly or one that lives for a year.
     */
    /**
     * Maximum number of profiles a single user account may create.
     *
     * Addressed by the dotted setting key `auth.max_profiles`. Enforced by
     * {@see \Phlix\Auth\UserProfileManager::maxProfiles()}, which is the single
     * point both cap checks go through — `UserProfileManager::create()` and the
     * pre-check in `AdminProfileController::createForUser()` (the one an
     * operator actually hits, since it returns 400 first).
     *
     * Clamped to `MIN_MAX_PROFILES`..`MAX_MAX_PROFILES` in code, so a 0 here
     * cannot make profile creation impossible.
     */
    'max_profiles' => 5,

    'access_ttl' => 3600,

    /**
     * Refresh-token lifetime in seconds (7 days).
     *
     * Addressed by the dotted setting key `auth.refresh_ttl`. Clamped by
     * `TokenTtlPolicy::MIN_REFRESH_TTL`/`MAX_REFRESH_TTL`, and additionally
     * raised to at least the access TTL — a refresh token that expires before
     * the access token it renews would end the session early with no way for
     * the client to continue.
     */
    'refresh_ttl' => 604800,
];
