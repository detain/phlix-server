# Step N.24 — Developer Guide: Server (Architecture, Namespaces, Event Map, Test Harness)

**Phase:** N (End-User Documentation)
**Step:** N.24
**Depends on:** N.0 (docs platform)
**Review:** No (doc-only step)
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:scribe (fallback: general-purpose)

## 1. Goal

Extend the existing **developer guide** at `docs/dev/architecture-server.md` by adding three new sections: a complete namespace map, a PSR-14 event map, and a debug recipes section. The existing content (bootstrap & container, request lifecycle, detain/phlex-shared) must be preserved untouched.

## 2. Context (what already exists)

Read first:

- `docs/dev/architecture-server.md` — existing doc to extend (do NOT modify existing sections).
- `src/Auth/JwtHandler.php` — JWT structure, `iss=phlex`, access/refresh token shapes.
- `src/Auth/UserRepository.php` — `password_hash(..., PASSWORD_ARGON2ID)`, user lookup.
- `src/Auth/AuthManager.php` — register/login/refresh orchestration.
- `src/Auth/UserProfileManager.php` — profile limits (≤5), PIN, rating filter.
- `src/Media/Library/LibraryManager.php` — library scan entry point.
- `src/Media/Library/MediaScanner.php` — filename parsing (`S01E02`, `(2020)`).
- `src/Media/Library/FolderWatcher.php` — mtime checksum monitoring.
- `src/Media/Library/ItemRepository.php` — `metadata_json` hydration.
- `src/Media/Metadata/MetadataManager.php` — provider priority (`tmdb→local` for movies, `tvdb→fanart→local` for series), 24 h cache.
- `src/Media/Metadata/TmdbProvider.php` — TMDB integration.
- `src/Media/Metadata/TvdbProvider.php` — TVDB integration.
- `src/Media/Metadata/FanartProvider.php` — fanart.tv integration.
- `src/Media/Metadata/LocalNfoProvider.php` — local NFO parsing.
- `src/Media/Streaming/HlsStreamer.php` — master/variant `.m3u8`, segment `.ts`.
- `src/Media/Streaming/QualitySelector.php` — profiles (generic, mobile-low, mobile-high, web, tv-4k).
- `src/Media/Streaming/StreamManager.php` — direct-play vs transcode path selection.
- `src/Media/Transcoding/FfmpegRunner.php` — probe/transcode/thumbnail.
- `src/Media/Transcoding/EncodingHelper.php` — CRF 23/28, libx264/libx265.
- `src/Media/Transcoding/TranscodeManager.php` — config from `config/ffmpeg.php`.
- `src/Session/SessionManager.php` — device sessions.
- `src/Session/PlaybackController.php` — continue-watched (< 95 %).
- `src/Session/SyncPlay/TimeSync.php` — NTP-style, `OFFSET_SAMPLE_COUNT=5`, weighted-mean offset.
- `src/LiveTv/ChannelManager.php` — channel management.
- `src/LiveTv/GuideManager.php` — EPG management.
- `src/LiveTv/Recorder.php` — DVR recording.
- `src/Dlna/ContentDirectory.php` — DMS ContentDirectory service.
- `src/Dlna/AvTransport.php` — DMS AVTransport service.
- `src/Dlna/DlnaServer.php` — DMS server entry.
- `src/Server/Core/Application.php` — Workerman worker entry, `fromConfigPath()`.
- `src/Server/Http/Router.php` — `{param}` routing, middleware groups.
- `src/Server/Http/Controllers/AuthController.php` — register/login/logout/refresh endpoints.
- `src/Server/Http/Controllers/LibraryController.php` — library CRUD.
- `src/Server/Http/Controllers/MediaItemController.php` — media item detail.
- `src/Server/Http/Controllers/SessionController.php` — session/device management.
- `src/Server/WebSocket/WebSocketServer.php` — Workerman WS wrapper, `on/handle` event registration.
- `src/Server/WebSocket/Connection.php` — per-connection state, `sendMessage()`.
- `src/Server/WebSocket/ConnectionPool.php` — singleton pool.
- `src/Server/WebSocket/MessageHandler.php` — `on($event, $cb)` registration.
- `src/Server/WebSocket/Events.php` — `WebSocketEvents::PLAYBACK_*`, `SYNCPLAY_*`, `AUTH_*` constants.
- `src/Common/Container/ContainerFactory.php` — PSR-11 container factory, four service providers.
- `src/Common/Database/ConnectionPool.php` — `init()` / `getConnection('mysql')`.
- `src/Common/Database/QueryBuilder.php` — query abstraction.
- `src/Common/Logger/LoggerFactory.php` — channel-named loggers.
- `src/Common/Logger/LogChannels.php` — AUTH, HTTP, WEBSOCKET, MEDIA, SESSION, STREAMING.
- `src/Common/Logger/AuditLogger.php` — auth audit events.
- `plans/expansion/a.2-event-dispatcher.md` — the plan that introduced PSR-14 events (step A.2); references the event map used across F.3, G.3, H.4, L.1.

