# Step J.4 — Chromecast (Default Media Receiver): Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit
# MUST be green; ≥ 13 new tests

# ── 2. Coverage ─────────────────────────────────────────────
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Chromecast|Cast'
# CastApiClient ≥ 85%, CastSession ≥ 85%, CastDiscovery ≥ 80%, CastManager ≥ 80%

# ── 3. PHPStan level 9 ─────────────────────────────────────
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
# Zero new errors

# ── 4. PHPCS PSR-12 ──────────────────────────────────────────
./vendor/bin/phpcs --standard=PSR12 src/
# Clean

# ── 5. Syntax check ─────────────────────────────────────────
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Empty output

# ── 6. HTTP routes ────────────────────────────────────────────
grep -c "cast/devices" src/Server/Core/Application.php
# Must be ≥ 4
```

## Acceptance Criteria — verify each:

- [ ] `CastDiscovery::discoverDevices()` uses `MdnsDiscovery::discoverChromecast()`.
- [ ] `CastDevice` extracts `id` from TXT record (`id=`) as `deviceId`.
- [ ] `CastDevice::name` is the mDNS name stripped of `._googlecast._tcp.local.` suffix.
- [ ] `CastApiClient::connect()` sends HTTP GET to `http://{host}:{port}/setup/eureka_info` and parses JSON response.
- [ ] `CastApiClient::launchApp('CC1AD845')` sends HTTP POST to `http://{host}:{port}/apps/CC1AD845` with body `{}` (empty JSON object).
- [ ] `CastApiClient::loadMedia()` sends correct JSON payload with `contentId` (URL), `streamType` (`LIVE` or `BUFFERED`), `contentType` (MIME).
- [ ] `CastSession` uses Workerman `Timer` to poll `getMediaStatus()` every 5 seconds.
- [ ] `CastSession::getMediaStatus()` correctly parses `currentTime` (seconds) and `playerState` ('PLAYING', 'PAUSED', 'BUFFERING', 'IDLE').
- [ ] `CastSession::seek()` converts milliseconds to seconds before sending to Cast device.
- [ ] `CastManager::startSession()` creates a new `CastSession`, calls `launchApp()`, then `loadMedia()`, and starts polling.
- [ ] `CastManager` prevents duplicate sessions (same `deviceId` → replaces existing).
- [ ] `RemoteCastClient` uses `RelayConsumer::registerMount()` for remote casting.
- [ ] HTTP `POST /api/v1/cast/devices/{id}/cast` accepts JSON body `{media_url, mime_type, title, duration}`.
- [ ] HTTP `GET /api/v1/cast/devices/{id}/status` returns JSON `{state, position_ms, duration_ms, device_name}`.
- [ ] ≥ 13 new unit tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG.md has Chromecast entry.

## Non-obvious points to verify:

- Chromecast device name in mDNS: format is `Chromecast-xxxx.local.` where `xxxx` is 4 hex chars, but TXT `id` field is the actual UUID.
- Cast media load payload must include `autoplay=true` and `currentTime=0` for new media.
- Chromecast `currentTime` in media status is in seconds (not milliseconds like DLNA ticks).
- Default Media Receiver app ID is `CC1AD845` (36-char string).
- `streamType` should be `BUFFERED` for VOD, `LIVE` for live streams.
- Content type for HLS is `application/x-mpegurl` or `vnd.apple.mpegurl` depending on the stream format.
