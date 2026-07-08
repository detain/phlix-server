<?php

/**
 * Phlix media server component: Metrics.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Stats\Metrics;

/**
 * Read-side contract for the metrics subsystem (S1-S2).
 *
 * Implemented by {@see MetricsRepository}. Exists as an interface so
 * controller/unit tests can mock the read side without needing a live
 * MySQL connection or a non-final concrete class.
 *
 * @package Phlix\Stats\Metrics
 * @since S1
 */
interface MetricsRepositoryInterface
{
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
    public function snapshot(int $windowSeconds = 60): array;

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
    public function history(int $minutes = 60, int $resolutionSeconds = 60): array;

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
    public function liveConnections(int $ttlSeconds = 10): array;

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
    public function topRoutes(int $minutes = 15, int $limit = 20): array;
}
