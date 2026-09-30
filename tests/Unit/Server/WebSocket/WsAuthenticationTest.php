<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\JwtHandler;
use Phlix\Server\WebSocket\Connection;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\SyncPlayAuthMiddleware;
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

        // Track if an error frame was sent
        $errorSent = false;
        $errorCode = '';

        // Create an unauthenticated mock connection
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('isAuthenticated')->willReturn(false);
        $mockConnection->method('send')->willReturnCallback(function (string|array $frame) use (&$errorSent, &$errorCode): bool {
            if (is_array($frame) && ($frame['type'] ?? '') === Messages::TYPE_ERROR) {
                $errorSent = true;
                $errorCode = is_string($frame['error_code'] ?? null) ? $frame['error_code'] : '';
            }
            return true;
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

        // Track if an error frame was sent
        $errorSent = false;
        $errorCode = '';

        // Create an unauthenticated mock connection
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('isAuthenticated')->willReturn(false);
        $mockConnection->method('send')->willReturnCallback(function (string|array $frame) use (&$errorSent, &$errorCode): bool {
            if (is_array($frame) && ($frame['type'] ?? '') === Messages::TYPE_ERROR) {
                $errorSent = true;
                $errorCode = is_string($frame['error_code'] ?? null) ? $frame['error_code'] : '';
            }
            return true;
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
        $mockConnection->method('send')->willReturnCallback(function (string|array $frame) use (&$errorCount): bool {
            if (is_array($frame) && ($frame['type'] ?? '') === Messages::TYPE_ERROR) {
                $errorCount++;
            }
            return true;
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
        $playbackFrames = $this->framesOfType($testConnection, Messages::TYPE_PLAYBACK_PLAY);
        $this->assertNotEmpty($playbackFrames, 'Playback play should succeed for host');

        // The member_id in the flat frame is the server-derived userId
        $playbackData = $this->frameData($playbackFrames[0]);
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

    // -----------------------------------------------------------------
    // S291 — handlePlaybackSync's docblock once claimed the frame goes
    // "directly to the requesting member", but the body broadcasts it to the
    // whole group via broadcastToGroup(). This case pins the REAL semantics:
    // a sync requested by user-b must ALSO reach the host (user-a). If the send
    // is ever narrowed back to a reply aimed only at the caller, the host frame
    // disappears and this test reddens.
    // -----------------------------------------------------------------

    public function testPlaybackSyncBroadcastsToWholeGroupNotJustRequester(): void
    {
        $manager = $this->createTestableSyncPlayManager();
        [$a, $b] = $this->seedTwoMemberRoom($manager);

        $manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_PLAYBACK_SYNC,
            'member_id' => 'user-b',
        ]);

        // The requester receives it...
        $this->assertNotEmpty(
            $this->framesOfType($b, Messages::TYPE_PLAYBACK_SYNC),
            'the requesting member must receive the sync broadcast'
        );
        // ...AND so does the *other* member. This is the assertion that a
        // revert to a direct reply (send only to $connection) would break.
        $this->assertNotEmpty(
            $this->framesOfType($a, Messages::TYPE_PLAYBACK_SYNC),
            'S291: playback_sync is a group broadcast — a non-requesting member (the host) '
                . 'must also receive it; if this reddens, the send was narrowed to a direct reply'
        );
        $this->assertSame(
            'user-a',
            $this->frameData($this->framesOfType($a, Messages::TYPE_PLAYBACK_SYNC)[0])['member_id'] ?? null,
            'the host frame is stamped with the host id, matching the requester response'
        );
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
     * Field map of a captured outbound frame.
     *
     * Since S417 every SyncPlay frame on the wire is flat canonical
     * (Messages::frame), so the frame itself is the field map. The nested
     * 'payload' unwrap of the retired sendFlat capture is gone: a frame that
     * is not flat no longer silently normalises.
     *
     * @param array<array-key, mixed> $frame
     * @return array<array-key, mixed>
     */
    private function frameData(array $frame): array
    {
        return $frame;
    }

    // ------------------------------------------------------------------
    // Transitional dual-carrier handshake law: `Sec-WebSocket-Protocol:
    // bearer, <jwt>` alongside the legacy `?token=<jwt>` (estate policy
    // WEBSOCKET_URL_QUERY_REFUSED; mirrors phlix-hub :8804 S237/S355).
    // ------------------------------------------------------------------

    /**
     * Build a REAL parsed WS upgrade Request with optional query + subprotocol
     * carriers, exactly as a client would send them on the wire.
     */
    private function makeHandshakeRequest(?string $queryToken, ?string $subprotocol = null): \Workerman\Protocols\Http\Request
    {
        $query = $queryToken === null ? '' : '?token=' . $queryToken;
        $raw = "GET /syncplay{$query} HTTP/1.1\r\nHost: localhost\r\n";
        if ($subprotocol !== null) {
            $raw .= "Sec-WebSocket-Protocol: {$subprotocol}\r\n";
        }

        return new \Workerman\Protocols\Http\Request($raw . "\r\n");
    }

    /**
     * @return array{host: string, port: int, jwt_secret: string}
     */
    private function authConfig(): array
    {
        return ['host' => '0.0.0.0', 'port' => 8097, 'jwt_secret' => $this->jwtSecret];
    }

    /**
     * Bearer-subprotocol-only carrier authenticates and gets the marker echoed
     * on the 101 — never the token.
     */
    public function testBearerSubprotocolCarrierAuthenticatesAndEchoesMarker(): void
    {
        $server = new WebSocketServer($this->authConfig());
        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection($callTracker);
        $token = $this->jwtHandler->createAccessToken('user-bearer');

        $server->onConnect($mockConnection);
        $server->onWebSocketConnect(
            $mockConnection,
            $this->makeHandshakeRequest(null, 'bearer, ' . $token)
        );

        $connections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $connections);
        $this->assertTrue($connections[0]->isAuthenticated());
        $this->assertSame('user-bearer', $connections[0]->getUserId());
        $this->assertFalse($callTracker['close']);
        $this->assertSame(
            ['Sec-WebSocket-Protocol: bearer'],
            $mockConnection->headers,
            'The 101 must echo the bearer marker so the browser WHATWG negotiation succeeds'
        );
        foreach ($mockConnection->headers as $header) {
            $this->assertStringNotContainsString($token, $header, 'A credential is not a protocol-id.');
        }
    }

    /**
     * Legacy ?token=-only carrier still authenticates (compat) and, negotiating
     * no subprotocol, must get NO echo (RFC 6455 §4.1: answering an offer the
     * client never made is itself the violation).
     */
    public function testQueryOnlyCarrierStillAuthenticatesWithoutEcho(): void
    {
        $server = new WebSocketServer($this->authConfig());
        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection($callTracker);
        $token = $this->jwtHandler->createAccessToken('user-query');

        $server->onConnect($mockConnection);
        $server->onWebSocketConnect($mockConnection, $this->makeHandshakeRequest($token));

        $connections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $connections);
        $this->assertSame('user-query', $connections[0]->getUserId());
        $this->assertSame([], $mockConnection->headers, 'No subprotocol offered → no echo.');
    }

    /**
     * Both carriers, identical credential: accepted (header wins), echo applies
     * because `bearer` was offered.
     */
    public function testBothCarriersIdenticalTokenAccepted(): void
    {
        $server = new WebSocketServer($this->authConfig());
        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection($callTracker);
        $token = $this->jwtHandler->createAccessToken('user-both');

        $server->onConnect($mockConnection);
        $server->onWebSocketConnect(
            $mockConnection,
            $this->makeHandshakeRequest($token, 'bearer, ' . $token)
        );

        $connections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $connections);
        $this->assertSame('user-both', $connections[0]->getUserId());
        $this->assertSame(['Sec-WebSocket-Protocol: bearer'], $mockConnection->headers);
    }

    /**
     * Both carriers, DIFFERENT credentials: rejected pre-101 — a half-migrated
     * client must fail loudly, not authenticate on a credential other than the
     * one it presents.
     */
    public function testCarrierMismatchRejectedPreUpgrade(): void
    {
        $server = new WebSocketServer($this->authConfig());
        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection($callTracker);
        $bearerToken = $this->jwtHandler->createAccessToken('user-a');
        $queryToken = $this->jwtHandler->createAccessToken('user-b');

        $server->onConnect($mockConnection);
        $server->onWebSocketConnect(
            $mockConnection,
            $this->makeHandshakeRequest($queryToken, 'bearer, ' . $bearerToken)
        );

        $this->assertTrue($callTracker['close'], 'Mismatched carriers must reject the handshake.');
        $this->assertCount(0, ConnectionPool::getInstance()->all());
        $this->assertSame([], $mockConnection->headers, 'A rejected handshake answers no negotiation.');
    }

    /**
     * Bearer carrier with an INVALID credential: rejected pre-101 like any
     * other invalid token (no echo can reach a client that never upgrades).
     */
    public function testBearerCarrierWithInvalidTokenRejected(): void
    {
        $server = new WebSocketServer($this->authConfig());
        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection($callTracker);

        $server->onConnect($mockConnection);
        $server->onWebSocketConnect(
            $mockConnection,
            $this->makeHandshakeRequest(null, 'bearer, not-a-jwt')
        );

        $this->assertTrue($callTracker['close']);
        $this->assertCount(0, ConnectionPool::getInstance()->all());
    }

    /**
     * `bearer` offered WITHOUT a token entry falls back to the legacy query
     * carrier (transitional clients mid-retirement) and still gets the echo,
     * because negotiation is offer-driven, not carrier-driven — the hub law.
     */
    public function testBearerMarkerWithoutTokenEntryFallsBackToQueryAndEchoes(): void
    {
        $server = new WebSocketServer($this->authConfig());
        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection($callTracker);
        $token = $this->jwtHandler->createAccessToken('user-fallback');

        $server->onConnect($mockConnection);
        $server->onWebSocketConnect($mockConnection, $this->makeHandshakeRequest($token, 'bearer'));

        $connections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $connections);
        $this->assertSame('user-fallback', $connections[0]->getUserId());
        $this->assertSame(['Sec-WebSocket-Protocol: bearer'], $mockConnection->headers);
    }

    /**
     * Dev path (no secret): anonymous connect that OFFERED bearer still gets the
     * echo — subprotocol negotiation is transport-level, independent of auth.
     */
    public function testDevModeAnonymousBearerOfferEchoes(): void
    {
        $server = new WebSocketServer(['host' => '0.0.0.0', 'port' => 8097]);
        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection($callTracker);

        $server->onConnect($mockConnection);
        $server->onWebSocketConnect($mockConnection, $this->makeHandshakeRequest(null, 'bearer, anything'));

        $this->assertFalse($callTracker['close']);
        $this->assertSame(['Sec-WebSocket-Protocol: bearer'], $mockConnection->headers);
    }

    /**
     * `bearer-chat` is a DIFFERENT protocol-id: not a bearer offer. Mirrors the
     * hub extraction quirk faithfully — the first non-`bearer` entry IS the
     * token candidate, so 'bearer-chat' becomes the (invalid) credential and
     * the handshake rejects.
     */
    public function testNearMissSubprotocolIdIsNotBearer(): void
    {
        $server = new WebSocketServer($this->authConfig());
        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection($callTracker);
        $token = $this->jwtHandler->createAccessToken('user-near');

        $server->onConnect($mockConnection);
        $server->onWebSocketConnect(
            $mockConnection,
            $this->makeHandshakeRequest(null, 'bearer-chat, ' . $token)
        );

        $this->assertTrue($callTracker['close']);
        $this->assertCount(0, ConnectionPool::getInstance()->all());
        $this->assertFalse(SyncPlayAuthMiddleware::offersBearerSubprotocol('bearer-chat'));
    }

    /**
     * resolveHandshakeToken priority matrix (pure unit of the carrier law).
     */
    public function testResolveHandshakeTokenMatrix(): void
    {
        // Header carrier wins (identical tokens on both carriers resolve bearer).
        $this->assertSame(
            ['token' => 'T1', 'carrier' => 'bearer', 'mismatch' => false],
            SyncPlayAuthMiddleware::resolveHandshakeToken('bearer, T1', 'T1')
        );
        // Query fallback.
        $this->assertSame(
            ['token' => 'T2', 'carrier' => 'query', 'mismatch' => false],
            SyncPlayAuthMiddleware::resolveHandshakeToken(null, 'T2')
        );
        // Neither.
        $this->assertSame(
            ['token' => null, 'carrier' => 'none', 'mismatch' => false],
            SyncPlayAuthMiddleware::resolveHandshakeToken(null, null)
        );
        // Mismatch.
        $this->assertSame(
            ['token' => null, 'carrier' => 'none', 'mismatch' => true],
            SyncPlayAuthMiddleware::resolveHandshakeToken('bearer, T1', 'T2')
        );
        // Empty ?token= carries no credential: absent, NOT a false mismatch.
        $this->assertSame(
            ['token' => 'T1', 'carrier' => 'bearer', 'mismatch' => false],
            SyncPlayAuthMiddleware::resolveHandshakeToken('bearer, T1', '')
        );
        $this->assertSame(
            ['token' => null, 'carrier' => 'none', 'mismatch' => false],
            SyncPlayAuthMiddleware::resolveHandshakeToken(null, '')
        );
        // Bearer marker with no token entry → no header credential.
        $this->assertSame(
            ['token' => 'T3', 'carrier' => 'query', 'mismatch' => false],
            SyncPlayAuthMiddleware::resolveHandshakeToken('bearer', 'T3')
        );
        $this->assertSame(
            ['token' => null, 'carrier' => 'none', 'mismatch' => false],
            SyncPlayAuthMiddleware::resolveHandshakeToken('bearer', null)
        );
    }

    /**
     * bearerSubprotocolToken / offersBearerSubprotocol entry parsing mirrors
     * phlix-hub's per-entry RFC 7230 comparison (trim, exact-match marker).
     */
    public function testBearerCarrierParsingMirrorsHubLaw(): void
    {
        $this->assertSame('JWT', SyncPlayAuthMiddleware::bearerSubprotocolToken('bearer, JWT'));
        $this->assertSame('JWT', SyncPlayAuthMiddleware::bearerSubprotocolToken('JWT, bearer'));
        $this->assertSame('JWT', SyncPlayAuthMiddleware::bearerSubprotocolToken(' bearer ,  JWT '));
        $this->assertNull(SyncPlayAuthMiddleware::bearerSubprotocolToken('bearer'));
        $this->assertNull(SyncPlayAuthMiddleware::bearerSubprotocolToken('bearer, , '));
        $this->assertNull(SyncPlayAuthMiddleware::bearerSubprotocolToken(null));
        $this->assertNull(SyncPlayAuthMiddleware::bearerSubprotocolToken(''));

        $this->assertTrue(SyncPlayAuthMiddleware::offersBearerSubprotocol('bearer, JWT'));
        $this->assertTrue(SyncPlayAuthMiddleware::offersBearerSubprotocol(' JWT, bearer '));
        $this->assertTrue(SyncPlayAuthMiddleware::offersBearerSubprotocol('bearer'));
        $this->assertFalse(SyncPlayAuthMiddleware::offersBearerSubprotocol('bearer-chat'));
        $this->assertFalse(SyncPlayAuthMiddleware::offersBearerSubprotocol('Bearer'));
        $this->assertFalse(SyncPlayAuthMiddleware::offersBearerSubprotocol(null));
    }

    /**
     * redactTokenQuery masks the credential but nothing else — the guarantee
     * behind every handshake URI that reaches a log line while legacy query
     * clients are still in the field.
     */
    public function testRedactTokenQueryMasksOnlyTheTokenParam(): void
    {
        $this->assertSame(
            '/syncplay?token=[redacted]',
            SyncPlayAuthMiddleware::redactTokenQuery('/syncplay?token=eyJhbGciOi.hidden.sig')
        );
        $this->assertSame(
            '/x?token=[redacted]&room=1',
            SyncPlayAuthMiddleware::redactTokenQuery('/x?token=abc&room=1')
        );
        $this->assertSame(
            '/x?room=1&token=[redacted]',
            SyncPlayAuthMiddleware::redactTokenQuery('/x?room=1&token=abc')
        );
        $this->assertSame(
            '/x?token=[redacted]#frag',
            SyncPlayAuthMiddleware::redactTokenQuery('/x?token=abc#frag')
        );
        $this->assertSame(
            '/x?a=1&token=[redacted]&b=2&token=[redacted]',
            SyncPlayAuthMiddleware::redactTokenQuery('/x?a=1&token=p&b=2&token=q')
        );
        // Whole-name matching: near-miss param names are NOT touched — the
        // case-insensitive pin applies to the NAME, never to name substrings.
        $this->assertSame(
            '/x?mytoken=secret&tokens=1',
            SyncPlayAuthMiddleware::redactTokenQuery('/x?mytoken=secret&tokens=1')
        );
        $this->assertSame(
            '/x?MYTOKEN=secret&ToKeNs=1',
            SyncPlayAuthMiddleware::redactTokenQuery('/x?MYTOKEN=secret&ToKeNs=1')
        );
        $this->assertSame('/syncplay', SyncPlayAuthMiddleware::redactTokenQuery('/syncplay'));
        // Non-token queries are left byte-identical.
        $this->assertSame(
            '/x?room=42&profile=kids#seg',
            SyncPlayAuthMiddleware::redactTokenQuery('/x?room=42&profile=kids#seg')
        );

        // Server-side reads are exact-key (parse_str preserves case, so
        // ?TOKEN= never authenticates) — but the redactor sees the RAW logged
        // URI, where a live JWT can ride any case spelling of `token`. Every
        // whole-name spelling must be masked; the masked name normalises to
        // lowercase `token=[redacted]`.
        $this->assertSame(
            '/syncplay?token=[redacted]',
            SyncPlayAuthMiddleware::redactTokenQuery('/syncplay?TOKEN=eyJhbGciOi.upper.sig')
        );
        $this->assertSame(
            '/x?room=1&token=[redacted]&lang=en',
            SyncPlayAuthMiddleware::redactTokenQuery('/x?room=1&Token=abc&lang=en')
        );
        $this->assertSame(
            '/x?token=[redacted]&token=[redacted]',
            SyncPlayAuthMiddleware::redactTokenQuery('/x?TOKEN=jwt&token=secret')
        );
        // Mismatch-attack shape from the bearer-law review: the exact-key
        // read consumes the lowercase decoy and the mismatch log writes the
        // raw URI — the wrong-case live JWT beside it must not survive
        // unredacted in that log line.
        $this->assertStringNotContainsString(
            'jwt',
            SyncPlayAuthMiddleware::redactTokenQuery('/syncplay?TOKEN=jwt&token=eyJhbGciOi.hidden.sig')
        );
    }

    /**
     * Log-masking PROOF: a carrier mismatch logs the rejected handshake URI
     * with the legacy query credential REDACTED — the raw JWT appears nowhere
     * in the written log line (nor does the bearer-carried one).
     */
    public function testMismatchLogNeverContainsTheCredential(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'phlix-ws-log-');
        $configFile = tempnam(sys_get_temp_dir(), 'phlix-ws-cfg-');
        $this->assertNotFalse($logFile);
        $this->assertNotFalse($configFile);

        file_put_contents(
            $configFile,
            '<?php return ' . var_export([
                'handlers' => [
                    ['type' => 'stream', 'path' => $logFile, 'channels' => ['websocket']],
                ],
            ], true) . ';'
        );

        try {
            \Phlix\Common\Logger\LoggerFactory::reset();
            \Phlix\Common\Logger\LoggerFactory::init($configFile);

            $server = new WebSocketServer($this->authConfig());
            $callTracker = [];
            $mockConnection = $this->createMockTcpConnection($callTracker);
            $bearerToken = $this->jwtHandler->createAccessToken('user-x');
            $queryToken = $this->jwtHandler->createAccessToken('user-y');

            $server->onConnect($mockConnection);
            $server->onWebSocketConnect(
                $mockConnection,
                $this->makeHandshakeRequest($queryToken, 'bearer, ' . $bearerToken)
            );

            $this->assertTrue($callTracker['close'], 'precondition: mismatch rejected');

            $logged = (string) file_get_contents($logFile);
            $this->assertStringContainsString('handshake rejected', $logged);
            $this->assertStringContainsString('token=[redacted]', $logged);
            $this->assertStringNotContainsString($queryToken, $logged, 'Legacy query credential must never reach the log.');
            $this->assertStringNotContainsString($bearerToken, $logged, 'Bearer-carried credential must never reach the log.');
        } finally {
            \Phlix\Common\Logger\LoggerFactory::reset();
            @unlink($logFile);
            @unlink($configFile);
        }
    }
}
