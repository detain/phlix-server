<?php

/**
 * Phlix media server component: Auth tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use Phlix\Auth\AuthManager;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Http\Response;
use Phlix\Server\Workerman\HttpHandler;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 * CP-008 (COOKIE-DESIGN.md §7): the CSRF belt as a DISPATCH-LAYER matrix —
 * verb × carrier × Origin-shape, executed through the REAL
 * {@see HttpHandler::__invoke} gate, not the primitives
 * ({@see \Phlix\Tests\Unit\Auth\CookiePolicyTest} owns the header-rule
 * table; this file owns the wire disposition the gate produces).
 *
 * Rows (§7 "verb×carrier×Origin incl Sec-Fetch belt"):
 *  - carrier ∈ {cookie jar, bearer (incl. the bearer+jar hybrid where the
 *    bearer heads and the gate must NOT engage), query/none (anonymous —
 *    the gate never sees these)};
 *  - origin shapes: same-site, foreign, belt (Sec-Fetch-Site: cross-site
 *    ALONGSIDE a same-site Origin — belt heads), absent-with-Host
 *    (fail-closed), literal 'null', unparseable — inputs that MEASURE
 *    parse_url() === false in this runtime (w31L1-R2 cure: round-1 shipped
 *    'http://[' on a false premise — PHP 8.3.6 parses it to scheme + host
 *    '[' — so the belt's parse-false MAJOR class now rides the two constants
 *    below, premise pinned by execution every run by
 *    testTheUnparseableFixtureValuesTrulyFailParseUrlInThisRuntime):
 *    these legs are paired with a same-shape-minus-the-defect control each,
 *    so an arm that would pass on both the refusal and the acceptance cannot
 *    exist here),
 *    port-discriminator (strict law: Origin without an explicit port
 *    against a Host that carries one REFUSES — no default-port
 *    normalization on this rail), referer-only same-site;
 *  - verbs: POST/PUT/DELETE/PATCH gated for cookie-carrier; GET gated
 *    ONLY on /auth/logout (the Application-registered `<a href>` arm);
 *    OPTIONS/HEAD exempt.
 *
 * Defect-inheritance law (estate-burned class): every refusal row is
 * paired with a named non-vacuous control (the minimal-delta request that
 * must reach dispatch), and every refusal answers the SAME byte-exact 403
 * disposition — one shared envelope, proven by full-wire equality across
 * arms, so a per-branch copy of the refusal cannot drift unnoticed.
 *
 * The harness is the S113 pattern (HttpHandlerHeadNoBodyTest): a real
 * HttpHandler over a recording TcpConnection double and a router-less
 * Application double whose dispatch answers 404 — "reached dispatch" and
 * "refused by the belt" are then wire-distinguishable by construction.
 */
final class CsrfMatrixTest extends TestCase
{
    /** The jar value the scripted AuthManager maps to a user. */
    private const COOKIE_TOKEN = 'csrf-cookie-token';

    /** The bearer value the scripted AuthManager maps to a user. */
    private const BEARER_TOKEN = 'csrf-bearer-token';

    /** The one envelope every refused arm ships (HttpHandler inline body). */
    private const REFUSAL_BODY = '{"error":"CSRF validation failed","code":"csrf.invalid_origin"}';

    /**
     * Origin fixture that MEASURES parse_url() === false on this runtime
     * (invalid non-numeric port). Replaces round-1's 'http://[' (parseable:
     * scheme + host '['). Premise pinned by execution below.
     */
    private const UNPARSEABLE_ORIGIN = 'http://example.com:port';

    /**
     * Referer fixture that MEASURES parse_url() === false on this runtime
     * (ambiguous multi-colon authority). Premise pinned by execution below.
     */
    private const UNPARSEABLE_REFERER = 'http://a:b:c:d';

