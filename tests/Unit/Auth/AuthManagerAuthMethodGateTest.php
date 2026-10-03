<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthManager;
use Phlix\Auth\AuthMethodDisabledException;
use Phlix\Auth\AuthMethodPolicy;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * F7 password-gate enforcement in AuthManager: with `auth.password.enabled`
 * stored false, login() must refuse BEFORE any identifier lookup or credential
 * compare, yet keep the L-1 timing envelope (a full burned Argon2id verify),
 * still charge the rate budget, audit with its own reason, and throw the
 * 401-mapped AuthMethodDisabledException. verifyCredentials() (the second
 * password consumer — OPDS HTTP-Basic) gets the silent-null equivalent.
 *
 * AuthMethodPolicy is final, so — exactly like WebAuthnManagerTest doubles
 * the final credential repository — these tests use a REAL policy over mocked
 * collaborators: the settings rows below are the whole truth the gate sees.
 */
final class AuthManagerAuthMethodGateTest extends TestCase
{
    /** @var list<string> */
    private array $mintedLogPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $ref = new ReflectionClass(AuthManager::class);
        $prop = $ref->getProperty('rateLimitStore');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        foreach ($this->mintedLogPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->mintedLogPaths = [];
        parent::tearDown();
    }

    private function silentLogger(): StructuredLogger
    {
        $path = sys_get_temp_dir() . '/phlix_authmethodgate_' . uniqid('', true) . '.log';
        $this->mintedLogPaths[] = $path;

        return new StructuredLogger('test', [
            'handlers' => ['stream' => ['type' => 'stream', 'path' => $path, 'level' => 'debug']],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides server_settings rows for the real policy.
     */
    private function policy(array $overrides): AuthMethodPolicy
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getAllOverrides')->willReturn($overrides);
        $settings->method('getDefault')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                'auth.password.enabled', 'auth.webauthn.enabled' => true,
                default => false,
            },
        );

        $waDb = $this->createMock(Connection::class);
        $waDb->method('query')->willReturn([]);

        return new AuthMethodPolicy(
            $settings,
            $this->createMock(UserRepository::class),
            new WebAuthnCredentialRepository($waDb),
            $this->createMock(UserIdentityRepository::class),
            $this->createMock(AuthProviderBootstrapper::class),
        );
    }

    private function manager(UserRepository $repo, ?AuthMethodPolicy $policy): AuthManager
    {
        return new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            authPolicy: $policy,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function userRow(): array
    {
        return [
            'id' => 'user-9',
            'username' => 'nina',
            'email' => 'nina@example.com',
            'status' => 'active',
            'password_hash' => 'xxx',
        ];
    }

    // ── login() ──────────────────────────────────────────────────────

    public function test_login_succeeds_unchanged_when_password_enabled(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->with('nina')->willReturn($this->userRow());
        $repo->method('verifyPassword')->willReturn(true);
        $repo->method('findById')->willReturn($this->userRow());

        $result = $this->manager($repo, $this->policy([]))->login('nina', 'topsecret123', 'device-1');

        $this->assertArrayHasKey('access_token', $result);
    }

    public function test_login_with_null_policy_keeps_pre_f7_behaviour(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->with('nina')->willReturn($this->userRow());
        $repo->method('verifyPassword')->willReturn(true);
        $repo->method('findById')->willReturn($this->userRow());

        $result = $this->manager($repo, null)->login('nina', 'topsecret123', 'device-1');

        $this->assertArrayHasKey('access_token', $result);
    }

    public function test_disabled_password_login_is_refused_before_any_credential_contact(): void
    {
        $repo = $this->createMock(UserRepository::class);
        // The gate precedes identifier lookups entirely.
        $repo->expects($this->never())->method('findByUsername');
        $repo->expects($this->never())->method('findByEmail');
        $repo->expects($this->never())->method('verifyPassword');
        // L-1 timing envelope: a full verify is still burned.
        $repo->expects($this->once())->method('burnPasswordVerifyTime')->with('topsecret123');

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->once())
            ->method('logFailedAuth')
            ->with('auth_method_disabled', $this->callback(
                static fn (array $ctx): bool => ($ctx['username'] ?? null) === 'nina'
                    && ($ctx['device_id'] ?? null) === 'device-1',
            ));

        $manager = new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $audit,
            $this->silentLogger(),
            authPolicy: $this->policy(['auth.password.enabled' => false]),
        );

