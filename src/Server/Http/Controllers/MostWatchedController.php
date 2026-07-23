<?php

/**
 * Phlix media server component: Server.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItemShaper;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Stats\StatsCollector;

/**
 * Public "Most Watched" rail endpoint (S31).
 *
 * Exposes the GLOBAL "trending" aggregate — the media items most-watched
 * across the WHOLE server — to signed-in users, reusing
 * {@see StatsCollector::getTopMedia()} (the same all-time, cross-user
 * aggregate the admin Top Media report reads). This is a server-wide
 * trending rail, NOT a per-user history (a deliberate product decision:
 * the rail shows the same "popular on this server" list to everyone).
 *
 * The returned media rows are shaped through {@see MediaItemShaper::shape()},
 * exactly like the other media-list rails (`GET /api/v1/media`), so poster /
 * artwork signed URLs are re-minted at response time and the payload is
 * type-correct and consistent with its sibling rails.
 *
 * Route + auth posture are wired in {@see \Phlix\Server\Core\Application}:
 * `GET /api/v1/media/most-watched`, gated by
 * {@see \Phlix\Server\Http\Middleware\AuthMiddleware} to match the other
 * home-rail media endpoints (a signed-in user is required, same audience).
 *
 * @package Phlix\Server\Http\Controllers
 * @since   S31
 */
final class MostWatchedController
{
    /**
     * Default rail size when `?limit=` is absent — a sensible home-rail length.
     */
    private const DEFAULT_LIMIT = 20;

    /**
     * @param StatsCollector $stats Global playback-stats aggregate source.
     * @param ItemRepository $items Media repository for hydrating the top IDs.
     */
    public function __construct(
        private readonly StatsCollector $stats,
        private readonly ItemRepository $items,
    ) {
    }

    /**
     * `GET /api/v1/media/most-watched?limit=20`
     *
     * Returns the server-wide most-watched media items, shaped for the SPA
     * exactly like `GET /api/v1/media` (`{ items, total, limit, offset }`).
     *
     * The `limit` is clamped to the hard server-side ceiling
     * ({@see \Phlix\Common\Http\PageLimit::MAX}) via
     * {@see Request::queryPageSize()} so an unbounded `?limit=` cannot exhaust a
     * resident worker, and is then passed through
     * {@see StatsCollector::getTopMedia()}'s existing `LIMIT ?` signature.
     *
     * @param Request              $request The HTTP request (query.limit).
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @return Response `{ items, total, limit, offset }`.
     */
    public function mostWatched(Request $request, array $params): Response
    {
        $limit = $request->queryPageSize('limit', self::DEFAULT_LIMIT);

        // GLOBAL, all-time trending aggregate (since = null): the SAME cross-user
        // list the admin Top Media report reads. Ordered by play_count DESC.
        $topMedia = $this->stats->getTopMedia($limit);

        // Extract the media-item IDs, preserving the play-count-descending order.
        $ids = [];
        foreach ($topMedia as $row) {
            $id = $row['media_item_id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        // Batch-hydrate the rows in ONE query; findByIds() preserves the input
        // order and silently drops any since-deleted item, so the rail stays in
        // popularity order and never references a missing row.
        $rows = $this->items->findByIds($ids);

        $items = array_map(
            static fn (array $item): array => MediaItemShaper::shape($item),
            $rows
        );

        return (new Response())->json([
            'items' => $items,
            'total' => count($items),
            'limit' => $limit,
            'offset' => 0,
        ]);
    }
}
