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
 *
 * @coversNothing End-to-end behaviour of a script, not of one class.
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
}
