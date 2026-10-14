<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\JwtHandler;
use Phlix\Server\WebSocket\Connection;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\SyncPlayAuthMiddleware;
use Workerman\Connection\TcpConnection;

/**
 * R17-02 pins: the WebSocket handshake gate accepts ONLY access tokens.
 *
 * JwtHandler::validateToken() verifies signature and expiry but is type-blind —
 * the refresh token (7-day TTL) is a signing-valid, unexpired credential. Before
 * this guard, a stolen refresh token — minted for the REST refresh rail only —
 * could authenticate a live SyncPlay WebSocket session for a full week. Every
 * validation rail in SyncPlayAuthMiddleware now demands `type === 'access'`.
 */
final class WsAccessTokenTypeTest extends TestCase
{
    private const SECRET = 'r17-02-test-secret';

    /** @var array<int, \Workerman\Worker> */
    private array $savedWorkers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedWorkers = \Workerman\Worker::getAllWorkers();
        ConnectionPool::getInstance()->clear();
    }

    protected function tearDown(): void
    {
        $workers = new \ReflectionProperty(\Workerman\Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);
        unset($_GET['token'], $_GET['syncplay_user_id']);
        // Un-pool (remove() also un-indexes userId/sessionId), then the singleton
        // reset guarantees no cross-test residue under random execution order.
        foreach ($this->pooledIds as $id) {
            ConnectionPool::getInstance()->remove($id);
        }
        $this->pooledIds = [];
        ConnectionPool::getInstance()->clear();
        parent::tearDown();
    }

    private function middleware(bool $requireAuth = true): SyncPlayAuthMiddleware
    {
        return new SyncPlayAuthMiddleware(self::SECRET, $requireAuth);
    }

    private function accessJwt(string $userId = 'user-1'): string
    {
        return (new JwtHandler(self::SECRET))->createAccessToken($userId);
    }

    private function refreshJwt(string $userId = 'user-1'): string
    {
        return (new JwtHandler(self::SECRET))->createRefreshToken($userId);
    }

    /** @var list<string> wrapper ids to un-pool in tearDown (mirrors server lifecycle) */
    private array $pooledIds = [];

    /**
     * Build a pooled Connection wrapper, exactly as onConnect() does before the
     * handshake gate authenticates it.
     */
    private function wrap(): Connection
    {
        $tcp = $this->createMock(TcpConnection::class);
        $tcp->method('getRemoteIp')->willReturn('203.0.113.9');

        $connection = new Connection($tcp);
        ConnectionPool::getInstance()->add($connection);
        $this->pooledIds[] = $connection->getId();

        return $connection;
    }

    /**
     * The wired handshake rail: a refresh token must NOT authenticate.
     */
    public function testAuthenticateConnectionRejectsRefreshToken(): void
    {
        $connection = $this->wrap();

        $ok = $this->middleware()->authenticateConnection($connection, $this->refreshJwt());

        self::assertFalse($ok, 'a refresh token must never authenticate the WS handshake');
        self::assertFalse($connection->isAuthenticated());
        self::assertNull($connection->getUserId());
    }

    /**
     * Control: the same subject with a real access token still authenticates —
     * the guard rejects the TYPE, not the user.
     */
    public function testAuthenticateConnectionStillAcceptsAccessToken(): void
    {
        $connection = $this->wrap();

        $ok = $this->middleware()->authenticateConnection($connection, $this->accessJwt());

        self::assertTrue($ok);
        self::assertTrue($connection->isAuthenticated());
        self::assertSame('user-1', $connection->getUserId());
    }

    /**
     * The static rail carries the identical law (it is public API; a caller must
     * not get a "valid" verdict for a refresh token here).
     */
    public function testValidateTokenStaticRejectsRefreshToken(): void
    {
        self::assertNull(
            SyncPlayAuthMiddleware::validateTokenStatic($this->refreshJwt(), self::SECRET),
            'validateTokenStatic must answer null for non-access tokens',
        );
        self::assertSame(
            ['sub' => 'user-1'],
            SyncPlayAuthMiddleware::validateTokenStatic($this->accessJwt(), self::SECRET),
        );
    }

    /**
     * The deprecated TCP-accept rail keeps the same guard: a refresh token is
     * closed with the invalid-token code, never stashed into $_GET.
     */
    public function testLegacyOnConnectClosesOnRefreshToken(): void
    {
        $closedWith = [];
        $tcp = $this->createMock(TcpConnection::class);
        $tcp->method('getRemoteIp')->willReturn('203.0.113.9');
        $tcp->method('close')->willReturnCallback(
            function (?string $reason = null) use (&$closedWith): void {
                $closedWith[] = $reason;
            }
        );

        $_GET['token'] = $this->refreshJwt();
        $this->middleware()->onConnect($tcp);

        self::assertNotEmpty($closedWith, 'refresh token must be closed on the legacy rail');
        self::assertArrayNotHasKey('syncplay_user_id', $_GET);
    }
}
