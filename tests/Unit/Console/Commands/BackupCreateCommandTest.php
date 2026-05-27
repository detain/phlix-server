<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Admin\BackupManager;
use Phlix\Console\Commands\BackupCreateCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Phlix\Console\Commands\BackupCreateCommand
 */
class BackupCreateCommandTest extends TestCase
{
    private function tester(BackupManager $manager): CommandTester
    {
        $application = new Application();
        $application->add(new BackupCreateCommand(fn(): BackupManager => $manager));

        return new CommandTester($application->find('backup:create'));
    }

    public function testCreatesBackupWithoutLabel(): void
    {
        $manager = $this->createMock(BackupManager::class);
        $manager->expects($this->once())
            ->method('createBackup')
            ->with(null)
            ->willReturn([
                'backup_id' => 'bk-1',
                'file_path' => '/backups/backup_2026.tar.gz',
                'size_bytes' => 4096,
            ]);

        $tester = $this->tester($manager);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Backup created.', $display);
        $this->assertStringContainsString('bk-1', $display);
        $this->assertStringContainsString('/backups/backup_2026.tar.gz', $display);
        $this->assertStringContainsString('4096 bytes', $display);
    }

    public function testCreatesBackupWithLabel(): void
    {
        $manager = $this->createMock(BackupManager::class);
        $manager->expects($this->once())
            ->method('createBackup')
            ->with('nightly')
            ->willReturn([
                'backup_id' => 'bk-2',
                'file_path' => '/backups/nightly_backup.tar.gz',
                'size_bytes' => 8192,
            ]);

        $tester = $this->tester($manager);
        $exitCode = $tester->execute(['--label' => 'nightly']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('bk-2', $tester->getDisplay());
    }

    public function testFailureExitsOne(): void
    {
        $manager = $this->createMock(BackupManager::class);
        $manager->method('createBackup')->willThrowException(new RuntimeException('mysqldump failed'));

        $tester = $this->tester($manager);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Backup creation failed: mysqldump failed', $tester->getDisplay());
    }
}
