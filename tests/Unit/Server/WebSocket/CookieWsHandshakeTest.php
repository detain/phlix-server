<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\JwtHandler;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\SyncPlayAuthMiddleware;
use Phlix\Server\WebSocket\WebSocketServer;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

/**
 * CP-006 (COOKIE-DESIGN.md §2.3): the `:8097` WebSocket handshake gains the
 * `phlix_session` cookie as a THIRD credential carrier — priority
 * bearer > cookie > query, the cookie admitted only under the state-changing
 * request-origin law the HTTP rails enforce, and a cookie-authenticated
 * handshake answers NO bearer marker on the 101.
 *
 * Two layers are pinned: the pure resolver verdicts (carrier arithmetic,
 * no sockets) and the wired hook (WebSocketServer::onWebSocketConnect over
 * REAL parsed upgrade Requests — the harness idiom WsAuthenticationTest
 * established, because Workerman's Request collides with PHPUnit mocks).
 *
 * Defect-inheritance law (estate-burned class): every guard limb carries a
 * uniquely convicting leg; belt legs kept green at the parent are disclosed
 * in the register's mutation matrix, not silently counted as convictions.
 */
final class CookieWsHandshakeTest extends TestCase
{
    private JwtHandler $jwtHandler;
    private string $jwtSecret = 'cookie-ws-handshake-secret-256bit-x';

    protected function setUp(): void
    {
        parent::setUp();
        ConnectionPool::getInstance()->clear();
        $this->jwtHandler = new JwtHandler($this->jwtSecret, 'HS256', 3600, 604800);
    }

    protected function tearDown(): void
    {
        ConnectionPool::getInstance()->clear();
        parent::tearDown();
    }

    /**
     * @return array{token: ?string, carrier: string, mismatch: bool}
     */
    private function resolve(
        ?string $subprotocol,
        ?string $query,
        ?string $cookie = null,
        bool $allowed = false
    ): array {
        return SyncPlayAuthMiddleware::resolveHandshakeToken($subprotocol, $query, $cookie, $allowed);
    }

    // --- pure resolver arms (carrier arithmetic) --------------------------

    public function test_resolverPrefersBearerOverAdmittedCookie(): void
    {
        // §2.3 priority head: an explicit subprotocol credential outranks the
        // ambient jar; the jar never participates in the mismatch reject
        // (a stale cookie is not a presented-then-contradicted credential).
        $bearer = $this->jwtHandler->createAccessToken('user-bearer');
        $verdict = $this->resolve('bearer, ' . $bearer, null, 'stale-jar-value', true);

        $this->assertSame('bearer', $verdict['carrier']);
        $this->assertSame($bearer, $verdict['token']);
        $this->assertFalse($verdict['mismatch']);
    }

    public function test_resolverAdmitsSameSiteCookieAsThirdCarrier(): void
    {
        $verdict = $this->resolve(null, null, 'jar-session-token', true);

        $this->assertSame('cookie', $verdict['carrier']);
        $this->assertSame('jar-session-token', $verdict['token']);
        $this->assertFalse($verdict['mismatch']);
    }

    public function test_resolverRefusesDisallowedCookieAndFallsToQuery(): void
    {
        // A REFUSED ambient value is not a credential the client is entitled
        // to be authenticated as — but it must not BLOCK the legacy carrier
        // either: the query token is still explicitly presented.
        $verdict = $this->resolve(null, 'query-token', 'cross-site-jar', false);

        $this->assertSame('query', $verdict['carrier']);
        $this->assertSame('query-token', $verdict['token']);
    }

    public function test_resolverRefusedCookieAloneCarriesNoCredential(): void
    {
        $verdict = $this->resolve(null, null, 'refused-jar', false);

        $this->assertSame('none', $verdict['carrier']);
        $this->assertNull($verdict['token']);
    }

    public function test_cookieOutranksLegacyQueryWhenAdmitted(): void
    {
        // §2.3 bearer > cookie > query: once the jar passes the origin law it
        // outranks the URL-borne credential — the card law "query fallback
        // must not silently outrank cookie" pinned as an ordering assertion.
        $verdict = $this->resolve(null, 'query-token', 'admitted-jar', true);

        $this->assertSame('cookie', $verdict['carrier']);
        $this->assertSame('admitted-jar', $verdict['token']);
    }

