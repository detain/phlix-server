<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Session\SyncPlay\GroupState;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\ConnectionInterface;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Tests\Unit\Server\WebSocket\TestableSyncPlayManager;
use Phlix\Tests\Unit\Server\WebSocket\TestConnection;
use Phlix\Tests\Support\SyncPlay\InMemorySyncPlaySnapshotService;

class SyncPlayManagerTest extends TestCase
{
    private SyncPlayManager $manager;

    protected function setUp(): void
    {
        $this->manager = new SyncPlayManager();
    }

    protected function tearDown(): void
    {
        // Reset the ConnectionPool singleton between tests to prevent state leakage
        $pool = ConnectionPool::getInstance();
        $pool->clear();
    }

    public function testCanCreateSyncPlayManager(): void
    {
        $this->assertInstanceOf(SyncPlayManager::class, $this->manager);
    }

    public function testCreateGroupSuccess(): void
    {
        $result = $this->manager->createGroup('Test Group');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('group', $result);
        $this->assertEquals('Test Group', $result['group']['group_name']);
    }

    public function testCreateGroupWithPassword(): void
    {
        $result = $this->manager->createGroup('Test Group', 'password123');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('group', $result);
    }

    public function testCreateGroupWithMemberSetsHost(): void
    {
        $result = $this->manager->createGroup('Test Group', null, 'member_1', 'Host User');

        $this->assertTrue($result['success']);
        $this->assertEquals('member_1', $result['group']['host_id']);
        $this->assertEquals(1, $result['group']['member_count']);
    }

    public function testJoinGroupSuccess(): void
    {
        $createResult = $this->manager->createGroup('Test Group', null, 'host_1', 'Host User');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $joinResult = $this->manager->joinGroup($groupId, 'member_2', 'User 2');

        $this->assertTrue($joinResult['success']);
        $this->assertEquals(2, $joinResult['group']['member_count']);
    }

    public function testJoinGroupWithPassword(): void
    {
        $createResult = $this->manager->createGroup('Test Group', 'secret');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $joinResult = $this->manager->joinGroup($groupId, 'member_2', 'User 2', 'secret');

        $this->assertTrue($joinResult['success']);
    }

    public function testJoinGroupWithWrongPasswordFails(): void
    {
        $createResult = $this->manager->createGroup('Test Group', 'secret');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $joinResult = $this->manager->joinGroup($groupId, 'member_2', 'User 2', 'wrong');

        $this->assertFalse($joinResult['success']);
        $this->assertEquals('Invalid password', $joinResult['error']);
    }

    public function testJoinNonexistentGroupFails(): void
    {
        $result = $this->manager->joinGroup('nonexistent', 'member_1', 'User 1');

        $this->assertFalse($result['success']);
        $this->assertEquals('Group not found', $result['error']);
    }

