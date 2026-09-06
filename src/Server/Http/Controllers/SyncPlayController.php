<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Session\SyncPlay\SyncPlaySnapshotService;

/**
 * Handles SyncPlay group watching HTTP requests.
 *
 * Wraps the SyncPlayManager's WebSocket-based group management
 * with a REST API for the admin UI to create/join/leave groups.
 *
 * ## Identity — the S289 design ruling
 *
 * A human's SyncPlay member id is the AUTHENTICATED JWT SUBJECT and nothing
 * else — the same identity the WebSocket transport already keys on
 * ({@see \Phlix\Session\SyncPlay\SyncPlayManager::handleGroupCreate()} reads
 * `$connection->getUserId()`). This controller therefore derives every
 * mutation's `$memberId` from `$request->userId` (set by `AuthMiddleware` to the
 * JWT `sub`, so it is always a non-empty string on these auth-gated routes) and
 * deliberately PARSES AWAY any client-supplied `memberId` body field.
 *
 * Rationale: a client-chosen id meant one human could present as two different
 * members depending on which transport they reached first — the exact root cause
 * that forced the front-end to thread `memberName` down all three join entry
 * points as a stop-gap. Unifying the identity source collapses that: one human
 * over REST and WS is EXACTLY one member, a reconnect keeps the same member
 * (see the idempotent `joinGroup()`), and two tabs of one account are one member
 * whose most-recent connection receives broadcasts. The display `memberName`
 * still flows verbatim — only the identity SOURCE is pinned to the JWT subject.
 *
 * ## Membership topology (SP5, honest state)
 *
 * The authoritative live membership table is the single WebSocket worker's
 * in-memory `SyncPlayManager` (count=1, :8097). The REST path here runs in one
 * of 14 HTTP workers (count=14, :8096), each with its OWN `SyncPlayManager`
 * instance that is NOT given a snapshot service — so the create/join/leave
 * mutations below mutate only THIS worker's per-process tables and are NOT
 * published to the shared `syncplay_snapshots` store or re-hydrated by the WS
 * worker. The read rails (`listGroups`, `getGroup`) DO come from the shared
 * snapshot the WS worker publishes. Cross-worker forwarding of membership
 * mutations to the authoritative WS process is the unwritten SP6 bridge and is
 * tracked as residual work; it is intentionally NOT half-implemented here
 * because writing a snapshot the live WS worker never re-hydrates would only
 * move the phantom, not remove it (a WS-side hydrate touches the S287/S290/S294
 * broadcast path and is its own step). What S289 fixes — the identity source —
 * is what makes that bridge, once built, converge on ONE member instead of two.
 *
 * @since 3.5
 */
class SyncPlayController
{
    /** @var SyncPlayManager The SyncPlay manager instance (for mutations) */
    private SyncPlayManager $syncPlayManager;

    /** @var SyncPlaySnapshotService Reads from WS-published snapshots */
    private SyncPlaySnapshotService $snapshotService;

    /**
     * Creates a new SyncPlayController instance.
     *
     * @param SyncPlayManager        $syncPlayManager The SyncPlay manager (mutations)
     * @param SyncPlaySnapshotService $snapshotService Reads from DB snapshots
     */
    public function __construct(SyncPlayManager $syncPlayManager, SyncPlaySnapshotService $snapshotService)
    {
        $this->syncPlayManager = $syncPlayManager;
        $this->snapshotService = $snapshotService;
    }

    /**
     * List all available SyncPlay groups.
     *
     * GET /api/v1/syncplay/groups
     *
     * Reads from the database snapshot published by the authoritative WS worker.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with groups array
     */
    public function listGroups(Request $request, array $params): Response
    {
        $groups = $this->snapshotService->listGroups();
        return (new Response())->json(['groups' => $groups]);
    }

