# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added (Step L.1)

- Webhook plugin framework for sending events to HTTP endpoints:
  - `WebhookEvent` — event class with eventType, payload, occurredAt, toArray(), getSignature() using HMAC-SHA256
  - `WebhookDispatcher` — registers/unregisters/dispatches webhooks, uses Workerman\MySQL\Connection and Workerman\Timer for async dispatch
  - `DispatchResult` — result class with successCount, failureCount, failures
  - `WebhookPluginInterface` — interface with getName(), getSupportedEvents(), send()
  - `migrations/018_webhooks.sql` — webhooks and webhook_logs tables
  - `WebhookAdminController` — GET/POST/DELETE /api/v1/admin/webhooks, POST test endpoint
  - `config/webhooks.php` — configuration with enabled, timeout, max_retries, parallel_dispatch
  - Unit tests: `WebhookEventTest` (5 tests), `WebhookDispatcherTest` (7 tests)

### Added (Step L.2)

- Notification provider plugins for webhook events:
  - 7 plugins: Discord, Slack, Telegram, Ntfy, Pushover, Apprise, MQTT
  - `AbstractNotificationPlugin` — base class with formatMessage(), getEmbedColor()
  - `WebhookPluginInterface` — getName(), getSupportedEvents(), send()
  - `PluginRegistry` — plugin management with get(), listAll(), register()
  - `config/notifications.php` — all 7 provider configurations
  - Unit tests: DiscordPluginTest (7), SlackPluginTest (6), TelegramPluginTest (6), NtfyPluginTest (7)

### Added (Step K.2)

- Bazarr/Prowlarr API clients for subtitle and indexer management:
  - `BazarrClient` — Bazarr API client with getSubtitles(), getSubtitleLanguages(), downloadSubtitle(), getLanguages(), testConnection()
  - `ProwlarrClient` — Prowlarr API client with getIndexers(), getIndexerStats(), getHealth(), triggerReindexerCheck(), testConnection()
  - Extended `config/arr.php` with bazarr and prowlarr sections
  - Unit tests: `BazarrClientTest` (9 tests), `ProwlarrClientTest` (8 tests)

### Added (Step K.1)

- Sonarr/Radarr API clients for media server integration:
  - `ArrClientInterface` — common interface for *arr clients with getQueue(), getQualityProfiles(), getTagList(), testConnection()
  - `SonarrClient` — Sonarr v3 API client with getSeries(), getSeriesById(), getEpisodeFile(), getQueue(), getWantedMissing(), getQualityProfiles(), getTagList(), addSeries(), triggerDownload(), testConnection()
  - `RadarrClient` — Radarr v3 API client with getMovies(), getMovieById(), getQueue(), getQualityProfiles(), getCustomFormats(), getTagList(), addMovie(), triggerDownload(), testConnection()
  - `ArrClientFactory` — factory for creating Sonarr/Radarr clients from config array
  - `config/arr.php` — configuration file for Sonarr/Radarr connection settings
  - Unit tests: `SonarrClientTest` (12 tests), `RadarrClientTest` (11 tests), `ArrClientFactoryTest` (10 tests)

### Added (Step J.6)

- Roku ECP support — send media to Roku devices:
  - `RokuDevice` — Roku device descriptor with deviceId, name, host, port, model, softwareVersion
  - `RokuDiscovery` — discovers Roku devices via mDNS `_ roku-ecnp._tcp.local.` using MdnsDiscovery
  - `RokuEcpClient` — HTTP ECP client with launchChannel(), playMedia(), sendKeypress(), getDeviceInfo(), getPlayerState()
  - `RokuSession` — active Roku session with playMedia()/pause()/play()/stop(), player state polling every 5 seconds via Workerman Timer
  - `RokuManager` — manages Roku sessions, discovers devices, creates sessions, launches media
  - `RemoteRokuClient` — Roku control via relay tunnel (RelayConsumer) for devices behind NAT
  - `RokuController` — HTTP API endpoints:
    - GET /api/v1/roku/devices — list discovered Roku devices
    - POST /api/v1/roku/devices/{id}/send — send media to Roku
    - POST /api/v1/roku/devices/{id}/launch/{channelId} — launch a channel
    - POST /api/v1/roku/devices/{id}/key/{keyName} — send keypress
    - GET /api/v1/roku/devices/{id}/status — get session status
  - `Application` — registered Roku routes in `loadRokuRoutes()`
  - Unit tests: `RokuDeviceTest` (4 tests), `RokuDiscoveryTest` (3 tests), `RokuEcpClientTest` (8 tests), `RokuSessionTest` (7 tests), `RokuManagerTest` (6 tests)

### Added (Step J.5)

- AirPlay 2 support — stream audio to AirPlay 2 devices (Apple TV, HomePod, AirPlay 2-compatible receivers):
  - `AirPlayDevice` — AirPlay device descriptor with deviceId, name, host, port, raopPort, model, supportsVideo
  - `AirPlayDiscovery` — discovers AirPlay devices via mDNS `_airplay._tcp.local.` and `_raop._tcp.local.` using MdnsDiscovery
  - `RaopClient` — RAOP (Real-Time Audio Protocol) client with buildAnnouncePayload(), flush(), getRtpInfo(), getLatency()
  - `AirPlaySession` — active AirPlay session with startStream()/pause()/resume()/stop() and state management
  - `AirPlayManager` — manages AirPlay sessions, discovers devices, creates/retrieves/stops sessions
  - `RemoteAirPlayClient` — AirPlay via relay tunnel (RelayConsumer) for devices behind NAT
  - `AirPlayController` — HTTP API endpoints:
    - GET /api/v1/airplay/devices — list discovered AirPlay devices
    - POST /api/v1/airplay/devices/{id}/stream — start streaming
    - POST /api/v1/airplay/devices/{id}/pause — pause playback
    - POST /api/v1/airplay/devices/{id}/resume — resume playback
    - POST /api/v1/airplay/devices/{id}/stop — stop playback
    - GET /api/v1/airplay/devices/{id}/status — get session status
  - `HlsStreamer` — added `getAirPlayStreamUrl()` for AirPlay-compatible stream URLs
  - `Application` — registered AirPlay routes in `loadAirPlayRoutes()`
  - Unit tests: `AirPlayDeviceTest` (5 tests), `AirPlayDiscoveryTest` (3 tests), `RaopClientTest` (5 tests), `AirPlaySessionTest` (5 tests), `AirPlayManagerTest` (5 tests)

### Added (Step J.4)

- Chromecast support — cast to Chromecast devices via Default Media Receiver:
  - `CastDevice` — Chromecast device descriptor with device ID, name, host, port, model, UUID
  - `CastDiscovery` — discovers Chromecast devices via mDNS `_googlecast._tcp.local.` using MdnsDiscovery
  - `CastApiClient` — HTTP/JSON Cast protocol client with connect(), launchApp(), loadMedia(), sendMediaCommand(), getMediaStatus()
  - `CastSession` — active Chromecast session with play/pause/stop/seek, position polling every 5 seconds via Workerman Timer
  - `CastManager` — manages multiple cast sessions, creates sessions, launches app, loads media
  - `RemoteCastClient` — cast via relay tunnel (RelayConsumer) for Chromecast behind NAT
  - `ChromecastController` — HTTP API endpoints:
    - GET /api/v1/cast/devices — list discovered Chromecast devices
    - POST /api/v1/cast/devices/{id}/cast — start casting
    - POST /api/v1/cast/devices/{id}/play — resume playback
    - POST /api/v1/cast/devices/{id}/pause — pause playback
    - POST /api/v1/cast/devices/{id}/stop — stop casting
    - POST /api/v1/cast/devices/{id}/seek — seek to position (ms)
    - GET /api/v1/cast/devices/{id}/status — get session status
  - `HlsStreamer` — added `getCastStreamUrl()` for Chromecast-compatible stream URLs
  - `Application` — registered Chromecast routes in `loadChromecastRoutes()`
  - Default Media Receiver app ID: `CC1AD845`
  - Unit tests: `CastDeviceTest` (4 tests), `CastDiscoveryTest` (4 tests), `CastApiClientTest` (8 tests), `CastSessionTest` (8 tests), `CastManagerTest` (8 tests)

### Added (Step J.3)

- DLNA AVTransport "play to" — send media to DLNA renderers:
  - `RendererDiscovery` — discovers DLNA MediaRenderers via SSDP with `urn:schemas-upnp-org:device:MediaRenderer:1`
  - `RendererControlClient` — HTTP SOAP client for AVTransport control (SetAVTransportURI, Play, Pause, Stop, Seek, GetPositionInfo, GetTransportInfo)
  - `PlayToSession` — active "play to" session with position polling every 5 seconds via Workerman Timer
  - `PlayToManager` — manages multiple play-to sessions, creates RendererControlClient, maps renderer IDs to sessions
  - `RemoteRendererClient` — "play to" via relay tunnel (RelayConsumer) for renderers behind NAT
  - `RendererListController` — HTTP API endpoints:
    - GET /api/v1/dlna/renderers — list discovered renderers
    - POST /api/v1/dlna/renderers/{id}/play — start "play to" session
    - POST /api/v1/dlna/renderers/{id}/pause — pause playback
    - POST /api/v1/dlna/renderers/{id}/stop — stop playback
    - POST /api/v1/dlna/renderers/{id}/seek — seek to position (ticks)
    - GET /api/v1/dlna/renderers/{id}/status — get renderer state
  - `AvTransport` — added `onStateChange()` callbacks and `notifyStateChange()` for observable state changes
  - `PlaybackController` — added `startPlayToSession()` for integrated local + remote playback
  - `Application` — registered DLNA renderer control routes in `loadDlnaRendererRoutes()`
  - Unit tests: `RendererDiscoveryTest` (5 tests), `RendererControlClientTest` (9 tests), `PlayToSessionTest` (11 tests), `PlayToManagerTest` (8 tests)

### Added (Step J.2)

- DLNA ContentDirectory full — browse and search real media library:
  - `LibraryBridge` — bridges `ItemRepository` to `ContentDirectory` for real media data
  - `CdsControlHandler` — HTTP SOAP endpoint for ContentDirectory actions (Browse, Search)
  - `CdsServer` — full DLNA MediaServer with HTTP endpoints: `/description.xml`, `/cds/control`, `/scpd/{service}.xml`
  - `src/Server/Http/Controllers/Dlna/DeviceDescriptionController` — serves `/description.xml`
  - `src/Server/Http/Controllers/Dlna/CdsControlController` — handles CDS SOAP requests
  - `ContentDirectory` — now uses `LibraryBridge` for real library data instead of stubs
  - `DlnaServer` — requires real `ItemRepository` (no stub), supports `setLibraryBridge()`
  - Unit tests: `LibraryBridgeTest` (14 tests), `CdsControlHandlerTest` (10 tests), `CdsServerTest` (13 tests)

