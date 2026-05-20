<?php

declare(strict_types=1);

namespace Phlex\Admin;

use Phlex\Admin\Dto\BackupConfig;
use Phlex\Admin\Dto\DbConnectionConfig;
use Phlex\Admin\Dto\S3Config;
use Phlex\Common\Logger\AuditLogger;
use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Manages server backup creation, restoration, S3 upload, and retention.
 *
 * Backup archives contain:
 * - MySQL database dump (all tables via mysqldump)
 * - Config files (config/*.php)
 * - User data files (data/)
 * - SSL certificates (if present)
 *
 * @package Phlex\Admin
 */
class BackupManager
{
    private Connection $db;

    private ?StructuredLogger $logger;

    private ?AuditLogger $auditLogger;

    private BackupConfig $config;

    /**
     * @param Connection $db Database connection
     * @param StructuredLogger|null $logger Optional logger for operations
     * @param AuditLogger|null $auditLogger Optional audit logger for security events
     */
    public function __construct(Connection $db, ?StructuredLogger $logger = null, ?AuditLogger $auditLogger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->auditLogger = $auditLogger;
        $this->config = $this->loadConfig();
    }

    /**
     * Load backup configuration into a strongly-typed value object.
     */
    private function loadConfig(): BackupConfig
    {
        $configPath = defined('PHLEX_CONFIG_DIR') ? PHLEX_CONFIG_DIR : 'config';
        $path = $configPath . '/backup.php';

        if (!file_exists($path)) {
            return BackupConfig::defaults();
        }

        /** @var mixed $raw */
        $raw = include $path;
        if (!is_array($raw)) {
            return BackupConfig::defaults();
        }
        /** @var array<string, mixed> $raw */
        return BackupConfig::fromArray($raw);
    }

    /**
     * Create a new backup archive.
     *
     * @param string|null $label Optional human-readable label
     * @return array{backup_id: string, file_path: string, size_bytes: int}
     * @throws Throwable On backup failure
     */
    public function createBackup(?string $label = null): array
    {
        $logger = $this->getLogger();

        $backupId = $this->generateUuid();
        $timestamp = gmdate('Y-m-d_H-i-s');
        $labelPrefix = $label !== null && $label !== '' ? "{$label}_" : '';
        $filename = "{$labelPrefix}backup_{$timestamp}.tar.gz";
        $localPath = $this->config->localPath;

        if (!is_dir($localPath)) {
            mkdir($localPath, 0755, true);
        }

        $tempDir = sys_get_temp_dir() . '/phlex_backup_' . $backupId;
        mkdir($tempDir, 0755, true);

        try {
            // 1. Dump database
            $dumpPath = $tempDir . '/database.sql';
            $this->createDatabaseDump($dumpPath);

            // 2. Copy config files
            $this->backupConfigs($tempDir);

            // 3. Copy data files if they exist
            $this->backupDataFiles($tempDir);

            // 4. Copy SSL certificates if they exist
            $this->backupSslCerts($tempDir);

            // 5. Create tar.gz archive
            $archivePath = $localPath . '/' . $filename;
            $this->createTarArchive($tempDir, $archivePath);

            // 6. Compute checksum
            $checksum = $this->computeChecksum($archivePath);
            if ($checksum === false) {
                throw new \RuntimeException('Failed to compute checksum for archive');
            }

            $sizeBytes = filesize($archivePath);
            if ($sizeBytes === false) {
                throw new \RuntimeException('Failed to get size of archive');
            }

            // 7. Store metadata in database
            $this->storeBackupMetadata($backupId, $label ?? '', $archivePath, $sizeBytes, $checksum);

            // 8. Cleanup old backups
            $this->cleanupOldBackups();

            $logger?->info('Backup created successfully', [
                'backup_id' => $backupId,
                'file_path' => $archivePath,
                'size_bytes' => $sizeBytes,
                'checksum_sha256' => $checksum,
            ]);

            $this->auditLogger?->logDataExport('system', 'backup_create', $sizeBytes);

            return [
                'backup_id' => $backupId,
                'file_path' => $archivePath,
                'size_bytes' => $sizeBytes,
            ];
        } catch (Throwable $e) {
            // Cleanup on failure
            $this->cleanupTempDir($tempDir);
            throw $e;
        }
    }

    /**
     * List all backups sorted by creation date descending.
     *
     * @return array<array{id:string, label:string, file_path:string, size_bytes:int, checksum_sha256:string, is_s3:bool, created_at:string, expires_at:?string}>
     */
    public function listBackups(): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id, label, file_path, size_bytes, checksum_sha256, is_s3, created_at, expires_at
             FROM backups
             ORDER BY created_at DESC'
        );

        return array_map(function (array $row): array {
            return [
                'id' => $this->rowString($row, 'id'),
                'label' => $this->rowString($row, 'label'),
                'file_path' => $this->rowString($row, 'file_path'),
                'size_bytes' => $this->rowInt($row, 'size_bytes'),
                'checksum_sha256' => $this->rowString($row, 'checksum_sha256'),
                'is_s3' => $this->rowBool($row, 'is_s3'),
                'created_at' => $this->rowString($row, 'created_at'),
                'expires_at' => isset($row['expires_at'])
                    ? $this->rowString($row, 'expires_at')
                    : null,
            ];
        }, $rows);
    }

    /**
     * Delete a backup by ID.
     *
     * @param string $backupId Backup UUID
     * @return bool True on success
     */
    public function deleteBackup(string $backupId): bool
    {
        $logger = $this->getLogger();

        // Get backup info first
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT file_path, is_s3 FROM backups WHERE id = ?',
            [$backupId]
        );

        if (empty($rows)) {
            return false;
        }

        $backup = $rows[0];

        // If S3 backup, delete from S3 first
        if ($this->rowBool($backup, 'is_s3')) {
            $s3Client = $this->createS3Client();
            if ($s3Client !== null) {
                $s3Key = $this->getS3KeyFromPath($this->rowString($backup, 'file_path'));
                $s3Client->deleteObject($this->config->s3->bucket, $s3Key);
            }
        } else {
            // Delete local file
            $filePath = $this->rowString($backup, 'file_path');
            if ($filePath !== '' && file_exists($filePath)) {
                unlink($filePath);
            }
        }

        // Delete database record
        $this->db->query('DELETE FROM backups WHERE id = ?', [$backupId]);

        $logger?->info('Backup deleted', ['backup_id' => $backupId]);

        return true;
    }

    /**
     * Restore from a backup archive.
     *
     * @param string $backupId Backup UUID to restore
     * @return RestoreResult Result of the restore operation
     */
    public function restore(string $backupId): RestoreResult
    {
        $logger = $this->getLogger();

        // Get backup info
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT file_path, checksum_sha256, is_s3 FROM backups WHERE id = ?',
            [$backupId]
        );

        if (empty($rows)) {
            return RestoreResult::failure('Backup not found', "No backup found with ID: {$backupId}");
        }

        $backup = $rows[0];

        try {
            // If S3 backup, download first
            $localPath = $this->rowString($backup, 'file_path');
            if ($this->rowBool($backup, 'is_s3')) {
                $s3Client = $this->createS3Client();
                if ($s3Client === null) {
                    return RestoreResult::failure('S3 not configured', 'Cannot download S3 backup - S3 not configured');
                }

                $s3Key = $this->getS3KeyFromPath($localPath);
                $tempPath = sys_get_temp_dir() . '/phlex_restore_' . $backupId . '.tar.gz';

                if (!$s3Client->download($this->config->s3->bucket, $s3Key, $tempPath)) {
                    return RestoreResult::failure('S3 download failed', "Failed to download backup from S3: {$s3Key}");
                }

                $localPath = $tempPath;
            }

            // Verify checksum
            $actualChecksum = $this->computeChecksum($localPath);
            if ($actualChecksum === false) {
                return RestoreResult::failure('Checksum computation failed', 'Could not compute checksum');
            }
            $expectedChecksum = $this->rowString($backup, 'checksum_sha256');
            if (strtolower($actualChecksum) !== strtolower($expectedChecksum)) {
                return RestoreResult::failure(
                    'Checksum mismatch',
                    "Expected {$expectedChecksum}, got {$actualChecksum}"
                );
            }

            // Extract archive
            $extractDir = sys_get_temp_dir() . '/phlex_restore_' . $backupId;
            $this->extractTarArchive($localPath, $extractDir);

            // Run database import
            $dbDumpPath = $extractDir . '/database.sql';
            if (file_exists($dbDumpPath)) {
                $this->importDatabaseDump($dbDumpPath);
            }

            // Restore config files
            $this->restoreConfigs($extractDir);

            // Cleanup
            $this->cleanupTempDir($extractDir);
            $originalPath = $this->rowString($backup, 'file_path');
            if ($localPath !== $originalPath && str_contains($localPath, 'phlex_restore_')) {
                @unlink($localPath);
            }

            $logger?->info('Backup restored successfully', ['backup_id' => $backupId]);

            $this->auditLogger?->logDataExport('system', 'backup_restore', 1);

            return RestoreResult::success("Backup '{$backupId}' restored successfully");
        } catch (Throwable $e) {
            $logger?->error('Backup restore failed', [
                'backup_id' => $backupId,
                'error' => $e->getMessage(),
            ]);

            return RestoreResult::failure('Restore failed', $e->getMessage());
        }
    }

    /**
     * Upload a backup to S3.
     *
     * @param string $backupId Backup UUID
     * @return bool True on success
     */
    public function uploadToS3(string $backupId): bool
    {
        $logger = $this->getLogger();
        $s3Client = $this->createS3Client();

        if ($s3Client === null) {
            return false;
        }

        // Get backup info
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT file_path, checksum_sha256 FROM backups WHERE id = ?',
            [$backupId]
        );

        if (empty($rows)) {
            return false;
        }

        $backup = $rows[0];
        $localPath = $this->rowString($backup, 'file_path');
        $checksum = $this->rowString($backup, 'checksum_sha256');

        // Verify local file exists
        if (!file_exists($localPath)) {
            return false;
        }

        $bucket = $this->config->s3->bucket;
        $filename = basename($localPath);
        $s3Key = $this->config->s3->prefix . $filename;

        if (!$s3Client->upload($bucket, $s3Key, $localPath, $checksum)) {
            return false;
        }

        // Update backup record to mark as S3
        $s3Path = "s3://{$bucket}/{$s3Key}";
        $this->db->query(
            'UPDATE backups SET is_s3 = TRUE, file_path = ? WHERE id = ?',
            [$s3Path, $backupId]
        );

        $logger?->info('Backup uploaded to S3', [
            'backup_id' => $backupId,
            's3_path' => $s3Path,
        ]);

        $this->auditLogger?->logDataExport('system', 'backup_upload_s3', 1);

        return true;
    }

    /**
     * Download a backup from S3 to local storage.
     *
     * @param string $backupId Backup UUID
     * @return bool True on success
     */
    public function downloadFromS3(string $backupId): bool
    {
        $logger = $this->getLogger();
        $s3Client = $this->createS3Client();

        if ($s3Client === null) {
            return false;
        }

        // Get backup info
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT file_path, checksum_sha256, is_s3 FROM backups WHERE id = ?',
            [$backupId]
        );

        if (empty($rows)) {
            return false;
        }

        $backup = $rows[0];

        if (!$this->rowBool($backup, 'is_s3')) {
            return true; // Already local
        }

        $bucket = $this->config->s3->bucket;
        $s3Key = $this->getS3KeyFromPath($this->rowString($backup, 'file_path'));
        $localPath = $this->config->localPath . '/' . basename($s3Key);

        if (!$s3Client->download($bucket, $s3Key, $localPath)) {
            return false;
        }

        // Update backup record with local path
        $this->db->query(
            'UPDATE backups SET is_s3 = FALSE, file_path = ? WHERE id = ?',
            [$localPath, $backupId]
        );

        $logger?->info('Backup downloaded from S3', [
            'backup_id' => $backupId,
            'local_path' => $localPath,
        ]);

        return true;
    }

    /**
     * Get the next scheduled backup timestamp.
     *
     * @return int|null Unix timestamp of next backup, or null if scheduling disabled
     */
    public function getNextScheduledBackup(): ?int
    {
        $intervalDays = $this->config->autoBackupIntervalDays;

        if ($intervalDays <= 0) {
            return null;
        }

        // Get the most recent backup timestamp
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT created_at FROM backups ORDER BY created_at DESC LIMIT 1'
        );

        if (empty($rows)) {
            // No backups yet - schedule for now
            return time();
        }

        $createdAt = $this->rowString($rows[0], 'created_at');
        $lastBackup = strtotime($createdAt);
        if ($lastBackup === false) {
            return time();
        }
        $intervalSeconds = $intervalDays * 86400;

        return $lastBackup + $intervalSeconds;
    }

    /**
     * Cleanup old backups beyond retention count.
     */
    public function cleanupOldBackups(): void
    {
        $logger = $this->getLogger();
        $retentionCount = $this->config->retentionCount;

        // Get backups beyond retention count
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id, file_path, is_s3 FROM backups ORDER BY created_at DESC LIMIT 1000 OFFSET ?',
            [$retentionCount]
        );

        foreach ($rows as $row) {
            $backupId = $this->rowString($row, 'id');
            if ($backupId !== '') {
                $this->deleteBackup($backupId);
            }
        }

        if (count($rows) > 0) {
            $logger?->info('Old backups cleaned up', ['count' => count($rows)]);
        }
    }

    /**
     * Create database dump via mysqldump.
     */
    private function createDatabaseDump(string $outputPath): void
    {
        $dbConfig = $this->getDbConfig();

        $cmd = sprintf(
            'mysqldump --single-transaction --quick --lock-tables=false -h %s -P %s -u %s %s --password=%s > %s 2>/dev/null',
            escapeshellarg($dbConfig->host),
            escapeshellarg((string) $dbConfig->port),
            escapeshellarg($dbConfig->username),
            escapeshellarg($dbConfig->database),
            escapeshellarg($dbConfig->password),
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 && !file_exists($outputPath)) {
            throw new \RuntimeException('mysqldump failed with code: ' . $returnCode);
        }
    }

    /**
     * Import database dump via mysql command.
     */
    private function importDatabaseDump(string $dumpPath): void
    {
        $dbConfig = $this->getDbConfig();

        $cmd = sprintf(
            'mysql -h %s -P %s -u %s --password=%s %s < %s 2>/dev/null',
            escapeshellarg($dbConfig->host),
            escapeshellarg((string) $dbConfig->port),
            escapeshellarg($dbConfig->username),
            escapeshellarg($dbConfig->password),
            escapeshellarg($dbConfig->database),
            escapeshellarg($dumpPath)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('mysql import failed with code: ' . $returnCode);
        }
    }

    /**
     * Backup config files to temp directory.
     */
    private function backupConfigs(string $tempDir): void
    {
        $configDir = defined('PHLEX_CONFIG_DIR') ? PHLEX_CONFIG_DIR : 'config';

        if (!is_dir($configDir)) {
            return;
        }

        $backupDir = $tempDir . '/config';
        mkdir($backupDir, 0755, true);

        $phpFiles = glob($configDir . '/*.php');
        if ($phpFiles === false) {
            return;
        }

        foreach ($phpFiles as $file) {
            $basename = basename($file);
            copy($file, $backupDir . '/' . $basename);
        }
    }

    /**
     * Restore config files from backup.
     */
    private function restoreConfigs(string $extractDir): void
    {
        $configDir = defined('PHLEX_CONFIG_DIR') ? PHLEX_CONFIG_DIR : 'config';
        $backupConfigDir = $extractDir . '/config';

        if (!is_dir($backupConfigDir)) {
            return;
        }

        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $phpFiles = glob($backupConfigDir . '/*.php');
        if ($phpFiles === false) {
            return;
        }

        foreach ($phpFiles as $file) {
            $basename = basename($file);
            copy($file, $configDir . '/' . $basename);
        }
    }

    /**
     * Backup data files if they exist.
     */
    private function backupDataFiles(string $tempDir): void
    {
        $dataDir = defined('PHLEX_DATA_DIR') ? PHLEX_DATA_DIR : 'data';

        if (!is_dir($dataDir)) {
            return;
        }

        $backupDir = $tempDir . '/data';
        mkdir($backupDir, 0755, true);

        $this->copyDirectory($dataDir, $backupDir);
    }

    /**
     * Backup SSL certificates if they exist.
     */
    private function backupSslCerts(string $tempDir): void
    {
        $sslDirs = ['/etc/ssl/certs/phlex', '/etc/phlex/ssl', '/var/lib/phlex/ssl'];

        $backupDir = $tempDir . '/ssl';
        mkdir($backupDir, 0755, true);

        foreach ($sslDirs as $sslDir) {
            if (is_dir($sslDir)) {
                $this->copyDirectory($sslDir, $backupDir . '/' . basename($sslDir));
            }
        }
    }

    /**
     * Create tar.gz archive from directory.
     */
    private function createTarArchive(string $sourceDir, string $archivePath): void
    {
        $cwd = getcwd();
        if ($cwd !== false) {
            chdir($sourceDir);
        }

        // Create archive using tar
        $cmd = sprintf(
            'tar czf %s . 2>/dev/null',
            escapeshellarg($archivePath)
        );

        exec($cmd, $output, $returnCode);

        if ($cwd !== false) {
            chdir($cwd);
        }

        if ($returnCode !== 0 && !file_exists($archivePath)) {
            throw new \RuntimeException('tar archive creation failed with code: ' . $returnCode);
        }
    }

    /**
     * Extract tar.gz archive to directory.
     */
    private function extractTarArchive(string $archivePath, string $extractDir): void
    {
        mkdir($extractDir, 0755, true);

        $cmd = sprintf(
            'tar xzf %s -C %s 2>/dev/null',
            escapeshellarg($archivePath),
            escapeshellarg($extractDir)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 && !is_dir($extractDir . '/database.sql')) {
            throw new \RuntimeException('tar archive extraction failed with code: ' . $returnCode);
        }
    }

    /**
     * Compute SHA-256 checksum of a file.
     *
     * @return string|false
     */
    private function computeChecksum(string $filePath): string|false
    {
        return hash_file('sha256', $filePath);
    }

    /**
     * Store backup metadata in database.
     */
    private function storeBackupMetadata(
        string $id,
        string $label,
        string $filePath,
        int $sizeBytes,
        string $checksum
    ): void {
        $this->db->query(
            'INSERT INTO backups (id, label, file_path, size_bytes, checksum_sha256, is_s3, created_at)
             VALUES (?, ?, ?, ?, ?, FALSE, NOW())',
            [$id, $label, $filePath, $sizeBytes, $checksum]
        );
    }

    /**
     * Cleanup a temporary directory.
     */
    private function cleanupTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $cmd = sprintf('rm -rf %s', escapeshellarg($dir));
        exec($cmd);
    }

    /**
     * Copy directory recursively.
     */
    private function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($source)) {
            return;
        }

        mkdir($dest, 0755, true);

        $items = scandir($source);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $srcPath = $source . '/' . $item;
            $dstPath = $dest . '/' . $item;

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }

    /**
     * Get database configuration.
     */
    private function getDbConfig(): DbConnectionConfig
    {
        $dbConfigPath = defined('PHLEX_CONFIG_DIR') ? PHLEX_CONFIG_DIR . '/database.php' : 'config/database.php';

        if (!file_exists($dbConfigPath)) {
            throw new \RuntimeException('Database config not found: ' . $dbConfigPath);
        }

        /** @var mixed $rawConfig */
        $rawConfig = include $dbConfigPath;
        if (!is_array($rawConfig)) {
            throw new \RuntimeException('Database config must return an array: ' . $dbConfigPath);
        }
        /** @var array<string, mixed> $rawConfig */

        $connections = $rawConfig['connections'] ?? null;
        $conn = is_array($connections) && isset($connections['mysql']) && is_array($connections['mysql'])
            ? $connections['mysql']
            : ($rawConfig['default'] ?? []);
        if (!is_array($conn)) {
            $conn = [];
        }
        /** @var array<string, mixed> $conn */

        return DbConnectionConfig::fromArray($conn);
    }

    /**
     * Create S3 client if configured.
     */
    private function createS3Client(): ?S3Client
    {
        $s3 = $this->config->s3;

        if (!$s3->hasCredentials()) {
            return null;
        }

        return new S3Client(
            $s3->region,
            $s3->accessKey,
            $s3->secretKey,
            $s3->endpoint,
        );
    }

    /**
     * Extract S3 key from file path.
     */
    private function getS3KeyFromPath(string $path): string
    {
        // Format: s3://bucket/key
        if (str_starts_with($path, 's3://')) {
            $parts = explode('/', substr($path, 5), 2);
            return $parts[1] ?? '';
        }

        // Assume it's the key itself
        return $path;
    }

    /**
     * Get the logger instance.
     */
    private function getLogger(): ?StructuredLogger
    {
        if ($this->logger !== null) {
            return $this->logger;
        }

        try {
            return LoggerFactory::get(LogChannels::APPLICATION);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extract a string column from a hydrated DB row.
     *
     * @param array<string, mixed> $row
     */
    private function rowString(array $row, string $key): string
    {
        $value = $row[$key] ?? '';
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return '';
    }

    /**
     * Extract an int column from a hydrated DB row.
     *
     * @param array<string, mixed> $row
     */
    private function rowInt(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        return 0;
    }

    /**
     * Extract a bool column from a hydrated DB row.
     *
     * @param array<string, mixed> $row
     */
    private function rowBool(array $row, string $key): bool
    {
        $value = $row[$key] ?? false;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return $value !== '' && $value !== '0' && strtolower($value) !== 'false';
        }
        return false;
    }

    /**
     * Generate a UUID v4 string.
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
