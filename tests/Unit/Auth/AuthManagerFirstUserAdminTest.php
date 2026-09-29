<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\SignupDisabledException;
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

    // ─────────────────────────────────────────────────────────────────
    // H-1 rework (security review 2026-09-29): an UNSTAMPED sentinel is only
    // honoured when `users` is genuinely empty. Upgraded installs got a virgin
    // 108 table with pre-existing CLI/admin-UI users — honoring the bare NULL
    // claim let any later register() self-elect ACTIVE ADMIN through it.
    // ─────────────────────────────────────────────────────────────────

    /**
     * THE exploit, red before the fix: sentinel reads back NULL (nobody ever
     * won) while the users table is non-empty (upgraded install). Must NOT
     * promote, must NOT stamp the sentinel.
     */
    public function test_upgraded_install_does_not_elect_through_virgin_sentinel(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('create')->willReturn('user-9');
        $repo->expects($this->never())->method('setAdmin');
        $repo->method('findById')->willReturn([
            'id' => 'user-9',
            'username' => 'intruder',
            'email' => 'intruder@example.com',
            'display_name' => 'intruder',
            'is_admin' => 0,
            'password_hash' => 'xxx',
        ]);
        $repo->method('getAuthState')->willReturn(['status' => 'active', 'tokensNotValidAfter' => 0]);

        $stampedSentinel = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /** @param mixed $params */
            static function (string $sql, $params = []) use (&$stampedSentinel): array {
                if (str_contains($sql, 'UPDATE first_admin_election')) {
                    $stampedSentinel++;
                }

                return self::upgradedShape($sql);
            }
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

        $result = $manager->register('intruder', 'intruder@example.com', 'topsecret123');
        $this->assertSame(0, $result['user']['is_admin'], 'the virgin sentinel must not mint an admin');
        $this->assertSame(0, $stampedSentinel, 'a losing election must never stamp the winner row');
    }

    /**
     * The same upgraded shape against a `disabled` signup gate: pre-fix the
     * bogus election skipped the gate entirely (the first-user branch always
     * bootstraps). Now the gate must answer 403-style.
     */
    public function test_upgraded_install_cannot_bypass_disabled_gate_through_virgin_sentinel(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->expects($this->never())->method('create');

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->with('auth.signup_mode')->willReturn('disabled');

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->once())->method('logFailedAuth')->with('signups_disabled', $this->anything());

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static fn (string $sql): array => self::upgradedShape($sql)
        );
        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->once())->method('rollBackTrans');

        $manager = new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $audit,
            $this->silentLogger(),
            null,
            $db,
            null,
            null,
            $settings,
        );

        $this->expectException(SignupDisabledException::class);
        $manager->register('intruder', 'intruder@example.com', 'topsecret123');
    }

    /**
     * Fail-CLOSED doctrine: a users-emptiness probe that does not parse to an
     * array must never elect, even with a NULL sentinel in hand.
     */
    public function test_unparseable_users_probe_fails_closed(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('create')->willReturn('user-9');
        $repo->expects($this->never())->method('setAdmin');
        $repo->method('findById')->willReturn([
            'id' => 'user-9',
            'username' => 'intruder',
            'email' => 'intruder@example.com',
            'display_name' => 'intruder',
            'is_admin' => 0,
            'password_hash' => 'xxx',
        ]);
        $repo->method('getAuthState')->willReturn(['status' => 'active', 'tokensNotValidAfter' => 0]);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static fn (string $sql) => match (true) {
                str_contains($sql, 'SELECT user_id') => [['user_id' => null]],
                str_contains($sql, 'SELECT 1 FROM users') => 'gateway exploded',
                default => [],
            }
        );

        $manager = new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            $db,
        );

        $result = $manager->register('intruder', 'intruder@example.com', 'topsecret123');
        $this->assertSame(0, $result['user']['is_admin']);
    }

    /**
     * The upgraded-install DB shape answered through a mocked Connection:
     * the sentinel row exists UNSTAMPED (NULL) and `users` holds rows —
     * exactly what a 108-only upgrade plus CLI-created users looks like.
     * (The probe row's `'1'` column name arrives as an int key — PHP's
     * numeric-string key cast — hence array-key on the inner shape.)
     *
     * @return array<int, array<array-key, mixed>>
     */
    private static function upgradedShape(string $sql): array
    {
        if (str_contains($sql, 'SELECT user_id')) {
            return [['user_id' => null]];
        }

        if (str_contains($sql, 'SELECT 1 FROM users')) {
            return [['1' => 1]];
        }

        return [];
    }

    /**
     * Program the Connection mock so the L-3 election sees THIS registration as
     * the winner: INSERT IGNORE answers with its default, the FOR UPDATE
     * read-back returns the unclaimed sentinel (user_id NULL ⇒ I hold it), and
     * the users-emptiness probe answers EMPTY (fresh install).
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
