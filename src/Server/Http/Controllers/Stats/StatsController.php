<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers\Stats;

use DateTime;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Stats\StatsCollector;

/**
 * JSON API for admin statistics endpoints (Step L.3).
 *
 * All routes are wired via {@see \Phlex\Server\Http\Routes\AdminRoutes}
 * under the `/api/v1/admin` group, with
 * {@see \Phlex\Server\Http\Middleware\AdminMiddleware} in front).
 *
 * Endpoints:
 *
 *  - `GET /api/v1/admin/stats/playback`     → playback stats time-series
 *  - `GET /api/v1/admin/stats/top-users`    → top users by watch time
 *  - `GET /api/v1/admin/stats/top-media`   → top media items by play count
 *  - `GET /api/v1/admin/stats/storage`      → storage usage snapshots
 *
 * @package Phlex\Server\Http\Controllers\Stats
 * @since   1.0.0 (Step L.3)
 */
final class StatsController
{
    /**
     * @param StatsCollector $collector Stats collector service
     */
    public function __construct(
        private readonly StatsCollector $collector,
    ) {
    }

    /**
     * Get playback stats as a time-series grouped by day.
     *
     * `GET /api/v1/admin/stats/playback?from=2024-01-01&to=2024-01-31` →
     * `200 { "data": [{date, play_count, total_duration, completed_count}, ...] }`
     *
     * @param Request              $request The HTTP request (query.from, query.to)
     * @param array<string,string> $params  Path parameters (unused)
     *
     * @return Response JSON-encoded playback stats
     */
    public function playback(Request $request, array $params): Response
    {
        $from = $this->parseDate($request->input('from', '-30 days'));
        $to = $this->parseDate($request->input('to', 'now'));

        $stats = $this->collector->getPlaybackStats($from, $to);

        return (new Response())->json(['data' => $stats]);
    }

    /**
     * Get top users by total watch time.
     *
     * `GET /api/v1/admin/stats/top-users?limit=10&since=2024-01-01` →
     * `200 { "data": [{user_id, total_watch_time, play_count}, ...] }`
     *
     * @param Request              $request The HTTP request (query.limit, query.since)
     * @param array<string,string> $params  Path parameters (unused)
     *
     * @return Response JSON-encoded top users list
     */
    public function topUsers(Request $request, array $params): Response
    {
        $limit = $this->parseInt($request->input('limit', '10'));
        $since = $request->input('since');
        $sinceDate = $since !== null ? $this->parseDate($since) : null;

        $topUsers = $this->collector->getTopUsers($limit, $sinceDate);

        return (new Response())->json(['data' => $topUsers]);
    }

    /**
     * Get top media items by play count.
     *
     * `GET /api/v1/admin/stats/top-media?limit=10&since=2024-01-01` →
     * `200 { "data": [{media_item_id, play_count, total_duration}, ...] }`
     *
     * @param Request              $request The HTTP request (query.limit, query.since)
     * @param array<string,string> $params  Path parameters (unused)
     *
     * @return Response JSON-encoded top media list
     */
    public function topMedia(Request $request, array $params): Response
    {
        $limit = $this->parseInt($request->input('limit', '10'));
        $since = $request->input('since');
        $sinceDate = $since !== null ? $this->parseDate($since) : null;

        $topMedia = $this->collector->getTopMedia($limit, $sinceDate);

        return (new Response())->json(['data' => $topMedia]);
    }

    /**
     * Get storage usage snapshots.
     *
     * `GET /api/v1/admin/stats/storage` →
     * `200 { "data": [{id, recorded_at, library_id, media_type, item_count, total_bytes, transcode_cache_bytes}, ...] }`
     *
     * @param Request              $request The HTTP request (unused)
     * @param array<string,string> $params  Path parameters (unused)
     *
     * @return Response JSON-encoded storage snapshots
     */
    public function storage(Request $request, array $params): Response
    {
        return (new Response())->json(['data' => []]);
    }

    /**
     * Parse a date string or return a relative date.
     *
     * Accepts mixed so callers can pass `Request::input()` results
     * directly without an extra narrowing step at every call site.
     *
     * @param mixed $input Date string, relative expression, or non-string.
     *
     * @return DateTime Parsed date
     */
    private function parseDate(mixed $input): DateTime
    {
        if (!is_string($input) || $input === '') {
            return new DateTime();
        }

        // Handle relative dates like "-30 days"
        if (str_starts_with($input, '-') || $input === 'now') {
            $timestamp = strtotime($input);
            if ($timestamp !== false) {
                return new DateTime('@' . $timestamp);
            }
        }

        // Try parsing as a standard date format
        $date = DateTime::createFromFormat('Y-m-d', $input);
        if ($date !== false) {
            return $date;
        }

        // Fallback to current date
        return new DateTime();
    }

    /**
     * Parse an integer from a string-ish input, with a default of 10.
     *
     * @param mixed $input Anything; values that filter_var cannot
     *                     coerce to int fall back to 10.
     *
     * @return int Parsed integer
     */
    private function parseInt(mixed $input): int
    {
        if (!is_scalar($input)) {
            return 10;
        }
        $parsed = filter_var((string) $input, FILTER_VALIDATE_INT);
        return $parsed !== false ? $parsed : 10;
    }
}
