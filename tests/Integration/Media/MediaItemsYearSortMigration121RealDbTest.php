<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Database\MigrationRunner;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * minors M-SERVER-20 (W-min100) — real-MySQL proof that migration 121
 * materializes `media_items.year_sort` (the release-year browse key), indexes
 * it with `idx_media_items_library_year`, derives exactly the legacy
 * expression's values for every year class, and — the whole reason the
 * column expression carries a CASE guard instead of the bare CAST — never
 * turns a hostile metadata write into a strict-mode error.
 *
 * ## Why real MySQL is the only venue (S345 rule 2 — the real artefact)
 *
 * The generated-column evaluation (including the ERROR 1292 promotion of the
 * JSON-null→'null'→CAST warning that the CASE guard exists to prevent), the
 * INFORMATION_SCHEMA + PREPARE guards, the SMALLINT clamp and the ledger over
 * THIS file are live-server properties. A mocked Connection accepts
 * everything and models none of it.
 *
 * SAFETY: every proof runs in a throwaway `phlix_srv100_*` DATABASE (the
 * 112-test pattern) against a fixture media_items carrying exactly the subset
 * migration 121 reads (`metadata_json` JSON-typed, `library_id` for the
 * index) — never the shared tables. tearDown drops the scratch DB.
 */
final class MediaItemsYearSortMigration121RealDbTest extends TestCase
{
    use RequiresRealDatabase;

    private const MIGRATION_121 = '121_media_items_year_sort.sql';

    /** The legacy per-row expression the column must reproduce for real years. */
    private const LEGACY_EXPR = "CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.year')) AS SIGNED)";

    private const FIXTURE_DDL =
        "CREATE TABLE media_items ("
        . " id CHAR(36) PRIMARY KEY,"
        . " library_id CHAR(36),"
        . " name VARCHAR(255),"
        . " metadata_json JSON"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    private ?Connection $db = null;

    private ?Connection $admin = null;

    private string $scratchDb = '';

    /** @var list<string> */
    private array $fixDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireHealthyDatabase('skipping the migration-121 year_sort proof. Runs in CI.');

        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $rand = bin2hex(random_bytes(6));
        $this->scratchDb = 'phlix_srv100_' . $rand;

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

    public function testAppliesThroughTheProductionRunnerAndDerivesEveryYearClass(): void
    {
        $db = $this->requireDb();
        $this->seed();

        $result = $this->runMigrationFile(self::MIGRATION_121);
        $this->assertSame([], $result['errors'], 'migration 121 must apply cleanly');

        $columns = $db->query(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->scratchDb, 'media_items', 'year_sort']
        );
        $this->assertCount(1, (array) $columns, 'year_sort must exist after apply');

