# Step J.5 — AirPlay 2: Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit
# MUST be green; ≥ 12 new tests

# ── 2. Coverage ─────────────────────────────────────────────
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'AirPlay|Raop'
# RaopClient ≥ 85%, AirPlaySession ≥ 85%, AirPlayDiscovery ≥ 80%, AirPlayManager ≥ 80%

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
grep -c "airplay/devices" src/Server/Core/Application.php
# Must be ≥ 4
```

## Acceptance Criteria — verify each:

- [ ] `AirPlayDiscovery::discoverDevices()` queries both `_airplay._tcp.local.` and `_raop._tcp.local.`.
- [ ] `AirPlayDevice` extracts `deviceid` (UUID) from TXT record as `deviceId`.
- [ ] `AirPlayDevice::raopPort` is extracted from SRV record of `_raop._tcp.local.` (typically port 7000 or 7001).
- [ ] `AirPlayDevice::name` is the friendly name from mDNS (stripped of `._airplay._tcp.local.`).
- [ ] `AirPlayDevice::supportsVideo` is determined from TXT `features` flag (bit 3 = video support).
- [ ] `RaopClient` sends RTSP-over-HTTP to the RAOP port (not the main AirPlay port).
- [ ] `RaopClient::buildAnnouncePayload()` includes `Content-Type: application/sdp` and proper SDP body.
- [ ] `AirPlaySession::startStream()` calls ANNOUNCE via RAOP client before starting to stream.
- [ ] `AirPlaySession::pause()` sends FLUSH command with current RTP time.
- [ ] `AirPlaySession::resume()` sends new RECORD command to resume.
- [ ] `AirPlaySession::stop()` sends TEARDOWN command to close the session.
- [ ] `AirPlayManager::startSession()` creates `AirPlaySession`, connects, starts streaming.
- [ ] `RemoteAirPlayClient` uses `RelayConsumer::registerMount()` for remote streaming.
- [ ] HTTP `POST /api/v1/airplay/devices/{id}/stream` accepts JSON body `{audio_url, content_type, duration}`.
- [ ] ≥ 12 new unit tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG.md has AirPlay entry.

## Non-obvious points to verify:

- AirPlay uses RTSP (Real Time Streaming Protocol) over HTTP for control. Request uses `POST` with `Content-Type: application/x-www-form-urlencoded` or `application/sdp` for ANNOUNCE.
- RAOP SDP body for AAC audio must include `a=rtpmap:96 mpeg4-generic/44100/2` and `a=fmtp:96` with specific audio codec parameters.
- RTP time is in samples (not seconds). 1 second of AAC audio at 44100 Hz = 44100 RTP time units.
- AirPlay devices announce two mDNS services: `_airplay._tcp.local.` (main control, port typically 7000) and `_raop._tcp.local.` (audio streaming, port 7001 or similar).
- TXT `features` field is a bitmask: bit 3 (0x08) = video support, bit 4 (0x10) = photo support, bit 5 (0x20) = audio support.
- RTSP CSeq (sequence number) increments on every request.
- RTSP Session header is returned in 200 OK responses to ANNOUNCE/RECORD and must be included in subsequent requests.
