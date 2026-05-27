<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Common\Database\MigrationRunner;
use Phlix\Console\Commands\MigrateCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlix\Console\Commands\MigrateCommand
 */
class MigrateCommandTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir() . '/phlix_migcmd_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tmpDir = $dir;
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
        $this->tmpDir = '';
        parent::tearDown();
    }

    private function writeMigration(string $name, string $sql): void
    {
        file_put_contents($this->tmpDir . '/' . $name, $sql);
    }

    private function tester(MigrationRunner $runner): CommandTester
    {
        $application = new Application();
        $application->add(new MigrateCommand($runner));

        return new CommandTester($application->find('migrate'));
    }

    public function testSuccessExitsZeroAndListsAppliedMigrations(): void
    {
        $this->writeMigration('001_init.sql', 'CREATE TABLE a (id INT);');
        $this->writeMigration('002_more.sql', 'CREATE TABLE b (id INT);');

        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturn([]);

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $tester = $this->tester($runner);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Running migration: 001_init.sql', $output);
        $this->assertStringContainsString('Running migration: 002_more.sql', $output);
        $this->assertStringContainsString('Migrations complete.', $output);
    }

    public function testIdempotentNoteStillExitsZero(): void
    {
        $this->writeMigration('001.sql', 'ALTER TABLE a ADD COLUMN n INT;');

        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willThrowException(new RuntimeException('Duplicate column name "n"'));

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $tester = $this->tester($runner);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('note: Duplicate column name "n"', $tester->getDisplay());
    }

    public function testGenuineErrorExitsOneAndPrintsWarning(): void
    {
        $this->writeMigration('001.sql', 'BAD STATEMENT;');

        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willThrowException(new RuntimeException('Syntax error near BAD'));

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $tester = $this->tester($runner);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Warning: Syntax error near BAD', $tester->getDisplay());
    }
}
