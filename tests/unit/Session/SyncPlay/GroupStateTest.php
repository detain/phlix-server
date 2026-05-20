<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Session\SyncPlay\GroupState;

class GroupStateTest extends TestCase
{
    public function testCanCreateGroupState(): void
    {
        $group = new GroupState('group_123', 'Test Group');

        $this->assertInstanceOf(GroupState::class, $group);
        $this->assertEquals('group_123', $group->getId());
        $this->assertEquals('Test Group', $group->getName());
    }

    public function testGroupHasNoPasswordByDefault(): void
    {
        $group = new GroupState('group_123', 'Test Group');

        $this->assertFalse($group->hasPassword());
    }

    public function testGroupWithPasswordHasPassword(): void
    {
        $group = new GroupState('group_123', 'Test Group', GroupState::hashPassword('secret'));

        $this->assertTrue($group->hasPassword());
    }

    public function testVerifyPasswordReturnsTrueForCorrectPassword(): void
    {
        $group = new GroupState('group_123', 'Test Group', GroupState::hashPassword('secret'));

        $this->assertTrue($group->verifyPassword('secret'));
    }

    public function testVerifyPasswordReturnsFalseForIncorrectPassword(): void
    {
        $group = new GroupState('group_123', 'Test Group', GroupState::hashPassword('secret'));

        $this->assertFalse($group->verifyPassword('wrong'));
    }

    public function testVerifyPasswordReturnsTrueWhenNoPasswordSet(): void
    {
        $group = new GroupState('group_123', 'Test Group');

        $this->assertTrue($group->verifyPassword('any'));
        $this->assertTrue($group->verifyPassword(''));
    }

    public function testAddMemberIncreasesMemberCount(): void
    {
        $group = new GroupState('group_123', 'Test Group');

        $this->assertEquals(0, $group->getMemberCount());

        $group->addMember('member_1', ['name' => 'User 1']);

        $this->assertEquals(1, $group->getMemberCount());
    }

    public function testAddMemberReturnsFalseWhenGroupIsFull(): void
    {
        $group = new GroupState('group_123', 'Test Group');

        // Add max members
        for ($i = 0; $i < GroupState::MAX_MEMBERS; $i++) {
            $group->addMember("member_{$i}", ['name' => "User {$i}"]);
        }

        $result = $group->addMember('extra_member', ['name' => 'Extra']);

        $this->assertFalse($result);
    }

    public function testAddMemberReturnsFalseForDuplicateMember(): void
    {
        $group = new GroupState('group_123', 'Test Group');

        $group->addMember('member_1', ['name' => 'User 1']);
        $result = $group->addMember('member_1', ['name' => 'User 1']);

        $this->assertFalse($result);
    }

    public function testHasMemberReturnsTrueForExistingMember(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->addMember('member_1', ['name' => 'User 1']);

        $this->assertTrue($group->hasMember('member_1'));
        $this->assertFalse($group->hasMember('member_2'));
    }

    public function testGetMemberReturnsMemberData(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->addMember('member_1', ['name' => 'User 1']);

        $member = $group->getMember('member_1');

        $this->assertNotNull($member);
        $this->assertEquals('User 1', $member['name']);
        $this->assertArrayHasKey('joined_at', $member);
    }

    public function testRemoveMemberDecreasesMemberCount(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->addMember('member_1', ['name' => 'User 1']);

        $this->assertEquals(1, $group->getMemberCount());

        $group->removeMember('member_1');

        $this->assertEquals(0, $group->getMemberCount());
    }

    public function testRemoveNonExistentMemberReturnsFalse(): void
    {
        $group = new GroupState('group_123', 'Test Group');

        $result = $group->removeMember('nonexistent');

        $this->assertFalse($result);
    }

    public function testRemoveHostElectsNewHost(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->addMember('host_1', ['name' => 'Host 1']);
        $group->addMember('member_2', ['name' => 'Member 2']);
        $group->setHost('host_1');

        $this->assertTrue($group->isHost('host_1'));

        $group->removeMember('host_1');

        // New host should be elected
        $newHost = $group->getHostId();
        $this->assertNotNull($newHost);
        $this->assertTrue($group->isHost($newHost));
    }

