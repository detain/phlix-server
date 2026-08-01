<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Docker;

use PHPUnit\Framework\TestCase;

/**
 * S159 — the smoke check for `docker/docker-entrypoint.sh`.
 *
 * That file is the container's boot path and, before this test, was covered by
 * NOTHING: no unit test, and no CI job builds and boots the image. A change to
 * it was therefore unverifiable — the same trap as a CI job that never actually
 * runs. It also carried a blanket `|| true` around the migration call, which
 * made every possible migration outcome look identical.
 *
 * The script is driven here for real (`/bin/sh docker/docker-entrypoint.sh`)
 * with stub `php` and `supervisord` executables on `PATH`, so each branch is
 * proven by execution:
 *
 *   - migrations succeed          → supervisord is exec'd, exit 0
 *   - migrations FAIL, default    → banner on stderr, supervisord STILL exec'd,
 *                                   exit 0  (the DECIDED boot-path behaviour)
 *   - migrations FAIL, strict     → banner, supervisord NOT exec'd, the
 *                                   migration's own exit code is propagated
 *   - no database host / no script→ migrations skipped, supervisord exec'd
 *
 * The middle two are only observable because the script now inspects the exit
 * code; under `|| true` they were indistinguishable from the first.
 *
 * @coversNothing The subject is a shell script, not a PHP class.
 */
class DockerEntrypointTest extends TestCase
{
    private string $tmpDir = '';

    /** Absolute path to the entrypoint under test. */
    private string $entrypoint = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->entrypoint = dirname(__DIR__, 3) . '/docker/docker-entrypoint.sh';
        self::assertFileExists($this->entrypoint);

        if (!is_file('/bin/sh')) {
            self::markTestSkipped('No /bin/sh on this platform.');
        }

        $dir = sys_get_temp_dir() . '/phlix_entrypoint_' . bin2hex(random_bytes(6));
        mkdir($dir . '/bin', 0777, true);
        mkdir($dir . '/approot/scripts', 0777, true);
        $this->tmpDir = $dir;

        // A file only has to EXIST for the entrypoint's `-f` guard; the stub
        // `php` below never reads it.
        file_put_contents($dir . '/approot/scripts/run-migrations.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            self::removeTree($this->tmpDir);
        }
        $this->tmpDir = '';
        parent::tearDown();
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Install a stub `php` that records that it ran (and with what argument)
     * and exits with the given code — standing in for
     * `scripts/run-migrations.php`.
     */
    private function stubPhp(int $exitCode): void
    {
        $script = "#!/bin/sh\n"
            . 'printf \'%s\n\' "$@" > "' . $this->tmpDir . "/php-ran\"\n"
            . 'echo "stub php: pretending to run migrations"' . "\n"
            . 'exit ' . $exitCode . "\n";
        file_put_contents($this->tmpDir . '/bin/php', $script);
        chmod($this->tmpDir . '/bin/php', 0755);
    }

    /**
     * Install a stub `supervisord` that records the arguments it was exec'd
     * with. Its marker file is the proof that the container "started".
     */
    private function stubSupervisord(): void
    {
        $script = "#!/bin/sh\n"
            . 'printf \'%s \' "$@" > "' . $this->tmpDir . "/supervisord-ran\"\n"
            . 'echo "stub supervisord: started"' . "\n";
        file_put_contents($this->tmpDir . '/bin/supervisord', $script);
        chmod($this->tmpDir . '/bin/supervisord', 0755);
    }

