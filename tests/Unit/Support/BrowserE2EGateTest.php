<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Phlix\Tests\Support\Browser\BrowserProbeEnvironment;
use Symfony\Component\Yaml\Yaml;

/**
 * S305 — the headless-browser HLS gate must RUN where merges are decided, and must be
 * unable to pass by not running.
 *
 * ## The defect, measured
 *
 * S57's three `Fmp4HlsPlaybackE2ETest` cases — real ffmpeg, real hls.js, real headless
 * Chrome, playing past a segment boundary — skipped on EVERY CI run, because
 * `.github/workflows/phpunit.yml` never installed hls.js. Measured on PR #664 (run
 * `31263130044`): `Skipped: 6` against master's 3, exactly those three. The job was
 * green throughout: **a skipped test reads as a pass**, PHPUnit exits 0 with "OK, but
 * some tests were skipped!", and with no branch protection here `skipped` scores as
 * SUCCESS for a status check too.
 *
 * ## What this file pins, in three layers
 *
 *  1. **The wiring** — the workflow installs the prerequisites, logs a JUnit report,
 *     and runs the gate; none of those steps may acquire an `if:`, a
 *     `continue-on-error:` or a `|| true`, and the gate step's only permitted condition
 *     is the one that cannot evaluate false.
 *  2. **The names** — the gate demands three methods on one class. If a rename left
 *     `BrowserProbeEnvironment::REQUIRED_CASES` pointing at nothing, CI would still red
 *     (a missing case fails the gate), but it would red for a confusing reason and
 *     minutes later. Here it reds in milliseconds, at the rename.
 *  3. **The gate itself, in BOTH directions** — a gate nobody has watched fail is not a
 *     gate anybody knows works. Every failure mode below was produced from a report and
 *     asserted on the script's real exit code, and the positive control is asserted
 *     beside each so "always fails" cannot masquerade as "detects the defect".
 *
 * The one thing this file deliberately does NOT do is re-implement the browser probe's
 * environment discovery to check up on it. That would be a check derived from its own
 * subject. The real proof is the CI run: `scripts/assert-browser-e2e-ran.php` reads the
 * JUnit report, which is produced by PHPUnit and not by anything either script controls.
 */
final class BrowserE2EGateTest extends TestCase
{
    private const REPO = __DIR__ . '/../../..';

    private const WORKFLOW = self::REPO . '/.github/workflows/phpunit.yml';

    private const GATE_SCRIPT = 'scripts/assert-browser-e2e-ran.php';

