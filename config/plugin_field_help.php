<?php

declare(strict_types=1);

/**
 * Server-side plugin field-help overlay.
 *
 * Curated, human-facing help for plugin configure forms — labels, richer
 * descriptions, and "where do I get this value" links. It is merged over each
 * installed plugin's manifest `settings` schema by
 * {@see \Phlix\Plugins\PluginFieldHelp}, so an ALREADY-INSTALLED plugin shows
 * the improved help immediately, without waiting for a plugin update that
 * carries the same text in its own `plugin.json`.
 *
 * Shape: `pluginName => [ settingKey => [label?, description?, link?, link_text?] ]`.
 * Only the keys present here override the manifest — everything else
 * (`type`/`required`/`secret`/`default`) still comes from the manifest schema.
 * Add an entry for any new plugin/field here to enrich its form.
 *
 * @return array<string, array<string, array{label?: string, description?: string, link?: string, link_text?: string}>>
 */

return [
    'phlix-plugin-anidb' => [
        'username' => [
            'label'       => 'AniDB Username',
            'description' => 'Your AniDB account username. Registration is free.',
            'link'        => 'https://anidb.net/',
            'link_text'   => 'Create an AniDB account',
        ],
        'api_key' => [
            'label'       => 'AniDB UDP API Key',
            'description' => 'The UDP "API Key" (a.k.a. API password) you set in your AniDB profile under '
                . 'Settings → Account → API. This is SEPARATE from your website login password. Required to '
                . 'query the AniDB UDP API.',
            'link'        => 'https://anidb.net/software/api',
            'link_text'   => 'AniDB API docs',
        ],
        'use_title_dump' => [
            'label'       => 'Use anime title dump',
            'description' => 'Download the daily anime-titles dump for fast, offline title search. Recommended — '
                . 'it greatly reduces the number of rate-limited UDP calls. Optional; default on.',
        ],
        'title_dump_url' => [
            'label'       => 'Title dump URL',
            'description' => 'Source URL for the anime-titles.dat.gz file. Optional — change only if you use a '
                . 'mirror. Default: the official AniDB dump.',
        ],
    ],

    'phlix-plugin-lastfm' => [
        'enabled' => [
            'label'       => 'Enable scrobbling',
            'description' => 'Master on/off for Last.fm scrobbling. Optional; default off.',
        ],
        'api_key' => [
            'label'       => 'Last.fm API Key',
            'description' => 'Your Last.fm API key. Create a free API account to get one.',
            'link'        => 'https://www.last.fm/api/account/create',
            'link_text'   => 'Create a Last.fm API account',
        ],
        'shared_secret' => [
            'label'       => 'Last.fm Shared Secret',
            'description' => 'The "Shared secret" shown next to your API key on your Last.fm API accounts page. '
                . 'Used to sign authenticated requests.',
            'link'        => 'https://www.last.fm/api/accounts',
            'link_text'   => 'Your Last.fm API accounts',
        ],
        'callback_url' => [
            'label'       => 'OAuth Callback URL',
            'description' => 'The Callback URL you registered for your Last.fm API application, used for the '
                . 'authorization handshake. Optional — defaults to this server.',
        ],
        'username' => [
            'label'       => 'Last.fm Username',
            'description' => 'Display-only: the Last.fm account scrobbles are sent to (set during authorization).',
        ],
    ],

    'phlix-plugin-omdb' => [
        'enabled' => [
            'label'       => 'Enable OMDb',
            'description' => 'Master on/off for OMDb rating enrichment. Optional; default off.',
        ],
        'api_key' => [
            'label'       => 'OMDb API Key',
            'description' => 'Your OMDb API key. The free tier allows 1,000 requests/day.',
            'link'        => 'https://www.omdbapi.com/apikey.aspx',
            'link_text'   => 'Get a free OMDb API key',
        ],
        'use_ssl_verification' => [
            'label'       => 'Verify SSL',
            'description' => 'Verify OMDb\'s TLS certificate. Optional; default on. Leave on unless debugging.',
        ],
        'cache_ttl_seconds' => [
            'label'       => 'Cache TTL (seconds)',
            'description' => 'How long to cache OMDb responses. Optional; default 86400 (24 hours).',
        ],
    ],

    'phlix-plugin-trakt' => [
        'enabled' => [
            'label'       => 'Enable Trakt',
            'description' => 'Master on/off for Trakt scrobbling and sync. Optional; default off.',
        ],
        'username' => [
            'label'       => 'Trakt Username',
            'description' => 'Display-only: the Trakt account Phlix is linked to (set during authorization).',
        ],
        'access_token' => [
            'label'       => 'Access Token',
            'description' => 'Obtained automatically when you authorize Phlix with Trakt — you do not enter this '
                . 'by hand. Create an API app only if you self-host credentials.',
            'link'        => 'https://trakt.tv/oauth/applications',
            'link_text'   => 'Trakt API applications',
        ],
        'refresh_token' => [
            'label'       => 'Refresh Token',
            'description' => 'Set automatically during authorization; used to renew the access token. Not entered '
                . 'by hand.',
        ],
        'expires_at' => [
            'label'       => 'Token Expiry',
            'description' => 'Unix timestamp when the access token expires. Managed automatically.',
        ],
        'sync_enabled' => [
            'label'       => 'Enable library sync',
            'description' => 'Sync watched state with Trakt. Optional; default on.',
        ],
        'sync_interval_minutes' => [
            'label'       => 'Sync interval (minutes)',
            'description' => 'How often to sync with Trakt. Optional; default 30.',
        ],
        'scrobble_enabled' => [
            'label'       => 'Enable scrobbling',
            'description' => 'Report play/pause/stop to Trakt as you watch. Optional; default on.',
        ],
    ],

    'phlix-plugin-opensubtitles' => [
        'api_key' => [
            'label'       => 'OpenSubtitles API Key',
            'description' => 'The API key for your OpenSubtitles.com consumer (API application). Required.',
            'link'        => 'https://www.opensubtitles.com/en/consumers',
            'link_text'   => 'OpenSubtitles API consumers',
        ],
        'username' => [
            'label'       => 'Username',
            'description' => 'Your OpenSubtitles.com username. Optional — providing login credentials raises '
                . 'download limits (and unlocks VIP limits for VIP accounts).',
        ],
        'password' => [
            'label'       => 'Password',
            'description' => 'Your OpenSubtitles.com password. Optional; only used with the username above.',
        ],
        'language' => [
            'label'       => 'Subtitle language',
            'description' => 'Preferred subtitle language as an ISO 639-1 code (e.g. en, es, fr). Optional; '
                . 'default en.',
        ],
        'format' => [
            'label'       => 'Subtitle format',
            'description' => 'Preferred subtitle file format. Optional; default srt.',
        ],
    ],

    'phlix-plugin-musicbrainz' => [
        'enabled' => [
            'label'       => 'Enable MusicBrainz',
            'description' => 'Master on/off for MusicBrainz enrichment. Optional; default off. No API key needed — '
                . 'MusicBrainz is an open service.',
        ],
        'user_agent' => [
            'label'       => 'User-Agent',
            'description' => 'Identifies Phlix to MusicBrainz (their policy REQUIRES a descriptive User-Agent with '
                . 'contact info). Optional — the default is fine; customise if you run a fork.',
            'link'        => 'https://musicbrainz.org/doc/MusicBrainz_API/Rate_Limiting',
            'link_text'   => 'MusicBrainz API etiquette',
        ],
        'rate_limit_delay' => [
            'label'       => 'Rate-limit delay (ms)',
            'description' => 'Minimum delay between MusicBrainz requests. Optional; default 1100 (MusicBrainz asks '
                . 'for no more than ~1 request/second).',
        ],
        'auto_enrich' => [
            'label'       => 'Auto-enrich',
            'description' => 'Automatically enrich music items as they are scanned. Optional; default on.',
        ],
        'fetch_album_art' => [
            'label'       => 'Fetch album art',
            'description' => 'Pull cover art via the Cover Art Archive. Optional; default on.',
        ],
        'fetch_acoustid' => [
            'label'       => 'Fetch AcoustID',
            'description' => 'Use AcoustID audio fingerprints to improve matching. Optional; default on.',
        ],
        'search_depth' => [
            'label'       => 'Search depth',
            'description' => 'How hard to search for matches (e.g. normal / deep). Optional; default normal.',
        ],
    ],

    'phlix-plugin-anilist' => [
        'enabled' => [
            'label'       => 'Enable AniList',
            'description' => 'Master on/off for AniList sync/matching. Optional; default off.',
        ],
        'access_token' => [
            'label'       => 'Access Token',
            'description' => 'Obtained automatically when you authorize Phlix with AniList — you do not enter this '
                . 'by hand. Register a client only if you self-host credentials.',
            'link'        => 'https://anilist.co/settings/developer',
            'link_text'   => 'AniList developer settings',
        ],
        'username' => [
            'label'       => 'AniList Username',
            'description' => 'Display-only: the AniList account Phlix is linked to (set during authorization).',
        ],
        'sync_enabled' => [
            'label'       => 'Enable list sync',
            'description' => 'Sync watched/progress with your AniList list. Optional; default on.',
        ],
        'sync_interval_minutes' => [
            'label'       => 'Sync interval (minutes)',
            'description' => 'How often to sync with AniList. Optional; default 60.',
        ],
        'auto_match' => [
            'label'       => 'Auto-match',
            'description' => 'Automatically match anime to AniList entries. Optional; default on.',
        ],
    ],

    'phlix-plugin-myanimelist' => [
        'client_id' => [
            'label'       => 'MyAnimeList Client ID',
            'description' => 'Your MyAnimeList API Client ID. Create an API application (choose "web"/"other"), '
                . 'then copy its Client ID.',
            'link'        => 'https://myanimelist.net/apiconfig',
            'link_text'   => 'MyAnimeList API applications',
        ],
        'use_ssl_verification' => [
            'label'       => 'Verify SSL',
            'description' => 'Verify MyAnimeList\'s TLS certificate. Optional; default on. Leave on unless '
                . 'debugging.',
        ],
    ],
];