        $indexCols = $db->query(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$this->scratchDb, 'media_items', 'idx_media_items_library_year']
        );
        $names = array_map(static fn (array $r): string => (string) $r['COLUMN_NAME'], (array) $indexCols);
        $this->assertSame(['library_id', 'year_sort'], $names, 'the browse index must be (library_id, year_sort) in order');

        $derived = [];
        foreach ((array) $db->query('SELECT id, year_sort FROM media_items ORDER BY id') as $row) {
            $derived[(string) $row['id']] = $row['year_sort'];
        }

        $this->assertSame(2020, (int) $derived['y-int'], 'number year');
        $this->assertSame(2019, (int) $derived['y-str'], 'digit-string year parses identically');
        $this->assertNull($derived['y-missing'], 'absent $.year path extracts SQL NULL — NULL sorts first, unchanged');
        $this->assertSame(0, (int) $derived['y-null'], 'JSON null → 0, exactly the legacy CAST behavior');
        $this->assertSame(32767, (int) $derived['y-huge'], 'out-of-range clamps to the SMALLINT edge — no 1264');
        $this->assertSame(0, (int) $derived['y-garbage'], "'abc' folds to 0, matching legacy CAST-returns-0");

        $ledger = $db->query('SELECT name FROM schema_migrations WHERE name LIKE ?', ['121_media_items_year_sort%']);
        $this->assertCount(1, (array) $ledger, 'the ledger must record THIS file (S114 pattern)');
    }

    public function testReplayIsANoOp(): void
    {
        $db = $this->requireDb();
        $this->seed();
        $this->assertSame([], $this->runMigrationFile(self::MIGRATION_121)['errors']);

        // Second run of the same single-file dir: the runner ledger skips it.
        $second = $this->runMigrationFile(self::MIGRATION_121);
        $this->assertSame([], $second['errors']);
        $this->assertSame([], $second['applied']);
        $this->assertSame(1, $second['skipped_count']);

        // And the file's OWN guards: strip the ledger row, replay must still
        // no-op (INFORMATION_SCHEMA guard → SELECT 1), never eat 1060/1061.
        $db->query('DELETE FROM schema_migrations WHERE name LIKE ?', ['121_media_items_year_sort%']);
        $third = $this->runMigrationFile(self::MIGRATION_121);
        $this->assertSame([], $third['errors'], 'unguarded-schema replay must no-op via the INFORMATION_SCHEMA guards');
        $this->assertSame(
            2020,
            (int) $this->requireDb()->query(
                'SELECT year_sort FROM media_items WHERE id = ?',
                ['y-int']
            )[0]['year_sort'],
            'replay must not disturb materialized values'
        );
    }

    public function testPostApplyHostileWritesNeverErrorAndFoldToTheGuardArms(): void
    {
        $db = $this->requireDb();
        $this->assertSame([], $this->runMigrationFile(self::MIGRATION_121)['errors']);

        // THE regression this CASE guard exists for: with the bare CAST
        // expression the ALTER above had already died 1292 on the seeded
        // JSON-null row; on the write path a post-apply 'tba'/huge/negative
        // year must fold, never 500 the metadata save. (Measured on MySQL
        // 8.4 scratch before shipping the guard.)
        $db->query(
            "INSERT INTO media_items (id, library_id, name, metadata_json) VALUES"
            . " ('w1','L','W1','{\\\"year\\\": \\\"tba\\\"}'),"
            . " ('w2','L','W2','{\\\"year\\\": 99999999}'),"
            . " ('w3','L','W3','{\\\"year\\\": -5}'),"
            . " ('w4','L','W4','{\\\"year\\\": null}')"
        );

        $folded = [];
        foreach ((array) $db->query("SELECT id, year_sort FROM media_items WHERE id LIKE 'w%' ORDER BY id") as $row) {
            $folded[(string) $row['id']] = (int) $row['year_sort'];
        }
        $this->assertSame(['w1' => 0, 'w2' => 32767, 'w3' => 0, 'w4' => 0], $folded);
    }

    public function testOrderingIsByteIdenticalToTheLegacyExpressionForRealYears(): void
    {
        $db = $this->requireDb();
        $this->seed();
        $this->assertSame([], $this->runMigrationFile(self::MIGRATION_121)['errors']);

        // Every REALISTIC year class (numbers and clean digit strings, plus
        // the missing-year NULL) must sort under year_sort exactly as under
        // the legacy CAST expression — same sequence both directions.
        $rows = (array) $db->query(
            'SELECT id FROM media_items'
            . " WHERE id NOT IN ('y-garbage','y-huge','y-null')"
            . ' ORDER BY year_sort ASC, id ASC'
        );
        $newAsc = array_map(static fn (array $r): string => (string) $r['id'], $rows);
        $rows = (array) $db->query(
            'SELECT id FROM media_items'
            . " WHERE id NOT IN ('y-garbage','y-huge','y-null')"
            . ' ORDER BY ' . self::LEGACY_EXPR . ' ASC, id ASC'
        );
        $oldAsc = array_map(static fn (array $r): string => (string) $r['id'], $rows);
        $this->assertSame($oldAsc, $newAsc, 'ASC browse order must be identical to the legacy per-row CAST');

        $rows = (array) $db->query(
            'SELECT id FROM media_items'
            . " WHERE id NOT IN ('y-garbage','y-huge','y-null')"
            . ' ORDER BY year_sort DESC, id ASC'
        );
        $newDesc = array_map(static fn (array $r): string => (string) $r['id'], $rows);
        $rows = (array) $db->query(
            'SELECT id FROM media_items'
            . " WHERE id NOT IN ('y-garbage','y-huge','y-null')"
            . ' ORDER BY ' . self::LEGACY_EXPR . ' DESC, id ASC'
        );
        $oldDesc = array_map(static fn (array $r): string => (string) $r['id'], $rows);
        $this->assertSame($oldDesc, $newDesc, 'DESC browse order must be identical too');
    }

    /** Seed one row per year class the guard arms cover. */
    private function seed(): void
    {
        $db = $this->requireDb();
        $db->query(
            'INSERT INTO media_items (id, library_id, name, metadata_json) VALUES'
            . " ('y-int','L','Int', '{\"year\": 2020}'),"
            . " ('y-str','L','Str', '{\"year\": \"2019\"}'),"
            . " ('y-missing','L','Mis', '{}'),"
            . " ('y-null','L','Nul', '{\"year\": null}'),"
            . " ('y-huge','L','Hug', '{\"year\": 99999999}'),"
            . " ('y-garbage','L','Gar', '{\"year\": \"abc\"}')"
        );
    }

    /**
     * Run ONE real migration file through the production runner over a
     * single-file fixDir (the 106/112 tests' scratch-DB isolation pattern).
     *
     * @return array{applied: list<string>, notes: list<string>, errors: list<string>, skipped_count: int}
     */
    private function runMigrationFile(string $basename): array
    {
        $rand = bin2hex(random_bytes(4));
        $fixDir = sys_get_temp_dir() . '/phlix-srv100-fix-' . $basename . '-' . $rand;
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
