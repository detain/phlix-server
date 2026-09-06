<?php

declare(strict_types=1);

namespace Phlix\Tests\E2E\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Session\SyncPlay\GroupState;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\ConnectionInterface;
use ReflectionMethod;

/**
 * E2E tests for SyncPlay functionality.
 *
 * Tests the full SyncPlay protocol flow:
 * - WS connection → JWT auth
 * - Member joins group
 * - Playback sync (play/pause)
 * - Host transfer
 * - Member leaves
 *
 * These tests use mock connections to simulate multiple WebSocket clients
 * and verify the complete end-to-end behavior of the SyncPlay feature.
 *
 * @group syncplay
 * @group e2e
 */
class SyncPlayE2ETest extends TestCase
{
    private SyncPlayManager $manager;
    private ConnectionPool $pool;

    /**
     * Sent frames per connection id, captured by createMockConnection for assertions.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $sentMessagesByConnectionId = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new SyncPlayManager();
        $this->pool = ConnectionPool::getInstance();
        $this->pool->clear();
        $this->sentMessagesByConnectionId = [];
    }

    protected function tearDown(): void
    {
        $this->pool->clear();
        parent::tearDown();
    }

    /**
     * Creates a mock connection and adds it to the connection pool.
     */
    private function createMockConnection(string $id, ?string $userId = null, bool $authenticated = false): ConnectionInterface
    {
        $mock = $this->createMock(ConnectionInterface::class);
        $mock->method('getId')->willReturn($id);
        $mock->method('getUserId')->willReturn($userId);
        $mock->method('isAuthenticated')->willReturn($authenticated);
        $mock->method('getSessionId')->willReturn(null);

        // Track sent messages per connection so tests can assert broadcasts.
        $mock->method('send')->willReturnCallback(function ($data) use ($id): bool {
            if (is_string($data)) {
                $data = json_decode($data, true);
            }
            $this->sentMessagesByConnectionId[$id][] = $data;
            return true;
        });
        $mock->method('sendFlat')->willReturnCallback(function ($type, $payload) use ($id) {
            $frame = array_merge(['type' => $type], $payload, ['timestamp' => time()]);
            $this->sentMessagesByConnectionId[$id][] = $frame;
        });
        $mock->method('sendMessage')->willReturnCallback(function ($type, $data) use ($id) {
            $this->sentMessagesByConnectionId[$id][] = ['type' => $type, 'data' => $data, 'timestamp' => time()];
        });

        $this->pool->add($mock);
        return $mock;
    }

    /**
     * All frames a mock connection "sent" (broadcasts, echoes, errors), in order.
     *
     * @return list<array<string, mixed>>
     */
    private function getSentMessages(string $connectionId): array
    {
        return $this->sentMessagesByConnectionId[$connectionId] ?? [];
    }

    /**
     * Test 1: Full group creation and member join flow.
     */
    public function testGroupCreationAndMemberJoin(): void
    {
        // Host creates a group
        $hostConn = $this->createMockConnection('conn-host', 'host_user', true);
        $result = $this->manager->createGroup(
            'Movie Night',
            null,
            'host_user',
            'Host User',
            'conn-host'
        );

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('group', $result);
        /** @var array{group: array{group_id: string, host_id: string, member_count: int}} $result */
        $groupId = $result['group']['group_id'];
        $this->assertStringStartsWith('sp_', $groupId);

        // Verify host is set correctly
        $this->assertEquals('host_user', $result['group']['host_id']);
        $this->assertEquals(1, $result['group']['member_count']);

        // Member joins the group
        $memberConn = $this->createMockConnection('conn-member', 'member_user', true);
        $joinResult = $this->manager->joinGroup(
            $groupId,
            'member_user',
            'Guest User',
            null,
            'conn-member'
        );

        $this->assertTrue($joinResult['success']);
        $this->assertEquals(2, $joinResult['group']['member_count']);

        // Verify member is in the group
        $memberGroup = $this->manager->getMemberGroup('member_user');
        $this->assertEquals($groupId, $memberGroup);
    }

