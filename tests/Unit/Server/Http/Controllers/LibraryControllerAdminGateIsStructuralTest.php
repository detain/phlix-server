<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use ArgumentCountError;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Server\Http\Controllers\LibraryController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Tests\Support\Http\RouterDispatchableHandlers;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * S282 — the {@see AdminMiddleware} dependency of {@see LibraryController} is
 * STRUCTURALLY required, and every admin-gated handler is behind it.
 *
 * ## The defect this file exists to make impossible
 *
 * `LibraryController` used to hold `private ?AdminMiddleware $adminMiddleware = null;`
 * filled by an OPTIONAL `setAdminMiddleware()` setter, and `requireAdmin()` wrapped
 * its decision in `if ($this->adminMiddleware !== null)`. A controller built without
 * the setter therefore returned "authorised" from `requireAdmin()` **without any
 * admin decision having been taken** — a fail-OPEN that downgraded fourteen
 * admin-only handlers, including `delete-all`, to auth-only.
 *
 * Production always called the setter, so the hole was latent. That is a property
 * of the wiring, not of the class, and the wiring is exactly the thing a future
 * change can get wrong: PHP-DI's `autowire()` SKIPS optional parameters, and this
 * estate has already shipped silently-null dependencies that way.
 *
 * ## Two independent nets, because either alone can be defeated
 *
 *  1. **Structural** — reflection over the constructor, the property and the
 *     method list. This is what catches the three ways the optional shape can
 *     come back: a nullable property, a defaulted/omitted constructor parameter,
 *     or a re-introduced setter. A behavioural test alone would NOT catch a
 *     re-added setter, because a setter changes nothing until someone calls it.
 *  2. **Behavioural** — all fourteen admin-gated handlers driven three ways
 *     (anonymous / authenticated non-admin / admin). The 403 arm is the
 *     experiment; the admin arm is the succeeding control beside it, so a
 *     blanket-deny regression cannot read as a pass. A structural test alone
 *     would not catch `requireAdmin()` being changed to ignore the (still
 *     required) middleware.
 *
 * ## S323 phase 2 — the drift detector was re-based on a WIDER enumeration
 *
 * As shipped, {@see self::testEveryRequireAdminCallSiteIsListed()} counted
 * `requireAdmin()` CALL SITES in the controller source and asserted the count was
 * 14. That population is the WRONG one: it only rises when a handler is added
 * WITH a gate, and only falls when an existing gate is removed, so it is
 * structurally blind to a FIFTEENTH handler added WITHOUT one — which is exactly
 * the regression class this file exists to prevent. S323 phase 1 measured that
 * blindness on the sibling controller: appending an ungated
 * `Request`-taking handler left the whole pin green.
 *
 * It is now {@see self::testEveryRequestHandlerIsGatedOrExplicitlyExempt()},
 * enumerating every public (including `static` and inherited) method that
 * `Router::callHandler()` would actually dispatch — see
 * {@see RouterDispatchableHandlers} — and requiring each
 * to appear in exactly one of two HARDCODED lists:
 * {@see self::adminGatedHandlerProvider()} or
 * {@see self::UNGATED_REQUEST_HANDLERS}. The call-site count survives as a
 * SECONDARY net (it still catches a gate deleted from a still-listed handler).
 * Nothing the old assertion detected was traded away.
 *
 * NB: this file carries NO coverage-metadata annotation, deliberately. Per this
 * repo's policy (S141, enforced by CoverageMetadataPolicyTest) such a marker in
 * `tests/` silently DISCARDS every other file the test executes. The policy check
 * matches the token itself, so it must not be spelled out even in prose.
 */
final class LibraryControllerAdminGateIsStructuralTest extends TestCase
{
    use RouterDispatchableHandlers;

