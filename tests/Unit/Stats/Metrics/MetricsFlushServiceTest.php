<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Stats\Metrics;

use Phlix\Stats\Metrics\MetricsCollector;
use Phlix\Stats\Metrics\MetricsFlushService;
use Phlix\Stats\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see MetricsFlushService}, which drains a worker's registry
 * into MySQL.
 *
 * The Workerman MySQL {@see Connection} is mocked and every `query()` call is
 * captured (SQL + bindings) so we can assert the UPSERT / DELETE SQL fragments,
 * the exact bound parameters (worker id, counts, DATETIME strings) and — across
 * two flushes — the per-connection byte-rate computation.
 *
 * @covers \Phlix\Stats\Metrics\MetricsFlushService
 */
final class MetricsFlushServiceTest extends TestCase
{
    /**
     * @var array<int, array{sql: string, params: array<int, mixed>}>
     */
    private array $queries = [];

    /**
     * Build a mocked Connection that records every query() call into
     * $this->queries and returns an empty result set.
     */
    private function mockConnection(): Connection
    {
        $mock = $this->createMock(Connection::class);
        $mock->method('query')->willReturnCallback(
            function (string $sql, array $bindings = []): array {
                $this->queries[] = ['sql' => $sql, 'params' => $bindings];
                return [];
            }
        );
        return $mock;
    }

    /**
     * @param array<string, mixed> $configOverrides
     */
    private function service(Connection $db, MetricsCollector $collector, array $configOverrides = []): MetricsFlushService
    {
        $config = array_merge([
            'retention_days'         => 7,
            'connection_ttl_seconds' => 15,
            'flush_interval_seconds' => 5,
        ], $configOverrides);

        return new MetricsFlushService($db, $collector, $config);
    }

