# Step I.1 — HDHomeRun tuner driver: Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit
# MUST be green; ≥ 9 new tests

# ── 2. Coverage ─────────────────────────────────────────────
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'HdHomeRun|TunerDriver'
# HdHomeRunDiscovery ≥ 85%, HdHomeRunApiClient ≥ 85%, HdHomeRunTunerDriver ≥ 80%

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
ls docs/developers/hdhomerun.md
# File must exist

# ── 7. Config ───────────────────────────────────────────────
ls config/livetv.php
# File must exist with hdhomerun key

# ── 8. CHANGELOG ───────────────────────────────────────────
grep -c "HDHomeRun" CHANGELOG.md
# Must be ≥ 1
```

## Acceptance Criteria — verify each:

- [ ] `TunerDriverInterface` is defined in `src/LiveTv/Tuners/TunerDriverInterface.php`
- [ ] `HdHomeRunDiscovery` sends UDP M-SEARCH on port 1900
- [ ] `HdHomeRunDiscovery::discover()` returns `[]` gracefully on network failure
- [ ] `HdHomeRunApiClient` fetches `/lineup.json` and `/discover.json`
- [ ] `HdHomeRunTunerDriver` implements `TunerDriverInterface`
- [ ] `LiveTvManager` no longer references `/dev/dvb`
- [ ] All 7 TunerDriverInterface methods are implemented by `HdHomeRunTunerDriver`
- [ ] `config/livetv.php` has `hdhomerun.enabled`, `ssdp_timeout_secs` keys
- [ ] ≥ 9 new unit tests pass
- [ ] PHPStan level 9 clean
- [ ] PHPCS PSR-12 clean
- [ ] `docs/developers/hdhomerun.md` exists and covers SSDP + HTTP API
- [ ] CHANGELOG.md has HDHomeRun entry

## Non-obvious points to verify:

- The SSDP discovery uses `socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)` with
  broadcast address `239.255.255.250` and port `1900`.
- The device XML is fetched via `file_get_contents()` with 3-second timeout.
- Stream URLs returned by `getStreamUrl()` follow `hdhomerun://<device-ip>/ch<num>`
  or direct HTTP HLS URL pattern.
- `HdHomeRunDevice` is a plain value object (readonly properties, no logic).
