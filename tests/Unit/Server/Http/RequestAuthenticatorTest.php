<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use Phlix\Auth\AuthManager;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Http\Response;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 *
 * SV-1.8 — CSRF Origin/Referer exact-match.
 *
 * These tests pin the CSRF protection applied to cookie-authenticated
 * state-changing requests. The critical guarantee is an EXACT host match
 * (no `str_ends_with` suffix bypass): `https://evil-example.com` must be
 * rejected for a server whose Host is `example.com`, and a host that merely
 * carries `example.com` as a suffix (`example.com.evil.com`) must be rejected
 * too. Port handling is strict — both-absent OR both-equal.
 *
 * Two layers are covered:
 *  1. {@see RequestAuthenticator::validateCsrfOrigin()} — the pure
 *     Origin/Referer validation (cases 1-10 in the plan).
 *  2. The gate in {@see HttpHandler::__invoke()} — a cookie-authenticated POST
 *     with a bad Origin returns 403 `csrf.invalid_origin`, while a
 *     bearer-authenticated POST bypasses the gate entirely.
 */
final class RequestAuthenticatorTest extends TestCase
{
    /**
     * A RequestAuthenticator whose AuthManager is a bare mock — the
     * validateCsrfOrigin() path never touches the AuthManager.
     */
    private function authenticator(): RequestAuthenticator
    {
        return new RequestAuthenticator($this->createMock(AuthManager::class));
    }

    /**
     * Build a Phlix Request with a method and a set of headers. Header lookups
     * in {@see Request::getHeader()} are case-insensitive; absent headers fall
     * through to $_SERVER (unset in the CLI test env), i.e. they read as null.
     *
     * @param array<string, string> $headers
     */
    private function request(string $method, array $headers): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->headers = $headers;