    /**
     * @return array<int, array{sql: string, params: array<int, mixed>}>
     */
    private function queriesMatching(string $needle): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (array $q): bool => str_contains($q['sql'], $needle)
        ));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->queries = [];
    }

    public function test_flush_noops_when_collector_disabled(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, false);
        $db        = $this->mockConnection();

        $registry->recordRequest('GET', '/a', 200, 5.0, 0, 0, 1000);

        $this->service($db, $collector)->flush(3, 1000);

        $this->assertSame([], $this->queries);
    }

    public function test_flush_upserts_overall_rollup_with_histogram_columns(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $db        = $this->mockConnection();

        // Two requests in the same bucket: one 5ms (h_le_10), one 600ms (h_le_1000).
        $registry->recordRequest('GET', '/a', 200, 5.0, 100, 900, 1000);
        $registry->recordRequest('GET', '/a', 500, 600.0, 50, 50, 1000);

        $this->service($db, $collector)->flush(7, 1000);

        $overall = $this->queriesMatching('INSERT INTO metrics_rollup');
        $this->assertCount(1, $overall);

        $q = $overall[0];
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $q['sql']);
        $this->assertStringContainsString('request_count   = request_count + VALUES(request_count)', $q['sql']);
        $this->assertStringContainsString('duration_ms_max = GREATEST(duration_ms_max, VALUES(duration_ms_max))', $q['sql']);

        // Bindings: bucket-datetime, worker_id, counts, byte totals, histogram cols.
        $p = $q['params'];
        $this->assertSame(date('Y-m-d H:i:s', 1000), $p[0]); // bucket_started_at
        $this->assertSame(7, $p[1]);                          // worker_id
        $this->assertSame(2, $p[2]);                          // request_count
        $this->assertSame(1, $p[3]);                          // error_count
        $this->assertSame(605, $p[4]);                        // duration_ms_sum (5 + 600)
        $this->assertSame(600, $p[5]);                        // duration_ms_max
        $this->assertSame(150, $p[6]);                        // bytes_in (100 + 50)
        $this->assertSame(950, $p[7]);                        // bytes_out (900 + 50)
        // Histogram columns h_le_10 .. h_gt_5000 (indices 8..16).
        $this->assertSame(1, $p[8]);   // h_le_10  (the 5ms request)
        $this->assertSame(1, $p[13]);  // h_le_1000 (the 600ms request)
        $this->assertSame(0, $p[16]);  // h_gt_5000 overflow
    }

    public function test_flush_upserts_route_rollup(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $db        = $this->mockConnection();

        $registry->recordRequest('POST', '/api/v1/media', 201, 12.0, 0, 0, 1000);

        $this->service($db, $collector)->flush(2, 1000);

        $routes = $this->queriesMatching('INSERT INTO metrics_route_rollup');
        $this->assertCount(1, $routes);

        $p = $routes[0]['params'];
        $this->assertSame(date('Y-m-d H:i:s', 1000), $p[0]);
        $this->assertSame(2, $p[1]);             // worker_id
        $this->assertSame('POST', $p[2]);        // method
        $this->assertSame('/api/v1/media', $p[3]); // route
        $this->assertSame(1, $p[4]);             // request_count
    }

    public function test_connection_rate_is_zero_on_first_flush_and_delta_on_second(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $db        = $this->mockConnection();
        $service   = $this->service($db, $collector, ['flush_interval_seconds' => 5]);

        // First flush: connection has cumulative 1000 in / 5000 out.
        $registry->openConnection('0-1', 'stream', 'user-1', '9.9.9.9', null, 'media-1', 1000);
        $registry->touchConnection('0-1', 1000, 5000, 1004);
        $service->flush(1, 1005);

        $first = $this->queriesMatching('INSERT INTO metrics_connections');
        $this->assertCount(1, $first);
        // bytes_in_rate (index 9) and bytes_out_rate (index 10) are 0 on the
        // first flush because there is no previous sample to diff against.
        $this->assertSame(0, $first[0]['params'][9]);
        $this->assertSame(0, $first[0]['params'][10]);

        // Second flush 5s later: cumulative grew by 2500 in / 10000 out.
        $this->queries = [];
        $registry->touchConnection('0-1', 3500, 15000, 1009);
        $service->flush(1, 1010);

        $second = $this->queriesMatching('INSERT INTO metrics_connections');
        $this->assertCount(1, $second);
        // rate = delta / flush_interval = 2500/5 = 500 in, 10000/5 = 2000 out.
        $this->assertSame(500, $second[0]['params'][9]);
        $this->assertSame(2000, $second[0]['params'][10]);
        // Cumulative totals themselves are the latest values.
        $this->assertSame(3500, $second[0]['params'][7]); // bytes_in
        $this->assertSame(15000, $second[0]['params'][8]); // bytes_out
    }

    public function test_connection_rate_never_negative(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $db        = $this->mockConnection();
        $service   = $this->service($db, $collector);

        $registry->openConnection('0-1', 'http', null, null, null, null, 1000);
        $registry->touchConnection('0-1', 5000, 5000, 1004);
        $service->flush(1, 1005);

        // A counter reset (lower cumulative) must clamp the rate to >= 0.
        $this->queries = [];
        $registry->touchConnection('0-1', 10, 10, 1009);
        $service->flush(1, 1010);

        $conn = $this->queriesMatching('INSERT INTO metrics_connections');
        $this->assertGreaterThanOrEqual(0, $conn[0]['params'][9]);
        $this->assertGreaterThanOrEqual(0, $conn[0]['params'][10]);
    }

    public function test_prune_emits_three_deletes_with_cutoffs(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $db        = $this->mockConnection();
        $service   = $this->service($db, $collector, [
            'retention_days'         => 7,
            'connection_ttl_seconds' => 15,
        ]);

        $now = 1_000_000;
        $service->prune($now);

        $connDeletes = $this->queriesMatching('DELETE FROM metrics_connections');
        $rollupDeletes = $this->queriesMatching('DELETE FROM metrics_rollup');
        $routeDeletes = $this->queriesMatching('DELETE FROM metrics_route_rollup');

        $this->assertCount(1, $connDeletes);
        $this->assertCount(1, $rollupDeletes);
        $this->assertCount(1, $routeDeletes);

        // Connection cutoff = now - ttl; rollup cutoff = now - retention_days*86400.
        $this->assertSame(date('Y-m-d H:i:s', $now - 15), $connDeletes[0]['params'][0]);
        $this->assertSame(date('Y-m-d H:i:s', $now - 7 * 86400), $rollupDeletes[0]['params'][0]);
        $this->assertSame(date('Y-m-d H:i:s', $now - 7 * 86400), $routeDeletes[0]['params'][0]);
    }

    public function test_prune_is_throttled_across_flushes(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $db        = $this->mockConnection();
        // flush_interval 5 => ticksPerMinute = round(60/5) = 12: prune fires on
        // the 12th flush, not before.
        $service   = $this->service($db, $collector, ['flush_interval_seconds' => 5]);

        for ($i = 1; $i <= 11; $i++) {
            $service->flush(1, 1000 + $i);
        }
        $this->assertCount(0, $this->queriesMatching('DELETE FROM metrics_rollup'));

        $service->flush(1, 1012); // 12th flush
        $this->assertCount(1, $this->queriesMatching('DELETE FROM metrics_rollup'));
    }

    public function test_flush_forgets_rate_state_for_departed_connections(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $db        = $this->mockConnection();
        $service   = $this->service($db, $collector);

        $registry->openConnection('0-1', 'http', null, null, null, null, 1000);
        $registry->touchConnection('0-1', 100, 100, 1004);
        $service->flush(1, 1005);

        // Connection closes -> next flush snapshots no connections, so no
        // metrics_connections upsert is emitted and the tracked rate state is
        // pruned (no unbounded growth in the resident worker).
        $registry->closeConnection('0-1');
        $this->queries = [];
        $service->flush(1, 1010);

        $this->assertCount(0, $this->queriesMatching('INSERT INTO metrics_connections'));

        // Re-opening the SAME id starts a fresh rate baseline (rate 0 again),
        // proving the previous-bytes entry was forgotten.
        $registry->openConnection('0-1', 'http', null, null, null, null, 1015);
        $registry->touchConnection('0-1', 100, 100, 1016);
        $this->queries = [];
        $service->flush(1, 1017);

        $conn = $this->queriesMatching('INSERT INTO metrics_connections');
        $this->assertCount(1, $conn);
        $this->assertSame(0, $conn[0]['params'][9]);
        $this->assertSame(0, $conn[0]['params'][10]);
    }
}
