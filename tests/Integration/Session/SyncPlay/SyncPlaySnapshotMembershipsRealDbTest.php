<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Session\SyncPlay;

use Phlix\Auth\UserRepository;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Session\SyncPlay\SyncPlaySnapshotService;
use Phlix\Server\Http\Controllers\SyncPlayController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * MED-2 (SyncPlay audit) — the REAL-MySQL half of room visibility.
 *
 * The unit venue (SyncPlayVisibilityRestTest) proves the controller's
 * members-or-admin filter over an in-memory store; THIS file pins the layer
 * beneath it: {@see SyncPlaySnapshotService::listGroupMemberships()} running
 * the production `JSON_EXTRACT(serialized_state, '$.members')` against the
 * genuine MySQL JSON column of `syncplay_snapshots`, and the same filter
 * driving the real read rails end-to-end.
 *
 * The store is shared (CI seeds migrations; other suites may have rows), so
 * every assertion is scoped to the ids THIS lane creates, and every created
 * row is purged in tearDown.
 *
 * CI applies all migrations to the `phlix_test` MySQL service before the
 * suite; locally, with no reachable MySQL, the guard skips — the same venue
 * contract as {@see SyncPlayIdentitySharedStoreIntegrationTest}.
 */
final class SyncPlaySnapshotMembershipsRealDbTest extends TestCase
{
    use RequiresRealDatabase;

    /** Lane marker for MED-2; kept code-resident (never in markdown) per lane contract. */
    private const LANE_TOKEN = 'MED2VISREALDBK9T4';

    private const HOST_USER_ID = 'jwt-med2-host';
    private const MEMBER_USER_ID = 'jwt-med2-member';
    private const OUTSIDER_USER_ID = 'jwt-med2-outsider';
    private const ADMIN_USER_ID = 'jwt-med2-admin';
    private const PASSWORD = 'med2-secret';

    private ?Connection $db = null;

    /** @var list<string> created group ids to purge from the shared snapshot table */
    private array $createdGroupIds = [];

