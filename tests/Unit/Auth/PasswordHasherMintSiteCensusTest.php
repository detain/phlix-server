<?php

/**
 * Phlix media server component: Auth tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The executable half of the B-06 SSOT law: the password primitives
 * password_hash(), password_verify() and password_needs_rehash() may be
 * CALLED from exactly one first-party production file —
 * src/Auth/PasswordHasher.php. A new ad-hoc mint anywhere in src/ reddens
 * this census, which is what stops the nine-scattered-call-site history from
 * re-forming after the migration.
 *
 * Scope is src/ only, by design: tests must mint NON-canonical fixtures
 * (drifted-cost hashes cannot come from the SSOT class), and the census
 * itself quotes these function names. Quoted strings and comments never
 * register — the scan works on PHP tokens, where those are T_CONSTANT_ENCAPSED_STRING /
 * T_COMMENT / T_DOC_COMMENT, not the bare function-call token shape.
 */
final class PasswordHasherMintSiteCensusTest extends TestCase
{
    private const SSOT_RELATIVE_PATH = 'Auth/PasswordHasher.php';

    /** @var list<string> */
    private const GUARDED_FUNCTIONS = ['password_hash', 'password_verify', 'password_needs_rehash'];

    /** Whether a token is skipped when looking for the significant neighbour. */
    private function isInsignificant(array|string $token): bool
    {
        return \is_array($token)
            && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @return array<string, list<int>> map of src-relative path => calling lines
     */
    private function collectPrimitiveCallSites(): array
    {
        $srcRoot = \dirname(__DIR__, 3) . '/src';
        $this->assertDirectoryExists($srcRoot, 'The census must scan the real production tree.');

        $hits = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $tokens = token_get_all((string) file_get_contents($file->getPathname()));
            $relative = \substr($file->getPathname(), \strlen($srcRoot) + 1);

            for ($i = 0, $count = \count($tokens); $i < $count; $i++) {
                $token = $tokens[$i];
                $isGuardedName = \is_array($token)
                    && $token[0] === T_STRING
                    && \in_array($token[1], self::GUARDED_FUNCTIONS, true);
                if (!$isGuardedName) {
                    continue;
                }

                // Nearest preceding significant token: a definition, member access,
                // or class constant declaration is not a global call.
                $prev = null;
                for ($j = $i - 1; $j >= 0; $j--) {
                    if (!$this->isInsignificant($tokens[$j])) {
                        $prev = $tokens[$j];
                        break;
                    }
                }
                $isMemberish = \is_array($prev) && \in_array(
                    $prev[0],
                    [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR, T_CONST],
                    true
                );
                if ($isMemberish) {
                    continue;
                }

                // Must be applied to an argument list to count as a call. A
                // T_NS_SEPARATOR immediately before (\password_hash(...)) is
                // still a global call.
                $next = null;
                for ($k = $i + 1; $k < $count; $k++) {
                    if (!$this->isInsignificant($tokens[$k])) {
                        $next = $tokens[$k];
                        break;
                    }
                }

                if ($next === '(') {
                    $hits[$relative][] = $token[2];
                }
            }
        }

        return $hits;
    }

    public function testThePasswordPrimitivesAreOnlyEverCalledInsideTheSsotClass(): void
    {
        $hits = $this->collectPrimitiveCallSites();

        $offenders = [];
        foreach ($hits as $relative => $lines) {
            if ($relative !== self::SSOT_RELATIVE_PATH) {
                $offenders[] = $relative . ':' . \implode(',', $lines);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'B-06 SSOT violation: password_hash()/password_verify()/password_needs_rehash() '
                . 'were called outside src/' . self::SSOT_RELATIVE_PATH . '. Route mint, '
                . 'verify and rehash through PasswordHasher::hash()/verify()/needsRehash() '
                . '(the three signatures are the estate contract shared with the hub twin).'
        );
    }

    public function testTheSsotClassHoldsTheThreePrimitiveCallsItPinsAway(): void
    {
        $hits = $this->collectPrimitiveCallSites();

        // Wave-S1 (phlix-shared v0.56.0 adoption): the three primitives moved
        // into the shared SSOT and src/Auth/PasswordHasher.php became a thin
        // delegate. The census law is unchanged in effect — first-party src
        // holds ZERO primitive call sites — but the scanner sanity arm now
        // reads the shared class, which is where the estate's only
        // password_*() calls legitimately live.
        $this->assertArrayNotHasKey(
            self::SSOT_RELATIVE_PATH,
            $hits,
            'src/Auth/PasswordHasher.php must stay a pure delegate — any real '
                . 'password_*() call returning there bypasses the shared SSOT.'
        );

        $sharedSsot = \dirname(__DIR__, 3) . '/vendor/detain/phlix-shared/src/Auth/PasswordHasher.php';
        $this->assertFileExists($sharedSsot, 'The shared PasswordHasher is the post-v0.56.0 primitive home.');

        $tokens = token_get_all((string) file_get_contents($sharedSsot));
        $primitiveLines = [];
        foreach ($tokens as $token) {
            if (\is_array($token) && $token[0] === T_STRING && \in_array($token[1], self::GUARDED_FUNCTIONS, true)) {
                $primitiveLines[] = $token[2];
            }
        }

        $this->assertCount(
            4,
            $primitiveLines,
            'The shared SSOT is exactly four primitive tokens (hash, verify x2 — the B-06 '
                . 'unknown-user burn arm and the real verify — needsRehash); a different count '
                . 'means the class shape drifted away from the estate contract or this scan '
                . 'broke (empty means the walk is dead, not that the law holds).'
        );
    }
}
