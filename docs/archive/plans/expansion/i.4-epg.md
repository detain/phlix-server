# Step I.4 — Schedules Direct EPG

**Phase:** I (Live TV / DVR / IPTV)
**Step:** I.4
**Depends on:** I.1
**Review:** Yes — see `i.4-epg-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement Schedules Direct (SD) API integration to fetch authoritative TV
guide data (program listings, series info, artwork, ratings) and persist them
via `GuideManager`/`ChannelManager`. Schedules Direct is a SOAP/REST hybrid
service at `https://api.schedulesdirect.tmsglobal.com` (or the newer JSON API).

## 2. Context (what already exists)

- `src/LiveTv/GuideManager.php` — already has `upsertProgram()`,
  `importGuideData()`, `getProgram()`, `searchPrograms()`. I.4 wires
  SD data into these methods.
- `src/LiveTv/ChannelManager.php` — channel CRUD; SD lineups map to
  channel maps.
- `config/livetv.php` — I.1 added `hdhomerun`; I.4 adds `schedules_direct`.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase I table — I.4 is the SD EPG step.
- `src/Media/Metadata/` — existing metadata providers (TmdbProvider etc.);
  SD sits alongside these as an EPG source.

## 3. Scope — files to create / modify

### Create

#### New classes — Schedules Direct client

- `src/LiveTv/Epg/SchedulesDirect/SdApiClient.php` — HTTP JSON client for SD:

  ```php
  class SdApiClient
  {
      public const BASE_URL = 'https://api.schedulesdirect.tmsglobal.com';

      public function __construct(
          private readonly string $token,
          private readonly ?LoggerInterface $logger = null,
          private readonly int $timeoutSecs = 30,
      ) {}

      /** GET /token — validate token. */
      public function validateToken(): bool {}

      /** POST /token — obtain a token. */
      public function fetchToken(string $username, string $password): ?string {}

      /** GET /headend/{systemId}/{type} — available stations for a lineup. */
      public function getStations(string $systemId): array {}

      /** GET /schedules/md5/{stationIds} — schedule hash (to detect changes). */
      public function getScheduleMd5(array $stationIds): array {}

      /** POST /schedules — full schedule data for stations in a time window. */
      public function getSchedules(array $stationIds, int $startDate, int $endDate): array {}

      /** POST /programs/{programIds} — program metadata (titles, descriptions, etc.). */
      public function getPrograms(array $programIds): array {}
  }
  ```

- `src/LiveTv/Epg/SchedulesDirect/SdLineupHandler.php` — maps SD lineups
  to Phlex channels:

  ```php
  class SdLineupHandler
  {
      public function __construct(
          private readonly SdApiClient $client,
          private readonly ChannelManager $channelManager,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Fetch available lineups for the token's account country. */
      public function getAvailableLineups(): array {}

      /** Fetch the station list for a given lineup and register channels. */
      public function importLineup(string $lineupId): array {}
  }
  ```

- `src/LiveTv/Epg/SchedulesDirect/SdProgramMapper.php` — maps SD program
  data to `GuideManager::upsertProgram()` format:

  ```php
  class SdProgramMapper
  {
      /** Map SD schedule + program data to an array suitable for GuideManager::upsertProgram(). */
      public function map(array $scheduleEntry, array $programData): array {}

      /** Map SD station data to channel creation data. */
      public function mapStation(array $station): array {}
  }
  ```

- `src/LiveTv/Epg/SchedulesDirect/SdEpgService.php` — orchestrates SD
  fetch → parse → persist cycle:

  ```php
  class SdEpgService
  {
      public function __construct(
          private readonly SdApiClient $client,
          private readonly SdLineupHandler $lineupHandler,
          private readonly SdProgramMapper $mapper,
          private readonly GuideManager $guideManager,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Full EPG sync: fetch schedules, programs, upsert to GuideManager. */
      public function syncEpg(array $stationIds, int $daysAhead = 14): array {}

      /** Quick EPG sync for a single station. */
      public function syncStation(string $stationId, int $daysAhead = 14): array {}
  }
  ```

- `src/LiveTv/Epg/SchedulesDirect/SdEpgServiceFactory.php`:

  ```php
  final class SdEpgServiceFactory
  {
      public static function build(
          array $config,
          ChannelManager $channelManager,
          GuideManager $guideManager,
          ?LoggerInterface $logger = null,
      ): SdEpgService {}
  }
  ```

#### Config

- `config/livetv.php` — add `schedules_direct` key:
  ```php
  'schedules_direct' => [
      'enabled' => true,
      'username' => '',
      'password' => '',  // stored encrypted, not plaintext
      'token_cache_path' => '/var/phlex/sd_token.json',
      'lineup_id' => null,  // set to a specific lineup or null for auto
      'sync_hours_ahead' => 336,  // 14 days
  ],
  ```

#### Tests

- `tests/unit/LiveTv/Epg/SchedulesDirect/SdApiClientTest.php`
- `tests/unit/LiveTv/Epg/SchedulesDirect/SdProgramMapperTest.php`
- `tests/unit/LiveTv/Epg/SchedulesDirect/SdEpgServiceTest.php`

#### Documentation

