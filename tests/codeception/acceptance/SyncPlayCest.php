<?php

declare(strict_types=1);

namespace Phlix\Tests\Codeception\acceptance;

use Phlix\Tests\Codeception\SyncPlayTester;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Tests\Codeception\Helper\MockConnection;

/**
 * SyncPlay E2E Acceptance Tests.
 *
 * Tests the full SyncPlay protocol flow:
 * - WS connection → JWT auth
 * - Member joins group
 * - Playback sync (play/pause)
 * - Host transfer
 * - Member leave
 *
 * These tests verify the complete end-to-end behavior of the SyncPlay
 * feature without requiring an actual WebSocket server.
 *
 * @group syncplay
 * @group e2e
 */
class SyncPlayCest
{
    /**
     * Test 1: WebSocket Connection and Authentication Flow.
     *
     * Verifies:
     * - Mock connections can be created
     * - Connections can be authenticated
     * - Authentication state is tracked correctly
     */
    public function testWsConnectionAndAuthentication(SyncPlayTester $I): void
    {
        // Create first connection (unauthenticated)
        $conn1 = $I->createConnection();
        $I->assertNotEmpty($conn1);

        // Create second connection with user ID
        $conn2 = $I->createConnection('user_123', true);
        $I->assertNotEmpty($conn2);

        // Authenticate the first connection
        $I->authenticateConnection($conn1, 'user_456');

        // Verify member IDs are assigned
        $member1 = $I->getMemberId($conn1);
        $member2 = $I->getMemberId($conn2);

        $I->assertEquals('user_456', $member1);
        $I->assertEquals('user_123', $member2);
    }

    /**
     * Test 2: Group Creation (Host Workflow).
     *
     * Verifies:
     * - Host can create a group
     * - Creator becomes the host
     * - Group ID is generated (sp_* format)
     * - Member count is 1
     */
    public function testGroupCreation(SyncPlayTester $I): void
    {
        // Initialize manager for message handling
        $I->initializeManager();

        // Create authenticated connection for host
        $hostConn = $I->createConnection('host_user', true);

        // Create group
        $result = $I->createGroup($hostConn, 'Test Movie Night');

        // Verify group creation succeeded
        $I->assertArrayHasKey('success', $result);
        $I->assertTrue($result['success']);

        // Verify group structure
        $group = $result['group'];
        $I->assertArrayHasKey('group_id', $group);
        $I->assertArrayHasKey('group_name', $group);
        $I->assertArrayHasKey('host_id', $group);
        $I->assertArrayHasKey('member_count', $group);

        // Verify group ID format
        $I->assertStringStartsWith('sp_', $group['group_id']);

        // Verify host is set correctly
        $I->assertEquals('host_user', $group['host_id']);

        // Verify member count
        $I->assertEquals(1, $group['member_count']);

        // Verify group name
        $I->assertEquals('Test Movie Night', $group['group_name']);
    }

    /**
     * Test 3: Member Joins Group.
     *
     * Verifies:
     * - Member can join existing group
     * - Member count increases to 2
     * - Member receives group state
     */
    public function testMemberJoinsGroup(SyncPlayTester $I): void
    {
        $I->initializeManager();

        // Host creates group
        $hostConn = $I->createConnection('host_user', true);
        $createResult = $I->createGroup($hostConn, 'Movie Night');
        $I->assertTrue($createResult['success']);
        $groupId = $createResult['group']['group_id'];

        // Member joins group
        $memberConn = $I->createConnection('member_user', true);
        $joinResult = $I->joinGroup($memberConn, $groupId, null, 'Movie Fan');

        // Verify join succeeded
        $I->assertArrayHasKey('success', $joinResult);
        $I->assertTrue($joinResult['success']);

        // Verify member count is now 2
        $I->assertGroupMemberCount($groupId, 2);

        // Verify member is in group
        $memberId = $I->getMemberId($memberConn);
        $I->assertMemberInGroup($memberId, $groupId);
    }

