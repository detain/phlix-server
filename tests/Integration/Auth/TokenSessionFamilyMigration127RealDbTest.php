<?php

/**
 * CP-029 / D-1a (real-MySQL proof): migration 127 + TokenSessionRepository
 * executed against a scratch InnoDB database.
 *
 * The unit cohort (tests/Unit/Auth/TokenSessionFamilyTest.php) proves the
 * manager-side decision table against a mocked store. THIS file proves the
 * two things a mock cannot:
 *
 *  1. the DDL ships what its comments promise — widths, ENUM domains, the
 *     four indexes the read paths depend on, and BOTH cascades the ownership
 *     cleanup relies on (a CHAR(32) jti column would silently truncate the
 *     32-hex ids at the exact row the reuse detector must find);
 *  2. the grace window is a DATABASE-CLOCK fact — `consumed_at` seeded at
 *     NOW()-2s answers withinGrace=true and at NOW()-30s answers false,
 *     against the same second `markGrantConsumed()` writes with. A PHP-side
 *     probe would make the window two clocks wide on the resident worker;
 *     the falsifying pair (same query, only time differs) proves it is not.
 *
 * The ceremony legs additionally run the REAL repository through the whole
 * D-1a story (mint → consume → replay-as-reuse revoke → isolation → nuclear
 * → ownership) so the SQL strings themselves — not just their mocked echo —
 * execute at least once here.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Auth;

use Phlix\Auth\TokenSessionRepository;
use Phlix\Common\Database\MigrationRunner;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * @internal
 */
final class TokenSessionFamilyMigration127RealDbTest extends TestCase
{
    use RequiresRealDatabase;

    private const MIGRATION = '127_auth_token_sessions.sql';

    private ?Connection $db = null;
    private ?string $scratchDb = null;
    /** @var list<string> */
    private array $fixDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireHealthyDatabase('skipping the CP-029 migration-127 real-MySQL proof. Runs in CI.');

        $baseDb = $_ENV['DB_DATABASE'] ?? 'phlix_test';
        $this->scratchDb = 'phlix_s127_' . bin2hex(random_bytes(4));

