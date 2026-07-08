<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\JwtHandler;
use Phlix\Server\WebSocket\Connection;
use Phlix\Server\WebSocket\ConnectionInterface;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Server\WebSocket\WebSocketServer;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Tests\Unit\Server\WebSocket\TestConnection;

/**
 * Unit tests for WebSocket JWT authentication and server-derived member_id.
 *
 * @covers \Phlix\Server\WebSocket\WebSocketServer
 * @covers \Phlix\Session\SyncPlay\SyncPlayManager
 */
class WsAuthenticationTest extends TestCase
{
    private JwtHandler $jwtHandler;
    private string $jwtSecret = 'test-secret-key-for-ws-auth-256bit';

    protected function setUp(): void
    {
        parent::setUp();
        ConnectionPool::getInstance()->clear();
        $this->jwtHandler = new JwtHandler($this->jwtSecret, 'HS256', 3600, 604800);
    }

    /**
     * Creates a mock TcpConnection that tracks send and close calls.
     *
     * @param array<string, bool> $callTracker Tracks which methods were called
     * @return \PHPUnit\Framework\MockObject\MockObject&\Workerman\Connection\TcpConnection
     */
    private function createMockTcpConnection(array &$callTracker = []): \Workerman\Connection\TcpConnection|\PHPUnit\Framework\MockObject\MockObject
    {
        $callTracker = ['send' => false, 'close' => false];

        $mockConnection = $this->createMock(\Workerman\Connection\TcpConnection::class);
        $mockConnection->method('send')->willReturnCallback(function () use (&$callTracker) {
            $callTracker['send'] = true;
        });
        $mockConnection->method('close')->willReturnCallback(function () use (&$callTracker) {
            $callTracker['close'] = true;
        });

        return $mockConnection;
    }

    /**
     * Creates a SyncPlayManager with handleMessage exposed for testing.
     */
    private function createTestableSyncPlayManager(): TestableSyncPlayManager
    {
        $pool = ConnectionPool::getInstance();
        $handler = new MessageHandler($pool);

        return new TestableSyncPlayManager($handler);
    }

    /**
     * @covers \Phlix\Server\WebSocket\WebSocketServer::onConnect
     */
    public function testValidTokenAuthenticatesConnection(): void
    {
        $config = [
            'host' => '0.0.0.0',
            'port' => 8097,
            'jwt_secret' => $this->jwtSecret,
        ];

        $server = new WebSocketServer($config);
        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection($callTracker);

        // Set the token in $_GET
        $token = $this->jwtHandler->createAccessToken('user-123');
        $_GET['token'] = $token;

        // Call onConnect
        $server->onConnect($mockConnection);

        // Verify the connection was added to pool and authenticated
        $pool = ConnectionPool::getInstance();
        $connections = $pool->all();
        $this->assertCount(1, $connections);

        $wsConnection = $connections[0];
        $this->assertInstanceOf(Connection::class, $wsConnection);
        $this->assertTrue($wsConnection->isAuthenticated());
        $this->assertEquals('user-123', $wsConnection->getUserId());
        $this->assertTrue($callTracker['send'], 'Welcome message should be sent');

        // Clean up
        unset($_GET['token']);
    }

    /**
     * @covers \Phlix\Server\WebSocket\WebSocketServer::onConnect
     */
    public function testInvalidTokenClosesConnection(): void
    {
        $config = [
            'host' => '0.0.0.0',
            'port' => 8097,
            'jwt_secret' => $this->jwtSecret,
        ];

        $server = new WebSocketServer($config);
        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection($callTracker);

        // Set an invalid token in $_GET
        $_GET['token'] = 'invalid.token.here';

        // Call onConnect
        $server->onConnect($mockConnection);

        // Verify close was called
        $this->assertTrue($callTracker['close'], 'Connection should be closed for invalid token');

        // Verify no connection was added to pool
        $pool = ConnectionPool::getInstance();
        $this->assertCount(0, $pool->all());

        // Clean up
        unset($_GET['token']);
    }

    /**
     * @covers \Phlix\Server\WebSocket\WebSocketServer::onConnect
     */
    public function testMissingTokenAllowsUnauthenticatedConnection(): void
    {
        $config = [
            'host' => '0.0.0.0',
            'port' => 8097,
            'jwt_secret' => $this->jwtSecret,
        ];

        $server = new WebSocketServer($config);
        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection($callTracker);

        // Don't set any token
        unset($_GET['token']);

        // Call onConnect
        $server->onConnect($mockConnection);

        // Verify connection was added but not authenticated
        $pool = ConnectionPool::getInstance();
        $connections = $pool->all();
        $this->assertCount(1, $connections);

        $wsConnection = $connections[0];
        $this->assertFalse($wsConnection->isAuthenticated());
        $this->assertNull($wsConnection->getUserId());
        $this->assertTrue($callTracker['send'], 'Welcome message should be sent');
    }

