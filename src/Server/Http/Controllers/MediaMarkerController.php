<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\MarkerService;
use Phlix\Media\MarkerType;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\RequestContext;

final readonly class MediaMarkerController
{
    public function __construct(
        private MarkerService $markerService,
        private ItemRepository $itemRepository,
        /**
         * minors M-SERVER-80 (W-min100): resolves whether the requesting user
         * is an ACTIVE admin — the only role allowed to delete ownerless
         * (legacy NULL `user_id`) marker rows. Required, so PHP-DI autowiring
         * and the Application fallback both must wire it; an unwired
         * controller cannot exist.
         */
        private UserRepository $users,
    ) {
    }

    /**
     * Get markers for a media item.
     * Note: This endpoint is NOT registered to avoid conflicting with MarkerController's
     * skip marker set endpoint. User marker listing should go through getPlaybackInfo
     * or a dedicated endpoint in the future.
     */
    public function getMarkers(string $id): Response
    {
        $markers = $this->markerService->findByMediaItem($id);
        return (new Response())->json(['markers' => array_map(fn($m) => $m->toArray(), $markers)]);
    }

    public function createMarker(string $id): Response
    {
        // Verify the media item exists before allowing marker creation
        $item = $this->itemRepository->findById($id);
        if ($item === null) {
            return (new Response())->status(404)->json(['error' => 'Media item not found']);
        }

        // P3-S1: Associate marker with the authenticated user
        $userId = RequestContext::getUserId();
        if ($userId === null) {
            return (new Response())->status(401)->json(['error' => 'Authentication required']);
        }

        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody)) {
            return (new Response())->status(400)->json(['error' => 'Invalid request body']);
        }
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return (new Response())->status(400)->json(['error' => 'Invalid JSON body']);
        }
        $body = $decoded;
        $typeStr = is_string($body['type'] ?? null) ? $body['type'] : '';
        $type = MarkerType::tryFrom($typeStr);
        if ($type === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid or missing marker type']);
        }
        $startMs = is_int($body['startMs'] ?? null)
            ? $body['startMs']
            : (is_numeric($body['startMs'] ?? null) ? (int) ($body['startMs']) : 0);
        $endMs = is_int($body['endMs'] ?? null)
            ? $body['endMs']
            : (is_numeric($body['endMs'] ?? null) ? (int) ($body['endMs']) : 0);
        $label = is_string($body['label'] ?? null) ? $body['label'] : '';
        // P3-S1: Pass userId so markers are associated with their creator
        $marker = $this->markerService->upsert($id, $type, $startMs, $endMs, $label, $userId);
        return (new Response())->json($marker->toArray(), 201);
    }

    /**
     * Delete a marker by ID.
     *
     * P3-S1 Ownership check: Verifies that:
     * 1. The request is authenticated (userId in context)
     * 2. The marker exists for the given media item
     * 3. The current user owns the marker (user_id matches), OR the marker
     *    has no user_id (NULL = legacy system marker) and the requester is an
     *    ACTIVE admin (minors M-SERVER-80: non-admins are refused 403)
     */
    public function deleteMarker(string $id, string $markerId): Response
    {
        // Verify authenticated user context exists
        $userId = RequestContext::getUserId();
        if ($userId === null) {
            return (new Response())->status(401)->json(['error' => 'Authentication required']);
        }

        // Verify the marker exists for this media item and check ownership
        $markers = $this->markerService->findByMediaItem($id);
        $found = null;
        foreach ($markers as $m) {
            if ($m->id === (int) $markerId) {
                $found = $m;
                break;
            }
        }
        if ($found === null) {
            return (new Response())->status(404)->json(['error' => 'Marker not found']);
        }

        // P3-S1: Enforce ownership — user-owned markers require the requesting
        // user to be the owner. minors M-SERVER-80 (W-min100): legacy NULL-owner
        // rows are NO LONGER deletable by any authenticated user — that made
        // every member able to erase another account's (or the system's)
        // markers on any item. Only an ACTIVE admin may delete them (the
        // curation path); everyone else gets the same 403 family.
        if ($found->userId === null) {
            if ($this->users->findAdminById($userId) === null) {
                return (new Response())->status(403)->json([
                    'error' => 'Admin access is required to delete a marker without an owner',
                ]);
            }
        } elseif ($found->userId !== $userId) {
            return (new Response())->status(403)->json(['error' => 'Not authorized to delete this marker']);
        }

        $this->markerService->delete((int) $markerId);
        return (new Response())->json(['message' => 'Marker deleted successfully']);
    }
}
