<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Session\SyncPlay\Messages;

/**
 * Unit tests for SyncPlay message shape compliance (SP2).
 *
 */
class MessagesFrameShapeTest extends TestCase
{
    public function testGroupStateFactoryProducesFlatEnvelope(): void
    {
        $message = Messages::groupState(
            'sp_abc123',
            [['id' => 'm1', 'name' => 'Member 1', 'is_host' => true]],
            'media_1',
            5000,
            'playing',
            'm1'
        );

        // Must have top-level keys
        $this->assertArrayHasKey('type', $message);
        $this->assertArrayHasKey('protocol_version', $message);
        $this->assertArrayHasKey('timestamp', $message);

        // Must have specific group state keys at top level (NOT under 'data')
        $this->assertArrayHasKey('group_id', $message);
        $this->assertArrayHasKey('members', $message);
        $this->assertArrayHasKey('current_media_id', $message);
        $this->assertArrayHasKey('playback_position', $message);
        $this->assertArrayHasKey('playback_state', $message);
        $this->assertArrayHasKey('host_id', $message);

        // Must NOT have 'data' key
        $this->assertArrayNotHasKey('data', $message);

        // Verify values
        $this->assertEquals(Messages::TYPE_GROUP_STATE, $message['type']);
        $this->assertEquals(Messages::PROTOCOL_VERSION, $message['protocol_version']);
        $this->assertEquals('sp_abc123', $message['group_id']);
    }

    public function testErrorFactoryUsesErrorCodeNotCode(): void
    {
        $message = Messages::error('NOT_IN_GROUP', 'You are not in a group');

        // Must use 'error_code' field
        $this->assertArrayHasKey('error_code', $message);
        $this->assertArrayNotHasKey('code', $message);

        // Verify values
        $this->assertEquals('NOT_IN_GROUP', $message['error_code']);
        $this->assertEquals('You are not in a group', $message['message']);
        $this->assertEquals(Messages::TYPE_ERROR, $message['type']);
        $this->assertEquals(Messages::PROTOCOL_VERSION, $message['protocol_version']);
    }

    public function testTimePongFactoryProducesFlatEnvelope(): void
    {
        $message = Messages::timePong(1000000, 1000015);

        // Must have top-level keys (no 'data' wrapper)
        $this->assertArrayHasKey('type', $message);
        $this->assertArrayHasKey('protocol_version', $message);
        $this->assertArrayHasKey('client_time', $message);
        $this->assertArrayHasKey('server_time', $message);
        $this->assertArrayHasKey('timestamp', $message);

        // Must NOT have 'data' key
        $this->assertArrayNotHasKey('data', $message);

        // Verify values
        $this->assertEquals(Messages::TYPE_TIME_PONG, $message['type']);
        $this->assertEquals(1000000, $message['client_time']);
        $this->assertEquals(1000015, $message['server_time']);
    }

    public function testTimePingFactoryProducesFlatEnvelope(): void
    {
        $message = Messages::timePing(1000000);

        // Must have top-level keys (no 'data' wrapper)
        $this->assertArrayHasKey('type', $message);
        $this->assertArrayHasKey('protocol_version', $message);
        $this->assertArrayHasKey('client_time', $message);
        $this->assertArrayHasKey('timestamp', $message);

        // Must NOT have 'data' key
        $this->assertArrayNotHasKey('data', $message);

        // Verify values
        $this->assertEquals(Messages::TYPE_TIME_PING, $message['type']);
        $this->assertEquals(1000000, $message['client_time']);
    }

    public function testPlaybackPlayFactoryProducesFlatEnvelope(): void
    {
        $message = Messages::playbackPlay('sp_abc123', 'member_1', 5000, 1234567890);

        // Must have top-level keys (no 'data' wrapper)
        $this->assertArrayHasKey('type', $message);
        $this->assertArrayHasKey('protocol_version', $message);
        $this->assertArrayHasKey('group_id', $message);
        $this->assertArrayHasKey('member_id', $message);
        $this->assertArrayHasKey('position', $message);
        $this->assertArrayHasKey('server_time', $message);
        $this->assertArrayHasKey('timestamp', $message);

        // Must NOT have 'data' key
        $this->assertArrayNotHasKey('data', $message);

        // Verify values
        $this->assertEquals(Messages::TYPE_PLAYBACK_PLAY, $message['type']);
        $this->assertEquals('sp_abc123', $message['group_id']);
        $this->assertEquals('member_1', $message['member_id']);
        $this->assertEquals(5000, $message['position']);
    }

    public function testHostElectFactoryProducesFlatEnvelope(): void
    {
        $message = Messages::hostElect('sp_abc123', 'member_new', 'member_old');

        // Must have top-level keys (no 'data' wrapper)
        $this->assertArrayHasKey('type', $message);
        $this->assertArrayHasKey('protocol_version', $message);
        $this->assertArrayHasKey('group_id', $message);
        $this->assertArrayHasKey('elected_id', $message);
        $this->assertArrayHasKey('elected_by', $message);
        $this->assertArrayHasKey('timestamp', $message);

        // Must NOT have 'data' key
        $this->assertArrayNotHasKey('data', $message);

        // Verify values
        $this->assertEquals(Messages::TYPE_HOST_ELECT, $message['type']);
        $this->assertEquals('member_new', $message['elected_id']);
        $this->assertEquals('member_old', $message['elected_by']);
    }

    public function testSerializeProducesValidFlatJson(): void
    {
        $message = Messages::groupState('sp_abc123', [], 'media_1', 0, 'stopped', null);
        $json = Messages::serialize($message);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertEquals(Messages::TYPE_GROUP_STATE, $decoded['type']);
        $this->assertArrayHasKey('group_id', $decoded);
        $this->assertArrayNotHasKey('data', $decoded);
    }

    public function testDeserializeAcceptsFlatEnvelope(): void
    {
        $flatJson = json_encode([
            'type' => Messages::TYPE_GROUP_CREATE,
            'protocol_version' => 1,
            'group_name' => 'Test Group',
            'timestamp' => 1234567890,
        ]);

        /** @var non-empty-string $flatJson */
        $result = Messages::deserialize($flatJson);

        $this->assertTrue($result['valid']);
        /** @var array{valid: true, message: array<string, mixed>} $result */
        $this->assertArrayHasKey('type', $result['message']);
        $this->assertEquals(Messages::TYPE_GROUP_CREATE, $result['message']['type']);
        $this->assertEquals('Test Group', $result['message']['group_name']);
    }

    public function testValidateRejectsFutureProtocolVersion(): void
    {
        $message = [
            'type' => Messages::TYPE_GROUP_CREATE,
            'protocol_version' => 999, // Future version
            'group_name' => 'Test',
            'timestamp' => 1234567890,
        ];

        $result = Messages::validate($message);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Protocol version mismatch', $result['errors'][0]);
    }
}