### Added (Step J.1)

- SSDP (Simple Service Discovery Protocol) and mDNS (multicast DNS) discovery infrastructure:
  - `SsdpSocket` — raw UDP socket wrapper for SSDP multicast `239.255.255.250:1900`
  - `SsdpDevice` — discovered SSDP device descriptor with `getDeviceId()` and `getBaseUrl()`
  - `SsdpDiscovery` — SSDP discovery service with `discoverDevices()` and `announceServer()`
  - `MdnsSocket` — raw UDP socket wrapper for mDNS multicast `224.0.0.251:5353`
  - `MdnsService` — resolved mDNS service descriptor with `getAddress()`
  - `MdnsDiscovery` — mDNS discovery service with `discoverChromecast()`, `discoverAirPlay()`, `discoverRoku()`
  - `DiscoveryManager` — unified facade combining SSDP and mDNS discovery
  - `DiscoveryServer` — Workerman Timer integration for background discovery
  - `config/discovery.php` — configuration with SSDP/mDNS settings
  - Unit tests: `SsdpSocketTest`, `SsdpDiscoveryTest`, `MdnsSocketTest`, `MdnsDiscoveryTest`, `DiscoveryManagerTest` (12+ tests)
  - `docs/developers/discovery.md` — protocol documentation

### Added (Step I.7)

- Hub relay for remote live TV streams (HLS re-streaming via hub WebSocket tunnel):
  - `HlsRelaySession` — value object for relay session with `sessionId`, `channelId`, `tuneRequestId`, `getMountUrl()`, `getVariantPlaylistUrl()`
  - `HlsRelayManager` — orchestrates relay sessions: `startRelaySession()`, `stopRelaySession()`, `getActiveSessions()`, `getUserSession()`
  - `HlsSegmentPrefetcher` — LRU cache for HLS segments with Workerman Timer-based prefetching (`startPrefetch()`, `stopPrefetch()`, `getSegment()`)
  - `HlsRelaySessionFactory` — factory for building `HlsRelayManager` from config
  - `RelayConsumer` — added `registerMount()` and `unregisterMount()` methods for dynamic path handlers; `dispatchViaMount()` routes `/relay/live/{sessionId}/*` to registered handlers
  - `migrations/015_livetv_relay_sessions.sql` — creates `livetv_relay_sessions` table
  - `config/livetv.php` — added `relay` section with `enabled`, `prefetch_segments`, `max_concurrent_sessions`, `segment_cache_ttl_seconds`, `relay_path_prefix`
  - Unit tests in `tests/unit/LiveTv/Relay/` (HlsRelaySessionTest, HlsRelayManagerTest, HlsSegmentPrefetcherTest — 26+ tests)
  - `docs/developers/live-relay.md` — architecture docs, session lifecycle, configuration

### Added (Step I.6)

- Comskip commercial detection for live TV recordings with chapter markers:
  - `ComskipIntegration` — wires `ComskipRunner` into recording lifecycle:
    `processRecording()`, `getEdlSegments()`, `markProcessed()`
  - `ComskipLifecycleManager` — queue management with `max_concurrent` enforcement:
    `enqueue()`, `processNext()`, `getPendingCount()`
  - `ChapterMarkerService` — EDL to HLS chapter conversion:
    `toHlsChapters()`, `persistChapters()`, `getChapters()`
  - `migrations/014_livetv_commercials.sql` — adds `commercial_processed_at`,
    `commercial_edl_path`, `commercial_frame_count`, `commercial_duration_seconds`
    to `livetv_recordings`
  - `config/livetv.php` — added `comskip` section with `enabled`, `binary_path`,
    `ini_path`, `output_dir`, `queue_processing`, `max_concurrent`
  - `Recorder` — registers `ComskipLifecycleManager::enqueue()` via `onComplete()`
    callback at construction time
  - Unit tests in `tests/unit/LiveTv/Recording/` (ComskipIntegrationTest,
    ComskipLifecycleManagerTest, ChapterMarkerServiceTest — 12+ tests)
  - `docs/developers/comskip-live.md` — integration docs, EDL format, config

### Added (Step I.5)

- Scheduled + series DVR recordings. Includes:
  - `SeriesRuleManager` — CRUD for series recording rules; `matchAndSchedule()`
    queries `GuideManager::getUpcomingBySeries()` and schedules unmatched episodes
  - `RecordingDeduplicator` — prevents duplicate recordings via 2-hour window;
    `isDuplicate()`, `getCanonical()`, `resolveDuplicates()`
  - `RecordingScheduler` — priority-based conflict resolution; `processDueRecordings()`
    runs via Workerman timer; `getNextRecording()` for display
  - `RecordingHooksRunner` — async post-recording hook enqueueing
  - `migrations/013_livetv_dvr.sql` — adds `series_rule_id`, `duplicate_group`,
    `pre/post_padding_seconds` to `livetv_recordings`; creates `livetv_series_rules` table
  - `Recorder` — updated `scheduleRecording()` accepts `pre_padding_seconds`,
    `post_padding_seconds`, `series_rule_id`; added `isDuplicate()` method;
    `startRecording()` applies pre-padding (starts recording early)
  - `config/livetv.php` — added `dvr` section with `default_pre_padding_seconds`,
    `default_post_padding_seconds`, `auto_resolution`, `storage_path`,
    `max_storage_bytes`
  - `RecordingHooks` — already wires `ComskipPostProcessor` via `onComplete()` callback
  - Unit tests in `tests/unit/LiveTv/Recording/` (SeriesRuleManagerTest,
    RecordingDeduplicatorTest, RecordingSchedulerTest — 12+ tests)
  - `docs/developers/dvr.md` — series rules, deduplication, padding,
    conflict resolution, scheduler integration

### Added (Step I.4)

