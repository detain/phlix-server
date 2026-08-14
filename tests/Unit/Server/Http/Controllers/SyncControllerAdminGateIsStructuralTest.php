<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use ArgumentCountError;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Server\Arr\CustomFormatSyncer;
use Phlix\Server\Http\Controllers\Arr\SyncController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Shared\Arr\SyncResult;
use Phlix\Tests\Support\Http\RouterDispatchableHandlers;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * S323 phase 2 — the {@see AdminMiddleware} dependency of the TRaSH-Guides
 * {@see SyncController} is STRUCTURALLY required, and all three handlers are
 * behind it.
 *
 * ## The defect this file exists to make impossible
 *
 * `SyncController` used to hold
 * `private ?AdminMiddleware $adminMiddleware = null;` filled by an OPTIONAL
 * `setAdminMiddleware()` setter, and `requireAdmin()` wrapped its decision in
 * `if ($this->adminMiddleware !== null)`. A controller built without the setter
 * therefore returned "authorised" from `requireAdmin()` **without any admin
 * decision having been taken**, leaving all three `/api/v1/admin/sync/*`
 * endpoints — including the one that triggers a full outbound TRaSH-Guides sync
 * and the one that enables or disables it — reachable by any logged-in user.
 *
 * `requireAdmin()` calls `requireAuth()` first, so — unlike S323 phase 1's
 * `ThemeMediaController` — the hole never reached an ANONYMOUS caller. Production
 * always called the setter, so it was latent; that is a property of the wiring,
 * not of the class, and the wiring is exactly the thing a future change can get
 * wrong: PHP-DI's `autowire()` SKIPS optional parameters, and this estate has
 * already shipped silently-null dependencies that way.
 *
 * ## Two independent nets, because either alone can be defeated
 *
 *  1. **Structural** — reflection over the constructor, the property and the
 *     method list, plus a source-level check that `requireAdmin()` does not
 *     compare the middleware against null. A behavioural test alone would NOT
 *     catch a re-added setter, because a setter changes nothing until someone
 *     calls it — measured in S282's M2 mutation and reproduced in phase 1.
 *  2. **Behavioural** — all three handlers driven three ways (anonymous /
 *     authenticated non-admin / admin). The 403 arm is the experiment; the admin
 *     arm is the succeeding control beside it, so a blanket-deny regression cannot
 *     read as a pass. A structural test alone would not catch `requireAdmin()`
 *     being changed to ignore the (still required) middleware. Both refusal arms
 *     additionally assert that NO sync was attempted, so "refused" means the
 *     side effect did not happen, not merely that the status code was right.
 *
 * NB: this file carries NO coverage-metadata annotation, deliberately. Per this
 * repo's policy (S141, enforced by CoverageMetadataPolicyTest) such a marker in
 * `tests/` silently DISCARDS every other file the test executes. The policy check
 * matches the token itself, so it must not be spelled out even in prose.
 */
final class SyncControllerAdminGateIsStructuralTest extends TestCase
{
    use RouterDispatchableHandlers;

    /**
     * Every admin-gated handler on {@see SyncController}, as
     * `[controller method => expected status on the ADMIN arm]`.
     *
     * The admin-arm status is a BODY-ONLY outcome, never one the gate can emit.
     * `triggerSync()` and `getSyncStatus()` answer 200 off the fixture's syncer;
     * `setEnabled()` is driven with an EMPTY body, so it answers 400 on its own
     * missing-field check and never writes. The gate emits only 401 and 403, so an
     * admin arm that shows the expected status proves the request reached the
     * handler body rather than being waved through OR refused.
     *
     * Hardcoded on purpose — a derived list would self-adjust to whatever the
     * controller happens to do.
     * {@see self::testEveryRequestHandlerIsGatedOrExplicitlyExempt()} is the drift
     * detector that fails when the controller grows a fourth handler — gated OR
     * ungated.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function adminGatedHandlerProvider(): array
    {
        return [
            'POST /api/v1/admin/sync/trash-guides' => ['triggerSync', 200],
            'GET  /api/v1/admin/sync/status'       => ['getSyncStatus', 200],
            'PUT  /api/v1/admin/sync/enable'       => ['setEnabled', 400],
        ];
    }

    /**
     * The public request handlers that are deliberately NOT admin-gated.
     *
     * EMPTY: all three endpoints on this controller are operator surfaces — one
     * triggers an outbound TRaSH-Guides sync, one flips the integration on or off,
     * and the status read exposes the operator's configuration state. An empty
     * exempt list makes the classification assertion STRICTER — every handler must
     * be in the gated provider — never laxer, so it is not the "empty allow-list
     * fails open" shape.
     *
     * ⚠ Adding a name here is how you declare "this handler needs no admin gate".
     * It is a deliberate, reviewable security decision and must come with a
     * behavioural test that pins the intended reachability — never a way to silence
     * {@see self::testEveryRequestHandlerIsGatedOrExplicitlyExempt()}.
     *
     * @var list<string>
     */
    private const UNGATED_REQUEST_HANDLERS = [];

