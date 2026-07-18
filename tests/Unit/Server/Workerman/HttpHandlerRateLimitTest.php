<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Auth\DbLoginRateLimitStore;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\RateLimitException;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\MySQL\Connection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * @covers \Phlix\Server\Workerman\HttpHandler
 *
 * SV-4.15(c): the central {@see RateLimitException} -> HTTP 429 mapping on the
 * Workerman dispatch path, plus a regression for the latent login bug it fixes.
 *
 * Before this step, when the existing login limiter
 * ({@see DbLoginRateLimitStore} via {@see AuthManager}) tripped, no controller
 * caught the {@see RateLimitException} — it fell through to the handler's generic
 * `catch (\Throwable)` and became an HTTP 500 with NO `Retry-After`. These tests
 * pin that a limiter trip now produces the canonical 429 envelope (status +
 * `Retry-After` + `code=rate_limited`) built by the shared
 * {@see Application::rateLimitResponse()} helper.
 */
final class HttpHandlerRateLimitTest extends TestCase
{
    private ?string $savedRemoteAddr = null;

    protected function setUp(): void
    {
        $this->savedRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->savedRemoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->savedRemoteAddr;
        }
        // The direct-LAN cancel group is armed per request in __invoke; make sure
        // a throwing dispatch never leaks it into a sibling test.
        RequestContext::clearCancelGroup();
        parent::tearDown();
    }

    /**
     * A mock connection that records everything sent back to the client.
     *
     * @param list<mixed> $sent
     */
    private function makeConnection(array &$sent): TcpConnection
    {
        $sent = [];
        $conn = $this->createMock(TcpConnection::class);
        $conn->bytesRead = 0;
        $conn->bytesWritten = 0;
        $conn->method('send')->willReturnCallback(
            static function (mixed $data) use (&$sent): bool {
                $sent[] = $data;

                return true;
            }
        );

        return $conn;
    }

    /**
     * Build the handler exactly as start.php does, with a container that only
     * knows how to resolve the SegmentProcessRegistry armDirectCancelHook needs
     * (every request arms the direct-LAN disconnect hook before dispatch).
     */
    private function makeHandler(Application $application): \Phlix\Server\Workerman\HttpHandler
    {
        // SegmentProcessRegistry is final; armDirectCancelHook only stores it in
        // an onClose closure that is never fired here, so a real (never-signalled)
        // instance with default no-op collaborators is all that is needed.
        $registry = new SegmentProcessRegistry();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($registry): mixed {
                if ($id === SegmentProcessRegistry::class) {
                    return $registry;
                }
                throw new \RuntimeException('unexpected container get: ' . $id);
            }
        );
        $container->method('has')->willReturn(true);

        return new \Phlix\Server\Workerman\HttpHandler(
            $container,
            new RequestAuthenticator($this->createMock(AuthManager::class)),
            '/nonexistent/public',
            $application,
            null,
        );
    }

    /**
     * Assert a captured send is the canonical 429 envelope.
     */
    private function assertRateLimited(mixed $sent): void
    {
        self::assertInstanceOf(WorkermanResponse::class, $sent);
        self::assertSame(429, $sent->getStatusCode());

        $retryAfter = $sent->getHeader('Retry-After');
        self::assertIsString($retryAfter);
        self::assertMatchesRegularExpression('/^\d+$/', $retryAfter, 'Retry-After must be a non-negative integer.');

        /** @var array{error?: string, code?: string} $body */
        $body = json_decode($sent->rawBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Too Many Requests', $body['error'] ?? null);
        self::assertSame('rate_limited', $body['code'] ?? null);
    }

    // --- central catch: any RateLimitException out of dispatch -> 429 ---------

    public function testDispatchThrowingRateLimitExceptionIsMappedTo429(): void
    {
        $application = $this->createMock(Application::class);
        $application->method('dispatch')->willReturnCallback(
            static function (): never {
                throw new RateLimitException(resetAt: time() + 90, remaining: 0);
            }
        );

        $handler = $this->makeHandler($application);
        $wr = new WorkermanRequest("GET /api/v1/media/facets HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $sent = [];
        $handler->__invoke($this->makeConnection($sent), $wr);

        self::assertCount(1, $sent, 'exactly one response is sent');
        $this->assertRateLimited($sent[0]);

        // The Retry-After reflects the window (~90s), not a fixed constant.
        self::assertInstanceOf(WorkermanResponse::class, $sent[0]);
        $retryAfter = (int) $sent[0]->getHeader('Retry-After');
        self::assertGreaterThanOrEqual(88, $retryAfter);
        self::assertLessThanOrEqual(90, $retryAfter);
    }

    /**
     * SV-4.15 F4: the 429 branch must run through the SAME CORS + security-header
     * decoration as every other branch. A cross-origin XHR needs the CORS headers
     * to even READ the 429 (and its Retry-After); without them the browser
     * surfaces an opaque network error instead of the rate-limit signal.
     */
    public function testRateLimited429CarriesCorsAndSecurityHeaders(): void
    {
        $origin = 'https://app.example.com';
        $savedCors = getenv('CORS_ALLOWED_ORIGINS');
        $savedCorsPhlix = getenv('PHLIX_CORS_ALLOWED_ORIGINS');
        putenv('PHLIX_CORS_ALLOWED_ORIGINS=' . $origin);

        try {
            $application = $this->createMock(Application::class);
            $application->method('dispatch')->willReturnCallback(
                static function (): never {
                    throw new RateLimitException(resetAt: time() + 30, remaining: 0);
                }
            );

            $handler = $this->makeHandler($application);
            $raw = "GET /api/v1/media/facets HTTP/1.1\r\nHost: localhost\r\n"
                . "Origin: {$origin}\r\n\r\n";
            $wr = new WorkermanRequest($raw);

            $sent = [];
            $handler->__invoke($this->makeConnection($sent), $wr);

            self::assertCount(1, $sent);
            $this->assertRateLimited($sent[0]);

            self::assertInstanceOf(WorkermanResponse::class, $sent[0]);
            // CORS: the allowlisted origin is reflected (never '*'), so the browser
            // lets the page read the 429.
            self::assertSame($origin, $sent[0]->getHeader('Access-Control-Allow-Origin'));
            // Security headers: applied on the 429 like every other branch.
            self::assertSame('nosniff', $sent[0]->getHeader('X-Content-Type-Options'));
        } finally {
            if ($savedCorsPhlix === false) {
                putenv('PHLIX_CORS_ALLOWED_ORIGINS');
            } else {
                putenv('PHLIX_CORS_ALLOWED_ORIGINS=' . $savedCorsPhlix);
            }
            if ($savedCors === false) {
                putenv('CORS_ALLOWED_ORIGINS');
            } else {
                putenv('CORS_ALLOWED_ORIGINS=' . $savedCors);
            }
        }
    }

    // --- regression: the login limiter trip returns 429, NOT 500 --------------

    public function testLoginLimiterTripReturns429NotInternalServerError(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';

        // Real login stack wired to a DB-backed limiter that reports OVER-limit:
        // AuthManager::login -> checkRateLimit -> DbLoginRateLimitStore::check
        // throws RateLimitException before any user lookup. AuthController::login
        // does NOT catch it, so it propagates out of dispatch — exactly the path
        // that used to 500.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['attempts' => 999, 'reset_at' => time() + 600],
        ]);
        $store = new DbLoginRateLimitStore($db);

        $authManager = new AuthManager(
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $this->createMock(AuditLogger::class),
            null,
            null,
            null,
            null,
            null,
            null,
            $store,
        );
        $authController = new AuthController($authManager);

        // The Application router would dispatch POST /api/v1/auth/login to the
        // controller; stand that in with a callback that runs the REAL controller.
        $application = $this->createMock(Application::class);
        $application->method('dispatch')->willReturnCallback(
            static fn (Request $request): \Phlix\Server\Http\Response => $authController->login($request, [])
        );

        $handler = $this->makeHandler($application);

        $payload = json_encode(['username' => 'alice', 'password' => 'hunter2'], JSON_THROW_ON_ERROR);
        $raw = "POST /api/v1/auth/login HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n\r\n" . $payload;
        $wr = new WorkermanRequest($raw);

        $sent = [];
        $handler->__invoke($this->makeConnection($sent), $wr);

        self::assertCount(1, $sent);
        self::assertInstanceOf(WorkermanResponse::class, $sent[0]);
        self::assertNotSame(500, $sent[0]->getStatusCode(), 'the login-limiter trip must NOT be a 500.');
        $this->assertRateLimited($sent[0]);
    }

    /**
     * The controller itself must let the RateLimitException escape (it only
     * catches AccountInactiveException / InvalidArgumentException). This pins the
     * "no local catch" assumption the central mapping depends on.
     */
    public function testAuthControllerLoginDoesNotSwallowRateLimitException(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['attempts' => 999, 'reset_at' => time() + 600],
        ]);
        $store = new DbLoginRateLimitStore($db);

        $authManager = new AuthManager(
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $this->createMock(AuditLogger::class),
            null,
            null,
            null,
            null,
            null,
            null,
            $store,
        );
        $controller = new AuthController($authManager);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/login';
        $request->body = ['username' => 'alice', 'password' => 'hunter2'];

        $this->expectException(RateLimitException::class);
        $controller->login($request, []);
    }
}