        return $request;
    }

    // --- authenticate() / isCookieAuthenticated() — the gate's collaborators -

    public function testAuthenticateReturnsFalseWithoutCredential(): void
    {
        $request = $this->request('POST', ['Host' => 'example.com']);

        self::assertFalse($this->authenticator()->authenticate($request));
        self::assertNull($request->userId);
    }

    public function testAuthenticateReturnsFalseForInvalidToken(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('validateAccessToken')->willReturn(null);
        $authenticator = new RequestAuthenticator($authManager);

        $request = $this->request('POST', ['Authorization' => 'Bearer bad-token']);

        self::assertFalse($authenticator->authenticate($request));
        self::assertNull($request->userId);
    }

    public function testAuthenticateResolvesBearerToken(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('validateAccessToken')->willReturn(['user_id' => 'u42']);
        $authenticator = new RequestAuthenticator($authManager);

        $request = $this->request('GET', ['Authorization' => 'Bearer good-token']);

        self::assertTrue($authenticator->authenticate($request));
        self::assertSame('u42', $request->userId);
    }

    public function testAuthenticateFallsBackToSessionCookie(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('validateAccessToken')->willReturn(['user_id' => 'u7']);
        $authenticator = new RequestAuthenticator($authManager);

        $request = $this->request('POST', ['Host' => 'example.com']);
        $request->cookies = ['phlix_session' => 'cookie-token'];

        self::assertTrue($authenticator->authenticate($request));
        self::assertSame('u7', $request->userId);
    }

    public function testIsCookieAuthenticatedFalseWithoutUserId(): void
    {
        $request = $this->request('POST', ['Host' => 'example.com']);

        self::assertFalse($this->authenticator()->isCookieAuthenticated($request));
    }

    public function testIsCookieAuthenticatedFalseWhenBearerPresent(): void
    {
        $request = $this->request('POST', ['Authorization' => 'Bearer good-token']);
        $request->userId = 'u1';

        // A resolved userId that came WITH a bearer token is not cookie auth.
        self::assertFalse($this->authenticator()->isCookieAuthenticated($request));
    }

    public function testIsCookieAuthenticatedTrueForCookieOnly(): void
    {
        $request = $this->request('POST', ['Host' => 'example.com']);
        $request->userId = 'u1';

        // A userId with no bearer token → resolved from the session cookie.
        self::assertTrue($this->authenticator()->isCookieAuthenticated($request));
    }

    // --- Case 1/2: suffix-bypass attacks are rejected -----------------------

    public function testSuffixAttackOriginRejected(): void
    {
        // The classic str_ends_with bypass: evil-example.com ends with
        // example.com but is a DIFFERENT origin — must be rejected.
        $request = $this->request('POST', [
            'Host' => 'example.com',
            'Origin' => 'https://evil-example.com',
        ]);

        self::assertFalse($this->authenticator()->validateCsrfOrigin($request));
    }

    public function testSuffixAttackOtherDirectionRejected(): void
    {
        // example.com.evil.com merely CONTAINS example.com as a label prefix —
        // an exact host compare rejects it.
        $request = $this->request('POST', [
            'Host' => 'example.com',
            'Origin' => 'https://example.com.evil.com',
        ]);

        self::assertFalse($this->authenticator()->validateCsrfOrigin($request));
    }

    // --- Case 3: exact same-origin is accepted ------------------------------

    public function testExactSameOriginAccepted(): void
    {
        $request = $this->request('POST', [
            'Host' => 'example.com',
            'Origin' => 'https://example.com',
        ]);

        self::assertTrue($this->authenticator()->validateCsrfOrigin($request));
    }

    // --- Case 4/5: port handling --------------------------------------------

    public function testMatchingPortAccepted(): void
    {
        $request = $this->request('POST', [
            'Host' => 'example.com:8096',
            'Origin' => 'http://example.com:8096',
        ]);

        self::assertTrue($this->authenticator()->validateCsrfOrigin($request));
    }

    public function testMismatchedPortRejected(): void
    {
        $request = $this->request('POST', [
            'Host' => 'example.com:8096',
            'Origin' => 'http://example.com:9000',
        ]);

        self::assertFalse($this->authenticator()->validateCsrfOrigin($request));
    }

    // --- Case 6: asymmetric port (one side has a port) is rejected both ways -

    public function testAsymmetricPortOriginHasPortRejected(): void
    {
        // Host has no port, Origin does → reject.
        $request = $this->request('POST', [
            'Host' => 'example.com',
            'Origin' => 'http://example.com:8096',
        ]);

        self::assertFalse($this->authenticator()->validateCsrfOrigin($request));
    }

    public function testAsymmetricPortHostHasPortRejected(): void
    {
        // Host has a port, Origin does not → reject.
        $request = $this->request('POST', [
            'Host' => 'example.com:8096',
            'Origin' => 'http://example.com',
        ]);

        self::assertFalse($this->authenticator()->validateCsrfOrigin($request));
    }

    // --- Case 7: Referer is enforced identically to Origin ------------------

    public function testRefererSuffixAttackRejected(): void
    {
        $request = $this->request('POST', [
            'Host' => 'example.com',
            'Referer' => 'https://evil-example.com/some/path',
        ]);

        self::assertFalse($this->authenticator()->validateCsrfOrigin($request));
    }

    public function testRefererExactMatchAccepted(): void
    {
        $request = $this->request('POST', [
            'Host' => 'example.com',
            'Referer' => 'https://example.com/some/path',
        ]);

        self::assertTrue($this->authenticator()->validateCsrfOrigin($request));
    }

    public function testUnparseableRefererRejected(): void
    {
        // A non-numeric port makes parse_url() return false → reject.
        $request = $this->request('POST', [
            'Host' => 'example.com',
            'Referer' => 'https://example.com:port',
        ]);

        self::assertFalse($this->authenticator()->validateCsrfOrigin($request));
    }

    // --- Case 8: both headers absent, or an Origin that fails parse_url ------

    public function testBothOriginAndRefererAbsentRejected(): void
    {
        // A legitimate browser always sends at least one of Origin/Referer on a
        // state-changing request; neither present → reject.
        $request = $this->request('POST', [
            'Host' => 'example.com',
        ]);

        self::assertFalse($this->authenticator()->validateCsrfOrigin($request));
    }

    public function testUnparseableOriginRejected(): void
    {
        // A non-numeric port makes parse_url() return false → reject.
        $request = $this->request('POST', [
            'Host' => 'example.com',
            'Origin' => 'https://example.com:port',
        ]);

        self::assertFalse($this->authenticator()->validateCsrfOrigin($request));
    }

    // --- Case 9: Origin 'null'/'' skipped, but Referer still enforced --------

    public function testOriginNullSkippedButRefererEnforcedAccepts(): void
    {
        // Origin: null (privacy-sensitive contexts / redirects) is skipped, so
        // the Referer becomes authoritative — a matching Referer passes.
        $request = $this->request('POST', [
            'Host' => 'example.com',
            'Origin' => 'null',
            'Referer' => 'https://example.com/x',
        ]);

        self::assertTrue($this->authenticator()->validateCsrfOrigin($request));
    }

    public function testOriginNullSkippedButRefererEnforcedRejects(): void
    {
        // Origin: null is skipped but the Referer is still validated — a
        // mismatched Referer must still be rejected (no free pass from 'null').
        $request = $this->request('POST', [
            'Host' => 'example.com',
            'Origin' => 'null',
            'Referer' => 'https://evil-example.com/x',
        ]);

        self::assertFalse($this->authenticator()->validateCsrfOrigin($request));
    }

    public function testOriginEmptySkippedButRefererEnforcedAccepts(): void
    {
        $request = $this->request('POST', [
            'Host' => 'example.com',
            'Origin' => '',
            'Referer' => 'https://example.com/x',
        ]);

        self::assertTrue($this->authenticator()->validateCsrfOrigin($request));
    }

    public function testOriginEmptySkippedButRefererEnforcedRejects(): void
    {
        $request = $this->request('POST', [
            'Host' => 'example.com',
            'Origin' => '',
            'Referer' => 'https://evil-example.com/x',
        ]);

        self::assertFalse($this->authenticator()->validateCsrfOrigin($request));
    }

    public function testMalformedServerHostFailsSafe(): void
    {
        // A Host header with a non-numeric port makes parse_url() return false;
        // parseHostHeader() then falls back to treating the whole string as the
        // host, which cannot match a clean Origin host → rejected (fail-safe).
        $request = $this->request('POST', [
            'Host' => 'example.com:port',
            'Origin' => 'https://example.com',
        ]);

        self::assertFalse($this->authenticator()->validateCsrfOrigin($request));
    }

    // --- Case 10: safe methods are never CSRF-checked -----------------------

    /**
     * @dataProvider safeMethodProvider
     */
    public function testSafeMethodsAllowedRegardlessOfOrigin(string $method): void
    {
        // GET/HEAD/OPTIONS are not state-changing — a cross-origin value must
        // not cause a rejection.
        $request = $this->request($method, [
            'Host' => 'example.com',
            'Origin' => 'https://evil-example.com',
        ]);

        self::assertTrue($this->authenticator()->validateCsrfOrigin($request));
    }

    /** @return array<string, array{0: string}> */
    public static function safeMethodProvider(): array
    {
        return [
            'GET' => ['GET'],
            'HEAD' => ['HEAD'],
            'OPTIONS' => ['OPTIONS'],
        ];
    }

    // --- Case 11: the HttpHandler gate composes auth + CSRF -----------------

    /**
     * Capture the WorkermanResponse a mocked TcpConnection is asked to send
     * when the given raw HTTP request runs through {@see HttpHandler::__invoke}.
     *
     * @param callable(ContainerInterface): void $configureContainer
     */
    private function invokeHandler(
        WorkermanRequest $wr,
        Application $application,
        callable $configureContainer,
    ): WorkermanResponse {
        $authManager = $this->createMock(AuthManager::class);
        // Any resolved token maps to a valid user — enough for the cookie/bearer
        // auth resolution the gate depends on.
        $authManager->method('validateAccessToken')->willReturn(['user_id' => 'u1']);
        $authenticator = new RequestAuthenticator($authManager);

        $container = $this->createMock(ContainerInterface::class);
        $configureContainer($container);

        $handler = new HttpHandler(
            $container,
            $authenticator,
            '/nonexistent/public',
            $application,
            null,
        );

        $captured = [];
        $conn = $this->createMock(TcpConnection::class);
        $conn->bytesRead = 0;
        $conn->bytesWritten = 0;
        $conn->method('send')->willReturnCallback(
            static function (mixed $response) use (&$captured): bool {
                $captured[] = $response;

                return true;
            }
        );

        $handler->__invoke($conn, $wr);

        self::assertCount(1, $captured, 'the handler must send exactly one response');
        $response = $captured[0];
        self::assertInstanceOf(WorkermanResponse::class, $response);

        return $response;
    }

    public function testGateRejectsCookieAuthPostWithBadOrigin(): void
    {
        // A cookie-authenticated POST with a cross-origin (suffix-bypass) Origin
        // is exactly the CSRF vector — the gate returns 403 csrf.invalid_origin.
        $wr = new WorkermanRequest(
            "POST /api/v1/media/abc/favorite HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Cookie: phlix_session=valid-session-token\r\n"
            . "Origin: https://evil-localhost\r\n"
            . "\r\n"
        );

        // The 403 path returns before any container->get, so the container is
        // never consulted.
        $response = $this->invokeHandler(
            $wr,
            $this->createMock(Application::class),
            static function (ContainerInterface $container): void {
                // no services resolved on the 403 path
                // S128: invokeHandler() hands this closure a PHPUnit MockObject, so
                // ->method() exists at run time, but the parameter can only be typed as
                // the interface the production code sees. A docblock on a closure passed
                // as an argument is not read here, and this repo has no
                // phpstan/phpstan-phpunit to narrow it. Per-line, with the identifier, so
                // it self-clears if either of those changes.
                // @phpstan-ignore method.notFound
                $container->method('get')->willReturn(null);
            },
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('csrf.invalid_origin', $response->rawBody());
    }

    public function testGateBypassedForBearerAuthPostWithBadOrigin(): void
    {
        // The SAME bad Origin on a BEARER-authenticated POST must NOT be gated:
        // browsers never auto-attach the Authorization header cross-origin, so
        // bearer requests are not CSRF-exposed. The request flows to dispatch.
        $application = $this->createMock(Application::class);
        $application->method('dispatch')->willReturn(
            (new Response())->status(200)->json(['ok' => true])
        );

        // SegmentProcessRegistry is final (cannot be doubled); a real instance
        // is inert here — armDirectCancelHook only captures it in the onClose
        // closure, which this test never fires.
        $registry = new SegmentProcessRegistry();

        $wr = new WorkermanRequest(
            "POST /api/v1/media/abc/favorite HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Authorization: Bearer some-bearer-token\r\n"
            . "Origin: https://evil-localhost\r\n"
            . "\r\n"
        );

        $response = $this->invokeHandler(
            $wr,
            $application,
            static function (ContainerInterface $container) use ($registry): void {
                // armDirectCancelHook resolves the segment registry before dispatch.
                // See the 403-path closure above — a MockObject typed as the interface.
                // @phpstan-ignore method.notFound
                $container->method('get')->willReturnCallback(
                    static fn (string $id): mixed =>
                        $id === SegmentProcessRegistry::class ? $registry : null
                );
            },
        );

        // Reached dispatch (200), NOT the 403 CSRF gate.
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('csrf.invalid_origin', $response->rawBody());
    }
}
