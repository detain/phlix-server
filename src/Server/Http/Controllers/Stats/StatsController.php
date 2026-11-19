<?php

/**
 * Phlix media server component: Stats.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Stats;

use DateTime;
use Phlix\Common\Http\PageLimit;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Stats\StatsCollector;

/**
 * JSON API for admin statistics endpoints (Step L.3).
 *
 * All routes are wired via {@see \Phlix\Server\Http\Routes\AdminRoutes}
 * under the `/api/v1/admin` group, with
 * {@see \Phlix\Server\Http\Middleware\AdminMiddleware} in front).
 *
 * Endpoints:
 *
 *  - `GET /api/v1/admin/stats/playback`     → playback stats time-series
 *  - `GET /api/v1/admin/stats/top-users`    → top users by watch time
 *  - `GET /api/v1/admin/stats/top-media`   → top media items by play count
 *  - `GET /api/v1/admin/stats/storage`      → storage usage snapshots
 *
 * @package Phlix\Server\Http\Controllers\Stats
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
     * The `limit` passes through {@see PageLimit::clamp()} with a default of
     * 10: an unclamped value reaches `getTopUsers()`'s `LIMIT ?` binding, and
     * under Workerman (resident memory) that is an unbounded-scan / OOM
     * vector against every other user of the worker, not a "big page".
     * Values in `[1, PageLimit::MAX]` behave exactly as before.
     *
     * `since` follows the same channel law as `limit` — query-first,
     * body-second (SRV-3 review: the doc example and phlix-console-client
     * both build `?since=`, and a body-only read made it dead-on-arrival).
     * Its PARSING is unchanged: whatever channel supplies the value,
     * {@see self::parseDate()} keeps its documented leniency, and an absent
     * param on both channels means no filter.
     *
     * @param Request              $request The HTTP request (`limit`/`since` read query-first, body-second)
     * @param array<string,string> $params  Path parameters (unused)
     *
     * @return Response JSON-encoded top users list
     */
    public function topUsers(Request $request, array $params): Response
    {
        $limit = PageLimit::clamp($this->rawParam($request, 'limit'), 10);
        $since = $this->rawParam($request, 'since');
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
     * The `limit` is clamped by {@see PageLimit::clamp()} for the same
     * resident-worker law documented on {@see self::topUsers()}. `since`
     * follows the same query-first, body-second channel law documented there,
     * with {@see self::parseDate()}'s semantics untouched.
     *
     * @param Request              $request The HTTP request (`limit`/`since` read query-first, body-second)
     * @param array<string,string> $params  Path parameters (unused)
     *
     * @return Response JSON-encoded top media list
     */
    public function topMedia(Request $request, array $params): Response
    {
        $limit = PageLimit::clamp($this->rawParam($request, 'limit'), 10);
        $since = $this->rawParam($request, 'since');
        $sinceDate = $since !== null ? $this->parseDate($since) : null;

        $topMedia = $this->collector->getTopMedia($limit, $sinceDate);

        return (new Response())->json(['data' => $topMedia]);
    }

    /**
     * Get storage usage snapshots.
     *
     * `GET /api/v1/admin/stats/storage` →
     * `200 { "data": [{id, recorded_at, library_id, media_type, item_count,
     *                  total_bytes, transcode_cache_bytes}, ...] }`
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
     * Read a request parameter across BOTH documented channels: the query
     * string wins when present (the `?limit=` / `?since=` surface every
     * endpoint doc advertises — `Request::input()` alone never saw it), then
     * the decoded body (the channel that actually carried admin-UI values
     * pre-fix), else null so the caller's declared default applies.
     *
     * Interpretation is deliberately NOT decided here: numeric parameters go
     * to {@see PageLimit} (the one pagination policy), date parameters to
     * {@see self::parseDate()} (its documented leniency unchanged). This
     * method only fixes the CHANNEL, so every param obeys the same law.
     *
     * @return mixed Raw request value or null.
     */
    private function rawParam(Request $request, string $key): mixed
    {
        return $request->query[$key] ?? $request->input($key);
    }
}
