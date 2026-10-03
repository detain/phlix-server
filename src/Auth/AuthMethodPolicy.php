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
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Common\Logger\StructuredLogger;

/**
 * Single source of truth for the five `auth.<method>.enabled` toggles (F7).
 *
 * ## What it answers
 *
 *   - {@see self::isEnabled()} — is method X currently usable for sign-in?
 *     Consulted at every enforcement point (password login, OPDS credential
 *     verification, WebAuthn login/enrolment) and by nothing else; no other
 *     class re-reads the five keys.
 *   - {@see self::assertSafeTransition()} — would a proposed toggle state lock
 *     anyone out? Consulted by BOTH write surfaces (the admin settings API and
 *     the provider integrations API) BEFORE anything is persisted.
 *
 * ## Read path
 *
 * Class (a) LIVE, exactly like {@see PasswordPolicy}: every answer comes from
 * a fresh settings read, so an override applies on the next request with no
 * restart. There is deliberately NO static/process cache (resident-process
 * law). One {@see SettingsRepository::getAllOverrides()} snapshot per instance
 * keeps a request that consults several methods at a single SELECT;
 * instances are request-scoped through the container, so the snapshot cannot
 * outlive a request. A snapshot whose five answers are ALL false is a state
 * the write guards make API-unreachable; if it is ever forced into the table
 * by hand (direct SQL, restored backup) the read path falls back to
 * password-only sign-in and logs {@see self::FALLBACK_EVENT} loudly on every
 * activation. The fallback is NEVER persisted — the emergency stays visible
 * to the operator instead of silently rewriting their data.
 *
 * ## Per-method absent-key semantics (no forked truth)
 *
 *   - `password` / `webauthn`: new store-side keys. Absent answers the config
 *     default (`config/auth.php`, which ships `true` for both), so installs
 *     that never touch the toggles behave exactly as they always did.
 *   - `oidc` / `ldap` / `github`: the SAME rows
 *     {@see AuthProviderBootstrapper::isEnabled()} reads. That class defines
 *     absent = OFF (`getOverride() !== null && value === true`) and ignores
 *     config defaults; this policy reproduces those exact semantics rather
 *     than inventing a second answer for the same row, because the
 *     bootstrapper still owns provider registration itself.
 *
 * ## The two lock-out rules
 *
 *   - R1 — the proposed state must not switch ALL five methods off: the
 *     fallback posture exists for hand-forced states, not as a UI option.
 *   - R2 / R2-bis — the proposed state must not leave an ACTIVE administrator
 *     (`is_admin = 1 AND status = 'active'`, the same predicate as
 *     {@see UserRepository::findAdminById()}) without at least one usable
 *     factor: a password when password sign-in stays on, a registered passkey
 *     when WebAuthn stays on, or a stored identity for an external provider
 *     that stays on AND remains configured. Disabling ANY method re-runs the
 *     probe, so the reverse direction (an admin whose only factor was the
 *     provider being disabled) is covered by the same code path, not a
 *     special case.
 *
 * Fail-fast: an unreadable settings store THROWS out of {@see self::state()}
 * and callers on the enforcement path must fail CLOSED (deny the sign-in),
 * mirroring the security-first posture the revocation checks already take.
 * A throw here is never a reason to sign anybody in more easily.
 *
 * @package Phlix\Auth
 * @since 1.4.0 (F7 auth-method toggles)
 */
final class AuthMethodPolicy
{
    /**
     * Sign-in method names governed by this policy.
     */
    public const PASSWORD = 'password';
    public const WEBAUTHN = 'webauthn';
    public const OIDC = 'oidc';
    public const LDAP = 'ldap';
    public const GITHUB = 'github';

    /**
     * The five governed methods, in stable order.
     *
     * @var list<string>
     */
    public const METHODS = [self::PASSWORD, self::WEBAUTHN, self::OIDC, self::LDAP, self::GITHUB];

    /**
     * Loud audit event emitted (once per instance) whenever the read path has
     * to apply the all-disabled password-only fallback. Never persisted.
     */
    public const FALLBACK_EVENT = 'auth.methods_all_disabled_fallback';

    /**
     * Store-side keys for the two methods this policy owns outright; the
     * external three derive theirs from {@see AuthProviderBootstrapper::flagKey()}.
     */
    private const OWN_KEYS = [
        self::PASSWORD => 'auth.password.enabled',
        self::WEBAUTHN => 'auth.webauthn.enabled',
    ];

