<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserRepository;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Server\Http\Controllers\SyncPlayController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Tests\Support\SyncPlay\InMemorySyncPlaySnapshotService;

/**
 * MED-2 (SyncPlay audit) — REST room READS are members-or-admin.
 *
 * `GET /api/v1/syncplay/groups` and `GET /api/v1/syncplay/groups/{id}` used to
 * serve the shared snapshot store to ANY authenticated user: outsiders could
 * enumerate every room and pull a foreign room's FULL state — the members
 * dict, i.e. user-ids and display names — straight out of the roster of a
 * password-protected party. Owner ruling: visibility (listing + state reads)
 * is restricted to room members or ACTIVE admins.
 *
 * Refusal shape law: a non-member reading a SPECIFIC existing room gets the
 * exact same 404 envelope a missing room gets ({error: "Group not found"}) —
 * the endpoint must never become a group-id oracle, so there is no 403 arm to
 * distinguish here. Listing degrades to a filtered set (empty for outsiders),
 * never an error.
 *
 * Joining by known id stays open (the join rail is untouched and verified
 * here); this restriction governs discovery/reading only.
 */
final class SyncPlayVisibilityRestTest extends TestCase
{
    private const ADMIN_USER_ID = 'jwt-admin';
    private const HOST_USER_ID = 'jwt-host';
    private const MEMBER_USER_ID = 'jwt-member';
    private const OUTSIDER_USER_ID = 'jwt-outsider';
    private const FOREIGN_USER_ID = 'jwt-foreign-host';
    private const PASSWORD = 'hunter2';

