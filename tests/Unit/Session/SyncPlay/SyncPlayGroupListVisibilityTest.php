<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserRepository;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Tests\Unit\Server\WebSocket\TestableSyncPlayManager;
use Phlix\Tests\Unit\Server\WebSocket\TestConnection;

/**
 * MED-2 (SyncPlay audit) — room VISIBILITY is members-or-admin.
 *
 * The WS `group_list` reply used to hand EVERY authenticated connection the
 * full roster of summaries for ALL rooms, including password-protected ones:
 * any user could enumerate names, member counts and what's-watching across the
 * whole estate. Owner ruling: a `group_list` responder sees only rooms it is a
 * member of, unless it is an ACTIVE admin (the same S1-hardened predicate the
 * REST admin paths use — `UserRepository::findAdminById`, active-only).
 *
 * Visibility is a READ concern only: joining a KNOWN room (by id + password)
 * must keep working for non-members, which the last test pins.
 *
 * Fail-closed posture: a manager constructed WITHOUT the admin repository
 * (legacy paths: standalone worker, tests) resolves nobody as admin — the
 * members-only filter still applies, so forgetting the wiring shrinks
 * exposure rather than widening it.
 */
final class SyncPlayGroupListVisibilityTest extends TestCase
{
    private const ADMIN_USER_ID = 'jwt-admin';

    protected function setUp(): void
    {
        parent::setUp();
        ConnectionPool::getInstance()->clear();
    }

    protected function tearDown(): void
    {
        ConnectionPool::getInstance()->clear();
        parent::tearDown();
    }

    /**
     * RED-FIRST (parent): a non-member's group_list contained BOTH foreign
     * rooms, the passworded one included. Post-fix it must be empty.
     */
    public function testNonMemberGroupListIsEmpty(): void
    {
        $manager = $this->managerWithAdminLookup();
        $this->seedPrivateAndOpenRooms($manager);

        $outsider = $this->authenticate('jwt-outsider');

        $frame = $this->groupListFrame($manager, $outsider);

        $this->assertSame([], $frame['groups'], 'a non-member must receive no room summaries');
        $this->assertSame(0, $frame['count']);
    }

    /**
     * A member sees exactly their own rooms — the private one here — and not
     * the foreign open room.
     */
    public function testMemberSeesOnlyOwnRooms(): void
    {
        $manager = $this->managerWithAdminLookup();
        $groupIds = $this->seedPrivateAndOpenRooms($manager);

        // host-a is a member of the private room only.
        $hostA = $this->memberConnection('conn-a', 'jwt-host-a');
        $frame = $this->groupListFrame($manager, $hostA);

        $ids = array_column($frame['groups'], 'id');
        $this->assertSame([$groupIds['private']], $ids);
        $this->assertSame(1, $frame['count']);
    }

    /**
     * An ACTIVE admin sees every room, private included.
     */
    public function testAdminSeesAllRooms(): void
    {
        $manager = $this->managerWithAdminLookup();
        $groupIds = $this->seedPrivateAndOpenRooms($manager);

        $admin = $this->authenticate(self::ADMIN_USER_ID);
        $frame = $this->groupListFrame($manager, $admin);

        $ids = array_column($frame['groups'], 'id');
        sort($ids);
        $expected = [$groupIds['open'], $groupIds['private']];
        sort($expected);
        $this->assertSame($expected, $ids, 'an active admin must see the full room list');
    }

    /**
     * Fail-closed: with no admin repository wired, even a real admin is held to
     * members-only visibility (a wiring gap must never EXPOSE rooms).
     */
    public function testUnwiredAdminRepositoryFailsClosedToMembersOnly(): void
    {
        $manager = $this->managerWithoutAdminLookup();
        $groupIds = $this->seedPrivateAndOpenRooms($manager);

        $admin = $this->authenticate(self::ADMIN_USER_ID);
        $frame = $this->groupListFrame($manager, $admin);

        $this->assertSame([], $frame['groups'], 'unwired admin lookup must default to members-only');
        $this->assertSame(0, $frame['count']);

        $hostB = $this->memberConnection('conn-c', 'jwt-host-c');
        $memberFrame = $this->groupListFrame($manager, $hostB);
        $this->assertSame(
            [$groupIds['open']],
            array_column($memberFrame['groups'], 'id'),
            'member visibility is unaffected by the missing admin wiring'
        );
    }

