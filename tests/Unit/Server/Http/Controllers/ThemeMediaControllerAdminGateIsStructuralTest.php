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
     * controller happens to do. {@see self::testEveryCheckAccessCallSiteIsListed()}
     * is the drift detector that fails when the controller grows a third.
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
     * Drift detector: the provider must list EVERY `checkAccess()` call site in
     * the controller.
     *
     * Counted over the controller's source so a third gated handler cannot be
     * added without this file being updated. The count is the only thing derived
     * from the subject; the handler list and its expectations above are hardcoded,
     * so this cannot self-adjust to a regression.
     */
    public function testEveryCheckAccessCallSiteIsListed(): void
    {
        $file = (new ReflectionClass(ThemeMediaController::class))->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        $callSites = substr_count($source, '$this->adminMiddleware->checkAccess($request)');

        self::assertSame(
            2,
            $callSites,
            'ThemeMediaController has ' . $callSites . ' checkAccess() call sites but this file '
            . 'covers 2. Add the new handler to adminGatedHandlerProvider().'
        );
        self::assertCount(2, self::adminGatedHandlerProvider());

        foreach (self::adminGatedHandlerProvider() as $route => [$method]) {
            self::assertTrue(
                method_exists(ThemeMediaController::class, $method),
                "{$route} maps to ThemeMediaController::{$method}(), which must exist"
            );
        }
    }
}
