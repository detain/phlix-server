<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Admin\BackupManager;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Throwable;

/**
 * Backup administration controller.
 *
 * Provides JSON API endpoints for backup management including
 * create, list, delete, restore, S3 upload/download, and scheduling.
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Admin backup API controller
 */
class BackupController
{
    /**
     * SH-003 (M-SERVER-05 idiom): the operator-facing `message` carried by every
     * 500 envelope this class emits. `BackupManager` failures are system copy —
     * `mysqldump`/`tar` process errors, re-thrown `PDOException` text ("mysql
     * import failed with code: N"), and absolute filesystem paths — so the wire
     * carries this constant instead of {@see \Throwable::getMessage()}. The
     * exception's class, message and origin are logged server-side
     * ({@see logFailure()}). The envelope SHAPE is unchanged
     * (`{success:false, error:<per-arm label>, message:<this constant>}`) and no
     * wire error codes are added — contract law.
     */
    // Concatenated to keep the PSR-12 warning-strict src gate happy; the
    // runtime value is one flat string (pinned verbatim by the law test).
    private const GENERIC_FAILURE_MESSAGE = 'An internal error occurred while handling the backup request. '
        . 'The details were logged server-side.';

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
            $body = $request->body;
            $label = $body['label'] ?? null;

            if ($label !== null && !is_string($label)) {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'error' => 'Invalid label',
                    'message' => 'Backup label must be text',
                ]);
            }

            $result = $this->backupManager->createBackup($label);

            return (new Response())->json([
                'success' => true,
                'message' => 'Backup created successfully',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            self::logFailure('Backup creation failed', $e);
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'Backup creation failed',
                'message' => self::GENERIC_FAILURE_MESSAGE,
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
            self::logFailure('Failed to list backups', $e);
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'Failed to list backups',
                'message' => self::GENERIC_FAILURE_MESSAGE,
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
            self::logFailure('Failed to delete backup', $e);
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'Failed to delete backup',
                'message' => self::GENERIC_FAILURE_MESSAGE,
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
            self::logFailure('Restore failed', $e);
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'Restore failed',
                'message' => self::GENERIC_FAILURE_MESSAGE,
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
            self::logFailure('S3 upload failed', $e);
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'S3 upload failed',
                'message' => self::GENERIC_FAILURE_MESSAGE,
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

            $configPath = defined('PHLIX_CONFIG_DIR') ? PHLIX_CONFIG_DIR : 'config';
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
            self::logFailure('Failed to get schedule', $e);
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'Failed to get schedule',
                'message' => self::GENERIC_FAILURE_MESSAGE,
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
            $body = $request->body;

            $intervalDays = $body['auto_backup_interval_days'] ?? null;
            $retentionCount = $body['retention_count'] ?? null;

            // `Request::$body` is array<string, mixed>: a JSON client can send
            // any shape here, so parse to numeric before the (int) casts below
            // (this same method used to see only `null` because of the dead
            // property read — these guards are what "live" actually means).
            if ($intervalDays !== null && !is_numeric($intervalDays)) {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'error' => 'Invalid interval',
                    'message' => 'Auto backup interval must be a number of days',
                ]);
            }

            if ($retentionCount !== null && !is_numeric($retentionCount)) {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'error' => 'Invalid retention count',
                    'message' => 'Backup retention count must be a number',
                ]);
            }

            if ($intervalDays !== null) {
                $intervalDays = (int) $intervalDays;
                if ($intervalDays < 0) {
                    return (new Response())->status(400)->json([
                        'success' => false,
                        'error' => 'Invalid interval',
                        'message' => 'Auto-backup interval must be a non-negative number of days.',
                    ]);
                }
            }

            if ($retentionCount !== null) {
                $retentionCount = (int) $retentionCount;
                if ($retentionCount < 1) {
                    return (new Response())->status(400)->json([
                        'success' => false,
                        'error' => 'Invalid retention count',
                        'message' => 'Backup retention count must be at least 1.',
                    ]);
                }
            }

            $configPath = defined('PHLIX_CONFIG_DIR') ? PHLIX_CONFIG_DIR : 'config';
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
            self::logFailure('Failed to update schedule', $e);
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'Failed to update schedule',
                'message' => self::GENERIC_FAILURE_MESSAGE,
            ]);
        }
    }

    /**
     * Write backup configuration file.
     *
     * @param string $path Destination filesystem path.
     * @param array<string, mixed> $config Decoded backup configuration.
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
    /**
     * SH-003: full exception detail goes to the HTTP log channel, never to the
     * wire. Mirrors AdminSettingsController::logFailure() (M-SERVER-05).
     *
     * @param string    $event Log event label (the per-arm copy).
     * @param Throwable $e     The caught failure.
     */
    private static function logFailure(string $event, Throwable $e): void
    {
        LoggerFactory::get(LogChannels::HTTP)->error($event, [
            'exception' => $e::class,
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);
    }
}