    private const PREREQ_SCRIPT = 'scripts/ci-browser-e2e-prereqs.php';

    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_dir($path)) {
                exec('rm -rf ' . escapeshellarg($path));
            } elseif (is_file($path)) {
                unlink($path);
            }
        }

        $this->tempPaths = [];

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Layer 1 — the wiring.
    // -----------------------------------------------------------------------

    public function testTheSuiteStepWritesAJUnitReportForTheGateToRead(): void
    {
        $step = $this->stepMatching(
            static fn (string $run): bool => str_contains($run, 'vendor/bin/phpunit --coverage-clover'),
        );

        $this->assertStringContainsString(
            '--log-junit',
            $step['step']['run'],
            'The JUnit report is the ONLY per-case record of what ran. Without it the browser gate '
            . 'cannot tell three cases that played video in a real browser from three that skipped, '
            . 'and the coverage report cannot substitute (it is aggregate, and a skipped test '
            . 'contributes to it exactly as an absent one does).',
        );
    }

    public function testTheWorkflowInstallsTheBrowserPrerequisitesAndCannotSkipDoingSo(): void
    {
        $match = $this->stepRunning(self::PREREQ_SCRIPT);

        $this->assertArrayNotHasKey(
            'if',
            $match['step'],
            'A skipped step contributes nothing, and without hls.js the three browser cases skip — '
            . 'which is the exact defect S305 exists to close.',
        );

        $this->assertNotTrue(
            $match['step']['continue-on-error'] ?? false,
            'continue-on-error makes a FAILED step report success, so a missing browser would arrive '
            . 'as three silent skips again.',
        );

        $this->assertStringNotContainsString('|| true', $match['step']['run']);

        foreach (['--skip-binaries', '--tarball=', '--lock=', '--dest='] as $option) {
            $this->assertStringNotContainsString(
                $option,
                $match['step']['run'],
                $option . ' exists only so this test can exercise the installer offline. In CI it '
                . 'would narrow the step to something that no longer proves the prerequisites are '
                . 'where the E2E test looks for them.',
            );
        }
    }

    public function testTheWorkflowProvidesANodeNewEnoughToDriveChrome(): void
    {
        $job = $this->testJob();

        /** @var list<array<string, mixed>> $setupNode */
        $setupNode = [];
        foreach ($this->steps($job) as $step) {
            $uses = $step['uses'] ?? null;
            if (is_string($uses) && str_starts_with($uses, 'actions/setup-node@')) {
                $setupNode[] = $step;
            }
        }

        $this->assertCount(
            1,
            $setupNode,
            'The probe drives Chrome with node\'s built-in global WebSocket (node >= '
            . BrowserProbeEnvironment::MIN_NODE_MAJOR . '). Relying on whatever node the runner '
            . 'image happens to ship is how an environment-dependent check goes quietly missing.',
        );

        /** @var array<string, mixed> $with */
        $with = is_array($setupNode[0]['with'] ?? null) ? $setupNode[0]['with'] : [];
        $version = (string) ($with['node-version'] ?? '');

        $this->assertMatchesRegularExpression('/^\d+$/', $version, 'pin a major version, not a range');
        $this->assertGreaterThanOrEqual(
            BrowserProbeEnvironment::MIN_NODE_MAJOR,
            (int) $version,
            'node ' . $version . ' has no global WebSocket, so the probe cannot reach Chrome and the '
            . 'three cases skip.',
        );
    }

    public function testTheGateRunsInTheSameJobAndItsOnlyConditionIsOneThatCannotBeFalse(): void
    {
        $match = $this->stepRunning(self::GATE_SCRIPT);

        $this->assertSame(
            $this->testJobName(),
            $match['jobName'],
            'The gate reads junit.xml from the workspace, so it has to live in the job that produced '
            . 'it. A separate job would need an artifact round-trip, and an upload that silently '
            . 'produced nothing would leave the gate reading an absent file.',
        );

        $this->assertSame(
            'always()',
            $match['step']['if'] ?? null,
            'always() is deliberate and is the ONLY acceptable condition here: on a red run the most '
            . 'useful thing this gate can say is "the browser cases ran, and they are what failed". '
            . 'Any other expression can evaluate false, and a skipped step is a gate that passes by '
            . 'not running.',
        );

        $this->assertNotTrue($match['step']['continue-on-error'] ?? false);
        $this->assertStringNotContainsString('|| true', $match['step']['run']);

        $this->assertArrayNotHasKey(
            'if',
            $match['job'],
            'Same reason one level up: a skipped JOB reports conclusion "skipped", which counts as '
            . 'SUCCESS for a status check.',
        );
        $this->assertNotTrue($match['job']['continue-on-error'] ?? false);
    }

    public function testBothScriptsExist(): void
    {
        $this->assertFileExists(self::REPO . '/' . self::GATE_SCRIPT);
        $this->assertFileExists(self::REPO . '/' . self::PREREQ_SCRIPT);
    }

    // -----------------------------------------------------------------------
    // Layer 2 — the gate names cases that exist.
    // -----------------------------------------------------------------------

    public function testEveryRequiredCaseIsARealMethodOnTheRealTestClass(): void
    {
        $class = BrowserProbeEnvironment::TEST_CLASS;

        $this->assertTrue(
            class_exists($class),
            $class . ' does not exist, so the CI gate is demanding cases from a class that was moved '
            . 'or renamed. Update BrowserProbeEnvironment::TEST_CLASS in the same commit.',
        );

        $this->assertCount(3, BrowserProbeEnvironment::REQUIRED_CASES);

        foreach (BrowserProbeEnvironment::REQUIRED_CASES as $method) {
            $this->assertTrue(
                method_exists($class, $method),
                $class . '::' . $method . '() does not exist. The gate matches on exact class+name, '
                . 'so a rename here silently becomes "case ABSENT" in CI — loud, but minutes later '
                . 'and for a confusing reason.',
            );
        }
    }

    // -----------------------------------------------------------------------
    // Layer 3 — the gate, in both directions, on its real exit code.
    // -----------------------------------------------------------------------

    public function testTheGateAcceptsAReportWhereAllThreeCasesExecuted(): void
    {
        $result = $this->runGate($this->junit($this->executedCases()));

        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringContainsString('3/3 required cases EXECUTED', $result['output']);
        $this->assertStringContainsString(
            '4 test cases in the run',
            $result['output'],
            'The denominator must be printed. A gate that inspected zero items exits 0 with LESS '
            . 'output than a real pass, which is indistinguishable from success without one.',
        );
    }

    public function testTheGateRejectsAReportWhereTheCasesSkipped(): void
    {
        $cases = $this->executedCases();
        foreach ($cases as $i => $case) {
            $cases[$i]['skipped'] = true;
            $cases[$i]['assertions'] = 0;
        }

        $result = $this->runGate($this->junit($cases));

        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('were SKIPPED', $result['output']);
        foreach (BrowserProbeEnvironment::REQUIRED_CASES as $method) {
            $this->assertStringContainsString($method, $result['output'], 'every skipped case is named');
        }
    }

    public function testTheGateRejectsAReportWhereOneCaseIsAbsent(): void
    {
        $cases = $this->executedCases();
        array_splice($cases, 1, 1);

        $result = $this->runGate($this->junit($cases));

        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('ABSENT', $result['output']);
        $this->assertStringContainsString(BrowserProbeEnvironment::REQUIRED_CASES[1], $result['output']);
    }

    /**
     * The neuter this repo has already been burned by: a check that runs, inspects
     * nothing, and exits 0 with less output than a pass.
     */
    public function testTheGateRejectsAnEmptyRun(): void
    {
        $result = $this->runGate($this->junit([]));

        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('no <testcase> elements', $result['output']);
    }

    public function testTheGateRejectsACaseThatExecutedButAssertedNothing(): void
    {
        $cases = $this->executedCases();
        $cases[2]['assertions'] = 0;

        $result = $this->runGate($this->junit($cases));

        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('ZERO assertions', $result['output']);
        $this->assertStringContainsString(BrowserProbeEnvironment::REQUIRED_CASES[2], $result['output']);
    }

    /**
     * A near-miss must not satisfy the demand. Substring matching is how a deleted route
     * gets absorbed by a sibling wildcard elsewhere in this estate; the same mistake here
     * would let a renamed or data-provider-variant case stand in for the real one.
     */
    public function testTheGateMatchesOnExactClassAndNameRatherThanASubstring(): void
    {
        $cases = $this->executedCases();
        $cases[0]['name'] .= 'WithDataSet';

        $absentByName = $this->runGate($this->junit($cases));
        $this->assertSame(1, $absentByName['code'], $absentByName['output']);
        $this->assertStringContainsString('ABSENT', $absentByName['output']);

        $cases = $this->executedCases();
        $cases[0]['class'] = 'Phlix\\Tests\\E2E\\Media\\Transcoding\\Fmp4HlsPlaybackE2ETestExtra';

        $absentByClass = $this->runGate($this->junit($cases));
        $this->assertSame(1, $absentByClass['code'], $absentByClass['output']);
        $this->assertStringContainsString('ABSENT', $absentByClass['output']);
    }

    /**
     * The division of labour, pinned. A FAILING browser case must not fail this gate:
     * the PHPUnit step already reds for that, and "it ran, and it failed" is the reading
     * that makes the EXT-X-MAP mutation experiment legible.
     */
    public function testTheGatePassesWhenARequiredCaseRanAndFailed(): void
    {
        $cases = $this->executedCases();
        $cases[0]['failure'] = 'hls.js did not play the fMP4 playlist';

        $result = $this->runGate($this->junit($cases));

        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringContainsString('failure', $result['output'], 'the status is reported, not hidden');
    }

    public function testTheGateRejectsAMissingReportRatherThanExitingZero(): void
    {
        $missing = sys_get_temp_dir() . '/s305-absent-' . bin2hex(random_bytes(6)) . '.xml';

        $result = $this->runScript(self::GATE_SCRIPT, [$missing]);

        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('was not produced', $result['output']);
    }

    public function testTheGateRejectsAnEmptyOrUnparseableReport(): void
    {
        $empty = $this->tempFile('');
        $emptyResult = $this->runScript(self::GATE_SCRIPT, [$empty]);
        $this->assertSame(1, $emptyResult['code'], $emptyResult['output']);
        $this->assertStringContainsString('empty', $emptyResult['output']);

        $broken = $this->tempFile('<testsuites><testcase');
        $brokenResult = $this->runScript(self::GATE_SCRIPT, [$broken]);
        $this->assertSame(1, $brokenResult['code'], $brokenResult['output']);
        $this->assertStringContainsString('not parseable XML', $brokenResult['output']);
    }

    // -----------------------------------------------------------------------
    // Layer 3b — the installer's integrity check, offline, in both directions.
    // -----------------------------------------------------------------------

    public function testTheInstallerExtractsATarballThatMatchesTheLockfileHash(): void
    {
        [$tarball, $integrity] = $this->fakeHlsTarball();
        $lock = $this->fakeLock($integrity);
        $dest = $this->tempDir();

        $result = $this->runScript(self::PREREQ_SCRIPT, [
            '--lock=' . $lock,
            '--dest=' . $dest,
            '--tarball=' . $tarball,
            '--skip-binaries',
        ]);

        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringContainsString('integrity verified', $result['output']);
        $this->assertFileExists($dest . '/dist/hls.min.js');
    }

    public function testTheInstallerRefusesATarballThatDoesNotMatchTheLockfileHash(): void
    {
        [$tarball] = $this->fakeHlsTarball();
        $lock = $this->fakeLock('sha512-' . base64_encode(hash('sha512', 'not this archive', true)));
        $dest = $this->tempDir();

        $result = $this->runScript(self::PREREQ_SCRIPT, [
            '--lock=' . $lock,
            '--dest=' . $dest,
            '--tarball=' . $tarball,
            '--skip-binaries',
        ]);

        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('does NOT match the lockfile integrity', $result['output']);
        $this->assertFileDoesNotExist($dest . '/dist/hls.min.js');
    }

    public function testTheInstallerRefusesALockfileWithNoHlsJsEntry(): void
    {
        $lock = $this->tempFile((string) json_encode(['packages' => ['node_modules/vue' => ['version' => '3.5.0']]]));

        $result = $this->runScript(self::PREREQ_SCRIPT, [
            '--lock=' . $lock,
            '--dest=' . $this->tempDir(),
            '--skip-binaries',
        ]);

        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString(BrowserProbeEnvironment::HLSJS_LOCK_KEY, $result['output']);
    }

    /**
     * The real lockfile still pins hls.js. If the SPA drops it, CI's installer fails with
     * the message above — but this says so at the moment of the dependency change.
     */
    public function testTheRealLockfilePinsHlsJsWithAnSha512Integrity(): void
    {
        $lockPath = self::REPO . '/' . BrowserProbeEnvironment::WEB_UI_LOCKFILE;
        $this->assertFileExists($lockPath);

        /** @var array<string, mixed> $lock */
        $lock = (array) json_decode((string) file_get_contents($lockPath), true);
        /** @var array<string, mixed> $packages */
        $packages = is_array($lock['packages'] ?? null) ? $lock['packages'] : [];
        $entry = $packages[BrowserProbeEnvironment::HLSJS_LOCK_KEY] ?? null;

        $this->assertIsArray($entry, 'hls.js must stay in web-ui/package-lock.json for the gate to install it');
        $this->assertIsString($entry['version'] ?? null);
        $this->assertStringStartsWith('https://', (string) ($entry['resolved'] ?? ''));
        $this->assertStringStartsWith(
            'sha512-',
            (string) ($entry['integrity'] ?? ''),
            'the installer verifies the downloaded bytes against this hash, so an unpinned or '
            . 'weakly-hashed entry would turn a pinned fetch back into a trust-the-network one (S309)',
        );
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /**
     * @return list<array{class: string, name: string, assertions: int, skipped?: bool, failure?: string}>
     */
    private function executedCases(): array
    {
        $cases = [];
        foreach (BrowserProbeEnvironment::REQUIRED_CASES as $method) {
            $cases[] = ['class' => BrowserProbeEnvironment::TEST_CLASS, 'name' => $method, 'assertions' => 7];
        }
        // One unrelated case, so the printed denominator is not merely the required set.
        $cases[] = [
            'class' => 'Phlix\\Tests\\Unit\\Example\\SomethingTest',
            'name' => 'testSomething',
            'assertions' => 1,
        ];

        return $cases;
    }

    /**
     * @param list<array{class: string, name: string, assertions: int, skipped?: bool, failure?: string}> $cases
     *
     * @return string path to the written report
     */
    private function junit(array $cases): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<testsuites>\n  <testsuite name=\"synthetic\">\n";
        foreach ($cases as $case) {
            $xml .= sprintf(
                '    <testcase name="%s" class="%s" classname="%s" assertions="%d" time="1.500"',
                htmlspecialchars($case['name'], ENT_XML1),
                htmlspecialchars($case['class'], ENT_XML1),
                htmlspecialchars(str_replace('\\', '.', $case['class']), ENT_XML1),
                $case['assertions'],
            );

            if (($case['skipped'] ?? false) === true) {
                $xml .= ">\n      <skipped/>\n    </testcase>\n";
            } elseif (is_string($case['failure'] ?? null)) {
                $xml .= sprintf(
                    ">\n      <failure type=\"x\">%s</failure>\n    </testcase>\n",
                    htmlspecialchars($case['failure'], ENT_XML1),
                );
            } else {
                $xml .= "/>\n";
            }
        }
        $xml .= "  </testsuite>\n</testsuites>\n";

        return $this->tempFile($xml);
    }

    /**
     * @return array{code: int, output: string}
     */
    private function runGate(string $reportPath): array
    {
        return $this->runScript(self::GATE_SCRIPT, [$reportPath]);
    }

    /**
     * @param list<string> $args
     *
     * @return array{code: int, output: string}
     */
    private function runScript(string $script, array $args): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::REPO . '/' . $script);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        /** @var list<string> $output */
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);

        return ['code' => $code, 'output' => implode("\n", $output)];
    }

    /**
     * A minimal npm-shaped tarball: everything under `package/`, with a dist file large
     * enough to pass the installer's "that is not a browser build" size floor.
     *
     * @return array{0: string, 1: string} the tarball path and its sha512 integrity string
     */
    private function fakeHlsTarball(): array
    {
        $work = $this->tempDir();
        mkdir($work . '/package/dist', 0o755, true);
        file_put_contents(
            $work . '/package/package.json',
            (string) json_encode(['name' => 'hls.js', 'version' => '9.9.9']),
        );
        file_put_contents($work . '/package/dist/hls.min.js', str_repeat('/* hls */', 500));

        $tarball = $work . '/hls.tgz';
        exec(sprintf('tar -czf %s -C %s package', escapeshellarg($tarball), escapeshellarg($work)), $out, $code);
        $this->assertSame(0, $code, 'the fixture tarball could not be created');

        return [$tarball, 'sha512-' . base64_encode(hash_file('sha512', $tarball, true))];
    }

    private function fakeLock(string $integrity): string
    {
        return $this->tempFile((string) json_encode(['packages' => [
            BrowserProbeEnvironment::HLSJS_LOCK_KEY => [
                'version' => '9.9.9',
                'resolved' => 'https://registry.npmjs.org/hls.js/-/hls.js-9.9.9.tgz',
                'integrity' => $integrity,
            ],
        ]]));
    }

    private function tempFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/s305-' . bin2hex(random_bytes(6));
        file_put_contents($path, $contents);
        $this->tempPaths[] = $path;

        return $path;
    }

    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/s305-dir-' . bin2hex(random_bytes(6));
        mkdir($path, 0o755, true);
        $this->tempPaths[] = $path;

        return $path;
    }

    /** @return array<string, mixed> */
    private function workflow(): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile(self::WORKFLOW);

        return $parsed;
    }

    private function testJobName(): string
    {
        return 'test';
    }

    /** @return array<string, mixed> */
    private function testJob(): array
    {
        /** @var array<string, mixed> $jobs */
        $jobs = is_array($this->workflow()['jobs'] ?? null) ? $this->workflow()['jobs'] : [];
        $job = $jobs[$this->testJobName()] ?? null;

        $this->assertIsArray($job, 'the "test" job is where the full suite (Unit + Integration + E2E) runs');

        /** @var array<string, mixed> $typed */
        $typed = $job;

        return $typed;
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return list<array<string, mixed>>
     */
    private function steps(array $job): array
    {
        /** @var list<array<string, mixed>> $steps */
        $steps = [];
        foreach (is_array($job['steps'] ?? null) ? $job['steps'] : [] as $step) {
            if (is_array($step)) {
                /** @var array<string, mixed> $typed */
                $typed = $step;
                $steps[] = $typed;
            }
        }

        return $steps;
    }

    /**
     * Exactly one step in the whole file whose `run` body names the script — parsed YAML,
     * not a substring search over the text, so a commented-out step is not a step.
     *
     * @return array{jobName: string, job: array<string, mixed>, step: array<string, mixed>}
     */
    private function stepRunning(string $script): array
    {
        return $this->stepMatching(static fn (string $run): bool => str_contains($run, $script), $script);
    }

    /**
     * @param callable(string): bool $predicate
     *
     * @return array{jobName: string, job: array<string, mixed>, step: array<string, mixed>}
     */
    private function stepMatching(callable $predicate, string $label = 'the expected command'): array
    {
        /** @var array<string, mixed> $jobs */
        $jobs = is_array($this->workflow()['jobs'] ?? null) ? $this->workflow()['jobs'] : [];

        /** @var list<array{jobName: string, job: array<string, mixed>, step: array<string, mixed>}> $matches */
        $matches = [];

        foreach ($jobs as $jobName => $job) {
            if (!is_array($job)) {
                continue;
            }
            /** @var array<string, mixed> $typedJob */
            $typedJob = $job;

            foreach ($this->steps($typedJob) as $step) {
                $run = $step['run'] ?? null;
                if (is_string($run) && $predicate($run)) {
                    $matches[] = ['jobName' => (string) $jobName, 'job' => $typedJob, 'step' => $step];
                }
            }
        }

        $this->assertCount(
            1,
            $matches,
            '.github/workflows/phpunit.yml must contain exactly ONE step running ' . $label
            . '. Zero means the gate is gone; more than one means two jobs disagree about who owns it.',
        );

        return $matches[0];
    }
}
