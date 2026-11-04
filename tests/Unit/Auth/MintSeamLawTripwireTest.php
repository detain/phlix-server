<?php

/**
 * Phlix media server component: Auth tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Plugins\Github\Controller\GithubCallbackController;
use Phlix\Plugins\Oidc\Controller\OidcCallbackController;
use Phlix\Server\Http\Controllers\HubTokenController;
use PHPUnit\Framework\TestCase;

/**
 * SRV-M18 REWORK tripwire (review server__pm): the must-change-password
 * hold is a UNIVERSAL mint law, so the enumeration of token-minting sites
 * must be machine-pinned, not README prose. This test tokenizes every src/
 * PHP file and asserts:
 *
 *  1. the set of files containing a JwtHandler `createAccessToken` /
 *     `createRefreshToken` CALL is exactly the audited five — AuthManager's
 *     seam plus the four legs the review rework brought into the law;
 *  2. every file in that set except the inert carve-out calls the shared
 *     {@see \Phlix\Auth\PasswordChangeRequiredException::refuseIfHeld()}
 *     guard — so the hold consult can never drift away from a minter again;
 *  3. the one ungated minter, SyncPlayAuthMiddleware::createToken(), stays
 *     exactly what it is at the cure: a dead static with ZERO call sites
 *     in src/ and tests/. If anyone ever wires it, this test goes red and
 *     the mint must join the guard first.
 *
 * A NEW mint surface (a controller calling createAccessToken() anywhere in
 * src/) reddens check 1 immediately — the law cannot be widened by accident
 * or amnesia again. Comments and strings are invisible to the walk: the
 * scanner reads PHP tokens (T_STRING names preceded by an operator), so
 * docblock examples (`@example ... createToken(...)`) and mock stub names
 * (`->method('createAccessToken')` — a quoted string) never count.
 *
 * @package Phlix\Tests\Unit\Auth
 * @since   SRV-M18 rework (2026-11-04, review server__pm)
 */
final class MintSeamLawTripwireTest extends TestCase
{
    /** Root of the first-party source tree this walk audits. */
    private const SRC_DIR = __DIR__ . '/../../../src';

    /** Root of the test tree scanned for carve-out references. */
    private const TESTS_DIR = __DIR__ . '/../../';

    /**
     * The audited mint-call files, relative to src/. Rotating this set is
     * deliberate work: a new minter must first join the guard (checks 2/3).
     */
    private const AUDITED_MINTERS = [
        'Auth/AuthManager.php',
        'Plugins/Oidc/Controller/OidcCallbackController.php',
        'Plugins/Github/Controller/GithubCallbackController.php',
        'Server/Http/Controllers/HubTokenController.php',
        'Server/WebSocket/SyncPlayAuthMiddleware.php',
    ];

    /**
     * The single tolerated ungated minter (dead static — see check 3): its
     * mint lives inside a helper with zero callers, pinned dead by
     * {@see self::testTheCarveOutStayerIsDeadAndStaysDead()}.
     */
    private const CARVE_OUTS = [
        'Server/WebSocket/SyncPlayAuthMiddleware.php',
    ];

