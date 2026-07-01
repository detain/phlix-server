<?php

declare(strict_types=1);

/**
 * Metrics / live-traffic telemetry configuration.
 *
 * Consumed by the {@see \Phlix\Stats\Metrics} subsystem (registry / collector /
 * flush service / repository). Threaded into the DI container via
 * config/server.php (`$config['metrics']`), so it reaches BOTH entry points
 * (public/index.php CGI path and the Workerman daemon in start.php) as
 * `$appConfig['metrics']`, exactly like the ffmpeg/hub/relay sub-arrays.
 *
 * All knobs accept an environment-variable override with a sane default so the
 * feature works out of the box and can be tuned / disabled without editing code.
 *
 * @return array{
 *     enabled: bool,
 *     flush_interval_seconds: int,
 *     bucket_seconds: int,
 *     retention_days: int,
 *     connection_ttl_seconds: int,
 *     route_cardinality_cap: int,
 *     latency_buckets_ms: array<int, int>
 * }
 */

/**
 * Coerce an environment variable into a boolean, honouring common truthy values.
 *
 * @param string $key     Environment variable name.
 * @param bool   $default Value returned when the variable is unset.
 *
 * @return bool
 */
$envBool = static function (string $key, bool $default): bool {
    $raw = getenv($key);
    if ($raw === false || $raw === '') {
        return $default;
    }
    return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
};

/**
 * Coerce an environment variable into a positive integer.
 *
 * @param string $key     Environment variable name.
 * @param int    $default Value returned when the variable is unset or non-numeric.
 *
 * @return int
 */
$envInt = static function (string $key, int $default): int {
    $raw = getenv($key);
    if ($raw === false || !is_numeric($raw)) {
        return $default;
    }
    return (int) $raw;
};

return [
    // Master on/off switch. When false the MetricsCollector no-ops every call so
    // there is zero per-request overhead.
    'enabled' => $envBool('PHLIX_METRICS_ENABLED', true),

    // How often (seconds) each worker flushes its in-memory registry to MySQL.
    'flush_interval_seconds' => $envInt('PHLIX_METRICS_FLUSH_INTERVAL', 5),

    // Time-bucket granularity (seconds). Buckets are aligned to floor(ts / N) * N.
    'bucket_seconds' => $envInt('PHLIX_METRICS_BUCKET_SECONDS', 10),

    // How long rollup rows are retained before the flush service prunes them.
    'retention_days' => $envInt('PHLIX_METRICS_RETENTION_DAYS', 7),

    // A connection row is considered stale (and pruned) once it has not been
    // touched for this many seconds.
    'connection_ttl_seconds' => $envInt('PHLIX_METRICS_CONNECTION_TTL', 15),

    // Upper bound on distinct (method, route) pairs tracked per bucket. Once the
    // cap is hit further routes fold into a single "__other__" bucket so an
    // adversarial / high-cardinality URL space cannot blow up worker memory.
    'route_cardinality_cap' => $envInt('PHLIX_METRICS_ROUTE_CAP', 200),

    // Latency histogram bucket upper bounds (milliseconds), ascending. A request
    // is counted into the first bucket whose bound it does not exceed; anything
    // slower than the last bound lands in the implicit "greater-than" overflow
    // bucket (h_gt_<last>). Used for percentile approximation at read time.
    'latency_buckets_ms' => [10, 50, 100, 250, 500, 1000, 2500, 5000],
];
