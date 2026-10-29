<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Common\Database;

use Phlix\Tests\Support\Database\IntegrationDbGuard;
use PHPUnit\Framework\TestCase;

/**
 * M27 — `bin/phlix migrate` and `scripts/run-migrations.php` must bootstrap
 * the managed env file IDENTICALLY (the README's "supported equivalent"
 * promise, review server__ipd M2).
 *
 * ## The asymmetry this pins
 *
 * On an install.sh-managed box the only copy of `DB_PASSWORD` lives in
 * `/etc/phlix/env`, which systemd hands to the server via `EnvironmentFile=`.
 * A hand-run CLI process gets nothing, so `scripts/run-migrations.php` has
 * always compensated by requiring `scripts/bootstrap_env.php` right after
 * the Composer autoloader. `bin/phlix` did NOT — until M27 — so on exactly
 * those boxes the README's primary command failed with
 * "Access denied … (using password: NO)" while its self-declared
 * "equivalent" succeeded. S159 review finding 11 settled this repo's law for
 * a previous asymmetry of the same class (PHLIX_MIGRATIONS_DIR): both
 * operator paths must behave alike, and the CODE is what gets fixed.
 *
 * ## How the parity is proven — behaviour, not source text
 *
 * The child env is scrubbed of every DB credential EXCEPT the one the probe
 * feeds it through the env file: a deliberately WRONG `DB_PASSWORD` in a
 * scratch file pointed at via `PHLIX_ENV_FILE`. The only way that password
 * can reach the MySQL handshake is by the entrypoint running
 * bootstrap_env.php's loader. The server then answers with
 * "Access denied … (using password: YES)" — the `YES`/`NO` bit of that
 * message is the observable: a path that bootstrapped says YES (sent the
 * garbage password), a path that did not says NO (sent empty). Both children
 * are also pinned to the identical S159 exit code (1): the auth failure
 * degrades through MigrationRunner's never-abort ledger arms into one
 * genuine (non-idempotent) statement error per run, which
 * `MigrationRunner::exitCodeFor()` maps to 1 on BOTH paths.
 *
 * Nothing executes against the server: the handshake fails before any
 * statement or ledger write happens (the planted `.sql` exists only to force
 * the first real query — with zero migration files the pool's lazy lease
 * never dials, see MigrationRunner::run()'s empty-directory return), so no
 * credential on this machine can "succeed" and no object is created or
 * dropped anywhere. A dev box without MySQL skips via IntegrationDbGuard;
 * CI's `test` job provisions mysql:8.0 and runs this for real.
 */
class CliEnvBootstrapParityTest extends TestCase
{
    private const SKIP_REASON = 'skipping the M27 env-bootstrap parity proof. Runs in CI.';

    /** Password the env file hands to both children. Deliberately WRONG. */
    private const PROBE_PASSWORD = 'msrv27-deliberately-wrong-password';

    private string $tmpDir = '';

    private string $migrationsDir = '';

    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Guard for the server's ABSENCE only (skip, not fail) — the probe
        // itself must land on a listener, or "using password" never appears.
        IntegrationDbGuard::connection(self::SKIP_REASON);

        $this->tmpDir = sys_get_temp_dir() . '/phlix_msrv27_' . bin2hex(random_bytes(4));
        $this->migrationsDir = $this->tmpDir . '/migrations';
        $this->envFile = $this->tmpDir . '/env';

        self::assertTrue(mkdir($this->migrationsDir, 0777, true));

        // Never executed (auth fails first) — its sole job is to make the
        // runner issue a real query so the pool opens its socket.
        file_put_contents($this->migrationsDir . '/zz_msrv27_parity_probe.sql', 'SELECT 1;');

        file_put_contents($this->envFile, "# M27 parity probe env file\nDB_PASSWORD=" . self::PROBE_PASSWORD . "\n");
    }

    protected function tearDown(): void
    {
        if ($this->envFile !== '') {
            @unlink($this->envFile);
        }
        if ($this->migrationsDir !== '') {
            @unlink($this->migrationsDir . '/zz_msrv27_parity_probe.sql');
            @rmdir($this->migrationsDir);
        }
        if ($this->tmpDir !== '') {
            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    public function testBinPhlixMigrateBootstrapsTheEnvFileItIsDocumentedEquivalentOn(): void
    {
        $result = $this->runEntrypoint(['bin/phlix', 'migrate']);

        // The cure: DB_PASSWORD travelled from PHLIX_ENV_FILE into the
        // handshake. On the parent this reads "(using password: NO)" — the
        // exact failure scripts/bootstrap_env.php's header warns about.
        $this->assertEnvFilePasswordReachedTheHandshake($result, 'bin/phlix migrate');
    }

    public function testRunMigrationsScriptBootstrapsTheSameEnvFileTheSameWay(): void
    {
        $result = $this->runEntrypoint(['scripts/run-migrations.php']);

        // Control arm: the script side of the README's equivalence pair.
        // Green at the parent too — that is what makes the pair's mismatch
        // (not this assertion) the finding.
        $this->assertEnvFilePasswordReachedTheHandshake($result, 'scripts/run-migrations.php');
    }

    /**
     * @param array{code: int, out: string} $result
     */
    private function assertEnvFilePasswordReachedTheHandshake(array $result, string $label): void
    {
        $this->assertStringNotContainsString(
            '(using password: NO)',
            $result['out'],
            $label . ' connected WITHOUT the env-file password — the M27 asymmetry. Child output: '
                . $result['out']
        );
        $this->assertStringContainsString(
            '(using password: YES)',
            $result['out'],
            $label . ' never sent a non-empty password, so the env-file bootstrap is not wired: '
                . $result['out']
        );
        $this->assertSame(
            1,
            $result['code'],
            $label . ' must exit 1 on the auth failure (S159 parity, both paths): ' . $result['out']
        );
    }

    /**
     * Spawn one documented migration entrypoint as a real subprocess against
     * the guard's server, with an EXPLICIT env (never inherited — phpunit.xml
     * exports DB_PASSWORD for the suite and the probe needs it ABSENT), and
     * return its exit code plus merged stdout+stderr.
     *
     * @param list<string> $argv Entry file (repo-relative) then arguments.
     * @return array{code: int, out: string}
     */
    private function runEntrypoint(array $argv): array
    {
        $root = dirname(__DIR__, 4);
        $entry = $root . '/' . $argv[0];
        self::assertFileExists($entry);

        $env = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'APP_ENV' => 'testing',
            // Every DB_* the config resolves EXCEPT DB_PASSWORD — which may
            // therefore only ever arrive via the env file below.
            'DB_HOST' => IntegrationDbGuard::host(),
            'DB_PORT' => (string) IntegrationDbGuard::port(),
            'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'phlix_test'),
            'DB_USER' => (string) (getenv('DB_USER') ?: 'root'),
            'PHLIX_MIGRATIONS_DIR' => $this->migrationsDir,
            'PHLIX_ENV_FILE' => $this->envFile,
        ];

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(array_merge([PHP_BINARY], $argv), $descriptors, $pipes, $root, $env);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $stdout . $stderr];
    }
}
