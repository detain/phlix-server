<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItemShaper;
use Phlix\Media\Library\RatingGate;
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
     * Shared parental-control access gate. Null in legacy/test contexts, where
     * every gate check is a strict no-op (owner-safe).
     */
    private ?RatingGate $ratingGate;

    /**
     * @param ItemRepository         $itemRepository Resolves/validates the media item.
     * @param UserItemDataRepository $userItemData   Per-user favorite/rating store.
     * @param RatingGate|null        $ratingGate     Parental-control access gate.
     */
    public function __construct(
        ItemRepository $itemRepository,
        UserItemDataRepository $userItemData,
        ?RatingGate $ratingGate = null
    ) {
        $this->itemRepository = $itemRepository;
        $this->userItemData = $userItemData;
        $this->ratingGate = $ratingGate;
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
        [$userId, $itemId, $profileId] = $ctx;

        $this->userItemData->setFavorite($userId, $itemId, true, $profileId);

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
        [$userId, $itemId, $profileId] = $ctx;

        $this->userItemData->setFavorite($userId, $itemId, false, $profileId);

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
        [$userId, $itemId, $profileId] = $ctx;

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
            $this->userItemData->setRating($userId, $itemId, $rating, $profileId);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        }

        return (new Response())->json(['message' => 'Rating saved']);
    }

    /**
     * Set the authenticated user's "like" level for a media item.
     *
     * Body: `{ "level": <int −2..2> }` on the thumbs axis (−2 = strongly
     * dislike, −1 = dislike, 0 = not set, 1 = like, 2 = love). Unlike the
     * rating, the like level is non-nullable (0 = not set) and required: a
     * missing/null, non-numeric, non-integer, or out-of-range value is a 400.
     * The validation/coercion mirrors {@see self::setRating()}
     * (bool/non-numeric/non-integer rejected up front), differing only in the
     * −2..2 range and the absence of a null-clears branch.
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint PUT /api/v1/media/{id}/like
     */
    public function setLikeLevel(Request $request, array $params): Response
    {
        $ctx = $this->resolve($request, $params);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $itemId, $profileId] = $ctx;

        $raw = $request->input('level');
        // Reject missing/null and non-integer input up front so only clean ints
        // reach the repository. Bools are numeric-adjacent in PHP but never a
        // valid level. The repository enforces the canonical −2..2 range.
        if (
            $raw === null
            || is_bool($raw)
            || !is_numeric($raw)
            || (float) $raw !== floor((float) $raw)
        ) {
            return (new Response())->status(400)->json(
                ['error' => 'level must be an integer between -2 and 2']
            );
        }
        $level = (int) $raw;

        try {
            $this->userItemData->setLikeLevel($userId, $itemId, $level, $profileId);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        }

        return (new Response())->json(['message' => 'Love level saved']);
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
        [$userId, $itemId, $profileId] = $ctx;

        $this->userItemData->setRating($userId, $itemId, null, $profileId);

        return (new Response())->json(['message' => 'Rating cleared']);
    }

    /**
     * Mark a media item as watched for the authenticated user (Step 11.6).
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint POST /api/v1/media/{id}/watched
     */
    public function markWatched(Request $request, array $params): Response
    {
        $ctx = $this->resolve($request, $params);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $itemId, $profileId] = $ctx;

        $this->userItemData->setWatched($userId, $itemId, true, $profileId);

        return (new Response())->json(['message' => 'Item marked as watched']);
    }

    /**
     * Clear the "watched" flag for the authenticated user (Step 11.6).
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint POST /api/v1/media/{id}/unwatched
     */
    public function markUnwatched(Request $request, array $params): Response
    {
        $ctx = $this->resolve($request, $params);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $itemId, $profileId] = $ctx;

        $this->userItemData->setWatched($userId, $itemId, false, $profileId);

        return (new Response())->json(['message' => 'Item marked as unwatched']);
    }

    /**
     * List the authenticated user's favorited media items, most-recently
     * favorited first, as fully shaped media items.
     *
     * Each returned item is hydrated by id and shaped with the SAME
     * {@see MediaItemShaper::shape()} used by the media-list endpoint, then
     * carries an add-only `user_data:{favorite:true, rating:int|null,
     * like_level:int, watched:bool}` block.
     * Rows whose underlying media item no longer exists are skipped defensively
     * (the FK cascade normally prevents this, but a sparse join is possible
     * mid-delete). Pagination mirrors `GET /api/v1/media`: `limit` defaults to
     * 50 and is clamped to 1-100, `offset` defaults to 0 and floors at 0.
     *
     * @param array<string, string> $params Route params (unused).
     *
     * @api_endpoint GET /api/v1/users/me/favorites
     */
    public function listFavorites(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        // SECURITY: single shared clamp policy (PageLimit).
        $limit = $request->queryPageSize('limit', 50);
        $offset = $request->queryOffset();

        $rows = $this->userItemData->getFavorites($userId, $limit, $offset, $this->sessionProfileId($request));

        // Favorites are per PROFILE (S79), and the session's profile parental cap
        // still governs what it may see: an over-cap favorite (by effective
        // rating) is filtered out for a capped profile. No-op for the owner /
        // un-capped profile (null filter).
        $ratingFilter = $this->ratingGate?->resolveFilterForUser($userId);

        $items = [];
        foreach ($rows as $row) {
            $itemId = is_string($row['item_id'] ?? null) ? $row['item_id'] : '';
            if ($itemId === '') {
                continue;
            }

            $item = $this->itemRepository->findById($itemId);
            if ($item === null) {
                // Defensive: media item gone since the favorite row was written.
                continue;
            }

            if (
                $ratingFilter !== null
                && $this->ratingGate !== null
                && !$this->ratingGate->isAllowed($item, $ratingFilter)
            ) {
                continue;
            }

            $shaped = MediaItemShaper::shape($item);
            // ADD-ONLY user_data block (mirrors the media-detail endpoint). Every
            // row from getFavorites() is a favorite by definition (favorite = 1).
            // `like_level` is the signed −2..2 thumbs axis (−2 = strongly dislike,
            // −1 = dislike, 0 = not set, 1 = like, 2 = love; Feature 10),
            // defaulting to 0 when absent/NULL/non-numeric. `watched` is the
            // seen/unseen flag (Step 11.6), defaulting to false when NULL/absent.
            $rating = $row['rating'] ?? null;
            $likeLevel = $row['like_level'] ?? null;
            $watched = $row['watched'] ?? null;
            $shaped['user_data'] = [
                'favorite' => true,
                'rating' => is_numeric($rating) ? (int) $rating : null,
                'like_level' => is_numeric($likeLevel) ? (int) $likeLevel : 0,
                'watched' => (bool) (is_numeric($watched) ? (int) $watched : 0),
            ];
            $items[] = $shaped;
        }

        return (new Response())->json([
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Shared guard: enforce auth (401), a present path id (400), and an existing
     * media item (404). Returns the resolved [userId, itemId, profileId] tuple on
     * success, or a ready-to-send error Response on failure.
     *
     * S80: the third element is the profile THIS SESSION is running as, taken from
     * `Request::$profileId` — which RequestAuthenticator set from the token's
     * already-ownership-checked `profile_id` claim. It is deliberately read from
     * the request OBJECT and never from `$params`, the query string or the body:
     * a caller-named profile here would let user A write into user B's favorites.
     * Null falls the repository back to the account's default profile.
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @return array{0: string, 1: string, 2: string|null}|Response
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

        return [$userId, $itemId, $this->sessionProfileId($request)];
    }

    /**
     * The profile this request runs as, or null for the account default.
     *
     * ⚠ Reads `Request::$profileId` ONLY. That field is populated from a JWT
     * claim this server signed and then re-verified against the caller's
     * `user_id`; nothing a client can set reaches it.
     */
    private function sessionProfileId(Request $request): ?string
    {
        $profileId = $request->profileId;

        return is_string($profileId) && $profileId !== '' ? $profileId : null;
    }
}
