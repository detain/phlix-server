<?php

/**
 * Phlix media server component: Metrics.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Stats\Metrics;

use Workerman\MySQL\Connection;

/**
 * Persists a worker's {@see MetricsRegistry} state to MySQL.
 *
 * Invoked from a per-worker `Workerman\Timer` (wired in step S2). Each worker
 * only ever writes rows keyed by its own `worker_id`, so the UPSERTs
 * (`INSERT ... ON DUPLICATE KEY UPDATE col = col + VALUES(col)`) never contend
 * across workers and no locking is required. Rates for the live-connection panel
 * are derived from the delta between successive flushes divided by the flush
 * interval (previous cumulative byte counts are tracked here per connection).
 *
 * All writes use positional `?` placeholders. DATETIME columns receive
 * `Y-m-d H:i:s` strings derived from the caller-supplied `$nowTs` / bucket
 * timestamps (the class makes no clock calls of its own).
 *
 * @package Phlix\Stats\Metrics
 * @since S1
 */
final class MetricsFlushService
{
    /** @var Connection MySQL connection (positional binding). */
    private Connection $db;

    /** @var MetricsCollector Owns the registry this service drains. */
    private MetricsCollector $collector;

    /** @var int Days of rollup history to retain before pruning. */
    private int $retentionDays;

    /** @var int Seconds of connection inactivity before a row is pruned. */
    private int $connectionTtlSeconds;

    /** @var int Flush cadence in seconds (rate denominator). */
    private int $flushIntervalSeconds;

    /**
     * Previous cumulative byte counts per connection id, for rate computation.
     *
     * @var array<string, array{in: int, out: int}>
     */
    private array $previousBytes = [];

    /** @var int Flush counter used to throttle pruning to ~once/minute. */
    private int $flushTick = 0;

    /**
     * @param Connection       $db        MySQL connection.
     * @param MetricsCollector $collector Provides the registry to drain.
     * @param array<string, mixed> $config config/metrics.php array (reads
     *        retention_days, connection_ttl_seconds, flush_interval_seconds).
     */
    public function __construct(Connection $db, MetricsCollector $collector, array $config)
    {
        $this->db                   = $db;
        $this->collector            = $collector;
        $this->retentionDays        = $this->cfgInt($config, 'retention_days', 7);
        $this->connectionTtlSeconds = $this->cfgInt($config, 'connection_ttl_seconds', 15);
        $this->flushIntervalSeconds = max(1, $this->cfgInt($config, 'flush_interval_seconds', 5));
    }

    /**
     * Drain the registry and persist rollups + live connections for one worker.
     *
     * Upserts overall + per-route rollups (accumulating VALUES into existing rows)
     * and the active-connection snapshot (computing per-connection byte rates from
     * the delta since the previous flush). Pruning is invoked but internally
     * throttled so old rows are cleaned up without a DELETE on every 5s tick.
     *
     * @param int $workerId The forked worker's id (SMALLINT).
     * @param int $nowTs     Unix timestamp of the flush.
     *
     * @return void
     */
    public function flush(int $workerId, int $nowTs): void
    {
        if (!$this->collector->isEnabled()) {
            return;
        }

        $registry = $this->collector->registry();
        $drained  = $registry->drainRollups($nowTs);

        $this->flushOverall($workerId, $drained['overall']);
        $this->flushRoutes($workerId, $drained['routes']);
        $this->flushConnections($workerId, $registry->snapshotConnections(), $nowTs);

        // Prune roughly once per minute rather than every flush. Evict stale rows
        // from BOTH the persisted tables and the in-RAM connection map (the latter
        // now that the WS close hook records a final touch instead of an immediate
        // delete — see MetricsRegistry::pruneStaleConnections()).
        $this->flushTick++;
        $ticksPerMinute = max(1, (int) round(60 / $this->flushIntervalSeconds));
        if ($this->flushTick % $ticksPerMinute === 0) {
            $this->prune($nowTs);
            $registry->pruneStaleConnections($nowTs - $this->connectionTtlSeconds);
        }
    }

