<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Auth;

use Phlix\Auth\UserProfileManager;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * M-SERVER-83 on REAL MySQL: profile creation is serialized by the users-row
 * FOR UPDATE lock and its two write legs are one transaction.
 *
 * Venue shape cloned from the B2 auth-lock serialization proof
 * ({@see \Phlix\Tests\Integration\Admin\AdminSettingsAuthLockB2Test}): a
 * second connection with `innodb_lock_wait_timeout = 1` turns "blocked by the
 * lock" into a thrown 1205 inside one second — no sleeps are used as
 * synchronization anywhere in this file. Throwaway `phlix_srv83_*` scratch
 * DATABASE (S345 rule), dropped in tearDown.
 *
 * What each test proves (and why it is RED pre-cure, where the old create()
 * was a lock-free COUNT→cap→derive→INSERT→INSERT sequence on autocommit):
 *  - test_second_create_blocks_on_the_user_row_lock_and_then_hits_the_cap:
 *    the lock probe exists and blocks B while A's transaction is live; after
 *    A commits, B re-counts under the lock and the cap refuses the 6th
 *    profile. Pre-cure, B never touched a lock: it counted 4 concurrently
 *    and both writers landed (the exact race the review documented).
 *  - test_first_active_claim_cannot_be_double_claimed: the first-profile
 *    auto-activation is decided UNDER the serialization, so a racing second
 *    creator can never also write is_active = 1 (the S81 one-active law).
 *  - test_settings_leg_failure_rolls_back_the_profile_row: a trigger forces
 *    the profile_settings INSERT to fail; the user_profiles row must not
 *    survive it. Pre-cure (autocommit) the orphan row persisted.
 *  - test_unknown_user_is_still_the_fks_job_not_a_new_exception: the missing
 *    user row is not turned into a new exception type by the lock probe —
 *    the FK rejects the INSERT exactly as it always did.
 */
final class UserProfileCreateLockRace83RealDbTest extends TestCase
{
    use RequiresRealDatabase;

    private const USER_ID = '11111111-1111-4111-8111-111111111111';

    private ?PhlixMySQLConnection $admin = null;

    private string $scratchDb = '';

    private ?PhlixMySQLConnection $scratch = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireHealthyDatabase('skipping the M-SERVER-83 lock-serialization proof. Runs in CI.');

        $host     = IntegrationDbGuard::host();
        $port     = IntegrationDbGuard::port();
        $user     = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb   = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $this->scratchDb = 'phlix_srv83_' . bin2hex(random_bytes(6));

        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->scratch = $this->conn();

        // Minimal parent + the two child tables, DDL mirroring migration 002
        // columns create() actually touches (the child tables' definitions
        // are verbatim-relevant only in columns; defaults carried).
        $this->scratch()->query(
            'CREATE TABLE users ('
            . ' id CHAR(36) PRIMARY KEY'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->scratch()->query(
            'CREATE TABLE user_profiles ('
            . ' id CHAR(36) PRIMARY KEY,'
            . ' user_id CHAR(36) NOT NULL,'
            . ' name VARCHAR(100) NOT NULL,'
            . ' avatar_url VARCHAR(500) NULL,'
            . ' is_active BOOLEAN DEFAULT FALSE,'
            . ' is_admin BOOLEAN DEFAULT FALSE,'
            . ' created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,'
            . ' updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . ' FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,'
            . ' INDEX idx_user (user_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->scratch()->query(
            'CREATE TABLE profile_settings ('
            . ' id CHAR(36) PRIMARY KEY,'
            . ' profile_id CHAR(36) NOT NULL UNIQUE,'
            . " content_rating ENUM('G', 'PG', 'PG-13', 'R', 'NC-17', 'X', 'UNRATED') DEFAULT 'R',"
            . ' pin_hash VARCHAR(255) NULL,'
            . ' pin_required_for_admin BOOLEAN DEFAULT FALSE,'
            . ' max_daily_watch_time INT DEFAULT 0,'
            . ' allowed_genres JSON NULL,'
            . ' blocked_genres JSON NULL,'
            . ' allow_unrated BOOLEAN DEFAULT TRUE,'
            . ' FOREIGN KEY (profile_id) REFERENCES user_profiles(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->scratch()->query(
            'INSERT INTO users (id) VALUES (?)',
            [self::USER_ID]
        );
    }

    protected function tearDown(): void
    {
        if ($this->admin !== null && $this->scratchDb !== '') {
            $this->admin->query('DROP DATABASE IF EXISTS `' . $this->scratchDb . '`');
        }

        $this->admin     = null;
        $this->scratch   = null;
        $this->scratchDb = '';

        parent::tearDown();
    }

    private function scratch(): PhlixMySQLConnection
    {
        $scratch = $this->scratch;
        self::assertInstanceOf(PhlixMySQLConnection::class, $scratch, 'setUp must build the scratch connection');

        return $scratch;
    }

    /**
     * A fresh SESSION against the scratch database (each create() writer and
     * each racing writer needs its own connection to see real row locks).
     */
    private function conn(): PhlixMySQLConnection
    {
        return new PhlixMySQLConnection(
            IntegrationDbGuard::host(),
            IntegrationDbGuard::port(),
            (string) (getenv('DB_USER') ?: 'root'),
            (string) (getenv('DB_PASSWORD') ?: ''),
            $this->scratchDb
        );
    }

