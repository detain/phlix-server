<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Theming;

use Phlix\Tests\Support\Theming\ColorsCssParser;
use Phlix\Theming\ThemeTokenAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * S228 clause (a): the mechanical cross-repo guard.
 *
 * `@phlix/tokens/src/css/colors.css` is the canonical source of BOTH the
 * built-in theme transcription ({@see \Phlix\Theming\BuiltInThemes}) and the
 * plugin-settable token surface ({@see \Phlix\Theming\ThemeTokenAllowlist}).
 * Until S228 nothing in CI could read that stylesheet — it lives in another
 * repository — so both sides were pinned against hand transcriptions and a hex
 * edit to colors.css was invisible estate-wide.
 *
 * This test closes the gap the venue itself can close: the stylesheet ships
 * into the test checkout as the dev-only, commit-pinned composer package
 * `detain/phlix-tokens` (see the `repositories` block in composer.json), and
 * every check here parses THAT FILE. The chain of custody is
 *
 *   colors.css @ 5500d70b (tag v0.2.0, phlix-tokens)
 *     -> codeload tarball over that immutable commit SHA, fetched by
 *        `composer install` and recorded in composer.lock
 *     -> vendor/detain/phlix-tokens/src/css/colors.css
 *     -> sha256 asserted equal to {@see self::COLORS_CSS_SHA256} right here
 *     -> parsed and diffed, name-for-name and value-for-value, against the
 *        server's transcription.
 *
 * Mutating one hex on EITHER side reddens a test: the transcription side via
 * {@see BuiltInThemesTest::testEveryBuiltInTokenMapMatchesColorsCssExactly()},
 * the stylesheet side by editing the vendor copy in a checkout (or, upstream,
 * by rolling the pin forward without re-transcribing). Nothing here skips
 * when the package is absent — absence is exactly a red, and this suite's
 * S249 lesson forbids `runIf(exists(...))`-style vacuity.
 */
final class ColorsCssParityTest extends TestCase
{
    /** phlix-tokens commit the vendored tarball is fetched at (immutable pin). */
    private const TOKENS_COMMIT = '5500d70b142d46ed8d382087ea542b1047cc803f';

    /** The tag that names {@see TOKENS_COMMIT}; recorded for the human reader. */
    private const TOKENS_TAG = 'v0.2.0';

    /**
     * sha256 of src/css/colors.css AT {@see TOKENS_COMMIT}.
     *
     * Measured from the delivered bytes, and identical to
     * `sha256sum https://raw.githubusercontent.com/detain/phlix-tokens/`{@see
     * TOKENS_COMMIT}`/src/css/colors.css`. A deliberate pin bump updates
     * composer.json + composer.lock + this constant together, and the parity
     * tests then say, in the same run, whether the transcription followed.
     */
    private const COLORS_CSS_SHA256 = '495d7b481a6774313e73fd0a143c1a02b12f70a7a66c2295cf3ea37d82beb8a0';

    /** The vendored canonical stylesheet, relative to the repository root. */
    private const COLORS_CSS = __DIR__ . '/../../../vendor/detain/phlix-tokens/src/css/colors.css';

    /**
     * The artifact under test exists and is the exact pinned bytes — the
     * provenance half of the guard. A wrong-sha vendor copy (hand edit, torn
     * fetch, silently rolled pin) stops every downstream claim dead here.
     */
    public function testTheVendoredColorsCssIsThePinnedBytes(): void
    {
        self::assertFileExists(
            self::COLORS_CSS,
            'detain/phlix-tokens did not install. The S228 guard must read the REAL colors.css: the pin is '
            . self::TOKENS_COMMIT . ' (' . self::TOKENS_TAG . ') in composer.json/composer.lock. '
            . 'Run `composer install`; if that fails, fix the pin — never skip this test.'
        );

        $sha = hash_file('sha256', self::COLORS_CSS);

        self::assertSame(
            self::COLORS_CSS_SHA256,
            $sha,
            'vendor/detain/phlix-tokens/src/css/colors.css does not match the bytes pinned at commit '
            . self::TOKENS_COMMIT . ' (got ' . var_export($sha, true) . '). '
            . 'Bump the pin deliberately (composer.json repository dist URL + composer.lock + this constant) '
            . 'or restore the install — do not edit the vendor copy, and do not relax this assertion.'
        );
    }

    /**
     * The parse itself is non-vacuous: exactly the three theme blocks exist,
     * each declares a non-empty ordered token map, and each resolves to zero
     * remaining `var(` occurrences. Without this, a parser that matched
     * nothing could "diff" nothing and stay green.
     */
    public function testTheParsedStylesheetHasTheShapeTheParityChecksRequire(): void
    {
        $colors = ColorsCssParser::fromFile(self::COLORS_CSS);

        self::assertSame(
            ColorsCssParser::THEME_IDS,
            $colors->themeIds(),
            'colors.css must define exactly the three theme blocks the server transcribes, in declaration order.'
        );

        foreach (ColorsCssParser::THEME_IDS as $id) {
            $tokens = $colors->resolvedTokens($id);

            self::assertNotEmpty($tokens, "theme {$id} parsed to an empty token map — the parse is vacuous.");
            foreach ($tokens as $token => $value) {
                self::assertStringNotContainsString(
                    'var(',
                    $value,
                    "theme {$id} token {$token} did not resolve to a literal: {$value}"
                );
            }
        }
    }

    /**
     * The allowlist is exactly the union of what the three theme blocks
     * declare — same names, same order, all three blocks identical. The
     * stylesheet grew or renamed a token? This reddens before anything else.
     */
    public function testTheAllowlistEqualsTheTokensColorsCssDeclaresInOrder(): void
    {
        $colors = ColorsCssParser::fromFile(self::COLORS_CSS);

        $declared = [];
        foreach (ColorsCssParser::THEME_IDS as $id) {
            $declared[$id] = $colors->tokenOrder($id);
            self::assertSame(
                $declared['nocturne'],
                $declared[$id],
                "theme block {$id} declares a different token set (in a different order) than nocturne — "
                . 'the allowlist can only transcribe one shared list if the stylesheet keeps all three in step.'
            );
        }

        self::assertSame(
            $declared['nocturne'],
            ThemeTokenAllowlist::all(),
            'The plugin-settable allowlist no longer matches what colors.css declares inside its theme blocks. '
            . 'If colors.css moved, roll the detain/phlix-tokens pin forward and update '
            . 'ThemeTokenAllowlist in the same change — do not relax this assertion.'
        );

        self::assertCount(53, $declared['nocturne'], 'the census measured 53 declarations per theme block at the pin');
    }

    /**
     * `color-scheme` is a real CSS property, not a token — and it is the
     * provenance of every built-in's `dark` flag. Diffed against the parsed
     * file, not folklore.
     */
    public function testColorSchemeFlagsComeFromTheParsedStylesheet(): void
    {
        $colors = ColorsCssParser::fromFile(self::COLORS_CSS);

        self::assertSame('dark', $colors->colorScheme('nocturne'));
        self::assertSame('light', $colors->colorScheme('daylight'));
        self::assertSame('dark', $colors->colorScheme('midnight'));
    }
}
