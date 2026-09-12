<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlix\Server\WebSocket\Connection;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Session\SyncPlay\Messages;
use Workerman\Connection\TcpConnection;

/**
 * Unit tests for frame shape handling (SP2 - flat canonical wire format).
 */
class MessageHandlerFrameShapeTest extends TestCase
{
    /**
     * Tracks sent messages for verification.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $sentMessages = [];

    /**
     * Creates a Connection with a mock TcpConnection that captures sent data.
     */
    private function createConnection(bool $authenticated = true): Connection
    {
        $mockTcp = $this->createMock(TcpConnection::class);
        $mockTcp->method('send')->willReturnCallback(function ($data) {
            $decoded = is_string($data) ? json_decode($data, true) : $data;
            /** @var array<string, mixed> $decoded */
            $this->sentMessages[] = $decoded;
        });

        $connection = new class ($mockTcp) extends Connection {
            public function __construct(TcpConnection $connection)
            {
                parent::__construct($connection);
            }
        };

        // These frame-shape / protocol tests exercise dispatch, not the SV-4.7
        // auth gate — authenticate by default so privileged SyncPlay events pass
        // the gate and reach the shape/protocol logic under test.
        $connection->setAuthenticated($authenticated, $authenticated ? 'frame-shape-user' : null);

        return $connection;
    }

    /**
     * Creates a MessageHandler with a ConnectionPool for testing.
     */
    private function createMessageHandler(): MessageHandler
    {
        $pool = ConnectionPool::getInstance();
        $pool->clear();
        return new MessageHandler($pool);
    }

    protected function tearDown(): void
    {
        $this->sentMessages = [];
        parent::tearDown();
    }

    /**
     * S417: the auth-gate rejection wire site must emit a flat canonical frame
     * carrying protocol_version and a millisecond timestamp. This replaces the
     * pre-S417 test that exercised Connection::sendFlat directly — the seconds-based
     * flat-merge transport method no longer exists.
     */
    public function testAuthGateRejectionProducesFlatCanonicalEnvelope(): void
    {
        $handler = $this->createMessageHandler();
        $connection = $this->createConnection(false);

        $handler->handle($connection, (string) json_encode([
            'type' => 'syncplay_group_create',
            'protocol_version' => 1,
        ], JSON_THROW_ON_ERROR));

        $this->assertCount(1, $this->sentMessages);
        $sent = $this->sentMessages[0];

        // Must have type at top level
        $this->assertSame(Messages::TYPE_ERROR, $sent['type']);

        // S417 envelope conformance
        $this->assertSame(1, $sent['protocol_version'] ?? null);
        $this->assertIsInt($sent['timestamp'] ?? null);
        $this->assertGreaterThanOrEqual(1_000_000_000_000, $sent['timestamp'] ?? 0, 'timestamp must be milliseconds');

        // Must have payload keys at top level (NOT under 'data')
        $this->assertArrayHasKey('error_code', $sent);
        $this->assertArrayHasKey('message', $sent);
        $this->assertArrayNotHasKey('data', $sent);

        $this->assertSame('NOT_AUTHENTICATED', $sent['error_code']);
    }

    public function testSendMessageProducesDeprecatedEnvelope(): void
    {
        $connection = $this->createConnection();

        $connection->sendMessage('syncplay_group_state', [
            'group' => ['group_id' => 'sp_abc123'],
            'your_id' => 'member_1',
        ]);

        $this->assertCount(1, $this->sentMessages);
        $sent = $this->sentMessages[0];

        // Deprecated envelope has 'data' key
        $this->assertEquals('syncplay_group_state', $sent['type']);
        $this->assertArrayHasKey('data', $sent);
        $this->assertArrayHasKey('timestamp', $sent);

        // Payload is under 'data'
        /** @var array<string, mixed> $data */
        $data = $sent['data'];
        $this->assertArrayHasKey('group', $data);
        $this->assertArrayHasKey('your_id', $data);
    }

