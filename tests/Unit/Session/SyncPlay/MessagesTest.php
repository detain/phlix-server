<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Session\SyncPlay\Messages;

class MessagesTest extends TestCase
{
    public function testIsValidTypeReturnsTrueForValidTypes(): void
    {
        $this->assertTrue(Messages::isValidType(Messages::TYPE_GROUP_CREATE));
        $this->assertTrue(Messages::isValidType(Messages::TYPE_GROUP_JOIN));
        $this->assertTrue(Messages::isValidType(Messages::TYPE_PLAYBACK_PLAY));
        $this->assertTrue(Messages::isValidType(Messages::TYPE_PLAYBACK_PAUSE));
        $this->assertTrue(Messages::isValidType(Messages::TYPE_PLAYBACK_SEEK));
        $this->assertTrue(Messages::isValidType(Messages::TYPE_TIME_PING));
        $this->assertTrue(Messages::isValidType(Messages::TYPE_TIME_PONG));
        $this->assertTrue(Messages::isValidType(Messages::TYPE_CHAT_MESSAGE));
    }

    public function testIsValidTypeReturnsFalseForInvalidType(): void
    {
        $this->assertFalse(Messages::isValidType('invalid_type'));
        $this->assertFalse(Messages::isValidType(''));
        $this->assertFalse(Messages::isValidType('TYPE_GROUP_CREATE'));
    }

    public function testGetValidTypesReturnsAllTypes(): void
    {
        $types = Messages::getValidTypes();

        $this->assertContains(Messages::TYPE_GROUP_CREATE, $types);
        $this->assertContains(Messages::TYPE_GROUP_JOIN, $types);
        $this->assertContains(Messages::TYPE_PLAYBACK_PLAY, $types);
        $this->assertContains(Messages::TYPE_PLAYBACK_PAUSE, $types);
        $this->assertContains(Messages::TYPE_PLAYBACK_SEEK, $types);
        $this->assertContains(Messages::TYPE_CHAT_MESSAGE, $types);
        $this->assertContains(Messages::TYPE_TIME_PING, $types);
    }

    public function testGroupCreateCreatesValidMessage(): void
    {
        $msg = Messages::groupCreate('Test Group', 'password123');

        $this->assertEquals(Messages::TYPE_GROUP_CREATE, $msg['type']);
        $this->assertEquals(Messages::PROTOCOL_VERSION, $msg['protocol_version']);
        $this->assertEquals('Test Group', $msg['group_name']);
        $this->assertArrayHasKey('password_hash', $msg);
        $this->assertArrayHasKey('timestamp', $msg);
    }

    public function testGroupCreateWithoutPassword(): void
    {
        $msg = Messages::groupCreate('Test Group');

        $this->assertEquals(Messages::TYPE_GROUP_CREATE, $msg['type']);
        $this->assertEquals('Test Group', $msg['group_name']);
        $this->assertArrayNotHasKey('password_hash', $msg);
    }

    public function testGroupJoinCreatesValidMessage(): void
    {
        $msg = Messages::groupJoin('group_123', 'password123');

        $this->assertEquals(Messages::TYPE_GROUP_JOIN, $msg['type']);
        $this->assertEquals('group_123', $msg['group_id']);
        $this->assertArrayHasKey('password_hash', $msg);
    }

    public function testGroupLeaveCreatesValidMessage(): void
    {
        $msg = Messages::groupLeave('group_123', 'member_456');

        $this->assertEquals(Messages::TYPE_GROUP_LEAVE, $msg['type']);
        $this->assertEquals('group_123', $msg['group_id']);
        $this->assertEquals('member_456', $msg['member_id']);
    }

    public function testGroupStateCreatesValidMessage(): void
    {
        $members = [
            ['id' => 'm1', 'name' => 'User 1'],
            ['id' => 'm2', 'name' => 'User 2'],
        ];

        $msg = Messages::groupState(
            'group_123',
            $members,
            'media_456',
            5000,
            'playing',
            'host_789'
        );

        $this->assertEquals(Messages::TYPE_GROUP_STATE, $msg['type']);
        $this->assertEquals('group_123', $msg['group_id']);
        $this->assertEquals($members, $msg['members']);
        $this->assertEquals('media_456', $msg['current_media_id']);
        $this->assertEquals(5000, $msg['playback_position']);
        $this->assertEquals('playing', $msg['playback_state']);
        $this->assertEquals('host_789', $msg['host_id']);
    }

