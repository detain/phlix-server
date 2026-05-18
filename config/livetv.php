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
     * XMLTV guide fetcher settings.
     *
     * Used by {@see \Phlex\LiveTv\Tuners\Iptv\XmlTvParser::parseUrl()} when
     * downloading remote XMLTV guide files. Bounds protect the worker
     * against malicious endpoints that serve arbitrarily large payloads.
     */
    'xmltv' => [
        /**
         * Maximum number of bytes to read from a remote XMLTV URL.
         *
         * Downloads exceeding this size throw {@see \Phlex\LiveTv\Tuners\Iptv\XmlTvOversizedException}.
         *
         * @default 67108864 (64 MiB)
         */
        'max_bytes' => 64 * 1024 * 1024,

        /**
         * HTTP request timeout in seconds for guide downloads.
         *
         * @default 30
         */
        'timeout_secs' => 30,

        /**
         * Maximum number of HTTP redirects to follow.
         *
         * @default 3
         */
        'max_redirects' => 3,
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

    /**
     * Comskip commercial detection settings.
     *
     * Comskip is a third-party application for detecting commercial
     * breaks in video recordings. When enabled, recordings are
     * automatically processed after completion to detect and store
     * commercial segments as chapter markers.
     */
    'comskip' => [
        /**
         * Enable Comskip commercial detection.
         *
         * When enabled, completed recordings are automatically
         * queued for Comskip processing.
         *
         * @default true
         */
        'enabled' => true,

        /**
         * Path to the Comskip binary.
         *
         * @default '/usr/bin/comskip'
         */
        'binary_path' => '/usr/bin/comskip',

        /**
         * Path to the Comskip configuration INI file.
         *
         * @default '/etc/comskip/comskip.ini'
         */
        'ini_path' => '/etc/comskip/comskip.ini',

        /**
         * Directory for storing generated EDL files.
         *
         * @default '/var/recordings/edl'
         */
        'output_dir' => '/var/recordings/edl',

        /**
         * Enable queue-based async processing.
         *
         * When true, recordings are enqueued and processed
         * asynchronously. When false, processing runs synchronously.
         *
         * @default true
         */
        'queue_processing' => true,

        /**
         * Maximum number of concurrent Comskip processes.
         *
         * @default 2
         */
        'max_concurrent' => 2,
    ],

    /**
     * DVR (Digital Video Recorder) settings.
     *
     * Controls scheduled and series recording behavior including
     * storage limits, pre/post-padding defaults, and auto-recording.
     */
    'dvr' => [
        /**
         * Enable DVR functionality.
         *
         * @default true
         */
        'enabled' => true,

        /**
         * Storage path for DVR recordings.
         *
         * @default '/var/recordings'
         */
        'storage_path' => '/var/recordings',

        /**
         * Maximum storage bytes for recordings (0 = unlimited).
         *
         * @default 0
         */
        'max_storage_bytes' => 0,

        /**
         * Default pre-recording padding in seconds.
         *
         * How many seconds before the scheduled start time to begin recording.
         *
         * @default 60
         */
        'default_pre_padding_seconds' => 60,

        /**
         * Default post-recording padding in seconds.
         *
         * How many seconds after the scheduled end time to continue recording.
         *
         * @default 60
         */
        'default_post_padding_seconds' => 60,

        /**
         * Auto-resolution of recording conflicts.
         *
         * When true, automatically starts scheduled recordings when
         * tuners become available. When false, requires manual intervention.
         *
         * @default true
         */
        'auto_resolution' => true,
    ],

    /**
     * HLS Relay settings for remote live TV streaming.
     *
     * Enables remote clients to watch Live TV by relaying HLS streams
     * through the hub's RelayConsumer (WebSocket tunnel).
     */
    'relay' => [
        /**
         * Enable HLS relay functionality.
         *
         * @default true
         */
        'enabled' => true,

        /**
         * Number of segments to prefetch ahead during playback.
         *
         * Higher values improve playback smoothness but increase memory usage.
         *
         * @default 3
         */
        'prefetch_segments' => 3,

        /**
         * Maximum concurrent relay sessions allowed.
         *
         * Each session consumes a tuner and memory for segment caching.
         *
         * @default 10
         */
        'max_concurrent_sessions' => 10,

        /**
         * TTL for cached HLS segments in seconds.
         *
         * Segments older than this are evicted from the LRU cache.
         *
         * @default 30
         */
        'segment_cache_ttl_seconds' => 30,

        /**
         * URL prefix for relay mount paths.
         *
         * Remote clients access streams via /relay/live/{sessionId}/playlist.m3u8
         *
         * @default '/relay/live'
         */
        'relay_path_prefix' => '/relay/live',
    ],
];
