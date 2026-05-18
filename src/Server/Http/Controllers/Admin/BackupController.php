<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers\Admin;

use Phlex\Admin\BackupManager;
use Phlex\Common\Database\ConnectionPool;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Throwable;

/**
 * Backup administration controller.
 *
 * Provides JSON API endpoints for backup management including
 * create, list, delete, restore, S3 upload/download, and scheduling.
 *
 * @author Phlex Team
 * @version 1.0.0
 * @description Admin backup API controller
 */
class BackupController
{
    private BackupManager $backupManager;

    /**
     * Creates a new BackupController instance.
     */
    public function __construct(BackupManager $backupManager)
    {
        $this->backupManager = $backupManager;
    }

    /**
     * Create a new backup.
     *
     * POST /api/v1/admin/backup/create
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with backup info
     */
    public function create(Request $request, array $params): Response
    {
        try {
            $body = $request->jsonBody ?? [];
            $label = $body['label'] ?? null;

            $result = $this->backupManager->createBackup($label);

            return (new Response())->json([
                'success' => true,
                'message' => 'Backup created successfully',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'Backup creation failed',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * List all backups.
     *
     * GET /api/v1/admin/backup/list
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with backup list
     */
    public function list(Request $request, array $params): Response
    {
        try {
            $backups = $this->backupManager->listBackups();

            return (new Response())->json([
                'success' => true,
                'data' => $backups,
                'count' => count($backups),
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'Failed to list backups',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete a backup.
     *
     * DELETE /api/v1/admin/backup/{id}
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters including 'id'
     * @return Response JSON response with deletion status
     */
    public function delete(Request $request, array $params): Response
    {
        try {
            $backupId = $params['id'] ?? '';

            if (empty($backupId)) {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'error' => 'Missing backup ID',
                ]);
            }

            $result = $this->backupManager->deleteBackup($backupId);

            if (!$result) {
                return (new Response())->status(404)->json([
                    'success' => false,
                    'error' => 'Backup not found',
                ]);
            }

            return (new Response())->json([
                'success' => true,
                'message' => 'Backup deleted successfully',
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'Failed to delete backup',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Restore from a backup.
     *
     * POST /api/v1/admin/backup/{id}/restore
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters including 'id'
     * @return Response JSON response with restore result
     */
    public function restore(Request $request, array $params): Response
    {
        try {
            $backupId = $params['id'] ?? '';

            if (empty($backupId)) {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'error' => 'Missing backup ID',
                ]);
            }

            $result = $this->backupManager->restore($backupId);

            if (!$result->success) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'message' => $result->message,
                    'error' => $result->error,
                ]);
            }

            return (new Response())->json([
                'success' => true,
                'message' => $result->message,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'Restore failed',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Upload a backup to S3.
     *
     * POST /api/v1/admin/backup/{id}/upload-s3
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters including 'id'
     * @return Response JSON response with upload status
     */
    public function uploadS3(Request $request, array $params): Response
    {
        try {
            $backupId = $params['id'] ?? '';

            if (empty($backupId)) {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'error' => 'Missing backup ID',
                ]);
            }

            $result = $this->backupManager->uploadToS3($backupId);

            if (!$result) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error' => 'S3 upload failed',
                    'message' => 'Failed to upload backup to S3',
                ]);
            }

            return (new Response())->json([
                'success' => true,
                'message' => 'Backup uploaded to S3 successfully',
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'S3 upload failed',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get backup schedule info.
     *
     * GET /api/v1/admin/backup/schedule
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with schedule info
     */
    public function getSchedule(Request $request, array $params): Response
    {
        try {
            $nextBackup = $this->backupManager->getNextScheduledBackup();

            $configPath = defined('PHLEX_CONFIG_DIR') ? PHLEX_CONFIG_DIR : 'config';
            $backupConfigPath = $configPath . '/backup.php';

            $config = ['auto_backup_interval_days' => 7, 'retention_count' => 5];
            if (file_exists($backupConfigPath)) {
                $config = include $backupConfigPath;
            }

            return (new Response())->json([
                'success' => true,
                'data' => [
                    'auto_backup_interval_days' => $config['auto_backup_interval_days'] ?? 7,
                    'retention_count' => $config['retention_count'] ?? 5,
                    'next_scheduled_backup' => $nextBackup,
                    'next_scheduled_backup_iso' => $nextBackup ? date('c', $nextBackup) : null,
                ],
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'Failed to get schedule',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update backup schedule settings.
     *
     * PUT /api/v1/admin/backup/schedule
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with update status
     */
    public function updateSchedule(Request $request, array $params): Response
    {
        try {
            $body = $request->jsonBody ?? [];

            $intervalDays = $body['auto_backup_interval_days'] ?? null;
            $retentionCount = $body['retention_count'] ?? null;

            if ($intervalDays !== null) {
                $intervalDays = (int) $intervalDays;
                if ($intervalDays < 0) {
                    return (new Response())->status(400)->json([
                        'success' => false,
                        'error' => 'Invalid interval',
                        'message' => 'auto_backup_interval_days must be non-negative',
                    ]);
                }
            }

            if ($retentionCount !== null) {
                $retentionCount = (int) $retentionCount;
                if ($retentionCount < 1) {
                    return (new Response())->status(400)->json([
                        'success' => false,
                        'error' => 'Invalid retention count',
                        'message' => 'retention_count must be at least 1',
                    ]);
                }
            }

            $configPath = defined('PHLEX_CONFIG_DIR') ? PHLEX_CONFIG_DIR : 'config';
            $backupConfigPath = $configPath . '/backup.php';

            $config = [];
            if (file_exists($backupConfigPath)) {
                $config = include $backupConfigPath;
            }

            if ($intervalDays !== null) {
                $config['auto_backup_interval_days'] = $intervalDays;
            }

            if ($retentionCount !== null) {
                $config['retention_count'] = $retentionCount;
            }

            // Write config back
            $this->writeBackupConfig($backupConfigPath, $config);

            return (new Response())->json([
                'success' => true,
                'message' => 'Schedule updated successfully',
                'data' => [
                    'auto_backup_interval_days' => $config['auto_backup_interval_days'],
                    'retention_count' => $config['retention_count'],
                ],
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'Failed to update schedule',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write backup configuration file.
     */
    private function writeBackupConfig(string $path, array $config): void
    {
        $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        file_put_contents($path, $content);
    }

    /**
     * Create a BackupController with default BackupManager.
     */
    public static function createDefault(): self
    {
        $db = ConnectionPool::getConnection('mysql');
        $backupManager = new BackupManager($db);

        return new self($backupManager);
    }
}
