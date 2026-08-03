<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Stats\Metrics;

use Phlix\Stats\Metrics\MetricsRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see MetricsRepository}, the read side of the subsystem.
 *
 * The Workerman MySQL {@see Connection} is mocked and returns canned rows keyed
 * off SQL fragments (the repo issues several distinct SELECTs). The tests assert
 * the cross-worker aggregation hydration, the derived rates / error rate, the
 * histogram percentile approximation output, and the SELECT-leading contract
 * (the driver returns NULL for a WITH/CTE-leading statement).
 */
final class MetricsRepositoryTest extends TestCase
{
    /**
     * @var array<int, string> Every SQL string the repository issued.
     */
    private array $seenSql = [];

    /**
     * Build a mock Connection whose query() returns canned rows chosen by
     * matching a fragment of the SQL.
     *
     * @param array<string, array<int, array<string, mixed>>> $byFragment
     *        Map of SQL-fragment => rows to return when the SQL contains it.
     */
    private function mockConnection(array $byFragment): Connection
    {
        $mock = $this->createMock(Connection::class);
        $mock->method('query')->willReturnCallback(
            function (string $sql) use ($byFragment): array {
                $this->seenSql[] = $sql;
                foreach ($byFragment as $fragment => $rows) {
                    if (str_contains($sql, $fragment)) {
                        return $rows;
                    }
                }
                return [];
            }
        );
        return $mock;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seenSql = [];
    }

    public function test_snapshot_aggregates_and_derives_rates(): void
    {
        // A summed-across-workers rollup row. 100 requests over a 10s window.
        $rollupRow = [
            'request_count' => 100,
            'error_count'   => 5,
            'bytes_in'      => 20_000,
            'bytes_out'     => 100_000,
            // Histogram: 90 fast (<=10ms), 10 slow (<=1000ms).
            'h_le_10'   => 90,
            'h_le_50'   => 0,
            'h_le_100'  => 0,
            'h_le_250'  => 0,
            'h_le_500'  => 0,
            'h_le_1000' => 10,
            'h_le_2500' => 0,
            'h_le_5000' => 0,
            'h_gt_5000' => 0,
        ];

        $db = $this->mockConnection([
            'FROM metrics_rollup'      => [$rollupRow],
            'FROM metrics_connections' => [['c' => 3]],
        ]);

        $repo = new MetricsRepository($db);
        $snap = $repo->snapshot(10);

        // Even integer divisions yield int-valued floats in PHP; assertEquals
        // (loose) so the numeric value is what matters, not the int/float type.
        $this->assertEquals(2000.0, $snap['bytes_in_per_sec']);   // 20000 / 10
        $this->assertEquals(10000.0, $snap['bytes_out_per_sec']); // 100000 / 10
        $this->assertSame(3, $snap['active_connections']);
        $this->assertEquals(10.0, $snap['requests_per_sec']);     // 100 / 10
        $this->assertSame(0.05, $snap['error_rate']);             // 5 / 100

        // Percentiles: p50 target = ceil(.5*100)=50, cumulative hits h_le_10 (90) -> 10.
        $this->assertSame(10, $snap['p50_ms']);
        // p95 target = ceil(.95*100)=95, cumulative 90 (h_le_10) then +10=100 at
        // h_le_1000 -> 1000. p99 target=99 lands there too.
        $this->assertSame(1000, $snap['p95_ms']);
        $this->assertSame(1000, $snap['p99_ms']);
    }

    public function test_snapshot_handles_empty_rollup(): void
    {
        $db = $this->mockConnection([
            'FROM metrics_rollup'      => [], // no rows at all
            'FROM metrics_connections' => [['c' => 0]],
        ]);

        $repo = new MetricsRepository($db);
        $snap = $repo->snapshot(60);

        $this->assertEquals(0.0, $snap['bytes_in_per_sec']);
        $this->assertEquals(0.0, $snap['requests_per_sec']);
        $this->assertSame(0.0, $snap['error_rate']); // no divide-by-zero
        $this->assertSame(0, $snap['active_connections']);
        // No samples -> percentiles are 0.
        $this->assertSame(0, $snap['p50_ms']);
        $this->assertSame(0, $snap['p95_ms']);
        $this->assertSame(0, $snap['p99_ms']);
    }