    /**
     * How many times the fixture's syncer was asked to run a full sync.
     *
     * Reset by {@see self::makeController()}. The two refusal arms assert this is
     * still 0 AFTER the call, which is the difference between "the status code was
     * 401/403" and "the outbound sync did not happen".
     */
    private int $syncCalls = 0;

    /**
     * Build a controller whose gate treats exactly `admin-1` as an admin, over a
     * syncer that counts the syncs it is asked for and can never be
     * enabled/disabled (see the provider docblock: `setEnabled()` is driven with an
     * empty body, so no arm reaches the write).
     */
    private function makeController(): SyncController
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $id === 'admin-1'
                ? ['id' => $id, 'is_admin' => 1, 'status' => 'active']
                : null
        );

        $this->syncCalls = 0;

        $syncer = $this->createMock(CustomFormatSyncer::class);
        $syncer->method('syncAll')->willReturnCallback(
            function (): SyncResult {
                ++$this->syncCalls;

                return new SyncResult(
                    customFormatsAdded: 0,
                    customFormatsUpdated: 0,
                    qualityProfilesAdded: 0,
                    qualityProfilesUpdated: 0,
                    version: '0.0.0',
                    syncedAt: new DateTimeImmutable()
                );
            }
        );
        // No refused or admitted request may ever flip the integration on or off.
        $syncer->expects(self::never())->method('setEnabled');

        return new SyncController(
            $syncer,
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
        $ctor = (new ReflectionClass(SyncController::class))->getConstructor();
        self::assertNotNull($ctor, 'SyncController must declare a constructor');

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
            'SyncController::__construct() must take an AdminMiddleware. Setter injection '
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
        $property = new ReflectionProperty(SyncController::class, 'adminMiddleware');

        $type = $property->getType();
        self::assertInstanceOf(
            ReflectionNamedType::class,
            $type,
            'SyncController::$adminMiddleware must carry a declared type'
        );
        self::assertSame(AdminMiddleware::class, $type->getName());
        self::assertFalse(
            $type->allowsNull(),
            'SyncController::$adminMiddleware must NOT be nullable — `?AdminMiddleware` is '
            . 'the exact shape S323 removed'
        );
        self::assertFalse(
            $property->hasDefaultValue(),
            'SyncController::$adminMiddleware must have no default value'
        );
    }

    /**
     * No setter may reintroduce the optional-wiring shape.
     *
     * Checked by SHAPE, not by name alone: any public method taking an
     * AdminMiddleware is a re-opened door, whatever it is called.
     *
     * This is the assertion no behavioural test can replace — S282's M2 mutation
     * re-added the setter and the entire behavioural suite stayed green, because a
     * setter changes nothing until someone calls it.
     */
    public function testControllerExposesNoAdminMiddlewareSetter(): void
    {
        $class = new ReflectionClass(SyncController::class);

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
     * ## Why this goes through reflection rather than writing `new`
     *
     * A literal `new SyncController($syncer)` is a STATIC arity
     * error, so `phpstan analyse -c phpstan-tests.neon` (tests/, level 2) rejects
     * the file outright with `arguments.count` — and that config forbids inline
     * ignore comments, baselines, `assert()`, inline type overrides and casts, all
     * for good reasons. (Nor can this docblock spell the ignore annotation out: the
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
        $syncer = $this->createMock(CustomFormatSyncer::class);
        $class = new ReflectionClass(SyncController::class);

        // POSITIVE CONTROL — two arguments, i.e. WITH the gate, must construct.
        $controlError = null;
        try {
            $class->newInstanceArgs([
                $syncer,
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

        // THE EXPERIMENT — the one-argument construction must be fatal.
        $this->expectException(ArgumentCountError::class);
        $this->expectExceptionMessage('Too few arguments');

        $class->newInstanceArgs([$syncer]);
    }

    /**
     * `requireAdmin()` must not compare the middleware against null.
     *
     * The required parameter removes the null STATE; this removes the null CHECK,
     * so nobody can re-add `?AdminMiddleware` and find a working guard waiting for
     * it. Asserted over the method's own source lines.
     *
     * Carries its own positive control: the same source slice must contain the
     * `checkAccess()` call. Without it, a pattern that matched nothing — because
     * the slice was empty, or the method was renamed — would read as a pass.
     */
    public function testRequireAdminHasNoNullGuardAroundTheGate(): void
    {
        $method = new ReflectionMethod(SyncController::class, 'requireAdmin');
        $file = $method->getFileName();
        self::assertIsString($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $start;
        $source = implode("\n", array_slice($lines, $start, $length));

        self::assertNotSame('', trim($source), 'could not read requireAdmin() source');

        // Positive control FIRST: prove the slice contains what we think it does.
        self::assertStringContainsString(
            '$this->adminMiddleware->checkAccess($request)',
            $source,
            'positive control: requireAdmin() must consult the middleware — if this fails, the '
            . 'null-guard assertion below is measuring nothing'
        );

        self::assertDoesNotMatchRegularExpression(
            '/adminMiddleware\s*(!==|===|!=|==)\s*null|null\s*(!==|===|!=|==)\s*\$this->adminMiddleware/',
            $source,
            'requireAdmin() must not compare $this->adminMiddleware against null — that guard IS '
            . 'the S323 fail-open'
        );
    }

    // -----------------------------------------------------------------------
    // Net 2 — behavioural, over all three handlers
    // -----------------------------------------------------------------------

    /**
     * Arm 1 of 3 — anonymous. Expected 401 `auth.required`, and no sync attempted.
     *
     * The status on its own is worth little: `requireAuth()` emits the same 401
     * with no middleware at all. Arm 2 is the experiment. The side-effect
     * assertion is what makes this arm more than a status check.
     *
     * @dataProvider adminGatedHandlerProvider
     */
    public function testAnonymousCallerIsRefused(string $method): void
    {
        $controller = $this->makeController();

        $response = $controller->{$method}(new Request(), []);

        self::assertSame(401, $response->statusCode, "{$method}() must 401 an anonymous caller");
        self::assertSame('auth.required', $this->decode($response)['code'] ?? null);
        self::assertSame(0, $this->syncCalls, "{$method}() must not sync for a refused caller");
    }

    /**
     * Arm 2 of 3 — AUTHENTICATED NON-ADMIN. Expected 403 `auth.not_admin`, and no
     * sync attempted.
     *
     * This is the experiment. Before S323 an unwired controller answered this
     * request with the handler's own 200/400 response — and for `triggerSync()`
     * that meant any logged-in user could start a full outbound sync. The distinct
     * `auth.not_admin` code proves the ADMIN branch decided it, not the auth
     * branch.
     *
     * @dataProvider adminGatedHandlerProvider
     */
    public function testAuthenticatedNonAdminIsRefusedOnTheAdminBranch(string $method): void
    {
        $controller = $this->makeController();

        $request = new Request();
        $request->userId = 'user-1'; // only 'admin-1' is an admin
        $request->body = ['enabled' => true];

        $response = $controller->{$method}($request, []);

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
        self::assertSame(0, $this->syncCalls, "{$method}() must not sync for a refused caller");
    }

    /**
     * Arm 3 of 3 — authenticated ADMIN. Expected the handler's own body-only
     * outcome (200, or 400 for `setEnabled()`'s empty body).
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

        $response = $this->makeController()->{$method}($request, []);

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
     * or deliberately exempt (in {@see self::UNGATED_REQUEST_HANDLERS}).
     *
     * ## Why the enumeration is over handlers and not over `requireAdmin()` calls
     *
     * S282's pin counted `requireAdmin()` call sites in the controller source and
     * asserted the count. That population is the WRONG one: it only rises when a
     * handler is added WITH a gate and only falls when an existing gate is removed,
     * so it is structurally blind to a fourth handler added WITHOUT one — which is
     * precisely the regression class S323 exists to prevent. Measured in phase 1:
     * appending an ungated handler to the controller left the whole file green.
     *
     * The enumeration is therefore over the population that OUGHT to be gated:
     * every public method declared on the controller that takes a {@see Request}. A
     * new one is unclassified until a human adds it to one of the two HARDCODED
     * lists above, and that edit is the review moment. Only the enumeration is
     * derived from the subject; both lists are hardcoded, so this cannot
     * self-adjust to a regression.
     *
     * ## What counts as a request handler, and why
     *
     * The population is DERIVED FROM THE DISPATCHER, not from a list of type
     * spellings: {@see RouterDispatchableHandlers::routerWouldDispatch()} asks, of
     * every public method, whether PHP would let the one call
     * `Router::callHandler()` makes — `$instance->$method($request, $params)` —
     * reach its body. Statics count (PHP dispatches that to a `public static`
     * method without complaint), inherited methods count, and a first parameter
     * typed `mixed`, `object`, `Request|Response`, `?Request` or not at all counts,
     * because every one of those accepts the `Request` the router passes.
     *
     * ⚠ The helper this replaced matched only a native type spelled `Request` or an
     * `@param` mentioning `Request`, and claimed in its own docblock that "the
     * population is closed". It was NOT: `mixed $request` and
     * `Request|Response $request` were each measured slipping through all six
     * copies of it AND through `phpstan analyse -c phpstan.neon.dist` (src/,
     * level 9). Read the trait's docblock for what is and is not closed now. Do not
     * re-derive the rule here and do not copy a private helper back into this file
     * — one implementation, pinned by
     * {@see \Phlix\Tests\Unit\Support\RouterDispatchableHandlersTest}.
     *
     * ⚠ Carries a POSITIVE CONTROL / explicit DENOMINATOR: reflection that returned
     * an empty (or truncated) handler list would make the classification assertion
     * below vacuously true, so the count is ASSERTED against a hardcoded 3 and the
     * enumerated names are carried in that assertion's failure message. It is
     * asserted rather than echoed on purpose — `phpunit.xml` sets
     * `beStrictAboutOutputDuringTests="true"` with `failOnRisky="true"`, so a test
     * that printed anything would fail the suite. Do not read "denominator" here as
     * something that appears in CI output on a green run; nothing is printed. It is
     * visible only when the count is wrong, which is the only moment it matters.
     */
    public function testEveryRequestHandlerIsGatedOrExplicitlyExempt(): void
    {
        $class = new ReflectionClass(SyncController::class);

        $handlers = $this->dispatchableRequestHandlers(SyncController::class);

        // POSITIVE CONTROL / DENOMINATOR — an empty or short list would make the
        // classification assertion below pass while measuring nothing.
        self::assertCount(
            3,
            $handlers,
            'expected 3 public Request-taking handlers on SyncController; reflection '
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
            'every public Request-taking method of SyncController must be listed either in '
            . 'adminGatedHandlerProvider() (admin-gated) or in UNGATED_REQUEST_HANDLERS '
            . '(deliberately not gated). Unclassified: ['
            . implode(', ', array_values(array_diff($handlers, $classified)))
            . ']; listed but absent from the controller: ['
            . implode(', ', array_values(array_diff($classified, $handlers)))
            . ']; listed in BOTH lists, which is never right — a handler is gated or exempt, not '
            . 'both: ['
            . implode(', ', array_values(array_intersect($gated, self::UNGATED_REQUEST_HANDLERS)))
            . ']. A NEW UNGATED handler lands in the first bucket — that is the S323 fail-open '
            . 'coming back.'
        );

        foreach (self::adminGatedHandlerProvider() as $route => [$method]) {
            self::assertTrue(
                method_exists(SyncController::class, $method),
                "{$route} maps to SyncController::{$method}(), which must exist"
            );
        }

        // Secondary net: one requireAdmin() call per gated handler, counted over
        // the source. Catches a gate deleted from a still-listed handler.
        //
        // Counted over TOKENISED source with T_COMMENT/T_DOC_COMMENT removed, not
        // over raw bytes: a docblock quoting the literal would otherwise inflate
        // the count and mask exactly the deletion this net exists to catch. That
        // trap — a step's own comment recreating the string a check counts — has 8
        // recorded instances in this estate.
        $file = $class->getFileName();
        self::assertIsString($file);
        $source = $this->sourceWithoutComments($file);

        $callSites = substr_count($source, '$this->requireAdmin($request)');

        self::assertSame(
            count($gated),
            $callSites,
            'SyncController has ' . $callSites . ' requireAdmin() call sites but '
            . count($gated) . ' DISTINCT handlers are listed as admin-gated.'
        );
    }
}