    private SyncPlaySnapshotService $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping SyncPlay MED-2 real-DB visibility test. Runs in CI.');
        $this->assertNotNull($this->db);
        $this->store = new SyncPlaySnapshotService();
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            foreach ($this->createdGroupIds as $groupId) {
                $db->query('DELETE FROM syncplay_snapshots WHERE group_id = ?', [$groupId]);
            }
        }
        $this->createdGroupIds = [];
        parent::tearDown();
    }

    /**
     * listGroupMemberships() must read the JWT-subject member ids back out of
     * the real JSON column — the exact key set GroupState published.
     */
    public function testListGroupMembershipsReadsRealJsonColumn(): void
    {
        $this->assertNotSame('', self::LANE_TOKEN);

        $groupId = $this->createRoomViaRail(self::HOST_USER_ID, 'Med2 Json Room', self::PASSWORD);
        $this->joinRoomViaRail($groupId, self::MEMBER_USER_ID);

        $memberships = $this->store->listGroupMemberships();

        $this->assertArrayHasKey($groupId, $memberships, 'a published room must appear in the membership view');
        $actual = $memberships[$groupId];
        sort($actual);
        $this->assertSame([self::HOST_USER_ID, self::MEMBER_USER_ID], $actual);
    }

    /**
     * End-to-end over the real store: the outsider's list omits the room, the
     * member's includes it, the admin's includes it, and the outsider's direct
     * state read is the existence-agnostic 404.
     */
    public function testReadRailsFilterPerUserAgainstRealStore(): void
    {
        $groupId = $this->createRoomViaRail(self::HOST_USER_ID, 'Med2 Rails Room', self::PASSWORD);
        $this->joinRoomViaRail($groupId, self::MEMBER_USER_ID);

        $controller = $this->controllerWithAdmin();

        $outsiderList = $this->decode($controller->listGroups(
            $this->request('GET', '/api/v1/syncplay/groups', self::OUTSIDER_USER_ID, []),
            []
        ));
        $this->assertNotContains(
            $groupId,
            array_column($outsiderList['groups'], 'id'),
            'a non-member must not discover the room through the real read rail'
        );

        $memberList = $this->decode($controller->listGroups(
            $this->request('GET', '/api/v1/syncplay/groups', self::MEMBER_USER_ID, []),
            []
        ));
        $this->assertContains($groupId, array_column($memberList['groups'], 'id'));

        $adminList = $this->decode($controller->listGroups(
            $this->request('GET', '/api/v1/syncplay/groups', self::ADMIN_USER_ID, []),
            []
        ));
        $this->assertContains($groupId, array_column($adminList['groups'], 'id'));

        $hidden = $controller->getGroup(
            $this->request('GET', '/api/v1/syncplay/groups/' . $groupId, self::OUTSIDER_USER_ID, []),
            ['id' => $groupId]
        );
        $this->assertSame(404, $hidden->statusCode);
        $this->assertSame(['error' => 'Group not found'], $this->decode($hidden));
        $this->assertStringNotContainsString(self::MEMBER_USER_ID, $hidden->body);

        $open = $controller->getGroup(
            $this->request('GET', '/api/v1/syncplay/groups/' . $groupId, self::MEMBER_USER_ID, []),
            ['id' => $groupId]
        );
        $this->assertSame(200, $open->statusCode);
        $this->assertArrayHasKey(self::MEMBER_USER_ID, $this->decode($open)['group']['members']);
    }

    /**
     * Reading is gated; JOINING a known room is not — an outsider with the id
     * and password still joins over the real write-through rail, then gains
     * visibility of the joined room.
     */
    public function testJoinByKnownIdStillWorksOverRealRail(): void
    {
        $groupId = $this->createRoomViaRail(self::HOST_USER_ID, 'Med2 Join Room', self::PASSWORD);

        $joined = $this->controllerWithoutAdmin()->joinGroup(
            $this->request('POST', '/api/v1/syncplay/groups/' . $groupId . '/join', self::OUTSIDER_USER_ID, [
                'password' => self::PASSWORD,
                'memberName' => 'Olivia',
            ]),
            ['id' => $groupId]
        );
        $this->assertSame(200, $joined->statusCode);
        $this->assertTrue($this->decode($joined)['success']);

        $this->assertContains(
            self::OUTSIDER_USER_ID,
            $this->store->listGroupMemberships()[$groupId],
            'the join must land in the durable membership view the read rails filter on'
        );
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function controllerWithAdmin(): SyncPlayController
    {
        $adminUsers = $this->createMock(UserRepository::class);
        $adminUsers->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $id === self::ADMIN_USER_ID ? ['id' => $id, 'is_admin' => 1] : null
        );

        return new SyncPlayController(new SyncPlayManager(), $this->store, null, $adminUsers);
    }

    private function controllerWithoutAdmin(): SyncPlayController
    {
        // The join rail needs a manager that can hydrate from the snapshot row
        // in THIS process — same construction as production's HTTP worker.
        return new SyncPlayController(new SyncPlayManager(), $this->store);
    }

    private function createRoomViaRail(string $userId, string $name, string $password): string
    {
        $response = $this->controllerWithoutAdmin()->createGroup(
            $this->request('POST', '/api/v1/syncplay/groups', $userId, [
                'name' => $name,
                'password' => $password,
                'memberName' => 'Host',
            ]),
            []
        );
        $this->assertSame(200, $response->statusCode);
        $groupId = (string) $this->decode($response)['group']['group_id'];
        $this->assertNotSame('', $groupId);
        $this->createdGroupIds[] = $groupId;

        return $groupId;
    }

    private function joinRoomViaRail(string $groupId, string $userId): void
    {
        $response = $this->controllerWithoutAdmin()->joinGroup(
            $this->request('POST', '/api/v1/syncplay/groups/' . $groupId . '/join', $userId, [
                'password' => self::PASSWORD,
                'memberName' => 'Member',
            ]),
            ['id' => $groupId]
        );
        $this->assertSame(200, $response->statusCode);
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