    public function test_snapshot_all_slow_requests_report_overflow_bound(): void
    {
        $rollupRow = [
            'request_count' => 4,
            'error_count'   => 0,
            'bytes_in'      => 0,
            'bytes_out'     => 0,
            'h_le_10'   => 0,
            'h_le_50'   => 0,
            'h_le_100'  => 0,
            'h_le_250'  => 0,
            'h_le_500'  => 0,
            'h_le_1000' => 0,
            'h_le_2500' => 0,
            'h_le_5000' => 0,
            'h_gt_5000' => 4, // all in the overflow bucket
        ];

        $db = $this->mockConnection([
            'FROM metrics_rollup'      => [$rollupRow],
            'FROM metrics_connections' => [['c' => 0]],
        ]);

        $repo = new MetricsRepository($db);
        $snap = $repo->snapshot(10);

        // Overflow bucket reports the last real bound (5000) as its floor.
        $this->assertSame(5000, $snap['p50_ms']);
        $this->assertSame(5000, $snap['p95_ms']);
    }

    public function test_history_hydrates_and_computes_percentiles_per_row(): void
    {
        $row = [
            'bucket'    => '2026-07-01 10:00:00',
            'bytes_in'  => '1000',   // strings, as the driver returns them
            'bytes_out' => '2000',
            'requests'  => '10',
            'errors'    => '1',
            'h_le_10'   => '8',
            'h_le_50'   => '0',
            'h_le_100'  => '2',
            'h_le_250'  => '0',
            'h_le_500'  => '0',
            'h_le_1000' => '0',
            'h_le_2500' => '0',
            'h_le_5000' => '0',
            'h_gt_5000' => '0',
        ];

        $db   = $this->mockConnection(['FROM metrics_rollup' => [$row]]);
        $repo = new MetricsRepository($db);

        $history = $repo->history(60, 60);
        $this->assertCount(1, $history);

        $h = $history[0];
        $this->assertSame('2026-07-01 10:00:00', $h['bucket']);
        $this->assertSame(1000, $h['bytes_in']);   // coerced to int
        $this->assertSame(2000, $h['bytes_out']);
        $this->assertSame(10, $h['requests']);
        $this->assertSame(1, $h['errors']);
        // 10 samples: 8 <=10ms, 2 <=100ms. p50 target=5 -> h_le_10 -> 10.
        $this->assertSame(10, $h['p50_ms']);
        // p95 target=ceil(.95*10)=10 -> cumulative 8 then +2=10 at h_le_100 -> 100.
        $this->assertSame(100, $h['p95_ms']);

        // Guard the ONLY_FULL_GROUP_BY fix (proven end-to-end in
        // tests/Integration/Stats/MetricsReadQueriesTest): the history query must
        // GROUP BY the `bucket` SELECT alias, never the raw
        // FLOOR(UNIX_TIMESTAMP(...) / ?) expression — MySQL 8's default sql_mode
        // rejects the latter with error 1055 and the admin charts 500.
        $historySql = implode("\n", $this->seenSql);
        $this->assertStringContainsString('GROUP BY bucket', $historySql);
        $this->assertStringNotContainsString('GROUP BY FLOOR', $historySql);
    }

    public function test_history_skips_non_array_rows(): void
    {
        $db   = $this->mockConnection(['FROM metrics_rollup' => [['bucket' => 'x'], []]]);
        $repo = new MetricsRepository($db);

        // Both rows are arrays here; assert the shape stays consistent and the
        // empty row hydrates to zeros rather than throwing.
        $history = $repo->history();
        $this->assertCount(2, $history);
        $this->assertSame(0, $history[1]['requests']);
    }

