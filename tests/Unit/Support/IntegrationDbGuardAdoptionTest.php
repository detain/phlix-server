<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

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
 * guards — is respected by measurement rather than by assumption: after S126's
 * migration both rules below match **zero** files across the whole `tests/`
 * tree, and there is no test in the tree that opens a socket for any other
 * reason. S120's rejected static rule differed in that it had a *present,
 * actual* false positive.
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

    public function test_no_test_declares_its_own_mysql_reachability_probe(): void
    {
        $offenders = [];

        foreach ($this->testFiles() as $relative => $tokens) {
            foreach ($this->declaredFunctionNames($tokens) as $line => $name) {
                if ($name === 'isMysqlReachable') {
                    $offenders[] = $relative . ':' . $line;
                }
            }
        }

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

    public function test_no_test_opens_its_own_socket_probe(): void
    {
        $offenders = [];

        foreach ($this->testFiles() as $relative => $tokens) {
            if (str_starts_with($relative, self::SHARED_SUPPORT_DIR . '/')) {
                continue;
            }

            foreach ($this->calledFunctionNames($tokens) as $line => $name) {
                if ($name === 'fsockopen' || $name === 'pfsockopen') {
                    $offenders[] = $relative . ':' . $line;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "S126: an fsockopen()/pfsockopen() call appeared in a test outside "
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
     * The migrated tree must actually be using the shared guard — a rule that is
     * silent because nothing calls the guard at all would prove nothing.
     */
    public function test_the_shared_guard_is_adopted_by_the_integration_suite(): void
    {
        $users = 0;

        foreach ($this->testFiles() as $relative => $tokens) {
            if (str_starts_with($relative, self::SHARED_SUPPORT_DIR . '/')) {
                continue;
            }

            $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);

            if (str_contains($source, 'RequiresRealDatabase')) {
                $users++;
            }
        }

        // 35 files declared a private probe when S126 was written; every one of
        // them was migrated. Asserted as a floor rather than an equality so that
        // deleting a test does not fail this check for an unrelated reason.
        $this->assertGreaterThanOrEqual(
            35,
            $users,
            'S126: fewer files use RequiresRealDatabase than the 35 that were migrated. '
            . 'If a real-DB test was removed, lower this floor deliberately; if the trait '
            . 'was dropped from a test, restore it.',
        );
    }

    /**
     * Every `*.php` under `tests/`, keyed by repo-relative path, valued by its
     * token stream.
     *
     * @return array<string, list<array{0: int, 1: string, 2: int}|string>>
     */
    private function testFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $testsDir = $root . '/tests';

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testsDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);
            /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
            $tokens = token_get_all((string) file_get_contents($path));
            $files[$relative] = $tokens;
        }

        ksort($files);

        return $files;
    }

    /**
     * Names declared with the `function` keyword, keyed by line.
     *
     * Token-based, so a name that only appears inside a docblock, a comment or a
     * string literal is not matched — which is what keeps this rule free of the
     * prose false positives a plain grep produces (two files carried the word
     * `fsockopen` in a docblock before S126).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<int, string>
     */
    private function declaredFunctionNames(array $tokens): array
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
                    $names[$next[2]] = $next[1];
                }

                break;
            }
        }

        return $names;
    }

    /**
     * Bare function names that are immediately followed by `(`, keyed by line.
     *
     * Deliberately ignores `->name(` and `::name(` so a method that happens to
     * share a name with a global function is not matched.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<int, string>
     */
    private function calledFunctionNames(array $tokens): array
    {
        $names = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $previous = $this->significantToken($tokens, $i, -1);

            $qualifiers = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR];

            if (is_array($previous) && in_array($previous[0], $qualifiers, true)) {
                continue;
            }

            if ($this->significantToken($tokens, $i, 1) === '(') {
                $names[$token[2]] = $token[1];
            }
        }

        return $names;
    }

    /**
     * The nearest token in `$direction` that is not whitespace or a comment.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function significantToken(array $tokens, int $from, int $direction): array|string|null
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
}
