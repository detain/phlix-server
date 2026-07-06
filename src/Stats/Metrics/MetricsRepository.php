<?php

declare(strict_types=1);

namespace Phlix\Stats\Metrics;

use Workerman\MySQL\Connection;

/**
 * Read side of the metrics subsystem (consumed by the admin controller in S2).
 *
 * Every query is SELECT-leading (the workerman/mysql driver returns NULL for a
 * WITH/CTE-leading statement, so no CTEs are used) and aggregates ACROSS workers
 * with SUM / GROUP BY, since each worker persists its own `worker_id` rows.
 *
 * Percentile approximation: latencies are stored as a fixed histogram
 * (`h_le_10 … h_le_5000`, `h_gt_5000`). A requested percentile is located by
 * walking the cumulative histogram to the bucket that contains it and reporting
 * that bucket's UPPER bound in milliseconds (the overflow bucket reports the last
 * bound as a floor). This is a coarse upper-bound estimate — good enough for the
 * admin traffic graphs, not for SLA-grade percentiles.
 *
 * @package Phlix\Stats\Metrics
 * @since S1
 */
final class MetricsRepository implements MetricsRepositoryInterface
{
    /**
     * Histogram column name => its upper bound (ms). The final entry is the
     * overflow bucket, keyed by its column with the last real bound as its floor.
     *
     * @var array<int, array{col: string, le: int}>
     */
    private const HISTOGRAM = [
        ['col' => 'h_le_10',   'le' => 10],
        ['col' => 'h_le_50',   'le' => 50],
        ['col' => 'h_le_100',  'le' => 100],
        ['col' => 'h_le_250',  'le' => 250],
        ['col' => 'h_le_500',  'le' => 500],
        ['col' => 'h_le_1000', 'le' => 1000],
        ['col' => 'h_le_2500', 'le' => 2500],
        ['col' => 'h_le_5000', 'le' => 5000],
        ['col' => 'h_gt_5000', 'le' => 5000],
    ];

    /** @var Connection MySQL connection (positional binding). */
    private Connection $db;

    /**
     * Seconds of inactivity after which a connection row is treated as no longer
     * live. Drives the "active connections" count so the read-side liveness
     * window matches the flush service's connection TTL (config
     * `connection_ttl_seconds`, default 15).
     *
     * @var int
     */
    private int $connectionTtlSeconds;

    /**
     * @param Connection           $db     MySQL connection.
     * @param array<string, mixed> $config config/metrics.php array (reads
     *        `connection_ttl_seconds` for the live-connection count window).
     */
    public function __construct(Connection $db, array $config = [])
    {
        $this->db                   = $db;
        $this->connectionTtlSeconds = $this->cfgInt($config, 'connection_ttl_seconds', 15);
    }

    /**
     * Current server-wide snapshot over the trailing window.
     *
     * @param int $windowSeconds Trailing window in seconds (default 60).
     *
     * @return array{
     *     bytes_in_per_sec: float,
     *     bytes_out_per_sec: float,
     *     active_connections: int,
     *     requests_per_sec: float,
     *     error_rate: float,
     *     p50_ms: int,
     *     p95_ms: int,
     *     p99_ms: int
     * }
     */
    public function snapshot(int $windowSeconds = 60): array
    {
        $windowSeconds = max(1, $windowSeconds);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT
                 COALESCE(SUM(request_count), 0)   AS request_count,
                 COALESCE(SUM(error_count), 0)     AS error_count,
                 COALESCE(SUM(bytes_in), 0)        AS bytes_in,
                 COALESCE(SUM(bytes_out), 0)       AS bytes_out,
                 COALESCE(SUM(h_le_10), 0)   AS h_le_10,
                 COALESCE(SUM(h_le_50), 0)   AS h_le_50,
                 COALESCE(SUM(h_le_100), 0)  AS h_le_100,
                 COALESCE(SUM(h_le_250), 0)  AS h_le_250,
                 COALESCE(SUM(h_le_500), 0)  AS h_le_500,
                 COALESCE(SUM(h_le_1000), 0) AS h_le_1000,
                 COALESCE(SUM(h_le_2500), 0) AS h_le_2500,
                 COALESCE(SUM(h_le_5000), 0) AS h_le_5000,
                 COALESCE(SUM(h_gt_5000), 0) AS h_gt_5000
             FROM metrics_rollup
             WHERE bucket_started_at >= (NOW() - INTERVAL ? SECOND)",
            [$windowSeconds]
        );

