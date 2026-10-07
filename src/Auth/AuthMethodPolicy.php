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
use Throwable;

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
 *     anyone out? Consulted by BOTH write surfaces BEFORE anything is
 *     persisted, and since the provider-parity close BOTH under the SAME
 *     serialization protocol: {@see self::guardAndPersistThrough()} runs
 *     BEGIN → `SELECT ... FOR UPDATE` row locks on the five toggle keys (see
 *     {@see \Phlix\Admin\SettingsRepository::lockSettingRows()}) → guard
 *     re-read against the locked latest-committed view (never a race-prone
 *     pre-write snapshot) → persist → COMMIT, rejecting with ROLLBACK and
 *     zero persistence. The admin settings API
 *     ({@see \Phlix\Server\Http\Controllers\Admin\AdminSettingsController::updateAuthGuarded()})
 *     and the provider integrations API
 *     ({@see \Phlix\Server\Http\Controllers\AuthProviderController::disableProvider()})
 *     are the two protocol callers. Deadlock symmetry / universal overlap:
 *     every guarded writer locks the IDENTICAL five-key set in the IDENTICAL
 *     canonical order, so any two of them fully serialize — a partial-overlap
 *     deadlock pair cannot form — and the B2 review's provider×admin
 *     interleave residual is closed rather than mitigated. Writes that bypass
 *     the API entirely (raw SQL, restores) remain outside any lock; the
 *     read-path password fallback below stays their last-resort backstop.
 *
 * ## Read path
 *
 * Class (a) LIVE, exactly like {@see PasswordPolicy}: every answer comes from
 * a fresh settings read, so an override applies on the very next decision —
 * no restart, no worker recycle. There is deliberately NO cross-decision
 * cache of any kind (resident-process law): PHP-DI MEMOIZES built instances
 * and a Workerman worker keeps one container for its whole life, so a
 * container-held policy lives as long as the worker — the same in-repo
 * admission behind {@see AuthManager} accumulating its in-memory rate-limit
 * entries "on a busy, long-running resident worker" and behind
 * {@see AuthProviderBootstrapper::ensureProviderRegistered()}'s request-path
 * self-heal. A per-instance snapshot would therefore be a process cache in a
 * request-scoped costume: it froze the toggle answers at the first read, so
 * live disables arrived late (writes-guarded base: never) on warm workers.
 * Instead every public decision method — {@see self::isEnabled()} and
 * {@see self::currentState()} (which is the write guards' live base) — takes
 * EXACTLY ONE {@see SettingsRepository::getAllOverrides()} snapshot per call:
 * fresh ACROSS decisions, single-SELECT WITHIN one. The added cost is one
 * settings SELECT per login attempt — the same request already pays a user
 * SELECT, an Argon2ID verify, and the bootstrapper's per-call
 * {@see SettingsRepository::getOverride()} in
 * {@see AuthProviderBootstrapper::isEnabled()} — and one per admin toggle
 * write: measured noise against the I/O surrounding it, and simplicity wins
 * over inventing request-lifecycle machinery the container does not offer.
 *
 * A snapshot whose five answers are ALL false is a state the write guards
 * make API-unreachable; if it is ever forced into the table by hand (direct
 * SQL, restored backup) the read path falls back to password-only sign-in
 * and logs {@see self::FALLBACK_EVENT} loudly — once per instance (the
 * bounded-alarm precedent), which in production means once per worker life.
 * The fallback is NEVER persisted — the emergency stays visible to the
 * operator instead of silently rewriting their data.
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
 * ## Accepted residual (owner-reviewed at the F7 rework)
 *
 * Turning `password` OFF while `auth.signup_mode` stays open does NOT stop
 * fresh accounts from being minted and signed in: registration is governed by
 * signup_mode ({@see AuthManager::register()} reads it), never by the method
 * toggles, and the R2 probe protects only EXISTING active admins. Password-off
 * + open signup therefore still yields a working new account (the account's
 * own first sign-in issues tokens without consulting the login gate). That is
 * the accepted boundary of this control: the quintet is a sign-in-FACTOR
 * switch, not an account-creation switch — operators who want no new
 * password-backed accounts close signup, and the toggles cannot substitute for
 * that.
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
     * One-shot fallback alarm per instance (precedent: WebAuthnManager's
     * bounded metrics-failure flag). NOTE: in production an instance lives as
     * long as its worker (PHP-DI memoizes; see class docblock "Read path"),
     * so this alarm is deliberately bounded at once per worker life while the
     * password-only fallback posture keeps enforcing on every decision.
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
     * Takes ONE fresh settings snapshot per call — this value is the write
     * guards' base, and a stale base is a live R2 bypass (class docblock,
     * "Read path").
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
     * Takes ONE fresh settings snapshot per call (bootstrapper isEnabled()
     * precedent) — enforcement on a resident worker must never answer from a
     * pre-disable view of the table.
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
     * THE B2 serialization protocol — one dialect, both guarded write surfaces.
     *
     * Order of operations, every statement on the SAME connection (the one
     * `$settings` writes through — a lock taken on a second connection would
     * be theater):
     *   1. BEGIN
     *   2. `SELECT ... FOR UPDATE` over the five canonical toggle keys in
     *      canonical order ({@see self::lockKeys()} — THE lock target; both
     *      callers inherit it from here, so the universal-overlap /
     *      deadlock-symmetry property cannot drift per surface).
     *   3. Locked re-read: `currentState() + $diff` → {@see self::assertSafeTransition()}.
     *      The read happens AFTER the lock, so the guard certifies the write
     *      against the latest-committed state the competitors can no longer
     *      change under us — this is the exact step the pre-B2 read-probe-write
     *      got wrong.
     *   4. Rejection (or an unwired guard, or a blown probe) → ROLLBACK, then
     *      the typed throw — nothing was persisted, no half-state exists.
     *   5. Accept → run `$persist()` INSIDE the open transaction, COMMIT, and
     *      return its result verbatim (surfaces typically read their response
     *      payload inside the callback, before COMMIT, so it reflects the
     *      locked state).
     *
     * Failure classification is deliberate and byte-pinned by the surfaces'
     * envelopes: guard-window blow-ups arrive as
     * {@see AuthMethodGuardCheckFailedException} (original message preserved,
     * previous chained) so both controllers keep answering their 500
     * 'Auth-method policy check failed'; BEGIN/lock/persist/COMMIT failures
     * re-propagate UNWRAPPED after ROLLBACK (the admin PUT's outer catch
     * keeps its 'Failed to update settings' path; the provider route's
     * dispatcher 500 for a bootstrapper failure stays a dispatcher 500).
     * The ROLLBACK itself mirrors the house idiom (a secondary rollback
     * failure after a dead connection throws past us — accepted, documented,
     * same as {@see \Phlix\Collections\CollectionItemRepository::applyMemberDiff()}).
     *
     * Same-connection precondition: in production the container memoizes one
     * {@see SettingsRepository} (PHP-DI singleton), so the policy's reads, the
     * protocol's locks, and each surface's persist callback all traverse one
     * connection. That identity is pinned by
     * `tests/Unit/Auth/AuthMethodPolicyWiringGuardTest` — a future factory-
     * scoped split would silently unserialize the protocol and MUST redden
     * that guard first.
     *
     * @template T
     *
     * @param self|null               $policy   The guard; NULL only reaches the
     *        fail-closed {@see AuthMethodGuardUnwiredException} arm after the
     *        lock (never an unguarded persist).
     * @param SettingsRepository      $settings The store the transaction — and
     *        therefore the locks — runs on. Must be the same connection
     *        `$persist` writes through (see precondition above).
     * @param array<string, bool>     $diff     Methods this write-set changes,
     *        method => proposed value; overlaid on the LOCKED currentState().
     * @param callable(): T           $persist  Runs only after the guard
     *        passed, inside the still-open transaction.
     *
     * @return T Whatever `$persist` returned.
     *
     * @throws AuthMethodLockoutException R1/R2 rejected — rolled back, nothing persisted.
     * @throws AuthMethodGuardUnwiredException no policy instance — rolled back.
     * @throws AuthMethodGuardCheckFailedException the guard probe blew up mid-check — rolled back.
     * @throws Throwable any BEGIN/lock/persist/COMMIT failure — rolled back, re-raised unwrapped.
     */
    public static function guardAndPersistThrough(
        ?self $policy,
        SettingsRepository $settings,
        array $diff,
        callable $persist,
    ): mixed {
        $settings->beginTransaction();

        try {
            $settings->lockSettingRows(self::lockKeys());

            if ($policy === null) {
                // Fail closed: an unwired guard must never degrade into
                // unguarded toggle writes. The container keeps this arm
                // unreachable (wiring guard); it exists for the nullable
                // constructor default, and it still serializes + rolls its
                // (empty) transaction back so the statement law the surfaces
                // pinned (BEGIN → LOCK → ROLLBACK) is unchanged.
                throw new AuthMethodGuardUnwiredException(
                    'The server cannot validate auth-method toggles without the auth policy service.',
                );
            }

            try {
                // One locked re-read per call (class docblock, "Read path"):
                // currentState() IS the base the proposal overlays, and under
                // the open FOR UPDATE locks it is the latest-committed view.
                $policy->assertSafeTransition(array_merge($policy->currentState(), $diff));
            } catch (AuthMethodLockoutException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new AuthMethodGuardCheckFailedException($e->getMessage(), 0, $e);
            }

            $result = $persist();
            $settings->commitTransaction();

            return $result;
        } catch (Throwable $e) {
            $settings->rollbackTransaction();

            throw $e;
        }
    }

    /**
     * Instance form of {@see self::guardAndPersistThrough()} for surfaces that
     * hold the policy but write through the SAME store this policy reads
     * (production: the container-memoized singleton — precondition documented
     * there).
     *
     * @template T
     *
     * @param array<string, bool> $diff
     * @param callable(): T       $persist
     *
     * @return T
     */
    public function guardAndPersist(array $diff, callable $persist): mixed
    {
        return self::guardAndPersistThrough($this, $this->settings, $diff, $persist);
    }

    /**
     * The five `auth.<method>.enabled` keys in canonical lock order — THE
     * single lock target every protocol writer serializes on (universal
     * overlap; see {@see self::guardAndPersistThrough()}).
     *
     * @return list<string>
     */
    private static function lockKeys(): array
    {
        return array_map([self::class, 'settingsKey'], self::METHODS);
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
     * One fresh snapshot of the five enforced booleans, fallback applied.
     *
     * Every public decision method calls this EXACTLY once per invocation:
     * fresh across decisions (no stale answers on a resident worker),
     * single-SELECT within one. Do NOT re-add a per-instance memo here — the
     * container memoizes THIS object for the worker's lifetime, which is the
     * CRITICAL the F7 rework fixed; the liveness pins in
     * tests/Unit/Auth/AuthMethodPolicyTest.php reddens on a re-plant.
     *
     * @return array<string, bool>
     */
    private function state(): array
    {
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
