<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Auth;

use Phlex\Auth\AuthManager;
use Phlex\Auth\JwtHandler;
use Phlex\Auth\UserRepository;
use Phlex\Common\Logger\AuditLogger;
use Phlex\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the Step A.5 admin-bootstrap behaviour in {@see AuthManager::register()}:
 *
 *  - Empty `users` table → newly-registered user is promoted to admin.
 *  - Non-empty `users` table → newly-registered user stays non-admin.
 *
 * @covers \Phlex\Auth\AuthManager
 */
final class AuthManagerFirstUserAdminTest extends TestCase
{
    public function test_first_user_is_promoted_to_admin(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->expects($this->once())->method('countUsers')->willReturn(0);
        $repo->expects($this->once())
            ->method('create')
            ->willReturn('user-1');
        $repo->expects($this->once())
            ->method('setAdmin')
            ->with('user-1', true);
        $repo->method('findById')->willReturn([
            'id' => 'user-1',
            'username' => 'root',
            'email' => 'root@example.com',
            'display_name' => 'root',
            'is_admin' => 1,
            'password_hash' => 'xxx',
        ]);

        $manager = new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
        );

        $result = $manager->register('root', 'root@example.com', 'topsecret123');
        $this->assertSame('user-1', $result['user']['id']);
    }

    public function test_subsequent_users_are_not_promoted(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(3);
        $repo->method('create')->willReturn('user-4');
        $repo->expects($this->never())->method('setAdmin');
        $repo->method('findById')->willReturn([
            'id' => 'user-4',
            'username' => 'alice',
            'email' => 'alice@example.com',
            'display_name' => 'alice',
            'is_admin' => 0,
            'password_hash' => 'xxx',
        ]);

        $manager = new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
        );

        $result = $manager->register('alice', 'alice@example.com', 'topsecret123');
        $this->assertSame('user-4', $result['user']['id']);
    }

    private function silentLogger(): StructuredLogger
    {
        // Build a no-op StructuredLogger that swallows messages.
        $tmp = sys_get_temp_dir() . '/phlex_admin_test_' . uniqid('', true);
        @mkdir($tmp, 0775, true);
        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => ['type' => 'stream', 'path' => $tmp . '/test.log', 'level' => 'debug'],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ]);
    }
}
