<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\UserItemDataRepository;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Per-user favorites + ratings endpoints for media items (E10).
 *
 * Each handler requires an authenticated user ($request->userId, set by the
 * entry point from the Bearer token / session cookie before dispatch and
 * enforced by AuthMiddleware on the route group), a path `{id}` naming an
 * existing media item, and — for ratings — a JSON body. The handlers are
 * referenced from {@see \Phlix\Server\WebPortal\WebPortalRouter}, which both
 * HTTP entry points (public/index.php and the Workerman daemon's HttpHandler)
 * dispatch `/api/*` to, so a single registration serves both entry points.
 *
 * Responses are a flat `{ message }` on success; failures use the same
 * `{ error }` shape as the surrounding web-portal API.
 */
class MediaUserDataController
{
    /** @var ItemRepository Verifies the target media item exists (404 otherwise). */
    private ItemRepository $itemRepository;

    /** @var UserItemDataRepository Persists/reads the per-user favorite + rating. */
    private UserItemDataRepository $userItemData;

    /**
     * @param ItemRepository         $itemRepository Resolves/validates the media item.
     * @param UserItemDataRepository $userItemData   Per-user favorite/rating store.
     */
    public function __construct(ItemRepository $itemRepository, UserItemDataRepository $userItemData)
    {
        $this->itemRepository = $itemRepository;
        $this->userItemData = $userItemData;
    }

    /**
     * Mark a media item as a favorite for the authenticated user.
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint POST /api/v1/media/{id}/favorite
     */
    public function addFavorite(Request $request, array $params): Response
    {
        $ctx = $this->resolve($request, $params);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $itemId] = $ctx;

        $this->userItemData->setFavorite($userId, $itemId, true);

        return (new Response())->json(['message' => 'Added to favorites']);
    }

    /**
     * Remove a media item from the authenticated user's favorites.
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint DELETE /api/v1/media/{id}/favorite
     */
    public function removeFavorite(Request $request, array $params): Response
    {
        $ctx = $this->resolve($request, $params);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $itemId] = $ctx;

        $this->userItemData->setFavorite($userId, $itemId, false);

        return (new Response())->json(['message' => 'Removed from favorites']);
    }

    /**
     * Set the authenticated user's personal rating for a media item.
     *
     * Body: `{ "rating": <int 1-10|null> }`. A null (or omitted) rating clears
     * the rating; a non-numeric or out-of-range value is a 400.
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint PUT /api/v1/media/{id}/rating
     */
    public function setRating(Request $request, array $params): Response
    {
        $ctx = $this->resolve($request, $params);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $itemId] = $ctx;

        $raw = $request->input('rating');
        $rating = null;
        if ($raw !== null) {
            // Reject non-numeric (and non-integer numeric, e.g. "4.5"/4.5) input
            // up front so the only values reaching the repository are clean ints
            // or null. Bools are numeric-adjacent in PHP but never a valid rating.
            if (is_bool($raw) || !is_numeric($raw) || (float) $raw !== floor((float) $raw)) {
                return (new Response())->status(400)->json(
                    ['error' => 'rating must be an integer between 1 and 10, or null']
                );
            }
            $rating = (int) $raw;
        }

        try {
            $this->userItemData->setRating($userId, $itemId, $rating);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        }

        return (new Response())->json(['message' => 'Rating saved']);
    }

    /**
     * Clear the authenticated user's personal rating for a media item.
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint DELETE /api/v1/media/{id}/rating
     */
    public function clearRating(Request $request, array $params): Response
    {
        $ctx = $this->resolve($request, $params);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $itemId] = $ctx;

        $this->userItemData->setRating($userId, $itemId, null);

        return (new Response())->json(['message' => 'Rating cleared']);
    }

    /**
     * Shared guard: enforce auth (401), a present path id (400), and an existing
     * media item (404). Returns the resolved [userId, itemId] tuple on success,
     * or a ready-to-send error Response on failure.
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @return array{0: string, 1: string}|Response
     */
    private function resolve(Request $request, array $params): array|Response
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

        return [$userId, $itemId];
    }
}
