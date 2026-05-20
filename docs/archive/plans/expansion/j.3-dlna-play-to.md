# Step J.3 — DLNA AVTransport "play to"

**Phase:** J (DLNA / Cast / Discovery)
**Step:** J.3
**Depends on:** J.2
**Review:** Yes — see `j.3-dlna-play-to-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement DLNA AVTransport "play to" functionality — the ability for a DLNA controller (Phlex app) to send media to a DLNA renderer (TV, speaker, receiver) via the DLNA AVTransport service.

This step:
- Discovers DLNA renderers on the network via SSDP (from J.1).
- Implements the full AVTransport control flow: `SetAVTransportURI` → `Play` → `Pause` → `Stop` → `Seek`.
- Integrates with Phlex's `PlaybackController` so "play to" works with Phlex's playback session and position tracking.
- Adds HTTP endpoints for renderer discovery and AVTransport control.
- Supports remote casting via the relay tunnel (`RelayConsumer` from Phase C.6) for devices behind NAT.

## 2. Context (what already exists)

Read first, do not modify until §4:

- `src/Dlna/AvTransport.php` — existing AVTransport implementation with `setAvTransportUri()`, `play()`, `pause()`, `stop()`, `seek()`, `getTransportInfo()`, `getPositionInfo()`. Framework only, not wired to network.
- `src/Dlna/TransportState.php` — per-instance transport state (URI, position, state).
- `src/Dlna/DeviceRegistry.php` — registry for discovered DLNA devices (renderers).
- `src/Dlna/DlnaServer.php` — existing server with SOAP handlers wired to AvTransport. Not currently discovering renderers.
- `src/Discovery/Ssdp/SsdpDiscovery.php` — from J.1; use `discoverDevices('urn:schemas-upnp-org:device:MediaRenderer:1')` to find renderers.
- `src/Session/PlaybackController.php` — Phlex's playback session controller.
- `src/Hub/RelayConsumer.php` — from Phase C.6; relay tunnel for remote casting.
- `src/Media/Streaming/HlsStreamer.php` — stream URL generation for remote casting relay.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase J — J.3 is "play to" step.

## 3. Scope — files to create / modify

### Create

#### New classes — renderer discovery and control

- `src/Dlna/RendererDiscovery.php` — discovers DLNA renderers via SSDP:
  ```php
  class RendererDiscovery
  {
      public function __construct(
          private readonly SsdpDiscovery $ssdpDiscovery,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Discover all DLNA MediaRenderers on the network. */
      public function discoverRenderers(): array {}

      /** Get the device description for a renderer. */
      public function getRendererDescription(string $locationUrl): ?array {}
  }
  ```

- `src/Dlna/RendererControlClient.php` — HTTP SOAP client for AVTransport control of a remote renderer:
  ```php
  class RendererControlClient
  {
      public function __construct(
          private readonly string $rendererUrl,  // e.g. http://192.168.1.50:8200
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Set the transport URI (what to play). */
      public function setAvTransportUri(string $uri, string $metadata = ''): array {}

      /** Start playback. */
      public function play(string $speed = '1'): array {}

      /** Pause playback. */
      public function pause(): array {}

      /** Stop playback. */
      public function stop(): array {}

      /** Seek to position (REL_TIME format: HH:MM:SS). */
      public function seek(string $target): array {}

      /** Get current transport info. */
      public function getTransportInfo(): array {}

      /** Get current position info. */
      public function getPositionInfo(): array {}

      /** Get media info. */
      public function getMediaInfo(): array {}

      /** Send a SOAP request to the renderer. */
      private function sendSoapRequest(string $service, string $action, array $params): array {}
  }
  ```

- `src/Dlna/PlayToSession.php` — active "play to" session with a remote renderer:
  ```php
  class PlayToSession
  {
      public const STATE_IDLE = 'idle';
      public const STATE_BUFFERING = 'buffering';
      public const STATE_PLAYING = 'playing';
      public const STATE_PAUSED = 'paused';
      public const STATE_STOPPED = 'stopped';

      public function __construct(
          private readonly string $sessionId,
          private readonly string $rendererId,
          private readonly string $rendererName,
          private readonly RendererControlClient $client,
          private readonly PlaybackController $playbackController,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      public function setMediaItem(string $itemId, string $uri, string $metadata): void {}
      public function play(): void {}
      public function pause(): void {}
      public function stop(): void {}
      public function seek(int $positionTicks): void {}
      public function getState(): string {}
      public function getPosition(): int {}
      public function syncFromRenderer(): void {}  // Poll position from renderer
  }
  ```

- `src/Dlna/PlayToManager.php` — manages multiple "play to" sessions:
  ```php
  class PlayToManager
  {
      public function __construct(
          private readonly RendererDiscovery $rendererDiscovery,
          private readonly PlaybackController $playbackController,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Discover available renderers on the network. */
      public function discoverRenderers(): array {}

      /** Start a "play to" session for a media item. */
      public function startSession(string $rendererId, string $mediaItemId, string $uri, string $metadata): ?PlayToSession {}

      /** Get the active session for a renderer. */
      public function getSession(string $rendererId): ?PlayToSession {}

      /** Stop and remove a session. */
      public function stopSession(string $rendererId): void {}

      /** Get all active sessions. */
      public function getActiveSessions(): array {}
  }
  ```

#### New HTTP endpoints for renderer control

- `src/Server/Http/Controllers/Dlna/RendererListController.php`:
  ```php
  class RendererListController
  {
      public function __construct(
          private readonly PlayToManager $playToManager,
      ) {}

      /** GET /api/v1/dlna/renderers — list discovered renderers. */
      public function listRenderers(Request $request, array $params): Response {}

      /** POST /api/v1/dlna/renderers/{id}/play — start "play to" session. */
      public function playTo(Request $request, array $params): Response {}

      /** POST /api/v1/dlna/renderers/{id}/pause — pause playback. */
      public function pause(Request $request, array $params): Response {}

      /** POST /api/v1/dlna/renderers/{id}/stop — stop playback. */
      public function stop(Request $request, array $params): Response {}

      /** POST /api/v1/dlna/renderers/{id}/seek — seek to position. */
      public function seek(Request $request, array $params): Response {}

      /** GET /api/v1/dlna/renderers/{id}/status — get renderer state. */
      public function getStatus(Request $request, array $params): Response {}
  }
  ```

#### Relay integration for remote "play to"

- `src/Dlna/RemoteRendererClient.php` — "play to" via relay tunnel (for renderers behind NAT):
  ```php
  class RemoteRendererClient
  {
      public function __construct(
          private readonly RelayConsumer $relayConsumer,
          private readonly string $rendererId,
          private readonly string $relayPath,
      ) {}

      /** Send a play command through the relay tunnel. */
      public function play(): array {}

      /** Send a pause command through the relay tunnel. */
      public function pause(): array {}

      /** Send a stop command through the relay tunnel. */
      public function stop(): array {}

      /** Send a seek command through the relay tunnel. */
      public function seek(int $position): array {}
  }
  ```

#### Tests

- `tests/Unit/Dlna/RendererDiscoveryTest.php`
- `tests/Unit/Dlna/RendererControlClientTest.php`
- `tests/Unit/Dlna/PlayToSessionTest.php`
- `tests/Unit/Dlna/PlayToManagerTest.php`

#### Documentation

- `docs/developers/dlna-play-to.md` — how "play to" works, renderer discovery flow, AVTransport control sequence, relay tunnel for remote play-to.

### Modify

- `src/Dlna/AvTransport.php` — add `getMediaDuration()` method to `TransportState` (needed for position info). Add state change callbacks (observable pattern) so `PlayToSession` can sync state.
- `src/Session/PlaybackController.php` — add `startPlayToSession()` method that creates a `PlayToSession` alongside the local session.
- `src/Server/Core/Application.php` — register DLNA renderer control routes: `/api/v1/dlna/renderers`, `/api/v1/dlna/renderers/{id}/play`, etc.
- `composer.json` — no new runtime dependencies.
- `CHANGELOG.md` — add entry: "Added: DLNA 'play to' — send media to DLNA renderers".

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Branch from master (after J.2 merged): `git checkout -b j.3-dlna-play-to`.
2. **RendererDiscovery.** Use `SsdpDiscovery::discoverDevices('urn:schemas-upnp-org:device:MediaRenderer:1')` to find renderers. Parse device description XML to extract `avtransport` control URL.
3. **RendererControlClient.** Implement HTTP SOAP client (reuse `HttpClient` or plain `file_get_contents`). Build SOAP envelope with proper `SOAPACTION` header (`"urn:schemas-upnp-org:service:AVTransport:1#Play"` etc.). Parse SOAP response XML.
4. **PlayToSession.** Manage a "play to" session: holds `RendererControlClient`, wraps `PlaybackController` calls, tracks session state, polls renderer position via `getPositionInfo()` every 5 s via Workerman Timer.
5. **PlayToManager.** Maintains `rendererId => PlayToSession` map. `discoverRenderers()` calls `RendererDiscovery`. `startSession()` creates client, sets media URI, starts polling timer.
6. **Relay integration.** `RemoteRendererClient` wraps `RelayConsumer::registerMount()` to relay AVTransport commands over the tunnel. `PlayToSession` uses `RemoteRendererClient` when the renderer is not on the local network (detected via relay enrollment).
7. **HTTP routes.** Register renderer list/status/control endpoints in `Application::loadApiRoutes()`. All return JSON responses.
8. **Sync with local playback.** `PlaybackController::startPlayToSession()` updates both local and remote position together (using Phase C.6 relay tunnel for remote sync).
9. **Tests.** Write four test files.
10. **Verification bar.**
11. **Docs + CHANGELOG.**
12. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `RendererDiscoveryTest::test_discover_renderers_returns_array_of_renderers`
2. `RendererDiscoveryTest::test_discover_renderers_returns_empty_on_network_error`
3. `RendererControlClientTest::test_set_av_transport_uri_builds_correct_soap_request`
4. `RendererControlClientTest::test_play_sends_play_action`
5. `RendererControlClientTest::test_get_position_info_parses_response`
6. `PlayToSessionTest::test_set_media_item_updates_session_state`
7. `PlayToSessionTest::test_play_transitions_to_playing`
8. `PlayToSessionTest::test_seek_calls_renderer_seek`
9. `PlayToManagerTest::test_discover_renderers_delegates_to_renderer_discovery`
10. `PlayToManagerTest::test_start_session_creates_client_and_sets_uri`
11. `PlayToManagerTest::test_stop_session_removes_session`

**Coverage target:** `RendererControlClient` ≥ 85 %, `PlayToSession` ≥ 85 %, `PlayToManager` ≥ 80 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New public class/method"** → all new public classes get PHPDoc with `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry (users can now "play to" DLNA renderers from Phlex).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `RendererDiscovery::discoverRenderers()` returns DLNA renderers discovered via SSDP with `urn:schemas-upnp-org:device:MediaRenderer:1`.
- [ ] `RendererControlClient` sends properly formatted SOAP requests to the renderer's control URL.
- [ ] `RendererControlClient::setAvTransportUri()` includes correct `SOAPACTION` header and DIDL-Lite metadata.
- [ ] `PlayToSession` polls renderer position via `getPositionInfo()` every 5 seconds.
- [ ] `PlayToSession` transitions states correctly: idle → buffering → playing → paused → stopped.
- [ ] `PlayToManager::startSession()` creates a `RendererControlClient` and calls `setAvTransportUri()` with the HLS stream URL.
- [ ] `PlayToManager` correctly maps renderer ID to active session.
- [ ] Remote "play to" via `RemoteRendererClient` uses `RelayConsumer::registerMount()` for relay tunneling.
- [ ] `PlaybackController::startPlayToSession()` creates both local and remote sessions and syncs position.
- [ ] HTTP endpoints `/api/v1/dlna/renderers`, `/api/v1/dlna/renderers/{id}/play`, `/api/v1/dlna/renderers/{id}/pause`, `/api/v1/dlna/renderers/{id}/stop` work correctly.
- [ ] `./vendor/bin/phpunit` — green; ≥ 11 new tests.
- [ ] Coverage of `RendererControlClient` ≥ 85 %, `PlayToSession` ≥ 85 %, `PlayToManager` ≥ 80 %.
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
git checkout -b j.3-dlna-play-to

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Renderer|PlayTo'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step J.3: DLNA AVTransport play-to — send media to DLNA renderers"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step J.3 (DLNA): AVTransport play-to — send media to DLNA renderers" \
  --body  "Implements DLNA play-to: RendererDiscovery (SSDP), RendererControlClient (SOAP/HTTP), PlayToSession (session management + polling), PlayToManager (session map), RemoteRendererClient (relay tunnel), HTTP API for renderer control. Part of Phase J (Step J.3 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'j.3-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `j.3-dlna-play-to-review.md`.

(End of file - total 372 lines)
