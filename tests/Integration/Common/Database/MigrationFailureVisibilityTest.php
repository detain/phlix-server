<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Common\Database;

use Phlix\Tests\Support\Database\IntegrationDbGuard;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S159 — `scripts/run-migrations.php` must EXIT NON-ZERO when a migration
 * genuinely fails, and must keep exiting 0 for a clean run and for a legitimate
 * "already applied" replay.
 *
 * This test runs the real script in a real child process against a real MySQL
 * server and reads its real exit code. Nothing here is mocked, because the
 * defect being fixed lived precisely in the layer a mock replaces: the script
 * recorded the error, printed it as a `Warning:` and then fell off the end of
 * the file, so `echo $?` said 0 and `docker/docker-entrypoint.sh`'s `|| true`
 * finished the job. A unit test over `MigrationRunner` could never have seen
 * that, and did not.
 *
 * `PHLIX_MIGRATIONS_DIR` points the script at a scratch directory so the repo's
 * real `migrations/` is never involved, and every object this test creates is
 * prefixed `s159_` and dropped in tearDown.
 */
class MigrationFailureVisibilityTest extends TestCase
{
    private const SKIP_REASON = 'skipping the S159 migration exit-code proof. Runs in CI.';

    private Connection $db;

    private string $tmpDir = '';

    /** Unique suffix so parallel/reused databases cannot collide. */
    private string $tag = '';

    /** @var list<string> Tables to drop in tearDown. */
    private array $tables = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = IntegrationDbGuard::connection(self::SKIP_REASON);

