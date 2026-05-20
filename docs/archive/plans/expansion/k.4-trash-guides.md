# Step K.4 — TRaSH-Guides Custom Format Sync

**Phase:** K (*arr Integration)
**Step:** K.4
**Depends on:** K.1 (RadarrClient needed for custom format API)
**Review:** Yes — see `k.4-trash-guides-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement a **TRaSH-Guides custom format sync** system that:
- Imports quality profiles and custom formats from [TRaSH-Guides](https://trash-guides.info/) JSON files.
- Syncs them to Radarr via the RadarrClient (from K.1).
- Allows admins to pick which custom formats to apply to which quality profiles.
- Stores sync state (last sync time, applied format versions) so repeated syncs are idempotent.

TRaSH-Guides publishes canonical JSON files for quality profiles and custom formats at `https://raw.githubusercontent.com/TRaSH-/Guides/main/docs/json/`.

## 2. Context (what already exists)

Read first:

- `src/Arr/RadarrClient.php` — from K.1 (has `getQualityProfiles`, `getCustomFormats`, `addMovie`).
- `config/arr.php` — from K.1.
- `src/Media/Metadata/` — existing metadata/quality provider pattern.
- `src/Media/Library/LibraryManager.php` — existing library.

## 3. Scope — files to create / modify

### Create

#### Custom format sync logic

- `src/Arr/TrashGuidesProvider.php`:
  - Fetches quality profiles and custom formats from TRaSH-Guides GitHub raw JSON.
  - `getQualityProfiles(): array` — parses TRaSH-Guides quality profiles JSON.
  - `getCustomFormats(): array` — parses TRaSH-Guides custom formats JSON.
  - `getVersion(): string` — git commit SHA of the imported version.
  - Cache results for 24h (same URL shouldn't be fetched repeatedly).

- `src/Arr/CustomFormatSyncer.php`:
  - Syncs TRaSH-Guides formats to Radarr via RadarrClient.
  - `__construct(RadarrClient $radarr, TrashGuidesProvider $provider, Connection $db, ?StructuredLogger $logger)`
  - `syncAll(): SyncResult` — sync all quality profiles and custom formats.
  - `syncCustomFormats(): int` — sync only custom formats, return count.
  - `syncQualityProfiles(): int` — sync only quality profiles, return count.
  - `getLastSyncTime(): ?int` — unix timestamp of last successful sync.
  - `setEnabled(bool $enabled): void` — enable/disable auto-sync.

- `src/Arr/SyncResult.php`:
  - Value object returned by `syncAll()`:
  - ```php
    class SyncResult
    {
        public function __construct(
            public readonly int $customFormatsAdded,
            public readonly int $customFormatsUpdated,
            public readonly int $qualityProfilesAdded,
            public readonly int $qualityProfilesUpdated,
            public readonly string $version,
            public readonly \DateTimeImmutable $syncedAt,
        ) {}
    }
    ```

#### Database schema

- `migrations/00X_custom_format_sync.sql`:
  ```sql
  CREATE TABLE custom_format_sync (
    id CHAR(36) PRIMARY KEY,
    sync_type VARCHAR(50) NOT NULL,  -- 'custom_format' or 'quality_profile'
    remote_id INT NOT NULL,
    remote_name VARCHAR(255) NOT NULL,
    trash_version VARCHAR(40) NOT NULL,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_type_remote (sync_type, remote_id)
  );
  CREATE TABLE trash_guides_sync_log (
    id CHAR(36) PRIMARY KEY,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    custom_formats_added INT DEFAULT 0,
    custom_formats_updated INT DEFAULT 0,
    quality_profiles_added INT DEFAULT 0,
    quality_profiles_updated INT DEFAULT 0,
    version VARCHAR(40) NOT NULL,
    error_message TEXT
  );
  ```

#### API endpoints

- `src/Server/Http/Controllers/Arr/SyncController.php`:
  - `POST /api/v1/admin/sync/trash-guides` — trigger full sync (admin only).
  - `GET /api/v1/admin/sync/status` — get last sync time and results.
  - `PUT /api/v1/admin/sync/enable` — enable/disable auto-sync.

#### Config

- `config/trash_guides.php`:
  ```php
  return [
      'enabled' => false,
      'auto_sync_interval' => 86400, // 24 hours
      'custom_formats_url' => 'https://raw.githubusercontent.com/TRaSH-/Guides/main/docs/json/radarr/radarr-collection-of-custom-formats.json',
      'quality_profiles_url' => 'https://raw.githubusercontent.com/TRaSH-/Guides/main/docs/json/radarr/radarr-setup-quality-profiles-parent.json',
  ];
  ```

#### Tests

- `tests/Unit/Arr/TrashGuidesProviderTest.php`
- `tests/Unit/Arr/CustomFormatSyncerTest.php`

### Modify

- `config/arr.php` — no changes needed (sync settings go in `config/trash_guides.php`).
- `composer.json` — no new dependencies.
- `CHANGELOG.md` — add entry.

## 4. Approach

1. Branch from master (after K.1 merged): `git checkout -b k.4-trash-guides`.
2. Fetch TRaSH-Guides JSON using `file_get_contents` with stream context (same pattern as other HTTP clients).
3. Parse JSON → extract quality profiles/custom formats → compare with `custom_format_sync` table → apply diff to Radarr via RadarrClient.
4. Use `trash_guides_sync_log` to store sync history.
5. Prevent re-syncing same version by checking `trash_version` in sync log.
6. Write tests using mocks.
7. Verify: PHPUnit green, PHPStan level 9, PHPCS clean.
8. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `TrashGuidesProviderTest::test_get_quality_profiles_parses_json`
2. `TrashGuidesProviderTest::test_get_custom_formats_parses_json`
3. `TrashGuidesProviderTest::test_get_version_returns_sha`
4. `CustomFormatSyncerTest::test_sync_custom_formats_creates_new`
5. `CustomFormatSyncerTest::test_sync_custom_formats_updates_existing`
6. `CustomFormatSyncerTest::test_sync_all_returns_sync_result`
7. `CustomFormatSyncerTest::test_get_last_sync_time`
8. `CustomFormatSyncerTest::test_sync_is_idempotent_for_same_version`

## 6. Acceptance Criteria

- [ ] `TrashGuidesProvider` fetches and parses both TRaSH-Guides JSON files.
- [ ] `CustomFormatSyncer::syncAll()` returns `SyncResult` with accurate counts.
- [ ] Sync is idempotent (same version → no duplicate entries in Radarr).
- [ ] `custom_format_sync` table tracks all synced items.
- [ ] `trash_guides_sync_log` stores sync history with counts.
- [ ] `SyncController` exposes: POST `/sync/trash-guides`, GET `/sync/status`, PUT `/sync/enable`.
- [ ] Config file `config/trash_guides.php` created.
- [ ] ≥ 8 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b k.4-trash-guides
# ... implement ...
./vendor/bin/phpunit tests/Unit/Arr/TrashGuidesProviderTest.php tests/Unit/Arr/CustomFormatSyncerTest.php
./vendor/bin/phpstan analyze src/Arr --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/Arr/
git add -A
git commit -m "Step K.4: TRaSH-Guides custom format sync"
unset GITHUB_TOKEN
gh pr create --title "Step K.4: TRaSH-Guides custom format sync" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `k.4-trash-guides-review.md`.

(End of file - total 153 lines)
