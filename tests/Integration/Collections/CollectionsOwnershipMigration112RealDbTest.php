<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Collections;

use Phlix\Common\Database\MigrationRunner;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Per-user collection ownership — real-MySQL proof that migration 112 adds
 * `collections.created_by`, indexes it, and backfills legacy rows with the
 * exact election the owner ruling specified (earliest ACTIVE admin, else
 * earliest user of any status, else the rows stay NULL), replay-idempotently.
 *
 * ## Why real MySQL is the only venue (S345 rule 2 — the real artefact)
 *
 * The INFORMATION_SCHEMA + PREPARE guards, the ENUM literal matching in the
 * election subqueries, the `ORDER BY created_at ASC, id ASC` tie-break, the
 * WHERE `created_by IS NULL` replay semantics, and the ledger over THIS file
 * are all live-server properties. A mocked `Connection` accepts everything and
 * models none of it — including shipping a typo that only errors 1054/1146 at
 * apply time on a real box.
 *
 * SAFETY: every destructive proof runs in a throwaway `phlix_s112_*` DATABASE
 * built by running the REAL migration 005 (the pre-ownership shape) and then
 * the REAL migration 112 through the production MigrationRunner (S114
 * pattern) — never against the shared `phlix_test` tables. tearDown drops the
 * scratch DB.
 */
final class CollectionsOwnershipMigration112RealDbTest extends TestCase
{
    use RequiresRealDatabase;

    private const MIGRATION_005 = '005_collections.sql';

    private const MIGRATION_112 = '112_collections_created_by.sql';

    /**
     * The exact `users` subset migration 112's election reads. The production
     * table carries more columns; none participate in the backfill, and
     * re-running all of 001+002+004+037 here would test THEIR shape, not 112's.
     */
    private const USERS_FIXTURE_DDL =
        "CREATE TABLE users ("
        . " id CHAR(36) PRIMARY KEY,"
        . " is_admin TINYINT(1) NOT NULL DEFAULT 0,"
        . " status ENUM('pending', 'active', 'disabled') NOT NULL DEFAULT 'active',"
        . " created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    private ?Connection $db = null;

    private ?Connection $admin = null;

    private string $scratchDb = '';