## 3. Scope — section to add to `docs/dev/architecture-server.md`

Append the following three new sections **after the existing "See also" block** (line 131). Do NOT edit any content before "See also". The existing sections (Bootstrap & container, Request lifecycle, Dependencies → detain/phlex-shared, See also) must remain unchanged.

### §7 Layout (required sections in this order)

#### A. Namespace Map

A table mapping each `Phlex\*` namespace to its constituent classes and their roles:

| Namespace | Key classes | Role |
|-----------|-------------|------|
| `Phlex\Auth\*` | `JwtHandler`, `UserRepository`, `AuthManager`, `UserProfileManager` | JWT auth, user management, profiles, parental controls |
| `Phlex\Media\Library\*` | `LibraryManager`, `MediaScanner`, `FolderWatcher`, `ItemRepository` | Media library scanning, watching, persistence |
| `Phlex\Media\Metadata\*` | `MetadataManager`, `TmdbProvider`, `TvdbProvider`, `FanartProvider`, `LocalNfoProvider` | Metadata fetching with priority queue and 24 h cache |
| `Phlex\Media\Streaming\*` | `HlsStreamer`, `QualitySelector`, `StreamManager` | HLS packaging, quality profiling, stream selection |
| `Phlex\Media\Transcoding\*` | `FfmpegRunner`, `EncodingHelper`, `TranscodeManager` | FFmpeg orchestration, CRF encoding, hardware acceleration |
| `Phlex\Session\*` | `SessionManager`, `PlaybackController`, `SyncPlay\*` | Device sessions, continue-watched, SyncPlay time-sync |
| `Phlex\Hub\*` | `HubClient`, `RelayConsumer` | Hub claim protocol and relay heartbeat (Phase C) |
| `Phlex\Plugins\*` | `Loader`, `PluginManager` | Plugin manifest loading and lifecycle management |
| `Phlex\LiveTv\*` | `ChannelManager`, `GuideManager`, `Recorder` | Live TV channels, EPG, DVR recording |
| `Phlex\Dlna\*` | `ContentDirectory`, `AvTransport`, `DlnaServer` | DLNA/DMS ContentDirectory and AVTransport services |
| `Phlex\Common\*` | `Container`, `Database` (`ConnectionPool`, `QueryBuilder`), `Logger` | DI container, MySQL connection pool, structured logging |
| `Phlex\Server\*` | `Core` (`Application`), `Http` (`Router`, `Controllers`), `WebSocket`, `WebPortal` | Workerman HTTP/WS entry, routing, page rendering |

#### B. PSR-14 Event Map

A table of all dispatched PSR-14 events with their event names, payload shape, and which step introduced them:

| Event name | Payload | Introduced |
|------------|---------|------------|
| `phlex.playback.started` | `{media_id, user_id, profile_id, position_ticks}` | A.2 |
| `phlex.playback.stopped` | `{media_id, user_id, position_ticks, completed}` | A.2 |
| `phlex.library.scanned` | `{library_id, item_count, duration}` | A.2 |
| `phlex.user.created` | `{user_id, email}` | A.2 |
| `phlex.scrobble.*` | `{media_id, user_id, scrobbler_type}` | A.2 |
| `phlex.webhook.*` | `{event_type, payload}` | A.2 |