    /**
     * Every admin-gated handler on {@see LibraryController}, as
     * `[controller method => expected status on the ADMIN arm]`.
     *
     * The admin-arm status is a BODY-ONLY outcome, never one the gate can emit:
     * thirteen of the handlers look their library up first and the fixture makes
     * `getLibrary()` return null, so they answer 404; `create()` does not read a
     * library and answers 400 for an empty body. The gate emits only 401 and 403,
     * so an admin arm that shows the expected status proves the request reached
     * the handler body rather than being waved through OR refused.
     *
     * Hardcoded on purpose — a derived list would self-adjust to whatever the
     * controller happens to do.
     * {@see self::testEveryRequestHandlerIsGatedOrExplicitlyExempt()} is the drift
     * detector that fails when the controller grows a fifteenth handler — gated OR
     * ungated.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function adminGatedHandlerProvider(): array
    {
        return [
            'POST   /api/v1/libraries'                        => ['create', 400],
            'PUT    /api/v1/libraries/{id}'                   => ['update', 404],
            'DELETE /api/v1/libraries/{id}'                   => ['delete', 404],
            'POST   /api/v1/libraries/{id}/scan'              => ['scan', 404],
            'POST   /api/v1/libraries/{id}/rescan'            => ['rescan', 404],
            'POST   /api/v1/libraries/{id}/match-metadata'    => ['matchMetadata', 404],
            'POST   /api/v1/libraries/{id}/refresh-metadata'  => ['refreshMetadata', 404],
            'POST   /api/v1/libraries/{id}/prune'             => ['prune', 404],
            'POST   /api/v1/libraries/{id}/clear-metadata'    => ['clearMetadata', 404],
            'POST   /api/v1/libraries/{id}/clear-artwork'     => ['clearArtwork', 404],
            'POST   /api/v1/libraries/{id}/delete-all'        => ['deleteAll', 404],
            'POST   /api/v1/libraries/{id}/regenerate-assets' => ['regenerateAssets', 404],
            'GET    /api/v1/libraries/{id}/scan-status'       => ['scanStatus', 404],
            'GET    /api/v1/libraries/{id}/scan-history'      => ['scanHistory', 404],
        ];
    }

    /**
     * The public request handlers that are deliberately NOT admin-gated.
     *
     * Exactly two, and both are READS: `index()` (list libraries) and `show()`
     * (one library). Neither writes on any path — both call `requireAuth()` and
     * then only `LibraryManager::getAllLibraries()` / `getLibrary()` plus an
     * optional `ItemRepository::countByType()`. They are AUTH-gated, not
     * ADMIN-gated, and {@see self::testTheReadHandlersAreAuthOnlyNotAdminOnly()}
     * pins that intended reachability in both directions: an anonymous caller is
     * refused, an authenticated NON-admin is admitted.
     *
     * ⚠ Adding a name here is how you declare "this handler needs no admin gate".
     * It is a deliberate, reviewable security decision and must come with a
     * behavioural test that pins the intended reachability — never a way to
     * silence {@see self::testEveryRequestHandlerIsGatedOrExplicitlyExempt()}.
     *
     * @var list<string>
     */
    private const UNGATED_REQUEST_HANDLERS = ['index', 'show'];