    private InMemorySyncPlaySnapshotService $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new InMemorySyncPlaySnapshotService();
    }

    // ── listing ────────────────────────────────────────────────────────────

    /**
     * RED-FIRST (parent): the list served BOTH foreign rooms to the outsider.
     * Post-fix an outsider's list is empty — discovery is member-or-admin.
     */
    public function testOutsiderListIsEmpty(): void
    {
        $controller = $this->controllerWithAdmin();
        $this->seedRooms($controller);

        $body = $this->decode($controller->listGroups(
            $this->request('GET', '/api/v1/syncplay/groups', self::OUTSIDER_USER_ID, []),
            []
        ));

        $this->assertSame([], $body['groups']);
    }

    public function testMemberListContainsOnlyOwnRooms(): void
    {
        $controller = $this->controllerWithAdmin();
        $groupIds = $this->seedRooms($controller);

        $body = $this->decode($controller->listGroups(
            $this->request('GET', '/api/v1/syncplay/groups', self::MEMBER_USER_ID, []),
            []
        ));

        $ids = array_column($body['groups'], 'id');
        $this->assertSame([$groupIds['private']], $ids, 'a member sees the room they are in — and nothing else');
    }

    public function testAdminListSeesEveryRoom(): void
    {
        $controller = $this->controllerWithAdmin();
        $groupIds = $this->seedRooms($controller);

        $body = $this->decode($controller->listGroups(
            $this->request('GET', '/api/v1/syncplay/groups', self::ADMIN_USER_ID, []),
            []
        ));

        $ids = array_column($body['groups'], 'id');
        sort($ids);
        $expected = [$groupIds['open'], $groupIds['private']];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    /**
     * Fail-closed: with no admin repository wired, even an admin is held to
     * member visibility — a wiring gap must never EXPOSE the roster.
     */
    public function testUnwiredAdminRepositoryFailsClosedToMembersOnly(): void
    {
        $controller = $this->controllerWithoutAdmin();
        $groupIds = $this->seedRooms($controller);

        $adminBody = $this->decode($controller->listGroups(
            $this->request('GET', '/api/v1/syncplay/groups', self::ADMIN_USER_ID, []),
            []
        ));
        $this->assertSame([], $adminBody['groups']);

        $memberBody = $this->decode($controller->listGroups(
            $this->request('GET', '/api/v1/syncplay/groups', self::MEMBER_USER_ID, []),
            []
        ));
        $this->assertSame(
            [$groupIds['private']],
            array_column($memberBody['groups'], 'id'),
            'member visibility is unaffected by the missing admin wiring'
        );
    }

    // ── single-room state reads ────────────────────────────────────────────

    /**
     * RED-FIRST (parent): an outsider pulled the FULL state of a foreign
     * passworded room — members dict included. Post-fix: identical 404 to the
     * missing-room arm (no id oracle).
     */
    public function testOutsiderReadOfForeignRoomIsHiddenAsNotFound(): void
    {
        $controller = $this->controllerWithAdmin();
        $groupIds = $this->seedRooms($controller);

        $hidden = $controller->getGroup(
            $this->request('GET', '/api/v1/syncplay/groups/' . $groupIds['private'], self::OUTSIDER_USER_ID, []),
            ['id' => $groupIds['private']]
        );
        $missing = $controller->getGroup(
            $this->request('GET', '/api/v1/syncplay/groups/sp_never_existed', self::OUTSIDER_USER_ID, []),
            ['id' => 'sp_never_existed']
        );

        $this->assertSame(404, $hidden->statusCode);
        $this->assertSame(404, $missing->statusCode);
        $this->assertSame(
            $this->decode($missing),
            $this->decode($hidden),
            'the refusal must be byte-identical to the missing-room arm — never an id oracle'
        );
        $this->assertSame(['error' => 'Group not found'], $this->decode($hidden));
        $this->assertStringNotContainsString(self::MEMBER_USER_ID, $hidden->body);
        $this->assertStringNotContainsString('hunter2', $hidden->body);
    }

    public function testMemberReadsOwnRoomState(): void
    {
        $controller = $this->controllerWithAdmin();
        $groupIds = $this->seedRooms($controller);

        $response = $controller->getGroup(
            $this->request('GET', '/api/v1/syncplay/groups/' . $groupIds['private'], self::MEMBER_USER_ID, []),
            ['id' => $groupIds['private']]
        );

        $this->assertSame(200, $response->statusCode);
        $body = $this->decode($response);
        $this->assertArrayHasKey('members', $body['group']);
        $this->assertArrayHasKey(self::HOST_USER_ID, $body['group']['members']);
        $this->assertArrayHasKey(self::MEMBER_USER_ID, $body['group']['members']);
    }

    public function testAdminReadsAnyRoomState(): void
    {
        $controller = $this->controllerWithAdmin();
        $groupIds = $this->seedRooms($controller);

        $response = $controller->getGroup(
            $this->request('GET', '/api/v1/syncplay/groups/' . $groupIds['private'], self::ADMIN_USER_ID, []),
            ['id' => $groupIds['private']]
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertArrayHasKey(
            self::HOST_USER_ID,
            $this->decode($response)['group']['members'],
            'support workflows need the admin to read a room they are not in'
        );
    }

    // ── join preservation ──────────────────────────────────────────────────

    /**
     * The ruling restricts READING, not joining: an outsider who knows the id
     * and password still joins — and gains visibility of that room afterwards.
     */
    public function testOutsiderCanStillJoinByKnownIdAndGainsVisibility(): void
    {
        $controller = $this->controllerWithAdmin();
        $groupIds = $this->seedRooms($controller);

        $joinPath = '/api/v1/syncplay/groups/' . $groupIds['private'] . '/join';
        $joined = $controller->joinGroup(
            $this->request('POST', $joinPath, self::OUTSIDER_USER_ID, [
                'password' => self::PASSWORD,
                'memberName' => 'Olivia',
            ]),
            ['id' => $groupIds['private']]
        );
        $this->assertSame(200, $joined->statusCode);
        $this->assertTrue($this->decode($joined)['success']);

        $read = $controller->getGroup(
            $this->request('GET', '/api/v1/syncplay/groups/' . $groupIds['private'], self::OUTSIDER_USER_ID, []),
            ['id' => $groupIds['private']]
        );
        $this->assertSame(200, $read->statusCode, 'joining grants read visibility');

        $list = $this->decode($controller->listGroups(
            $this->request('GET', '/api/v1/syncplay/groups', self::OUTSIDER_USER_ID, []),
            []
        ));
        $this->assertSame([$groupIds['private']], array_column($list['groups'], 'id'));
    }

    /**
     * An outsider joining the OPEN room by known id works too (no password) —
     * the join gate is untouched by visibility.
     */
    public function testOutsiderCanJoinOpenRoomByKnownId(): void
    {
        $controller = $this->controllerWithAdmin();
        $groupIds = $this->seedRooms($controller);

        $joined = $controller->joinGroup(
            $this->request('POST', '/api/v1/syncplay/groups/' . $groupIds['open'] . '/join', self::OUTSIDER_USER_ID, [
                'memberName' => 'Olivia',
            ]),
            ['id' => $groupIds['open']]
        );

        $this->assertSame(200, $joined->statusCode);
        $this->assertTrue($this->decode($joined)['success']);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function controllerWithAdmin(): SyncPlayController
    {
        $adminUsers = $this->createMock(UserRepository::class);
        $adminUsers->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $id === self::ADMIN_USER_ID ? ['id' => $id, 'is_admin' => 1] : null
        );

        return new SyncPlayController(
            new SyncPlayManager(),
            $this->store,
            null,
            $adminUsers
        );
    }

    private function controllerWithoutAdmin(): SyncPlayController
    {
        return new SyncPlayController(new SyncPlayManager(), $this->store);
    }

    /**
     * Two rooms through the real controller rails: a passworded one with
     * host+member, and an open one with a foreign host.
     *
     * @return array{private: string, open: string}
     */
    private function seedRooms(SyncPlayController $controller): array
    {
        $private = $this->decode($controller->createGroup(
            $this->request('POST', '/api/v1/syncplay/groups', self::HOST_USER_ID, [
                'name' => 'Private Party',
                'password' => self::PASSWORD,
                'memberName' => 'Hank',
            ]),
            []
        ));
        $privateId = (string) $private['group']['group_id'];

        $this->assertSame(200, $controller->joinGroup(
            $this->request('POST', '/api/v1/syncplay/groups/' . $privateId . '/join', self::MEMBER_USER_ID, [
                'password' => self::PASSWORD,
                'memberName' => 'Mia',
            ]),
            ['id' => $privateId]
        )->statusCode);

        $open = $this->decode($controller->createGroup(
            $this->request('POST', '/api/v1/syncplay/groups', self::FOREIGN_USER_ID, [
                'name' => 'Open Lounge',
                'memberName' => 'Fiona',
            ]),
            []
        ));

        return ['private' => $privateId, 'open' => (string) $open['group']['group_id']];
    }

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
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
