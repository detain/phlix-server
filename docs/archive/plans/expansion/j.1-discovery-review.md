# Step J.1 — SSDP + mDNS broadcast + listener: Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit
# MUST be green; ≥ 12 new tests

# ── 2. Coverage ─────────────────────────────────────────────
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Ssdp|Mdns|Discovery'
# SsdpSocket ≥ 85%, MdnsSocket ≥ 85%, SsdpDiscovery ≥ 80%, MdnsDiscovery ≥ 80%, DiscoveryManager ≥ 80%

# ── 3. PHPStan level 9 ─────────────────────────────────────
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
# Zero new errors

# ── 4. PHPCS PSR-12 ──────────────────────────────────────────
./vendor/bin/phpcs --standard=PSR12 src/
# Clean

# ── 5. Syntax check ─────────────────────────────────────────
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Empty output

# ── 6. Docs ─────────────────────────────────────────────────
ls docs/developers/discovery.md
# File must exist

# ── 7. Config ───────────────────────────────────────────────
ls config/discovery.php
# File must exist with ssdp and mdns keys

# ── 8. CHANGELOG ───────────────────────────────────────────
grep -c "discovery" CHANGELOG.md
# Must be ≥ 1
```

## Acceptance Criteria — verify each:

- [ ] `SsdpSocket` uses `socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)` with multicast address `239.255.255.250` and port `1900`.
- [ ] `SsdpSocket::search()` sends a properly formatted M-SEARCH HTTP/1.1 request.
- [ ] `SsdpSocket::parseResponse()` extracts `LOCATION`, `SERVER`, `NT`, `USN`, `CACHE-CONTROL` fields correctly.
- [ ] `SsdpSocket` returns `[]` gracefully when no devices found or network unavailable.
- [ ] `MdnsSocket` uses `socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)` with multicast address `224.0.0.251` and port `5353`.
- [ ] `MdnsSocket::query()` sends a properly formatted mDNS query.
- [ ] `MdnsSocket::parseResponse()` extracts SRV records (port, host) and TXT records.
- [ ] `SsdpDiscovery::discoverDevices()` returns `SsdpDevice[]` with working `getDeviceId()` and `getBaseUrl()`.
- [ ] `MdnsDiscovery::discoverChromecast()` queries `_googlecast._tcp.local.` and returns `MdnsService[]`.
- [ ] `MdnsDiscovery::discoverAirPlay()` queries both `_airplay._tcp.local.` and `_raop._tcp.local.`.
- [ ] `MdnsDiscovery::discoverRoku()` queries the correct mDNS service type for Roku.
- [ ] `DiscoveryManager` combines SSDP + mDNS into a single facade with all 6 discovery methods.
- [ ] `DiscoveryManager::announcePhlexServer()` calls both SSDP NOTIFY and mDNS announcement.
- [ ] `DiscoveryServer::start()` wires into Workerman Timer; background discovery runs periodically.
- [ ] `config/discovery.php` has `ssdp.enabled`, `ssdp.announce_interval_secs`, `mdns.enabled` keys.
- [ ] ≥ 12 new unit tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] `docs/developers/discovery.md` exists and covers SSDP + mDNS protocols, multicast addresses, message formats.
- [ ] CHANGELOG.md has discovery entry.

## Non-obvious points to verify:

- SSDP M-SEARCH must include `ST:` header (search target), `MX:` header (max wait seconds), and `MAN:` header (must be `"ssdp:discover"`).
- SSDP NOTIFY announcement must include `NTS:` (notification sub-type) set to `"alive"`.
- mDNS queries use DNS record format with proper flags byte (0x0000 for query, 0x8400 for response).
- Chromecast device ID is extracted from the `id` TXT record (format: `DeviceId=<uuid>`).
- Both socket types set `IP_MULTICAST_TTL` to 4 and `IP_MULTICAST_LOOP` to 1.
- `DiscoveryServer` uses `Timer::add()` not a full worker to avoid port conflicts with HTTP server.
