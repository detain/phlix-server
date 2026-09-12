<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * S485 — the paraunit verdict in scripts/parallel/run-suite.sh must be EXACT.
 *
 * The defect (CI run 34651283031, attempt-1): paraunit 2.11.0 maps every test
 * verdict to exactly 0 or 10 (Runner::onProcessParsingCompleted — any worker
 * exiting non-zero ⇒ 10), and children run with failOnWarning=true, so a
 * warnings-only Integration tail exited 10 and the wrapper re-exited it
 * verbatim — killing CI over the same chmod/unlink warning text that had
 * passed twice before, while the script's own comment claimed warnings were
 * non-fatal. The comment was the intent; the code was the flake.
 *
 * This file EXECUTES the mechanism end to end (the S457 house style — the
 * protection must run, not be described):
 *
 *  1. Real paraunit runs over five one-test scratch suites (warnings-only,
 *     failure, error, clean, and the S439 zero-residue-census class), pinning
 *     the measured artifact shape FIRST — exit status, recap wording, JUnit
 *     element presence. If paraunit's format drifts, these asserts scream
 *     before the verdict can drift with it: a verdict that silently stops
 *     matching its subject is worse than no verdict at all.
 *  2. The wrapper's `verdict <status> <log> <junit-dir>` seam is then run
 *     against exactly those artifacts, asserted on real exit codes:
 *     warnings-only ⇒ 0 (the AC's deterministic non-fatal); ERRORS/FAILURES
 *     and runner-level [UNKNOWN] warnings ⇒ 1; no-evidence under 10 ⇒ 1;
 *     statuses outside {0,10} (console/config failures — paraunit never ran
 *     a child to a verdict) ⇒ propagated verbatim.
 *  3. Mutation controls: two targeted edits to a COPY of the script must flip
 *     exactly the verdicts they invalidate. Reverting the narrowing reddens
 *     the warnings-only case with the old bug's own exit code (10); deleting
 *     the [UNKNOWN] route would let the S439 census go green inside the
 *     parallel lanes — both provable, because the unmutated copy is asserted
 *     opposite in the same test.
 *
 * Deliberate, documented demotion: a test-attributed PHPUnit warning is
 * indistinguishable from a PHP warning in paraunit's recap (both collapse onto
 * the test filename), so it rides the non-fatal verdict together with them.
 *
 * Scratch dirs are named `s485-verdict-*`, never `phlix_*`: the S439 census
 * globs `phlix_*` in each worker's sys_get_temp_dir(), and this test must not
 * itself become the flake it studies. Every scratch path is removed in
 * tearDownAfterClass.
 *
 * The step's collision sentinel ({@see STEP_TOKEN}) lives here, in code,
 * deliberately: exactly one occurrence across the estate, never in prose.
 */
final class ParaunitVerdictExactnessTest extends TestCase
{
    public const STEP_TOKEN = 'S485EX10MECHX9P3';

    private const REPO = __DIR__ . '/../../..';

    private const RUN_SUITE = self::REPO . '/scripts/parallel/run-suite.sh';

    /** @var array<string, array{status:int, log:string, junitDir:string, dir:string}> */
    private static array $fixtures = [];

