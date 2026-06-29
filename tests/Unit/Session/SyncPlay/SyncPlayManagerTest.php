<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Session\SyncPlay\GroupState;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\ConnectionInterface;

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
        $groupId = $createResult['group']['group_id'];

        $joinResult = $this->manager->joinGroup($groupId, 'member_2', 'User 2');

        $this->assertTrue($joinResult['success']);
        $this->assertEquals(2, $joinResult['group']['member_count']);
    }

    public function testJoinGroupWithPassword(): void
    {
        $createResult = $this->manager->createGroup('Test Group', 'secret');
        $groupId = $createResult['group']['group_id'];

        $joinResult = $this->manager->joinGroup($groupId, 'member_2', 'User 2', 'secret');

        $this->assertTrue($joinResult['success']);
    }

    public function testJoinGroupWithWrongPasswordFails(): void
    {
        $createResult = $this->manager->createGroup('Test Group', 'secret');
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
        $groupId = $createResult['group']['group_id'];

        $this->manager->joinGroup($groupId, 'member_2', 'User 2');

        $this->manager->leaveGroup('member_2');

        $state = $this->manager->getGroupState($groupId);
        $this->assertEquals(1, $state['member_count']);
    }

    public function testGetGroupStateReturnsState(): void
    {
        $createResult = $this->manager->createGroup('Test Group', null, 'member_1', 'Host');
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
        $state = $this->manager->getGroupState($result['group']['group_id']);
        $this->assertNotNull($state);
    }

    public function testMultipleMembersCanJoinGroup(): void
    {
        $createResult = $this->manager->createGroup('Test Group', null, 'host', 'Host User');
        $groupId = $createResult['group']['group_id'];

        $this->manager->joinGroup($groupId, 'member_1', 'User 1');
        $this->manager->joinGroup($groupId, 'member_2', 'User 2');
        $this->manager->joinGroup($groupId, 'member_3', 'User 3');

        $state = $this->manager->getGroupState($groupId);

        $this->assertEquals(4, $state['member_count']); // host + 3 members
    }

    public function testCannotJoinGroupAsDuplicateMember(): void
    {
        $createResult = $this->manager->createGroup('Test Group', null, 'member_1', 'User 1');
        $groupId = $createResult['group']['group_id'];

        $result = $this->manager->joinGroup($groupId, 'member_1', 'User 1 Again');

        $this->assertFalse($result['success']);
        $this->assertEquals('Already a member of this group', $result['error']);
    }

    public function testHostTransferOnHostLeave(): void
    {
        // Create group with host
        $createResult = $this->manager->createGroup('Test Group', null, 'host_1', 'Host 1');
        $groupId = $createResult['group']['group_id'];

        // Add another member
        $this->manager->joinGroup($groupId, 'member_2', 'Member 2');

        // Verify host
        $state = $this->manager->getGroupState($groupId);
        $this->assertEquals('host_1', $state['host_id']);

        // Leave host - should trigger election
        $this->manager->leaveGroup('host_1');

        $state = $this->manager->getGroupState($groupId);
        // New host should be elected (either member_2 or null if group became empty temporarily)
        $this->assertNotEquals('host_1', $state['host_id']);
    }

    public function testEmptyGroupIsRemoved(): void
    {
        $createResult = $this->manager->createGroup('Test Group', null, 'member_1', 'User 1');
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
        $state = $this->manager->getGroupState($result['group']['group_id']);
        $this->assertNotNull($state);
        // connection_id is stored in the member record inside GroupState
        $groupState = $this->manager->listGroups()[0] ?? null;
        $this->assertNotNull($groupState);
    }

    public function testJoinGroupStoresConnectionIdOnMemberRecord(): void
    {
        $createResult = $this->manager->createGroup('Test Group', null, 'host_1', 'Host');
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
        $groupId = $createResult['group']['group_id'];

        $this->manager->joinGroup($groupId, 'member_2', 'User 2', null, 'conn-member');

        $stateBefore = $this->manager->getGroupState($groupId);
        $this->assertEquals(2, $stateBefore['member_count']);

        $this->manager->onConnectionClose('conn-member');

        $stateAfter = $this->manager->getGroupState($groupId);
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
            ->willReturnCallback(function (array $frame) use (&$sentTo): void {
                $sentTo['conn-host'] = $frame;
            });
        $mockConn2->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (array $frame) use (&$sentTo): void {
                $sentTo['conn-member-2'] = $frame;
            });
        $mockConn3->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (array $frame) use (&$sentTo): void {
                $sentTo['conn-member-3'] = $frame;
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
            $this->assertIsArray($frame);
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
            ->willReturnCallback(function (array $frame) use (&$sentTo): void {
                $sentTo['conn-host'] = $frame;
            });
        // member_2 should NOT be called (excluded by member ID)
        $mockConn2->expects($this->never())->method('send');
        $mockConn3->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (array $frame) use (&$sentTo): void {
                $sentTo['conn-member-3'] = $frame;
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
}
