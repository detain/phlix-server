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
 * The three token-map themes the Phlix SPA ships with, as host-owned data.
 *
 * ## Which "built-in" this is
 *
 * S85's acceptance criterion asks the `/api/v1/themes` endpoints to list
 * "built-in + plugin-registered themes **with their token maps**". That phrase
 * settles which of the two candidate theme systems is meant: these three
 * (`nocturne` / `daylight` / `midnight`), whose whole substance IS a token map,
 * and NOT `config/themes.php`'s four `phlix-*` entries, which carry a URL to a
 * stylesheet FILE and have no tokens at all. Three more signals agree:
 * {@see ThemeTokenValidator::RESERVED_IDS} already reserves exactly these three
 * ids against plugin hijacking; {@see ThemeSourceRegistry::resolveTokens()}
 * calls them "the SPA's built-in ids"; and S86's own criterion names
 * "nocturne/daylight/midnight".
 *
 * ## Canonical source, and the drift this accepts
 *
 * Transcribed from the three theme blocks of
 * `@phlix/tokens/src/css/colors.css` — `:root, [data-theme='nocturne']`,
 * `[data-theme='daylight']`, `[data-theme='midnight']` — the same file
 * {@see ThemeTokenAllowlist} is derived from. Each block declares all 53
 * allowlisted properties, so each theme here has exactly 53 tokens. `dark` is
 * read from each block's `color-scheme`.
 *
 * colors.css lives in a **different repository** (`phlix-tokens`). Since S228
 * that no longer means "undiffable": the stylesheet ships into the test
 * checkout as the dev-only, commit-SHA-pinned `detain/phlix-tokens` composer
 * package, and `BuiltInThemesTest` diffs every value below against the PARSED
 * delivered file (`ColorsCssParser` resolves the `var()` chains the same way
 * this file does by hand), with `ColorsCssParityTest` pinning the delivered
 * bytes by sha256. A hex edit on either side reddens CI; re-syncing is
 * deliberate, reviewable and no longer trust-me-bro. The blast radius of
 * staleness stays small by construction: the SPA renders a built-in from its
 * OWN CSS (it sets `data-theme` on `<html>`), never from this endpoint, so a
 * stale value here shows a wrong preview swatch — it cannot render a theme
 * wrongly.
 *
 * ## `var()` chains are resolved, on purpose
 *
 * colors.css writes ~30 of each theme's 53 tokens as references
 * (`--accent: var(--amber-500)`, `--color-bg: var(--bg)`). Those are resolved
 * to literals here (`#f5a524`, `#0b0a08`) for two reasons: `var(...)` is
 * rejected outright by {@see ThemeTokenValidator}'s value grammar, so an
 * unresolved map could not pass the host's own sanitiser; and a literal map is
 * the only form a non-CSS client (Roku, console, Tizen) can use at all.
 *
 * The flattening does lose one CSS behaviour worth naming: in the stylesheet
 * `--color-bg: var(--bg)` FOLLOWS `--bg`, so overriding `--bg` alone also moves
 * the legacy alias. Here the two are independent values. That only matters to a
 * plugin theme that `extends` a built-in and overrides one half of such a pair;
 * such a theme should set both, which is why both halves are on the allowlist.
 *
 * ## Every payload goes through the host sanitiser
 *
 * {@see all()} runs each payload through
 * {@see ThemeTokenValidator::validateBuiltIn()} rather than calling
 * `new TokenTheme(...)` directly. That is deliberate: it means **no theme
 * reaches the HTTP surface without having passed the S84 validator**, plugin or
 * built-in alike, so the endpoint has exactly one trust boundary instead of
 * two. All 159 values below satisfy the grammar today; the check exists so that
 * an edit which broke that fails loudly at the first request instead of
 * shipping a CSS-injection vector under the host's own name.
 *
 * @package Phlix\Theming
 * @since 0.44.0
 */
final class BuiltInThemes
{
    /**
     * Built-in theme ids, in colors.css declaration order — which is also the
     * order {@see all()} returns and the order the list endpoint emits.
     *
     * Identical, by contract, to {@see ThemeTokenValidator::RESERVED_IDS};
     * `BuiltInThemesTest` asserts the two never diverge, because a divergence
     * either leaves a built-in claimable by a plugin or reserves an id nothing
     * ships.
     *
     * @var list<string>
     */
    public const IDS = ['nocturne', 'daylight', 'midnight'];

    /**
     * Memoised result of {@see all()}.
     *
     * Bounded at exactly {@see IDS} entries and derived purely from the
     * compile-time {@see PAYLOADS} constant — it holds no request data and can
     * never grow, so it is safe in a resident-memory Workerman worker. It
     * exists so the 159 grammar checks run once per worker rather than once per
     * request.
     *
     * @var array<string, TokenTheme>|null
     */
    private static ?array $validated = null;

    /**
     * The raw payloads, in {@see ThemeSourceInterface} shape.
     *
     * Tokens are listed in {@see ThemeTokenAllowlist::all()} order.
     *
     * @var array<string, array<string, mixed>>
     */
    private const PAYLOADS = [
        'nocturne' => [
            'id' => 'nocturne',
            'name' => 'Nocturne',
            'dark' => true,
            'extends' => null,
            'tokens' => [
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
        ],
        'daylight' => [
            'id' => 'daylight',
            'name' => 'Daylight',
            'dark' => false,
            'extends' => null,
            'tokens' => [
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
        ],
        'midnight' => [
            'id' => 'midnight',
            'name' => 'Midnight',
            'dark' => true,
            'extends' => null,
            'tokens' => [
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
        ],
    ];

    /**
     * Every built-in theme, keyed by id, in {@see IDS} order.
     *
     * @return array<string, TokenTheme>
     *
     * @throws \Phlix\Theming\Exception\InvalidThemeDefinition If {@see PAYLOADS}
     *         has been edited into something the host sanitiser refuses. That is
     *         a programmer error in this file, never plugin input — see the
     *         class docblock.
     *
     * @since 0.44.0
     */
    public static function all(): array
    {
        if (self::$validated !== null) {
            return self::$validated;
        }

        $themes = [];
        foreach (self::PAYLOADS as $payload) {
            $theme = ThemeTokenValidator::validateBuiltIn($payload);
            $themes[$theme->id] = $theme;
        }

        self::$validated = $themes;

        return $themes;
    }

    /**
     * One built-in theme by id.
     *
     * @param string $id Theme id.
     * @return TokenTheme|null Null when the id is not a built-in.
     *
     * @since 0.44.0
     */
    public static function get(string $id): ?TokenTheme
    {
        return self::all()[$id] ?? null;
    }

    /**
     * Whether an id names a built-in theme.
     *
     * @param string $id Theme id.
     * @return bool
     *
     * @since 0.44.0
     */
    public static function has(string $id): bool
    {
        return isset(self::all()[$id]);
    }

    /**
     * The raw, pre-validation payloads.
     *
     * Exposed so tests can assert the constant's contents directly and can feed
     * a deliberately poisoned copy back through
     * {@see ThemeTokenValidator::validateBuiltIn()}; production code should use
     * {@see all()}, which returns validated {@see TokenTheme} objects.
     *
     * @return array<string, array<string, mixed>>
     *
     * @since 0.44.0
     */
    public static function payloads(): array
    {
        return self::PAYLOADS;
    }
}
