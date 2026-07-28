<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * S126 — the mechanical check that stops the 36th private MySQL probe.
 *
 * ## What it is guarding against
 *
 * Before S126, **35** files under `tests/` each declared their own
 * `private function isMysqlReachable()` — an `@fsockopen()` port probe — and
 * treated its success as "the database works". Measured on 2026-07-27:
 * `grep -rl 'function isMysqlReachable' tests/` → 35 files, all 35 also
 * containing `markTestSkipped` (80 occurrences), and `grep -rn fsockopen tests/`
 * → 37 hits in the same 35 files (the two extras being docblock prose in
 * `tests/Integration/Auth/AccountLinkIntegrationTest.php` and
 * `tests/Integration/Auth/UserIdentitiesMigrationIntegrationTest.php`). The 35
 * bodies had already drifted into three variants (28 / 5 / 2 by md5), differing
 * only in the local variable name (`$sock` vs `$socket`) and one blank line — so
 * copy-paste, not divergent intent.
 *
 * A port probe cannot distinguish "no MySQL on this box" (a legitimate skip)
 * from "wrong credentials / missing database" (a real failure), and the count
 * grew from 24 on 2026-07-25 to 35 on 2026-07-27 — faster than any hand cleanup.
 * {@see \Phlix\Tests\Support\Database\IntegrationDbGuard} is the replacement;
 * this test is what keeps it the only copy.
 *
 * ## Four rules, and why the last one exists
 *
 * The first two rules pin the *historical spelling* of the defect — the method
 * name `isMysqlReachable` and the `fsockopen()` call that implemented it. That
 * is necessary but not sufficient, because after S126 the cheapest way to
 * reintroduce "a broken database reports as an absent one" no longer looks like
 * a socket probe at all:
 *
 * ```php
 * try { $this->db = $this->requireRealDatabase('…'); }
 * catch (Throwable $e) { $this->markTestSkipped('no DB: ' . $e->getMessage()); }
 * ```
 *
 * That restores the defect *exactly* — {@see \Phlix\Tests\Support\Database\IntegrationDbUnusableException}
 * is thrown for a reachable-but-unusable database precisely so the run reddens,
 * and this shape converts it straight back into a green skip — while passing
 * rules 1 and 2 and looking like the 35 files around it. Rule 3
 * ({@see testNoTestCatchesTheGuardAndSkips()}) is the rule that describes the
 * *defect* rather than its 2026-07 spelling: a `markTestSkipped()` reached from
 * a `catch` that encloses a database-acquisition call site. The same rule covers
 * a hand-rolled probe written as `try { new PhlixMySQLConnection(…) } catch { skip }`.
 *
 * ## Static, not runtime — and why that is the opposite call from S120
 *
 * S120 shipped a *runtime* assertion-escape guard because its fact — "did an
 * assertion failure decide this test's outcome?" — is only knowable while the
 * test runs. The fact here is the exact opposite: "does this file declare its
 * own port probe?" is pure source text, fully available to `token_get_all()`,
 * with no runtime component. A runtime check would also be blind exactly when it
 * matters, because the defect's whole symptom is that the test **skips** — a
 * skipped test executes almost nothing, so on a machine with no MySQL a runtime
 * guard would never see the new copy. Static is the only form that does.
 *
 * S120's other lesson — a guard that false-positives trains people to delete
 * guards — is respected by measurement rather than by assumption. All four rules
 * match **zero** files across the whole `tests/` tree, and each one was proven
 * on planted code before shipping (verbatim probe caught, renamed probe caught,
 * catch-and-skip caught, docblock prose NOT caught). Planting is not ceremony:
 * it is what found that the original rule-2 helper keyed its results *by line*,
 * so `@fsockopen(getenv('DB_HOST') ?: '127.0.0.1', …)` was silently dropped when
 * the trailing `getenv` on the same line overwrote it. The 35 historical probes
 * happened to put nothing else on that line, so the rule looked correct.
 *
 * Two narrowings exist for exactly that reason:
 *
 *  - `socket_create()` / `socket_connect()` / `stream_socket_client()` are only
 *    flagged in a file that *also* references the database configuration
 *    (`DB_HOST`, `DB_PORT`, `3306`, `ConnectionPool`). Without that narrowing the
 *    rule fires on `tests/Unit/Discovery/Mdns/MdnsSocketTest.php:107,133`, which
 *    opens a UDP socket for mDNS and has nothing to do with MySQL — a present,
 *    actual false positive of exactly the kind S120 rejected a static rule for.
 *    `fsockopen()`/`pfsockopen()` need no narrowing: they are the historical
 *    mechanism and have no other user in the tree.
 *  - Rule 3 requires the *lexical* presence of a database-acquisition marker
 *    inside the `try` span. `MusicTrackPathHashLookupTest.php:594-624` wraps DDL
 *    in `try`/`catch` and legitimately skips from the catch; its `try` contains
 *    no such marker, so it is not flagged.
 *
 * Also like S120, there is no opt-out list. A test that genuinely needs to open
 * a socket puts that behind a named helper in `tests/Support/`, which is where
 * shared test infrastructure lives and which these rules exempt. That is a
 * location rule, not an escape hatch: it cannot be used to silence the check
 * without the change being visible in a shared directory.
 *
 * ## Delivery
 *
 * This is an ordinary PHPUnit test rather than a `scripts/` check wired into the
 * workflow. S120 needed a separate script because a PHPUnit *extension* cannot
 * influence the exit code under this repo's configuration
 * (`scripts/assertion-escape-check.php:8-22`); a plain test has no such problem,
 * needs no DB, fails the suite natively, and — the point — fires on the
 * developer's machine at the moment the copy is written rather than in CI later.
 *
 * It does not depend on S128: `phpstan.neon.dist` sets `paths: [src]`, so
 * PHPStan does not read `tests/` today, and this check deliberately does not
 * rely on that changing.
 */
