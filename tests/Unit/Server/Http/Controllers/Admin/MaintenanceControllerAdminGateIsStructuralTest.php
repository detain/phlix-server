<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use ArgumentCountError;
use Phlix\Admin\Maintenance\MaintenanceJobRepository;
use Phlix\Admin\Maintenance\MaintenanceTaskRunner;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Server\Http\Controllers\Admin\MaintenanceController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Tests\Support\Http\RouterDispatchableHandlers;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * S338 — the {@see AdminMiddleware} dependency of {@see MaintenanceController} is
 * STRUCTURALLY required, and every admin-gated handler is behind it.
 *
 * ## The defect this file exists to make impossible
 *
 * `MaintenanceController` used to hold `private readonly ?AdminMiddleware $adminGuard = null;`
 * — an OPTIONAL constructor parameter — and `requireAdmin()` wrapped its decision in
 * `if ($this->adminGuard === null) { return null; }`. A controller built without
 * the guard (a plain two-argument `new MaintenanceController($jobs, $runner)`)
 * therefore returned "authorised" from `requireAdmin()` **without any admin
 * decision having been taken** — a fail-OPEN that downgraded all eight
 * admin-only handlers, including the two destructive ones
 * (`cleanup-orphaned-stats` deletes rows; `dedupe-paths` merges media items).
 *
 * Production always supplied the guard, so the hole was latent. That is a
 * property of the wiring, not of the class, and the wiring is exactly the thing
 * a future change can get wrong: PHP-DI's `autowire()` SKIPS optional
 * parameters, and this estate has already shipped silently-null dependencies
 * that way. S338 closes the last instance of the family (the S282/S323 recipe,
 * applied to the one controller the tokenized scan still found).
 *
 * ## The exposure class is the logged-in NON-ADMIN, not the anonymous caller
 *
 * `requireAdmin()` (:320-345) checks `$request->userId` FIRST — a null/empty
 * userId is a 401 `auth.required` before the guard is ever consulted. So an
 * anonymous caller is already rejected inside `requireAdmin()`. The fail-open
 * was the NEXT step: with a null guard, a logged-in NON-admin sailed through to
 * the handler body. The anonymous 401 in the behavioural arms below therefore
 * proves less than it looks like proving — the 403 `auth.not_admin` arm is the
 * experiment.
 *
 * ## Two independent nets, because either alone can be defeated
 *
 *  1. **Structural** — reflection over the constructor, the property and the
 *     method list. This is what catches the three ways the optional shape can
 *     come back: a nullable property, a defaulted/omitted constructor parameter,
 *     or a re-introduced setter. A behavioural test alone would NOT catch a
 *     re-added setter, because a setter changes nothing until someone calls it.
 *  2. **Behavioural** — all eight admin-gated handlers driven three ways
 *     (anonymous / authenticated non-admin / admin). The 403 arm is the
 *     experiment; the admin arm is the succeeding control beside it, so a
 *     blanket-deny regression cannot read as a pass. A structural test alone
 *     would not catch `requireAdmin()` being changed to ignore the (still
 *     required) middleware.
 *
 * The enumeration is the S323 drift detector: every public method that
 * {@see Router::callHandler()} would actually dispatch must be classified —
 * see {@see RouterDispatchableHandlers} for what is and is not closed.
 * MaintenanceController has NO deliberately-ungated request handler: all eight
 * handlers route through `requireAdmin()` (three directly, the five actions via
 * `runTask()`, which calls it once), so
 * {@see self::UNGATED_REQUEST_HANDLERS} is empty — a NON-empty list here is a
 * security decision that must come with its own behavioural pin.
 *
 * NB: this file carries NO coverage-metadata annotation, deliberately. Per this
 * repo's policy (S141, enforced by CoverageMetadataPolicyTest) such a marker in
 * `tests/` silently DISCARDS every other file the test executes. The policy check
 * matches the token itself, so it must not be spelled out even in prose.
 */
final class MaintenanceControllerAdminGateIsStructuralTest extends TestCase
{
    use RouterDispatchableHandlers;

