<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Admin\BackupManager;
use Phlix\Console\Commands\BackupListCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Phlix\Console\Commands\BackupListCommand
 */
class BackupListCommandTest extends TestCase
{
    private function tester(BackupManager $manager): CommandTester
    {
        $application = new Application();
        $application->add(new BackupListCommand(fn(): BackupManager => $manager));

        return new CommandTester($application->find('backup:list'));
    }

    public function testListsBackupsAsTable(): void
    {
        $manager = $this->createMock(BackupManager::class);
        $manager->expects($this->once())
            ->method('listBackups')
            ->willReturn([
                [
                    'id' => 'bk-1',
                    'label' => 'nightly',
                    'file_path' => '/backups/a.tar.gz',
                    'size_bytes' => 1024,
                    'checksum_sha256' => 'abc',
                    'is_s3' => false,
                    'created_at' => '2026-05-01 02:00:00',
                    'expires_at' => null,
                ],
                [
                    'id' => 'bk-2',
                    'label' => '',
                    'file_path' => 's3://bucket/b.tar.gz',
                    'size_bytes' => 2048,
                    'checksum_sha256' => 'def',
                    'is_s3' => true,
                    'created_at' => '2026-05-02 02:00:00',
                    'expires_at' => null,
                ],
            ]);

        $tester = $this->tester($manager);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('bk-1', $display);
        $this->assertStringContainsString('nightly', $display);
        $this->assertStringContainsString('local', $display);
        $this->assertStringContainsString('bk-2', $display);
        $this->assertStringContainsString('S3', $display);
    }

    public function testEmptyListPrintsMessage(): void
    {
        $manager = $this->createMock(BackupManager::class);
        $manager->method('listBackups')->willReturn([]);

        $tester = $this->tester($manager);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No backups found.', $tester->getDisplay());
    }

    public function testManagerThrowsExitsOne(): void
    {
        $manager = $this->createMock(BackupManager::class);
        $manager->method('listBackups')->willThrowException(new RuntimeException('db error'));

        $tester = $this->tester($manager);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Failed to list backups: db error', $tester->getDisplay());
    }
}
