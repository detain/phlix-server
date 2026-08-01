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
    /**
     * THE CANARY LIST — the exact set of verdicts `scripts/docker-boot-smoke.sh`
     * is required to reach, pinned BY NAME, in a second file.
     *
     * S163 review round 3, finding 1. The gate's own `EXPECTED_CHECKS`
     * registry catches a check that stops REPORTING; it is structurally unable
     * to catch a check that is DELETED, because the registry is
     * self-referential — it enforces exactly the list it currently carries and
     * that list is editable in the same commit as the deletion.
     *
     * Reproduced on this tree before this list existed: deleting ASSERT 5
     * (196 lines — round 1's F1 fix for the crash-loop false-pass) and
     * ASSERT 11 (83 lines — round 2's findings 3+9 fix for the
     * FATAL-leaves-the-container-`Up` false-pass) together with their four
     * registry entries left the gate printing `ALL 15 ASSERTIONS PASSED` /
     * `all 15 registered checks reported exactly once`, exit 0, and the whole
     * 84-test suite green — because the only size constraint was
     * `assertGreaterThan(10, 15)`. Fourteen of the nineteen ids had zero
     * references anywhere in the unit suite.
     *
     * So the set lives here too. Removing a protection now costs an explicit,
     * reviewable edit in TWO places instead of one, and ADDING a check reddens
     * this test until the list is updated — that is the intended price, not a
     * defect.
     *
     * The value is the provenance: what the check is, and which demonstrated
     * false-pass or blocker it exists to prevent. Deleting a line means
     * deleting that sentence too.
     *
     * @var array<string, string>
     */
    private const PINNED_GATE_CHECKS = [
        'health' => 'ASSERT 1 — /health 200 served by the application itself (the step\'s acceptance criterion)',
        'migrations' => 'ASSERT 2 — round 1 F2: 7/7 green against a container whose entire migration chain had failed',
        'schema' => 'ASSERT 3 — round 1 F2: /health is DB-free, so it cannot tell "serves" from "serves against an '
            . 'empty schema"',
        'supervisor-states' => 'ASSERT 4 — blockers 2 and 3: a supervisord program in FATAL/BACKOFF',
        'stability-program' => 'ASSERT 5 — round 1 F1 (DEMONSTRATED FALSE-PASS): the gate exited 0 against an app that '
            . 'crash-restarted forever',
        'stability-workers' => 'ASSERT 5b — round 2 finding 10: blocker 6\'s shape, workers reforking under a stable '
            . 'master',
        'stability-listener' => 'ASSERT 5c — round 3 finding 5: a crash-looping exit-on-fatal listener was invisible, '
            . 'and it is the ONLY thing that turns a FATAL into a dead container',
        'daemon-process' => 'ASSERT 6 — blocker 1 stated positively: start.php is the running process, not '
            . 'public/index.php',
        'no-cgi' => 'ASSERT 6 — blockers 1/3/4: no nginx, php-fpm or CGI front-controller process in the image',
        'ws-in-container' => 'ASSERT 7 — the SyncPlay WS worker really listens on 8097',
        'ws-published' => 'ASSERT 7 — round 1 F8 + round 2 finding 2 (DEMONSTRATED FALSE-PASS): the PUBLISHED mapping, '
            . 'keyed on curl exit codes because a raw TCP connect cannot fail behind the userland proxy',
        'spa-shell' => 'ASSERT 8 — VERIFY 2: the /app shell is served by Workerman, so nothing depended on the deleted '
            . 'nginx',
        'spa-asset' => 'ASSERT 8 — VERIFY 2: a hashed static asset is served by Workerman',
        'spa-immutable' => 'ASSERT 8 — VERIFY 2: the immutable cache header nginx used to be credited with',
        'healthcheck-declared' => 'ASSERT 9 — no HEALTHCHECK is why a container serving nothing read `Up 22 minutes`',
        'healthcheck-healthy' => 'ASSERT 9 — the declared healthcheck must actually go healthy',
        'healthcheck-start-period' => 'ASSERT 9 — round 2 finding 1 (DEMONSTRATED FALSE-PASS): a start-period longer '
            . 'than a gate run makes `healthy` unfalsifiable',
        'platform-reqs' => 'ASSERT 10 — `--ignore-platform-reqs` masked a missing ext-ldap in every published image',
        'fatal-kills-container' => 'ASSERT 11 — round 1 F6 + round 2 finding 9: a FATAL program left '
            . 'the container `Up` forever',
        'fatal-exit-code-nonzero' => 'ASSERT 11 — round 2 finding 3: exiting 0 on a FATAL is indistinguishable from '
            . '`docker stop`',
    ];

    /**
     * The MACHINERY each of the above checks needs in order to mean anything.
     *
     * Same finding, one level down: a check id can survive in the registry
     * while the block that computes it is replaced by a stub, and the id list
     * alone cannot see that. These are the identifiers that only exist because
     * of the assertion they belong to, so their absence is the deletion.
     *
     * @var array<string, list<string>>
     */
    private const PINNED_GATE_MACHINERY = [
        'migrations' => ['PHLIX-MIGRATION-FAILURE'],
        'schema' => ['schema_migrations'],
        'supervisor-states' => ['sup_states'],
        'stability-program' => ['STABILITY_WINDOW', 'sup_status_retry', 'STAB_EVENT_RE'],
        'stability-workers' => ['worker_snapshot', 'WORKER_NAME_CHURN_BUDGET'],
        'stability-listener' => ['FATAL_LISTENER_PROGRAM', 'STAB_LISTENER_RE'],
        'ws-published' => ['WS_PORT'],
        'healthcheck-start-period' => ['MAX_START_PERIOD', '{{json .Config.Healthcheck.StartPeriod}}'],
        'fatal-kills-container' => ['CANARY_PROGRAM', 's163-fatal-canary', 'supervisorctl start'],
        'fatal-exit-code-nonzero' => ['CANARY_WENT_FATAL', '.State.ExitCode'],
    ];

    /**
     * How many `pass <id>` and `fail <id>` sites each pinned check has.
     *
     * S163 review round 4, finding 1. `testEveryPinnedCheckCanBothPassAndFail`
     * used to ask only whether a `fail <id>` token appeared ANYWHERE in the
     * script. Thirteen of the twenty ids have 2-4 `fail` sites — the surplus
     * ones are gate-setup and error paths (`could not read the process table`,
     * `could not induce a FATAL … GATE SETUP failure`, `SKIPPED: KEEP=1`) — so
     * those surplus sites SATISFIED the detector on behalf of the verdict that
     * matters. Reproduced on the real file: downgrading
     * `fail migrations "the entrypoint printed PHLIX-MIGRATION-FAILURE …"` (1
     * match) to a `pass` left the whole suite at OK (93 tests, 525 assertions)
     * — and that one line IS round 1's F2, the demonstrated false-pass where
     * the gate went 7/7 against a container whose entire migration chain had
     * failed.
     *
     * Scoping the detector to the check's own `say "ASSERT k/N"` block is
     * necessary but NOT sufficient, and that was measured rather than assumed:
     * a census of the shipped gate shows every one of the twenty ids already
     * has all of its sites inside a single block, `migrations` included (all
     * four of its `fail`s live in ASSERT 2). So the block scoping below is
     * enforced — a verdict may not drift out of the block that computes it —
     * and the COUNTS are what stop one site standing in for another.
     *
     * The price is the same one PINNED_GATE_CHECKS charges: adding or removing
     * a branch inside a check is a real, reviewable edit in two files. That is
     * the point, not a defect.
     *
     * @var array<string, array{pass: int, fail: int}>
     */
    private const PINNED_GATE_VERDICT_SITES = [
        'health' => ['pass' => 1, 'fail' => 1],
        'migrations' => ['pass' => 1, 'fail' => 4],
        'schema' => ['pass' => 1, 'fail' => 3],
        'supervisor-states' => ['pass' => 1, 'fail' => 4],
        'stability-program' => ['pass' => 1, 'fail' => 1],
        'stability-workers' => ['pass' => 1, 'fail' => 3],
        'stability-listener' => ['pass' => 1, 'fail' => 2],
        'daemon-process' => ['pass' => 1, 'fail' => 2],
        'no-cgi' => ['pass' => 1, 'fail' => 2],
        'ws-in-container' => ['pass' => 1, 'fail' => 1],
        'ws-published' => ['pass' => 2, 'fail' => 4],
        'spa-shell' => ['pass' => 1, 'fail' => 1],
        'spa-asset' => ['pass' => 1, 'fail' => 2],
        'spa-immutable' => ['pass' => 1, 'fail' => 2],
        'healthcheck-declared' => ['pass' => 1, 'fail' => 1],
        'healthcheck-healthy' => ['pass' => 1, 'fail' => 2],
        'healthcheck-start-period' => ['pass' => 1, 'fail' => 2],
        'platform-reqs' => ['pass' => 1, 'fail' => 1],
        'fatal-kills-container' => ['pass' => 1, 'fail' => 4],
        'fatal-exit-code-nonzero' => ['pass' => 1, 'fail' => 4],
    ];

    /**
     * The THRESHOLD VALUES a verdict is computed against, pinned as the literal
     * assignment in `scripts/docker-boot-smoke.sh`.
     *
     * S163 review round 4, finding 2. PINNED_GATE_MACHINERY pins identifier
     * NAMES, so `MAX_START_PERIOD` and `STABILITY_WINDOW` had to keep existing
     * but could hold any number at all. Reproduced on the real file, one match
     * each: `${MAX_START_PERIOD:-120}` → `:-99999` and `${STABILITY_WINDOW:-90}`
     * → `:-1` together left the suite at OK (93 tests, 525 assertions). At
     * 99999 the gate's own `[ "$HC_START_S" -gt "$MAX_START_PERIOD" ]` can
     * never be true, so `s163r3:sp180` — round 2 finding 1's demonstrated
     * false-pass — would PASS; at a 1s window round 1's F1 crash-loop is
     * unobservable.
     *
     * Deliberately NOT every number in the script. The rule is: a tunable is
     * pinned when RELAXING it makes a check unable to go negative, i.e. when
     * one digit re-admits a demonstrated false-pass. `BOOT_TIMEOUT` and
     * `SUP_EXEC_RETRIES` are excluded on that rule — they bound how long the
     * gate waits and how often it retries, not what it concludes, and
     * shortening them produces a loud RED rather than a quiet green.
     *
     * @var array<string, string>
     */
    private const PINNED_GATE_THRESHOLDS = [
        // Round 1 F1: the crash-loop window and its sampling interval. A short
        // window, or an interval as long as the window, collapses the loop to a
        // single sample — which a crash-looping container passes 5 times in 6.
        'STABILITY_WINDOW' => 'STABILITY_WINDOW="${STABILITY_WINDOW:-90}"',
        'STABILITY_SAMPLE' => 'STABILITY_SAMPLE="${STABILITY_SAMPLE:-15}"',
        // Round 2 finding 1: a start period longer than a gate run makes
        // `healthy` unfalsifiable.
        'MAX_START_PERIOD' => 'MAX_START_PERIOD="${MAX_START_PERIOD:-120}"',
        // Round 2 finding 10: the two budgets behind `stability-workers`.
        // Pinning one and leaving the other free would be a detector narrower
        // than the rule it states — the family of defect this round is closing.
        'WORKER_DROP_TOLERANCE' => 'WORKER_DROP_TOLERANCE="${WORKER_DROP_TOLERANCE:-2}"',
        'WORKER_NAME_CHURN_BUDGET' => 'WORKER_NAME_CHURN_BUDGET="${WORKER_NAME_CHURN_BUDGET:-1}"',
    ];

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
            // Same reasoning for the FATAL marker (real default /var/run/…).
            'PHLIX_SUPERVISOR_FATAL_MARKER' => $this->tmpDir . '/fatal-marker',
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

    /**
     * A stub supervisord that behaves like the real one does when the
     * `exit-on-fatal` listener fires: it drops the marker file and then exits
     * ZERO, because supervisord treats the listener's SIGTERM as a clean
     * shutdown. (S163 review round 2, finding 3.)
     */
    private function stubSupervisordEnteringFatal(): void
    {
        $script = "#!/bin/sh\n"
            . 'printf \'%s \' "$@" > "' . $this->tmpDir . "/supervisord-ran\"\n"
            . 'printf \'%s\\n\' "ver:3.0 server:supervisor eventname:PROCESS_STATE_FATAL" '
            . '> "$PHLIX_SUPERVISOR_FATAL_MARKER"' . "\n"
            . 'exit 0' . "\n";
        file_put_contents($this->tmpDir . '/bin/supervisord', $script);
        chmod($this->tmpDir . '/bin/supervisord', 0755);
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
     * Every secret-shaped variable in the shipped example, not just the one
     * that happened to be load-bearing.
     *
     * S163 review round 2, finding 7: the F3 fix commented out
     * `PHLIX_SECRET_KEY` and left `HUB_SECRET_KEY=change_me_generate_with_openssl`
     * on the very next line — the identical committed placeholder, inert only
     * because no PHP reads that name YET, which is exactly the state
     * `PHLIX_SECRET_KEY` was in before the entrypoint mapping made it live.
     * The guard was written for one variable and the defect moved one line.
     *
     * @return array<string, array{string}>
     */
    public static function exampleSecretVariableProvider(): array
    {
        return [
            'PHLIX_SECRET_KEY' => ['PHLIX_SECRET_KEY'],
            'HUB_SECRET_KEY' => ['HUB_SECRET_KEY'],
        ];
    }

    /**
     * The shipped example must not hand anyone a working-looking secret.
     *
     * @dataProvider exampleSecretVariableProvider
     */
    public function testTheEnvExampleShipsNoUsableSecretValue(string $variable): void
    {
        $example = (string) file_get_contents(
            dirname(__DIR__, 3) . '/docker/examples/.env.example'
        );

        self::assertDoesNotMatchRegularExpression(
            '/^' . preg_quote($variable, '/') . '=.+$/m',
            $example,
            "docker/examples/.env.example must not ship a {$variable} value — a secret "
            . 'committed to a public repository is a secret everyone has'
        );
    }

    /**
     * The placeholder assignments in `.env.example` that are DELIBERATE, with
     * the reason each one is.
     *
     * S163 review round 3, finding 4: the value-based guard below re-introduced
     * a NAME filter (`secret`/`key`) while its failure message claimed to cover
     * every "secret-shaped" variable. Replayed against the shipped file, that
     * filter silently skipped four real placeholder assignments —
     * `MYSQL_ROOT_PASSWORD`, `PHLIX_DB_PASSWORD`, `HUB_DB_PASSWORD` and
     * `XDEBUG_TRIGGER=your-secret-trigger-value`, the last of which has the
     * word `secret` in its VALUE. Round 2's finding 7 was "the guard was
     * written for one variable and the defect moved one line"; a name filter
     * is the same mistake with a bigger radius, so it is gone. The exceptions
     * are enumerated HERE instead, where adding one is a visible edit.
     *
     * @var array<string, string> variable => why its placeholder must stay
     */
    private const ENV_EXAMPLE_PLACEHOLDER_EXEMPT = [
        'MYSQL_ROOT_PASSWORD' => 'the mysql:8.0 entrypoint refuses to initialise a data '
            . 'directory without one, so this line cannot be blank or commented out',
        'PHLIX_DB_PASSWORD' => 'it is the password the compose file also feeds to that same '
            . 'throwaway MySQL container; blanking one half breaks the example',
        'HUB_DB_PASSWORD' => 'same, for the hub database in the server-hub / full-stack examples',
    ];

    /**
     * No line in the shipped example may assign a known placeholder, under ANY
     * name. The two tests above enumerate names; this one enumerates the
     * VALUE, so a variable nobody thought of cannot reintroduce it.
     */
    public function testTheEnvExampleAssignsNoKnownPlaceholderSecret(): void
    {
        $example = (string) file_get_contents(
            dirname(__DIR__, 3) . '/docker/examples/.env.example'
        );

        $offenders = [];
        $exemptSeen = [];
        foreach (preg_split('/\R/', $example) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (!preg_match('/^\s*([A-Z0-9_]+)=(.+)$/', $line, $m)) {
                continue;
            }
            if (preg_match('/change[_-]?me|changeme|your[_-]secret|placeholder/i', $m[2]) !== 1) {
                continue;
            }
            if (array_key_exists($m[1], self::ENV_EXAMPLE_PLACEHOLDER_EXEMPT)) {
                $exemptSeen[] = $m[1];
                continue;
            }
            $offenders[] = trim($line);
        }

        self::assertSame(
            [],
            $offenders,
            'docker/examples/.env.example assigns a known placeholder value. A committed '
            . 'placeholder is public, and this repository has already shipped one that '
            . 'became a live JWT + media-signing key the moment the entrypoint started '
            . 'reading it. Blank it, or — if the example genuinely cannot work without a '
            . 'value — add it to ENV_EXAMPLE_PLACEHOLDER_EXEMPT with the reason. Offending '
            . 'lines: ' . implode(' | ', $offenders)
        );

        // The exemption list must not outlive what it exempts, or it becomes a
        // pre-approval for a variable nobody has looked at.
        sort($exemptSeen);
        $expected = array_keys(self::ENV_EXAMPLE_PLACEHOLDER_EXEMPT);
        sort($expected);
        self::assertSame(
            $expected,
            $exemptSeen,
            'ENV_EXAMPLE_PLACEHOLDER_EXEMPT no longer matches the file: an exempted '
            . 'variable has been removed or renamed, so its exemption is now dead weight '
            . 'that would silently cover a future variable of the same name'
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
        // directivesOnly: that file's COMMENTS explain `kill -TERM 1` at
        // length, so matching the raw body would pass on the prose alone.
        self::assertStringContainsString(
            'kill -TERM 1',
            self::directivesOnly((string) file_get_contents($inRepo))
        );
    }

    /**
     * S163 review round 2, finding 3 — the container must exit NON-ZERO.
     *
     * `kill -TERM 1` alone made supervisord exit 0, so `docker inspect` showed
     * `"Status":"exited","ExitCode":0` and the failure was indistinguishable
     * from `docker stop`: compose `restart: on-failure`, k8s `OnFailure`,
     * `docker wait` and every exit-code alert read it as SUCCESS. The
     * entrypoint therefore keeps PID 1 and translates the marker into exit 70.
     *
     * Driven for real: a stub supervisord that drops the marker and exits 0,
     * exactly like the real one does when the listener signals it.
     */
    public function testAFatalMarkerMakesTheEntrypointExitNonZero(): void
    {
        $this->stubPhp(0);
        $this->stubSupervisordEnteringFatal();

        $run = $this->runEntrypoint(['PHLIX_DATABASE_HOST' => 'mysql']);

        self::assertTrue($this->supervisordStarted());
        self::assertSame(
            70,
            $run['code'],
            'a FATAL program must give the CONTAINER a non-zero exit code; 0 is '
            . 'indistinguishable from a clean docker stop'
        );
        self::assertStringContainsString('PHLIX-SUPERVISOR-FATAL-EXIT', $run['stderr']);
    }

    /**
     * S163 review round 3, finding 6 — the exit code the previous round
     * introduced was documented NOWHERE an operator reads.
     *
     * `docker/README.md` and `docker/examples/README.md` must both name it,
     * and the number they name must be the number the entrypoint actually
     * exits with, read out of the script rather than retyped here. An
     * operator-facing claim about a published artefact that nothing checks is
     * how `docker/README.md` came to describe a base image this branch had
     * already replaced.
     */
    public function testTheContainerExitCodeForAFatalIsDocumentedWhereOperatorsRead(): void
    {
        $root = dirname(__DIR__, 3);
        $entrypoint = self::directivesOnly((string) file_get_contents(
            $root . '/docker/docker-entrypoint.sh'
        ));

        self::assertSame(
            1,
            preg_match('/^\s*exit\s+(\d+)\s*$/m', substr(
                $entrypoint,
                (int) strpos($entrypoint, 'PHLIX-SUPERVISOR-FATAL-EXIT')
            ), $m),
            'the entrypoint must exit with a literal code after the FATAL banner'
        );
        $code = $m[1];
        self::assertNotSame('0', $code, 'a FATAL must not exit 0');

        foreach (['/docker/README.md', '/docker/examples/README.md'] as $doc) {
            $contents = (string) file_get_contents($root . $doc);

            // ⚠ Require the code and the word FATAL on the SAME line, as a
            // backticked literal. A bare `/\b70\b/` looked fine until the
            // control: mutating the script to `exit 1` left this test GREEN,
            // because a lone "1" appears all over a README. A detector
            // narrower than its rule fakes a zero; a detector LOOSER than its
            // rule fakes a pass, and this one was loose for every small code.
            self::assertMatchesRegularExpression(
                '/`\*{0,2}' . preg_quote($code, '/') . '\*{0,2}`[^\n]*FATAL/i',
                $contents,
                $doc . ' must tell an operator what container exit code ' . $code
                . ' means, on one line with the word FATAL — it is the only signal that '
                . 'distinguishes a total application outage from a clean `docker stop`'
            );
            self::assertStringContainsString(
                'restart:',
                $contents,
                $doc . ' must say what the shipped restart policy does with that exit code'
            );
        }
    }

    /**
     * ... and the compose files an operator copies must carry the same warning
     * at the line they would edit. Same finding: every shipped example uses
     * `restart: unless-stopped`, which restarts on exit 70 as readily as on 0,
     * turning a persistent FATAL into a migration-replaying restart loop.
     */
    public function testEveryShippedComposeExplainsTheRestartPolicyItPicks(): void
    {
        $root = dirname(__DIR__, 3);
        $files = [
            '/docker-compose.yml',
            '/docker/examples/server-only/docker-compose.yml',
            '/docker/examples/server-hub/docker-compose.yml',
            '/docker/examples/full-stack/docker-compose.yml',
        ];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($root . $file);

            self::assertStringContainsString(
                'restart:',
                $contents,
                $file . ' should declare a restart policy'
            );
            self::assertMatchesRegularExpression(
                '/^\s*#.*\b70\b/m',
                $contents,
                $file . ' must carry the exit-70 note next to its restart policy: an operator '
                . 'editing this line is the person who needs to know that a supervised-program '
                . 'FATAL now stops the container'
            );
        }
    }

    /**
     * The other half of finding 3's fix: a CLEAN shutdown must still exit 0, or
     * `docker stop` starts looking like a crash and `restart: on-failure` loops
     * a container the operator deliberately stopped.
     */
    public function testACleanSupervisordShutdownStillExitsZero(): void
    {
        $this->stubPhp(0);
        $this->stubSupervisord();

        $run = $this->runEntrypoint(['PHLIX_DATABASE_HOST' => 'mysql']);

        self::assertSame(0, $run['code']);
        self::assertFileDoesNotExist($this->tmpDir . '/fatal-marker');
        self::assertStringNotContainsString('PHLIX-SUPERVISOR-FATAL-EXIT', $run['stderr']);
    }

    /**
     * A marker left behind by a PREVIOUS life of the same container (restart
     * policies reuse the filesystem) must not make every later boot exit 70.
     */
    public function testAStaleFatalMarkerIsClearedAtBoot(): void
    {
        $this->stubPhp(0);
        $this->stubSupervisord();
        file_put_contents($this->tmpDir . '/fatal-marker', "stale\n");

        $run = $this->runEntrypoint(['PHLIX_DATABASE_HOST' => 'mysql']);

        self::assertSame(0, $run['code'], 'a stale marker must not poison later boots');
        self::assertTrue($this->supervisordStarted());
    }

    /**
     * The listener must write the marker BEFORE it signals PID 1 — the other
     * order is a race the container loses by exiting 0.
     */
    public function testTheFatalListenerWritesTheMarkerBeforeSignalling(): void
    {
        // Comments in this file DESCRIBE `kill -TERM 1` at length, so the raw
        // body would match the prose before the code.
        $script = self::directivesOnly((string) file_get_contents(
            dirname(__DIR__, 3) . '/docker/supervisord-exit-on-fatal.sh'
        ));

        $markerAt = strpos($script, '> "$FATAL_MARKER"');
        $signalAt = strpos($script, 'kill -TERM 1');

        self::assertIsInt($markerAt, 'the listener must write the FATAL marker file');
        self::assertIsInt($signalAt);
        self::assertMatchesRegularExpression(
            '/^FATAL_MARKER="\$\{PHLIX_SUPERVISOR_FATAL_MARKER:-\S+\}"$/m',
            $script,
            'the marker path must be the one docker-entrypoint.sh checks, and overridable'
        );
        self::assertLessThan(
            $signalAt,
            $markerAt,
            'the marker must be written BEFORE PID 1 is signalled, or the entrypoint '
            . 'can wake up, find no marker and exit 0'
        );
    }

    /**
     * S163 review round 2, finding 5 — the F3 fix made the entrypoint REFUSE
     * to boot on the value `docker/examples/.env.example` used to ship, and
     * left `docker/examples/README.md` telling operators the variable is
     * Required: Yes. An operator who follows the README sets it, most
     * plausibly to the placeholder still visible on a neighbouring line, and
     * gets a container that exits 1.
     */
    public function testTheExamplesReadmeDoesNotDemandASecretTheEntrypointGenerates(): void
    {
        $readme = (string) file_get_contents(
            dirname(__DIR__, 3) . '/docker/examples/README.md'
        );

        foreach (['PHLIX_SECRET_KEY', 'HUB_SECRET_KEY'] as $variable) {
            self::assertSame(
                1,
                preg_match('/^\|\s*`' . $variable . '`\s*\|([^|]*)\|([^|]*)\|/m', $readme, $m),
                "docker/examples/README.md must document {$variable} in the env table"
            );
            self::assertDoesNotMatchRegularExpression(
                '/^\s*\**Yes\**\s*$/',
                $m[2],
                "docker/examples/README.md marks {$variable} as Required, but the "
                . 'entrypoint generates and persists a key when it is unset — and '
                . 'refuses to start on the placeholder an operator would most likely copy'
            );
        }
    }

    /**
     * S163 review round 2, finding 6 — `docker/README.md` is the operator-facing
     * description of a PUBLISHED artefact and still named the base image this
     * branch replaced. Tie the prose to the Dockerfile so it cannot drift again.
     */
    public function testTheDockerReadmeNamesTheBaseImageTheBaseDockerfileActuallyUses(): void
    {
        $root = dirname(__DIR__, 3);
        $base = (string) file_get_contents($root . '/docker/Dockerfile.base');
        $readme = (string) file_get_contents($root . '/docker/README.md');

        self::assertSame(
            1,
            preg_match('/^FROM\s+php:\$\{PHP_VERSION\}-(\S+)\s*$/m', $base, $m),
            'docker/Dockerfile.base must FROM an official php image'
        );
        $flavour = $m[1];

        self::assertMatchesRegularExpression(
            '/^\|\s*`Dockerfile`\s*\|[^|]*`php:8\.3-' . preg_quote($flavour, '/') . '`/m',
            $readme,
            "docker/README.md's variant table must name php:8.3-{$flavour}, the base "
            . 'docker/Dockerfile.base actually builds on'
        );
        self::assertStringContainsString(
            'php:8.3-' . $flavour . '`, which' . "\n" . 'places PHP config under',
            $readme,
            'the "Why the path layouts differ" section must name the same base'
        );
    }

    /**
     * S163 review round 2, finding 4 — `opcache.validate_timestamps = 0` rests
     * on "the code in an image is immutable". It is not: the plugin installer
     * extracts PHP into `var/plugins/<name>/` at runtime and the Dockerfiles
     * create that directory for it, so a plugin UPDATE would keep executing
     * the previously cached bytecode until the container restarted.
     */
    public function testOpcacheRevalidatesBecauseThePluginInstallerWritesPhpAtRuntime(): void
    {
        $root = dirname(__DIR__, 3);
        $ini = self::directivesOnly(
            (string) file_get_contents($root . '/docker/php.ini'),
            ';'
        );

        self::assertDoesNotMatchRegularExpression(
            '/^\s*opcache\.validate_timestamps\s*=\s*0\s*$/m',
            $ini,
            'docker/php.ini must not disable opcache timestamp validation: '
            . 'src/Plugins/Installer/HttpInstaller.php writes PHP into var/plugins/ '
            . 'inside the running container'
        );
        self::assertMatchesRegularExpression(
            '/^\s*opcache\.validate_timestamps\s*=\s*1\s*$/m',
            $ini,
            'timestamp validation must be explicitly ON, not merely absent'
        );
        self::assertMatchesRegularExpression(
            '/^\s*opcache\.revalidate_freq\s*=\s*\d+\s*$/m',
            $ini,
            'a revalidate_freq must bound the stat() cost the line above introduces'
        );

        // The premise this guards is a real code path, not a hypothesis.
        self::assertStringContainsString(
            'var/plugins',
            (string) file_get_contents($root . '/src/Plugins/Installer/HttpInstaller.php'),
            'if the plugin installer no longer writes into the image, revisit docker/php.ini'
        );
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

    /**
     * Dockerfile lines with `\`-continuations JOINED, so one `RUN` is one
     * string no matter how its author chose to wrap it.
     *
     * S163 review round 2, finding 11: a check written against the wrapped
     * form only ever saw the wrapped form.
     *
     * @return list<string>
     */
    private static function logicalLines(string $contents): array
    {
        $joined = (string) preg_replace('/\\\\\R\s*/', ' ', $contents);

        return array_values(array_filter(
            preg_split('/\R/', $joined) ?: [],
            static fn (string $line): bool => trim($line) !== ''
        ));
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

        // S163 review round 2, finding 11: the regex above only matches a
        // package sitting alone on its own line-continuation, so a perfectly
        // ordinary `RUN apk add --no-cache nginx` written on ONE line escaped
        // it entirely. The provider fix widened WHICH files are scanned; this
        // widens WHAT is scanned inside them. Join the continuations first,
        // then look at every package-install command as a whole.
        $installCommand = '/\b(apk\s+add|apt-get\s+(?:-\S+\s+)*install'
            . '|apt\s+install|yum\s+install|dnf\s+install)\b/';
        foreach (self::logicalLines($contents) as $line) {
            if (preg_match($installCommand, $line) !== 1) {
                continue;
            }
            // S163 review round 3, finding 3 (second half) — RE-SCOPED BY
            // MEASUREMENT. The finding said `logicalLines()` does not strip
            // comments, so a whole-line `# never install nginx` reddens this
            // guard. Measured against the SHIPPED test: it does NOT —
            // `directivesOnly()` above already drops whole-line comments and
            // the guard stayed green. What it cannot drop is a comment
            // TRAILING a real directive, and that one does redden:
            // `RUN apt-get install -y curl  # nginx is deliberately absent`
            // → `Failures: 1`. So the false-RED is real, one shape over.
            $scannable = (string) preg_replace('/(?<=\s)#.*$/', '', $line);

            // ... and the package pattern itself was narrower than the rule it
            // enforces (finding 3, first half). Measured, one mutation at a
            // time, against the shipped test: `nginx-light`, `nginx-full`,
            // `nginx-extras` and `nginx-mod-http-brotli` all passed, because
            // the trailing `(?![\w./-])` rejected the hyphen. Those are the
            // standard Debian/Ubuntu packages that install /usr/sbin/nginx and
            // .intel/.nvidia are apt-based, so it is the live shape. Two more
            // found the same way: Alpine spells php-fpm `php83-fpm` (two
            // digits, NO dot) and Debian ships nginx modules as
            // `libnginx-mod-*`; both were misses too.
            //
            // The lookbehind still prevents a path (`/etc/nginx/…`) or a flag
            // (`--with-nginx-dir`) from matching, so widening the suffix does
            // not buy a false-RED.
            self::assertDoesNotMatchRegularExpression(
                '#(?<![\w./-])((?:lib)?nginx(?:-[a-z0-9.+]+)*'
                . '|php-fpm(?:-[a-z0-9.+]+)*'
                . '|php\d+(?:\.\d+)?-fpm(?:-[a-z0-9.+]+)*)(?![\w./-])#i',
                $scannable,
                $relative . ' installs nginx or php-fpm: ' . trim($line)
            );
        }
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

    // ------------------------------------------------------------------
    // S163 review round 2, finding 1 — the boot gate must be able to detect
    // its OWN skipped assertions. These are the cheap textual half; the
    // behavioural half is the gate's own "CHECK COMPLETENESS" section, proven
    // by running it against a mutated copy.
    // ------------------------------------------------------------------

    /**
     * `docker inspect -f '{{.Config.Healthcheck.StartPeriod}}'` renders a Go
     * `time.Duration` through String() — "1m30s", not nanoseconds. Feeding
     * that to `$(( ))` under `set -euo pipefail` abandons the rest of the
     * enclosing block WITHOUT incrementing the failure count, so the gate
     * printed ALL ASSERTIONS PASSED against an image whose HEALTHCHECK
     * start-period had been reverted to the exact value round 1 removed.
     * Only the `{{json …}}` form returns nanoseconds.
     */
    public function testTheBootGateReadsDurationsInTheirNumericForm(): void
    {
        $gate = (string) file_get_contents(
            dirname(__DIR__, 3) . '/scripts/docker-boot-smoke.sh'
        );
        $directives = self::directivesOnly($gate);

        self::assertDoesNotMatchRegularExpression(
            '/inspect[^\n]*-f\s+\'\{\{\.Config\.Healthcheck\.(StartPeriod|Interval|Timeout)\}\}\'/',
            $directives,
            'the plain -f form renders a Go duration as "1m30s"; use {{json …}} for nanoseconds'
        );
        self::assertStringContainsString(
            "{{json .Config.Healthcheck.StartPeriod}}",
            $directives,
            'the gate must still read the start period — round 2 fixed the Dockerfiles '
            . 'and then guarded them with an assertion that never ran'
        );
    }

    /**
     * The gate must fail when a registered check produces no verdict. Assert
     * the mechanism exists and that every check id `pass`/`fail` can emit is
     * registered — an unregistered id is a check nothing would miss.
     */
    public function testTheBootGateRegistersEveryCheckItCanReport(): void
    {
        $gate = (string) file_get_contents(
            dirname(__DIR__, 3) . '/scripts/docker-boot-smoke.sh'
        );
        $directives = self::directivesOnly($gate);

        self::assertSame(
            1,
            preg_match('/^EXPECTED_CHECKS=\'\n(.*?)\'$/ms', $directives, $m),
            'the gate must declare the list of checks it is required to reach'
        );
        $registered = array_values(array_filter(array_map('trim', explode("\n", $m[1]))));
        // Deliberately NO size assertion here. A lower bound
        // (`assertGreaterThan(10, …)`) is what let two whole assertion blocks
        // be deleted with this test green; the exact set is pinned in
        // testTheBootGateReachesExactlyThePinnedSetOfChecks().
        self::assertNotEmpty($registered);

        self::assertMatchesRegularExpression(
            '/produced NO verdict/',
            $directives,
            'the gate must FAIL on a check that did not run, not merely count the ones that did'
        );

        // Capture the id GREEDILY (`\S+`, not a lowercase-only class): a class
        // that stops at the first unexpected character silently skips the very
        // line a typo introduced, which is how this control first came back
        // green against a mutation it was written to catch.
        preg_match_all('/^\s*(?:\S+\)\s+)?(?:pass|fail)\s+(\S+)\s/m', $directives, $calls);
        $used = array_values(array_unique(array_filter(
            $calls[1],
            // `pass "$1" …` inside the uint_or_fail helper is a forwarded id,
            // not a literal one.
            static fn (string $id): bool => !str_contains($id, '$') && !str_contains($id, '"'),
        )));
        self::assertNotEmpty($used);

        $unregistered = array_values(array_diff($used, $registered, ['gate-completeness']));
        self::assertSame(
            [],
            $unregistered,
            'these check ids are reported but not registered, so the completeness '
            . 'check cannot notice if they stop running: ' . implode(', ', $unregistered)
        );

        $neverReported = array_values(array_diff($registered, $used));
        self::assertSame(
            [],
            $neverReported,
            'these check ids are registered but nothing can ever report them: '
            . implode(', ', $neverReported)
        );
    }

    /**
     * S163 round 4 — no job may build a runtime image against a FLOATING base
     * tag.
     *
     * The pipeline is two-stage, and until this commit the second stage built
     * `FROM ghcr.io/detain/phlix-base:latest` while the first stage's `push:`
     * was `github.event_name != 'pull_request'`. So on a PR the new base was
     * built and discarded and the runtime images were compiled against
     * whatever base some earlier commit had published — the same "a green
     * check measured the wrong artefact" defect this step exists to fix, one
     * level up in the pipeline. It only became visible when this branch added
     * `ext-ldap` to the base and stopped passing `--ignore-platform-reqs`.
     *
     * Every `PHLIX_BASE_IMAGE` must therefore name something built from THIS
     * commit: the per-commit tag the base job now publishes, or the boot
     * gate's locally-built `phlix-base-bootgate:<sha>`.
     */
    public function testNoWorkflowJobBuildsAgainstAFloatingBaseTag(): void
    {
        $workflow = (string) file_get_contents(
            dirname(__DIR__, 3) . '/.github/workflows/docker.yml'
        );

        self::assertGreaterThan(
            0,
            // To END OF LINE, not `\S+`: a `${{ … }}` expression contains
            // spaces, and `\S+` captured a bare `${{` — a detector that reads
            // three characters of the thing it is judging.
            preg_match_all('/PHLIX_BASE_IMAGE[=:]\s*(.+)$/m', $workflow, $m),
            'the workflow must pass PHLIX_BASE_IMAGE somewhere'
        );

        foreach ($m[1] as $value) {
            self::assertDoesNotMatchRegularExpression(
                '/:latest\b/',
                $value,
                'PHLIX_BASE_IMAGE=' . $value . ' is a floating tag: a PR would build this '
                . "commit's code against a base published by some other commit"
            );
            self::assertMatchesRegularExpression(
                '/needs\.docker-base\.outputs\.base_ref|github\.sha|matrix\./',
                $value,
                'PHLIX_BASE_IMAGE=' . $value . ' must name a base built from THIS commit'
            );
        }

        // ... and the per-commit tag has to actually be published on a PR, or
        // the reference above resolves to nothing.
        self::assertStringContainsString(
            'TAGS="${BASE_IMAGE}:${GITHUB_SHA}"',
            $workflow,
            'the base job must publish an immutable per-commit tag for the dependent jobs'
        );
        self::assertMatchesRegularExpression(
            '/if \[ "\$\{\{ github\.event_name \}\}" != "pull_request" \]; then\s*\n\s*TAGS="\$\{BASE_IMAGE\}:latest,/',
            $workflow,
            'and `:latest` must stay master/tag-only — a PR must not move the tag every '
            . 'deployment pulls'
        );
        self::assertMatchesRegularExpression(
            '/push:\s*\$\{\{\s*github\.event_name\s*!=\s*\'pull_request\'\s*\|\|/',
            $workflow,
            'the base job must also push on a (non-fork) pull request; '
            . "`push: github.event_name != 'pull_request'` alone is what left PRs "
            . 'building against a stale base'
        );
        // The digest check is the part that makes the reference trustworthy —
        // a tag is mutable, a digest is not.
        self::assertSame(
            2,
            substr_count($workflow, 'Verify the base image is the one built from this commit'),
            'both dependent jobs (docker, docker-hub) must verify the base digest before building'
        );
    }

    /**
     * S163 review round 3, finding 2 — ASSERT 11 credited the container's
     * death to the canary even when no FATAL was ever induced.
     *
     * The old guard was `[ "$FATAL_RUNNING" != "false" ] && [
     * "$CANARY_WENT_FATAL" -eq 0 ]`, which cannot fire once the container is
     * already dead; driven verbatim with `Running=false, canary-never-FATAL,
     * exit 137` it printed two PASSes for something that never happened. The
     * completeness registry cannot see a WRONG verdict — only a missing one —
     * so the shape has to be pinned here.
     */
    public function testTheFatalCanaryVerdictRequiresAFatalToHaveHappened(): void
    {
        $directives = self::gateDirectives();

        self::assertMatchesRegularExpression(
            '/if \[ "\$CANARY_WENT_FATAL" -ne 1 \]; then/',
            $directives,
            'the induced FATAL is what licenses BOTH ASSERT 11 verdicts, so it must be '
            . 'tested on its own and first'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\[ "\$FATAL_RUNNING" != "false" \]\s*&&\s*\[ "\$CANARY_WENT_FATAL" -eq 0 \]/',
            $directives,
            'that condition cannot fire for an already-dead container, so the else branch '
            . 'credits the canary for a death it had nothing to do with'
        );
    }

    /**
     * S163 review round 3, finding 5 — the FATAL event listener needs its own
     * stability verdict.
     *
     * Round 2 scoped the supervisord respawn count to the application to kill
     * a false-RED that round 3 then measured and could not reproduce; the
     * scoping made a crash-looping `exit-on-fatal` invisible (0 events counted
     * against 6). The listener is the only mechanism that turns a FATAL into a
     * dead container, so while it is flapping a total outage reads as `Up`
     * again.
     */
    public function testTheGateWatchesTheFatalListenerAndNotOnlyTheApplication(): void
    {
        $directives = self::gateDirectives();

        self::assertStringContainsString(
            'FATAL_LISTENER_PROGRAM="${FATAL_LISTENER_PROGRAM:-exit-on-fatal}"',
            $directives,
            'the gate must know the listener program by name'
        );
        self::assertMatchesRegularExpression(
            '/STAB_LISTENER_RE="[^"]*\$\{FATAL_LISTENER_PROGRAM\}[^"]*"/',
            $directives,
            'the listener needs its own event pattern; folding it back into STAB_EVENT_RE '
            . 'either loses the signal or blames the application for it'
        );
        // Both directions: the application pattern must STAY scoped, so a
        // listener respawn cannot redden the application's verdict either.
        self::assertMatchesRegularExpression(
            '/STAB_EVENT_RE="[^"]*\$\{APP_PROGRAM\}[^"]*"/',
            $directives,
            'the application event pattern must stay scoped to $APP_PROGRAM'
        );
    }

    /**
     * The boot gate must reach EXACTLY the pinned set of verdicts — no more,
     * no fewer. See PINNED_GATE_CHECKS for why a count or a lower bound is not
     * enough: two whole assertion blocks were deleted with everything green.
     */
    public function testTheBootGateReachesExactlyThePinnedSetOfChecks(): void
    {
        $registered = self::registeredGateChecks();
        sort($registered);

        $pinned = array_keys(self::PINNED_GATE_CHECKS);
        sort($pinned);

        $removed = array_values(array_diff($pinned, $registered));
        $added = array_values(array_diff($registered, $pinned));

        self::assertSame(
            $pinned,
            $registered,
            "scripts/docker-boot-smoke.sh no longer reaches the pinned set of verdicts.\n"
            . 'REMOVED (a protection has been deleted — every one of these exists because of a '
            . 'blocker or a demonstrated false-pass; say why in the commit and delete it from '
            . 'PINNED_GATE_CHECKS in the SAME diff): ' . (implode(', ', $removed) ?: '(none)') . "\n"
            . 'ADDED (good — record it in PINNED_GATE_CHECKS with the reason it exists): '
            . (implode(', ', $added) ?: '(none)')
        );
    }

    /**
     * A check that can only ever PASS is not a check — and, per round 4's
     * finding 1, neither is one whose only remaining `fail` sites are
     * gate-setup error paths.
     *
     * Every pinned id must therefore have EXACTLY the `pass`/`fail` sites
     * PINNED_GATE_VERDICT_SITES records for it, and all of them inside the one
     * `say "ASSERT k/N"` block that computes the verdict. Converting the
     * primary `fail` into a `pass` moves a count; moving a verdict out of its
     * block splits the ownership. Either reddens.
     */
    public function testEveryPinnedCheckCanBothPassAndFail(): void
    {
        $sites = self::gateVerdictSites();

        self::assertSame(
            array_keys(self::PINNED_GATE_CHECKS),
            array_keys(self::PINNED_GATE_VERDICT_SITES),
            'PINNED_GATE_VERDICT_SITES must cover exactly the pinned checks, in the same order'
        );

        foreach (self::PINNED_GATE_VERDICT_SITES as $id => $expected) {
            self::assertTrue(
                $expected['pass'] >= 1 && $expected['fail'] >= 1,
                "PINNED_GATE_VERDICT_SITES[{$id}] pins pass={$expected['pass']} fail={$expected['fail']}"
                . ' — a check that can never be negative is not a check, and one that can never be '
                . 'positive reddens every good image. ' . self::PINNED_GATE_CHECKS[$id]
            );

            $blocks = $sites[$id] ?? [];
            self::assertCount(
                1,
                $blocks,
                "the `{$id}` verdict is emitted from " . (count($blocks) ?: 'no')
                . ' assertion block(s) [' . (implode(', ', array_keys($blocks)) ?: 'none')
                . '] — it must be reached from exactly the one block that computes it, or a '
                . 'gate-setup path elsewhere in the script can stand in for the real verdict. '
                . self::PINNED_GATE_CHECKS[$id]
            );

            $block = (string) array_key_first($blocks);
            self::assertSame(
                $expected,
                $blocks[$block],
                "scripts/docker-boot-smoke.sh reaches `{$id}` from {$block} with pass="
                . $blocks[$block]['pass'] . ' fail=' . $blocks[$block]['fail'] . ', not the pinned pass='
                . $expected['pass'] . ' fail=' . $expected['fail'] . ".\n"
                . 'A `fail` that became a `pass` is a protection being switched off — the surplus '
                . "`fail {$id}` sites are gate-setup/error paths and MUST NOT cover for it. Adding "
                . 'or removing a branch on purpose is fine: say why, and update '
                . "PINNED_GATE_VERDICT_SITES in the SAME diff. " . self::PINNED_GATE_CHECKS[$id]
            );
        }
    }

    /**
     * S163 review round 4, finding 2 — pinning the NAME of a threshold leaves
     * its VALUE free, and a one-digit edit neuters the check that reads it.
     *
     * Both halves are guarded: the literal assignment in the gate, and the
     * workflow step that invokes it (every tunable is `${VAR:-default}`, so an
     * `env:` line in `.github/workflows/docker.yml` widens the threshold
     * without touching the script at all).
     */
    public function testTheBootGateKeepsItsLoadBearingThresholdValues(): void
    {
        $directives = self::gateDirectives();

        foreach (self::PINNED_GATE_THRESHOLDS as $assignment) {
            self::assertStringContainsString(
                $assignment,
                $directives,
                "scripts/docker-boot-smoke.sh no longer sets `{$assignment}`. This is a THRESHOLD a "
                . 'verdict is compared against, not a preference: relaxing it makes the check unable '
                . 'to go negative, which is how a demonstrated false-pass gets back in with every '
                . 'other detector green. Changing it is allowed — say why, and update '
                . 'PINNED_GATE_THRESHOLDS in the SAME diff.'
            );
        }

        $workflow = (string) file_get_contents(
            dirname(__DIR__, 3) . '/.github/workflows/docker.yml'
        );
        foreach (array_keys(self::PINNED_GATE_THRESHOLDS) as $name) {
            self::assertDoesNotMatchRegularExpression(
                '/^\s*' . preg_quote($name, '/') . '\s*:/m',
                $workflow,
                "the workflow sets `{$name}` in the gate step's environment, which overrides the "
                . 'pinned default without editing the script — the same neutering the assignment '
                . 'guard above exists to catch, one file over'
            );
        }
    }

    /**
     * ... and the block behind each check must still be there. An id is cheap
     * to keep; the sampling loop, the canary program and the duration parsing
     * are what actually produce the verdict.
     */
    public function testTheBootGateKeepsTheMachineryBehindEachPinnedCheck(): void
    {
        $directives = self::gateDirectives();

        foreach (self::PINNED_GATE_MACHINERY as $id => $tokens) {
            self::assertArrayHasKey(
                $id,
                self::PINNED_GATE_CHECKS,
                'PINNED_GATE_MACHINERY names a check that is not pinned: ' . $id
            );
            foreach ($tokens as $token) {
                self::assertStringContainsString(
                    $token,
                    $directives,
                    "scripts/docker-boot-smoke.sh no longer contains `{$token}`, which is part of "
                    . "the machinery behind the `{$id}` verdict — " . self::PINNED_GATE_CHECKS[$id]
                );
            }
        }
    }

    /**
     * `say "ASSERT k/N"` must be self-consistent: one N, and the numerators
     * exactly 1..N. Deleting a block leaves a hole (1,2,3,4,6,…/11) that no
     * id list can see, and renumbering is an edit a reviewer notices.
     */
    public function testTheBootGateAssertionHeadersAreSequentialAndComplete(): void
    {
        $directives = self::gateDirectives();

        self::assertGreaterThan(
            0,
            preg_match_all('/^say "ASSERT (\d+)\/(\d+) /m', $directives, $m),
            'the boot gate must announce its assertions as `ASSERT k/N`'
        );

        $denominators = array_values(array_unique($m[2]));
        self::assertCount(
            1,
            $denominators,
            'the boot gate declares more than one assertion total: ' . implode(', ', $denominators)
        );

        $total = (int) $denominators[0];
        $numerators = array_map('intval', $m[1]);
        sort($numerators);

        self::assertSame(
            range(1, $total),
            $numerators,
            'the boot gate announces ' . $total . ' assertions but its headers are ['
            . implode(', ', $numerators) . '] — a gap means a block was deleted, '
            . 'and a duplicate means one was copied'
        );
    }

    /**
     * Every `pass <id>` / `fail <id>` site in the gate, attributed to the
     * `say "ASSERT k/N"` block it is reached from.
     *
     * @return array<string, array<string, array{pass: int, fail: int}>>
     */
    private static function gateVerdictSites(): array
    {
        $block = 'the preamble (before ASSERT 1)';
        $raw = [];

        foreach (preg_split('/\R/', self::gateDirectives()) ?: [] as $line) {
            if (preg_match('/^say "ASSERT (\d+)\/\d+ /', $line, $header) === 1) {
                $block = 'ASSERT ' . $header[1];
            }
            // Same shape as testTheBootGateRegistersEveryCheckItCanReport(): the
            // optional `NN)` prefix is a `case` arm, and the id is captured
            // GREEDILY so a typo cannot be silently skipped.
            if (preg_match('/^\s*(?:\S+\)\s+)?(pass|fail)\s+(\S+)\s/', $line, $m) !== 1) {
                continue;
            }
            // `fail "$1" …` inside uint_or_fail() forwards a caller's id.
            if (str_contains($m[2], '$') || str_contains($m[2], '"')) {
                continue;
            }
            $raw[$m[2]][$block][$m[1]] = ($raw[$m[2]][$block][$m[1]] ?? 0) + 1;
        }

        $sites = [];
        foreach ($raw as $id => $blocks) {
            foreach ($blocks as $name => $counts) {
                $sites[$id][$name] = [
                    'pass' => $counts['pass'] ?? 0,
                    'fail' => $counts['fail'] ?? 0,
                ];
            }
        }

        return $sites;
    }

    /** The ids the gate's own EXPECTED_CHECKS registry carries. */
    private static function registeredGateChecks(): array
    {
        self::assertSame(
            1,
            preg_match('/^EXPECTED_CHECKS=\'\n(.*?)\'$/ms', self::gateDirectives(), $m),
            'the gate must declare the list of checks it is required to reach'
        );

        return array_values(array_filter(array_map('trim', explode("\n", $m[1]))));
    }

    private static function gateDirectives(): string
    {
        return self::directivesOnly((string) file_get_contents(
            dirname(__DIR__, 3) . '/scripts/docker-boot-smoke.sh'
        ));
    }

    /**
     * Finding 2: a raw TCP connect through a published port cannot fail while
     * Docker's userland proxy is enabled (the default, including on
     * GitHub-hosted runners) — it accepts on the host port BEFORE dialling the
     * container. The host-side WS check must therefore key on curl's exit code,
     * which does discriminate: 52 healthy, 56 nothing listening, 7 no mapping.
     */
    public function testTheBootGateRejectsAnEmptyPublishedPort(): void
    {
        $gate = self::directivesOnly((string) file_get_contents(
            dirname(__DIR__, 3) . '/scripts/docker-boot-smoke.sh'
        ));

        self::assertMatchesRegularExpression(
            '/^\s*56\)\s+fail\s+ws-published/m',
            $gate,
            'curl exit 56 (connection reset behind the mapping) must FAIL the gate'
        );
        self::assertMatchesRegularExpression(
            '/^\s*7\)\s+fail\s+ws-published/m',
            $gate,
            'curl exit 7 (no mapping at all) must FAIL the gate'
        );
        self::assertMatchesRegularExpression(
            '/^\s*52\)\s+pass\s+ws-published/m',
            $gate,
            'curl exit 52 is the HEALTHY signal — the worker answered and closed the '
            . 'unauthenticated upgrade; rejecting it reddens a good container'
        );
        self::assertDoesNotMatchRegularExpression(
            '#^\s*if\s+timeout\s+\d+\s+bash\s+-c\s+"exec\s+3<>/dev/tcp/[^"]*"[^\n]*;\s*then\s*\n\s*pass\s#m',
            $gate,
            'a raw /dev/tcp connect to a PUBLISHED port cannot fail, so it must not be an assertion'
        );
    }

    /**
     * Finding 12: `30000 + RANDOM % 20000` sits inside the kernel ephemeral
     * range (32768-60999 by default), so ~85% of draws could collide with an
     * outbound socket and `docker run -p` then died under `set -e` with no
     * diagnostic. Let Docker allocate instead.
     */
    public function testTheBootGateDoesNotPickItsOwnHostPorts(): void
    {
        $gate = self::directivesOnly((string) file_get_contents(
            dirname(__DIR__, 3) . '/scripts/docker-boot-smoke.sh'
        ));

        self::assertDoesNotMatchRegularExpression(
            '/\$\(\(\s*\d+\s*\+\s*RANDOM\s*%/',
            $gate,
            'the gate must not hand-pick a host port out of the kernel ephemeral range'
        );
        self::assertStringContainsString(
            '-p 127.0.0.1::8096',
            $gate,
            'publish with an unspecified host port and read it back with `docker port`'
        );
    }
}
