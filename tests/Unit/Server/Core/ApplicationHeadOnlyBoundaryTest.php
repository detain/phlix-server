<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use PHPUnit\Framework\TestCase;
use Phlix\Access\AccessScheduleService;
use Phlix\Auth\UserProfileManager;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Middleware\AccessScheduleMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use ReflectionClass;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * The BOUNDARY of `Router::markHeadOnly()`'s head-only guarantee (S105 review r1,
 * INFO item).
 *
 * `Router::dispatch()` flags every `HEAD` reply it returns from a matched route, so
 * exactly one `Content-Length` reaches the wire (RFC 9110 §8.6 makes two
 * conflicting ones unrecoverable). The chain in `Application::dispatch()` runs
 * BEFORE the router, so a **global** middleware that short-circuits — returns a
 * `Response` instead of calling `$next` — returns without the router's flag ever
 * being applied. **S295 closed that seam where the global chain returns**
 * (`Application::flagHeadShortCircuitReply()`), so these tests pin the SHIPPED
 * state of both halves of the boundary:
 *
 *  1. a `HEAD` that passes THROUGH the global chain still gets the guarantee, i.e.
 *     the chain does not undo it;
 *  2. a `HEAD` short-circuited by a global middleware now gets it at the
 *     chain-return seam: the reply is flagged head-only (via the real
 *     `flagHeadShortCircuitReply()` method), so no body ships and the
 *     `Content-Length` is the entity the equivalent `GET` would have returned;
 *  3. even a global short-circuit that DID declare its own `Content-Length`
 *     ships exactly ONE of them on a `HEAD` — the RFC 9110 §8.6 two-length
 *     framing defect is now unreachable on the global chain for a `HEAD`,
 *     because the seam flag runs before the encoder. Test 3 pins that closure,
 *     and test 4 asserts that `AccessScheduleMiddleware`'s three refusals
 *     declare no `Content-Length` (so a `GET` short-circuit keeps the framework
 *     encoder's single field, exactly as before).
 *
 * The body-on-a-HEAD shape is fixed for ALL of its sites: `Router::notFound()`,
 * `HttpHandler`'s SPA shell / `serveStatic()` / 404 / 500 / 429 (S113), and the
 * global middleware short-circuit (S295).
 *
 * No database: `Application` is built with `newInstanceWithoutConstructor()` and
 * only its `$router` / `$middleware` are populated, which is all `dispatch()` reads.
 */
final class ApplicationHeadOnlyBoundaryTest extends TestCase
{
    /**
     * `AccessScheduleMiddleware` publishes into the coroutine context; clear both
     * keys so nothing leaks into another test in this (resident-memory) process.
     */
    protected function tearDown(): void
    {
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        parent::tearDown();
    }

    /**
     * The guarantee SURVIVES the global chain: a pass-through global middleware
     * — one that always calls `$next` — leaves the router's flag, and therefore
     * the single `Content-Length`, intact.
     *
     * `ThemeMiddleware` was the registered example of this shape until S84 retired
     * it. The property is not tied to that class, so the test keeps its synthetic
     * pass-through: it pins what the CHAIN does to a `HEAD`, which is still worth
     * pinning for the next pass-through middleware that gets registered.
     */
    public function testTheRouterHeadOnlyGuaranteeSurvivesTheGlobalMiddlewareChain(): void
    {
        $router = new Router();
        $router->match(['GET', 'HEAD'], '/dlna/stream/{id}', function ($req, $params): Response {
            return (new Response())
                ->status(200)
                ->header('Content-Type', 'video/mp4')
                ->header('Content-Length', '4242');
        });

        $passThrough = static fn(Request $request, callable $next): Response => $next($request);
        $app = $this->applicationWith($router, [$passThrough]);

        $response = $app->dispatch($this->makeRequest('HEAD', '/dlna/stream/abc123'));
        $wire = (string) $response->toWorkermanResponse();

        $this->assertTrue($response->headOnly, 'a global middleware that calls $next must not lose the flag');
        $this->assertSame(1, substr_count($wire, 'Content-Length:'), "one Content-Length only:\n" . $wire);
        $this->assertStringContainsString("Content-Length: 4242\r\n", $wire);
        $this->assertSame('', explode("\r\n\r\n", $wire, 2)[1] ?? 'TERMINATOR MISSING', 'a HEAD carries no body');
    }

    /**
     * The boundary, asserted on the bytes: a global middleware short-circuit on a
     * `HEAD` is NOW flagged at the chain-return seam (S295), so the refusal body
     * does NOT reach the wire and the `Content-Length` is the real entity size.
     *
     * The middleware below models the wrapper `Application::__construct()`
     * registers for `AccessScheduleMiddleware` — S295 shape: every short-circuit
     * reply is routed through the REAL `Application::flagHeadShortCircuitReply()`
     * seam (reached via reflection), never `$next`. The discriminating control is
     * the same `GET`: it must still carry its whole body, so this suite can never
     * be passed by suppressing bodies generally.
     */
    public function testAGlobalMiddlewareShortCircuitIsNowFlaggedHeadOnlyAtTheChainReturnSeam(): void
    {
        $payload = ['error' => 'AccessScheduled', 'message' => 'Access denied during scheduled window'];
        $json = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $shortCircuit = function (Request $request, callable $next): Response {
            $result = (new Response())->status(403)->json([
                'error' => 'AccessScheduled',
                'message' => 'Access denied during scheduled window',
            ]);

            return $this->flagHeadShortCircuitReply($request, $result);
        };

        $app = $this->applicationWith($this->routerWithGatedStream(), [$shortCircuit]);

        $headResponse = $app->dispatch($this->makeRequest('HEAD', '/dlna/stream/abc123'));
        $headWire = (string) $headResponse->toWorkermanResponse();

        $this->assertTrue(
            $headResponse->headOnly,
            'S295: the chain-return seam must flag a HEAD short-circuit head-only',
        );
        $this->assertSame(
            1,
            substr_count($headWire, 'Content-Length:'),
            "exactly ONE Content-Length on the wire:\n" . $headWire,
        );
        $this->assertStringContainsString('Content-Length: ' . strlen($json) . "\r\n", $headWire);
        $this->assertSame('', explode("\r\n\r\n", $headWire, 2)[1] ?? 'TERMINATOR MISSING', 'a HEAD carries no body');
        $this->assertStringNotContainsString('Access denied during scheduled window', $headWire);

        // The discriminating control: the same short-circuit on a GET must still
        // ship the refusal body, byte for byte.
        $getResponse = $app->dispatch($this->makeRequest('GET', '/dlna/stream/abc123'));
        $getWire = (string) $getResponse->toWorkermanResponse();

        $this->assertFalse($getResponse->headOnly, 'a GET short-circuit is never flagged head-only');
        $this->assertStringEndsWith($json, $getWire, 'a GET still ships the refusal body');
    }

    /**
     * The drift alarm, re-pointed at the SHIPPED state: because the chain-return
     * seam flags every global short-circuit head-only on a `HEAD`, a global
     * middleware that declares its OWN `Content-Length` now ships exactly ONE of
     * them — the unrecoverable RFC 9110 §8.6 two-length framing defect is
     * unreachable on the global chain for a `HEAD`. (A GET short-circuit that
     * declares its own length still ships the framework's generated field over
     * it, which is the pre-existing, non-HEAD behaviour nothing here claims to
     * change.)
     */
    public function testAGlobalShortCircuitDeclaringItsOwnContentLengthNowShipsOneOnAHead(): void
    {
        $shortCircuit = function (Request $request, callable $next): Response {
            $result = (new Response())
                ->status(403)
                ->header('Content-Type', 'application/json')
                ->header('Content-Length', '4242');

            return $this->flagHeadShortCircuitReply($request, $result);
        };

        $app = $this->applicationWith($this->routerWithGatedStream(), [$shortCircuit]);

        $response = $app->dispatch($this->makeRequest('HEAD', '/dlna/stream/abc123'));
        $wire = (string) $response->toWorkermanResponse();

        $this->assertTrue($response->headOnly, 'the seam flag must win even when the caller set a length');
        $this->assertSame(
            1,
            substr_count($wire, 'Content-Length:'),
            "S295: a caller-set Content-Length is authoritative in asHeadReply(), so the "
            . "two-length framing defect is closed on the global chain for a HEAD.\n" . $wire,
        );
        $this->assertStringContainsString("Content-Length: 4242\r\n", $wire);
        $this->assertStringNotContainsString("Content-Length: 0\r\n", $wire);
        $this->assertSame('', explode("\r\n\r\n", $wire, 2)[1] ?? 'TERMINATOR MISSING', 'a HEAD carries no body');
    }

    /**
     * Why the hole is LATENT and not live: the only global middleware that can
     * short-circuit today is `AccessScheduleMiddleware`, and none of its refusals
     * declares a `Content-Length`. Two of its three branches are exercised here —
     * no profile for an authenticated user, and a profile row with no usable id.
     *
     * The third (denied inside an active schedule window) is **not reachable from a
     * unit test today**: `AccessSchedule::isActiveAt()` feeds the CURRENT clock
     * through `AccessSchedule::timeToMinutes()`, which divides by 60 while being
     * typed `: int`, so under `strict_types` it throws
     * `TypeError: … must be of type int, float returned` for every wall-clock second
     * that is not a multiple of 60. That is a pre-existing defect in the access
     * schedule feature, entirely unrelated to this HEAD boundary, reported for its
     * own step; its refusal is the same `->status(403)->json([...])` shape as the two
     * asserted here, so the property below covers it by construction.
     *
     * If a future edit adds a `Content-Length` to one of these (or a new global
     * middleware short-circuits with one), this test fails and the two-length defect
     * must be fixed then and there rather than deferred.
     */
    public function testAccessScheduleMiddlewareRefusalsDeclareNoContentLength(): void
    {
        RequestContext::setUserId('user-1');

        $noProfile = $this->createMock(UserProfileManager::class);
        $noProfile->method('getActiveProfile')->willReturn(null);

        $unusableId = $this->createMock(UserProfileManager::class);
        $unusableId->method('getActiveProfile')->willReturn(['id' => '']);

        $cases = [
            'no profile for an authenticated user' => [$noProfile, $this->scheduleService([])],
            'profile row without a usable id' => [$unusableId, $this->scheduleService([])],
        ];

        foreach ($cases as $label => [$profileManager, $scheduleService]) {
            $refusal = (new AccessScheduleMiddleware($scheduleService, $profileManager))(
                $this->makeRequest('HEAD', '/api/v1/media/abc123'),
            );

            $this->assertInstanceOf(Response::class, $refusal, $label . ': must short-circuit');
            $this->assertSame(403, $refusal->statusCode, $label . ': must refuse with 403');
            $this->assertSame(
                [],
                array_filter(
                    array_keys($refusal->headers),
                    static fn(string $name): bool => strtolower($name) === 'content-length',
                ),
                $label . ': a global short-circuit must not declare a Content-Length — see this class docblock',
            );
        }
    }

    /**
     * S105 AC-audit residual (review r2's open finding, reproduced by execution).
     *
     * The three tests above are only half a defence, and the missing half is the
     * half that matters. Test 3 proves what a Content-Length-declaring short-circuit
     * WOULD do, but it builds that middleware **synthetically inside itself**; test 4
     * proves the hole is latent, but it hard-codes **`AccessScheduleMiddleware`**.
     * Neither looks at what is actually registered. So an EXTRA global middleware
     * declaring a `Content-Length` on a short-circuit — the unrecoverable RFC 9110
     * §8.6 shape the class docblock says "must be fixed at once" — was caught by
     * nothing: adding one to `Application::__construct()` left the entire Unit suite
     * (8,470 tests) GREEN with an identical assertion count.
     *
     * This asserts the **count** instead of the identity, so it fires on any change
     * to the registration list regardless of what that registration is. Both
     * spellings are covered: `Application::middleware()` is public, and the only way
     * to reach it from outside the class is the `Application::getInstance()`
     * singleton.
     *
     * ⚠ Stopping rule, so this cannot be quietly deleted as noise: if it fires,
     * the fix is to analyse the new middleware and — if it cannot short-circuit with
     * a `Content-Length` — raise the expected count here with that reasoning written
     * down. It is not to delete the assertion. If a `getInstance()` caller appears
     * that does not register middleware, the second assertion already ignores it.
     *
     * ## Count history (each change gets a line, per the stopping rule)
     *
     *  - measured at **2**: `ThemeMiddleware` (pass-through) + `AccessScheduleMiddleware`.
     *  - **S84 → 1**: `ThemeMiddleware` was RETIRED. Analysis for the lowering, which
     *    the stopping rule demands in both directions: it was the pass-through half of
     *    the pair, so removing it cannot introduce a short-circuit and the two-length
     *    defect stays out of reach; and it substituted the Smarty placeholders
     *    `{$theme_css|raw}` / `{$theme_js|raw}`, which no template has emitted since
     *    the Smarty page renderer was deleted, so nothing observable changed on the
     *    wire. `AccessScheduleMiddleware` — still the only middleware that can
     *    short-circuit, still declaring no `Content-Length` (test 4) — is now the only
     *    global middleware at all.
     *  - **S295 re-measured at 1**: the registration COUNT did not change — the
     *    constructor's wrapper gained the `flagHeadShortCircuitReply()` HEAD gate
     *    (a shape change to the existing registration, not a new registration), so
     *    the denominator of global short-circuit paths stays one middleware with its
     *    three refusal branches, each now flagged head-only on a `HEAD`.
     */
    public function testNoThirdGlobalMiddlewareHasAppearedSinceThisBoundaryWasMeasured(): void
    {
        $applicationFile = (string) (new ReflectionClass(Application::class))->getFileName();

        $this->assertSame(
            1,
            $this->countGlobalRegistrations($applicationFile),
            'A global middleware was added or removed. The head-only boundary is currently measured '
            . 'against exactly one (AccessScheduleMiddleware, whose refusals declare no Content-Length; '
            . 'ThemeMiddleware was the second until S84 retired it). Re-do that analysis for the new '
            . 'one: if it can short-circuit while declaring a Content-Length, a HEAD it refuses ships '
            . 'TWO of them (RFC 9110 §8.6, unrecoverable) because Router::markHeadOnly() is never '
            . 'reached. Then update the count history in this test docblock.',
        );

        $this->assertSame(
            [],
            $this->productionFilesRegisteringMiddlewareOnTheSingleton(),
            'Application::middleware() is public and Application::getInstance() is the way to reach it '
            . 'from outside the class. A registration made there bypasses the count above.',
        );
    }

    /**
     * Count real `$this->middleware(...)` CALLS in a file.
     *
     * Tokenised rather than `substr_count()`ed on purpose: the first version of this
     * guard counted the raw string and promptly failed on its own commit, because the
     * docblock it added to `Application::dispatch()` mentions `$this->middleware(...)`
     * in prose. A counter that a comment can move is not a counter.
     */
    private function countGlobalRegistrations(string $file): int
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $count = 0;

        foreach ($tokens as $i => $token) {
            // The call is the 4-token run: T_VARIABLE '$this', T_OBJECT_OPERATOR,
            // T_STRING 'middleware', '('. Comments and doc comments are single
            // T_COMMENT / T_DOC_COMMENT tokens, so prose can never match.
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$this') {
                continue;
            }
            $operator = $tokens[$i + 1] ?? null;
            $name = $tokens[$i + 2] ?? null;
            $paren = $tokens[$i + 3] ?? null;

            if (
                is_array($operator) && $operator[0] === T_OBJECT_OPERATOR
                && is_array($name) && $name[0] === T_STRING && $name[1] === 'middleware'
                && $paren === '('
            ) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Files under `src/` that both obtain the `Application` singleton AND call
     * `->middleware(` — i.e. could register a global middleware from outside
     * `Application` itself. A file that merely uses `getInstance()` is ignored.
     *
     * @return list<string> Repo-relative paths, empty when nothing does this.
     */
    private function productionFilesRegisteringMiddlewareOnTheSingleton(): array
    {
        $root = dirname(__DIR__, 4) . '/src';
        $found = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $body = (string) file_get_contents($file->getPathname());
            if (str_contains($body, 'Application::getInstance()') && str_contains($body, '->middleware(')) {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        sort($found);

        return $found;
    }

    /**
     * A real `AccessScheduleService` (it is `final`, so it cannot be mocked) over a
     * mocked connection returning the given `access_schedules` rows.
     *
     * @param list<array<string, mixed>> $rows Rows the schedule query returns.
     */
    private function scheduleService(array $rows): AccessScheduleService
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn($rows);

        return new AccessScheduleService($db);
    }

    /**
     * A router carrying the DLNA-shaped `HEAD`-registered stream route the
     * short-circuit tests never reach (the handler must not be what answers).
     */
    private function routerWithGatedStream(): Router
    {
        $router = new Router();
        $router->match(['GET', 'HEAD'], '/dlna/stream/{id}', function ($req, $params): Response {
            return (new Response())->status(200)->header('Content-Length', '4242')->body('HANDLER RAN');
        });

        return $router;
    }

    /**
     * An `Application` with only the two properties `dispatch()` reads populated —
     * no container, no config, no connection pool, and no database.
     *
     * @param list<callable> $middleware Global middleware stack, in registration order.
     */
    private function applicationWith(Router $router, array $middleware): Application
    {
        $reflection = new ReflectionClass(Application::class);
        $app = $reflection->newInstanceWithoutConstructor();

        $routerProperty = $reflection->getProperty('router');
        $routerProperty->setValue($app, $router);

        $middlewareProperty = $reflection->getProperty('middleware');
        $middlewareProperty->setValue($app, $middleware);

        return $app;
    }

    /**
     * The seam `Application::__construct()` routes every global short-circuit
     * reply through — S295. Invoked via reflection so the tests below exercise
     * the REAL shipped method, never a hand-written mirror of it.
     */
    private function flagHeadShortCircuitReply(Request $request, Response $response): Response
    {
        $method = new ReflectionMethod(Application::class, 'flagHeadShortCircuitReply');

        /** @var Response */
        return $method->invoke(null, $request, $response);
    }

    /**
     * A bare `Request` — method + path is all the dispatch path reads here.
     */
    private function makeRequest(string $method, string $path): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;

        return $request;
    }
}
