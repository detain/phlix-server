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
     * Builds a REAL parsed WS upgrade Request whose `token` query param carries
     * the supplied value (null = no token). SV-4.7 auth runs at the handshake
     * stage off this Request, not off $_GET at TCP-accept.
     *
     * A real Request is used rather than a mock because Workerman's Request has
     * its own `method()` accessor that collides with PHPUnit's mock configurator.
     *
     * @param string|null $token The token to place in the query string.
     * @return \Workerman\Protocols\Http\Request
     */
    private function makeRequest(?string $token): \Workerman\Protocols\Http\Request
    {
        $line = $token === null
            ? "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"
            : "GET /?token=" . $token . " HTTP/1.1\r\nHost: localhost\r\n\r\n";

        return new \Workerman\Protocols\Http\Request($line);
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
     * A valid token presented in the WS handshake authenticates the connection.
     *
     * SV-4.7: auth runs at the handshake stage (onWebSocketConnect), where the
     * upgrade request's query string is populated — not at TCP-accept (onConnect,
     * where $_GET is empty/stale under Workerman).
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

        $token = $this->jwtHandler->createAccessToken('user-123');

        // TCP-accept: wrapper created + welcome sent (still unauthenticated).
        $server->onConnect($mockConnection);
        // Handshake: token in the upgrade request authenticates the connection.
        $server->onWebSocketConnect($mockConnection, $this->makeRequest($token));

        // Verify the connection was added to pool and authenticated
        $pool = ConnectionPool::getInstance();
        $connections = $pool->all();
        $this->assertCount(1, $connections);

        $wsConnection = $connections[0];
        $this->assertInstanceOf(Connection::class, $wsConnection);
        $this->assertTrue($wsConnection->isAuthenticated());
        $this->assertEquals('user-123', $wsConnection->getUserId());
        $this->assertTrue($callTracker['send'], 'Welcome message should be sent');
    }

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

        $server->onConnect($mockConnection);
        // Handshake with a malformed token must reject the connection.
        $server->onWebSocketConnect($mockConnection, $this->makeRequest('invalid.token.here'));

        // Verify close was called
        $this->assertTrue($callTracker['close'], 'Connection should be closed for invalid token');

        // Verify the connection was removed from the pool
        $pool = ConnectionPool::getInstance();
        $this->assertCount(0, $pool->all());
    }

    /**
     * SV-4.7 Gap 2 (FLIPPED from the old insecure assertion): when a JWT secret
     * is configured, a token-less handshake MUST be rejected — the previous test
     * asserted the opposite (allowed unauthenticated), which is the vulnerability.
     */
    public function testMissingTokenRejectedWhenSecretConfigured(): void
    {
        $config = [
            'host' => '0.0.0.0',
            'port' => 8097,
            'jwt_secret' => $this->jwtSecret,
        ];

        $server = new WebSocketServer($config);
        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection($callTracker);

        $server->onConnect($mockConnection);
        // Handshake with NO token, secret configured → must be rejected.
        $server->onWebSocketConnect($mockConnection, $this->makeRequest(null));

        $this->assertTrue(
            $callTracker['close'],
            'Token-less connection must be rejected when a JWT secret is configured'
        );
        $this->assertCount(0, ConnectionPool::getInstance()->all());
    }

    /**
     * SV-4.7 Gap 2: with NO JWT secret configured (dev), a token-less handshake
     * is allowed as an anonymous, unauthenticated connection.
     */
    public function testMissingTokenAllowedWhenNoSecretConfigured(): void
    {
        // No jwt_secret key at all → anonymous connections allowed.
        $config = [
            'host' => '0.0.0.0',
            'port' => 8097,
        ];

        $server = new WebSocketServer($config);
        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection($callTracker);

        $server->onConnect($mockConnection);
        $server->onWebSocketConnect($mockConnection, $this->makeRequest(null));

        $pool = ConnectionPool::getInstance();
        $connections = $pool->all();
        $this->assertCount(1, $connections);

        $wsConnection = $connections[0];
        $this->assertFalse($wsConnection->isAuthenticated());
        $this->assertNull($wsConnection->getUserId());
        $this->assertFalse($callTracker['close'], 'Anonymous connection must be allowed when no secret is set');
        $this->assertTrue($callTracker['send'], 'Welcome message should be sent');
    }

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

        $server->onConnect($mockConnection);
        $server->onWebSocketConnect($mockConnection, $this->makeRequest($token));

        // Verify close was called
        $this->assertTrue($callTracker['close'], 'Connection should be closed for expired token');

        // Verify the connection was removed from the pool
        $pool = ConnectionPool::getInstance();
        $this->assertCount(0, $pool->all());
    }

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

    // -----------------------------------------------------------------
    // S289 — the residual handlers that still read a payload member_id (chat,
    // typing, leave, playback_sync, time_sync) are now server-derived like
    // create/join/playback-control. Each test below reddens if identity reverts
    // to the client-trusted payload field.
    // -----------------------------------------------------------------

    public function testChatMessageUsesServerIdentityNotSpoofedMemberId(): void
    {
        $manager = $this->createTestableSyncPlayManager();
        [$a, $b] = $this->seedTwoMemberRoom($manager);

        $manager->publicHandleMessage($a, [
            'type' => Messages::TYPE_CHAT_MESSAGE,
            'member_id' => 'evil-spoof', // must be IGNORED
            'message' => 'hello',
        ]);

        $chat = $this->framesOfType($b, Messages::TYPE_CHAT_MESSAGE);
        $this->assertNotEmpty($chat, 'the listener must receive the chat broadcast');
        $this->assertSame('user-a', $this->frameData($chat[0])['member_id'] ?? null);
    }

    public function testChatTypingUsesServerIdentityNotSpoofedMemberId(): void
    {
        $manager = $this->createTestableSyncPlayManager();
        [$a, $b] = $this->seedTwoMemberRoom($manager);

        $manager->publicHandleMessage($a, [
            'type' => Messages::TYPE_CHAT_TYPING,
            'member_id' => 'evil-spoof', // must be IGNORED
            'is_typing' => true,
        ]);

        $typing = $this->framesOfType($b, Messages::TYPE_CHAT_TYPING);
        $this->assertNotEmpty($typing);
        $this->assertSame('user-a', $this->frameData($typing[0])['member_id'] ?? null);
    }

    public function testGroupLeaveActsOnServerIdentityNotSpoofedMemberId(): void
    {
        $manager = $this->createTestableSyncPlayManager();
        [, $b, $groupId] = $this->seedTwoMemberRoom($manager);

        // $b is user-b but the body tries to evict user-a (the host).
        $manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_GROUP_LEAVE,
            'member_id' => 'user-a',
        ]);

        $state = $manager->getGroupState($groupId);
        $this->assertNotNull($state);
        $this->assertArrayNotHasKey('user-b', $state['members'], 'the SERVER identity must be the one that left');
        $this->assertArrayHasKey('user-a', $state['members'], 'the spoofed target must remain a member');
    }

    public function testPlaybackSyncResolvesGroupByServerIdentityNotSpoofedId(): void
    {
        $manager = $this->createTestableSyncPlayManager();
        [, $b] = $this->seedTwoMemberRoom($manager);

        // A spoofed id that maps to NO group would (pre-fix) answer NOT_IN_GROUP;
        // with server identity, user-b is really in the group and gets the sync.
        $manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_PLAYBACK_SYNC,
            'member_id' => 'ghost-id',
        ]);

        $this->assertSame([], $this->framesOfType($b, Messages::TYPE_ERROR), 'a spoofed id must not force NOT_IN_GROUP');
        $sync = $this->framesOfType($b, Messages::TYPE_PLAYBACK_SYNC);
        $this->assertNotEmpty($sync);
        $this->assertSame('user-a', $this->frameData($sync[0])['member_id'] ?? null, 'stamped with the host id');
    }

    public function testTimeSyncRepliesToServerIdentityNotSpoofedId(): void
    {
        $manager = $this->createTestableSyncPlayManager();
        [, $b] = $this->seedTwoMemberRoom($manager);

        $manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_TIME_SYNC,
            'member_id' => 'ghost-id',
        ]);

        $this->assertSame([], $this->framesOfType($b, Messages::TYPE_ERROR));
        $ts = $this->framesOfType($b, Messages::TYPE_TIME_SYNC);
        $this->assertNotEmpty($ts, 'server identity (user-b) resolves the group');
        $this->assertSame('user-b', $this->frameData($ts[0])['member_id'] ?? null, 'reply carries the server id');
    }

    /**
     * Create a room owned by user-a (conn-a) with user-b (conn-b) joined, both
     * registered in the connection pool so broadcasts resolve.
     *
     * @return array{0: TestConnection, 1: TestConnection, 2: string}
     */
    private function seedTwoMemberRoom(TestableSyncPlayManager $manager): array
    {
        $pool = ConnectionPool::getInstance();

        $a = new TestConnection('conn-a');
        $a->setAuthenticated(true, 'user-a');
        $pool->add($a);

        $b = new TestConnection('conn-b');
        $b->setAuthenticated(true, 'user-b');
        $pool->add($b);

        $manager->publicHandleMessage($a, [
            'type' => Messages::TYPE_GROUP_CREATE,
            'member_name' => 'A',
            'group_name' => 'S289 Room',
        ]);
        $groups = $manager->listGroups();
        $this->assertNotEmpty($groups, 'precondition: the group exists');
        /** @var string $groupId */
        $groupId = $groups[0]['id'];

        $manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupId,
            'member_name' => 'B',
        ]);

        return [$a, $b, $groupId];
    }

    /**
     * Frames sent to a connection whose top-level `type` matches.
     *
     * @return list<array<array-key, mixed>>
     */
    private function framesOfType(TestConnection $connection, string $type): array
    {
        return array_values(array_filter(
            $connection->getSentMessages(),
            static fn (array $frame): bool => ($frame['type'] ?? null) === $type
        ));
    }

    /**
     * A `sendFlat` frame nests its fields under `payload`; a `send()` broadcast frame
     * is already flat. Normalise both to the field map.
     *
     * @param array<array-key, mixed> $frame
     * @return array<array-key, mixed>
     */
    private function frameData(array $frame): array
    {
        if (isset($frame['payload']) && is_array($frame['payload'])) {
            /** @var array<array-key, mixed> $payload */
            $payload = $frame['payload'];
            return $payload;
        }

        return $frame;
    }
}
