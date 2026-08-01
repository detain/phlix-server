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
            // stay on PATH because the script uses `tr` and `od`.
            'PATH' => $this->tmpDir . '/bin:/usr/bin:/bin',
            'PHLIX_APP_ROOT' => $this->tmpDir . '/approot',
            'PHLIX_SUPERVISORD' => $this->tmpDir . '/bin/supervisord',
            'PHLIX_SUPERVISORD_CONF' => $this->tmpDir . '/supervisord.conf',
            // Keep the JWT-secret persistence inside the sandbox: the real
            // default is /var/phlix/config/jwt_secret, which a test host has no
            // business writing to.
            'PHLIX_JWT_SECRET_FILE' => $this->tmpDir . '/jwt_secret',
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

    /**
     * The arguments the stub `php` was invoked with.
     *
     * S159 review finding 8: `stubPhp()` recorded `"$@"` and NOTHING ever read
     * it, so the whole suite stayed green if the entrypoint were changed to run
     * a different file. Every case that expects migrations to run now asserts
     * WHICH script ran.
     */
    private function assertMigrationScriptWasInvoked(): void
    {
        self::assertTrue($this->migrationsRan(), 'the entrypoint must invoke php');
        self::assertStringContainsString(
            $this->tmpDir . '/approot/scripts/run-migrations.php',
            (string) file_get_contents($this->tmpDir . '/php-ran'),
            'the entrypoint must invoke scripts/run-migrations.php, not some other file'
        );
    }

    public function testCleanMigrationRunBootsTheContainer(): void
    {
        $this->stubPhp(0);
        $this->stubSupervisord();

        $run = $this->runEntrypoint(['PHLIX_DATABASE_HOST' => 'mysql']);

        self::assertSame(0, $run['code']);
        $this->assertMigrationScriptWasInvoked();
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
        $this->assertMigrationScriptWasInvoked();
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
        $this->assertMigrationScriptWasInvoked();
        self::assertFalse($this->supervisordStarted(), 'strict mode must NOT start the app');
        self::assertStringContainsString('refusing to start', $run['stderr']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paddedStrictValueProvider(): array
    {
        return [
            'trailing space' => ['1 '],
            'leading space' => [' true'],
            'tab-padded' => ["\tyes\t"],
        ];
    }

    /**
     * A docker `env_file` preserves surrounding whitespace, so
     * `PHLIX_MIGRATIONS_STRICT=1 ` used to fall through to boot-anyway — an
     * opt-in that looks applied and is not (S159 review finding 6, secondary).
     *
     * @dataProvider paddedStrictValueProvider
     */
    public function testStrictModeIgnoresSurroundingWhitespace(string $strictValue): void
    {
        $this->stubPhp(1);
        $this->stubSupervisord();

        $run = $this->runEntrypoint([
            'PHLIX_DATABASE_HOST' => 'mysql',
            'PHLIX_MIGRATIONS_STRICT' => $strictValue,
        ]);

        self::assertSame(1, $run['code']);
        self::assertFalse($this->supervisordStarted());
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

    // ------------------------------------------------------------------
    // S159 review finding 6 — STRICT must also cover the two ways migrations
    // can fail to happen AT ALL. Before this, the operator who opted into
    // "refuse to start unless the schema is current" got a silent boot on
    // exactly the paths where nothing verified the schema.
    // ------------------------------------------------------------------

    public function testStrictModeRefusesToStartWhenNoDatabaseHostIsConfigured(): void
    {
        $this->stubPhp(0);
        $this->stubSupervisord();

        $run = $this->runEntrypoint(['PHLIX_MIGRATIONS_STRICT' => '1']);

        self::assertSame(1, $run['code']);
        self::assertFalse($this->migrationsRan());
        self::assertFalse($this->supervisordStarted(), 'STRICT must not boot with an unverified schema');
        self::assertStringContainsString('PHLIX-MIGRATIONS-NOT-RUN', $run['stderr']);
        // A skip is NOT a migration failure, so the failure banner must not fire.
        self::assertStringNotContainsString('PHLIX-MIGRATION-FAILURE', $run['stderr']);
    }

    public function testStrictModeRefusesToStartWhenTheScriptIsAbsent(): void
    {
        unlink($this->tmpDir . '/approot/scripts/run-migrations.php');
        $this->stubPhp(0);
        $this->stubSupervisord();

        $run = $this->runEntrypoint([
            'PHLIX_DATABASE_HOST' => 'mysql',
            'PHLIX_MIGRATIONS_STRICT' => '1',
        ]);

        self::assertSame(1, $run['code']);
        self::assertFalse($this->supervisordStarted());
        self::assertStringContainsString('PHLIX-MIGRATIONS-NOT-RUN', $run['stderr']);
    }

    /**
     * The default (non-strict) skip path stays a plain informational line — no
     * failure banner on a boot where nothing went wrong.
     */
    public function testSkippingMigrationsIsAnnouncedButIsNotAFailure(): void
    {
        $this->stubPhp(0);
        $this->stubSupervisord();

        $run = $this->runEntrypoint([]);

        self::assertSame(0, $run['code']);
        self::assertTrue($this->supervisordStarted());
        self::assertStringContainsString('Skipping database migrations', $run['stdout']);
        self::assertStringNotContainsString('PHLIX-MIGRATION-FAILURE', $run['stderr']);
        self::assertStringNotContainsString('PHLIX-MIGRATIONS-NOT-RUN', $run['stderr']);
    }

    // ------------------------------------------------------------------
    // S159 review finding 5 — PHLIX_DATABASE_* is what every documented
    // deployment sets and NO PHP in this repo reads. Without the mapping, a
    // correctly-configured container would run migrations against the
    // `127.0.0.1 / phlix` defaults and print the failure banner on every boot.
    // ------------------------------------------------------------------

    /**
     * Install a stub `php` that records the DB_* environment it was given.
     */
    private function stubPhpRecordingEnv(): void
    {
        $script = "#!/bin/sh\n"
            . 'printf \'%s\n\' "$@" > "' . $this->tmpDir . "/php-ran\"\n"
            . '{ echo "DB_HOST=$DB_HOST"; echo "DB_PORT=$DB_PORT"; echo "DB_DATABASE=$DB_DATABASE";'
            . ' echo "DB_USER=$DB_USER"; echo "DB_PASSWORD=$DB_PASSWORD";'
            . ' echo "JWT_SECRET=$JWT_SECRET"; } > "'
            . $this->tmpDir . "/php-env\"\n"
            . "exit 0\n";
        file_put_contents($this->tmpDir . '/bin/php', $script);
        chmod($this->tmpDir . '/bin/php', 0755);
    }

    public function testPhlixDatabaseEnvIsMappedOntoTheNamesConfigDatabaseActuallyReads(): void
    {
        $this->stubPhpRecordingEnv();
        $this->stubSupervisord();

        $run = $this->runEntrypoint([
            'PHLIX_DATABASE_HOST' => 'mysql',
            'PHLIX_DATABASE_PORT' => '3307',
            'PHLIX_DATABASE_NAME' => 'phlix',
            'PHLIX_DATABASE_USER' => 'phlix',
            'PHLIX_DATABASE_PASSWORD' => 'phlix_secret',
        ]);

        self::assertSame(0, $run['code']);
        $env = (string) file_get_contents($this->tmpDir . '/php-env');
        self::assertStringContainsString("DB_HOST=mysql\n", $env);
        self::assertStringContainsString("DB_PORT=3307\n", $env);
        self::assertStringContainsString("DB_DATABASE=phlix\n", $env);
        self::assertStringContainsString("DB_USER=phlix\n", $env);
        self::assertStringContainsString("DB_PASSWORD=phlix_secret\n", $env);
    }

    /**
     * An operator who configures the app with the names the app actually reads
     * must never be overridden by a stale PHLIX_DATABASE_* left in a compose
     * file.
     */
    public function testExplicitDbEnvWinsOverThePhlixDatabaseAliases(): void
    {
        $this->stubPhpRecordingEnv();
        $this->stubSupervisord();

        $run = $this->runEntrypoint([
            'PHLIX_DATABASE_HOST' => 'stale-host',
            'PHLIX_DATABASE_NAME' => 'stale-db',
            'DB_HOST' => 'real-host',
            'DB_DATABASE' => 'real-db',
        ]);

        self::assertSame(0, $run['code']);
        $env = (string) file_get_contents($this->tmpDir . '/php-env');
        self::assertStringContainsString("DB_HOST=real-host\n", $env);
        self::assertStringContainsString("DB_DATABASE=real-db\n", $env);
    }

    /**
     * `DB_HOST` alone is enough to make migrations run — the guard must not
     * insist on the PHLIX_DATABASE_* spelling.
     */
    public function testDbHostAloneEnablesMigrations(): void
    {
        $this->stubPhp(0);
        $this->stubSupervisord();

        $run = $this->runEntrypoint(['DB_HOST' => 'mysql']);

        self::assertSame(0, $run['code']);
        $this->assertMigrationScriptWasInvoked();
        self::assertTrue($this->supervisordStarted());
    }

    // ------------------------------------------------------------------
    // S163 — JWT_SECRET. `start.php:57-62` calls
    // AuthServicesProvider::assertSecretConfigured() BEFORE any Worker exists
    // and exit(1)s on an empty key, while `grep -rn JWT_SECRET
    // docker-compose.yml docker/examples k8s/` returns NOTHING: every
    // documented deployment sets PHLIX_SECRET_KEY, a name no PHP in this repo
    // reads. So a correctly-configured container would have the daemon exit 1
    // on every boot and supervisord BACKOFF then FATAL it. Exactly the
    // PHLIX_DATABASE_* trap of S159 finding 5, one env var over.
    // ------------------------------------------------------------------

    public function testAnExplicitJwtSecretIsPassedThroughUnchanged(): void
    {
        $this->stubPhpRecordingEnv();
        $this->stubSupervisord();

        $run = $this->runEntrypoint([
            'PHLIX_DATABASE_HOST' => 'mysql',
            'JWT_SECRET' => 'operator-configured-secret',
        ]);

        self::assertSame(0, $run['code']);
        self::assertStringContainsString(
            "JWT_SECRET=operator-configured-secret\n",
            (string) file_get_contents($this->tmpDir . '/php-env')
        );
        // An explicit secret must not be persisted over a mounted one.
        self::assertFileDoesNotExist($this->tmpDir . '/jwt_secret');
    }

    public function testPhlixSecretKeyIsMappedOntoTheNameTheAppActuallyReads(): void
    {
        $this->stubPhpRecordingEnv();
        $this->stubSupervisord();

        $run = $this->runEntrypoint([
            'PHLIX_DATABASE_HOST' => 'mysql',
            'PHLIX_SECRET_KEY' => 'from-the-compose-file',
        ]);

        self::assertSame(0, $run['code']);
        self::assertStringContainsString(
            "JWT_SECRET=from-the-compose-file\n",
            (string) file_get_contents($this->tmpDir . '/php-env')
        );
    }

    public function testJwtSecretWinsOverThePhlixSecretKeyAlias(): void
    {
        $this->stubPhpRecordingEnv();
        $this->stubSupervisord();

        $run = $this->runEntrypoint([
            'PHLIX_DATABASE_HOST' => 'mysql',
            'PHLIX_SECRET_KEY' => 'stale-alias',
            'JWT_SECRET' => 'real-secret',
        ]);

        self::assertSame(0, $run['code']);
        self::assertStringContainsString(
            "JWT_SECRET=real-secret\n",
            (string) file_get_contents($this->tmpDir . '/php-env')
        );
    }

    /**
     * A bare `docker run` with no secret configured must still produce a
     * working server — and the generated key must be PERSISTED, because one
     * that changed on every restart would silently invalidate every session and
     * every signed media URL.
     */
    public function testASecretIsGeneratedAndPersistedWhenNoneIsConfigured(): void
    {
        $this->stubPhpRecordingEnv();
        $this->stubSupervisord();

        $run = $this->runEntrypoint(['PHLIX_DATABASE_HOST' => 'mysql']);

        self::assertSame(0, $run['code']);
        self::assertTrue($this->supervisordStarted());
        self::assertFileExists($this->tmpDir . '/jwt_secret');

        $persisted = (string) file_get_contents($this->tmpDir . '/jwt_secret');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $persisted);
        self::assertStringContainsString(
            "JWT_SECRET={$persisted}\n",
            (string) file_get_contents($this->tmpDir . '/php-env'),
            'the generated secret must reach the process supervisord starts'
        );
        self::assertStringContainsString('persisted it to', $run['stdout']);
    }

    public function testAPersistedSecretIsReusedOnTheNextBoot(): void
    {
        $this->stubPhpRecordingEnv();
        $this->stubSupervisord();

        $first = $this->runEntrypoint(['PHLIX_DATABASE_HOST' => 'mysql']);
        self::assertSame(0, $first['code']);
        $generated = (string) file_get_contents($this->tmpDir . '/jwt_secret');

        $second = $this->runEntrypoint(['PHLIX_DATABASE_HOST' => 'mysql']);
        self::assertSame(0, $second['code']);

        self::assertSame(
            $generated,
            (string) file_get_contents($this->tmpDir . '/jwt_secret'),
            'a restart must not mint a new signing key'
        );
        self::assertStringContainsString(
            "JWT_SECRET={$generated}\n",
            (string) file_get_contents($this->tmpDir . '/php-env')
        );
        self::assertStringContainsString('persisted at', $second['stdout']);
    }

    /**
     * An unwritable (or unmounted) config volume must degrade LOUDLY, not
     * silently — and must still start the server.
     */
    public function testAnUnwritableSecretPathWarnsButStillBoots(): void
    {
        $this->stubPhpRecordingEnv();
        $this->stubSupervisord();

        $run = $this->runEntrypoint([
            'PHLIX_DATABASE_HOST' => 'mysql',
            'PHLIX_JWT_SECRET_FILE' => $this->tmpDir . '/no-such-dir/jwt_secret',
        ]);

        self::assertSame(0, $run['code']);
        self::assertTrue($this->supervisordStarted());
        self::assertStringContainsString('PHLIX-JWT-SECRET-EPHEMERAL', $run['stderr']);
        self::assertMatchesRegularExpression(
            '/JWT_SECRET=[0-9a-f]{64}\n/',
            (string) file_get_contents($this->tmpDir . '/php-env'),
            'the app must still receive a usable key'
        );
    }

    /**
     * S163 review F3 — the PHLIX_SECRET_KEY -> JWT_SECRET mapping turned
     * `docker/examples/.env.example`'s COMMITTED placeholder into a live
     * signing key: /proc/1/environ showed
     * `JWT_SECRET=change_me_generate_with_openssl` and the daemon served 200s
     * with it. `AuthServicesProvider::assertSecretConfigured()` rejects only
     * '' and 'default-secret-change-me', so it could not catch this. The
     * mapping introduced the defect, so the guard lives beside the mapping.
     *
     * @return array<string, array{string}>
     */
    public static function placeholderSecretProvider(): array
    {
        return [
            'the value committed in docker/examples/.env.example' => ['change_me_generate_with_openssl'],
            'the app sentinel' => ['default-secret-change-me'],
            'change_me' => ['change_me'],
            'changeme' => ['changeme'],
            'uppercase' => ['CHANGE_ME_GENERATE_WITH_OPENSSL'],
            'bare secret' => ['secret'],
            'your-secret-here' => ['your-secret-here'],
        ];
    }

    /**
     * @dataProvider placeholderSecretProvider
     */
    public function testAPlaceholderJwtSecretRefusesToStart(string $placeholder): void
    {
        $this->stubPhp(0);
        $this->stubSupervisord();

        $run = $this->runEntrypoint([
            'PHLIX_DATABASE_HOST' => 'mysql',
            'JWT_SECRET' => $placeholder,
        ]);

        self::assertSame(1, $run['code'], 'a publicly-known signing key must fail CLOSED');
        self::assertFalse($this->supervisordStarted(), 'the app must not start with a forgeable key');
        self::assertStringContainsString('PHLIX-PLACEHOLDER-SECRET', $run['stderr']);
    }

    /**
     * @dataProvider placeholderSecretProvider
     */
    public function testAPlaceholderPhlixSecretKeyRefusesToStart(string $placeholder): void
    {
        $this->stubPhp(0);
        $this->stubSupervisord();

        $run = $this->runEntrypoint([
            'PHLIX_DATABASE_HOST' => 'mysql',
            'PHLIX_SECRET_KEY' => $placeholder,
        ]);

        self::assertSame(1, $run['code']);
        self::assertFalse($this->supervisordStarted());
        self::assertStringContainsString('PHLIX-PLACEHOLDER-SECRET', $run['stderr']);
    }

    /**
     * The guard must not fire on a real key — a false positive here is a total
     * outage for anyone who legitimately generated one.
     */
    public function testARealSecretIsNotMistakenForAPlaceholder(): void
    {
        $this->stubPhpRecordingEnv();
        $this->stubSupervisord();

        $real = str_repeat('a1b2c3d4', 8);
        $run = $this->runEntrypoint([
            'PHLIX_DATABASE_HOST' => 'mysql',
            'JWT_SECRET' => $real,
        ]);

        self::assertSame(0, $run['code']);
        self::assertTrue($this->supervisordStarted());
        self::assertStringNotContainsString('PHLIX-PLACEHOLDER-SECRET', $run['stderr']);
        self::assertStringContainsString(
            "JWT_SECRET={$real}\n",
            (string) file_get_contents($this->tmpDir . '/php-env')
        );
    }

    /**
     * The shipped example must not hand anyone a working-looking secret.
     */
    public function testTheEnvExampleShipsNoUsablePhlixSecretKey(): void
    {
        $example = (string) file_get_contents(
            dirname(__DIR__, 3) . '/docker/examples/.env.example'
        );

        self::assertDoesNotMatchRegularExpression(
            '/^PHLIX_SECRET_KEY=.+$/m',
            $example,
            'docker/examples/.env.example must not ship a PHLIX_SECRET_KEY value — '
            . 'the entrypoint generates and persists one when it is unset'
        );
    }

    /**
     * S163 review F6 — a FATAL program used to leave the container `Up` with
     * nothing serving and nothing consuming `unhealthy`, which is the exact
     * shape of the outage this step exists to fix.
     */
    public function testSupervisordTurnsAFatalProgramIntoADeadContainer(): void
    {
        $root = dirname(__DIR__, 3);
        $conf = (string) file_get_contents($root . '/docker/supervisord.conf');

        self::assertStringContainsString('[eventlistener:exit-on-fatal]', $conf);
        self::assertMatchesRegularExpression('/^events=PROCESS_STATE_FATAL\s*$/m', $conf);

        self::assertSame(
            1,
            preg_match('#^command=sh\s+(\S+supervisord-exit-on-fatal\.sh)\s*$#m', $conf, $m),
            'the listener must be invoked as `sh <path>` so the file mode cannot break boot'
        );
        // The path is inside the image; map it back to the repo.
        $inRepo = $root . '/docker/' . basename($m[1]);
        self::assertFileExists($inRepo, 'supervisord.conf names a listener script that is not in the repo');
        self::assertStringContainsString('kill -TERM 1', (string) file_get_contents($inRepo));
    }

    // ------------------------------------------------------------------
    // S159 review finding 4 — the boot path must actually EXIST in the images.
    // All three Dockerfiles ended with `CMD ["sh", "/docker-entrypoint.sh"]`
    // and none of them ever copied the file to `/`, so no shipped container had
    // run this script since c2127f91. A `docker build` never executes CMD, so
    // every image Build job passed with the boot path dead. These assertions
    // are the cheap permanent guard.
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function dockerfileProvider(): array
    {
        return [
            'alpine' => ['docker/Dockerfile'],
            'intel' => ['docker/Dockerfile.intel'],
            'nvidia' => ['docker/Dockerfile.nvidia'],
        ];
    }

    /**
     * EVERY Dockerfile in the repo, including the shared base.
     *
     * S163 review F4: `dockerfileProvider()` lists only the three RUNTIME
     * images, so `testNoDockerfileWiresNginxOrPhpFpmIntoTheImage` and
     * `testNoDockerfileMasksMissingPlatformRequirements` were green while
     * `docker/Dockerfile.base` still did `FROM php:8.3-fpm-alpine` and
     * `apk add nginx` — i.e. the Alpine image DID ship nginx + php-fpm and
     * DID `EXPOSE 9000`, and the tests asserting otherwise passed by simply
     * not looking. That is the same false-confidence failure this whole step
     * exists to kill, so the fix is the PROVIDER, not just the Dockerfile.
     *
     * Kept separate from dockerfileProvider() because the base legitimately has
     * no CMD/EXPOSE/HEALTHCHECK — it is not a runnable image.
     *
     * @return array<string, array{string}>
     */
    public static function allDockerfileProvider(): array
    {
        return self::dockerfileProvider() + ['base' => ['docker/Dockerfile.base']];
    }

    /**
     * The provider guard: if a Dockerfile is added and not scanned, the
     * "no nginx/php-fpm anywhere" assertions quietly stop covering it.
     */
    public function testEveryDockerfileInTheRepoIsCoveredByTheProvider(): void
    {
        $root = dirname(__DIR__, 3);
        $found = glob($root . '/docker/Dockerfile*') ?: [];
        $found = array_map(static fn (string $f): string => 'docker/' . basename($f), $found);
        sort($found);

        $covered = array_map(static fn (array $row): string => $row[0], self::allDockerfileProvider());
        sort($covered);

        self::assertSame(
            $found,
            $covered,
            'allDockerfileProvider() must list every docker/Dockerfile* in the repo'
        );
    }

    /**
     * @dataProvider dockerfileProvider
     */
    public function testEveryDockerfileCopiesTheEntrypointToThePathItsCmdNames(string $relative): void
    {
        $dockerfile = dirname(__DIR__, 3) . '/' . $relative;
        self::assertFileExists($dockerfile);
        $contents = (string) file_get_contents($dockerfile);

        self::assertSame(
            1,
            preg_match('/^CMD \["sh", "([^"]+)"\]/m', $contents, $cmd),
            $relative . ' must end with a CMD that runs the entrypoint with sh'
        );

        self::assertMatchesRegularExpression(
            '/^COPY\s+docker\/docker-entrypoint\.sh\s+' . preg_quote($cmd[1], '/') . '\s*$/m',
            $contents,
            $relative . ' names ' . $cmd[1] . ' in CMD but never COPYs the entrypoint there'
        );
    }

    /**
     * The harness always overrides PHLIX_APP_ROOT / PHLIX_SUPERVISORD_CONF, so
     * the DEFAULTS — the only values a real container uses — would otherwise be
     * unasserted (S159 review finding 8, second half).
     *
     * @dataProvider dockerfileProvider
     */
    public function testEntrypointDefaultsMatchTheDockerfileLayout(string $relative): void
    {
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
        $script = (string) file_get_contents($this->entrypoint);

        self::assertSame(1, preg_match('/^WORKDIR\s+(\S+)\s*$/m', $dockerfile, $workdir));
        self::assertStringContainsString(
            'APP_ROOT="${PHLIX_APP_ROOT:-' . $workdir[1] . '}"',
            $script,
            'the entrypoint default APP_ROOT must match ' . $relative . "'s WORKDIR"
        );

        self::assertSame(
            1,
            preg_match('#^COPY\s+docker/supervisord\.conf\s+(\S+)\s*$#m', $dockerfile, $conf)
        );
        self::assertStringContainsString(
            'SUPERVISORD_CONF="${PHLIX_SUPERVISORD_CONF:-' . $conf[1] . '}"',
            $script,
            'the entrypoint default SUPERVISORD_CONF must match where ' . $relative . ' puts it'
        );
    }

    // ------------------------------------------------------------------
    // S163 — the serving model, pinned.
    //
    // These are TEXT assertions and are deliberately the WEAK half of the
    // guard: they cannot see a wrong binary name, a missing directory, a
    // missing extension or a FATAL program. The real gate is
    // `scripts/docker-boot-smoke.sh` / the `docker-boot-gate` CI job, which
    // BOOTS each image. These exist only to make an accidental reintroduction
    // fail in the fast suite instead of ten minutes into an image build.
    // ------------------------------------------------------------------

    /**
     * Strip comment lines so an assertion cannot be satisfied — or defeated —
     * by prose. These files document at length WHY nginx/php-fpm/
     * `--ignore-platform-reqs`/`public/index.php` are gone, and a naive
     * `assertStringNotContainsString` against the raw file matches that very
     * explanation.
     *
     * @param string $contents      The file body.
     * @param string $commentPrefix `#` for a Dockerfile, `;` for an ini/conf.
     *
     * @return string Only the directive lines.
     */
    private static function directivesOnly(string $contents, string $commentPrefix = '#'): string
    {
        $kept = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (!str_starts_with(ltrim($line), $commentPrefix)) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    public function testSupervisordStartsTheDaemonAndNothingElse(): void
    {
        $conf = self::directivesOnly(
            (string) file_get_contents(dirname(__DIR__, 3) . '/docker/supervisord.conf'),
            ';'
        );

        self::assertMatchesRegularExpression(
            '/^command=php\s+\S*start\.php\s+start\s*$/m',
            $conf,
            'supervisord must run the Workerman daemon (start.php), not the CGI front controller'
        );
        self::assertStringNotContainsString(
            'public/index.php',
            $conf,
            'public/index.php is the one-shot CGI front controller — it has no `start` verb'
        );
        self::assertDoesNotMatchRegularExpression(
            '/^\[program:(nginx|php-fpm)\]/m',
            $conf,
            'the image runs the daemon only; nginx/php-fpm are not part of the serving path'
        );
        // supervisorctl was unusable — the one command an operator reaches for
        // answered "no such file" while the container reported Up.
        self::assertStringContainsString('[unix_http_server]', $conf);
        self::assertStringContainsString('[supervisorctl]', $conf);
        self::assertStringContainsString('[rpcinterface:supervisor]', $conf);
    }

    public function testTheImageNginxConfigIsGone(): void
    {
        self::assertFileDoesNotExist(
            dirname(__DIR__, 3) . '/docker/nginx.conf',
            'docker/nginx.conf was a second serving architecture that existed only in the images'
        );
    }

    /**
     * Docker reads `.dockerignore` ONLY from the build context root, and every
     * build uses `context: .`. At `docker/.dockerignore` it was inert, which
     * defeated `--no-dev` and shipped `.logs/` (api_key/token/password strings)
     * inside the published image.
     */
    public function testTheDockerignoreLivesAtTheContextRoot(): void
    {
        $root = dirname(__DIR__, 3);

        self::assertFileExists($root . '/.dockerignore');
        self::assertFileDoesNotExist($root . '/docker/.dockerignore');

        $ignore = (string) file_get_contents($root . '/.dockerignore');
        self::assertStringContainsString('vendor/', $ignore);
        // A bare `*.log` matches the context ROOT only, so it never covered
        // `.logs/app-*.log`.
        self::assertStringContainsString('.logs/', $ignore);
    }

    /**
     * @dataProvider dockerfileProvider
     */
    public function testEveryDockerfileExposesThePortsTheDaemonBinds(string $relative): void
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);

        self::assertMatchesRegularExpression(
            '/^EXPOSE\s+8096\s+8097\s*$/m',
            $contents,
            $relative . ' must expose 8096 (HTTP) and 8097 (SyncPlay WS) — the ports start.php binds'
        );
        self::assertDoesNotMatchRegularExpression(
            '/^EXPOSE.*\b(80|443|9000)\b/m',
            $contents,
            $relative . ' must not expose ports nothing listens on'
        );
    }

    /**
     * The absence of a HEALTHCHECK is why a container that 502'd every request
     * for 22 minutes reported `Up 22 minutes`.
     *
     * @dataProvider dockerfileProvider
     */
    public function testEveryDockerfileDeclaresAHealthcheck(string $relative): void
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);

        self::assertMatchesRegularExpression(
            '/^HEALTHCHECK\s/m',
            $contents,
            $relative . ' must declare a HEALTHCHECK'
        );
        self::assertStringContainsString(
            'http://127.0.0.1:8096/health',
            $contents,
            $relative . "'s HEALTHCHECK must probe the route the application owns"
        );
    }

    /**
     * @dataProvider allDockerfileProvider
     */
    public function testNoDockerfileWiresNginxOrPhpFpmIntoTheImage(string $relative): void
    {
        $contents = self::directivesOnly(
            (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative)
        );

        self::assertStringNotContainsString('nginx.conf', $contents, $relative);
        self::assertDoesNotMatchRegularExpression(
            '/^\s+(nginx|php-fpm|php8\.\d-fpm)\s*\\\\?$/m',
            $contents,
            $relative . ' must not install nginx or php-fpm'
        );
        // A `-fpm` base image ships php-fpm AND `EXPOSE 9000` no matter what
        // the package list says — which is exactly how the Alpine image kept
        // both while three tests said it had neither.
        self::assertDoesNotMatchRegularExpression(
            '/^FROM\s+\S*php:\S*-fpm/m',
            $contents,
            $relative . ' must not build on a -fpm base: it carries php-fpm and EXPOSE 9000'
        );
    }

    /**
     * `--ignore-platform-reqs` is what let ext-ldap — a HARD composer.json
     * requirement — be absent from every image while the build stayed green.
     *
     * @dataProvider allDockerfileProvider
     */
    public function testNoDockerfileMasksMissingPlatformRequirements(string $relative): void
    {
        $contents = self::directivesOnly(
            (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative)
        );

        self::assertStringNotContainsString(
            '--ignore-platform-reqs',
            $contents,
            $relative . ' must let a missing PHP extension fail the build'
        );
    }
}