    public function testLeaveGroupSuccess(): void
    {
        $createResult = $this->manager->createGroup('Test Group', null, 'member_1', 'Host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $this->manager->joinGroup($groupId, 'member_2', 'User 2');

        $leaveResult = $this->manager->leaveGroup('member_2');

        $this->assertTrue($leaveResult['success']);
    }

    public function testLeaveGroupNotInGroupFails(): void
    {
        $result = $this->manager->leaveGroup('nonexistent');

        $this->assertFalse($result['success']);
        $this->assertEquals('Not in any group', $result['error']);
    }

    public function testLeaveGroupRemovesMemberFromGroup(): void
    {
        $createResult = $this->manager->createGroup('Test Group', null, 'member_1', 'Host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $this->manager->joinGroup($groupId, 'member_2', 'User 2');

        $this->manager->leaveGroup('member_2');

        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertEquals(1, $state['member_count']);
    }

    public function testGetGroupStateReturnsState(): void
    {
        $createResult = $this->manager->createGroup('Test Group', null, 'member_1', 'Host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $state = $this->manager->getGroupState($groupId);

        $this->assertIsArray($state);
        $this->assertEquals($groupId, $state['group_id']);
    }

    public function testGetGroupStateReturnsNullForNonexistent(): void
    {
        $state = $this->manager->getGroupState('nonexistent');

        $this->assertNull($state);
    }

    public function testListGroupsReturnsAllGroups(): void
    {
        $this->manager->createGroup('Group 1');
        $this->manager->createGroup('Group 2');

        $list = $this->manager->listGroups();

        $this->assertCount(2, $list);
    }

    public function testGetMemberGroupReturnsGroupId(): void
    {
        $createResult = $this->manager->createGroup('Test Group', null, 'member_1', 'Host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $foundGroupId = $this->manager->getMemberGroup('member_1');

        $this->assertEquals($groupId, $foundGroupId);
    }

    public function testGetMemberGroupReturnsNullForNonMember(): void
    {
        $result = $this->manager->getMemberGroup('nonexistent');

        $this->assertNull($result);
    }

    public function testGetTimeSyncReturnsTimeSyncInstance(): void
    {
        $timeSync = $this->manager->getTimeSync();

        $this->assertInstanceOf(\Phlix\Session\SyncPlay\TimeSync::class, $timeSync);
    }

    public function testCleanupStaleGroupsRemovesInactiveGroups(): void
    {
        // This is more of a structural test since we can't easily
        // simulate time passage in unit tests
        $this->manager->createGroup('Group 1');

        $removed = $this->manager->cleanupStaleGroups(3600);

        $this->assertEquals(0, $removed);
    }

    public function testGetStatsReturnsStatistics(): void
    {
        $this->manager->createGroup('Group 1');
        $this->manager->createGroup('Group 2', null, 'member_1', 'User');

        $stats = $this->manager->getStats();

        $this->assertArrayHasKey('total_groups', $stats);
        $this->assertArrayHasKey('total_members', $stats);
        $this->assertArrayHasKey('time_sync_status', $stats);
        $this->assertEquals(2, $stats['total_groups']);
    }

    public function testGroupPasswordIsHashed(): void
    {
        // Create a group with password
        $result = $this->manager->createGroup('Test Group', 'secret');

        $this->assertTrue($result['success']);

        // Verify the group requires password
        /** @var array{group: array{group_id: string}} $result */
        $state = $this->manager->getGroupState($result['group']['group_id']);
        $this->assertNotNull($state);
    }

    public function testMultipleMembersCanJoinGroup(): void
    {
        $createResult = $this->manager->createGroup('Test Group', null, 'host', 'Host User');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $this->manager->joinGroup($groupId, 'member_1', 'User 1');
        $this->manager->joinGroup($groupId, 'member_2', 'User 2');
        $this->manager->joinGroup($groupId, 'member_3', 'User 3');

        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */

        $this->assertEquals(4, $state['member_count']); // host + 3 members
    }

    /**
     * S289 — the same identity re-joining is IDEMPOTENT, not a second member and
     * not an error. This is the property that makes one human over two transports,
     * a reconnect, or two tabs of one account collapse to exactly one member.
     */
    public function testJoinIsIdempotentForExistingMember(): void
    {
        $createResult = $this->manager->createGroup('Test Group', null, 'member_1', 'User 1');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $result = $this->manager->joinGroup($groupId, 'member_1', 'User 1 Again');

        $this->assertTrue($result['success'], 're-joining as an existing identity must succeed, not error');
        $this->assertSame(
            1,
            $result['group']['member_count'],
            'an existing identity re-joining must NOT create a second member'
        );
        $this->assertSame(
            ['member_1'],
            array_keys($result['group']['members']),
            'the member dict stays keyed by the one identity'
        );
        $this->assertSame(
            'User 1 Again',
            $result['group']['members']['member_1']['name'],
            'the display name is refreshed on an idempotent re-join'
        );
    }

    /**
     * S289 — on an idempotent re-join the member's connection is re-pointed to the
     * newest socket and the stale reverse-map entry is dropped, so broadcasts follow
     * the most-recent connection (two-tabs / reconnect semantics).
     */
    public function testIdempotentJoinRepointsToNewestConnection(): void
    {
        $createResult = $this->manager->createGroup(
            'Test Group',
            null,
            'member_1',
            'User 1',
            'conn-old'
        );
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $result = $this->manager->joinGroup($groupId, 'member_1', 'User 1', null, 'conn-new');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['group']['member_count']);

        $this->assertSame($groupId, $this->manager->getMemberGroup('member_1'));

        // The connection->member reverse map must now resolve the NEW socket and no
        // longer the old one, else a broadcast on close of the old socket would strand.
        $connMap = new \ReflectionProperty($this->manager, 'connectionToMember');
        $connMap->setAccessible(true);
        /** @var array<string, string> $map */
        $map = $connMap->getValue($this->manager);
        $this->assertArrayHasKey('conn-new', $map);
        $this->assertSame('member_1', $map['conn-new']);
        $this->assertArrayNotHasKey('conn-old', $map, 'the stale connection entry must be dropped');
    }

    public function testHostTransferOnHostLeave(): void
    {
        // Create group with host
        $createResult = $this->manager->createGroup('Test Group', null, 'host_1', 'Host 1');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        // Add another member
        $this->manager->joinGroup($groupId, 'member_2', 'Member 2');

        // Verify host
        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertEquals('host_1', $state['host_id']);

        // Leave host - should trigger election
        $this->manager->leaveGroup('host_1');

        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        // New host should be elected (either member_2 or null if group became empty temporarily)
        $this->assertNotEquals('host_1', $state['host_id']);
    }

    public function testEmptyGroupIsRemoved(): void
    {
        $createResult = $this->manager->createGroup('Test Group', null, 'member_1', 'User 1');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $this->manager->leaveGroup('member_1');

        $state = $this->manager->getGroupState($groupId);

        $this->assertNull($state);
    }

    public function testMessagesProtocolVersionIsOne(): void
    {
        $this->assertEquals(1, Messages::PROTOCOL_VERSION);
    }

    public function testGroupStateConstants(): void
    {
        $this->assertEquals('playing', GroupState::STATE_PLAYING);
        $this->assertEquals('paused', GroupState::STATE_PAUSED);
        $this->assertEquals('buffering', GroupState::STATE_BUFFERING);
        $this->assertEquals('stopped', GroupState::STATE_STOPPED);
    }

    public function testGroupStateMaxMembersConstant(): void
    {
        $this->assertEquals(50, GroupState::MAX_MEMBERS);
    }

    // =====================================================================
    // SP3: Member ↔ connection_id binding tests
    // =====================================================================

    public function testCreateGroupStoresConnectionIdOnMemberRecord(): void
    {
        $result = $this->manager->createGroup(
            'Test Group',
            null,
            'member_1',
            'Host User',
            'conn-abc123'
        );

        $this->assertTrue($result['success']);
        /** @var array{group: array{group_id: string}} $result */
        $state = $this->manager->getGroupState($result['group']['group_id']);
        $this->assertNotNull($state);
        // connection_id is stored in the member record inside GroupState
        $groupState = $this->manager->listGroups()[0] ?? null;
        $this->assertNotNull($groupState);
    }

    public function testJoinGroupStoresConnectionIdOnMemberRecord(): void
    {
        $createResult = $this->manager->createGroup('Test Group', null, 'host_1', 'Host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $joinResult = $this->manager->joinGroup(
            $groupId,
            'member_2',
            'User 2',
            null,
            'conn-xyz789'
        );

        $this->assertTrue($joinResult['success']);
        $state = $this->manager->getGroupState($groupId);
        $this->assertNotNull($state);
        /** @var array{members: array<string, array{id: string, name: string}>} $state */
        // Verify member_2 is in the group
        $member2 = null;
        foreach ($state['members'] as $m) {
            if ($m['id'] === 'member_2') {
                $member2 = $m;
                break;
            }
        }
        $this->assertNotNull($member2);
        $this->assertEquals('User 2', $member2['name']);
    }

    public function testOnConnectionCloseRemovesMemberFromGroup(): void
    {
        $createResult = $this->manager->createGroup(
            'Test Group',
            null,
            'host_1',
            'Host',
            'conn-host'
        );
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $this->manager->joinGroup($groupId, 'member_2', 'User 2', null, 'conn-member');

        $stateBefore = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $stateBefore */
        $this->assertEquals(2, $stateBefore['member_count']);

        $this->manager->onConnectionClose('conn-member');

        $stateAfter = $this->manager->getGroupState($groupId);
        /** @var array{member_count: int, members: array<string, array{id: string, name: string}>} $stateAfter */
        $this->assertEquals(1, $stateAfter['member_count']);
        $member2Found = false;
        foreach ($stateAfter['members'] as $m) {
            if ($m['id'] === 'member_2') {
                $member2Found = true;
                break;
            }
        }
        $this->assertFalse($member2Found, 'member_2 should have been removed from the group');
    }

    public function testBroadcastToGroupDeliversToAllConnectedMembers(): void
    {
        $createResult = $this->manager->createGroup(
            'Test Group',
            null,
            'host_1',
            'Host',
            'conn-host'
        );
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $this->manager->joinGroup($groupId, 'member_2', 'User 2', null, 'conn-member-2');
        $this->manager->joinGroup($groupId, 'member_3', 'User 3', null, 'conn-member-3');

        // Create mock connections for each member and add to ConnectionPool
        $pool = ConnectionPool::getInstance();

        $mockConnHost = $this->createMock(ConnectionInterface::class);
        $mockConnHost->method('getId')->willReturn('conn-host');
        $mockConn2 = $this->createMock(ConnectionInterface::class);
        $mockConn2->method('getId')->willReturn('conn-member-2');
        $mockConn3 = $this->createMock(ConnectionInterface::class);
        $mockConn3->method('getId')->willReturn('conn-member-3');

        // Track which connections received sends
        $sentTo = [];
        $mockConnHost->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (array $frame) use (&$sentTo): bool {
                $sentTo['conn-host'] = $frame;
                return true;
            });
        $mockConn2->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (array $frame) use (&$sentTo): bool {
                $sentTo['conn-member-2'] = $frame;
                return true;
            });
        $mockConn3->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (array $frame) use (&$sentTo): bool {
                $sentTo['conn-member-3'] = $frame;
                return true;
            });

        $pool->add($mockConnHost);
        $pool->add($mockConn2);
        $pool->add($mockConn3);

        // Invoke private broadcastToGroup via reflection
        $reflection = new \ReflectionMethod($this->manager, 'broadcastToGroup');
        $reflection->setAccessible(true);
        $reflection->invoke(
            $this->manager,
            $groupId,
            Messages::TYPE_INFO,
            ['message' => 'hello'],
            []
        );

        $this->assertCount(3, $sentTo, 'All 3 members should receive the broadcast');
        foreach (['conn-host', 'conn-member-2', 'conn-member-3'] as $connId) {
            $this->assertArrayHasKey($connId, $sentTo);
            $frame = $sentTo[$connId];
            $this->assertArrayHasKey('type', $frame);
            $this->assertEquals(Messages::TYPE_INFO, $frame['type']);
            $this->assertArrayHasKey('message', $frame);
            $this->assertEquals('hello', $frame['message']);
            $this->assertArrayHasKey('timestamp', $frame);
        }
    }