> **Note:** Wildcard patterns (`phlex.scrobble.*`, `phlex.webhook.*`) match all sub-events. Listeners use `EventDispatcher::getListeners($eventName)` with the wildcard to receive all variants.

#### C. Test Harness

A concise section covering how to write and run unit tests using the project's PHPUnit setup:

**Running tests:**
```bash
./vendor/bin/phpunit                        # Unit + Integration suites
./vendor/bin/phpunit --testsuite Unit       # Unit tests only
./vendor/bin/phpunit tests/unit/Auth/JwtHandlerTest.php --testdox
```

**Mocking the database:**
```php
$db = $this->createMock(Workerman\MySQL\Connection::class);
$db->method('query')
   ->willReturn([['col' => 'val']]); // SELECT results
$db->expects($this->once())
    ->method('query')
    ->with($this->stringContains('INSERT'), $this->anything()); // write assertions
```

**Test location convention:**
- Unit tests: `tests/unit/{Module}/{Class}Test.php`
- Namespace: `Phlex\Tests\Unit\{Module}`
- Extends: `PHPUnit\Framework\TestCase`
- Testdox output: `./vendor/bin/phpunit --testdox` for BDD-style descriptions

**Coverage:**
```bash
./vendor/bin/phpunit --coverage-text  # Text coverage report
# Writes to coverage.xml + coverage-report/ (configured in phpunit.xml)
```

**Static analysis:**
```bash
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name '*.php' -exec php -l {} \;
```

#### D. Debug Recipes

Three compact recipes for common development debugging scenarios:

**1. Enable debug logging:**

```bash
PHLEX_LOG_LEVEL=debug php public/index.php
# Valid levels (least → most verbose): emergency, alert, critical, error, warning, notice, info, debug
```

**2. Xdebug with the server:**

```bash
php -d xdebug.mode=debug -d xdebug.clientHost=localhost public/index.php
# For VS Code: launch.json with "request": "launch" + "pathMappings" mapping /home/sites/phlex to the container workspace
# For PhpStorm: set "DBGp Proxy" port 9003, map server paths via deployment configuration
```

**3. Tail errors in real time:**

```bash
tail -f .logs/phlex.log | grep -i error
# To also watch transcode logs:
tail -f .logs/transcode/*.log
# To watch a specific channel:
tail -f .logs/auth.log
```

## 4. Approach

1. Branch from master: `git checkout -b n.24-dev-server`.
2. Read all context files listed in §2 above (focus on namespace structure, event names, test patterns).
3. Open `docs/dev/architecture-server.md` and append the four new sections (§A–§D) **after** the existing "See also" block at line 131. Do not modify any existing content.
4. Verify the sections are internally consistent: all classes in the namespace map exist in the codebase; all events in the event map match `src/Server/Events.php` or equivalent.
5. Run `./vendor/bin/phpcs --standard=PSR12 docs/dev/architecture-server.md` to verify no style violations.
6. Commit + PR + merge.

## 5. Acceptance Criteria

- [ ] `docs/dev/architecture-server.md` has all four new sections appended after "See also".
- [ ] Existing content (lines 1–131) is completely unchanged.
- [ ] Namespace map table lists all 12 namespaces with correct classes.
- [ ] PSR-14 event map table lists all 6 events with correct payload shapes.
- [ ] Test Harness section covers running, mocking, and static analysis commands.
- [ ] Debug Recipes section covers all 3 scenarios (debug logging, Xdebug, log tailing).
- [ ] No implementation code in doc; only tables and shell blocks.
- [ ] PHPCS clean on the file.
- [ ] All cross-references within the doc are valid.

## 6. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b n.24-dev-server
# ... append sections to docs/dev/architecture-server.md ...
git add docs/dev/architecture-server.md
git commit -m "Step N.24: Extend architecture-server.md with namespace map, event map, and debug recipes"
unset GITHUB_TOKEN
gh pr create --title "Step N.24: Developer guide — server (architecture, namespaces, event map, test harness)" --body "Doc-only step. Extends docs/dev/architecture-server.md with namespace map, PSR-14 event map, test harness, and debug recipes."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 7. Reviewer hand-off

Review = No. This is a doc-only step. Merge when ready.
