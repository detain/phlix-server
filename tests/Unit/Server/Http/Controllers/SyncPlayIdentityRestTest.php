<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Session\SyncPlay\SyncPlaySnapshotService;
use Phlix\Server\Http\Controllers\SyncPlayController;
use Phlix\Server\Http\Request;

/**
 * S289 — the REST transport derives a member's identity from the authenticated JWT
 * subject (`Request::$userId`) and NOTHING else. A client-supplied `memberId` body
 * field is parsed away, so one human is the same member whether they arrive over
 * REST or the WebSocket.
 *
 * This is the controller-boundary half of the AC. The transport-collapse half (the
 * same identity over both transports resolving to ONE member, plus reconnect and the
 * two-tabs model) lives in `SyncPlayE2ETest`; the cross-process phantom boundary
 * (REST writes are per-process until the SP6 bridge) is pinned against real MySQL in
 * `SyncPlayIdentitySharedStoreIntegrationTest`.
 *
 * The manager and snapshot service here are the REAL classes; no mutation rail reads
 * the snapshot (create/join/leave only mutate the in-memory manager), so no database
 * is required and no double stands in for the logic under test.
 */
final class SyncPlayIdentityRestTest extends TestCase
{
    private SyncPlayManager $manager;
    private SyncPlayController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new SyncPlayManager();
        $this->controller = new SyncPlayController($this->manager, new SyncPlaySnapshotService());
    }

    /**
     * POST /groups keys the creator by the JWT subject even when the body carries a
     * hostile memberId, and the display memberName still flows verbatim.
     */
    public function testCreateGroupKeysCreatorByJwtSubjectNotBodyMemberId(): void
    {
        $response = $this->controller->createGroup(
            $this->request('POST', '/api/v1/syncplay/groups', 'jwt-alice', [
                'name' => 'Movie Night',
                'memberId' => 'ATTACKER-SPOOF',
                'memberName' => 'Alice',
            ]),
            []
        );

        $this->assertSame(200, $response->statusCode);
        $body = $this->json($response->body);
        $members = $body['group']['members'];

        $this->assertSame(
            ['jwt-alice'],
            array_keys($members),
            'the host member id must be the JWT subject, never the body memberId'
        );
        $this->assertArrayNotHasKey('ATTACKER-SPOOF', $members, 'a spoofed body memberId must not mint a member');
        $this->assertSame('Alice', $members['jwt-alice']['name'], 'the display name still flows (S285 preserved)');
    }

    /**
     * POST /groups/{id}/join keys the joiner by the JWT subject; a spoofed body
     * memberId is ignored.
     */
    public function testJoinGroupKeysMemberByJwtSubjectNotBodyMemberId(): void
    {
        $created = $this->json(
            $this->controller->createGroup(
                $this->request('POST', '/api/v1/syncplay/groups', 'jwt-sam', ['name' => 'G', 'memberName' => 'Sam']),
                []
            )->body
        );
        $groupId = (string) $created['group']['group_id'];

        $response = $this->controller->joinGroup(
            $this->request('POST', '/api/v1/syncplay/groups/' . $groupId . '/join', 'jwt-jane', [
                'memberId' => 'GHOST',
                'memberName' => 'Jane',
            ]),
            ['id' => $groupId]
        );

        $this->assertSame(200, $response->statusCode);
        $members = $this->json($response->body)['group']['members'];
        $this->assertSame(['jwt-sam', 'jwt-jane'], array_keys($members));
        $this->assertArrayNotHasKey('GHOST', $members, 'a spoofed body memberId must not mint a member');
    }

    /**
     * POST /groups/{id}/leave removes the CALLER (the JWT subject), not whatever id
     * the body claims.
     */
    public function testLeaveGroupActsOnJwtSubjectNotBodyMemberId(): void
    {
        $created = $this->json(
            $this->controller->createGroup(
                $this->request('POST', '/api/v1/syncplay/groups', 'jwt-sam', ['name' => 'G', 'memberName' => 'Sam']),
                []
            )->body
        );
        $groupId = (string) $created['group']['group_id'];
        $this->controller->joinGroup(
            $this->request('POST', "/api/v1/syncplay/groups/{$groupId}/join", 'jwt-jane', ['memberName' => 'Jane']),
            ['id' => $groupId]
        );

        // Jane leaves but the body tries to evict Sam.
        $response = $this->controller->leaveGroup(
            $this->request('POST', "/api/v1/syncplay/groups/{$groupId}/leave", 'jwt-jane', ['memberId' => 'jwt-sam']),
            ['id' => $groupId]
        );

        $this->assertSame(200, $response->statusCode);
        $state = $this->manager->getGroupState($groupId);
        $this->assertNotNull($state);
        $this->assertSame(
            ['jwt-sam'],
            array_keys($state['members']),
            'the JWT subject left; the spoofed body target must remain'
        );
    }

    /**
     * AC — the same identity reaching the group over REST first, then over the
     * WebSocket join (both funnel to the authoritative manager), is ONE member.
     */
    public function testSameIdentityOverBothTransportsIsExactlyOneMember(): void
    {
        $created = $this->json(
            $this->controller->createGroup(
                $this->request('POST', '/api/v1/syncplay/groups', 'jwt-sam', ['name' => 'G', 'memberName' => 'Sam']),
                []
            )->body
        );
        $groupId = (string) $created['group']['group_id'];

        // REST join as Jane (no WS socket on the HTTP path).
        $this->controller->joinGroup(
            $this->request('POST', "/api/v1/syncplay/groups/{$groupId}/join", 'jwt-jane', ['memberName' => 'Jane']),
            ['id' => $groupId]
        );

        // The same Jane now joins over WebSocket — same JWT subject, live connection.
        $this->manager->joinGroup($groupId, 'jwt-jane', 'Jane', null, 'ws-conn-jane');

        $state = $this->manager->getGroupState($groupId);
        $this->assertNotNull($state);
        $this->assertSame(
            ['jwt-sam', 'jwt-jane'],
            array_keys($state['members']),
            'one human over both transports must be exactly one member'
        );
        $this->assertSame(2, $state['member_count']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, ?string $userId, array $body): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;
        $request->userId = $userId;
        $request->body = $body;

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
