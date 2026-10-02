<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\UserRepository;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Session\SyncPlay\SyncPlayBridgePublisher;
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
 * ## Membership topology (S445 — write-through publish bridge)
 *
 * The authoritative live membership table is the single WebSocket worker's
 * in-memory `SyncPlayManager` (count=1, :8097). The REST path here runs in one
 * of 14 HTTP workers (count=14, :8096), each with its OWN `SyncPlayManager`
 * instance that is NOT given a snapshot service (broadcast paths must stay
 * inert here). As of S445 each mutation rail is a WRITE-THROUGH read-modify-
 * publish cycle over the shared `syncplay_snapshots` store: join/leave first
 * hydrate the worker-local manager from the snapshot row when this process has
 * never seen the group (so password/capacity/idempotent-join/host-election run
 * against live truth — a REST join into a WS-live room now works), the
 * unchanged `SyncPlayManager` logic mutates locally, the result is DURABLY
 * PERSISTED (`publishGroup`/`removeGroup`), and only THEN a frame is published
 * on the private unix-socket bridge so the WS worker applies it to the live
 * tables without restart — the WS worker itself never becomes a DB reader; it
 * is fed by published deltas and remains the single authority on live state.
 * The read rails (`listGroups`, `getGroup`) come from the same shared store.
 * Loss posture, idempotency and the full model:
 * `docs/dev/SYNCPLAY_WRITE_THROUGH_BRIDGE.md`.
 *
 * @since 3.5
 */
class SyncPlayController
{
    /** @var SyncPlayManager The SyncPlay manager instance (for mutations) */
    private SyncPlayManager $syncPlayManager;

    /** @var SyncPlaySnapshotService Owns REST-side persistence; reads shared snapshots */
    private SyncPlaySnapshotService $snapshotService;

    /** @var SyncPlayBridgePublisher|null Post-persist publisher to the WS worker; null = local-only legacy rail */
    private ?SyncPlayBridgePublisher $bridgePublisher;

    /**
     * MED-2 (SyncPlay audit): active-admin lookup for the read-rail visibility
     * filter. Null (legacy/unwired construction) FAILS CLOSED — nobody resolves
     * as admin, so reads narrow to members-only instead of staying public.
     *
     * @var UserRepository|null
     */
    private ?UserRepository $adminUsers;

    /**
     * Creates a new SyncPlayController instance.
     *
     * @param SyncPlayManager         $syncPlayManager The SyncPlay manager (mutations)
     * @param SyncPlaySnapshotService $snapshotService REST-owned persistence + snapshot reads
     * @param SyncPlayBridgePublisher|null $bridgePublisher Write-through publisher to the WS
     *     worker (S445); null leaves the rail persist-only (the WS worker self-heals on that
     *     group's next mutation) — used by legacy/no-container construction paths.
     * @param UserRepository|null $adminUsers Active-admin predicate for the MED-2
     *     read-rail visibility filter (trailing-optional: existing constructions
     *     stay legal and fail closed to members-only).
     */
    public function __construct(
        SyncPlayManager $syncPlayManager,
        SyncPlaySnapshotService $snapshotService,
        ?SyncPlayBridgePublisher $bridgePublisher = null,
        ?UserRepository $adminUsers = null
    ) {
        $this->syncPlayManager = $syncPlayManager;
        $this->snapshotService = $snapshotService;
        $this->bridgePublisher = $bridgePublisher;
        $this->adminUsers = $adminUsers;
    }

    /**
     * List the SyncPlay groups VISIBLE to the requester (MED-2: members-or-admin).
     *
     * GET /api/v1/syncplay/groups
     *
     * Reads from the database snapshot published by the authoritative WS worker,
     * then keeps only rooms whose member set contains the requester's JWT subject
     * — unless the requester is an ACTIVE admin ({@see UserRepository::findAdminById()},
     * the same S1-hardened predicate the admin routes enforce), who sees all.
     * Non-members get their (possibly empty) slice, never an error — discovery
     * of other people's rooms is exactly what the ruling removed. Joining a
     * KNOWN room is unaffected.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with groups array
     */
    public function listGroups(Request $request, array $params): Response
    {
        $groups = $this->snapshotService->listGroups();

        $userId = $request->userId ?? '';
        if (!$this->isAdminUser($userId)) {
            $memberships = $this->snapshotService->listGroupMemberships();
            $groups = array_values(array_filter(
                $groups,
                static fn (array $group): bool => in_array($userId, $memberships[$group['id']] ?? [], true)
            ));
        }

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

        // S445 write-through: DURABLE PERSIST FIRST, publish second (ordering is
        // the whole point — a published-but-unpersisted mutation would lie about
        // surviving a WS-worker restart, and a persisted-but-unpublished one
        // self-heals on the group's next mutation instead).
        $groupId = is_string($result['group']['group_id'] ?? null) ? $result['group']['group_id'] : '';
        $this->persistAndPublishUpsert($groupId);

        return (new Response())->json(['success' => true, 'group' => $result['group']]);
    }

