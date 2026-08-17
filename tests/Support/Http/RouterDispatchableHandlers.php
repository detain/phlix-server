<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Http;

use Phlix\Server\Http\Request;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;

/**
 * S323 — the ONE definition of "this controller method is a route handler",
 * shared by every `*AdminGateIsStructuralTest`.
 *
 * ## Why this is a trait and not six private copies
 *
 * It WAS six private copies of a `declaresARequestParameter()` helper, and the
 * duplication is exactly how the helper's own rationale went stale: the
 * docblock claimed "between this method and the src/ analyser the population is
 * closed" while the code matched only a `ReflectionNamedType` spelled
 * `Request` (plus an `@param` mentioning `Request`). Two shapes escaped all six
 * pins AND `phpstan analyse -c phpstan.neon.dist` (src/, level 9) — measured,
 * by appending each to a real controller:
 *
 *     public function purgeAllWebhooks(mixed $request, array $params): Response
 *     public function purgeAllWebhooks(Request|Response $request, array $params): Response
 *
 * `mixed` is a named type whose name is `mixed`; a union is a
 * {@see ReflectionUnionType} and not a named type at all. Both are dispatched
 * by {@see \Phlix\Server\Http\Router::callHandler()} with a real
 * {@see \Phlix\Server\Http\Request}, so an ungated handler in either spelling
 * would have shipped with every pin green — precisely the regression class
 * S323 exists to prevent.
 *
 * ## The rule, derived from the dispatcher rather than from type spellings
 *
 * `Router::callHandler()` (`src/Server/Http/Router.php:547-569`) is the ONLY
 * mechanism that turns a `[Controller::class, 'method']` route into a call, and
 * the call it makes is always literally
 *
 *     $instance->$method($request, $params);   // a Request, then an array
 *
 * So a public method's body RUNS unless PHP refuses that exact call, and PHP
 * refuses it in exactly two ways:
 *
 *  1. `ArgumentCountError` — the method requires MORE than the two arguments
 *     the router passes. (Fewer is fine: PHP accepts surplus arguments to a
 *     userland function, so a zero- or one-parameter method is dispatchable and
 *     is counted here.)
 *  2. `TypeError` — a declared type on parameter #1 rejects the `Request`, or a
 *     declared type on parameter #2 rejects the `array`.
 *
 * Everything else is dispatched, so everything else is enumerated. The
 * acceptance question is answered by asking each declared type whether it
 * accepts the ACTUAL value the router passes — union, intersection, nullable,
 * `mixed`, `object` and untyped all fall out of that one question instead of
 * being listed. The return type is deliberately NOT considered: a handler
 * returning something other than a `Response` still has its body executed, and
 * only then does `callHandler()` throw.
 *
 * ⚠ Docblocks play no part, and that is a WIDENING. PHP does not read them, so
 * they cannot make a call fail; an untyped parameter accepts a `Request` and is
 * therefore included by the untyped rule alone — which strictly supersedes the
 * old `@param`-scanning arm, whatever parameter the annotation named. The only
 * thing the old arm did that this does not is include a method PHP would refuse
 * to call (e.g. `foo(array $params, Request $request)`, whose first parameter
 * rejects the `Request`). That is not a fail-open: such a method `TypeError`s
 * before its body runs.
 *
 * ## What this does NOT close — read before trusting it
 *
 *  - **Only `Router::callHandler()` is modelled.** A controller method reached
 *    some other way — a closure route that calls it, a CLI command, a WebSocket
 *    or DLNA dispatcher, `call_user_func_array()` with a different argument
 *    list — is outside this predicate. At the time of writing `callHandler()`
 *    is the only dynamic `$instance->$method($request, ...)` in `src/`
 *    (verified by grep over `src/`; the two other dynamic calls,
 *    `Application.php:2266` and `:2318`, invoke the middleware chain, not a
 *    controller).
 *  - **Only PUBLIC methods are enumerated**, including inherited ones. A
 *    `__call()` that forwards to a private method is not modelled.
 *  - **Reachability is not asserted.** A dispatchable method that no route
 *    registers is still listed. Over-inclusion forces a CLASSIFICATION, which
 *    is a review moment; under-inclusion is the fail-open.
 *  - **The acceptance table fails SAFE, not shut.** An unrecognised builtin
 *    type (a future PHP addition) counts as ACCEPTING, so the new shape lands
 *    in the "must be classified" bucket rather than vanishing.
 *  - **A VARIADIC spanning both slots is not modelled**, and is a measured
 *    over-inclusion. `foo(Request ...$requests)` is enumerated, but the router's
 *    call `TypeError`s: a variadic's declared type governs argument #2 as well,
 *    and only `$parameters[0]` / `$parameters[1]` are interrogated below. Left
 *    unmodelled deliberately — the direction is fail-safe and no handler in
 *    `src/` is written this way — but stated here rather than glossed over, and
 *    pinned by CALLING it in `RouterDispatchableHandlersTest`
 *    (`testTheVariadicShapeIsAKnownFailSafeOverInclusion()`).
 *
 * {@see \Phlix\Tests\Unit\Support\RouterDispatchableHandlersTest} is this
 * trait's own pin: it exercises the predicate against a fixture carrying every
 * shape above — both escaping spellings included — with an asserted
 * denominator, so the closure claim in this docblock is measured rather than
 * asserted in prose. It also decides WHERE a pin may live: it walks
 * `tests/Unit/Server/Http/Controllers/` recursively, so a new pin filed outside
 * that tree is invisible to the "one implementation" assertion.
 *
 * The prose home for this invariant — the recipe a controller that gates itself
 * follows, and the whole family's current state — is the phlix-docs page
 * `docs/dev/admin-gate-invariant.md`.
 */
