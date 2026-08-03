<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Stats\Metrics;

use Phlix\Stats\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MetricsRegistry}, the per-worker in-RAM accumulator.
 *
 * The registry is fully deterministic (every method that needs "now" takes an
 * explicit `$nowTs`), so these tests exercise time-bucketing, the latency
 * histogram at its bucket boundaries, error classification, the route
 * cardinality cap and the connection lifecycle without any clock stubbing.
 */
final class MetricsRegistryTest extends TestCase
{
    /** Default histogram bounds used by the class (and its emptyHistogram()). */
    private const BOUNDS = [10, 50, 100, 250, 500, 1000, 2500, 5000];

    private function registry(int $bucketSeconds = 10, int $cap = 200): MetricsRegistry
    {
        return new MetricsRegistry($bucketSeconds, self::BOUNDS, $cap);
    }

    public function test_record_request_populates_current_bucket(): void
    {
        $reg = $this->registry(10);
        // 1234 -> floor(1234/10)*10 = 1230
        $reg->recordRequest('GET', '/api/v1/media', 200, 42.0, 100, 900, 1234);

        $drained = $reg->drainRollups(1234);

        $this->assertArrayHasKey('overall', $drained);
        $this->assertArrayHasKey(1230, $drained['overall']);

        $bucket = $drained['overall'][1230];
        $this->assertSame(1230, $bucket['bucket_started_at']);
        $this->assertSame(1, $bucket['request_count']);
        $this->assertSame(0, $bucket['error_count']);
        $this->assertSame(42, $bucket['duration_ms_sum']);
        $this->assertSame(42, $bucket['duration_ms_max']);
        $this->assertSame(100, $bucket['bytes_in']);
        $this->assertSame(900, $bucket['bytes_out']);
    }

    public function test_requests_land_in_separate_time_buckets(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('GET', '/a', 200, 5.0, 0, 0, 1000);   // bucket 1000
        $reg->recordRequest('GET', '/a', 200, 5.0, 0, 0, 1009);   // bucket 1000
        $reg->recordRequest('GET', '/a', 200, 5.0, 0, 0, 1010);   // bucket 1010

        $drained = $reg->drainRollups(1010);

        $this->assertCount(2, $drained['overall']);
        $this->assertSame(2, $drained['overall'][1000]['request_count']);
        $this->assertSame(1, $drained['overall'][1010]['request_count']);
    }

    public function test_drain_resets_accumulators(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('GET', '/a', 200, 5.0, 0, 0, 1000);

        $first = $reg->drainRollups(1000);
        $this->assertNotEmpty($first['overall']);
        $this->assertNotEmpty($first['routes']);

        $second = $reg->drainRollups(1000);
        $this->assertSame([], $second['overall']);
        $this->assertSame([], $second['routes']);
    }

