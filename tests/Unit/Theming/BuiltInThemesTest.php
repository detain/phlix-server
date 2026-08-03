<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Theming;

use Phlix\Theming\BuiltInThemes;
use Phlix\Theming\ThemeTokenAllowlist;
use Phlix\Theming\ThemeTokenValidator;
use Phlix\Theming\TokenTheme;
use PHPUnit\Framework\TestCase;

/**
 * Freezes the three host-shipped token-map themes (S85).
 *
 * ## Why the expectation is spelled out in full
 *
 * These 159 values are a transcription of a file in ANOTHER repository —
 * `phlix-tokens/src/css/colors.css` — with its `var()` chains resolved to
 * literals. Nothing in this repository can diff the two automatically, which is
 * exactly the position {@see ThemeTokenAllowlist} is in, so this test takes the
 * same remedy S84 chose for it: enumerate everything, so that changing one
 * colour is a visible, deliberate diff a reviewer can check against colors.css
 * rather than a silent drift.
 *
 * The expectation below was transcribed from colors.css directly, NOT read back
 * out of {@see BuiltInThemes} — asserting the constant against itself would
 * prove nothing.
 *
 * ⚠ **When colors.css changes, update BOTH files.** If this test fails, the
 * question to ask is "did colors.css move?", and the fix is to re-derive
 * `BuiltInThemes::PAYLOADS` from it and update the literal here. The fix is NOT
 * to relax the assertion to a count or a key check.
 */
