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

    // ------------------------------------------------------------------
    // S159 review finding 1 — classification is on the MySQL error NUMBER,
    // never on a substring of a message that carries the migration's own SQL.
    //
    // Every message below is verbatim output captured from MySQL 8.0.46
    // through Phlix\Common\Database\PooledMySQLConnection, i.e. the exact
    // string MigrationRunner::run() stores in notes[] / errors[].
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function realDriverMessageProvider(): array
    {
        return [
            // --- class (a): a legitimate replay ---
            '1050 table exists' => [
                "SQL:CREATE TABLE p_parent (id INT NOT NULL PRIMARY KEY) SQLSTATE[42S01]: "
                . "Base table or view already exists: 1050 Table 'p_parent' already exists",
                true,
            ],
            '1060 duplicate column' => [
                "SQL:ALTER TABLE p_child ADD COLUMN v INT NULL SQLSTATE[42S21]: "
                . "Column already exists: 1060 Duplicate column name 'v'",
                true,
            ],
            '1061 duplicate key name' => [
                "SQL:ALTER TABLE p_child ADD KEY k_pid (pid) SQLSTATE[42000]: "
                . "Syntax error or access violation: 1061 Duplicate key name 'k_pid'",
                true,
            ],
            '1091 cannot drop' => [
                "SQL:ALTER TABLE p_child DROP COLUMN nope SQLSTATE[42000]: Syntax error or "
                . "access violation: 1091 Can't DROP 'nope'; check that column/key exists",
                true,
            ],

            // --- class (b): genuine failures, deliberately NOT squelched ---
            //
            // 1826/3822 were CONSIDERED for the idempotent set in review round 1
            // and REJECTED in round 2: a FOREIGN KEY / CHECK constraint name is
            // unique per SCHEMA, not per table, so the colliding object can
            // belong to a different table entirely and the failing statement can
            // be a `CREATE TABLE` that created NOTHING. See
            // MigrationRunner::IDEMPOTENT_ERROR_CODES, and the CREATE TABLE
            // shapes pinned further down.
            '1826 duplicate foreign key name is a genuine failure' => [
                "SQL:ALTER TABLE p_child ADD CONSTRAINT fk_pc FOREIGN KEY (pid) REFERENCES "
                . "p_parent(id) SQLSTATE[HY000]: General error: 1826 Duplicate foreign key "
                . "constraint name 'fk_pc'",
                false,
            ],
            '3822 duplicate check constraint name is a genuine failure' => [
                "SQL:ALTER TABLE p_chk ADD CONSTRAINT chk_n CHECK (n > 0) SQLSTATE[HY000]: "
                . "General error: 3822 Duplicate check constraint name 'chk_n'.",
                false,
            ],
            // The shape that makes 1826/3822 unsafe rather than merely
            // over-reaching: the statement is a CREATE TABLE, so squelching it
            // says "already applied" about a table that does not exist.
            '1826 raised by a CREATE TABLE that created nothing' => [
                'SQL:CREATE TABLE IF NOT EXISTS t_b (id INT PRIMARY KEY, pid INT, CONSTRAINT '
                . 'fk_dup FOREIGN KEY (pid) REFERENCES t_parent(id)) SQLSTATE[HY000]: General '
                . "error: 1826 Duplicate foreign key constraint name 'fk_dup'",
                false,
            ],
            '3822 raised by a CREATE TABLE that created nothing' => [
                'SQL:CREATE TABLE IF NOT EXISTS t_d (id INT PRIMARY KEY, v INT, CONSTRAINT '
                . 'chk_dup CHECK (v > 0)) SQLSTATE[HY000]: General error: 3822 Duplicate check '
                . "constraint name 'chk_dup'.",
                false,
            ],
            '1062 duplicate entry is a genuine failure' => [
                "SQL:INSERT INTO p_seed (k) VALUES ('a') SQLSTATE[23000]: Integrity constraint "
                . "violation: 1062 Duplicate entry 'a' for key 'p_seed.PRIMARY'",
                false,
            ],
            '1068 multiple primary key is a genuine failure' => [
                'SQL:ALTER TABLE p_pk ADD PRIMARY KEY (id) SQLSTATE[42000]: Syntax error or '
                . 'access violation: 1068 Multiple primary key defined',
                false,
            ],
            '1146 missing table' => [
                "SQL:ALTER TABLE p_no_such ADD COLUMN foo INT SQLSTATE[42S02]: Base table or "
                . "view not found: 1146 Table 'phlix.p_no_such' doesn't exist",
                false,
            ],
            '1054 unknown column' => [
                "SQL:SELECT nope FROM p_child SQLSTATE[42S22]: Column not found: 1054 Unknown "
                . "column 'nope' in 'field list'",
                false,
            ],
            '1064 syntax error' => [
                'SQL:CREATE TABLE broken ( SQLSTATE[42000]: Syntax error or access violation: '
                . '1064 You have an error in your SQL syntax',
                false,
            ],
            'connection refused carries no errno' => [
                'SQLSTATE[HY000] [2002] Connection refused',
                false,
            ],

            // --- the finding-1 reproduction: the idempotent PHRASES live in
            //     the statement text, the errno says the statement failed hard.
            "1146 whose SQL contains 'already exists'" => [
                "SQL:ALTER TABLE zz_no_such_table ADD COLUMN foo INT COMMENT 'reuse the row if "
                . "it already exists' SQLSTATE[42S02]: Base table or view not found: 1146 Table "
                . "'phlix.zz_no_such_table' doesn't exist",
                false,
            ],
            "1064 whose SQL contains 'Duplicate column name'" => [
                "SQL:ALTER TABLE t ADD COLUMN c VARCHAR(8) DEFAULT 'Duplicate column name' "
                . "SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in "
                . "your SQL syntax",
                false,
            ],
            "1146 whose SQL contains 'check that column/key exists'" => [
                "SQL:ALTER TABLE gone ADD COLUMN c INT COMMENT 'check that column/key exists' "
                . "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'phlix.gone' "
                . "doesn't exist",
                false,
            ],
            "1451 whose SQL contains 'Duplicate key name'" => [
                "SQL:DELETE FROM p_parent /* Duplicate key name */ SQLSTATE[23000]: Integrity "
                . "constraint violation: 1451 Cannot delete or update a parent row: a foreign "
                . "key constraint fails",
                false,
            ],
        ];
    }

    /**
     * @dataProvider realDriverMessageProvider
     */
    public function testClassificationUsesTheDriverErrnoNotTheStatementText(
        string $message,
        bool $expectedIdempotent
    ): void {
        $this->assertSame($expectedIdempotent, MigrationRunner::isAlreadyAppliedNote($message));
    }

    /**
     * End-to-end through `run()`: the reproduction from the review must be an
     * ERROR (exit 1) and the file must be left OUT of the ledger so the next
     * run retries it — class (b) AND class (c), both of which the phrase match
     * defeated at once.
     */
    public function testGenuineFailureWhoseSqlContainsAnIdempotentPhraseIsNotRecorded(): void
    {
        $this->writeMigration(
            '001_trap.sql',
            "ALTER TABLE zz_no_such_table ADD COLUMN foo INT "
            . "COMMENT 'reuse the row if it already exists';"
        );

        $recorded = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$recorded): array {
                if (str_starts_with(ltrim($sql), 'INSERT INTO schema_migrations')) {
                    $recorded[] = $params;
                    return [];
                }
                if (str_contains($sql, 'schema_migrations')) {
                    return [];
                }
                throw new RuntimeException(
                    'SQL:' . $sql . " SQLSTATE[42S02]: Base table or view not found: "
                    . "1146 Table 'phlix.zz_no_such_table' doesn't exist"
                );
            }
        );

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        $this->assertSame([], $result['notes']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(0, $result['skipped_count']);
        $this->assertSame(MigrationRunner::EXIT_FAILURE, MigrationRunner::exitCodeFor($result));
        $this->assertSame([], $recorded, 'a genuinely failed file must NOT enter the ledger');
    }

    /**
     * The inverse control: the same file shape, but a real duplicate-column
     * replay, stays a note and IS recorded.
     */
    public function testRealDuplicateColumnReplayIsStillANoteAndIsRecorded(): void
    {
        $this->writeMigration('001_replay.sql', 'ALTER TABLE t ADD COLUMN v INT;');

        $recorded = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$recorded): array {
                if (str_starts_with(ltrim($sql), 'INSERT INTO schema_migrations')) {
                    $recorded[] = $params;
                    return [];
                }
                if (str_contains($sql, 'schema_migrations')) {
                    return [];
                }
                throw new RuntimeException(
                    'SQL:' . $sql . " SQLSTATE[42S21]: Column already exists: "
                    . "1060 Duplicate column name 'v'"
                );
            }
        );

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        $this->assertCount(1, $result['notes']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(MigrationRunner::EXIT_SUCCESS, MigrationRunner::exitCodeFor($result));
        $this->assertCount(1, $recorded);
    }

    /**
     * An SQL-prefixed message we cannot split from its statement is treated as
     * a GENUINE error — the safe direction. Nothing produces this today (the
     * prefix is only ever added around a PDO message, which always carries a
     * SQLSTATE), but guessing would put the finding-1 hole straight back.
     */
    public function testSqlPrefixedMessageWithNoErrorSegmentIsNotSquelched(): void
    {
        $this->assertFalse(MigrationRunner::isAlreadyAppliedNote(
            "SQL:ALTER TABLE t ADD COLUMN c INT COMMENT 'already exists' mysterious driver text"
        ));
    }

    // ------------------------------------------------------------------
    // S159 review ROUND 2, finding 1 — 1826 / 3822 are NOT idempotent.
    //
    // Round 1 asked for them as "named-object collisions of the same shape as
    // 1050/1061". That reasoning is false: in MySQL 8 a FOREIGN KEY name and a
    // CHECK-constraint name are unique per SCHEMA, not per table, so the object
    // already holding the name can belong to a different table and the failing
    // statement can be a CREATE TABLE that created NOTHING. Squelching them
    // made a file exit 0 AND enter schema_migrations, so it was never retried —
    // classes (b) and (c) defeated at once, and strictly worse than master.
    // ------------------------------------------------------------------

    /**
     * Pin the DECISION, not just today's array: the set is exactly the four
     * collisions whose named object can only be the one the failing statement
     * was creating (a table in this schema; a column or an index, both of which
     * are TABLE-local). Anything schema-scoped — 1826, 3822 — must stay out.
     */
    public function testTheIdempotentSetIsExactlyTheFourTableLocalCollisions(): void
    {
        $reflected = new \ReflectionClass(MigrationRunner::class);
        /** @var list<int> $codes */
        $codes = $reflected->getConstant('IDEMPOTENT_ERROR_CODES');

        $this->assertSame(
            [1050, 1060, 1061, 1091],
            $codes,
            'IDEMPOTENT_ERROR_CODES is a CLOSED list. Before adding an errno, prove that the '
            . 'object MySQL reports as already existing can ONLY be the object the failing '
            . 'statement was creating. 1826/3822 fail that test — FK and CHECK constraint names '
            . 'are unique per SCHEMA, so the collision can be with another table and the '
            . 'statement (often a CREATE TABLE) may have created nothing at all.'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function duplicateConstraintNameProvider(): array
    {
        // Verbatim from MySQL 8.0.46 via PooledMySQLConnection: two files each
        // create a NEW table whose constraint name is already used by a
        // DIFFERENT table. Both tables are absent afterwards.
        return [
            '1826 foreign key' => [
                'CREATE TABLE IF NOT EXISTS t_b (id INT PRIMARY KEY, pid INT, CONSTRAINT fk_dup '
                . 'FOREIGN KEY (pid) REFERENCES t_parent(id))',
                "SQLSTATE[HY000]: General error: 1826 Duplicate foreign key constraint name "
                . "'fk_dup'",
            ],
            '3822 check constraint' => [
                'CREATE TABLE IF NOT EXISTS t_d (id INT PRIMARY KEY, v INT, CONSTRAINT chk_dup '
                . 'CHECK (v > 0))',
                "SQLSTATE[HY000]: General error: 3822 Duplicate check constraint name 'chk_dup'.",
            ],
        ];
    }

    /**
     * End to end through `run()`: a `CREATE TABLE` that fails 1826 / 3822 must
     * exit 1 AND must NOT be recorded in the ledger, so the next run retries it.
     *
     * @dataProvider duplicateConstraintNameProvider
     */
    public function testDuplicateConstraintNameOnCreateTableFailsAndIsNotRecorded(
        string $statement,
        string $driverMessage
    ): void {
        $this->writeMigration('001_ctor.sql', $statement . ';');

        $recorded = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$recorded, $driverMessage): array {
                if (str_starts_with(ltrim($sql), 'INSERT INTO schema_migrations')) {
                    $recorded[] = $params;
                    return [];
                }
                if (str_contains($sql, 'schema_migrations')) {
                    return [];
                }
                throw new RuntimeException('SQL:' . $sql . ' ' . $driverMessage);
            }
        );

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        $this->assertSame([], $result['notes']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(0, $result['skipped_count']);
        $this->assertSame(MigrationRunner::EXIT_FAILURE, MigrationRunner::exitCodeFor($result));
        $this->assertSame(
            [],
            $recorded,
            'a CREATE TABLE that created nothing must be retried, not recorded'
        );
    }

    // ------------------------------------------------------------------
    // S159 review ROUND 2, finding 2 — the `SQL:<statement> ` prefix is
    // stripped EXACTLY, so the "last SQLSTATE[ is the driver's" invariant
    // cannot be forged by a migration's own text.
    // ------------------------------------------------------------------

    /**
     * For errno 1064 MySQL echoes ~80 characters of the offending statement
     * back inside its own message (`… near '<tail>' at line 1`). A well-formed
     * `SQLSTATE[..]: ..: <errno>` decoy inside that echo window makes the LAST
     * `SQLSTATE[` the migration's rather than the driver's, and the decoy errno
     * wins. Message captured verbatim from MySQL 8.0.46.
     */
    public function testDecoySqlstateEchoedByMysqlCannotForgeAnIdempotentErrno(): void
    {
        $statement = "CREATE TABLE fix2_decoy (id INT) 'SQLSTATE[42S01]: e: 1050 z'";
        $message = 'SQL:' . $statement . ' SQLSTATE[42000]: Syntax error or access violation: '
            . '1064 You have an error in your SQL syntax; check the manual that corresponds to '
            . "your MySQL server version for the right syntax to use near ''SQLSTATE[42S01]: "
            . "e: 1050 z'' at line 1";

        $this->assertFalse(
            MigrationRunner::isAlreadyAppliedNote($message, $statement),
            'the driver text after the exact `SQL:<statement> ` prefix says 1064'
        );
    }

    /**
     * The same decoy driven through `run()`, which is where it matters and
     * where the statement is always known: exit 1, and the file stays out of
     * the ledger. This is also what goes red if the `$statement` argument is
     * ever dropped at the call site.
     */
    public function testDecoyStatementIsAnErrorAndIsNotRecordedEndToEnd(): void
    {
        $this->writeMigration(
            '001_decoy.sql',
            "CREATE TABLE fix2_decoy (id INT) 'SQLSTATE[42S01]: e: 1050 z';"
        );

        $recorded = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$recorded): array {
                if (str_starts_with(ltrim($sql), 'INSERT INTO schema_migrations')) {
                    $recorded[] = $params;
                    return [];
                }
                if (str_contains($sql, 'schema_migrations')) {
                    return [];
                }
                throw new RuntimeException(
                    'SQL:' . $sql . ' SQLSTATE[42000]: Syntax error or access violation: 1064 '
                    . 'You have an error in your SQL syntax; check the manual that corresponds '
                    . "to your MySQL server version for the right syntax to use near "
                    . "''SQLSTATE[42S01]: e: 1050 z'' at line 1"
                );
            }
        );

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        $this->assertSame([], $result['notes']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(0, $result['skipped_count']);
        $this->assertSame(MigrationRunner::EXIT_FAILURE, MigrationRunner::exitCodeFor($result));
        $this->assertSame([], $recorded);
    }

    /**
     * Check the fix against itself. Supplying the statement must NOT change any
     * ordinary case: a message with no `SQL:` prefix, a message with no
     * `SQLSTATE` at all, a bare non-PDO throwable message, and an SQL-prefixed
     * message whose prefix does not match the statement we were given (which
     * falls back to the previous behaviour rather than guessing) all keep the
     * verdict they had before the statement argument existed.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function statementAwareEquivalenceProvider(): array
    {
        return [
            'prefixed + idempotent errno' => [
                'ALTER TABLE p_child ADD COLUMN v INT NULL',
                'SQL:ALTER TABLE p_child ADD COLUMN v INT NULL SQLSTATE[42S21]: Column already '
                . "exists: 1060 Duplicate column name 'v'",
                true,
            ],
            'prefixed + genuine errno' => [
                'ALTER TABLE p_no_such ADD COLUMN foo INT',
                'SQL:ALTER TABLE p_no_such ADD COLUMN foo INT SQLSTATE[42S02]: Base table or '
                . "view not found: 1146 Table 'phlix.p_no_such' doesn't exist",
                false,
            ],
            'no SQL: prefix, phrase only (a mocked connection)' => [
                'ALTER TABLE x ADD COLUMN y INT',
                'Duplicate column name "y"',
                true,
            ],
            'no SQL: prefix, no SQLSTATE, not idempotent' => [
                'BAD STATEMENT',
                'Syntax error near BAD STATEMENT',
                false,
            ],
            'no SQLSTATE at all — the bare PDOException this project raises' => [
                'CREATE TABLE t (id INT)',
                'PDO connection is not available.',
                false,
            ],
            'connect failure carries no errno' => [
                'CREATE TABLE t (id INT)',
                'SQLSTATE[HY000] [2002] Connection refused',
                false,
            ],
            'SQL-prefixed but the prefix does not match the statement' => [
                'CREATE TABLE t (id INT)',
                'SQL:SOMETHING ELSE ENTIRELY SQLSTATE[42S21]: Column already exists: 1060 '
                . "Duplicate column name 'v'",
                true,
            ],
            'SQL-prefixed with no recognisable driver segment' => [
                "ALTER TABLE t ADD COLUMN c INT COMMENT 'already exists'",
                "SQL:ALTER TABLE t ADD COLUMN c INT COMMENT 'already exists' mysterious "
                . 'driver text',
                false,
            ],
        ];
    }

    /**
     * @dataProvider statementAwareEquivalenceProvider
     */
    public function testSupplyingTheStatementDoesNotChangeTheOrdinaryCases(
        string $statement,
        string $message,
        bool $expected
    ): void {
        $this->assertSame(
            $expected,
            MigrationRunner::isAlreadyAppliedNote($message),
            'verdict without the statement'
        );
        $this->assertSame(
            $expected,
            MigrationRunner::isAlreadyAppliedNote($message, $statement),
            'verdict with the statement — must be identical'
        );
    }

    // ------------------------------------------------------------------
    // S159 review finding 7 — the failure summary must not assert a state it
    // never checked.
    // ------------------------------------------------------------------

    public function testFailureSummaryNeverAssertsASchemaStateItDidNotCheck(): void
    {
        $attempted = MigrationRunner::failureSummary([
            'applied' => ['090_bad.sql'],
            'notes' => [],
            'errors' => ['SQLSTATE[42000]: … 1064 …'],
            'skipped_count' => 0,
        ]);
        $this->assertStringContainsString('1 error(s) in 1 file(s) attempted', $attempted);
        // The only claim about the schema is a conditional one plus how to
        // decide it — never the bare "The schema is HALF-MIGRATED".
        $this->assertStringNotContainsString('The schema is HALF-MIGRATED', $attempted);
        $this->assertStringContainsString('depends on the errors above', $attempted);
        $this->assertStringContainsString('NOT recorded in schema_migrations', $attempted);

        // Nothing attempted at all: unambiguous, so say so plainly.
        $nothingRan = MigrationRunner::failureSummary([
            'applied' => [],
            'notes' => [],
            'errors' => ['SQLSTATE[HY000] [2002] Connection refused'],
            'skipped_count' => 0,
        ]);
        $this->assertStringContainsString('0 file(s) attempted', $nothingRan);
        $this->assertStringContainsString('the schema is unchanged', $nothingRan);
        $this->assertStringContainsString('database is reachable', $nothingRan);
        $this->assertStringNotContainsString('PARTIALLY MIGRATED', $nothingRan);
    }

    /**
     * The shape an UNREACHABLE database actually produces (measured:
     * `DB_PORT=33999` against the real 100-file set gives
     * `229 error(s) in 100 file(s) attempted`). `applied` counts files
     * ATTEMPTED, not files changed, so "applied is empty" is NOT a usable test
     * for "nothing happened" — this pins that the summary does not pretend
     * otherwise.
     */
    public function testFailureSummaryDoesNotMistakeAnUnreachableDatabaseForAHalfMigratedSchema(): void
    {
        $summary = MigrationRunner::failureSummary([
            'applied' => array_map(static fn(int $i): string => sprintf('%03d.sql', $i), range(1, 100)),
            'notes' => [],
            'errors' => array_fill(0, 229, 'SQLSTATE[HY000] [2002] Connection refused'),
            'skipped_count' => 0,
        ]);

        $this->assertStringContainsString('229 error(s) in 100 file(s) attempted', $summary);
        $this->assertStringNotContainsString('The schema is HALF-MIGRATED', $summary);
        $this->assertStringContainsString('unreachable or misconfigured database changes nothing', $summary);
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

        $runner = new MigrationRunner($provider, $this->tmpDir);

        $this->assertInstanceOf(MigrationRunner::class, $runner);
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
        // Skipped, not applied; counted via skipped_count ONLY — NO per-file
        // note (SV-4.9 review finding 1: a per-file ledger-skip note would be
        // echoed in full by both callers every steady-state deploy, defeating
        // the skipped_count collapse). No re-record.
        $this->assertSame([], $result['applied']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame([], $result['notes']);
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

    /**
     * (f) SV-4.9 review finding 2a: a migration that raises a GENUINE
     * (non-idempotent) error is left UNRECORDED in the ledger, so it is
     * re-attempted on the next boot (the "re-run safe" contract).
     */
    public function testGenuineErrorLeavesMigrationUnrecorded(): void
    {
        $this->writeMigration('001.sql', 'CREATE TABLE a (id INT);');

        $ledgerWrites = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(
            function (string $q, $params = null) use (&$ledgerWrites) {
                if (str_contains($q, 'schema_migrations')) {
                    if (stripos(ltrim($q), 'SELECT') === 0) {
                        return []; // un-recorded → apply
                    }
                    if (stripos(ltrim($q), 'INSERT') === 0) {
                        $ledgerWrites[] = $params;
                    }
                    return [];
                }
                // A genuine, non-idempotent failure (NOT an "already applied" class).
                throw new RuntimeException('Syntax error near CREATE');
            }
        );

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        // The error was captured but the run did not abort.
        $this->assertSame(['Syntax error near CREATE'], $result['errors']);
        $this->assertSame(0, $result['skipped_count']);
        // CRITICAL: the file was NOT recorded, so it re-runs next boot.
        $this->assertSame([], $ledgerWrites);
    }

    /**
     * (g) SV-4.9 review finding 2b: a ledger READ failure (the SELECT throwing)
     * degrades to the historical apply-all path — every file is still applied,
     * no crash, treating the ledger as empty.
     */
    public function testLedgerReadFailureDegradesToApplyAll(): void
    {
        $this->writeMigration('001.sql', 'CREATE TABLE a (id INT);');
        $this->writeMigration('002.sql', 'CREATE TABLE b (id INT);');

        $executed = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(
            function (string $q, $params = null) use (&$executed) {
                if (str_contains($q, 'schema_migrations')) {
                    if (stripos(ltrim($q), 'SELECT') === 0) {
                        // Ledger read blows up (e.g. table missing / DB hiccup).
                        throw new RuntimeException('Table "schema_migrations" is missing');
                    }
                    return []; // CREATE / INSERT bookkeeping succeeds inertly.
                }
                $executed[] = $q;
                return [];
            }
        );

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        // Degrade path: both files applied as if the ledger were empty; no crash.
        $this->assertSame(['001.sql', '002.sql'], $result['applied']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(
            ['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'],
            $executed
        );
    }

    /**
     * (h) SV-4.9 review finding 1: in steady state (the ledger records every
     * file with a matching checksum) NO per-file "already applied" note is
     * emitted — only `skipped_count` — so both callers render a single quiet
     * summary line instead of one echoed line per file.
     */
    public function testSteadyStateSkipEmitsNoPerFileNotes(): void
    {
        $sqlA = 'CREATE TABLE a (id INT);';
        $sqlB = 'CREATE TABLE b (id INT);';
        $this->writeMigration('001.sql', $sqlA);
        $this->writeMigration('002.sql', $sqlB);

        // Ledger already records BOTH files with matching (normalised) checksums.
        $ledgerRows = [
            ['name' => '001.sql', 'checksum' => md5($sqlA)],
            ['name' => '002.sql', 'checksum' => md5($sqlB)],
        ];

        $executed = [];
        $conn = $this->connectionWithLedger($ledgerRows, function (string $sql) use (&$executed) {
            $executed[] = $sql;
            return [];
        });

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        $this->assertSame([], $executed, 'no migration statement should execute in steady state');
        $this->assertSame([], $result['applied']);
        $this->assertSame([], $result['notes'], 'steady-state skips must NOT push per-file notes');
        $this->assertSame(2, $result['skipped_count']);
    }

    /**
     * (i) SV-4.9 review finding 3: a documentation-only edit (adding/changing a
     * full-line `--` comment) does NOT diverge the normalised checksum, so an
     * already-applied file is still skipped rather than spuriously re-applied.
     */
    public function testCommentOnlyEditDoesNotDivergeChecksum(): void
    {
        // The file as recorded had a header comment; the current file's header
        // was edited (and trailing whitespace added) but the SQL is identical.
        $recordedSql = "-- old header\nCREATE TABLE a (id INT);\n";
        $currentSql = "-- a NEW, longer header line\n-- with an extra line\nCREATE TABLE a (id INT);   \n";

        $this->writeMigration('001.sql', $currentSql);

        // Recorded checksum = normalised md5 of the ORIGINAL file contents.
        $recordedChecksum = self::normalisedMd5($recordedSql);

        $executed = [];
        $conn = $this->connectionWithLedger(
            [['name' => '001.sql', 'checksum' => $recordedChecksum]],
            function (string $sql) use (&$executed) {
                $executed[] = $sql;
                return [];
            }
        );

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        // Comment/whitespace-only divergence → checksums still match → skipped.
        $this->assertSame([], $executed, 'a comment-only edit must not trigger a re-apply');
        $this->assertSame([], $result['applied']);
        $this->assertSame(1, $result['skipped_count']);
    }

    /**
     * (j) SV-4.9 review finding 3: a REAL SQL edit still diverges the normalised
     * checksum and triggers a (safe) one-time re-apply.
     */
    public function testRealSqlEditStillDivergesChecksum(): void
    {
        $recordedSql = "-- header\nCREATE TABLE a (id INT);\n";
        $currentSql = "-- header\nCREATE TABLE a (id BIGINT);\n"; // INT → BIGINT

        $this->writeMigration('001.sql', $currentSql);
        $recordedChecksum = self::normalisedMd5($recordedSql);

        $executed = [];
        $conn = $this->connectionWithLedger(
            [['name' => '001.sql', 'checksum' => $recordedChecksum]],
            function (string $sql) use (&$executed) {
                $executed[] = $sql;
                return [];
            }
        );

        $runner = new MigrationRunner(fn() => $conn, $this->tmpDir);
        $result = $runner->run();

        // The real change diverges the checksum → the file is re-applied.
        $this->assertSame(['CREATE TABLE a (id BIGINT)'], $executed);
        $this->assertSame(['001.sql'], $result['applied']);
    }

    // ------------------------------------------------------------------
    // S159 — exitCodeFor(): the single definition of "this run FAILED",
    // proven in BOTH directions and for all three failure classes.
    // ------------------------------------------------------------------

    /**
     * S159 class (b): a genuine, non-idempotent statement error must be
     * reachable as a NON-ZERO process exit code. This is the direction that was
     * unreachable before S159 from `scripts/run-migrations.php`.
     */
    public function testExitCodeIsFailureWhenAGenuineErrorWasRecorded(): void
    {
        $this->writeMigration('001.sql', 'BAD STATEMENT;');

        $conn = $this->connectionWithLedger([], static function (): array {
            throw new RuntimeException('Syntax error near BAD STATEMENT');
        });

        $result = (new MigrationRunner(fn() => $conn, $this->tmpDir))->run();

        $this->assertSame(['Syntax error near BAD STATEMENT'], $result['errors']);
        $this->assertSame(MigrationRunner::EXIT_FAILURE, MigrationRunner::exitCodeFor($result));
        $this->assertSame(1, MigrationRunner::exitCodeFor($result));
    }

    /**
     * The other direction: a clean run exits 0.
     */
    public function testExitCodeIsSuccessForACleanRun(): void
    {
        $this->writeMigration('001.sql', 'CREATE TABLE a (id INT);');

        $conn = $this->connectionWithLedger([], static fn(): array => []);

        $result = (new MigrationRunner(fn() => $conn, $this->tmpDir))->run();

        $this->assertSame([], $result['errors']);
        $this->assertSame(MigrationRunner::EXIT_SUCCESS, MigrationRunner::exitCodeFor($result));
        $this->assertSame(0, MigrationRunner::exitCodeFor($result));
    }

    /**
     * S159 class (a): an "already applied" REPLAY must still exit 0. Collapsing
     * this class into class (b) would redden every legitimate re-deploy — MySQL
     * 8 has no `IF NOT EXISTS` on `ADD COLUMN`/`ADD INDEX`, so a replay raises
     * one of these per statement — and the whole change would be reverted.
     *
     * @dataProvider idempotentMessageProvider
     */
    public function testExitCodeIsSuccessForAnAlreadyAppliedReplay(string $message): void
    {
        $this->writeMigration('001.sql', 'ALTER TABLE x ADD COLUMN y INT;');

        $conn = $this->connectionWithLedger([], static function () use ($message): array {
            throw new RuntimeException($message);
        });

        $result = (new MigrationRunner(fn() => $conn, $this->tmpDir))->run();

        $this->assertSame([$message], $result['notes'], 'replay must be a NOTE, not an error');
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(MigrationRunner::EXIT_SUCCESS, MigrationRunner::exitCodeFor($result));
    }

    /**
     * A steady-state boot where the ledger skips every file (nothing applied,
     * nothing failed) is the HEALTHY case and must exit 0 — an empty `applied`
     * list is not a failure signal.
     */
    public function testExitCodeIsSuccessWhenTheLedgerSkippedEverything(): void
    {
        $sql = "CREATE TABLE a (id INT);\n";
        $this->writeMigration('001.sql', $sql);

        $conn = $this->connectionWithLedger(
            [['name' => '001.sql', 'checksum' => self::normalisedMd5($sql)]],
            static function (): array {
                throw new RuntimeException('no migration statement should have executed');
            }
        );

        $result = (new MigrationRunner(fn() => $conn, $this->tmpDir))->run();

        $this->assertSame([], $result['applied']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(MigrationRunner::EXIT_SUCCESS, MigrationRunner::exitCodeFor($result));
    }

    /**
     * S159 — the CONTINUE-AND-REPORT decision, asserted rather than assumed:
     * a failing file does NOT stop later files, the run still reports failure
     * via the exit code, and (class (c)) the failing file is left out of the
     * ledger while the later, clean file is recorded.
     */
    public function testFailingFileDoesNotStopLaterFilesAndStillExitsNonZero(): void
    {
        $this->writeMigration('001_bad.sql', 'BAD STATEMENT;');
        $this->writeMigration('002_good.sql', 'CREATE TABLE b (id INT);');

        $executed = [];
        $recorded = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$executed, &$recorded) {
                if (str_contains($sql, 'schema_migrations')) {
                    if (stripos(ltrim($sql), 'SELECT') === 0) {
                        return [];
                    }
                    if (stripos(ltrim($sql), 'INSERT') === 0 && is_array($params)) {
                        $recorded[] = (string) $params[0];
                    }
                    return [];
                }

                $executed[] = $sql;
                if (str_contains($sql, 'BAD')) {
                    throw new RuntimeException('Syntax error near BAD STATEMENT');
                }

                return [];
            }
        );

        $result = (new MigrationRunner(fn() => $conn, $this->tmpDir))->run();

        // Continue-on-error: the later file still ran.
        $this->assertSame(['BAD STATEMENT', 'CREATE TABLE b (id INT)'], $executed);
        $this->assertSame(['001_bad.sql', '002_good.sql'], $result['applied']);
        // …and the run is still reported as a FAILURE.
        $this->assertSame(MigrationRunner::EXIT_FAILURE, MigrationRunner::exitCodeFor($result));
        // Class (c): only the clean file entered the ledger, so the failing one
        // is retried next run.
        $this->assertSame(['002_good.sql'], $recorded);
    }

    /**
     * `exitCodeFor()` is a pure function of the result shape — pinned directly
     * so the mapping cannot drift while every runner-driven test above still
     * passes.
     */
    public function testExitCodeForIsDecidedByErrorsAlone(): void
    {
        $this->assertSame(MigrationRunner::EXIT_SUCCESS, MigrationRunner::exitCodeFor([
            'applied' => [],
            'notes' => ['Duplicate column name "y"', 'Table "x" already exists'],
            'errors' => [],
            'skipped_count' => 99,
        ]));

        $this->assertSame(MigrationRunner::EXIT_FAILURE, MigrationRunner::exitCodeFor([
            'applied' => ['001.sql'],
            'notes' => [],
            'errors' => ['Unknown column "y" in "field list"'],
            'skipped_count' => 0,
        ]));
    }

    /**
     * Mirror of the runner's private checksum normalisation (strip full-line
     * `--` / `#` comments + per-line trailing whitespace, then md5), for tests
     * that need to compute the value the runner would record.
     */
    private static function normalisedMd5(string $sql): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(--|#)/', $line) === 1) {
                continue;
            }
            $kept[] = rtrim($line);
        }

        return md5(implode("\n", $kept));
    }
}
