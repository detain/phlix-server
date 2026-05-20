# Step J.5 — AirPlay 2

**Phase:** J (DLNA / Cast / Discovery)
**Step:** J.5
**Depends on:** J.1
**Review:** Yes — see `j.5-airplay-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement AirPlay 2 support for streaming audio (and video where supported) from Phlex to AirPlay 2 receivers (Apple TV, HomePod, AirPlay 2-compatible receivers).

AirPlay 2 uses:
- **mDNS** for device discovery (`_airplay._tcp.local.` for control, `_raop._tcp.local.` for audio streaming).
- **HTTP** for playback control (SETUP, TEARDOWN, RECORD, FLUSH commands via RTSP-over-HTTP).
- **UDP/RTP** for audio streaming (encrypted using FairPlay DRM for protected content; plain for unprotected).
- **NTP** for media clock synchronization.

This step focuses on **audio streaming** to AirPlay 2 receivers using the RAOP (Remote Audio Output Protocol) profile.

## 2. Context (what already exists)

Read first, do not modify until §4:

- `src/Discovery/Mdns/MdnsDiscovery.php` — from J.1; `discoverAirPlay()` queries `_airplay._tcp.local.` and `_raop._tcp.local.`.
- `src/Discovery/Mdns/MdnsService.php` — contains `name`, `port`, `host`, `txtRecords`.
- `src/Session/PlaybackController.php` — existing playback controller.
- `src/Media/Streaming/HlsStreamer.php` — audio stream URL generation.
- Phase C.6 `src/Hub/RelayConsumer.php` — relay tunnel for remote AirPlay.
- `src/Chromecast/CastSession.php` — reference for session management pattern (adapt for AirPlay).
- `config/discovery.php` — from J.1.

## 3. Scope — files to create / modify

### Create

#### New classes — AirPlay discovery and protocol

- `src/AirPlay/AirPlayDevice.php` — AirPlay device descriptor:
  ```php
  class AirPlayDevice
  {
      public function __construct(
          public readonly string $deviceId,      // From TXT `deviceid`
          public readonly string $name,          // Friendly name
          public readonly string $host,
          public readonly int $port,             // Main port (usually 7000)
          public readonly int $raopPort,        // RAOP port (from _raop._tcp.local)
          public readonly string $model,          // e.g. "AppleTV5,3"
          public readonly bool $supportsVideo,
      ) {}

      public function getAddress(): string {}
  }
  ```

- `src/AirPlay/AirPlayDiscovery.php` — discovers AirPlay devices via mDNS:
  ```php
  class AirPlayDiscovery
  {
      public function __construct(
          private readonly MdnsDiscovery $mdns,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Discover all AirPlay devices on the network. */
      public function discoverDevices(): array {}
  }
  ```

- `src/AirPlay/RaopClient.php` — RAOP (Real-Time Audio Protocol) client for audio streaming:
  ```php
  class RaopClient
  {
      public function __construct(
          private readonly string $deviceHost,
          private readonly int $deviceRaopPort,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Get the ANNOUNCE payload (audio format, encryption info). */
      public function buildAnnouncePayload(string $audioUrl, string $contentType, int $duration): string {}

      /** Send FLUSH command to reset playback. */
      public function flush(int $rtpTime): array {}

      /** Get the RTP sync info. */
      public function getRtpInfo(): array {}

      /** Get the current playback latency. */
      public function getLatency(): int {}
  }
  ```

- `src/AirPlay/AirPlaySession.php` — active AirPlay session:
  ```php
  class AirPlaySession
  {
      public const STATE_IDLE = 'idle';
      public const STATE_CONNECTING = 'connecting';
      public const STATE_STREAMING = 'streaming';
      public const STATE_PAUSED = 'paused';

      public function __construct(
          private readonly string $sessionId,
          private readonly AirPlayDevice $device,
          private readonly RaopClient $raopClient,
          private readonly PlaybackController $playbackController,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Start streaming audio to the device. */
      public function startStream(string $audioUrl, string $contentType = 'audio/mp4', int $duration = 0): array {}

      /** Pause playback. */
      public function pause(): array {}

      /** Resume playback. */
      public function resume(): array {}

      /** Stop playback. */
      public function stop(): array {}

      /** Get current session state. */
      public function getState(): string {}
  }
  ```

- `src/AirPlay/AirPlayManager.php` — manages AirPlay sessions:
  ```php
  class AirPlayManager
  {
      public function __construct(
          private readonly AirPlayDiscovery $discovery,
          private readonly PlaybackController $playbackController,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Discover AirPlay devices on the network. */
      public function discoverDevices(): array {}

      /** Start an AirPlay session for audio streaming. */
      public function startSession(string $deviceId, string $audioUrl, string $contentType, int $duration): ?AirPlaySession {}

      /** Get the active session for a device. */
      public function getSession(string $deviceId): ?AirPlaySession {}

      /** Stop and remove a session. */
      public function stopSession(string $deviceId): void {}
  }
  ```

#### HTTP endpoints

- `src/Server/Http/Controllers/AirPlay/AirPlayController.php`:
  ```php
  class AirPlayController
  {
      /** GET /api/v1/airplay/devices — list discovered AirPlay devices. */
      public function listDevices(Request $request, array $params): Response {}

      /** POST /api/v1/airplay/devices/{id}/stream — start streaming. */
      public function stream(Request $request, array $params): Response {}

      /** POST /api/v1/airplay/devices/{id}/pause — pause. */
      public function pause(Request $request, array $params): Response {}

      /** POST /api/v1/airplay/devices/{id}/resume — resume. */
      public function resume(Request $request, array $params): Response {}

      /** POST /api/v1/airplay/devices/{id}/stop — stop. */
      public function stop(Request $request, array $params): Response {}

      /** GET /api/v1/airplay/devices/{id}/status — get session status. */
      public function getStatus(Request $request, array $params): Response {}
  }
  ```

#### Remote streaming via relay

- `src/AirPlay/RemoteAirPlayClient.php` — AirPlay via relay tunnel:
  ```php
  class RemoteAirPlayClient
  {
      public function __construct(
          private readonly RelayConsumer $relay,
          private readonly string $deviceId,
      ) {}

      public function startStream(string $url, string $contentType, int $duration): array {}
      public function pause(): array {}
      public function resume(): array {}
      public function stop(): array {}
  }
  ```

#### Tests

- `tests/Unit/AirPlay/AirPlayDeviceTest.php`
- `tests/Unit/AirPlay/AirPlayDiscoveryTest.php`
- `tests/Unit/AirPlay/RaopClientTest.php`
- `tests/Unit/AirPlay/AirPlaySessionTest.php`
- `tests/Unit/AirPlay/AirPlayManagerTest.php`

#### Documentation

- `docs/developers/airplay.md` — AirPlay 2 protocol overview, RAOP streaming, device discovery, API endpoints.

### Modify

- `src/Server/Core/Application.php` — register AirPlay HTTP routes: `/api/v1/airplay/devices`, etc.
- `src/Media/Streaming/HlsStreamer.php` — add `getAirPlayStreamUrl()` method that returns an audio-only HLS URL (for audio items) or main track for video items.
- `composer.json` — no new runtime dependencies.
- `CHANGELOG.md` — add entry: "Added: AirPlay 2 support — stream audio to AirPlay 2 devices".

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Branch from master (after J.4 merged): `git checkout -b j.5-airplay`.
2. **Discovery.** Use `MdnsDiscovery::discoverAirPlay()` (from J.1) to find `_airplay._tcp.local.` and `_raop._tcp.local.` services. Parse TXT records: `deviceid`, `model`, `features`, `flags`. RAOP port comes from SRV record of `_raop._tcp.local.`.
3. **RAOP client.** RAOP uses RTSP over HTTP (port 7000). Commands: ANNOUNCE (set up stream), RECORD (start), FLUSH (stop), TEARDOWN (close). For audio, use `audio/aac` or `audio/mp4` content type. Use plain RTP for streaming (no encryption for basic implementation).
4. **Audio URL.** For audio items, `$hlsStreamer->getStreamUrl($item)` returns HLS URL; for AirPlay, provide the master HLS URL directly (AirPlay natively supports HLS audio).
5. **AirPlaySession.** Manages RAOP session: sends ANNOUNCE, then RECORD to start, FLUSH to pause (or TEARDOWN + re-ANNOUNCE to stop). Use HTTP for control (port 7000). Simple polling for status (no event mechanism in basic RAOP).
6. **Remote streaming.** `RemoteAirPlayClient` proxies RAOP over `RelayConsumer` for remote streaming.
7. **Audio-only focus.** This step focuses on audio streaming. Video AirPlay (mirroring) is out of scope for J.5.
8. **Tests.** Write five test files.
9. **Verification bar.**
10. **Docs + CHANGELOG.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `AirPlayDeviceTest::test_device_id_and_name_extraction`
2. `AirPlayDiscoveryTest::test_discover_devices_returns_airplay_devices`
3. `RaopClientTest::test_build_announce_payload_contains_audio_format`
4. `RaopClientTest::test_flush_sends_rtsp_flush_command`
5. `RaopClientTest::test_get_rtp_info_parses_response`
6. `AirPlaySessionTest::test_start_stream_transitions_to_streaming`
7. `AirPlaySessionTest::test_pause_transitions_to_paused`
8. `AirPlaySessionTest::test_resume_transitions_to_streaming`
9. `AirPlaySessionTest::test_stop_transitions_to_idle`
10. `AirPlayManagerTest::test_discover_devices_delegates_to_discovery`
11. `AirPlayManagerTest::test_start_session_creates_and_starts_stream`
12. `AirPlayManagerTest::test_stop_session_removes_session`

**Coverage target:** `RaopClient` ≥ 85 %, `AirPlaySession` ≥ 85 %, `AirPlayDiscovery` ≥ 80 %, `AirPlayManager` ≥ 80 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New public class/method"** → all new public classes get PHPDoc with `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry (users can stream audio to AirPlay 2 devices).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `AirPlayDiscovery::discoverDevices()` uses `MdnsDiscovery::discoverAirPlay()` (which queries both `_airplay._tcp.local.` and `_raop._tcp.local.`).
- [ ] `AirPlayDevice` extracts `deviceid` from TXT record and friendly name from mDNS name.
- [ ] `AirPlayDevice::raopPort` is correctly set from the SRV record of `_raop._tcp.local.`.
- [ ] `RaopClient` sends RTSP commands over HTTP (port 7000) to the AirPlay device.
- [ ] `RaopClient::buildAnnouncePayload()` includes `Content-Type`, `charset`, `Apple-Challenge` (if needed for auth).
- [ ] `AirPlaySession::startStream()` calls ANNOUNCE via RAOP client, then starts streaming the audio URL.
- [ ] `AirPlaySession` supports pause/resume via FLUSH and new RECORD commands.
- [ ] `AirPlayManager::startSession()` creates session, starts stream, and starts polling.
- [ ] `RemoteAirPlayClient` proxies RAOP over `RelayConsumer` for remote streaming.
- [ ] HTTP routes work correctly for listDevices, stream, pause, resume, stop, getStatus.
- [ ] `./vendor/bin/phpunit` — green; ≥ 12 new tests.
- [ ] Coverage of `RaopClient` ≥ 85 %, `AirPlaySession` ≥ 85 %.
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
git checkout -b j.5-airplay

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'AirPlay|Raop'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step J.5: AirPlay 2 support — stream audio to AirPlay 2 devices"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step J.5 (AirPlay): AirPlay 2 support — stream audio to AirPlay 2 devices" \
  --body  "Implements AirPlay 2 support: AirPlayDiscovery (mDNS), AirPlayDevice, RaopClient (RAOP/RTSP over HTTP), AirPlaySession (session + streaming), AirPlayManager, RemoteAirPlayClient (relay tunnel), HTTP API for stream control. Audio-only for J.5. Part of Phase J (Step J.5 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'j.5-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `j.5-airplay-review.md`.

(End of file - total 367 lines)