    public function test_live_connections_hydrates_rows(): void
    {
        $rows = [
            [
                'connection_id'  => '0-1',
                'kind'           => 'stream',
                'user_id'        => 'user-1',
                'remote_ip'      => '9.9.9.9',
                'session_id'     => null,
                'media_item_id'  => 'media-1',
                'bytes_in'       => '500',
                'bytes_out'      => '9000',
                'bytes_in_rate'  => '100',
                'bytes_out_rate' => '1800',
                'opened_at'      => '2026-07-01 10:00:00',
                'last_seen_at'   => '2026-07-01 10:00:05',
            ],
        ];

        $db   = $this->mockConnection(['FROM metrics_connections' => $rows]);
        $repo = new MetricsRepository($db);

        $conns = $repo->liveConnections(10);
        $this->assertCount(1, $conns);

        $c = $conns[0];
        $this->assertSame('0-1', $c['connection_id']);
        $this->assertSame('stream', $c['kind']);
        $this->assertSame('user-1', $c['user_id']);
        $this->assertNull($c['session_id']);       // NULL passes through
        $this->assertSame('media-1', $c['media_item_id']);
        $this->assertSame(500, $c['bytes_in']);    // string coerced to int
        $this->assertSame(1800, $c['bytes_out_rate']);
        $this->assertSame('2026-07-01 10:00:00', $c['opened_at']);
    }

    public function test_top_routes_computes_avg_and_hydrates(): void
    {
        $rows = [
            [
                'method'          => 'GET',
                'route'           => '/api/v1/media',
                'request_count'   => '10',
                'error_count'     => '2',
                'duration_ms_sum' => '250',
                'max_ms'          => '90',
            ],
            [
                'method'          => 'POST',
                'route'           => '/api/v1/media',
                'request_count'   => '0', // avoid divide-by-zero -> avg 0
                'error_count'     => '0',
                'duration_ms_sum' => '0',
                'max_ms'          => '0',
            ],
        ];

        $db   = $this->mockConnection(['FROM metrics_route_rollup' => $rows]);
        $repo = new MetricsRepository($db);

        $routes = $repo->topRoutes(15, 20);
        $this->assertCount(2, $routes);

        $this->assertSame('GET', $routes[0]['method']);
        $this->assertSame('/api/v1/media', $routes[0]['route']);
        $this->assertSame(10, $routes[0]['request_count']);
        $this->assertSame(2, $routes[0]['error_count']);
        $this->assertSame(25, $routes[0]['avg_ms']); // round(250/10)
        $this->assertSame(90, $routes[0]['max_ms']);

        // Zero request_count -> avg 0 (no divide-by-zero).
        $this->assertSame(0, $routes[1]['avg_ms']);
    }

    public function test_active_connection_count_uses_configured_ttl(): void
    {
        // Capture the bindings the COUNT query is issued with.
        $captured = [];
        $mock = $this->createMock(Connection::class);
        $mock->method('query')->willReturnCallback(
            function (string $sql, array $bindings = []) use (&$captured): array {
                if (str_contains($sql, 'COUNT(*)')) {
                    $captured = $bindings;
                    return [['c' => 2]];
                }
                return []; // empty rollup
            }
        );

        $repo = new MetricsRepository($mock, ['connection_ttl_seconds' => 42]);
        $snap = $repo->snapshot(10);

        $this->assertSame(2, $snap['active_connections']);
        // The configured TTL is bound into the live-count window (positional ?).
        $this->assertSame([42], $captured);
    }

    public function test_active_connection_count_defaults_ttl_to_fifteen(): void
    {
        $captured = [];
        $mock = $this->createMock(Connection::class);
        $mock->method('query')->willReturnCallback(
            function (string $sql, array $bindings = []) use (&$captured): array {
                if (str_contains($sql, 'COUNT(*)')) {
                    $captured = $bindings;
                    return [['c' => 0]];
                }
                return [];
            }
        );

        $repo = new MetricsRepository($mock); // no config
        $repo->snapshot(10);

        $this->assertSame([15], $captured);
    }

    public function test_all_read_queries_are_select_leading(): void
    {
        $db = $this->mockConnection([
            'FROM metrics_rollup'       => [],
            'FROM metrics_connections'  => [['c' => 0]],
            'FROM metrics_route_rollup' => [],
        ]);

        $repo = new MetricsRepository($db);
        $repo->snapshot();
        $repo->history();
        $repo->liveConnections();
        $repo->topRoutes();

        $this->assertNotEmpty($this->seenSql);
        foreach ($this->seenSql as $sql) {
            // The workerman/mysql driver returns NULL for WITH/CTE-leading
            // statements; every read must start with SELECT.
            $this->assertStringStartsWith('SELECT', ltrim($sql));
        }
    }
}
