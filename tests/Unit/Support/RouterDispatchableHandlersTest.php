<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use Countable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Tests\Support\Http\RouterDispatchableHandlers;
use ReflectionMethod;

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
     * Directory holding the controller admin-gate pins that MUST share this
     * predicate.
     */
    private const PIN_DIRECTORY = __DIR__ . '/../Server/Http/Controllers';

    /**
     * The complete dispatchable set of {@see RouterDispatchFixtureController},
     * hardcoded.
     *
     * Hardcoded and compared WHOLE, not spot-checked: a per-shape assertion
     * would let a predicate that over- or under-included some OTHER method pass.
     * Every name here is a method `Router::callHandler()` would drive; every
     * public method of the fixture absent from this list is one PHP would refuse
     * to call, and each of those carries a comment saying which refusal.
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
     * ONE implementation, enforced.
     *
     * The defect this whole file addresses was born of six verbatim copies, so a
     * seventh pin that reintroduces a private copy has to fail something. Every
     * `*AdminGateIsStructuralTest` in the controllers directory must use this
     * trait, and the file count is asserted so a glob that matched nothing
     * cannot read as a pass.
     */
    public function testEveryAdminGatePinSharesThisPredicate(): void
    {
        $files = glob(self::PIN_DIRECTORY . '/*AdminGateIsStructuralTest.php');
        self::assertIsArray($files);
        sort($files);

        // DENOMINATOR — a glob matching nothing would make the loop below vacuous.
        self::assertCount(
            6,
            $files,
            'expected 6 admin-gate structural pins; found ' . count($files) . ': ['
            . implode(', ', array_map('basename', $files)) . ']. A NEW pin is welcome — add it to '
            . 'this count in the same change, which is the review moment.'
        );

        $offenders = [];
        foreach ($files as $file) {
            $class = 'Phlix\\Tests\\Unit\\Server\\Http\\Controllers\\' . basename($file, '.php');
            self::assertTrue(class_exists($class), "{$class} must be autoloadable");

            if (!in_array(RouterDispatchableHandlers::class, class_uses($class) ?: [], true)) {
                $offenders[] = basename($file);
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

    public function variadicRequest(Request ...$requests): Response
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
