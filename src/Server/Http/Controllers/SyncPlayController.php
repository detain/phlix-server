<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Session\SyncPlay\SyncPlayManager;

/**
 * Handles SyncPlay group watching HTTP requests.
 *
 * Wraps the SyncPlayManager's WebSocket-based group management
 * with a REST API for the admin UI to create/join/leave groups.
 *
 * @since 3.5
 */
class SyncPlayController
{
    /** @var SyncPlayManager The SyncPlay manager instance */
    private SyncPlayManager $syncPlayManager;

    /**
     * Creates a new SyncPlayController instance.
     *
     * @param SyncPlayManager $syncPlayManager The SyncPlay manager
     */
    public function __construct(SyncPlayManager $syncPlayManager)
    {
        $this->syncPlayManager = $syncPlayManager;
    }

    /**
     * List all available SyncPlay groups.
     *
     * GET /api/v1/syncplay/groups
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with groups array
     */
    public function listGroups(Request $request, array $params): Response
    {
        $groups = $this->syncPlayManager->listGroups();
        return (new Response())->json(['groups' => $groups]);
    }

    /**
     * Create a new SyncPlay group.
     *
     * POST /api/v1/syncplay/groups
     * Body: { name: string, password?: string, memberId?: string, memberName?: string }
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

        // Use userId as memberId if not provided, generate a display name
        $memberId = is_string($body['memberId'] ?? null) && $body['memberId'] !== ''
            ? $body['memberId']
            : ($userId !== '' ? $userId : 'anon_' . bin2hex(random_bytes(4)));
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

        $group = $this->syncPlayManager->getGroupState($groupId);

        if ($group === null) {
            return (new Response())->status(404)->json(['error' => 'Group not found']);
        }

        return (new Response())->json(['group' => $group]);
    }

    /**
     * Join an existing SyncPlay group.
     *
     * POST /api/v1/syncplay/groups/{id}/join
     * Body: { password?: string, memberId?: string, memberName?: string }
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

        // Use userId as memberId if not provided, generate a display name
        $memberId = is_string($body['memberId'] ?? null) && $body['memberId'] !== ''
            ? $body['memberId']
            : ($userId !== '' ? $userId : 'user_' . bin2hex(random_bytes(4)));
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
     * Body: { memberId?: string }
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters with 'id' for group ID
     * @return Response JSON response with success and optional message
     */
    public function leaveGroup(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';

        $body = $request->body;
        // Use userId as memberId if not provided
        $memberId = is_string($body['memberId'] ?? null) && $body['memberId'] !== ''
            ? $body['memberId']
            : $userId;

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
