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
 * Single enforcement point for the JWT access- and refresh-token lifetimes.
 *
 * ## Why this class exists
 *
 * The two TTLs were spread across FOUR independent literals that nothing kept
 * in agreement:
 *
 *  1. `JwtHandler::__construct()`'s defaults (`3600` / `604800`) — what the
 *     token's `exp` claim was actually built from.
 *  2. `AuthServicesProvider`'s fallbacks — which were ALWAYS used, because
 *     `config/server.php` has never had a `jwt` key, so `$appConfig['jwt']`
 *     was permanently absent and the "configurable" branch was dead.
 *  3. `AuthManager::buildAuthResponse()`'s hardcoded `'expires_in' => 3600` —
 *     independent of the handler that minted the token, so the number the
 *     client was TOLD and the `exp` it could read out of the JWT were two
 *     unrelated constants that happened to match.
 *  4. `AuthController::attachAuthCookies()`'s `7 * 24 * 3600` refresh-cookie
 *     `Max-Age`, plus a second `3600` fallback for the access cookie.
 *
 * Wiring a setting to any subset of those is worse than shipping no setting at
 * all: shortening the access TTL would have expired the token while the client
 * still believed it had an hour, and the refresh cookie would have outlived (or
 * been outlived by) the refresh token it carried. Every one of those sites now
 * resolves through a single {@see JwtHandler} instance, which resolves through
 * this class, so the value cannot drift between them.
 *
 * ## Read path
 *
 * Class (a) LIVE: the effective value is read via
 * {@see SettingsRepository::getEffective()} at MINT time, so an override
 * applies to the next token issued with no restart. Mirrors
 * {@see PasswordPolicy}.
 *
 * Changing a TTL never invalidates a token that has already been issued —
 * validation only checks the `exp` baked into the token when it was minted.
 * Shortening the access TTL therefore takes full effect within one old TTL,
 * and nobody is signed out at the moment of the change.
 *
 * ## The clamps are deliberate, and differ from PasswordPolicy's
 *
 * A password minimum can only be made stronger, so that clamp is one-sided.
 * A token lifetime is unsafe in BOTH directions — too long is a credential
 * that outlives its usefulness, too short makes the install unusable — so both
 * bounds are enforced in code as well as through the schema's
 * `minimum`/`maximum`. The schema bound stops the admin API from persisting an
 * out-of-range value; these constants additionally cover a `server_settings`
 * row written by any other means (direct SQL, a restored backup, an orphaned
 * row left behind by a renamed key).
 *
 * {@see self::refreshTtl()} additionally enforces `refresh >= access`. A
 * refresh token that expires before the access token it is meant to renew is
 * incoherent — the session would end at the refresh TTL while the client was
 * still holding a valid access token and had no way to continue.
 *
 * @package Phlix\Auth
 * @since 1.3.0
 */
final class TokenTtlPolicy
{
    /**
     * The dotted settings key backing {@see self::accessTtl()}.
     */
    public const ACCESS_TTL_KEY = 'auth.access_ttl';

    /**
     * The dotted settings key backing {@see self::refreshTtl()}.
     */
    public const REFRESH_TTL_KEY = 'auth.refresh_ttl';

    /**
     * Shipped access-token lifetime: 1 hour. Matches the literal this class
     * replaces at all four historical sites.
     */
    public const DEFAULT_ACCESS_TTL = 3600;

    /**
     * Shipped refresh-token lifetime: 7 days. Matches the literal this class
     * replaces at all four historical sites.
     */
    public const DEFAULT_REFRESH_TTL = 604800;

    /**
     * Floor for the access TTL: 1 minute. Below this, clock skew between the
     * server and a client becomes comparable to the whole lifetime and tokens
     * start failing validation the moment they are issued.
     */
    public const MIN_ACCESS_TTL = 60;

    /**
     * Ceiling for the access TTL: 24 hours. The access token is the bearer
     * credential sent on every request and is NOT revocable, so its lifetime
     * is the window an intercepted token stays useful. A day is the outer
     * bound of defensible; longer belongs to the refresh token, which at least
     * rotates.
     */
    public const MAX_ACCESS_TTL = 86400;

    /**
     * Floor for the refresh TTL: 1 hour. Also the point below which the
     * refresh token stops being meaningfully longer-lived than the access
     * token it renews.
     */
    public const MIN_REFRESH_TTL = 3600;

    /**
     * Ceiling for the refresh TTL: 90 days. Long enough for a TV or console
     * that is signed in once and used occasionally; short enough that an
     * abandoned device eventually stops being a live credential.
     */
    public const MAX_REFRESH_TTL = 7776000;

