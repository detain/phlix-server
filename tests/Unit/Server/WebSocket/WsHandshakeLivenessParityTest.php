<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Server\WebSocket\Connection;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\SyncPlayAuthMiddleware;
use Workerman\Connection\TcpConnection;

/**
 * M35 (seceng2 M1) pins: the WS :8097 handshake gate enforces the SAME
 * user-liveness law every HTTP request passes through
 * {@see AuthManager::validateAccessToken()} —
 *
 *  - S1: a non-`active` status rejects an otherwise-valid access token;
 *  - H-1: a deleted account (row gone ⇒ null status) rejects;
 *  - M-1: a token minted at/before the revocation watermark (logout-everywhere,
 *    password change, admin bump) rejects;
 *  - an active, unrevoked identity still authenticates (control).
 *
 * Before this cure the handshake gate was crypto + `type === 'access'` only
 * (its own docblock disclosed the deferral on a premise the fork disproves:
 * `start.php` §4a already builds the container — UserRepository included —
 * inside the :8097 worker), so a purged identity stayed fully live on :8097
 * for the token's whole TTL while the same token died on every HTTP request.
 *
 * The parity test pairs the two transports over the same user matrix: they
 * cannot diverge because the WS rail delegates to the identical AuthManager
 * call the HTTP rail makes ({@see \Phlix\Server\Http\RequestAuthenticator}).
 *
 * Honest bound (pinned by law, not by reaper): a connection accepted while
 * live and deactivated afterwards stays open until disconnect — HTTP re-checks
 * per request, the WS gate checks per handshake. See the middleware docblock.
 */
final class WsHandshakeLivenessParityTest extends TestCase
{
    private const SECRET = 'm35-liveness-parity-secret';

    /** @var array<int, \Workerman\Worker> */
    private array $savedWorkers = [];