    public function testSetHostReturnsFalseForNonMember(): void
    {
        $group = new GroupState('group_123', 'Test Group');

        $result = $group->setHost('nonexistent');

        $this->assertFalse($result);
    }

    public function testSetHostUpdatesHostStatus(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->addMember('member_1', ['name' => 'User 1']);

        $group->setHost('member_1');

        $this->assertTrue($group->isHost('member_1'));
        $this->assertEquals('member_1', $group->getHostId());
    }

    public function testElectNewHostReturnsOldestMember(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->addMember('member_1', ['name' => 'User 1']);
        $group->addMember('member_2', ['name' => 'User 2']);

        // member_1 was added first (older)
        $newHost = $group->electNewHost();

        $this->assertEquals('member_1', $newHost);
    }

    public function testElectNewHostReturnsNullForEmptyGroup(): void
    {
        $group = new GroupState('group_123', 'Test Group');

        $newHost = $group->electNewHost();

        $this->assertNull($newHost);
    }

    public function testUpdateMemberUpdatesMemberData(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->addMember('member_1', ['name' => 'User 1']);

        $group->updateMember('member_1', ['is_active' => false]);

        $member = $group->getMember('member_1');
        $this->assertFalse($member['is_active']);
    }

    public function testSetCurrentMediaUpdatesMediaInfo(): void
    {
        $group = new GroupState('group_123', 'Test Group');

        $group->setCurrentMedia('media_123', 60000);

        $this->assertEquals('media_123', $group->getCurrentMediaId());
        $this->assertEquals(60000, $group->getCurrentMediaDuration());
        $this->assertEquals(0, $group->getPlaybackPosition());
        $this->assertEquals(GroupState::STATE_STOPPED, $group->getPlaybackState());
    }

    public function testUpdatePlaybackUpdatesState(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->setCurrentMedia('media_123', 60000);

        $group->updatePlayback(GroupState::STATE_PLAYING, 5000);

        $this->assertEquals(GroupState::STATE_PLAYING, $group->getPlaybackState());
        $this->assertEquals(5000, $group->getPlaybackPosition());
        $this->assertTrue($group->isPlaying());
    }

    public function testAddToQueueAddsItem(): void
    {
        $group = new GroupState('group_123', 'Test Group');

        $group->addToQueue('media_1', ['name' => 'Video 1']);

        $queue = $group->getPlaybackQueue();
        $this->assertCount(1, $queue);
        $this->assertEquals('media_1', $queue[0]['media_id']);
    }

    public function testRemoveFromQueueRemovesItem(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->addToQueue('media_1', ['name' => 'Video 1']);
        $group->addToQueue('media_2', ['name' => 'Video 2']);

        $result = $group->removeFromQueue('media_1');

        $this->assertTrue($result);
        $queue = $group->getPlaybackQueue();
        $this->assertCount(1, $queue);
        $this->assertEquals('media_2', $queue[0]['media_id']);
    }

    public function testGetNextInQueueReturnsFirstItem(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->addToQueue('media_1', ['name' => 'Video 1']);
        $group->addToQueue('media_2', ['name' => 'Video 2']);

        $next = $group->getNextInQueue();

        $this->assertEquals('media_1', $next['media_id']);
    }

    public function testClearQueueRemovesAllItems(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->addToQueue('media_1', ['name' => 'Video 1']);
        $group->addToQueue('media_2', ['name' => 'Video 2']);

        $group->clearQueue();

        $this->assertEmpty($group->getPlaybackQueue());
    }

    public function testAddChatMessageAddsMessage(): void
    {
        $group = new GroupState('group_123', 'Test Group');

        $group->addChatMessage('member_1', 'Hello');

        $messages = $group->getChatMessages();
        $this->assertCount(1, $messages);
        $this->assertEquals('Hello', $messages[0]['message']);
    }

    public function testChatMessageLimitIsEnforced(): void
    {
        $group = new GroupState('group_123', 'Test Group');

        // Add 100 messages (over the limit)
        for ($i = 0; $i < 100; $i++) {
            $group->addChatMessage('member_1', "Message {$i}");
        }

        $messages = $group->getChatMessages(100);
        $this->assertCount(100, $messages);

        // Add one more - should not exceed 100
        $group->addChatMessage('member_1', 'Extra message');
        $messages = $group->getChatMessages(100);
        $this->assertCount(100, $messages);
    }

