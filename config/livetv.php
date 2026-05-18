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

    /**
     * DVB-T (Terrestrial) USB tuner settings.
     *
     * Linux DVB-T USB dongles based on chipsets like RTL2832U expose
     * /dev/dvb/ devices that this driver interfaces with using dvbv5-zap.
     */
    'dvbt' => [
        /**
         * Enable DVB-T tuner support.
         *
         * @default true
         */
        'enabled' => true,

        /**
         * Path to FFmpeg binary.
         *
         * Used for repackaging DVB-T transport stream to HLS.
         *
         * @default '/usr/bin/ffmpeg'
         */
        'ffmpeg_path' => '/usr/bin/ffmpeg',

        /**
         * Path to dvbv5-zap binary.
         *
         * Standard Linux DVB tuning tool for frequency scanning.
         *
         * @default '/usr/bin/dvbv5-zap'
         */
        'dvbv5_zap_path' => '/usr/bin/dvbv5-zap',

        /**
         * Default modulation type.
         *
         * Options: 'auto', 'QPSK', 'QAM64', 'QAM256', 'DVB-T', 'DVB-T2'
         *
         * @default 'auto'
         */
        'default_modulation' => 'auto',

        /**
         * Default bandwidth in MHz.
         *
         * Common values: 6, 7, 8 (MHz)
         *
         * @default 8
         */
        'default_bandwidth_mhz' => 8,
    ],

    /**
     * Schedules Direct EPG integration.
     *
     * Schedules Direct (https://www.schedulesdirect.org) provides authoritative
     * TV guide data including program listings, series info, and artwork.
     * Requires a valid SD account subscription.
     */
    'schedules_direct' => [
        /**
         * Enable Schedules Direct EPG sync.
         *
         * @default true
         */
        'enabled' => false,

        /**
         * Schedules Direct account username.
         *
         * @default ''
         */
        'username' => '',

        /**
         * Schedules Direct account password.
         *
         * Stored encrypted in production; never plaintext.
         *
         * @default ''
         */
        'password' => '',

        /**
         * Filesystem path for caching the SD API token.
         *
         * Token is auto-refreshed before expiration (23h TTL, refreshes at 24h).
         *
         * @default '/var/phlex/sd_token.json'
         */
        'token_cache_path' => '/var/phlex/sd_token.json',

        /**
         * Specific lineup ID to use (null = auto-detect from account).
         *
         * Lineup ID format: "USA-XXX-XXXXX" (e.g., "USA-OTA-00000")
         *
         * @default null
         */
        'lineup_id' => null,

        /**
         * How many hours ahead to fetch program guide data.
         *
         * Default: 336 hours (14 days). Max recommended: 336 hours (SD limit).
         *
         * @default 336
         */
        'sync_hours_ahead' => 336,

        /**
         * HTTP request timeout in seconds for SD API calls.
         *
         * @default 30
         */
        'timeout_secs' => 30,
    ],
];
