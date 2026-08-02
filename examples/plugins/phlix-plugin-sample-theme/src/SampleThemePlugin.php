<?php

/**
 * Phlix sample plugin: a ui-theme provider.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\PluginSampleTheme;

use Phlix\Shared\Plugin\LifecycleInterface;
use Phlix\Theming\ThemeSourceInterface;
use Psr\Container\ContainerInterface;

/**
 * Reference implementation of a Phlix **theme plugin** (S86).
 *
 * This is the shortest complete answer to "how do I ship a theme?": one entry
 * class that implements {@see LifecycleInterface} (so the host can enable it)
 * and {@see ThemeSourceInterface} (so the host registers its themes). There is
 * no `onEnable()` body, no container sniffing and no manifest `theme` key —
 * {@see \Phlix\Plugins\PluginLoader::enable()} sees the `instanceof` and calls
 * {@see \Phlix\Theming\ThemeSourceRegistry::register()} itself, which validates
 * every payload before anything is committed.
 *
 * ## What this sample is here to PROVE
 *
 * It ships two themes rather than one, deliberately, because the pair
 * exercises both halves of the `extends` contract that S86 resolved
 * client-side:
 *
 *  - **`sample-dusk` extends a BUILT-IN (`midnight`).** The server does not
 *    know what `midnight`'s 53 token values are — they live in the SPA's
 *    bundled `@phlix/tokens` stylesheet — so no amount of server-side
 *    flattening could resolve this chain. The SPA layers it by pointing
 *    `data-theme` at `midnight` and `setProperty`-ing the overrides on top.
 *    Note what is deliberately NOT overridden: the status colours
 *    (`--error*`, `--success*`, `--warning*`, `--info*`) come straight from the
 *    base through the cascade.
 *  - **`sample-dusk-high-contrast` extends a PLUGIN theme (`sample-dusk`).**
 *    That chain IS resolvable from server data — but only from the LIST
 *    response, which carries every registered theme.
 *    `GET /api/v1/themes/{id}` alone would hand a client eight text/border
 *    tokens and no background.
 *
 * ## Every value here is inside the host's grammar
 *
 * {@see \Phlix\Theming\ThemeTokenValidator} accepts a token value only if the
 * whole string is a hex colour, an `rgb()/rgba()/hsl()/hsla()` over numeric
 * arguments, a bare number, `transparent` or `currentColor`. That is why there
 * is not a single `var(--…)` reference below even though the built-in themes
 * are written that way in CSS: a plugin ships literals. Keys are checked
 * against {@see \Phlix\Theming\ThemeTokenAllowlist} — the 53 semantic colour
 * tokens, and nothing layout-related, so a theme can recolour the UI but never
 * move it.
 *
 * ## Resident-memory contract
 *
 * {@see providedThemes()} returns a literal array. It is called synchronously
 * on the worker thread during plugin enable, so it must not do I/O, must not
 * sleep, and must not memoise into anything that grows.
 *
 * @package Phlix\PluginSampleTheme
 * @since 1.0.0
 */
final class SampleThemePlugin implements LifecycleInterface, ThemeSourceInterface
{
    /**
     * Canonical provenance key for this source.
     *
     * The host keys the registry's provenance map on this, so re-enabling
     * REPLACES this plugin's themes instead of duplicating them, and disabling
     * removes exactly these two ids. Keep it constant across versions.
     */
    public const SOURCE_NAME = 'sample-theme';

    /**
     * Nothing to do — the host registers the themes off the `instanceof`.
     *
     * @param ContainerInterface $container The host container (unused).
     */
    public function onEnable(ContainerInterface $container): void
    {
    }

    /**
     * Nothing to do — the host deregisters this source by name on disable.
     */
    public function onDisable(): void
    {
    }

    /**
     * A theme plugin subscribes to no events.
     *
     * @return array<class-string, string> Always empty.
     */
    public function subscribedEvents(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function themeSourceName(): string
    {
        return self::SOURCE_NAME;
    }

    /**
     * @inheritDoc
     *
     * @return list<array<array-key, mixed>>
     */
    public function providedThemes(): array
    {
        return [
            [
                'id' => 'sample-dusk',
                'name' => 'Sample Dusk',
                'dark' => true,
                // A BUILT-IN base: only the SPA can resolve this one.
                'extends' => 'midnight',
                'tokens' => [
                    // Accent ramp — the six semantic tokens are how a plugin
                    // re-hues the UI. The `--amber-*` ramp they are derived
                    // from in the built-ins is theme-invariant and NOT settable.
                    '--accent' => '#6ea8fe',
                    '--accent-hover' => '#8fbcff',
                    '--accent-active' => '#4d8ce0',
                    '--accent-soft' => 'rgba(110, 168, 254, 0.14)',
                    '--accent-ring' => 'rgba(110, 168, 254, 0.55)',
                    '--accent-text' => '#8fbcff',

                    // Background + elevation stack.
                    '--bg' => '#05060a',
                    '--surface' => '#0b0f18',
                    '--surface-2' => '#131a26',
                    '--surface-3' => '#1c2534',
                    '--surface-glass' => 'rgba(11, 15, 24, 0.62)',
                    '--surface-glass-strong' => 'rgba(5, 6, 10, 0.82)',

                    // Text ramp.
                    '--text' => '#e6ecf5',
                    '--text-muted' => '#a3b0c2',
                    '--text-subtle' => '#75839a',
                    '--text-faint' => '#465061',
                    '--text-on-accent' => '#05060a',

                    // Borders.
                    '--border' => '#1e2735',
                    '--border-subtle' => '#141b26',
                    '--border-strong' => '#2c3849',

                    // Atmosphere. `--grain-opacity` is the one bare number on
                    // the allowlist.
                    '--grain-opacity' => '0.04',
                    '--vignette' => 'rgba(0, 0, 0, 0.6)',
                    '--ambient' => 'rgba(110, 168, 254, 0.22)',

                    // Legacy `--color-*` aliases. Omitting these would leave
                    // un-migrated components on the BASE theme's colours, which
                    // is exactly why they are on the allowlist.
                    '--color-bg' => '#05060a',
                    '--color-surface' => '#0b0f18',
                    '--color-surface-hover' => '#131a26',
                    '--color-surface-elevated' => '#131a26',
                    '--color-surface-active' => '#1c2534',
                    '--color-text' => '#e6ecf5',
                    '--color-text-secondary' => '#a3b0c2',
                    '--color-text-muted' => '#a3b0c2',
                    '--color-text-subtle' => '#75839a',
                    '--color-primary' => '#6ea8fe',
                    '--color-primary-hover' => '#8fbcff',
                    '--color-primary-active' => '#4d8ce0',
                    '--color-border' => '#1e2735',
                    '--color-border-subtle' => '#141b26',
                ],
            ],
            [
                'id' => 'sample-dusk-high-contrast',
                'name' => 'Sample Dusk (High Contrast)',
                'dark' => true,
                // A PLUGIN base: resolvable only from the LIST response.
                'extends' => 'sample-dusk',
                'tokens' => [
                    '--text' => '#ffffff',
                    '--text-muted' => '#d7deea',
                    '--text-subtle' => '#b3bfd1',
                    '--border' => '#55637a',
                    '--border-strong' => '#7d8ba3',
                    '--color-text' => '#ffffff',
                    '--color-text-secondary' => '#d7deea',
                    '--color-border' => '#55637a',
                ],
            ],
        ];
    }
}
