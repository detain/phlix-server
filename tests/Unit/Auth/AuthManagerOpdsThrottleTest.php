<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\RateLimitException;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;

/**
 * M-5 (security audit 2026-09-29) — OPDS Basic-auth password oracle closed.
 *
 * The OPDS feed group validates HTTP Basic credentials through
 * {@see AuthManager::verifyCredentialsThrottled()}, which charges FAILURES to
 * the same per-IP budget as {@see AuthManager::login()} (5 attempts / 15 min)
 * and clears the window on success. Before the fix the OPDS path called the
 * deliberately-unthrottled verifyCredentials(), turning every e-reader request
 * into an unlimited Argon2id-cost guess — the expensive-password equivalent of
 * an open brute-force door.
 *
 * These tests exercise the in-memory fallback store (no
 * DbLoginRateLimitStore injected) keyed on the caller-supplied IP.
 */
final class AuthManagerOpdsThrottleTest extends TestCase
{
    /** @var list<string> log files minted by silentLogger(), removed in tearDown(). */
    private array $mintedLogPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        AuthManager::resetRateLimitStore();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        AuthManager::resetRateLimitStore();
        foreach ($this->mintedLogPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->mintedLogPaths = [];
    }

    private function silentLogger(): StructuredLogger
    {
        $path = sys_get_temp_dir() . '/phlix_opds_throttle_' . uniqid() . '.log';
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

    /**
     * A repository whose known user 'opds-reader' verifies ONLY against
     * password 'right-pass' (verifyPassword stubbed, so the fast path never
     * burns real Argon2id time).
     */
    private function manager(UserRepository $repo): AuthManager
    {
        return new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-at-least-32-bytes-long!!', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
        );
    }

    private function repoForKnownUser(): UserRepository
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn([
            'id' => 'user-opds',
            'username' => 'opds-reader',
            'email' => 'opds@example.com',
            'status' => 'active',
        ]);
        $repo->method('verifyPassword')->willReturnCallback(
            static fn (string $id, string $password): bool => $password === 'right-pass'
        );

        return $repo;
    }

    public function test_repeated_wrong_opds_passwords_trip_the_login_budget(): void
    {
        $manager = $this->manager($this->repoForKnownUser());

        // RATE_LIMIT_MAX_ATTEMPTS = 5 wrong passwords are answered as plain
        // rejections (null ⇒ 401 + WWW-Authenticate upstream); the 6th is
        // throttled BEFORE the password check — the same budget as login().
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull(
                $manager->verifyCredentialsThrottled('opds-reader', 'wrong-' . $i, '203.0.113.7'),
                'Wrong password must reject without throwing on attempt ' . ($i + 1)
            );
        }

        $this->expectException(RateLimitException::class);
        $manager->verifyCredentialsThrottled('opds-reader', 'wrong-6', '203.0.113.7');
    }

    public function test_successful_opds_auth_clears_the_failure_window(): void
    {
        $manager = $this->manager($this->repoForKnownUser());

        for ($i = 0; $i < 4; $i++) {
            $this->assertNull($manager->verifyCredentialsThrottled('opds-reader', 'wrong', '203.0.113.8'));
        }

        $this->assertSame('user-opds', $manager->verifyCredentialsThrottled('opds-reader', 'right-pass', '203.0.113.8'));

        // Post-success the budget is fresh again: five more failures stay
        // answerable (401 UX for a reader with a typo) instead of inheriting a
        // half-consumed window.
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($manager->verifyCredentialsThrottled('opds-reader', 'wrong', '203.0.113.8'));
        }

        $this->expectException(RateLimitException::class);
        $manager->verifyCredentialsThrottled('opds-reader', 'wrong', '203.0.113.8');
    }

    public function test_throttle_is_scoped_per_ip(): void
    {
        $manager = $this->manager($this->repoForKnownUser());

        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($manager->verifyCredentialsThrottled('opds-reader', 'wrong', '203.0.113.9'));
        }

        // A different e-reader on another IP is not punished by the first's
        // exhaustions — the budget is per-IP, exactly like the login throttle.
        $this->assertSame('user-opds', $manager->verifyCredentialsThrottled('opds-reader', 'right-pass', '198.51.100.4'));
    }

    public function test_unthrottled_verify_credentials_stays_pure_for_internal_callers(): void
    {
        // The plain method must NEVER consult the limiter (provider flows call
        // it in loops; M-5 deliberately wraps rather than mutates it).
        $repo = $this->repoForKnownUser();
        $manager = $this->manager($repo);

        for ($i = 0; $i < 10; $i++) {
            $this->assertNull($manager->verifyCredentials('opds-reader', 'wrong'));
        }

        $this->assertSame('user-opds', $manager->verifyCredentials('opds-reader', 'right-pass'));
    }
}