    /**
     * Delete stale connection rows and rollups older than the retention window.
     *
     * @param int $nowTs Unix timestamp used as the "now" reference.
     *
     * @return void
     */
    public function prune(int $nowTs): void
    {
        $connCutoff   = $this->datetime($nowTs - $this->connectionTtlSeconds);
        $rollupCutoff = $this->datetime($nowTs - ($this->retentionDays * 86400));

        $this->db->query(
            "DELETE FROM metrics_connections WHERE last_seen_at < ?",
            [$connCutoff]
        );
        $this->db->query(
            "DELETE FROM metrics_rollup WHERE bucket_started_at < ?",
            [$rollupCutoff]
        );
        $this->db->query(
            "DELETE FROM metrics_route_rollup WHERE bucket_started_at < ?",
            [$rollupCutoff]
        );
    }

    /**
     * Upsert the drained overall buckets.
     *
     * @param int $workerId Worker id.
     * @param array<int, array{
     *     bucket_started_at: int,
     *     request_count: int,
     *     error_count: int,
     *     duration_ms_sum: int,
     *     duration_ms_max: int,
     *     bytes_in: int,
     *     bytes_out: int,
     *     histogram: array<int, int>
     * }> $buckets
     *
     * @return void
     */
    private function flushOverall(int $workerId, array $buckets): void
    {
        foreach ($buckets as $b) {
            $h = $b['histogram'];
            $this->db->query(
                "INSERT INTO metrics_rollup
                 (bucket_started_at, worker_id, request_count, error_count,
                  duration_ms_sum, duration_ms_max, bytes_in, bytes_out,
                  h_le_10, h_le_50, h_le_100, h_le_250, h_le_500,
                  h_le_1000, h_le_2500, h_le_5000, h_gt_5000)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     request_count   = request_count + VALUES(request_count),
                     error_count     = error_count + VALUES(error_count),
                     duration_ms_sum = duration_ms_sum + VALUES(duration_ms_sum),
                     duration_ms_max = GREATEST(duration_ms_max, VALUES(duration_ms_max)),
                     bytes_in        = bytes_in + VALUES(bytes_in),
                     bytes_out       = bytes_out + VALUES(bytes_out),
                     h_le_10         = h_le_10 + VALUES(h_le_10),
                     h_le_50         = h_le_50 + VALUES(h_le_50),
                     h_le_100        = h_le_100 + VALUES(h_le_100),
                     h_le_250        = h_le_250 + VALUES(h_le_250),
                     h_le_500        = h_le_500 + VALUES(h_le_500),
                     h_le_1000       = h_le_1000 + VALUES(h_le_1000),
                     h_le_2500       = h_le_2500 + VALUES(h_le_2500),
                     h_le_5000       = h_le_5000 + VALUES(h_le_5000),
                     h_gt_5000       = h_gt_5000 + VALUES(h_gt_5000)",
                [
                    $this->datetime($b['bucket_started_at']),
                    $workerId,
                    $b['request_count'],
                    $b['error_count'],
                    $b['duration_ms_sum'],
                    $b['duration_ms_max'],
                    $b['bytes_in'],
                    $b['bytes_out'],
                    $h[10] ?? 0,
                    $h[50] ?? 0,
                    $h[100] ?? 0,
                    $h[250] ?? 0,
                    $h[500] ?? 0,
                    $h[1000] ?? 0,
                    $h[2500] ?? 0,
                    $h[5000] ?? 0,
                    $h[-1] ?? 0,
                ]
            );
        }
    }

