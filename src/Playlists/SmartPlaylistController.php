<?php

/**
 * Phlix media server component: Playlists.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Playlists;

use Phlix\Common\Uuid;
use Phlix\Media\Library\ItemRepository;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Shared\Support\Json;
use Workerman\MySQL\Connection;

/**
 * CRUD/preview handlers for smart playlists — currently UNROUTED.
 *
 * No HTTP surface serves this class: {@see \Phlix\Server\Http\Router::smartPlaylists()}
 * is the registration helper for it and no shipped boot path ever calls it
 * (pinned by SmartPlaylistRegistrationGuardTest). Treat the method bodies
 * below as an unrouted shape, not as live endpoints.
 *
 * The shipped smart story lives under collections: a collection carrying a
 * smart_playlist_id is refreshed by `POST /api/v1/collections/{id}/refresh`
 * (\Phlix\Server\Http\Controllers\CollectionController::refresh(),
 * owner-or-admin scoped per the collections ownership law) and by
 * {@see SmartPlaylistRefreshSubscriber} after a library scan. The
 * smart_playlists table (migrations/004) has no created_by column, so
 * registering these handlers as-is would expose unscoped server-wide CRUD;
 * doing it for real needs a migration, per-actor scoping, and the
 * openapi/WireGuard cascade first.
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
     * List all smart playlists (UNROUTED — see class docblock).
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
     * Create a new smart playlist (UNROUTED — see class docblock).
     *
     * Handler body shape: { name, library_id, rules_json, limit?, sort_by?, sort_desc? }
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
                'error' => 'Name, library and rules are all required',
            ]);
        }

        $name = is_string($data['name']) ? $data['name'] : '';
        $libraryId = is_string($data['library_id']) ? $data['library_id'] : '';
        $rulesJsonInput = $data['rules_json'];

        // Validate JSON (DECODE-ADOPT wave-5: shared inbound seam; see
        // rulesGateRejection() for the answer space + disclosed 'null' flip).
        if (is_string($rulesJsonInput)) {
            $rejection = $this->rulesGateRejection($rulesJsonInput);
            if ($rejection !== null) {
                return $rejection;
            }
        }

        $rulesJson = is_array($rulesJsonInput) ? (json_encode($rulesJsonInput) ?: '{}') : (is_string($rulesJsonInput) ?
            $rulesJsonInput : '{}');
        $limit = is_int($data['limit'] ?? null) ? $data['limit'] : (is_numeric($data['limit'] ?? null) ?
            (int)$data['limit'] : 0);
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
     * Get a single smart playlist (UNROUTED — see class docblock).
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
            return (new Response())->status(400)->json(['error' => 'Playlist ID is required']);
        }

        $playlist = $this->repo->findById($id);

        if (!$playlist) {
            return (new Response())->status(404)->json(['error' => 'Smart playlist not found']);
        }

        return (new Response())->json($playlist->toArray());
    }

    /**
     * Update a smart playlist (UNROUTED — see class docblock).
     *
     * Handler body shape: { name?, library_id?, rules_json?, limit?, sort_by?, sort_desc? }
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
            return (new Response())->status(400)->json(['error' => 'Playlist ID is required']);
        }

        $existing = $this->repo->findById($id);

        if (!$existing) {
            return (new Response())->status(404)->json(['error' => 'Smart playlist not found']);
        }

        $data = $request->body;

        // Validate JSON if provided (DECODE-ADOPT wave-5: shared inbound seam;
        // see rulesGateRejection() — same gate, same answer space as create()).
        if (isset($data['rules_json']) && is_string($data['rules_json'])) {
            $rejection = $this->rulesGateRejection($data['rules_json']);
            if ($rejection !== null) {
                return $rejection;
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
        $limit = isset($data['limit']) ? (is_int($data['limit']) ? $data['limit'] : (is_numeric($data['limit']) ?
            (int)$data['limit'] : $existing->limit)) : $existing->limit;
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
     * Delete a smart playlist (UNROUTED — see class docblock).
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
            return (new Response())->status(400)->json(['error' => 'Playlist ID is required']);
        }

        $existing = $this->repo->findById($id);

        if (!$existing) {
            return (new Response())->status(404)->json(['error' => 'Smart playlist not found']);
        }

        $this->repo->delete($id);

        return (new Response())->status(204);
    }

    /**
     * Preview/evaluate rules without saving (UNROUTED — see class docblock).
     *
     * Handler body shape: { rules_json?, limit?, sort_by?, sort_desc? }
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
            return (new Response())->status(400)->json(['error' => 'Playlist ID is required']);
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
            is_string($rulesJsonData) => $this->decodePreviewRules($rulesJsonData),
            default => null,
        };
        if ($rules === null) {
            $rules = $playlist->getRules();
        }

        if (!is_array($rules)) {
            return (new Response())->status(400)->json(['error' => 'Invalid rules']);
        }

        $rulesMap = [];
        foreach ($rules as $rKey => $rValue) {
            if (is_string($rKey)) {
                $rulesMap[$rKey] = $rValue;
            }
        }

        $limit = isset($data['limit']) ? (is_int($data['limit']) ? $data['limit'] : (is_numeric($data['limit']) ?
            (int)$data['limit'] : $playlist->limit)) : $playlist->limit;
        $sortBy = is_string($data['sort_by'] ?? null) ? $data['sort_by'] : $playlist->sortBy;
        $sortDesc = isset($data['sort_desc']) ? (bool)$data['sort_desc'] : $playlist->sortDesc;

        $matchedItems = $this->engine->evaluateOnScan(
            $rulesMap,
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
     * DECODE-ADOPT (wave-5): the single rules-document gate for create() and
     * update(), replacing both sites' hand-rolled `json_decode()` +
     * `json_last_error()` dance with the shared inbound seam.
     *
     * Answer space: malformed -> the seam's null -> the site's existing 400
     * envelope (byte-identical). Structural over-depth -> the seam rethrows
     * JsonException; the site keeps ITS OWN bad-document answer (same 400
     * envelope — no fresh 500 posture minted where a wire contract already
     * exists) and records the real cause in the log. Scalar-valid documents
     * ('5', 'true') pass through exactly as before — the gate refuses
     * unparseable rules, nothing more.
     *
     * Disclosed flip (unrouted endpoint — zero live-wire impact): the literal
     * string 'null' used to sail through the last_error gate and be persisted
     * raw as the rules document; the seam collapses it onto the bad-document
     * arm, and a null rules document is not a usable playlist definition.
     */
    private function rulesGateRejection(string $rulesJsonInput): ?Response
    {
        try {
            $decoded = Json::decodeInbound($rulesJsonInput);
        } catch (\JsonException $e) {
            error_log('[smart-playlist] rules_json exceeded the shared parse-depth ceiling: ' . $e->getMessage());

            return (new Response())->status(400)->json([
                'error' => 'The playlist rules are not valid JSON',
            ]);
        }

        if ($decoded === null) {
            error_log('[smart-playlist] invalid rules_json from client (undecodable or null document)');

            return (new Response())->status(400)->json([
                'error' => 'The playlist rules are not valid JSON',
            ]);
        }

        return null;
    }

    /**
     * Preview-path decoder: mirrors the historical `json_decode($rules, true)
     * ?: null` falsy-collapse onto the shared seam (null/false/0/''/'0'/[]
     * collapse to null so the caller falls back to the stored rules; truthy
     * non-arrays still reach the explicit 'Invalid rules' 400). Over-depth
     * keeps the SAME collapse answer but is now LOUD instead of silent.
     *
     * @return mixed the decoded document, or null to trigger the stored-rules fallback
     */
    private function decodePreviewRules(string $rulesJsonData): mixed
    {
        try {
            $decoded = Json::decodeInbound($rulesJsonData);
        } catch (\JsonException $e) {
            error_log(
                '[smart-playlist] preview rules_json exceeded the shared parse-depth ceiling: '
                . $e->getMessage(),
            );

            return null;
        }

        return $decoded ?: null;
    }

    /**
     * Generate a UUID for playlist IDs.
     *
     * @return string UUID string
     */
    private function generateUuid(): string
    {
        return Uuid::v4();
    }
}
