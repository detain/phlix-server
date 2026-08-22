<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use Countable;
use FilesystemIterator;
use LogicException;
use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Tests\Support\Http\RouterDispatchableHandlers;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;
use TypeError;

/**
 * S323 review round 1 — the pin under {@see RouterDispatchableHandlers}, the
 * shared "is this controller method a route handler" predicate that all six
 * `*AdminGateIsStructuralTest` files depend on.
 *
 * ## Why this file exists
 *
 * The predicate used to live as six verbatim private copies of a
 * `declaresARequestParameter()` helper. It matched a `ReflectionNamedType`
 * spelled `Request`, or an `@param` mentioning `Request` — and its own docblock
 * claimed the population was CLOSED. It was not. Two spellings were measured
 * escaping all six pins while `phpstan analyse -c phpstan.neon.dist` still
 * answered `[OK]`:
 *
 *     public function purgeAllWebhooks(mixed $request, array $params): Response
 *     public function purgeAllWebhooks(Request|Response $request, array $params): Response
 *
 * Both are dispatched by `Router::callHandler()` with a real `Request`, so an
 * ungated handler in either spelling would have shipped green. A predicate that
 * a handler can walk through is worth nothing, and a docblock asserting closure
 * in prose is how that survived. Hence: one implementation, and this file
 * measuring it against every shape rather than describing it.
 *
 * ## Structure
 *
 * {@see RouterDispatchFixtureController} carries every shape the predicate has
 * to judge — the two escapers first — and this test asserts the WHOLE
 * dispatchable set against a hardcoded list. That set assertion is the
 * denominator: a predicate that answered `false` for everything, or reflection
 * that enumerated nothing, fails here rather than reading as a pass.
 *
 * NB: no coverage-metadata annotation, deliberately (S141 policy — such a
 * marker silently discards every other file the test executes).
 */
final class RouterDispatchableHandlersTest extends TestCase
{
    use RouterDispatchableHandlers;

    /**
     * Root of the tree holding the controller admin-gate pins that MUST share
     * this predicate. Searched RECURSIVELY — see
     * {@see self::testEveryAdminGatePinSharesThisPredicate()} for why a flat
     * `glob()` was not enough.
     */
    private const PIN_DIRECTORY = __DIR__ . '/../Server/Http/Controllers';

    /**
     * The PSR-4 namespace {@see self::PIN_DIRECTORY} maps to. Subdirectories
     * extend it, so a pin at `Controllers/Admin/FooPin.php` is
     * `…\Controllers\Admin\FooPin`.
     */
    private const PIN_NAMESPACE = 'Phlix\\Tests\\Unit\\Server\\Http\\Controllers';

    /**
     * Filename suffix that marks a file as an admin-gate structural pin.
     */
    private const PIN_SUFFIX = 'AdminGateIsStructuralTest.php';

    /**
     * The complete set {@see RouterDispatchableHandlers} enumerates for
     * {@see RouterDispatchFixtureController}, hardcoded.
     *
     * Hardcoded and compared WHOLE, not spot-checked: a per-shape assertion
     * would let a predicate that over- or under-included some OTHER method pass.
     *
     * ⚠ This is a SUPERSET of what `Router::callHandler()` would really drive,
     * deliberately, and saying otherwise is the mistake this file was written to
     * correct. What is true of every name here is narrower: PHP refuses none of
     * them on the strength of the DECLARED TYPE of parameter #1 or #2, which is
     * the only question `routerWouldDispatch()` asks. Exactly one entry —
     * `variadicRequest` — is a measured over-inclusion the router would actually
     * refuse; see
     * {@see self::testTheVariadicShapeIsAKnownFailSafeOverInclusion()}, which
     * pins that gap by CALLING it rather than describing it. Over-inclusion is
     * the safe direction: it forces a handler to be classified.
     *
     * Every public method of the fixture absent from this list is one PHP would
     * refuse to call, and each of those carries a comment saying which refusal.
     *
     * @return list<string>
     */
    private static function expectedDispatchableHandlers(): array
    {
        return [
            'inheritedHandler',
            'iterableSecondParameter',
            'mixedFirstParameter',
            'mixedSecondParameter',
            'nativeRequest',
            'noParameters',
            'nullableRequest',
            'objectFirstParameter',
            'onlyTheRequest',
            'optionalThirdParameter',
            'staticHandler',
            'unionContainingRequest',
            'untypedFirstParameter',
            'untypedSecondParameter',
            'variadicRequest',
        ];
    }

