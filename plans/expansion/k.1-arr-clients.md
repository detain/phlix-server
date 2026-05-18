# Step K.1 — Sonarr/Radarr API Clients

**Phase:** K (*arr Integration)
**Step:** K.1
**Depends on:** Master (no prior *arr steps)
**Review:** Yes — see `k.1-arr-clients-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement PHP API clients for Sonarr (TV) and Radarr (Movies) that Phlex can use to:
- Query the media library status in Sonarr/Radarr (which series/movies are tracked, qualities, profiles).
- Trigger automatic downloads when a media item is not yet available in the local library.
- Get quality profiles and custom formats from Radarr.
- Monitor download/processing status.

These clients live in `src/Arr/` (the shared namespace) and are used by Phlex's library manager and download logic. They communicate via the *arr HTTP API (v3) with JSON payloads.

## 2. Context (what already exists)

Read first, do not modify until §4:

- `src/Common/Http/HttpClient.php` — existing HTTP client pattern.
- `config/` — existing config structure.
- `src/Media/Library/LibraryManager.php` — existing library management.

## 3. Scope — files to create / modify

### Create

#### Sonarr client

- `src/Arr/SonarrClient.php`:
  - Constructor: `__construct(string $baseUrl, string $apiKey, ?StructuredLogger $logger = null)`
  - `getSeries(): array` — list all tracked series
  - `getSeriesById(int $sonarrSeriesId): array`
  - `getEpisodeFile(int $episodeId): array`
  - `getQueue(): array` — pending downloads
  - `getWantedMissing(?int $startSeason = null): array` — missing episodes
  - `getQualityProfiles(): array`
  - `getTagList(): array`
  - `addSeries(int|array $tvdbId, int $qualityProfileId, int $rootFolder, ?string $monitor = 'all'): array` — add series to Sonarr
  - `triggerDownload(int $episodeId): bool` — mark episode as wanted and force search

#### Radarr client

- `src/Arr/RadarrClient.php`:
  - Constructor: `__construct(string $baseUrl, string $apiKey, ?StructuredLogger $logger = null)`
  - `getMovies(): array` — list all tracked movies
  - `getMovieById(int $radarrId): array`
  - `getQueue(): array` — pending downloads
  - `getQualityProfiles(): array`
  - `getCustomFormats(): array` — from K.4
  - `getTagList(): array`
  - `addMovie(int|array $tmdbId, int $qualityProfileId, string $rootFolder, bool $monitored = true): array`
  - `triggerDownload(int $movieId): bool`

#### Shared

- `src/Arr/ArrClientInterface.php` — common interface for Sonarr/Radarr clients:
  ```php
  interface ArrClientInterface
  {
      public function getQueue(): array;
      public function getQualityProfiles(): array;
      public function getTagList(): array;
      public function testConnection(): bool;
  }
  ```

- `src/Arr/ArrClientFactory.php` — factory to build Sonarr/Radarr clients from config:
  ```php
  class ArrClientFactory
  {
      public function __construct(private readonly array $config) {}

      public function createSonarrClient(): SonarrClient {}
      public function createRadarrClient(): RadarrClient {}
  }
  ```

#### Tests

- `tests/unit/Arr/SonarrClientTest.php`
- `tests/unit/Arr/RadarrClientTest.php`
- `tests/unit/Arr/ArrClientFactoryTest.php`

### Modify

- `config/` — add `config/arr.php` with Sonarr/Radarr connection settings:
  ```php
  return [
      'sonarr' => [
          'url' => 'http://localhost:8989',
          'api_key' => '',
          'enabled' => false,
      ],
      'radarr' => [
          'url' => 'http://localhost:7878',
          'api_key' => '',
          'enabled' => false,
      ],
  ];
  ```
- `composer.json` — no new dependencies (plain HTTP via file_get_contents).
- `CHANGELOG.md` — add entry.

## 4. Approach

1. Branch from master: `git checkout -b k.1-arr-clients`.
2. Build SonarrClient using `file_get_contents` with stream context for GET, and `curl` for POST/PUT/DELETE (matching existing HttpClient pattern if one exists, otherwise plain PHP).
3. Use JSON encode/decode for request/response bodies.
4. Use `testConnection()` → `GET /api/v3/system/status` to verify credentials.
5. Handle `HttpException` (404 not found, 401 unauthorized) gracefully with null returns or false.
6. Write tests using mocks for the HTTP layer.
7. Verify: PHPUnit green, PHPStan level 9, PHPCS clean.
8. Commit + PR + merge.

## 5. Tests (REQUIRED)

1. `SonarrClientTest::test_get_series_returns_array`
2. `SonarrClientTest::test_get_queue_parses_items`
3. `SonarrClientTest::test_add_series_builds_correct_payload`
4. `SonarrClientTest::test_trigger_download_sends_post`
5. `RadarrClientTest::test_get_movies_returns_array`
6. `RadarrClientTest::test_get_queue_parses_items`
7. `RadarrClientTest::test_add_movie_builds_correct_payload`
8. `RadarrClientTest::test_trigger_download_sends_post`
9. `ArrClientFactoryTest::test_creates_sonarr_client_with_config`
10. `ArrClientFactoryTest::test_creates_radarr_client_with_config`

## 6. Acceptance Criteria

- [ ] SonarrClient has all 9 methods listed in §3.
- [ ] RadarrClient has all 9 methods listed in §3.
- [ ] Both clients use `file_get_contents` / `curl` for HTTP (no Guzzle).
- [ ] Both clients return arrays (decoded JSON) or throw on network errors.
- [ ] `testConnection()` returns bool.
- [ ] Config file `config/arr.php` created with sonarr/radarr sections.
- [ ] ≥ 10 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout -b k.1-arr-clients
# ... implement ...
./vendor/bin/phpunit
./vendor/bin/phpstan analyze src/Arr --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/Arr/
git add -A
git commit -m "Step K.1: Sonarr/Radarr API clients"
unset GITHUB_TOKEN
gh pr create --title "Step K.1: Sonarr/Radarr API clients" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `k.1-arr-clients-review.md`.

(End of file - total 162 lines)