    /** @var list<string> */
    private static array $scratchDirs = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$scratchDirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }

        self::$scratchDirs = [];
        self::$fixtures = [];
    }

    // -----------------------------------------------------------------------
    // 0 — the collision sentinel is exactly one occurrence, in this file.
    // -----------------------------------------------------------------------

    public function testTheStepTokenExistsInCodeExactlyOnceAndInNoProse(): void
    {
        $needle = self::STEP_TOKEN;
        $this->assertSame(16, strlen($needle), 'the sentinel has an exact length; a typo changes it');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{16}$/', $needle);

        // --others --exclude-standard: this file must be found in its own commit too —
        // a scan of only tracked paths would be blind on the pre-commit run and in any
        // worktree where the guard itself is not yet added. Ignored paths stay out.
        $output = [];
        exec('git -C ' . escapeshellarg(self::REPO) . ' ls-files --cached --others --exclude-standard', $output);
        $this->assertNotEmpty($output, 'git ls-files must enumerate the estate');

        $hits = [];

        foreach ($output as $relative) {
            $path = self::REPO . '/' . $relative;

            if (!is_file($path)) {
                continue;
            }

            $contents = file_get_contents($path);
            $this->assertIsString($contents, 'cannot read file ' . $relative);
            $count = substr_count($contents, $needle);

            if ($count > 0) {
                $hits[$relative] = $count;
            }
        }

        $this->assertSame(
            ['tests/Unit/Support/ParaunitVerdictExactnessTest.php' => 1],
            $hits,
            'The S485 sentinel must reside in code — in exactly this file, exactly once — and'
            . ' in no markdown, workflow, or progress document anywhere. A second occurrence'
            . ' means a second copy of this work exists.',
        );
    }

    // -----------------------------------------------------------------------
    // 1 — real paraunit artifacts, graded through the real verdict path.
    // -----------------------------------------------------------------------

    public function testRealParaunitWarningsOnlyExitTenIsGradedNonFatal(): void
    {
        $fixture = self::paraunitFixture('warn', self::WARN_BODY);

        // Anti-vacuity first: the fixture really is the shape CI run 34651283031 hit.
        $this->assertSame(
            10,
            $fixture['status'],
            'warnings-only under failOnWarning=true must exit paraunit 10',
        );
        $log = self::logOf($fixture);
        $this->assertMatchesRegularExpression('/^[1-9][0-9]* files with WARNINGS:$/m', $log);
        $this->assertStringNotContainsString('files with ERRORS', $log);
        $this->assertStringNotContainsString('files with FAILURES', $log);
        $this->assertStringNotContainsString('files with RISKY', $log);
        $this->assertStringNotContainsString(' [UNKNOWN]', $log);
        $this->assertSame(
            [],
            self::faultyTails($fixture['junitDir']),
            'a PHP warning must never surface as a JUnit element',
        );

        $verdict = self::runBash(self::RUN_SUITE, ['verdict', '10', $fixture['log'], $fixture['junitDir']]);
        $this->assertSame(
            0,
            $verdict['exit'],
            'AC: warnings-only is a deterministic NON-fatal — got: ' . $verdict['output'],
        );
        $this->assertStringContainsString('deterministic non-fatal verdict (S485)', $verdict['output']);
        $this->assertStringContainsString('files with WARNINGS', $verdict['output'], 'the warn note stays loud');
    }

    public function testRealFailuresUnderStatusTenStayFatal(): void
    {
        $fixture = self::paraunitFixture('fail', self::FAIL_BODY);

        $this->assertSame(10, $fixture['status']);
        $log = self::logOf($fixture);
        $this->assertMatchesRegularExpression('/^[1-9][0-9]* files with FAILURES:$/m', $log);
        $this->assertNotEmpty(
            self::faultyTails($fixture['junitDir']),
            'the failure must really be in the JUnit tail',
        );

        $verdict = self::runBash(self::RUN_SUITE, ['verdict', '10', $fixture['log'], $fixture['junitDir']]);
        $this->assertSame(
            1,
            $verdict['exit'],
            'a real failure under status 10 must be fatal — got: ' . $verdict['output'],
        );
        $this->assertStringContainsString('ERRORS/FAILURES', $verdict['output']);
    }

    public function testRealErrorsUnderStatusTenStayFatal(): void
    {
        $fixture = self::paraunitFixture('err', self::ERR_BODY);

        $this->assertSame(10, $fixture['status']);
        $log = self::logOf($fixture);
        $this->assertMatchesRegularExpression('/^[1-9][0-9]* files with ERRORS:$/m', $log);
        $this->assertNotEmpty(self::faultyTails($fixture['junitDir']));

        $verdict = self::runBash(self::RUN_SUITE, ['verdict', '10', $fixture['log'], $fixture['junitDir']]);
        $this->assertSame(
            1,
            $verdict['exit'],
            'a real error under status 10 must be fatal — got: ' . $verdict['output'],
        );
        $this->assertStringContainsString('ERRORS/FAILURES', $verdict['output']);
    }

    public function testTheCleanLaneStillPassesThroughTheNewVerdict(): void
    {
        $fixture = self::paraunitFixture('clean', self::CLEAN_BODY);

        $this->assertSame(0, $fixture['status']);
        $this->assertDoesNotMatchRegularExpression('/files with [A-Z]/', self::logOf($fixture));

        $verdict = self::runBash(self::RUN_SUITE, ['verdict', '0', $fixture['log'], $fixture['junitDir']]);
        $this->assertSame(
            0,
            $verdict['exit'],
            'a clean log has no WARNINGS line; the load-bearing `|| true` must keep this 0 — got: '
            . $verdict['output'],
        );
    }

    public function testTheZeroResidueCensusWarningClassStaysFatalUnderTheParallelLane(): void
    {
        $fixture = self::paraunitFixture('census', self::CENSUS_BODY, true);

        // Measured S439 mechanism: the census throw becomes a runner-level warning,
        // which paraunit attributes to Test::unknown() — the literal ' [UNKNOWN]'
        // line in the WARNINGS recap, while its JUnit tail is fault-free.
        $this->assertSame(10, $fixture['status']);
        $log = self::logOf($fixture);
        $this->assertMatchesRegularExpression('/^[1-9][0-9]* files with WARNINGS:$/m', $log);
        $this->assertMatchesRegularExpression(
            '/^ \[UNKNOWN\]$/m',
            $log,
            'the census warning must surface as paraunit\'s own [UNKNOWN] attribution',
        );
        $this->assertSame([], self::faultyTails($fixture['junitDir']));

        $verdict = self::runBash(self::RUN_SUITE, ['verdict', '10', $fixture['log'], $fixture['junitDir']]);
        $this->assertSame(
            1,
            $verdict['exit'],
            'the S439 mechanism must stay red in the parallel lanes — got: ' . $verdict['output'],
        );
        $this->assertStringContainsString('[UNKNOWN]', $verdict['output']);
    }

    // -----------------------------------------------------------------------
    // 2 — synthetic inputs for the paths no honest scratch test can produce.
    // -----------------------------------------------------------------------

    public function testStatusesOutsideTheVerdictMapPropagateVerbatim(): void
    {
        $fixture = self::paraunitFixture('clean', self::CLEAN_BODY);

        // paraunit 2.11.0 exits 1 on console/config failures without ever running a
        // child (measured: bogus subcommand, missing config, extension unregistered)
        // — the verdict must not grade those as test outcomes, just propagate.
        foreach ([1, 2, 7] as $status) {
            $verdict = self::runBash(
                self::RUN_SUITE,
                ['verdict', (string) $status, $fixture['log'], $fixture['junitDir']],
            );
            $this->assertSame(
                $status,
                $verdict['exit'],
                "status {$status} must propagate verbatim — got: " . $verdict['output'],
            );
            $this->assertStringContainsString('Propagating verbatim', $verdict['output']);
        }
    }

    public function testNoEvidenceUnderStatusTenFailsInsteadOfPassing(): void
    {
        $dir = self::scratch('noevidence');
        $log = $dir . '/paraunit.log';
        file_put_contents($log, "\n1 files with WARNINGS:\n WTest\n\n");
        $junitDir = $dir . '/junit';
        mkdir($junitDir);

        $verdict = self::runBash(self::RUN_SUITE, ['verdict', '10', $log, $junitDir]);
        $this->assertSame(
            1,
            $verdict['exit'],
            'status 10 with zero JUnit tails is a gate that cannot read results — got: '
            . $verdict['output'],
        );
        $this->assertStringContainsString('no evidence', $verdict['output']);
    }

    public function testTheJUnitElementBackstopCatchesRecapWordingDrift(): void
    {
        $dir = self::scratch('drift');
        $log = $dir . '/paraunit.log';
        file_put_contents($log, "some future paraunit phrasing without any recap header\n");
        $junitDir = $dir . '/junit';
        mkdir($junitDir);
        file_put_contents(
            $junitDir . '/junit-cafe01.xml',
            '<testsuite name="DriftT" tests="1" assertions="0" errors="1" failures="0" skipped="0" time="0">'
            . '<testcase name="x"><error type="E">boom</error></testcase></testsuite>',
        );

        $verdict = self::runBash(self::RUN_SUITE, ['verdict', '10', $log, $junitDir]);
        $this->assertSame(
            1,
            $verdict['exit'],
            'a <error> element in any tail is fatal whatever the log says — got: ' . $verdict['output'],
        );
        $this->assertStringContainsString('backstop', $verdict['output']);
    }

    public function testTheVerdictSeamRefusesMalformedArguments(): void
    {
        $dir = self::scratch('usage');
        $log = $dir . '/paraunit.log';
        file_put_contents($log, 'x');

        foreach (
            [
            [],
            ['verdict', '10'],
            ['verdict', 'ten', $log, $dir],
            ['verdict', '10', $dir . '/missing.log', $dir],
            ['verdict', '10', $log, $dir . '/missing-dir'],
            ] as $args
        ) {
            $verdict = self::runBash(self::RUN_SUITE, $args);
            $this->assertSame(
                2,
                $verdict['exit'],
                'usage errors must exit 2 before grading anything — got: ' . $verdict['output'],
            );
        }
    }

    // -----------------------------------------------------------------------
    // 3 — the narrowing is load-bearing: mutate a copy, watch it break.
    // -----------------------------------------------------------------------

    public function testTheNarrowingAndTheUnknownRuleAreLoadBearing(): void
    {
        $script = file_get_contents(self::RUN_SUITE);
        $this->assertIsString($script);
        $warn = self::paraunitFixture('warn', self::WARN_BODY);
        $census = self::paraunitFixture('census', self::CENSUS_BODY, true);

        // Mutation 1: restore the S485 defect — re-exit any non-zero verbatim.
        $anchor = 'local status="$1" log="$2" junit_dir="$3"';
        $this->assertStringContainsString(
            $anchor,
            $script,
            'mutation anchor vanished: the verdict function was restructured; update this control',
        );
        $mutated1 = str_replace(
            $anchor,
            $anchor . "\n    if [ \"\$status\" != \"0\" ]; then\n        exit \"\$status\"\n    fi",
            $script,
        );
        $this->assertNotSame($script, $mutated1, 'mutation 1 did not apply — the control would be vacuous');
        $path1 = self::scratch('mutate-propagate') . '/run-suite-mutated.sh';
        file_put_contents($path1, $mutated1);

        $result = self::runBash($path1, ['verdict', '10', $warn['log'], $warn['junitDir']]);
        $this->assertSame(
            10,
            $result['exit'],
            'with the old verbatim re-exit the warnings-only lane dies with 10 '
            . '(the run-34651283031 class of failure)',
        );
        $control = self::runBash(self::RUN_SUITE, ['verdict', '10', $warn['log'], $warn['junitDir']]);
        $this->assertSame(0, $control['exit'], 'the unmutated script grades the same artifacts fatal-free');

        // Mutation 2: delete the [UNKNOWN] route — the S439 census goes green in paraunit.
        $guard = '[ -n "$unknown_warning" ]';
        $this->assertStringContainsString($guard, $script, 'unknown-warning guard vanished; update this control');
        $mutated2 = str_replace($guard, '[ -n "$unknown_warning" ] && false', $script);
        $this->assertNotSame($script, $mutated2, 'mutation 2 did not apply — the control would be vacuous');
        $path2 = self::scratch('mutate-unknown') . '/run-suite-mutated.sh';
        file_put_contents($path2, $mutated2);

        $result2 = self::runBash($path2, ['verdict', '10', $census['log'], $census['junitDir']]);
        $this->assertSame(
            0,
            $result2['exit'],
            'without the [UNKNOWN] rule the census warning is silently demoted — '
            . 'this is exactly the regression the rule exists to prevent',
        );
        $control2 = self::runBash(self::RUN_SUITE, ['verdict', '10', $census['log'], $census['junitDir']]);
        $this->assertSame(1, $control2['exit'], 'the unmutated script keeps the census class fatal');
    }

    // -----------------------------------------------------------------------
    // 4 — the script keeps the seams this test executes.
    // -----------------------------------------------------------------------

    public function testTheRunSuiteScriptCarriesTheVerdictContract(): void
    {
        $script = (string) file_get_contents(self::RUN_SUITE);

        foreach (
            [
            'paraunit_verdict()' => 'the single verdict authority both paths call',
            '[ "$status" != "0" ] && [ "$status" != "10" ]' => 'statuses outside the verdict map propagate verbatim',
            'files with (ERRORS|FAILURES)' => 'paraunit can exit 0 around ERRORS/FAILURES; the wrapper must not',
            'files with ABNORMAL TERMINATIONS' => 'a worker that died mid-test never rides the warnings-only pass',
            '" [UNKNOWN]"' => 'runner-level warnings keep the S439 census fatal in the parallel lanes',
            'files with RISKY OUTCOME' => 'failOnRisky=true makes risky a failure, not a warning',
            '<(error|failure)[ />]' => 'JUnit-side backstop against recap wording drift',
            '|| true' => 'the clean-lane grep miss must not kill the script under pipefail',
            ] as $needle => $why
        ) {
            $this->assertStringContainsString($needle, $script, "run-suite.sh lost a verdict seam ({$why}).");
        }
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    private const WARN_BODY = <<<'PHP'
        fopen('/phlix-s485-verdict-definitely-not-a-real-file', 'r');
        $this->assertTrue(true);
    PHP;

    private const FAIL_BODY = <<<'PHP'
        $this->assertSame(1, 2, 'planned failure for the S485 fixture');
    PHP;

    private const ERR_BODY = <<<'PHP'
        throw new \RuntimeException('planned error for the S485 fixture');
    PHP;

    private const CLEAN_BODY = <<<'PHP'
        $this->assertTrue(true);
    PHP;

    private const CENSUS_BODY = <<<'PHP'
        touch(sys_get_temp_dir() . '/phlix_planted_during_run');
        $this->assertTrue(true);
    PHP;

    /**
     * Run one scratch testsuite through the REAL vendor/bin/paraunit with the same
     * per-worker seams run-suite.sh exports, and return the artifacts (status, log
     * path, JUnit dir) for grading. Memoized per scenario: five real runs total.
     *
     * @return array{status:int, log:string, junitDir:string, dir:string}
     */
    private static function paraunitFixture(string $scenario, string $body, bool $withCensusExtension = false): array
    {
        if (isset(self::$fixtures[$scenario])) {
            return self::$fixtures[$scenario];
        }

        $autoload = realpath(self::REPO . '/vendor/autoload.php');
        $paraunit = realpath(self::REPO . '/vendor/bin/paraunit');

        if ($autoload === false || $paraunit === false) {
            throw new RuntimeException(
                'vendor/autoload.php or vendor/bin/paraunit is missing — the parallel lanes'
                . ' depend on it; run `composer install` (dev deps).',
            );
        }

        $dir = self::scratch($scenario);
        mkdir($dir . '/tests');
        mkdir($dir . '/junit');

        $class = 'S485Fixture' . ucfirst($scenario) . 'Test';
        file_put_contents(
            $dir . '/tests/' . $class . '.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse PHPUnit\\Framework\\TestCase;\n\n"
            . "final class {$class} extends TestCase\n{\n"
            . "    public function test_the_fixture(): void\n    {\n        " . ltrim($body) . "\n    }\n}\n",
        );

        $extensions = $withCensusExtension
            ? "  <extensions>\n    <bootstrap class=\"Paraunit\\Configuration\\ParaunitExtension\"/>\n"
              . "    <bootstrap class=\"Phlix\\Tests\\Support\\ResidueCensus\\ZeroResidueCensusExtension\"/>\n"
              . "  </extensions>\n"
            : "  <extensions>\n    <bootstrap class=\"Paraunit\\Configuration\\ParaunitExtension\"/>\n"
              . "  </extensions>\n";

        file_put_contents(
            $dir . '/phpunit.xml',
            "<?xml version=\"1.0\"?>\n"
            . '<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" bootstrap="'
            . $autoload . '" cacheDirectory="cache"'
            . " failOnWarning=\"true\" failOnRisky=\"true\" beStrictAboutOutputDuringTests=\"true\">\n"
            . "  <testsuites>\n    <testsuite name=\"Scratch\">\n"
            . "      <directory suffix=\"Test.php\">tests</directory>\n    </testsuite>\n  </testsuites>\n"
            . $extensions
            . "</phpunit>\n",
        );

        $log = $dir . '/paraunit.log';
        $command = [
            'php',
            $paraunit,
            'run',
            '--parallel=2',
            '--configuration=' . $dir . '/phpunit.xml',
            '--testsuite',
            'Scratch',
            '--pass-through=--do-not-cache-result',
            '--pass-through=--log-junit=' . $dir . '/junit/junit-__PHLIX_WORKER__.xml',
        ];

        $pipes = [];
        $process = proc_open(
            $command,
            [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            $dir,
            [
                'PATH' => self::REPO . '/scripts/parallel:' . (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
                'TMPDIR' => $dir,
                'PHLIX_TEST_TMP_BASE' => $dir,
                'PHLIX_REAL_PHP' => PHP_BINARY,
            ],
        );

        if (!is_resource($process)) {
            self::fail('paraunit fixture could not start for scenario ' . $scenario);
        }

        $status = proc_close($process);

        self::$fixtures[$scenario] = ['status' => $status, 'log' => $log, 'junitDir' => $dir . '/junit', 'dir' => $dir];

        return self::$fixtures[$scenario];
    }

    /**
     * JUnit tails whose content records a fault element. PHPUnit's JUnit logger has
     * no warning node at all (tests/assertions/errors/failures/skipped/time only),
     * so a non-empty result here means a real error/failure happened.
     *
     * @return list<string>
     */
    private static function faultyTails(string $junitDir): array
    {
        $faulty = [];

        foreach (glob($junitDir . '/*.xml') ?: [] as $file) {
            if (preg_match('/<(error|failure)[ \/>]/', (string) file_get_contents($file)) === 1) {
                $faulty[] = basename($file);
            }
        }

        return $faulty;
    }

    /** @param array{status:int, log:string, junitDir:string, dir:string} $fixture */
    private static function logOf(array $fixture): string
    {
        $log = file_get_contents($fixture['log']);
        self::assertTrue($log !== false, 'paraunit produced no log at all: ' . $fixture['log']);

        return (string) $log;
    }

    /**
     * Invoke a bash script with argv and capture combined output + exit code,
     * exactly the way the S457 wiring test invokes run-suite.sh's refusals.
     *
     * @param list<string> $args
     *
     * @return array{exit:int, output:string}
     */
    private static function runBash(string $script, array $args): array
    {
        $command = array_merge(['/usr/bin/env', 'bash', $script], array_map('strval', $args));
        $output = [];
        $exit = 0;

        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $output, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }

    /** Scratch dir, registered for tearDownAfterClass cleanup. */
    private static function scratch(string $label): string
    {
        $dir = sys_get_temp_dir() . '/s485-verdict-' . $label . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        self::$scratchDirs[] = $dir;

        return $dir;
    }
}