    /**
     * The whole population, in one assertion, with its denominator asserted
     * first.
     *
     * ⚠ Both escaping spellings that motivated this file — `mixedFirstParameter`
     * and `unionContainingRequest` — are in the expected list, so this test is
     * RED for the pre-fix predicate. That is the point: it is not a description
     * of the current behaviour, it is the measurement that the behaviour changed.
     */
    public function testEveryShapeTheRouterCanDispatchIsEnumerated(): void
    {
        $handlers = $this->dispatchableRequestHandlers(RouterDispatchFixtureController::class);
        $expected = self::expectedDispatchableHandlers();

        // DENOMINATOR — an empty or truncated enumeration would make the set
        // comparison below a comparison of two nothings.
        self::assertCount(
            15,
            $handlers,
            'expected 15 dispatchable handlers on the fixture; got ' . count($handlers)
            . ': [' . implode(', ', $handlers) . ']'
        );

        self::assertSame(
            $expected,
            $handlers,
            'the predicate must enumerate exactly the fixture methods Router::callHandler() would '
            . 'drive. Missed (a FAIL-OPEN — an ungated handler in this shape would ship green): ['
            . implode(', ', array_values(array_diff($expected, $handlers)))
            . ']; over-included (noise, not a hole): ['
            . implode(', ', array_values(array_diff($handlers, $expected)))
            . ']'
        );
    }

