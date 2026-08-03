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
use Workerman\MySQL\Connection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * The BOUNDARY of `Router::markHeadOnly()`'s head-only guarantee (S105 review r1,
 * INFO item).
 *
 * `Router::dispatch()` flags every `HEAD` reply it returns from a matched route, so
 * exactly one `Content-Length` reaches the wire (RFC 9110 §8.6 makes two
 * conflicting ones unrecoverable). The chain in `Application::dispatch()` runs
 * BEFORE the router, so a **global** middleware that short-circuits — returns a
 * `Response` instead of calling `$next` — returns without the flag ever being
 * applied. These tests pin both halves of that boundary:
 *
 *  1. a `HEAD` that passes THROUGH the global chain still gets the guarantee, i.e.
 *     the chain does not undo it;
 *  2. a `HEAD` short-circuited by a global middleware does NOT get it, and what
 *     that costs today is the *recoverable* shape only: the refusal body ships with
 *     ONE self-consistent `Content-Length` (RFC 9110 §9.3.2), because the only
 *     global middleware that can short-circuit declares no length of its own;
 *  3. a global short-circuit that DID declare a `Content-Length` would ship TWO —
 *     the unrecoverable defect. Test 3 exists as the drift alarm: it asserts that
 *     `AccessScheduleMiddleware`'s three refusals declare no `Content-Length`, so
 *     the hole stays latent. If one ever does, that must be fixed immediately
 *     rather than deferred with the body-on-a-HEAD group.
 *
 * The deliberate decision recorded here is that the body-on-a-HEAD shape is fixed
 * for ALL of its sites at once (`Router::notFound()`, `HttpHandler`'s SPA shell /
 * `serveStatic()` / 404 / 500 / 429, and this one) rather than one site at a time.
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
     * The boundary itself, asserted on the bytes: a global middleware short-circuit
     * on a `HEAD` is NOT flagged, so the refusal body reaches the wire.
     *
     * This is the CURRENT, deliberately-bounded behaviour. It is the recoverable
     * shape — Workerman's generated `Content-Length` is the only one present and it
     * matches the body it ships — which is why it waits for the change that fixes
     * every body-on-a-HEAD site together. If this test goes red because the hole was
     * closed, that is a deliberate improvement: update the expectation.
     */
    public function testAGlobalMiddlewareShortCircuitIsOutsideTheGuaranteeAndStillShipsItsBody(): void
    {
        $payload = ['error' => 'AccessScheduled', 'message' => 'Access denied during scheduled window'];
        $json = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // Exactly the wrapper Application::__construct() registers for
        // AccessScheduleMiddleware: return the middleware's Response, never $next.
        $shortCircuit = static fn(Request $request, callable $next): Response
            => (new Response())->status(403)->json($payload);

        $app = $this->applicationWith($this->routerWithGatedStream(), [$shortCircuit]);

        $response = $app->dispatch($this->makeRequest('HEAD', '/dlna/stream/abc123'));
        $wire = (string) $response->toWorkermanResponse();

        $this->assertFalse(
            $response->headOnly,
            'DOCUMENTED BOUNDARY: the router flag is not reached by a global short-circuit',
        );
        $this->assertSame(
            (string) new WorkermanResponse(403, ['Content-Type' => 'application/json'], $json),
            $wire,
            'a global short-circuit is byte-identical to the framework encoder, body included',
        );
        $this->assertStringEndsWith($json, $wire, 'the refusal body still ships on a HEAD (RFC 9110 §9.3.2)');

        // …but it is the RECOVERABLE shape: one length, and it matches the body.
        $this->assertSame(1, substr_count($wire, 'Content-Length:'), "not the two-length defect:\n" . $wire);
        $this->assertStringContainsString('Content-Length: ' . strlen($json) . "\r\n", $wire);
    }

    /**
     * The drift alarm for the boundary: a global middleware that declares its OWN
     * `Content-Length` on a `HEAD` short-circuit ships TWO of them — the
     * unrecoverable RFC 9110 §8.6 defect — precisely because the flag is out of
     * reach here. Nothing in `src/` does this today (test 3 pins that), and this
     * test is what makes the consequence explicit rather than prose.
     */
    public function testAGlobalShortCircuitDeclaringItsOwnContentLengthWouldShipTwo(): void
    {
        $shortCircuit = static fn(Request $request, callable $next): Response => (new Response())
            ->status(403)
            ->header('Content-Type', 'application/json')
            ->header('Content-Length', '4242');

        $app = $this->applicationWith($this->routerWithGatedStream(), [$shortCircuit]);

        $response = $app->dispatch($this->makeRequest('HEAD', '/dlna/stream/abc123'));
        $wire = (string) $response->toWorkermanResponse();

        $this->assertFalse($response->headOnly);
        $this->assertSame(
            2,
            substr_count($wire, 'Content-Length:'),
            "KNOWN HOLE (bounded, see Application::dispatch()'s docblock): a global short-circuit that "
            . "declares a Content-Length ships two. If this ever fires in reverse, the hole was closed.\n" . $wire,
        );
        $this->assertStringContainsString("Content-Length: 4242\r\n", $wire);
        $this->assertStringContainsString("Content-Length: 0\r\n", $wire);
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