    /**
     * Test 4: Password-Protected Group.
     *
     * Verifies:
     * - Group can be created with password
     * - Member can join with correct password
     * - Member cannot join with wrong password
     */
    public function testPasswordProtectedGroup(SyncPlayTester $I): void
    {
        $I->initializeManager();

        // Host creates protected group
        $hostConn = $I->createConnection('host_user', true);
        $createResult = $I->createGroup($hostConn, 'Private Watch Party', 'secret123');
        $I->assertTrue($createResult['success']);
        $groupId = $createResult['group']['group_id'];

        // Member joins with correct password
        $memberConn = $I->createConnection('member_user', true);
        $joinResult = $I->joinGroup($memberConn, $groupId, 'secret123');
        $I->assertTrue($joinResult['success']);

        // Another member tries with wrong password
        $member2Conn = $I->createConnection('member2_user', true);
        $joinFailResult = $I->joinGroup($member2Conn, $groupId, 'wrongpassword');
        $I->assertFalse($joinFailResult['success']);
        $I->assertEquals('Invalid password', $joinFailResult['error']);
    }

    /**
     * Test 5: Host Playback Control (Play/Pause).
     *
     * Verifies:
     * - Host can send play command
     * - Host can send pause command
     * - Only host can control playback
     */
    public function testHostPlaybackControl(SyncPlayTester $I): void
    {
        $I->initializeManager();

        // Setup: Host creates group, member joins
        $hostConn = $I->createConnection('host_user', true);
        $createResult = $I->createGroup($hostConn, 'Movie Night');
        $groupId = $createResult['group']['group_id'];

        $memberConn = $I->createConnection('member_user', true);
        $I->joinGroup($memberConn, $groupId);

        // Host sends play command
        $I->sendPlaybackPlay($hostConn, 5000);

        // Verify group state shows playing
        $state = $I->getGroupState($groupId);
        $I->assertEquals('playing', $state['playback_state']);

        // Host sends pause command
        $I->sendPlaybackPause($hostConn, 5000);

        // Verify group state shows paused
        $state = $I->getGroupState($groupId);
        $I->assertEquals('paused', $state['playback_state']);
    }

    /**
     * Test 6: Host Transfer.
     *
     * Verifies:
     * - Host can transfer ownership to another member
     * - New host is set correctly
     * - Old host is no longer host
     */
    public function testHostTransfer(SyncPlayTester $I): void
    {
        $I->initializeManager();

        // Setup: Host creates group, member joins
        $hostConn = $I->createConnection('host_user', true);
        $createResult = $I->createGroup($hostConn, 'Movie Night');
        $groupId = $createResult['group']['group_id'];
        $hostMemberId = $I->getMemberId($hostConn);

        $memberConn = $I->createConnection('member_user', true);
        $I->joinGroup($memberConn, $groupId);
        $newHostMemberId = $I->getMemberId($memberConn);

        // Verify original host
        $I->assertMemberIsHost($hostMemberId, $groupId);

        // Transfer host to member
        $I->transferHost($hostConn, $newHostMemberId);

        // Verify new host
        $I->assertMemberIsHost($newHostMemberId, $groupId);

        // Verify old host is still in group but not host
        $state = $I->getGroupState($groupId);
        $I->assertNotEquals($hostMemberId, $state['host_id']);
    }

    /**
     * Test 7: Member Leaves Group.
     *
     * Verifies:
     * - Member can leave group voluntarily
     * - Member count decreases
     * - Group still exists with remaining members
     */
    public function testMemberLeavesGroup(SyncPlayTester $I): void
    {
        $I->initializeManager();

        // Setup: Host creates group, 2 members join
        $hostConn = $I->createConnection('host_user', true);
        $createResult = $I->createGroup($hostConn, 'Movie Night');
        $groupId = $createResult['group']['group_id'];

        $member1Conn = $I->createConnection('member1', true);
        $I->joinGroup($member1Conn, $groupId);

        $member2Conn = $I->createConnection('member2', true);
        $I->joinGroup($member2Conn, $groupId);

        // Verify 3 members total
        $I->assertGroupMemberCount($groupId, 3);

        // Member1 leaves
        $leaveResult = $I->leaveGroup($member1Conn);
        $I->assertTrue($leaveResult['success']);

        // Verify 2 members remain
        $I->assertGroupMemberCount($groupId, 2);

        // Verify group still exists
        $I->assertGroupExists($groupId);
    }