    /**
     * The two shapes measured escaping the old predicate, named individually so
     * a failure says WHICH regression came back.
     *
     * @dataProvider escapingShapeProvider
     */
    public function testTheShapesThatEscapedTheOldPredicateAreDispatchable(string $method): void
    {
        self::assertTrue(
            $this->routerWouldDispatch(new ReflectionMethod(RouterDispatchFixtureController::class, $method)),
            "{$method}() is dispatched by Router::callHandler() with a real Request — an ungated "
            . 'handler in this spelling must never be invisible to the admin-gate pins again'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function escapingShapeProvider(): array
    {
        return [
            'mixed $request'            => ['mixedFirstParameter'],
            'Request|Response $request' => ['unionContainingRequest'],
        ];
    }

    /**
     * The negative arm, named per shape, with the refusal PHP would raise.
     *
     * Without this, a predicate hardwired to `return true` would pass every
     * other test in this file.
     *
     * @dataProvider undispatchableShapeProvider
     */
    public function testShapesPhpWouldRefuseAreNotEnumerated(string $method, string $why): void
    {
        self::assertFalse(
            $this->routerWouldDispatch(new ReflectionMethod(RouterDispatchFixtureController::class, $method)),
            "{$method}() must not be enumerated: {$why}"
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function undispatchableShapeProvider(): array
    {
        return [
            'array first' => ['wrongParameterOrder', 'parameter #1 is an array; the Request TypeErrors'],
            'scalar first' => ['scalarFirstParameter', 'parameter #1 is a string; the Request TypeErrors'],
            'unrelated class' => ['unrelatedClassFirst', 'parameter #1 is a Response; the Request TypeErrors'],
            'unsatisfiable and' => ['intersectionFirstParameter', 'Request does not satisfy Request&Countable'],
            'three required' => ['threeRequiredParameters', 'the router passes two arguments; ArgumentCountError'],
            'scalar second' => ['scalarSecondParameter', 'parameter #2 is a string; the params array TypeErrors'],
            'docblock, later param' => [
                'docblockTypesALaterParameter',
                'PHP ignores docblocks, and the router passes the Request FIRST, where an array stands',
            ],
        ];
    }

    /**
     * ⚠ Review round 2, finding 1 — the ONE known over-inclusion, MEASURED
     * rather than described.
     *
     * `variadicRequest(Request ...$requests)` is enumerated as dispatchable, and
     * PHP would in fact REFUSE the router's call: a variadic's declared type
     * governs every argument it collects, so the `array $params` the router
     * passes SECOND lands on the `Request` type and `TypeError`s.
     * `routerWouldDispatch()` inspects `$parameters[0]` and `$parameters[1]` and
     * never notices that a variadic occupying slot #1 also owns slot #2.
     *
     * This is deliberately NOT fixed in the predicate. The direction is
     * fail-SAFE — over-inclusion forces a CLASSIFICATION, it cannot hide an
     * ungated handler — and no route handler in `src/` is written in this shape.
     * What is not acceptable is a docblock claiming a closure the code does not
     * have: that is precisely how the original six-copy defect survived. So the
     * gap is pinned here instead. If a future change makes the predicate exact,
     * this test fails and says which line to update.
     */
    public function testTheVariadicShapeIsAKnownFailSafeOverInclusion(): void
    {
        self::assertTrue(
            $this->routerWouldDispatch(
                new ReflectionMethod(RouterDispatchFixtureController::class, 'variadicRequest')
            ),
            'the predicate over-includes variadicRequest() today. If it no longer does, remove the '
            . 'name from expectedDispatchableHandlers() and retire this test together.'
        );

        $controller = new RouterDispatchFixtureController();
        $request = new Request();

        // POSITIVE CONTROL — the same dynamic call style Router::callHandler()
        // uses DOES reach a real handler's body (the fixture bodies throw), so
        // the TypeError below is a fact about the variadic and not about the way
        // this test invokes things.
        $control = 'nativeRequest';
        $reachedTheBody = false;

        try {
            $controller->$control($request, []);
        } catch (LogicException) {
            $reachedTheBody = true;
        }

        self::assertTrue(
            $reachedTheBody,
            'control: $instance->nativeRequest($request, []) must reach the fixture body — if it '
            . 'does not, the refusal asserted below proves nothing about variadics'
        );

        // THE MEASUREMENT — PHP refuses the router's exact call.
        $subject = 'variadicRequest';

        $this->expectException(TypeError::class);

        $controller->$subject($request, []);
    }

    /**
     * Non-public methods are not dispatchable, whatever their signature.
     */
    public function testNonPublicMethodsAreNotEnumerated(): void
    {
        $handlers = $this->dispatchableRequestHandlers(RouterDispatchFixtureController::class);

        self::assertNotContains('protectedHandler', $handlers);
        self::assertNotContains('privateHandler', $handlers);

        // Positive control beside the two exclusions: the same fixture DOES
        // expose a public handler with the identical signature, so these two
        // absences are about visibility and nothing else.
        self::assertContains('nativeRequest', $handlers);
    }

    /**
     * The constructor is excluded — the router never calls it — and that
     * exclusion is by `isConstructor()`, not by signature.
     */
    public function testTheConstructorIsNotEnumerated(): void
    {
        $handlers = $this->dispatchableRequestHandlers(RouterDispatchFixtureController::class);

        self::assertNotContains('__construct', $handlers);
    }

    /**
     * Finding 3 — the secondary net must count over source with comments
     * REMOVED. A docblock quoting the counted literal would otherwise inflate
     * the count and mask a gate deleted from a still-listed handler.
     */
    public function testCommentsAreStrippedBeforeCounting(): void
    {
        $source = <<<'PHP'
        <?php
        class C
        {
            /**
             * Calls $this->requireAdmin($request) — this line is PROSE and must
             * not be counted.
             */
            public function handler(): void
            {
                $this->requireAdmin($request); // and $this->requireAdmin($request) again, in a comment
            }
        }
        PHP;

        $literal = '$this->requireAdmin($request)';

        // POSITIVE CONTROL — the raw source really does over-count, so the
        // assertion below is measuring the stripper and not an artefact.
        self::assertSame(
            3,
            substr_count($source, $literal),
            'control: the raw fixture must contain the literal three times (once in code, twice in '
            . 'comments) — otherwise this test proves nothing about stripping'
        );

        self::assertSame(
            1,
            substr_count($this->stripComments($source), $literal),
            'only the CODE occurrence may survive comment stripping'
        );
    }

    /**
     * ⚠ Review round 2, finding 5 — the per-METHOD slice each pin asserts over
     * really is stripped.
     *
     * The pins' positive control ("`requireAdmin()` must contain the
     * `checkAccess()` call") and their negative null-guard regex both read this
     * slice. If the stripping silently did nothing — which is exactly what
     * happens when a fragment reaches `token_get_all()` without an open tag, as
     * it all comes back as one `T_INLINE_HTML` token — the assertions would
     * quietly revert to raw-byte behaviour and nothing would say so.
     *
     * Carries its own control: the RAW slice must contain the comment marker,
     * otherwise the absence asserted afterwards proves nothing.
     */
    public function testMethodSourceWithoutCommentsStripsTheSlice(): void
    {
        $method = new ReflectionMethod(MethodSliceFixture::class, 'gated');

        $file = $method->getFileName();
        self::assertIsString($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $start = $method->getStartLine() - 1;
        $raw = implode("\n", array_slice($lines, $start, $method->getEndLine() - $start));

        // CONTROL — the raw slice carries BOTH markers, so the disappearance of
        // one below is the stripper's doing.
        self::assertStringContainsString('MARKER_IN_A_COMMENT', $raw);
        self::assertStringContainsString('MARKER_IN_CODE', $raw);

        $stripped = $this->methodSourceWithoutComments($method);

        self::assertStringNotContainsString(
            'MARKER_IN_A_COMMENT',
            $stripped,
            'the method slice must be tokenised and its comments dropped — a comment quoting the '
            . 'gate would otherwise satisfy the pins\' positive control with the real call deleted'
        );
        self::assertStringContainsString(
            'MARKER_IN_CODE',
            $stripped,
            'stripping must keep the CODE: without this the pins would assert over an empty slice'
        );
    }

    /**
     * `stripComments()` must not eat the code around the comments.
     */
    public function testStrippingPreservesCode(): void
    {
        $stripped = $this->stripComments("<?php\n/** doc */\n\$a = 'keep me'; // trailing\n\$b = 2;\n");

        self::assertStringContainsString("\$a = 'keep me';", $stripped);
        self::assertStringContainsString('$b = 2;', $stripped);
        self::assertStringNotContainsString('doc', $stripped);
        self::assertStringNotContainsString('trailing', $stripped);
    }

    /**
     * `sourceWithoutComments()` reads a real file and strips it.
     *
     * Uses THIS test's own file, so the fixture cannot drift away from the
     * assertion: the string below is in a docblock (this one) and nowhere in
     * the file's code.
     */
    public function testSourceWithoutCommentsReadsAndStripsAFile(): void
    {
        $stripped = $this->sourceWithoutComments(__FILE__);

        self::assertStringContainsString('final class RouterDispatchableHandlersTest', $stripped);
        self::assertStringNotContainsString('Uses THIS test\'s own file', $stripped);
    }

    /**
     * ONE implementation, enforced — over the WHOLE pin tree, not one directory.
     *
     * The defect this whole file addresses was born of six verbatim copies, so a
     * seventh pin that reintroduces a private copy has to fail something. Every
     * `*AdminGateIsStructuralTest` under the controllers tree must use this
     * trait.
     *
     * ⚠ Review round 2, finding 3 — this discovery WAS a flat
     * `glob(PIN_DIRECTORY . '/*AdminGateIsStructuralTest.php')` with the class
     * name rebuilt from a hardcoded flat namespace.
     * `tests/Unit/Server/Http/Controllers/` already contains `Admin/`, `Dlna/`
     * and `Stats/`, and the next likely pin subject —
     * `src/Server/Http/Controllers/Admin/MaintenanceController.php`, which still
     * carries the S282 fail-open shape — would naturally be pinned at
     * `…/Controllers/Admin/`. A flat glob cannot see there: the pin would be
     * silently unguarded, free to fork the predicate again, and the count
     * assertion would stay GREEN while guarding less. That is the failure mode
     * this file exists to prevent, so the walk is now recursive and the class
     * name is derived from the PATH.
     *
     * Three assertions, in order, each protecting the next:
     *
     *  1. the walk really DESCENDS (a recursive iterator that silently stopped
     *     at depth 0 would reproduce the very bug being fixed, and nothing else
     *     here would notice);
     *  2. the pin count is the asserted denominator (a walk matching nothing
     *     would make the loop vacuous);
     *  3. every pin found uses the trait.
     */
    public function testEveryAdminGatePinSharesThisPredicate(): void
    {
        $root = realpath(self::PIN_DIRECTORY);
        self::assertIsString($root, 'the pin tree must exist: ' . self::PIN_DIRECTORY);

        $files = [];
        $nested = [];

        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($entries as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($entry->getPathname(), strlen($root) + 1);

            if (str_contains($relative, DIRECTORY_SEPARATOR)) {
                $nested[] = $relative;
            }

            if (str_ends_with($entry->getFilename(), self::PIN_SUFFIX)) {
                $files[$relative] = $entry->getPathname();
            }
        }

        ksort($files);

        // (1) CONTROL — the recursion is real. Asserted against files that are
        // NOT pins, so it stays a control even when every pin sits at the root:
        // it measures the walker, not the population being walked.
        self::assertNotSame(
            [],
            $nested,
            'control: the walk over ' . $root . ' visited no file in any SUBDIRECTORY, so it is '
            . 'not recursive and the pin discovery below is as blind as the glob it replaced'
        );

        // (2) DENOMINATOR — a walk matching nothing would make the loop vacuous.
        self::assertCount(
            7,
            $files,
            'expected 7 admin-gate structural pins under ' . $root . '; found ' . count($files)
            . ': [' . implode(', ', array_keys($files)) . ']. A NEW pin is welcome — add it to '
            . 'this count in the same change, which is the review moment.'
        );

        $offenders = [];
        foreach ($files as $relative => $file) {
            $class = self::PIN_NAMESPACE . '\\'
                . str_replace(DIRECTORY_SEPARATOR, '\\', substr($relative, 0, -strlen('.php')));
            self::assertTrue(class_exists($class), "{$class} must be autoloadable");

            if (!in_array(RouterDispatchableHandlers::class, class_uses($class) ?: [], true)) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'these pins do not use the shared RouterDispatchableHandlers trait and have therefore '
            . 'forked the predicate — the exact duplication that let two handler shapes escape all '
            . 'six pins: ' . implode(', ', $offenders)
        );
    }
}

/**
 * A method carrying a marker in BOTH a comment and its code, so
 * {@see RouterDispatchableHandlersTest::testMethodSourceWithoutCommentsStripsTheSlice()}
 * can tell a real strip from a no-op.
 *
 * Deliberately NOT part of {@see RouterDispatchFixtureController}: adding a
 * public method there would move the enumerated denominator and make one
 * fixture answer two unrelated questions.
 */
final class MethodSliceFixture
{
    public function gated(): string
    {
        // MARKER_IN_A_COMMENT — prose, must not survive stripping.
        return 'MARKER_IN_CODE';
    }
}

/**
 * Base class, so the fixture can prove INHERITED public handlers are counted.
 *
 * They are as dispatchable as declared ones: `Router::callHandler()` resolves
 * the instance and calls the method, and PHP does not care where it was
 * declared.
 */
class RouterDispatchFixtureBase
{
    public function inheritedHandler(Request $request, array $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }
}

/**
 * Every parameter shape the predicate has to judge, in one place.
 *
 * Bodies throw: nothing here is ever called. Only the SIGNATURES matter, and
 * they are read by reflection.
 */
class RouterDispatchFixtureController extends RouterDispatchFixtureBase
{
    /**
     * A constructor whose signature would PASS the parameter test, so its
     * exclusion is proved to be by ROLE (`isConstructor()`) and not by shape.
     *
     * @param array<string, string> $params
     */
    public function __construct(
        public readonly Request $request = new Request(),
        public readonly array $params = [],
    ) {
    }

    // ---------------------------------------------------------------- dispatchable

    public function nativeRequest(Request $request, array $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    /** ESCAPER #1 — measured passing the old predicate and PHPStan level 9. */
    public function mixedFirstParameter(mixed $request, array $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    /** ESCAPER #2 — measured passing the old predicate and PHPStan level 9. */
    public function unionContainingRequest(Request|Response $request, array $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    /** @param mixed $request no native type at all — PHP accepts anything here. */
    public function untypedFirstParameter($request, array $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    public function nullableRequest(?Request $request, array $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    public function objectFirstParameter(object $request, array $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    public static function staticHandler(Request $request, array $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    /** PHP accepts surplus arguments to a userland function, so this body RUNS. */
    public function noParameters(): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    public function onlyTheRequest(Request $request): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    public function optionalThirdParameter(Request $request, array $params, string $extra = ''): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    /** @param mixed $params */
    public function untypedSecondParameter(Request $request, $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    public function iterableSecondParameter(Request $request, iterable $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    public function mixedSecondParameter(Request $request, mixed $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    // ------------------------------------- enumerated, but the router would REFUSE it

    /**
     * ⚠ The one KNOWN over-inclusion (review round 2, finding 1). It sits on
     * this side of the fixture, not among the dispatchable shapes, because
     * `$instance->variadicRequest($request, [])` raises a **TypeError**: a
     * variadic's declared type governs every argument it collects, so the
     * `array` the router passes second is checked against `Request` too.
     *
     * The predicate answers `true` anyway — it asks only about the declared
     * types of parameters #1 and #2 and does not model a variadic spanning both.
     * That is fail-SAFE (a handler in this shape is forced into classification,
     * never hidden), it is left unmodelled on purpose, and
     * {@see RouterDispatchableHandlersTest::testTheVariadicShapeIsAKnownFailSafeOverInclusion()}
     * measures the gap by calling the method rather than asserting it in prose.
     */
    public function variadicRequest(Request ...$requests): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    // ------------------------------------------------------------ NOT dispatchable

    /** TypeError: parameter #1 is an array, the router passes a Request. */
    public function wrongParameterOrder(array $params, Request $request): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    /** TypeError on parameter #1 (strict_types is on at the call site). */
    public function scalarFirstParameter(string $name, array $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    /** TypeError: a Request is not a Response. */
    public function unrelatedClassFirst(Response $request, array $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    /** TypeError: Request does not implement Countable, so the intersection is unsatisfiable. */
    public function intersectionFirstParameter(Request&Countable $request, array $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    /** ArgumentCountError: the router supplies exactly two arguments. */
    public function threeRequiredParameters(Request $request, array $params, string $extra): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    /** TypeError on parameter #2: the router passes an array of path parameters. */
    public function scalarSecondParameter(Request $request, string $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    /**
     * A docblock that types a LATER parameter as a Request.
     *
     * The old predicate scanned every `@param` in the block without caring which
     * parameter it named, so this counted as a handler. It is not one: the router
     * passes the Request FIRST, parameter #1 is an array, and the call `TypeError`s
     * before the body runs. PHP never reads the docblock at all.
     *
     * @param array<string, string> $params
     * @param Request $request
     */
    public function docblockTypesALaterParameter(array $params, $request): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    protected function protectedHandler(Request $request, array $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }

    private function privateHandler(Request $request, array $params): Response
    {
        throw new LogicException('fixture bodies are never executed');
    }
}
