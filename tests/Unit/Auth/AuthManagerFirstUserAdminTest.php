<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * Verifies the Step A.5 admin-bootstrap behaviour in {@see AuthManager::register()}:
 *
 *  - Empty `users` table → newly-registered user is promoted to admin.
 *  - Non-empty `users` table → newly-registered user stays non-admin.
 *
 * @covers \Phlix\Auth\AuthManager
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

    public function test_first_user_promotion_commits_transaction_on_success(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(0);
        $repo->method('create')->willReturn('user-1');
        $repo->method('setAdmin');
        $repo->method('findById')->willReturn([
            'id' => 'user-1',
            'username' => 'root',
            'email' => 'root@example.com',
            'display_name' => 'root',
            'is_admin' => 1,
            'password_hash' => 'xxx',
        ]);

        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->once())->method('commitTrans');
        $db->expects($this->never())->method('rollBackTrans');

        $manager = new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            $db,
        );

        $manager->register('root', 'root@example.com', 'topsecret123');
    }

    public function test_register_rolls_back_when_setAdmin_throws(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(0);
        $repo->method('create')->willReturn('user-1');
        $repo->method('setAdmin')->willThrowException(new RuntimeException('DB exploded between create and setAdmin'));

        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->never())->method('commitTrans');
        $db->expects($this->once())->method('rollBackTrans');

        $manager = new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            $db,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB exploded between create and setAdmin');
        $manager->register('root', 'root@example.com', 'topsecret123');
    }

    public function test_register_rolls_back_when_create_throws(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(0);
        $repo->method('create')->willThrowException(new RuntimeException('insert failed'));
        $repo->expects($this->never())->method('setAdmin');

        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->never())->method('commitTrans');
        $db->expects($this->once())->method('rollBackTrans');

        $manager = new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            $db,
        );

        $this->expectException(RuntimeException::class);
        $manager->register('alice', 'alice@example.com', 'topsecret123');
    }

    public function test_register_swallows_rollback_failure_and_still_propagates_original(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(0);
        $repo->method('create')->willThrowException(new RuntimeException('original insert error'));

        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('beginTrans');
        $db->method('rollBackTrans')->willThrowException(new RuntimeException('rollback also failed'));

        $manager = new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            $db,
        );

        try {
            $manager->register('alice', 'alice@example.com', 'topsecret123');
            $this->fail('Expected original RuntimeException to be re-thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('original insert error', $e->getMessage());
        }
    }

    public function test_register_without_db_connection_skips_transaction(): void
    {
        // Legacy code path: no Connection injected -> AuthManager must
        // still work, just without transactional semantics.
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(0);
        $repo->method('create')->willReturn('user-1');
        $repo->method('setAdmin');
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
            null,
            null, // explicitly no db
        );

        $result = $manager->register('root', 'root@example.com', 'topsecret123');
        $this->assertSame('user-1', $result['user']['id']);
    }

    private function silentLogger(): StructuredLogger
    {
        // Build a no-op StructuredLogger that swallows messages.
        $tmp = sys_get_temp_dir() . '/phlix_admin_test_' . uniqid('', true);
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