    public function testPlaybackPlayCreatesValidMessage(): void
    {
        $msg = Messages::playbackPlay('group_123', 'member_456', 5000, time());

        $this->assertEquals(Messages::TYPE_PLAYBACK_PLAY, $msg['type']);
        $this->assertEquals('group_123', $msg['group_id']);
        $this->assertEquals('member_456', $msg['member_id']);
        $this->assertEquals(5000, $msg['position']);
        $this->assertArrayHasKey('server_time', $msg);
    }

    public function testPlaybackPauseCreatesValidMessage(): void
    {
        $msg = Messages::playbackPause('group_123', 'member_456', 5000, time());

        $this->assertEquals(Messages::TYPE_PLAYBACK_PAUSE, $msg['type']);
        $this->assertEquals('group_123', $msg['group_id']);
        $this->assertEquals('member_456', $msg['member_id']);
        $this->assertEquals(5000, $msg['position']);
    }

    public function testPlaybackSeekCreatesValidMessage(): void
    {
        $msg = Messages::playbackSeek('group_123', 'member_456', 5000, 10000, time());

        $this->assertEquals(Messages::TYPE_PLAYBACK_SEEK, $msg['type']);
        $this->assertEquals('group_123', $msg['group_id']);
        $this->assertEquals('member_456', $msg['member_id']);
        $this->assertEquals(5000, $msg['from_position']);
        $this->assertEquals(10000, $msg['to_position']);
    }

    public function testPlaybackQueueCreatesValidMessage(): void
    {
        $queue = [
            ['media_id' => 'm1', 'media_info' => ['name' => 'Video 1']],
            ['media_id' => 'm2', 'media_info' => ['name' => 'Video 2']],
        ];

        $msg = Messages::playbackQueue('group_123', $queue);

        $this->assertEquals(Messages::TYPE_PLAYBACK_QUEUE, $msg['type']);
        $this->assertEquals('group_123', $msg['group_id']);
        $this->assertEquals($queue, $msg['queue']);
    }

    public function testPlaybackSyncCreatesValidMessage(): void
    {
        $msg = Messages::playbackSync('group_123', 'member_456', 5000, true, time());

        $this->assertEquals(Messages::TYPE_PLAYBACK_SYNC, $msg['type']);
        $this->assertEquals('group_123', $msg['group_id']);
        $this->assertEquals('member_456', $msg['member_id']);
        $this->assertEquals(5000, $msg['position']);
        $this->assertTrue($msg['is_playing']);
    }

    public function testChatMessageCreatesValidMessage(): void
    {
        $msg = Messages::chatMessage('group_123', 'member_456', 'Hello world');

        $this->assertEquals(Messages::TYPE_CHAT_MESSAGE, $msg['type']);
        $this->assertEquals('group_123', $msg['group_id']);
        $this->assertEquals('member_456', $msg['member_id']);
        $this->assertEquals('Hello world', $msg['message']);
    }

    public function testChatTypingCreatesValidMessage(): void
    {
        $msg = Messages::chatTyping('group_123', 'member_456', true);

        $this->assertEquals(Messages::TYPE_CHAT_TYPING, $msg['type']);
        $this->assertEquals('group_123', $msg['group_id']);
        $this->assertEquals('member_456', $msg['member_id']);
        $this->assertTrue($msg['is_typing']);
    }

    public function testHostTransferCreatesValidMessage(): void
    {
        $msg = Messages::hostTransfer('group_123', 'old_host', 'new_host');

        $this->assertEquals(Messages::TYPE_HOST_TRANSFER, $msg['type']);
        $this->assertEquals('group_123', $msg['group_id']);
        $this->assertEquals('old_host', $msg['current_host_id']);
        $this->assertEquals('new_host', $msg['new_host_id']);
    }

    public function testHostElectCreatesValidMessage(): void
    {
        $msg = Messages::hostElect('group_123', 'elected_456', 'elector_789');

        $this->assertEquals(Messages::TYPE_HOST_ELECT, $msg['type']);
        $this->assertEquals('group_123', $msg['group_id']);
        $this->assertEquals('elected_456', $msg['elected_id']);
        $this->assertEquals('elector_789', $msg['elected_by']);
    }

    public function testTimePingCreatesValidMessage(): void
    {
        $clientTime = (int)(microtime(true) * 1000);
        $msg = Messages::timePing($clientTime);

        $this->assertEquals(Messages::TYPE_TIME_PING, $msg['type']);
        $this->assertEquals($clientTime, $msg['client_time']);
    }

    public function testTimePongCreatesValidMessage(): void
    {
        $msg = Messages::timePong(1000, 1050);

        $this->assertEquals(Messages::TYPE_TIME_PONG, $msg['type']);
        $this->assertEquals(1000, $msg['client_time']);
        $this->assertEquals(1050, $msg['server_time']);
    }

