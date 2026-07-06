<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Stats;

use Phlix\Common\Database\ConnectionPool;
use Phlix\Stats\Metrics\MetricsRepository;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof that every {@see MetricsRepository} read query actually executes
 * against MySQL — the admin "Server Traffic" page (S2) calls these directly.
 *
 * The unit tests {@see \Phlix\Tests\Unit\Stats\Metrics\MetricsRepositoryTest}
 * mock the connection, so they validate the row→array hydration and percentile
 * maths but NOT the SQL itself. That gap let the history() query ship with a
 * `GROUP BY FLOOR(UNIX_TIMESTAMP(bucket_started_at) / ?)` that MySQL 8's default
 * sql_mode=ONLY_FULL_GROUP_BY rejects with error 1055 (the SELECT's
 * `FROM_UNIXTIME(FLOOR(...) * ?)` bucket column is not seen as functionally
 * dependent on that inner GROUP BY expression) — so all three history-fed charts
 * (Bandwidth / Latency / Request Rate) 500'd with "Request failed" in production
 * while the mocked suite stayed green.
 *
 * This test drives the real {@see \Phlix\Common\Database\PhlixMySQLConnection}
 * (emulated prepares + type-aware int binding, exactly the production path)
 * against MySQL so any real-DB SQL error — ONLY_FULL_GROUP_BY, a quoted `LIMIT`,
 * a bad column — fails loudly. CI applies all migrations to the `phlix_test`
 * MySQL service before the suite (see phpunit.yml); locally, with no reachable
 * MySQL, it self-skips.
 *
 * @covers \Phlix\Stats\Metrics\MetricsRepository
 */
final class MetricsReadQueriesTest extends TestCase
{
    private ?Connection $db = null;

    /**
     * Disposable worker ids for the rollup rows this test inserts. Chosen high in
     * the SMALLINT range so they never collide with a real worker (0..N) and can
     * be deleted wholesale in setUp/tearDown.
     *
     * @var array<int, int>
     */
    private const WORKER_IDS = [30001, 30002];

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);

        if (!$this->isMysqlReachable($host, $port)) {
            $this->markTestSkipped(
                sprintf('No MySQL on %s:%d — skipping metrics read-query test. Runs in CI.', $host, $port),
            );
        }

        try {
            ConnectionPool::init(dirname(__DIR__, 3) . '/config/database.php');
            $this->db = ConnectionPool::getConnection('mysql');
        } catch (Throwable $e) {
            $this->markTestSkipped('Could not connect to MySQL: ' . $e->getMessage());
        }

        $this->purgeFixtureRows();

        // Two workers, same time-bucket: proves the cross-worker SUM/GROUP BY.
        // A third row two minutes back lands in a separate 60s bucket.
        $this->db->query(
            'INSERT INTO metrics_rollup
                (bucket_started_at, worker_id, request_count, error_count,
                 duration_ms_sum, duration_ms_max, bytes_in, bytes_out,
                 h_le_10, h_le_50, h_le_100, h_le_250, h_le_500,
                 h_le_1000, h_le_2500, h_le_5000, h_gt_5000)
             VALUES
                (NOW(), ?, 10, 0, 500, 90, 1000, 2000, 5, 3, 1, 1, 0, 0, 0, 0, 0),
                (NOW(), ?, 5, 1, 300, 120, 500, 800, 2, 2, 1, 0, 0, 0, 0, 0, 0)',
            [self::WORKER_IDS[0], self::WORKER_IDS[1]],
        );
    }

    protected function tearDown(): void
    {
        $this->purgeFixtureRows();
        parent::tearDown();
    }

    /**
     * The regression this file exists for: history() must execute against real
     * MySQL (no 1055) and SUM across workers within each resolution bucket.
     */
    public function testHistoryExecutesAndAggregatesAcrossWorkers(): void
    {
        $this->assertNotNull($this->db);
        $repo = new MetricsRepository($this->db);

        // Would throw a PDOException (error 1055) on the pre-fix GROUP BY.
        $history = $repo->history(60, 60);
        $this->assertNotEmpty($history, 'history() returned no buckets for freshly-inserted rows');

        // Locate the bucket holding this test's two same-instant worker rows and
        // assert they were summed (10 + 5 requests, 1000 + 500 bytes_in). `>=`
        // tolerates any other rows that happen to share the minute bucket.
        $mine = null;
        foreach ($history as $bucket) {
            if ($bucket['requests'] >= 15 && $bucket['bytes_in'] >= 1500) {
                $mine = $bucket;
                break;
            }
        }
        $this->assertNotNull($mine, 'no bucket reflects the summed cross-worker rows');
        $this->assertGreaterThanOrEqual(15, $mine['requests']);
        $this->assertGreaterThanOrEqual(1500, $mine['bytes_in']);
        $this->assertGreaterThanOrEqual(2800, $mine['bytes_out']);
        // Percentiles are ints in ms and stay within the histogram's bounds.
        $this->assertGreaterThanOrEqual(0, $mine['p50_ms']);
        $this->assertLessThanOrEqual(5000, $mine['p95_ms']);
    }

    /**
     * The remaining read queries the admin page issues must also survive real
     * MySQL (snapshot has no GROUP BY, topRoutes binds a positional LIMIT, and
     * liveConnections selects raw columns) — a guard so none of them regress the
     * way history() did.
     */
    public function testSnapshotConnectionsAndRoutesExecute(): void
    {
        $this->assertNotNull($this->db);
        $repo = new MetricsRepository($this->db);

        $snapshot = $repo->snapshot(60);
        $this->assertArrayHasKey('requests_per_sec', $snapshot);
        $this->assertArrayHasKey('p95_ms', $snapshot);
        $this->assertGreaterThanOrEqual(0, $snapshot['active_connections']);

        // Executing these against real MySQL is the assertion: liveConnections()
        // selects raw columns and topRoutes() binds a positional LIMIT — a real
        // SQL fault in either would throw here and fail the test.
        $repo->liveConnections(15);
        $repo->topRoutes(15, 20);
    }

    private function purgeFixtureRows(): void
    {
        if ($this->db === null) {
            return;
        }
        foreach (self::WORKER_IDS as $workerId) {
            $this->db->query('DELETE FROM metrics_rollup WHERE worker_id = ?', [$workerId]);
        }
    }

    private function isMysqlReachable(string $host, int $port): bool
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return true;
    }
}
