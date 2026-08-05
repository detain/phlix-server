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
use Phlix\Media\Library\RatingGate;
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
 * PARENTAL CAP (S213): being a server-wide aggregate, the raw top-media list is
 * the SAME for everyone — including a rating-capped child profile. So the rail
 * post-filters through the shared {@see RatingGate}, exactly like its twelve
 * sibling surfaces ({@see \Phlix\Server\Http\Controllers\MediaItemController}
 * and the eleven sites in {@see \Phlix\Server\WebPortal\WebPortalRouter}). A
 * null filter (owner / un-capped profile) is a strict no-op; an unidentified
 * request resolves a deny-all cap instead (S235), never a null one.
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
     * @param StatsCollector $stats      Global playback-stats aggregate source.
     * @param ItemRepository $items      Media repository for hydrating the top IDs.
     * @param RatingGate     $ratingGate Shared parental-control access gate.
     *
     * ⚠ `$ratingGate` is deliberately REQUIRED and NON-NULLABLE. This controller
     * is built by PHP-DI `autowire()`
     * ({@see \Phlix\Common\Container\Providers\AdminServicesProvider}), and
     * `autowire()` SILENTLY SKIPS optional constructor parameters — a
     * `?RatingGate $ratingGate = null` would therefore be null in production
     * forever (the rail ungated) while every unit test that hand-builds the
     * controller with a gate stayed green. Same trap as `RatingGate::$users` and
     * `MediaUserDataController::$ratingGate`, both of which had to be rescued
     * with an explicit `constructorParameter()` binding. A required param cannot
     * be skipped.
     */
    public function __construct(
        private readonly StatsCollector $stats,
        private readonly ItemRepository $items,
        private readonly RatingGate $ratingGate,
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
     * The hydrated rows are then passed through {@see RatingGate::filterItems()}
     * so a rating-capped active profile never sees an over-cap title in the
     * rail (S213); `total` reflects the POST-filter list.
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

        // PARENTAL CAP (S213). The aggregate above is server-wide, so without
        // this a PG-capped profile saw the server's most-watched R-rated titles
        // on the first screen it ever loads. Filter on the RAW rows: findByIds()
        // is a `SELECT *`, so each row still carries `content_rating`/`parent_id`
        // and RatingGate settles most rows with zero extra DB work. `total` is
        // recomputed from the filtered list below, so the count never leaks the
        // number of hidden titles. Strict no-op when the filter is null (owner or
        // un-capped profile). An unidentified request gets a deny-all cap (S235),
        // not a null one — the route is AuthMiddleware-gated, so that is a
        // defence-in-depth default rather than a reachable state.
        $filter = $this->ratingGate->resolveFilterForUser($request->userId ?? '');
        if ($filter !== null) {
            $rows = $this->ratingGate->filterItems($rows, $filter, 'id');
        }

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
