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
 * minors M-SERVER-22 (W-min102) — real-MySQL proof that migration 122 prunes
 * the dangling `collections` / `collection_items` edges and replaces the
 * comments-only referential promises with three explicit, named, CASCADE
 * foreign keys — then that those keys actually cascade, reject new orphans,
 * and survive a replay (ledger AND unguarded) without 1826.
 *
 * ## Why real MySQL is the only venue (S345 rule 2 — the real artefact)
 *
 * Prune ordering (parent-most first, so one pass converges), the 1452 the
 * constraint check would raise if any orphan survived, the INFORMATION_SCHEMA
 * guards, CASCADE delivery on real deletes and the ledger over THIS file are
 * live-server properties. A mocked Connection accepts everything and models
 * none of it.
 *
 * SAFETY: every proof runs in a throwaway `phlix_srv22_*` DATABASE (the 121
 * pattern) against fixture tables carrying migration 005's real shapes —
 * never the shared tables. tearDown drops the scratch DB.
 */
final class CollectionsForeignKeysMigration122RealDbTest extends TestCase
{
    use RequiresRealDatabase;

    private const MIGRATION_122 = '122_collections_foreign_keys.sql';

    private const FIXTURE_DDL = [
        'libraries' =>
            "CREATE TABLE libraries ("
            . " id CHAR(36) PRIMARY KEY,"
            . " name VARCHAR(255)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'media_items' =>
            "CREATE TABLE media_items ("
            . " id CHAR(36) PRIMARY KEY,"
            . " name VARCHAR(255)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        // Migration 005 verbatim in shape — including the ABSENT foreign keys
        // this migration exists to add.
        'collections' =>
            "CREATE TABLE collections ("
            . " id CHAR(36) NOT NULL PRIMARY KEY,"
            . " name VARCHAR(128) NOT NULL,"
            . " library_id CHAR(36) NOT NULL,"
            . " smart_playlist_id CHAR(36) NULL,"
            . " parent_id CHAR(36) NULL,"
            . " sort_order INT NOT NULL DEFAULT 0,"
            . " created_at DATETIME NOT NULL,"
            . " updated_at DATETIME NOT NULL,"
            . " INDEX idx_col_library (library_id),"
            . " INDEX idx_col_smart_pl (smart_playlist_id),"
            . " INDEX idx_col_parent (parent_id)"
            . ") ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'collection_items' =>
            "CREATE TABLE collection_items ("
            . " collection_id CHAR(36) NOT NULL,"
            . " media_item_id CHAR(36) NOT NULL,"
            . " sort_order INT NOT NULL DEFAULT 0,"
            . " added_at DATETIME NOT NULL,"
            . " PRIMARY KEY (collection_id, media_item_id),"
            . " INDEX idx_ci_media (media_item_id)"
            . ") ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    private ?Connection $db = null;

    private ?Connection $admin = null;

    private string $scratchDb = '';

    /** @var list<string> */
    private array $fixDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireHealthyDatabase('skipping the migration-122 collections-FK proof. Runs in CI.');

        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $rand = bin2hex(random_bytes(6));
        $this->scratchDb = 'phlix_srv22_' . $rand;

        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);

        foreach (self::FIXTURE_DDL as $ddl) {
            $this->db->query($ddl);
        }
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

    public function testPrunesEveryDanglingEdgeThenConstrainsAllThree(): void
    {
        $db = $this->requireDb();
        $this->seedWithOrphans();

        $result = $this->runMigrationFile(self::MIGRATION_122);
        $this->assertSame(
            [],
            $result['errors'],
            'prune must converge BEFORE the ADDs — a surviving orphan would fail the'
            . ' constraint build 1452 on the very edge seeded dangling'
        );

        $this->assertSame(
            ['C1'],
            array_column((array) $db->query('SELECT id FROM collections'), 'id'),
            'only the collection whose library exists survives'
        );
        $this->assertSame(
            [['collection_id' => 'C1', 'media_item_id' => 'M1']],
            (array) $db->query('SELECT collection_id, media_item_id FROM collection_items'),
            'all three dangling item edges (ghost collection, ghost media, and'
            . ' the rows of the collection pruned by the library pass) are gone;'
            . ' the live pair remains'
        );

        $constraints = (array) $db->query(
            'SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.DELETE_RULE'
            . ' FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS k'
            . ' WHERE k.CONSTRAINT_SCHEMA = ? ORDER BY k.CONSTRAINT_NAME',
            [$this->scratchDb]
        );
        $this->assertSame([
            'fk_collection_items_collection@collection_items:CASCADE',
            'fk_collection_items_media_item@collection_items:CASCADE',
            'fk_collections_library@collections:CASCADE',
        ], array_map(
            static fn (array $r): string => $r['CONSTRAINT_NAME'] . '@'
                . $r['TABLE_NAME'] . ':' . $r['DELETE_RULE'],
            $constraints
        ), 'exactly the three named CASCADE edges — the i4 no-implicit-name law');

        $ledger = (array) $db->query(
            'SELECT name FROM schema_migrations WHERE name LIKE ?',
            ['122_collections_foreign_keys%']
        );
        $this->assertCount(1, $ledger, 'the ledger must record THIS file (S114 pattern)');
    }