    public function testHandlePassesFlatMessageToSyncplayHandler(): void
    {
        $handler = $this->createMessageHandler();
        $connection = $this->createConnection();

        $receivedPayload = null;
        $handler->on('syncplay_group_create', function ($conn, $payload) use (&$receivedPayload) {
            $receivedPayload = $payload;
        });

        // Flat canonical message (no 'data' key, has protocol_version)
        $flatMessage = json_encode([
            'type' => 'syncplay_group_create',
            'protocol_version' => 1,
            'member_id' => 'member_1',
            'member_name' => 'Test User',
            'group_name' => 'Test Group',
            'timestamp' => 1234567890,
        ], JSON_THROW_ON_ERROR);

        $handler->handle($connection, $flatMessage);

        // The handler should receive the FULL flat message as payload
        // (message minus 'type' field, so it has protocol_version, member_id, etc.)
        $this->assertNotNull($receivedPayload);
        $this->assertIsArray($receivedPayload);
        $this->assertEquals(1, $receivedPayload['protocol_version']);
        $this->assertEquals('member_1', $receivedPayload['member_id']);
        $this->assertEquals('Test User', $receivedPayload['member_name']);
        $this->assertEquals('Test Group', $receivedPayload['group_name']);
    }

    public function testHandlePreservesDeprecatedEnvelopeBC(): void
    {
        $handler = $this->createMessageHandler();
        $connection = $this->createConnection();

        // Deprecated Tizen envelope with 'data' key
        $deprecatedMessage = json_encode([
            'type' => 'subscribe_dashboard',
            'data' => ['session_id' => 'sess_123'],
            'timestamp' => 1234567890,
        ], JSON_THROW_ON_ERROR);

        $handler->handle($connection, $deprecatedMessage);

        // subscribe_dashboard is handled by handleSubscribeDashboard() which sends
        // DASHBOARD_NOW_PLAYING message. The callback registered via on() is NOT
        // invoked (this is the original behavior for BC).
        // We verify the message was sent with the correct structure.
        $this->assertNotEmpty($this->sentMessages);
        $sent = $this->sentMessages[0];
        $this->assertEquals('dashboard_now_playing', $sent['type']);
        $this->assertArrayHasKey('data', $sent);
        /** @var array<string, mixed> $data */
        $data = $sent['data'];
        $this->assertArrayHasKey('subscribed', $data);
        $this->assertTrue($data['subscribed']);
    }

    public function testHandleRejectsUnsupportedProtocolVersion(): void
    {
        $handler = $this->createMessageHandler();
        $connection = $this->createConnection();

        $receivedPayload = null;
        $handler->on('syncplay_group_create', function ($conn, $payload) use (&$receivedPayload) {
            $receivedPayload = $payload;
        });

        // Message with future protocol version
        $futureMessage = json_encode([
            'type' => 'syncplay_group_create',
            'protocol_version' => 999, // Future version
            'member_id' => 'member_1',
            'timestamp' => 1234567890,
        ], JSON_THROW_ON_ERROR);

        $handler->handle($connection, $futureMessage);

        // Handler should NOT be called
        $this->assertNull($receivedPayload);

        // Error should be sent with error_code (not 'code') — and since S417 with
        // the full flat canonical envelope (protocol_version + ms timestamp).
        $this->assertCount(1, $this->sentMessages);
        $errorMsg = $this->sentMessages[0];
        $this->assertEquals(Messages::TYPE_ERROR, $errorMsg['type']);
        $this->assertArrayHasKey('error_code', $errorMsg);
        $this->assertEquals('PROTOCOL_VERSION_MISMATCH', $errorMsg['error_code']);
        $this->assertSame(1, $errorMsg['protocol_version'] ?? null);
        $this->assertIsInt($errorMsg['timestamp'] ?? null);
        $this->assertGreaterThanOrEqual(1_000_000_000_000, $errorMsg['timestamp'] ?? 0, 'timestamp must be milliseconds');
        $this->assertArrayNotHasKey('data', $errorMsg);
    }
}
