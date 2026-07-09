<?php
declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license MIT
 */

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\MarkerService;
use Phlix\Media\MarkerType;
use Phlix\Server\Http\Response;

final readonly class MediaMarkerController
{
    public function __construct(
        private MarkerService $markerService,
    ) {}

    public function getMarkers(string $id): Response
    {
        $markers = $this->markerService->findByMediaItem($id);
        return (new Response())->json(['markers' => array_map(fn($m) => $m->toArray(), $markers)]);
    }

    public function createMarker(string $id): Response
    {
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
        $startMs = is_int($body['startMs'] ?? null) ? $body['startMs'] : (is_numeric($body['startMs'] ?? null) ? (int) ($body['startMs']) : 0);
        $endMs = is_int($body['endMs'] ?? null) ? $body['endMs'] : (is_numeric($body['endMs'] ?? null) ? (int) ($body['endMs']) : 0);
        $label = is_string($body['label'] ?? null) ? $body['label'] : '';
        $marker = $this->markerService->upsert($id, $type, $startMs, $endMs, $label);
        return (new Response())->json($marker->toArray(), 201);
    }

    public function deleteMarker(string $id, string $markerId): Response
    {
        $this->markerService->delete((int) $markerId);
        return (new Response())->json(['message' => 'Marker deleted successfully']);
    }
}
