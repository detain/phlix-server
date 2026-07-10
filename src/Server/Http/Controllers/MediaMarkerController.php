<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

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
        $marker = $this->markerService->upsert($id, $type, $startMs, $endMs, $label);
        return (new Response())->json($marker->toArray(), 201);
    }

    /**
     * Delete a marker by ID.
     *
     * Ownership check: Verifies that:
     * 1. The marker exists for the given media item (auth check)
     * 2. The current user owns the marker (ownership check)
     *
     * Note: Full user ownership tracking requires the media_markers table to have
     * a user_id column and MarkerService methods to support user filtering.
     * TODO(ownership): Add user_id to media_markers schema and update MarkerService
     * to filter by user_id in findByMediaItem() and delete().
     */
    public function deleteMarker(string $id, string $markerId): Response
    {
        // Verify authenticated user context exists
        $userId = RequestContext::getUserId();
        if ($userId === null) {
            return (new Response())->status(401)->json(['error' => 'Authentication required']);
        }

        // Verify the marker exists for this media item
        $markers = $this->markerService->findByMediaItem($id);
        $markerIds = array_map(fn($m) => $m->id, $markers);
        if (!in_array((int) $markerId, $markerIds, true)) {
            return (new Response())->status(404)->json(['error' => 'Marker not found']);
        }

        // TODO(ownership): Verify marker.user_id === $userId once the data model supports it.
        // Currently the media_markers table does not have a user_id column, so we cannot
        // enforce true ownership. The above auth check is the minimum required.

        $this->markerService->delete((int) $markerId);
        return (new Response())->json(['message' => 'Marker deleted successfully']);
    }
}
