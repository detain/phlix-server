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
 * ## Link policy (plan_settings.md §3.5)
 *
 * Concepts, protocols, acronyms and file formats link to **Wikipedia**; named
 * programs and services link to their **official site**. Every link below was
 * HTTP-verified when added.
 *
 * Four Wikipedia targets proposed by the investigation notes were checked
 * against the MediaWiki API and **do not exist** — `AniDB`, `AniList` and
 * `Trakt` are missing articles, and `Cover Art Archive` is a redirect that
 * lands on the general `MusicBrainz` page. Those four use official sites
 * instead. Do not "restore" them to Wikipedia.
 *
 * Three Trakt URLs are permanently dead and must never be reintroduced:
 * `trakt.tv/oauth/applications` (404), `trakt.tv/apps` (404) and
 * `trakt.docs.apiary.io` (no response). All `trakt.tv/*` paths now redirect to
 * `app.trakt.tv/*`; the live API-application page is
 * `https://app.trakt.tv/settings/apps/api/new`. Note `app.trakt.tv/settings/apps`
 * returns 200 but is the *connected apps* page, not the API-application page.
 *
 * A 403 from anidb.net or opensubtitles.com is a Cloudflare bot challenge, not a
 * dead link — verify with a browser User-Agent before "fixing" one.
 *
 * @return array<string, array<string, array{label?: string, description?: string, link?: string, link_text?: string}>>
 */

