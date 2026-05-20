# Step J.6 — Roku ECP "send to Roku"

**Phase:** J (DLNA / Cast / Discovery)
**Step:** J.6
**Depends on:** J.1
**Review:** Yes — see `j.6-roku-ecp-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement Roku "send to Roku" functionality via Roku's External Control Protocol (ECP). This allows Phlex to:
- Discover Roku devices on the network via mDNS (from J.1).
- Send media (video, music, photos) to a Roku device for playback via ECP HTTP API.
- Control playback (play, pause, skip, stop) via ECP keypress commands.
- Support displaying artwork, screensaver mode, and channel launching.

Roku ECP uses HTTP POST to port `8060` with simple XML or keypress commands. No SOAP, no special protocol — just plain HTTP.

## 2. Context (what already exists)

Read first, do not modify until §4:

- `src/Discovery/Mdns/MdnsDiscovery.php` — from J.1; `discoverRoku()` queries `_ roku-ecnp._tcp.local.` (note leading space in the actual service name).
- `src/Discovery/Mdns/MdnsService.php` — contains `name`, `port`, `host`, `txtRecords`.
- `src/Session/PlaybackController.php` — existing playback controller.
- `src/Media/Streaming/HlsStreamer.php` — HLS stream URL generation.
- Phase C.6 `src/Hub/RelayConsumer.php` — relay tunnel for remote Roku control.
- `src/Dlna/PlayToSession.php` — reference for session management pattern.
- `src/Chromecast/CastSession.php` — reference for session management.
- `config/discovery.php` — from J.1.

## 3. Scope — files to create / modify

### Create

#### New classes — Roku discovery and ECP protocol

- `src/Roku/RokuDevice.php` — Roku device descriptor:
  ```php
  class RokuDevice
  {
      public function __construct(
          public readonly string $deviceId,      // From ECP device info
          public readonly string $name,        // Friendly name
          public readonly string $host,         // IP address
          public readonly int $port,            // ECP port (8060)
          public readonly string $model,         // e.g. "Roku Express"
          public readonly string $softwareVersion,
      ) {}

      public function getAddress(): string {}  // "host:port"
  }
  ```

- `src/Roku/RokuDiscovery.php` — discovers Roku devices via mDNS:
  ```php
  class RokuDiscovery
  {
      public function __construct(
          private readonly MdnsDiscovery $mdns,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Discover all Roku devices on the network. */
      public function discoverDevices(): array {}

      /** Get detailed device info from ECP. */
      public function getDeviceInfo(RokuDevice $device): ?array {}
  }
  ```

- `src/Roku/RokuEcpClient.php` — HTTP ECP client for Roku control:
  ```php
  class RokuEcpClient
  {
      public function __construct(
          private readonly string $deviceHost,
          private readonly int $devicePort = 8060,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Launch a channel by its ID (e.g., '12' for YouTube). */
      public function launchChannel(string $channelId): array {}

      /** Send a media item to the Roku (play URL). */
      public function playMedia(string $mediaUrl, string $mimeType, string $title = '', string $thumbnail = ''): array {}

      /** Send a keypress command. */
      public function sendKeypress(string $key): array {}

      /** Send an icon (for artwork/screensaver). */
      public function sendIcon(string $iconUrl): array {}

      /** Get device info (name, model, serial). */
      public function getDeviceInfo(): array {}

      /** Query the player state. */
      public function getPlayerState(): array {}
  }
  ```

- `src/Roku/RokuSession.php` — active Roku "send to" session:
  ```php
  class RokuSession
  {
      public const STATE_IDLE = 'idle';
      public const STATE_LAUNCHING = 'launching';
      public const STATE_PLAYING = 'playing';
      public const STATE_PAUSED = 'paused';

      public function __construct(
          private readonly string $sessionId,
          private readonly RokuDevice $device,
          private readonly RokuEcpClient $client,
          private readonly PlaybackController $playbackController,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Play media (launches built-in media player channel if needed). */
      public function playMedia(string $mediaUrl, string $mimeType, string $title, string $thumbnail): array {}

      /** Send keypress. */
      public function sendKey(string $key): array {}

      /** Pause playback. */
      public function pause(): array { return $this->sendKey('Pause'); }

      /** Resume playback. */
      public function play(): array { return $this->sendKey('Play'); }

      /** Stop playback. */
      public function stop(): array { return $this->sendKey('Back'); }

      /** Get current session state. */
      public function getState(): string {}
  }
  ```

- `src/Roku/RokuManager.php` — manages Roku sessions:
  ```php
  class RokuManager
  {
      public function __construct(
          private readonly RokuDiscovery $discovery,
          private readonly PlaybackController $playbackController,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Discover Roku devices on the network. */
      public function discoverDevices(): array {}

      /** Start a "send to Roku" session for a media item. */
      public function startSession(string $deviceId, string $mediaUrl, string $mimeType, string $title, string $thumbnail): ?RokuSession {}

      /** Get the active session for a device. */
      public function getSession(string $deviceId): ?RokuSession {}

      /** Stop and remove a session. */
      public function stopSession(string $deviceId): void {}
  }
  ```

#### HTTP endpoints

- `src/Server/Http/Controllers/Roku/RokuController.php`:
  ```php
  class RokuController
  {
      /** GET /api/v1/roku/devices — list discovered Roku devices. */
      public function listDevices(Request $request, array $params): Response {}

      /** POST /api/v1/roku/devices/{id}/send — send media to Roku. */
      public function sendMedia(Request $request, array $params): Response {}

      /** POST /api/v1/roku/devices/{id}/launch/{channelId} — launch a channel. */
      public function launchChannel(Request $request, array $params): Response {}

      /** POST /api/v1/roku/devices/{id}/key/{keyName} — send keypress. */
      public function sendKey(Request $request, array $params): Response {}

      /** GET /api/v1/roku/devices/{id}/status — get session status. */
      public function getStatus(Request $request, array $params): Response {}
  }
  ```

#### Remote control via relay

- `src/Roku/RemoteRokuClient.php` — Roku control via relay tunnel:
  ```php
  class RemoteRokuClient
  {
      public function __construct(
          private readonly RelayConsumer $relay,
          private readonly string $deviceId,
      ) {}

      public function playMedia(string $url, string $mimeType, string $title, string $thumbnail): array {}
      public function sendKey(string $key): array {}
      public function launchChannel(string $channelId): array {}
  }
  ```

#### Tests

- `tests/unit/Roku/RokuDeviceTest.php`
- `tests/unit/Roku/RokuDiscoveryTest.php`
- `tests/unit/Roku/RokuEcpClientTest.php`
- `tests/unit/Roku/RokuSessionTest.php`
- `tests/unit/Roku/RokuManagerTest.php`

#### Documentation

- `docs/developers/roku-ecp.md` — Roku ECP protocol overview, keypress commands, media playback, channel launching.

### Modify

- `src/Server/Core/Application.php` — register Roku HTTP routes: `/api/v1/roku/devices`, etc.
- `composer.json` — no new runtime dependencies.
- `CHANGELOG.md` — add entry: "Added: Roku ECP support — send media to Roku devices".

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Branch from master (after J.5 merged): `git checkout -b j.6-roku-ecp`.
2. **Discovery.** Use `MdnsDiscovery::discoverRoku()` (from J.1) to find `_ roku-ecnp._tcp.local.` services (note: Roku uses a leading space in the service name — ` roku-ecnp`). Extract `host` from SRV record. Port is also from SRV (default 8060).
3. **ECP client.** Roku ECP is plain HTTP (no SOAP, no special encoding). Key endpoints:
   - `POST /input` — send keypress (body: `key={keyName}`).
   - `POST /launch/{channelId}` — launch a channel (e.g., `12` for YouTube).
   - `POST /media/play` — play media (form data: `url`, `mimeType`, `title`, `thumbnail`).
   - `GET /query/device-info` — get device info (name, model, serial, software version).
   - `GET /query/player-info` — get current player state.
   Use plain `file_get_contents` with stream context (no Guzzle).
4. **Media playback.** For general media, launch the built-in `MediaPlayer` channel (channel ID `дел` or `6585` depending on model). Then send `playMedia` with the stream URL.
5. **Keypress commands.** Common keys: `Play`, `Pause`, `Back`, `Home`, `Up`, `Down`, `Left`, `Right`, `Select`, `Rev`, `Fwd`, `InstantReplay`, `Info`, `BackSpace`.
6. **RokuSession.** Manages ECP session: launches channel (if needed), sends media, tracks state, polls player info every 5 s via Workerman Timer.
7. **Remote control.** `RemoteRokuClient` proxies ECP over `RelayConsumer` for remote "send to Roku".
8. **Tests.** Write five test files.
9. **Verification bar.**
10. **Docs + CHANGELOG.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `RokuDeviceTest::test_device_id_and_name_extraction`
2. `RokuDiscoveryTest::test_discover_devices_returns_roku_devices`
3. `RokuDiscoveryTest::test_discover_returns_empty_on_network_error`
4. `RokuEcpClientTest::test_send_keypress_builds_correct_post`
5. `RokuEcpClientTest::test_launch_channel_sends_post_to_correct_path`
6. `RokuEcpClientTest::test_play_media_sends_url_and_metadata`
7. `RokuEcpClientTest::test_get_device_info_parses_response`
8. `RokuSessionTest::test_play_media_transitions_to_playing`
9. `RokuSessionTest::test_send_key_sends_keypress`
10. `RokuSessionTest::test_pause_calls_send_key_pause`
11. `RokuManagerTest::test_discover_devices_delegates_to_discovery`
12. `RokuManagerTest::test_start_session_creates_client_and_launches`
13. `RokuManagerTest::test_stop_session_removes_session`

**Coverage target:** `RokuEcpClient` ≥ 85 %, `RokuSession` ≥ 85 %, `RokuDiscovery` ≥ 80 %, `RokuManager` ≥ 80 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New public class/method"** → all new public classes get PHPDoc with `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry (users can now send media to Roku devices).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `RokuDiscovery::discoverDevices()` uses `MdnsDiscovery::discoverRoku()` (which queries ` roku-ecnp._tcp.local.` with leading space).
- [ ] `RokuDevice` extracts `host` from SRV record and `deviceId` from ECP device info.
- [ ] `RokuEcpClient::sendKeypress()` sends `POST /input` with `key={keyName}` in body.
- [ ] `RokuEcpClient::launchChannel()` sends `POST /launch/{channelId}`.
- [ ] `RokuEcpClient::playMedia()` sends `POST /media/play` with `url`, `mimeType`, `title`, `thumbnail` form data.
- [ ] `RokuEcpClient::getDeviceInfo()` parses `GET /query/device-info` XML response for `friendlyName`, `modelName`, `softwareVersion`.
- [ ] `RokuSession::playMedia()` first launches the MediaPlayer channel if needed, then sends media play command.
- [ ] `RokuSession` polls `getPlayerState()` every 5 seconds via Workerman Timer.
- [ ] `RokuManager::startSession()` creates `RokuSession`, launches channel, and starts polling.
- [ ] `RemoteRokuClient` uses `RelayConsumer::registerMount()` for remote control.
- [ ] HTTP routes work correctly for listDevices, send, launch, key, status.
- [ ] `./vendor/bin/phpunit` — green; ≥ 13 new tests.
- [ ] Coverage of `RokuEcpClient` ≥ 85 %, `RokuSession` ≥ 85 %.
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
git checkout -b j.6-roku-ecp

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Roku'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step J.6: Roku ECP support — send media to Roku devices"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step J.6 (Roku): Roku ECP support — send media to Roku devices" \
  --body  "Implements Roku ECP support: RokuDiscovery (mDNS with leading-space service name), RokuEcpClient (plain HTTP ECP), RokuSession (session + polling), RokuManager, RemoteRokuClient (relay tunnel), HTTP API for send-to-Roku, keypress, channel launch. Part of Phase J (Step J.6 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'j.6-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `j.6-roku-ecp-review.md`.

(End of file - total 362 lines)