    public function test_error_classification_counts_5xx_only(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('GET', '/a', 200, 1.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/a', 404, 1.0, 0, 0, 1000); // client error, NOT counted
        $reg->recordRequest('GET', '/a', 499, 1.0, 0, 0, 1000); // below 500, NOT counted
        $reg->recordRequest('GET', '/a', 500, 1.0, 0, 0, 1000); // error
        $reg->recordRequest('GET', '/a', 503, 1.0, 0, 0, 1000); // error

        $drained = $reg->drainRollups(1000);

        $this->assertSame(5, $drained['overall'][1000]['request_count']);
        $this->assertSame(2, $drained['overall'][1000]['error_count']);
    }

    public function test_duration_sum_and_max_round_and_track_peak(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('GET', '/a', 200, 10.4, 0, 0, 1000); // rounds to 10
        $reg->recordRequest('GET', '/a', 200, 10.6, 0, 0, 1000); // rounds to 11
        $reg->recordRequest('GET', '/a', 200, 100.0, 0, 0, 1000);

        $bucket = $reg->drainRollups(1000)['overall'][1000];
        $this->assertSame(10 + 11 + 100, $bucket['duration_ms_sum']);
        $this->assertSame(100, $bucket['duration_ms_max']);
    }

    /**
     * A request whose elapsed time is exactly equal to a bound counts in THAT
     * bound (the classifier uses `elapsed <= bound`), and the empty histogram
     * carries one key per bound plus the `-1` overflow key.
     */
    public function test_histogram_bucketing_at_boundaries(): void
    {
        $reg = $this->registry(10);
        // Exactly on the 10ms bound -> h[10].
        $reg->recordRequest('GET', '/a', 200, 10.0, 0, 0, 1000);
        // 11ms -> next bound up is 50 -> h[50].
        $reg->recordRequest('GET', '/a', 200, 11.0, 0, 0, 1000);
        // Exactly on the 50ms bound -> h[50].
        $reg->recordRequest('GET', '/a', 200, 50.0, 0, 0, 1000);
        // Exactly on the last bound (5000) -> h[5000], NOT overflow.
        $reg->recordRequest('GET', '/a', 200, 5000.0, 0, 0, 1000);
        // Just over the last bound -> overflow bucket -1.
        $reg->recordRequest('GET', '/a', 200, 5001.0, 0, 0, 1000);

        $h = $reg->drainRollups(1000)['overall'][1000]['histogram'];

        // One key per bound plus the -1 overflow key.
        $expectedKeys = array_merge(self::BOUNDS, [-1]);
        $this->assertSame($expectedKeys, array_keys($h));

        $this->assertSame(1, $h[10]);
        $this->assertSame(2, $h[50]);   // 11ms and 50ms both fold here
        $this->assertSame(0, $h[100]);
        $this->assertSame(1, $h[5000]); // exactly on last bound
        $this->assertSame(1, $h[-1]);   // 5001ms overflow
    }

    public function test_zero_ms_request_lands_in_lowest_bucket(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('GET', '/a', 200, 0.0, 0, 0, 1000);

        $h = $reg->drainRollups(1000)['overall'][1000]['histogram'];
        $this->assertSame(1, $h[10]);
        $this->assertSame(0, $h[-1]);
    }

    public function test_route_rollup_normalises_method_and_records_per_route(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('get', '/api/v1/media', 200, 5.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/api/v1/media', 500, 15.0, 0, 0, 1000);
        $reg->recordRequest('POST', '/api/v1/media', 201, 25.0, 0, 0, 1000);

        $routes = $reg->drainRollups(1000)['routes'];
        $this->assertCount(2, $routes); // (GET /api/v1/media) and (POST /api/v1/media)

        $byKey = [];
        foreach ($routes as $r) {
            $byKey[$r['method'] . ' ' . $r['route']] = $r;
        }

        $this->assertArrayHasKey('GET /api/v1/media', $byKey);
        $get = $byKey['GET /api/v1/media'];
        $this->assertSame(1000, $get['bucket_started_at']);
        $this->assertSame(2, $get['request_count']);
        $this->assertSame(1, $get['error_count']);
        $this->assertSame(5 + 15, $get['duration_ms_sum']);
        $this->assertSame(15, $get['duration_ms_max']);

        $this->assertArrayHasKey('POST /api/v1/media', $byKey);
        $this->assertSame(1, $byKey['POST /api/v1/media']['request_count']);
    }

    public function test_empty_route_is_normalised_to_slash(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('GET', '', 200, 5.0, 0, 0, 1000);

        $routes = $reg->drainRollups(1000)['routes'];
        $this->assertCount(1, $routes);
        $this->assertSame('/', $routes[0]['route']);
    }

    /**
     * Once the distinct-route cap is reached in a bucket, every FURTHER new
     * route folds into the OTHER_ROUTE sentinel, while already-tracked routes
     * keep accumulating under their own name.
     */
    public function test_route_cardinality_cap_folds_to_other(): void
    {
        $cap = 3;
        $reg = $this->registry(10, $cap);

        // Fill the cap with 3 distinct routes.
        $reg->recordRequest('GET', '/r0', 200, 5.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/r1', 200, 5.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/r2', 200, 5.0, 0, 0, 1000);

        // Two further NEW routes -> both fold into __other__.
        $reg->recordRequest('GET', '/r3', 500, 5.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/r4', 200, 5.0, 0, 0, 1000);

        // An already-tracked route still records under its own name.
        $reg->recordRequest('GET', '/r0', 200, 5.0, 0, 0, 1000);

        $routes = $reg->drainRollups(1000)['routes'];
        $byRoute = [];
        foreach ($routes as $r) {
            $byRoute[$r['route']] = $r;
        }

        // 3 real routes + the single __other__ sentinel = 4 distinct entries.
        $this->assertCount(4, $routes);
        $this->assertArrayHasKey(MetricsRegistry::OTHER_ROUTE, $byRoute);

        // /r0 accumulated twice.
        $this->assertSame(2, $byRoute['/r0']['request_count']);

        // The two overflow routes folded into __other__ (one of them a 5xx).
        $other = $byRoute[MetricsRegistry::OTHER_ROUTE];
        $this->assertSame(2, $other['request_count']);
        $this->assertSame(1, $other['error_count']);
    }

    public function test_open_touch_and_snapshot_connection(): void
    {
        $reg = $this->registry(10);
        $reg->openConnection('0-1', 'websocket', 'user-1', '10.0.0.5', 'sess-1', 'media-9', 1000);
        $reg->touchConnection('0-1', 500, 1500, 1005);

        $snap = $reg->snapshotConnections();
        $this->assertArrayHasKey('0-1', $snap);

        $c = $snap['0-1'];
        $this->assertSame('websocket', $c['kind']);
        $this->assertSame('user-1', $c['user_id']);
        $this->assertSame('10.0.0.5', $c['remote_ip']);
        $this->assertSame('sess-1', $c['session_id']);
        $this->assertSame('media-9', $c['media_item_id']);
        $this->assertSame(500, $c['bytes_in']);   // cumulative, not a delta
        $this->assertSame(1500, $c['bytes_out']);
        $this->assertSame(1000, $c['opened_at']);
        $this->assertSame(1005, $c['last_seen_at']);
    }

    public function test_open_connection_normalises_unknown_kind_to_http(): void
    {
        $reg = $this->registry(10);
        $reg->openConnection('0-1', 'BOGUS', null, null, null, null, 1000);

        $this->assertSame('http', $reg->snapshotConnections()['0-1']['kind']);
    }

    public function test_touch_without_open_creates_connection(): void
    {
        $reg = $this->registry(10);
        $reg->touchConnection('0-7', 10, 20, 2000);

        $snap = $reg->snapshotConnections();
        $this->assertArrayHasKey('0-7', $snap);
        $this->assertSame('http', $snap['0-7']['kind']);
        $this->assertSame(10, $snap['0-7']['bytes_in']);
        $this->assertSame(20, $snap['0-7']['bytes_out']);
        $this->assertSame(2000, $snap['0-7']['opened_at']);
        $this->assertSame(2000, $snap['0-7']['last_seen_at']);
    }

    public function test_close_connection_removes_it_from_snapshot(): void
    {
        $reg = $this->registry(10);
        $reg->openConnection('0-1', 'http', null, null, null, null, 1000);
        $reg->openConnection('0-2', 'http', null, null, null, null, 1000);

        $reg->closeConnection('0-1');

        $snap = $reg->snapshotConnections();
        $this->assertArrayNotHasKey('0-1', $snap);
        $this->assertArrayHasKey('0-2', $snap);
    }

    public function test_prune_stale_connections_evicts_only_aged_rows(): void
    {
        $reg = $this->registry(10);
        // 'idle' opened at 900 and never touched again; 'live' touched up to 1000.
        $reg->openConnection('idle', 'websocket', null, null, null, null, 900);
        $reg->openConnection('live', 'websocket', null, null, null, null, 900);
        $reg->touchConnection('live', 10, 20, 1000);
        // 'edge' sits exactly at the cutoff — must be retained (strict less-than).
        $reg->touchConnection('edge', 0, 0, 990);

        $reg->pruneStaleConnections(990);

        $snap = $reg->snapshotConnections();
        $this->assertArrayNotHasKey('idle', $snap, 'A connection idle past the cutoff is evicted.');
        $this->assertArrayHasKey('live', $snap, 'A recently-touched connection survives.');
        $this->assertArrayHasKey('edge', $snap, 'last_seen_at == cutoff is retained (strict <).');
    }

    public function test_snapshot_does_not_reset_connections(): void
    {
        $reg = $this->registry(10);
        $reg->openConnection('0-1', 'http', null, null, null, null, 1000);

        $this->assertNotEmpty($reg->snapshotConnections());
        // Second call still returns the same live connection (no reset).
        $this->assertNotEmpty($reg->snapshotConnections());
    }

    public function test_latency_bounds_are_sorted_and_exposed(): void
    {
        $reg = new MetricsRegistry(10, [500, 10, 100], 200);
        $this->assertSame([10, 100, 500], $reg->latencyBounds());
    }

    public function test_bucket_seconds_floored_to_one_minimum(): void
    {
        // A zero bucketSeconds is clamped to 1, so each second is its own bucket.
        $reg = new MetricsRegistry(0, self::BOUNDS, 200);
        $reg->recordRequest('GET', '/a', 200, 1.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/a', 200, 1.0, 0, 0, 1001);

        $drained = $reg->drainRollups(1001);
        $this->assertArrayHasKey(1000, $drained['overall']);
        $this->assertArrayHasKey(1001, $drained['overall']);
    }
}