    /**
     * Effective-settings store, or NULL to degrade to the shipped defaults.
     * Populated lazily when {@see self::deferred()} was used.
     */
    private ?SettingsRepository $settings;

    /**
     * Deferred store resolver, or NULL when the store was supplied directly.
     *
     * @var (\Closure(): ?SettingsRepository)|null
     */
    private ?\Closure $resolver = null;

    /**
     * Whether {@see self::$settings} is final (no resolver left to run).
     */
    private bool $resolved = true;

    /**
     * @param SettingsRepository|null $settings Effective-settings store. NULL
     *        degrades to the shipped defaults, so the policy still yields the
     *        historical lifetimes when the store is unavailable.
     *
     *        NOTE for DI: PHP-DI skips optional constructor parameters during
     *        autowiring, so any binding that needs a configured policy must
     *        pass this explicitly. {@see \Phlix\Tests\Unit\Auth\TokenTtlEnforcementTest}
     *        covers the wired path — without it, this silently degrades to the
     *        constants and both settings become inert (read-path class (g)).
     */
    public function __construct(
        ?SettingsRepository $settings = null,
    ) {
        $this->settings = $settings;
    }

    /**
     * A policy that resolves its settings store on FIRST READ rather than at
     * construction time.
     *
     * ## Why this exists
     *
     * {@see JwtHandler} is a pure crypto object. Handing it a
     * {@see SettingsRepository} directly made resolving it from the container
     * transitively require a live database connection, which broke every
     * context that legitimately has no DB yet — `ContainerFactoryTest` caught
     * it immediately, and the same coupling would have bitten any CLI entry
     * point or worker that builds the container before
     * `ConnectionPool::init()`.
     *
     * Deferring costs nothing in practice: a TTL is only ever read while
     * minting a token, and minting already requires the database.
     *
     * @param \Closure(): ?SettingsRepository $resolver Invoked at most once.
     *        Any throw degrades the policy to the shipped defaults.
     *
     * @since 1.3.0
     */
    public static function deferred(\Closure $resolver): self
    {
        $policy = new self();
        $policy->resolver = $resolver;
        $policy->resolved = false;

        return $policy;
    }

    /**
     * The settings store, running the deferred resolver at most once.
     */
    private function store(): ?SettingsRepository
    {
        if (!$this->resolved) {
            $resolver = $this->resolver;

            // Cleared BEFORE invoking so a throwing resolver is not retried on
            // every token mint in a resident worker.
            $this->resolved = true;
            $this->resolver = null;

            try {
                $this->settings = $resolver === null ? null : $resolver();
            } catch (\Throwable) {
                $this->settings = null;
            }
        }

        return $this->settings;
    }

    /**
     * The effective access-token lifetime in seconds, clamped to
     * {@see self::MIN_ACCESS_TTL}..{@see self::MAX_ACCESS_TTL}.
     *
     * @return int Seconds.
     *
     * @since 1.3.0
     */
    public function accessTtl(): int
    {
        $value = $this->configured(self::ACCESS_TTL_KEY, self::DEFAULT_ACCESS_TTL);

        return max(self::MIN_ACCESS_TTL, min(self::MAX_ACCESS_TTL, $value));
    }

    /**
     * The effective refresh-token lifetime in seconds, clamped to
     * {@see self::MIN_REFRESH_TTL}..{@see self::MAX_REFRESH_TTL} and then
     * raised, if necessary, to at least {@see self::accessTtl()}.
     *
     * @return int Seconds.
     *
     * @since 1.3.0
     */
    public function refreshTtl(): int
    {
        $value = $this->configured(self::REFRESH_TTL_KEY, self::DEFAULT_REFRESH_TTL);
        $clamped = max(self::MIN_REFRESH_TTL, min(self::MAX_REFRESH_TTL, $value));

        // A refresh token must never expire before the access token it renews.
        return max($clamped, $this->accessTtl());
    }

    /**
     * Read one TTL key from the settings store, coercing and degrading safely.
     *
     * @param string $key      Dotted setting key.
     * @param int    $fallback Value to use when the store is absent, throws, or
     *                         holds something that is not a whole number.
     *
     * @return int The raw configured value; the caller applies the clamps.
     */
    private function configured(string $key, int $fallback): int
    {
        $settings = $this->store();
        if ($settings === null) {
            return $fallback;
        }

        try {
            /** @var mixed $configured */
            $configured = $settings->getEffective($key);
        } catch (\Throwable) {
            // A settings-store failure must never lengthen a credential's
            // life, so fall back to the shipped default rather than to an
            // unbounded or zero value.
            return $fallback;
        }

        return match (true) {
            is_int($configured) => $configured,
            is_string($configured) && is_numeric($configured) => (int) $configured,
            default => $fallback,
        };
    }
}
