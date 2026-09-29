<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Common\Database\MigrationRunner;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * H-1 rework (security review 2026-09-29) — real-MySQL proof that the
 * first-admin election cannot be taken over on an UPGRADED install, and that
 * migration 109 backfills the sentinel exactly as its header promises.
 *
 * ## Why real MySQL is the only venue (S345 rule 2 — the real artefact)
 *
 * The exploit is a DATA-STATE interaction between three real tables: the
 * virgin `first_admin_election` a 108-only upgrade leaves behind, rows in
 * `users` created outside register() (CLI / admin UI — none of which stamp
 * the sentinel), and the transactional `INSERT IGNORE … SELECT … FOR UPDATE`
 * serialisation the election rides. A mocked Connection serves whatever the
 * stub says and models none of it; the 109 backfill's INSERT-SELECT gating,
 * COALESCE stamp choice, NULL-row healing and replay-safety are live-server
 * DML semantics. This suite therefore builds throwaway `phlix_h1rw_*`
 * DATABASES through the production {@see MigrationRunner} (the S114/106-test
 * pattern), runs the REAL `AuthManager::register()` against them, and drops
 * the scratch DB in tearDown — never mutating shared `phlix_test` tables.
 *
 * Scenarios:
 *  1. THE EXPLOIT (red before the fix): upgraded shape (CLI admin seeded,
 *     sentinel virgin) → register() must land as a PLAIN user, leave the
 *     unstamped sentinel for 109 to heal, and log the suppression.
 *  2. Upgraded shape + 109: sentinel stamped with the EARLIEST admin (004's
 *     ordering precedent, not MIN(id)); a later register() is refused admin.
 *  3. Users without any admin: 109 writes the nil-UUID marker — the election
 *     closes without impersonating a non-admin — register() stays plain.
 *  4. Fresh install: 109 no-ops (NO sentinel row minted while `users` is
 *     empty), the first legitimate register() still wins, exactly one admin
 *     ever exists.
 *  5. Forced replay of 109 (ledger row deleted): idempotent, and a
 *     winner-stamped sentinel is NEVER clobbered, even when an older admin
 *     exists in `users`.
 */
final class FirstAdminElectionBackfill109RealDbTest extends TestCase
{
    use RequiresRealDatabase;

    private const SCHEMA_MIGRATIONS = [
        '001_initial_schema.sql',
        '004_admin_user_flag.sql',
        '037_users_status.sql',
        '107_users_tokens_not_valid_after.sql',
        '108_first_admin_election.sql',
    ];

    private const BACKFILL_MIGRATION = '109_first_admin_election_backfill.sql';

    /** The non-impersonating "election closed" marker 109 stamps when users exist with no admin. */
    private const NIL_MARKER = '00000000-0000-0000-0000-000000000000';

    private ?Connection $admin = null;

    private ?Connection $db = null;

    private string $scratchDb = '';

    private string $fixDir = '';

