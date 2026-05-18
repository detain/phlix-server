<?php

declare(strict_types=1);

namespace Phlex\Theming;

/**
 * Readonly theme descriptor representing a single UI theme.
 *
 * A theme contains metadata about its appearance and the resources
 * it provides (CSS and optional JS). Themes can be either built-in
 * (shipped with Phlex) or provided by ui-theme plugins.
 *
 * @package Phlex\Theming
 * @since 0.14.0
 */
class Theme
{
    /**
     * Creates a new Theme instance.
     *
     * @param string $id Unique theme identifier (e.g., 'phlex-dark')
     * @param string $name Human-readable theme name (e.g., 'Phlex Dark')
     * @param string $type Theme source type: 'builtin' or 'ui-theme-plugin'
     * @param string $cssUrl Absolute URL or /assets path to the theme's CSS file
     * @param string|null $jsUrl Optional URL to a JS bundle to load with this theme
     * @param string|null $thumbnailUrl Optional URL to a preview thumbnail image
     * @param string $version Theme version string (e.g., '1.0.0')
     * @param string|null $pluginName Name of the plugin providing this theme (null for built-in)
     * @param bool $dark Whether this theme is a dark theme (UI hint for client)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly string $cssUrl,
        public readonly ?string $jsUrl,
        public readonly ?string $thumbnailUrl,
        public readonly string $version,
        public readonly ?string $pluginName,
        public readonly bool $dark,
    ) {
    }
}