    /**
     * Get the state of one SyncPlay group — members-or-admin only (MED-2).
     *
     * GET /api/v1/syncplay/groups/{id}
     *
     * Reads the full state (including the members roster) from the database
     * snapshot published by the authoritative WS worker. The roster is exactly
     * why this rail is now visibility-gated: only a member of the room (or an
     * ACTIVE admin, for support workflows) may read it.
     *
     * Refusal shape law: a non-member asking for an EXISTING room receives the
     * byte-identical 404 a missing room gets. A 403 would confirm the id's
     * existence and turn this endpoint into a group-id oracle; the pre-existing
     * miss arm is the honest, existence-agnostic answer (mirrors how the audit
     * directive and the codebase's privacy refusals read).
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

        $userId = $request->userId ?? '';
        $isMember = array_key_exists($userId, is_array($group['members'] ?? null) ? $group['members'] : []);
        if (!$isMember && !$this->isAdminUser($userId)) {
            // Existence-agnostic refusal — same envelope, same status as the
            // missing-room arm above (MED-2: no id oracle, no roster exfil).
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

        // S445 — REST owns persistence: hydrate this worker's manager from the
        // shared snapshot row when we have never seen the group, so the join
        // gates (password, capacity, idempotent re-join) run against live truth
        // instead of this process's phantom table.
        $this->hydrateFromSnapshot($groupId);

        $result = $this->syncPlayManager->joinGroup($groupId, $memberId, $memberName, $password);

        if ($result['success'] === false) {
            return (new Response())->status(400)->json(['error' => $result['error']]);
        }

        $this->persistAndPublishUpsert($groupId);

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

        // S445 — hydrate the routed group first: the member may exist only in
        // the shared snapshot (created/joined through another worker or the WS
        // transport), and legacy semantics keep the leave keyed on the MEMBER,
        // so the route {id} is the hydrate target, not the leave target.
        $routeGroupId = is_string($params['id'] ?? null) ? $params['id'] : '';
        $this->hydrateFromSnapshot($routeGroupId);

        $affectedGroupId = $this->syncPlayManager->getMemberGroup($memberId) ?? '';

        $result = $this->syncPlayManager->leaveGroup($memberId);

        if ($result['success'] === false) {
            return (new Response())->status(400)->json(['error' => $result['error']]);
        }

        // Persist (delete when the leave emptied the group) THEN publish.
        if ($affectedGroupId !== '') {
            $this->persistAndPublishState($affectedGroupId);
        }

        return (new Response())->json([
            'success' => true,
            'message' => $result['message'] ?? null,
        ]);
    }

    /**
     * Install the shared snapshot row for a group into this worker's manager.
     *
     * Join/leave ALWAYS re-hydrate before mutating (when a row exists): the
     * write-through publish replaces the live membership set wholesale, so a
     * stale per-process base would silently drop members another worker (or
     * the WS transport) added in the meantime. The mirror row IS the truth
     * this rail modifies; re-adopting it is exactly one indexed SELECT.
     */
    private function hydrateFromSnapshot(string $groupId): void
    {
        if ($groupId === '') {
            return;
        }

        $serialized = $this->snapshotService->loadSerialized($groupId);
        if ($serialized !== null) {
            $this->syncPlayManager->adoptGroupFromSerialized($serialized);
        }
    }

    /**
     * Write-through half of the rail: durably persist the group's new state to
     * the snapshot store, THEN publish the frame to the WS worker. A snapshot
     * write failure MUST surface (a 200 that lied about durability is worse
     * than a 500); a bridge publish failure never does (fire-and-forget loss
     * posture, docs/dev/SYNCPLAY_WRITE_THROUGH_BRIDGE.md).
     */
    private function persistAndPublishUpsert(string $groupId): void
    {
        $group = $groupId === '' ? null : $this->syncPlayManager->getGroup($groupId);
        if ($group === null) {
            return;
        }

        $this->snapshotService->publishGroup($group);
        $this->bridgePublisher?->publishUpsert($group);
    }

    /**
     * Leave-rail twin of {@see persistAndPublishUpsert()}: the group may have
     * just emptied, and "gone" is itself a state that must be persisted and
     * published (delete frame / tombstone) before the response.
     */
    private function persistAndPublishState(string $groupId): void
    {
        $group = $this->syncPlayManager->getGroup($groupId);
        if ($group === null) {
            $this->snapshotService->removeGroup($groupId);
            $this->bridgePublisher?->publishDelete($groupId);

            return;
        }

        $this->snapshotService->publishGroup($group);
        $this->bridgePublisher?->publishUpsert($group);
    }

    /**
     * Active-admin predicate for the MED-2 read-rail visibility filter.
     *
     * Uses {@see UserRepository::findAdminById()} — the S1-hardened, active-only
     * lookup the AdminMiddleware routes enforce (is_admin = 1 AND status = 'active'),
     * never a soft flag from a session or token claim. Fail-closed in both
     * directions: empty user id and unwired repository are never admin.
     */
    private function isAdminUser(string $userId): bool
    {
        if ($userId === '' || $this->adminUsers === null) {
            return false;
        }

        return $this->adminUsers->findAdminById($userId) !== null;
    }
}