    /**
     * Test 2: Password-protected group.
     */
    public function testPasswordProtectedGroup(): void
    {
        // Host creates protected group
        $hostConn = $this->createMockConnection('conn-host', 'host_user', true);
        $result = $this->manager->createGroup(
            'Private Watch Party',
            'secret123',
            'host_user',
            'Host',
            'conn-host'
        );
        $this->assertTrue($result['success']);
        /** @var array{group: array{group_id: string}} $result */
        $groupId = $result['group']['group_id'];

        // Member joins with correct password
        $memberConn = $this->createMockConnection('conn-member', 'member_user', true);
        $joinResult = $this->manager->joinGroup(
            $groupId,
            'member_user',
            'Guest',
            'secret123',
            'conn-member'
        );
        $this->assertTrue($joinResult['success']);

        // Another member tries with wrong password
        $member2Conn = $this->createMockConnection('conn-member2', 'member2_user', true);
        $failResult = $this->manager->joinGroup(
            $groupId,
            'member2_user',
            'Hacker',
            'wrongpassword',
            'conn-member2'
        );
        $this->assertFalse($failResult['success']);
        $this->assertEquals('Invalid password', $failResult['error']);
    }

    /**
     * Test 3: Host sends playback control commands.
     */
    public function testHostPlaybackControl(): void
    {
        // Setup: Host creates group, member joins
        $hostConn = $this->createMockConnection('conn-host', 'host_user', true);
        $createResult = $this->manager->createGroup('Movie Night', null, 'host_user', 'Host', 'conn-host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $memberConn = $this->createMockConnection('conn-member', 'member_user', true);
        $this->manager->joinGroup($groupId, 'member_user', 'Guest', null, 'conn-member');

        // Use reflection to call private handlePlaybackPlay
        $method = new ReflectionMethod($this->manager, 'handlePlaybackPlay');
        $method->setAccessible(true);

        // Host sends play command
        $method->invoke($this->manager, $hostConn, [
            'position' => 5000,
            'server_time' => time(),
        ]);

        // Verify playback state
        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertEquals('playing', $state['playback_state']);

        // Host sends pause command
        $pauseMethod = new ReflectionMethod($this->manager, 'handlePlaybackPause');
        $pauseMethod->setAccessible(true);
        $pauseMethod->invoke($this->manager, $hostConn, [
            'position' => 5500,
            'server_time' => time(),
        ]);

        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertEquals('paused', $state['playback_state']);
    }

    /**
     * Test 4: Host transfer workflow.
     */
    public function testHostTransfer(): void
    {
        // Setup: Host creates group, member joins
        $hostConn = $this->createMockConnection('conn-host', 'host_user', true);
        $createResult = $this->manager->createGroup('Movie Night', null, 'host_user', 'Host', 'conn-host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];
        $hostMemberId = 'host_user';

        $memberConn = $this->createMockConnection('conn-member', 'member_user', true);
        $this->manager->joinGroup($groupId, 'member_user', 'Guest', null, 'conn-member');
        $newHostMemberId = 'member_user';

        // Verify original host
        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertEquals($hostMemberId, $state['host_id']);

        // Transfer host to member
        $transferMethod = new ReflectionMethod($this->manager, 'handleHostTransfer');
        $transferMethod->setAccessible(true);
        $transferMethod->invoke($this->manager, $hostConn, [
            'new_host_id' => $newHostMemberId,
        ]);

        // Verify new host
        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertEquals($newHostMemberId, $state['host_id']);
        $this->assertNotEquals($hostMemberId, $state['host_id']);
    }

    /**
     * Test 5: Member leaves group.
     */
    public function testMemberLeavesGroup(): void
    {
        // Setup: Host creates group, 2 members join
        $hostConn = $this->createMockConnection('conn-host', 'host_user', true);
        $createResult = $this->manager->createGroup('Movie Night', null, 'host_user', 'Host', 'conn-host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $member1Conn = $this->createMockConnection('conn-member1', 'member1_user', true);
        $this->manager->joinGroup($groupId, 'member1_user', 'Member 1', null, 'conn-member1');

        $member2Conn = $this->createMockConnection('conn-member2', 'member2_user', true);
        $this->manager->joinGroup($groupId, 'member2_user', 'Member 2', null, 'conn-member2');

        // Verify 3 members
        $this->assertGroupMemberCount($groupId, 3);

        // Member1 leaves
        $leaveResult = $this->manager->leaveGroup('member1_user');
        $this->assertTrue($leaveResult['success']);

        // Verify 2 members remain
        $this->assertGroupMemberCount($groupId, 2);

        // Verify group still exists
        $state = $this->manager->getGroupState($groupId);
        $this->assertNotNull($state);
    }

    /**
     * Test 6: Host leaves - automatic election.
     */
    public function testHostLeavesTriggersElection(): void
    {
        // Setup: Host creates group, member joins
        $hostConn = $this->createMockConnection('conn-host', 'host_user', true);
        $createResult = $this->manager->createGroup('Movie Night', null, 'host_user', 'Host', 'conn-host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];
        $hostMemberId = 'host_user';

        $memberConn = $this->createMockConnection('conn-member', 'member_user', true);
        $this->manager->joinGroup($groupId, 'member_user', 'Guest', null, 'conn-member');
        $memberMemberId = 'member_user';

        // Host leaves
        $this->manager->leaveGroup($hostMemberId);

        // Verify member is now host
        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertEquals($memberMemberId, $state['host_id']);

        // Verify only 1 member remains
        $this->assertGroupMemberCount($groupId, 1);
    }

    /**
     * Test 7: Last member leaves - group deleted.
     */
    public function testLastMemberLeavesDeletesGroup(): void
    {
        // Host creates group (only member)
        $hostConn = $this->createMockConnection('conn-host', 'host_user', true);
        $createResult = $this->manager->createGroup('Solo Movie Night', null, 'host_user', 'Host', 'conn-host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        // Host (only member) leaves
        $this->manager->leaveGroup('host_user');

        // Verify group no longer exists
        $state = $this->manager->getGroupState($groupId);
        $this->assertNull($state);
    }

    /**
     * Test 8: Complete SyncPlay workflow.
     */
    public function testCompleteSyncPlayWorkflow(): void
    {
        // === Phase 1: Connection and Authentication ===
        $aliceConn = $this->createMockConnection('conn-alice', 'alice', true);
        $bobConn = $this->createMockConnection('conn-bob', 'bob', true);
        $charlieConn = $this->createMockConnection('conn-charlie', 'charlie', true);

        // === Phase 2: Group Creation (Alice is host) ===
        $createResult = $this->manager->createGroup(
            'Friday Movie Night',
            null,
            'alice',
            'Alice',
            'conn-alice'
        );
        $this->assertTrue($createResult['success']);
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        // Verify Alice is host
        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertEquals('alice', $state['host_id']);

        // === Phase 3: Members Join ===
        $this->manager->joinGroup($groupId, 'bob', 'Bob', null, 'conn-bob');
        $this->manager->joinGroup($groupId, 'charlie', 'Charlie', null, 'conn-charlie');

        // Verify all 3 are in group
        $this->assertGroupMemberCount($groupId, 3);

        // === Phase 4: Playback Control ===
        $playMethod = new ReflectionMethod($this->manager, 'handlePlaybackPlay');
        $playMethod->setAccessible(true);
        $playMethod->invoke($this->manager, $aliceConn, [
            'position' => 0,
            'server_time' => time(),
        ]);

        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertEquals('playing', $state['playback_state']);

        // Alice pauses
        $pauseMethod = new ReflectionMethod($this->manager, 'handlePlaybackPause');
        $pauseMethod->setAccessible(true);
        $pauseMethod->invoke($this->manager, $aliceConn, [
            'position' => 5000,
            'server_time' => time(),
        ]);

        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertEquals('paused', $state['playback_state']);

        // === Phase 5: Host Transfer ===
        $transferMethod = new ReflectionMethod($this->manager, 'handleHostTransfer');
        $transferMethod->setAccessible(true);
        $transferMethod->invoke($this->manager, $aliceConn, [
            'new_host_id' => 'bob',
        ]);

        // Verify Bob is now host
        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertEquals('bob', $state['host_id']);

        // === Phase 6: Original Host Leaves ===
        $this->manager->leaveGroup('alice');
        $this->assertGroupMemberCount($groupId, 2);
        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertEquals('bob', $state['host_id']);

        // === Phase 7: Charlie Leaves ===
        $this->manager->leaveGroup('charlie');
        $this->assertGroupMemberCount($groupId, 1);

        // === Phase 8: Bob Leaves - Group Deleted ===
        $this->manager->leaveGroup('bob');
        $state = $this->manager->getGroupState($groupId);
        $this->assertNull($state);
    }

    /**
     * Test 9: Multiple groups can coexist.
     */
    public function testMultipleGroups(): void
    {
        // Create 3 separate groups
        $conn1 = $this->createMockConnection('conn-1', 'user1', true);
        $result1 = $this->manager->createGroup('Group 1', null, 'user1', 'User 1', 'conn-1');

        $conn2 = $this->createMockConnection('conn-2', 'user2', true);
        $result2 = $this->manager->createGroup('Group 2', null, 'user2', 'User 2', 'conn-2');

        $conn3 = $this->createMockConnection('conn-3', 'user3', true);
        $result3 = $this->manager->createGroup('Group 3', null, 'user3', 'User 3', 'conn-3');

        /** @var array{group: array{group_id: string}} $result1 */
        $groupId1 = $result1['group']['group_id'];
        /** @var array{group: array{group_id: string}} $result2 */
        $groupId2 = $result2['group']['group_id'];
        /** @var array{group: array{group_id: string}} $result3 */
        $groupId3 = $result3['group']['group_id'];

        // Verify all groups exist
        $this->assertNotNull($this->manager->getGroupState($groupId1));
        $this->assertNotNull($this->manager->getGroupState($groupId2));
        $this->assertNotNull($this->manager->getGroupState($groupId3));

        // Verify different IDs
        $this->assertNotEquals($groupId1, $groupId2);
        $this->assertNotEquals($groupId2, $groupId3);
        $this->assertNotEquals($groupId1, $groupId3);

        // Verify member counts
        $this->assertGroupMemberCount($groupId1, 1);
        $this->assertGroupMemberCount($groupId2, 1);
        $this->assertGroupMemberCount($groupId3, 1);
    }

    /**
     * Test 10: Group at maximum capacity.
     */
    public function testGroupAtMaxCapacity(): void
    {
        // Host creates group
        $hostConn = $this->createMockConnection('conn-host', 'host', true);
        $result = $this->manager->createGroup('Full Group', null, 'host', 'Host', 'conn-host');
        /** @var array{group: array{group_id: string}} $result */
        $groupId = $result['group']['group_id'];
        $maxMembers = GroupState::MAX_MEMBERS;

        // Add maximum number of additional members
        for ($i = 0; $i < $maxMembers - 1; $i++) {
            $conn = $this->createMockConnection("conn-{$i}", "member_{$i}", true);
            $joinResult = $this->manager->joinGroup(
                $groupId,
                "member_{$i}",
                "Member {$i}",
                null,
                "conn-{$i}"
            );
            $this->assertTrue($joinResult['success'], "Should be able to join as member #" . ($i + 1));
        }

        // Verify group is at max capacity
        $this->assertGroupMemberCount($groupId, $maxMembers);

        // Try to add one more - should fail
        $extraConn = $this->createMockConnection('conn-extra', 'extra', true);
        $extraResult = $this->manager->joinGroup(
            $groupId,
            'extra',
            'Extra',
            null,
            'conn-extra'
        );
        $this->assertFalse($extraResult['success']);
        $this->assertEquals('Group is full', $extraResult['error']);
    }

    /**
     * Test 11: Connection close removes member from group.
     */
    public function testConnectionCloseRemovesMember(): void
    {
        // Host creates group with connection
        $hostConn = $this->createMockConnection('conn-host', 'host_user', true);
        $createResult = $this->manager->createGroup(
            'Movie Night',
            null,
            'host_user',
            'Host',
            'conn-host'
        );
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        // Member joins
        $memberConn = $this->createMockConnection('conn-member', 'member_user', true);
        $this->manager->joinGroup($groupId, 'member_user', 'Guest', null, 'conn-member');

        // Verify 2 members
        $this->assertGroupMemberCount($groupId, 2);

        // Simulate member connection close
        $this->manager->onConnectionClose('conn-member');

        // Verify member was removed
        $this->assertGroupMemberCount($groupId, 1);

        // Verify host is still there
        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertEquals('host_user', $state['host_id']);
    }

    /**
     * Test 12 (S294): a one-member room's playback_sync request reaches the host
     * back as its own authoritative frame — the server half of the host re-anchor
     * proof. The server stamps `member_id` with the HOST id (not the requester's
     * self-asserted id) and excludes NOBODY from the broadcast, so the solo host
     * receives the frame that @phlix/syncplay's handlePlaybackSync must consume.
     */
    public function testOneMemberRoomPlaybackSyncReachesHost(): void
    {
        $hostConn = $this->createMockConnection('conn-solo-host', 'solo_host_user', true);
        $createResult = $this->manager->createGroup('Solo Room', null, 'solo_host_user', 'Host', 'conn-solo-host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        // Host plays at 5000ms so the sync broadcast carries a real re-anchor value.
        $playMethod = new ReflectionMethod($this->manager, 'handlePlaybackPlay');
        $playMethod->setAccessible(true);
        $playMethod->invoke($this->manager, $hostConn, [
            'position' => 5000,
            'server_time' => time(),
        ]);

        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertEquals(5000, $state['playback_position']);
        $this->assertEquals('playing', $state['playback_state']);

        // The solo host requests a playback sync (requester == host == only member).
        $syncMethod = new ReflectionMethod($this->manager, 'handlePlaybackSync');
        $syncMethod->setAccessible(true);
        $syncMethod->invoke($this->manager, $hostConn, ['member_id' => 'solo_host_user']);

        $syncFrames = array_values(array_filter(
            $this->getSentMessages('conn-solo-host'),
            static fn (array $frame): bool => ($frame['type'] ?? null) === Messages::TYPE_PLAYBACK_SYNC
        ));

        $this->assertCount(
            1,
            $syncFrames,
            'In a one-member room the host must receive exactly one playback_sync broadcast back'
        );
        $this->assertSame(
            'solo_host_user',
            $syncFrames[0]['member_id'] ?? null,
            'member_id must be the host id — in a solo room the host is the only member'
        );
        $this->assertSame(
            5000,
            $syncFrames[0]['position'] ?? null,
            'the broadcast must carry the authoritative group playback position (ms) the host re-anchors to'
        );
        $this->assertTrue($syncFrames[0]['is_playing'] ?? false, 'is_playing must reflect the group playback state');
        $this->assertSame($groupId, $syncFrames[0]['group_id'] ?? null, 'the broadcast must carry the group id');
    }

    /**
     * Test 13 (S294): a NON-host member's playback_sync request is answered with
     * a frame stamped with the HOST id, not the requester's id. This is the
     * property @phlix/syncplay's self-frame consumption relies on: the client
     * only ever sees member_id === own id when it IS the host.
     */
    public function testPlaybackSyncRequestByMemberIsStampedWithHostId(): void
    {
        $hostConn = $this->createMockConnection('conn-host', 'host_user', true);
        $createResult = $this->manager->createGroup('Movie Night', null, 'host_user', 'Host', 'conn-host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        $memberConn = $this->createMockConnection('conn-member', 'member_user', true);
        $this->manager->joinGroup($groupId, 'member_user', 'Guest', null, 'conn-member');

        $playMethod = new ReflectionMethod($this->manager, 'handlePlaybackPlay');
        $playMethod->setAccessible(true);
        $playMethod->invoke($this->manager, $hostConn, [
            'position' => 5000,
            'server_time' => time(),
        ]);

        // The NON-host member requests a playback sync.
        $syncMethod = new ReflectionMethod($this->manager, 'handlePlaybackSync');
        $syncMethod->setAccessible(true);
        $syncMethod->invoke($this->manager, $memberConn, ['member_id' => 'member_user']);

        $memberFrames = array_values(array_filter(
            $this->getSentMessages('conn-member'),
            static fn (array $frame): bool => ($frame['type'] ?? null) === Messages::TYPE_PLAYBACK_SYNC
        ));

        $this->assertCount(1, $memberFrames, 'The requesting member must receive exactly one playback_sync broadcast');
        $this->assertSame(
            'host_user',
            $memberFrames[0]['member_id'] ?? null,
            'member_id must be server-stamped with the HOST id, not the requester-supplied value'
        );
        $this->assertSame(
            5000,
            $memberFrames[0]['position'] ?? null,
            'the broadcast carries the host-authoritative position (ms)'
        );
    }

    /**
     * S289 AC — the SAME human reaching the SAME group over BOTH transports is
     * EXACTLY ONE member. The REST path joins with the authenticated JWT subject
     * (no WS socket → connection_id null); the WebSocket path then joins the same
     * subject and must collapse onto that one member, re-pointing its connection.
     */
    public function testSameHumanOverRestAndWsIsExactlyOneMember(): void
    {
        $hostConn = $this->createMockConnection('conn-host', 'sam', true);
        $createResult = $this->manager->createGroup('Movie Night', null, 'sam', 'Sam', 'conn-host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        // REST join of 'jane' — mirrors SyncPlayController::joinGroup (identity = JWT
        // subject; HTTP carries no WS socket, so connection_id is null).
        $restJoin = $this->manager->joinGroup($groupId, 'jane', 'Jane', null, null);
        $this->assertTrue($restJoin['success']);

        // The SAME human now joins over WebSocket with the identical JWT subject.
        $wsConn = $this->createMockConnection('conn-jane', 'jane', true);
        $this->invokeHandler('handleGroupJoin', $wsConn, [
            'group_id' => $groupId,
            'member_name' => 'Jane',
        ]);

        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertSame(2, $state['member_count'], 'one human over two transports must not add a third member');
        $this->assertSame(
            ['sam', 'jane'],
            array_keys($state['members']),
            'the member list is keyed by identity — jane appears exactly once'
        );

        // The idempotent WS join re-pointed jane to the live socket: a host play now
        // reaches conn-jane (proving the phantom REST-null connection became the WS one).
        $this->invokeHandler('handlePlaybackPlay', $hostConn, ['position' => 4000, 'server_time' => time()]);
        $this->assertTrue(
            $this->connectionReceivedType('conn-jane', Messages::TYPE_PLAYBACK_PLAY),
            'jane must be delivered to via her most-recent (WS) connection'
        );
    }

    /**
     * S289 AC — a reconnect (fresh connection id, same JWT subject) must NOT
     * duplicate the member: the socket-close removes the member, the re-join
     * re-adds exactly one.
     */
    public function testReconnectDoesNotDuplicateMember(): void
    {
        $hostConn = $this->createMockConnection('conn-host', 'sam', true);
        $createResult = $this->manager->createGroup('Movie Night', null, 'sam', 'Sam', 'conn-host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        // First session joins over WS.
        $conn1 = $this->createMockConnection('conn-jane-1', 'jane', true);
        $this->invokeHandler('handleGroupJoin', $conn1, ['group_id' => $groupId, 'member_name' => 'Jane']);
        $this->assertGroupMemberCount($groupId, 2);

        // The socket drops — S283 backoff will reconnect on a NEW connection id.
        $this->manager->onConnectionClose('conn-jane-1');

        // Reconnect on the new socket, SAME JWT subject.
        $conn2 = $this->createMockConnection('conn-jane-2', 'jane', true);
        $this->invokeHandler('handleGroupJoin', $conn2, ['group_id' => $groupId, 'member_name' => 'Jane']);

        $this->assertGroupMemberCount($groupId, 2);
        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertSame(['sam', 'jane'], array_keys($state['members']), 'a reconnect must not fork the identity');
    }

    /**
     * S289 two-tabs model — two OPEN connections of ONE account (same JWT subject)
     * are ONE member; the most-recent connection receives broadcasts and the earlier
     * tab is silently demoted (no error, no second seat). This is the WRITTEN model
     * the AC requires: a seat is an identity, not a browser tab.
     */
    public function testTwoTabsSameAccountAreOneMemberAndMostRecentConnectionWinsDelivery(): void
    {
        $hostConn = $this->createMockConnection('conn-host', 'sam', true);
        $createResult = $this->manager->createGroup('Movie Night', null, 'sam', 'Sam', 'conn-host');
        /** @var array{group: array{group_id: string}} $createResult */
        $groupId = $createResult['group']['group_id'];

        // Tab A opens and joins.
        $tabA = $this->createMockConnection('conn-jane-a', 'jane', true);
        $this->invokeHandler('handleGroupJoin', $tabA, ['group_id' => $groupId, 'member_name' => 'Jane']);

        // Tab B (SAME account, new socket) joins — must stay idempotent (one member).
        $tabB = $this->createMockConnection('conn-jane-b', 'jane', true);
        $this->invokeHandler('handleGroupJoin', $tabB, ['group_id' => $groupId, 'member_name' => 'Jane']);

        $state = $this->manager->getGroupState($groupId);
        /** @var array<string, mixed> $state */
        $this->assertSame(2, $state['member_count'], 'two tabs of one account are ONE member, not two');
        $this->assertSame(['sam', 'jane'], array_keys($state['members']));

        // Delivery follows the most-recent socket: host play reaches tab B, not tab A.
        $this->invokeHandler('handlePlaybackPlay', $hostConn, ['position' => 7000, 'server_time' => time()]);
        $this->assertTrue(
            $this->connectionReceivedType('conn-jane-b', Messages::TYPE_PLAYBACK_PLAY),
            'the most-recent connection must receive the broadcast'
        );
        $this->assertFalse(
            $this->connectionReceivedType('conn-jane-a', Messages::TYPE_PLAYBACK_PLAY),
            'the demoted (earlier) tab must not still be a delivery target'
        );
    }

    /**
     * Invoke a private SyncPlayManager WS handler by name on a mock connection — the
     * E2E venue (a raw SyncPlayManager, so handlers are not exposed publicly).
     *
     * @param array<string, mixed> $payload
     */
    private function invokeHandler(string $handler, ConnectionInterface $connection, array $payload): void
    {
        $method = new ReflectionMethod($this->manager, $handler);
        $method->setAccessible(true);
        $method->invoke($this->manager, $connection, $payload);
    }

    /**
     * Did the given mock connection receive at least one frame of $type?
     */
    private function connectionReceivedType(string $connectionId, string $type): bool
    {
        foreach ($this->getSentMessages($connectionId) as $frame) {
            if (($frame['type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Helper: Assert group member count.
     */
    private function assertGroupMemberCount(string $groupId, int $expected): void
    {
        $state = $this->manager->getGroupState($groupId);
        $this->assertNotNull($state, "Group {$groupId} should exist");
        $actualCount = $state['member_count'] ?? null;
        $this->assertEquals(
            $expected,
            $actualCount,
            "Group {$groupId} should have {$expected} members, has "
                . (is_int($actualCount) ? $actualCount : 0)
        );
    }
}
