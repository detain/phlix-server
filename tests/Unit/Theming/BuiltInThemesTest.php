<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Theming;

use Phlix\Tests\Support\Theming\ColorsCssParser;
use Phlix\Theming\BuiltInThemes;
use Phlix\Theming\ThemeTokenAllowlist;
use Phlix\Theming\ThemeTokenValidator;
use Phlix\Theming\TokenTheme;
use PHPUnit\Framework\TestCase;

/**
 * Freezes the three host-shipped token-map themes (S85), against the REAL
 * stylesheet (S228).
 *
 * ## What the expectation is made of
 *
 * These values are a transcription of a file in ANOTHER repository —
 * `phlix-tokens/src/css/colors.css` — with its `var()` chains resolved to
 * literals. Since S228 that file ships into the test checkout as the
 * dev-only, commit-pinned composer package `detain/phlix-tokens` (see the
 * `repositories` block in composer.json and the provenance assertions in
 * {@see ColorsCssParityTest}), and {@see self::expectedTokens()} parses THAT
 * delivered artifact — resolving its own `var()` chains — rather than
 * restating a second hand transcription. The comparison is therefore
 * transcription ⇄ real artefact, value for value; a hex moved on either side
 * reddens a test in CI.
 *
 * The expectation is still NOT read back out of {@see BuiltInThemes} —
 * asserting the constant against itself would prove nothing.
 *
 * ⚠ **When colors.css moves upstream, roll the pin and re-derive PAYLOADS in
 * the same change.** If this test fails, the question to ask is "did the
 * vendored colors.css move?" (`git diff composer.lock`, or just read the file
 * at vendor/detain/phlix-tokens/src/css/colors.css), and the fix is to re-derive
 * `BuiltInThemes::PAYLOADS` from it. The fix is NOT to relax the assertion to a
 * count or a key check.
 */
final class BuiltInThemesTest extends TestCase
{
    /**
     * Every token of every built-in, resolved to literals by parsing the
     * DELIVERED colors.css — the artifact, not this file's idea of it.
     *
     * @return array<string, array<string, string>>
     */
    private function expectedTokens(): array
    {
        $colors = ColorsCssParser::vendored();

        $expected = [];
        foreach (ColorsCssParser::THEME_IDS as $id) {
            $expected[$id] = $colors->resolvedTokens($id);
        }

        return $expected;
    }

    /**
     * The three ids, in colors.css declaration order, and nothing else.
     */
    public function testTheBuiltInIdsAreTheThreeSpaThemesInDeclarationOrder(): void
    {
        $this->assertSame(['nocturne', 'daylight', 'midnight'], BuiltInThemes::IDS);
        $this->assertSame(['nocturne', 'daylight', 'midnight'], array_keys(BuiltInThemes::all()));
    }

    /**
     * `BuiltInThemes::IDS` and `ThemeTokenValidator::RESERVED_IDS` are two
     * spellings of one fact and must never drift.
     *
     * A built-in missing from RESERVED_IDS becomes claimable by a plugin, which
     * would let a plugin silently replace a shipped theme for every user. A
     * reserved id with no built-in behind it reserves a name nothing ships.
     */
    public function testTheReservedIdSetIsExactlyTheBuiltInIdSet(): void
    {
        $this->assertSame(
            ThemeTokenValidator::RESERVED_IDS,
            BuiltInThemes::IDS,
            'RESERVED_IDS and BuiltInThemes::IDS must stay identical — see the docblocks on both.',
        );
    }

    /**
     * Light/dark flags, diffed against each block's `color-scheme` in the
     * DELIVERED colors.css (S228) — the same parse {@see
     * ColorsCssParityTest::testColorSchemeFlagsComeFromTheParsedStylesheet()}
     * pins to dark/light/dark at the vendor pin.
     */
    public function testDarkFlagsMatchColorsCss(): void
    {
        $colors = ColorsCssParser::vendored();
        $themes = BuiltInThemes::all();

        foreach (ColorsCssParser::THEME_IDS as $id) {
            $scheme = $colors->colorScheme($id);
            $this->assertContains(
                $scheme,
                ['dark', 'light'],
                "colors.css: theme {$id} declares an unexpected color-scheme: {$scheme}"
            );
            $this->assertSame(
                $scheme === 'dark',
                $themes[$id]->dark,
                "built-in {$id}: the dark flag no longer follows the block's color-scheme in colors.css"
            );
        }
    }

    /**
     * Human-readable labels, and the fact that no built-in extends anything or
     * carries a plugin provenance.
     */
    public function testNamesAndProvenance(): void
    {
        $themes = BuiltInThemes::all();

        $this->assertSame('Nocturne', $themes['nocturne']->name);
        $this->assertSame('Daylight', $themes['daylight']->name);
        $this->assertSame('Midnight', $themes['midnight']->name);

        foreach ($themes as $id => $theme) {
            $this->assertInstanceOf(TokenTheme::class, $theme);
            $this->assertSame($id, $theme->id);
            $this->assertNull($theme->extends, "built-in {$id} must be standalone");
            $this->assertNull($theme->sourceName, "built-in {$id} must have no plugin provenance");
        }
    }

