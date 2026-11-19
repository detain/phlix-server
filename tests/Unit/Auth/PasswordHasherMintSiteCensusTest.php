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

        $this->assertArrayHasKey(
            self::SSOT_RELATIVE_PATH,
            $hits,
            'The scan must actually find PasswordHasher\'s own primitive calls — an empty '
                . 'census here means the token walk broke, not that the law holds.'
        );
        $this->assertCount(
            3,
            $hits[self::SSOT_RELATIVE_PATH],
            'PasswordHasher is exactly three primitive calls (hash/verify/needsRehash); a '
                . 'different count means the class shape drifted away from the estate contract.'
        );
    }
}
