<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Theming;

use Phlix\Tests\Support\Theming\ColorsCssParser;
use Phlix\Theming\ThemeTokenAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * Pins the S84 token allowlist to the theme blocks of
 * `@phlix/tokens/src/css/colors.css`.
 *
 * Since S228 the canonical stylesheet ships into the test checkout as the
 * dev-only, commit-pinned `detain/phlix-tokens` composer package, and the
 * expected list below is PARSED from that delivered file (byte-pinned by
 * {@see ColorsCssParityTest}) — not restated from a transcription. A rename,
 * insertion or removal inside a colors.css theme block reddens this test on
 * CI; so does editing this list without moving the stylesheet. What the parse
 * cannot decide for you is the POLICY of which properties a plugin may set —
 * the group partition and well-formedness checks below keep that deliberate.
 */
final class ThemeTokenAllowlistTest extends TestCase
{
    /**
     * Every custom property declared inside `:root, [data-theme='nocturne']`,
     * `[data-theme='daylight']` and `[data-theme='midnight']` in colors.css,
     * parsed from the vendored artifact (S228).
     *
     * @return list<string>
     */
    private function expected(): array
    {
        $colors = ColorsCssParser::vendored();

        return $colors->tokenOrder('nocturne');
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
