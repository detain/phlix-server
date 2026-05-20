# Step L.6 — Server Backup & Restore

**Phase:** L (Notifications, Stats & Admin)
**Step:** L.6
**Depends on:** Master (no prior L steps)
**Review:** Yes — see `l.6-backup-restore-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement **server backup and restore** functionality:
- **Backup**: Create a `.tar.gz` archive containing:
  - All database tables (via `mysqldump`).
  - Config files (`config/*.php`).
  - User data files (if any in `data/`).
  - SSL certificates (if any).
- **Restore**: Extract a backup archive and restore all data.
- **Scheduled backups**: Auto-backup every N days (configurable).
- **Backup retention**: Keep only last N backups.
- **S3-compatible storage**: Upload backups to S3 (or S3-compatible) if configured.

## 2. Context (what already exists)

Read first:

- `config/` — existing config structure.
- `src/Common/Database/ConnectionPool.php` — existing DB connection.
- `src/Common/Logger/LoggerFactory.php` — existing logger.

## 3. Scope — files to create / modify

### Create

#### Backup manager

- `src/Admin/BackupManager.php`:
  ```php
  class BackupManager
  {
      public function __construct(
          private readonly Connection $db,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Create a backup archive. Returns backup ID and file path. */
      public function createBackup(?string $label = null): array {
          // Returns ['backup_id' => string, 'file_path' => string, 'size_bytes' => int]
      }

      /** List all local backups. */
      public function listBackups(): array {}

      /** Delete a backup by ID. */
      public function deleteBackup(string $backupId): bool {}

      /** Restore from a backup archive. */
      public function restore(string $backupId): RestoreResult {}

      /** Upload a backup to S3. */
      public function uploadToS3(string $backupId): bool {}

      /** Download a backup from S3. */
      public function downloadFromS3(string $backupId): bool {}

      /** Get next scheduled backup time. */
      public function getNextScheduledBackup(): ?int {}
  }
  ```

- `src/Admin/RestoreResult.php`:
  ```php
  class RestoreResult
  {
      public function __construct(
          public readonly bool $success,
          public readonly string $message,
          public readonly ?string $error = null,
      ) {}
  }
  ```

#### Database schema

- `migrations/021_backups.sql`:
  ```sql
  CREATE TABLE backups (
      id CHAR(36) PRIMARY KEY,
      label VARCHAR(255),
      file_path VARCHAR(2048) NOT NULL,
      size_bytes BIGINT DEFAULT 0,
      checksum_sha256 VARCHAR(64) NOT NULL,
      is_s3 BOOLEAN DEFAULT FALSE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      expires_at TIMESTAMP NULL,
      INDEX idx_created (created_at)
  );
  ```

#### S3 client (minimal, using plain HTTP)

- `src/Admin/S3Client.php`:
  - Minimal S3-compatible client using `file_get_contents` / `curl`.
  - `upload(string $bucket, string $key, string $filePath, string $checksum): bool`
  - `download(string $bucket, string $key, string $destination): bool`
  - `listObjects(string $bucket, string $prefix): array`
  - `deleteObject(string $bucket, string $key): bool`
  - Uses AWS Signature V4 for authentication.

#### HTTP endpoints (admin only)

- `src/Server/Http/Controllers/Admin/BackupController.php`:
  - `POST /api/v1/admin/backup/create` — trigger immediate backup
  - `GET /api/v1/admin/backup/list` — list all backups
  - `DELETE /api/v1/admin/backup/{id}` — delete a backup
  - `POST /api/v1/admin/backup/{id}/restore` — restore from backup
  - `POST /api/v1/admin/backup/{id}/upload-s3` — upload to S3
  - `GET /api/v1/admin/backup/schedule` — get schedule info
  - `PUT /api/v1/admin/backup/schedule` — update schedule

#### Config

- `config/backup.php`:
  ```php
  return [
      'enabled' => true,
      'local_path' => '/var/phlex/backups',
      'retention_count' => 5,
      'auto_backup_interval_days' => 7,
      's3' => [
          'enabled' => false,
          'bucket' => '',
          'region' => 'us-east-1',
          'access_key' => '',
          'secret_key' => '',
          'endpoint' => '',  // empty for AWS, set for MinIO/Backblaze
      ],
  ];
  ```

#### Tests

- `tests/Unit/Admin/BackupManagerTest.php`

### Modify

- `src/Server/Core/Application.php` — register backup scheduler (Workerman Timer).
- `composer.json` — no new dependencies (plain mysqldump via `exec`, S3 via signed HTTP).
- `CHANGELOG.md` — add entry.

## 4. Approach

1. Branch from master: `git checkout -b l.6-backup-restore`.
2. Use `exec("mysqldump ...")` for database backup (same pattern as existing scripts).
3. Create `.tar.gz` with `exec("tar czf ...")` containing: db dump + config + any data files.
4. Compute SHA-256 checksum and store.
5. Store backup metadata in `backups` table.
6. Implement S3 client using AWS Signature V4 (plain PHP, no SDK).
7. Cleanup old backups beyond `retention_count`.
8. Register Workerman Timer for scheduled backups.
9. Write tests using mocks.
10. Verify: PHPUnit green, PHPStan level 9, PHPCS clean.
11. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `BackupManagerTest::test_create_backup_generates_tarball`
2. `BackupManagerTest::test_create_backup_computes_sha256`
3. `BackupManagerTest::test_list_backups_returns_all`
4. `BackupManagerTest::test_delete_backup_removes_file`
5. `BackupManagerTest::test_restore_extracts_and_imports_db`
6. `BackupManagerTest::test_cleanup_old_backups_respects_retention`
7. `S3ClientTest::test_upload_sends_signed_request`
8. `S3ClientTest::test_download_retrieves_object`

## 6. Acceptance Criteria

- [ ] `createBackup()` creates `.tar.gz` with: mysqldump output, `config/*.php`, `data/` files, SSL certs.
- [ ] `createBackup()` computes SHA-256 checksum of archive.
- [ ] `createBackup()` stores metadata in `backups` table with `id`, `file_path`, `size_bytes`, `checksum_sha256`.
- [ ] `listBackups()` returns array sorted by `created_at` DESC.
- [ ] `deleteBackup()` removes file from filesystem and `backups` table row.
- [ ] `restore()` extracts `.tar.gz`, runs mysqldump import, verifies checksum.
- [ ] `S3Client` uses AWS Signature V4 with `access_key`, `secret_key`, `region`.
- [ ] `uploadToS3()` uses `PUT` with `x-amz-content-sha256` and proper Authorization header.
- [ ] `cleanupOldBackups()` respects `retention_count` and deletes oldest excess backups.
- [ ] Workerman Timer triggers `createBackup()` at configured `auto_backup_interval_days`.
- [ ] Admin API: 7 endpoints as specified.
- [ ] Config `config/backup.php` with `local_path`, `retention_count`, `auto_backup_interval_days`, `s3`.
- [ ] ≥ 8 new tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b l.6-backup-restore
# ... implement ...
./vendor/bin/phpunit tests/Unit/Admin/BackupManagerTest.php tests/Unit/Admin/S3ClientTest.php
./vendor/bin/phpstan analyze src/Admin/BackupManager.php src/Admin/S3Client.php src/Admin/RestoreResult.php --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/Admin/BackupManager.php src/Admin/S3Client.php src/Admin/RestoreResult.php
git add -A
git commit -m "Step L.6: Server backup & restore"
unset GITHUB_TOKEN
gh pr create --title "Step L.6: Server backup & restore" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `l.6-backup-restore-review.md`.

(End of file - total 176 lines)
