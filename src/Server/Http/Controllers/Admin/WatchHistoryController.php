<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Admin\WatchHistoryService;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Watch-history controller for the admin API.
 *
 * Exposes recent watch-history rows across ALL users (admin-only; the route is
 * gated by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}), joined with
 * user + media info. Backs the admin "watch history" view (item 7).
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Admin cross-user watch-history API controller
 *
 * @see WatchHistoryService For data aggregation
 */
class WatchHistoryController
{
    /** @var WatchHistoryService Watch-history data service */
    private WatchHistoryService $service;

    /**
     * Creates a new WatchHistoryController instance.
     *
     * @param WatchHistoryService $service Watch-history data service
     */
    public function __construct(WatchHistoryService $service)
    {
        $this->service = $service;
    }

    /**
     * Get recent watch-history rows across all users.
     *
     * GET /api/v1/admin/watch-history?limit=50&userId=<uuid>&libraryId=<uuid>
     *
     * The `limit` is clamped to the range 1..200 (default 50). The optional
     * `userId` / `libraryId` filters are only applied when present and
     * non-empty.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with recent watch-history rows
     */
    public function index(Request $request, array $params): Response
    {
        $limit = $request->queryInt('limit', 50);
        $limit = max(1, min($limit, 200));

        $userId = $this->nonEmptyStringOrNull($request->query['userId'] ?? null);
        $libraryId = $this->nonEmptyStringOrNull($request->query['libraryId'] ?? null);

        $data = $this->service->getRecentWatchHistory($limit, $userId, $libraryId);

        return (new Response())->json([
            'success' => true,
            'data' => $data,
            'count' => count($data),
        ]);
    }

    /**
     * Narrow a mixed query value to a non-empty string, or null otherwise.
     *
     * @param mixed $value The raw query-parameter value.
     * @return string|null The non-empty string, or null when absent/empty/non-string.
     */
    private function nonEmptyStringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