        $this->tag = bin2hex(random_bytes(4));
        $dir = sys_get_temp_dir() . '/phlix_s159_' . $this->tag;
        mkdir($dir, 0777, true);
        $this->tmpDir = $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            try {
                $this->db->query('DROP TABLE IF EXISTS ' . $table);
            } catch (\Throwable) {
                // Best-effort cleanup.
            }
        }
        $this->tables = [];

        try {
            $this->db->query('DELETE FROM schema_migrations WHERE name LIKE ?', ['s159\_%']);
        } catch (\Throwable) {
            // Best-effort cleanup.
        }

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

    private function probeTable(string $suffix): string
    {
        $table = 's159_' . $this->tag . '_' . $suffix;
        $this->tables[] = $table;

        return $table;
    }

    /**
     * Run `scripts/run-migrations.php` in a child process against the scratch
     * migrations directory, and return its real exit code plus both streams.
     *
     * The child's environment is built EXPLICITLY rather than inherited so the
     * script provably targets the same server {@see IntegrationDbGuard} just
     * validated, and so `scripts/bootstrap_env.php` cannot pull in a real
     * `/etc/phlix/env` from the machine running the suite.
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runMigrationsScript(): array
    {
        $root = dirname(__DIR__, 4);
        $script = $root . '/scripts/run-migrations.php';
        self::assertFileExists($script);

        $env = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'PHLIX_MIGRATIONS_DIR' => $this->tmpDir,
            // Never let the child read a real env file off this machine.
            'PHLIX_ENV_FILE' => $this->tmpDir . '/no-such-env-file',
            'APP_ENV' => 'testing',
            'DB_HOST' => IntegrationDbGuard::host(),
            'DB_PORT' => (string) IntegrationDbGuard::port(),
            'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'phlix_test'),
            'DB_USER' => (string) (getenv('DB_USER') ?: 'root'),
            'DB_PASSWORD' => (string) (getenv('DB_PASSWORD') ?: ''),
        ];

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes, $root, $env);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function isRecordedInLedger(string $name): bool
    {
        $rows = $this->db->query('SELECT name FROM schema_migrations WHERE name = ?', [$name]);

        return is_array($rows) && $rows !== [];
    }

    private function tableExists(string $table): bool
    {
        $rows = $this->db->query(
            'SELECT table_name FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return is_array($rows) && $rows !== [];
    }

    /**
     * Direction 1 — a CLEAN run still exits 0.
     */
    public function testCleanRunExitsZero(): void
    {
        $table = $this->probeTable('clean');
        $this->writeMigration('s159_' . $this->tag . '_clean.sql', "CREATE TABLE {$table} (id INT);");

        $run = $this->runMigrationsScript();

        self::assertSame(0, $run['code'], "stdout:\n{$run['stdout']}\nstderr:\n{$run['stderr']}");
        self::assertStringContainsString('Migrations complete.', $run['stdout']);
        self::assertStringNotContainsString('Migrations FAILED', $run['stderr']);
        self::assertTrue($this->tableExists($table));
        self::assertTrue($this->isRecordedInLedger('s159_' . $this->tag . '_clean.sql'));
    }

    /**
     * Direction 2 — a GENUINELY FAILING migration exits NON-ZERO. This is the
     * exit code that did not exist before S159, and the one CI's "Apply
     * database migrations" step now trips over.
     *
     * It also pins the CONTINUE-AND-REPORT decision against real MySQL: the
     * file sorted after the failing one still applies, and the failing file is
     * left out of `schema_migrations` so the next run retries it (class (c)).
     */
    public function testFailingMigrationExitsNonZeroAndLaterFilesStillApply(): void
    {
        $later = $this->probeTable('later');
        $badName = 's159_' . $this->tag . '_1_bad.sql';
        $laterName = 's159_' . $this->tag . '_2_later.sql';

        $this->writeMigration($badName, 'CREATE TABLE s159_this_is_not_valid (;');
        $this->writeMigration($laterName, "CREATE TABLE {$later} (id INT);");

        $run = $this->runMigrationsScript();

        self::assertSame(1, $run['code'], "stdout:\n{$run['stdout']}\nstderr:\n{$run['stderr']}");
        self::assertStringContainsString('Warning:', $run['stdout']);
        self::assertStringContainsString('Migrations FAILED', $run['stderr']);
        self::assertStringNotContainsString('Migrations complete.', $run['stdout']);

        // Continue-and-report: the later file DID run.
        self::assertTrue($this->tableExists($later), 'a later migration must still be applied');
        // Class (c): the failed file is not recorded, the clean one is.
        self::assertFalse($this->isRecordedInLedger($badName));
        self::assertTrue($this->isRecordedInLedger($laterName));
    }

    /**
     * Direction 3 — an "already applied" REPLAY exits 0 and is reported as a
     * NOTE (collapsed into the skip summary), not as an error.
     *
     * The replay is a SEPARATE, un-recorded file that repeats an `ADD COLUMN`
     * already performed, which is exactly what MySQL 8 raises `Duplicate column
     * name` for — the real shape of the (a) class, rather than the ledger's
     * cheap "already recorded, skip without executing" path (covered below).
     */
    public function testAlreadyAppliedReplayExitsZeroAndIsReportedAsANote(): void
    {
        $table = $this->probeTable('replay');

        $this->writeMigration(
            's159_' . $this->tag . '_1_create.sql',
            "CREATE TABLE {$table} (id INT);\nALTER TABLE {$table} ADD COLUMN n INT;"
        );
        $first = $this->runMigrationsScript();
        self::assertSame(0, $first['code'], "stdout:\n{$first['stdout']}\nstderr:\n{$first['stderr']}");

        // A DIFFERENT file (so the ledger cannot skip it) repeating work that
        // is already done → real MySQL duplicate-column error → note, exit 0.
        $replayName = 's159_' . $this->tag . '_2_replay.sql';
        $this->writeMigration($replayName, "ALTER TABLE {$table} ADD COLUMN n INT;");

        $replay = $this->runMigrationsScript();

        self::assertSame(0, $replay['code'], "stdout:\n{$replay['stdout']}\nstderr:\n{$replay['stderr']}");
        self::assertStringContainsString('statement(s) skipped (already applied)', $replay['stdout']);
        self::assertStringNotContainsString('Warning:', $replay['stdout']);
        self::assertStringContainsString('Migrations complete.', $replay['stdout']);
        // An idempotent replay is NOT a failure, so the file is still recorded.
        self::assertTrue($this->isRecordedInLedger($replayName));
    }

    /**
     * Direction 4 — the steady state: re-running an unchanged, already-recorded
     * migration set executes nothing and exits 0. A deploy on a fully-migrated
     * box must never be reported as a failure.
     */
    public function testSteadyStateReRunExitsZero(): void
    {
        $table = $this->probeTable('steady');
        $this->writeMigration('s159_' . $this->tag . '_steady.sql', "CREATE TABLE {$table} (id INT);");

        $first = $this->runMigrationsScript();
        self::assertSame(0, $first['code'], "stdout:\n{$first['stdout']}\nstderr:\n{$first['stderr']}");

        $second = $this->runMigrationsScript();

        self::assertSame(0, $second['code'], "stdout:\n{$second['stdout']}\nstderr:\n{$second['stderr']}");
        self::assertStringNotContainsString('Running migration:', $second['stdout']);
        self::assertStringContainsString('Migrations complete.', $second['stdout']);
    }

    /**
     * Direction 5 (S159 review finding 1) — a GENUINE hard failure whose own SQL
     * happens to contain one of the old "already applied" phrases.
     *
     * `Workerman\MySQL\Connection` rethrows as `"SQL:" . $theWholeStatement .
     * " " . $pdoMessage`, so the classifier used to grep the migration's own
     * text. The statement below fails with errno 1146 (the table does not
     * exist) yet the phrase `already exists` lives in its `COMMENT`, and the run
     * reported `1 statement(s) skipped (already applied)` / `Migrations
     * complete.` / **exit 0** — AND recorded the file in `schema_migrations`, so
     * it was never retried. That defeated class (b) and class (c) at once and
     * was strictly worse than the pre-S159 behaviour this step exists to fix.
     *
     * Nothing here is simulated: the phrase, the errno, the exit code and the
     * ledger row all come from a real MySQL server.
     */
    public function testGenuineFailureWhoseSqlContainsAnIdempotentPhraseFailsAndIsNotRecorded(): void
    {
        $missing = 's159_' . $this->tag . '_no_such_table';
        $name = 's159_' . $this->tag . '_trap.sql';

        $this->writeMigration(
            $name,
            "ALTER TABLE {$missing} ADD COLUMN foo INT "
            . "COMMENT 'reuse the row if it already exists';"
        );

        $run = $this->runMigrationsScript();

        self::assertSame(1, $run['code'], "stdout:\n{$run['stdout']}\nstderr:\n{$run['stderr']}");
        self::assertStringContainsString('1146', $run['stdout']);
        self::assertStringContainsString('Migrations FAILED', $run['stderr']);
        self::assertStringNotContainsString('Migrations complete.', $run['stdout']);
        self::assertStringNotContainsString('skipped (already applied)', $run['stdout']);
        self::assertFalse(
            $this->isRecordedInLedger($name),
            'a file that failed with errno 1146 must be retried, not recorded'
        );
    }

    /**
     * The other side of the same control: each error number in the idempotent
     * set really does stay a NOTE with exit 0 against real MySQL, so the
     * finding-1 fix cannot have been "make everything an error".
     *
     * The set is EXACTLY these four — 1050 table exists / 1060 duplicate column
     * / 1061 duplicate key name / 1091 can't drop — replayed from a second,
     * un-recorded file. Each names an object that can only be the one the
     * failing statement was creating: a table in this schema, or a column /
     * index, both of which are TABLE-local.
     *
     * Round 1 of the review also asked for 1826 (duplicate FK name) and 3822
     * (duplicate CHECK name) "because they are named-object collisions of the
     * same shape". Round 2 rejected that: those names are unique per SCHEMA, so
     * the collision can be with a different table entirely — see
     * {@see testDuplicateConstraintNameOnCreateTableFailsAndIsNotRecorded()},
     * which is the other half of this pair and must be read with it.
     */
    public function testEachIdempotentErrorNumberIsStillANoteWithExitZero(): void
    {
        $table = $this->probeTable('codes');

        $this->writeMigration(
            's159_' . $this->tag . '_1_create.sql',
            "CREATE TABLE {$table} (id INT NOT NULL, n INT NULL, KEY k_n (n));"
        );
        $first = $this->runMigrationsScript();
        self::assertSame(0, $first['code'], "stdout:\n{$first['stdout']}\nstderr:\n{$first['stderr']}");

        $replayName = 's159_' . $this->tag . '_2_codes.sql';
        $this->writeMigration(
            $replayName,
            // 1050, 1060, 1061, 1091 in that order.
            "CREATE TABLE {$table} (id INT NOT NULL);\n"
            . "ALTER TABLE {$table} ADD COLUMN n INT NULL;\n"
            . "ALTER TABLE {$table} ADD KEY k_n (n);\n"
            . "ALTER TABLE {$table} DROP COLUMN never_existed;"
        );

        $replay = $this->runMigrationsScript();

        self::assertSame(0, $replay['code'], "stdout:\n{$replay['stdout']}\nstderr:\n{$replay['stderr']}");
        // 5 = the four idempotent errnos above + the ledger skip of the already
        // recorded `_1_create.sql` (skipped_count counts both, by design).
        self::assertStringContainsString('5 statement(s) skipped (already applied)', $replay['stdout']);
        self::assertStringNotContainsString('Warning:', $replay['stdout']);
        self::assertTrue($this->isRecordedInLedger($replayName));
    }

    /**
     * The two replay shapes that are class (b) ON PURPOSE (S159 review finding
     * 2, decided rather than merely noted): a seed `INSERT` replayed raises
     * 1062, which is also what `ADD UNIQUE` raises when the existing DATA
     * violates the constraint — the most valuable failure a migration can
     * report — so it must not be squelched. This test is the record of that
     * decision: if someone adds 1062 to the idempotent set, it goes red.
     */
    public function testReplayedSeedInsertIsADeliberateFailureNotANote(): void
    {
        $table = $this->probeTable('seed');

        $this->writeMigration(
            's159_' . $this->tag . '_1_seed.sql',
            "CREATE TABLE {$table} (k VARCHAR(8) NOT NULL PRIMARY KEY);\n"
            . "INSERT INTO {$table} (k) VALUES ('a');"
        );
        $first = $this->runMigrationsScript();
        self::assertSame(0, $first['code'], "stdout:\n{$first['stdout']}\nstderr:\n{$first['stderr']}");

        $replayName = 's159_' . $this->tag . '_2_seed_again.sql';
        $this->writeMigration($replayName, "INSERT INTO {$table} (k) VALUES ('a');");

        $replay = $this->runMigrationsScript();

        self::assertSame(1, $replay['code'], "stdout:\n{$replay['stdout']}\nstderr:\n{$replay['stderr']}");
        self::assertStringContainsString('1062', $replay['stdout']);
        self::assertFalse($this->isRecordedInLedger($replayName));
    }

    /**
     * S159 review ROUND 2, finding 1 — a duplicate FOREIGN KEY / CHECK
     * constraint NAME (1826 / 3822) is class (b), and must stay class (b).
     *
     * Round 1 asked for both errnos in the idempotent set on the grounds that
     * they are "named-object collisions, the same shape as 1050/1061". That is
     * true of 1061 — index names are TABLE-local, so a collision is almost
     * certainly the same index. It is FALSE here: in MySQL 8 a FOREIGN KEY name
     * and a CHECK-constraint name are unique **per schema**
     * (`information_schema.TABLE_CONSTRAINTS` / `CHECK_CONSTRAINTS` are keyed on
     * `CONSTRAINT_SCHEMA` + `CONSTRAINT_NAME`), so the object already holding
     * the name can belong to a completely different table — and, as below, the
     * statement that failed can be a `CREATE TABLE` that therefore created
     * NOTHING AT ALL.
     *
     * With those errnos squelched, both files below exited 0 with their tables
     * absent AND their names written into `schema_migrations`, so they were
     * never retried: classes (b) and (c) defeated at once, and strictly worse
     * than the pre-S159 behaviour (master's substring list contains neither
     * phrase, and `CREATE TABLE IF NOT EXISTS` does not contain "already
     * exists", so master fails loudly on exactly this input).
     *
     * Nothing here is simulated: two brand-new tables, real MySQL, the real
     * script's real exit code and the real ledger.
     */
    public function testDuplicateConstraintNameOnCreateTableFailsAndIsNotRecorded(): void
    {
        // Registered child-first so tearDown's DROP order respects the FK.
        $child = $this->probeTable('fkchild');
        $chk = $this->probeTable('chk');
        $parent = $this->probeTable('fkparent');
        // Never created — registered only so a partial run cannot leak them.
        $fkClash = $this->probeTable('fkclash');
        $chkClash = $this->probeTable('chkclash');

        $fkName = 'fk_' . $this->tag;
        $chkName = 'chk_' . $this->tag;

        $this->writeMigration(
            's159_' . $this->tag . '_1_constraints.sql',
            "CREATE TABLE {$parent} (id INT NOT NULL PRIMARY KEY);\n"
            . "CREATE TABLE {$child} (id INT NOT NULL PRIMARY KEY, pid INT NULL, "
            . "CONSTRAINT {$fkName} FOREIGN KEY (pid) REFERENCES {$parent}(id));\n"
            . "CREATE TABLE {$chk} (id INT NOT NULL PRIMARY KEY, v INT NULL, "
            . "CONSTRAINT {$chkName} CHECK (v > 0));"
        );
        $first = $this->runMigrationsScript();
        self::assertSame(0, $first['code'], "stdout:\n{$first['stdout']}\nstderr:\n{$first['stderr']}");

        // Two NEW tables, each reusing a constraint name that belongs to a
        // DIFFERENT table. `IF NOT EXISTS` cannot help: the table does not
        // exist, so MySQL proceeds and fails on the schema-scoped name.
        $fkFile = 's159_' . $this->tag . '_2_fk_clash.sql';
        $chkFile = 's159_' . $this->tag . '_3_chk_clash.sql';
        $this->writeMigration(
            $fkFile,
            "CREATE TABLE IF NOT EXISTS {$fkClash} (id INT NOT NULL PRIMARY KEY, pid INT NULL, "
            . "CONSTRAINT {$fkName} FOREIGN KEY (pid) REFERENCES {$parent}(id));"
        );
        $this->writeMigration(
            $chkFile,
            "CREATE TABLE IF NOT EXISTS {$chkClash} (id INT NOT NULL PRIMARY KEY, v INT NULL, "
            . "CONSTRAINT {$chkName} CHECK (v > 0));"
        );

        $clash = $this->runMigrationsScript();

        self::assertSame(1, $clash['code'], "stdout:\n{$clash['stdout']}\nstderr:\n{$clash['stderr']}");
        self::assertStringContainsString('1826', $clash['stdout']);
        self::assertStringContainsString('3822', $clash['stdout']);
        self::assertStringContainsString('Migrations FAILED', $clash['stderr']);
        self::assertStringNotContainsString('Migrations complete.', $clash['stdout']);

        // The statements created NOTHING …
        self::assertFalse($this->tableExists($fkClash), 'the FK-clash CREATE TABLE created nothing');
        self::assertFalse($this->tableExists($chkClash), 'the CHECK-clash CREATE TABLE created nothing');
        // … so the files must be retried, not recorded (class (c)).
        self::assertFalse($this->isRecordedInLedger($fkFile));
        self::assertFalse($this->isRecordedInLedger($chkFile));
    }
}