trait RouterDispatchableHandlers
{
    /**
     * Every public method of `$controllerClass` that
     * {@see \Phlix\Server\Http\Router::callHandler()} would actually dispatch,
     * sorted by name.
     *
     * The constructor is excluded because the router never calls it. Nothing
     * else is excluded — in particular NOT inherited methods, which are every
     * bit as dispatchable as declared ones.
     *
     * @param class-string $controllerClass
     * @return list<string>
     */
    private function dispatchableRequestHandlers(string $controllerClass): array
    {
        $handlers = [];

        foreach ((new ReflectionClass($controllerClass))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Statics are NOT skipped: `$instance->$method($request, $params)`
            // dispatches to a `public static` method without complaint, so an
            // ungated static handler is every bit as reachable as an instance
            // one — measured in S323 phase 1.
            if ($method->isConstructor()) {
                continue;
            }
            if ($this->routerWouldDispatch($method)) {
                $handlers[] = $method->getName();
            }
        }

        sort($handlers);

        return array_values($handlers);
    }

    /**
     * Would `$instance->$method($request, $params)` — the one call
     * {@see \Phlix\Server\Http\Router::callHandler()} makes — reach this
     * method's BODY?
     *
     * See the trait docblock for the derivation. The two refusals PHP can
     * raise are checked in the order it raises them.
     */
    private function routerWouldDispatch(ReflectionMethod $method): bool
    {
        // (1) ArgumentCountError — the router supplies exactly two arguments.
        if ($method->getNumberOfRequiredParameters() > 2) {
            return false;
        }

        $parameters = array_values($method->getParameters());
        $request = $this->routerRequestSample();

        // (2) TypeError on the Request the router passes first...
        if (isset($parameters[0]) && !$this->typeAcceptsValue($parameters[0]->getType(), $request)) {
            return false;
        }

        // ...or on the path-parameter array it passes second.
        if (isset($parameters[1]) && !$this->typeAcceptsValue($parameters[1]->getType(), [])) {
            return false;
        }

        return true;
    }

    /**
     * The very value the router passes as the first argument.
     *
     * A real instance, not a mock or a class-string: the question being asked
     * is "would PHP accept THIS value here", so the answer must be computed
     * against the same kind of value production uses. `new Request()` is the
     * shape every handler test in this repo already builds.
     */
    private function routerRequestSample(): Request
    {
        return new Request();
    }

    /**
     * Does a declared parameter type accept `$value`?
     *
     * Answered by interrogating the value, never by matching a spelling, so
     * union / intersection / nullable / `mixed` / `object` / untyped are all
     * decided by the same rule.
     *
     * ⚠ The default arm is FAIL-SAFE (accepts). An unrecognised builtin means
     * "this predicate does not know", and "do not know" must widen the
     * enumeration, never narrow it.
     */
    private function typeAcceptsValue(?ReflectionType $type, mixed $value): bool
    {
        // No declared type: PHP accepts anything, including a Request. This is
        // what makes the old docblock-scanning arm unnecessary.
        if ($type === null) {
            return true;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $branch) {
                if ($this->typeAcceptsValue($branch, $value)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $branch) {
                if (!$this->typeAcceptsValue($branch, $value)) {
                    return false;
                }
            }

            return true;
        }

        if (!$type instanceof ReflectionNamedType) {
            // An unknown ReflectionType subclass. Fail SAFE: include it.
            return true;
        }

        if ($value === null) {
            return $type->allowsNull();
        }

        if (!$type->isBuiltin()) {
            return is_object($value) && is_a($value, $type->getName());
        }

        return match (strtolower($type->getName())) {
            'mixed' => true,
            'object' => is_object($value),
            'array' => is_array($value),
            'iterable' => is_iterable($value),
            'callable' => is_callable($value),
            'string' => is_string($value),
            'int' => is_int($value),
            // A widening int -> float is accepted even under strict_types.
            'float' => is_float($value) || is_int($value),
            'bool' => is_bool($value),
            'true' => $value === true,
            'false' => $value === false,
            'null', 'void', 'never' => false,
            // ⚠ FAIL-SAFE, see the docblock: an unrecognised builtin widens.
            default => true,
        };
    }

