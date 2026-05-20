# Step K.2 — Bazarr + Prowlarr Clients

**Phase:** K (*arr Integration)
**Step:** K.2
**Depends on:** K.1 (Sonarr/Radarr clients are prereq for Bazarr subtitle linking)
**Review:** Yes — see `k.2-bazarr-prowlarr-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement PHP API clients for:
- **Bazarr** — subtitles management (query available subtitles for a file, download subtitle, get languages).
- **Prowlarr** — indexer management (query indexers, stats, health, trigger recheck).

Bazarr integration allows Phlex to expose subtitle metadata to the frontend. Prowlarr integration allows Phlex to expose indexer status and trigger re-authentication.

## 2. Context (what already exists)

Read first:

- `src/Arr/SonarrClient.php`, `src/Arr/RadarrClient.php` — from K.1 (follow same patterns).
- `config/arr.php` — from K.1 (extend with bazarr/prowlarr settings).
- `src/Common/Http/HttpClient.php` — existing HTTP client pattern.

## 3. Scope — files to create / modify

### Create

#### Bazarr client

- `src/Arr/BazarrClient.php`:
  - Constructor: `__construct(string $baseUrl, string $apiKey, ?StructuredLogger $logger = null)`
  - `getSubtitles(string $sonarrSeriesId, ?int $episodeFileId = null): array`
  - `getSubtitleLanguages(string $videoFilePath): array`
  - `downloadSubtitle(string $videoFilePath, string $languageCode): array`
  - `getLanguages(): array` — available languages list
  - `testConnection(): bool`

#### Prowlarr client

- `src/Arr/ProwlarrClient.php`:
  - Constructor: `__construct(string $baseUrl, string $apiKey, ?StructuredLogger $logger = null)`
  - `getIndexers(): array` — list configured indexers
  - `getIndexerStats(int $indexerId): array`
  - `getHealth(): array`
  - `triggerReindexerCheck(int $indexerId): bool`
  - `testConnection(): bool`

#### Tests

- `tests/Unit/Arr/BazarrClientTest.php`
- `tests/Unit/Arr/ProwlarrClientTest.php`

### Modify

- `config/arr.php` — extend with bazarr/prowlarr sections.
- `composer.json` — no new dependencies.
- `CHANGELOG.md` — add entry.

## 4. Approach

1. Branch from master (after K.1 merged): `git checkout -b k.2-bazarr-prowlarr`.
2. Follow the same HTTP + JSON pattern as K.1 SonarrClient.
3. Bazarr API: `GET /api/v1/subtitles?episodeId=X`, `POST /api/v1/subtitles/download`.
4. Prowlarr API: `GET /api/v1/indexer`, `GET /api/v1/indexer/{id}/stats`, `POST /api/v1/indexer/{id}/recheck`.
5. Write tests using mocks.
6. Verify: PHPUnit green, PHPStan level 9, PHPCS clean.
7. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `BazarrClientTest::test_get_subtitles_returns_array`
2. `BazarrClientTest::test_download_subtitle_sends_post`
3. `BazarrClientTest::test_get_languages`
4. `BazarrClientTest::test_test_connection_returns_bool`
5. `ProwlarrClientTest::test_get_indexers_returns_array`
6. `ProwlarrClientTest::test_get_health`
7. `ProwlarrClientTest::test_trigger_reindexer_sends_post`
8. `ProwlarrClientTest::test_test_connection_returns_bool`

## 6. Acceptance Criteria

- [ ] `BazarrClient` has all 5 methods.
- [ ] `ProwlarrClient` has all 5 methods.
- [ ] Both use plain HTTP (no Guzzle).
- [ ] Both return arrays or throw on network errors.
- [ ] Config extended with `bazarr` and `prowlarr` sections.
- [ ] ≥ 8 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b k.2-bazarr-prowlarr
# ... implement ...
./vendor/bin/phpunit
./vendor/bin/phpstan analyze src/Arr --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/Arr/
git add -A
git commit -m "Step K.2: Bazarr + Prowlarr API clients"
unset GITHUB_TOKEN
gh pr create --title "Step K.2: Bazarr + Prowlarr API clients" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `k.2-bazarr-prowlarr-review.md`.

(End of file - total 116 lines)
