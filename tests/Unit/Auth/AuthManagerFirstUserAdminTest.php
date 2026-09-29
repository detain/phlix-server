<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * Verifies the Step A.5 admin-bootstrap behaviour in {@see AuthManager::register()}:
 *
 *  - Empty `users` table → newly-registered user is promoted to admin.
 *  - Non-empty `users` table → newly-registered user stays non-admin.
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

        /** @var array{user: array<string, mixed>} $result */
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

        /** @var array{user: array<string, mixed>} $result */
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
        $this->electFirstWinner($db); // L-3: claim sentinel + read back own NULL stamp
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
        $this->electFirstWinner($db); // election happens INSIDE the tx (L-3)
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
        $this->electFirstWinner($db);
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
        // Election queries ride the SAME mock; their queries happen before the
        // failing create(), so no stubbing beyond defaults is needed — but the
        // FOR UPDATE read-back must not report an empty result (that throws
        // "sentinel unreadable"), so answer it:
        $db->method('query')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'SELECT user_id')
                ? [['user_id' => null]]
                : []
        );

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
        // still work, just without transactional semantics (and, per L-3, the
        // unprotected countUsers() election).
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

        /** @var array{user: array<string, mixed>} $result */
        $result = $manager->register('root', 'root@example.com', 'topsecret123');
        $this->assertSame('user-1', $result['user']['id']);
    }

    // ─────────────────────────────────────────────────────────────────
    // L-3 (security audit 2026-09-29): the election is the SENTINEL, not a count
    // ─────────────────────────────────────────────────────────────────

    public function test_db_backed_register_uses_sentinel_not_countusers(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        // The transactional path must NEVER consult the race-prone count.
        $repo->expects($this->never())->method('countUsers');
        $repo->method('create')->willReturn('user-2');
        $repo->expects($this->never())->method('setAdmin');
        $repo->method('findById')->willReturn([
            'id' => 'user-2',
            'username' => 'bob',
            'email' => 'bob@example.com',
            'display_name' => 'bob',
            'is_admin' => 0,
            'password_hash' => 'xxx',
        ]);
        $repo->method('getAuthState')->willReturn(['status' => 'active', 'tokensNotValidAfter' => 0]);

        $db = $this->createMock(Connection::class);
        // Someone already won: the sentinel row reads back with a stamped user id.
        $db->method('query')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'SELECT user_id')
                ? [['user_id' => 'user-1']]
                : []
        );
        $db->expects($this->once())->method('commitTrans');

        $manager = new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            $db,
        );

        $result = $manager->register('bob', 'bob@example.com', 'topsecret123');
        $this->assertSame('user-2', $result['user']['id']);
        $this->assertSame(0, $result['user']['is_admin']);
    }

    public function test_missing_sentinel_table_fails_loud_instead_of_electing(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->expects($this->never())->method('create');

        $db = $this->createMock(Connection::class);
        // Empty read-back (e.g. migrations not run) must throw, not default.
        $db->method('query')->willReturn([]);
        $db->expects($this->once())->method('beginTrans');
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
        $this->expectExceptionMessage('first_admin_election');
        $manager->register('root', 'root@example.com', 'topsecret123');
    }

    /**
     * Program the Connection mock so the L-3 election sees THIS registration as
     * the winner: INSERT IGNORE answers with its default, and the FOR UPDATE
     * read-back returns the unclaimed sentinel (user_id NULL ⇒ I hold it).
     */
    private function electFirstWinner(Connection&MockObject $db): void
    {
        $db->method('query')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'SELECT user_id')
                ? [['user_id' => null]]
                : []
        );
    }

    /** @var list<string> log dirs minted by silentLogger(), removed in tearDown(). */
    private array $mintedLogDirs = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        // S439: silentLogger() mints a fresh dir per call — sweep them all.
        foreach ($this->mintedLogDirs as $dir) {
            @unlink($dir . '/test.log');
            @rmdir($dir);
        }
        $this->mintedLogDirs = [];
    }

    private function silentLogger(): StructuredLogger
    {
        // Build a no-op StructuredLogger that swallows messages.
        $tmp = sys_get_temp_dir() . '/phlix_admin_test_' . uniqid('', true);
        @mkdir($tmp, 0775, true);
        $this->mintedLogDirs[] = $tmp;
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