    /** @var list<string> */
    private array $fixDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // S126 gate: skip on genuine absence, LOUD on reachable-but-unusable.
        $this->requireHealthyDatabase('skipping the migration-112 ownership-backfill proof. Runs in CI.');

        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $rand = bin2hex(random_bytes(6));
        $this->scratchDb = 'phlix_s112_' . $rand;

        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);

        $this->db->query(self::USERS_FIXTURE_DDL);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixDirs as $fixDir) {
            if (is_dir($fixDir)) {
                foreach ((array) glob($fixDir . '/*') as $file) {
                    if (is_string($file)) {
                        unlink($file);
                    }
                }
                rmdir($fixDir);
            }
        }
        $this->fixDirs = [];

        if ($this->admin !== null && $this->scratchDb !== '') {
            $this->admin->query('DROP DATABASE IF EXISTS `' . $this->scratchDb . '`');
        }

        $this->db = null;
        $this->admin = null;
        $this->scratchDb = '';

        parent::tearDown();
    }

    public function testBackfillElectsEarliestActiveAdminAndAddsTheOwnerIndex(): void
    {
        $db = $this->requireDb();
        $this->applyBaseline();

        // Every candidate class planted so a WRONG precedence is observable:
        //  - disabled admin created FIRST (must lose: status gate),
        //  - earliest ACTIVE admin (must win),
        //  - later active admin (loses on created_at),
        //  - oldest user overall, non-admin (must lose to the active admin —
        //    the any-status tier is a FALLBACK, not a tie-break).
        $this->seedUser('u-disabled-first', is_admin: true, status: 'disabled', createdAt: '2019-01-01 00:00:00');
        $this->seedUser('u-active-earliest', is_admin: true, status: 'active', createdAt: '2020-06-01 00:00:00');
        $this->seedUser('u-active-later', is_admin: true, status: 'active', createdAt: '2021-06-01 00:00:00');
        $this->seedUser('u-member-oldest', is_admin: false, status: 'active', createdAt: '2018-01-01 00:00:00');

        $this->seedLegacyCollections(3);

        $this->assertMigration112AppliesCleanly();

        $rows = $db->query('SELECT id, created_by FROM collections ORDER BY id');
        $this->assertIsArray($rows);
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(
                'u-active-earliest',
                $row['created_by'],
                'legacy rows must be stamped with the earliest ACTIVE admin (is_admin=1 AND'
                . " status='active'), never a deactivated one, never the any-status fallback"
            );
        }

        $columns = $db->query(
            'SELECT COLUMN_NAME, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collections' AND COLUMN_NAME = 'created_by'"
        );
        $this->assertIsArray($columns);
        $this->assertCount(1, $columns, 'created_by must exist exactly once');
        $this->assertSame('YES', $columns[0]['IS_NULLABLE'], 'the column must stay NULLable (legacy policy)');
        $this->assertSame(36, (int) $columns[0]['CHARACTER_MAXIMUM_LENGTH'], 'created_by is CHAR(36) — a user id');

        $indexes = $db->query("SHOW INDEX FROM collections WHERE Key_name = 'idx_col_owner'");
        $this->assertIsArray($indexes);
        $this->assertCount(1, $indexes);
        $this->assertSame('created_by', (string) $indexes[0]['Column_name']);
        $this->assertSame(1, (int) $indexes[0]['Non_unique'], 'idx_col_owner is a plain KEY, not unique');
    }

    public function testBackfillFallsBackToEarliestUserOfAnyStatusWhenNoActiveAdminExists(): void
    {
        $db = $this->requireDb();
        $this->applyBaseline();

        // No active admin at all: the design's fallback tier is the
        // earliest-created user of ANY status — "earliest established user",
        // deliberately NOT "earliest non-admin". The oldest account here is a
        // DISABLED admin (2016): it must lose the first tier (status gate)
        // yet legitimately win the any-status fallback over every younger
        // row, admin flag notwithstanding.
        $this->seedUser('u-disabled-member', is_admin: false, status: 'disabled', createdAt: '2017-01-01 00:00:00');
        $this->seedUser('u-pending', is_admin: false, status: 'pending', createdAt: '2018-01-01 00:00:00');
        $this->seedUser('u-active-member', is_admin: false, status: 'active', createdAt: '2019-01-01 00:00:00');
        $this->seedUser('u-suspended-admin', is_admin: true, status: 'disabled', createdAt: '2016-01-01 00:00:00');

        $this->seedLegacyCollections(2);

        $this->assertMigration112AppliesCleanly();

        $rows = $db->query('SELECT created_by FROM collections');
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(
                'u-suspended-admin',
                $row['created_by'],
                'with no active admin, the earliest-created user of ANY status anchors the '
                . 'backfill (here the 2016 disabled admin) — tier 1 stayed closed on the status gate'
            );
        }
    }

    public function testRowsStayNullWhenTheUsersTableIsEmpty(): void
    {
        $db = $this->requireDb();
        $this->applyBaseline();
        $this->seedLegacyCollections(2);

        $this->assertMigration112AppliesCleanly();

        $rows = $db->query('SELECT created_by FROM collections');
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull(
                $row['created_by'],
                'a brand-new install (no users yet) must leave legacy rows NULL — '
                . 'the NULL row policy is visibility-for-all / admin-writable, not fabrication'
            );
        }
    }

    public function testReplayAfterLedgerResetIsIdempotentAndNeverReStamps(): void
    {
        $db = $this->requireDb();
        $this->applyBaseline();
        $this->seedUser('u-active-admin', is_admin: true, status: 'active', createdAt: '2020-01-01 00:00:00');
        $this->seedUser('u-newer-admin', is_admin: true, status: 'active', createdAt: '2021-01-01 00:00:00');
        $this->seedLegacyCollections(3);

        $this->assertMigration112AppliesCleanly();

        // A hand-fixed owner and a self-healed NULL are both replay states:
        // one row gets re-pointed to an id the election would NEVER choose, so
        // a re-strike would be visible; the other returns to NULL, where the
        // guarded UPDATE re-stamps it (documented self-heal, not a clobber).
        $db->query('UPDATE collections SET created_by = ? WHERE id = ?', ['u-hand-fixed', 'col-1']);
        $db->query('UPDATE collections SET created_by = NULL WHERE id = ?', ['col-2']);

        // Force a TRUE replay: drop only 112's ledger entry so the runner
        // re-executes the file body — INFORMATION_SCHEMA guards, index guard,
        // and the DML — against an already-migrated schema.
        $db->query("DELETE FROM schema_migrations WHERE name LIKE '112_%'");

        $result = $this->runMigrationFile(self::MIGRATION_112);
        $this->assertSame([], $result['errors'], 'replay of 112 must be error-free: ' . implode('; ', $result['errors']));

        $columns = $db->query(
            "SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collections' AND COLUMN_NAME = 'created_by'"
        );
        $this->assertIsArray($columns);
        $this->assertSame(1, (int) $columns[0]['n'], 'the ADD COLUMN guard must no-op on replay');

        $indexes = $db->query("SHOW INDEX FROM collections WHERE Key_name = 'idx_col_owner'");
        $this->assertIsArray($indexes);
        $this->assertCount(1, $indexes, 'the ADD INDEX guard must no-op on replay (single idx_col_owner row)');

        $rows = $db->query('SELECT id, created_by FROM collections ORDER BY id');
        $this->assertIsArray($rows);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row['id']] = $row['created_by'];
        }
        $this->assertSame(
            ['col-1' => 'u-hand-fixed', 'col-2' => 'u-active-admin', 'col-3' => 'u-active-admin'],
            $byId,
            'replay touches only NULL rows (self-heal) and never overwrites an existing stamp'
        );

        $ledger = $db->query('SELECT name FROM schema_migrations WHERE name LIKE ?', ['112_%']);
        $this->assertIsArray($ledger);
        $this->assertCount(1, $ledger, 'the replayed file must be re-recorded in the ledger');
    }

    // -----------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------

    private function requireDb(): Connection
    {
        $this->assertNotNull($this->db);
        return $this->db;
    }

    /**
     * Build the pre-ownership shape from the PRODUCTION 005 file, so the ALTER
     * target schema is the real one, not a hand drift-copy.
     */
    private function applyBaseline(): void
    {
        $result = $this->runMigrationFile(self::MIGRATION_005);
        $this->assertSame(
            [],
            $result['errors'],
            'baseline 005 must apply cleanly: ' . implode('; ', $result['errors'])
        );
    }

    /**
     * @return array{applied: list<string>, notes: list<string>, errors: list<string>, skipped_count: int}
     */
    private function assertMigration112AppliesCleanly(): array
    {
        $result = $this->runMigrationFile(self::MIGRATION_112);
        $this->assertSame(
            [],
            $result['errors'],
            'migration 112 must apply cleanly: ' . implode('; ', $result['errors'])
        );

        $db = $this->requireDb();
        $ledger = $db->query('SELECT name FROM schema_migrations WHERE name LIKE ?', ['112_collections%']);
        $this->assertIsArray($ledger);
        $this->assertCount(1, $ledger, 'the ledger must record THIS file (S114 pattern)');

        return $result;
    }

    /**
     * Run ONE real migration file through the production runner over a
     * single-file fixDir (mirrors the 106 test's scratch-DB isolation).
     *
     * @return array{applied: list<string>, notes: list<string>, errors: list<string>, skipped_count: int}
     */
    private function runMigrationFile(string $basename): array
    {
        $rand = bin2hex(random_bytes(4));
        $fixDir = sys_get_temp_dir() . '/phlix-s112-fix-' . $basename . '-' . $rand;
        mkdir($fixDir, 0o755, true);
        $this->fixDirs[] = $fixDir;

        copy($this->migrationPath($basename), $fixDir . '/' . $basename);

        $db = $this->requireDb();
        $runner = new MigrationRunner(static fn (): Connection => $db, $fixDir);

        return $runner->run();
    }

    private function migrationPath(string $basename): string
    {
        return dirname(__DIR__, 3) . '/migrations/' . $basename;
    }

    private function seedUser(string $id, bool $is_admin, string $status, string $createdAt): void
    {
        $db = $this->requireDb();
        $db->query(
            'INSERT INTO users (id, is_admin, status, created_at) VALUES (?, ?, ?, ?)',
            [$id, $is_admin ? 1 : 0, $status, $createdAt]
        );
    }

    /**
     * Legacy rows are inserted WITHOUT the created_by column — the exact
     * pre-112 INSERT shape `CollectionRepository::insert()` used to write — so
     * the migration's ADD COLUMN + backfill do all the work under test.
     */
    private function seedLegacyCollections(int $count): void
    {
        $db = $this->requireDb();
        for ($i = 1; $i <= $count; $i++) {
            // created_at/updated_at are DATETIME NOT NULL with no default in
            // 005 — the old repository INSERT passed them explicitly, and so
            // must this legacy seed.
            $db->query(
                'INSERT INTO collections (id, name, library_id, smart_playlist_id, parent_id, sort_order,'
                . " created_at, updated_at) VALUES (?, ?, 'lib-1', NULL, NULL, 0, '2020-01-01 00:00:00',"
                . " '2020-01-01 00:00:00')",
                ['col-' . $i, 'Legacy ' . $i]
            );
        }
    }
}