        $this->expectException(AuthMethodDisabledException::class);
        $this->expectExceptionMessage('auth.method_disabled');
        $manager->login('nina', 'topsecret123', 'device-1');
    }

    public function test_disabled_password_login_still_charges_the_rate_limit_budget(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $manager = $this->manager($repo, $this->policy(['auth.password.enabled' => false]));

        // 5 refused attempts must exhaust the same 5/60 budget the invalid-
        // credential path charges — probing a disabled surface is not free.
        for ($i = 0; $i < 5; $i++) {
            try {
                $manager->login('nina', 'whatever123', 'device-1');
            } catch (AuthMethodDisabledException) {
                // expected
            }
        }

        $this->expectException(\Phlix\Auth\RateLimitException::class);
        $manager->login('nina', 'whatever123', 'device-1');
    }

    public function test_disabled_password_rejection_is_an_invalid_argument_exception_for_401_mapping(): void
    {
        // AuthController maps InvalidArgumentException → 401; the typed
        // exception MUST ride that existing path untouched.
        $this->assertInstanceOf(\InvalidArgumentException::class, new AuthMethodDisabledException());
        $this->assertSame('auth.method_disabled', AuthMethodDisabledException::CODE);
    }

    public function test_unreadable_policy_denies_password_login_fail_closed(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getAllOverrides')->willThrowException(new RuntimeException('db down'));
        $waDb = $this->createMock(Connection::class);
        $waDb->method('query')->willReturn([]);
        $policy = new AuthMethodPolicy(
            $settings,
            $this->createMock(UserRepository::class),
            new WebAuthnCredentialRepository($waDb),
            $this->createMock(UserIdentityRepository::class),
            $this->createMock(AuthProviderBootstrapper::class),
        );

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->never())->method('findByUsername');
        $repo->expects($this->once())->method('burnPasswordVerifyTime');

        $this->expectException(AuthMethodDisabledException::class);
        $this->manager($repo, $policy)->login('nina', 'topsecret123', 'device-1');
    }

    // ── verifyCredentials() — the second password consumer (OPDS Basic) ──

    public function test_verify_credentials_returns_null_while_password_disabled(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->never())->method('findByUsername');
        $repo->expects($this->never())->method('verifyPassword');
        // Same L-1 envelope as its unknown-user branch.
        $repo->expects($this->once())->method('burnPasswordVerifyTime')->with('topsecret123');

        $manager = $this->manager($repo, $this->policy(['auth.password.enabled' => false]));

        $this->assertNull($manager->verifyCredentials('alice', 'topsecret123'));
    }

    public function test_verify_credentials_unaffected_when_enabled_or_unwired(): void
    {
        $row = ['id' => 'user-42', 'status' => 'active'];
        foreach ([[], null] as $case) {
            $repo = $this->createMock(UserRepository::class);
            $repo->method('findByUsername')->with('alice')->willReturn($row);
            $repo->method('verifyPassword')->willReturn(true);

            $policy = $case === null ? null : $this->policy($case);
            $this->assertSame('user-42', $this->manager($repo, $policy)->verifyCredentials('alice', 'topsecret123'));
        }
    }

    public function test_verify_credentials_fail_closed_on_unreadable_policy(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getAllOverrides')->willThrowException(new RuntimeException('db down'));
        $waDb = $this->createMock(Connection::class);
        $waDb->method('query')->willReturn([]);
        $policy = new AuthMethodPolicy(
            $settings,
            $this->createMock(UserRepository::class),
            new WebAuthnCredentialRepository($waDb),
            $this->createMock(UserIdentityRepository::class),
            $this->createMock(AuthProviderBootstrapper::class),
        );

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->never())->method('findByUsername');
        $repo->expects($this->once())->method('burnPasswordVerifyTime');

        $this->assertNull($this->manager($repo, $policy)->verifyCredentials('alice', 'topsecret123'));
    }
}
