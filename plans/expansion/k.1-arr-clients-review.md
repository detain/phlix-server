# Step K.1 — Sonarr/Radarr API Clients: Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit tests/unit/Arr/
# MUST be green; ≥ 10 new tests

# ── 2. PHPStan level 9 ─────────────────────────────────────
./vendor/bin/phpstan analyze src/Arr --level=9 --no-progress
# Zero errors

# ── 3. PHPCS PSR-12 ──────────────────────────────────────────
./vendor/bin/phpcs --standard=PSR12 src/Arr/
# Clean

# ── 4. Syntax check ─────────────────────────────────────────
find src/Arr -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Empty output
```

## Acceptance Criteria

- [ ] `SonarrClient` has all 9 methods: `getSeries`, `getSeriesById`, `getEpisodeFile`, `getQueue`, `getWantedMissing`, `getQualityProfiles`, `getTagList`, `addSeries`, `triggerDownload`.
- [ ] `RadarrClient` has all 9 methods: `getMovies`, `getMovieById`, `getQueue`, `getQualityProfiles`, `getCustomFormats`, `getTagList`, `addMovie`, `triggerDownload`.
- [ ] Both clients use `file_get_contents` / `curl` for HTTP (no Guzzle or Symfony HttpClient).
- [ ] Both clients return arrays (decoded JSON) or throw on network errors.
- [ ] `testConnection()` sends `GET /api/v3/system/status` and returns bool.
- [ ] Config file `config/arr.php` created with sonarr/radarr sections.
- [ ] `ArrClientInterface` defines `getQueue`, `getQualityProfiles`, `getTagList`, `testConnection`.
- [ ] `ArrClientFactory` creates clients from config.
- [ ] ≥ 10 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

(End of file - total 44 lines)
