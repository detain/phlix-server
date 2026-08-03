<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Stats\StatsCollector;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Verifies AuthManager records user-activity stats (login/logout) into the
 * StatsCollector when one is wired — the source the admin dashboard activity
 * feed reads from. Without this wiring stats_user_activity stays empty.
 *
 */
final class AuthManagerStatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // AuthManager throttles logins via a process-wide static store keyed on
        // client IP (here the default 127.0.0.1). Under executionOrder="random"
        // failed-login attempts from other auth tests can pre-trip the limiter,
        // so reset it to keep these cases isolated.
        AuthManager::resetRateLimitStore();
    }

    private function silentLogger(): StructuredLogger
    {
        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => sys_get_temp_dir() . '/phlix_authstats_' . uniqid() . '.log',
                    'level' => 'debug',
                ],
            ],
        ]);
    }

    private function userRepoForLogin(): UserRepository
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(['id' => 'user-42']);
        $repo->method('verifyPassword')->willReturn(true);
        $repo->method('findById')->willReturn([
            'id' => 'user-42',
            'username' => 'alice',
            'email' => 'alice@example.com',
            'display_name' => 'alice',
            'is_admin' => 0,
            'password_hash' => 'xxx',
        ]);
        return $repo;
    }

    public function test_login_records_login_activity(): void
    {
        $stats = $this->createMock(StatsCollector::class);
        $stats->expects($this->once())
            ->method('recordUserActivity')
            ->with('user-42', 'login', $this->anything());

        $manager = new AuthManager(
            $this->userRepoForLogin(),
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            null,
            null,
            $stats,
        );

        $manager->login('alice', 'topsecret123', 'device-1');
    }

    public function test_logout_records_logout_activity(): void
    {
        $stats = $this->createMock(StatsCollector::class);
        $stats->expects($this->once())
            ->method('recordUserActivity')
            ->with('user-42', 'logout', $this->anything());

        $manager = new AuthManager(
            $this->createMock(UserRepository::class),
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            null,
            null,
            $stats,
        );

        $manager->logout('user-42', 'session-1');
    }

    public function test_login_without_collector_does_not_throw(): void
    {
        $manager = new AuthManager(
            $this->userRepoForLogin(),
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
        );

        /** @var array{user: array<string, mixed>} $result */
        $result = $manager->login('alice', 'topsecret123', 'device-1');
        $this->assertSame('user-42', $result['user']['id']);
    }
}
