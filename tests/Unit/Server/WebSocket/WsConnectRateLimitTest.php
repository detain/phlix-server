<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use Phlix\Auth\JwtHandler;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Common\RateLimit\RateLimitState;
use Phlix\Server\WebSocket\Connection;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\WebSocketServer;
use PHPUnit\Framework\TestCase;

/**
 * SV-4.15(h): per-IP connect throttle enforced INLINE in
 * {@see WebSocketServer::onWebSocketConnect}.
 *
 * The `:8097` WS worker is `count=1`, so the worker-local in-memory
 * {@see RateLimitProfiles::WS_CONNECT} limiter is already server-wide. Unlike the
 * HTTP surfaces (SV-4.15(f)/(g)), there is no HTTP response after the WS-upgrade
 * hook, so the central 429 mapping (SV-4.15(c)) does NOT apply: the check is
 * inline, keyed on the remote IP, runs BEFORE the auth gate, and on a trip
 * removes the pool wrapper + closes the connection WITHOUT throwing (a throw out
 * of onWebSocketConnect would trigger Workerman's Worker::stopAll()).
 *
 * @covers \Phlix\Server\WebSocket\WebSocketServer
 */
final class WsConnectRateLimitTest extends TestCase
{
    private JwtHandler $jwtHandler;
    private string $jwtSecret = 'test-secret-key-for-ws-connect-rate-limit';

    protected function setUp(): void
    {
        parent::setUp();
        ConnectionPool::getInstance()->clear();
        $this->jwtHandler = new JwtHandler($this->jwtSecret, 'HS256', 3600, 604800);
    }

    /**
     * A recording {@see RateLimiterInterface} double: captures each key passed to
     * {@see hit()} and reports a fixed limited/not-limited state.
     */
    private function makeLimiter(bool $limited): RateLimiterInterface
    {
        return new class ($limited) implements RateLimiterInterface {
            /** @var list<string> */
            public array $hits = [];

            public function __construct(private bool $limited)
            {
            }

            public function hit(string $key): RateLimitState
            {
                $this->hits[] = $key;

                return new RateLimitState(
                    count: $this->limited ? 31 : 1,
                    remaining: 0,
                    resetAt: time() + 30,
                    limited: $this->limited,
                    limit: 30,
                );
            }

            public function reset(string $key): void
            {
            }

            public function peek(string $key): RateLimitState
            {
                return new RateLimitState(0, 30, 0, false, 30);
            }
        };
    }

    /**
     * Creates a mock TcpConnection tracking close and reporting a fixed remote IP.
     *
     * @param array<string, bool> $callTracker Tracks which methods were called.
     * @return \PHPUnit\Framework\MockObject\MockObject&\Workerman\Connection\TcpConnection
     */
    private function createMockTcpConnection(
        string $remoteIp,
        array &$callTracker = [],
    ): \Workerman\Connection\TcpConnection|\PHPUnit\Framework\MockObject\MockObject {
        $callTracker = ['send' => false, 'close' => false];

        $mockConnection = $this->createMock(\Workerman\Connection\TcpConnection::class);
        $mockConnection->method('getRemoteIp')->willReturn($remoteIp);
        $mockConnection->method('send')->willReturnCallback(function () use (&$callTracker) {
            $callTracker['send'] = true;
        });
        $mockConnection->method('close')->willReturnCallback(function () use (&$callTracker) {
            $callTracker['close'] = true;
        });

        return $mockConnection;
    }

    /**
     * Builds a REAL parsed WS upgrade Request carrying `?token=<value>`.
     */
    private function makeRequest(?string $token): \Workerman\Protocols\Http\Request
    {
        $line = $token === null
            ? "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"
            : "GET /?token=" . $token . " HTTP/1.1\r\nHost: localhost\r\n\r\n";

        return new \Workerman\Protocols\Http\Request($line);
    }

