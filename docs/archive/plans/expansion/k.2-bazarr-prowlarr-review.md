# Step K.2 — Bazarr + Prowlarr Clients: Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit tests/Unit/Arr/BazarrClientTest.php tests/Unit/Arr/ProwlarrClientTest.php
# MUST be green; ≥ 8 new tests

# ── 2. PHPStan level 9 ─────────────────────────────────────
./vendor/bin/phpstan analyze src/Arr/BazarrClient.php src/Arr/ProwlarrClient.php --level=9 --no-progress
# Zero errors

# ── 3. PHPCS PSR-12 ──────────────────────────────────────────
./vendor/bin/phpcs --standard=PSR12 src/Arr/BazarrClient.php src/Arr/ProwlarrClient.php
# Clean

# ── 4. Syntax check ─────────────────────────────────────────
find src/Arr -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Empty output
```

## Acceptance Criteria

- [ ] `BazarrClient` has all 5 methods: `getSubtitles`, `getSubtitleLanguages`, `downloadSubtitle`, `getLanguages`, `testConnection`.
- [ ] `ProwlarrClient` has all 5 methods: `getIndexers`, `getIndexerStats`, `getHealth`, `triggerReindexerCheck`, `testConnection`.
- [ ] Both use plain HTTP (file_get_contents/curl, no Guzzle or Symfony HttpClient).
- [ ] Both return arrays (decoded JSON) or throw on network errors.
- [ ] Config `config/arr.php` extended with `bazarr` and `prowlarr` sections.
- [ ] ≥ 8 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

(End of file - total 33 lines)
