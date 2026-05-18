<?php

declare(strict_types=1);

/**
 * Theme configuration for built-in and user-overridden themes.
 *
 * This file defines the four built-in Phlex themes that ship with the
 * server. Plugin-provided themes are merged at runtime via ThemeRegistry.
 *
 * @package Phlex\Theming
 * @since 0.14.0
 */

return [
    /**
     * Built-in themes shipped with Phlex.
     *
     * Each theme must have:
     * - id: Unique identifier (e.g., 'phlex-dark')
     * - name: Human-readable name
     * - css: URL or /assets path to the theme's CSS file
     * - js: Optional URL to a JS bundle (null if not needed)
     * - thumb: URL or /assets path to a preview thumbnail
     * - dark: Boolean indicating if this is a dark theme
     */
    'builtin' => [
        'dark' => [
            'id' => 'phlex-dark',
            'name' => 'Phlex Dark',
            'css' => '/assets/css/themes/phlex-dark.css',
            'js' => null,
            'thumb' => '/assets/images/themes/phlex-dark.png',
            'dark' => true,
        ],
        'light' => [
            'id' => 'phlex-light',
            'name' => 'Phlex Light',
            'css' => '/assets/css/themes/phlex-light.css',
            'js' => null,
            'thumb' => '/assets/images/themes/phlex-light.png',
            'dark' => false,
        ],
        'amoled' => [
            'id' => 'phlex-amoled',
            'name' => 'Phlex AMOLED',
            'css' => '/assets/css/themes/phlex-amoled.css',
            'js' => null,
            'thumb' => '/assets/images/themes/phlex-amoled.png',
            'dark' => true,
        ],
        'contrast' => [
            'id' => 'phlex-contrast',
            'name' => 'Phlex High Contrast',
            'css' => '/assets/css/themes/phlex-contrast.css',
            'js' => null,
            'thumb' => '/assets/images/themes/phlex-contrast.png',
            'dark' => false,
        ],
    ],

    /**
     * User overrides for built-in themes (loaded from var/themes/).
     *
     * This array is populated at runtime when users upload custom themes
     * or when plugin themes need to override built-ins.
     *
     * Structure mirrors 'builtin' above.
     *
     * @var array<string, array<string, mixed>>
     */
    'user_override' => [],
];
