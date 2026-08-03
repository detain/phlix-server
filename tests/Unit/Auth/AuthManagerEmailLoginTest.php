<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use InvalidArgumentException;
use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;

/**
 * Verifies AuthManager::login accepts EITHER a username or an email as the
 * identifier: it tries findByUsername first, then falls back to findByEmail.
 * This is what lets the SPA "Username or email" login field work when the user
 * types the email they registered with.
 */
final class AuthManagerEmailLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // AuthManager throttles logins via a process-wide static store keyed on
        // client IP (here the default 127.0.0.1). Under executionOrder="random"
        // failed-login attempts from other auth tests can pre-trip the limiter,
        // so reset it to keep this case isolated.
        AuthManager::resetRateLimitStore();
    }

    private function silentLogger(): StructuredLogger
    {
        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => sys_get_temp_dir() . '/phlix_authemail_' . uniqid() . '.log',
                    'level' => 'debug',
                ],
            ],
        ]);
    }

    private function manager(UserRepository $repo): AuthManager
    {
        return new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
        );
    }

    public function test_login_falls_back_to_email_when_username_misses(): void
    {
        $repo = $this->createMock(UserRepository::class);
        // The identifier is an email, so the username lookup misses…
        $repo->method('findByUsername')->with('alice@example.com')->willReturn(null);
        // …and the email lookup resolves the account.
        $repo->expects($this->once())
            ->method('findByEmail')
            ->with('alice@example.com')
            ->willReturn(['id' => 'user-42']);
        $repo->method('verifyPassword')->with('user-42', 'topsecret123')->willReturn(true);
        $repo->method('findById')->willReturn([
            'id' => 'user-42',
            'username' => 'alice',
            'email' => 'alice@example.com',
            'display_name' => 'alice',
            'is_admin' => 0,
            'password_hash' => 'xxx',
        ]);

        /** @var array{user: array<string, mixed>} $result */
        $result = $this->manager($repo)->login('alice@example.com', 'topsecret123', 'device-1');

        $this->assertSame('user-42', $result['user']['id']);
    }

    public function test_login_still_works_by_username_without_hitting_email(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->with('alice')->willReturn(['id' => 'user-42']);
        // Username matched, so the email fallback must NOT be consulted.
        $repo->expects($this->never())->method('findByEmail');
        $repo->method('verifyPassword')->willReturn(true);
        $repo->method('findById')->willReturn([
            'id' => 'user-42',
            'username' => 'alice',
            'email' => 'alice@example.com',
            'display_name' => 'alice',
            'is_admin' => 0,
            'password_hash' => 'xxx',
        ]);

        /** @var array{user: array<string, mixed>} $result */
        $result = $this->manager($repo)->login('alice', 'topsecret123', 'device-1');

        $this->assertSame('user-42', $result['user']['id']);
    }

    public function test_login_rejects_when_neither_username_nor_email_matches(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByEmail')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->manager($repo)->login('ghost@example.com', 'whatever123', 'device-1');
    }
}