    /**
     * @param SettingsRepository $settings  Override store for the five flag keys.
     * @param UserRepository     $users     Source of the active-admin set for R2.
     * @param WebAuthnCredentialRepository $webauthn Passkey factor probe.
     * @param UserIdentityRepository $identities External-provider factor probe.
     * @param AuthProviderBootstrapper $bootstrapper Provider-configuredness probe
     *        and the semantic authority for external flag truth.
     * @param StructuredLogger|null $logger Loud-log channel for the fallback
     *        posture; NULL (tests, degraded boot) still denies nothing — the
     *        fallback itself activates — but the emergency goes unheard.
     */
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly UserRepository $users,
        private readonly WebAuthnCredentialRepository $webauthn,
        private readonly UserIdentityRepository $identities,
        private readonly AuthProviderBootstrapper $bootstrapper,
        private readonly ?StructuredLogger $logger = null,
    ) {
    }

    /**
     * Per-instance snapshot; request-scoped like the rest of the class, so
     * this is a within-one-request memo, not a process cache.
     *
     * @var array<string, bool>|null
     */
    private ?array $stateCache = null;

    /**
     * One-shot fallback alarm per instance (precedent: WebAuthnManager's
     * bounded metrics-failure flag).
     */
    private bool $fallbackLogged = false;

    /**
     * Dotted `server_settings` key that stores a method's enable flag.
     *
     * @throws \InvalidArgumentException for a name outside {@see self::METHODS}.
     */
    public static function settingsKey(string $method): string
    {
        if (isset(self::OWN_KEYS[$method])) {
            /** @var string */
            return self::OWN_KEYS[$method];
        }

        if (in_array($method, [self::OIDC, self::LDAP, self::GITHUB], true)) {
            return AuthProviderBootstrapper::flagKey($method);
        }

        throw new \InvalidArgumentException("Unknown auth method: {$method}");
    }

    /**
     * Inverse of {@see self::settingsKey()}: which governed method (if any)
     * a written settings key belongs to.
     */
    public static function methodForSettingsKey(string $key): ?string
    {
        foreach (self::METHODS as $method) {
            if (self::settingsKey($method) === $key) {
                return $method;
            }
        }

        return null;
    }

    /**
     * The full current toggle state, keyed by method name.
     *
     * Includes the all-disabled fallback already applied (see class docblock),
     * so write guards validate against what the server actually ENFORCES
     * rather than against a raw table view.
     *
     * @return array<string, bool> All five {@see self::METHODS}, booleans.
     */
    public function currentState(): array
    {
        return $this->state();
    }

    /**
     * Is this sign-in method usable right now?
     *
     * @throws \InvalidArgumentException for a name outside {@see self::METHODS}.
     * @throws \Throwable when the settings store is unreadable — enforcement
     *         points MUST catch and deny (fail closed).
     */
    public function isEnabled(string $method): bool
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException("Unknown auth method: {$method}");
        }

        return $this->state()[$method];
    }

    /**
     * Reject a proposed toggle state that would lock the install out.
     *
     * @param array<string, bool> $proposed EXACTLY the five {@see self::METHODS}
     *        keys, each a strict boolean — the caller's write-set overlaid on
     *        {@see self::currentState()}. Anything else is a caller bug and
     *        throws immediately (parse, don't validate).
     *
     * @throws \InvalidArgumentException when the map is malformed.
     * @throws AuthMethodLockoutException when R1 or R2 would be violated.
     */
    public function assertSafeTransition(array $proposed): void
    {
        /** @var array<string, bool> $state */
        $state = $this->parseProposed($proposed);

        if (!in_array(true, $state, true)) {
            throw new AuthMethodLockoutException(AuthMethodLockoutException::REASON_ALL_METHODS_DISABLED);
        }

        $blocked = $this->adminsWithoutFactor($state);

        if ($blocked !== []) {
            throw new AuthMethodLockoutException(AuthMethodLockoutException::REASON_ADMIN_LOCKOUT, $blocked);
        }
    }

    /**
     * Parse the caller's proposed map into a trusted method=>bool state.
     *
     * @param array<array-key, mixed> $proposed
     *
     * @return array<string, bool>
     *
     * @throws \InvalidArgumentException on any missing key, extra key, or
     *         non-boolean value.
     */
    private function parseProposed(array $proposed): array
    {
        $keys = array_keys($proposed);

        if (count(array_diff(self::METHODS, $keys)) > 0 || count(array_diff($keys, self::METHODS)) > 0) {
            throw new \InvalidArgumentException(
                'Proposed auth state must contain exactly the five governed methods',
            );
        }

        $state = [];
        foreach (self::METHODS as $method) {
            if (!is_bool($proposed[$method])) {
                throw new \InvalidArgumentException("Proposed state for '{$method}' must be a boolean");
            }
            $state[$method] = $proposed[$method];
        }

        return $state;
    }

    /**
     * Active admins left with zero usable factors under a proposed state.
     *
     * @param array<string, bool> $state Parsed proposed state.
     *
     * @return list<string> User ids (for audit; the message carries only a count).
     */
    private function adminsWithoutFactor(array $state): array
    {
        $blocked = [];

        foreach ($this->users->findActiveAdminsForLockoutProbe() as $admin) {
            if ($this->hasUsableFactor($admin, $state)) {
                continue;
            }
            $blocked[] = $admin['id'];
        }

        return $blocked;
    }

    /**
     * Does this admin keep at least one door open under the proposed state?
     *
     * @param array{id: string, has_password: bool} $admin
     * @param array<string, bool>                    $state
     */
    private function hasUsableFactor(array $admin, array $state): bool
    {
        if ($state[self::PASSWORD] && $admin['has_password']) {
            return true;
        }

        if ($state[self::WEBAUTHN] && $this->webauthn->countForUser($admin['id']) > 0) {
            return true;
        }

        foreach ([self::OIDC, self::LDAP, self::GITHUB] as $provider) {
            if (!$state[$provider] || !$this->bootstrapper->isConfigured($provider)) {
                continue;
            }

            foreach ($this->identities->findByUserId($admin['id']) as $identity) {
                if (($identity['provider'] ?? null) === $provider) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Snapshot of the five enforced booleans, fallback applied.
     *
     * @return array<string, bool>
     */
    private function state(): array
    {
        if ($this->stateCache !== null) {
            return $this->stateCache;
        }

        $overrides = $this->settings->getAllOverrides();

        $state = [];
        foreach (self::METHODS as $method) {
            $key = self::settingsKey($method);
            $state[$method] = match (true) {
                // External flags: AuthProviderBootstrapper semantics verbatim —
                // stored-true is the ONLY on-switch, absent = OFF.
                in_array($method, [self::OIDC, self::LDAP, self::GITHUB], true)
                    => ($overrides[$key] ?? null) === true,
                // Own keys: a stored value decides; absent falls to the config
                // default, and a non-boolean default (missing/mangled config
                // file) fails toward the historical behaviour = enabled.
                array_key_exists($key, $overrides) => $overrides[$key] === true,
                default => $this->settings->getDefault($key) !== false,
            };
        }

        if (!in_array(true, $state, true)) {
            $state = $this->applyAllDisabledFallback($state);
        }

        $this->stateCache = $state;

        return $state;
    }

    /**
     * Emergency posture: persisted state says nobody can sign in. Deny
     * everything EXCEPT password sign-in until the operator fixes the flags.
     *
     * Rejected alternative — denying ALL logins outright: that turns a
     * hand-forced (or backup-restored) bad state into a total lockout the
     * admin can only escape at the database, which is strictly worse than the
     * failure the toggles exist to prevent, and it would brick OPDS/TV
     * integrations for every install one bad row away from it. Password stays
     * the last door BECAUSE it is the only method whose credential lives in
     * the users table itself — always present, never dependent on a provider
     * that might be misconfigured. The state is fixed by the API guards; this
     * read-path fallback exists solely for writes that bypassed them, and it
     * is never persisted, so the emergency keeps announcing itself.
     *
     * @param array<string, bool> $state
     *
     * @return array<string, bool>
     */
    private function applyAllDisabledFallback(array $state): array
    {
        $state[self::PASSWORD] = true;

        if (!$this->fallbackLogged && $this->logger !== null) {
            $this->fallbackLogged = true;
            $this->logger->warning(self::FALLBACK_EVENT, [
                'persisted_state' => 'all_methods_disabled',
                'enforced_state' => $state,
            ]);
        }

        return $state;
    }
}
