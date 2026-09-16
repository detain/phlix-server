<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Stats;

use Phlix\Common\Database\MigrationRunner;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Stats\ClientHeartbeatStore;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S518 — real-MySQL proof that migration 106 builds `client_heartbeats` with
 * the fleet-bounded shape the telemetry privacy posture depends on, and that
 * the write path UPSERTs per instance instead of accreting history.
 *
 * ## Why real MySQL is the only venue (S345 rule 2 — the real artefact)
 *
 * The PK-ness of `instance_id` (the property that turns a repeat heartbeat into
 * an UPDATE rather than a second row — and therefore bounds the table by fleet
 * size), the `ON DUPLICATE KEY UPDATE` semantics that keep `first_seen_at`
 * untouched while `last_seen_at` rides forward, the `CHAR(64)` pad-space
 * equality a recorded double cannot emulate, and the runner's ledger over THIS
 * file are all live-server properties. Mocked `Connection` accepts everything
 * and models none of it.
 *
 * SAFETY: every destructive proof runs in a throwaway `phlix_s518_*` DATABASE
 * built by running the REAL migration 106 file through the production
 * MigrationRunner (S114 pattern) — never against the shared `phlix_test`
 * tables. tearDown drops the scratch DB.
 */
final class ClientHeartbeatsMigration106RealDbTest extends TestCase
{
    use RequiresRealDatabase;

    private const MIGRATION_106 = '106_client_heartbeats.sql';

    private ?Connection $db = null;

    private ?Connection $admin = null;

    private string $scratchDb = '';

    private string $fixDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        // S126 gate: skip on genuine absence, LOUD on reachable-but-unusable.
        $this->requireHealthyDatabase('skipping the S518 migration-106 upsert proof. Runs in CI.');

        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $rand = bin2hex(random_bytes(6));
        $this->scratchDb = 'phlix_s518_' . $rand;

        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);

        $this->fixDir = sys_get_temp_dir() . '/phlix-s518-fix-' . $rand;
        mkdir($this->fixDir, 0o755, true);
        copy($this->migrationPath(self::MIGRATION_106), $this->fixDir . '/' . self::MIGRATION_106);

        $db = $this->db;
        $runner = new MigrationRunner(static fn (): Connection => $db, $this->fixDir);
        $result = $runner->run();
        $this->assertSame(
            [],
            $result['errors'],
            'migration 106 must apply cleanly on a fresh database: ' . implode('; ', $result['errors'])
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

        if ($this->admin !== null && $this->scratchDb !== '') {
            $this->admin->query('DROP DATABASE IF EXISTS `' . $this->scratchDb . '`');
        }

        $this->db = null;
        $this->admin = null;
        $this->scratchDb = '';
        $this->fixDir = '';

        parent::tearDown();
    }

    /**
     * The shape AC: `instance_id` is the ONLY unique key — the property that
     * makes "one row per consenting device" structural, not conventional.
     */
    public function testPrimaryKeyIsInstanceAloneAndHistoryIndexesAreNonUnique(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $rows = $db->query('SHOW INDEX FROM client_heartbeats');
        $this->assertIsArray($rows);

        $unique = [];
        $secondary = [];
        foreach ($rows as $row) {
            $name = (string) $row['Key_name'];
            if ((int) $row['Non_unique'] === 0) {
                $unique[$name][] = (string) $row['Column_name'];
            } else {
                $secondary[$name][] = (string) $row['Column_name'];
            }
        }

        $this->assertSame(['instance_id'], $unique['PRIMARY'] ?? null, 'PK must be exactly (instance_id)');
        $this->assertSame(['PRIMARY'], array_keys($unique), 'no other unique/secondary key may bound the table');
        $this->assertArrayHasKey('ix_client_heartbeats_last_seen', $secondary);
    }

    /**
     * The upsert AC: two heartbeats from one instance = ONE row, first_seen
     * frozen, last_seen advanced, mutable columns overwritten — the exact
     * "bounded by fleet size" retention promise migration 106's header makes.
     */
    public function testRepeatedHeartbeatsUpsertToASingleRow(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $store = new ClientHeartbeatStore($db);
        $this->assertTrue($store->record('inst-fixture-aaaaaaaa', '1.0.0', 'webos', 'bt-one'));

        // Age the first observation two days so NOW() cannot tie it.
        $db->query(
            'UPDATE client_heartbeats SET first_seen_at = DATE_SUB(NOW(), INTERVAL 2 DAY),'
            . ' last_seen_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE instance_id = ?',
            ['inst-fixture-aaaaaaaa']
        );

        $this->assertTrue($store->record('inst-fixture-aaaaaaaa', '1.2.3', 'tizen', 'bt-two'));
        $this->assertTrue($store->record('inst-fixture-aaaaaaaa', '1.2.4', 'tizen', 'bt-three'));

        $this->assertSame(1, $store->countInstances(), 'repeat heartbeats must never accrete rows');

        $rows = $db->query(
            'SELECT version, client_type, build_token, first_seen_at,'
            . ' (last_seen_at > first_seen_at) AS advanced FROM client_heartbeats WHERE instance_id = ?',
            ['inst-fixture-aaaaaaaa']
        );
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('1.2.4', (string) $row['version'], 'the newest version must ride along');
        $this->assertSame('tizen', (string) $row['client_type']);
        $this->assertSame('bt-three', (string) $row['build_token']);
        $this->assertSame(1, (int) $row['advanced'], 'last_seen_at must move while first_seen_at stays');
        $this->assertSame(
            (new \DateTimeImmutable('-2 days'))->format('Y-m-d'),
            substr((string) $row['first_seen_at'], 0, 10),
            'first_seen_at must be untouched by the ON DUPLICATE update list'
        );
    }

    /**
     * Distinct instances are distinct rows; CHAR(64) pad-space equality means
     * an id and the same id with trailing spaces are the SAME instance — the
     * column type chosen deliberately so a sloppy client cannot forge a second row.
     */
    public function testDistinctInstancesLandSeparatelyAndPadEqualMerge(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $store = new ClientHeartbeatStore($db);
        $this->assertTrue($store->record('inst-fixture-bbbbbbbb', '1.0.0', 'android', 'bt-a'));
        $this->assertTrue($store->record('inst-fixture-cccccccc', '1.0.0', 'ios', 'bt-b'));
        $this->assertTrue($store->record('inst-fixture-bbbbbbbb ', '1.0.1', 'android', 'bt-a2'));

        $this->assertSame(2, $store->countInstances(), 'CHAR(64) PAD SPACE equality merges the trailing-space re-send');

        $recent = $store->recent(10);
        $this->assertCount(2, $recent);
        $ids = array_map(static fn (array $r): string => rtrim((string) $r['instance_id']), $recent);
        sort($ids);
        $this->assertSame(['inst-fixture-bbbbbbbb', 'inst-fixture-cccccccc'], $ids);
    }

    /**
     * The ledger must record THIS file — a hand-created scratch table would let
     * the shape proof above silently test a drift-copy instead of the migration.
     */
    public function testMigrationIsRecordedInLedger(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $rows = $db->query('SELECT name FROM schema_migrations WHERE name LIKE ?', ['106_client_heartbeats%']);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
    }

    private function migrationPath(string $basename): string
    {
        return dirname(__DIR__, 3) . '/migrations/' . $basename;
    }
}
