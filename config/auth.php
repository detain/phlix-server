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
];
