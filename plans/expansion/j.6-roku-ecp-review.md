# Step J.6 — Roku ECP "send to Roku": Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit
# MUST be green; ≥ 13 new tests

# ── 2. Coverage ─────────────────────────────────────────────
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Roku'
# RokuEcpClient ≥ 85%, RokuSession ≥ 85%, RokuDiscovery ≥ 80%, RokuManager ≥ 80%

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
grep -c "roku/devices" src/Server/Core/Application.php
# Must be ≥ 4
```

## Acceptance Criteria — verify each:

- [ ] `RokuDiscovery::discoverDevices()` queries mDNS for `_ roku-ecnp._tcp.local.` (note the leading space in the service name).
- [ ] `RokuDevice::host` is extracted from the SRV record's target hostname (resolve to IP).
- [ ] `RokuDevice::port` defaults to 8060.
- [ ] `RokuEcpClient` sends plain HTTP (no SOAP, no special encoding).
- [ ] `RokuEcpClient::sendKeypress()` sends `POST /input` with `key={keyName}` in body and `Content-Type: application/x-www-form-urlencoded`.
- [ ] `RokuEcpClient::launchChannel()` sends `POST /launch/{channelId}` (no body needed).
- [ ] `RokuEcpClient::playMedia()` sends `POST /media/play` with form data: `url`, `mimeType`, `title`, `thumbnail`.
- [ ] `RokuEcpClient::getDeviceInfo()` parses XML response from `GET /query/device-info`.
- [ ] `RokuEcpClient::getPlayerState()` returns current player state (from `GET /query/player-info`).
- [ ] `RokuSession::playMedia()` first sends `launchChannel('dev')` to launch the built-in media player channel, then sends `playMedia()`.
- [ ] `RokuSession` uses Workerman `Timer` to poll `getPlayerState()` every 5 seconds.
- [ ] `RokuManager::startSession()` creates `RokuSession`, launches channel, starts polling.
- [ ] `RemoteRokuClient` uses `RelayConsumer::registerMount()` for remote control.
- [ ] HTTP `POST /api/v1/roku/devices/{id}/send` accepts JSON body `{media_url, mime_type, title, thumbnail}`.
- [ ] HTTP `POST /api/v1/roku/devices/{id}/key/{keyName}` sends the named keypress.
- [ ] ≥ 13 new unit tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG.md has Roku entry.

## Non-obvious points to verify:

- The mDNS service name for Roku ECP is `_ roku-ecnp._tcp.local.` (with a leading space before `roku`). This is an unusual naming convention used by Roku.
- ECP key names: `Play`, `Pause`, `Back`, `Home`, `Up`, `Down`, `Left`, `Right`, `Select`, `Rev`, `Fwd`, `InstantReplay`, `Info`, `BackSpace`, `Search`, `Enter`.
- Media player channel ID on most Roku devices is `dev` (built-in developer channel for media playback testing).
- Form data for `/media/play` uses `application/x-www-form-urlencoded` encoding.
- Device info XML response has root element `<device-info>` with child elements `<friendlyName>`, `<modelName>`, `<softwareVersion>`, `<serialNumber>`.
- Player state from `GET /query/player-info` returns `<player>` element with `<state>` (Stopped, Playing, Paused, Buffering) and `<position>` elements.
