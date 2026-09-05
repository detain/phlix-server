<?php

/**
 * S431 — the S427 census, EXECUTABLE: the five census numbers re-derived from
 * tokens at run time and pinned, instead of asserted as prose.
 *
 * ## Why this file exists
 *
 * S427 shipped `Request::__get`/`__isset` (posture (a): unguarded dynamic reads
 * of undeclared names throw, guarded shapes keep their defaults) and published
 * the tokenized census that licensed that posture as DECLARED PROSE inside
 * {@see RequestDynamicPropertyGuardTest}'s docblocks: 1,756 PHP files, 331
 * dynamic-free property reads on Request roots, 0 surviving dynamic reads,
 * 0 dynamic writes, 1,037 declared write sites. Prose drifts silently the
 * moment source moves — the numbers were measured once by a lane script that
 * never shipped, so nothing could re-check them. The W30 barrier recorded the
 * gap and spawned this step. Here the census becomes a test: every value is
 * re-tokenized from the working tree on every suite run, against the pins
 * below, exactly as {@see \Phlix\Tests\Unit\Support\WriteResultAdoptionGuardTest}
 * re-pins the S342 insert-consumer denominators (the ported pattern).
 *
 * ## The numbers, adjudicated at this tip (declared prose vs. runtime)
 *
 * | # | S427 prose | This test (measured, tip) | Verdict |
 * |---|------------|---------------------------|---------|
 * | 1 | 1,756 PHP files | 1,760 | DRIFT +4 — prose was measured at the lane's
 *   BASE commit `4b620f59`; the estate grew by S427's own guard test (+1), S228's
 *   two files (+2) and this file (+1); the prose did not even match the tree
 *   S427 merged into (1,757 at `aea41d37`). |
 * | 2 | 331 declared reads | 391 | DRIFT +60 — see reconciliation below;
 *   the load-bearing property — EVERY counted read names a declared member —
 *   holds (0 undeclared). |
 * | 3 | 0 dynamic reads | 0 | MATCH — the guard's throw can only fire on would-be-new code. |
 * | 4 | 0 dynamic writes | 0 | MATCH. |
 * | 5 | 1,037 declared writes | 940 | DRIFT −97 — exactly the 97
 *   offset-write sites (`$root->body['k'] = …`) this walk classifies as reads;
 *   see below. |
 *
 * ## The prose cannot be reproduced as written (the finding S431 exists to catch)
 *
 * The lane's scan script was never shipped, so its exact rules are unknown —
 * but its arithmetic cannot be reproduced from the published signals. Counting
 * every property site exactly once, this estate contains **1,331** named-name
 * sites on Request roots (this walk: 391 read-classified + 940
 * write-classified; under setter semantics the same population splits
 * 294 + 1,037). Strikingly, the prose WRITE number 1,037 is EXACTLY this
 * scan's 940 plain writes + the 97 offset-write sites — so the lane counted
 * `$root->body['k'] = …` as a write — yet its READ number 331 then cannot be
 * this population's 294: the lane's root set saw ~37 read sites this walk does
 * not (and sums to 1,368 vs 1,331 total), a difference no published signal
 * explains. The prose also carries an unshipped adjudication step ("332 reads,
 * 1 false positive ruled out by hand"), which no executable test could ever
 * re-derive. What survived the drift review: every qualitative
 * claim S427 made on top of the numbers is TRUE at this tip — zero undeclared
 * name reads, zero dynamic-name reads, zero dynamic writes — and those zero
 * pins, not the denominators, are what licenses the throwing `__get`.
 *
 * ## What is scanned, and what each number means
 *
 * Estate: every `*.php` below the repository root, excluding `vendor/` and
 * `node_modules/` path segments — the set `git ls-files '*.php'` matches
 * exactly on a clean checkout (measured, this commit). Site scanning carves
 * out ONLY the two census-fixture files listed in GUARD_FIXTURE_FILES: the
 * S427 guard test deliberately performs dynamic reads of undeclared names (it
 * is testing the runtime tripwire — counting its own probes would make the
 * zero-pins unfalsifiable from day one), and this file spells property names
 * in prose. Neither is production code; the 1,756-file estate count still
 * includes both.
 *
 * A **Request root** is a variable that carries a `Phlix\Server\Http\Request`
 * at run time, inferred per function scope from (union over enclosing scopes,
 * so `use ($request)` captures count):
 *  - typed parameters naming the class (`Request $x`, fully/relatively
 *    qualified, union/intersect members, aliases resolved through the file's
 *    `use` statements — `Workerman\Protocols\Http\Request` never matches);
 *  - `@param`/`@var Request $x` docblock hints on the function;
 *  - factory assignments: `$x = new Request()` / `new self()` / `new static()`
 *    (the latter two only inside Request.php), `$x = Request::fromGlobals(…)`,
 *    `…::fromWorkerman(…)`;
 *  - same-file factory CALLS: a function whose own body `return`s one of the
 *    above (or a visible root) is a Request factory, so `$x = $this->makeRequest(…)`
 *    inherits (this is what covers the test-suite helpers `authedRequest()`,
 *    `bearerRequest()`, `request()` …);
 *  - copy and clone propagation from a visible root (`$x = $root`,
 *    `$x = clone $root`, `$x = $root ?? <factory-expr>`);
 *  - `$this` inside `src/Server/Http/Request.php` itself.
 *
 * Per access `$root->name` (also `?->`):
 *  - **write**  — `name` directly followed by an assignment operator;
 *  - **read**   — every other non-call use, INCLUDING `$root->name['k'] = …`
 *    (only `__get` fires on that shape — there is no `__set`, and for a guard
 *    whose question is "can the throw ever fire in existing code", the getter
 *    classification is the behaviorally true one; 97 such offset sites exist
 *    at this tip and each hits the slot, never the guard, because the name is
 *    declared);
 *  - **dynamic** — `$root->$k` / `$root->{$expr}` — the PHPStan-blind shape
 *    S271's ghost property lived in;
 *  - **method call** — `name` followed by `(` — skipped (not a property site).
 *
 * The four zero-pinned sets (undeclared reads/writes, dynamic reads/writes)
 * name every offending `file:line name` in the failure text — plant a
 * `$request->jsonBody` anywhere and the suite points at it.
 *
 * ## Known limits — escapes accepted, not overlooked
 *
 *  - Root inference is name-scope textual, not a type system: cross-file
 *    helper indirection (`$x = (new Suite())->request()`), arrays/iterators of
 *    Requests (`foreach ($requests as $r)`), typed class properties
 *    (`$this->request->x` — none exist today), and `Request` sub-classes
 *    (none exist today) are NOT roots; a site only they reach escapes.
 *  - A factory recognised by name inside one file applies to same-named
 *    methods of sibling classes in that same file too (first definition wins);
 *    mis-marked roots can only ADD counted sites, and an undeclared property
 *    on such a lookalike would surface as a named undeclared-site failure —
 *    the loud direction, which is the right trade (WriteResultAdoptionGuardTest's
 *    "false positive costs more than an escape" discipline, with the opposite
 *    sign: here a false positive names itself and is reviewable).
 *  - Reads inside string interpolation (`"{$request->userId}"`) ARE counted —
 *    the engine really calls `__get` there, and `HttpHandler.php:143` is the
 *    live example.
 *
 * ## Re-pin procedure (the point of pinning)
 *
 * When a legitimate change moves the landscape, update the constant(s) in the
 * SAME commit and say why in the message — same law as S342's
 * EXPECTED_TOTAL_INSERT_CALLS. The four zeros are different: they are posture
 * claims, not bookkeeping; a non-zero value there is a regression to fix in
 * source, not a number to re-pin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RequestDynamicPropertyCensusExecutableTest extends TestCase
{
    /**
     * Code-resident lane token (never in any .md): proves at merge time that
     * this file — the executable census — is really tracked in the merged tree.
     */
    public const EXECUTABLE_CENSUS_TOKEN = 'S431-executable-census@e74cdc88';

    /** The class whose roots the census walks. */
    private const TARGET_CLASS = 'Phlix\\Server\\Http\\Request';

    /** The 17 declared public members of Request (mirrors RequestDynamicPropertyGuardTest). */
    private const DECLARED_MEMBERS = [
        'method', 'path', 'queryString', 'headers', 'query', 'body', 'rawBody',
        'files', 'remoteIp', 'remotePort', 'protocol', 'bearerToken', 'cookies',
        'userId', 'profileId', 'hubUser', 'pathParams',
    ];

    /**
     * Census number 1 — every `*.php` in the estate (excluding vendor/ and
     * node_modules/ segments), measured at this tip. S427 prose said 1,756 —
     * the count at its BASE `4b620f59`, never re-taken at its own merge tip.
     * Re-pinned 1760→1764 by S433: the delivery path adds four first-party
     * files (HookDelivery, HookDeliveryException, the probe test, the guard
     * test); the other four pins — both denominators and both posture zeros —
     * are untouched, as they must be for files that never name Request.
     * Re-pinned 1764→1768 by S434: the socket-guard path adds four first-party
     * files (CoroutineSocketGuard, CoroutineSocketFault, the refused exception,
     * the guard test); same reasoning — none of them names Request.
     * Re-pinned 1768→1769 by S436: adds the real-DB collection-members integration test.
     * Re-pinned 1769→1771 by S435: the boundary-decode path adds two first-party
     * files (RouterPathParamDecodingTest, MusicEncodedRouteParamE2eTest); the fix
     * itself lives in the existing Router.php, which never joined or left the tree.
     */
    private const EXPECTED_PHP_FILES = 1771;

    /**
     * Census number 2 — dynamic-free property READS on Request roots, all on
     * declared members. S427 prose said 331 (drift +60 here; the prose's own
     * 332-minus-hand-ruled-1 arithmetic was not executable — see header).
     */
    private const EXPECTED_DECLARED_READS = 391;

    /**
     * Census number 5 — property WRITES (name directly assigned) on Request
     * roots. S427 prose said 1,037 (drift −97 — the 97 offset-write sites; unreachable under either
     * bracket convention — see header).
     * Re-pinned 940→949 by S435: the two new test classes assign nine declared
     * Request members directly (method/path ×4 unit sites, userId in the e2e
     * dispatch helper — every one on a DECLARED property, the S427 license intact).
     */
    private const EXPECTED_DECLARED_WRITES = 949;

    /** Census numbers 3 and 4 — the posture claims; never re-pin, fix source. */
    private const EXPECTED_DYNAMIC_READS = 0;
    private const EXPECTED_DYNAMIC_WRITES = 0;

    /**
     * Files carved out of SITE scanning (never out of the estate count): the
     * S427 guard test probes the dynamic tripwire on purpose, and this test
     * spells the shapes in prose. The original census ran on a tree where
     * neither file existed; excluding them reproduces its scope, not its bugs.
     */
    private const GUARD_FIXTURE_FILES = [
        'tests/Unit/Server/Http/RequestDynamicPropertyGuardTest.php',
        'tests/Unit/Server/Http/RequestDynamicPropertyCensusExecutableTest.php',
    ];

    /** Estate path segments that are not first-party PHP. */
    private const EXCLUDED_SEGMENTS = ['vendor', 'node_modules'];

    /** Tokens that never carry meaning for any rule here. */
    private const IGNORED_TOKENS = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * The whole-estate scan, computed once per PHPUnit process.
     *
     * @var array{
     *     files: int,
     *     declaredReads: int,
     *     declaredWrites: int,
     *     undeclaredReads: list<string>,
     *     undeclaredWrites: list<string>,
     *     dynamicReads: list<string>,
     *     dynamicWrites: list<string>,
     * }|null
     */
    private static ?array $census = null;

    public function testTheEstateFileCountIsPinned(): void
    {
        $this->assertSame(
            self::EXPECTED_PHP_FILES,
            self::census()['files'],
            'S431 [' . self::EXECUTABLE_CENSUS_TOKEN . ']: the estate now holds a different '
            . 'number of first-party PHP files than the '
            . 'pinned census denominator. A file joined or left the tree — update '
            . 'EXPECTED_PHP_FILES in the same commit (the enumeration must not drift silently; '
            . 'that silence is the exact defect S431 was spawned to end).',
        );
    }

    public function testTheDeclaredPropertyReadDenominatorIsPinned(): void
    {
        $this->assertSame(
            self::EXPECTED_DECLARED_READS,
            self::census()['declaredReads'],
            'S431: the count of declared-member property READS on Request roots changed. '
            . 'A Request property read was added or removed — update EXPECTED_DECLARED_READS '
            . 'in the same commit and re-check that the S427 census license (all reads on '
            . 'declared members) still holds: the undeclared-read test in this file, not '
            . 'this number, is what guards that.',
        );
    }

    public function testTheDeclaredPropertyWriteDenominatorIsPinned(): void
    {
        $this->assertSame(
            self::EXPECTED_DECLARED_WRITES,
            self::census()['declaredWrites'],
            'S431: the count of declared-member property WRITES on Request roots changed. '
            . 'Update EXPECTED_DECLARED_WRITES in the same commit (S427 licensed the guard '
            . 'against "1,037 declared write sites" — the executable number governs now).',
        );
    }

    /**
     * Posture claim, census number 3: zero dynamic-NAME reads (`->$k`,
     * `->{$expr}`) on Request roots. THIS is the test a planted S271-shaped
     * read reddens, naming the exact `file:line`.
     */
    public function testZeroDynamicPropertyReadsSurviveOnRequestRoots(): void
    {
        $found = self::census()['dynamicReads'];

        $this->assertSame(
            self::EXPECTED_DYNAMIC_READS,
            count($found),
            'S431: dynamic-NAME property reads on Request roots survived — the '
            . '`$request->$name` / `->{$expr}` shape. S271\'s `jsonBody` was invisible to PHPStan at any '
            . 'level exactly because the name is not in the source text; the S427 census '
            . 'measured zero such sites and that zero is what licenses the throwing __get. '
            . 'Remedy: read a declared member. Offending sites: ' . implode('; ', $found),
        );
    }

    /** Posture claim, census number 4: zero dynamic-name writes. */
    public function testZeroDynamicPropertyWritesSurviveOnRequestRoots(): void
    {
        $found = self::census()['dynamicWrites'];

        $this->assertSame(
            self::EXPECTED_DYNAMIC_WRITES,
            count($found),
            'S431: dynamic-NAME property WRITES on Request roots survived. Request declares '
            . 'no magic properties and no __set — such a write silently creates a dynamic slot '
            . '(PHP 8.2 deprecation territory) and reopens the exact bug class S427 closed. '
            . 'Offending sites: ' . implode('; ', $found),
        );
    }

    /**
     * The census invariant under the denominators: every NAMED property site
     * on a Request root references one of the 17 declared members. This is
     * the executable form of "331 reads — ALL on declared members" and of
     * "1,037 writes all declared": a static-name `jsonBody` anywhere —
     * guarded or not — names itself here (S271's shape in new code).
     */
    public function testEveryNamedPropertySiteOnRequestRootsIsADeclaredMember(): void
    {
        $readOffenders = self::census()['undeclaredReads'];
        $writeOffenders = self::census()['undeclaredWrites'];

        $this->assertSame(
            [],
            $readOffenders,
            'S431: property READS of undeclared names on Request roots exist. An unguarded '
            . 'one now throws LogicException at run time (the S427 tripwire); a `?? $default` '
            . 'one stays the S271 silent-null bug — either way it must be rewritten against '
            . 'a declared member (->body carries the decoded request body). Offenders: '
            . implode('; ', $readOffenders),
        );

        $this->assertSame(
            [],
            $writeOffenders,
            'S431: property WRITES to undeclared names on Request roots exist — silently '
            . 'dynamic slots, the same bug class from the other side. Offenders: '
            . implode('; ', $writeOffenders),
        );
    }

    /**
     * One tokenise-inspect-discard pass over the estate.
     *
     * @return array{
     *     files: int,
     *     declaredReads: int,
     *     declaredWrites: int,
     *     undeclaredReads: list<string>,
     *     undeclaredWrites: list<string>,
     *     dynamicReads: list<string>,
     *     dynamicWrites: list<string>,
     * }
     */
    private static function census(): array
    {
        if (self::$census !== null) {
            return self::$census;
        }

        $root = dirname(__DIR__, 4);

        $files = 0;
        $declaredReads = 0;
        $declaredWrites = 0;
        $undeclaredReads = [];
        $undeclaredWrites = [];
        $dynamicReads = [];
        $dynamicWrites = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);

            $segments = explode('/', $relative);
            if (array_intersect(self::EXCLUDED_SEGMENTS, $segments) !== []) {
                continue;
            }

            $files++;

            if (in_array($relative, self::GUARD_FIXTURE_FILES, true)) {
                continue;
            }

            /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
            $tokens = token_get_all((string) file_get_contents($path));

            $site = self::tokenizeRequestRoots($tokens, $relative);

            $declaredReads += $site['declaredReads'];
            $declaredWrites += $site['declaredWrites'];
            $undeclaredReads = array_merge($undeclaredReads, $site['undeclaredReads']);
            $undeclaredWrites = array_merge($undeclaredWrites, $site['undeclaredWrites']);
            $dynamicReads = array_merge($dynamicReads, $site['dynamicReads']);
            $dynamicWrites = array_merge($dynamicWrites, $site['dynamicWrites']);

            unset($tokens);
        }

        sort($undeclaredReads);
        sort($undeclaredWrites);
        sort($dynamicReads);
        sort($dynamicWrites);

        self::$census = [
            'files' => $files,
            'declaredReads' => $declaredReads,
            'declaredWrites' => $declaredWrites,
            'undeclaredReads' => $undeclaredReads,
            'undeclaredWrites' => $undeclaredWrites,
            'dynamicReads' => $dynamicReads,
            'dynamicWrites' => $dynamicWrites,
        ];

        return self::$census;
    }

    /**
     * Per-file: resolve aliases, infer Request roots per scope, classify sites.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{
     *     declaredReads: int,
     *     declaredWrites: int,
     *     undeclaredReads: list<string>,
     *     undeclaredWrites: list<string>,
     *     dynamicReads: list<string>,
     *     dynamicWrites: list<string>,
     * }
     */
    private static function tokenizeRequestRoots(array $tokens, string $relative): array
    {
        $count = count($tokens);
        $inRequestFile = $relative === 'src/Server/Http/Request.php';

        [$namespace, $aliasMap] = self::namespaceAndImports($tokens);

        $scopes = self::functionScopes($tokens);

        $scopeRoots = [-1 => []];

        foreach ($scopes as $si => $scope) {
            foreach (self::rootsDeclaredInParams($tokens, $scope, $aliasMap, $namespace, $inRequestFile) as $var) {
                $scopeRoots[$si][$var] = true;
            }
            foreach (
                self::rootsDeclaredInDocblock((string) $scope['doc'], $aliasMap, $namespace, $inRequestFile) as $var
            ) {
                $scopeRoots[$si][$var] = true;
            }
        }

        if ($inRequestFile) {
            foreach (array_keys($scopes) as $si) {
                $scopeRoots[$si]['$this'] = true;
            }
        }

        $namedFns = [];
        foreach ($scopes as $si => $scope) {
            $name = $scope['name'];
            if ($name !== null) {
                $namedFns[$name] ??= $si;
            }
        }

        $visible = static function (int $idx) use ($scopes, &$scopeRoots): array {
            $vars = $scopeRoots[-1] ?? [];
            foreach ($scopes as $si => $scope) {
                if ($idx > $scope['body'][0] && $idx < $scope['body'][1]) {
                    foreach ($scopeRoots[$si] ?? [] as $var => $_) {
                        $vars[$var] = true;
                    }
                }
            }

            return $vars;
        };

        $isRequestExpr = static function (
            array $tokens,
            int $v
        ) use (
            $count,
            $aliasMap,
            $namespace,
            $inRequestFile
        ): bool {
            if ($v >= $count || !is_array($tokens[$v])) {
                return false;
            }
            if ($tokens[$v][0] === T_NEW) {
                $w = self::nextSignificant($tokens, $v + 1);

                return $w !== null && self::nameTargets($tokens[$w], $aliasMap, $namespace, $inRequestFile);
            }
            if (in_array($tokens[$v][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $colon = self::nextSignificant($tokens, $v + 1);
                if ($colon === null || !is_array($tokens[$colon]) || $tokens[$colon][0] !== T_DOUBLE_COLON) {
                    return false;
                }
                $method = self::nextSignificant($tokens, $colon + 1);

                return $method !== null && is_array($tokens[$method]) && $tokens[$method][0] === T_STRING
                    && in_array($tokens[$method][1], ['fromGlobals', 'fromWorkerman'], true)
                    && self::nameTargets($tokens[$v], $aliasMap, $namespace, $inRequestFile);
            }

            return false;
        };

        // Fixed point: assignments and same-file factory call sites.
        $factories = [];
        for ($iteration = 0; $iteration < 8; $iteration++) {
            $changed = false;
            $factories = self::computeFactories($tokens, $scopes, $namedFns, $visible, $isRequestExpr);

            foreach ($scopes as $si => $scope) {
                [$bodyOpen, $bodyClose] = $scope['body'];
                for ($j = $bodyOpen; $j < $bodyClose; $j++) {
                    $t = $tokens[$j];
                    if (!is_array($t) || $t[0] !== T_VARIABLE) {
                        continue;
                    }
                    $eq = self::nextSignificant($tokens, $j + 1);
                    if ($eq === null || $tokens[$eq] !== '=') {
                        continue;
                    }
                    $v = self::nextSignificant($tokens, $eq + 1);
                    if ($v === null) {
                        continue;
                    }

                    $mark = $isRequestExpr($tokens, $v);

                    if (!$mark && is_array($tokens[$v]) && $tokens[$v][0] === T_CLONE) {
                        $w = self::nextSignificant($tokens, $v + 1);
                        $mark = $w !== null && is_array($tokens[$w]) && $tokens[$w][0] === T_VARIABLE
                            && isset($visible($bodyOpen)[$tokens[$w][1]]);
                    } elseif (!$mark && is_array($tokens[$v]) && $tokens[$v][0] === T_VARIABLE) {
                        $next = self::nextSignificant($tokens, $v + 1);
                        $chain = $next !== null && is_array($tokens[$next])
                            && in_array(
                                $tokens[$next][0],
                                [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON],
                                true
                            );
                        if (!$chain) {
                            $mark = isset($visible($bodyOpen)[$tokens[$v][1]])
                                || ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_COALESCE
                                    && self::afterCoalesced($tokens, $next, $isRequestExpr));
                        } else {
                            $call = self::callShape($tokens, $v);
                            $mark = $call !== null && isset($factories[$call]);
                        }
                    } elseif (
                        !$mark && is_array($tokens[$v])
                        && in_array($tokens[$v][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    ) {
                        $call = self::callShape($tokens, $v);
                        $mark = $call !== null && isset($factories[$call]);
                    }

                    if ($mark && !isset($scopeRoots[$si][$t[1]])) {
                        $scopeRoots[$si][$t[1]] = true;
                        $changed = true;
                    }
                }
            }

            if (!$changed && $iteration > 0) {
                break;
            }
        }

        $out = [
            'declaredReads' => 0,
            'declaredWrites' => 0,
            'undeclaredReads' => [],
            'undeclaredWrites' => [],
            'dynamicReads' => [],
            'dynamicWrites' => [],
        ];

        for ($j = 0; $j < $count; $j++) {
            $t = $tokens[$j];
            if (!is_array($t) || $t[0] !== T_VARIABLE) {
                continue;
            }
            if (!isset($visible($j)[$t[1]])) {
                continue;
            }
            $op = self::nextSignificant($tokens, $j + 1);
            if (
                $op === null || !is_array($tokens[$op])
                || !in_array($tokens[$op][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            ) {
                continue;
            }
            $name = self::nextSignificant($tokens, $op + 1);
            if ($name === null) {
                continue;
            }
            $site = $relative . ':' . $t[2];

            $nm = $tokens[$name];

            if (is_array($nm) && $nm[0] === T_VARIABLE) {
                $after = self::nextSignificant($tokens, $name + 1);
                $isWrite = $after !== null && is_array($tokens[$after])
                    && in_array($tokens[$after][0], self::ASSIGN_OPERATORS, true);
                if ($isWrite) {
                    $out['dynamicWrites'][] = $site;
                } else {
                    $out['dynamicReads'][] = $site;
                }

                continue;
            }

            if ($nm === '{') {
                // `->{$expr}` — a property-read of a computed name. Find the
                // matching `}` and classify by what follows it.
                $depth = 0;
                $close = null;
                for ($k = $name; $k < $count; $k++) {
                    if (
                        $tokens[$k] === '{'
                        || (is_array($tokens[$k])
                            && in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
                    ) {
                        $depth++;
                    } elseif ($tokens[$k] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $close = $k;
                            break;
                        }
                    }
                }
                $after = $close === null ? null : self::nextSignificant($tokens, $close + 1);
                $isWrite = $after !== null && (
                    $tokens[$after] === '='
                    || (is_array($tokens[$after]) && in_array($tokens[$after][0], self::ASSIGN_OPERATORS, true))
                );
                if ($isWrite) {
                    $out['dynamicWrites'][] = $site;
                } else {
                    $out['dynamicReads'][] = $site;
                }

                continue;
            }

            if (!is_array($nm) || !in_array($nm[0], [T_STRING, T_ARRAY, T_CLASS], true)) {
                continue;
            }
            $propertyName = $nm[1];
            $after = self::nextSignificant($tokens, $name + 1);
            if ($after === null) {
                continue;
            }
            if ($tokens[$after] === '(') {
                continue; // method call
            }
            $isWrite = $tokens[$after] === '='
                || (is_array($tokens[$after]) && in_array($tokens[$after][0], self::ASSIGN_OPERATORS, true));
            $isDeclared = in_array($propertyName, self::DECLARED_MEMBERS, true);

            if ($isWrite) {
                if ($isDeclared) {
                    $out['declaredWrites']++;
                } else {
                    $out['undeclaredWrites'][] = $site . ' ' . $propertyName;
                }
            } elseif ($isDeclared) {
                $out['declaredReads']++;
            } else {
                $out['undeclaredReads'][] = $site . ' ' . $propertyName;
            }
        }

        return $out;
    }

    /** Compound/plain assignment operator ids beyond the `'='` char token. */
    private const ASSIGN_OPERATORS = [
        T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL,
        T_CONCAT_EQUAL, T_COALESCE_EQUAL, T_POW_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
        T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL,
    ];

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: string, 1: array<string,string>}
     */
    private static function namespaceAndImports(array $tokens): array
    {
        $count = count($tokens);
        $namespace = '';
        $aliasMap = [];

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $q = $tokens[$j];
                    if (is_array($q) && in_array($q[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace = $q[1];
                        break;
                    }
                    if (!is_array($q) && ($q === ';' || $q === '{')) {
                        break;
                    }
                }
            }
            if ($t[0] !== T_USE) {
                continue;
            }
            $prev = self::prevSignificant($tokens, $i);
            if ($prev !== null && is_array($tokens[$prev]) === false && $tokens[$prev] === ')') {
                continue; // closure `use (...)`
            }
            for ($j = $i + 1; $j < $count; $j++) {
                $q = $tokens[$j];
                if (!is_array($q)) {
                    if ($q === ';') {
                        break;
                    }
                    continue;
                }
                if ($q[0] === T_FUNCTION || $q[0] === T_CONST) {
                    for (; $j < $count; $j++) {
                        $r = $tokens[$j];
                        if (!is_array($r) && $r === ';') {
                            break;
                        }
                    }
                    break;
                }
                if (is_array($q) && in_array($q[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $fqn = ltrim($q[1], '\\');
                    $as = self::nextSignificant($tokens, $j + 1);
                    if ($as !== null && is_array($tokens[$as]) && $tokens[$as][0] === T_AS) {
                        $alias = self::nextSignificant($tokens, $as + 1);
                        if ($alias !== null && is_array($tokens[$alias])) {
                            $aliasMap[strtolower($tokens[$alias][1])] = $fqn;
                            $j = $alias;
                        }
                    } else {
                        $aliasMap[strtolower(self::shortName($fqn))] = $fqn;
                    }
                }
            }
        }

        return [$namespace, $aliasMap];
    }

    /**
     * Every T_FUNCTION/T_FN with a body: name, param range, body range, docblock.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{name: string|null, params: array{0:int,1:int}, body: array{0:int,1:int}, doc: string|null}>
     */
    private static function functionScopes(array $tokens): array
    {
        $count = count($tokens);
        $scopes = [];

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || !in_array($t[0], [T_FUNCTION, T_FN], true)) {
                continue;
            }
            $isArrow = $t[0] === T_FN;

            $name = null;
            $probe = self::nextSignificant($tokens, $i + 1);
            if ($probe !== null && is_array($tokens[$probe]) && $tokens[$probe][0] === T_STRING) {
                $name = $tokens[$probe][1];
            }

            $pOpen = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === '(') {
                    $pOpen = $j;
                    break;
                }
            }
            if ($pOpen === null) {
                continue;
            }
            $pClose = self::matching($tokens, $pOpen, '(', ')');
            if ($pClose === null) {
                continue;
            }

            $bodyOpen = null;
            $bodyClose = null;
            for ($j = $pClose + 1; $j < $count; $j++) {
                $q = $tokens[$j];
                if ($isArrow && $q === '=>') {
                    $depth = 0;
                    for ($k = $j + 1; $k < $count; $k++) {
                        $r = $tokens[$k];
                        if ($r === '(' || $r === '[') {
                            $depth++;
                        } elseif ($r === ')' || $r === ']') {
                            if ($depth === 0) {
                                $bodyClose = $k;
                                break;
                            }
                            $depth--;
                        } elseif ($depth === 0 && $r === ';') {
                            $bodyClose = $k;
                            break;
                        }
                    }
                    $bodyOpen = $j;
                    break;
                }
                if ($q === '{') {
                    $bodyOpen = $j;
                    $bodyClose = self::matchingBrace($tokens, $j);
                    break;
                }
                if ($q === ';') {
                    break; // abstract/interface signature
                }
            }
            if ($bodyOpen === null || $bodyClose === null) {
                continue;
            }

            $doc = null;
            for ($j = $i - 1; $j >= max(0, $i - 60); $j--) {
                $q = $tokens[$j];
                if (is_array($q) && $q[0] === T_DOC_COMMENT) {
                    $doc = $q[1];
                    break;
                }
                if (is_array($q) && in_array($q[0], [T_WHITESPACE, T_COMMENT], true)) {
                    continue;
                }
                $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY];
                if (is_array($q) && in_array($q[0], $modifiers, true)) {
                    continue;
                }
                break;
            }

            $scopes[] = [
                'name' => $name,
                'params' => [$pOpen, $pClose],
                'body' => [$bodyOpen, $bodyClose],
                'doc' => $doc,
            ];
        }

        return $scopes;
    }

    /**
     * Parameters whose type chunk names the target class.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array{name: string|null, params: array{0: int, 1: int},
     *              body: array{0: int, 1: int}, doc: string|null} $scope
     * @param array<string,string> $aliasMap
     * @return list<string>
     */
    private static function rootsDeclaredInParams(
        array $tokens,
        array $scope,
        array $aliasMap,
        string $namespace,
        bool $inRequestFile
    ): array {
        [$pOpen, $pClose] = $scope['params'];
        $vars = [];
        $depth = 0;
        $segmentStart = $pOpen + 1;

        for ($j = $pOpen; $j <= $pClose; $j++) {
            $q = $tokens[$j];
            if ($q === '(' || $q === '[') {
                $depth++;
                continue;
            }
            $isFinal = $q === ')' && $depth === 1 && $j === $pClose;
            if ($q === ')' || $q === ']') {
                $depth--;
            }
            if ($depth !== 1 || ($q !== ',' && !$isFinal)) {
                continue;
            }
            for ($k = $segmentStart; $k < $j; $k++) {
                $r = $tokens[$k];
                if (is_array($r) && $r[0] === T_VARIABLE) {
                    if (self::chunkTargets($tokens, $segmentStart, $k - 1, $aliasMap, $namespace, $inRequestFile)) {
                        $vars[] = $r[1];
                    }
                    break;
                }
            }
            $segmentStart = $j + 1;
        }

        return $vars;
    }

    /**
     * `@param Request $x` / `@var Request $x` docblock hints.
     *
     * @param array<string,string> $aliasMap
     * @return list<string>
     */
    private static function rootsDeclaredInDocblock(
        string $doc,
        array $aliasMap,
        string $namespace,
        bool $inRequestFile
    ): array {
        $vars = [];
        if (!preg_match_all('/@(?:param|var)\s+([\\\\\w|&\[\]]+)\s+\$(\w+)/', $doc, $matches, PREG_SET_ORDER)) {
            return $vars;
        }
        foreach ($matches as $one) {
            foreach (preg_split('/[|&]/', $one[1]) ?: [] as $type) {
                $type = rtrim($type, '[]');
                $lower = strtolower(ltrim($type, '\\'));
                $hit = false;
                if ($lower === 'self' || $lower === 'static') {
                    $hit = $inRequestFile;
                } elseif (str_starts_with($lower, 'phlix\\server\\http\\')) {
                    $hit = $lower === strtolower(self::TARGET_CLASS);
                } else {
                    $short = strtolower(self::shortName($type));
                    if (isset($aliasMap[$short]) || $short === 'request') {
                        $resolved = $aliasMap[$short] ?? ($namespace . '\\' . $type);
                        $hit = strcasecmp($resolved, self::TARGET_CLASS) === 0;
                    }
                }
                if ($hit) {
                    $vars[] = '$' . $one[2];
                    break;
                }
            }
        }

        return $vars;
    }

    /**
     * Named functions in this file whose own body (excluding nested function
     * bodies) returns a Request expression or a visible root variable.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<array{name: string|null, params: array{0: int, 1: int},
     *                   body: array{0: int, 1: int}, doc: string|null}> $scopes
     * @param array<string,int> $namedFns
     * @param callable(int): array<string,bool> $visible
     * @param callable(list<array{0: int, 1: string, 2: int}|string>, int): bool $isRequestExpr
     * @return array<string,true>
     */
    private static function computeFactories(
        array $tokens,
        array $scopes,
        array $namedFns,
        callable $visible,
        callable $isRequestExpr
    ): array {
        $count = count($tokens);
        $factories = [];

        foreach ($namedFns as $name => $si) {
            [$bodyOpen, $bodyClose] = $scopes[$si]['body'];
            $nested = [];
            foreach ($scopes as $sj => $other) {
                if ($sj !== $si && $other['body'][0] > $bodyOpen && $other['body'][1] < $bodyClose) {
                    $nested[] = $other['body'];
                }
            }
            for ($j = $bodyOpen + 1; $j < $bodyClose; $j++) {
                $t = $tokens[$j];
                if (!is_array($t) || $t[0] !== T_RETURN) {
                    continue;
                }
                $insideNested = false;
                foreach ($nested as [$n0, $n1]) {
                    if ($j > $n0 && $j < $n1) {
                        $insideNested = true;
                        break;
                    }
                }
                if ($insideNested) {
                    continue;
                }
                $v = self::nextSignificant($tokens, $j + 1);
                if ($v === null) {
                    break;
                }
                if ($isRequestExpr($tokens, $v)) {
                    $factories[$name] = true;
                } elseif (
                    is_array($tokens[$v])
                    && $tokens[$v][0] === T_VARIABLE
                    && isset($visible($j)[$tokens[$v][1]])
                ) {
                    $factories[$name] = true;
                }
                break; // first return decides
            }
        }

        return $factories;
    }

    /**
     * Does the type chunk between two token indices name the target class?
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string,string> $aliasMap
     */
    private static function chunkTargets(
        array $tokens,
        int $from,
        int $to,
        array $aliasMap,
        string $namespace,
        bool $inRequestFile
    ): bool {
        for ($i = $from; $i <= $to; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || !in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            if (self::nameTargets($t, $aliasMap, $namespace, $inRequestFile)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does a class-name token (after alias/namespace resolution) denote Request?
     *
     * @param array{0: int, 1: string, 2: int} $token
     * @param array<string,string> $aliasMap
     */
    private static function nameTargets(array $token, array $aliasMap, string $namespace, bool $inRequestFile): bool
    {
        if ($token[0] === T_STATIC) {
            return $inRequestFile;
        }
        if (!in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }
        $word = $token[1];
        if ($inRequestFile && $token[0] === T_STRING && in_array(strtolower($word), ['self', 'static'], true)) {
            return true;
        }
        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            return strcasecmp(ltrim($word, '\\'), self::TARGET_CLASS) === 0;
        }
        if (str_contains($word, '\\')) {
            $first = substr($word, 0, strpos($word, '\\') ?: 0);
            $rest = substr($word, strlen($first) + 1);
            $resolved = $aliasMap[strtolower($first)] ?? ($namespace . '\\' . $first);

            return strcasecmp($resolved . '\\' . $rest, self::TARGET_CLASS) === 0;
        }
        $lower = strtolower($word);
        if (isset($aliasMap[$lower])) {
            return strcasecmp($aliasMap[$lower], self::TARGET_CLASS) === 0;
        }

        return $lower === 'request' && strcasecmp($namespace . '\\Request', self::TARGET_CLASS) === 0;
    }

    /**
     * `$x = $anything ?? <request-expr>` — the coalesce fallback still yields a Request.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param callable(list<array{0: int, 1: string, 2: int}|string>, int): bool $isRequestExpr
     */
    private static function afterCoalesced(array $tokens, int $coalesceIndex, callable $isRequestExpr): bool
    {
        $q = self::nextSignificant($tokens, $coalesceIndex + 1);

        return $q !== null && $isRequestExpr($tokens, $q);
    }

    /**
     * When the token at $from begins a `->method(`/`::method(` call shape, its
     * method name; else null.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function callShape(array $tokens, int $from): ?string
    {
        $count = count($tokens);
        $w = self::nextSignificant($tokens, $from + 1);
        if (
            $w === null || !is_array($tokens[$w])
            || !in_array($tokens[$w][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
        ) {
            return null;
        }
        $w = self::nextSignificant($tokens, $w + 1);
        if ($w === null || !is_array($tokens[$w]) || $tokens[$w][0] !== T_STRING) {
            return null;
        }
        $name = $tokens[$w][1];
        $p = self::nextSignificant($tokens, $w + 1);

        return $p !== null && $tokens[$p] === '(' ? $name : null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], self::IGNORED_TOKENS, true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function prevSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], self::IGNORED_TOKENS, true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function matching(array $tokens, int $open, string $openChar, string $closeChar): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i] === $openChar) {
                $depth++;
            } elseif ($tokens[$i] === $closeChar) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function matchingBrace(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            if (
                $tokens[$i] === '{'
                || (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
            ) {
                $depth++;
            } elseif ($tokens[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private static function shortName(string $name): string
    {
        $pos = strrpos($name, '\\');

        return $pos === false ? $name : substr($name, $pos + 1);
    }
}