    public function test_emptyCookieCountsAsAbsent(): void
    {
        $verdict = $this->resolve(null, null, '', true);

        $this->assertSame('none', $verdict['carrier']);
        $this->assertNull($verdict['token']);
    }

    public function test_bearerQueryMismatchLawStillHeadsOverTheCookieCarrier(): void
    {
        // The two EXPLICIT carriers keep their loud-disagreement reject
        // exactly as before CP-006, even while a viable jar rides along: the
        // half-migration check never softens because a third carrier exists.
        $verdict = $this->resolve('bearer, token-a', 'token-b', 'admitted-jar', true);

        $this->assertTrue($verdict['mismatch']);
        $this->assertNull($verdict['token']);
        $this->assertSame('none', $verdict['carrier']);
    }

    // --- wired hook arms (WebSocketServer::onWebSocketConnect) ------------

    /**
     * @param array<string, string> $extraHeaders
     */
    private function makeHandshakeRequest(
        ?string $queryToken = null,
        ?string $subprotocol = null,
        ?string $sessionCookie = null,
        array $extraHeaders = []
    ): Request {
        $query = $queryToken === null ? '' : '?token=' . $queryToken;
        $raw = "GET /syncplay{$query} HTTP/1.1\r\nHost: localhost\r\n";
        if ($subprotocol !== null) {
            $raw .= "Sec-WebSocket-Protocol: {$subprotocol}\r\n";
        }
        if ($sessionCookie !== null) {
            $raw .= 'Cookie: ' . AuthController::SESSION_COOKIE . '=' . $sessionCookie . "\r\n";
        }
        foreach ($extraHeaders as $name => $value) {
            $raw .= $name . ': ' . $value . "\r\n";
        }

        return new Request($raw . "\r\n");
    }

    /**
     * @return array{host: string, port: int, jwt_secret: string}
     */
    private function authConfig(): array
    {
        return ['host' => '0.0.0.0', 'port' => 8097, 'jwt_secret' => $this->jwtSecret];
    }