    /**
     * @covers \Phlix\Server\WebSocket\WebSocketServer::onConnect
     */
    public function testExpiredTokenRejectsConnection(): void
    {
        $config = [
            'host' => '0.0.0.0',
            'port' => 8097,
            'jwt_secret' => $this->jwtSecret,
        ];

        // Create an expired JWT handler
        $expiredHandler = new JwtHandler($this->jwtSecret, 'HS256', -10, 604800);
        $token = $expiredHandler->createAccessToken('user-456');

        $server = new WebSocketServer($config);
        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection($callTracker);

        // Set the expired token
        $_GET['token'] = $token;

        // Call onConnect
        $server->onConnect($mockConnection);

        // Verify close was called
        $this->assertTrue($callTracker['close'], 'Connection should be closed for expired token');

        // Verify no connection was added to pool
        $pool = ConnectionPool::getInstance();
        $this->assertCount(0, $pool->all());

        // Clean up
        unset($_GET['token']);
    }

    /**
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::handleMessage
     */
    public function testUnauthenticatedConnectionCannotCreateGroup(): void
    {
        $syncPlayManager = $this->createTestableSyncPlayManager();

        // Track if sendFlat was called with error
        $errorSent = false;
        $errorCode = '';

        // Create an unauthenticated mock connection
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('isAuthenticated')->willReturn(false);
        $mockConnection->method('sendFlat')->willReturnCallback(function (string $type, array $data) use (&$errorSent, &$errorCode) {
            if ($type === Messages::TYPE_ERROR) {
                $errorSent = true;
                $errorCode = $data['error_code'] ?? '';
            }
        });
        $mockConnection->method('sendMessage')->willReturnCallback(function () {
        });
        $mockConnection->method('getId')->willReturn('conn-123');
        $mockConnection->method('getUserId')->willReturn(null);

        // Try to create a group (should be rejected)
        $payload = [
            'type' => Messages::TYPE_GROUP_CREATE,
            'group_name' => 'Test Group',
        ];

        $syncPlayManager->publicHandleMessage($mockConnection, $payload);

        // Verify NOT_AUTHENTICATED error was sent
        $this->assertTrue($errorSent, 'Error should be sent for unauthenticated create attempt');
        $this->assertEquals('NOT_AUTHENTICATED', $errorCode);
    }

    /**
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::handleMessage
     */
    public function testUnauthenticatedConnectionCannotJoinGroup(): void
    {
        $syncPlayManager = $this->createTestableSyncPlayManager();

        // Track if sendFlat was called with error
        $errorSent = false;
        $errorCode = '';

        // Create an unauthenticated mock connection
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('isAuthenticated')->willReturn(false);
        $mockConnection->method('sendFlat')->willReturnCallback(function (string $type, array $data) use (&$errorSent, &$errorCode) {
            if ($type === Messages::TYPE_ERROR) {
                $errorSent = true;
                $errorCode = $data['error_code'] ?? '';
            }
        });
        $mockConnection->method('sendMessage')->willReturnCallback(function () {
        });
        $mockConnection->method('getId')->willReturn('conn-123');
        $mockConnection->method('getUserId')->willReturn(null);

        // Try to join a group (should be rejected)
        $payload = [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => 'sp_abc123',
        ];

        $syncPlayManager->publicHandleMessage($mockConnection, $payload);

        // Verify NOT_AUTHENTICATED error was sent
        $this->assertTrue($errorSent, 'Error should be sent for unauthenticated join attempt');
        $this->assertEquals('NOT_AUTHENTICATED', $errorCode);
    }

    /**
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::handleMessage
     */
    public function testUnauthenticatedConnectionCannotControlPlayback(): void
    {
        $syncPlayManager = $this->createTestableSyncPlayManager();

        // Track error count
        $errorCount = 0;

        // Create an unauthenticated mock connection
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('isAuthenticated')->willReturn(false);
        $mockConnection->method('sendFlat')->willReturnCallback(function (string $type, array $data) use (&$errorCount) {
            if ($type === Messages::TYPE_ERROR) {
                $errorCount++;
            }
        });
        $mockConnection->method('sendMessage')->willReturnCallback(function () {
        });
        $mockConnection->method('getId')->willReturn('conn-123');
        $mockConnection->method('getUserId')->willReturn(null);

        // Try to send playback commands (should be rejected)
        $payloads = [
            ['type' => Messages::TYPE_PLAYBACK_PLAY, 'position' => 1000, 'server_time' => time()],
            ['type' => Messages::TYPE_PLAYBACK_PAUSE, 'position' => 1000, 'server_time' => time()],
            ['type' => Messages::TYPE_PLAYBACK_SEEK, 'from_position' => 1000, 'to_position' => 2000, 'server_time' => time()],
        ];

        foreach ($payloads as $payload) {
            $syncPlayManager->publicHandleMessage($mockConnection, $payload);
        }

        // Verify NOT_AUTHENTICATED error was sent 3 times
        $this->assertEquals(3, $errorCount, 'Should reject 3 playback commands from unauthenticated connection');
    }

