<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthMethodPolicy;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Server\Http\Controllers\Admin\AdminSettingsController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * F7 write-guard on the admin settings PUT.
 *
 * The five auth-method toggles are injected synthetically into the
 * controller's static schema caches (same sanctioned seam as
 * AdminSettingsControllerTest::withSyntheticFloatKey) because the vendored
 * phlix-shared pin still predates the keys — the schema-side test in
 * phlix-shared pins the real declarations, and the end-to-end PUT flips to
 * real keys at the re-vendor cascade with zero changes here.
 *
 * What is pinned: the guard fires only when the write-set touches a toggle,
 * a refused transition persists NOTHING (atomic batch), the 422 carries the
 * machine reason plus a per-key errors map, an unwired guard fails closed
 * (500), and an unreadable policy fails closed (500).
 */
final class AdminSettingsControllerAuthGuardTest extends TestCase
{
    private const TOGGLES = [
        'auth.password.enabled',
        'auth.webauthn.enabled',
        'auth.oidc.enabled',
        'auth.ldap.enabled',
        'auth.github.enabled',
    ];

    /** @var (callable(): void)|null */
    private $restore = null;

    protected function tearDown(): void
    {
        if (isset($this->restore)) {
            ($this->restore)();
        }
    }

    private function withToggleKeys(): void
    {
        $ref = new \ReflectionClass(AdminSettingsController::class);

        $props = [];
        foreach (['allowedKeys', 'schemaMeta', 'schemaValidators'] as $name) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $props[$name] = $prop;
        }

        $orig = [];
        foreach ($props as $name => $prop) {
            $orig[$name] = $prop->getValue();
        }

        // Force-populate the real caches FIRST (same order as the float seam)
        // so the synthetic set merges into the full live schema; snapshot the
        // pre-force values (possibly null) for teardown.
        $keys = AdminSettingsController::allowedKeys();
        $meta = AdminSettingsController::schemaMeta();
        $validators = $props['schemaValidators']->getValue();
        if (!is_array($validators)) {
            $vm = $ref->getMethod('schemaValidators');
            $vm->setAccessible(true);
            $validators = $vm->invoke(null);
        }

        $defaults = [
            'auth.password.enabled' => true,
            'auth.webauthn.enabled' => true,
            'auth.oidc.enabled' => false,
            'auth.ldap.enabled' => false,
            'auth.github.enabled' => false,
        ];

        foreach (self::TOGGLES as $key) {
            $keys[$key] = 'bool';
            // Full loadSchemaMeta() shape — a partial entry would 500 the meta
            // consumers just like the float seam's comment warns.
            $meta[$key] = [
                'label'      => 'Synthetic toggle (test only)',
                'helpText'   => 'Injected by withToggleKeys().',
                'helpLinks'  => null,
                'tier'       => 'standard',
                'group'      => 'auth',
                'enum'       => null,
                'enumLabels' => null,
                'optionHelp' => null,
                'minimum'    => null,
                'maximum'    => null,
                'default'    => $defaults[$key],
                'secret'     => false,
                'restart'    => false,
            ];
            $validators[$key] = (object) ['type' => 'boolean'];
        }

        $props['allowedKeys']->setValue(null, $keys);
        $props['schemaMeta']->setValue(null, $meta);
        $props['schemaValidators']->setValue(null, $validators);