    public function testIsInSyncReturnsTrueWhenNotPlaying(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->setCurrentMedia('media_1', 60000);

        // Not playing state
        $group->updatePlayback(GroupState::STATE_PAUSED, 5000);

        $this->assertTrue($group->isInSync(5000));
        $this->assertTrue($group->isInSync(10000)); // Different position should still be in sync when paused
    }

    public function testIsInSyncChecksToleranceWhenPlaying(): void
    {
        $group = new GroupState('group_123', 'Test Group', null, 2000); // 2000ms tolerance
        $group->setCurrentMedia('media_1', 60000);
        $group->updatePlayback(GroupState::STATE_PLAYING, 5000);

        // Exact position
        $this->assertTrue($group->isInSync(5000));

        // Within tolerance
        $this->assertTrue($group->isInSync(6000));
        $this->assertTrue($group->isInSync(4000));

        // Outside tolerance
        $this->assertFalse($group->isInSync(8000));
        $this->assertFalse($group->isInSync(2000));
    }

    public function testGetStateReturnsCompleteState(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->addMember('member_1', ['name' => 'User 1']);
        $group->setHost('member_1');
        $group->setCurrentMedia('media_1', 60000);
        $group->updatePlayback(GroupState::STATE_PLAYING, 5000);

        $state = $group->getState();

        $this->assertEquals('group_123', $state['group_id']);
        $this->assertEquals('Test Group', $state['group_name']);
        $this->assertEquals(1, $state['member_count']);
        $this->assertEquals('member_1', $state['host_id']);
        $this->assertEquals('media_1', $state['current_media_id']);
        $this->assertEquals(60000, $state['current_media_duration']);
        $this->assertEquals(5000, $state['playback_position']);
        $this->assertEquals('playing', $state['playback_state']);
    }

    public function testSerializeAndDeserializeRestoresState(): void
    {
        $group = new GroupState('group_123', 'Test Group', GroupState::hashPassword('secret'));
        $group->addMember('member_1', ['name' => 'User 1']);
        $group->setHost('member_1');
        $group->setCurrentMedia('media_1', 60000);
        $group->updatePlayback(GroupState::STATE_PLAYING, 5000);
        $group->addToQueue('media_2', ['name' => 'Video 2']);
        $group->addChatMessage('member_1', 'Hello');

        $serialized = $group->serialize();
        $restored = GroupState::deserialize($serialized);

        $this->assertEquals($group->getId(), $restored->getId());
        $this->assertEquals($group->getName(), $restored->getName());
        $this->assertTrue($restored->hasPassword());
        $this->assertEquals($group->getMemberCount(), $restored->getMemberCount());
        $this->assertEquals($group->getHostId(), $restored->getHostId());
        $this->assertEquals($group->getCurrentMediaId(), $restored->getCurrentMediaId());
        $this->assertEquals($group->getPlaybackState(), $restored->getPlaybackState());
        $this->assertEquals(count($group->getPlaybackQueue()), count($restored->getPlaybackQueue()));
    }

    public function testHashPasswordProducesConsistentHash(): void
    {
        $hash1 = GroupState::hashPassword('password');
        $hash2 = GroupState::hashPassword('password');

        $this->assertEquals($hash1, $hash2);
        $this->assertEquals(64, strlen($hash1)); // SHA256
    }

    public function testGetCreatedAtReturnsTimestamp(): void
    {
        $group = new GroupState('group_123', 'Test Group');

        $this->assertIsInt($group->getCreatedAt());
        $this->assertGreaterThan(0, $group->getCreatedAt());
    }

    public function testGetLastActivityAtUpdatesOnActions(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $initialActivity = $group->getLastActivityAt();

        // Wait a tiny bit and perform an action
        usleep(1000);
        $group->addMember('member_1', ['name' => 'User 1']);

        $this->assertGreaterThanOrEqual($initialActivity, $group->getLastActivityAt());
    }

    public function testGetPositionTolerance(): void
    {
        $group1 = new GroupState('group_123', 'Test Group');
        $group2 = new GroupState('group_123', 'Test Group', null, 5000);

        $this->assertEquals(GroupState::POSITION_TOLERANCE, $group1->getPositionTolerance());
        $this->assertEquals(5000, $group2->getPositionTolerance());
    }
}