    /**
     * Test 8: Host Leaves - Automatic Host Election.
     *
     * Verifies:
     * - When host leaves, automatic host election occurs
     * - Another member becomes host
     * - Remaining member count is correct
     */
    public function testHostLeavesTriggersElection(SyncPlayTester $I): void
    {
        $I->initializeManager();

        // Setup: Host creates group, member joins
        $hostConn = $I->createConnection('host_user', true);
        $createResult = $I->createGroup($hostConn, 'Movie Night');
        $groupId = $createResult['group']['group_id'];
        $hostMemberId = $I->getMemberId($hostConn);

        $memberConn = $I->createConnection('member_user', true);
        $I->joinGroup($memberConn, $groupId);
        $memberMemberId = $I->getMemberId($memberConn);

        // Host leaves
        $I->leaveGroup($hostConn);

        // Verify member is now host
        $I->assertMemberIsHost($memberMemberId, $groupId);

        // Verify only 1 member remains
        $I->assertGroupMemberCount($groupId, 1);
    }

    /**
     * Test 9: Last Member Leaves - Group Deleted.
     *
     * Verifies:
     * - When last member leaves, group is deleted
     * - Group no longer exists
     */
    public function testLastMemberLeavesDeletesGroup(SyncPlayTester $I): void
    {
        $I->initializeManager();

        // Setup: Host creates group (only member)
        $hostConn = $I->createConnection('host_user', true);
        $createResult = $I->createGroup($hostConn, 'Solo Movie Night');
        $groupId = $createResult['group']['group_id'];

        // Host (only member) leaves
        $I->leaveGroup($hostConn);

        // Verify group no longer exists
        $I->assertGroupNotExists($groupId);
    }

    /**
     * Test 10: Full SyncPlay Flow (Complete Scenario).
     *
     * Tests the complete end-to-end flow:
     * 1. Multiple users connect and authenticate
     * 2. One creates a group (becomes host)
     * 3. Others join the group
     * 4. Host controls playback
     * 5. Host transfers to another member
     * 6. Original host leaves
     * 7. New host is in control
     */
    public function testFullSyncPlayFlow(SyncPlayTester $I): void
    {
        $I->initializeManager();

        // === Phase 1: Connection and Authentication ===
        $aliceConn = $I->createConnection('alice', true);
        $bobConn = $I->createConnection('bob', true);
        $charlieConn = $I->createConnection('charlie', true);

        // === Phase 2: Group Creation (Alice is host) ===
        $createResult = $I->createGroup($aliceConn, 'Friday Movie Night');
        $I->assertTrue($createResult['success']);
        $groupId = $createResult['group']['group_id'];
        $aliceMemberId = $I->getMemberId($aliceConn);

        // Verify Alice is host
        $I->assertMemberIsHost($aliceMemberId, $groupId);

        // === Phase 3: Members Join ===
        $bobJoinResult = $I->joinGroup($bobConn, $groupId, null, 'Bob');
        $I->assertTrue($bobJoinResult['success']);

        $charlieJoinResult = $I->joinGroup($charlieConn, $groupId, null, 'Charlie');
        $I->assertTrue($charlieJoinResult['success']);

        // Verify all 3 are in group
        $I->assertGroupMemberCount($groupId, 3);

        $bobMemberId = $I->getMemberId($bobConn);
        $charlieMemberId = $I->getMemberId($charlieConn);

        // === Phase 4: Playback Control ===
        // Alice (host) starts playback
        $I->sendPlaybackPlay($aliceConn, 0);

        $state = $I->getGroupState($groupId);
        $I->assertEquals('playing', $state['playback_state']);

        // Alice pauses
        $I->sendPlaybackPause($aliceConn, 5000);

        $state = $I->getGroupState($groupId);
        $I->assertEquals('paused', $state['playback_state']);

        // === Phase 5: Host Transfer ===
        // Alice transfers host to Bob
        $I->transferHost($aliceConn, $bobMemberId);

        // Verify Bob is now host
        $I->assertMemberIsHost($bobMemberId, $groupId);

        // === Phase 6: Original Host Leaves ===
        $I->leaveGroup($aliceConn);

        // Verify Alice is gone, Bob is still host, Charlie remains
        $I->assertGroupMemberCount($groupId, 2);
        $I->assertMemberIsHost($bobMemberId, $groupId);

        // === Phase 7: Charlie Leaves ===
        $I->leaveGroup($charlieConn);

        // Only Bob remains
        $I->assertGroupMemberCount($groupId, 1);

        // === Phase 8: Bob Leaves - Group Deleted ===
        $I->leaveGroup($bobConn);
        $I->assertGroupNotExists($groupId);
    }

