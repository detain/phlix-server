<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\Rating;
use Phlix\Media\Metadata\RatingService;
use Phlix\Media\Metadata\RatingSource;
use Phlix\Media\Metadata\RatingType;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Public and authenticated endpoints for media item ratings.
 *
 * GET /api/v1/media/{id}/ratings returns all ratings (TMDB, IMDb, user, aggregated)
 * for a media item and is accessible to unauthenticated callers.
 *
 * POST /api/v1/media/{id}/ratings allows an authenticated user to submit or update
 * their personal user rating for the item.
 *
 * Both handlers live on {@see \Phlix\Server\WebPortal\WebPortalRouter}, which is the
 * single dispatch point for /api/* on both HTTP entry points.
 */
class MediaRatingsController
{
    public function __construct(
        private readonly ItemRepository $itemRepository,
        private readonly RatingService $ratingService,
    ) {
    }

    /**
     * Return all ratings for a media item.
     *
     * Returns an empty list when the item does not exist (not a 404 — the
     * distinction is not meaningful to a public, caching-friendly endpoint).
     *
     * @param array<string, string> $params Route params including 'id'.
     * @return array<int, array<string, mixed>>
     *
     * @api_endpoint GET /api/v1/media/{id}/ratings
     */
    public function getRatings(array $params): array
    {
        $itemId = $params['id'] ?? '';

        $ratings = $this->ratingService->findByMediaItem($itemId);

        return array_map(
            static fn(Rating $r): array => $r->toArray(),
            $ratings,
        );
    }

    /**
     * Create or update the authenticated user's personal rating for a media item.
     *
     * Body: `{ "score": <float 0.0-10.0>, "votes": <int|null> }`.  The `votes`
     * field is accepted but ignored for user ratings (it is stored as null since
     * personal ratings carry no vote count).  An absent/malformed score is a 400.
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint POST /api/v1/media/{id}/ratings
     */
    public function createRating(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $itemId = $params['id'] ?? '';
        if ($itemId === '') {
            return (new Response())->status(400)->json(['error' => 'Media item ID is required']);
        }

        if ($this->itemRepository->findById($itemId) === null) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $raw = $request->input('score');
        if ($raw === null || !is_numeric($raw)) {
            return (new Response())->status(400)->json(['error' => 'score is required and must be numeric']);
        }

        $score = (float) $raw;
        if ($score < 0.0 || $score > 10.0) {
            return (new Response())->status(400)->json(['error' => 'score must be between 0.0 and 10.0']);
        }

        $this->ratingService->upsert(
            $itemId,
            RatingSource::User,
            RatingType::User,
            $score,
            null,
        );

        return (new Response())->json(['message' => 'Rating saved']);
    }
}
