<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
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
 * minors M-SERVER-23 (W-min104 cluster) — real-MySQL proof that migration 123
 * gives the collection read rail an index that leads
 * (collection_id, sort_order, added_at) and answers it index-only.
 *
 * ## Why real MySQL is the only venue (S345 rule 2 — the real artefact)
 *
 * "Filesorts every time" and its cure are optimizer properties: only a live
 * server runs EXPLAIN, only a live InnoDB appends the PK columns to the
 * secondary index (which is what makes the rail covering), and the
 * INFORMATION_SCHEMA + PREPARE replay guard is a real-server shape. A mocked
 * Connection models none of it.
 *
 * SAFETY: every proof runs in a throwaway `phlix_srv104_*` DATABASE against a
 * fixture collection_items carrying exactly the 005 columns — never the
 * shared tables. tearDown drops the scratch DB.
 */
final class CollectionItemsSortIndexMigration123RealDbTest extends TestCase
{
    use RequiresRealDatabase;

    private const MIGRATION_123 = '123_collection_items_sort_index.sql';

    /** The rail verbatim from CollectionItemRepository::findMediaItemIdsForCollection(). */
    private const RAIL =
        "SELECT media_item_id FROM collection_items"
        . " WHERE collection_id = 'col-a' ORDER BY sort_order, added_at";

    private const FIXTURE_DDL =
        "CREATE TABLE collection_items ("
        . " collection_id CHAR(36) NOT NULL,"
        . " media_item_id CHAR(36) NOT NULL,"
        . " sort_order INT NOT NULL DEFAULT 0,"
        . " added_at DATETIME NOT NULL,"
        . " PRIMARY KEY (collection_id, media_item_id),"
        . " INDEX idx_ci_media (media_item_id)"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    private ?Connection $db = null;

    private ?Connection $admin = null;

    private string $scratchDb = '';

    /** @var list<string> */
    private array $fixDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireHealthyDatabase('skipping the migration-123 sort-index proof. Runs in CI.');

        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $rand = bin2hex(random_bytes(6));
        $this->scratchDb = 'phlix_srv104_' . $rand;

        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);

        $this->db->query(self::FIXTURE_DDL);
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

    public function testPreApplyRailFilesortsOnEveryRead(): void
    {
        $db = $this->requireDb();
        $this->seed();
        $db->query('ANALYZE TABLE collection_items');

        $row = $this->explainRail();

        $this->assertSame(
            'PRIMARY',
            $row['key'] ?? null,
            'pre-cure the optimizer can only use the PK prefix (defect state under test)'
        );
        $this->assertStringContainsString(
            'Using filesort',
            (string) ($row['Extra'] ?? ''),
            'pre-cure the ORDER BY sort_order, added_at is answered by a filesort — M-SERVER-23 exactly'
        );
    }

    public function testAppliesThroughTheProductionRunnerAndAnswersTheRailIndexOnly(): void
    {
        $db = $this->requireDb();
        $this->seed();

        $result = $this->runMigrationFile(self::MIGRATION_123);
        $this->assertSame([], $result['errors'], 'migration 123 must apply cleanly');

        $indexCols = $db->query(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$this->scratchDb, 'collection_items', 'idx_ci_collection_sort']
        );
        $names = array_map(static fn (array $r): string => (string) $r['COLUMN_NAME'], (array) $indexCols);
        $this->assertSame(
            ['collection_id', 'sort_order', 'added_at'],
            $names,
            'the composite must lead (collection_id, sort_order) and tie-break with added_at'
        );

        $db->query('ANALYZE TABLE collection_items');
        $row = $this->explainRail();
        $this->assertSame('idx_ci_collection_sort', $row['key'] ?? null, 'post-cure the rail rides the new index');
        $this->assertStringContainsString('Using index', (string) ($row['Extra'] ?? ''));
        $this->assertStringNotContainsString('filesort', (string) ($row['Extra'] ?? ''));

        // Order-equivalence: the index scan must return the SAME sequence the
        // old filesort produced (IGNORE INDEX forces the pre-cure path).
        $indexed = array_map(
            static fn (array $r): string => (string) $r['media_item_id'],
            (array) $db->query(self::RAIL)
        );
        $filesorted = array_map(
            static fn (array $r): string => (string) $r['media_item_id'],
            (array) $db->query(
                "SELECT media_item_id FROM collection_items IGNORE INDEX (idx_ci_collection_sort)"
                . " WHERE collection_id = 'col-a' ORDER BY sort_order, added_at"
            )
        );
        $this->assertCount(300, $indexed, 'all of collection A rides the rail');
        $this->assertSame($filesorted, $indexed, 'index order must equal filesort order — same semantics, no filesort');

        $ledger = $db->query(
            'SELECT name FROM schema_migrations WHERE name LIKE ?',
            ['123_collection_items_sort_index%']
        );
        $this->assertCount(1, (array) $ledger, 'the ledger must record THIS file (S114 pattern)');
    }

    public function testReplayIsANoOp(): void
    {
        $db = $this->requireDb();
        $this->seed();
        $this->assertSame([], $this->runMigrationFile(self::MIGRATION_123)['errors']);

        // Second run of the same single-file dir: the runner ledger skips it.
        $second = $this->runMigrationFile(self::MIGRATION_123);
        $this->assertSame([], $second['errors']);
        $this->assertSame([], $second['applied']);
        $this->assertSame(1, $second['skipped_count']);

        // And the file's OWN guard: strip the ledger row, replay must still
        // no-op via the INFORMATION_SCHEMA check, never eat 1061 mid-file.
        $db->query('DELETE FROM schema_migrations WHERE name LIKE ?', ['123_collection_items_sort_index%']);
        $third = $this->runMigrationFile(self::MIGRATION_123);
        $this->assertSame([], $third['errors'], 'ledger-stripped replay must no-op via the guard');

        $defs = $db->query(
            'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->scratchDb, 'collection_items', 'idx_ci_collection_sort']
        );
        $this->assertCount(1, (array) $defs, 'replay must not duplicate the index definition');
    }

    /** @return array<string, mixed> single EXPLAIN row for the rail */
    private function explainRail(): array
    {
        // Connection::query() only returns rows for select/show leading
        // keywords — EXPLAIN needs Connection::row() (house idiom, per
        // PathHashIndexUsageTest::explain).
        $row = $this->requireDb()->row('EXPLAIN ' . self::RAIL);
        $this->assertIsArray($row, 'EXPLAIN must answer for the rail');

        return $row;
    }

    private function seed(): void
    {
        $db = $this->requireDb();

        $values = [];
        for ($i = 0; $i < 300; $i++) {
            $order = intdiv($i, 3);
            $at = gmdate('Y-m-d H:i:s', 1767225600 + $i * 7);
            $values[] = "('col-a', 'a-$i', $order, '$at')";
        }
        foreach (['col-b' => 60, 'col-c' => 60] as $col => $count) {
            for ($i = 0; $i < $count; $i++) {
                $at = gmdate('Y-m-d H:i:s', 1767225600 + $i * 3);
                $values[] = "('$col', '$col-$i', $i, '$at')";
            }
        }
        $db->query(
            'INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at) VALUES '
            . implode(',', $values)
        );
    }

    private function runMigrationFile(string $basename): array
    {
        $rand = bin2hex(random_bytes(4));
        $fixDir = sys_get_temp_dir() . '/phlix-srv104-fix-' . $basename . '-' . $rand;
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

    private function requireDb(): Connection
    {
        $this->assertNotNull($this->db, 'scratch connection missing (setUp did not complete)');

        return $this->db;
    }
}