final class IntegrationDbGuardAdoptionTest extends TestCase
{
    /**
     * Relative path, from the repo root, of the one directory allowed to open a
     * socket. `IntegrationDbGuard` itself lives here.
     */
    private const SHARED_SUPPORT_DIR = 'tests/Support/Database';

    /**
     * Files that must carry `use RequiresRealDatabase;` **in a class body**.
     *
     * 35 files declared a private probe when S126 was written and every one of
     * them was migrated. Asserted as an exact count, not a floor: a floor of
     * `>= 35` against a count of 36 (the old whole-file substring match counted
     * this very file, whose failure messages name the trait) left exactly one
     * unit of slack, so one migrated test could silently drop the trait and the
     * check stayed green. If a real-DB test is legitimately deleted or added,
     * change this number in the same commit — that is the point.
     */
    private const EXPECTED_ADOPTERS = 35;

    /**
     * Bare function calls that are a MySQL reachability probe under any
     * circumstances. Historical mechanism; no other user has ever existed here.
     */
    private const UNCONDITIONAL_SOCKET_CALLS = ['fsockopen', 'pfsockopen'];

    /**
     * Bare function calls that open a socket but have legitimate non-database
     * users in this tree, so they are only flagged in a file that also
     * references the database configuration ({@see DB_CONFIG_MARKERS}).
     */
    private const DB_TARGETED_SOCKET_CALLS = ['stream_socket_client', 'socket_create', 'socket_connect'];

    /**
     * Plain-source markers that say "this file talks to the configured MySQL".
     * Only used to *narrow* {@see DB_TARGETED_SOCKET_CALLS}, never to widen a rule.
     */
    private const DB_CONFIG_MARKERS = ['DB_HOST', 'DB_PORT', '3306', 'ConnectionPool'];

    /**
     * Identifiers whose presence inside a `try` block means "this block acquires
     * the real database". A `catch` around one of these that ends in
     * `markTestSkipped()` is the post-S126 spelling of the S126 defect.
     *
     * Matched on the last segment of the name, so `\Phlix\Common\Database\ConnectionPool::init()`
     * and a bare `ConnectionPool::init()` both count.
     */
    private const DB_ACQUISITION_MARKERS = [
        'requireRealDatabase',
        'requireHealthyDatabase',
        'IntegrationDbGuard',
        'IntegrationDbUnusableException',
        'ConnectionPool',
        'PhlixMySQLConnection',
        'PooledMySQLConnection',
    ];

    /**
     * The whole-tree scan, computed once per PHPUnit process.
     *
     * PHPUnit builds a fresh instance per test method, so without this the tree
     * was tokenised once per method. Memoising the *findings* (four small
     * arrays) rather than the token streams is what makes this cheap: the scan
     * below tokenises one file, inspects it, and discards the tokens before
     * reading the next, so peak memory is one file's tokens rather than 705
     * files' — measured 298 MB / 3.3 s before, see the class docblock of
     * `IntegrationDbGuard` for the guard this protects. Bounded to a single
     * entry, so it cannot grow.
     *
     * @var array{probes: list<string>, sockets: list<string>, catchSkips: list<string>, adopters: list<string>}|null
     */
    private static ?array $scan = null;

