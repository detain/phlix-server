<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthMethodPolicy;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Server\Http\Controllers\AuthProviderController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * F7 locks the provider-disable route with the same lock-out policy the admin
 * settings PUT uses (R2-bis: a provider that is an active admin's only usable
 * factor cannot be switched off; R1: this route can never empty the last
 * enabled method). A refusal persists NOTHING — bootstrapper->disable() must
 * not be reached — and answers 422 with the machine reason.
 *
 * The policy under test is REAL over mocked stores so the transition logic,
 * not a stub, decides the outcome.
 */
final class AuthProviderControllerDisableGuardTest extends TestCase
{
    /** @var AuthProviderRegistry&MockObject */
    private AuthProviderRegistry $registry;
    /** @var AuthProviderBootstrapper&MockObject */
    private AuthProviderBootstrapper $bootstrapper;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(AuthProviderRegistry::class);
        $this->bootstrapper = $this->createMock(AuthProviderBootstrapper::class);
        $this->bootstrapper->method('isToggleable')
            ->willReturnCallback(static fn (string $name): bool => in_array($name, ['oidc', 'ldap', 'github'], true));
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<array{id: string, has_password: bool}> $admins
     * @param array<string, list<string>> $identities userId => provider list
     * @param list<string> $configured
     */
    private function policy(
        array $overrides,
        array $admins,
        array $identities = [],
        array $configured = ['github', 'oidc', 'ldap'],
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
        $waDb->method('query')->willReturn([]);

        $identitiesRepo = $this->createMock(UserIdentityRepository::class);
        $identitiesRepo->method('findByUserId')->willReturnCallback(
            static fn (string $userId): array => array_map(
                static fn (string $provider): array => ['provider' => $provider],
                $identities[$userId] ?? [],
            ),
        );

        $this->bootstrapper->method('isConfigured')
            ->willReturnCallback(static fn (string $name): bool => in_array($name, $configured, true));

        return new AuthMethodPolicy(
            $settings,
            $users,
            new WebAuthnCredentialRepository($waDb),
            $identitiesRepo,
            $this->bootstrapper,
        );
    }

    private function controller(?AuthMethodPolicy $policy): AuthProviderController
    {
        return new AuthProviderController($this->registry, $this->bootstrapper, $policy);
    }

    public function test_disabling_a_providers_last_admin_factor_is_refused_and_never_persisted(): void
    {
        $policy = $this->policy(
            ['auth.github.enabled' => true],
            [['id' => 'admin-1', 'has_password' => false]],
            ['admin-1' => ['github']],
        );
        $this->bootstrapper->expects($this->never())->method('disable');

        $response = $this->controller($policy)
            ->disableProvider($this->createMock(Request::class), ['name' => 'github']);

        $this->assertSame(422, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('admin_lockout', $body['reason']);
        $this->assertArrayHasKey('auth.github.enabled', $body['errors']);
    }

    public function test_disabling_the_last_enabled_method_hits_the_all_off_rule(): void
    {
        // Password + webauthn explicitly OFF, github the only live method.
        $policy = $this->policy(
            [
                'auth.password.enabled' => false,
                'auth.webauthn.enabled' => false,
                'auth.github.enabled' => true,
            ],
            [['id' => 'admin-1', 'has_password' => true]],
        );
        $this->bootstrapper->expects($this->never())->method('disable');

        $response = $this->controller($policy)
            ->disableProvider($this->createMock(Request::class), ['name' => 'github']);

        $this->assertSame(422, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('all_methods_disabled', $body['reason']);
    }

    public function test_safe_disable_persists_through_the_bootstrapper(): void
    {
        $policy = $this->policy(
            ['auth.github.enabled' => true],
            [['id' => 'admin-1', 'has_password' => true]],
        );
        $this->bootstrapper->expects($this->once())->method('disable')->with('github');

        $response = $this->controller($policy)
            ->disableProvider($this->createMock(Request::class), ['name' => 'github']);

        $this->assertSame(200, $response->statusCode);
    }

    public function test_unknown_provider_404_precedes_the_guard(): void
    {
        $policy = $this->policy([], [['id' => 'admin-1', 'has_password' => false]]);
        $this->bootstrapper->expects($this->never())->method('disable');

        $response = $this->controller($policy)
            ->disableProvider($this->createMock(Request::class), ['name' => 'password']);

        $this->assertSame(404, $response->statusCode);
    }

    public function test_legacy_controller_without_policy_keeps_the_unguarded_route(): void
    {
        $this->bootstrapper->expects($this->once())->method('disable')->with('ldap');

        $response = $this->controller(null)
            ->disableProvider($this->createMock(Request::class), ['name' => 'ldap']);

        $this->assertSame(200, $response->statusCode);
    }

    public function test_unreadable_policy_fails_closed_with_500(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getAllOverrides')
            ->willThrowException(new RuntimeException('store down'));
        $policy = new AuthMethodPolicy(
            $settings,
            $this->createMock(UserRepository::class),
            new WebAuthnCredentialRepository($this->createMock(Connection::class)),
            $this->createMock(UserIdentityRepository::class),
            $this->bootstrapper,
        );
        $this->bootstrapper->expects($this->never())->method('disable');

        $response = $this->controller($policy)
            ->disableProvider($this->createMock(Request::class), ['name' => 'github']);

        $this->assertSame(500, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Auth-method policy check failed', $body['error']);
    }
}
