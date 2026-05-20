<?php

declare(strict_types=1);

/**
 * Theme configuration for built-in and user-overridden themes.
 *
 * This file defines the four built-in Phlix themes that ship with the
 * server. Plugin-provided themes are merged at runtime via ThemeRegistry.
 *
 * @package Phlix\Theming
 * @since 0.14.0
 */

return [
    /**
     * Built-in themes shipped with Phlix.
     *
     * Each theme must have:
     * - id: Unique identifier (e.g., 'phlix-dark')
     * - name: Human-readable name
     * - css: URL or /assets path to the theme's CSS file
     * - js: Optional URL to a JS bundle (null if not needed)
     * - thumb: URL or /assets path to a preview thumbnail
     * - dark: Boolean indicating if this is a dark theme
     */
    'builtin' => [
        'dark' => [
            'id' => 'phlix-dark',
            'name' => 'Phlix Dark',
            'css' => '/assets/css/themes/phlix-dark.css',
            'js' => null,
            'thumb' => '/assets/images/themes/phlix-dark.png',
            'dark' => true,
        ],
        'light' => [
            'id' => 'phlix-light',
            'name' => 'Phlix Light',
            'css' => '/assets/css/themes/phlix-light.css',
            'js' => null,
            'thumb' => '/assets/images/themes/phlix-light.png',
            'dark' => false,
        ],
        'amoled' => [
            'id' => 'phlix-amoled',
            'name' => 'Phlix AMOLED',
            'css' => '/assets/css/themes/phlix-amoled.css',
            'js' => null,
            'thumb' => '/assets/images/themes/phlix-amoled.png',
            'dark' => true,
        ],
        'contrast' => [
            'id' => 'phlix-contrast',
            'name' => 'Phlix High Contrast',
            'css' => '/assets/css/themes/phlix-contrast.css',
            'js' => null,
            'thumb' => '/assets/images/themes/phlix-contrast.png',
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