- Schedules Direct EPG integration. Includes:
  - `SdApiClient` — HTTP JSON client for SD API with token auth
    (BASE_URL: https://api.schedulesdirect.tmsglobal.com)
  - `SdLineupHandler` — fetches SD lineups, imports channels via ChannelManager
  - `SdProgramMapper` — maps SD program/schedule data to GuideManager format
  - `SdEpgService` — orchestrates full sync: fetch schedules, programs, upsert to guide
  - `SdEpgServiceFactory` — builds service from config with token caching
  - `config/livetv.php` — added `schedules_direct` section (username,
    password, token_cache_path, lineup_id, sync_hours_ahead, timeout_secs)
  - `LiveTvManager` — wired `SdEpgService` as optional dependency;
    `getSdEpgService()`, `setSdConfig()`, `syncSdEpG()`
  - Unit tests in `tests/unit/LiveTv/Epg/SchedulesDirect/` (SdApiClientTest,
    SdProgramMapperTest, SdEpgServiceTest — 12 tests total)
  - `docs/developers/schedules-direct.md` — SD API overview, auth, endpoints,
    data model, and config reference

### Added (Step I.3)

- Linux DVB-T USB tuner driver. Includes:
  - `DvbtDevice` — immutable value object for /dev/dvb/ devices
  - `DvbtDeviceScanner` — scans /dev/dvb/ for adapters, reads capabilities
  - `DvbtSignalEngine` — dvbv5-zap integration + FFmpeg ingest URL generation
  - `DvbtTunerDriver` — implements `TunerDriverInterface`
  - `DvbtTunerDriverFactory` — builds driver from `config/livetv.php`
  - `config/livetv.php` — added `dvbt` section
  - `TunerDriverInterface` — updated to accept `DvbtDevice` union type
  - `LiveTvManager` — integrated DvbtTunerDriver via additionalDrivers
  - Unit tests for scanner, signal engine, and driver
  - `docs/developers/dvbt.md` — developer documentation

### Added (Step I.2)

- M3U/XMLTV IPTV tuner driver. Includes:
  - `M3UEntry` — immutable value object for M3U playlist entries
  - `M3UParser` — parses M3U/M3U8 playlists, fetches remote via `parseUrl()`
  - `XmlTvProgramme` — immutable value object for XMLTV programme entries
  - `XmlTvParser` — parses XMLTV format, handles YYYYMMDDHHMMSS times
  - `IptvDevice` — immutable descriptor for IPTV sources
  - `IptvTunerDriver` — implements `TunerDriverInterface` for IPTV
  - `IptvTunerDriverFactory` — builds driver from `config/livetv.php`
  - `config/livetv.php` — added `iptv` section with `sources` array
  - `LiveTvManager` — integrated IPTV alongside HDHomeRun tuners
  - `GuideManager::upsertProgram()` — added `xmltv_id` parameter for IPTV matching
  - Unit tests for `M3UParser`, `XmlTvParser`, `IptvTunerDriver`
  - `docs/developers/iptv.md` — developer documentation

### Added (Step I.1)

- HDHomeRun tuner driver (SSDP discovery + HTTP API). Includes:
  - `TunerDriverInterface` — shared interface for all tuner drivers
  - `HdHomeRunDevice` — immutable value object for discovered devices
  - `HdHomeRunDiscovery` — SSDP M-SEARCH discovery on UDP 1900
  - `HdHomeRunApiClient` — HTTP API client for HDHomeRun devices
  - `HdHomeRunTunerDriver` — concrete driver implementing `TunerDriverInterface`
  - `HdHomeRunTunerDriverFactory` — factory for driver instantiation
  - `LiveTvManager` refactored to use `TunerDriverInterface` (no more `/dev/dvb` references)
  - `config/livetv.php` — LiveTV configuration with HDHomeRun settings
  - Unit tests for `HdHomeRunDiscovery`, `HdHomeRunApiClient`, `HdHomeRunTunerDriver`
  - `docs/developers/hdhomerun.md` — developer documentation

### Added (Step H.6)

- Theme music + theme video auto-play on browse. Includes:
  - `ThemeAudio` — readonly DTO (path, url, duration, format) for audio themes
  - `ThemeVideo` — readonly DTO (path, url, duration, width, height, format) for video backdrops
  - `ThemeMedia` — readonly DTO containing libraryId, audio, video, scannedAt
  - `ThemeMediaFinder` — filesystem scanner for theme.mp3/theme.ogg and backdrop.mp4/backdrop.webm
  - `ThemeMediaRepository` — cache operations (upsert, findByLibraryId, delete)
  - `ThemeMediaController` — 3 REST endpoints:
    - `GET /api/v1/libraries/{id}/theme-media` — get theme media
    - `POST /api/v1/libraries/{id}/theme-media/scan` — trigger rescan
    - `DELETE /api/v1/libraries/{id}/theme-media` — clear cached entry
  - `ThemeMediaStreamController` — 2 streaming endpoints:
    - `GET /stream/theme-media/{libraryId}/audio` — stream theme audio
    - `GET /stream/theme-media/{libraryId}/video` — stream theme video
  - `Migration 008_theme_media.sql` — creates theme_media table
  - `Router::themeMedia()` — registers all theme media routes
  - `library-header.tpl` — theme media player partial with toggle button
  - `theme-media.js` — autoplay handling with browser policy fallback
  - `LibraryManager::scanThemeMedia()` — scans and caches after library scan
  - `PageRenderer::setThemeMediaRepository()` + `renderLibrary()` passes themeMedia to template
  - Unit tests in `tests/unit/Theming/` (10+ tests)
  - Integration test `tests/integration/Theming/ThemeMediaScanTest.php`
  - `docs/developers/theme-media.md` — file naming, scanning, streaming, autoplay policy

### Added (Step H.5)

- Trailers and extras with local `Trailers/` folder support. Includes:
  - `Trailer` — readonly DTO (id, mediaItemId, title, source, url, duration, quality, isLocal, filePath)
  - `Extra` — readonly DTO for non-trailer extras (featurette|behind_the_scenes|interview|clip|deleted_scene|trailer)
  - `TrailerFinder` — filesystem scanner for local trailers (same-level and Trailers/ subfolder)
  - `TrailerResolver` — merges local + TMDB trailers, caches in media_extras with 24h TTL
  - `ExtrasRepository` — data access for media_extras table
  - `ExtrasController` — 3 REST endpoints:
    - `GET /api/v1/media/{id}/extras` — full merged list
    - `GET /api/v1/media/{id}/trailers` — trailers only
    - `GET /api/v1/media/{id}/extras/other` — non-trailer extras
  - `Migration 007_media_extras.sql` — creates media_extras table
  - `TmdbProvider::getTrailers()` — fetches trailers from TMDB API
  - `Router::extras()` — registers ExtrasController routes
  - `MediaScanner::hasTrailers()` — detects Trailers/ folders at scan time
  - `FolderWatcher::shouldRescanExtras()` — triggers extras rescan on change
  - Unit tests in `tests/unit/Media/Extras/` (15 tests)
  - Integration test `tests/integration/Media/Extras/TrailerScannerTest.php`
  - `docs/developers/trailers-and-extras.md` — naming conventions, API reference, architecture

### Added (Step H.4)

- Trakt.tv scrobble plugin with two-way history sync. Includes:
  - `TraktApi` — OAuth2 PKCE client, scrobble start/pause/stop, history sync
  - `TraktSettings` — per-user settings (tokens, sync prefs, username)
  - `TraktPlugin` — LifecycleInterface entry, subscribes to PlaybackStarted/Stopped/ProgressUpdated
  - `TraktHistorySync` — syncTraktToPhlex() (pull on schedule) and syncPhlexToTrakt() (push on ≥90% completion)
  - `TraktOAuthController` — OAuth callback at GET /api/v1/oauth/trakt/callback
  - `config/scrobblers/trakt.php` — client_id, client_secret, redirect_uri, sync_interval
  - `phlex-plugin-trakt/plugin.json` — scrobbler plugin manifest
  - Unit tests (19 tests across TraktApi, TraktSettings, TraktHistorySync, TraktPlugin)
  - `docs/developers/scrobbler-plugins.md` — scrobbler plugin author guide
- New Router method `traktAuth()` for Trakt OAuth routes

### Added (Step H.3)

- Custom CSS / themes with `ui-theme` plugin type. Includes:
  - `Theme` — readonly theme descriptor (id, name, type, cssUrl, jsUrl,
    thumbnailUrl, version, pluginName, dark).
  - `ThemeRegistry` — central registry with registerBuiltIn(), registerFromPlugin(),
    getTheme(), getAllThemes(), getActiveThemeForUser(), setActiveThemeForUser().
  - `ThemeMiddleware` — HTTP middleware that injects theme CSS/JS into WebPortal
    responses via str_replace on Smarty placeholders.
  - `ThemePluginInterface` — marker interface for ui-theme plugin entry classes.
  - `ThemePreviewController` — renders live theme preview in iframe sandbox at
    GET /portal/theme-preview?id={themeId}.
  - `config/themes.php` — 4 built-in themes (phlex-dark, phlex-light,
    phlex-amoled, phlex-contrast) with CSS and thumbnail assets.
  - Migration `migrations/006_user_theme_settings.sql` — adds active_theme_id
    to user_profiles.
  - UserProfileManager::getActiveThemeId() / setActiveThemeId() for per-profile
    theme preferences.
  - `{$theme_css|raw}` and `{$theme_js|raw}` Smarty placeholders in base.tpl.
  - `var/themes/` runtime directory for extracted plugin themes (gitignored).
  - Unit tests in `tests/unit/Theming/` (ThemeRegistryTest, ThemeMiddlewareTest — 11 tests).
  - `docs/developers/ui-themes.md` — plugin author guide with CSS variable reference.

### Added (Step H.2)

- Collections — named groups of media items for manual curation
  (bulk-add from search) and rule-based auto-population via smart playlists.
  Includes:
  - `Collection` — readonly entity with id, name, libraryId, smartPlaylistId,
    parentId, sortOrder, timestamps.
  - `CollectionWithItems` — hydrated DTO with collection + hydrated media items.
  - `CollectionRepository` — full CRUD for collections table with parameterized
    Workerman\MySQL\Connection queries.
  - `CollectionItemRepository` — membership CRUD for collection_items with
    sort order support.
  - `CollectionManager` — orchestrator with addItem(), removeItem(),
    bulkAddFromSearch(), getCollectionWithItems(), refreshSmartCollection().
  - `CollectionController` — 9 REST API endpoints:
    GET/POST /api/v1/collections, GET/PUT/DELETE /api/v1/collections/{id},
    POST/DELETE /api/v1/collections/{id}/items/{mediaItemId},
    POST /api/v1/collections/{id}/bulk-add,
    POST /api/v1/collections/{id}/refresh,
    GET /api/v1/libraries/{libraryId}/collections.
  - Migration `migrations/005_collections.sql` — creates collections and
    collection_items tables with proper indexes.
  - Unit tests in `tests/unit/Collections/` (CollectionRepositoryTest,
    CollectionItemRepositoryTest, CollectionManagerTest — 14 tests).
  - Integration test `tests/integration/Collections/CollectionCrudTest.php`.
  - `docs/developers/collections.md` — model, API reference, smart sync
    algorithm, integration guide.
  - `Router::collections()` — registers collection routes.
  - `SmartPlaylistRefreshHandler` now calls CollectionManager::refreshSmartCollection()
    for any collection linked to a changed smart playlist.

### Added (Step H.1)

- Smart-playlist rule engine with JSON DSL evaluation at scan time and
  on folder-watch events. Includes:
  - `RuleNode` — immutable AST node (TYPE_AND/OR/NOT/RULE) for rule trees.
  - `RuleOperators` — 11 static operator methods (equals, notEquals, contains,
    notContains, greaterThan, lessThan, between, in, notIn, startsWith, endsWith).
  - `SmartPlaylistEngine` — buildFromDsl(), evaluate(), evaluateOnScan(), toJson()
    for parsing JSON DSL and evaluating media items against rules.
  - `SmartPlaylist` — readonly entity with id, name, libraryId, rulesJson, limit,
    sortBy, sortDesc, timestamps.
  - `SmartPlaylistRepository` — full CRUD for smart_playlists table with
    parameterized Workerman\MySQL\Connection queries.
  - `SmartPlaylistRefreshHandler` — listens to LibraryUpdated events and
    re-evaluates all smart playlists for the changed library.
  - `SmartPlaylistController` — REST API endpoints:
    GET/POST/PUT/DELETE /api/v1/smart-playlists, POST /api/v1/smart-playlists/{id}/preview.
  - `LibraryUpdated` event dispatched by FolderWatcher on content changes.
  - Migration `migrations/004_smart_playlists.sql` — creates smart_playlists table
    with JSON rules column, limit, sort_by, sort_desc fields.
  - Unit tests in `tests/unit/Playlists/` (RuleNodeTest, RuleOperatorsTest,
    SmartPlaylistEngineTest, SmartPlaylistRepositoryTest, SmartPlaylistTest).
  - Integration test `tests/integration/Playlists/SmartPlaylistRefreshTest.php`.
  - `docs/developers/smart-playlists.md` — DSL reference, operator list,
    evaluation algorithm, extension guide.
  - `Router::smartPlaylists()` — registers smart playlist routes.
  - `FolderWatcher` now injects EventDispatcherInterface and dispatches
    LibraryUpdated events when changes are detected.
  - MediaServicesProvider registers SmartPlaylistEngine, SmartPlaylistRepository,
    SmartPlaylistRefreshHandler, SmartPlaylistController.

### Added (Step G.6)

- `AudiobookProgress` — Value object for per-user audiobook progress tracking.
  Immutable with position_ms, current_chapter_index, completed_chapters array,
  percent_complete, and last_position_ms for chapter-resume support.
- `AudiobookProgressStore` — Persistence layer using Workerman MySQL for
  audiobook_progress table. Supports getProgress(), saveProgress(), and
  markChapterComplete() operations with composite PK (user_id, audiobook_id).
- `AudiobookScanner` — Extends BookScanner for audiobook-specific scanning.
  - `harvestChapters()` — Pure-PHP M4B chapter extraction via MP4 chpl atom
    parsing (binary string scanning, no external dependencies). Handles 64-bit
    duration values.
  - Returns chapters as metadata_json array with title, start_ms, end_ms,
    and duration_ms fields.
- `AudiobookLibraryManager` — Extends BookLibraryManager for audiobook
  libraries. Orchestrates scanning and progress management. Methods:
  getProgress(), saveProgress(), markChapterComplete(), chapterDuration().
- `AudiobookController` — REST API endpoints for audiobooks:
  - `GET /api/v1/audiobooks` — List audiobooks with pagination
  - `GET /api/v1/audiobooks/{id}` — Get audiobook details with chapters
  - `GET /api/v1/audiobooks/{id}/chapters` — List chapters for an audiobook
  - `GET /api/v1/audiobooks/{id}/progress` — Get user's progress for an audiobook
  - `POST /api/v1/audiobooks/{id}/progress` — Save progress (position, chapter)
  - `GET /api/v1/audiobooks/{id}/stream` — Stream audiobook (chapter + offset)
- `AudiobookLibraryType` — Library type plugin with type `'audiobook'`.
  Returns AudiobookScanner and AudiobookLibraryManager instances.
- Migration `012_audiobook_progress.sql` — Creates audiobook_progress table
  with user_id, audiobook_id, position_ms, current_chapter_index,
  completed_chapters (JSON), percent_complete, last_position_ms, created_at,
  updated_at.
- Smarty templates: `audiobooks/audiobooks.tpl`, `audiobooks/audiobook.tpl`,
  `player/player.tpl`, `audiobooks/partials/audiobook_card.tpl`,
  `audiobooks/partials/chapter_row.tpl` — Audiobook grid view, detail with
  chapter navigation, audio player UI, and chapter list component.
- `public/assets/css/audiobooks.css` — Player styles (play/pause, seek bar,
  volume, chapter list) and grid layout with cover cards.
- `public/assets/js/audiobook-player.js` — Chapter navigation, progress
  persistence every 10 seconds, chapter completion tracking, play/pause controls.
- `docs/libraries/audiobooks.md` — Documentation for supported formats (M4B,
  M4A, MP3), chapter navigation, progress persistence, and streaming.
- Unit tests: AudiobookScannerTest (8 tests), AudiobookProgressStoreTest
  (4 tests), AudiobookLibraryManagerTest (4 tests), AudiobookControllerTest
  (9 tests).
- Router now registers `/api/v1/audiobooks/*` routes.
- LibraryManager routes `'audiobook'` type libraries through AudiobookScanner.

### Added (Step G.5)

- `BookScanner` — Pure-PHP book file scanner for EPUB, PDF, and CBZ formats.
  - `harvestEpub()` — parses EPUB container.xml and content.opf for Dublin Core
    metadata (title, author, publisher, ISBN, language, pub_date, description) and
    extracts cover images.
  - `harvestPdf()` — uses `exif_read_data()` for XMP/EXIF metadata and pure-PHP
    page count extraction.
  - `harvestCbz()` — parses ComicInfo.xml for extended metadata (series, volume,
    authors, page_count) and extracts cover images from ZIP archive.
  - `scanBookLibrary()` — generator that yields book item arrays with metadata.
- `BookLibraryManager` — orchestrates book library scanning, metadata extraction,
  and upsert. Implements `rescanLibrary()` for full pipeline and `upsertBook()`
  for single-file processing.
- `BookLibraryType` — Library type plugin implementing `LibraryTypeInterface`
  with type `'book'`. Returns `BookScanner` and `BookLibraryManager` instances.
- `OpdsFeedBuilder` — builds OPDS 1.2 compliant XML feeds using `DOMDocument`.
  - `buildRootFeed()` — root catalog with links to libraries.
  - `buildNavigationFeed()` — navigation feed listing book libraries.
  - `buildAcquisitionFeed()` — acquisition feed with pagination (?offset=N&limit=N).
  - `buildEntry()` — individual book entries with dc:title, dc:creator,
    opds:link rel=acquisition.
- `BookController` — REST API endpoints for books and OPDS:
  - OPDS: `GET /opds/v1.2`, `GET /opds/v1.2/libraries`, `GET /opds/v1.2/libraries/{id}`
  - Web portal: `GET /books`, `GET /books/{id}`, `GET /books/{id}/cover`,
    `GET /books/{id}/read`, `GET /books/{id}/download`
- Smarty templates: `books/books.tpl`, `books/book.tpl`, `books/reader.tpl`,
  `books/partials/book_card.tpl` — book grid view, book detail with cover
  and metadata, minimal reader stub, book card component.
- `public/assets/css/books.css` — styles for book grid, cover cards,
  reader layout, and theme support (light/sepia/dark).
- `public/assets/js/reader.js` — reader controller with font size controls,
  theme switching, keyboard navigation (←/→).
- `docs/libraries/books.md` — documentation for supported formats, OPDS feed URL,
  third-party client setup (Uboiquity, Komga, Kore, Moon+ Reader), naming
  conventions, metadata fields, reader stub limitations.
- `docs/reference/api.md` — updated with OPDS endpoints and Books API.
- Unit tests: `BookScannerTest` (8 tests), `BookLibraryManagerTest` (2 tests),
  `OpdsFeedBuilderTest` (5 tests), `BookControllerTest` (7 tests).
- Router now registers `/opds/*` and `/books/*` routes.
- LibraryManager routes `'book'` type libraries through BookScanner.
- WebPortalRouter now registers `/books` and `/books/{id}` routes.
- `public/templates/partials/header.tpl` — Added Books nav link.
- LibraryController accepts `'book'` as a valid library type.

### Added (Step G.4)

- `PhotoScanner` — Pure-PHP photo file scanner with EXIF metadata extraction.
  Uses PHP's built-in `exif_read_data()` for JPEG files; graceful fallback
  for PNG/TIFF/WebP/HEIC. Extracts camera_make, camera_model, lens,
  aperture, iso, shutter_speed, focal_length, width, height, orientation,
  date_taken_unix, gps_lat, gps_lng, gps_alt.
- `PhotoLibraryManager` — Orchestrates photo library scanning, EXIF extraction,
  and metadata upsert. Implements `rescanLibrary()` for full pipeline and
  `upsertPhoto()` for single-file processing.
- `PhotoLibraryType` — Library type plugin implementing `LibraryTypeInterface`
  with type `'photo'`. Returns `PhotoScanner` and `PhotoLibraryManager` instances.
- `ExifProvider` — Local EXIF metadata provider that reads from `metadata_json`
  stored on media items. Implements `MetadataProviderInterface`.
- `PhotoController` — REST API endpoints for photo browsing and slideshow:
  - `GET /photo/albums` — list all albums (grouped by date)
  - `GET /photo/albums/{id}` — get specific album with photos
  - `GET /photo/photos` — list all photos
  - `GET /photo/photos/{id}` — photo with full EXIF data
  - `GET /photo/photos/{id}/thumbnail?w=300&h=300&fit=cover` — resized thumbnail
  - `GET /photo/photos/{id}/full` — full-resolution photo
  - `GET /photo/slideshow?album_id=xxx&interval=5` — slideshow data
- Smarty templates: `photo/albums.tpl`, `photo/album.tpl`, `photo/photo.tpl`,
  `photo/slideshow.tpl`, `photo/partials/exif_panel.tpl`,
  `photo/partials/photo_card.tpl` — album grid, photo grid, lightbox view,
  fullscreen slideshow player, EXIF data sidebar.
- `public/assets/css/photo.css` — Styles for album grid, photo grid,
  lightbox, EXIF sidebar, slideshow player.
- `public/assets/js/slideshow.js` — Slideshow controller with auto-advance
  interval, keyboard nav (←/→/Space/Escape), touch/swipe support.
- `docs/libraries/photos.md` — Documentation for supported formats, EXIF
  fields, album organization, API endpoints, thumbnail generation,
  slideshow features, and deferred geotag clustering note.
- Unit tests: `PhotoScannerTest` (12 tests), `PhotoLibraryManagerTest`
  (6 tests), `PhotoControllerTest` (11 tests).
- Router now registers `/photo/*` routes pointing to `PhotoController`.
- LibraryManager routes `'photo'` type libraries through `PhotoLibraryManager`.
- `public/templates/layouts/main.tpl` — Added Photos nav link.

### Added (Step G.3)

- `Phlex\Plugins\Lastfm\Plugin` — In-core Last.fm scrobbler plugin
  implementing the `scrobbler` plugin type. Subscribes to
  `phlex.playback.started` (Now Playing updates) and
  `phlex.playback.stopped` (scrobble submission). Off by default;
  configure `config/lastfm.php` with API credentials to enable.
- `Phlex\Plugins\Lastfm\LastfmApiClient` — Last.fm API v1.2 client
  with HMAC-MD5 signing. Supports `auth.getMobileSession`,
  `track.scrobble`, and `track.updateNowPlaying` endpoints.
- `Phlex\Plugins\Lastfm\ScrobbleData` — Immutable value object for
  scrobble submission (artist, track, timestamp, album, duration,
  MusicBrainz ID).
- `Phlex\Plugins\Lastfm\NowPlayingData` — Immutable value object for
  Now Playing notifications.
- `Phlex\Plugins\Lastfm\LastfmPluginNotConfiguredException` — Thrown
  when API key, secret, or session key is missing.
- `Phlex\Plugins\Lastfm\LastfmScrobbleFailedException` — Thrown when
  Last.fm API returns an error on scrobble/Now Playing.
- `config/lastfm.php` — Default configuration with `enabled` (default
  false), `api_key`, `api_secret`, `session_key`, `username`,
  `submit_now_playing` (default true), and `scrobble_threshold`
  (default 0.5 — scrobble after 50% of track).
- `docs/plugins/developer-guide.md` — Added §14 documenting the
  `scrobbler` plugin type with Last.fm as the reference example.
- `docs/developers/lastfm-plugin.md` — New developer guide covering
  Last.fm API protocol, HMAC-MD5 signing, mobile auth flow,
  scrobble threshold semantics, and full configuration reference.
- Unit tests: `LastfmApiClientTest` (11 tests), `PluginTest` (9 tests).

### Added (Step G.2)

- `AudioScanner` — Pure-PHP audio file scanner with ID3v2 (MP3), Vorbis
  Comment (FLAC/OGG), and MP4 atom (M4A/AAC) tag harvesting. No external
  dependencies required. Never throws; returns partial results on best
  effort.
- `MusicLibraryManager` — Orchestrates music library scanning, tag harvest,
  and metadata enrichment via `MetadataManager`. Implements `rescanLibrary()` for
  full pipeline and `upsertTrack()` for single-file processing.
- `MusicLibraryType` — Library type plugin implementing `LibraryTypeInterface`
  with type `'music'`. Returns `AudioScanner` and `MusicLibraryManager` instances.
- `LibraryTypeInterface` — New interface for library type plugins, allowing
  type-specific scanner and manager instances.
- `MusicController` — REST API endpoints for music browsing:
  - `GET /music/artists` — list all artists
  - `GET /music/artists/{mbid}` — artist detail with albums
  - `GET /music/albums` — list all albums
  - `GET /music/albums/{mbid}` — album detail with tracks
  - `GET /music/tracks` — list all tracks (paginated)
  - `GET /music/tracks/{id}` — single track
  - `GET /music/now-playing` — current playback state
- `Router::music()` — Registers `/music/*` routes pointing to `MusicController`.
- `WebPortalRouter` — Added `/music`, `/music/artists`, `/music/albums`,
  `/music/tracks`, `/music/player` web portal routes.
- Smarty templates — `music/artists.tpl`, `music/artist.tpl`,
  `music/albums.tpl`, `music/album.tpl`, `music/tracks.tpl`,
  `music/player.tpl`, `music/partials/music_card.tpl`.
- `public/assets/css/music.css` — Styles for artist grid, album grid,
  track list, and player bar.
- `public/assets/js/music-player.js` — Music player JavaScript with play,
  pause, seek, next/prev, shuffle, repeat, and queue management.
- `migrations/011_music_library.sql` — Adds 'track' to media_items type enum,
  adds indexes for library_type, artist, album, and genre queries.
- `docs/libraries/music.md` — Developer documentation covering supported
  formats, tag field mapping, naming conventions, scan behavior, and API.
- Unit tests: `AudioScannerTest` (8 tests), `MusicLibraryManagerTest` (8 tests),
  `MusicControllerTest` (13 tests).

### Added (Step G.1)

- `MusicBrainzProvider` — MusicBrainz API v2 metadata provider implementing
  `MetadataProviderInterface`. Supports artist, album, and track search and
  detail retrieval with MusicBrainz-required User-Agent headers and 1 req/sec
  rate limiting via `MusicMetadataProviderTrait`.
- `AudioDbProvider` — AudioDB API v1 metadata provider implementing
  `MetadataProviderInterface`. Supports artist, album, and track search and
  detail retrieval. Degrades gracefully when no API key is configured.
- `MusicMetadataProviderTrait` — shared trait for music providers with
  `rateLimit()` for enforcing request delays and `mbHeaders()` for
  MusicBrainz-required headers.
- `MetadataProviderInterface` — added `MEDIA_TYPE_ALBUM`, `MEDIA_TYPE_ARTIST`,
  `MEDIA_TYPE_TRACK` constants and `getSourceName()` method.
- `MetadataHttpClient` — extended `get()` method to accept optional `$headers`
  parameter for custom request headers.
- `MetadataManager` — updated provider priority to include `audiodb` as fallback
  for music types; added `track` media type support.
- `config/music_providers.php` — new config file with MusicBrainz and AudioDB
  provider settings (rate limits, user-agent, API key, fallback behavior).
- `docs/developers/music-providers.md` — developer documentation covering
  provider architecture, configuration keys, MusicBrainz rate-limit requirements,
  and guide for adding third-party providers.
- Unit tests: `MusicBrainzProviderTest` (10 tests), `AudioDbProviderTest`
  (11 tests) with ≥85% coverage on both providers.

### Added (Step F.5)

- `ComskipRunner` — detects and runs the comskip binary on Live TV recordings;
  `isAvailable()` checks if the binary exists and is executable, `run()` executes
  comskip with a 5-minute timeout and returns the path to the generated .edl file.
- `ComskipEdlParser` — parses comskip EDL (Edit Decision List) files with 3-column
  tab-separated format (start_seconds, end_seconds, scene_type); filters segments
  shorter than `min_commercial_length`; converts to `ChapterMarker[]` DTOs.
- `ComskipPostProcessor` — orchestrator that runs comskip after a recording
  completes, parses the EDL, and stores chapters via `MarkerService::storeChapters()`.
  Idempotent — skips recordings that already have chapters.
- `RecordingHooks::register()` — wires `ComskipPostProcessor` into the `Recorder`
  via the new `onComplete()` callback hook.
- `Recorder::onComplete()` — registers callbacks to fire after a recording stops
  with status COMPLETED; callbacks receive `(string $mediaItemId, string $recordingPath)`.
- `MarkerService::storeChapters()` — persists `ChapterMarker[]` arrays to
  `chapters_json` column via `ItemRepository::updateMarkers()`.
- `config/comskip.php` — comskip binary path, `min_commercial_length` (30s),
  `require_confidence` (0.7), `post_process_immediately` flag, and `edl_output_dir`.
- `docs/advanced/live-tv-comskip.md` — user-facing documentation covering
  comskip installation, configuration, EDL format, and troubleshooting.
- Unit tests: `ComskipRunnerTest` (6 tests), `ComskipEdlParserTest` (12 tests),
  `ComskipPostProcessorTest` (6 tests).

### Added (Step F.4)

- `SkipButtonSpec` — immutable value object with `toArray()` serialization and
  `fromMarkerSet()` factory for client-facing JSON.
- `PlaybackMarkerService` — provides `getFullSpec()` and `getSkipSpec(id, position_ticks)`
  to return position-aware skip button specs.
- `WebPortalRouter::getPlaybackInfo()` — embeds `markers` key with
  `skip_intro_start`, `skip_intro_end`, `skip_outro_start`, `skip_outro_end`
  in the playback info response.
- `docs/reference/skip-button-protocol.md` — full protocol specification for
  client teams implementing skip button UI.
- `docs/clients/skip-button-integration-brief.md` — concise hand-off brief
  for Phase M client integration.
- `docs/reference/api.md` — updated with `GET /api/v1/media/{id}/playback`
  endpoint documentation including `markers` key.
- Unit tests: `SkipButtonSpecTest` (4 tests), `PlaybackMarkerServiceTest` (4 tests).

### Added (Step F.3)

- Marker storage columns and GET API for chapters, intro, and outro markers.
- `migrations/003_marker_columns.sql` — adds `intro_start_seconds`,
  `intro_end_seconds`, `outro_start_seconds`, `outro_end_seconds`,
  `chapters_json` columns to `media_items` table.
- `IntroMarker` / `OutroMarker` / `ChapterMarker` — immutable DTOs for marker
  segments with start/end times, confidence, and optional title.
- `MarkerSet` — aggregate DTO containing intro, outro, and chapters array with
  `hasMarkers()` and `toArray()` methods.
- `MarkerService` — service for reading/promoting markers; reads formal columns
  first, falls back to `metadata_json` candidates; exposes `getMarkers()`,
  `promoteCandidates()`, `promoteShowMarkers()`, and `getShowMarkers()`.
- `MarkerController` — HTTP controller with 4 GET endpoints:
  - `GET /api/v1/media/{id}/markers` — all markers for an item
  - `GET /api/v1/media/{id}/markers/intro` — intro marker only
  - `GET /api/v1/media/{id}/markers/outro` — outro marker only
  - `GET /api/v1/shows/{id}/markers/bulk` — all episode markers for a show
- `Router::markers()` — registers the 4 marker routes.
- `ItemRepository` — added `getIntroMarker()`, `getOutroMarker()`,
  `getChapters()`, and `updateMarkers()` methods for marker column access.
- `docs/reference/api.md` — API reference documentation for marker endpoints.
- Unit tests: `MarkerSetTest` (10 tests), `MarkerServiceTest` (9 tests),
  `MarkerControllerTest` (10 tests).

### Added (Step F.2)

- Intro/outro detection background job system using audio fingerprint clustering.
- `FingerprintClusterer` — Jaccard similarity-based clustering to detect shared
  intro/outro segments across episodes using audio fingerprints.
- `IntroDetectionJob` — orchestrates detection for all episodes of a TV show,
  clusters fingerprints, returns marker candidates.
- `IntroMarkerCandidate` / `OutroMarkerCandidate` — immutable DTOs for detected
  intro/outro segments with start/end times, fingerprint, and confidence score.
- `IntroDetectionResult` — result container for show-level detection results.
- `ClusteringResult` — result container for fingerprint clustering output.
- `StoredMarkers` — parses stored marker candidates from episode metadata.
- `MarkerCandidateRepository` — persists intro/outro candidates to
  `media_items.metadata_json` for consumption by F.3 API.
- `MarkerCandidateStore` — file-based job queue (`/tmp/phlex_marker_jobs/`)
  with one lock file per show being processed.
- `BackgroundDetectorWorker` — queue consumer loop that processes detection
  jobs continuously.
- `scripts/run-marker-detection-worker.php` — CLI entry point for running
  the background worker.
- `config/marker_detection.php` — configuration for intro/max duration,
  similarity threshold (0.85), minimum episodes (3), worker interval.
- `docs/developers/intro-outro-detection.md` — developer documentation
  covering the clustering algorithm, configuration, and usage.
- Unit tests: `IntroDetectionJobTest` (5 tests), `FingerprintClustererTest`
  (12 tests), `MarkerCandidateStoreTest` (10 tests),
  `MarkerCandidateRepositoryTest` (5 tests).

### Added (Step E.6)

- Subtitle burn-in (hardsubbing) pipeline for embedding subtitles directly
  in the video stream — required for players/devices that don't support
  external subtitle tracks (many smart TVs, game consoles, some mobile browsers).
- `SubtitleFormat` — enum with SRT, ASS, SSA, VTT, HDMV formats plus
  `getFfmpegFormat()` and `supportsFontstyle()` methods.
- `SubtitleTrack` — immutable value object with stream index, language code,
  display label, format, and file path.
- `SubtitleStyleOptions` — value object for burn-in styling (font, size,
  primary/outline colors, outline thickness, position, margin) with
  `toAssStyle()` and `toSrtStyle()` methods.
- `SubtitleBurner` — core class for subtitle stream detection, extraction,
  and FFmpeg filter graph generation for burn-in across all vendors.
- `SubtitleBurnerFactory` — factory for creating vendor-specific burners.
- `HwaccelCommandBuilder` — added `setSubtitleTrack()`, `setSubtitleStyle()`,
  and `setSubtitleBurner()` methods; integrates subtitle burn-in filter
  args into hardware transcoding commands.
- `StreamManager` — added `setSubtitleBurnIn()` and `getSubtitleBurnInConfig()`
  methods for configuring subtitle burn-in per streaming session.
- `StreamState` — added `subtitleBurnInIndex` and `forceSubtitleBurnIn` properties.
- `config/subtitles.php` — subtitle configuration with `enabled`, `default_language`,
  `burn_in_by_default`, `extract_to_dir`, and `style` options.
- `config/ffmpeg.php` — added `subtitles` key referencing `config/subtitles.php`.
- `docs/developers/subtitle-processing.md` — developer documentation covering
  soft vs. hard subtitling, vendor burn-in support matrix, styling reference,
  and usage examples.
- Unit tests: `SubtitleFormatTest` (11 tests), `SubtitleTrackTest` (4 tests),
  `SubtitleStyleOptionsTest` (6 tests), `SubtitleBurnerTest` (13 tests).

### Added (Step E.5)

- Trickplay (thumbnail seek / scrub preview) support for video progress bar
  hover preview using DASH-IF / HLS spec-compliant "BIF" (Bitmap Image Format)
  thumbnail grids.
- `TrickplayConfig` — value object with grid dimensions (8×4), thumbnail size
  (160×90px), interval (10s), image format (JPEG/PNG), and quality settings.
- `TrickplayResult` — result container with job ID, interval, grid dimensions,
  image file metadata (byte offsets for byte-range requests), and BIF index XML
  path.
- `TrickplayGenerator` — extracts frames at fixed intervals using FFmpeg batch
  extraction (`generateThumbnailBatch`), assembles frames into grid images via
  FFmpeg `tile` filter, generates BIF index XML with offset/length per thumbnail.
- `TrickplayController` — HTTP handler serving thumbnail grid images and BIF
  index XML with correct `Content-Type` headers.
- `StreamManager` — added `setTrickplay()` and `generateTrickplay()` methods,
  `TrickplayGenerator` and `TrickplayController` properties, and
  `getTrickplayController()` getter.
- `FfmpegRunner` — extended `generateThumbnail()` to accept `int|array` for
  batch extraction, added `generateThumbnailBatch()` for multiple timestamps in
  one command, added `getFfmpegPath()` accessor.
- `Router` — added `trickplay()` route registration for
  `GET /trickplay/{jobId}/thumb-{index}.jpg` and `GET /trickplay/{jobId}/index.xml`.
- `config/trickplay.php` — trickplay configuration with `enabled`, `interval_seconds`,
  `grid_columns`, `grid_rows`, `thumb_width`, `thumb_height`, `image_format`,
  `jpeg_quality`, `storage_dir`.
- `docs/developers/streaming-protocols.md` — added "Trickplay / Thumbnail Seek"
  section documenting BIF format, generation pipeline, configuration, and
  client-side usage.
- Unit tests: `TrickplayConfigTest` (15 tests), `TrickplayResultTest` (9 tests),
  `TrickplayGeneratorTest` (8 tests), `TrickplayControllerTest` (10 tests).

### Added (Step E.4)

- DASH (Dynamic Adaptive Streaming over HTTP) streaming support alongside
  existing HLS implementation.
- `DashStreamer` — DASH manifest generator and segment manager producing
  DASH-IF compliant MPD manifests with SegmentTemplate elements.
- `SegmentTemplate` — value object for DASH segment template handling
  (SegmentTemplate vs. SegmentList for efficient live streaming).
- `AdaptationSet` — value object representing DASH adaptation sets
  (video, audio, text) with codec/bandwidth metadata.
- `DashController` — HTTP endpoints for DASH streaming:
  `GET /dash/{jobId}/manifest.mpd`, `GET /dash/{jobId}/{setId}/manifest.mpd`,
  `GET /dash/{jobId}/{setId}/segment_{n}.m4s`.
- `config/dash.php` — DASH-specific configuration with `enabled`,
  `manifest_refresh_seconds`, `min_buffer_time`, `min_buffer_time_live`,
  `time_shift_buffer_depth`, `default_codecs`.
- `config/ffmpeg.php` — added `dash` key with `enabled`, `segment_dir`,
  `default_codecs`.
- `HlsStreamer` — added `setSegmentContent()` method so segment writer
  can store once and both HLS and DASH streamers reference the same files.
- `StreamManager` — added `DashStreamer` property and `getManifestUrl()`
  method returning HLS or DASH manifest URL based on `$protocol` parameter.
- `Router` — added `dashStreaming()` route registration method.
- `docs/developers/streaming-protocols.md` — documentation covering HLS
  vs. DASH tradeoffs, manifest structure, client-side selection, and usage.
- Unit tests: `DashStreamerTest` (11 tests), `SegmentTemplateTest` (7 tests),
  `AdaptationSetTest` (8 tests).

### Added (Step E.1)

- Hardware acceleration probe system for detecting GPU encoders (NVENC,
  VAAPI, QSV, VideoToolbox, AMF, V4L2) at startup.
- `HwaccelCapability` — immutable value object representing hardware
  encoder capabilities (vendor, encoder/decoder names, supported codecs,
  HDR tone mapping support, resolution/bitrate limits).
- `HwaccelProbe` — runs vendor-specific probes via `ffmpeg -encoders`
  and `ffmpeg -decoders`, aggregates results into a capability map.
- `HwaccelRegistry` — lazy singleton holding probed capabilities;
  `getEncoder()` / `getDecoder()` use vendor priority for best-match
  selection.
- `VendorProbeInterface` + 7 concrete implementations:
  `NvencProbe`, `VaapiProbe`, `QsvProbe`, `VideoToolboxProbe`,
  `AmfProbe`, `V4L2Probe`, `SoftwareProbe` (always-available fallback).
- `config/hwaccel.php` — `enabled`, `prefer_hardware`,
  `vendor_priority`, `probe_timeout`, `test_clip_path`,
  `fallback_to_software` configuration.
- `config/ffmpeg.php` — added `hwaccel` key with `enabled`,
  `prefer_hardware`, `vendor_priority`.
- `FfmpegRunner` — added `HwaccelRegistry` property and
  `probeHardwareAcceleration()` + `buildHwaccelCommand()` methods.
- `docs/developers/hardware-acceleration.md` — architecture overview,
  capability fields, usage examples, and guide for adding new vendors.
- Unit tests: `HwaccelCapabilityTest` (6 tests),
  `HwaccelProbeTest` (9 tests), `HwaccelRegistryTest` (8 tests).
- No user-visible behavior change yet — transcode remains software-only
  until Step E.2 integrates hardware encoding into TranscodeManager.

### Added (Step D.5)

- Hub-side invite-link sharing (D.5). Invite links are generated on
  the hub and grant library access to recipients. Server-side is unchanged;
  library shares are synced via the existing hub heartbeat mechanism.

### Added (Step D.4)

- First-class passkey / WebAuthn support for passwordless login.
  Supports platform authenticators (Touch ID, Windows Hello, Face ID)
  and roaming FIDO2 tokens (YubiKey, etc.).
- `src/Auth/WebAuthn/WebAuthnManager` — orchestrates registration and
  authentication ceremonies; generates cryptographically random
  challenges; validates attestation and assertions.
- `src/Auth/WebAuthn/WebAuthnCredential` — entity for stored credentials
  with VARBINARY credential ID, sign counter, and device metadata.
- `src/Auth/WebAuthn/WebAuthnSettings` — RP configuration (ID, name,
  origin, attestation requirement).
- `src/Auth/WebAuthn/WebAuthnCredentialRepository` — data access for
  `webauthn_credentials` table; implements replay attack detection via
  sign counter validation.
- `src/Auth/WebAuthnProvider` — implements `ProviderInterface` for
  WebAuthn as an auth provider alongside OIDC/LDAP.
- `src/Server/Http/Controllers/WebAuthnController` — HTTP API with
  6 endpoints for registration, authentication, and credential
  management.
- Database migration `migrations/010_webauthn_credentials.sql` —
  creates `webauthn_credentials` table with VARBINARY credential_id
  and foreign key to users.
- Smarty template `public/templates/auth/webauthn-settings.tpl` —
  user-facing passkey management UI.
- Routes wired in `Application::loadApiRoutes()`:
  `POST /api/v1/auth/webauthn/register/options`,
  `POST /api/v1/auth/webauthn/register/verify`,
  `POST /api/v1/auth/webauthn/login/options`,
  `POST /api/v1/auth/webauthn/login/verify`,
  `GET /api/v1/me/webauthn/credentials`,
  `DELETE /api/v1/me/webauthn/credentials/{id}`.
- Composer dependency added: `web-auth/webauthn-lib: ^4.0`.
- Unit tests in `tests/unit/Auth/WebAuthn/`: `WebAuthnManagerTest`,
  `WebAuthnCredentialTest`, `WebAuthnControllerTest`,
  `WebAuthnProviderTest`.
- Documentation:
  - `docs/plugins/auth-providers.md` — passkeys section added.
  - `docs/reference/api/auth-webauthn.md` — new API endpoint reference.
  - `docs/security/passkeys.md` — user-facing passkey guide.

### Added (Step D.3)

- `phlex-plugin-ldap` — LDAP authentication provider plugin.
  Supports OpenLDAP and Active Directory via the LDAP protocol.
  Includes:
  - `LdapProvider` — implements `ProviderInterface` with bind
    authentication and user attribute mapping.
  - `LdapConnection` — wraps `directorytree/ldaprecord` Connection
    with request-scoped caching per host:port:ssl triple.
  - `UserMapper` — maps LDAP attributes to Phlex user fields
    (uid/sAMAccountName → username, mail → email, displayname/cn →
    display name, jpegPhoto/thumbnailPhoto → avatar_url).
  - `LdapUserInfo` — LDAP-specific user info carrier.
  - `LdapAdminController` — admin API for LDAP settings management
    and test-connection action.
  - Smarty settings form at `templates/ldap-settings.tpl`.
- Routes wired in `AdminRoutes`:
  `GET /api/v1/admin/auth-providers/ldap/config`,
  `POST /api/v1/admin/auth-providers/ldap/config`,
  `POST /api/v1/admin/auth-providers/ldap/test`,
  `GET /api/v1/admin/auth-providers/ldap/schema`.
- Composer dependency added: `directorytree/ldaprecord: ^3.0`.

### Added (Step D.2)

- `phlex-plugin-oidc` — OIDC/OAuth2 authentication provider plugin.
  Supports any OIDC-compliant identity provider (Authelia, Authentik,
  Keycloak, Google, GitHub). Includes:
  - `OidcProvider` — implements `ProviderInterface` with authorization
    code flow and direct API token authentication.
  - `DiscoveryDocument` — cached OIDC discovery document (24 h TTL).
  - `IdTokenValidator` — RS256/RS384/RS512 token validation with
    cached JWKS.
  - `OidcCallbackController` — handles `/auth/oidc/authorize` and
    `/auth/oidc/callback` routes.
  - `OidcAdminController` — admin API for OIDC settings management.
  - Smarty settings form at `templates/oidc-settings.tpl`.
- Routes wired in `Router::oidcAuth()`:
  `GET /auth/oidc/authorize`, `GET /auth/oidc/callback`.
- Admin routes in `AdminRoutes`:
  `GET /api/v1/admin/auth-providers/oidc/config`,
  `POST /api/v1/admin/auth-providers/oidc/config`,
  `GET /api/v1/admin/auth-providers/oidc/schema`.
- Composer dependencies added: `web-token/jwt-framework: ^3.0`,
  `phpseclib/phpseclib: ^3.0`.

### Added (Step D.1)

- `Phlex\Auth\AuthProviderRegistry` — singleton registry holding
  registered {@see \Phlex\Auth\ProviderInterface} instances; resolves
  provider-prefixed usernames to the correct external provider.
- `Phlex\Auth\ProviderManager` — bridges {@see AuthManager} to the
  registry; handles `provider:username` parsing and delegates to either
  an external provider or the standard password-based flow.
- `Phlex\Auth\AuthProviderNotFoundException` — thrown when a
  provider-prefix references an unregistered provider.
- `Phlex\Auth\AuthManager::loginWithProvider()` — authenticates a user
  via an external provider (OIDC, LDAP, SAML, passkey). On first login,
  automatically creates a local user row with `password_hash = NULL`.
- `Phlex\Auth\UserRepository::findByExternalId()`,
  `findOrCreateByExternalId()`, `updateProviderData()` — data access
  for provider-linked accounts.
- `Phlex\Server\Http\Controllers\AuthProviderController` — admin API
  for listing / enabling / disabling providers and retrieving their
  configuration JSON schema.
- Routes wired in `AdminRoutes`:
  `GET /api/v1/admin/auth-providers`,
  `POST /api/v1/admin/auth-providers/{name}/enable`,
  `POST /api/v1/admin/auth-providers/{name}/disable`,
  `GET /api/v1/admin/auth-providers/{name}/config-schema`.
- Migration `009_auth_provider_schema.sql` adds `provider` (VARCHAR 64),
  `external_id` (VARCHAR 255), `provider_data` (JSON) columns to
  `users` table, with indexes `idx_provider` and `idx_external`.
- `detain/phlex-shared:^0.3.0` — new package version with
  `Phlex\Shared\Auth\ProviderInterface`, `AuthResult`, `UserInfo`.
- `docs/plugins/developer-guide.md` — added "Auth Provider Plugins"
  section (Section 13) covering the interface contract, result types,
  manifest, lifecycle hooks, and admin API.
- Unit tests: `AuthResultTest` (5 tests), `UserInfoTest` (6 tests),
  `AuthProviderRegistryTest` (5 tests), `ProviderManagerTest` (8 tests),
  `UserRepositoryExternalIdTest` (5 tests), `AuthProviderControllerTest` (6 tests).

### Added (Step C.9)

- `Phlex\Hub\HubClient::sendHeartbeat()` — now includes `library_count`,
  `total_size_bytes`, and `library-sharing` capability in heartbeat
  payload to advertise library information to the hub.

### Added (Step C.8)

- `Phlex\Hub\SubdomainResult` — DTO for subdomain allocation result with
  subdomain, fqdn, tlsCertPath, and tlsKeyPath fields.
- `Phlex\Hub\SubdomainClient` — client for claiming/releasing subdomains
  from the hub and storing TLS configuration locally.
- `Phlex\Hub\HttpClientInterface::delete()` — added DELETE method for
  subdomain release.
- `Phlex\Hub\HttpClient::delete()` — implements DELETE method.
- `Phlex\Hub\HubClient::getHttpClient()` — exposes HTTP client for use
  by SubdomainClient.
- `scripts/claim-subdomain.php` — CLI script for claiming a subdomain.
- `config/hub.php` — added `subdomain_auto_claim`, `tls_enabled`,
  `domain` configuration options.
- `docs/dev/tls-certificates.md` — guide covering TLS setup, certificate
  sources (hub-provisioned vs self-signed), and security considerations.
- `docs/reference/env-vars.md` — added `PHLEX_SUBDOMAIN_AUTO_CLAIM`,
  `PHLEX_TLS_ENABLED`, `PHLEX_DOMAIN` environment variables.

### Added (Step C.7)

- `Phlex\Network\UpnpIgdClient` — UPnP-IGD client using raw sockets.
  SSDP M-SEARCH discovery on `239.255.255.250:1900`, SOAP
  `AddPortMapping` / `GetExternalIPAddress` / `DeletePortMapping`
  actions for automatic port forwarding on compatible routers.
- `Phlex\Network\StunClient` — RFC 5389 STUN client for discovering
  the server's public IP address and testing port accessibility via
  TCP connect probe.
- `Phlex\Network\NatPmpClient` — RFC 6886 NAT-PMP client for Apple
  AirPort routers and other NAT-PMP-compatible gateways.
- `Phlex\Network\PortForwardService` — orchestrator that tries UPnP
  first, then NAT-PMP, then STUN for IP detection; falls back to
  manual port-forward instructions; stores result to
  `config/port-forward.json`.
- `scripts/port-forward.php` — CLI with `status`, `enable`,
  `disable`, `info`, and `help` commands.
- `src/Common\Container\Providers\NetworkServicesProvider` — registers
  `UpnpIgdClient`, `StunClient`, `NatPmpClient`, and
  `PortForwardService` in the PHP-DI container.
- `config/port-forward.php` — `PHLEX_PORT_FORWARD_AUTO`,
  `PHLEX_EXTERNAL_PORT`, `PHLEX_EXTERNAL_HTTP_PORT`,
  `PHLEX_EXTERNAL_HTTPS_PORT`, `PHLEX_UPNP_ENABLED`,
  `PHLEX_STUN_SERVER`, `PHLEX_STUN_PORT` configuration.
- `docs/hub/remote-access.md` — end-user guide covering UPnP, NAT-PMP,
  STUN, manual port forwarding setup, and troubleshooting.
- `docs/hub-admin/network.md` — hub admin guide covering port forwarding
  configuration, firewall rules, and network requirements.
- `docs/reference/env-vars.md` — documents port-forwarding and STUN
  environment variables.
- `docs/reference/cli.md` — documents `port-forward.php` CLI commands.
- Unit tests: `UpnpIgdClientTest` (5 tests), `StunClientTest` (8 tests),
  `NatPmpClientTest` (6 tests), `PortForwardServiceTest` (9 tests),
  `PortForwardScriptTest` (5 tests).

### Changed (Step C.7)

- `Phlex\Hub\HubClient` now injects `PortForwardService` and calls
  `discoverHostnameCandidates()` to augment heartbeat hostname
  candidates with LAN IP, mDNS, and public IP endpoints when available.
- `Phlex\Common\Container\ContainerFactory::defaultProviders()` now
  registers `NetworkServicesProvider`.

### Added (Step C.6)

- `Phlex\Hub\RelayMessageFramer` — binary framing for HTTP-over-WebSocket
  tunnel. Wire format: `[1-byte type][4-byte seq][4-byte payload_len][payload]`.
  Types: HTTP_REQUEST (1), HTTP_RESPONSE (2), PING (3), PONG (4).
  All payloads are JSON.
- `Phlex\Hub\RelayFrame` — immutable parsed frame DTO with accessors
  (`isRequest()`, `isResponse()`, `isPing()`, `isPong()`).
- `Phlex\Hub\RelayConfig` — relay tunnel configuration from environment
  variables (`PHLEX_RELAY_ENABLED`, `PHLEX_RELAY_HUB_URL`,
  `PHLEX_RELAY_TUNNEL_HOSTNAME`, etc.).
- `Phlex\Hub\RelayConsumer` — server-side Workerman consumer that opens a
  persistent WSS connection to the hub, receives framed HTTP requests,
  dispatches them to the local router, and sends responses back over the
  tunnel. Implements auto-reconnect with configurable delay and
  keep-alive ping/pong.
- `Phlex\Hub\RelayApplication` — thin Workerman Worker entry point
  (`text://` protocol, timer-driven) wrapping `RelayConsumer`.
- `config/relay.php` — `PHLEX_RELAY_ENABLED`, `PHLEX_RELAY_HUB_URL`,
  `PHLEX_RELAY_TUNNEL_HOSTNAME`, `PHLEX_RELAY_RECONNECT_DELAY`,
  `PHLEX_RELAY_PING_INTERVAL`, `PHLEX_RELAY_PING_TIMEOUT`.
- `config/hub.php` — added `relay` capability to heartbeat payload.
- `docs/dev/relay-protocol.md` — wire protocol reference for the
  HTTP-over-WebSocket relay tunnel.
- `docs/reference/env-vars.md` — documents relay env vars.
- Unit tests: `RelayMessageFramerTest` (13 tests covering frame round-trips,
  ping/pong, invalid/incomplete frames), `RelayConsumerTest` (11 tests
  covering config, routing, connection state).

### Changed (Step C.6)

- `Phlex\Hub\HubClient::sendHeartbeat()` now advertises `relay`
  in the server capabilities list.
- `Phlex\Server\Core\Application` now starts `RelayApplication`
  automatically when `config/hub-enrollment.json` exists and
  `PHLEX_RELAY_ENABLED=true`.
- `Phlex\Common\Container\Providers\HubServicesProvider` now registers
  `RelayConfig`, `RelayMessageFramer`, `RelayConsumer`, and
  `RelayApplication` in the PHP-DI container.

### Added (Step C.2)

- `Phlex\Hub\HubClient` — server-side orchestrator for server↔hub pairing,
  heartbeat loop, re-enrollment, and JWKS exposure. Implements the protocol
  defined in `docs/dev/pairing-protocol.md`.
- `Phlex\Hub\Ed25519KeyManager` — generates, stores, loads, and rotates
  Ed25519 keypairs (libsodium `sodium_crypto_sign_*`). Key stored at
  `config/hub-server-key.pem` (mode 0600). Key ID is SHA-256 first 8 bytes
  of the public key (base64url).
- `Phlex\Hub\HttpClient` — cURL-based HTTP client for hub API communication.
  Always sends `Accept-Phlex-Protocol: v1` header.
- `Phlex\Hub\HubApplication` — thin Workerman Worker wrapper for the
  background heartbeat loop (`text://` protocol, timer-driven).
- `Phlex\Server\Http\Controllers\HubJwksController` — serves
  `GET /.well-known/jwks.json` with the server's Ed25519 JWK(s).
  Cache-Control: public, max-age=3600.
- `scripts/pair-with-hub.php` — CLI pairing script. Initiates claim request,
  displays claim code, polls until claimed, stores enrollment, starts
  heartbeat loop.
- `config/hub.php` — hub subsystem configuration (`PHLEX_HUB_URL`,
  `PHLEX_HUB_HEARTBEAT_INTERVAL`, key/enrollment paths).
- `Phlex\Common\Container\Providers\HubServicesProvider` — registers
  Ed25519KeyManager, HubClient, HubJwksController, HubApplication in
  the PHP-DI container.
- `docs/reference/api/hub-jwks.yaml` — OpenAPI 3.0 spec for
  `/.well-known/jwks.json`.
- `docs/reference/cli.md` — documents `php scripts/pair-with-hub.php`.
- `docs/reference/env-vars.md` — documents `PHLEX_HUB_URL`,
  `PHLEX_HUB_ENROLLMENT_TOKEN`, `PHLEX_HUB_HEARTBEAT_INTERVAL`.

### Changed (Step C.2)

- `src/Server/Core/Application` now starts the hub heartbeat background
  worker automatically when `config/hub-enrollment.json` exists.
- `src/Common\Container\ContainerFactory` now wires `HubServicesProvider`
  into the default provider list.

### Added (Step C.5)

- `Phlex\Hub\HubJwtValidator` — validates JWTs issued by the Phlex Hub
  using the hub's JWKS. Supports Ed25519 signature verification via
  `sodium_crypto_sign_verify_detached`, automatic JWKS caching with TTL,
  and key rotation (refetches JWKS once on unknown `kid`).
- `Phlex\Hub\HubUserClaims` — immutable DTO for extracted hub JWT claims
  (`userId`, `serverId`, `subject`, `issuer`, `expiresAt`, `scope`).
- `Phlex\Hub\JwksCache` — in-memory JWKS cache with TTL support.
- `Phlex\Hub\HttpClientFactory` — factory for creating HTTP clients used
  by `HubJwtValidator` to fetch JWKS (enables testability).
- `Phlex\Server\Http\Middleware\HubJwtMiddleware` — validates hub JWTs on
  routes that support hub-mediated access. Populates `$request->hubUser`
  with `HubUserClaims` on success; returns 401 on invalid/expired tokens.
- `Phlex\Server\Http\Controllers\HubTokenController` — exchanges a hub JWT
  for a server-issued session token via `POST /api/v1/auth/hub-token`.
  Provides backward compatibility for older clients that present a hub
  JWT to get a server session token.
- `Phlex\Server\Http\Request::$hubUser` — new property holding
  `HubUserClaims` when the request was authenticated via hub JWT.
- `config/hub.php` — added `hub_jwks_url` key (`PHLEX_HUB_JWKS_URL`
  env var) for the hub's JWKS endpoint.
- `docs/reference/env-vars.md` — documents `PHLEX_HUB_JWKS_URL`.
- Unit tests: `HubJwtValidatorTest`, `HubUserClaimsTest`,
  `JwksCacheTest`, `HubJwtMiddlewareTest` (18 new tests).

### Changed (Step C.5)

- `Phlex\Common\Container\Providers\HubServicesProvider` now registers
  `HubJwtValidator`, `HubTokenController`, `HubJwtMiddleware`,
  `HttpClientFactory`, and `JwksCache`.
- `Phlex\Server\Core\Application` now registers the
  `POST /api/v1/auth/hub-token` route.

## [0.11.0] — 2026-05-17

### Changed

- Repository moved from `github.com/detain/phlex` to
  `github.com/detain/phlex-server`. The local working directory stays
  `/home/sites/phlex` per the expansion plan; only the `origin` remote
  URL changes. Update your local clone with
  `git remote set-url origin git@github.com:detain/phlex-server.git`.
  The old `detain/phlex` repo is archived (B.4b) with a README pointing
  at the new home.
- Refactored to depend on `detain/phlex-shared:^0.2`. The
  `LifecycleInterface`, manifest DTOs, event DTOs, and `EventNameMap`
  now live in the shared package. Old FQCNs
  (`Phlex\Plugins\Contract\LifecycleInterface`,
  `Phlex\Plugins\Manifest`, `Phlex\Plugins\ManifestType`,
  `Phlex\Plugins\ManifestValidationError`,
  `Phlex\Plugins\EventNameMap`, `Phlex\Common\Events\*`) remain as
  deprecated aliases through 0.11.x; removed in 0.12.0.
- Manifest schema validation extracted to
  `Phlex\Plugins\Manifest\ManifestSchema`.

### Added

- Composer require on `detain/phlex-shared:^0.2.0` via a VCS
  repositories entry.
- `src/Plugins/AliasCompatShim.php` registers the 16 `class_alias`
  entries for the moved classes.
- Three-line interface bridge at
  `src/Plugins/Contract/LifecycleInterface.php` (extends the shared
  interface — `class_alias` doesn't work for interfaces).

- Complete plugin developer documentation
  ([`docs/plugins/developer-guide.md`](docs/plugins/developer-guide.md))
  covering plugin types, manifest, lifecycle, event subscription,
  settings, signing, packaging, local testing, and publishing — plus a
  matching server-internals reference for contributors extending the
  loader ([`docs/dev/plugin-sdk.md`](docs/dev/plugin-sdk.md)). Phase A
  is now functionally complete; the plugin system is ready for
  external authors. `docs/plugins/install-from-catalog.md` rewritten
  to set expectations about the catalog's Phase L delivery; README
  promotes the developer guide and the reference plugin.
- Plugin manifest specification (`docs/plugins/manifest.md`,
  `docs/plugins/manifest.schema.json`) and the
  `Phlex\Plugins\Manifest` value object that parses and validates
  `plugin.json` files. The eleven plugin types from
  `PHLEX_EXPANSION_PLAN.md` §5 are codified as the
  `Phlex\Plugins\ManifestType` enum. No loader yet — see Step A.4.
  Adds `justinrainbow/json-schema:^5.2` as a runtime dependency.
- PSR-11 dependency injection container (PHP-DI). Application services are
  now auto-wired; the legacy ConnectionPool / LoggerFactory statics remain
  for backwards compatibility but are wrapped behind container bindings.
- `phpstan/phpstan` (level 9) and `squizlabs/php_codesniffer` (PSR-12) added
  as require-dev so the documented "minimum bar" is actually enforceable.
  A `phpstan-baseline.neon` absorbs pre-existing errors so new code is held
  to the bar without forcing a repo-wide refactor.
- `docs/dev/architecture-server.md` and `docs/reference/env-vars.md`.
- PSR-14 event dispatcher (Crell\Tukio). Playback, library-scan, and
  auth lifecycle events are now published from `PlaybackController`,
  `MediaScanner`, and `AuthManager`; plugins will be able to subscribe in
  Phase A.4. Twelve typed `readonly` event DTOs ship in
  `src/Common/Events/`. New env var `PHLEX_DEBUG_EVENTS` and `events`
  log channel. Canonical catalog in `docs/dev/event-reference.md`.
- Plugin loader (`Phlex\Plugins\PluginLoader`) with the full
  install / enable / disable / uninstall lifecycle. Plugins can be
  installed from a URL (HTTPS + `file://` by default; HTTP behind
  `PHLEX_PLUGINS_ALLOW_HTTP=1`) or from a local directory; each plugin
  gets its own Composer-resolved `vendor/` tree under
  `var/plugins/<name>/`. The lifecycle contract lives in
  `Phlex\Plugins\Contract\LifecycleInterface` (temporary home — moves to
  `Phlex\Shared\Plugin` in B.1). New table `plugins` (migration
  `migrations/003_plugins.sql`). New `plugins` log channel and config
  key. New env vars: `PHLEX_PLUGINS_ALLOW_HTTP`,
  `PHLEX_PLUGINS_REQUIRE_SIGNATURE`, `PHLEX_PLUGINS_COMPOSER_TIMEOUT`.
  Adds `symfony/process:^7.0`.
  See `docs/plugins/developer-guide.md` for the lifecycle diagram and
  a sample `LifecycleInterface` implementation.
- Plugin admin UI at `/admin/plugins` and JSON API under
  `/api/v1/admin/plugins/*` (list / install / enable / disable /
  uninstall). All routes gated by a new `AdminMiddleware` that reads
  the new `users.is_admin` flag (migration `004_admin_user_flag.sql`).
  The first user registered after the migration is auto-promoted to
  admin; subsequent users default to `is_admin = 0`. Adds runtime
  Composer dep `smarty/smarty:^4.0` (already used at runtime; now
  declared). OpenAPI spec at `docs/reference/api/admin-plugins.yaml`;
  end-user docs at `docs/plugins/install-from-url.md`. Editable
  settings UI deferred to a later phase — A.5 renders settings
  read-only with `secret: true` fields masked.
- Reference plugin
  [`phlex-plugin-example`](https://github.com/detain/phlex-plugin-example)
  — the first community-shaped Phlex plugin, published as its own
  public GitHub repo. Implements
  `Phlex\Plugins\Contract\LifecycleInterface` as a
  `metadata-provider` that returns `['title' => 'Hello, World']` for a
  fixed fixture path, and ships unsigned by design as the canonical
  fork-as-starter template for plugin authors. Installable through the
  A.5 admin UI by pasting
  `https://raw.githubusercontent.com/detain/phlex-plugin-example/main/plugin.json`
  into **Install from URL**. Server-side wiring: new fixture
  `tests/fixtures/plugins/example-manifest.json` mirrors the published
  manifest so the loader's URL-install test can use a `file://` URL,
  and `docs/plugins/install-from-url.md` /
  `docs/plugins/trusted-plugin-list.md` now reference the live
  example URL.

### Deprecated

- `Phlex\Server\Core\Application::getInstance()` — resolve services from
  the PSR-11 container instead. Slated for removal in Phase B.