    /**
     * THE freeze: every token of every built-in, value for value, against the
     * vendored colors.css parsed by {@see self::expectedTokens()} — expected
     * order (allowlist order, which {@see
     * ColorsCssParityTest::testTheAllowlistEqualsTheTokensColorsCssDeclaresInOrder()}
     * pins to the stylesheet's own declaration order) included, since the maps
     * are compared with assertSame on key order.
     */
    public function testEveryBuiltInTokenMapMatchesColorsCssExactly(): void
    {
        $themes = BuiltInThemes::all();
        $expected = $this->expectedTokens();

        foreach ($expected as $id => $tokens) {
            $this->assertArrayHasKey($id, $themes);
            $this->assertSame(
                $tokens,
                $themes[$id]->tokens,
                "Built-in theme \"{$id}\" no longer matches the vendored colors.css "
                . '(vendor/detain/phlix-tokens/src/css/colors.css, pinned in composer.json). '
                . 'Re-derive BuiltInThemes::PAYLOADS from it, or roll the pin back — do not relax this assertion.',
            );
        }
    }

    /**
     * Each theme block in colors.css declares all 53 allowlisted properties, so
     * each built-in sets all 53 — a theme that set only some would leave the
     * rest on the previously-applied theme's values.
     */
    public function testEveryBuiltInSetsTheCompleteAllowlist(): void
    {
        $allowlist = ThemeTokenAllowlist::all();
        $this->assertCount(53, $allowlist);

        foreach (BuiltInThemes::all() as $id => $theme) {
            $this->assertSame(
                $allowlist,
                array_keys($theme->tokens),
                "Built-in \"{$id}\" must set every allowlisted token, in allowlist order.",
            );
        }
    }

    /**
     * SECURITY: nothing host-shipped bypasses the S84 sanitiser.
     *
     * The brief for S85 asked specifically whether anything could reach the
     * endpoint output WITHOUT passing {@see ThemeTokenValidator}. The built-ins
     * were that candidate — they are host data, not plugin input. This asserts
     * the property directly at the data, so the answer stays "no" even if the
     * call inside {@see BuiltInThemes::all()} were removed.
     */
    public function testEveryBuiltInTokenIsAllowlistedAndGrammarSafe(): void
    {
        foreach (BuiltInThemes::payloads() as $id => $payload) {
            $this->assertIsArray($payload['tokens']);
            foreach ($payload['tokens'] as $key => $value) {
                $this->assertIsString($key);
                $this->assertIsString($value);
                $this->assertTrue(
                    ThemeTokenAllowlist::allows($key),
                    "Built-in \"{$id}\" sets non-allowlisted token \"{$key}\".",
                );
                $this->assertTrue(
                    ThemeTokenValidator::isSafeValue($value),
                    "Built-in \"{$id}\" token \"{$key}\" has a value the host grammar refuses: {$value}",
                );
            }
        }
    }

    /**
     * The `var()` resolution is not cosmetic: an unresolved reference would be
     * refused by the host's own grammar, so a built-in carrying one could never
     * be served at all.
     */
    public function testNoBuiltInValueIsAnUnresolvedVarReference(): void
    {
        foreach (BuiltInThemes::payloads() as $id => $payload) {
            $this->assertIsArray($payload['tokens']);
            foreach ($payload['tokens'] as $key => $value) {
                $this->assertIsString($value);
                $this->assertStringNotContainsString(
                    'var(',
                    $value,
                    "Built-in \"{$id}\" token \"{$key}\" still references another token; resolve it to a literal.",
                );
            }
        }
    }

    /**
     * The call site inside `all()`, asserted structurally.
     *
     * ⚠ Read the reason, not just the assertion. `PAYLOADS` is a compile-time
     * constant that passes the grammar, so removing the
     * `ThemeTokenValidator::validateBuiltIn()` call and constructing
     * {@see TokenTheme} directly produces byte-identical output for the data
     * that ships — no data-driven test can kill that mutation. What the call
     * buys is the FUTURE case: an edit to `PAYLOADS` that introduces a hostile
     * value fails at the first request instead of being served under the host's
     * own name. That guarantee lives in the call site, so the call site is what
     * is pinned here.
     *
     * The property this protects is separately covered by
     * {@see testEveryBuiltInTokenIsAllowlistedAndGrammarSafe()}, which catches a
     * bad edit earlier and louder.
     */
    public function testAllRunsEveryPayloadThroughTheHostSanitiser(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Theming/BuiltInThemes.php');
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression(
            '/ThemeTokenValidator::validateBuiltIn\(\s*\$payload\s*\)/',
            $source,
            'BuiltInThemes::all() must build each theme through ThemeTokenValidator::validateBuiltIn(), '
            . 'not with `new TokenTheme(...)` — that is what keeps ONE trust boundary in front of '
            . 'GET /api/v1/themes for host and plugin themes alike.',
        );
    }

    /**
     * `get()` / `has()` answer for built-ins and only for built-ins.
     */
    public function testGetAndHasAnswerForBuiltInsOnly(): void
    {
        foreach (BuiltInThemes::IDS as $id) {
            $this->assertTrue(BuiltInThemes::has($id));
            $this->assertInstanceOf(TokenTheme::class, BuiltInThemes::get($id));
        }

        $this->assertFalse(BuiltInThemes::has('acme-noir'));
        $this->assertNull(BuiltInThemes::get('acme-noir'));
        $this->assertFalse(BuiltInThemes::has(''));
        $this->assertFalse(BuiltInThemes::has('NOCTURNE'), 'ids are case-sensitive');
    }

    /**
     * The memo is bounded and stable: repeated calls return the SAME objects
     * (so it really is memoised) and never a growing map (so it cannot leak in
     * a resident-memory worker).
     */
    public function testAllIsMemoisedAndBounded(): void
    {
        $first = BuiltInThemes::all();
        $second = BuiltInThemes::all();

        $this->assertCount(3, $first);
        $this->assertCount(3, $second);
        foreach ($first as $id => $theme) {
            $this->assertSame($theme, $second[$id], "memoised built-in {$id} must be the same instance");
        }
    }
}
