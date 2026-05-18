# Step J.4 — Chromecast (Default Media Receiver)

**Phase:** J (DLNA / Cast / Discovery)
**Step:** J.4
**Depends on:** J.1
**Review:** Yes — see `j.4-chromecast-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement Chromecast (Google Cast) support using the Default Media Receiver. This allows Phlex to:
- Discover Chromecast devices on the network via mDNS (from J.1).
- Cast media (HLS video / MP3 audio) to Chromecast devices via the Google Cast protocol.
- Provide a playback control API (play, pause, seek, stop) for active Chromecast sessions.

Chromecast uses mDNS for device discovery (`_googlecast._tcp.local.`) and a JSON-based protocol over HTTP/WS for session management and media control.

## 2. Context (what already exists)

Read first, do not modify until §4:

- `src/Discovery/Mdns/MdnsDiscovery.php` — from J.1; `discoverChromecast()` returns `MdnsService[]` for `_googlecast._tcp.local.`.
- `src/Discovery/Mdns/MdnsService.php` — contains `name`, `port`, `host`, `txtRecords` including `id` (Chromecast device ID).
- `src/Session/PlaybackController.php` — existing playback controller; extend with Chromecast sessions.
- `src/Media/Streaming/HlsStreamer.php` — HLS stream URL generation.
- Phase C.6 `src/Hub/RelayConsumer.php` — relay tunnel for remote Chromecast casting.
- `src/Dlna/PlayToSession.php` — reference for session management pattern (adapt for Chromecast).
- `config/discovery.php` — from J.1; mDNS config.

## 3. Scope — files to create / modify

### Create

#### New classes — Chromecast discovery and protocol

- `src/Chromecast/CastDevice.php` — Chromecast device descriptor:
  ```php
  class CastDevice
  {
      public function __construct(
          public readonly string $deviceId,      // From TXT `id`
          public readonly string $name,          // Friendly name (from mDNS name, stripped)
          public readonly string $host,
          public readonly int $port,
          public readonly string $model,
          public readonly string $uuid,
      ) {}

      public function getAddress(): string {}  // "host:port"
  }
  ```

- `src/Chromecast/CastDiscovery.php` — discovers Chromecast devices via mDNS:
  ```php
  class CastDiscovery
  {
      public function __construct(
          private readonly MdnsDiscovery $mdns,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Discover all Chromecast devices on the network. */
      public function discoverDevices(): array {}

      /** Get detailed device info from the Cast device's /api pages. */
      public function getDeviceInfo(CastDevice $device): ?array {}
  }
  ```

- `src/Chromecast/CastSession.php` — active Chromecast session:
  ```php
  class CastSession
  {
      public const STATE_IDLE = 'idle';
      public const STATE_APP LaunchING = 'app_launching';
      public const STATE_APP_RUNNING = 'app_running';
      public const STATE_PLAYING = 'playing';
      public const STATE_PAUSED = 'paused';
      public const STATE_BUFFERING = 'buffering';

      public function __construct(
          private readonly string $sessionId,
          private readonly CastDevice $device,
          private readonly CastApiClient $client,
          private readonly PlaybackController $playbackController,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Launch the Default Media Receiver app. */
      public function launchApp(): array {}

      /** Load and play a media item (HLS or MP3). */
      public function loadMedia(string $mediaUrl, string $mimeType, int $duration = 0, string $title = '', string $thumbnail = ''): array {}

      /** Play. */
      public function play(): array {}

      /** Pause. */
      public function pause(): array {}

      /** Stop. */
      public function stop(): array {}

      /** Seek to position in milliseconds. */
      public function seek(int $positionMs): array {}

      /** Get current media status. */
      public function getMediaStatus(): array {}

      /** Get session state. */
      public function getState(): string {}
  }
  ```

- `src/Chromecast/CastApiClient.php` — HTTP/JSON Cast protocol client:
  ```php
  class CastApiClient
  {
      public function __construct(
          private readonly string $deviceHost,
          private readonly int $devicePort,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Get device API version and transport ID. */
      public function connect(): array {}

      /** Launch an app by name (e.g., 'Default Media Receiver'). */
      public function launchApp(string $appId): array {}

      /** Get the current app session (media control URL). */
      public function getAppSessions(): array {}

      /** Load media into the receiver. */
      public function loadMedia(string $mediaUrl, string $mimeType, array $metadata = []): array {}

      /** Send a media command (PLAY, PAUSE, STOP, SEEK). */
      public function sendMediaCommand(string $command, array $params = []): array {}

      /** Get media status. */
      public function getMediaStatus(): array {}

      /** Send a WS message to the Cast device. */
      private function sendWsMessage(array $payload): array {}

      /** Send an HTTP POST to the Cast device API. */
      private function sendHttpRequest(string $path, array $body = []): array {}
  }
  ```

- `src/Chromecast/CastManager.php` — manages Chromecast sessions:
  ```php
  class CastManager
  {
      public function __construct(
          private readonly CastDiscovery $discovery,
          private readonly PlaybackController $playbackController,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Discover Chromecast devices on the network. */
      public function discoverDevices(): array {}

      /** Start a cast session for a media item. */
      public function startSession(string $deviceId, string $mediaUrl, string $mimeType, string $title, int $duration): ?CastSession {}

      /** Get the active session for a device. */
      public function getSession(string $deviceId): ?CastSession {}

      /** Stop and remove a session. */
      public function stopSession(string $deviceId): void {}
  }
  ```

#### HTTP endpoints

- `src/Server/Http/Controllers/Chromecast/ChromecastController.php`:
  ```php
  class ChromecastController
  {
      /** GET /api/v1/cast/devices — list discovered Chromecast devices. */
      public function listDevices(Request $request, array $params): Response {}

      /** POST /api/v1/cast/devices/{id}/cast — start casting. */
      public function cast(Request $request, array $params): Response {}

      /** POST /api/v1/cast/devices/{id}/play — play. */
      public function play(Request $request, array $params): Response {}

      /** POST /api/v1/cast/devices/{id}/pause — pause. */
      public function pause(Request $request, array $params): Response {}

      /** POST /api/v1/cast/devices/{id}/stop — stop. */
      public function stop(Request $request, array $params): Response {}

      /** POST /api/v1/cast/devices/{id}/seek — seek. */
      public function seek(Request $request, array $params): Response {}

      /** GET /api/v1/cast/devices/{id}/status — get session status. */
      public function getStatus(Request $request, array $params): Response {}
  }
  ```

#### Remote casting via relay

- `src/Chromecast/RemoteCastClient.php` — cast via relay tunnel (for Chromecast behind NAT / remote network):
  ```php
  class RemoteCastClient
  {
      public function __construct(
          private readonly RelayConsumer $relay,
          private readonly string $deviceId,
      ) {}

      public function launchApp(): array {}
      public function loadMedia(string $url, string $mimeType, array $metadata): array {}
      public function play(): array {}
      public function pause(): array {}
      public function stop(): array {}
      public function seek(int $positionMs): array {}
  }
  ```

#### Tests

- `tests/unit/Chromecast/CastDeviceTest.php`
- `tests/unit/Chromecast/CastDiscoveryTest.php`
- `tests/unit/Chromecast/CastApiClientTest.php`
- `tests/unit/Chromecast/CastSessionTest.php`
- `tests/unit/Chromecast/CastManagerTest.php`

#### Documentation

- `docs/developers/chromecast.md` — Chromecast protocol overview, device discovery, Default Media Receiver flow, API endpoints.

### Modify

- `src/Server/Core/Application.php` — register Chromecast HTTP routes: `/api/v1/cast/devices`, etc.
- `src/Media/Streaming/HlsStreamer.php` — add `getCastStreamUrl()` that returns a direct stream URL suitable for Chromecast.
- `composer.json` — no new runtime dependencies.
- `CHANGELOG.md` — add entry: "Added: Chromecast support — cast to Chromecast devices via Default Media Receiver".

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Branch from master (after J.1 merged): `git checkout -b j.4-chromecast`.
2. **Discovery.** Use `MdnsDiscovery::discoverChromecast()` (from J.1) to find `_googlecast._tcp.local.` services. Parse TXT record `id` (Chromecast UUID) and `md` (model). Strip `._googlecast._tcp.local.` from name to get friendly name.
3. **CastApiClient.** Chromecast uses HTTP for device connection (`/apps/...`) and WebSocket for event transport. Use plain HTTP (no WS library dependency — use `stream_socket_client`). Default Media Receiver app ID: `CC1AD845` (or `Default Media Receiver`).
4. **Session flow:**
   - `connect()` → GET `http://{host}:{port}/setup/eureka_info` → returns `device_info` with `name`, `model`, `uuid`.
   - `launchApp('CC1AD845')` → POST `http://{host}:{port}/apps/CC1AD845` with body `{}` → returns transport ID.
   - `loadMedia(url, mimeType)` → POST `http://{host}:{port}/apps/CC1AD845/media` with media URL + content type.
   - Send PLAY command → GET media status to confirm.
5. **Media URLs.** Chromecast HLS: provide `$hlsStreamer->getStreamUrl($item)` directly. Chromecast MP3: same URL (HLS streamer returns MP3 for audio items).
6. **CastSession.** Use Workerman `Timer` to poll `getMediaStatus()` every 5 s for position updates. Sync position to `PlaybackController`.
7. **CastManager.** Map `deviceId → CastSession`. `startSession()` creates session, launches app, loads media, starts polling.
8. **Remote casting.** `RemoteCastClient` uses `RelayConsumer::registerMount()` to proxy Cast protocol over relay tunnel (Phase C.6).
9. **Tests.** Write five test files.
10. **Verification bar.**
11. **Docs + CHANGELOG.**
12. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `CastDeviceTest::test_device_id_and_name_extraction`
2. `CastDiscoveryTest::test_discover_devices_returns_cast_devices`
3. `CastApiClientTest::test_connect_fetches_eureka_info`
4. `CastApiClientTest::test_launch_app_sends_post_to_apps_endpoint`
5. `CastApiClientTest::test_load_media_sends_correct_payload`
6. `CastApiClientTest::test_get_media_status_parses_response`
7. `CastSessionTest::test_launch_app_transitions_state`
8. `CastSessionTest::test_load_media_sets_session_and_starts_player`
9. `CastSessionTest::test_play_transitions_to_playing`
10. `CastSessionTest::test_seek_sends_seek_command`
11. `CastManagerTest::test_discover_devices_delegates_to_discovery`
12. `CastManagerTest::test_start_session_creates_and_launches`
13. `CastManagerTest::test_stop_session_removes_session`

**Coverage target:** `CastApiClient` ≥ 85 %, `CastSession` ≥ 85 %, `CastDiscovery` ≥ 80 %, `CastManager` ≥ 80 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New public class/method"** → all new public classes get PHPDoc with `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry (users can now cast to Chromecast devices).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `CastDiscovery::discoverDevices()` uses `MdnsDiscovery` to find `_googlecast._tcp.local.` devices.
- [ ] `CastDevice` extracts `id` from TXT record (Chromecast UUID) and strips `_googlecast._tcp.local.` from name.
- [ ] `CastApiClient::connect()` fetches `/setup/eureka_info` and returns `name`, `model`, `uuid`.
- [ ] `CastApiClient::launchApp('CC1AD845')` launches Default Media Receiver and returns `transportId`.
- [ ] `CastApiClient::loadMedia()` sends POST to `/apps/CC1AD845/media` with `contentId` (media URL) and `contentType`.
- [ ] `CastSession` polls `getMediaStatus()` every 5 seconds using Workerman Timer.
- [ ] `CastSession::getMediaStatus()` correctly parses `currentTime` (seconds) and `playerState` from Cast response.
- [ ] `CastManager::startSession()` creates `CastSession`, launches app, loads media, returns session.
- [ ] `RemoteCastClient` proxies Cast protocol over `RelayConsumer` for remote casting.
- [ ] HTTP routes `/api/v1/cast/devices`, `/api/v1/cast/devices/{id}/cast`, `/api/v1/cast/devices/{id}/play`, etc. work.
- [ ] `./vendor/bin/phpunit` — green; ≥ 13 new tests.
- [ ] Coverage of `CastApiClient` ≥ 85 %, `CastSession` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b j.4-chromecast

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Chromecast|CastDevice|CastApi|CastSession|CastManager'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step J.4: Chromecast support — Default Media Receiver casting"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step J.4 (Chromecast): Cast to Chromecast devices via Default Media Receiver" \
  --body  "Implements Chromecast support: CastDiscovery (mDNS), CastDevice, CastApiClient (HTTP Cast protocol), CastSession (session + polling), CastManager, RemoteCastClient (relay tunnel), HTTP API for cast control. Default Media Receiver app ID CC1AD845. Part of Phase J (Step J.4 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'j.4-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `j.4-chromecast-review.md`.

(End of file - total 381 lines)
