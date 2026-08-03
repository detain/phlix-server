<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Stats\Metrics;

use Phlix\Stats\Metrics\MetricsCollector;
use Phlix\Stats\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MetricsCollector}, the per-worker façade.
 *
 * The collector owns "now" via an injected clock and either delegates to the
 * shared {@see MetricsRegistry} (enabled) or no-ops on the hot path (disabled).
 * A fixed clock makes the delegated timestamp deterministic so we can assert the
 * exact bucket the registry recorded into.
 */
final class MetricsCollectorTest extends TestCase
{
    /** @var callable(): int A frozen clock returning 1000. */
    private $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = static fn (): int => 1000;
    }

    public function test_enabled_collector_delegates_request_to_registry(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true, $this->clock);

        $collector->recordRequest('GET', '/a', 500, 42.0, 11, 22);

        $drained = $registry->drainRollups(1000);
        $this->assertArrayHasKey(1000, $drained['overall']);
        $bucket = $drained['overall'][1000];
        $this->assertSame(1, $bucket['request_count']);
        $this->assertSame(1, $bucket['error_count']);
        $this->assertSame(42, $bucket['duration_ms_sum']);
        $this->assertSame(11, $bucket['bytes_in']);
        $this->assertSame(22, $bucket['bytes_out']);
    }

    public function test_disabled_collector_does_not_record_requests(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, false, $this->clock);

        $collector->recordRequest('GET', '/a', 200, 42.0, 11, 22);

        $drained = $registry->drainRollups(1000);
        $this->assertSame([], $drained['overall']);
        $this->assertSame([], $drained['routes']);
    }

    public function test_enabled_collector_delegates_connection_lifecycle(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true, $this->clock);

        $collector->openConnection('0-1', 'stream', 'user-1', '1.2.3.4', 'sess', 'media');
        $collector->touchConnection('0-1', 100, 200);

        $snap = $registry->snapshotConnections();
        $this->assertArrayHasKey('0-1', $snap);
        $this->assertSame('stream', $snap['0-1']['kind']);
        $this->assertSame('user-1', $snap['0-1']['user_id']);
        $this->assertSame(100, $snap['0-1']['bytes_in']);
        $this->assertSame(200, $snap['0-1']['bytes_out']);
        $this->assertSame(1000, $snap['0-1']['opened_at']);

        $collector->closeConnection('0-1');
        $this->assertArrayNotHasKey('0-1', $registry->snapshotConnections());
    }

    public function test_disabled_collector_does_not_touch_connections(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, false, $this->clock);

        $collector->openConnection('0-1', 'http', null, null, null, null);
        $collector->touchConnection('0-1', 100, 200);
        $collector->closeConnection('0-1');

        $this->assertSame([], $registry->snapshotConnections());
    }

    public function test_open_connection_uses_defaults_for_optional_args(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true, $this->clock);

        $collector->openConnection('0-2', 'http');

        $c = $registry->snapshotConnections()['0-2'];
        $this->assertNull($c['user_id']);
        $this->assertNull($c['remote_ip']);
        $this->assertNull($c['session_id']);
        $this->assertNull($c['media_item_id']);
    }

    public function test_is_enabled_reflects_constructor_flag(): void
    {
        $registry = new MetricsRegistry(10);
        $this->assertTrue((new MetricsCollector($registry, true))->isEnabled());
        $this->assertFalse((new MetricsCollector($registry, false))->isEnabled());
    }

    public function test_registry_accessor_returns_shared_instance(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);

        $this->assertSame($registry, $collector->registry());
    }

    public function test_default_clock_is_time(): void
    {
        // With no injected clock the collector must still record (against the
        // current wall clock's bucket) without throwing.
        $registry  = new MetricsRegistry(1);
        $collector = new MetricsCollector($registry, true);

        $before = time();
        $collector->recordRequest('GET', '/a', 200, 1.0, 0, 0);
        $after = time();

        $drained = $registry->drainRollups(time());
        $this->assertNotEmpty($drained['overall']);
        // The single recorded bucket must be within [before, after].
        $bucketTs = array_key_first($drained['overall']);
        $this->assertIsInt($bucketTs);
        $this->assertGreaterThanOrEqual($before, $bucketTs);
        $this->assertLessThanOrEqual($after, $bucketTs);
    }
}
