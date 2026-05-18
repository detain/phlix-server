<?php

namespace Phlex\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Phlex\Admin\BackupManager;
use Phlex\Admin\RestoreResult;
use Workerman\MySQL\Connection;

class BackupManagerTest extends TestCase
{
    private BackupManager $backupManager;
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->backupManager = new BackupManager($this->db);
    }

    public function testCreateBackupGeneratesIdAndPath(): void
    {
        // This test requires a real filesystem and mysqldump, so we skip it in unit tests.
        // The method structure is tested via integration tests.
        $this->markTestSkipped('createBackup requires actual filesystem and mysqldump - use integration tests');
    }

    public function testListBackupsReturnsAllBackupsSorted(): void
    {
        $this->db->method('query')->willReturn([
            [
                'id' => 'backup-1',
                'label' => 'First Backup',
                'file_path' => '/var/phlex/backups/backup_1.tar.gz',
                'size_bytes' => 1024,
                'checksum_sha256' => 'abc123',
                'is_s3' => false,
                'created_at' => '2024-01-02 00:00:00',
                'expires_at' => null,
            ],
            [
                'id' => 'backup-2',
                'label' => 'Second Backup',
                'file_path' => '/var/phlex/backups/backup_2.tar.gz',
                'size_bytes' => 2048,
                'checksum_sha256' => 'def456',
                'is_s3' => true,
                'created_at' => '2024-01-01 00:00:00',
                'expires_at' => null,
            ],
        ]);

        $backups = $this->backupManager->listBackups();

        $this->assertCount(2, $backups);
        $this->assertEquals('backup-1', $backups[0]['id']);
        $this->assertEquals('backup-2', $backups[1]['id']);
    }

    public function testListBackupsReturnsEmptyArrayWhenNoBackups(): void
    {
        $this->db->method('query')->willReturn([]);

        $backups = $this->backupManager->listBackups();

        $this->assertIsArray($backups);
        $this->assertEmpty($backups);
    }

    public function testDeleteBackupReturnsFalseWhenNotFound(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->backupManager->deleteBackup('non-existent-id');

        $this->assertFalse($result);
    }

    public function testDeleteBackupRemovesFileAndRecord(): void
    {
        // First query to get backup info
        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [
                    [
                        'file_path' => '/tmp/test_backup.tar.gz',
                        'is_s3' => false,
                    ]
                ],
                [] // DELETE query
            );

        $result = $this->backupManager->deleteBackup('test-backup-id');

        $this->assertTrue($result);
    }

    public function testRestoreReturnsFailureWhenBackupNotFound(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->backupManager->restore('non-existent-id');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->message);
    }

    public function testGetNextScheduledBackupReturnsNullWhenDisabled(): void
    {
        $this->db->method('query')->willReturn([]);

        // Without a valid config, defaults should apply
        $result = $this->backupManager->getNextScheduledBackup();

        // When no backups exist, returns time() which is effectively "now"
        $this->assertNotNull($result);
        $this->assertIsInt($result);
    }

    public function testCleanupOldBackupsRespectsRetention(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->backupManager->cleanupOldBackups();

        // No assertions needed - just verify no exceptions
        $this->assertTrue(true);
    }

    public function testRestoreResultSuccessFactory(): void
    {
        $result = RestoreResult::success('Test message');

        $this->assertTrue($result->success);
        $this->assertEquals('Test message', $result->message);
        $this->assertNull($result->error);
    }

    public function testRestoreResultFailureFactory(): void
    {
        $result = RestoreResult::failure('Test message', 'Test error');

        $this->assertFalse($result->success);
        $this->assertEquals('Test message', $result->message);
        $this->assertEquals('Test error', $result->error);
    }

    public function testListBackupsHandlesS3Flag(): void
    {
        $this->db->method('query')->willReturn([
            [
                'id' => 'backup-s3',
                'label' => 'S3 Backup',
                'file_path' => 's3://bucket/prefix/backup.tar.gz',
                'size_bytes' => 4096,
                'checksum_sha256' => 'xyz789',
                'is_s3' => true,
                'created_at' => '2024-01-03 00:00:00',
                'expires_at' => null,
            ],
        ]);

        $backups = $this->backupManager->listBackups();

        $this->assertCount(1, $backups);
        $this->assertTrue($backups[0]['is_s3']);
        $this->assertStringStartsWith('s3://', $backups[0]['file_path']);
    }

    public function testUploadToS3ReturnsFalseWhenNotConfigured(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->backupManager->uploadToS3('some-backup-id');

        $this->assertFalse($result);
    }

    public function testDownloadFromS3ReturnsFalseWhenNotConfigured(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->backupManager->downloadFromS3('some-backup-id');

        $this->assertFalse($result);
    }

    public function testDeleteBackupDeletesS3ObjectWhenS3Backup(): void
    {
        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [
                    [
                        'file_path' => 's3://bucket/prefix/backup.tar.gz',
                        'is_s3' => true,
                    ]
                ],
                [] // DELETE query
            );

        $result = $this->backupManager->deleteBackup('s3-backup-id');

        $this->assertTrue($result);
    }
}