    /** @var list<string> log dirs minted by silentLogger(), removed in tearDown(). */
    private array $mintedLogDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // S126 gate: skip on genuine absence, LOUD on reachable-but-unusable.
        $this->requireHealthyDatabase('skipping the H-1-rework migration-109 upgraded-install proof. Runs in CI.');

        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $rand = bin2hex(random_bytes(6));
        $this->scratchDb = 'phlix_h1rw_' . $rand;

        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);

        $this->fixDir = sys_get_temp_dir() . '/phlix-h1rw-fix-' . $rand;
        mkdir($this->fixDir, 0o755, true);
        foreach (self::SCHEMA_MIGRATIONS as $file) {
            copy($this->migrationPath($file), $this->fixDir . '/' . $file);
        }

        $result = $this->runner()->run();
        $this->assertSame(
            [],
            $result['errors'],
            'the schema migrations must apply cleanly on a fresh database: '
            . implode('; ', $result['errors'])
        );
    }

    protected function tearDown(): void
    {
        if ($this->fixDir !== '' && is_dir($this->fixDir)) {
            foreach ((array) glob($this->fixDir . '/*') as $file) {
                if (is_string($file)) {
                    unlink($file);
                }
            }
            rmdir($this->fixDir);
        }

        foreach ($this->mintedLogDirs as $dir) {
            // S439: silentLogger() mints a fresh dir per call — sweep them all.
            @unlink($dir . '/test.log');
            @rmdir($dir);
        }
        $this->mintedLogDirs = [];

        if ($this->admin !== null && $this->scratchDb !== '') {
            $this->admin->query('DROP DATABASE IF EXISTS `' . $this->scratchDb . '`');
        }

        $this->db = null;
        $this->admin = null;
        $this->scratchDb = '';
        $this->fixDir = '';

        parent::tearDown();
    }

    public function test_upgraded_shape_register_through_virgin_sentinel_does_not_elect_admin(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        // The upgraded shape: a CLI-created admin owns the box, `users` is
        // non-empty, and migration 108's sentinel table is VIRGIN (109 has not
        // run). Pre-fix, the next register() self-elected ACTIVE ADMIN here.
        $this->seedUser('cliadm01-0000-0000-0000-000000000001', 'cliadmin', 'cli-admin@example.com', 1, '2020-01-01 00:00:01');

        $logDir = $this->logDir();
        $manager = $this->authManager($logDir);
        $result = $manager->register('intruder', 'intruder@example.com', 'topsecret123');

        $this->assertSame('active', (string) ($result['user']['status'] ?? ''), 'open-mode signup still succeeds');
        $this->assertSame(
            0,
            (int) $this->userColumn($result['user']['id'], 'is_admin'),
            'THE EXPLOIT: a virgin 108 sentinel must never mint an admin on an upgraded install'
        );
        $this->assertSame(
            1,
            (int) $db->query('SELECT COUNT(*) AS c FROM users WHERE is_admin = 1')[0]['c'],
            'the CLI admin must remain the ONLY admin'
        );

        // The losing election commits its INSERT IGNORE claim as an unstamped
        // residue (documented on claimFirstAdminElection) — 109's UPDATE arm is
        // what heals this row; re-running register() keeps re-hitting the
        // emptiness probe, so the residue is inert.
        $this->assertSame(
            null,
            $this->sentinelUserId(),
            'the suppressed election must leave the sentinel UNSTAMPED, not fake a winner'
        );
        $this->assertStringContainsString(
            'First-admin election suppressed',
            (string) file_get_contents($logDir . '/test.log'),
            'the suppression must be loudly logged for the operator (points at 109)'
        );
    }

    public function test_backfill_stamps_earliest_admin_and_register_is_refused_admin(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        // 004's ordering precedent: the EARLIEST admin by (created_at, id) is
        // the established owner — not MIN(id), not the earliest user overall.
        $this->seedUser('plnold01-0000-0000-0000-000000000001', 'oldestplain', 'oldest@example.com', 0, '2019-01-01 00:00:01');
        $this->seedUser('admear01-0000-0000-0000-000000000001', 'earlyadmin', 'early-admin@example.com', 1, '2020-01-01 00:00:01');
        $this->seedUser('admlat01-0000-0000-0000-000000000001', 'lateadmin', 'late-admin@example.com', 1, '2021-01-01 00:00:01');

        $this->applyBackfill();

        $this->assertSame(
            'admear01-0000-0000-0000-000000000001',
            $this->sentinelUserId(),
            '109 must record the earliest established admin as the closed election winner'
        );

        $manager = $this->authManager($this->logDir());
        $result = $manager->register('intruder', 'intruder@example.com', 'topsecret123');
        $this->assertSame(
            0,
            (int) $this->userColumn($result['user']['id'], 'is_admin'),
            'even the post-109 stamped path must keep later registrations plain users'
        );
    }

    public function test_backfill_marks_nil_when_users_exist_without_any_admin(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        // A CLI-created non-admin (user:create without --admin) is the other
        // upgraded shape. The nil marker CLOSES the election without
        // impersonating the non-admin as its winner.
        $this->seedUser('plnonly1-0000-0000-0000-000000000001', 'plainuser', 'plain@example.com', 0, '2020-06-01 00:00:01');

        $this->applyBackfill();

        $this->assertSame(
            self::NIL_MARKER,
            $this->sentinelUserId(),
            'no admin existed: the marker must close the election, not name a fake winner'
        );

        $manager = $this->authManager($this->logDir());
        $result = $manager->register('intruder', 'intruder@example.com', 'topsecret123');
        $this->assertSame(0, (int) $this->userColumn($result['user']['id'], 'is_admin'));
    }

    public function test_backfill_noops_on_fresh_install_and_first_register_still_wins_alone(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        // 108 + 109 applied up-front (the fresh-install order): NO sentinel
        // row may be minted while `users` is empty — the election must stay
        // open for the first real registration.
        $this->applyBackfill();
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) AS c FROM first_admin_election')[0]['c']);

        $manager = $this->authManager($this->logDir());
        $first = $manager->register('rootuser', 'root@example.com', 'topsecret123');
        $this->assertSame(1, (int) $this->userColumn($first['user']['id'], 'is_admin'), 'fresh-install first user still self-elects');
        $this->assertSame((string) $first['user']['id'], $this->sentinelUserId());

        $second = $manager->register('bobuser', 'bob@example.com', 'topsecret123');
        $this->assertSame(0, (int) $this->userColumn($second['user']['id'], 'is_admin'), 'exactly one winner, ever');
        $this->assertSame((string) $first['user']['id'], $this->sentinelUserId(), 'the winner stamp survives the second registration');
    }

    public function test_forced_replay_is_idempotent_and_never_clobbers_a_stamped_winner(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        // A legitimately-elected winner W, plus an OLDER admin row that a
        // naive replay could promote in its place. 109 must: skip the INSERT
        // (row exists), skip the UPDATE (user_id not NULL), and re-run cleanly
        // once its ledger row is deleted (true statement-level replay).
        $this->seedUser('admold01-0000-0000-0000-000000000001', 'oldadmin', 'old-admin@example.com', 1, '2019-01-01 00:00:01');
        $db->query('INSERT INTO first_admin_election (id, user_id) VALUES (1, ?)', ['wnr00001-0000-0000-0000-000000000001']);

        $this->applyBackfill();
        $this->assertSame('wnr00001-0000-0000-0000-000000000001', $this->sentinelUserId(), 'a stamped winner is never rewritten');

        $db->query("DELETE FROM schema_migrations WHERE name LIKE '109_%'");
        copy($this->migrationPath(self::BACKFILL_MIGRATION), $this->fixDir . '/' . self::BACKFILL_MIGRATION);
        $second = $this->runner()->run();
        $this->assertSame([], $second['errors'], 'replaying 109 against a migrated database must be a SUCCESS');
        $this->assertSame('wnr00001-0000-0000-0000-000000000001', $this->sentinelUserId());
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) AS c FROM first_admin_election')[0]['c']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function runner(): MigrationRunner
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return new MigrationRunner(static fn (): Connection => $db, $this->fixDir);
    }

    /** Copy 109 into the fix dir and run the production MigrationRunner over it. */
    private function applyBackfill(): void
    {
        copy($this->migrationPath(self::BACKFILL_MIGRATION), $this->fixDir . '/' . self::BACKFILL_MIGRATION);

        $result = $this->runner()->run();
        $this->assertSame(
            [],
            $result['errors'],
            'migration 109 must apply cleanly: ' . implode('; ', $result['errors'])
        );
    }

    /**
     * Seed a `users` row in the exact shape the CLI/admin paths create (the
     * paths that never stamped the sentinel — the whole point of the audit):
     * raw INSERT, explicit created_at so ordering claims are deterministic.
     */
    private function seedUser(string $id, string $username, string $email, int $isAdmin, string $createdAt): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query(
            'INSERT INTO users (id, username, email, password_hash, display_name, is_admin, created_at)'
            . " VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $username,
                $email,
                password_hash('seeded-by-test-not-loginable', PASSWORD_ARGON2ID),
                $username,
                $isAdmin,
                $createdAt,
            ]
        );
    }

    private function authManager(string $logDir): AuthManager
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return new AuthManager(
            new UserRepository($db),
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger($logDir),
            null,
            $db,
        );
    }

    /** @return mixed the requested column of a user row */
    private function userColumn(string $userId, string $column): mixed
    {
        $db = $this->db;
        $this->assertNotNull($db);
        $this->assertMatchesRegularExpression('/^[a-z_]+$/', $column);

        $rows = $db->query("SELECT {$column} FROM users WHERE id = ?", [$userId]);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows, "user {$userId} must exist to read {$column}");

        return $rows[0][$column];
    }

    private function sentinelUserId(): ?string
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $rows = $db->query('SELECT user_id FROM first_admin_election WHERE id = 1');
        $this->assertIsArray($rows);
        if ($rows === []) {
            return null;
        }

        $userId = $rows[0]['user_id'];

        return $userId === null ? null : (string) $userId;
    }

    private function migrationPath(string $basename): string
    {
        return dirname(__DIR__, 3) . '/migrations/' . $basename;
    }

    private function logDir(): string
    {
        $tmp = sys_get_temp_dir() . '/phlix_h1rw_test_' . uniqid('', true);
        mkdir($tmp, 0775, true);
        $this->mintedLogDirs[] = $tmp;

        return $tmp;
    }

    private function silentLogger(string $dir): StructuredLogger
    {
        // Stream handler onto a per-test file: the suppression assertion in
        // scenario 1 reads THIS file back — the log line is part of the proof.
        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => ['type' => 'stream', 'path' => $dir . '/test.log', 'level' => 'debug'],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ]);
    }
}