    /**
     * Build a controller whose gate treats exactly `admin-1` as an admin and
     * whose `getLibrary()` resolves to nothing (see the provider docblock).
     */
    private function makeController(): LibraryController
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $id === 'admin-1'
                ? ['id' => $id, 'is_admin' => 1, 'status' => 'active']
                : null
        );

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->willReturn(null);

        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects(self::never())->method('enqueue');

        return new LibraryController(
            $libraryManager,
            $scanJobs,
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
        $ctor = (new ReflectionClass(LibraryController::class))->getConstructor();
        self::assertNotNull($ctor, 'LibraryController must declare a constructor');

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
            'LibraryController::__construct() must take an AdminMiddleware. Setter injection '
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
     * value of `$this->adminMiddleware` that means "no gate".
     */
    public function testAdminMiddlewarePropertyIsNonNullableAndHasNoDefault(): void
    {
        $property = new ReflectionProperty(LibraryController::class, 'adminMiddleware');

        $type = $property->getType();
        self::assertInstanceOf(
            ReflectionNamedType::class,
            $type,
            'LibraryController::$adminMiddleware must carry a declared type'
        );
        self::assertSame(AdminMiddleware::class, $type->getName());
        self::assertFalse(
            $type->allowsNull(),
            'LibraryController::$adminMiddleware must NOT be nullable — `?AdminMiddleware` is '
            . 'the exact shape S282 removed'
        );
        self::assertFalse(
            $property->hasDefaultValue(),
            'LibraryController::$adminMiddleware must have no default value'
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
        $class = new ReflectionClass(LibraryController::class);

        self::assertFalse(
            $class->hasMethod('setAdminMiddleware'),
            'setAdminMiddleware() must not exist — the middleware is constructor-injected (S282)'
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
     * This is the "seen to work" arm: the two-argument construction that 75 tests
     * and one production fallback used to make now dies before the object exists.
     *
     * ## Why this goes through reflection rather than writing `new`
     *
     * A literal `new LibraryController($a, $b)` is a STATIC arity error, so
     * `phpstan analyse -c phpstan-tests.neon` (tests/, level 2) rejects the file
     * outright with `arguments.count` — and that config forbids inline ignore
     * comments, baselines, `assert()`, inline type overrides and casts, all for
     * good reasons. (Nor can this docblock spell the ignore annotation out: the
     * analyser parses the token wherever it appears, including in prose, and
     * answers with `ignore.parseError`.) Suppressing was not an option and
     * neither was deleting the case, so the call is made in a way the arity
     * checker cannot see while the RUNTIME behaviour is identical:
     * `ReflectionClass::newInstanceArgs()` builds the argument list at run time
     * and PHP raises the same `ArgumentCountError` from the same place.
     *
     * ⚠ Reflection is what makes this test worth reading twice, so it carries its
     * own POSITIVE CONTROL: the same reflective construction, given the gate,
     * must succeed. Without that arm an `ArgumentCountError` below could equally
     * be reflection failing for some unrelated reason, and the test would pass
     * while proving nothing.
     */
    public function testConstructingWithoutTheAdminMiddlewareIsAFatalError(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $class = new ReflectionClass(LibraryController::class);

        // POSITIVE CONTROL — three arguments, i.e. WITH the gate, must construct.
        $controlError = null;
        try {
            $class->newInstanceArgs([
                $libraryManager,
                $scanJobs,
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

        $class->newInstanceArgs([$libraryManager, $scanJobs]);
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
        $method = new ReflectionMethod(LibraryController::class, 'requireAdmin');
        // Review round 2, finding 5: this slice is TOKENISED and its comments
        // dropped, exactly like the counting net further down. Read raw, an
        // inline comment quoting the gate would satisfy the positive control
        // below with the real call deleted — the same trap, in the same file.
        $source = $this->methodSourceWithoutComments($method);

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
            . 'the S282 fail-open'
        );
    }

    // -----------------------------------------------------------------------
    // Net 2 — behavioural, over all fourteen handlers
    // -----------------------------------------------------------------------

    /**
     * Arm 1 of 3 — anonymous. Expected 401 `auth.required`.
     *
     * The floor of the control, and on its own worth little: a plain auth check
     * emits the same 401.
     *
     * @dataProvider adminGatedHandlerProvider
     */
    public function testAnonymousCallerIsRefused(string $method): void
    {
        $response = $this->makeController()->{$method}(new Request(), ['id' => 'lib-1']);

        self::assertSame(401, $response->statusCode, "{$method}() must 401 an anonymous caller");
        self::assertSame('auth.required', $this->decode($response)['code'] ?? null);
    }

    /**
     * Arm 2 of 3 — AUTHENTICATED NON-ADMIN. Expected 403 `auth.not_admin`.
     *
     * This is the experiment. Before S282 an unwired controller answered this
     * request with the handler's own success/404 response; the distinct
     * `auth.not_admin` code proves the ADMIN branch decided it, not the auth branch.
     *
     * @dataProvider adminGatedHandlerProvider
     */
    public function testAuthenticatedNonAdminIsRefusedOnTheAdminBranch(string $method): void
    {
        $request = new Request();
        $request->userId = 'user-1'; // only 'admin-1' is an admin
        $request->body = ['confirm' => true, 'name' => 'X', 'type' => 'movie', 'paths' => ['/x']];

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
     * outcome (404 library-missing, or 400 for `create()`'s empty body).
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
     * The two READS stay AUTH-only, and stay so for a REASON that is asserted.
     *
     * `index()` and `show()` are the entries in
     * {@see self::UNGATED_REQUEST_HANDLERS}, and an exempt list without a
     * behavioural pin is just a way to silence the drift detector. Both directions
     * are asserted here:
     *
     *  - an ANONYMOUS caller is still refused (401 `auth.required`) — they are
     *    exempt from the ADMIN gate, not from authentication; and
     *  - an authenticated NON-ADMIN is ADMITTED (the handler's own 200/404) —
     *    which is also the negative control for the three arms above: it proves
     *    their 403s come from the admin gate on the fourteen mutations and not
     *    from something global.
     *
     * A future "make everything admin-only" sweep therefore has to be a deliberate
     * edit to this file rather than a silent behaviour change.
     */
    public function testTheReadHandlersAreAuthOnlyNotAdminOnly(): void
    {
        $expectedForNonAdmin = ['index' => 200, 'show' => 404]; // 404 = library missing

        foreach (self::UNGATED_REQUEST_HANDLERS as $handler) {
            $anonymous = $this->makeController()->{$handler}(new Request(), ['id' => 'lib-1']);
            self::assertSame(
                401,
                $anonymous->statusCode,
                "{$handler}() is exempt from the ADMIN gate, not from authentication"
            );

            $request = new Request();
            $request->userId = 'user-1'; // only 'admin-1' is an admin
            $response = $this->makeController()->{$handler}($request, ['id' => 'lib-1']);

            self::assertSame(
                $expectedForNonAdmin[$handler],
                $response->statusCode,
                "{$handler}() must stay readable by an authenticated NON-admin — if this ever "
                . 'becomes a 403 the change was intentional and belongs in this file, not in a '
                . 'passing suite'
            );
            self::assertNotSame(
                'auth.not_admin',
                $this->decode($response)['code'] ?? null,
                "{$handler}() must not refuse a non-admin on the admin branch"
            );
        }
    }

    /**
     * Drift detector: EVERY public request handler on the controller must be
     * classified — either admin-gated (in {@see self::adminGatedHandlerProvider()})
     * or deliberately exempt (in {@see self::UNGATED_REQUEST_HANDLERS}).
     *
     * ## Why the enumeration is over handlers and not over `requireAdmin()` calls
     *
     * The first version of this test counted `requireAdmin()` call sites in the
     * controller source and asserted the count was 14. That population is the
     * WRONG one: it only rises when a handler is added WITH a gate and only falls
     * when an existing gate is removed, so it is structurally blind to a fifteenth
     * handler added WITHOUT one — which is precisely the regression class S282 and
     * S323 exist to prevent. Measured in S323 phase 1 on the sibling controller:
     * appending an ungated `Request`-taking handler left the whole file green; the
     * same handler WITH a gate reddened it. A detector that cannot see the defect
     * it is named for reads as a pass. This controller made it worse than the
     * sibling did — the blind population here is ~16 methods, not 3.
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
     * below vacuously true, so the count is ASSERTED against a hardcoded 16 and the
     * enumerated names are carried in that assertion's failure message. It is
     * asserted rather than echoed on purpose — `phpunit.xml` sets
     * `beStrictAboutOutputDuringTests="true"` with `failOnRisky="true"`, so a test
     * that printed anything would fail the suite. Do not read "denominator" here as
     * something that appears in CI output on a green run; nothing is printed. It is
     * visible only when the count is wrong, which is the only moment it matters.
     */
    public function testEveryRequestHandlerIsGatedOrExplicitlyExempt(): void
    {
        $class = new ReflectionClass(LibraryController::class);

        $handlers = $this->dispatchableRequestHandlers(LibraryController::class);

        // POSITIVE CONTROL / DENOMINATOR — an empty or short list would make the
        // classification assertion below pass while measuring nothing.
        self::assertCount(
            16,
            $handlers,
            'expected 16 public Request-taking handlers on LibraryController; reflection '
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
            'every public Request-taking method of LibraryController must be listed either in '
            . 'adminGatedHandlerProvider() (admin-gated) or in UNGATED_REQUEST_HANDLERS '
            . '(deliberately not gated). Unclassified: ['
            . implode(', ', array_values(array_diff($handlers, $classified)))
            . ']; listed but absent from the controller: ['
            . implode(', ', array_values(array_diff($classified, $handlers)))
            . ']; listed in BOTH lists, which is never right — a handler is gated or exempt, not '
            . 'both: ['
            . implode(', ', array_values(array_intersect($gated, self::UNGATED_REQUEST_HANDLERS)))
            . ']. A NEW UNGATED handler lands in the first bucket — that is the S282 fail-open '
            . 'coming back.'
        );

        foreach (self::adminGatedHandlerProvider() as $route => [$method]) {
            self::assertTrue(
                method_exists(LibraryController::class, $method),
                "{$route} maps to LibraryController::{$method}(), which must exist"
            );
        }

        // Secondary net, kept from the original detector: one requireAdmin() call
        // per gated handler, counted over the source. Catches a gate deleted from a
        // still-listed handler.
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
            'LibraryController has ' . $callSites . ' requireAdmin() call sites but '
            . count($gated) . ' DISTINCT handlers are listed as admin-gated.'
        );
    }
}
