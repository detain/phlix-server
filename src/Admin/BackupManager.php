<?php

declare(strict_types=1);

namespace Phlex\Admin;

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
    /** @var Connection */
    private $db;

    /** @var StructuredLogger|null */
    private $logger;

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param Connection $db Database connection
     * @param StructuredLogger|null $logger Optional logger for operations
     */
    public function __construct(Connection $db, ?StructuredLogger $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
        /** @var array<string, mixed> */
        $this->config = $this->loadConfig();
    }

    /**
     * Get S3 configuration.
     *
     * @return array<string, mixed>
     */
    private function getS3Config(): array
    {
        /** @var array<string, mixed> $s3Config */
        $s3Config = $this->config['s3'] ?? [];
        return $s3Config;
    }

    /**
     * Get a string config value with default.
     *
     * @param mixed $value
     */
    private function getConfigString(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? $default;
        return is_string($value) ? $value : (string) $value;
    }

    /**
     * Get an int config value with default.
     *
     * @param mixed $value
     */
    private function getConfigInt(string $key, int $default = 0): int
    {
        $value = $this->config[$key] ?? $default;
        return is_int($value) ? $value : (int) $value;
    }

    /**
     * Load backup configuration.
     *
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        $configPath = defined('PHLEX_CONFIG_DIR') ? PHLEX_CONFIG_DIR : 'config';
        $path = $configPath . '/backup.php';

        if (!file_exists($path)) {
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
                    'endpoint' => '',
                    'prefix' => 'backups/',
                ],
            ];
        }

        /** @var array<string, mixed> */
        return include $path;
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
        $labelPrefix = $label ? "{$label}_" : '';
        $filename = "{$labelPrefix}backup_{$timestamp}.tar.gz";
        /** @var string $localPath */
        $localPath = (string) ($this->config['local_path'] ?? '/var/phlex/backups');

        if (!is_dir($localPath)) {
            mkdir($localPath, 0755, true);
        }

        $tempDir = sys_get_temp_dir() . '/phlex_backup_' . $backupId;
        mkdir($tempDir, 0755, true);

        try {
            // 1. Dump database
            /** @var string $dumpPath */
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
                'id' => (string) $row['id'],
                'label' => (string) ($row['label'] ?? ''),
                'file_path' => (string) $row['file_path'],
                'size_bytes' => (int) ($row['size_bytes'] ?? 0),
                'checksum_sha256' => (string) $row['checksum_sha256'],
                'is_s3' => (bool) ($row['is_s3'] ?? false),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'expires_at' => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
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

        /** @var array<string, mixed> $backup */
        $backup = $rows[0];

        // If S3 backup, delete from S3 first
        if ((bool) ($backup['is_s3'] ?? false)) {
            $s3Client = $this->createS3Client();
            if ($s3Client !== null) {
                $s3Key = $this->getS3KeyFromPath((string) ($backup['file_path'] ?? ''));
                $s3Cfg = $this->getS3Config();
                $bucket = (string) ($s3Cfg['bucket'] ?? '');
                $s3Client->deleteObject($bucket, $s3Key);
            }
        } else {
            // Delete local file
            $filePath = (string) ($backup['file_path'] ?? '');
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

        /** @var array<string, mixed> $backup */
        $backup = $rows[0];

        try {
            // If S3 backup, download first
            $localPath = (string) ($backup['file_path'] ?? '');
            if ((bool) ($backup['is_s3'] ?? false)) {
                $s3Client = $this->createS3Client();
                if ($s3Client === null) {
                    return RestoreResult::failure('S3 not configured', 'Cannot download S3 backup - S3 not configured');
                }

                $s3Cfg = $this->getS3Config();
                $bucket = (string) ($s3Cfg['bucket'] ?? '');
                $s3Key = $this->getS3KeyFromPath($localPath);
                $tempPath = sys_get_temp_dir() . '/phlex_restore_' . $backupId . '.tar.gz';

                if (!$s3Client->download($bucket, $s3Key, $tempPath)) {
                    return RestoreResult::failure('S3 download failed', "Failed to download backup from S3: {$s3Key}");
                }

                $localPath = $tempPath;
            }

            // Verify checksum
            $actualChecksum = $this->computeChecksum($localPath);
            if ($actualChecksum === false) {
                return RestoreResult::failure('Checksum computation failed', 'Could not compute checksum');
            }
            $expectedChecksum = (string) ($backup['checksum_sha256'] ?? '');
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
            $originalPath = (string) ($backup['file_path'] ?? '');
            if ($localPath !== $originalPath && str_contains($localPath, 'phlex_restore_')) {
                @unlink($localPath);
            }

            $logger?->info('Backup restored successfully', ['backup_id' => $backupId]);

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

        /** @var array<string, mixed> $backup */
        $backup = $rows[0];
        $localPath = (string) ($backup['file_path'] ?? '');
        $checksum = (string) ($backup['checksum_sha256'] ?? '');

        // Verify local file exists
        if (!file_exists($localPath)) {
            return false;
        }

        $s3Cfg = $this->getS3Config();
        $bucket = (string) ($s3Cfg['bucket'] ?? '');
        $prefix = (string) ($s3Cfg['prefix'] ?? 'backups/');
        $filename = basename($localPath);
        $s3Key = $prefix . $filename;

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

        /** @var array<string, mixed> $backup */
        $backup = $rows[0];

        if (!(bool) ($backup['is_s3'] ?? false)) {
            return true; // Already local
        }

        $s3Cfg = $this->getS3Config();
        $bucket = (string) ($s3Cfg['bucket'] ?? '');
        $s3Key = $this->getS3KeyFromPath((string) ($backup['file_path'] ?? ''));
        $localPath = $this->getConfigString('local_path', '/var/phlex/backups') . '/' . basename($s3Key);

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
        $intervalDays = (int) ($this->config['auto_backup_interval_days'] ?? 7);

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

        $createdAt = (string) ($rows[0]['created_at'] ?? '');
        $lastBackup = strtotime($createdAt);
        $intervalSeconds = $intervalDays * 86400;

        return $lastBackup + $intervalSeconds;
    }

    /**
     * Cleanup old backups beyond retention count.
     */
    public function cleanupOldBackups(): void
    {
        $logger = $this->getLogger();
        $retentionCount = (int) ($this->config['retention_count'] ?? 5);

        // Get backups beyond retention count
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id, file_path, is_s3 FROM backups ORDER BY created_at DESC LIMIT 1000 OFFSET ?',
            [$retentionCount]
        );

        foreach ($rows as $row) {
            $backupId = (string) ($row['id'] ?? '');
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
            escapeshellarg($dbConfig['host']),
            escapeshellarg((string) $dbConfig['port']),
            escapeshellarg($dbConfig['username']),
            escapeshellarg($dbConfig['database']),
            escapeshellarg($dbConfig['password']),
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
            escapeshellarg($dbConfig['host']),
            escapeshellarg((string) $dbConfig['port']),
            escapeshellarg($dbConfig['username']),
            escapeshellarg($dbConfig['password']),
            escapeshellarg($dbConfig['database']),
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
     *
     * @return array{host:string, port:int, username:string, password:string, database:string}
     */
    private function getDbConfig(): array
    {
        $dbConfigPath = defined('PHLEX_CONFIG_DIR') ? PHLEX_CONFIG_DIR . '/database.php' : 'config/database.php';

        if (!file_exists($dbConfigPath)) {
            throw new \RuntimeException('Database config not found: ' . $dbConfigPath);
        }

        /** @var array<string, mixed> $config */
        $config = include $dbConfigPath;
        /** @var array<string, mixed> $conn */
        $conn = $config['connections']['mysql'] ?? $config['default'] ?? [];

        return [
            'host' => (string) ($conn['host'] ?? '127.0.0.1'),
            'port' => (int) ($conn['port'] ?? 3306),
            'username' => (string) ($conn['username'] ?? 'root'),
            'password' => (string) ($conn['password'] ?? ''),
            'database' => (string) ($conn['database'] ?? 'phlex'),
        ];
    }

    /**
     * Create S3 client if configured.
     */
    private function createS3Client(): ?S3Client
    {
        $s3Cfg = $this->getS3Config();

        if (empty($s3Cfg['enabled'])) {
            return null;
        }

        $accessKey = (string) ($s3Cfg['access_key'] ?? '');
        $secretKey = (string) ($s3Cfg['secret_key'] ?? '');

        if ($accessKey === '' || $secretKey === '') {
            return null;
        }

        return new S3Client(
            (string) ($s3Cfg['region'] ?? 'us-east-1'),
            $accessKey,
            $secretKey,
            (string) ($s3Cfg['endpoint'] ?? '')
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
