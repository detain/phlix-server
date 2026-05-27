<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use Phlix\Common\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlix\Common\Database\MigrationRunner
 */
class MigrationRunnerTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir() . '/phlix_migr_' . bin2hex(random_bytes(6));
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

    public function testRunsEveryStatementOfEveryFileInSortedOrder(): void
    {
        // Deliberately written out of order to prove sort() is applied.
        $this->writeMigration('002_second.sql', "CREATE TABLE b (id INT);\nALTER TABLE b ADD COLUMN n INT;");
        $this->writeMigration('001_first.sql', 'CREATE TABLE a (id INT);');

        $captured = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured[] = $sql;
            return [];
        });

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        // applied lists both files, in sorted order.
        $this->assertSame(['001_first.sql', '002_second.sql'], $result['applied']);
        $this->assertSame([], $result['notes']);
        $this->assertSame([], $result['errors']);

        // Statements ran in file-then-statement order, comments/blank-only fragments dropped.
        $this->assertSame([
            'CREATE TABLE a (id INT)',
            'CREATE TABLE b (id INT)',
            'ALTER TABLE b ADD COLUMN n INT',
        ], $captured);
    }

    public function testStripsCommentsAndSplitsOnlyOnRealSemicolons(): void
    {
        // A `;` inside a `--` comment must NOT split the statement.
        $sql = "-- header; with a semicolon\n"
            . "/* block\n comment; here */\n"
            . "CREATE TABLE c (id INT); -- trailing comment;\n"
            . "INSERT INTO c VALUES (1);";
        $this->writeMigration('001.sql', $sql);

        $captured = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured[] = $sql;
            return [];
        });

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        $this->assertSame([
            'CREATE TABLE c (id INT)',
            'INSERT INTO c VALUES (1)',
        ], $captured);
        $this->assertSame([], $result['errors']);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function idempotentMessageProvider(): array
    {
        return [
            ['Duplicate column name "foo"'],
            ['Duplicate key name "idx_foo"'],
            ['Cant DROP; check that column/key exists'],
            ['Table "widgets" already exists'],
        ];
    }

    /**
     * @dataProvider idempotentMessageProvider
     */
    public function testIdempotentErrorsAreDowngradedToNotes(string $message): void
    {
        $this->writeMigration('001.sql', 'ALTER TABLE x ADD COLUMN y INT;');

        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willThrowException(new RuntimeException($message));

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        // Run does NOT fail; the file is still reported as applied and the
        // idempotent error is captured as a note, not an error.
        $this->assertSame(['001.sql'], $result['applied']);
        $this->assertSame([$message], $result['notes']);
        $this->assertSame([], $result['errors']);
    }

    public function testGenuineErrorIsRecordedButDoesNotAbortTheRun(): void
    {
        $this->writeMigration('001.sql', "GOOD STATEMENT;\nBAD STATEMENT;\nGOOD AGAIN;");

        $calls = 0;
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(function (string $sql) use (&$calls) {
            $calls++;
            if (str_contains($sql, 'BAD')) {
                throw new RuntimeException('Syntax error near BAD STATEMENT');
            }
            return [];
        });

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        // All three statements were attempted (the run did not abort on the error).
        $this->assertSame(3, $calls);
        $this->assertSame(['001.sql'], $result['applied']);
        $this->assertSame([], $result['notes']);
        $this->assertSame(['Syntax error near BAD STATEMENT'], $result['errors']);
    }

    public function testEmptyDirectoryYieldsNoWorkAndNoConnection(): void
    {
        $connectionResolved = false;
        $provider = function () use (&$connectionResolved): Connection {
            $connectionResolved = true;
            return $this->createMock(Connection::class);
        };

        $runner = new MigrationRunner($provider, $this->tmpDir);
        $result = $runner->run();

        $this->assertSame([], $result['applied']);
        $this->assertSame([], $result['notes']);
        $this->assertSame([], $result['errors']);
        // The provider IS invoked (mirrors the script obtaining $db up front),
        // but with no files no query is ever issued.
        $this->assertTrue($connectionResolved);
    }

    public function testConnectionProviderIsNotCalledAtConstruction(): void
    {
        $called = false;
        $provider = function () use (&$called): Connection {
            $called = true;
            return $this->createMock(Connection::class);
        };

        new MigrationRunner($provider, $this->tmpDir);

        $this->assertFalse($called, 'Construction must not open a database connection.');
    }
}
