<?php

namespace Phlix\Tests\Unit\Admin;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Admin\BackupManager;
use Phlix\Admin\RestoreResult;
use Workerman\MySQL\Connection;

class BackupManagerTest extends TestCase
{
    /**
     * Label handed to {@see self::testCreateBackupGeneratesIdAndPath()}. Its prefix
     * is asserted on the archive filename, and it carries the lane token so the case
     * that ended W52's permanent-skip finding stays greppable in the tree.
     */
    private const BACKUP_LABEL = 'S184FOLDX7K2';

    /** RFC 4122 v4 shape, i.e. what {@see \Phlix\Common\Uuid::v4()} emits. */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private BackupManager $backupManager;
    /** @var Connection&MockObject */
    private Connection $db;

    /** @var list<string> Scratch dirs created by the end-to-end case. */
    private array $scratchDirs = [];

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->backupManager = new BackupManager($this->db);
    }

    protected function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            self::rmrf($dir);
        }
        $this->scratchDirs = [];

        parent::tearDown();
    }

    /** Scratch directory under the system temp dir, removed in tearDown. */
    private function scratchDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/phlix_s452_' . $prefix . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->scratchDirs[] = $dir;

        return $dir;
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * `createBackup()` end to end: real mysqldump step, real config copy, real
     * `tar.gz` in a temp-dir-isolated sink, real checksum/size round trip.
     *
     * ## Why this used to be an unconditional skip, and why it is not one now
     *
     * The case carried `markTestSkipped('createBackup requires actual filesystem and
     * mysqldump …')` with no env or extension gate in front of it, so it skipped on
     * every machine — CI included (the name sits in the CI skip set) — and reported
     * green forever while never executing a line of `createBackup()`. Both halves of
     * that justification were false on the suite's own venues: the filesystem it
     * needs is a temp dir, and `createDatabaseDump()` cannot fail the way the message
     * assumes — the shell's `>` redirect creates `database.sql` before mysqldump
     * runs, and the throw needs BOTH a non-zero exit AND a missing file, so a missing
     * or failing mysqldump still leaves a real archive.
     *
     * ## Venue
     *
     * `PHLIX_CONFIG_DIR`/`PHLIX_DATA_DIR` are constants, definable once per process,
     * so the case runs isolated — the same shape as the two end-to-end cases in
     * {@see BackupConfigRecursionTest()} and `BackupControllerBodyPersistenceTest`.
     * `database.php` is a scratch fixture built from the same `DB_*` env phpunit.xml
     * exports, so the dump step gets credentials wherever the suite runs and never
     * depends on the repo's own config.
     *
     * ## What is pinned
     *
     * The returned `backup_id` is a real v4 UUID; `file_path` points at an actual
     * archive inside the configured sink whose name carries the label prefix; the
     * reported `size_bytes` is the file's real size; the archive really lists the
     * staged dump and the config snapshot; and the staging directory is swept (the
     * S439 zero-residue fix). What is deliberately NOT pinned is the SQL inside
     * `database.sql` — that is mysqldump's contract, not this method's, and asserting
     * it here would reintroduce the venue dependency the skip wrongly claimed.
     *
     * @group integration
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testCreateBackupGeneratesIdAndPath(): void
    {
        self::assertFalse(defined('PHLIX_CONFIG_DIR'), 'the isolated process must start with the constant free');

        $configDir = $this->scratchDir('cfg_');
        $sinkDir = $this->scratchDir('sink_');

        file_put_contents($configDir . '/database.php', "<?php\nreturn " . var_export([
            'connections' => [
                'mysql' => [
                    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
                    'port'     => (int) (getenv('DB_PORT') ?: 3306),
                    'username' => getenv('DB_USER') ?: 'root',
                    'password' => getenv('DB_PASSWORD') ?: '',
                    'database' => getenv('DB_DATABASE') ?: 'phlix_test',
                ],
            ],
        ], true) . ";\n");
        file_put_contents($configDir . '/backup.php', "<?php\nreturn " . var_export([
            'enabled' => true,
            'local_path' => $sinkDir,
            'retention_count' => 5,
            'auto_backup_interval_days' => 0,
            's3' => ['enabled' => false],
        ], true) . ";\n");

        define('PHLIX_CONFIG_DIR', $configDir);
        // Keep the data-file copy out of the repository tree entirely.
        define('PHLIX_DATA_DIR', $configDir . '/no-such-data-dir');

        // The `backups` table only: the metadata INSERT and the retention SELECT.
        $this->db->method('query')->willReturn([]);

        $result = (new BackupManager($this->db))->createBackup(self::BACKUP_LABEL);

        self::assertMatchesRegularExpression(self::UUID_PATTERN, $result['backup_id']);
        self::assertStringStartsWith($sinkDir . '/', $result['file_path']);
        self::assertStringEndsWith('.tar.gz', $result['file_path']);
        self::assertStringStartsWith(
            self::BACKUP_LABEL . '_backup_',
            basename($result['file_path']),
            'the label must prefix the archive name',
        );
        self::assertFileExists($result['file_path']);
        self::assertGreaterThan(0, $result['size_bytes']);
        self::assertSame(filesize($result['file_path']), $result['size_bytes']);

        exec('tar tzf ' . escapeshellarg($result['file_path']), $listing, $tarStatus);
        self::assertSame(0, $tarStatus, 'the produced archive must be a readable gzip tar');

        // GNU tar lists what `tar czf … .` stored with a leading "./"; strip it so the
        // assertions name archive paths the way the code that staged them does.
        $entries = [];
        foreach ($listing as $entry) {
            $normalised = (string) $entry;
            $entries[] = str_starts_with($normalised, './') ? substr($normalised, 2) : $normalised;
        }
        self::assertContains('database.sql', $entries, 'the staged dump must be inside the archive');
        self::assertContains('config/backup.php', $entries, 'the config snapshot must be inside the archive');

        self::assertDirectoryDoesNotExist(
            sys_get_temp_dir() . '/phlix_backup_' . $result['backup_id'],
            'S439: the staging directory must be swept on the success path too'
        );
    }

    public function testListBackupsReturnsAllBackupsSorted(): void
    {
        $this->db->method('query')->willReturn([
            [
                'id' => 'backup-1',
                'label' => 'First Backup',
                'file_path' => '/var/phlix/backups/backup_1.tar.gz',
                'size_bytes' => 1024,
                'checksum_sha256' => 'abc123',
                'is_s3' => false,
                'created_at' => '2024-01-02 00:00:00',
                'expires_at' => null,
            ],
            [
                'id' => 'backup-2',
                'label' => 'Second Backup',
                'file_path' => '/var/phlix/backups/backup_2.tar.gz',
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
    }

    public function testCleanupOldBackupsRespectsRetention(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->expectNotToPerformAssertions();

        $this->backupManager->cleanupOldBackups();

        // No assertions needed - just verify no exceptions
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