        $this->restore = static function () use ($props, $orig): void {
            foreach ($props as $name => $prop) {
                $prop->setValue(null, $orig[$name]);
            }
        };
    }

    private function makeRequest(array $settings): Request
    {
        $request = new Request();
        $request->body = ['settings' => $settings];

        return $request;
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<array{id: string, has_password: bool}> $admins
     * @param array<string, list<string>> $identities
     * @param list<string> $passkeyUsers admin ids whose countForUser probe answers rows
     */
    private function policy(
        array $overrides,
        array $admins,
        array $identities = [],
        array $passkeyUsers = [],
    ): AuthMethodPolicy {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getAllOverrides')->willReturn($overrides);
        $settings->method('getDefault')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                'auth.password.enabled', 'auth.webauthn.enabled' => true,
                default => false,
            },
        );

        $users = $this->createMock(UserRepository::class);
        $users->method('findActiveAdminsForLockoutProbe')->willReturn($admins);

        $waDb = $this->createMock(Connection::class);
        // countForUser() binds the userId as a parameter, so the SQL text is
        // identical for every admin — attribute probes by call order, which
        // matches the policy's admin-probe order.
        $waProbeIndex = 0;
        $waDb->method('query')->willReturnCallback(
            static function (string $sql) use ($admins, $passkeyUsers, &$waProbeIndex): array {
                if (!str_contains($sql, 'webauthn_credentials')) {
                    return [];
                }
                $admin = $admins[$waProbeIndex] ?? null;
                $waProbeIndex++;
                if ($admin !== null && in_array($admin['id'], $passkeyUsers, true)) {
                    return [['c' => 1]];
                }

                return [];
            },
        );

        $identityRepo = $this->createMock(UserIdentityRepository::class);
        $identityRepo->method('findByUserId')->willReturnCallback(
            static fn (string $userId): array => array_map(
                static fn (string $provider): array => ['provider' => $provider],
                $identities[$userId] ?? [],
            ),
        );

        $bootstrapper = $this->createMock(AuthProviderBootstrapper::class);
        $bootstrapper->method('isConfigured')->willReturn(true);

        return new AuthMethodPolicy(
            $settings,
            $users,
            new WebAuthnCredentialRepository($waDb),
            $identityRepo,
            $bootstrapper,
        );
    }

    /**
     * @param array<string, mixed> $overrides Persisted flag rows (state BEFORE the PUT).
     * @return SettingsRepository&MockObject
     */
    private function settingsStore(array $overrides): SettingsRepository
    {
        $store = $this->createMock(SettingsRepository::class);
        $store->method('getAllOverrides')->willReturn($overrides);
        $store->method('getDefault')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                'auth.password.enabled', 'auth.webauthn.enabled' => true,
                default => false,
            },
        );
        $store->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        return $store;
    }

    public function test_all_off_batch_is_refused_and_persists_nothing(): void
    {
        $this->withToggleKeys();
        // Password + webauthn already stored OFF; the batch turns off the last
        // three providers → proposed state has zero enabled methods (R1).
        $store = $this->settingsStore([
            'auth.password.enabled' => false,
            'auth.webauthn.enabled' => false,
            'auth.oidc.enabled' => true,
            'auth.github.enabled' => true,
        ]);
        $store->expects($this->never())->method('set');
        $controller = new AdminSettingsController(
            $store,
            $this->policy(
                [
                    'auth.password.enabled' => false,
                    'auth.webauthn.enabled' => false,
                    'auth.oidc.enabled' => true,
                    'auth.github.enabled' => true,
                ],
                [['id' => 'admin-1', 'has_password' => true]],
            ),
        );

        $response = $controller->update($this->makeRequest([
            'auth.oidc.enabled' => false,
            'auth.ldap.enabled' => false,
            'auth.github.enabled' => false,
        ]), []);

        $this->assertSame(422, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('all_methods_disabled', $body['reason']);
        $this->assertSame(
            ['auth.oidc.enabled', 'auth.ldap.enabled', 'auth.github.enabled'],
            array_keys($body['errors']),
        );
    }

    public function test_password_off_with_zero_factor_admins_is_refused(): void
    {
        $this->withToggleKeys();
        $store = $this->settingsStore([]);
        $store->expects($this->never())->method('set');
        $controller = new AdminSettingsController(
            $store,
            // Admin has no password hash, no passkeys, no external identities.
            $this->policy([], [['id' => 'admin-1', 'has_password' => false]]),
        );

        $response = $controller->update($this->makeRequest(['auth.password.enabled' => false]), []);

        $this->assertSame(422, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('admin_lockout', $body['reason']);
    }

    public function test_password_off_with_passkey_enrolled_admin_persists(): void
    {
        $this->withToggleKeys();
        $store = $this->settingsStore([]);
        $store->expects($this->once())
            ->method('set')
            ->with('auth.password.enabled', false, 'bool');
        $controller = new AdminSettingsController(
            $store,
            $this->policy(
                [],
                [['id' => 'admin-1', 'has_password' => true]],
                [],
                ['admin-1'],
            ),
        );

        $response = $controller->update($this->makeRequest(['auth.password.enabled' => false]), []);

        $this->assertSame(200, $response->statusCode);
    }

    public function test_unrelated_write_leaves_the_guard_inert(): void
    {
        $this->withToggleKeys();
        $store = $this->settingsStore([]);
        $store->expects($this->once())
            ->method('set')
            ->with('auth.max_profiles', 5, 'int');
        // A policy whose store throws proves the guard never runs for a
        // write-set without toggles: an inert path cannot 500.
        $hostile = $this->createMock(SettingsRepository::class);
        $hostile->method('getAllOverrides')->willThrowException(new RuntimeException('must not be read'));
        $controller = new AdminSettingsController(
            $store,
            new AuthMethodPolicy(
                $hostile,
                $this->createMock(UserRepository::class),
                new WebAuthnCredentialRepository($this->createMock(Connection::class)),
                $this->createMock(UserIdentityRepository::class),
                $this->createMock(AuthProviderBootstrapper::class),
            ),
        );

        $response = $controller->update($this->makeRequest(['auth.max_profiles' => 5]), []);

        $this->assertSame(200, $response->statusCode);
    }

    public function test_toggle_write_without_wired_guard_fails_closed(): void
    {
        $this->withToggleKeys();
        $store = $this->settingsStore([]);
        $store->expects($this->never())->method('set');
        $controller = new AdminSettingsController($store);

        $response = $controller->update($this->makeRequest(['auth.webauthn.enabled' => false]), []);

        $this->assertSame(500, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Auth-method lock-out guard unavailable', $body['error']);
    }

    public function test_unreadable_policy_fails_closed_with_500(): void
    {
        $this->withToggleKeys();
        $store = $this->settingsStore([]);
        $store->expects($this->never())->method('set');

        // currentState() works (the settings store answers); the transition
        // probe itself blows up mid-audit — the guard must still fail closed.
        $users = $this->createMock(UserRepository::class);
        $users->method('findActiveAdminsForLockoutProbe')
            ->willThrowException(new RuntimeException('users table gone'));
        $broken = new AuthMethodPolicy(
            $this->settingsStorePolicyRepo(),
            $users,
            new WebAuthnCredentialRepository($this->createMock(Connection::class)),
            $this->createMock(UserIdentityRepository::class),
            $this->createMock(AuthProviderBootstrapper::class),
        );
        $controller = new AdminSettingsController($store, $broken);

        $response = $controller->update($this->makeRequest(['auth.webauthn.enabled' => false]), []);

        $this->assertSame(500, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Auth-method policy check failed', $body['error']);
    }

    private function settingsStorePolicyRepo(): SettingsRepository
    {
        $store = $this->createMock(SettingsRepository::class);
        $store->method('getAllOverrides')->willReturn([]);
        $store->method('getDefault')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                'auth.password.enabled', 'auth.webauthn.enabled' => true,
                default => false,
            },
        );

        return $store;
    }
}