    /**
     * Relative paths (from src/) of every src/ file with a
     * createAccessToken/createRefreshToken CALL site — not a definition,
     * comment, or string.
     *
     * @return list<string>
     */
    private function mintCallFiles(): array
    {
        $files = [];
        foreach ($this->phpFiles(self::SRC_DIR) as $abs) {
            $relative = str_replace(self::SRC_DIR . '/', '', $abs);
            if ($this->hasCallTo($this->tokens($abs), ['createAccessToken', 'createRefreshToken'])) {
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }

    public function testTheMintCallSiteSetIsExactlyTheAuditedFive(): void
    {
        $expected = self::AUDITED_MINTERS;
        sort($expected);

        $this->assertSame(
            $expected,
            $this->mintCallFiles(),
            'every token-minting site in src/ must be on the audited list; '
                . 'a new createAccessToken/createRefreshToken caller means the '
                . 'must-change-password law must be applied there FIRST (review server__pm)',
        );
    }

    public function testEveryGatedMinterCallsTheSharedHoldGuard(): void
    {
        foreach (self::AUDITED_MINTERS as $relative) {
            if (in_array($relative, self::CARVE_OUTS, true)) {
                continue;
            }
            $tokens = $this->tokens(self::SRC_DIR . '/' . $relative);
            $this->assertTrue(
                $this->hasCallTo($tokens, ['refuseIfHeld']),
                "minter {$relative} must consult PasswordChangeRequiredException::refuseIfHeld() "
                    . '— the single-source hold guard — before it mints',
            );
        }
    }

    /**
     * The four live surfaces are also pinned at their CLASS level: if any of
     * these controllers is deleted or moved the relative-path set above
     * would silently shrink past a careless reviewer — the classname import
     * here makes that a loud, named failure.
     */
    public function testTheAuditedMinterClassesStillExist(): void
    {
        $classes = [
            OidcCallbackController::class,
            GithubCallbackController::class,
            HubTokenController::class,
        ];
        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "{$class} must exist — the mint law enumerates it");
        }
    }

    public function testTheCarveOutStayerIsDeadAndStaysDead(): void
    {
        // (a) Its mint lives ONLY inside the static createToken() helper.
        $tokens = $this->tokens(self::SRC_DIR . '/Server/WebSocket/SyncPlayAuthMiddleware.php');
        $this->assertTrue($this->hasCallTo($tokens, ['createAccessToken']));

        // (b) ZERO call references to that helper exist anywhere in src/ or
        //     tests/ outside the file itself — the review confirmed the
        //     method inert (docblock example only). Wiring it up is allowed
        //     only AFTER it joins the hold guard, which turns check 2 red
        //     here until AUDITED_MINTERS and CARVE_OUTS are consciously
        //     re-ruled. (The definition line is `function createToken` and
        //     the @example lives in a doc comment — both invisible to the
        //     token walk, by design.)
        $referenceRoots = [self::SRC_DIR, self::TESTS_DIR];
        foreach ($referenceRoots as $root) {
            foreach ($this->phpFiles($root) as $abs) {
                if (str_ends_with($abs, '/SyncPlayAuthMiddleware.php')) {
                    continue;
                }
                $this->assertFalse(
                    $this->hasCallTo($this->tokens($abs), ['createToken']),
                    "SyncPlayAuthMiddleware::createToken() was referenced from {$abs} — "
                        . 'the inert carve-out was revived: gate it behind refuseIfHeld() first',
                );
            }
        }
    }

    /**
     * True when the token stream contains NAME as a bare T_STRING preceded
     * (ignoring whitespace/comments) by `->`, `?->`, or `::` — a call or
     * class-qualified reference. A declared function name is preceded by
     * T_FUNCTION, so definitions can never carry an operator and need no
     * special case. Comments, doc comments and quoted strings are separate
     * token types and never match.
     *
     * @param list<array{int|string,string}|string> $tokens
     * @param list<string>                          $names
     */
    private function hasCallTo(array $tokens, array $names): bool
    {
        $operators = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR];
        $count = count($tokens);
        for ($i = 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], $names, true)) {
                continue;
            }
            $prev = $this->previousSignificant($tokens, $i);
            if (is_array($prev) && in_array($prev[0], $operators, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The previous significant (non-whitespace, non-comment) token before
     * $i, or null at stream start.
     *
     * @param list<array{int|string,string}|string> $tokens
     */
    private function previousSignificant(array $tokens, int $i): array|string|null
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * @return \Generator<string>
     */
    private function phpFiles(string $root): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    /**
     * @return list<array{int|string,string}|string>
     */
    private function tokens(string $path): array
    {
        $source = file_get_contents($path);
        $this->assertIsString($source, "unreadable source file {$path}");

        return token_get_all($source);
    }
}
