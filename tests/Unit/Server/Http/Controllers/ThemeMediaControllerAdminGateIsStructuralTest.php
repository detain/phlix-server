<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use ArgumentCountError;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\Library\LibraryManager;
use Phlix\Server\Http\Controllers\ThemeMediaController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Theming\ThemeMediaFinder;
use Phlix\Theming\ThemeMediaRepository;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * S323 — the {@see AdminMiddleware} dependency of {@see ThemeMediaController} is
 * STRUCTURALLY required, and both mutation handlers are behind it.
 *
 * ## The defect this file exists to make impossible
 *
 * `ThemeMediaController` used to hold `private ?AdminMiddleware $adminMiddleware = null;`
 * filled by an OPTIONAL `setAdminMiddleware()` setter, and both `scanThemeMedia()`
 * and `deleteThemeMedia()` wrapped their decision in
 * `if ($this->adminMiddleware !== null)`.
 *
 * This is the same shape S282 removed from `LibraryController`, but a WORSE
 * severity class. `LibraryController::requireAdmin()` runs `requireAuth()` first,
 * so its fail-open only ever reached an authenticated non-admin. The check here is
 * INLINE, with no auth check in front of it, so a controller built without the
 * setter served `POST /api/v1/libraries/{id}/theme-media/scan` and
 * `DELETE /api/v1/libraries/{id}/theme-media` to an **ANONYMOUS** caller.
 *
 * Production always called the setter, so the hole was latent. That is a property
 * of the wiring, not of the class, and the wiring is exactly the thing a future
 * change can get wrong: PHP-DI's `autowire()` SKIPS optional parameters, and this
 * estate has already shipped silently-null dependencies that way.
 *
 * ## Two independent nets, because either alone can be defeated
 *
 *  1. **Structural** — reflection over the constructor, the property and the
 *     method list, plus a source-level check that neither handler compares the
 *     middleware against null. This is what catches the three ways the optional
 *     shape can come back: a nullable property, a defaulted/omitted constructor
 *     parameter, or a re-introduced setter. A behavioural test alone would NOT
 *     catch a re-added setter, because a setter changes nothing until someone
 *     calls it — measured in S282's M2 mutation.
 *  2. **Behavioural** — both gated handlers driven three ways (anonymous /
 *     authenticated non-admin / admin). The anonymous arm is the experiment here
 *     (it is the exposure this step closes); the admin arm is the succeeding
 *     control beside it, so a blanket-deny regression cannot read as a pass. A
 *     structural test alone would not catch a handler being changed to ignore the
 *     (still required) middleware.
 *
 * NB: this file carries NO coverage-metadata annotation, deliberately. Per this
 * repo's policy (S141, enforced by CoverageMetadataPolicyTest) such a marker in
 * `tests/` silently DISCARDS every other file the test executes. The policy check
 * matches the token itself, so it must not be spelled out even in prose.
 */
final class ThemeMediaControllerAdminGateIsStructuralTest extends TestCase
{
    /**
     * Every admin-gated handler on {@see ThemeMediaController}, as
     * `[controller method => expected status on the ADMIN arm]`.
     *
     * The admin-arm status is a BODY-ONLY outcome, never one the gate can emit:
     * both handlers look their library up first and the fixture makes
     * `getLibrary()` return null, so they answer 404. The gate emits only 401 and
     * 403, so an admin arm that shows 404 proves the request reached the handler
     * body rather than being waved through OR refused.
     *
     * Hardcoded on purpose — a derived list would self-adjust to whatever the
     * controller happens to do.
     * {@see self::testEveryRequestHandlerIsGatedOrExplicitlyExempt()} is the drift
     * detector that fails when the controller grows a third handler — gated OR
     * ungated.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function adminGatedHandlerProvider(): array
    {
        return [
            'POST   /api/v1/libraries/{id}/theme-media/scan' => ['scanThemeMedia', 404],
            'DELETE /api/v1/libraries/{id}/theme-media'      => ['deleteThemeMedia', 404],
        ];
    }

    /**
     * The public request handlers that are deliberately NOT admin-gated.
     *
     * Exactly one entry, and it is the READ. `getThemeMedia()` exposes no
     * mutation and is pinned as anonymously reachable by
     * {@see self::testTheReadHandlerIsNotGated()}, which is also the negative
     * control for the three behavioural arms.
     *
     * ⚠ Adding a name here is how you declare "this handler needs no admin
     * gate". It is a deliberate, reviewable security decision and must come with
     * a behavioural test that pins the intended reachability — never a way to
     * silence {@see self::testEveryRequestHandlerIsGatedOrExplicitlyExempt()}.
     *
     * @var list<string>
     */
    private const UNGATED_REQUEST_HANDLERS = ['getThemeMedia'];

