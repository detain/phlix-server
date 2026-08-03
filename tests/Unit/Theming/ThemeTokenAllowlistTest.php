<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Theming;

use Phlix\Theming\ThemeTokenAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * Pins the S84 token allowlist to the theme blocks of
 * `@phlix/tokens/src/css/colors.css`.
 *
 * ⚠ The canonical source lives in a DIFFERENT repository (`phlix-tokens`), so
 * nothing in this repo can diff the two automatically — re-syncing after a
 * colors.css change is a manual step. What this test can do, and does, is make
 * every edit to the list DELIBERATE: the expected set is spelled out in full
 * below, so adding, removing or renaming a token here fails until the
 * expectation is updated too, and the diff shows a reviewer exactly which
 * property became settable by a plugin.
 *
 */
final class ThemeTokenAllowlistTest extends TestCase
{
    /**
     * Every custom property declared inside `:root, [data-theme='nocturne']`,
     * `[data-theme='daylight']` and `[data-theme='midnight']` in colors.css.
     *
     * @return list<string>
     */
    private function expected(): array
    {
        return [
            // Accent (semantic; the --amber-* ramp behind it is invariant).
            '--accent', '--accent-hover', '--accent-active',
            '--accent-soft', '--accent-ring', '--accent-text',
            // Background + surface elevation stack.
            '--bg', '--surface', '--surface-2', '--surface-3',
            '--surface-glass', '--surface-glass-strong',
            // Text ramp.
            '--text', '--text-muted', '--text-subtle', '--text-faint', '--text-on-accent',
            // Borders.
            '--border', '--border-subtle', '--border-strong',
            // Status colours + tinted backgrounds.
            '--error', '--error-bg', '--success', '--success-bg',
            '--warning', '--warning-bg', '--info', '--info-bg',
            // Atmosphere hooks.
            '--grain-opacity', '--vignette', '--ambient',
            // Legacy --color-* aliases, re-declared per theme block.
            '--color-bg', '--color-surface', '--color-surface-hover',
            '--color-surface-elevated', '--color-surface-active',
            '--color-text', '--color-text-secondary', '--color-text-muted', '--color-text-subtle',
            '--color-primary', '--color-primary-hover', '--color-primary-active',
            '--color-border', '--color-border-subtle',
            '--color-error', '--color-error-bg',
            '--color-success', '--color-success-bg',
            '--color-warning', '--color-warning-bg',
            '--color-info', '--color-info-bg',
        ];
    }

    public function testTheAllowlistIsExactlyTheColorsCssThemeBlockTokens(): void
    {
        $this->assertSame($this->expected(), ThemeTokenAllowlist::all());
    }

    public function testTheAllowlistHasFiftyThreeEntriesAndNoDuplicates(): void
    {
        $all = ThemeTokenAllowlist::all();

        $this->assertCount(53, $all);
        $this->assertSame($all, array_values(array_unique($all)), 'a duplicated token would double-count');
    }

    public function testTheGroupsPartitionTheAllowlistWithoutOverlap(): void
    {
        $groups = [
            ThemeTokenAllowlist::ACCENT,
            ThemeTokenAllowlist::SURFACE,
            ThemeTokenAllowlist::TEXT,
            ThemeTokenAllowlist::BORDER,
            ThemeTokenAllowlist::STATUS,
            ThemeTokenAllowlist::ATMOSPHERE,
            ThemeTokenAllowlist::LEGACY_ALIAS,
        ];

        $flat = array_merge(...$groups);

        $this->assertSame(count($flat), count(array_unique($flat)), 'the groups must not overlap');
        $this->assertSame(ThemeTokenAllowlist::all(), $flat);
    }

    public function testEveryEntryIsAWellFormedLowercaseCustomProperty(): void
    {
        foreach (ThemeTokenAllowlist::all() as $token) {
            $this->assertMatchesRegularExpression('/^--[a-z0-9]+(?:-[a-z0-9]+)*$/', $token);
        }
    }

    /**
     * The deliberate exclusions, asserted so a future "just add it" cannot slip
     * in unnoticed: the theme-invariant amber ramp, layout tokens (which live
     * in other token files and are out of scope so a plugin cannot break
     * layout), and `color-scheme` (a real CSS property, carried by
     * `TokenTheme::$dark` instead).
     */
    public function testTheDeliberateExclusionsAreStillExcluded(): void
    {
        $excluded = [
            '--amber-50', '--amber-500', '--amber-950', '--accent-contrast',
            '--space-4', '--radius-md', '--shadow-1', '--font-size-body', '--duration-fast',
            'color-scheme', 'background-image', 'background',
        ];

        foreach ($excluded as $token) {
            $this->assertFalse(ThemeTokenAllowlist::allows($token), $token . ' must not be settable');
        }
    }

    public function testMembershipIsCaseSensitive(): void
    {
        $this->assertTrue(ThemeTokenAllowlist::allows('--bg'));
        $this->assertFalse(ThemeTokenAllowlist::allows('--BG'));
        $this->assertFalse(ThemeTokenAllowlist::allows('--Bg'));
    }
}