    /**
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::handleGroupCreate
     */
    public function testServerDerivedMemberIdIsUsedInsteadOfClientSupplied(): void
    {
        $syncPlayManager = $this->createTestableSyncPlayManager();

        // Create a test connection that properly tracks authenticated state
        $testConnection = new TestConnection('conn-456');
        $testConnection->setAuthenticated(true, 'server-user-id-123');

        // Send create group with a different client-supplied member_id
        // The server should ignore it and use the userId instead
        $payload = [
            'type' => Messages::TYPE_GROUP_CREATE,
            'member_id' => 'client-claimed-member-id', // This should be IGNORED
            'member_name' => 'Test Host',
            'group_name' => 'Test Group',
        ];

        $syncPlayManager->publicHandleMessage($testConnection, $payload);

        // Verify the group was created with the server-derived userId
        $groups = $syncPlayManager->listGroups();
        $this->assertCount(1, $groups);

        $groupState = $syncPlayManager->getGroupState($groups[0]['id']);
        $this->assertNotNull($groupState);

        // The member should have the server-derived userId (not the client-supplied one)
        /** @var array<string, mixed> $members */
        $members = $groupState['members'] ?? [];
        $this->assertArrayHasKey('server-user-id-123', $members, 'Should use server-derived userId');
        $this->assertArrayNotHasKey('client-claimed-member-id', $members, 'Should NOT use client-supplied member_id');
    }

    /**
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::handlePlaybackPlay
     */
    public function testHostAuthorizationUsesServerDerivedMemberId(): void
    {
        $syncPlayManager = $this->createTestableSyncPlayManager();

        // Create a test connection that properly tracks authenticated state
        $testConnection = new TestConnection('conn-789');
        $testConnection->setAuthenticated(true, 'authenticated-user');

        // First create a group (authenticated user will be host)
        $syncPlayManager->publicHandleMessage($testConnection, [
            'type' => Messages::TYPE_GROUP_CREATE,
            'member_name' => 'Host User',
            'group_name' => 'Test Group',
        ]);

        // Now try to send playback command with a spoofed member_id
        // The server should use the authenticated userId for host check, not the spoofed one
        $syncPlayManager->publicHandleMessage($testConnection, [
            'type' => Messages::TYPE_PLAYBACK_PLAY,
            'member_id' => 'spoofed-member-id', // This should be IGNORED
            'position' => 1000,
            'server_time' => time(),
        ]);

        // The playback command should succeed because the authenticated user is the host
        $sentFlatMessages = $testConnection->getSentFlatMessages(Messages::TYPE_PLAYBACK_PLAY);
        $this->assertNotEmpty($sentFlatMessages, 'Playback play should succeed for host');

        // The member_id in the broadcast should be the server-derived userId
        $playbackData = $sentFlatMessages[0] ?? [];
        $this->assertEquals(
            'authenticated-user',
            $playbackData['member_id'] ?? '',
            'Should use server-derived userId for host authorization'
        );
    }

    /**
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::handleGroupJoin
     */
    public function testJoinGroupUsesServerDerivedMemberId(): void
    {
        $syncPlayManager = $this->createTestableSyncPlayManager();

        // Create a test connection for host
        $hostConnection = new TestConnection('conn-host');
        $hostConnection->setAuthenticated(true, 'host-user');

        $syncPlayManager->publicHandleMessage($hostConnection, [
            'type' => Messages::TYPE_GROUP_CREATE,
            'member_name' => 'Host User',
            'group_name' => 'Test Group',
        ]);

        $groups = $syncPlayManager->listGroups();
        $this->assertCount(1, $groups);
        $groupId = $groups[0]['id'];

        // Create a member connection that will try to join with a different member_id
        $memberConnection = new TestConnection('conn-member');
        $memberConnection->setAuthenticated(true, 'member-user');

        // Join with a spoofed member_id - should be ignored
        $syncPlayManager->publicHandleMessage($memberConnection, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupId,
            'member_id' => 'spoofed-member-id', // Should be IGNORED
            'member_name' => 'Spoofed Name',
        ]);

        // Verify the group has the server-derived userId as member, not the spoofed one
        $groupState = $syncPlayManager->getGroupState($groupId);
        $this->assertNotNull($groupState);

        /** @var array<string, mixed> $members */
        $members = $groupState['members'] ?? [];
        $this->assertArrayHasKey('member-user', $members, 'Should use server-derived userId as member');
        $this->assertArrayNotHasKey('spoofed-member-id', $members, 'Should NOT use client-supplied member_id');
    }
}

/**
 * {@see SyncPlayManager} double that exposes the protected `handleMessage()`
 * as public so the authentication paths can be driven directly in tests.
 *
 * @internal For testing only
 */
class TestableSyncPlayManager extends SyncPlayManager
{
    public function __construct(MessageHandler $handler)
    {
        parent::__construct();
        $this->initialize($handler);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function publicHandleMessage(ConnectionInterface $connection, array $payload): void
    {
        $this->handleMessage($connection, $payload);
    }
}
