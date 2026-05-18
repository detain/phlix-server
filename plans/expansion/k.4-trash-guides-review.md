# Step K.4 — TRaSH-Guides Custom Format Sync: Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit tests/unit/Arr/TrashGuidesProviderTest.php tests/unit/Arr/CustomFormatSyncerTest.php
# MUST be green; ≥ 8 new tests

# ── 2. PHPStan level 9 ─────────────────────────────────────
./vendor/bin/phpstan analyze src/Arr/TrashGuidesProvider.php src/Arr/CustomFormatSyncer.php src/Arr/SyncResult.php --level=9 --no-progress
# Zero errors

# ── 3. PHPCS PSR-12 ──────────────────────────────────────────
./vendor/bin/phpcs --standard=PSR12 src/Arr/TrashGuidesProvider.php src/Arr/CustomFormatSyncer.php src/Arr/SyncResult.php
# Clean

# ── 4. Syntax check ─────────────────────────────────────────
find src/Arr -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Empty output

# ── 5. Migration check ──────────────────────────────────────
ls migrations/00X_custom_format_sync.sql
# File must exist
```

## Acceptance Criteria

- [ ] `TrashGuidesProvider::getQualityProfiles()` fetches and parses TRaSH-Guides quality profiles JSON.
- [ ] `TrashGuidesProvider::getCustomFormats()` fetches and parses TRaSH-Guides custom formats JSON.
- [ ] `TrashGuidesProvider::getVersion()` returns the git SHA from the imported version.
- [ ] `CustomFormatSyncer::syncAll()` returns `SyncResult` with accurate counts for all 4 fields.
- [ ] Sync is idempotent — calling `syncAll()` twice with same version produces no new changes.
- [ ] `custom_format_sync` table tracks all synced items with `sync_type`, `remote_id`, `trash_version`.
- [ ] `trash_guides_sync_log` stores sync history with counts and error messages.
- [ ] `SyncController` exposes: `POST /api/v1/admin/sync/trash-guides`, `GET /api/v1/admin/sync/status`, `PUT /api/v1/admin/sync/enable`.
- [ ] Config file `config/trash_guides.php` created with `enabled`, `auto_sync_interval`, and URL settings.
- [ ] ≥ 8 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

(End of file - total 45 lines)