    /**
     * Create a new SyncPlay group.
     *
     * POST /api/v1/syncplay/groups
     * Body: { name: string, password?: string, memberName?: string }
     *
     * The creator's member id is the authenticated JWT subject; a `memberId` body
     * field (if present) is ignored (S289 — one identity across transports).
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with success and group state
     */
    public function createGroup(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';

        $body = $request->body;
        $name = is_string($body['name'] ?? null) ? $body['name'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : null;

        if ($name === '') {
            return (new Response())->status(400)->json(['error' => 'Group name is required']);
        }

        // S289 — identity is the authenticated JWT subject ONLY; a client-supplied
        // `memberId` body field is parsed away (see class docblock). Display
        // `memberName` still flows verbatim.
        $memberId = $userId;
        $memberName = is_string($body['memberName'] ?? null) && $body['memberName'] !== ''
            ? $body['memberName']
            : 'Host';

        $result = $this->syncPlayManager->createGroup($name, $password, $memberId, $memberName);

        if ($result['success'] === false) {
            return (new Response())->status(400)->json(['error' => $result['error']]);
        }

        return (new Response())->json(['success' => true, 'group' => $result['group']]);
    }

    /**
     * Get details of a specific SyncPlay group.
     *
     * GET /api/v1/syncplay/groups/{id}
     *
     * Reads from the database snapshot published by the authoritative WS worker.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters with 'id' for group ID
     * @return Response JSON response with group state
     */
    public function getGroup(Request $request, array $params): Response
    {
        $groupId = $params['id'] ?? '';

        if ($groupId === '') {
            return (new Response())->status(400)->json(['error' => 'Group ID is required']);
        }

        $group = $this->snapshotService->getGroupState($groupId);

        if ($group === null) {
            return (new Response())->status(404)->json(['error' => 'Group not found']);
        }

        return (new Response())->json(['group' => $group]);
    }

    /**
     * Join an existing SyncPlay group.
     *
     * POST /api/v1/syncplay/groups/{id}/join
     * Body: { password?: string, memberName?: string }
     *
     * The joining member's id is the authenticated JWT subject; a `memberId` body
     * field (if present) is ignored (S289 — one identity across transports).
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters with 'id' for group ID
     * @return Response JSON response with success and group state
     */
    public function joinGroup(Request $request, array $params): Response
    {
        $groupId = $params['id'] ?? '';
        $userId = $request->userId ?? '';

        if ($groupId === '') {
            return (new Response())->status(400)->json(['error' => 'Group ID is required']);
        }

        $body = $request->body;
        $password = is_string($body['password'] ?? null) ? $body['password'] : null;

        // S289 — identity is the authenticated JWT subject ONLY; a client-supplied
        // `memberId` body field is parsed away (see class docblock). `memberName`
        // display value still flows verbatim.
        $memberId = $userId;
        $memberName = is_string($body['memberName'] ?? null) && $body['memberName'] !== ''
            ? $body['memberName']
            : 'Guest';

        $result = $this->syncPlayManager->joinGroup($groupId, $memberId, $memberName, $password);

        if ($result['success'] === false) {
            return (new Response())->status(400)->json(['error' => $result['error']]);
        }

        return (new Response())->json(['success' => true, 'group' => $result['group']]);
    }

    /**
     * Leave a SyncPlay group.
     *
     * POST /api/v1/syncplay/groups/{id}/leave
     * Body: {} (identity is the authenticated JWT subject; `memberId` is ignored)
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters with 'id' for group ID
     * @return Response JSON response with success and optional message
     */
    public function leaveGroup(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';

        // S289 — the leaving member is the authenticated JWT subject; a client-supplied
        // `memberId` body field is parsed away (see class docblock).
        $memberId = $userId;

        if ($memberId === '') {
            return (new Response())->status(400)->json(['error' => 'Member ID is required']);
        }

        $result = $this->syncPlayManager->leaveGroup($memberId);

        if ($result['success'] === false) {
            return (new Response())->status(400)->json(['error' => $result['error']]);
        }

        return (new Response())->json([
            'success' => true,
            'message' => $result['message'] ?? null,
        ]);
    }
}