- `docs/developers/schedules-direct.md` — SD API overview, token
  handling, available endpoints, data model, config keys.

### Modify

- `config/livetv.php` — add `schedules_direct` key (done in Create above).
- `src/LiveTv/GuideManager.php` — add `upsertProgram()` accepts optional
  `md5` / `md5_previous` fields for SD schedule diffing; add
  `getProgramsByMd5(md5)` check before full upsert.
- `src/LiveTv/LiveTvManager.php` — after I.1+I.2+I.3, wire `SdEpgService`
  as an optional dependency; `discoverTuners()` may trigger SD lineup
  detection if HDHomeRun or DVB-T devices have a lineup ID.
- `composer.json` — no new dependencies (HTTP client is plain
  `file_get_contents` with stream context).
- `CHANGELOG.md` — add entry: "Added: Schedules Direct EPG integration".

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master, branch `i.4-epg`.
2. **API client.** `SdApiClient` sends signed HTTP requests (token in
   `Authorization: Bearer <token>` header). Token can be pre-seeded or
   fetched via `fetchToken()` using HTTP Basic Auth. Handles HTTP 400/401
   gracefully; `validateToken()` returns `false` on 401.
3. **Lineup handler.** `SdLineupHandler` first calls `getAvailableLineups()`
   to list account lineups, then `getStations(lineupId)` to get channel list.
   Maps each station to `ChannelManager::createChannel()`.
4. **Program mapper.** `SdProgramMapper::map()` converts SD's schedule hash +
   program data into the flat array `GuideManager::upsertProgram()` expects.
   Handles `md5` for skip-if-unchanged optimization.
5. **EPG service.** `SdEpgService::syncEpg()` fetches station IDs for the
   registered lineup, calls `getSchedules()`, calls `getPrograms()` for
   unique program IDs, maps and upserts. Returns stats: `['imported' => N,
   'errors' => M]`.
6. **Integration.** `LiveTvManager` after I.3 gets an optional `SdEpgService`.
   When `schedules_direct.enabled = true`, the SD EPG sync runs after channel
   scan completes, populating the guide automatically.
7. **Tests.** Three test files per §5.
8. **Verification bar.**
9. **Docs.**
10. **Commit + PR + merge.**

## 5. Tests (REQUIRED)

Unit tests (coverage ≥ 85 % on every new class):

1. `SdApiClientTest::test_validate_token_returns_bool`
2. `SdApiClientTest::test_fetch_token_returns_string_on_success`
3. `SdApiClientTest::test_fetch_token_returns_null_on_bad_credentials`
4. `SdApiClientTest::test_get_stations_returns_array`
5. `SdApiClientTest::test_get_schedules_returns_array`
6. `SdApiClientTest::test_get_programs_returns_array`
7. `SdProgramMapperTest::test_map_converts_sd_schedule_to_guide_entry`
8. `SdProgramMapperTest::test_map_station_to_channel_data`
9. `SdProgramMapperTest::test_map_handles_null_episode_title`
10. `SdEpgServiceTest::test_sync_epg_imports_programs`
11. `SdEpgServiceTest::test_sync_station_imports_and_returns_stats`
12. `SdEpgServiceTest::test_sync_handles_api_errors_gracefully`

**Coverage target:** `SdApiClient` ≥ 80 %, `SdProgramMapper` ≥ 85 %,
`SdEpgService` ≥ 80 %.

## 6. Documentation

Matrix rows that apply:
- **"Anything"** → `docs/developers/schedules-direct.md` covers SD API
  auth, endpoints, data model, and config.
- **"New public class/method"** → all new classes get PHPDoc with `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry.

## 7. Acceptance criteria

- [ ] `SdApiClient` authenticates via token (pre-seeded or `fetchToken()`).
- [ ] `SdApiClient::validateToken()` returns `false` on 401.
- [ ] `SdApiClient::getSchedules()` returns schedule entries for station IDs.
- [ ] `SdApiClient::getPrograms()` returns program metadata for program IDs.
- [ ] `SdLineupHandler::importLineup()` creates `ChannelManager` channels
      from SD station data.
- [ ] `SdProgramMapper::map()` produces a `GuideManager::upsertProgram()`-compatible array.
- [ ] `SdEpgService::syncEpg()` upserts all programs and returns import stats.
- [ ] `config/livetv.php` has `schedules_direct` key with `username`,
      `password`, `token_cache_path`.
- [ ] `./vendor/bin/phpunit` — green; ≥ 12 new tests.
- [ ] Coverage targets met.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/schedules-direct.md` written.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual

```bash
cd /home/sites/phlex
git status --short && git branch --show-current && git pull --ff-only origin master
git checkout -b i.4-epg
# ... implement ...
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'SdApi|SdProgram|SdEpg'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
git add -A
git commit -m "Step I.4: Schedules Direct EPG integration"
unset GITHUB_TOKEN
gh pr create \
  --title "Step I.4 (Live TV): Schedules Direct EPG integration" \
  --body  "Adds Schedules Direct API client: token auth, lineup/channel sync, program/guide import. Part of Phase I (Step I.4 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
git status --short && git branch --show-current && git log --oneline -1 && git branch --list 'i.4-*'
```