    public function test_second_create_blocks_on_the_user_row_lock_and_then_hits_the_cap(): void
    {
        // A is mid-create(): transaction open, users-row lock held, four
        // existing profiles counted. (Seed four finished rows first.)
        for ($i = 1; $i <= 4; $i++) {
            (new UserProfileManager($this->conn()))
                ->create(self::USER_ID, ['name' => 'Seed ' . $i]);
        }

        $connA = $this->conn();
        $connA->beginTrans();
        $connA->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [self::USER_ID]);

        // Writer B: the FULL cured create() must now block on the lock probe.
        $connB = $this->conn();
        $connB->query('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = false;
        try {
            (new UserProfileManager($connB))->create(self::USER_ID, ['name' => 'Racer']);
        } catch (Throwable $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout exceeded');
            if (!$blocked) {
                throw $e;
            }
        }
        self::assertTrue(
            $blocked,
            'B completed while A held the users-row lock — create() is not serialized (M-SERVER-83)',
        );

        // A finishes its own create under the SAME transaction (the
        // within-transaction entry, as the signup path uses), then commits.
        $fifth = (new UserProfileManager($connA))->createWithinTransaction(
            self::USER_ID,
            ['name' => 'A finishes']
        );
        $connA->commitTrans();
        self::assertNotSame('', $fifth);

        // B retries after the lock was released: it re-counts UNDER the lock,
        // sees 5, and is refused — the cap can no longer be undercut.
        $refused = null;
        try {
            (new UserProfileManager($connB))->create(self::USER_ID, ['name' => 'Racer again']);
        } catch (Throwable $e) {
            $refused = $e;
        }
        self::assertInstanceOf(\InvalidArgumentException::class, $refused);
        self::assertStringContainsString('Maximum number of profiles', $refused->getMessage());

        $row = $this->scratch()->query('SELECT COUNT(*) AS c FROM user_profiles');
        self::assertSame('5', (string) $this->scalar($row, 'c'));
    }

    public function test_first_active_claim_cannot_be_double_claimed(): void
    {
        $connA = $this->conn();
        $connA->beginTrans();
        $connA->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [self::USER_ID]);

        $first = (new UserProfileManager($connA))->createWithinTransaction(
            self::USER_ID,
            ['name' => 'First']
        );

        // B attempts the FIRST-profile create concurrently: blocked at the
        // lock (red pre-cure: no lock existed, so B also derived count 0 and
        // both rows landed is_active = 1 — the violated one-active law).
        $connB = $this->conn();
        $connB->query('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = false;
        try {
            (new UserProfileManager($connB))->create(self::USER_ID, ['name' => 'Rival']);
        } catch (Throwable $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout exceeded');
            if (!$blocked) {
                throw $e;
            }
        }
        self::assertTrue($blocked, 'rival first-creator was not serialized by the users-row lock');

        $connA->commitTrans();

        // Post-commit, B legitimately lands its own (not-first) profile.
        (new UserProfileManager($connB))->create(self::USER_ID, ['name' => 'Second']);

        $active = $this->scratch()->query(
            'SELECT COUNT(*) AS c FROM user_profiles WHERE is_active = TRUE'
        );
        self::assertSame('1', (string) $this->scalar($active, 'c'));
        $who = $this->scratch()->query(
            'SELECT id FROM user_profiles WHERE is_active = TRUE'
        );
        self::assertSame($first, (string) $this->scalar($who, 'id'));
    }

    public function test_settings_leg_failure_rolls_back_the_profile_row(): void
    {
        $this->scratch()->query(
            "CREATE TRIGGER force_settings_fail BEFORE INSERT ON profile_settings"
            . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced M-SERVER-83 failure'"
        );

        $failed = null;
        try {
            (new UserProfileManager($this->conn()))->create(self::USER_ID, ['name' => 'Halfway']);
        } catch (Throwable $e) {
            $failed = $e;
        }
        self::assertInstanceOf(Throwable::class, $failed, 'the forced settings failure must propagate');
        self::assertStringContainsString('forced M-SERVER-83 failure', $failed->getMessage());

        // Pre-cure this assert fails: the profile row survived on autocommit
        // while its settings row never existed.
        $profiles = $this->scratch()->query('SELECT COUNT(*) AS c FROM user_profiles');
        self::assertSame('0', (string) $this->scalar($profiles, 'c'));
    }

    public function test_unknown_user_is_still_the_fks_job_not_a_new_exception(): void
    {
        $failed = null;
        try {
            (new UserProfileManager($this->conn()))->create('ghost-user-id', ['name' => 'Orphan']);
        } catch (Throwable $e) {
            $failed = $e;
        }
        self::assertInstanceOf(Throwable::class, $failed);
        self::assertStringContainsString(
            'foreign key constraint fails',
            $failed->getMessage(),
            'the FK must stay the rejection point — the lock probe adds no new exception shape',
        );

        $profiles = $this->scratch()->query('SELECT COUNT(*) AS c FROM user_profiles');
        self::assertSame('0', (string) $this->scalar($profiles, 'c'));
    }

    /**
     * @param mixed $row
     */
    private function scalar(mixed $row, string $col): mixed
    {
        self::assertIsArray($row);
        $first = $row[0] ?? null;
        self::assertIsArray($first);

        return $first[$col] ?? null;
    }
}
