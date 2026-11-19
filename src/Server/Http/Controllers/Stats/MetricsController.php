<?php

/**
 * Phlix media server component: Stats.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Stats;

use Phlix\Common\Http\PageLimit;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Stats\Metrics\MetricsRepositoryInterface;

/**
 * JSON API for metrics endpoints (S2).
 *
 * All routes are wired via {@see \Phlix\Server\Http\Routes\AdminRoutes}
 * under the `/api/v1/admin` group, with
 * {@see \Phlix\Server\Http\Middleware\AdminMiddleware} in front).
 *
 * Endpoints:
 *
 *  - `GET /api/v1/admin/metrics/snapshot`     → current metrics snapshot
 *  - `GET /api/v1/admin/metrics/history`      → historical metrics time-series
 *  - `GET /api/v1/admin/metrics/connections` → live WebSocket connections
 *  - `GET /api/v1/admin/metrics/routes`       → top routes by request count
 *
 * @package Phlix\Server\Http\Controllers\Stats
 * @since S2
 */
final class MetricsController
{
    /**
     * Largest look-back a caller may ask the metrics repository to scan:
     * 7 days in minutes — the metrics retention default
     * ({@see \Phlix\Stats\Metrics\MetricsFlushService} `retention_days`, 7).
     * Anything beyond the retention window is guaranteed-empty scan work on a
     * RESIDENT Workerman worker, which is the same hazard class the
     * pagination ceiling at {@see PageLimit::MAX} exists for.
     */
    public const MAX_LOOKBACK_MINUTES = 10080;

    /** Largest live-rate window: 7 days of seconds (per-bucket sample loop). */
    public const MAX_WINDOW_SECONDS = 604800;

    /** Largest connection-liveness TTL: 7 days of seconds. */
    public const MAX_TTL_SECONDS = 604800;

    /** Largest history bucket width: 1 day of seconds (bucketing granularity). */
    public const MAX_RESOLUTION_SECONDS = 86400;

    public function __construct(
        private readonly MetricsRepositoryInterface $repo,
    ) {
    }

    /**
     * Get a snapshot of current metrics.
     *
     * `GET /api/v1/admin/metrics/snapshot?window=60` →
     * `200 { "success": true, "data":{ bytes_in_per_sec, bytes_out_per_sec, active_connections,
     *                  requests_per_sec, error_rate, p50_ms, p95_ms, p99_ms } }`
     *
     * @param Request              $request The HTTP request (query.window seconds)
     * @param array<string,string> $params  Path parameters (unused)
     *
     * @return Response JSON-encoded metrics snapshot
     */
    public function snapshot(Request $request, array $params): Response
    {
        $window = $this->queryBoundedInt($request, 'window', 60, self::MAX_WINDOW_SECONDS);
        $data = $this->repo->snapshot($window);

        return (new Response())->json(['success' => true, 'data' => $data]);
    }

    /**
     * Get historical metrics time-series.
     *
     * `GET /api/v1/admin/metrics/history?minutes=60&resolution=60` →
     * `200 { "success": true, "data":[{ bucket, bytes_in, bytes_out, requests, errors,
     *                   p50_ms, p95_ms }, ...] }`
     *
     * @param Request              $request The HTTP request
     *                                       (query.minutes, query.resolution)
     * @param array<string,string> $params  Path parameters (unused)
     *
     * @return Response JSON-encoded metrics history
     */
    public function history(Request $request, array $params): Response
    {
        $minutes = $this->queryBoundedInt($request, 'minutes', 60, self::MAX_LOOKBACK_MINUTES);
        $resolution = $this->queryBoundedInt($request, 'resolution', 60, self::MAX_RESOLUTION_SECONDS);
        $data = $this->repo->history($minutes, $resolution);

        return (new Response())->json(['success' => true, 'data' => $data]);
    }

    /**
     * Get live WebSocket connections.
     *
     * `GET /api/v1/admin/metrics/connections?ttl=15` →
     * `200 { "success": true, "data":[{ id, kind, user_id, remote_ip, opened_at }, ...] }`
     *
     * @param Request              $request The HTTP request (query.ttl seconds)
     * @param array<string,string> $params  Path parameters (unused)
     *
     * @return Response JSON-encoded live connections
     */
    public function connections(Request $request, array $params): Response
    {
        $ttl = $this->queryBoundedInt($request, 'ttl', 15, self::MAX_TTL_SECONDS);
        $data = $this->repo->liveConnections($ttl);

        return (new Response())->json(['success' => true, 'data' => $data]);
    }

    /**
     * Get top routes by request count.
     *
     * `GET /api/v1/admin/metrics/routes?minutes=15&limit=20` →
     * `200 { "success": true, "data":[{ route, method, request_count, error_count,
     *                   avg_latency_ms, p95_ms }, ...] }`
     *
     * @param Request              $request The HTTP request
     *                                       (query.minutes, query.limit)
     * @param array<string,string> $params  Path parameters (unused)
     *
     * @return Response JSON-encoded top routes
     */
    public function routes(Request $request, array $params): Response
    {
        $minutes = $this->queryBoundedInt($request, 'minutes', 15, self::MAX_LOOKBACK_MINUTES);
        // `limit` is a page size bound into a `LIMIT`, so it rides the ONE
        // pagination policy ([PageLimit::MIN, PageLimit::MAX]), not a bespoke
        // ceiling; default stays 20.
        $limit = PageLimit::clamp($this->rawParam($request, 'limit'), 20);
        $data = $this->repo->topRoutes($minutes, $limit);

        return (new Response())->json(['success' => true, 'data' => $data]);
    }

    /**
     * Read a caller-supplied parameter across BOTH documented channels: the
     * query string wins when present (the `?window=`/`?minutes=` surfaces the
     * endpoint docs advertise — `Request::input()` alone never saw them),
     * then the decoded body (the channel that actually carried admin-UI
     * values pre-fix), else null so the action's declared default applies.
     *
     * @return mixed Raw request value or null.
     */
    private function rawParam(Request $request, string $key): mixed
    {
        return $request->query[$key] ?? $request->input($key);
    }

    /**
     * Bounded integer parameter: shared coercion ({@see PageLimit::coerce()}
     * — the one `is_numeric ? (int) : default` rule the codebase standardized
     * on), then clamped into `[1, $max]`. Invalid/missing falls back to the
     * action's default exactly as the removed hand-rolled `parseInt()` ladder
     * did; the `$max` ceiling is what it never had.
     */
    private function queryBoundedInt(Request $request, string $key, int $default, int $max): int
    {
        $value = PageLimit::coerce($this->rawParam($request, $key), $default);

        return min(max(1, $value), $max);
    }
}
