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
        $output = $tester->getDisplay();
        // Already-applied duplicates are collapsed into ONE summary line
        // instead of a per-statement note (which reads like a broken deploy).
        $this->assertStringContainsString('1 statement(s) skipped (already applied)', $output);
        $this->assertStringNotContainsString('note: Duplicate column name "n"', $output);
    }

    public function testAlreadyAppliedNotesAreSummarisedAsOneLine(): void
    {
        $this->writeMigration(
            '001.sql',
            "ALTER TABLE a ADD COLUMN n INT;\nALTER TABLE a ADD KEY idx_n (n);\nCREATE TABLE a (id INT);"
        );

        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'ADD COLUMN')) {
                throw new RuntimeException('Duplicate column name "n"');
            }
            if (str_contains($sql, 'ADD KEY')) {
                throw new RuntimeException('Duplicate key name "idx_n"');
            }
            throw new RuntimeException('Table "a" already exists');
        });

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $tester = $this->tester($runner);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('3 statement(s) skipped (already applied)', $output);
        // No per-duplicate note lines — the summary replaces them all.
        $this->assertStringNotContainsString('note:', $output);
    }

    public function testSuccessWithNoSkipsPrintsNoSkipSummary(): void
    {
        $this->writeMigration('001.sql', 'CREATE TABLE a (id INT);');

        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturn([]);

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $tester = $this->tester($runner);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('skipped (already applied)', $tester->getDisplay());
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

    /**
     * S159 review finding 3 — the human-readable half.
     *
     * This command used to print `Migrations complete. (1 file(s), 0 note(s),
     * 1 error(s))` and return 1: the word "complete" next to a non-zero exit
     * and a non-zero error count, while `scripts/run-migrations.php` correctly
     * suppressed it and wrote `Migrations FAILED …` instead. The exit contract
     * agreed; the sentence an operator reads did not.
     */
    public function testFailureDoesNotSayCompleteAndPrintsTheSharedFailedSummary(): void
    {
        $this->writeMigration('001.sql', 'BAD STATEMENT;');

        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willThrowException(new RuntimeException('Syntax error near BAD'));

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $tester = $this->tester($runner);

        $this->assertSame(Command::FAILURE, $tester->execute([]));

        $output = $tester->getDisplay();
        $this->assertStringNotContainsString('Migrations complete.', $output);
        // Byte-identical to what `scripts/run-migrations.php` writes, because
        // both render MigrationRunner::failureSummary().
        $this->assertStringContainsString(
            MigrationRunner::failureSummary([
                'applied' => ['001.sql'],
                'notes' => [],
                'errors' => ['Syntax error near BAD'],
                'skipped_count' => 0,
            ]),
            $output
        );
    }

    /**
     * A genuine failure whose SQL merely CONTAINS an idempotent phrase must
     * still fail this path (S159 review finding 1). The message shape is the
     * one MySQL 8.0.46 produces through the project's connection classes.
     */
    public function testGenuineErrorWhoseSqlContainsAnIdempotentPhraseStillFails(): void
    {
        $this->writeMigration(
            '001.sql',
            "ALTER TABLE gone ADD COLUMN foo INT COMMENT 'reuse the row if it already exists';"
        );

        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'schema_migrations')) {
                    return [];
                }
                throw new RuntimeException(
                    'SQL:' . $sql . " SQLSTATE[42S02]: Base table or view not found: "
                    . "1146 Table 'phlix.gone' doesn't exist"
                );
            }
        );

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $tester = $this->tester($runner);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Migrations FAILED', $output);
        $this->assertStringNotContainsString('skipped (already applied)', $output);
    }
}
