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

        /**
         * Whether username-and-password sign-in is accepted (F7 toggle).
         *
         * Addressed by the dotted setting key `auth.password.enabled`. This
         * file value is the DEFAULT; the live answer comes from
         * {@see \Phlix\Auth\AuthMethodPolicy::isEnabled()} (override when
         * present, else this default, absent-safe true). Enforced at
         * `AuthManager::login()` and `AuthManager::verifyCredentials()` (OPDS
         * HTTP-Basic); write-time lock-out guards (never all-off, never
         * lock-out-an-active-admin) live in
         * {@see \Phlix\Auth\AuthMethodPolicy::assertSafeTransition()}.
         */
        'enabled' => true,
    ],

    /**
     * Passkey (WebAuthn) sign-in toggle (F7).
     *
     * Addressed by the dotted setting key `auth.webauthn.enabled`, consumed by
     * the same {@see \Phlix\Auth\AuthMethodPolicy} as the password toggle.
     * NOTE the namespace collision deliberately avoided: this is the `auth`
     * file's `webauthn` subtree, NOT the top-level `$appConfig['webauthn']`
     * relying-party block (rp_id/rp_name/rp_origin) composed elsewhere —
     * `config/server.php` declares no `auth` key and no top-level `webauthn`
     * key, so the two never meet (213fce9d dotted-path near-miss law; pinned
     * by `tests/Unit/Admin/AuthMethodFlagsReachabilityTest.php`). Switching
     * this off gates the login and NEW-enrolment routes but keeps credential
     * management routes live, and never deletes stored credentials.
     */
    'webauthn' => [
        'enabled' => true,
    ],

    /**
     * External provider toggles (F7).
     *
     * `auth.oidc.enabled` / `auth.ldap.enabled` / `auth.github.enabled` are the
     * same `server_settings` rows {@see \Phlix\Auth\AuthProviderBootstrapper}
     * has always owned: ABSENT means OFF, and the bootstrapper ignores config
     * defaults for these keys — so the `false` values below exist to satisfy
     * the schema-defaults resolvability contract and to DOCUMENT the effective
     * absence semantics, not to act as a second switch.
     */
    'oidc' => [
        'enabled' => false,
    ],

    'ldap' => [
        'enabled' => false,
    ],

    'github' => [
        'enabled' => false,
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