    public function testNoTestDeclaresItsOwnMysqlReachabilityProbe(): void
    {
        $offenders = self::scan()['probes'];

        $this->assertSame(
            [],
            $offenders,
            "S126: a private isMysqlReachable() probe reappeared under tests/.\n"
            . "An fsockopen() port probe cannot tell \"no MySQL on this box\" (skip) from\n"
            . "\"wrong credentials / missing database\" (a real failure), and in the default\n"
            . "pooled configuration nothing after it can fail either — PooledMySQLConnection\n"
            . "opens no socket in its constructor (src/Common/Database/PooledMySQLConnection.php:108).\n"
            . 'Remedy: `use ' . 'Phlix\Tests\Support\Database\RequiresRealDatabase;` and call'
            . " \$this->requireRealDatabase('skipping <what>. Runs in CI.') instead.\n"
            . 'Offending declarations: ' . implode(', ', $offenders),
        );
    }

    public function testNoTestOpensItsOwnSocketProbe(): void
    {
        $offenders = self::scan()['sockets'];

        $this->assertSame(
            [],
            $offenders,
            "S126: a raw socket call appeared in a test outside "
            . self::SHARED_SUPPORT_DIR . ".\n"
            . "This is the mechanism of the 35 duplicated MySQL probes S126 removed: a port\n"
            . "probe that succeeds proves only that SOMETHING is listening, never that the\n"
            . "database is usable, so a broken config is reported as an absent one.\n"
            . 'For MySQL, use `Phlix\Tests\Support\Database\RequiresRealDatabase`. If the socket'
            . " is genuinely for something else, put it behind a named helper under\n"
            . "tests/Support/ so there is one reviewable copy rather than 35.\n"
            . 'Offending calls: ' . implode(', ', $offenders),
        );
    }

    /**
     * The rule that describes the defect rather than its 2026-07 spelling.
     *
     * `IntegrationDbUnusableException` exists so that "something is listening on
     * the DB port but no query can run over it" reddens the run. Catching it —
     * or catching anything thrown by a database-acquisition call — and calling
     * `markTestSkipped()` converts it straight back into the green skip S126
     * removed, without ever mentioning `isMysqlReachable` or `fsockopen`.
     */
    public function testNoTestCatchesTheGuardAndSkips(): void
    {
        $offenders = self::scan()['catchSkips'];

        $this->assertSame(
            [],
            $offenders,
            "S126: a catch around a database-acquisition call ends in markTestSkipped().\n"
            . "That is the S126 defect in its post-migration spelling: IntegrationDbUnusableException\n"
            . "is raised precisely so a reachable-but-UNUSABLE database reddens the run, and swallowing\n"
            . "it into a skip reports success without ever touching a database — the exact outcome the\n"
            . "35 fsockopen() probes produced.\n"
            . "Remedy: call \$this->requireRealDatabase('skipping <what>. Runs in CI.') WITHOUT a\n"
            . "try/catch. It already skips on genuine absence (nothing listening) all by itself.\n"
            . 'Offending catch blocks: ' . implode(', ', $offenders),
        );
    }

    /**
     * The migrated tree must actually be using the shared guard — a rule that is
     * silent because nothing calls the guard at all would prove nothing.
     *
     * Counts real, in-class-body `use RequiresRealDatabase;` trait usage via the
     * token stream. A whole-file `str_contains()` would also count a file that
     * keeps only the `use Phlix\…\RequiresRealDatabase;` import or a
     * `{@see RequiresRealDatabase}` docblock after the in-class `use` was
     * dropped — which is the realistic regression shape (setUp rewritten,
     * imports left behind), and 12 migrated files mention the name 2–3 times.
     */
    public function testTheSharedGuardIsAdoptedByTheIntegrationSuite(): void
    {
        $adopters = self::scan()['adopters'];

        $this->assertCount(
            self::EXPECTED_ADOPTERS,
            $adopters,
            sprintf(
                'S126: %d files declare `use RequiresRealDatabase;` in a class body, expected %d. '
                . 'If a real-DB test was added or removed, update EXPECTED_ADOPTERS in the same '
                . 'commit; if the trait was dropped from a test while its import or docblock stayed '
                . 'behind, restore it. Current adopters: %s',
                count($adopters),
                self::EXPECTED_ADOPTERS,
                implode(', ', $adopters),
            ),
        );
    }