    /**
     * The visibility gate must NOT touch joining: an outsider who knows the
     * room id (and password) still joins through the unchanged join handler.
     */
    public function testNonMemberCanStillJoinByKnownId(): void
    {
        $manager = $this->managerWithAdminLookup();
        $groupIds = $this->seedPrivateAndOpenRooms($manager);

        $outsider = $this->memberConnection('conn-x', 'jwt-outsider');

        $manager->publicHandleMessage($outsider, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupIds['private'],
            'member_name' => 'Xavier',
            'password' => 'hunter2',
        ]);

        $stateFrames = $outsider->framesOfType(Messages::TYPE_GROUP_STATE);
        $this->assertNotEmpty($stateFrames, 'join by known id + password must still succeed for a non-member');

        // And now that they ARE a member, their own room appears in group_list.
        $frame = $this->groupListFrame($manager, $outsider);
        $this->assertSame(
            [$groupIds['private']],
            array_column($frame['groups'], 'id'),
            'joining grants visibility of the joined room'
        );
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function managerWithAdminLookup(): TestableSyncPlayManager
    {
        $adminUsers = $this->createMock(UserRepository::class);
        $adminUsers->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $id === self::ADMIN_USER_ID ? ['id' => $id, 'is_admin' => 1] : null
        );

        return new TestableSyncPlayManager(new MessageHandler(ConnectionPool::getInstance()), $adminUsers);
    }

    private function managerWithoutAdminLookup(): TestableSyncPlayManager
    {
        return new TestableSyncPlayManager(new MessageHandler(ConnectionPool::getInstance()));
    }

    /**
     * Raw connection: authenticated, in the pool, but member of no group.
     */
    private function authenticate(string $userId): TestConnection
    {
        $connection = new TestConnection('conn-' . $userId);
        $connection->setAuthenticated(true, $userId);
        ConnectionPool::getInstance()->add($connection);

        return $connection;
    }

    /**
     * Connection that participates in the WS join/create protocol (so the
     * manager registers it against the group).
     */
    private function memberConnection(string $connId, string $userId): TestConnection
    {
        $connection = new TestConnection($connId);
        $connection->setAuthenticated(true, $userId);
        ConnectionPool::getInstance()->add($connection);

        return $connection;
    }

    /**
     * Two rooms via the real WS protocol: one passworded (host-a), one open
     * (host-c). Returns their ids.
     *
     * @return array{private: string, open: string}
     */
    private function seedPrivateAndOpenRooms(TestableSyncPlayManager $manager): array
    {
        $hostA = $this->memberConnection('conn-a', 'jwt-host-a');
        $manager->publicHandleMessage($hostA, [
            'type' => Messages::TYPE_GROUP_CREATE,
            'group_name' => 'Private Party',
            'member_name' => 'A',
            'password' => 'hunter2',
        ]);

        $hostC = $this->memberConnection('conn-c', 'jwt-host-c');
        $manager->publicHandleMessage($hostC, [
            'type' => Messages::TYPE_GROUP_CREATE,
            'group_name' => 'Open Lounge',
            'member_name' => 'C',
        ]);

        $privateId = $this->createdGroupId($hostA, 'seed private room');
        $openId = $this->createdGroupId($hostC, 'seed open room');

        return ['private' => $privateId, 'open' => $openId];
    }

    private function createdGroupId(TestConnection $host, string $context): string
    {
        $frames = $host->framesOfType(Messages::TYPE_GROUP_STATE);
        $this->assertNotEmpty($frames, "seed ($context): create must answer with group_state");
        /** @var array<string, mixed> $group */
        $group = $frames[count($frames) - 1]['group'] ?? [];
        $groupId = (string) ($group['group_id'] ?? '');
        $this->assertNotSame('', $groupId, "seed ($context): group_id must be present");

        return $groupId;
    }

    /**
     * @return array<string, mixed>
     */
    private function groupListFrame(TestableSyncPlayManager $manager, TestConnection $connection): array
    {
        $manager->publicHandleMessage($connection, ['type' => Messages::TYPE_GROUP_LIST]);

        $frames = $connection->framesOfType(Messages::TYPE_GROUP_LIST);
        $this->assertNotEmpty($frames, 'group_list must be answered');
        /** @var array<string, mixed> $frame */
        $frame = $frames[count($frames) - 1];

        return $frame;
    }
}