return [
    'phlix-plugin-anidb' => [
        'username' => [
            'label'       => 'AniDB Username',
            'description' => 'Your AniDB account username (NOT the API password — that is the separate '
                . 'field below). Registration is free.',
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
            'link'        => 'https://anidb.net/software/api',
            'link_text'   => 'AniDB API & title dump docs',
        ],
        'title_dump_url' => [
            'label'       => 'Title dump URL',
            'description' => 'Source URL for the anime-titles.dat.gz file. Optional — change only if you use a '
                . 'mirror. Note the shipped default is plain http://, so the dump is fetched unencrypted.',
            'link'        => 'https://en.wikipedia.org/wiki/HTTPS',
            'link_text'   => 'Why plain http:// is not encrypted',
        ],
    ],

    'phlix-plugin-lastfm' => [
        'enabled' => [
            'label'       => 'Enable scrobbling',
            'description' => 'Master on/off for Last.fm scrobbling — reporting what you play to your Last.fm '
                . 'profile. Optional; default off.',
            'link'        => 'https://www.last.fm/about/trackmymusic',
            'link_text'   => 'What scrobbling is',
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
                . 'authorization handshake. Optional — a server-relative path such as '
                . '/auth/lastfm/callback is expected, not a full URL.',
            'link'        => 'https://www.last.fm/api/webauth',
            'link_text'   => 'Last.fm web-auth handshake',
        ],
        'username' => [
            'label'       => 'Last.fm Username',
            'description' => 'Display-only: the Last.fm account scrobbles are sent to (set during authorization).',
            'link'        => 'https://www.last.fm/',
            'link_text'   => 'Last.fm',
        ],
    ],

    'phlix-plugin-omdb' => [
        'enabled' => [
            'label'       => 'Enable OMDb',
            'description' => 'Master on/off for OMDb rating enrichment. Optional; default off.',
            'link'        => 'https://www.omdbapi.com/',
            'link_text'   => 'OMDb API',
        ],
        'api_key' => [
            'label'       => 'OMDb API Key',
            'description' => 'Your OMDb API key. The free tier allows 1,000 requests/day.',
            'link'        => 'https://www.omdbapi.com/apikey.aspx',
            'link_text'   => 'Get a free OMDb API key',
        ],
        'use_ssl_verification' => [
            'label'       => 'Verify SSL certificates',
            'description' => "Verify OMDb's TLS certificate. Optional; default on. Disable only behind a "
                . 'self-hosted proxy with a self-signed certificate.',
            'link'        => 'https://en.wikipedia.org/wiki/Transport_Layer_Security',
            'link_text'   => 'TLS',
        ],
        'cache_ttl_seconds' => [
            'label'       => 'Cache TTL (seconds)',
            'description' => 'How long to cache OMDb responses before re-fetching. Optional; default 86400 '
                . '(24 hours).',
            'link'        => 'https://en.wikipedia.org/wiki/Time_to_live',
            'link_text'   => 'Time to live (TTL)',
        ],
    ],

    'phlix-plugin-trakt' => [
        'enabled' => [
            'label'       => 'Enable Trakt',
            'description' => 'Master on/off for Trakt scrobbling and sync. Optional; default off.',
            'link'        => 'https://trakt.tv/',
            'link_text'   => 'Trakt',
        ],
        'username' => [
            'label'       => 'Trakt Username',
            'description' => 'Display-only: the Trakt account Phlix is linked to (set during authorization).',
            'link'        => 'https://trakt.tv/',
            'link_text'   => 'Your Trakt profile',
        ],
        'access_token' => [
            'label'       => 'Access Token',
            'description' => 'Obtained automatically when you authorize Phlix with Trakt — you do not enter this '
                . 'by hand. Create an API app only if you self-host credentials.',
            // Was https://trakt.tv/oauth/applications, which now 404s. See the file
            // docblock: all trakt.tv/* paths redirect to app.trakt.tv/*, and this is
            // the live "create an API app" page.
            'link'        => 'https://app.trakt.tv/settings/apps/api/new',
            'link_text'   => 'Create a Trakt API app',
        ],
        'refresh_token' => [
            'label'       => 'Refresh Token',
            'description' => 'Set automatically during authorization; used to renew the access token. Not entered '
                . 'by hand.',
            'link'        => 'https://en.wikipedia.org/wiki/OAuth',
            'link_text'   => 'OAuth token refresh',
        ],
        'expires_at' => [
            'label'       => 'Token Expiry',
            'description' => 'Unix timestamp when the access token expires. Managed automatically.',
            'link'        => 'https://en.wikipedia.org/wiki/Unix_time',
            'link_text'   => 'Unix timestamp',
        ],
        'sync_enabled' => [
            'label'       => 'Enable library sync',
            'description' => 'Sync watched state with Trakt. Optional; default on.',
            'link'        => 'https://docs.trakt.tv/docs/getting-started',
            'link_text'   => 'Trakt API docs',
        ],
        'sync_interval_minutes' => [
            'label'       => 'Sync interval (minutes)',
            'description' => 'Intended sync frequency. NOTE: this value is currently ignored — the sync loop '
                . 'uses a fixed 30-minute interval. Changing it has no effect until the plugin wires it up.',
            'link'        => 'https://en.wikipedia.org/wiki/Polling_(computer_science)',
            'link_text'   => 'Polling',
        ],
        'scrobble_enabled' => [
            'label'       => 'Enable scrobbling',
            'description' => 'Report play/pause/stop to Trakt as you watch. Optional; default on.',
            'link'        => 'https://trakt.tv/',
            'link_text'   => 'Trakt',
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
            'link'        => 'https://www.opensubtitles.com/',
            'link_text'   => 'OpenSubtitles.com',
        ],
        'password' => [
            'label'       => 'Password',
            'description' => 'Your OpenSubtitles.com password. Optional; only used with the username above.',
            'link'        => 'https://www.opensubtitles.com/',
            'link_text'   => 'OpenSubtitles.com account',
        ],
        'language' => [
            'label'       => 'Subtitle language',
            'description' => 'Preferred subtitle language as an ISO 639-1 code (e.g. en, es, fr). Optional; '
                . 'default en.',
            'link'        => 'https://en.wikipedia.org/wiki/List_of_ISO_639_language_codes',
            'link_text'   => 'ISO 639-1 language codes',
        ],
        'format' => [
            'label'       => 'Subtitle format',
            'description' => 'Preferred subtitle file format (srt, sub, ass, etc.). Optional; default srt.',
            'link'        => 'https://en.wikipedia.org/wiki/SubRip',
            'link_text'   => 'SubRip (.srt) format',
        ],
    ],

    'phlix-plugin-musicbrainz' => [
        'enabled' => [
            'label'       => 'Enable MusicBrainz',
            'description' => 'Master on/off for MusicBrainz enrichment. Optional; default off. No API key needed — '
                . 'MusicBrainz is an open service.',
            'link'        => 'https://musicbrainz.org/',
            'link_text'   => 'MusicBrainz',
        ],
        'user_agent' => [
            'label'       => 'User-Agent',
            'description' => 'Identifies Phlix to MusicBrainz (their policy REQUIRES a descriptive User-Agent '
                . 'containing a contact URL). Optional — the default is fine; if you customise it, keep a '
                . 'URL in the string or the plugin will report itself as not configured.',
            'link'        => 'https://musicbrainz.org/doc/MusicBrainz_API/Rate_Limiting',
            'link_text'   => 'MusicBrainz API etiquette',
        ],
        'rate_limit_delay' => [
            'label'       => 'Rate-limit delay (ms)',
            'description' => 'Minimum delay between MusicBrainz requests. Optional; default 1100 (MusicBrainz asks '
                . 'for no more than ~1 request/second).',
            'link'        => 'https://en.wikipedia.org/wiki/Rate_limiting',
            'link_text'   => 'Rate limiting',
        ],
        'auto_enrich' => [
            'label'       => 'Auto-enrich',
            'description' => 'Automatically enrich music items as they are scanned. Optional; default on.',
            'link'        => 'https://musicbrainz.org/doc/MusicBrainz_API',
            'link_text'   => 'MusicBrainz API',
        ],
        'fetch_album_art' => [
            'label'       => 'Fetch album art',
            'description' => 'Pull cover art via the Cover Art Archive. Optional; default on.',
            'link'        => 'https://coverartarchive.org/',
            'link_text'   => 'Cover Art Archive',
        ],
        'fetch_acoustid' => [
            'label'       => 'Fetch AcoustID',
            'description' => 'Intended to use AcoustID audio fingerprints to improve matching. NOTE: not yet '
                . 'implemented — the lookup requires a locally computed fingerprint that the plugin does not '
                . 'produce, so this toggle currently has no effect.',
            'link'        => 'https://en.wikipedia.org/wiki/AcoustID',
            'link_text'   => 'AcoustID',
        ],
        'search_depth' => [
            'label'       => 'Search depth',
            'description' => 'How hard to search for matches: fast, normal or deep. Optional; default normal.',
            'link'        => 'https://musicbrainz.org/doc/Indexed_Search_Syntax',
            'link_text'   => 'MusicBrainz search',
        ],
    ],

    'phlix-plugin-anilist' => [
        'enabled' => [
            'label'       => 'Enable AniList',
            'description' => 'Master on/off for AniList sync/matching. Optional; default off.',
            'link'        => 'https://anilist.co/',
            'link_text'   => 'AniList',
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
            'link'        => 'https://anilist.co/',
            'link_text'   => 'Your AniList profile',
        ],
        'sync_enabled' => [
            'label'       => 'Enable list sync',
            'description' => 'Sync watched/progress with your AniList list. Optional; default on.',
            'link'        => 'https://docs.anilist.co/',
            'link_text'   => 'AniList API docs',
        ],
        'sync_interval_minutes' => [
            'label'       => 'Sync interval (minutes)',
            'description' => 'Intended sync frequency. NOTE: this value is currently ignored — the sync loop '
                . 'uses a fixed 60-minute interval. Changing it has no effect until the plugin wires it up.',
            'link'        => 'https://en.wikipedia.org/wiki/Polling_(computer_science)',
            'link_text'   => 'Polling',
        ],
        'auto_match' => [
            'label'       => 'Auto-match',
            'description' => 'Automatically match anime to AniList entries. Optional; default on.',
            'link'        => 'https://docs.anilist.co/',
            'link_text'   => 'How AniList matching works',
        ],
    ],

    'phlix-plugin-myanimelist' => [
        'client_id' => [
            'label'       => 'MyAnimeList Client ID',
            'description' => 'Your MyAnimeList API Client ID. Create an API application (choose "web"/"other"), '
                . 'then copy its Client ID. Sent as the X-MAL-CLIENT-ID header.',
            'link'        => 'https://myanimelist.net/apiconfig',
            'link_text'   => 'MyAnimeList API applications',
        ],
        'use_ssl_verification' => [
            'label'       => 'Verify SSL certificates',
            'description' => "Verify MyAnimeList's TLS certificate. Optional; default on. Disable only behind a "
                . 'self-hosted proxy with a self-signed certificate.',
            'link'        => 'https://en.wikipedia.org/wiki/Transport_Layer_Security',
            'link_text'   => 'TLS',
        ],
    ],
];