        $row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];

        $requests = $this->toInt($row['request_count'] ?? 0);
        $errors   = $this->toInt($row['error_count'] ?? 0);
        $bytesIn  = $this->toInt($row['bytes_in'] ?? 0);
        $bytesOut = $this->toInt($row['bytes_out'] ?? 0);

        $histogram = [];
        foreach (self::HISTOGRAM as $h) {
            $histogram[$h['col']] = $this->toInt($row[$h['col']] ?? 0);
        }

        $activeConnections = $this->countLiveConnections();

        return [
            'bytes_in_per_sec'   => $bytesIn / $windowSeconds,
            'bytes_out_per_sec'  => $bytesOut / $windowSeconds,
            'active_connections' => $activeConnections,
            'requests_per_sec'   => $requests / $windowSeconds,
            'error_rate'         => $requests > 0 ? $errors / $requests : 0.0,
            'p50_ms'             => $this->percentile($histogram, 0.50),
            'p95_ms'             => $this->percentile($histogram, 0.95),
            'p99_ms'             => $this->percentile($histogram, 0.99),
        ];
    }

    /**
     * Time-series history for the charts, grouped by resolution window.
     *
     * @param int $minutes           How far back to look (default 60).
     * @param int $resolutionSeconds Grouping window in seconds (default 60).
     *
     * @return array<int, array{
     *     bucket: string,
     *     bytes_in: int,
     *     bytes_out: int,
     *     requests: int,
     *     errors: int,
     *     p50_ms: int,
     *     p95_ms: int
     * }>
     */
    public function history(int $minutes = 60, int $resolutionSeconds = 60): array
    {
        $minutes           = max(1, $minutes);
        $resolutionSeconds = max(1, $resolutionSeconds);

        // GROUP BY the `bucket` SELECT alias — NOT the raw
        // `FLOOR(UNIX_TIMESTAMP(bucket_started_at) / ?)` expression. Under
        // sql_mode=ONLY_FULL_GROUP_BY (MySQL 8 default) the checker does not
        // recognise the bucket column buried inside the SELECT's
        // FROM_UNIXTIME(FLOOR(...) * ?) as functionally dependent on that inner
        // GROUP BY expression, so it rejects the whole query with error 1055 and
        // the endpoint 500s. Grouping by the alias makes the grouped value IS the
        // selected value, which satisfies the dependency check (and drops the
        // now-redundant fourth parameter).
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT
                 FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(bucket_started_at) / ?) * ?) AS bucket,
                 COALESCE(SUM(bytes_in), 0)      AS bytes_in,
                 COALESCE(SUM(bytes_out), 0)     AS bytes_out,
                 COALESCE(SUM(request_count), 0) AS requests,
                 COALESCE(SUM(error_count), 0)   AS errors,
                 COALESCE(SUM(h_le_10), 0)   AS h_le_10,
                 COALESCE(SUM(h_le_50), 0)   AS h_le_50,
                 COALESCE(SUM(h_le_100), 0)  AS h_le_100,
                 COALESCE(SUM(h_le_250), 0)  AS h_le_250,
                 COALESCE(SUM(h_le_500), 0)  AS h_le_500,
                 COALESCE(SUM(h_le_1000), 0) AS h_le_1000,
                 COALESCE(SUM(h_le_2500), 0) AS h_le_2500,
                 COALESCE(SUM(h_le_5000), 0) AS h_le_5000,
                 COALESCE(SUM(h_gt_5000), 0) AS h_gt_5000
             FROM metrics_rollup
             WHERE bucket_started_at >= (NOW() - INTERVAL ? MINUTE)
             GROUP BY bucket
             ORDER BY bucket ASC",
            [$resolutionSeconds, $resolutionSeconds, $minutes]
        );

        $out = [];
        foreach ((is_array($rows) ? $rows : []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $histogram = [];
            foreach (self::HISTOGRAM as $h) {
                $histogram[$h['col']] = $this->toInt($row[$h['col']] ?? 0);
            }
            $out[] = [
                'bucket'    => $this->toString($row['bucket'] ?? ''),
                'bytes_in'  => $this->toInt($row['bytes_in'] ?? 0),
                'bytes_out' => $this->toInt($row['bytes_out'] ?? 0),
                'requests'  => $this->toInt($row['requests'] ?? 0),
                'errors'    => $this->toInt($row['errors'] ?? 0),
                'p50_ms'    => $this->percentile($histogram, 0.50),
                'p95_ms'    => $this->percentile($histogram, 0.95),
            ];
        }

        return $out;
    }

    /**
     * Live per-connection list (rows newer than the TTL), busiest first.
     *
     * @param int $ttlSeconds Only rows with last_seen_at newer than now-ttl (default 10).
     *
     * @return array<int, array{
     *     connection_id: string,
     *     kind: string,
     *     user_id: ?string,
     *     remote_ip: ?string,
     *     session_id: ?string,
     *     media_item_id: ?string,
     *     bytes_in: int,
     *     bytes_out: int,
     *     bytes_in_rate: int,
     *     bytes_out_rate: int,
     *     opened_at: string,
     *     last_seen_at: string
     * }>
     */
    public function liveConnections(int $ttlSeconds = 10): array
    {
        $ttlSeconds = max(1, $ttlSeconds);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT connection_id, kind, user_id, remote_ip, session_id, media_item_id,
                    bytes_in, bytes_out, bytes_in_rate, bytes_out_rate, opened_at, last_seen_at
             FROM metrics_connections
             WHERE last_seen_at > (NOW() - INTERVAL ? SECOND)
             ORDER BY bytes_out_rate DESC",
            [$ttlSeconds]
        );

        $out = [];
        foreach ((is_array($rows) ? $rows : []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'connection_id'  => $this->toString($row['connection_id'] ?? ''),
                'kind'           => $this->toString($row['kind'] ?? 'http'),
                'user_id'        => $this->toNullableString($row['user_id'] ?? null),
                'remote_ip'      => $this->toNullableString($row['remote_ip'] ?? null),
                'session_id'     => $this->toNullableString($row['session_id'] ?? null),
                'media_item_id'  => $this->toNullableString($row['media_item_id'] ?? null),
                'bytes_in'       => $this->toInt($row['bytes_in'] ?? 0),
                'bytes_out'      => $this->toInt($row['bytes_out'] ?? 0),
                'bytes_in_rate'  => $this->toInt($row['bytes_in_rate'] ?? 0),
                'bytes_out_rate' => $this->toInt($row['bytes_out_rate'] ?? 0),
                'opened_at'      => $this->toString($row['opened_at'] ?? ''),
                'last_seen_at'   => $this->toString($row['last_seen_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Busiest routes over the trailing window (by request count).
     *
     * @param int $minutes How far back to aggregate (default 15).
     * @param int $limit   Max rows returned (default 20).
     *
     * @return array<int, array{
     *     method: string,
     *     route: string,
     *     request_count: int,
     *     error_count: int,
     *     avg_ms: int,
     *     max_ms: int
     * }>
     */
    public function topRoutes(int $minutes = 15, int $limit = 20): array
    {
        $minutes = max(1, $minutes);
        $limit   = max(1, $limit);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT
                 method,
                 route,
                 COALESCE(SUM(request_count), 0)   AS request_count,
                 COALESCE(SUM(error_count), 0)     AS error_count,
                 COALESCE(SUM(duration_ms_sum), 0) AS duration_ms_sum,
                 COALESCE(MAX(duration_ms_max), 0) AS max_ms
             FROM metrics_route_rollup
             WHERE bucket_started_at >= (NOW() - INTERVAL ? MINUTE)
             GROUP BY method, route
             ORDER BY request_count DESC
             LIMIT ?",
            [$minutes, $limit]
        );

        $out = [];
        foreach ((is_array($rows) ? $rows : []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $count = $this->toInt($row['request_count'] ?? 0);
            $sum   = $this->toInt($row['duration_ms_sum'] ?? 0);
            $out[] = [
                'method'        => $this->toString($row['method'] ?? ''),
                'route'         => $this->toString($row['route'] ?? ''),
                'request_count' => $count,
                'error_count'   => $this->toInt($row['error_count'] ?? 0),
                'avg_ms'        => $count > 0 ? (int) round($sum / $count) : 0,
                'max_ms'        => $this->toInt($row['max_ms'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Count currently-live connections (last_seen within the default TTL window).
     *
     * @return int
     */
    private function countLiveConnections(): int
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT COUNT(*) AS c
             FROM metrics_connections
             WHERE last_seen_at > (NOW() - INTERVAL ? SECOND)",
            [$this->connectionTtlSeconds]
        );
        $row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];
        return $this->toInt($row['c'] ?? 0);
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

    /**
     * Approximate a percentile from the summed latency histogram.
     *
     * Walks the cumulative histogram to the bucket containing the target rank and
     * returns that bucket's upper bound (ms). The overflow bucket returns the last
     * real bound as a floor. Returns 0 when there are no samples.
     *
     * @param array<string, int> $histogram Summed per-column counts keyed by column name.
     * @param float               $q        Target quantile in [0, 1] (e.g. 0.95).
     *
     * @return int Approximate latency in milliseconds.
     */
    private function percentile(array $histogram, float $q): int
    {
        $total = 0;
        foreach (self::HISTOGRAM as $h) {
            $total += $histogram[$h['col']] ?? 0;
        }
        if ($total === 0) {
            return 0;
        }

        $target     = (int) ceil($q * $total);
        $cumulative = 0;
        foreach (self::HISTOGRAM as $h) {
            $cumulative += $histogram[$h['col']] ?? 0;
            if ($cumulative >= $target) {
                return $h['le'];
            }
        }

        return self::HISTOGRAM[count(self::HISTOGRAM) - 1]['le'];
    }

    /**
     * Convert a mixed value to string.
     *
     * @param mixed $value
     *
     * @return string
     */
    private function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Convert a mixed value to a nullable string (NULL passes through as null).
     *
     * @param mixed $value
     *
     * @return ?string
     */
    private function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = $this->toString($value);
        return $s === '' ? null : $s;
    }

    /**
     * Convert a mixed value to int.
     *
     * @param mixed $value
     *
     * @return int
     */
    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return 0;
    }
}