    /**
     * Over-limit connect: the connection is removed from the pool and closed, and
     * auth is NEVER reached — a VALID token is presented (which WOULD authenticate
     * absent the throttle), yet the wrapper is rejected unauthenticated, proving
     * the limiter runs BEFORE the auth gate. The hook must not throw.
     */
    public function testOverLimitConnectRejectsBeforeAuth(): void
    {
        $config = ['host' => '0.0.0.0', 'port' => 8097, 'jwt_secret' => $this->jwtSecret];
        $server = new WebSocketServer($config);

        $limiter = $this->makeLimiter(true);
        $server->setWsConnectLimiter($limiter);

        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection('203.0.113.11', $callTracker);

        $server->onConnect($mockConnection);

        // Grab the wrapper BEFORE the handshake so we can inspect it after the
        // pool removal — a valid token would authenticate it if auth were reached.
        $pool = ConnectionPool::getInstance();
        $wrapper = $pool->all()[0];
        self::assertInstanceOf(Connection::class, $wrapper);

        $token = $this->jwtHandler->createAccessToken('user-should-not-auth');
        $server->onWebSocketConnect($mockConnection, $this->makeRequest($token));

        self::assertTrue($callTracker['close'], 'Over-limit connection must be closed');
        self::assertCount(0, $pool->all(), 'Over-limit connection must be removed from the pool');
        self::assertFalse(
            $wrapper->isAuthenticated(),
            'Auth must NOT run on an over-limit connection (the limiter gates before the auth path)',
        );
        // Keyed on the remote IP.
        self::assertSame(['ws_connect:203.0.113.11'], $limiter->hits);
    }

    /**
     * Under-limit connect: the hook proceeds to the normal auth flow — a valid
     * token authenticates the connection, which stays in the pool.
     */
    public function testUnderLimitConnectProceedsToAuth(): void
    {
        $config = ['host' => '0.0.0.0', 'port' => 8097, 'jwt_secret' => $this->jwtSecret];
        $server = new WebSocketServer($config);

        $limiter = $this->makeLimiter(false);
        $server->setWsConnectLimiter($limiter);

        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection('198.51.100.22', $callTracker);

        $server->onConnect($mockConnection);
        $token = $this->jwtHandler->createAccessToken('user-777');
        $server->onWebSocketConnect($mockConnection, $this->makeRequest($token));

        $pool = ConnectionPool::getInstance();
        $connections = $pool->all();
        self::assertCount(1, $connections, 'Under-limit connection must survive');

        $wrapper = $connections[0];
        self::assertInstanceOf(Connection::class, $wrapper);
        self::assertTrue($wrapper->isAuthenticated(), 'Under-limit connection must reach + pass auth');
        self::assertSame('user-777', $wrapper->getUserId());
        self::assertFalse($callTracker['close'], 'Under-limit connection must not be closed');
        self::assertSame(['ws_connect:198.51.100.22'], $limiter->hits);
    }

    /**
     * The throttle applies BEFORE the auth gate even on the anonymous/dev path
     * (no JWT secret configured): an over-limit token-less connect is still
     * rejected, keyed on the remote IP.
     */
    public function testOverLimitRejectsAnonymousDevConnectToo(): void
    {
        // No jwt_secret → authMiddleware stays null (anonymous connections).
        $config = ['host' => '0.0.0.0', 'port' => 8097];
        $server = new WebSocketServer($config);

        $limiter = $this->makeLimiter(true);
        $server->setWsConnectLimiter($limiter);

        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection('203.0.113.44', $callTracker);

        $server->onConnect($mockConnection);
        $server->onWebSocketConnect($mockConnection, $this->makeRequest(null));

        self::assertTrue($callTracker['close'], 'Over-limit anonymous connect must be closed');
        self::assertCount(0, ConnectionPool::getInstance()->all());
        self::assertSame(['ws_connect:203.0.113.44'], $limiter->hits);
    }

    /**
     * With no limiter injected (all direct-construction call sites and the
     * pre-SV-4.15(h) resident path), the connect hook applies no throttling: a
     * valid token still authenticates normally.
     */
    public function testNoLimiterLeavesConnectUnthrottled(): void
    {
        $config = ['host' => '0.0.0.0', 'port' => 8097, 'jwt_secret' => $this->jwtSecret];
        $server = new WebSocketServer($config);

        $callTracker = [];
        $mockConnection = $this->createMockTcpConnection('192.0.2.5', $callTracker);

        $server->onConnect($mockConnection);
        $token = $this->jwtHandler->createAccessToken('user-nolimit');
        $server->onWebSocketConnect($mockConnection, $this->makeRequest($token));

        $connections = ConnectionPool::getInstance()->all();
        self::assertCount(1, $connections);
        self::assertTrue($connections[0]->isAuthenticated());
        self::assertFalse($callTracker['close']);
    }
}