    public function testBroadcastToGroupExcludesSpecifiedMemberIds(): void
    {
        $createResult = $this->manager->createGroup(
            'Test Group',
            null,
            'host_1',
            'Host',
            'conn-host'
        );
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $this->manager->joinGroup($groupId, 'member_2', 'User 2', null, 'conn-member-2');
        $this->manager->joinGroup($groupId, 'member_3', 'User 3', null, 'conn-member-3');

        $pool = ConnectionPool::getInstance();

        $mockConnHost = $this->createMock(ConnectionInterface::class);
        $mockConnHost->method('getId')->willReturn('conn-host');
        $mockConn2 = $this->createMock(ConnectionInterface::class);
        $mockConn2->method('getId')->willReturn('conn-member-2');
        $mockConn3 = $this->createMock(ConnectionInterface::class);
        $mockConn3->method('getId')->willReturn('conn-member-3');

        $sentTo = [];
        $mockConnHost->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (array $frame) use (&$sentTo): bool {
                $sentTo['conn-host'] = $frame;
                return true;
            });
        // member_2 should NOT be called (excluded by member ID)
        $mockConn2->expects($this->never())->method('send');
        $mockConn3->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (array $frame) use (&$sentTo): bool {
                $sentTo['conn-member-3'] = $frame;
                return true;
            });

        $pool->add($mockConnHost);
        $pool->add($mockConn2);
        $pool->add($mockConn3);

        // Exclude member_2 from the broadcast
        $reflection = new \ReflectionMethod($this->manager, 'broadcastToGroup');
        $reflection->setAccessible(true);
        $reflection->invoke(
            $this->manager,
            $groupId,
            Messages::TYPE_INFO,
            ['message' => 'hello'],
            ['member_2']
        );

        $this->assertCount(2, $sentTo, 'Exactly 2 members (host + member_3) should receive the broadcast');
        $this->assertArrayHasKey('conn-host', $sentTo);
        $this->assertArrayHasKey('conn-member-3', $sentTo);
        $this->assertArrayNotHasKey('conn-member-2', $sentTo);
    }

    // =================================================================
    // HIGH-1 — spec `password_hash` gate over WS (field-name split-brain fix).
    // SPEC §4/§8.2: create/join frames carry `password_hash` (SHA-256 hex);
    // the pre-fix parser read only legacy `password`, so a spec client's
    // protected room was created PUBLIC (fail-open). These drive the REAL
    // production handlers via TestableSyncPlayManager::publicHandleMessage.
    // =================================================================

    private function wireManager(): TestableSyncPlayManager
    {
        return new TestableSyncPlayManager(new MessageHandler(ConnectionPool::getInstance()));
    }

    private function authedConnection(string $id, string $userId): TestConnection
    {
        $connection = new TestConnection($id);
        $connection->setAuthenticated(true, $userId);

        return $connection;
    }

    /**
     * @param array<array-key, mixed> $frame
     */
    private function assertErrorCode(array $frame, string $code): void
    {
        $this->assertSame(Messages::TYPE_ERROR, $frame['type'] ?? null);
        $this->assertSame($code, $frame['error_code'] ?? null);
    }

    public function testSpecPasswordHashCreateThenJoinRoundTripsThroughRealWireHandlers(): void
    {
        $wire = $this->wireManager();
        $hash = hash('sha256', 'hunter2');

        $host = $this->authedConnection('h-1', 'user-host');
        $wire->publicHandleMessage($host, [
            'type' => Messages::TYPE_GROUP_CREATE,
            'group_name' => 'Locked Room',
            'password_hash' => $hash,
        ]);

        $stateFrames = $host->framesOfType(Messages::TYPE_GROUP_STATE);
        $this->assertCount(1, $stateFrames, 'spec-shape create with a valid digest must succeed');
        $groupId = $stateFrames[0]['group']['group_id'];
        $this->assertIsString($groupId);
        $group = $wire->getGroup($groupId);
        $this->assertNotNull($group);
        $this->assertTrue($group->hasPassword(), 'the wire digest must gate the room, not vanish');
        $this->assertSame($hash, $group->serialize()['password_hash'], 'stored VERBATIM (no server re-hash)');

        // Correct digest joins.
        $guest = $this->authedConnection('g-1', 'user-guest');
        $wire->publicHandleMessage($guest, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupId,
            'password_hash' => $hash,
        ]);
        $this->assertCount(1, $guest->framesOfType(Messages::TYPE_GROUP_STATE), 'correct digest must be admitted');

        // Wrong digest refused.
        $wrong = $this->authedConnection('g-2', 'user-wrong');
        $wire->publicHandleMessage($wrong, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupId,
            'password_hash' => hash('sha256', 'not-it'),
        ]);
        $wrongFrames = $wrong->getSentMessages();
        $this->assertNotEmpty($wrongFrames);
        $this->assertErrorCode($wrongFrames[count($wrongFrames) - 1], 'syncplay.invalid_password');

        // Absent digest on a protected room refused.
        $absent = $this->authedConnection('g-3', 'user-absent');
        $wire->publicHandleMessage($absent, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupId,
        ]);
        $absentFrames = $absent->getSentMessages();
        $this->assertErrorCode($absentFrames[count($absentFrames) - 1], 'syncplay.invalid_password');
    }

    public function testMalformedPasswordHashIsRefusedLoudlyAndCreatesNothing(): void
    {
        $wire = $this->wireManager();
        foreach (['nope', str_repeat('g', 64), substr(hash('sha256', 'x'), 0, 63), 12345] as $malformed) {
            $host = $this->authedConnection('h-mal', 'user-mal');
            $wire->publicHandleMessage($host, [
                'type' => Messages::TYPE_GROUP_CREATE,
                'group_name' => 'Should Not Exist',
                'password_hash' => $malformed,
            ]);
            $this->assertSame(
                [],
                $host->framesOfType(Messages::TYPE_GROUP_STATE),
                'a malformed digest must never mint a room'
            );
            $this->assertNotEmpty($host->getSentMessages(), 'the refusal must be visible to the client');
        }

        $this->assertSame(0, $wire->getStats()['total_groups'], 'no group may exist behind a refused create');
    }

    public function testLegacyPlaintextFieldStillProtectsAcrossBothWireShapes(): void
    {
        $wire = $this->wireManager();
        $hash = hash('sha256', 'legacy-secret');

        $host = $this->authedConnection('l-h', 'legacy-host');
        $wire->publicHandleMessage($host, [
            'type' => Messages::TYPE_GROUP_CREATE,
            'group_name' => 'Legacy Room',
            'password' => 'legacy-secret',
        ]);
        $stateFrames = $host->framesOfType(Messages::TYPE_GROUP_STATE);
        $this->assertCount(1, $stateFrames);
        $groupId = $stateFrames[0]['group']['group_id'];
        $this->assertIsString($groupId);

        // The mirror direction the scan flagged: a legacy-created (server-hashed)
        // room must accept a SPEC joiner's digest — same stored value.
        $specGuest = $this->authedConnection('l-spec', 'legacy-guest');
        $wire->publicHandleMessage($specGuest, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupId,
            'password_hash' => $hash,
        ]);
        $this->assertCount(1, $specGuest->framesOfType(Messages::TYPE_GROUP_STATE));

        // …and the legacy plaintext joiner keeps working unchanged.
        $legacyGuest = $this->authedConnection('l-leg', 'legacy-guest-2');
        $wire->publicHandleMessage($legacyGuest, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupId,
            'password' => 'legacy-secret',
        ]);
        $this->assertCount(1, $legacyGuest->framesOfType(Messages::TYPE_GROUP_STATE));

        $wrongLegacy = $this->authedConnection('l-wrong', 'legacy-guest-3');
        $wire->publicHandleMessage($wrongLegacy, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupId,
            'password' => 'nope',
        ]);
        $frames = $wrongLegacy->getSentMessages();
        $this->assertErrorCode($frames[count($frames) - 1], 'syncplay.invalid_password');
    }

    public function testManagerLevelHashGateStoresVerbatimAndRejectsNonCanonical(): void
    {
        $hash = hash('sha256', 'direct-api');

        $result = $this->manager->createGroup('Direct', null, 'd-host', 'Host', null, $hash);
        $this->assertTrue($result['success']);
        $groupId = $result['group']['group_id'];
        $directGroup = $this->manager->getGroup($groupId);
        $this->assertNotNull($directGroup);
        $this->assertSame($hash, $directGroup->serialize()['password_hash']);

        $joinOk = $this->manager->joinGroup($groupId, 'd-guest', 'Guest', null, null, $hash);
        $this->assertTrue($joinOk['success']);

        $joinWrong = $this->manager->joinGroup($groupId, 'd-guest-2', 'G2', null, null, hash('sha256', 'other'));
        $this->assertFalse($joinWrong['success']);
        $this->assertSame('syncplay.invalid_password', $joinWrong['error_code'] ?? null);

        $this->expectException(\InvalidArgumentException::class);
        $this->manager->createGroup('X', null, null, null, null, 'not-a-digest');
    }

    // =================================================================
    // MED-3 — joinGroup must imply leave-of-prior-group.
    // =================================================================

    public function testJoiningSecondGroupDetachesFromFirstWithHostReelection(): void
    {
        $pool = ConnectionPool::getInstance();

        $connU1 = $this->authedConnection('conn-u1', 'u1');
        $connU2 = $this->authedConnection('conn-u2', 'u2');
        $pool->add($connU1);
        $pool->add($connU2);

        $a = $this->manager->createGroup('Room A', null, 'u1', 'One', 'conn-u1');
        $groupA = $a['group']['group_id'];
        $this->assertIsString($groupA);
        $this->manager->joinGroup($groupA, 'u2', 'Two', null, 'conn-u2');

        $b = $this->manager->createGroup('Room B', null, 'u9', 'Nine', 'conn-u9');
        $groupB = $b['group']['group_id'];
        $this->assertIsString($groupB);

        // u1 — A's HOST — moves to B.
        $moved = $this->manager->joinGroup($groupB, 'u1', 'One', null, 'conn-u1');
        $this->assertTrue($moved['success']);

        $stateA = $this->manager->getGroupState($groupA);
        $this->assertNotNull($stateA, 'A survives with its remaining member');
        $this->assertArrayNotHasKey('u1', $stateA['members'], 'the ghost must be gone from A roster');
        $this->assertSame(1, $stateA['member_count']);

        $this->assertSame($groupB, $this->manager->getMemberGroup('u1'));
        $groupAObject = $this->manager->getGroup($groupA);
        $this->assertNotNull($groupAObject);
        $this->assertSame('u2', $groupAObject->getHostId(), 'A re-elected its oldest member');
        $this->assertCount(1, $connU2->framesOfType(Messages::TYPE_HOST_ELECT), 'A broadcast the election');

        // A's future broadcasts must never reach u1's connection again.
        $seenBefore = count($connU1->getSentMessages());
        $reflection = new \ReflectionMethod($this->manager, 'broadcastToGroup');
        $reflection->setAccessible(true);
        $reflection->invoke($this->manager, $groupA, Messages::TYPE_INFO, ['message' => 'A only'], []);
        $this->assertSame(
            $seenBefore,
            count($connU1->getSentMessages()),
            'moved member must never receive the OLD room broadcasts again'
        );
        $this->assertCount(1, $connU2->framesOfType(Messages::TYPE_INFO));
    }

    public function testJoiningSecondGroupTearsDownFirstRoomWhenLeftEmpty(): void
    {
        $snapshots = new InMemorySyncPlaySnapshotService();
        $this->manager->setSnapshotService($snapshots);

        $a = $this->manager->createGroup('Solo A', null, 'solo', 'Solo', 'conn-solo');
        $groupA = $a['group']['group_id'];
        $this->assertIsString($groupA);
        $this->assertArrayHasKey($groupA, $snapshots->rows(), 'create published its snapshot');

        $b = $this->manager->createGroup('Room B', null, 'u9', 'Nine', 'conn-b9');
        $groupB = $b['group']['group_id'];
        $this->assertIsString($groupB);

        $this->manager->joinGroup($groupB, 'solo', 'Solo', null, 'conn-solo');

        $this->assertNull($this->manager->getGroupState($groupA), 'A emptied by the move must be gone');
        $this->assertArrayNotHasKey($groupA, $snapshots->rows(), 'A snapshot torn down by the move');
        $this->assertNotContains($groupA, array_column($this->manager->listGroups(), 'id'));
    }

    // =================================================================
    // LOW-2 — queue mutation publishes snapshots and is capped.
    // =================================================================

    public function testQueueReplacementPublishesSnapshot(): void
    {
        $snapshots = new InMemorySyncPlaySnapshotService();
        $wire = $this->wireManager();
        $wire->setSnapshotService($snapshots);

        $host = $this->authedConnection('q-host', 'q-user');
        ConnectionPool::getInstance()->add($host);

        $created = $wire->createGroup('Queue Room', null, 'q-user', 'QHost', 'q-host');
        $groupId = $created['group']['group_id'];
        $this->assertIsString($groupId);

        $wire->publicHandleMessage($host, [
            'type' => Messages::TYPE_PLAYBACK_QUEUE,
            'group_id' => $groupId,
            'queue' => [
                ['media_id' => 'm1', 'media_info' => ['title' => 'One']],
                ['media_id' => 'm2'],
            ],
        ]);

        $this->assertCount(1, $host->framesOfType(Messages::TYPE_PLAYBACK_QUEUE));
        $row = $snapshots->loadSerialized($groupId);
        $this->assertNotNull($row);
        $this->assertSame(
            ['m1', 'm2'],
            array_column($row['playback_queue'], 'media_id'),
            'the queue mutation must reach the snapshot store'
        );
    }

    public function testOversizedQueueIsRefusedLoudlyAndCurrentQueueUntouched(): void
    {
        $snapshots = new InMemorySyncPlaySnapshotService();
        $wire = $this->wireManager();
        $wire->setSnapshotService($snapshots);

        $host = $this->authedConnection('c-host', 'c-user');
        ConnectionPool::getInstance()->add($host);

        $created = $wire->createGroup('Cap Room', null, 'c-user', 'CHost', 'c-host');
        $groupId = $created['group']['group_id'];
        $this->assertIsString($groupId);

        $wire->publicHandleMessage($host, [
            'type' => Messages::TYPE_PLAYBACK_QUEUE,
            'group_id' => $groupId,
            'queue' => [['media_id' => 'keep-1'], ['media_id' => 'keep-2']],
        ]);

        $overflow = array_map(
            static fn (int $i): array => ['media_id' => 'x' . $i],
            range(1, GroupState::MAX_QUEUE_SIZE + 1)
        );
        $wire->publicHandleMessage($host, [
            'type' => Messages::TYPE_PLAYBACK_QUEUE,
            'group_id' => $groupId,
            'queue' => $overflow,
        ]);

        $frames = $host->getSentMessages();
        $this->assertErrorCode($frames[count($frames) - 1], 'syncplay.group_limit_reached');

        $state = $wire->getGroupState($groupId);
        $this->assertSame(
            ['keep-1', 'keep-2'],
            array_column($state['queue'], 'media_id'),
            'the refused frame must leave the live queue byte-identical (no clear-then-grow)'
        );
    }

    public function testQueueAtExactlyTheCapIsAccepted(): void
    {
        $wire = $this->wireManager();
        $host = $this->authedConnection('e-host', 'e-user');
        ConnectionPool::getInstance()->add($host);

        $created = $wire->createGroup('Edge Room', null, 'e-user', 'EHost', 'e-host');
        $groupId = $created['group']['group_id'];
        $this->assertIsString($groupId);

        $atCap = array_map(
            static fn (int $i): array => ['media_id' => 'm' . $i],
            range(1, GroupState::MAX_QUEUE_SIZE)
        );
        $wire->publicHandleMessage($host, [
            'type' => Messages::TYPE_PLAYBACK_QUEUE,
            'group_id' => $groupId,
            'queue' => $atCap,
        ]);

        $this->assertCount(GroupState::MAX_QUEUE_SIZE, $wire->getGroupState($groupId)['queue']);
        $this->assertSame([], $host->framesOfType(Messages::TYPE_ERROR), 'exactly-at-cap is legal, not an overflow');
    }
}