    /**
     * Upsert the drained per-route buckets.
     *
     * @param int $workerId Worker id.
     * @param array<int, array{
     *     bucket_started_at: int,
     *     method: string,
     *     route: string,
     *     request_count: int,
     *     error_count: int,
     *     duration_ms_sum: int,
     *     duration_ms_max: int
     * }> $routes
     *
     * @return void
     */
    private function flushRoutes(int $workerId, array $routes): void
    {
        foreach ($routes as $r) {
            $this->db->query(
                "INSERT INTO metrics_route_rollup
                 (bucket_started_at, worker_id, method, route,
                  request_count, error_count, duration_ms_sum, duration_ms_max)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     request_count   = request_count + VALUES(request_count),
                     error_count     = error_count + VALUES(error_count),
                     duration_ms_sum = duration_ms_sum + VALUES(duration_ms_sum),
                     duration_ms_max = GREATEST(duration_ms_max, VALUES(duration_ms_max))",
                [
                    $this->datetime($r['bucket_started_at']),
                    $workerId,
                    $r['method'],
                    $r['route'],
                    $r['request_count'],
                    $r['error_count'],
                    $r['duration_ms_sum'],
                    $r['duration_ms_max'],
                ]
            );
        }
    }

    /**
     * Upsert the active-connection snapshot with computed byte rates.
     *
     * @param int $workerId Worker id.
     * @param array<string, array{
     *     kind: string,
     *     user_id: ?string,
     *     remote_ip: ?string,
     *     session_id: ?string,
     *     media_item_id: ?string,
     *     bytes_in: int,
     *     bytes_out: int,
     *     opened_at: int,
     *     last_seen_at: int
     * }> $connections
     * @param int $nowTs Unix timestamp of the flush.
     *
     * @return void
     */
    private function flushConnections(int $workerId, array $connections, int $nowTs): void
    {
        foreach ($connections as $id => $c) {
            $prev = $this->previousBytes[$id] ?? ['in' => $c['bytes_in'], 'out' => $c['bytes_out']];
            $inRate  = (int) max(0, intdiv($c['bytes_in'] - $prev['in'], $this->flushIntervalSeconds));
            $outRate = (int) max(0, intdiv($c['bytes_out'] - $prev['out'], $this->flushIntervalSeconds));
            $this->previousBytes[$id] = ['in' => $c['bytes_in'], 'out' => $c['bytes_out']];

            $this->db->query(
                "INSERT INTO metrics_connections
                 (connection_id, worker_id, kind, user_id, remote_ip, session_id,
                  media_item_id, bytes_in, bytes_out, bytes_in_rate, bytes_out_rate,
                  opened_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     kind           = VALUES(kind),
                     user_id        = VALUES(user_id),
                     remote_ip      = VALUES(remote_ip),
                     session_id     = VALUES(session_id),
                     media_item_id  = VALUES(media_item_id),
                     bytes_in       = VALUES(bytes_in),
                     bytes_out      = VALUES(bytes_out),
                     bytes_in_rate  = VALUES(bytes_in_rate),
                     bytes_out_rate = VALUES(bytes_out_rate),
                     last_seen_at   = VALUES(last_seen_at)",
                [
                    $id,
                    $workerId,
                    $c['kind'],
                    $c['user_id'],
                    $c['remote_ip'],
                    $c['session_id'],
                    $c['media_item_id'],
                    $c['bytes_in'],
                    $c['bytes_out'],
                    $inRate,
                    $outRate,
                    $this->datetime($c['opened_at']),
                    $this->datetime($c['last_seen_at']),
                ]
            );
        }

        // Forget rate-tracking state for connections that have gone away so the
        // map cannot grow without bound in a resident worker.
        foreach (array_keys($this->previousBytes) as $trackedId) {
            if (!isset($connections[$trackedId])) {
                unset($this->previousBytes[$trackedId]);
            }
        }
    }

    /**
     * Format a Unix timestamp as a MySQL DATETIME string (UTC-agnostic; uses the
     * process timezone, consistent with the rest of the schema which uses NOW()).
     *
     * @param int $ts Unix timestamp.
     *
     * @return string `Y-m-d H:i:s`.
     */
    private function datetime(int $ts): string
    {
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Read an int config value with a default.
     *
     * @param array<string, mixed> $config
     * @param string               $key
     * @param int                  $default
     *
     * @return int
     */
    private function cfgInt(array $config, string $key, int $default): int
    {
        $v = $config[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return $default;
    }
}