    private string $publicRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        // serveStatic() must never shadow a matrix row: an empty public root
        // keeps every path on the authenticated-dispatch rail.
        $root = sys_get_temp_dir() . '/phlix-csrf-matrix-' . bin2hex(random_bytes(6));
        mkdir($root, 0o777, true);
        $this->publicRoot = (string) realpath($root);
    }

    protected function tearDown(): void
    {
        if ($this->publicRoot !== '' && is_dir($this->publicRoot)) {
            @rmdir($this->publicRoot);
        }
        parent::tearDown();
    }

    // ── the matrix ────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: bool}>
     *         [method, path, header lines, expectRefused]
     */
    public static function matrix(): array
    {
        $jar = 'Cookie: ' . AuthController::SESSION_COOKIE . '=' . self::COOKIE_TOKEN;
        $bearer = 'Authorization: Bearer ' . self::BEARER_TOKEN;
        $host = 'Host: localhost:8096';

        return [
            // cookie × POST: the shape §2.5 exists to refuse.
            'post cookie foreign origin' => ['POST', '/api/v1/users/me/settings', [$host, $jar, 'Origin: http://evil.example'], true],
            'post cookie same origin control' => ['POST', '/api/v1/users/me/settings', [$host, $jar, 'Origin: http://localhost:8096'], false],
            // Belt heads: a same-site Origin does NOT rescue a cross-site
            // classification from a trustworthy proxy signal.
            'post cookie belt cross-site despite same origin' => ['POST', '/api/v1/users/me/settings', [$host, $jar, 'Origin: http://localhost:8096', 'Sec-Fetch-Site: cross-site'], true],
            'post cookie same-site minus belt control' => ['POST', '/api/v1/users/me/settings', [$host, $jar, 'Origin: http://localhost:8096', 'Sec-Fetch-Site: same-origin'], false],
            // Fail-closed: no Origin AND no Referer, whatever the Host.
            'post cookie absent origin fails closed' => ['POST', '/api/v1/users/me/settings', [$host, $jar], true],
            // Sandbox/opaque-origin postings literalize 'null' — M-SERVER-76.
            'post cookie literal null origin' => ['POST', '/api/v1/users/me/settings', [$host, $jar, 'Origin: null'], true],
            // PARSE-FALSE CLASS (cured w31L1-R2 from measured red): round 1
            // claimed parse_url('http://[') === false; PHP 8.3.6 returns an
            // array for it (host '['), so those rows convicted the
            // host-mismatch leg and the parse-false arms were mutation-blind.
            // These constants genuinely fail parse_url() in this runtime —
            // premise pinned by testTheUnparseableFixtureValuesTrulyFailParseUrlInThisRuntime.
            // An unparseable Origin/Referer must REFUSE (fail-closed), never skip.
            'post cookie unparseable origin refuses' => ['POST', '/api/v1/users/me/settings', [$host, $jar, 'Origin: ' . self::UNPARSEABLE_ORIGIN], true],
            'post cookie unparseable referer refuses' => ['POST', '/api/v1/users/me/settings', [$host, $jar, 'Referer: ' . self::UNPARSEABLE_REFERER], true],
            // Strict host law: byte-exact host AND both-or-neither port.
            'post cookie origin portless against ported host' => ['POST', '/api/v1/users/me/settings', [$host, $jar, 'Origin: http://localhost'], true],
            'post cookie port parity control' => ['POST', '/api/v1/users/me/settings', [$host, $jar, 'Origin: http://localhost:8096'], false],
            'post cookie host portless origin portless' => [
                'POST', '/api/v1/users/me/settings', ['Host: localhost', $jar, 'Origin: http://localhost'], false,
            ],
            // Referer-only same-site: accepted (the form-era no-CORS rail).
            'post cookie referer only same site' => [
                'POST', '/api/v1/users/me/settings', [$host, $jar, 'Referer: http://localhost:8096/settings'], false,
            ],
            // Origin beats Referer disagreement: foreign Origin + same-site
            // Referer is still a refusal.
            'post cookie foreign origin over same referer' => [
                'POST', '/api/v1/users/me/settings',
                [$host, $jar, 'Origin: http://evil.example', 'Referer: http://localhost:8096/settings'], true,
            ],
            // Other state-changing verbs ride the same gate.
            'put cookie foreign origin' => ['PUT', '/api/v1/users/me/settings', [$host, $jar, 'Origin: http://evil.example'], true],
            'delete cookie foreign origin' => ['DELETE', '/api/v1/users/me/settings', [$host, $jar, 'Origin: http://evil.example'], true],
            'patch cookie foreign origin' => ['PATCH', '/api/v1/collections/1', [$host, $jar, 'Origin: http://evil.example'], true],
            // The auth-adjacent cohort explicitly named by §7.
            'post logout cookie foreign origin' => ['POST', '/api/v1/auth/logout', [$host, $jar, 'Origin: http://evil.example'], true],
            'post refresh cookie foreign origin' => ['POST', '/api/v1/auth/refresh', [$host, $jar, 'Origin: http://evil.example'], true],
            // GET exemption law + its single named exception.
            'get logout foreign origin gated' => ['GET', '/auth/logout', [$host, $jar, 'Origin: http://evil.example'], true],
            'get logout absent origin fails closed' => ['GET', '/auth/logout', [$host, $jar], true],
            'get logout same referer control' => ['GET', '/auth/logout', [$host, $jar, 'Referer: http://localhost:8096/'], false],
            'get ordinary path foreign referer exempt' => ['GET', '/api/v1/media', [$host, $jar, 'Referer: http://evil.example/page'], false],
            'head ordinary path exempt' => ['HEAD', '/api/v1/users/me/settings', [$host, $jar, 'Origin: http://evil.example'], false],
            // Bearer is never subject: browsers do not auto-attach Authorization.
            'bearer foreign origin dispatches' => [
                'POST', '/api/v1/users/me/settings', ['Host: localhost:8096', $bearer, 'Origin: http://evil.example'], false,
            ],
            // Hybrid: bearer HEADS the request — the jar on the same message
            // never flips custody class, so the gate must stay out of it.
            'bearer plus jar foreign origin dispatches' => [
                'POST', '/api/v1/users/me/settings', [$host, $bearer, $jar, 'Origin: http://evil.example'], false,
            ],
            // Query/none carrier: the HTTP rail has no query-token custody
            // (that is the WS surface's); anonymously-authed requests never
            // reach the gate at all.
            'query carrier never gated' => [
                'POST', '/api/v1/users/me/settings?token=' . self::COOKIE_TOKEN,
                [$host, 'Origin: http://evil.example'], false,
            ],
            // Route-independence: the gate is verb×carrier, not path-table.
            'post settings family second path foreign' => [
                'PUT', '/api/v1/server/settings', ['Host: localhost:8096', $jar, 'Origin: http://evil.example'], true,
            ],
        ];
    }

    /**
     * @param list<string> $headerLines
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('matrix')]
    public function testTheBeltDispositionsFollowTheVerbCarrierOriginMatrix(
        string $method,
        string $path,
        array $headerLines,
        bool $expectRefused
    ): void {
        $wire = $this->send($method, $path, $headerLines);

        if ($expectRefused) {
            $this->assertRefusalWire($wire, $method . ' ' . $path);
            return;
        }

        $this->assertStringStartsWith(
            'HTTP/1.1 404 ',
            $wire,
            $method . ' ' . $path . ": expected DISPATCH (the 404 of the router-less "
            . "Application double), the belt intercepted:\n" . $wire
        );
        $this->assertStringNotContainsString('csrf.invalid_origin', $wire);
    }

    /**
     * One shared envelope: EVERY refused arm ships byte-identical reply bytes.
     * Pairwise equality across the matrix's refusal rows is the belt's
     * "disposition byte-exact" law — a per-branch copy of the 403 cannot
     * drift without this reddening.
     */
    public function testEveryRefusedArmShipsTheIdenticalWireBytes(): void
    {
        $refusals = [];
        foreach (self::matrix() as $name => [$method, $path, $headerLines, $expectRefused]) {
            if (!$expectRefused) {
                continue;
            }
            $refusals[$name] = $this->send($method, $path, $headerLines);
        }

        $this->assertNotEmpty($refusals, 'premise: the matrix contains refusals');
        $canonical = (string) reset($refusals);
        foreach ($refusals as $name => $wire) {
            $this->assertSame(
                $canonical,
                $wire,
                "the refusal of '{$name}' diverged from the shared 403 envelope"
            );
        }
        $this->assertRefusalWire($canonical, 'shared envelope');
    }

    /**
     * PREMISE-FALSIFICATION PIN (estate law instantiated by the w31L1-R2 DD
     * verdict): a fixture that CLAIMS to be unparseable must PROVE it by
     * execution, in this runtime, every run. Round 1 shipped 'http://[' on
     * the belief parse_url() returned false; PHP 8.3.6 parses it to
     * scheme + host '[' instead, so the two unparseable matrix rows refused
     * via the host-mismatch leg and force-allow plants on either parse-false
     * arm stayed green (DD d4/d4b, reproduced at base f31178da). With the
     * premise pinned here, a future swap of a parseable value back into
     * these fixtures reddens THIS leg by name — never silently in some
     * later mutation transcript.
     */
    public function testTheUnparseableFixtureValuesTrulyFailParseUrlInThisRuntime(): void
    {
        $this->assertSame(
            false,
            parse_url(self::UNPARSEABLE_ORIGIN),
            'the Origin fixture must be parse-false in this runtime'
        );
        $this->assertSame(
            false,
            parse_url(self::UNPARSEABLE_REFERER),
            'the Referer fixture must be parse-false in this runtime'
        );

        // The retired round-1 value stays pinned in the OPPOSITE direction:
        // parse_url('http://[') is an ARRAY on this runtime (scheme http,
        // host '[') — precisely why it can never return as this fixture.
        $retired = parse_url('http://[');
        $this->assertIsArray($retired, "round-1's 'http://[' must stay documented as parseable");

        $this->assertSame('[', $retired['host'] ?? '');
    }

    /**
     * The compact (UNPRETTIFIED) body law: the belt's refusal is built with a
     * bare json_encode() outside Response::json(), so its bytes are NOT the
     * pretty shape the rest of the API ships. Pinned deliberately: consumers
     * match on the code, and any future re-shape must be a disclosed wire
     * change, never an incidental one.
     */
    public function testTheRefusalBodyIsTheCompactEnvelopeNotThePrettyRail(): void
    {
        $wire = $this->send('POST', '/api/v1/users/me/settings', [
            'Host: localhost:8096',
            'Cookie: ' . AuthController::SESSION_COOKIE . '=' . self::COOKIE_TOKEN,
            'Origin: http://evil.example',
        ]);

        [, $body] = explode("\r\n\r\n", $wire, 2);
        $this->assertSame(self::REFUSAL_BODY, $body);
        $this->assertSame(
            strlen(self::REFUSAL_BODY),
            (int) ($this->headerValue($wire, 'Content-Length') ?? -1),
            'the refusal declares its true length'
        );
    }

    // ── the authenticator seam the handler consults ──────────────────────────

    /**
     * Scripted authenticator: the cookie jar and the bearer token both map
     * to a user; anything else is anonymous. This is the ONLY auth surface
     * the matrix scripts — the belt law itself runs real
     * ({@see RequestAuthenticator} + CookiePolicy under test).
     */
    private function authenticator(): RequestAuthenticator
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('validateAccessToken')->willReturnCallback(
            static fn (string $token): ?array => match ($token) {
                self::COOKIE_TOKEN, self::BEARER_TOKEN => ['user_id' => 'u-csrf'],
                default => null,
            }
        );

        return new RequestAuthenticator($authManager);
    }

    /**
     * @param list<string> $headerLines
     */
    private function send(string $method, string $path, array $headerLines): string
    {
        $raw = "{$method} {$path} HTTP/1.1\r\n";
        foreach ($headerLines as $line) {
            $raw .= "{$line}\r\n";
        }
        $raw .= "\r\n";

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

        $this->handler()->__invoke($conn, new WorkermanRequest($raw));

        $this->assertCount(1, $sent, 'exactly one response is sent');

        return (string) $sent[0];
    }

    private function handler(): HttpHandler
    {
        $registry = new SegmentProcessRegistry();

        // An /api/* path the Application router 404s falls through to the
        // web-portal rail (S171 mirror at HttpHandler :343); the double keeps
        // it on the same "reached dispatch" verdict-shape (a router-less 404)
        // so no matrix row can mistake the fall-through for a belt refusal.
        $portal = $this->createMock(WebPortalRouter::class);
        $portal->method('dispatch')->willReturnCallback(
            static fn (Request $request): Response => (new Response())->status(404)->json(['error' => 'Not Found']),
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($registry, $portal): mixed {
                if ($id === SegmentProcessRegistry::class) {
                    return $registry;
                }
                if ($id === WebPortalRouter::class) {
                    return $portal;
                }
                throw new \RuntimeException('unexpected container get: ' . $id);
            }
        );
        $container->method('has')->willReturn(true);

        $application = $this->createMock(Application::class);
        $application->method('dispatch')->willReturnCallback(
            static fn (Request $request): Response => (new Response())->status(404)->json(['error' => 'Not Found']),
        );

        return new HttpHandler(
            $container,
            $this->authenticator(),
            $this->publicRoot,
            $application,
            null,
        );
    }

    private function assertRefusalWire(string $wire, string $site): void
    {
        $this->assertStringStartsWith('HTTP/1.1 403 Forbidden', $wire, "{$site}: the belt must answer 403");
        $this->assertStringContainsString('Content-Type: application/json; charset=utf-8', $wire, $site);
        $this->assertStringContainsString(self::REFUSAL_BODY, $wire, "{$site}: refusal envelope");
        // The shared decoration: a cross-origin client must be able to READ
        // the refusal (M-SERVER-50 rail — decorateRawReply over every exit).
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $wire, "{$site}: security decoration");
    }

    private function headerValue(string $wire, string $name): ?string
    {
        [$head] = explode("\r\n\r\n", $wire, 2);
        foreach (preg_split('/\r\n/', $head) ?: [] as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return null;
    }
}
