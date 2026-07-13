<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use Phlix\Common\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

    /**
     * Build a mock connection whose SV-4.9 ledger bookkeeping queries (the
     * `schema_migrations` CREATE / SELECT / INSERT the runner now issues) are
     * handled inertly, so a test's `$onStatement` callback only ever sees the
     * actual migration statements. The ledger SELECT returns `$ledgerRows`
     * (empty = a fresh / unpopulated ledger, i.e. the pre-SV-4.9 behaviour).
     *
     * @param list<array{name: string, checksum: string}> $ledgerRows
     * @param callable(string): mixed $onStatement
     */
    private function connectionWithLedger(array $ledgerRows, callable $onStatement): Connection
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(
            function (string $sql, $params = null) use ($ledgerRows, $onStatement) {
                if (str_contains($sql, 'schema_migrations')) {
                    if (stripos(ltrim($sql), 'SELECT') === 0) {
                        return $ledgerRows;
                    }

                    return []; // CREATE TABLE IF NOT EXISTS / INSERT bookkeeping.
                }

                return $onStatement($sql);
            }
        );

        return $conn;
    }

    public function testRunsEveryStatementOfEveryFileInSortedOrder(): void
    {
        // Deliberately written out of order to prove sort() is applied.
        $this->writeMigration('002_second.sql', "CREATE TABLE b (id INT);\nALTER TABLE b ADD COLUMN n INT;");
        $this->writeMigration('001_first.sql', 'CREATE TABLE a (id INT);');

        $captured = [];
        $conn = $this->connectionWithLedger([], function (string $sql) use (&$captured) {
            $captured[] = $sql;
            return [];
        });

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        // applied lists both files, in sorted order.
        $this->assertSame(['001_first.sql', '002_second.sql'], $result['applied']);
        $this->assertSame([], $result['notes']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(0, $result['skipped_count']);

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
        $conn = $this->connectionWithLedger([], function (string $sql) use (&$captured) {
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

    public function testDoesNotSplitOnSemicolonInsideStringLiteral(): void
    {
        // Regression: a `;` inside a column COMMENT string literal must NOT
        // split the CREATE TABLE — doing so truncates the DDL mid-string and
        // fails with a 1064 syntax error.
        $sql = "CREATE TABLE c (\n"
            . "    id INT COMMENT 'Hard expiry; the token is invalid once this passes'\n"
            . ");\n"
            . "INSERT INTO c VALUES (1);";
        $this->writeMigration('001.sql', $sql);

        $captured = [];
        $conn = $this->connectionWithLedger([], function (string $sql) use (&$captured) {
            $captured[] = $sql;
            return [];
        });

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        $this->assertCount(2, $captured);
        $this->assertStringContainsString(
            "COMMENT 'Hard expiry; the token is invalid once this passes'",
            $captured[0],
        );
        $this->assertSame('INSERT INTO c VALUES (1)', $captured[1]);
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
        // Every downgraded note is of the "already applied" class, so the
        // summary counter callers use for the one-line skip summary bumps.
        $this->assertSame(1, $result['skipped_count']);
    }

    public function testGenuineErrorIsRecordedButDoesNotAbortTheRun(): void
    {
        $this->writeMigration('001.sql', "GOOD STATEMENT;\nBAD STATEMENT;\nGOOD AGAIN;");

        $calls = 0;
        $conn = $this->connectionWithLedger([], function (string $sql) use (&$calls) {
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
        // Genuine errors are NOT counted as already-applied skips.
        $this->assertSame(0, $result['skipped_count']);
    }

    public function testSkippedCountAccumulatesAcrossStatementsAndFiles(): void
    {
        $this->writeMigration('001.sql', "ALTER TABLE x ADD COLUMN y INT;\nALTER TABLE x ADD KEY idx_y (y);");
        $this->writeMigration('002.sql', 'CREATE TABLE x (id INT);');

        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, 'ADD COLUMN')) {
                throw new RuntimeException('Duplicate column name "y"');
            }
            if (str_contains($sql, 'ADD KEY')) {
                throw new RuntimeException('Duplicate key name "idx_y"');
            }
            throw new RuntimeException('Table "x" already exists');
        });

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        // All three idempotent replays are captured as notes AND counted, so
        // callers can print a single "3 statements skipped" summary line.
        $this->assertSame(['001.sql', '002.sql'], $result['applied']);
        $this->assertCount(3, $result['notes']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(3, $result['skipped_count']);
    }

    /**
     * @dataProvider idempotentMessageProvider
     */
    public function testIsAlreadyAppliedNoteRecognisesIdempotentClasses(string $message): void
    {
        $this->assertTrue(MigrationRunner::isAlreadyAppliedNote($message));
    }

    public function testIsAlreadyAppliedNoteRejectsOtherMessages(): void
    {
        $this->assertFalse(MigrationRunner::isAlreadyAppliedNote('Syntax error near BAD STATEMENT'));
        $this->assertFalse(MigrationRunner::isAlreadyAppliedNote("Unknown column 'y' in 'field list'"));
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
        $this->assertSame(0, $result['skipped_count']);
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

    // --- SV-4.9: schema_migrations ledger -----------------------------------

    /**
     * (a) A migration is recorded in `schema_migrations` after a clean apply.
     */
    public function testCleanApplyRecordsMigrationInLedger(): void
    {
        $sql = 'CREATE TABLE a (id INT);';
        $this->writeMigration('001.sql', $sql);
        $checksum = md5($sql);

        $ledgerWrites = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(
            function (string $q, $params = null) use (&$ledgerWrites): array {
                if (str_contains($q, 'schema_migrations')) {
                    if (stripos(ltrim($q), 'INSERT') === 0) {
                        $ledgerWrites[] = $params;
                    }
                    return []; // CREATE / SELECT / INSERT bookkeeping.
                }
                return [];
            }
        );

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        $this->assertSame(['001.sql'], $result['applied']);
        $this->assertSame([], $result['errors']);
        // Exactly one ledger row was written, with the file's name + md5.
        $this->assertCount(1, $ledgerWrites);
        $this->assertSame('001.sql', $ledgerWrites[0][0]);
        $this->assertIsInt($ledgerWrites[0][1]);
        $this->assertSame($checksum, $ledgerWrites[0][2]);
    }

    /**
     * (b) A recorded file whose checksum still matches is SKIPPED — its
     * statements are never executed on the next run.
     */
    public function testRecordedChecksumMatchIsSkippedNotExecuted(): void
    {
        $sql = 'CREATE TABLE a (id INT);';
        $this->writeMigration('001.sql', $sql);
        $checksum = md5($sql);

        $executed = [];
        $ledgerWrites = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(
            function (string $q, $params = null) use (&$executed, &$ledgerWrites, $checksum) {
                if (str_contains($q, 'schema_migrations')) {
                    if (stripos(ltrim($q), 'SELECT') === 0) {
                        return [['name' => '001.sql', 'checksum' => $checksum]];
                    }
                    if (stripos(ltrim($q), 'INSERT') === 0) {
                        $ledgerWrites[] = $params;
                    }
                    return [];
                }
                $executed[] = $q; // an actual migration statement executed
                return [];
            }
        );

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        // The migration statement was NOT executed.
        $this->assertSame([], $executed);
        // Skipped, not applied; surfaced as a note + skip count; no re-record.
        $this->assertSame([], $result['applied']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(['001.sql already applied (ledger), skipping'], $result['notes']);
        $this->assertSame([], $ledgerWrites);
    }

    /**
     * (c) A recorded file whose checksum DIVERGED logs a warning, re-applies,
     * and refreshes the recorded checksum.
     */
    public function testRecordedChecksumDivergenceWarnsAndReapplies(): void
    {
        $sql = 'CREATE TABLE a (id INT);';
        $this->writeMigration('001.sql', $sql);
        $currentChecksum = md5($sql);

        $executed = [];
        $ledgerWrites = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(
            function (string $q, $params = null) use (&$executed, &$ledgerWrites) {
                if (str_contains($q, 'schema_migrations')) {
                    if (stripos(ltrim($q), 'SELECT') === 0) {
                        // Recorded with a STALE checksum (file edited since).
                        return [['name' => '001.sql', 'checksum' => str_repeat('0', 32)]];
                    }
                    if (stripos(ltrim($q), 'INSERT') === 0) {
                        $ledgerWrites[] = $params;
                    }
                    return [];
                }
                $executed[] = $q;
                return [];
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Migration checksum diverged; re-applying', $this->anything());

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir, $logger);
        $result = $runner->run();

        // Re-applied: statement ran, file listed as applied.
        $this->assertSame(['CREATE TABLE a (id INT)'], $executed);
        $this->assertSame(['001.sql'], $result['applied']);
        // Recorded checksum refreshed to the current file's md5.
        $this->assertCount(1, $ledgerWrites);
        $this->assertSame($currentChecksum, $ledgerWrites[0][2]);
    }

    /**
     * (d) Transition: an EMPTY ledger on a box that already has every migration
     * applied (the current live state) re-applies safely (idempotent notes) and
     * backfills the ledger.
     */
    public function testEmptyLedgerButAlreadyAppliedReappliesSafelyAndBackfills(): void
    {
        $sql = 'CREATE TABLE a (id INT);';
        $this->writeMigration('001.sql', $sql);
        $checksum = md5($sql);

        $ledgerWrites = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(
            function (string $q, $params = null) use (&$ledgerWrites) {
                if (str_contains($q, 'schema_migrations')) {
                    if (stripos(ltrim($q), 'SELECT') === 0) {
                        return []; // empty ledger — current live state
                    }
                    if (stripos(ltrim($q), 'INSERT') === 0) {
                        $ledgerWrites[] = $params;
                    }
                    return [];
                }
                // Object already exists on an already-migrated box → idempotent.
                throw new RuntimeException('Table "a" already exists');
            }
        );

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        // Re-applied without a genuine failure, then backfilled into the ledger.
        $this->assertSame(['001.sql'], $result['applied']);
        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['notes']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertCount(1, $ledgerWrites);
        $this->assertSame('001.sql', $ledgerWrites[0][0]);
        $this->assertSame($checksum, $ledgerWrites[0][2]);
    }

    /**
     * (e) The ledger table is bootstrap-created (idempotently) BEFORE the first
     * ledger read, so the runner does not depend on `076` running first.
     */
    public function testBootstrapsLedgerTableBeforeReadingIt(): void
    {
        $this->writeMigration('001.sql', 'CREATE TABLE a (id INT);');

        $queries = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(function (string $q) use (&$queries): array {
            $queries[] = $q;
            return [];
        });

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $runner->run();

        $createIdx = null;
        $selectIdx = null;
        foreach ($queries as $i => $q) {
            if ($createIdx === null && stripos($q, 'CREATE TABLE IF NOT EXISTS schema_migrations') !== false) {
                $createIdx = $i;
            }
            if ($selectIdx === null && stripos(ltrim($q), 'SELECT') === 0 && str_contains($q, 'schema_migrations')) {
                $selectIdx = $i;
            }
        }

        $this->assertNotNull($createIdx, 'ledger bootstrap CREATE TABLE IF NOT EXISTS was not issued');
        $this->assertNotNull($selectIdx, 'ledger SELECT was not issued');
        $this->assertLessThan($selectIdx, $createIdx, 'bootstrap CREATE must precede the ledger read');
    }
}
