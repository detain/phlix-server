<?php

declare(strict_types=1);

/**
 * LiveTV configuration.
 *
 * @since 0.12.0
 */

return [
    /**
     * HDHomeRun tuner settings.
     */
    'hdhomerun' => [
        /**
         * Enable HDHomeRun tuner discovery and streaming.
         *
         * @default true
         */
        'enabled' => true,

        /**
         * SSDP discovery timeout in seconds.
         *
         * @default 5
         */
        'ssdp_timeout_secs' => 5,

        /**
         * Preferred HDHomeRun device ID to use (null = auto-discover first available).
         *
         * Set to a specific device ID (e.g. "12345678") to use that device exclusively.
         * Null value means the first discovered device will be used.
         *
         * @default null
         */
        'preferred_device_id' => null,

        /**
         * Preferred tuner index on multi-tuner devices.
         *
         * HDHomeRun devices can have multiple tuners. This specifies which tuner
         * to use first (0-indexed). Set to null to use any available tuner.
         *
         * @default 0
         */
        'preferred_tuner_index' => 0,
    ],

    /**
     * Storage path for Live TV recordings.
     *
     * @default '/var/recordings'
     */
    'storage_path' => '/var/recordings',

    /**
     * Maximum storage bytes for Live TV recordings (0 = unlimited).
     *
     * @default 0
     */
    'max_storage_bytes' => 0,

    /**
     * Default stream quality profile for Live TV.
     *
     * Options: 'mobile-low', 'mobile-high', 'web', 'tv', 'tv-4k'
     *
     * @default 'tv'
     */
    'default_quality' => 'tv',

    /**
     * Enable direct streaming (bypass transcoding) when possible.
     *
     * @default true
     */
    'allow_direct_stream' => true,

    /**
     * IPTV tuner settings.
     */
    'iptv' => [
        /**
         * Enable IPTV tuner support.
         *
         * @default true
         */
        'enabled' => false,

        /**
         * IPTV source definitions.
         *
         * Each source requires a name and playlist_url. The epg_url is optional
         * and points to an XMLTV guide file for programme data.
         *
         * @default []
         */
        'sources' => [
            // Example source:
            // [
            //     'name' => 'My IPTV',
            //     'playlist_url' => 'https://example.com/playlist.m3u8',
            //     'epg_url' => 'https://example.com/epg.xml',
            //     'enabled' => true,
            // ],
        ],
    ],
];