    /**
     * Build a controller whose gate treats exactly `admin-1` as an admin and
     * whose `getLibrary()` resolves to nothing (see the provider docblock).
     */
    private function makeController(): ThemeMediaController
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $id === 'admin-1'
                ? ['id' => $id, 'is_admin' => 1, 'status' => 'active']
                : null
        );

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->willReturn(null);

        // No refused or admitted request may ever reach a write.
        $repository = $this->createMock(ThemeMediaRepository::class);
        $repository->expects(self::never())->method('upsert');
        $finder = $this->createMock(ThemeMediaFinder::class);
        $finder->expects(self::never())->method('findForLibrary');

        return new ThemeMediaController(
            $repository,
            $finder,
            $libraryManager,
            new AdminMiddleware($users, $this->createMock(AuditLogger::class))
        );
    }

    /**
     * Does `$method` take a {@see Request} — by NATIVE type OR by docblock?
     *
     * The docblock arm is not decoration. A method declared
     * `public function purge($request, array $params): Response` carrying
     * `@param Request $request` is dispatched by
     * {@see \Phlix\Server\Http\Router::callHandler()} exactly like a natively
     * typed one, and `phpstan analyse -c phpstan.neon.dist` (src/, level 9)
     * reports `[OK]` on it — measured. A native-type-only match therefore left a
     * whole ungated handler shape invisible to BOTH nets.
     *
     * A parameter with neither a native type nor a docblock type is the only
     * remaining shape, and level 9 rejects that one (`missingType.parameter`),
     * so between this method and the src/ analyser the population is closed.
     *
     * Deliberately over-inclusive: any `@param` whose type mentions `Request`
     * counts. Over-inclusion only ever forces a handler to be CLASSIFIED, which
     * is a review moment; under-inclusion is the fail-open.
     */
    private function declaresARequestParameter(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === Request::class) {
                return true;
            }
        }

        $doc = $method->getDocComment();
        if ($doc === false) {
            return false;
        }

        $matches = [];
        preg_match_all('/@param\s+(\S+)\s+&?\.{0,3}\$\w+/', $doc, $matches);
        foreach ($matches[1] as $declared) {
            foreach (explode('|', $declared) as $alternative) {
                $segments = explode('\\', ltrim(trim($alternative), '?'));
                if (end($segments) === 'Request') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $body = json_decode($response->body, true);
        self::assertIsArray($body, 'response body must be a JSON object');
        /** @var array<string, mixed> $body */
        return $body;
    }

    // -----------------------------------------------------------------------
    // Net 1 — structural
    // -----------------------------------------------------------------------

    /**
     * The middleware must be a REQUIRED constructor parameter.
     *
     * Kills: dropping the parameter, giving it a default, widening it to
     * `?AdminMiddleware`, or moving it back behind a setter.
     */
    public function testAdminMiddlewareIsARequiredConstructorParameter(): void
    {
        $ctor = (new ReflectionClass(ThemeMediaController::class))->getConstructor();
        self::assertNotNull($ctor, 'ThemeMediaController must declare a constructor');

        $match = null;
        foreach ($ctor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === AdminMiddleware::class) {
                $match = $parameter;
                break;
            }
        }

        self::assertNotNull(
            $match,
            'ThemeMediaController::__construct() must take an AdminMiddleware. Setter injection '
            . 'is what made the S323 fail-open possible; do not go back to it.'
        );
        self::assertFalse(
            $match->isOptional(),
            'the AdminMiddleware constructor parameter must be REQUIRED — an optional one is '
            . 'skipped by PHP-DI autowire() and leaves the gate null'
        );
        self::assertFalse(
            $match->isDefaultValueAvailable(),
            'the AdminMiddleware constructor parameter must have no default value'
        );
        $type = $match->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertFalse(
            $type->allowsNull(),
            'the AdminMiddleware constructor parameter must not be nullable'
        );
    }

    /**
     * The backing property must be non-nullable and un-defaulted, so there is no
     * value of `$this->adminMiddleware` that means "no gate".
     */
    public function testAdminMiddlewarePropertyIsNonNullableAndHasNoDefault(): void
    {
        $property = new ReflectionProperty(ThemeMediaController::class, 'adminMiddleware');

        $type = $property->getType();
        self::assertInstanceOf(
            ReflectionNamedType::class,
            $type,
            'ThemeMediaController::$adminMiddleware must carry a declared type'
        );
        self::assertSame(AdminMiddleware::class, $type->getName());
        self::assertFalse(
            $type->allowsNull(),
            'ThemeMediaController::$adminMiddleware must NOT be nullable — `?AdminMiddleware` is '
            . 'the exact shape S323 removed'
        );
        self::assertFalse(
            $property->hasDefaultValue(),
            'ThemeMediaController::$adminMiddleware must have no default value'
        );
    }

    /**
     * No setter may reintroduce the optional-wiring shape.
     *
     * Checked by SHAPE, not by name alone: any public method taking an
     * AdminMiddleware is a re-opened door, whatever it is called.
     *
     * This is the assertion no behavioural test can replace — S282's M2 mutation
     * re-added the setter and the entire behavioural suite stayed green, because
     * a setter changes nothing until someone calls it.
     */
    public function testControllerExposesNoAdminMiddlewareSetter(): void
    {
        $class = new ReflectionClass(ThemeMediaController::class);

        self::assertFalse(
            $class->hasMethod('setAdminMiddleware'),
            'setAdminMiddleware() must not exist — the middleware is constructor-injected (S323)'
        );

        $offenders = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType && $type->getName() === AdminMiddleware::class) {
                    $offenders[] = $method->getName();
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'no public method other than the constructor may accept an AdminMiddleware: '
            . implode(', ', $offenders)
        );
    }

    /**
     * Constructing without the gate must be impossible, not merely unusual.
     *
     * This is the "seen to work" arm: the three-argument construction that
     * seventeen tests and one production fallback used to make now dies before the
     * object exists.
     *
     * ## Why this goes through reflection rather than writing `new`
     *
     * A literal `new ThemeMediaController($a, $b, $c)` is a STATIC arity error, so
     * `phpstan analyse -c phpstan-tests.neon` (tests/, level 2) rejects the file
     * outright with `arguments.count` — and that config forbids inline ignore
     * comments, baselines, `assert()`, inline type overrides and casts, all for
     * good reasons. (Nor can this docblock spell the ignore annotation out: the
     * analyser parses the token wherever it appears, including in prose, and
     * answers with `ignore.parseError`.) Suppressing was not an option and neither
     * was deleting the case, so the call is made in a way the arity checker cannot
     * see while the RUNTIME behaviour is identical:
     * `ReflectionClass::newInstanceArgs()` builds the argument list at run time and
     * PHP raises the same `ArgumentCountError` from the same place. This is the
     * shape S282 arrived at after PR #675 went red on exactly this.
     *
     * ⚠ Reflection is what makes this test worth reading twice, so it carries its
     * own POSITIVE CONTROL: the same reflective construction, given the gate, must
     * succeed. Without that arm an `ArgumentCountError` below could equally be
     * reflection failing for some unrelated reason, and the test would pass while
     * proving nothing.
     */
    public function testConstructingWithoutTheAdminMiddlewareIsAFatalError(): void
    {
        $repository = $this->createMock(ThemeMediaRepository::class);
        $finder = $this->createMock(ThemeMediaFinder::class);
        $libraryManager = $this->createMock(LibraryManager::class);
        $class = new ReflectionClass(ThemeMediaController::class);

        // POSITIVE CONTROL — four arguments, i.e. WITH the gate, must construct.
        $controlError = null;
        try {
            $class->newInstanceArgs([
                $repository,
                $finder,
                $libraryManager,
                new AdminMiddleware(
                    $this->createMock(UserRepository::class),
                    $this->createMock(AuditLogger::class)
                ),
            ]);
        } catch (ArgumentCountError $e) {
            $controlError = $e->getMessage();
        }
        self::assertNull(
            $controlError,
            'positive control: reflective construction WITH the middleware must succeed — if it '
            . 'does not, the ArgumentCountError below is an artefact of reflection, not proof of '
            . 'a required dependency'
        );

        // THE EXPERIMENT — the three-argument construction must be fatal.
        $this->expectException(ArgumentCountError::class);
        $this->expectExceptionMessage('Too few arguments');

        $class->newInstanceArgs([$repository, $finder, $libraryManager]);
    }

    /**
     * Neither gated handler may compare the middleware against null.
     *
     * The required parameter removes the null STATE; this removes the null CHECK,
     * so nobody can re-add `?AdminMiddleware` and find a working guard waiting for
     * it.
     *
     * Carries its own positive control: each source slice must contain the
     * `checkAccess()` call. Without it, a pattern that matched nothing — because
     * the slice was empty, or the method was renamed — would read as a pass.
     *
     * @dataProvider adminGatedHandlerProvider
     */
    public function testGatedHandlerHasNoNullGuardAroundTheGate(string $handler): void
    {
        $method = new ReflectionMethod(ThemeMediaController::class, $handler);
        $file = $method->getFileName();
        self::assertIsString($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $start;
        $source = implode("\n", array_slice($lines, $start, $length));

        self::assertNotSame('', trim($source), "could not read {$handler}() source");

        // Positive control FIRST: prove the slice contains what we think it does.
        self::assertStringContainsString(
            '$this->adminMiddleware->checkAccess($request)',
            $source,
            "positive control: {$handler}() must consult the middleware — if this fails, the "
            . 'null-guard assertion below is measuring nothing'
        );

        self::assertDoesNotMatchRegularExpression(
            '/adminMiddleware\s*(!==|===|!=|==)\s*null|null\s*(!==|===|!=|==)\s*\$this->adminMiddleware/',
            $source,
            "{$handler}() must not compare \$this->adminMiddleware against null — that guard IS "
            . 'the S323 fail-open, and on this controller it admits ANONYMOUS callers'
        );
    }

    // -----------------------------------------------------------------------
    // Net 2 — behavioural, over both gated handlers
    // -----------------------------------------------------------------------

    /**
     * Arm 1 of 3 — ANONYMOUS. Expected 401 `auth.required`.
     *
     * This is the experiment for S323 specifically. Unlike `LibraryController`,
     * these handlers have no `requireAuth()` in front of the admin check, so
     * before this step an unwired controller answered an anonymous POST/DELETE
     * with the handler's own success/404 response — an unauthenticated write.
     *
     * @dataProvider adminGatedHandlerProvider
     */
    public function testAnonymousCallerIsRefused(string $method): void
    {
        $response = $this->makeController()->{$method}(new Request(), ['id' => 'lib-1']);

        self::assertSame(
            401,
            $response->statusCode,
            "{$method}() must 401 an ANONYMOUS caller — anything else means the handler is "
            . 'reachable without logging in at all'
        );
        self::assertSame('auth.required', $this->decode($response)['code'] ?? null);
    }

    /**
     * Arm 2 of 3 — AUTHENTICATED NON-ADMIN. Expected 403 `auth.not_admin`.
     *
     * The distinct `auth.not_admin` code proves the ADMIN branch decided it, not
     * some incidental auth check.
     *
     * @dataProvider adminGatedHandlerProvider
     */
    public function testAuthenticatedNonAdminIsRefusedOnTheAdminBranch(string $method): void
    {
        $request = new Request();
        $request->userId = 'user-1'; // only 'admin-1' is an admin

        $response = $this->makeController()->{$method}($request, ['id' => 'lib-1']);

        self::assertSame(
            403,
            $response->statusCode,
            "{$method}() must 403 an authenticated NON-ADMIN — anything else means the handler "
            . 'is reachable by any logged-in user'
        );
        self::assertSame(
            'auth.not_admin',
            $this->decode($response)['code'] ?? null,
            "{$method}() must refuse on the ADMIN branch, not the auth branch"
        );
    }

    /**
     * Arm 3 of 3 — authenticated ADMIN. Expected the handler's own body-only
     * outcome (404, because the fixture's library does not exist).
     *
     * The succeeding control beside the refusals: without it a gate that denied
     * every request would pass arms 1 and 2 and look correct.
     *
     * @dataProvider adminGatedHandlerProvider
     */
    public function testAuthenticatedAdminReachesTheHandlerBody(string $method, int $expected): void
    {
        $request = new Request();
        $request->userId = 'admin-1';

        $response = $this->makeController()->{$method}($request, ['id' => 'lib-1']);

        self::assertSame(
            $expected,
            $response->statusCode,
            "{$method}() must reach the handler body for an admin (got {$response->statusCode})"
        );
        self::assertNotContains(
            $this->decode($response)['code'] ?? null,
            ['auth.required', 'auth.not_admin'],
            "{$method}() must not emit an auth code for an admin"
        );
    }

    /**
     * The READ stays ungated, and stays ungated for a REASON that is asserted.
     *
     * `getThemeMedia()` is deliberately not behind the gate. Pinning that here
     * means a future "make everything admin-only" sweep has to be a deliberate
     * edit to this file rather than a silent behaviour change, and it is the
     * negative control for the three arms above: an anonymous caller reaching a
     * 200 on the READ proves the 401s above come from the gate on the mutations
     * and not from something global.
     */
    public function testTheReadHandlerIsNotGated(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturn(null); // nobody is an admin

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->willReturn(['id' => 'lib-1', 'name' => 'M', 'type' => 'video']);

        $repository = $this->createMock(ThemeMediaRepository::class);
        $repository->method('findByLibraryId')->willReturn(null);

        $controller = new ThemeMediaController(
            $repository,
            $this->createMock(ThemeMediaFinder::class),
            $libraryManager,
            new AdminMiddleware($users, $this->createMock(AuditLogger::class))
        );

        $response = $controller->getThemeMedia(new Request(), ['id' => 'lib-1']);

        self::assertSame(
            200,
            $response->statusCode,
            'getThemeMedia() must stay readable by an anonymous caller — if this ever becomes a '
            . '401 the change was intentional and belongs in this test, not in a passing suite'
        );
    }

    /**
     * Drift detector: EVERY public request handler on the controller must be
     * classified — either admin-gated (in {@see self::adminGatedHandlerProvider()})
     * or deliberately exempt (in {@see self::UNGATED_REQUEST_HANDLERS}).
     *
     * ## Why the enumeration is over handlers and not over `checkAccess()` calls
     *
     * The first version of this test counted `checkAccess()` call sites in the
     * controller source and asserted the count was 2. That population is the
     * WRONG one: it only rises when a handler is added WITH a gate and only falls
     * when an existing gate is removed, so it is structurally blind to a third
     * handler added WITHOUT one — which is precisely the regression class S323
     * exists to prevent. Measured: appending an ungated
     * `purgeThemeMedia(Request $request, array $params)` to the controller left
     * the whole file green; the same method WITH a gate reddened it. A detector
     * that cannot see the defect it is named for reads as a pass.
     *
     * The enumeration is therefore over the population that OUGHT to be gated:
     * every public method declared on the controller that takes a
     * {@see Request}. A new one is unclassified until a human adds it to one of
     * the two HARDCODED lists above, and that edit is the review moment. Only the
     * enumeration is derived from the subject; both lists are hardcoded, so this
     * cannot self-adjust to a regression.
     *
     * ## What "takes a Request" and "public method" mean here, and why
     *
     *  - **Statics are included.** `Router::callHandler()` does
     *    `$instance->$method($request, $params)`, and PHP dispatches that to a
     *    `public static` method without complaint. Excluding statics bought
     *    nothing (there are none on this controller) and left an ungated static
     *    handler invisible — measured.
     *  - **A docblock-declared `Request` counts**, not just a native type; see
     *    {@see self::declaresARequestParameter()}. An untyped parameter with an
     *    `@param Request` docblock passes PHPStan level 9 on src/, so the
     *    analyser does not close that hole either.
     *
     * ⚠ Carries a POSITIVE CONTROL / explicit DENOMINATOR: reflection that
     * returned an empty (or truncated) handler list would make the classification
     * assertion below vacuously true, so the count is ASSERTED against a
     * hardcoded 3 and the enumerated names are carried in that assertion's
     * failure message. It is asserted rather than echoed on purpose —
     * `phpunit.xml` sets `beStrictAboutOutputDuringTests="true"` with
     * `failOnRisky="true"`, so a test that printed anything would fail the suite.
     * Do not read "denominator" here as something that appears in CI output on a
     * green run; nothing is printed. It is visible only when the count is wrong,
     * which is the only moment it matters.
     */
    public function testEveryRequestHandlerIsGatedOrExplicitlyExempt(): void
    {
        $class = new ReflectionClass(ThemeMediaController::class);

        $handlers = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Statics are NOT skipped: `$instance->$method($request, $params)` in
            // Router::callHandler() dispatches to a `public static` method without
            // error, so an ungated static handler is every bit as reachable as an
            // instance one. Skipping them bought nothing (this controller has none)
            // and opened a bypass — measured: an ungated `public static` handler
            // left this test green.
            if ($method->isConstructor()) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== ThemeMediaController::class) {
                continue;
            }
            if ($this->declaresARequestParameter($method)) {
                $handlers[] = $method->getName();
            }
        }
        sort($handlers);

        // POSITIVE CONTROL / DENOMINATOR — an empty or short list would make the
        // classification assertion below pass while measuring nothing.
        self::assertCount(
            3,
            $handlers,
            'expected 3 public Request-taking handlers on ThemeMediaController; reflection '
            . 'enumerated ' . count($handlers) . ': [' . implode(', ', $handlers) . ']. If the '
            . 'controller really did gain or lose a handler, update adminGatedHandlerProvider() '
            . 'or UNGATED_REQUEST_HANDLERS and this count together.'
        );

        /** @var list<string> $gatedRoutes */
        $gatedRoutes = array_column(array_values(self::adminGatedHandlerProvider()), 0);

        // De-duplicated on purpose. Two ROUTES may legitimately alias ONE handler
        // (e.g. a `/rescan` alias for scanThemeMedia), and that must be
        // representable. Merged un-deduplicated it was not: the provider grew a
        // third entry, `$classified` grew to 4 against 3 handlers, and both
        // array_diff() buckets in the message below rendered EMPTY because they
        // dedupe — a permanent red with no stated cause that no edit to either
        // hardcoded list could green. A gate that a CORRECT change cannot satisfy
        // is how a rule gets deleted as noise.
        $gated = array_values(array_unique($gatedRoutes));

        $classified = array_merge($gated, self::UNGATED_REQUEST_HANDLERS);
        sort($classified);

        self::assertSame(
            $classified,
            $handlers,
            'every public Request-taking method of ThemeMediaController must be listed either in '
            . 'adminGatedHandlerProvider() (admin-gated) or in UNGATED_REQUEST_HANDLERS '
            . '(deliberately not gated). Unclassified: ['
            . implode(', ', array_values(array_diff($handlers, $classified)))
            . ']; listed but absent from the controller: ['
            . implode(', ', array_values(array_diff($classified, $handlers)))
            . ']; listed in BOTH lists, which is never right — a handler is gated or exempt, not '
            . 'both: ['
            . implode(', ', array_values(array_intersect($gated, self::UNGATED_REQUEST_HANDLERS)))
            . ']. A NEW UNGATED handler lands in the first bucket — that is the S323 fail-open '
            . 'coming back, and on this controller it admits ANONYMOUS callers.'
        );

        foreach (self::adminGatedHandlerProvider() as $route => [$method]) {
            self::assertTrue(
                method_exists(ThemeMediaController::class, $method),
                "{$route} maps to ThemeMediaController::{$method}(), which must exist"
            );
        }

        // Secondary net: one gate per gated handler, counted over the source.
        // Catches a gate deleted from a still-listed handler.
        $file = $class->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        $callSites = substr_count($source, '$this->adminMiddleware->checkAccess($request)');

        self::assertSame(
            count($gated),
            $callSites,
            'ThemeMediaController has ' . $callSites . ' checkAccess() call sites but '
            . count($gated) . ' DISTINCT handlers are listed as admin-gated.'
        );
    }
}
