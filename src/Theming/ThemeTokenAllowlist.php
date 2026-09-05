<?php

/**
 * Phlix media server component: Theming.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Theming;

/**
 * The closed set of CSS custom properties a plugin theme may set.
 *
 * ## Canonical source
 *
 * This list is transcribed from the **theme blocks** of
 * `@phlix/tokens/src/css/colors.css` (the `phlix-tokens` package; the same
 * file ships to the SPA as `@phlix/tokens/dist/css/colors.css`). A "theme
 * block" is one of the three `[data-theme=...]` rules — `nocturne` (which
 * doubles as the `:root` default), `daylight` and `midnight`. Every custom
 * property those three blocks declare is here; nothing else is.
 *
 * Since S228 the transcription is not folklore: the stylesheet ships into the
 * test checkout as the dev-only, commit-pinned `detain/phlix-tokens` composer
 * package, and `ThemeTokenAllowlistTest` / `ColorsCssParityTest` diff this
 * list against the PARSED delivered file — name set, order and per-block
 * consistency included — on every CI run.
 *
 * ## What is deliberately NOT here
 *
 *  - **The `--amber-*` accent ramp and `--accent-contrast`.** They live in the
 *    standalone `:root` block that colors.css labels *"Theme-invariant (amber
 *    identity is constant)"*. A plugin re-hues the UI through the six semantic
 *    `--accent*` tokens instead, so the ramp stays a fixed reference.
 *  - **`color-scheme`.** It is a real CSS property, not a custom property; the
 *    light/dark signal travels on {@see TokenTheme::$dark}.
 *  - **Layout tokens** (density / spacing / radius / shadow / motion). They
 *    live in other token files and are explicitly out of scope so a plugin
 *    theme cannot break layout — only colour it.
 *
 * ## Why an allowlist and not a denylist
 *
 * The key check is exact membership in this array (`in_array(..., true)`), so
 * an unknown, mis-cased, whitespace-padded or otherwise smuggled property name
 * is rejected by *not matching* rather than by being recognised as hostile.
 * The value side is the same shape — see {@see ThemeTokenValidator}.
 *
 * @package Phlix\Theming
 * @since 0.44.0
 */
final class ThemeTokenAllowlist
{
    /**
     * Accent ramp expressed semantically. The six tokens a plugin uses to
     * change the UI's accent hue without touching the invariant `--amber-*`
     * ramp they are derived from in the built-in themes.
     *
     * @var list<string>
     */
    public const ACCENT = [
        '--accent',
        '--accent-hover',
        '--accent-active',
        '--accent-soft',
        '--accent-ring',
        '--accent-text',
    ];

    /**
     * Page background and the elevation stack of surfaces above it,
     * including the two translucent "glass" layers.
     *
     * @var list<string>
     */
    public const SURFACE = [
        '--bg',
        '--surface',
        '--surface-2',
        '--surface-3',
        '--surface-glass',
        '--surface-glass-strong',
    ];

    /**
     * Foreground text ramp, strongest to faintest, plus the ink used on top
     * of an accent fill.
     *
     * @var list<string>
     */
    public const TEXT = [
        '--text',
        '--text-muted',
        '--text-subtle',
        '--text-faint',
        '--text-on-accent',
    ];

    /**
     * Divider / outline ramp.
     *
     * @var list<string>
     */
    public const BORDER = [
        '--border',
        '--border-subtle',
        '--border-strong',
    ];

    /**
     * Status colours and their tinted backgrounds.
     *
     * @var list<string>
     */
    public const STATUS = [
        '--error',
        '--error-bg',
        '--success',
        '--success-bg',
        '--warning',
        '--warning-bg',
        '--info',
        '--info-bg',
    ];

    /**
     * Atmosphere hooks. `--grain-opacity` is a bare number (0..1); the other
     * two are colours.
     *
     * @var list<string>
     */
    public const ATMOSPHERE = [
        '--grain-opacity',
        '--vignette',
        '--ambient',
    ];

    /**
     * Legacy `--color-*` aliases. The "un-migrated components" justification
     * S84 wrote here no longer holds, and this replaces it with a measured
     * census (2026-09-05, each repo at its origin/master tip):
     *
     *  - READERS. Of the 13 tokens S228 named dead here, 12 have ZERO
     *    `var()` consumers in phlix-server `src/` and `web-ui/` (incl. the
     *    bundled `web-ui/node_modules/@phlix/` copies), phlix-ui,
     *    phlix-windows-client, phlix-tizen-client, phlix-mobile-client,
     *    phlix-roku-client, phlix-console-client and phlix-hub. The 13th,
     *    `--color-primary`, has exactly one reader: the dev-only preview page
     *    `phlix-ui/src/dev/swatches.html`. (phlix-website reads `--color-*`
     *    names heavily but declares its OWN `:root` palettes per marketing
     *    site — a disjoint namespace, not a consumer of this surface.)
     *
     *  - WHY THEY STAY ANYWAY. Two measured, mechanical reasons — this is a
     *    deprecation cycle, not a server-side edit:
     *      1. `detain/phlix-tokens` `src/css/colors.css` still declares every
     *         one inside all three theme blocks — {@see
     *         \Phlix\Tests\Unit\Theming\ColorsCssParityTest} asserts the
     *         stylesheet's declared set IS this allowlist at the vendored pin,
     *         so dropping an entry here reddens that guard until tokens
     *         catches up.
     *      2. The published `detain/phlix-plugin-sample-theme` sets all 13
     *         today, and {@see ThemeSourceRegistry::register()} validates
     *         every plugin payload against exactly this list — removal would
     *         reject shipped themes. It would also change the built-in token
     *         maps in `GET /api/v1/themes`, whose wire body is md5-pinned
     *         byte-for-byte against a copy in phlix-ui
     *         (`tests/Unit/Plugins/SampleThemePluginTest::GOLDEN_MD5`).
     *      3. phlix-ui mirrors the full set in its own `THEME_TOKEN_ALLOWLIST`
     *         (`src/composables/themeTokens.ts`); the two advertised surfaces
     *         move together or not at all.
     *
     * @var list<string>
     */
    public const LEGACY_ALIAS = [
        '--color-bg',
        '--color-surface',
        '--color-surface-hover',
        '--color-surface-elevated',
        '--color-surface-active',
        '--color-text',
        '--color-text-secondary',
        '--color-text-muted',
        '--color-text-subtle',
        '--color-primary',
        '--color-primary-hover',
        '--color-primary-active',
        '--color-border',
        '--color-border-subtle',
        '--color-error',
        '--color-error-bg',
        '--color-success',
        '--color-success-bg',
        '--color-warning',
        '--color-warning-bg',
        '--color-info',
        '--color-info-bg',
    ];

    /**
     * Every allowed token name, in colors.css declaration order.
     *
     * @return list<string> The 53 settable semantic tokens.
     *
     * @since 0.44.0
     */
    public static function all(): array
    {
        return array_merge(
            self::ACCENT,
            self::SURFACE,
            self::TEXT,
            self::BORDER,
            self::STATUS,
            self::ATMOSPHERE,
            self::LEGACY_ALIAS,
        );
    }

    /**
     * Whether a token name is settable by a plugin theme.
     *
     * Exact, case-sensitive membership: CSS custom properties are
     * case-sensitive, so `--BG` is a different property from `--bg` and must
     * NOT be folded into it.
     *
     * @param string $token Candidate custom-property name, e.g. `--bg`.
     * @return bool True when the token is on the allowlist.
     *
     * @since 0.44.0
     */
    public static function allows(string $token): bool
    {
        return in_array($token, self::all(), true);
    }
}