    /**
     * Every admin-gated handler on {@see MaintenanceController}, as
     * `[controller method => expected status on the ADMIN arm]`.
     *
     * The admin-arm status is a BODY-ONLY outcome, never one the gate can emit:
     * the reads answer 200/404 off their mocked repositories, and the actions
     * answer 200 (sync, mocked runner result) or 202 (queued, mocked enqueue).
     * The gate emits only 401 and 403, so an admin arm that shows the expected
     * status proves the request reached the handler body rather than being waved
     * through OR refused.
     *
     * Hardcoded on purpose — a derived list would self-adjust to whatever the
     * controller happens to do.
     * {@see self::testEveryRequestHandlerIsGatedOrExplicitlyExempt()} is the drift
     * detector that fails when the controller grows a ninth handler — gated OR
     * ungated.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function adminGatedHandlerProvider(): array
    {
        return [
            'GET  /api/v1/admin/maintenance/tasks'                  => ['tasks', 200],
            'GET  /api/v1/admin/maintenance/jobs'                   => ['jobs', 200],
            'GET  /api/v1/admin/maintenance/jobs/{id}'              => ['job', 404],
            'POST /api/v1/admin/maintenance/storage-snapshot'       => ['storageSnapshot', 202],
            'POST /api/v1/admin/maintenance/reap-scan-jobs'         => ['reapScanJobs', 200],
            'POST /api/v1/admin/maintenance/reap-transcode-jobs'    => ['reapTranscodeJobs', 200],
            'POST /api/v1/admin/maintenance/cleanup-orphaned-stats' => ['cleanupOrphanedStats', 200],
            'POST /api/v1/admin/maintenance/dedupe-paths'           => ['dedupePaths', 202],
        ];
    }

    /**
     * The public request handlers that are deliberately NOT admin-gated.
     *
     * EMPTY on purpose: every request handler on MaintenanceController routes
     * through `requireAdmin()` (the three reads directly, the five actions via
     * `runTask()`). ⚠ Adding a name here is how you declare "this handler needs
     * no admin gate" — a deliberate, reviewable security decision that must come
     * with a behavioural test pinning the intended reachability, never a way to
     * silence {@see self::testEveryRequestHandlerIsGatedOrExplicitlyExempt()}.
     *
     * @var list<string>
     */
    private const UNGATED_REQUEST_HANDLERS = [];

