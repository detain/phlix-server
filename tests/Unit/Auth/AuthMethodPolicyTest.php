<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthMethodLockoutException;
use Phlix\Auth\AuthMethodPolicy;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F7 — the auth-method policy SSOT.
 *
 * Covers the absent-default matrix, the strict stored-value semantics (the
 * external three reproduce AuthProviderBootstrapper's absent=OFF rule), the
 * all-disabled fallback posture (password-only, loud, ONE-SHOT, never
 * persisted), the R1/R2/R2-bis transition rules, the proposed-map parsing,
 * the per-instance snapshot memo, and fail-fast on an unreadable store.
 */
final class AuthMethodPolicyTest extends TestCase
{
    private const PW = 'auth.password.enabled';
    private const WA = 'auth.webauthn.enabled';
    private const OIDC = 'auth.oidc.enabled';
    private const LDAP = 'auth.ldap.enabled';
    private const GH = 'auth.github.enabled';

    /**
     * @param array<string, mixed>              $overrides Raw server_settings rows.
     * @param list<array{id: string, has_password: bool}> $admins
     * @param array<string, int>                $passkeys   userId => credential count
     * @param array<string, list<array{provider: string}>> $identities
     * @param array<string, bool>               $configured Provider-configured map
     */
    private function policy(
        array $overrides = [],
        array $admins = [],
        array $passkeys = [],
        array $identities = [],
        array $configured = [],
        ?StructuredLogger $logger = null,
    ): AuthMethodPolicy {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getAllOverrides')->willReturn($overrides);
        // Mirror config/auth.php: password/webauthn ship true, externals false.
        $settings->method('getDefault')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                self::PW, self::WA => true,
                self::OIDC, self::LDAP, self::GH => false,
                default => null,
            },
        );

        $users = $this->createMock(UserRepository::class);
        $users->method('findActiveAdminsForLockoutProbe')->willReturn($admins);

        // WebAuthnCredentialRepository is final — the repo convention for its
        // tests is the REAL class over a mocked Workerman connection (the
        // exact createMock(Connection::class) + willReturn row-shape pattern
        // WebAuthnManagerTest uses).
        $waDb = $this->createMock(\Workerman\MySQL\Connection::class);
        $waDb->method('query')->willReturnCallback(
            /** @param list<mixed> $params */
            static function (string $sql, array $params = []) use ($passkeys): array {
                if (str_contains($sql, 'webauthn_credentials')) {
                    return [['c' => $passkeys[(string) ($params[0] ?? '')] ?? 0]];
                }
                return [];
            },
        );
        $webauthn = new WebAuthnCredentialRepository($waDb);

        $identitiesRepo = $this->createMock(UserIdentityRepository::class);
        $identitiesRepo->method('findByUserId')->willReturnCallback(
            static fn (string $id): array => $identities[$id] ?? [],
        );

        $bootstrapper = $this->createMock(AuthProviderBootstrapper::class);
        $bootstrapper->method('isConfigured')->willReturnCallback(
            static fn (string $provider): bool => $configured[$provider] ?? false,
        );

        return new AuthMethodPolicy(
            $settings,
            $users,
            $webauthn,
            $identitiesRepo,
            $bootstrapper,
            $logger,
        );
    }

    /**
     * @param array<string, bool> $overrides
     *
     * @return array<string, bool>
     */
    private function proposed(array $overrides = []): array
    {
        return array_merge([
            'password' => true,
            'webauthn' => true,
            'oidc' => false,
            'ldap' => false,
            'github' => false,
        ], $overrides);
    }

    // ── absent-default matrix ────────────────────────────────────────

    public function test_absent_flags_answer_password_and_webauthn_true_externals_false(): void
    {
        $policy = $this->policy();

        $this->assertTrue($policy->isEnabled('password'));
        $this->assertTrue($policy->isEnabled('webauthn'));
        $this->assertFalse($policy->isEnabled('oidc'));
        $this->assertFalse($policy->isEnabled('ldap'));
        $this->assertFalse($policy->isEnabled('github'));
    }

    public function test_stored_values_decide_strictly_when_present(): void
    {
        $policy = $this->policy([
            self::PW => false,
            self::WA => false,
            self::OIDC => true,
            self::LDAP => true,
            self::GH => true,
        ]);

        // NOTE the fallback must NOT trigger here (three methods are on).
        $this->assertFalse($policy->isEnabled('password'));
        $this->assertFalse($policy->isEnabled('webauthn'));
        $this->assertTrue($policy->isEnabled('oidc'));
        $this->assertTrue($policy->isEnabled('ldap'));
        $this->assertTrue($policy->isEnabled('github'));
    }

    public function test_external_flags_reproduce_bootstrapper_strict_true_semantics(): void
    {
        // A non-boolean stored value is NOT enabled for the externals — the
        // exact `=== true` rule of AuthProviderBootstrapper::isEnabled().
        $policy = $this->policy([self::OIDC => 'yes', self::LDAP => 1]);

        $this->assertFalse($policy->isEnabled('oidc'));
        $this->assertFalse($policy->isEnabled('ldap'));
    }

    public function test_own_keys_honour_config_default_when_override_absent(): void
    {
        // getDefault returning false (operator edited config/auth.php) means
        // the absent key answers OFF — the config file remains the default layer.
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getAllOverrides')->willReturn([]);
        $settings->method('getDefault')->willReturnCallback(
            static fn (string $key): mixed => $key === self::PW ? false : true,
        );
        $policy = new AuthMethodPolicy(
            $settings,
            $this->createMock(UserRepository::class),
            new WebAuthnCredentialRepository($this->emptyWaDb()),
            $this->createMock(UserIdentityRepository::class),
            $this->createMock(AuthProviderBootstrapper::class),
        );

        $this->assertFalse($policy->isEnabled('password'));
        $this->assertTrue($policy->isEnabled('webauthn'));
    }

    // ── keys mapping ─────────────────────────────────────────────────

    public function test_settings_keys_match_the_live_bootstrapper_family(): void
    {
        $this->assertSame(self::PW, AuthMethodPolicy::settingsKey('password'));
        $this->assertSame(self::WA, AuthMethodPolicy::settingsKey('webauthn'));
        // Externals reuse the bootstrapper's canonical key builder — one row,
        // two surfaces, zero divergence.
        $this->assertSame(
            AuthProviderBootstrapper::flagKey('oidc'),
            AuthMethodPolicy::settingsKey('oidc'),
        );
        $this->assertSame('github', AuthMethodPolicy::methodForSettingsKey(self::GH));
        $this->assertNull(AuthMethodPolicy::methodForSettingsKey('auth.signup_mode'));
    }

    public function test_unknown_method_fails_fast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy()->isEnabled('carrier-pigeon');
    }

    // ── all-disabled fallback posture ────────────────────────────────

    public function test_persisted_all_off_falls_back_to_password_only_and_logs_once(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(AuthMethodPolicy::FALLBACK_EVENT, $this->callback(
                static fn (array $ctx): bool => $ctx['persisted_state'] === 'all_methods_disabled'
                    && $ctx['enforced_state']['password'] === true
                    && $ctx['enforced_state']['github'] === false,
            ));

        $policy = $this->policy([
            self::PW => false,
            self::WA => false,
            self::OIDC => false,
            self::LDAP => false,
            self::GH => false,
        ], logger: $logger);

        // Read the whole set repeatedly: the alarm sounds once per instance…
        $this->assertTrue($policy->isEnabled('password'));
        $this->assertFalse($policy->isEnabled('webauthn'));
        $this->assertFalse($policy->isEnabled('oidc'));
        $this->assertFalse($policy->isEnabled('ldap'));
        $this->assertFalse($policy->isEnabled('github'));
        $this->assertTrue($policy->currentState()['password']);
    }

    public function test_fallback_is_never_persisted(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getAllOverrides')->willReturn([
            self::PW => false, self::WA => false, self::OIDC => false,
            self::LDAP => false, self::GH => false,
        ]);
        $settings->expects($this->never())->method('set');

        $policy = new AuthMethodPolicy(
            $settings,
            $this->createMock(UserRepository::class),
            new WebAuthnCredentialRepository($this->emptyWaDb()),
            $this->createMock(UserIdentityRepository::class),
            $this->createMock(AuthProviderBootstrapper::class),
        );

        $this->assertTrue($policy->isEnabled('password'));
    }

    // ── snapshot memo + fail-fast ────────────────────────────────────

    public function test_one_snapshot_per_instance_across_queries(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->expects($this->once())
            ->method('getAllOverrides')
            ->willReturn([self::PW => false]);

        $policy = new AuthMethodPolicy(
            $settings,
            $this->createMock(UserRepository::class),
            new WebAuthnCredentialRepository($this->emptyWaDb()),
            $this->createMock(UserIdentityRepository::class),
            $this->createMock(AuthProviderBootstrapper::class),
        );

        $this->assertFalse($policy->isEnabled('password'));
        $this->assertTrue($policy->isEnabled('webauthn'));
        $this->assertCount(5, $policy->currentState());
    }

    public function test_unreadable_store_throws_to_the_caller_which_must_deny(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getAllOverrides')->willThrowException(new RuntimeException('db down'));
        $policy = new AuthMethodPolicy(
            $settings,
            $this->createMock(UserRepository::class),
            new WebAuthnCredentialRepository($this->emptyWaDb()),
            $this->createMock(UserIdentityRepository::class),
            $this->createMock(AuthProviderBootstrapper::class),
        );

        $this->expectException(RuntimeException::class);
        $policy->isEnabled('password');
    }

    // ── R1: never all-off ────────────────────────────────────────────

    public function test_r1_all_off_transition_is_rejected(): void
    {
        $policy = $this->policy();

        try {
            $policy->assertSafeTransition($this->proposed([
                'password' => false, 'webauthn' => false,
            ]));
            $this->fail('R1 violation must throw');
        } catch (AuthMethodLockoutException $e) {
            $this->assertSame(AuthMethodLockoutException::REASON_ALL_METHODS_DISABLED, $e->reason());
            $this->assertSame([], $e->blockedAdminIds());
        }
    }

    // ── R2: never lock out an active admin ───────────────────────────

    public function test_r2_disabling_password_strands_password_only_admin(): void
    {
        $policy = $this->policy(admins: [['id' => 'admin-1', 'has_password' => true]]);

        $this->expectException(AuthMethodLockoutException::class);
        $policy->assertSafeTransition($this->proposed(['password' => false]));
    }

    public function test_r2_reason_and_blocked_ids_survive_on_the_exception(): void
    {
        $policy = $this->policy(admins: [
            ['id' => 'admin-1', 'has_password' => true],
            ['id' => 'admin-2', 'has_password' => true],
        ]);

        try {
            $policy->assertSafeTransition($this->proposed(['password' => false]));
            $this->fail('expected lockout');
        } catch (AuthMethodLockoutException $e) {
            $this->assertSame(AuthMethodLockoutException::REASON_ADMIN_LOCKOUT, $e->reason());
            $this->assertSame(['admin-1', 'admin-2'], $e->blockedAdminIds());
            // Message carries the COUNT, not ids — ids stay in the audit lane.
            $this->assertStringContainsString('2 active administrator(s)', $e->getMessage());
            $this->assertStringNotContainsString('admin-1', $e->getMessage());
        }
    }

    public function test_r2_passes_when_the_admin_holds_a_passkey(): void
    {
        $policy = $this->policy(
            admins: [['id' => 'admin-1', 'has_password' => true]],
            passkeys: ['admin-1' => 1],
        );

        $policy->assertSafeTransition($this->proposed(['password' => false]));
        $this->addToAssertionCount(1); // returning here proves the transition is safe
    }

    public function test_passkey_factor_only_counts_while_webauthn_stays_on(): void
    {
        // Admin-1's ONLY factor is a passkey and the transition turns off BOTH
        // password and webauthn, leaving oidc enabled but UNCONFIGURED (never
        // a usable factor) — still a lockout.
        $policy = $this->policy(
            admins: [['id' => 'admin-1', 'has_password' => false]],
            passkeys: ['admin-1' => 2],
            identities: ['admin-1' => [['provider' => 'oidc']]],
            configured: [],
        );

        $this->expectException(AuthMethodLockoutException::class);
        $policy->assertSafeTransition($this->proposed([
            'password' => false, 'webauthn' => false, 'oidc' => true,
        ]));
    }

    public function test_provider_identity_counts_when_provider_stays_on_and_configured(): void
    {
        // The admin's ONLY remaining door is the GitHub identity, and the
        // transition turns off password + webauthn while KEEPING github on and
        // configured — so it is safe, and the probe must say so.
        $policy = $this->policy(
            admins: [['id' => 'admin-1', 'has_password' => false]],
            identities: ['admin-1' => [['provider' => 'github']]],
            configured: ['github' => true],
        );

        $policy->assertSafeTransition($this->proposed([
            'password' => false,
            'webauthn' => false,
            'github' => true,
        ]));
        $this->addToAssertionCount(1); // returning here proves the transition is safe
    }

    public function test_identity_for_provider_disabled_by_the_transition_no_longer_counts(): void
    {
        $policy = $this->policy(
            admins: [['id' => 'admin-1', 'has_password' => false]],
            identities: ['admin-1' => [['provider' => 'github']]],
            configured: ['github' => true],
        );

        $this->expectException(AuthMethodLockoutException::class);
        $policy->assertSafeTransition($this->proposed([
            'password' => false, 'github' => false, 'webauthn' => false,
        ]));
    }

    public function test_r2_bis_disabling_a_provider_strands_its_only_admin(): void
    {
        // The reverse direction is the SAME code path: an admin whose only
        // factor was LDAP (password already off) cannot have LDAP disabled.
        $policy = $this->policy(
            admins: [['id' => 'admin-1', 'has_password' => false]],
            configured: ['ldap' => true],
            identities: ['admin-1' => [['provider' => 'ldap']]],
        );

        $this->expectException(AuthMethodLockoutException::class);
        $policy->assertSafeTransition($this->proposed([
            'password' => false, 'webauthn' => false, 'ldap' => false,
        ]));
    }

    public function test_zero_admins_passes_any_not_all_off_transition(): void
    {
        $policy = $this->policy();

        $policy->assertSafeTransition($this->proposed(['password' => false]));
        $this->addToAssertionCount(1); // returning here proves the transition is safe
    }

    public function test_foreign_identity_rows_do_not_count_as_factors(): void
    {
        // The user HAS identity rows, but for a provider the transition keeps
        // OFF — and provider rows of an ON provider must match by name.
        $policy = $this->policy(
            admins: [['id' => 'admin-1', 'has_password' => false]],
            identities: ['admin-1' => [['provider' => 'saml']]],
            configured: ['ldap' => true],
        );

        $this->expectException(AuthMethodLockoutException::class);
        $policy->assertSafeTransition($this->proposed([
            'password' => false, 'webauthn' => false, 'ldap' => true,
        ]));
    }

    // ── proposed-map parsing ─────────────────────────────────────────

    public function test_malformed_proposed_maps_fail_fast_as_programmer_errors(): void
    {
        $policy = $this->policy();

        $missing = $this->proposed();
        unset($missing['github']);
        $extra = $this->proposed(['carrier_pigeon' => true]);

        foreach (
            [
            'missing key' => $missing,
            'extra key' => $extra,
            // array_merge (not proposed()) so the deliberately string-valued
            // key never crosses the typed helper's boundary.
            'non-bool value' => array_merge($this->proposed(), ['password' => 'true']),
            'empty map' => [],
            ] as $label => $map
        ) {
            try {
                /** @psalm-suppress InvalidArgument the union map is deliberately malformed — rejecting it IS the test */
                $policy->assertSafeTransition($map);
                $this->fail("{$label} must throw");
            } catch (\InvalidArgumentException $e) {
                // Parse-boundary rejection, not a policy verdict: the
                // lockout messages all start with their machine prefix.
                $this->assertStringNotContainsString('auth.method_lockout:', $e->getMessage());
            }
        }
    }

    /** Connection double whose every SELECT answers "no rows" — zero passkeys. */
    private function emptyWaDb(): \Workerman\MySQL\Connection
    {
        $db = $this->createMock(\Workerman\MySQL\Connection::class);
        $db->method('query')->willReturn([]);
        return $db;
    }
}