    /**
     * One tokenise-inspect-discard pass over every `*.php` under `tests/`.
     *
     * @return array{probes: list<string>, sockets: list<string>, catchSkips: list<string>, adopters: list<string>}
     */
    private static function scan(): array
    {
        if (self::$scan !== null) {
            return self::$scan;
        }

        $root = dirname(__DIR__, 3);
        // This file's own failure messages name `RequiresRealDatabase`, and its
        // rule constants name `fsockopen`. Excluded from the adoption count so
        // the expected total is exactly the number of migrated tests.
        $selfFile = (string) (new ReflectionClass(self::class))->getFileName();

        $probes = [];
        $sockets = [];
        $catchSkips = [];
        $adopters = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/tests', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);
            $source = (string) file_get_contents($path);
            /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
            $tokens = token_get_all($source);

            foreach (self::declaredFunctionNames($tokens) as [$line, $name]) {
                if ($name === 'isMysqlReachable') {
                    $probes[] = $relative . ':' . $line;
                }
            }

            if (!str_starts_with($relative, self::SHARED_SUPPORT_DIR . '/')) {
                $dbTargeted = self::mentionsDatabaseConfig($source);

                foreach (self::calledFunctionNames($tokens) as [$line, $name]) {
                    $flagged = in_array($name, self::UNCONDITIONAL_SOCKET_CALLS, true)
                        || ($dbTargeted && in_array($name, self::DB_TARGETED_SOCKET_CALLS, true));

                    if ($flagged) {
                        $sockets[] = $relative . ':' . $line;
                    }
                }

                foreach (self::catchAndSkipLines($tokens) as $line) {
                    $catchSkips[] = $relative . ':' . $line;
                }

                if ($path !== $selfFile && in_array('RequiresRealDatabase', self::traitUses($tokens), true)) {
                    $adopters[] = $relative;
                }
            }

            unset($tokens, $source);
        }

        sort($probes);
        sort($sockets);
        sort($catchSkips);
        sort($adopters);

        self::$scan = [
            'probes' => $probes,
            'sockets' => $sockets,
            'catchSkips' => $catchSkips,
            'adopters' => $adopters,
        ];

