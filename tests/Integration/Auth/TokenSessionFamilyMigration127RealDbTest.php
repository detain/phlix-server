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
 * The D1a parity cure legs (migration 128) pin the retention half the core
 * shipped without: the grants table carries each mint's OWN baked expiry,
 * the write-time sweep retires exactly the arms TokenSessionRepository
 * documents (never the consumed-but-unexpired theft sensor, never a live
 * grant inside its grace), the expiry column and its sweep index ship in
 * the DDL fingerprint, replay of 128 is an idempotent no-op, and the
 * sessions list is bounded to MAX_LISTED_SESSIONS rows — newest activity
 * first, the overflow row excluded by execution (201 rows seeded, exactly
 * 200 returned), not by prose.
 *
 * The SRV-S16 legs pin the two halves of grant-consume atomicity and the
 * aged-revoked family sweep on real rows: markGrantConsumed() answers true
 * for exactly ONE of two consumes of the same jti (the atomic arbiter the
 * lost-race arm in AuthManager re-adjudicates on), and the per-user sweep at
 * createSession() retires only the login user's families revoked past the
 * derived horizon, deleting their grants BEFORE the family (a family whose
 * grants overflow one batch survives to the next sweep, so the FK cascade is
 * never the bound).
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
    private const MIGRATION_128 = '128_auth_refresh_grants_expiry.sql';

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
     * Apply the REAL migration-128 file (same isolated-fixDir idiom) and
     * return the runner report.
     *
     * @return array{applied: list<string>, notes: list<string>, errors: list<string>, skipped_count: int}
     */
    private function apply128(): array
    {
        $fixDir = sys_get_temp_dir() . '/phlix-s128-fix-' . bin2hex(random_bytes(3));
        mkdir($fixDir, 0777, true);
        $this->fixDirs[] = $fixDir;
        copy(dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION_128, $fixDir . '/' . self::MIGRATION_128);

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

    /**
     * Seed one sweep-arm grant row with every clock value written as a raw
     * DATABASE-CLOCK expression (the caller's strings) — never PHP-formatted
     * dates, so the sweep is tested against the same clock its comparisons
     * read (the two-clocks flaw the grace-window leg already forbids).
     */
    private function seedGrantArm(string $jti, string $sid, string $born, string $dies, string $consumed = 'NULL'): void
    {
        $this->db->query(
            'INSERT INTO auth_refresh_grants (jti, session_id, consumed_at, created_at, expires_at)'
            . " VALUES (?, ?, $consumed, $born, $dies)",
            [$jti, $sid]
        );
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
        $this->assertSame([], $this->apply128()['errors']);

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

        // D1a parity cure (128): the grants table gains its OWN lifetime
        // claim. Timestamp-nullable — a row that predates the cure (or a
        // mint whose token carried no exp claim) keeps NULL and rides the
        // created_at grandfather arm instead of sweeping instantly.
        $this->assertSame('timestamp', $cols['auth_refresh_grants.expires_at']['COLUMN_TYPE'] ?? null);
        $this->assertSame('YES', $cols['auth_refresh_grants.expires_at']['IS_NULLABLE'] ?? null);

        $indexes = [];
        $indexRows = $this->rows(
            'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS'
            . " WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('auth_token_sessions','auth_refresh_grants')",
            [$this->scratchDb]
        );
        foreach ($indexRows as $i) {
            $indexes[$i['INDEX_NAME']] = true;
        }
        $wantIndexes = [
            'idx_ats_user',
            'idx_ats_user_status',
            'idx_arg_session',
            'idx_arg_consumed',
            'idx_arg_expires_at',
        ];
        foreach ($wantIndexes as $want) {
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
        $this->assertSame([], $this->apply128()['errors'], 'the current schema carries expires_at');

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
        $this->assertSame([], $this->apply128()['errors'], 'the current schema carries expires_at');

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
        $this->assertSame([], $this->apply128()['errors'], 'the current schema carries expires_at');

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

    public function testTheExpiryMigrationAppliesReplaySafeAndIsLedgered(): void
    {
        $this->assertSame([], $this->apply127()['errors']);

        $report = $this->apply128();
        $this->assertSame([], $report['errors'], 'migration 128 must apply without errors');
        $this->assertNotEmpty($report['applied'], '128 must report as applied');

        $ledger = $this->rows("SELECT name FROM schema_migrations WHERE name LIKE '128_%'");
        $this->assertCount(1, $ledger, 'exactly one ledger row for 128');

        // Replay with the ledger row REMOVED: both guarded blocks (column
        // probe, index probe) must degrade to SELECT 1 — zero errors and, in
        // the decisive case, zero duplicates. A second expires_at column is
        // impossible by MySQL's own rules; a second identical index name is
        // entirely possible if a probe lies, so the STATISTICS count is taken
        // over ROWS, not distinct names.
        $this->db->query("DELETE FROM schema_migrations WHERE name LIKE '128_%'");
        $second = $this->apply128();
        $this->assertSame([], $second['errors'], 'replay of 128 must be an error-free no-op');

        $columns = $this->rows(
            'SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS'
            . " WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'auth_refresh_grants' AND COLUMN_NAME = 'expires_at'",
            [$this->scratchDb]
        );
        $this->assertSame(1, (int) ($columns[0]['n'] ?? 0), 'exactly one expires_at column after replay');

        $indexRows = $this->rows(
            'SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.STATISTICS'
            . " WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'auth_refresh_grants' AND INDEX_NAME = 'idx_arg_expires_at'",
            [$this->scratchDb]
        );
        $this->assertSame(1, (int) ($indexRows[0]['n'] ?? 0), 'replay must not duplicate the sweep index');
    }

    public function testTheSessionsListIsBoundedToTheCeiling(): void
    {
        $this->assertSame([], $this->apply127()['errors']);

        $u = 'user-' . bin2hex(random_bytes(6));
        $this->seedUser($u);
        $repo = new TokenSessionRepository($this->db);

        /** @var list<string> $ids */
        $ids = [];
        for ($i = 0; $i <= TokenSessionRepository::MAX_LISTED_SESSIONS; $i++) {
            $ids[] = $repo->createSession($u);
        }
        $this->assertCount(201, $ids, 'the seed must overshoot the ceiling by exactly one row');

        // Make the cut DECISIVE instead of a tie among same-second rows: one
        // session is aged on the database clock, so any correct newest-first
        // page must exclude it, and only it.
        $oldest = $ids[0];
        $this->db->query(
            'UPDATE auth_token_sessions SET last_used_at = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE id = ?',
            [$oldest]
        );

        $list = $repo->listActiveSessions($u);
        $this->assertCount(
            TokenSessionRepository::MAX_LISTED_SESSIONS,
            $list,
            'the store must never hand the endpoint an unbounded page'
        );

        $returned = array_column($list, 'id');
        $this->assertNotContains($oldest, $returned, 'the aged row is the overflow: it must be the one excluded');
        $this->assertContains($ids[200], $returned, 'newest activity wins the page');
    }

    public function testTheWriteTimeSweepRetiresOnlyRetiredGrants(): void
    {
        $this->assertSame([], $this->apply127()['errors']);
        $this->assertSame([], $this->apply128()['errors']);

        $u = 'user-' . bin2hex(random_bytes(6));
        $this->seedUser($u);
        $repo = new TokenSessionRepository($this->db);
        $sid = $repo->createSession($u);

        // Arm table — every row a premise of pruneExpired()'s contract, seeded
        // entirely on the DATABASE clock so the sweep is tested against the
        // same clock its comparisons read (PHP-side seeding would make the
        // window two clocks wide, exactly the flaw the grace-window leg
        // already forbids for findGrant).
        $goneExpired = str_pad('1', 32, '0', STR_PAD_LEFT);
        $keptInGrace = str_pad('2', 32, '0', STR_PAD_LEFT);
        $keptSensor = str_pad('3', 32, '0', STR_PAD_LEFT);
        $goneUnstamped = str_pad('4', 32, '0', STR_PAD_LEFT);
        $keptFreshUnstamped = str_pad('5', 32, '0', STR_PAD_LEFT);
        $keptStampedLive = str_pad('6', 32, '0', STR_PAD_LEFT);

        $this->seedGrantArm($goneExpired, $sid, 'NOW()', 'DATE_SUB(NOW(), INTERVAL 3 DAY)');
        $this->seedGrantArm($keptInGrace, $sid, 'NOW()', 'DATE_SUB(NOW(), INTERVAL 600 SECOND)');
        $this->seedGrantArm($keptSensor, $sid, 'NOW()', 'DATE_ADD(NOW(), INTERVAL 3600 SECOND)', 'NOW()');
        $this->seedGrantArm($goneUnstamped, $sid, 'DATE_SUB(NOW(), INTERVAL 400 DAY)', 'NULL');
        $this->seedGrantArm($keptFreshUnstamped, $sid, 'NOW()', 'NULL');
        $this->seedGrantArm(
            $keptStampedLive,
            $sid,
            'DATE_SUB(NOW(), INTERVAL 400 DAY)',
            'DATE_ADD(NOW(), INTERVAL 86400 SECOND)'
        );

        $triggerJti = str_pad('ff', 32, '0', STR_PAD_LEFT);
        $bakedExp = time() + 3600;
        $repo->recordGrant($triggerJti, $sid, $bakedExp);

        $present = [];
        foreach ($this->rows('SELECT jti, UNIX_TIMESTAMP(expires_at) AS exp FROM auth_refresh_grants') as $r) {
            $present[(string) $r['jti']] = $r['exp'];
        }

        $this->assertArrayNotHasKey($goneExpired, $present, 'expired beyond the grace: retired at the next write');
        $this->assertArrayHasKey($keptInGrace, $present, 'expiry inside the grace survives — the sweep is a janitor');
        $this->assertArrayHasKey($keptSensor, $present, 'consumed-but-UNexpired IS the reuse detector — never swept');
        $this->assertArrayNotHasKey($goneUnstamped, $present, 'NULL-expiry rows retire on the year horizon');
        $this->assertArrayHasKey($keptFreshUnstamped, $present, 'young pre-cure rows must survive grandfathering');
        $this->assertArrayHasKey($keptStampedLive, $present, 'a live expiry outranks an ancient created_at');

        $this->assertArrayHasKey($triggerJti, $present, 'recordGrant must still register the mint it was called for');
        $this->assertEqualsWithDelta(
            $bakedExp,
            (float) $present[$triggerJti],
            2.0,
            'the stamped expires_at must be the mint\'s OWN baked claim (FROM_UNIXTIME round-trip)'
        );
    }

    public function testOnlyOneOfTwoConsumesOfTheSameGrantWins(): void
    {
        $this->assertSame([], $this->apply127()['errors']);
        $this->assertSame([], $this->apply128()['errors']);

        $u = 'user-' . bin2hex(random_bytes(6));
        $this->seedUser($u);
        $repo = new TokenSessionRepository($this->db);
        $sid = $repo->createSession($u);
        $jti = str_repeat('c3', 16);
        $repo->recordGrant($jti, $sid, time() + 3600);

        $this->assertTrue($repo->markGrantConsumed($jti), 'the first consume flips the row');
        $this->assertFalse($repo->markGrantConsumed($jti), 'the second consume of the same jti LOSES');
        $this->assertFalse($repo->markGrantConsumed(str_repeat('d4', 16)), 'an unknown jti consumes nothing');

        $grant = $repo->findGrant($jti, 10);
        $this->assertIsArray($grant);
        $this->assertTrue($grant['consumed']);
        $this->assertTrue($grant['withinGrace'], 'the loser re-reads the winner inside the window');
    }

    public function testAgedRevokedFamiliesAreSweptPerUserInOrder(): void
    {
        $this->assertSame([], $this->apply127()['errors']);
        $this->assertSame([], $this->apply128()['errors']);

        $u = 'user-' . bin2hex(random_bytes(6));
        $other = 'user-' . bin2hex(random_bytes(6));
        $this->seedUser($u);
        $this->seedUser($other);
        $repo = new TokenSessionRepository($this->db);

        // Arm table, every clock value on the DATABASE clock. The horizon is
        // MAX_REFRESH_TTL (90d) + PRUNE_GRACE (1d) = 91 days past revoked_at.
        $agedRevoked = $repo->createSession($u);
        $youngRevoked = $repo->createSession($u);
        $agedActive = $repo->createSession($u);
        $revokedNoStamp = $repo->createSession($u);
        $foreignAged = $repo->createSession($other);
        $overflow = $repo->createSession($u);

        $age = static fn (string $interval): string => 'DATE_SUB(NOW(), INTERVAL ' . $interval . ')';
        $revoke = function (string $sid, ?string $when): void {
            $this->db->query(
                "UPDATE auth_token_sessions SET status = 'revoked', revoke_reason = 'logout', revoked_at = "
                . ($when ?? 'NULL') . ' WHERE id = ?',
                [$sid]
            );
        };
        $revoke($agedRevoked, $age('92 DAY'));
        $revoke($youngRevoked, $age('90 DAY'));
        $revoke($revokedNoStamp, null);
        $revoke($foreignAged, $age('400 DAY'));
        $revoke($overflow, $age('92 DAY'));
        $this->db->query(
            'UPDATE auth_token_sessions SET created_at = ' . $age('400 DAY') . ' WHERE id = ?',
            [$agedActive]
        );

        $this->seedGrantArm(str_pad('a1', 32, '0', STR_PAD_LEFT), $agedRevoked, 'NOW()', 'NULL');
        $this->seedGrantArm(str_pad('a2', 32, '0', STR_PAD_LEFT), $youngRevoked, 'NOW()', 'NULL');
        $this->seedGrantArm(str_pad('a3', 32, '0', STR_PAD_LEFT), $foreignAged, 'NOW()', 'NULL');
        // 1001 unstamped stragglers: one more than a sweep batch may remove.
        $this->db->query(
            'INSERT INTO auth_refresh_grants (jti, session_id)'
            . ' WITH d(x) AS (SELECT 0 UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3'
            . ' UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7'
            . ' UNION ALL SELECT 8 UNION ALL SELECT 9),'
            . ' n(i) AS (SELECT a.x * 100 + b.x * 10 + c.x FROM d a, d b, d c UNION ALL SELECT 1000)'
            . " SELECT LPAD(CONCAT('b', i), 32, '0'), ? FROM n",
            [$overflow]
        );

        $count = fn (string $sid): int => (int) ($this->rows(
            'SELECT COUNT(*) AS n FROM auth_refresh_grants WHERE session_id = ?',
            [$sid]
        )[0]['n'] ?? -1);
        $families = function (): array {
            return array_map(
                static fn (array $r): string => (string) $r['id'],
                $this->rows('SELECT id FROM auth_token_sessions')
            );
        };

        // Sweep 1 rides the login of $u. The overflow family's pick holds two
        // aged-revoked ids; the batch-bounded grant DELETE removes 1000 rows
        // in total across them, so at least one grant survives and the family
        // that still holds it must survive too.
        $fresh = $repo->createSession($u);
        $this->assertTrue($repo->isSessionActive($fresh), 'the login still mints its family');

        $after1 = $families();
        $this->assertContains($youngRevoked, $after1, 'revoked inside the 91-day horizon is kept');
        $this->assertContains($agedActive, $after1, 'an ancient ACTIVE family is not this sweep\'s business');
        $this->assertContains($revokedNoStamp, $after1, 'a revoked row with no revoked_at is never guessed at');
        $this->assertContains($foreignAged, $after1, 'the sweep is scoped to the login user only');
        $this->assertSame(1, $count($foreignAged), 'a foreign family keeps its grants');
        $this->assertSame(1, $count($youngRevoked));
        $remaining = $count($overflow) + $count($agedRevoked);
        $this->assertSame(2, $remaining, 'one batch (1000 grants) left exactly 1002 - 1000 stragglers');
        foreach ([$overflow, $agedRevoked] as $sid) {
            $this->assertSame(
                $count($sid) > 0,
                in_array($sid, $after1, true),
                'a family survives the batch exactly while it still holds grants'
            );
        }

        // Sweep 2 (the next login) finishes the ordered job.
        $repo->createSession($u);
        $after2 = $families();
        $this->assertNotContains($agedRevoked, $after2, 'aged-revoked family retired');
        $this->assertNotContains($overflow, $after2, 'the overflow family retires on the next sweep');
        $this->assertSame(0, $count($overflow));
        $this->assertSame(0, $count($agedRevoked));
        $this->assertContains($youngRevoked, $after2);
        $this->assertContains($foreignAged, $after2);
        $this->assertContains($agedActive, $after2);
        $this->assertContains($revokedNoStamp, $after2);
    }
}