final class BuiltInThemesTest extends TestCase
{
    /**
     * Every token of every built-in, resolved to literals, in
     * {@see ThemeTokenAllowlist::all()} order.
     *
     * @return array<string, array<string, string>>
     */
    private function expectedTokens(): array
    {
        return [
            'nocturne' => [
                '--accent' => '#f5a524',
                '--accent-hover' => '#fbab36',
                '--accent-active' => '#d98209',
                '--accent-soft' => 'rgba(245, 165, 36, 0.14)',
                '--accent-ring' => 'rgba(245, 165, 36, 0.55)',
                '--accent-text' => '#f5a524',
                '--bg' => '#0b0a08',
                '--surface' => '#16130f',
                '--surface-2' => '#211c16',
                '--surface-3' => '#2c251c',
                '--surface-glass' => 'rgba(28, 23, 17, 0.62)',
                '--surface-glass-strong' => 'rgba(20, 16, 11, 0.82)',
                '--text' => '#f3ece1',
                '--text-muted' => '#b8ab98',
                '--text-subtle' => '#918370',
                '--text-faint' => '#544a3f',
                '--text-on-accent' => '#2a1804',
                '--border' => '#2b2419',
                '--border-subtle' => '#1d1810',
                '--border-strong' => '#3a3122',
                '--error' => '#ef5e57',
                '--error-bg' => 'rgba(239, 94, 87, 0.14)',
                '--success' => '#61c073',
                '--success-bg' => 'rgba(97, 192, 115, 0.14)',
                '--warning' => '#e8a33d',
                '--warning-bg' => 'rgba(232, 163, 61, 0.14)',
                '--info' => '#5aa9d6',
                '--info-bg' => 'rgba(90, 169, 214, 0.14)',
                '--grain-opacity' => '0.05',
                '--vignette' => 'rgba(0, 0, 0, 0.55)',
                '--ambient' => 'rgba(245, 165, 36, 0.22)',
                '--color-bg' => '#0b0a08',
                '--color-surface' => '#16130f',
                '--color-surface-hover' => '#211c16',
                '--color-surface-elevated' => '#211c16',
                '--color-surface-active' => '#2c251c',
                '--color-text' => '#f3ece1',
                '--color-text-secondary' => '#b8ab98',
                '--color-text-muted' => '#b8ab98',
                '--color-text-subtle' => '#918370',
                '--color-primary' => '#f5a524',
                '--color-primary-hover' => '#fbab36',
                '--color-primary-active' => '#d98209',
                '--color-border' => '#2b2419',
                '--color-border-subtle' => '#1d1810',
                '--color-error' => '#ef5e57',
                '--color-error-bg' => 'rgba(239, 94, 87, 0.14)',
                '--color-success' => '#61c073',
                '--color-success-bg' => 'rgba(97, 192, 115, 0.14)',
                '--color-warning' => '#e8a33d',
                '--color-warning-bg' => 'rgba(232, 163, 61, 0.14)',
                '--color-info' => '#5aa9d6',
                '--color-info-bg' => 'rgba(90, 169, 214, 0.14)',
            ],
            'daylight' => [
                '--accent' => '#f5a524',
                '--accent-hover' => '#d98209',
                '--accent-active' => '#b4610a',
                '--accent-soft' => 'rgba(217, 130, 9, 0.12)',
                '--accent-ring' => 'rgba(146, 77, 16, 0.85)',
                '--accent-text' => '#924d10',
                '--bg' => '#f7f1e6',
                '--surface' => '#fffdf8',
                '--surface-2' => '#f1e8d8',
                '--surface-3' => '#e8dcc6',
                '--surface-glass' => 'rgba(255, 253, 248, 0.70)',
                '--surface-glass-strong' => 'rgba(255, 253, 248, 0.90)',
                '--text' => '#2a2017',
                '--text-muted' => '#6b5d49',
                '--text-subtle' => '#746753',
                '--text-faint' => '#b7a98f',
                '--text-on-accent' => '#2a1804',
                '--border' => '#e3d6c0',
                '--border-subtle' => '#efe6d6',
                '--border-strong' => '#d3c2a4',
                '--error' => '#b53832',
                '--error-bg' => 'rgba(195, 60, 54, 0.10)',
                '--success' => '#26743b',
                '--success-bg' => 'rgba(47, 143, 73, 0.10)',
                '--warning' => '#9b5309',
                '--warning-bg' => 'rgba(180, 97, 10, 0.10)',
                '--info' => '#2d6a91',
                '--info-bg' => 'rgba(47, 111, 151, 0.10)',
                '--grain-opacity' => '0.025',
                '--vignette' => 'rgba(74, 55, 20, 0.10)',
                '--ambient' => 'rgba(245, 165, 36, 0.18)',
                '--color-bg' => '#f7f1e6',
                '--color-surface' => '#fffdf8',
                '--color-surface-hover' => '#f1e8d8',
                '--color-surface-elevated' => '#f1e8d8',
                '--color-surface-active' => '#e8dcc6',
                '--color-text' => '#2a2017',
                '--color-text-secondary' => '#6b5d49',
                '--color-text-muted' => '#6b5d49',
                '--color-text-subtle' => '#746753',
                '--color-primary' => '#f5a524',
                '--color-primary-hover' => '#d98209',
                '--color-primary-active' => '#b4610a',
                '--color-border' => '#e3d6c0',
                '--color-border-subtle' => '#efe6d6',
                '--color-error' => '#b53832',
                '--color-error-bg' => 'rgba(195, 60, 54, 0.10)',
                '--color-success' => '#26743b',
                '--color-success-bg' => 'rgba(47, 143, 73, 0.10)',
                '--color-warning' => '#9b5309',
                '--color-warning-bg' => 'rgba(180, 97, 10, 0.10)',
                '--color-info' => '#2d6a91',
                '--color-info-bg' => 'rgba(47, 111, 151, 0.10)',
            ],
            'midnight' => [
                '--accent' => '#f5a524',
                '--accent-hover' => '#fbab36',
                '--accent-active' => '#d98209',
                '--accent-soft' => 'rgba(245, 165, 36, 0.16)',
                '--accent-ring' => 'rgba(245, 165, 36, 0.60)',
                '--accent-text' => '#f5a524',
                '--bg' => '#000000',
                '--surface' => '#0a0807',
                '--surface-2' => '#141009',
                '--surface-3' => '#1d1710',
                '--surface-glass' => 'rgba(10, 8, 6, 0.70)',
                '--surface-glass-strong' => 'rgba(0, 0, 0, 0.85)',
                '--text' => '#ede6da',
                '--text-muted' => '#a99d8c',
                '--text-subtle' => '#897a68',
                '--text-faint' => '#463d33',
                '--text-on-accent' => '#2a1804',
                '--border' => '#211b12',
                '--border-subtle' => '#120e09',
                '--border-strong' => '#322a1c',
                '--error' => '#ef5e57',
                '--error-bg' => 'rgba(239, 94, 87, 0.16)',
                '--success' => '#61c073',
                '--success-bg' => 'rgba(97, 192, 115, 0.16)',
                '--warning' => '#e8a33d',
                '--warning-bg' => 'rgba(232, 163, 61, 0.16)',
                '--info' => '#5aa9d6',
                '--info-bg' => 'rgba(90, 169, 214, 0.16)',
                '--grain-opacity' => '0.06',
                '--vignette' => 'rgba(0, 0, 0, 0.70)',
                '--ambient' => 'rgba(245, 165, 36, 0.24)',
                '--color-bg' => '#000000',
                '--color-surface' => '#0a0807',
                '--color-surface-hover' => '#141009',
                '--color-surface-elevated' => '#141009',
                '--color-surface-active' => '#1d1710',
                '--color-text' => '#ede6da',
                '--color-text-secondary' => '#a99d8c',
                '--color-text-muted' => '#a99d8c',
                '--color-text-subtle' => '#897a68',
                '--color-primary' => '#f5a524',
                '--color-primary-hover' => '#fbab36',
                '--color-primary-active' => '#d98209',
                '--color-border' => '#211b12',
                '--color-border-subtle' => '#120e09',
                '--color-error' => '#ef5e57',
                '--color-error-bg' => 'rgba(239, 94, 87, 0.16)',
                '--color-success' => '#61c073',
                '--color-success-bg' => 'rgba(97, 192, 115, 0.16)',
                '--color-warning' => '#e8a33d',
                '--color-warning-bg' => 'rgba(232, 163, 61, 0.16)',
                '--color-info' => '#5aa9d6',
                '--color-info-bg' => 'rgba(90, 169, 214, 0.16)',
            ],
        ];
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
     * Light/dark flags, taken from each block's `color-scheme` in colors.css.
     */
    public function testDarkFlagsMatchColorsCss(): void
    {
        $themes = BuiltInThemes::all();

        $this->assertTrue($themes['nocturne']->dark, 'colors.css: nocturne declares color-scheme: dark');
        $this->assertFalse($themes['daylight']->dark, 'colors.css: daylight declares color-scheme: light');
        $this->assertTrue($themes['midnight']->dark, 'colors.css: midnight declares color-scheme: dark');
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
     * THE freeze: every token of every built-in, value for value.
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
                "Built-in theme \"{$id}\" no longer matches the colors.css transcription. "
                . 'Re-derive it from @phlix/tokens/src/css/colors.css; do not relax this assertion.',
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