    public function testConstraintsCascadeAndRejectNewOrphans(): void
    {
        $db = $this->requireDb();
        $this->assertSame([], $this->runMigrationFile(self::MIGRATION_122)['errors']);

        // Clean schema seeded AFTER apply (the apply above ran against empty
        // fixtures): live rows only, so the seeds themselves respect the keys.
        $db->query("INSERT INTO libraries (id, name) VALUES ('L1','lib')");
        $db->query("INSERT INTO media_items (id, name) VALUES ('M1','one'),('M2','two')");
        $db->query(
            "INSERT INTO collections (id, name, library_id, created_at, updated_at)"
            . " VALUES ('C1','c',  'L1', NOW(), NOW()), ('C2','c2','L1', NOW(), NOW())"
        );
        $db->query(
            "INSERT INTO collection_items (collection_id, media_item_id, added_at) VALUES"
            . " ('C1','M1',NOW()),('C1','M2',NOW()),('C2','M2',NOW())"
        );

        // A new orphan is refused outright (pre-migration it was accepted).
        try {
            $db->query(
                "INSERT INTO collection_items (collection_id, media_item_id, added_at)"
                . " VALUES ('C1','NO-SUCH-MEDIA',NOW())"
            );
            $this->fail('the media_item_id key must reject a dangling child row');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('foreign key constraint fails', $e->getMessage());
        }

        // Deleting a media item takes its membership rows with it (F edge).
        $db->query("DELETE FROM media_items WHERE id = 'M2'");
        $this->assertSame(
            [['collection_id' => 'C1', 'media_item_id' => 'M1']],
            (array) $db->query('SELECT collection_id, media_item_id FROM collection_items'),
            'the CASCADE pruned both membership rows of the deleted media item'
        );

        // Deleting the collection takes its remaining rows (E edge).
        $db->query("DELETE FROM collections WHERE id = 'C1'");
        $this->assertSame(
            [],
            (array) $db->query('SELECT collection_id FROM collection_items'),
            'the CASCADE pruned the membership rows of the deleted collection'
        );

        // Deleting the library takes its collections (D edge).
        $db->query("DELETE FROM libraries WHERE id = 'L1'");
        $this->assertSame(
            [],
            (array) $db->query('SELECT id FROM collections'),
            'a library delete now cascades through to collections'
        );
    }

    public function testReplayIsANoOpLedgerAndUnguarded(): void
    {
        $db = $this->requireDb();
        $this->seedWithOrphans();
        $this->assertSame([], $this->runMigrationFile(self::MIGRATION_122)['errors']);

        // Ledger skip.
        $second = $this->runMigrationFile(self::MIGRATION_122);
        $this->assertSame([], $second['errors']);
        $this->assertSame([], $second['applied']);
        $this->assertSame(1, $second['skipped_count']);

        // The file's OWN guards: strip the ledger row, replay must still no-op
        // (INFORMATION_SCHEMA constraint-count guards → SELECT 1; the prunes
        // delete nothing), and must never eat 1826 (duplicate key name).
        $db->query('DELETE FROM schema_migrations WHERE name LIKE ?', ['122_collections_foreign_keys%']);
        $third = $this->runMigrationFile(self::MIGRATION_122);
        $this->assertSame([], $third['errors'], 'unguarded-schema replay must no-op via the TABLE_CONSTRAINTS guards');
        $this->assertCount(
            3,
            (array) $db->query(
                'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS'
                . ' WHERE CONSTRAINT_SCHEMA = ?',
                [$this->scratchDb]
            ),
            'replay must neither duplicate nor drop any edge'
        );

        // Pruned state is undisturbed.
        $this->assertSame(
            ['C1'],
            array_column((array) $db->query('SELECT id FROM collections'), 'id')
        );
    }

    /**
     * One row per edge class the migration touches: a live pair, an item row on
     * a ghost media item, an item row on a ghost collection, and a collection
     * on a ghost library whose own item row must fall to the (B) pass that runs
     * AFTER the library prune — the parent-most-first ordering made observable.
     */
    private function seedWithOrphans(): void
    {
        $db = $this->requireDb();
        $db->query("INSERT INTO libraries (id, name) VALUES ('L1','live lib')");
        $db->query("INSERT INTO media_items (id, name) VALUES ('M1','live media')");
        $db->query(
            "INSERT INTO collections (id, name, library_id, created_at, updated_at) VALUES"
            . " ('C1','live',    'L1',       NOW(), NOW()),"
            . " ('C2','ghostlib','L-GHOST',  NOW(), NOW())"
        );
        $db->query(
            "INSERT INTO collection_items (collection_id, media_item_id, added_at) VALUES"
            . " ('C1','M1',NOW()),"
            . " ('C1','M-GHOST',NOW()),"
            . " ('C-GHOST','M1',NOW()),"
            . " ('C2','M1',NOW())"
        );
    }

    /**
     * Run ONE real migration file through the production runner over a
     * single-file fixDir (the 121 test's scratch-DB isolation pattern).
     *
     * @return array{applied: list<string>, notes: list<string>, errors: list<string>, skipped_count: int}
     */
    private function runMigrationFile(string $basename): array
    {
        $rand = bin2hex(random_bytes(4));
        $fixDir = sys_get_temp_dir() . '/phlix-srv22-fix-' . $basename . '-' . $rand;
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