        return self::$scan;
    }

    /**
     * Whether a file references the configured MySQL at all. Narrowing only —
     * see {@see DB_TARGETED_SOCKET_CALLS}.
     */
    private static function mentionsDatabaseConfig(string $source): bool
    {
        foreach (self::DB_CONFIG_MARKERS as $marker) {
            if (str_contains($source, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Names declared with the `function` keyword, as `[line, name]` pairs.
     *
     * Token-based, so a name that only appears inside a docblock, a comment or a
     * string literal is not matched — which is what keeps this rule free of the
     * prose false positives a plain grep produces (two files carried the word
     * `fsockopen` in a docblock before S126).
     *
     * ⚠ A *list*, not a map keyed by line. Keying by line silently drops every
     * occurrence but the last on a shared line — the bug that let
     * `@fsockopen(getenv('DB_HOST') ?: '127.0.0.1', …)` evade the socket rule
     * entirely, because `getenv` came after it on the same line and overwrote it.
     * Caught by planting exactly that probe; see the class docblock.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: int, 1: string}>
     */
    private static function declaredFunctionNames(array $tokens): array
    {
        $names = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];

                if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                // `function &foo()` — a by-reference return.
                if ($next === '&') {
                    continue;
                }

                if (is_array($next) && $next[0] === T_STRING) {
                    $names[] = [$next[2], $next[1]];
                }

                break;
            }
        }

        return $names;
    }

    /**
     * Bare function names that are immediately followed by `(`, as `[line, name]`
     * pairs — see {@see declaredFunctionNames()} for why this is not keyed by line.
     *
     * Deliberately ignores `->name(` and `::name(` so a method that happens to
     * share a name with a global function is not matched.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: int, 1: string}>
     */
    private static function calledFunctionNames(array $tokens): array
    {
        $names = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $previous = self::significantToken($tokens, $i, -1);

            $qualifiers = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR];

            if (is_array($previous) && in_array($previous[0], $qualifiers, true)) {
                continue;
            }

            if (self::significantToken($tokens, $i, 1) === '(') {
                $names[] = [$token[2], $token[1]];
            }
        }

        return $names;
    }

    /**
     * Trait names brought in by a `use` **inside a class body**, unqualified.
     *
     * Three `use` spellings share the keyword and must be told apart:
     *  - a file-level import (`use Foo\Bar;`) — always at brace depth 0;
     *  - a closure capture (`function () use ($x)`) — the next significant token
     *    is `(`;
     *  - a trait use (`use Bar;` / `use Foo\Bar;` / `use A, B { … }`) — what this
     *    returns.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<string>
     */
    private static function traitUses(array $tokens): array
    {
        $names = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (self::opensBrace($token)) {
                $depth++;
                continue;
            }

            if ($token === '}') {
                $depth--;
                continue;
            }

            if (!is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            if ($depth === 0 || self::significantToken($tokens, $i, 1) === '(') {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];

                if ($next === ';' || $next === '{') {
                    break;
                }

                if (is_array($next) && self::isNameToken($next[0])) {
                    $names[] = self::lastNameSegment($next[1]);
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Lines of `markTestSkipped()` calls sitting in a `catch` that either names
     * `IntegrationDbUnusableException` or guards a `try` containing a
     * database-acquisition call ({@see DB_ACQUISITION_MARKERS}).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<int>
     */
    private static function catchAndSkipLines(array $tokens): array
    {
        $lines = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_TRY) {
                continue;
            }

            $open = self::nextBrace($tokens, $i);
            $close = $open === null ? null : self::matchingBrace($tokens, $open);

            if ($open === null || $close === null) {
                continue;
            }

            $acquiresDatabase = self::spanNames($tokens, $open, $close, self::DB_ACQUISITION_MARKERS) !== [];
            $cursor = $close;

            while (true) {
                $next = self::significantIndex($tokens, $cursor + 1);

                if ($next === null) {
                    break;
                }

                $clause = $tokens[$next];

                if (!is_array($clause) || $clause[0] !== T_CATCH) {
                    break;
                }

                $body = self::nextBrace($tokens, $next);
                $bodyEnd = $body === null ? null : self::matchingBrace($tokens, $body);

                if ($body === null || $bodyEnd === null) {
                    break;
                }

                $catchesTheGuard = $acquiresDatabase
                    || self::spanNames($tokens, $next, $body, ['IntegrationDbUnusableException']) !== [];

                if ($catchesTheGuard) {
                    foreach (self::spanNames($tokens, $body, $bodyEnd, ['markTestSkipped']) as [$line, $_name]) {
                        $lines[] = $line;
                    }
                }

                $cursor = $bodyEnd;
            }
        }

        sort($lines);

        return array_values(array_unique($lines));
    }

    /**
     * Occurrences of any `$wanted` identifier strictly between two token indexes,
     * as `[line, name]` pairs. Matches on the last segment of a qualified name,
     * so `\Phlix\Common\Database\ConnectionPool` and `ConnectionPool` both count.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<string>                                  $wanted
     * @return list<array{0: int, 1: string}>
     */
    private static function spanNames(array $tokens, int $from, int $to, array $wanted): array
    {
        $found = [];

        for ($i = $from + 1; $i < $to; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || !self::isNameToken($token[0])) {
                continue;
            }

            $name = self::lastNameSegment($token[1]);

            if (in_array($name, $wanted, true)) {
                $found[] = [$token[2], $name];
            }
        }

        return $found;
    }

    /**
     * Index of the first `{` at or after `$from`, or `null`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextBrace(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            if (self::opensBrace($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Index of the `}` closing the `{` at `$open`, or `null`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function matchingBrace(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];

            if (self::opensBrace($token)) {
                $depth++;
                continue;
            }

            if ($token === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * `{`, plus the two interpolation openers that are also closed by a plain
     * `}` (`"{$a}"` and `"${a}"`), so brace depth stays balanced inside strings.
     *
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function opensBrace(array|string $token): bool
    {
        if ($token === '{') {
            return true;
        }

        return is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
    }

    private static function isNameToken(int $type): bool
    {
        return in_array($type, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
    }

    private static function lastNameSegment(string $name): string
    {
        $parts = explode('\\', $name);

        return (string) end($parts);
    }

    /**
     * The nearest token in `$direction` that is not whitespace or a comment.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function significantToken(array $tokens, int $from, int $direction): array|string|null
    {
        for ($i = $from + $direction; $i >= 0 && $i < count($tokens); $i += $direction) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * Index of the first token at or after `$from` that is not whitespace or a
     * comment.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function significantIndex(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = max(0, $from); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
