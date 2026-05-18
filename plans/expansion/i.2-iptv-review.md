# Step I.2 — M3U / XMLTV IPTV tuner: Review Checklist

## Reviewer: run these commands and check every box.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit
# Green; ≥ 11 new tests

# ── 2. Coverage ─────────────────────────────────────────────
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'M3U|XmlTv|Iptv'
# M3UParser ≥ 85%, XmlTvParser ≥ 85%, IptvTunerDriver ≥ 80%

# ── 3. PHPStan level 9 ─────────────────────────────────────
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
# Zero new errors

# ── 4. PHPCS PSR-12 ──────────────────────────────────────────
./vendor/bin/phpcs --standard=PSR12 src/
# Clean

# ── 5. Syntax check ─────────────────────────────────────────
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Empty

# ── 6. Docs ─────────────────────────────────────────────────
ls docs/developers/iptv.md

# ── 7. Config ───────────────────────────────────────────────
grep -A5 "'iptv'" config/livetv.php
# Must have sources array with playlist_url

# ── 8. CHANGELOG ───────────────────────────────────────────
grep -c "IPTV" CHANGELOG.md
```

## Acceptance Criteria:

- [ ] `M3UParser` handles `#EXTINF:-1 tvg-id="..." tvg-name="..." group-title="..." tvg-logo="..."` format
- [ ] `M3UParser` handles radio channels (`isRadio` flag from `#EXTINF:-1 radio`)
- [ ] `XmlTvParser` parses `<programme start="..." stop="..." channel="...">` with nested `<title>`, `<desc>`, `<category>`, `<episode-num>`, `<rating>`
- [ ] `IptvTunerDriver::getStreamUrl()` returns the M3U entry's `.ts` or `.m3u8` URL
- [ ] `IptvTunerDriver` is registered in `LiveTvManager::discoverTuners()` alongside HDHomeRun
- [ ] `config/livetv.php` has `iptv.sources[].playlist_url` and `epg_url` keys
- [ ] ≥ 11 new unit tests pass
- [ ] PHPStan level 9 clean
- [ ] PHPCS clean
- [ ] `docs/developers/iptv.md` exists
- [ ] CHANGELOG updated

## Non-obvious points:

- M3U entries are trimmed for `\r\n` and `\n` line endings.
- XMLTV times are in `YYYYMMDDHHMMSS +ZONE` format; parser converts to Unix timestamp.
- IPTV channels are registered with `tuner_type = 'iptv'` in `livetv_tuners`.
- The driver supports multiple IPTV sources; each source is a separate `IptvDevice`.
