<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use InvalidArgumentException;
use Phlix\Auth\AuthManager;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\ProviderManager;
use Phlix\Auth\RateLimitException;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Shared\Auth\AuthResult;
use Phlix\Shared\Auth\ProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * S44 Finding 2 (HIGH) — the external-provider login path (used by the `ldap:`
 * prefix) MUST share the same per-IP brute-force throttle as the local password
 * login. Before the fix, {@see AuthManager::loginWithProvider()} called none of
 * checkRateLimit()/recordFailedAttempt()/clearRateLimit(), so LDAP logins
 * allowed unthrottled directory-credential guessing.
 *
 * These tests exercise the in-memory fallback rate-limit store (no
 * DbLoginRateLimitStore is injected), keyed on the default 127.0.0.1 client IP.
 */
final class AuthManagerProviderRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The rate-limit store is a process-wide static keyed on client IP; reset
        // it so failed attempts from other auth tests can't pre-trip the limiter
        // (executionOrder="random").
        AuthManager::resetRateLimitStore();
    }

    /** @var list<string> log files minted by silentLogger(), removed in tearDown(). */
    private array $mintedLogPaths = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        AuthManager::resetRateLimitStore();
        // S439: sweep every stream path minted by silentLogger().
        foreach ($this->mintedLogPaths as $path) {
            @unlink($path);
        }
        $this->mintedLogPaths = [];
    }

    private function silentLogger(): StructuredLogger
    {
        $path = sys_get_temp_dir() . '/phlix_provider_ratelimit_' . uniqid() . '.log';
        $this->mintedLogPaths[] = $path;

        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $path,
                    'level' => 'debug',
                ],
            ],
        ]);
    }

    private function manager(UserRepository $repo, ProviderManager $providerManager): AuthManager
    {
        return new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,   // eventDispatcher
            null,   // db
            $providerManager,
        );
    }

    /**
     * A real ProviderManager (it is final, so it cannot be mocked) wired to a
     * registry holding a stub 'ldap' provider that returns the given result.
     */
    private function providerManagerReturning(AuthResult $result, UserRepository $repo): ProviderManager
    {
        $registry = new AuthProviderRegistry();
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('ldap');
        $provider->method('authenticate')->willReturn($result);
        $registry->registerProvider($provider);

        return new ProviderManager($registry, $repo);
    }

    public function test_repeated_ldap_failures_trip_the_rate_limiter(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $providerManager = $this->providerManagerReturning(
            new AuthResult(success: false, error: 'invalid_credentials'),
            $repo,
        );
        $manager = $this->manager($repo, $providerManager);

        // RATE_LIMIT_MAX_ATTEMPTS = 5 failures are allowed through (each rejected
        // as bad credentials); the 6th attempt is throttled BEFORE reaching the
        // provider.
        for ($i = 0; $i < 5; $i++) {
            try {
                $manager->loginWithProvider(
                    'ldap:victim',
                    ['username' => 'victim', 'password' => 'wrong'],
                    'device-1',
                );
                $this->fail('Expected InvalidArgumentException on bad credentials');
            } catch (InvalidArgumentException $e) {
                // expected — the provider rejected the credentials.
            }
        }

        $this->expectException(RateLimitException::class);
        $manager->loginWithProvider(
            'ldap:victim',
            ['username' => 'victim', 'password' => 'wrong'],
            'device-1',
        );
    }

    public function test_successful_provider_login_clears_the_rate_limit_window(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->willReturn([
            'id' => 'user-1',
            'username' => 'victim',
            'email' => 'victim@example.com',
            'display_name' => 'Victim',
            'is_admin' => 0,
            'password_hash' => 'xxx',
        ]);

        // Fail 4 times, then succeed — the success must clear the failed-attempt
        // window so the counter is back to zero.
        $failingManager = $this->manager(
            $repo,
            $this->providerManagerReturning(
                new AuthResult(success: false, error: 'invalid_credentials'),
                $repo,
            ),
        );
        for ($i = 0; $i < 4; $i++) {
            try {
                $failingManager->loginWithProvider(
                    'ldap:victim',
                    ['username' => 'victim', 'password' => 'wrong'],
                    'device-1',
                );
            } catch (InvalidArgumentException $e) {
                // expected
            }
        }

        $successManager = $this->manager(
            $repo,
            $this->providerManagerReturning(
                new AuthResult(success: true, userId: 'user-1'),
                $repo,
            ),
        );
        $result = $successManager->loginWithProvider(
            'ldap:victim',
            ['username' => 'victim', 'password' => 'correct'],
            'device-1',
        );
        $this->assertArrayHasKey('access_token', $result);

        // The window was cleared, so 5 fresh failures are needed to trip again —
        // one more failure must NOT throttle.
        $failAgain = $this->manager(
            $repo,
            $this->providerManagerReturning(
                new AuthResult(success: false, error: 'invalid_credentials'),
                $repo,
            ),
        );
        try {
            $failAgain->loginWithProvider(
                'ldap:victim',
                ['username' => 'victim', 'password' => 'wrong'],
                'device-1',
            );
            $this->fail('Expected InvalidArgumentException on bad credentials');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('invalid_credentials', $e->getMessage());
        }
    }
}
