# Step J.3 — DLNA AVTransport "play to": Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit
# MUST be green; ≥ 11 new tests

# ── 2. Coverage ─────────────────────────────────────────────
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Renderer|PlayTo'
# RendererControlClient ≥ 85%, PlayToSession ≥ 85%, PlayToManager ≥ 80%

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
grep -c "dlna/renderers" src/Server/Core/Application.php
# Must be ≥ 4 (list, play, pause/stop/seek, status)
```

## Acceptance Criteria — verify each:

- [ ] `RendererDiscovery::discoverRenderers()` queries SSDP with `ST: urn:schemas-upnp-org:device:MediaRenderer:1`.
- [ ] `RendererControlClient` builds correct SOAP envelope with `SOAPACTION` header.
- [ ] `RendererControlClient::setAvTransportUri()` includes DIDL-Lite metadata with title, artist, duration, resource URL.
- [ ] `RendererControlClient::play()` sends `Speed=1` in SOAP body.
- [ ] `RendererControlClient::getPositionInfo()` correctly parses `RelTime` from response (format: `HH:MM:SS`).
- [ ] `PlayToSession` uses Workerman `Timer` to poll `getPositionInfo()` every 5 seconds.
- [ ] `PlayToSession::seek()` converts ticks to `HH:MM:SS` format before calling `RendererControlClient::seek()`.
- [ ] `PlayToManager::startSession()` creates `RendererControlClient` from `rendererId` → `locationUrl` mapping.
- [ ] `PlayToManager` prevents duplicate sessions for the same renderer (replaces existing if restarted).
- [ ] `RemoteRendererClient` uses `RelayConsumer::registerMount()` for the relay path.
- [ ] `PlaybackController::startPlayToSession()` creates both local `PlaybackSession` and remote `PlayToSession`.
- [ ] HTTP `POST /api/v1/dlna/renderers/{id}/play` accepts JSON body `{item_id, uri, metadata}` and starts session.
- [ ] HTTP `GET /api/v1/dlna/renderers/{id}/status` returns JSON with `state`, `position`, `duration`, `renderer_name`.
- [ ] ≥ 11 new unit tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG.md has play-to entry.

## Non-obvious points to verify:

- DIDL-Lite metadata in `SetAVTransportURI` must include proper XML declaration and namespace declarations.
- `RelTime` position parsing: response format is `HH:MM:SS` (e.g., `00:05:30`), convert to ticks (1 tick = 100 nanoseconds, 1 second = 10,000,000 ticks) for internal storage.
- `SOAPACTION` header format: `"urn:schemas-upnp-org:service:AVTransport:1#Play"` (double quotes required).
- Renderer control URL is extracted from device description XML's `<controlURL>` element under `<service>` for `AVTransport:1`.
- `PlayToSession` state machine: idle → (setMediaItem) → buffering → (play) → playing → (pause) → paused → (stop/play) → playing/idle.