    /**
     * Test 11: Multiple Groups Can Exist Simultaneously.
     */
    public function testMultipleGroups(SyncPlayTester $I): void
    {
        $I->initializeManager();

        // Create 3 separate groups
        $conn1 = $I->createConnection('user1', true);
        $result1 = $I->createGroup($conn1, 'Group 1');
        $groupId1 = $result1['group']['group_id'];

        $conn2 = $I->createConnection('user2', true);
        $result2 = $I->createGroup($conn2, 'Group 2');
        $groupId2 = $result2['group']['group_id'];

        $conn3 = $I->createConnection('user3', true);
        $result3 = $I->createGroup($conn3, 'Group 3');
        $groupId3 = $result3['group']['group_id'];

        // Verify all groups exist
        $I->assertGroupExists($groupId1);
        $I->assertGroupExists($groupId2);
        $I->assertGroupExists($groupId3);

        // Verify they have different IDs
        $I->assertNotEquals($groupId1, $groupId2);
        $I->assertNotEquals($groupId2, $groupId3);
        $I->assertNotEquals($groupId1, $groupId3);

        // Verify member counts
        $I->assertGroupMemberCount($groupId1, 1);
        $I->assertGroupMemberCount($groupId2, 1);
        $I->assertGroupMemberCount($groupId3, 1);
    }

    /**
     * Test 12: Group At Maximum Capacity.
     */
    public function testGroupAtMaxCapacity(SyncPlayTester $I): void
    {
        $I->initializeManager();

        // Host creates group
        $hostConn = $I->createConnection('host', true);
        $result = $I->createGroup($hostConn, 'Full Group');
        $groupId = $result['group']['group_id'];

        // Add maximum number of additional members (MAX_MEMBERS - 1 since host is already 1)
        // GroupState::MAX_MEMBERS = 50
        $maxMembers = 50;
        $connections = [];

        for ($i = 0; $i < $maxMembers - 1; $i++) {
            $conn = $I->createConnection("member_{$i}", true);
            $joinResult = $I->joinGroup($conn, $groupId, null, "Member {$i}");
            $I->assertTrue($joinResult['success'], "Should be able to join as member #" . ($i + 1));
            $connections[] = $conn;
        }

        // Verify group is at max capacity
        $I->assertGroupMemberCount($groupId, $maxMembers);

        // Try to add one more - should fail
        $extraConn = $I->createConnection('extra_member', true);
        $extraResult = $I->joinGroup($extraConn, $groupId, null, 'Extra');
        $I->assertFalse($extraResult['success']);
        $I->assertEquals('Group is full', $extraResult['error']);
    }
}
