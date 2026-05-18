<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers\Admin;

use Phlex\Admin\DashboardService;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

/**
 * Dashboard controller for admin API endpoints.
 *
 * Provides JSON API endpoints for the admin dashboard data including
 * now playing, top users, top media, storage summary, and recent activity.
 *
 * @author Phlex Team
 * @version 1.0.0
 * @description Admin dashboard API controller
 *
 * @see DashboardService For data aggregation
 */
class DashboardController
{
    /** @var DashboardService Dashboard data service */
    private DashboardService $dashboardService;

    /**
     * Creates a new DashboardController instance.
     *
     * @param DashboardService $dashboardService Dashboard data service
     */
    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Get currently active playback sessions.
     *
     * GET /api/v1/admin/dashboard/now-playing
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with now playing data
     */
    public function nowPlaying(Request $request, array $params): Response
    {
        $data = $this->dashboardService->getNowPlaying();

        return (new Response())->json([
            'success' => true,
            'data' => $data,
            'count' => count($data),
        ]);
    }

    /**
     * Get top users leaderboard.
     *
     * GET /api/v1/admin/dashboard/top-users?limit=10&days=30
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with top users data
     */
    public function topUsers(Request $request, array $params): Response
    {
        $limit = $this->getIntQueryParam($request, 'limit', 10);
        $days = $this->getIntQueryParam($request, 'days', 30);

        $limit = max(1, min($limit, 100));
        $days = $days !== null ? max(1, min($days, 365)) : null;

        $data = $this->dashboardService->getTopUsers($limit, $days);

        return (new Response())->json([
            'success' => true,
            'data' => $data,
            'count' => count($data),
        ]);
    }

    /**
     * Get top media items by play count.
     *
     * GET /api/v1/admin/dashboard/top-media?limit=10&days=30
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with top media data
     */
    public function topMedia(Request $request, array $params): Response
    {
        $limit = $this->getIntQueryParam($request, 'limit', 10);
        $days = $this->getIntQueryParam($request, 'days', 30);

        $limit = max(1, min($limit, 100));
        $days = $days !== null ? max(1, min($days, 365)) : null;

        $data = $this->dashboardService->getTopMedia($limit, $days);

        return (new Response())->json([
            'success' => true,
            'data' => $data,
            'count' => count($data),
        ]);
    }

    /**
     * Get storage usage summary.
     *
     * GET /api/v1/admin/dashboard/storage
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with storage data
     */
    public function storage(Request $request, array $params): Response
    {
        $data = $this->dashboardService->getStorageSummary();

        return (new Response())->json([
            'success' => true,
            'data' => $data,
            'count' => count($data),
        ]);
    }

    /**
     * Get recent activity feed.
     *
     * GET /api/v1/admin/dashboard/activity?limit=20
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with activity data
     */
    public function activity(Request $request, array $params): Response
    {
        $limit = $this->getIntQueryParam($request, 'limit', 20);
        $limit = max(1, min($limit, 100));

        $data = $this->dashboardService->getRecentActivity($limit);

        return (new Response())->json([
            'success' => true,
            'data' => $data,
            'count' => count($data),
        ]);
    }

    /**
     * Get an integer query parameter with default value.
     *
     * @param Request $request The HTTP request
     * @param string $name Parameter name
     * @param int $default Default value
     * @return int|null Integer value or null if not set
     */
    private function getIntQueryParam(Request $request, string $name, int $default): ?int
    {
        $query = $request->query;
        $value = $query[$name] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
