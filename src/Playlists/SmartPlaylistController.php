<?php

declare(strict_types=1);

namespace Phlex\Playlists;

use Phlex\Media\Library\ItemRepository;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Workerman\MySQL\Connection;

/**
 * REST API controller for smart playlist CRUD operations.
 *
 * Endpoints:
 * - GET    /api/v1/smart-playlists           list all
 * - POST   /api/v1/smart-playlists           create
 * - GET    /api/v1/smart-playlists/{id}      get one
 * - PUT    /api/v1/smart-playlists/{id}      update
 * - DELETE /api/v1/smart-playlists/{id}      delete
 * - POST   /api/v1/smart-playlists/{id}/preview   evaluate without saving
 *
 * @since 0.14.0
 */
final class SmartPlaylistController
{
    private SmartPlaylistRepository $repo;
    private SmartPlaylistEngine $engine;

    public function __construct(
        Connection $db,
        ItemRepository $itemRepository,
    ) {
        $this->repo = new SmartPlaylistRepository($db);
        $this->engine = new SmartPlaylistEngine($itemRepository);
    }

    /**
     * List all smart playlists.
     *
     * GET /api/v1/smart-playlists
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters
     * @return Response JSON response with playlist list
     *
     * @since 0.14.0
     */
    public function index(Request $request, array $params): Response
    {
        $playlists = $this->repo->findAll();

        return (new Response())->json([
            'smart_playlists' => array_map(fn(SmartPlaylist $p) => $p->toArray(), $playlists),
        ]);
    }