    /**
     * Build a controller whose gate treats exactly `admin-1` as an admin and
     * whose collaborators answer benignly (empty recent list, missing job,
     * created-enqueue, successful sync run).
     */
    private function makeController(): MaintenanceController
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $id === 'admin-1'
                ? ['id' => $id, 'is_admin' => 1, 'status' => 'active']
                : null
        );

        $jobs = $this->createMock(MaintenanceJobRepository::class);
        $jobs->method('recent')->willReturn([]);
        $jobs->method('findById')->willReturn(null);
        $jobs->method('enqueue')->willReturn([
            'created' => true,
            'job' => ['id' => 'job-1', 'status' => 'queued'],
        ]);

        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->method('run')->willReturn(['ok' => true]);

        return new MaintenanceController(
            $jobs,
            $runner,
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
        $ctor = (new ReflectionClass(MaintenanceController::class))->getConstructor();
        self::assertNotNull($ctor, 'MaintenanceController must declare a constructor');

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
            'MaintenanceController::__construct() must take an AdminMiddleware. Setter injection '
            . 'is what made the S282 fail-open possible; do not go back to it.'
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
     * value of `$this->adminGuard` that means "no gate".
     */
    public function testAdminMiddlewarePropertyIsNonNullableAndHasNoDefault(): void
    {
        $property = new ReflectionProperty(MaintenanceController::class, 'adminGuard');

        $type = $property->getType();
        self::assertInstanceOf(
            ReflectionNamedType::class,
            $type,
            'MaintenanceController::$adminGuard must carry a declared type'
        );
        self::assertSame(AdminMiddleware::class, $type->getName());
        self::assertFalse(
            $type->allowsNull(),
            'MaintenanceController::$adminGuard must NOT be nullable — `?AdminMiddleware` is '
            . 'the exact shape S338 removed'
        );
        self::assertFalse(
            $property->hasDefaultValue(),
            'MaintenanceController::$adminGuard must have no default value'
        );
    }

    /**
     * No setter may reintroduce the optional-wiring shape.
     *
     * Checked by SHAPE, not by name alone: any public method taking an
     * AdminMiddleware is a re-opened door, whatever it is called.
     */
    public function testControllerExposesNoAdminMiddlewareSetter(): void
    {
        $class = new ReflectionClass(MaintenanceController::class);

        self::assertFalse(
            $class->hasMethod('setAdminGuard'),
            'setAdminGuard() must not exist — the middleware is constructor-injected (S338)'
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
     * This is the "seen to work" arm: the two-argument construction that the
     * fail-open wiring test used to demonstrate now dies before the object
     * exists.
     *
     * ## Why this goes through reflection rather than writing `new`
     *
     * A literal `new MaintenanceController($a, $b)` is a STATIC arity error, so
     * `phpstan analyse -c phpstan-tests.neon` (tests/, level 2) rejects the file
     * outright with `arguments.count` — and that config forbids inline ignore
     * comments, baselines, `assert()`, inline type overrides and casts, all for
     * good reasons. Suppressing was not an option and neither was deleting the
     * case, so the call is made in a way the arity checker cannot see while the
     * RUNTIME behaviour is identical: `ReflectionClass::newInstanceArgs()`
     * builds the argument list at run time and PHP raises the same
     * `ArgumentCountError` from the same place.
     *
     * ⚠ Reflection is what makes this test worth reading twice, so it carries its
     * own POSITIVE CONTROL: the same reflective construction, given the gate,
     * must succeed. Without that arm an `ArgumentCountError` below could equally
     * be reflection failing for some unrelated reason, and the test would pass
     * while proving nothing.
     */
    public function testConstructingWithoutTheAdminMiddlewareIsAFatalError(): void
    {
        $class = new ReflectionClass(MaintenanceController::class);

        // POSITIVE CONTROL — three arguments, i.e. WITH the gate, must construct.
        $controlError = null;
        try {
            $class->newInstanceArgs([
                $this->createMock(MaintenanceJobRepository::class),
                $this->createMock(MaintenanceTaskRunner::class),
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

        // THE EXPERIMENT — the two-argument construction must be fatal.
        $this->expectException(ArgumentCountError::class);
        $this->expectExceptionMessage('Too few arguments');

        $class->newInstanceArgs([
            $this->createMock(MaintenanceJobRepository::class),
            $this->createMock(MaintenanceTaskRunner::class),
        ]);
    }

    /**
     * `requireAdmin()` must not compare the middleware against null.
     *
     * The required parameter removes the null STATE; this removes the null CHECK,
     * so nobody can re-add `?AdminMiddleware` and find a working guard waiting for
     * it. Asserted over the method's own source lines, TOKENISED with comments
     * removed so that prose quoting the gate cannot stand in for it.
     *
     * Carries its own positive control: the same source slice must contain the
     * `checkAccess()` call. Without it, a pattern that matched nothing — because
     * the slice was empty, or the method was renamed — would read as a pass.
     */
    public function testRequireAdminHasNoNullGuardAroundTheGate(): void
    {
        $method = new ReflectionMethod(MaintenanceController::class, 'requireAdmin');
        $source = $this->methodSourceWithoutComments($method);

        self::assertNotSame('', trim($source), 'could not read requireAdmin() source');

        // Positive control FIRST: prove the slice contains what we think it does.
        self::assertStringContainsString(
            '$this->adminGuard->checkAccess($request)',
            $source,
            'positive control: requireAdmin() must consult the middleware — if this fails, the '
            . 'null-guard assertion below is measuring nothing'
        );

        self::assertDoesNotMatchRegularExpression(
            '/adminGuard\s*(!==|===|!=|==)\s*null|null\s*(!==|===|!=|==)\s*\$this->adminGuard/',
            $source,
            'requireAdmin() must not compare $this->adminGuard against null — that guard IS '
            . 'the S338 fail-open'
        );
    }

    // -----------------------------------------------------------------------
    // Net 2 — behavioural, over all eight handlers
    // -----------------------------------------------------------------------

    /**
     * Arm 1 of 3 — anonymous. Expected 401 `auth.required`.
     *
     * The floor of the control, and on its own worth little: `requireAdmin()`
     * rejects a null `userId` BEFORE the guard is consulted, so even the old
     * fail-open shape answered 401 here. See the class docblock — the 403 arm is
     * the experiment.
     *
     * @dataProvider adminGatedHandlerProvider
     */
    public function testAnonymousCallerIsRefused(string $method): void
    {
        $response = $this->makeController()->{$method}(new Request(), ['id' => 'job-1']);

        self::assertSame(401, $response->statusCode, "{$method}() must 401 an anonymous caller");
        self::assertSame('auth.required', $this->decode($response)['code'] ?? null);
    }

    /**
     * Arm 2 of 3 — AUTHENTICATED NON-ADMIN. Expected 403 `auth.not_admin`.
     *
     * This is the experiment. Before S338 an unwired controller answered this
     * request with the handler's own success response (200/404/202) — the
     * fail-open. The distinct `auth.not_admin` code proves the ADMIN branch
     * decided it, not the auth branch.
     *
     * @dataProvider adminGatedHandlerProvider
     */
    public function testAuthenticatedNonAdminIsRefusedOnTheAdminBranch(string $method): void
    {
        $request = new Request();
        $request->userId = 'user-1'; // only 'admin-1' is an admin

        $response = $this->makeController()->{$method}($request, ['id' => 'job-1']);

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
     * outcome (200 for the reads and sync tasks, 404 for a missing job, 202 for
     * a freshly queued task).
     *
     * The succeeding control beside the refusal: without it a gate that denied
     * every request would pass arms 1 and 2 and look correct.
     *
     * @dataProvider adminGatedHandlerProvider
     */
    public function testAuthenticatedAdminReachesTheHandlerBody(string $method, int $expected): void
    {
        $request = new Request();
        $request->userId = 'admin-1';

        $response = $this->makeController()->{$method}($request, ['id' => 'job-1']);

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
     * Drift detector: EVERY public request handler on the controller must be
     * classified — either admin-gated (in {@see self::adminGatedHandlerProvider()})
     * or deliberately exempt (in {@see self::UNGATED_REQUEST_HANDLERS}, which is
     * empty here).
     *
     * ## Why the enumeration is over handlers and not over `requireAdmin()` calls
     *
     * Counting `requireAdmin()` call sites is structurally blind to a handler
     * added WITHOUT a gate — exactly the regression class S282/S323/S338 exist
     * to prevent (measured in S323 phase 1 on a sibling controller: appending an
     * ungated `Request`-taking handler left the old pin green). The enumeration
     * is therefore over the population that OUGHT to be gated: every public
     * method the router would actually dispatch. A new one is unclassified until
     * a human adds it to the HARDCODED provider above, and that edit is the
     * review moment. Only the enumeration is derived from the subject; the
     * provider is hardcoded, so this cannot self-adjust to a regression.
     *
     * ## What counts as a request handler, and why
     *
     * The population is DERIVED FROM THE DISPATCHER, not from a list of type
     * spellings: {@see RouterDispatchableHandlers::routerWouldDispatch()} asks, of
     * every public method, whether PHP would let the one call
     * `Router::callHandler()` makes — `$instance->$method($request, $params)` —
     * reach its body. Statics count, inherited methods count, and a first
     * parameter typed `mixed`, `object`, `Request|Response`, `?Request` or not at
     * all counts, because every one of those accepts the `Request` the router
     * passes. Do not re-derive the rule here and do not copy a private helper
     * back into this file — one implementation, pinned by
     * {@see \Phlix\Tests\Unit\Support\RouterDispatchableHandlersTest}.
     *
     * ## The denominator and the secondary nets
     *
     * Carries an explicit DENOMINATOR: the enumerated handler set is ASSERTED
     * against a hardcoded 8 and the enumerated names are carried in that
     * assertion's failure message (S345 lesson 3 — a "nothing matched" defence
     * needs its own guard). Asserted rather than echoed on purpose: `phpunit.xml`
     * sets `beStrictAboutOutputDuringTests="true"` with `failOnRisky="true"`, so
     * a test that printed anything would fail the suite. The denominator is
     * visible exactly when it matters — when the count is wrong.
     *
     * Two further nets close what classification cannot see:
     *
     *  - Each gated handler's own TOKENISED source must reach `requireAdmin()`,
     *    either directly or via `runTask()` — a gate deleted from a
     *    still-listed handler fails here.
     *  - A global count of `$this->requireAdmin(` over the whole file is 4 —
     *    `tasks`, `jobs`, `job`, and `runTask()` itself (the three direct gates
     *    plus the one call that gates all five actions). `runTask()` is private,
     *    so no per-handler net covers its gate; the global count is what pins it.
     *    Counted over TOKENISED source with T_COMMENT/T_DOC_COMMENT removed, not
     *    raw bytes — a docblock quoting the literal would inflate the count and
     *    mask the deletion (8 recorded instances of that trap in this estate).
     */
    public function testEveryRequestHandlerIsGatedOrExplicitlyExempt(): void
    {
        $class = new ReflectionClass(MaintenanceController::class);

        $handlers = $this->dispatchableRequestHandlers(MaintenanceController::class);

        // POSITIVE CONTROL / DENOMINATOR — an empty or short list would make the
        // classification assertion below pass while measuring nothing.
        self::assertCount(
            8,
            $handlers,
            'expected 8 public Request-taking handlers on MaintenanceController; reflection '
            . 'enumerated ' . count($handlers) . ': [' . implode(', ', $handlers) . ']. If the '
            . 'controller really did gain or lose a handler, update adminGatedHandlerProvider() '
            . 'or UNGATED_REQUEST_HANDLERS and this count together.'
        );

        /** @var list<string> $gatedRoutes */
        $gatedRoutes = array_column(array_values(self::adminGatedHandlerProvider()), 0);

        // De-duplicated on purpose. Two ROUTES may legitimately alias ONE handler,
        // and that must be representable. Merged un-deduplicated it is not: the
        // provider grows an entry, `$classified` grows past the handler count, and
        // both array_diff() buckets in the message below render EMPTY because they
        // dedupe — a permanent red with no stated cause that no edit to either
        // hardcoded list could green. A gate that a CORRECT change cannot satisfy
        // is how a rule gets deleted as noise.
        $gated = array_values(array_unique($gatedRoutes));

        $classified = array_merge($gated, self::UNGATED_REQUEST_HANDLERS);
        sort($classified);

        self::assertSame(
            $classified,
            $handlers,
            'every public Request-taking method of MaintenanceController must be listed either in '
            . 'adminGatedHandlerProvider() (admin-gated) or in UNGATED_REQUEST_HANDLERS '
            . '(deliberately not gated). Unclassified: ['
            . implode(', ', array_values(array_diff($handlers, $classified)))
            . ']; listed but absent from the controller: ['
            . implode(', ', array_values(array_diff($classified, $handlers)))
            . ']; listed in BOTH lists, which is never right — a handler is gated or exempt, not '
            . 'both: ['
            . implode(', ', array_values(array_intersect($gated, self::UNGATED_REQUEST_HANDLERS)))
            . ']. A NEW UNGATED handler lands in the first bucket — that is the S338 fail-open '
            . 'coming back.'
        );

        foreach (self::adminGatedHandlerProvider() as $route => [$method]) {
            self::assertTrue(
                method_exists(MaintenanceController::class, $method),
                "{$route} maps to MaintenanceController::{$method}(), which must exist"
            );
        }

        $file = $class->getFileName();
        self::assertIsString($file);
        $source = $this->sourceWithoutComments($file);

        // Secondary net 1 — every gated handler's own body reaches the gate,
        // either directly or through the one runTask() path that gates it.
        foreach (self::adminGatedHandlerProvider() as $route => [$method]) {
            $handlerSource = $this->methodSourceWithoutComments(
                new ReflectionMethod(MaintenanceController::class, $method)
            );

            self::assertTrue(
                str_contains($handlerSource, '$this->requireAdmin(')
                    || str_contains($handlerSource, '$this->runTask('),
                "{$method}() ({$route}) must reach requireAdmin() — directly or via runTask(). "
                . 'A gate deleted from a still-listed handler must fail here.'
            );
        }

        // Secondary net 2 — the gate CALL SITES over the whole file: the three
        // direct handlers plus the one inside runTask() that gates the five
        // actions. runTask() is private, so only this global count pins its gate.
        $callSites = substr_count($source, '$this->requireAdmin(');

        self::assertSame(
            4,
            $callSites,
            'MaintenanceController has ' . $callSites . ' requireAdmin() call sites; expected '
            . 'exactly 4 — tasks(), jobs(), job() and runTask() (the five action handlers route '
            . 'through runTask). A gate deleted from runTask() ungates all five actions and '
            . 'must fail here.'
        );
    }
}
