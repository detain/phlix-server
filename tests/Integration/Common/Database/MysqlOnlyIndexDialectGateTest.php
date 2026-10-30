<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Common\Database;

use Phlix\Common\Database\MigrationRunner;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * M-SERVER-06 (review server__dba) — real-MySQL proof that the full migration
 * chain fresh-applies green WITH the `@phlix-require-mysql` dialect directives
 * in place, keeps every MySQL-capable index it is entitled to, and settles
 * into a zero-work steady state.
 *
 * ## What this pins that the unit matrix cannot
 *
 * The mocked-connection matrix (`MigrationRunnerTest`) proves the gate's
 * decision table against synthetic `SELECT VERSION()` answers. Only a live
 * server proves that:
 *
 *  - the real driver's row shape for `SELECT VERSION()` is actually parsed
 *    (PhlixMySQLConnection fetches assoc — the key is literally `VERSION()`,
 *    not column 0; the probe reads the first cell of the first row either way);
 *  - the directive comments in the real 011/050 files are invisible to the
 *    ledger checksums of a machine that applied the pre-directive bytes (the
 *    steady-state re-run below executes ZERO migration SQL on a directory
 *    whose files now carry directives);
 *  - MySQL 8 satisfies both minimums (8.0.13 / 8.0.17) so nothing is skipped
 *    on the support platform the CI service runs — the functional indexes
 *    still exist after the chain, and 051's purge of the multi-valued index
 *    still lands.
 *
 * SAFETY: everything runs in a throwaway `phlix_s06_*` DATABASE (S518/S114
 * pattern) — never against the shared `phlix_test` tables. tearDown drops it.
 */
final class MysqlOnlyIndexDialectGateTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $admin = null;

    private ?Connection $db = null;

    private string $scratchDb = '';

    private string $migrationsDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireHealthyDatabase('skipping the M-SERVER-06 full-chain dialect-gate proof. Runs in CI.');

        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $this->migrationsDir = dirname(__DIR__, 4) . '/migrations';

        $rand = bin2hex(random_bytes(6));
        $this->scratchDb = 'phlix_s06_' . $rand;

        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);
    }

    protected function tearDown(): void
    {
        if ($this->admin !== null && $this->scratchDb !== '') {
            $this->admin->query('DROP DATABASE IF EXISTS `' . $this->scratchDb . '`');
        }

        $this->admin = null;
        $this->db = null;
        $this->scratchDb = '';

        parent::tearDown();
    }

    public function testFullChainFreshAppliesGreenOnMysql8WithDirectivesPresent(): void
    {
        $runner = new MigrationRunner(fn (): Connection => $this->db ?? throw new \RuntimeException("migration runner invoked before setUp connected"), $this->migrationsDir);
        $result = $runner->run();

        $this->assertSame(
            [],
            $result['errors'],
            'the full chain must fresh-apply cleanly on MySQL 8: ' . implode('; ', $result['errors'])
        );
        $this->assertNotEmpty($result['applied'], 'a scratch DB must actually apply the chain');

        // MySQL 8 satisfies both directive minimums — nothing may report a skip.
        foreach ($result['notes'] as $note) {
            $this->assertStringNotContainsString(
                '@phlix-require-mysql',
                $note,
                'a dialect-gated statement must not skip on MySQL 8'
            );
        }

        // The directive-carrying files recorded.
        $ledger = $this->db->query('SELECT name FROM schema_migrations');
        $recorded = array_map(static fn (array $r): string => (string) $r['name'], is_array($ledger) ? $ledger : []);
        $this->assertContains('011_music_library.sql', $recorded);
        $this->assertContains('050_media_items_sort_indexes.sql', $recorded);
        $this->assertContains('051_media_item_genres_join_table.sql', $recorded);
    }

    public function testMysql8EndStateKeepsFunctionalIndexesAnd051Purge(): void
    {
        $runner = new MigrationRunner(fn (): Connection => $this->db ?? throw new \RuntimeException("migration runner invoked before setUp connected"), $this->migrationsDir);
        $this->assertSame([], $runner->run()['errors']);

        /** @var array<int, array<string, string>> $rows */
        $rows = $this->db->query(
            'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? '
            . 'AND INDEX_NAME IN ('
            . "'idx_media_items_metadata_artist', 'idx_media_items_metadata_album', "
            . "'idx_media_items_metadata_genre', 'idx_media_items_genres')",
            [$this->scratchDb, 'media_items']
        );
        $names = array_map(static fn (array $r): string => (string) reset($r), is_array($rows) ? $rows : []);

        // The three 011 functional indexes (MySQL 8.0.13+) exist...
        $this->assertContains('idx_media_items_metadata_artist', $names);
        $this->assertContains('idx_media_items_metadata_album', $names);
        $this->assertContains('idx_media_items_metadata_genre', $names);
        // ...and 050's multi-valued index was created then purged by 051 —
        // the historical end state is unchanged by the gate.
        $this->assertNotContains('idx_media_items_genres', $names, '051 must still drop the superseded multi-valued index');

        $tables = $this->db->query('SHOW TABLES LIKE \'media_item_genres\'');
        $this->assertNotEmpty($tables, '051 join table must exist');
    }

    public function testSteadyStateReRunExecutesNothingOnceEveryFileIsLedgered(): void
    {
        $first = (new MigrationRunner(fn (): Connection => $this->db ?? throw new \RuntimeException("migration runner invoked before setUp connected"), $this->migrationsDir))->run();
        $this->assertSame([], $first['errors']);

        $executed = [];
        $spy = $this->createMock(Connection::class);
        $spy->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$executed) {
                // Ledger reads are bookkeeping, not migration execution.
                if (str_contains($sql, 'schema_migrations')) {
                    return stripos(ltrim($sql), 'SELECT') === 0
                        ? $this->db->query($sql)
                        : [];
                }

                $executed[] = $sql;

                return $this->db->query($sql, $params);
            }
        );

        $second = (new MigrationRunner(fn (): Connection => $spy, $this->migrationsDir))->run();

        $this->assertSame([], $executed, 'with every file recorded, the directive-laden chain must execute zero migration SQL and issue zero dialect probes');
        $this->assertSame([], $second['applied']);
        $this->assertSame([], $second['notes']);
        $this->assertSame([], $second['errors']);
        $fileCount = count(glob($this->migrationsDir . '/*.sql') ?: []);
        $this->assertSame($fileCount, $second['skipped_count']);
    }
}