    /**
     * @return TcpConnection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function mockConnection(array &$callTracker = []): TcpConnection
    {
        $mock = $this->createMock(TcpConnection::class);
        $mock->method('close')->willReturnCallback(function () use (&$callTracker): void {
            $callTracker['close'] = true;
        });
        return $mock;
    }

    public function test_sameSiteCookieHandshakeAuthenticatesWithoutBearerMarkerEcho(): void
    {
        // The §2.3 centerpiece: jar + same-origin Origin authenticates, and
        // the 101 answers NO bearer marker (the marker law stays with the
        // carrier actually accepted). Strip the echo suppression → red.
        $server = new WebSocketServer($this->authConfig());
        $tracker = [];
        $connection = $this->mockConnection($tracker);
        $jar = $this->jwtHandler->createAccessToken('user-jar');

        $server->onConnect($connection);
        $server->onWebSocketConnect(
            $connection,
            $this->makeHandshakeRequest(null, 'bearer', $jar, ['Origin' => 'http://localhost'])
        );

        $connections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $connections);
        $this->assertTrue($connections[0]->isAuthenticated());
        $this->assertSame('user-jar', $connections[0]->getUserId());
        $this->assertArrayNotHasKey('close', $tracker, 'The accepted jar handshake must not be closed.');
        $this->assertSame(
            [],
            $connection->headers,
            'A cookie-authenticated handshake answers no bearer marker (§2.3 verbatim).'
        );
    }

    public function test_crossOriginCookieHandshakeIsRefused(): void
    {
        // Foreign Origin + jar (no explicit credential): the origin law
        // refuses the ambient carrier and nothing else remains — reject.
        $server = new WebSocketServer($this->authConfig());
        $tracker = [];
        $connection = $this->mockConnection($tracker);

        $server->onConnect($connection);
        $server->onWebSocketConnect(
            $connection,
            $this->makeHandshakeRequest(
                null,
                null,
                $this->jwtHandler->createAccessToken('user-evil'),
                ['Origin' => 'http://evil.example']
            )
        );

        $this->assertTrue($tracker['close'] ?? false, 'A cross-origin jar must not authenticate.');
        foreach (ConnectionPool::getInstance()->all() as $pooled) {
            $this->assertFalse($pooled->isAuthenticated());
        }
    }

    public function test_secFetchSiteCrossSiteBeltRefusesTheCookieCarrier(): void
    {
        // FULLY matching Origin+Referer, but Sec-Fetch-Site: cross-site — the
        // CP-009 belt must act on the WS carrier gate too (it rides the same
        // CookiePolicy law). Dropping the belt (or using a laxer WS-only gate)
        // reddens exactly this leg.
        $server = new WebSocketServer($this->authConfig());
        $tracker = [];
        $connection = $this->mockConnection($tracker);

        $server->onConnect($connection);
        $server->onWebSocketConnect(
            $connection,
            $this->makeHandshakeRequest(
                null,
                null,
                $this->jwtHandler->createAccessToken('user-belt'),
                [
                    'Origin' => 'http://localhost',
                    'Referer' => 'http://localhost/app',
                    'Sec-Fetch-Site' => 'cross-site',
                ]
            )
        );

        $this->assertTrue($tracker['close'] ?? false, 'The belt must refuse the jar carrier like the HTTP rails.');
    }

    public function test_cookieWithNoOriginContextFailsClosed(): void
    {
        // The disclosed §2.3 mirror reading: both origin headers ABSENT is a
        // REFUSE in the mirrored law, and an ambient credential with no origin
        // context is precisely the smuggling shape. Native clients carry no
        // jar, so this arm is inert for them.
        $server = new WebSocketServer($this->authConfig());
        $tracker = [];
        $connection = $this->mockConnection($tracker);

        $server->onConnect($connection);
        $server->onWebSocketConnect(
            $connection,
            $this->makeHandshakeRequest(null, null, $this->jwtHandler->createAccessToken('user-smuggle'))
        );

        $this->assertTrue($tracker['close'] ?? false, 'A jar presented with no origin headers fails closed.');
    }

    public function test_refererOnlySameSiteCookieHandshakeAuthenticates(): void
    {
        // Referer-arm coverage on the WS wiring (browser requests that send
        // Referer without Origin still pass — the gate is the FULL mirrored
        // law, not an Origin-only approximation).
        $server = new WebSocketServer($this->authConfig());
        $tracker = [];
        $connection = $this->mockConnection($tracker);

        $server->onConnect($connection);
        $server->onWebSocketConnect(
            $connection,
            $this->makeHandshakeRequest(
                null,
                null,
                $this->jwtHandler->createAccessToken('user-ref'),
                ['Referer' => 'http://localhost/app']
            )
        );

        $connections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $connections);
        $this->assertSame('user-ref', $connections[0]->getUserId());
        $this->assertSame([], $connection->headers);
    }

    public function test_bearerAuthenticatesAlongsideRefusedJarAndStillEchoes(): void
    {
        // Carrier independence: a refused (cross-site) jar beside a VALID
        // bearer offer neither blocks the explicit carrier nor suppresses its
        // echo — the suppression belongs to the ACCEPTED carrier only. A
        // cookie-vs-bearer contradiction must NOT trip the mismatch reject.
        $server = new WebSocketServer($this->authConfig());
        $tracker = [];
        $connection = $this->mockConnection($tracker);
        $bearer = $this->jwtHandler->createAccessToken('user-beat-jar');

        $server->onConnect($connection);
        $server->onWebSocketConnect(
            $connection,
            $this->makeHandshakeRequest(
                null,
                'bearer, ' . $bearer,
                'stale-cross-site-jar',
                ['Origin' => 'http://evil.example']
            )
        );

        $connections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $connections);
        $this->assertSame('user-beat-jar', $connections[0]->getUserId());
        $this->assertSame(
            ['Sec-WebSocket-Protocol: bearer'],
            $connection->headers,
            'Bearer-accepted handshakes keep the marker echo even when a jar rode along.'
        );
    }
}