    public function testErrorCreatesValidMessage(): void
    {
        $msg = Messages::error('ERR_CODE', 'Error message', ['detail' => 'value']);

        $this->assertEquals(Messages::TYPE_ERROR, $msg['type']);
        $this->assertEquals('ERR_CODE', $msg['error_code']);
        $this->assertEquals('Error message', $msg['message']);
        $this->assertEquals(['detail' => 'value'], $msg['details']);
    }

    public function testInfoCreatesValidMessage(): void
    {
        $msg = Messages::info('Info message', ['key' => 'value']);

        $this->assertEquals(Messages::TYPE_INFO, $msg['type']);
        $this->assertEquals('Info message', $msg['message']);
        $this->assertEquals(['key' => 'value'], $msg['data']);
    }

    public function testValidateAcceptsValidMessage(): void
    {
        $message = Messages::groupCreate('Test Group');

        $result = Messages::validate($message);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateRejectsMissingType(): void
    {
        $message = ['data' => 'test'];

        $result = Messages::validate($message);

        $this->assertFalse($result['valid']);
        $this->assertContains('Missing required field: type', $result['errors']);
    }

    public function testValidateRejectsInvalidType(): void
    {
        $message = [
            'type' => 'invalid_type',
            'protocol_version' => Messages::PROTOCOL_VERSION,
        ];

        $result = Messages::validate($message);

        $this->assertFalse($result['valid']);
        $this->assertContains('Invalid message type: invalid_type', $result['errors']);
    }

    public function testValidateRejectsMissingProtocolVersion(): void
    {
        $message = [
            'type' => Messages::TYPE_GROUP_CREATE,
        ];

        $result = Messages::validate($message);

        $this->assertFalse($result['valid']);
        $this->assertContains('Missing required field: protocol_version', $result['errors']);
    }

    public function testValidateRejectsNewerProtocolVersion(): void
    {
        $message = [
            'type' => Messages::TYPE_GROUP_CREATE,
            'protocol_version' => Messages::PROTOCOL_VERSION + 1,
            'group_name' => 'Test',
        ];

        $result = Messages::validate($message);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testValidateAcceptsPlaybackPlayWithRequiredFields(): void
    {
        $message = Messages::playbackPlay('group_123', 'member_456', 5000, time());

        $result = Messages::validate($message);

        $this->assertTrue($result['valid']);
    }

    public function testValidateRejectsPlaybackPlayMissingPosition(): void
    {
        $message = [
            'type' => Messages::TYPE_PLAYBACK_PLAY,
            'protocol_version' => Messages::PROTOCOL_VERSION,
            'group_id' => 'group_123',
            'member_id' => 'member_456',
            'server_time' => time(),
        ];

        $result = Messages::validate($message);

        $this->assertFalse($result['valid']);
        $this->assertContains('Missing playback position', $result['errors']);
    }

    public function testValidateRejectsChatMessageEmptyContent(): void
    {
        $message = [
            'type' => Messages::TYPE_CHAT_MESSAGE,
            'protocol_version' => Messages::PROTOCOL_VERSION,
            'group_id' => 'group_123',
            'member_id' => 'member_456',
            'message' => '',
        ];

        $result = Messages::validate($message);

        $this->assertFalse($result['valid']);
    }

    public function testSerializeProducesJson(): void
    {
        $message = Messages::groupCreate('Test Group');
        $json = Messages::serialize($message);

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertEquals($message, $decoded);
    }

    public function testDeserializeParsesValidJson(): void
    {
        $message = Messages::groupCreate('Test Group');
        $json = Messages::serialize($message);

        $result = Messages::deserialize($json);

        $this->assertTrue($result['valid']);
        $this->assertEquals($message, $result['message']);
    }

    public function testDeserializeRejectsInvalidJson(): void
    {
        $result = Messages::deserialize('not valid json');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('JSON parse error', $result['error']);
    }

    public function testDeserializeRejectsInvalidMessageStructure(): void
    {
        $result = Messages::deserialize('{"type": "invalid"}');

        $this->assertFalse($result['valid']);
    }

    public function testGetProtocolVersion(): void
    {
        $this->assertEquals(1, Messages::getProtocolVersion());
    }

    public function testPasswordHashingIsConsistent(): void
    {
        // Use reflection to test the private hashPassword method
        $reflection = new \ReflectionClass(Messages::class);
        $method = $reflection->getMethod('hashPassword');
        $method->setAccessible(true);

        $hash1 = $method->invoke(null, 'password123');
        $hash2 = $method->invoke(null, 'password123');

        $this->assertEquals($hash1, $hash2);
        $this->assertEquals(64, strlen($hash1)); // SHA256 produces 64 hex chars
    }
}