    /** @var list<string> wrapper ids to un-pool in tearDown (mirrors server lifecycle) */
    private array $pooledIds = [];

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
        foreach ($this->pooledIds as $id) {
            ConnectionPool::getInstance()->remove($id);
        }
        $this->pooledIds = [];
        ConnectionPool::getInstance()->clear();
        parent::tearDown();
    }

    private function silentLogger(): StructuredLogger
    {
        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => sys_get_temp_dir() . '/phlix_m35_' . uniqid('', true) . '.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ]);
    }

    private function jwt(): JwtHandler
    {
        return new JwtHandler(self::SECRET, 'HS256', 3600, 604800);
    }

    /**
     * AuthManager over a scripted auth-state answer — the exact collaborator
     * shape HTTP's RequestAuthenticator consumes.
     *
     * @param array{status: ?string, tokensNotValidAfter: int}|null $authState
     */
    private function manager(?array $authState): AuthManager
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('getAuthState')->willReturn($authState);

        return new AuthManager(
            $repo,
            $this->jwt(),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
        );
    }

    /**
     * Build a pooled Connection wrapper, exactly as onConnect() does before the
     * handshake gate authenticates it.
     */
    private function wrap(): Connection
    {
        $tcp = $this->createMock(TcpConnection::class);
        $tcp->method('getRemoteIp')->willReturn('203.0.113.35');

        $connection = new Connection($tcp);
        ConnectionPool::getInstance()->add($connection);
        $this->pooledIds[] = $connection->getId();

        return $connection;
    }

    /**
     * @return array<string, array{0: array{status: ?string, tokensNotValidAfter: int}|null, 1: bool}>
     *         auth-state script => expected verdict (live on HTTP ⇒ live on WS).
     */
    public static function livenessMatrix(): array
    {
        return [
            'active, no watermark' => [['status' => 'active', 'tokensNotValidAfter' => 0], true],
            'disabled' => [['status' => 'disabled', 'tokensNotValidAfter' => 0], false],
            // H-1: row gone => getAuthState null => status null => reject.
            'deleted (no row)' => [null, false],
            // M-1: watermark set ahead of the token's iat (fresh `time()`).
            'revoked watermark' => [['status' => 'active', 'tokensNotValidAfter' => time() + 10], false],
        ];
    }

    /**
     * THE cure, per state: with the AuthManager seam wired (the resident
     * :8097 path), the handshake verdict equals the HTTP verdict. On the
     * pre-cure middleware this file's constructor seam silently arrived null
     * (PHP tolerates the extra argument), the disabled/deleted/revoked rows
     * authenticated, and every expect-false row ran RED — the measured
     * red-on-parent for M35.
     *
     * @dataProvider livenessMatrix
     */
    public function testHttpAndWsHandshakeVerdictsMatchOverTheLivenessMatrix(?array $authState, bool $expectLive): void
    {
        $manager = $this->manager($authState);
        $token = $this->jwt()->createAccessToken('user-m35');

        // HTTP side of the parity: the law itself.
        $httpLive = $manager->validateAccessToken($token) !== null;
        self::assertSame($expectLive, $httpLive, 'the matrix must describe AuthManager honestly');

        // WS side: same manager, same token, handshake gate.
        $connection = $this->wrap();
        $middleware = new SyncPlayAuthMiddleware(self::SECRET, true, $manager);

        $wsLive = $middleware->authenticateConnection($connection, $token);

        self::assertSame($httpLive, $wsLive, 'WS handshake must diverge from HTTP never');
        self::assertSame($expectLive, $connection->isAuthenticated());
        self::assertSame($expectLive ? 'user-m35' : null, $connection->getUserId());
    }

    /**
     * Deleted-account exploitation narrative, frozen as a test: the H-1 row.
     * Kept as its own case so the red-on-parent signature is unambiguous
     * ("a purged user still authenticated the handshake").
     */
    public function testHandshakeRefusesTokenOfDeletedAccountWhenLivenessSeamIsWired(): void
    {
        $connection = $this->wrap();
        $middleware = new SyncPlayAuthMiddleware(self::SECRET, true, $this->manager(null));

        self::assertFalse(
            $middleware->authenticateConnection($connection, $this->jwt()->createAccessToken('user-m35')),
            'a deleted account must not authenticate :8097 for its token TTL',
        );
        self::assertFalse($connection->isAuthenticated());
        self::assertNull($connection->getUserId());
    }

    /**
     * Uniform-refusal law: a liveness refusal is observably IDENTICAL to an
     * invalid-token refusal at the gate contract (false + untouched wrapper) —
     * the wired reject path in WebSocketServer::onWebSocketConnect() is the
     * single shared false→pre-101-close branch. No new wire codes, no new
     * close vocabulary.
     */
    public function testLivenessRefusalIsIndistinguishableFromInvalidTokenRefusal(): void
    {
        $manager = $this->manager(['status' => 'active', 'tokensNotValidAfter' => time() + 10]);
        $middleware = new SyncPlayAuthMiddleware(self::SECRET, true, $manager);

        $revoked = $this->wrap();
        $bogus = $this->wrap();

        $revokedVerdict = $middleware->authenticateConnection($revoked, $this->jwt()->createAccessToken('user-m35'));
        $bogusVerdict = $middleware->authenticateConnection($bogus, 'not-even-a-jwt');

        self::assertFalse($revokedVerdict);
        self::assertFalse($bogusVerdict);
        self::assertSame($revoked->isAuthenticated(), $bogus->isAuthenticated());
        self::assertSame($revoked->getUserId(), $bogus->getUserId());
    }

    /**
     * Control kept inside the parity file: the refusal is keyed on LIVENESS,
     * not on the user — the identical credential opens the gate the moment the
     * account is live again (admin re-activation), mirroring HTTP's 5s-cached
     * revival behavior rather than inventing a stricter bar.
     */
    public function testActiveIdentityWithValidTokenStillAuthenticatesThroughTheSeam(): void
    {
        $connection = $this->wrap();
        $middleware = new SyncPlayAuthMiddleware(
            self::SECRET,
            true,
            $this->manager(['status' => 'active', 'tokensNotValidAfter' => 0]),
        );

        self::assertTrue($middleware->authenticateConnection($connection, $this->jwt()->createAccessToken('user-m35')));
        self::assertTrue($connection->isAuthenticated());
        self::assertSame('user-m35', $connection->getUserId());
    }
}
