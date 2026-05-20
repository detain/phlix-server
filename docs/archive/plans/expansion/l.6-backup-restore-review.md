# Step L.6 — Server Backup & Restore: Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit tests/Unit/Admin/BackupManagerTest.php tests/Unit/Admin/S3ClientTest.php
# MUST be green; ≥ 8 new tests

# ── 2. PHPStan level 9 ─────────────────────────────────────
./vendor/bin/phpstan analyze src/Admin/BackupManager.php src/Admin/S3Client.php src/Admin/RestoreResult.php --level=9 --no-progress
# Zero errors

# ── 3. PHPCS PSR-12 ──────────────────────────────────────────
./vendor/bin/phpcs --standard=PSR12 src/Admin/BackupManager.php src/Admin/S3Client.php src/Admin/RestoreResult.php
# Clean

# ── 4. Syntax check ─────────────────────────────────────────
find src/Admin -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Empty output

# ── 5. Migration check ──────────────────────────────────────
ls migrations/021_backups.sql
# File must exist
```

## Acceptance Criteria

- [ ] `createBackup()` uses `exec("mysqldump ...")` for database and `exec("tar czf ...")` for archive.
- [ ] Archive contains: mysqldump output, `config/*.php`, `data/` directory, SSL certs.
- [ ] `createBackup()` computes SHA-256 via `hash_file('sha256', $archivePath)`.
- [ ] `backups` table: id, label, file_path, size_bytes, checksum_sha256, is_s3, created_at, expires_at.
- [ ] `listBackups()` returns array with all backup metadata, sorted by `created_at DESC`.
- [ ] `deleteBackup()` calls `unlink($file_path)` and deletes DB row.
- [ ] `restore()` extracts archive, runs `mysql` import via `exec()`, verifies checksum matches.
- [ ] `RestoreResult` has `success: bool`, `message: string`, `error: ?string`.
- [ ] `S3Client::upload()` sends `PUT` request to S3 endpoint with `Authorization: AWS4-HMAC-SHA256` header.
- [ ] `S3Client::download()` sends `GET` request and streams to `$destination` file path.
- [ ] `S3Client::listObjects()` returns array of `{key, last_modified, size}` from XML response.
- [ ] `S3Client::deleteObject()` sends `DELETE` request.
- [ ] `cleanupOldBackups()` deletes oldest backups when count > `retention_count`.
- [ ] Workerman Timer registered on server start if `enabled=true` and `auto_backup_interval_days > 0`.
- [ ] Admin API: 7 endpoints as specified in plan.
- [ ] Config `config/backup.php` with all settings including S3.
- [ ] ≥ 8 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

(End of file - total 48 lines)