    /**
     * Create a new smart playlist.
     *
     * POST /api/v1/smart-playlists
     * Body: { name, library_id, rules_json, limit?, sort_by?, sort_desc? }
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters
     * @return Response JSON response with created playlist
     *
     * @since 0.14.0
     */
    public function create(Request $request, array $params): Response
    {
        $data = $request->body;

        if (empty($data['name']) || empty($data['library_id']) || empty($data['rules_json'])) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: name, library_id, rules_json',
            ]);
        }

        $name = is_string($data['name']) ? $data['name'] : '';
        $libraryId = is_string($data['library_id']) ? $data['library_id'] : '';
        $rulesJsonInput = $data['rules_json'];

        // Validate JSON
        if (is_string($rulesJsonInput)) {
            json_decode($rulesJsonInput);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return (new Response())->status(400)->json([
                    'error' => 'Invalid rules_json: ' . json_last_error_msg(),
                ]);
            }
        }

        $rulesJson = is_array($rulesJsonInput) ? (json_encode($rulesJsonInput) ?: '{}') : (is_string($rulesJsonInput) ? $rulesJsonInput : '{}');
        $limit = is_int($data['limit'] ?? null) ? $data['limit'] : (is_numeric($data['limit'] ?? null) ? (int)$data['limit'] : 0);
        $sortBy = is_string($data['sort_by'] ?? null) ? $data['sort_by'] : 'addedAt';
        $sortDesc = (bool)($data['sort_desc'] ?? true);

        $playlist = new SmartPlaylist(
            id: $this->generateUuid(),
            name: $name,
            libraryId: $libraryId,
            rulesJson: $rulesJson,
            limit: $limit,
            sortBy: $sortBy,
            sortDesc: $sortDesc,
        );

        $this->repo->insert($playlist);

        return (new Response())->status(201)->json($playlist->toArray());
    }

    /**
     * Get a single smart playlist.
     *
     * GET /api/v1/smart-playlists/{id}
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response with playlist or error
     *
     * @since 0.14.0
     */
    public function show(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;

        if (!$id) {
            return (new Response())->status(400)->json(['error' => 'Missing id parameter']);
        }

        $playlist = $this->repo->findById($id);

        if (!$playlist) {
            return (new Response())->status(404)->json(['error' => 'Smart playlist not found']);
        }

        return (new Response())->json($playlist->toArray());
    }

    /**
     * Update a smart playlist.
     *
     * PUT /api/v1/smart-playlists/{id}
     * Body: { name?, library_id?, rules_json?, limit?, sort_by?, sort_desc? }
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response with updated playlist or error
     *
     * @since 0.14.0
     */
    public function update(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;

        if (!$id) {
            return (new Response())->status(400)->json(['error' => 'Missing id parameter']);
        }

        $existing = $this->repo->findById($id);

        if (!$existing) {
            return (new Response())->status(404)->json(['error' => 'Smart playlist not found']);
        }

        $data = $request->body;

        // Validate JSON if provided
        if (isset($data['rules_json']) && is_string($data['rules_json'])) {
            json_decode($data['rules_json']);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return (new Response())->status(400)->json([
                    'error' => 'Invalid rules_json: ' . json_last_error_msg(),
                ]);
            }
        }

        $name = is_string($data['name'] ?? null) ? $data['name'] : $existing->name;
        $libraryId = is_string($data['library_id'] ?? null) ? $data['library_id'] : $existing->libraryId;
        $rulesJsonInput = $data['rules_json'] ?? null;
        $rulesJson = match (true) {
            is_array($rulesJsonInput) => json_encode($rulesJsonInput) ?: $existing->rulesJson,
            is_string($rulesJsonInput) => $rulesJsonInput,
            default => $existing->rulesJson,
        };
        $limit = isset($data['limit']) ? (is_int($data['limit']) ? $data['limit'] : (is_numeric($data['limit']) ? (int)$data['limit'] : $existing->limit)) : $existing->limit;
        $sortBy = is_string($data['sort_by'] ?? null) ? $data['sort_by'] : $existing->sortBy;
        $sortDesc = isset($data['sort_desc']) ? (bool)$data['sort_desc'] : $existing->sortDesc;

        $updated = new SmartPlaylist(
            id: $existing->id,
            name: $name,
            libraryId: $libraryId,
            rulesJson: $rulesJson,
            limit: $limit,
            sortBy: $sortBy,
            sortDesc: $sortDesc,
            createdAt: $existing->createdAt,
        );

        $this->repo->update($updated);

        return (new Response())->json($updated->toArray());
    }

    /**
     * Delete a smart playlist.
     *
     * DELETE /api/v1/smart-playlists/{id}
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response Empty response on success or error
     *
     * @since 0.14.0
     */
    public function delete(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;

        if (!$id) {
            return (new Response())->status(400)->json(['error' => 'Missing id parameter']);
        }

        $existing = $this->repo->findById($id);

        if (!$existing) {
            return (new Response())->status(404)->json(['error' => 'Smart playlist not found']);
        }

        $this->repo->delete($id);

        return (new Response())->status(204);
    }

    /**
     * Preview/evaluate rules without saving.
     *
     * POST /api/v1/smart-playlists/{id}/preview
     * Body: { rules_json?, limit?, sort_by?, sort_desc? }
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response with matched items or error
     *
     * @since 0.14.0
     */
    public function preview(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;

        if (!$id) {
            return (new Response())->status(400)->json(['error' => 'Missing id parameter']);
        }

        $playlist = $this->repo->findById($id);

        if (!$playlist) {
            return (new Response())->status(404)->json(['error' => 'Smart playlist not found']);
        }

        $data = $request->body;

        // Use provided rules or fall back to playlist's rules
        $rulesJsonData = $data['rules_json'] ?? null;
        $rules = match (true) {
            is_array($rulesJsonData) => $rulesJsonData,
            is_string($rulesJsonData) => json_decode($rulesJsonData, true) ?: null,
            default => null,
        };
        if ($rules === null) {
            $rules = $playlist->getRules();
        }

        if (!is_array($rules)) {
            return (new Response())->status(400)->json(['error' => 'Invalid rules']);
        }

        $limit = isset($data['limit']) ? (is_int($data['limit']) ? $data['limit'] : (is_numeric($data['limit']) ? (int)$data['limit'] : $playlist->limit)) : $playlist->limit;
        $sortBy = is_string($data['sort_by'] ?? null) ? $data['sort_by'] : $playlist->sortBy;
        $sortDesc = isset($data['sort_desc']) ? (bool)$data['sort_desc'] : $playlist->sortDesc;

        $matchedItems = $this->engine->evaluateOnScan(
            $rules,
            $playlist->libraryId,
            $limit,
            $sortBy,
            $sortDesc
        );

        return (new Response())->json([
            'playlist_id' => $playlist->id,
            'matched_count' => count($matchedItems),
            'items' => $matchedItems,
        ]);
    }

    /**
     * Generate a UUID for playlist IDs.
     *
     * @return string UUID string
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