    /**
     * `$file`'s source with every `T_COMMENT` / `T_DOC_COMMENT` removed.
     *
     * The secondary net in each pin counts a literal (`$this->requireAdmin($request)`
     * or `$this->adminMiddleware->checkAccess($request)`) over the controller's
     * source. Counted over RAW source, a docblock quoting that literal inflates
     * the count and can mask a gate deleted from a still-listed handler — the
     * single most repeated trap in this estate, with 8 recorded instances of a
     * step's own docblock recreating the exact string a check looks for.
     *
     * ⚠ This removes the COMMENT class of that trap, and only that class
     * (review round 2, finding 2 — the earlier wording, "removes the whole class
     * of it", overstated it). A single-quoted string literal, a heredoc or a
     * nowdoc containing the counted literal survives tokenising and still
     * inflates a `substr_count()` over the result — measured on a fixture
     * carrying the literal in a string, a nowdoc, real code and a `#` comment:
     * raw 4, stripped 3. Comments are the realistic vector here (the six pinned
     * controllers hold exactly one occurrence each, in code, and none of them
     * quotes the gate in a string); closing the string case as well would need
     * token-sequence matching rather than `substr_count()`. Stated rather than
     * assumed, because a check whose whole purpose is that prose cannot mask a
     * deleted gate must not itself rest on prose.
     *
     * Loud rather than lenient: an unreadable file throws instead of returning
     * an empty string, because an empty string would make every count 0 — and a
     * parser that matches nothing reads as a pass.
     */
    private function sourceWithoutComments(string $file): string
    {
        $source = file_get_contents($file);

        if ($source === false) {
            throw new RuntimeException("could not read controller source: {$file}");
        }

        return $this->stripComments($source);
    }

    /**
     * The source of ONE method — from its `{` line to its `}` line — with
     * comments removed.
     *
     * ⚠ Review round 2, finding 5. Each pin asserts over this slice twice: a
     * positive control (`requireAdmin()` must contain the `checkAccess()` call)
     * and a negative regex (it must not compare the middleware against null).
     * Both used to read RAW bytes while the sibling counting net had already
     * moved to {@see self::sourceWithoutComments()}, which left one file
     * disagreeing with itself about which source it trusts — and left the
     * control satisfiable by an inline comment quoting the gate with the real
     * call deleted. The same trap, in the same file, as the one the counting net
     * closed. Now both run over stripped source.
     *
     * Slicing cannot be done on the whole stripped file: removing a docblock
     * removes its lines, so `getStartLine()`/`getEndLine()` no longer address
     * what they named. The slice is therefore cut from the raw file FIRST and
     * stripped after — which is also why the `<?php` prefix is prepended below:
     * `token_get_all()` only lexes PHP after an open tag, and without one the
     * whole fragment comes back as a single `T_INLINE_HTML` token with nothing
     * stripped. That failure would be SILENT (the assertions would simply revert
     * to raw behaviour), so
     * {@see \Phlix\Tests\Unit\Support\RouterDispatchableHandlersTest::testMethodSourceWithoutCommentsStripsTheSlice()}
     * measures it.
     *
     * Loud rather than lenient, for the same reason as
     * {@see self::sourceWithoutComments()}: an unreadable file throws instead of
     * yielding an empty string that every `assertStringContainsString()` would
     * fail on for the wrong reason.
     */
    private function methodSourceWithoutComments(ReflectionMethod $method): string
    {
        $file = $method->getFileName();

        if ($file === false) {
            throw new RuntimeException(
                'cannot read the source of the internal method ' . $method->getName()
            );
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException("could not read controller source: {$file}");
        }

        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $start;

        return $this->stripComments("<?php\n" . implode("\n", array_slice($lines, $start, $length)));
    }

    /**
     * {@see self::sourceWithoutComments()} over a string, so the stripper can
     * be pinned without touching the filesystem.
     */
    private function stripComments(string $source): string
    {
        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $stripped .= $token[1];
                continue;
            }

            $stripped .= $token;
        }

        return $stripped;
    }
}
