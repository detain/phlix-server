<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Admin\SettingsRepository;

/**
 * Single enforcement point for the password-strength policy.
 *
 * ## Why this class exists
 *
 * The minimum password length was a bare `strlen($password) < 8` duplicated at
 * THREE call sites — {@see AuthManager::register()} and both password paths in
 * `AdminUserController` (create-user and change-password). A setting wired to
 * only some of its duplicate literals is half-effective: an admin could raise
 * the minimum, watch self-service registration honour it, and still create a
 * weaker password from the admin UI. Centralising the check here is what makes
 * `auth.password.min_length` an honest control rather than a partial one.
 *
 * ## Read path
 *
 * Class (a) LIVE: the effective value is read via
 * {@see SettingsRepository::getEffective()} at validation time, so an admin
 * override applies immediately with no restart. Mirrors how
 * `auth.signup_mode` is consumed in {@see AuthManager::register()}.
 *
 * ## The floor is deliberate
 *
 * {@see self::ABSOLUTE_MIN_LENGTH} is enforced in code, not only through the
 * schema's `minimum`. The schema bound stops the admin API from persisting a
 * weaker value; this floor additionally covers a `server_settings` row written
 * by any other means (direct SQL, a restored backup, an orphaned row left by a
 * renamed key). The setting can therefore only ever STRENGTHEN the policy Phlix
 * has always applied — a configurability feature must not become a way to
 * quietly weaken authentication.
 *
 * @package Phlix\Auth
 * @since 1.3.0
 */
final class PasswordPolicy
{
    /**
     * The dotted settings key backing {@see self::minLength()}.
     */
    public const SETTING_KEY = 'auth.password.min_length';

    /**
     * Hard floor for the minimum length, matching the schema's `minimum` and
     * the literal that was previously hardcoded at all three call sites.
     *
     * A configured value below this is clamped UP, never honoured.
     */
    public const ABSOLUTE_MIN_LENGTH = 8;

    /**
     * Upper bound, matching the schema's `maximum`. A value above this is
     * clamped DOWN so a fat-fingered override cannot make every password
     * unsettable and effectively lock the install out of user management.
     */
    public const ABSOLUTE_MAX_LENGTH = 128;

    /**
     * @param SettingsRepository|null $settings Effective-settings store. NULL
     *        degrades to {@see self::ABSOLUTE_MIN_LENGTH} so the policy still
     *        enforces the historical baseline when the store is unavailable.
     *
     *        NOTE for DI: PHP-DI skips optional constructor parameters during
     *        autowiring, so every binding that needs a configured policy must
     *        name this parameter explicitly. `PasswordPolicyWiringTest` asserts
     *        the container actually hands over a configured instance — without
     *        that, this silently degrades to the constant and the setting
     *        becomes inert (read-path class (g)).
     */
    public function __construct(
        private readonly ?SettingsRepository $settings = null,
    ) {
    }

    /**
     * The effective minimum password length, clamped to the absolute bounds.
     *
     * @return int Between {@see self::ABSOLUTE_MIN_LENGTH} and
     *         {@see self::ABSOLUTE_MAX_LENGTH} inclusive.
     *
     * @since 1.3.0
     */
    public function minLength(): int
    {
        if ($this->settings === null) {
            return self::ABSOLUTE_MIN_LENGTH;
        }

        try {
            /** @var mixed $configured */
            $configured = $this->settings->getEffective(self::SETTING_KEY);
        } catch (\Throwable) {
            // A settings-store failure must never make passwords EASIER to set,
            // so fall back to the floor rather than to "no policy".
            return self::ABSOLUTE_MIN_LENGTH;
        }

        $value = match (true) {
            is_int($configured) => $configured,
            is_string($configured) && is_numeric($configured) => (int) $configured,
            default => self::ABSOLUTE_MIN_LENGTH,
        };

        return max(self::ABSOLUTE_MIN_LENGTH, min(self::ABSOLUTE_MAX_LENGTH, $value));
    }

    /**
     * Validate a candidate password against the effective policy.
     *
     * @param string $password Candidate plaintext password.
     *
     * @return string|null Human-readable error message, or NULL when the
     *         password is acceptable.
     *
     * @since 1.3.0
     */
    public function validate(string $password): ?string
    {
        $min = $this->minLength();

        // Deliberately strlen(), not mb_strlen(): this preserves the exact
        // behaviour of the three literals it replaces. Changing to a codepoint
        // count here would silently loosen the policy for non-ASCII passwords
        // (a 3-character CJK password is 9 bytes), which is a security change
        // that does not belong in a refactor.
        if (strlen($password) < $min) {
            return sprintf('Password must be at least %d characters', $min);
        }

        return null;
    }
}
