<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AccountInactiveException;
use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\SignupDisabledException;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Verifies the S1 signup-approval gate in {@see AuthManager::register()} and
 * {@see AuthManager::login()}:
 *
 *  - register() honours `auth.signup_mode` (open|approval|disabled).
 *  - first user always bootstraps active+admin regardless of mode.
 *  - login() rejects non-active accounts with distinct error codes.
 *
 * Mirrors the existing AuthManager test setup (createMock UserRepository,
 * real JwtHandler, mocked AuditLogger, silent StructuredLogger). The
 * SettingsRepository is mocked and stubbed to return the signup mode.
 *
 */
final class AuthManagerSignupGateTest extends TestCase
{
    /**
     * The IP-keyed rate-limit store is a process-wide static on AuthManager.
     * Reset it (and the default client IP) before each test so attempts from
     * one test never trip the limiter in another.
     */
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
        parent::tearDown();
    }

    private function silentLogger(): StructuredLogger
    {
        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => sys_get_temp_dir() . '/phlix_signupgate_' . uniqid('', true) . '.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ]);
    }

    /**
     * Build an AuthManager with an optional settings mode. The same real
     * JwtHandler is reused so tests that mint a token can hand it back in.
     */
    private function manager(UserRepository $repo, ?string $mode, ?JwtHandler $jwt = null): AuthManager
    {
        $settings = null;
        if ($mode !== null) {
            $settings = $this->createMock(SettingsRepository::class);
            $settings->method('getEffective')->with('auth.signup_mode')->willReturn($mode);
        }

        return new AuthManager(
            $repo,
            $jwt ?? new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            null,
            null,
            null,
            $settings,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function userRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'user-9',
            'username' => 'nina',
            'email' => 'nina@example.com',
            'display_name' => 'nina',
            'is_admin' => 0,
            'status' => 'active',
            'password_hash' => 'xxx',
        ], $overrides);
    }

    // ─────────────────────────────────────────────────────────────────
    // register() — signup mode gate
    // ─────────────────────────────────────────────────────────────────

    public function test_register_open_mode_creates_active_user_with_tokens(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(5); // not first user
        $repo->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn (array $data): bool => ($data['status'] ?? null) === 'active'))
            ->willReturn('user-9');
        $repo->expects($this->never())->method('setAdmin');
        $repo->method('findById')->willReturn($this->userRow());

        /** @var array{user: array<string, mixed>} $result */
        $result = $this->manager($repo, 'open')->register('nina', 'nina@example.com', 'topsecret123');

        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertSame('user-9', $result['user']['id']);
    }

    public function test_register_approval_mode_creates_pending_user_with_no_tokens(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(5); // not first user
        $repo->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn (array $data): bool => ($data['status'] ?? null) === 'pending'))
            ->willReturn('user-9');
        $repo->expects($this->never())->method('setAdmin');
        // No tokens issued, so findById (for the auth response user) is never needed.
        $repo->expects($this->never())->method('findById');

        $result = $this->manager($repo, 'approval')->register('nina', 'nina@example.com', 'topsecret123');

        $this->assertSame('pending', $result['status']);
        $this->assertNull($result['user']);
        $this->assertArrayNotHasKey('access_token', $result);
        $this->assertArrayNotHasKey('refresh_token', $result);
        $this->assertIsString($result['message']);
    }

    public function test_register_disabled_mode_throws_signup_disabled(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(5); // not first user
        // No account is created when signups are disabled.
        $repo->expects($this->never())->method('create');

        $this->expectException(SignupDisabledException::class);
        $this->manager($repo, 'disabled')->register('nina', 'nina@example.com', 'topsecret123');
    }

    public function test_register_disabled_exception_carries_stable_code(): void
    {
        $this->assertSame('auth.signups_disabled', SignupDisabledException::ERROR_CODE);
    }

    public function test_first_user_is_active_admin_even_when_mode_disabled(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(0); // first user
        $repo->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn (array $data): bool => ($data['status'] ?? null) === 'active'))
            ->willReturn('user-1');
        $repo->expects($this->once())->method('setAdmin')->with('user-1', true);
        $repo->method('findById')->willReturn($this->userRow([
            'id' => 'user-1',
            'username' => 'root',
            'is_admin' => 1,
        ]));

        /** @var array{user: array<string, mixed>} $result */
        $result = $this->manager($repo, 'disabled')->register('root', 'root@example.com', 'topsecret123');

        // First user bootstraps active + admin and gets tokens despite mode=disabled.
        $this->assertArrayHasKey('access_token', $result);
        $this->assertSame('user-1', $result['user']['id']);
    }

    public function test_first_user_is_active_admin_even_when_mode_approval(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(0); // first user
        $repo->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn (array $data): bool => ($data['status'] ?? null) === 'active'))
            ->willReturn('user-1');
        $repo->expects($this->once())->method('setAdmin')->with('user-1', true);
        $repo->method('findById')->willReturn($this->userRow([
            'id' => 'user-1',
            'username' => 'root',
            'is_admin' => 1,
        ]));

        /** @var array{user: array<string, mixed>} $result */
        $result = $this->manager($repo, 'approval')->register('root', 'root@example.com', 'topsecret123');

        $this->assertArrayHasKey('access_token', $result);
        $this->assertSame('user-1', $result['user']['id']);
    }

    public function test_register_without_settings_repo_falls_back_to_open(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(5); // not first user
        $repo->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn (array $data): bool => ($data['status'] ?? null) === 'active'))
            ->willReturn('user-9');
        $repo->method('findById')->willReturn($this->userRow());

        // No settings repository wired => legacy open behaviour, active + tokens.
        $result = $this->manager($repo, null)->register('nina', 'nina@example.com', 'topsecret123');

        $this->assertArrayHasKey('access_token', $result);
    }

    public function test_register_defaults_to_approval_when_settings_read_throws(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new RuntimeException('settings unavailable'));

        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(5); // not first user
        $repo->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn (array $data): bool => ($data['status'] ?? null) === 'pending'))
            ->willReturn('user-9');

        $manager = new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            null,
            null,
            null,
            $settings,
        );

        $result = $manager->register('nina', 'nina@example.com', 'topsecret123');
        $this->assertSame('pending', $result['status']);
    }

    public function test_register_defaults_to_approval_on_unknown_mode_value(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(5);
        $repo->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn (array $data): bool => ($data['status'] ?? null) === 'pending'))
            ->willReturn('user-9');

        $result = $this->manager($repo, 'banana')->register('nina', 'nina@example.com', 'topsecret123');
        $this->assertSame('pending', $result['status']);
    }

    // ─────────────────────────────────────────────────────────────────
    // login() — active-account gate
    // ─────────────────────────────────────────────────────────────────

    public function test_login_active_user_succeeds(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->with('nina')->willReturn($this->userRow(['status' => 'active']));
        $repo->method('verifyPassword')->willReturn(true);
        $repo->method('findById')->willReturn($this->userRow(['status' => 'active']));

        /** @var array{user: array<string, mixed>, access_token?: mixed} $result */
        $result = $this->manager($repo, 'open')->login('nina', 'topsecret123', 'device-1');

        $this->assertArrayHasKey('access_token', $result);
        $this->assertSame('user-9', $result['user']['id']);
    }

    public function test_login_pending_user_throws_account_pending(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->with('nina')->willReturn($this->userRow(['status' => 'pending']));
        $repo->method('verifyPassword')->willReturn(true);
        // No tokens issued for an inactive account.
        $repo->expects($this->never())->method('updateLastLogin');

        try {
            $this->manager($repo, 'open')->login('nina', 'topsecret123', 'device-1');
            $this->fail('Expected AccountInactiveException for a pending account');
        } catch (AccountInactiveException $e) {
            $this->assertSame(AccountInactiveException::ERROR_PENDING, $e->errorCode);
            $this->assertSame('auth.account_pending', $e->errorCode);
        }
    }

    public function test_login_disabled_user_throws_account_disabled(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->with('nina')->willReturn($this->userRow(['status' => 'disabled']));
        $repo->method('verifyPassword')->willReturn(true);
        $repo->expects($this->never())->method('updateLastLogin');

        try {
            $this->manager($repo, 'open')->login('nina', 'topsecret123', 'device-1');
            $this->fail('Expected AccountInactiveException for a disabled account');
        } catch (AccountInactiveException $e) {
            $this->assertSame(AccountInactiveException::ERROR_DISABLED, $e->errorCode);
            $this->assertSame('auth.account_disabled', $e->errorCode);
        }
    }

    public function test_login_missing_status_defaults_to_active(): void
    {
        $repo = $this->createMock(UserRepository::class);
        // Row without a 'status' key (e.g. pre-migration column) — login should
        // treat it as active for safety.
        $row = $this->userRow();
        unset($row['status']);
        $repo->method('findByUsername')->with('nina')->willReturn($row);
        $repo->method('verifyPassword')->willReturn(true);
        $repo->method('findById')->willReturn($this->userRow());

        $result = $this->manager($repo, 'open')->login('nina', 'topsecret123', 'device-1');
        $this->assertArrayHasKey('access_token', $result);
    }

    public function test_login_rate_limit_still_enforced_after_failed_attempts(): void
    {
        // The rate-limit store is reset in setUp(), so this test starts clean.
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByEmail')->willReturn(null);

        $manager = $this->manager($repo, 'open');

        // 5 failed attempts trip the limiter (RATE_LIMIT_MAX_ATTEMPTS = 5).
        for ($i = 0; $i < 5; $i++) {
            try {
                $manager->login('ghost', 'whatever123', 'device-1');
            } catch (\InvalidArgumentException) {
                // expected: invalid credentials
            }
        }

        $this->expectException(\Phlix\Auth\RateLimitException::class);
        $manager->login('ghost', 'whatever123', 'device-1');
    }

    // ─────────────────────────────────────────────────────────────────
    // refreshToken() — re-check DB status (S1 security fix, finding 1)
    // ─────────────────────────────────────────────────────────────────

    public function test_refresh_token_succeeds_for_active_user(): void
    {
        $jwt = new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
        $refreshToken = $jwt->createRefreshToken('user-9');

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('getStatus')->with('user-9')->willReturn('active');
        $repo->method('findById')->willReturn($this->userRow(['status' => 'active']));

        /** @var array{user: array<string, mixed>, access_token?: mixed, refresh_token?: mixed} $result */
        $result = $this->manager($repo, 'open', $jwt)->refreshToken($refreshToken);

        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertSame('user-9', $result['user']['id']);
    }

    public function test_refresh_token_rejects_disabled_user(): void
    {
        $jwt = new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
        $refreshToken = $jwt->createRefreshToken('user-9');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('getStatus')->with('user-9')->willReturn('disabled');
        // A disabled account must not be issued fresh tokens.
        $repo->expects($this->never())->method('findById');

        $this->expectException(\InvalidArgumentException::class);
        $this->manager($repo, 'open', $jwt)->refreshToken($refreshToken);
    }

    public function test_refresh_token_rejects_pending_user(): void
    {
        $jwt = new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
        $refreshToken = $jwt->createRefreshToken('user-9');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('getStatus')->with('user-9')->willReturn('pending');

        $this->expectException(\InvalidArgumentException::class);
        $this->manager($repo, 'open', $jwt)->refreshToken($refreshToken);
    }

    public function test_refresh_token_treats_missing_status_as_active(): void
    {
        $jwt = new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
        $refreshToken = $jwt->createRefreshToken('user-9');

        $repo = $this->createMock(UserRepository::class);
        // getStatus returns null when the column/row is absent — treated as active.
        $repo->method('getStatus')->with('user-9')->willReturn(null);
        $repo->method('findById')->willReturn($this->userRow());

        $result = $this->manager($repo, 'open', $jwt)->refreshToken($refreshToken);
        $this->assertArrayHasKey('access_token', $result);
    }

    // ─────────────────────────────────────────────────────────────────
    // validateAccessToken() — re-check DB status (S1 security fix, finding 1)
    // ─────────────────────────────────────────────────────────────────

    public function test_validate_access_token_returns_info_for_active_user(): void
    {
        $jwt = new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
        $accessToken = $jwt->createAccessToken('user-9');

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('getStatus')->with('user-9')->willReturn('active');

        $info = $this->manager($repo, 'open', $jwt)->validateAccessToken($accessToken);

        $this->assertIsArray($info);
        $this->assertSame('user-9', $info['user_id']);
        $this->assertArrayHasKey('expires_at', $info);
    }

    public function test_validate_access_token_returns_null_for_disabled_user(): void
    {
        $jwt = new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
        $accessToken = $jwt->createAccessToken('user-9');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('getStatus')->with('user-9')->willReturn('disabled');

        // A live token for a now-disabled account is revoked → null (mirrors the
        // invalid-token contract so HttpHandler leaves the request unauthenticated).
        $this->assertNull($this->manager($repo, 'open', $jwt)->validateAccessToken($accessToken));
    }

    public function test_validate_access_token_returns_null_for_pending_user(): void
    {
        $jwt = new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
        $accessToken = $jwt->createAccessToken('user-9');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('getStatus')->with('user-9')->willReturn('pending');

        $this->assertNull($this->manager($repo, 'open', $jwt)->validateAccessToken($accessToken));
    }

    public function test_validate_access_token_treats_missing_status_as_active(): void
    {
        $jwt = new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
        $accessToken = $jwt->createAccessToken('user-9');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('getStatus')->with('user-9')->willReturn(null);

        $info = $this->manager($repo, 'open', $jwt)->validateAccessToken($accessToken);
        $this->assertIsArray($info);
        $this->assertSame('user-9', $info['user_id']);
    }
}
