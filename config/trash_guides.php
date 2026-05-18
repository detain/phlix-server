<?php

declare(strict_types=1);

/**
 * TRaSH-Guides sync configuration.
 *
 * @package Phlex\Config
 * @since 0.12.0
 */

return [
    /**
     * Enable or disable automatic TRaSH-Guides sync.
     *
     * @since 0.12.0
     */
    'enabled' => false,

    /**
     * Interval between automatic syncs in seconds.
     * Default: 86400 (24 hours).
     *
     * @since 0.12.0
     */
    'auto_sync_interval' => 86400,

    /**
     * URL to the TRaSH-Guides custom formats JSON.
     * This JSON contains the collection of custom formats for Radarr.
     *
     * @since 0.12.0
     */
    'custom_formats_url' => 'https://raw.githubusercontent.com/TRaSH-/Guides/main/docs/json/radarr/radarr-collection-of-custom-formats.json',

    /**
     * URL to the TRaSH-Guides quality profiles JSON.
     * This JSON contains the recommended quality profile setup for Radarr.
     *
     * @since 0.12.0
     */
    'quality_profiles_url' => 'https://raw.githubusercontent.com/TRaSH-/Guides/main/docs/json/radarr/radarr-setup-quality-profiles-parent.json',
];