    /**
     * Run the entrypoint with a controlled environment.
     *
     * @param array<string, string> $env Extra environment for the script.
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runEntrypoint(array $env = []): array
    {
        $baseEnv = [
            // The stub bin dir must win over the real toolchain; /usr/bin:/bin
            // stay on PATH because the script uses `tr`.
            'PATH' => $this->tmpDir . '/bin:/usr/bin:/bin',
            'PHLIX_APP_ROOT' => $this->tmpDir . '/approot',
            'PHLIX_SUPERVISORD' => $this->tmpDir . '/bin/supervisord',
            'PHLIX_SUPERVISORD_CONF' => $this->tmpDir . '/supervisord.conf',
        ];

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            ['/bin/sh', $this->entrypoint],
            $descriptors,
            $pipes,
            $this->tmpDir,
            array_merge($baseEnv, $env)
        );

        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'code' => proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function supervisordStarted(): bool
    {
        return is_file($this->tmpDir . '/supervisord-ran');
    }

    private function migrationsRan(): bool
    {
        return is_file($this->tmpDir . '/php-ran');
    }

    public function testCleanMigrationRunBootsTheContainer(): void
    {
        $this->stubPhp(0);
        $this->stubSupervisord();

        $run = $this->runEntrypoint(['PHLIX_DATABASE_HOST' => 'mysql']);

        self::assertSame(0, $run['code']);
        self::assertTrue($this->migrationsRan());
        self::assertTrue($this->supervisordStarted(), 'supervisord must be exec\'d after a clean migration run');
        self::assertStringContainsString('Running database migrations...', $run['stdout']);
        self::assertStringNotContainsString('PHLIX-MIGRATION-FAILURE', $run['stderr']);
        // The conf path is forwarded to supervisord unchanged.
        self::assertStringContainsString(
            '-c ' . $this->tmpDir . '/supervisord.conf',
            (string) file_get_contents($this->tmpDir . '/supervisord-ran')
        );
    }

    /**
     * The DECIDED default: a failed migration is LOUD but does not stop the
     * container. Under the old `|| true` the banner could not exist, because
     * the exit code was discarded before anything could look at it.
     */
    public function testFailedMigrationIsAnnouncedOnStderrAndStillBootsByDefault(): void
    {
        $this->stubPhp(1);
        $this->stubSupervisord();

        $run = $this->runEntrypoint(['PHLIX_DATABASE_HOST' => 'mysql']);

        self::assertSame(0, $run['code'], 'default boot-path behaviour is to start anyway');
        self::assertTrue($this->supervisordStarted());
        self::assertStringContainsString('PHLIX-MIGRATION-FAILURE', $run['stderr']);
        self::assertStringContainsString('exited 1', $run['stderr']);
        self::assertStringContainsString('Starting anyway (default)', $run['stderr']);
        self::assertStringContainsString('PHLIX_MIGRATIONS_STRICT=1', $run['stderr']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function strictValueProvider(): array
    {
        return [
            'one' => ['1'],
            'true' => ['true'],
            'yes' => ['yes'],
            'on' => ['on'],
            'uppercase TRUE' => ['TRUE'],
            'mixed-case On' => ['On'],
        ];
    }

    /**
     * @dataProvider strictValueProvider
     */
    public function testStrictModeRefusesToStartOnAFailedMigration(string $strictValue): void
    {
        $this->stubPhp(1);
        $this->stubSupervisord();

        $run = $this->runEntrypoint([
            'PHLIX_DATABASE_HOST' => 'mysql',
            'PHLIX_MIGRATIONS_STRICT' => $strictValue,
        ]);

        self::assertSame(1, $run['code']);
        self::assertFalse($this->supervisordStarted(), 'strict mode must NOT start the app');
        self::assertStringContainsString('refusing to start', $run['stderr']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonStrictValueProvider(): array
    {
        return [
            'zero' => ['0'],
            'false' => ['false'],
            'empty' => [''],
            'garbage' => ['maybe'],
        ];
    }

    /**
     * Anything that is not an explicit truthy value leaves the default
     * (boot-anyway) behaviour in place — a typo must not silently turn a media
     * server into a crash loop.
     *
     * @dataProvider nonStrictValueProvider
     */
    public function testNonTruthyStrictValuesKeepTheDefaultBehaviour(string $strictValue): void
    {
        $this->stubPhp(1);
        $this->stubSupervisord();

        $run = $this->runEntrypoint([
            'PHLIX_DATABASE_HOST' => 'mysql',
            'PHLIX_MIGRATIONS_STRICT' => $strictValue,
        ]);

        self::assertSame(0, $run['code']);
        self::assertTrue($this->supervisordStarted());
        self::assertStringContainsString('Starting anyway (default)', $run['stderr']);
    }

    /**
     * A fatal (non-1) exit — e.g. PHP's 255 for an uncaught exception, which
     * the old `|| true` also swallowed — is propagated verbatim in strict mode.
     */
    public function testStrictModePropagatesTheMigrationExitCode(): void
    {
        $this->stubPhp(255);
        $this->stubSupervisord();

        $run = $this->runEntrypoint([
            'PHLIX_DATABASE_HOST' => 'mysql',
            'PHLIX_MIGRATIONS_STRICT' => '1',
        ]);

        self::assertSame(255, $run['code']);
        self::assertStringContainsString('exited 255', $run['stderr']);
        self::assertFalse($this->supervisordStarted());
    }

    public function testMigrationsAreSkippedWhenNoDatabaseHostIsConfigured(): void
    {
        $this->stubPhp(1);
        $this->stubSupervisord();

        $run = $this->runEntrypoint([]); // no PHLIX_DATABASE_HOST

        self::assertSame(0, $run['code']);
        self::assertFalse($this->migrationsRan());
        self::assertTrue($this->supervisordStarted());
        self::assertStringNotContainsString('PHLIX-MIGRATION-FAILURE', $run['stderr']);
    }

    public function testMigrationsAreSkippedWhenTheScriptIsAbsent(): void
    {
        unlink($this->tmpDir . '/approot/scripts/run-migrations.php');
        $this->stubPhp(1);
        $this->stubSupervisord();

        $run = $this->runEntrypoint(['PHLIX_DATABASE_HOST' => 'mysql']);

        self::assertSame(0, $run['code']);
        self::assertFalse($this->migrationsRan());
        self::assertTrue($this->supervisordStarted());
    }
}