        $admin = new PhlixMySQLConnection(
            IntegrationDbGuard::host(),
            IntegrationDbGuard::port(),
            $_ENV['DB_USER'] ?? 'root',
            $_ENV['DB_PASSWORD'] ?? 'root',
            (string) $baseDb
        );
        $admin->query('CREATE DATABASE `' . $this->scratchDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->db = new PhlixMySQLConnection(
            IntegrationDbGuard::host(),
            IntegrationDbGuard::port(),
            $_ENV['DB_USER'] ?? 'root',
            $_ENV['DB_PASSWORD'] ?? 'root',
            (string) $this->scratchDb
        );

        // The FK target: migration 127 references users(id) only, so the
        // fixture DDL carries exactly that one first-party table (112-era
        // subset idiom — the full 001 corpus is not this proof's concern).
        $this->db->query(
            'CREATE TABLE users ('
            . " id CHAR(36) NOT NULL PRIMARY KEY,"
            . " username VARCHAR(255) NOT NULL,"
            . " status ENUM('active','disabled') NOT NULL DEFAULT 'active',"
            . " tokens_not_valid_after DATETIME NULL DEFAULT NULL,"
            . " created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
            . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    protected function tearDown(): void
    {
        if ($this->scratchDb !== null) {
            $admin = new PhlixMySQLConnection(
                IntegrationDbGuard::host(),
                IntegrationDbGuard::port(),
                $_ENV['DB_USER'] ?? 'root',
                $_ENV['DB_PASSWORD'] ?? 'root',
                (string) ($_ENV['DB_DATABASE'] ?? 'phlix_test')
            );
            $admin->query('DROP DATABASE IF EXISTS `' . $this->scratchDb . '`');
            $this->scratchDb = null;
        }
        foreach ($this->fixDirs as $dir) {
            foreach (glob($dir . '/*.sql') ?: [] as $f) {
                unlink($f);
            }
            @rmdir($dir);
        }
        $this->fixDirs = [];

        parent::tearDown();
    }

    /**
     * Apply the REAL migration file (copied into an isolated fixDir, 112
     * idiom) and return the runner report.
     *
     * @return array{applied: list<string>, notes: list<string>, errors: list<string>, skipped_count: int}
     */
    private function apply127(): array
    {
        $fixDir = sys_get_temp_dir() . '/phlix-s127-fix-' . bin2hex(random_bytes(3));
        mkdir($fixDir, 0777, true);
        $this->fixDirs[] = $fixDir;
        copy(dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION, $fixDir . '/' . self::MIGRATION);

        $db = $this->db;
        if (!$db instanceof Connection) {
            $this->fail('setUp must leave a live scratch connection before applying migrations.');
        }
        $runner = new MigrationRunner(static fn (): Connection => $db, $fixDir);

        return $runner->run();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $binds = []): array
    {
        $result = $this->db->query($sql, $binds);

        return is_array($result) ? $result : [];
    }

    private function seedUser(string $id): void
    {
        $this->db->query('INSERT INTO users (id, username) VALUES (?, ?)', [$id, 'u-' . substr($id, 0, 8)]);
    }

    public function testTheMigrationAppliesCleanAndIsLedgered(): void
    {
        $report = $this->apply127();

        $this->assertSame([], $report['errors'], 'migration 127 must apply without errors');
        $this->assertNotEmpty($report['applied'], '127 must report as applied');

        $ledger = $this->rows("SELECT name FROM schema_migrations WHERE name LIKE '127_%'");
        $this->assertCount(1, $ledger, 'exactly one ledger row for 127');
    }

    public function testTheDdlShipsThePinnedShape(): void
    {
        $this->assertSame([], $this->apply127()['errors']);

        $cols = [];
        $colRows = $this->rows(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA'
            . ' FROM INFORMATION_SCHEMA.COLUMNS'
            . " WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('auth_token_sessions','auth_refresh_grants')"
            . ' ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$this->scratchDb]
        );
        foreach ($colRows as $c) {
            $cols[$c['TABLE_NAME'] . '.' . $c['COLUMN_NAME']] = $c;
        }

        // Widths that must EXACTLY fit the ids the store writes: a 36-CHAR
        // uuid dotted hex and a 32-hex jti. Wrong width here = silent
        // truncation = unknown-grant fail-closed on live tokens.
        $this->assertSame('char(36)', $cols['auth_token_sessions.id']['COLUMN_TYPE'] ?? null);
        $this->assertSame('char(36)', $cols['auth_token_sessions.user_id']['COLUMN_TYPE'] ?? null);
        $this->assertSame("enum('active','revoked')", $cols['auth_token_sessions.status']['COLUMN_TYPE'] ?? null);
        $this->assertSame('active', $cols['auth_token_sessions.status']['COLUMN_DEFAULT'] ?? null);
        $this->assertSame('NO', $cols['auth_token_sessions.status']['IS_NULLABLE']);
        $this->assertSame('char(32)', $cols['auth_refresh_grants.jti']['COLUMN_TYPE'] ?? null);
        $this->assertSame('char(36)', $cols['auth_refresh_grants.session_id']['COLUMN_TYPE'] ?? null);
        $this->assertSame('YES', $cols['auth_refresh_grants.consumed_at']['IS_NULLABLE']);

        $indexes = [];
        $indexRows = $this->rows(
            'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS'
            . " WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('auth_token_sessions','auth_refresh_grants')",
            [$this->scratchDb]
        );
        foreach ($indexRows as $i) {
            $indexes[$i['INDEX_NAME']] = true;
        }
        foreach (['idx_ats_user', 'idx_ats_user_status', 'idx_arg_session', 'idx_arg_consumed'] as $want) {
            $this->assertArrayHasKey($want, $indexes, "index {$want} must ship — read paths depend on it");
        }

        $fks = [];
        $fkRows = $this->rows(
            'SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, r.REFERENCED_TABLE_NAME, r.DELETE_RULE'
            . ' FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r'
            . ' JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS k ON k.CONSTRAINT_NAME = r.CONSTRAINT_NAME'
            . '  AND k.TABLE_NAME = r.TABLE_NAME'
            . " WHERE r.CONSTRAINT_SCHEMA = ? AND k.TABLE_NAME IN ('auth_token_sessions','auth_refresh_grants')",
            [$this->scratchDb]
        );
        foreach ($fkRows as $f) {
            $fks[$f['TABLE_NAME']] = $f;
        }
        $this->assertSame('users', $fks['auth_token_sessions']['REFERENCED_TABLE_NAME'] ?? null);
        $this->assertSame('CASCADE', strtoupper((string) ($fks['auth_token_sessions']['DELETE_RULE'] ?? '')));
        $this->assertSame('auth_token_sessions', $fks['auth_refresh_grants']['REFERENCED_TABLE_NAME'] ?? null);
        $this->assertSame('CASCADE', strtoupper((string) ($fks['auth_refresh_grants']['DELETE_RULE'] ?? '')));
    }

    public function testReplayIsAnIdempotentNoOp(): void
    {
        $first = $this->apply127();
        $this->assertSame([], $first['errors']);

        // The ledger row for this file is REMOVED, then the file re-runs: the
        // CREATE TABLE IF NOT EXISTS contract must make the second execution a
        // no-op with zero errors and zero duplicate tables.
        $this->db->query("DELETE FROM schema_migrations WHERE name LIKE '127_%'");
        $second = $this->apply127();
        $this->assertSame([], $second['errors'], 'replay must be error-free');

        $tables = $this->rows(
            'SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (\'auth_token_sessions\',\'auth_refresh_grants\')',
            [$this->scratchDb]
        );
        $this->assertSame(2, (int) ($tables[0]['n'] ?? 0), 'exactly the two tables, no duplicates');
    }

    public function testTheGraceWindowIsADatabaseClockFact(): void
    {
        $this->assertSame([], $this->apply127()['errors']);

        $user = 'user-' . bin2hex(random_bytes(6));
        $this->seedUser($user);
        $repo = new TokenSessionRepository($this->db);

        $sid = $repo->createSession($user);
        $live = str_repeat('a1', 16);
        $fresh = str_repeat('b2', 16);
        $stale = str_repeat('c3', 16);
        $repo->recordGrant($live, $sid);
        $repo->recordGrant($fresh, $sid);
        $repo->recordGrant($stale, $sid);

        // Consume the latter two on the DATABASE clock, 2s and 30s ago.
        $this->db->query(
            'UPDATE auth_refresh_grants SET consumed_at = DATE_SUB(NOW(), INTERVAL 2 SECOND) WHERE jti = ?',
            [$fresh]
        );
        $this->db->query(
            'UPDATE auth_refresh_grants SET consumed_at = DATE_SUB(NOW(), INTERVAL 30 SECOND) WHERE jti = ?',
            [$stale]
        );

        $grantLive = $repo->findGrant($live, 10);
        $this->assertIsArray($grantLive);
        $this->assertFalse($grantLive['consumed'], 'un-consumed token is live');
        $this->assertFalse($grantLive['withinGrace'], 'the within-grace flag needs a consumption stamp');

        $grantFresh = $repo->findGrant($fresh, 10);
        $this->assertIsArray($grantFresh);
        $this->assertTrue($grantFresh['consumed']);
        $this->assertTrue(
            $grantFresh['withinGrace'],
            'consumed 2 DB-seconds ago must sit INSIDE the 10s grace — if the probe read'
            . ' a PHP clock against a DB clock this exact pair of seeds could not exist'
        );

        $grantStale = $repo->findGrant($stale, 10);
        $this->assertIsArray($grantStale);
        $this->assertTrue($grantStale['consumed']);
        $this->assertFalse(
            $grantStale['withinGrace'],
            'consumed 30 DB-seconds ago is REUSE territory — same query, only the seeded'
            . ' time differs; the two verdicts falsify any fixed-answer probe'
        );

        $this->assertSame($sid, $grantStale['sessionId']);
        $this->assertSame('active', $grantStale['sessionStatus']);
        $this->assertNull($repo->findGrant(str_repeat('de', 16), 10), 'unknown jti fails closed as null');
    }

    public function testTheFullCeremonyRunsOnRealRows(): void
    {
        $this->assertSame([], $this->apply127()['errors']);

        $user = 'user-' . bin2hex(random_bytes(6));
        $this->seedUser($user);
        $repo = new TokenSessionRepository($this->db);

        $sid = $repo->createSession($user);
        $this->assertTrue($repo->isSessionActive($sid));

        $jti = str_repeat('f0', 16);
        $repo->recordGrant($jti, $sid);
        $grant = $repo->findGrant($jti, 10);
        $this->assertIsArray($grant);
        $this->assertFalse($grant['consumed']);

        $repo->markGrantConsumed($jti);
        $consumed = $repo->findGrant($jti, 10);
        $this->assertIsArray($consumed);
        $this->assertTrue($consumed['consumed']);
        $this->assertTrue($consumed['withinGrace'], 'rotation-then-immediate-replay lands in-window');

        // REUSE verdict (outside the window) is proven by the clock leg above;
        // here the revoke itself executes on real rows:
        $repo->revokeSession($sid, 'refresh_token_reuse');
        $this->assertFalse($repo->isSessionActive($sid));

        $row = $this->rows(
            "SELECT status, revoke_reason, revoked_at FROM auth_token_sessions WHERE id = ?",
            [$sid]
        )[0] ?? [];
        $this->assertSame('revoked', $row['status'] ?? null);
        $this->assertSame('refresh_token_reuse', $row['revoke_reason'] ?? null);
        $this->assertNotNull($row['revoked_at'] ?? null);

        // The joined family status rides into the next read too — a presentation
        // of this grant from here on sees sessionStatus='revoked'.
        $after = $repo->findGrant($jti, 10);
        $this->assertIsArray($after);
        $this->assertSame('revoked', $after['sessionStatus']);

        // Idempotency at SQL level: a second revoke rewrites neither stamp nor
        // reason (double sign-out must not shuffle the audit evidence).
        $before = $row;
        $repo->revokeSession($sid, 'second_thought');
        $again = $this->rows(
            'SELECT status, revoke_reason, revoked_at FROM auth_token_sessions WHERE id = ?',
            [$sid]
        )[0] ?? [];
        $this->assertSame($before['revoke_reason'], $again['revoke_reason']);
        $this->assertSame($before['revoked_at'], $again['revoked_at']);
    }

    public function testFamilyIsolationTheNuclearOptionAndOwnership(): void
    {
        $this->assertSame([], $this->apply127()['errors']);

        $u1 = 'user-' . bin2hex(random_bytes(6));
        $u2 = 'user-' . bin2hex(random_bytes(6));
        $this->seedUser($u1);
        $this->seedUser($u2);
        $repo = new TokenSessionRepository($this->db);

        $a = $repo->createSession($u1);
        $b = $repo->createSession($u1);
        $c = $repo->createSession($u2);

        $repo->revokeSession($a, 'logout');
        $this->assertFalse($repo->isSessionActive($a), 'per-device blade ends exactly one family');
        $this->assertTrue($repo->isSessionActive($b), 'sibling family on the SAME account survives');
        $this->assertTrue($repo->isSessionActive($c));

        $listed = $repo->listActiveSessions($u1);
        $this->assertCount(1, $listed);
        $this->assertSame($b, $listed[0]['id'], 'active-only list, revoked family stays out');

        $this->assertTrue($repo->sessionBelongsToUser($b, $u1));
        $this->assertFalse($repo->sessionBelongsToUser($b, $u2), 'foreign family = ownership false (404 arm)');
        $this->assertFalse($repo->sessionBelongsToUser('nope-not-a-family', $u1));

        $repo->revokeAllForUser($u1, 'password_changed');
        $this->assertFalse($repo->isSessionActive($b));
        $this->assertTrue($repo->isSessionActive($c), 'nuclear bump of u1 never reaches u2');
        $this->assertSame([], $repo->listActiveSessions($u1));
    }

    public function testListOrdersByLastActivityDesc(): void
    {
        $this->assertSame([], $this->apply127()['errors']);

        $u = 'user-' . bin2hex(random_bytes(6));
        $this->seedUser($u);
        $repo = new TokenSessionRepository($this->db);

        $old = $repo->createSession($u);
        $new = $repo->createSession($u);
        $this->db->query(
            'UPDATE auth_token_sessions SET last_used_at = DATE_SUB(NOW(), INTERVAL 600 SECOND) WHERE id = ?',
            [$old]
        );

        $ids = array_column($repo->listActiveSessions($u), 'id');
        $this->assertSame([$new, $old], $ids, 'most-recently-used family first');

        $repo->touchSession($old);
        $touched = $this->rows('SELECT last_used_at FROM auth_token_sessions WHERE id = ?', [$old])[0] ?? [];
        $seeded = $this->rows(
            'SELECT DATE_SUB(NOW(), INTERVAL 600 SECOND) AS t'
        )[0] ?? [];
        $this->assertGreaterThan(
            (string) ($seeded['t'] ?? ''),
            (string) ($touched['last_used_at'] ?? ''),
            'touchSession advances last_used_at on the database clock'
        );
    }

    public function testCascadeDeletesMirrorOwnershipCleanup(): void
    {
        $this->assertSame([], $this->apply127()['errors']);

        $u = 'user-' . bin2hex(random_bytes(6));
        $this->seedUser($u);
        $repo = new TokenSessionRepository($this->db);

        $sid = $repo->createSession($u);
        $repo->recordGrant(str_repeat('ab', 16), $sid);

        // Deleting the family row cascades its grants away...
        $this->db->query('DELETE FROM auth_token_sessions WHERE id = ?', [$sid]);
        $grants = $this->rows('SELECT COUNT(*) AS n FROM auth_refresh_grants');
        $this->assertSame(0, (int) ($grants[0]['n'] ?? 0), 'grants die with their family');

        // ...and deleting the USER (the account-deletion path) sweeps both.
        $sid2 = $repo->createSession($u);
        $repo->recordGrant(str_repeat('cd', 16), $sid2);
        $this->db->query('DELETE FROM users WHERE id = ?', [$u]);
        $sessions = $this->rows('SELECT COUNT(*) AS n FROM auth_token_sessions');
        $grants2 = $this->rows('SELECT COUNT(*) AS n FROM auth_refresh_grants');
        $this->assertSame(0, (int) ($sessions[0]['n'] ?? 0));
        $this->assertSame(0, (int) ($grants2[0]['n'] ?? 0));
    }
}
