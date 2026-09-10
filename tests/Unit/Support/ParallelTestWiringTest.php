<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;

/**
 * S457 — the parallel test lanes must produce the SAME evidence the serial lane produced,
 * and the CI wiring that consumes it must keep its promises.
 *
 * ## What moved
 *
 * The single serial `./vendor/bin/phpunit` step became three suite steps (Unit under
 * paraunit, Integration under paraunit on eight cloned schemas, E2E as a serial tail)
 * plus a merge step that reconstitutes the two artifacts every downstream consumer
 * already reads: `coverage.xml` (Clover, merged from serialized php-code-coverage
 * objects — never by re-deriving metrics from XML attributes) and `junit.xml` (every
 * testcase from every worker, each suite name exactly once).
 *
 * ## What this file pins, and why THAT is the dangerous part
 *
 *  1. **The evidence invariants.** A parallel run that hides a testcase, double-counts
 *     one, or merges coverage with lossy arithmetic would keep CI green while lying
 *     about it — the "gate that cannot fail" class. So: the merge scripts' failure
 *     modes are EXECUTED here against crafted inputs and asserted on their real exit
 *     codes, not described.
 *  2. **The seam contracts.** run-suite.sh's per-worker JUnit token is only meaningful
 *     because the PATH shim expands it per child; the shim is executed with and without
 *     a chunk id to pin expansion AND inertness (a seam that fires serially would change
 *     every developer's plain `php` invocations).
 *  3. **The workflow shape.** Step names, their order (clone → Unit → Integration →
 *     E2E tail → merge → gates), the exact shard/parallel literals (they must agree with
 *     each other), and the invocation-site literal count that
 *     {@see SkippedTestNameReportingTest}'s census independently guards.
 *  4. **The parallel config carries no report targets.** phpunit-parallel.xml without a
 *     stripped <coverage> block means every child clobbers coverage.xml — the merge step
 *     would be overwriting itself.
 *
 * The step's collision sentinel ({@see STEP_TOKEN}) lives HERE, in code, deliberately:
 * it must never appear in prose (CHANGELOG/README/PROGRESS), so a stray duplicate copy
 * of this work is detectable by grep alone.
 */
final class ParallelTestWiringTest extends TestCase
{
    public const STEP_TOKEN = 'CS457PARAUNITX9C';

    private const REPO = __DIR__ . '/../../..';

    private const WORKFLOW = self::REPO . '/.github/workflows/phpunit.yml';

    private const PARALLEL_CONFIG = self::REPO . '/phpunit-parallel.xml';

    private const SERIAL_CONFIG = self::REPO . '/phpunit.xml';

    /**
     * The exact classes that must be excluded from the parallel Unit lane and re-hosted in the
     * Serialized suite. A literal on purpose (this repo's "derived-from-subject self-adjusts"
     * doctrine): adding or dropping a member is an explicit, reviewable edit, not a silent set drift.
     *
     * @var list<string>
     */
    private const SERIALIZED_CLASSES = [
        'tests/Unit/Discovery/Mdns/MdnsMulticastJoinTest.php',
        'tests/Unit/Discovery/Ssdp/SsdpMulticastJoinTest.php',
        'tests/Unit/Dlna/SsdpMSearchListenerTest.php',
    ];

    private const RUN_SUITE = self::REPO . '/scripts/parallel/run-suite.sh';

    private const SHIM = self::REPO . '/scripts/parallel/php';

    private const MERGE_JUNIT = self::REPO . '/scripts/parallel/merge-junit.php';

    private const MERGE_COVERAGE = self::REPO . '/scripts/parallel/merge-coverage.php';

    private const JUNIT_TOKEN = '--log-junit=.phpunit-junit/junit-__PHLIX_WORKER__.xml';

    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_dir($path)) {
                exec('rm -rf ' . escapeshellarg($path));
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }

        $this->tempPaths = [];

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // 0 — the collision sentinel is exactly one occurrence, in this file.
    // -----------------------------------------------------------------------

    public function testTheStepTokenExistsInCodeExactlyOnceAndInNoProse(): void
    {
        $needle = self::STEP_TOKEN;
        $this->assertSame(16, strlen($needle), 'the sentinel has an exact length; a typo changes the collision check');

        $hits = [];

        foreach ($this->trackedFiles() as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                $this->fail('cannot read ' . $file);
            }

            $count = substr_count($contents, $needle);

            if ($count > 0) {
                $hits[$this->relative($file)] = $count;
            }
        }

        $this->assertSame(
            ['tests/Unit/Support/ParallelTestWiringTest.php' => 1],
            $hits,
            'The S457 sentinel must reside in code — in exactly this file, exactly once — and'
            . ' in no markdown, workflow, or progress document anywhere. A second occurrence means'
            . ' a second copy of this work exists.',
        );

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{16}$/', $needle);
    }

    // -----------------------------------------------------------------------
    // 1 — phpunit-parallel.xml is report-target-free and fully wired.
    // -----------------------------------------------------------------------

    public function testTheParallelConfigCarriesNoCoverageReportTargets(): void
    {
        $document = $this->parseXml(self::PARALLEL_CONFIG);

        $this->assertSame(
            0,
            $document->getElementsByTagName('coverage')->length,
            'Every paraunit child shares the repo root as CWD. An inherited <coverage><report>'
            . ' makes each child write coverage.xml/coverage-report/ and the stdout text stream —'
            . ' a last-writer-wins clobber of the artifact the merge step owns. Strip the block:'
            . ' scripts/parallel/merge-coverage.php is the only writer of coverage.xml now.',
        );

        $bootstraps = [];

        foreach ($document->getElementsByTagName('bootstrap') as $bootstrap) {
            $bootstraps[] = $bootstrap->getAttribute('class');
        }

        foreach (
            [
            'Phlix\Tests\Support\AssertionEscape\AssertionEscapeGuardExtension',
            'Phlix\Tests\Support\ResidueCensus\ZeroResidueCensusExtension',
            'Paraunit\Configuration\ParaunitExtension',
            ] as $required
        ) {
            $this->assertContains(
                $required,
                $bootstraps,
                'the parallel config dropping an extension registration is how a guard silently'
                . ' stops existing on exactly the path CI actually runs.',
            );
        }
    }

    // -----------------------------------------------------------------------
    // 2 — the workflow shape: order, literals, agreement between steps.
    // -----------------------------------------------------------------------

    public function testTheTestJobRunsThreeLanesInOrderAndMergesBeforeTheGates(): void
    {
        $steps = $this->testJobSteps();

        $indexOf = static function (string $needle) use ($steps): int {
            foreach ($steps as $index => $step) {
                if (is_string($step['run'] ?? null) && str_contains((string) $step['run'], $needle)) {
                    return $index;
                }
            }

            return -1;
        };

        $clone = $indexOf('scripts/parallel-test-db.sh create');
        $unit = $indexOf('run-suite.sh Unit 8 --coverage coverage-unit.covobj');
        $integration = $indexOf('run-suite.sh Integration 8 8 --coverage coverage-integration.covobj');
        $e2e = $indexOf('--testsuite E2E');
        $merge = $indexOf('scripts/parallel/merge-coverage.php');
        $gate = $indexOf('scripts/assert-browser-e2e-ran.php');

        // A found step is a valid list offset; -1 (not found) must never reach an index. Fail
        // fast on the missing step so the ordering assertions below compare real positions.
        $stepAt = static function (int $index, string $label) use ($steps): array {
            if ($index < 0) {
                throw new \RuntimeException("phpunit.yml lost the {$label} step.");
            }

            /** @var array<string, mixed> $step */
            $step = $steps[$index];

            return $step;
        };

        $labels = ['clone shards' => $clone, 'unit' => $unit, 'integration' => $integration,
                   'e2e tail' => $e2e, 'merge' => $merge, 'browser gate' => $gate];

        foreach ($labels as $label => $index) {
            $this->assertGreaterThanOrEqual(0, $index, "phpunit.yml lost the {$label} step.");
        }

        $this->assertLessThan($unit, $clone, 'shard schemas must exist before Integration claims them');
        $this->assertLessThan($integration, $unit, 'the JUnit corpus wipe happens in the Unit step; ordering is the contract');
        $this->assertLessThan($e2e, $integration, 'E2E tail is SERIAL — after the parallel suites');
        $this->assertLessThan($merge, $e2e, 'merge consumes the E2E tail artifacts');
        $this->assertLessThan($gate, $merge, 'the S305 gate must read MERGED junit.xml, never a per-worker fragment');

        $this->assertSame(
            '8',
            $stepAt($clone, 'clone shards')['env']['PHLIX_TEST_DB_SHARDS'] ?? null,
            'run-suite.sh refuses Integration when shards < parallel; the clone literal and the'
            . ' Integration step literal (8) must move together.',
        );

        $e2eRun = (string) $stepAt($e2e, 'e2e tail')['run'];

        $this->assertStringContainsString('--configuration=phpunit-parallel.xml', $e2eRun);
        $this->assertStringContainsString('--log-junit .phpunit-junit/junit-e2e.xml', $e2eRun);
    }

    public function testTheVendorPhpunitInvocationSitesStayAtTheCountTheCensusDocuments(): void
    {
        $count = 0;

        foreach ($this->allJobSteps() as $step) {
            $run = $step['run'] ?? null;

            if (!is_string($run)) {
                continue;
            }

            foreach (explode("\n", $run) as $line) {
                if (str_contains($line, 'vendor/bin/phpunit')) {
                    $count++;
                }
            }
        }

        $this->assertSame(
            2,
            $count,
            'scripts/skipped-test-names.sh documents phpunit.yml as carrying TWO invocation sites'
            . ' (the E2E serial tail and Run Server-specific tests); parallel children run'
            . ' vendor/bin/paraunit and are not counted. A third literal site — or one fewer —'
            . ' must update that header and the census in the same commit.',
        );
    }

    // -----------------------------------------------------------------------
    // 3 — run-suite.sh keeps its seams.
    // -----------------------------------------------------------------------

    public function testTheRunSuiteWrapperCarriesEveryIsolationSeam(): void
    {
        $script = (string) file_get_contents(self::RUN_SUITE);

        foreach (
            [
            'PATH="$repo_root/scripts/parallel:$PATH"' => 'per-worker TMPDIR needs the shim on PATH',
            '--pass-through=--do-not-cache-result' => 'children must not race on .phpunit.cache',
            self::JUNIT_TOKEN => 'per-child JUnit evidence',
            'shards' => 'Integration shard claiming',
            '--php=' => 'coverage mode emits the merged php-code-coverage object',
            'files with (ERRORS|FAILURES)' => 'paraunit may exit 0 around recorded ERRORS/FAILURES; the wrapper must not',
            ] as $needle => $why
        ) {
            $this->assertStringContainsString($needle, $script, "run-suite.sh lost a seam ({$why}).");
        }

        // The 'serial tail step' refusal and the 'shards' guard are EXECUTED in
        // testTheRunSuiteWrapperRefusesE2EAndUnderShardedIntegration — greps for those words
        // would stay green even after someone deleted the exit-fast branches, so they are not
        // asserted here.
    }

    public function testTheRunSuiteWrapperRefusesE2EAndUnderShardedIntegration(): void
    {
        // Both refusals exit before any side effect, so invoking the wrapper directly is safe.
        $e2e = $this->runBash(self::RUN_SUITE, ['E2E', '8']);
        $this->assertSame(2, $e2e['exit'], 'E2E must be refused (serial tail only): ' . $e2e['output']);
        $this->assertStringContainsString('serial tail step', $e2e['output']);

        // parallel=8, shards=2 -> the "shards >= parallel" branch must refuse before the MySQL probe.
        $undersharded = $this->runBash(self::RUN_SUITE, ['Integration', '8', '2']);
        $this->assertSame(2, $undersharded['exit'], 'Integration must refuse when shards < parallel: ' . $undersharded['output']);
        $this->assertStringContainsString('must be >=', $undersharded['output']);
    }

    // -----------------------------------------------------------------------
    // 4 — the PATH shim: expand with a chunk id, inert without one. Executed.
    // -----------------------------------------------------------------------

    public function testTheShimExpandsTheWorkerTokenOnlyForParallelChildren(): void
    {
        $scratch = $this->scratchDir();

        $parallel = $this->runShim($scratch, [
            'PARAUNIT_PROCESS_UNIQUE_ID' => 'cafe1234',
        ], ['-r', 'echo getenv("TMPDIR"), "\n", $argv[1] ?? "";', '--', 'junit-__PHLIX_WORKER__.xml']);

        $this->assertSame(0, $parallel['exit'], $parallel['output']);
        $this->assertStringContainsString($scratch . '/worker-cafe1234', $parallel['output']);
        $this->assertStringContainsString('junit-cafe1234.xml', $parallel['output']);
        $this->assertDirectoryExists($scratch . '/worker-cafe1234');

        $workersBefore = glob($scratch . '/worker-*') ?: [];

        $serial = $this->runShim($scratch, ['TMPDIR' => $scratch . '/inherited-tmp'], [
            '-r', 'echo getenv("TMPDIR"), "\n", $argv[1] ?? "";', '--', 'junit-__PHLIX_WORKER__.xml',
        ]);

        $this->assertSame(0, $serial['exit'], $serial['output']);
        $this->assertStringContainsString(
            'junit-__PHLIX_WORKER__.xml',
            $serial['output'],
            'without a chunk id the seam must be inert — a developer running plain `php` through'
            . ' this PATH entry (or paraunit itself as parent) must see their argv untouched.',
        );
        // argv inertness is not enough: an unconditional TMPDIR rewrite would send the
        // paraunit PARENT (and any plain `php`) into a `worker-$$` dir and litter the base
        // root with empty dirs. TMPDIR must be byte-identical to whatever was inherited.
        $this->assertStringContainsString(
            $scratch . '/inherited-tmp',
            $serial['output'],
            'without a chunk id the shim must leave TMPDIR exactly as inherited, not mint a worker dir.',
        );
        $this->assertDirectoryDoesNotExist(
            $scratch . '/worker-' . getmypid(),
            'the inert path must not create a worker temp directory.',
        );
        $this->assertSame(
            $workersBefore,
            glob($scratch . '/worker-*') ?: [],
            'with no chunk id the shim must create no new worker-* directory under the base.',
        );
    }

    // -----------------------------------------------------------------------
    // 5 — merge-junit.php: sums exact, duplicates fatal. Executed.
    // -----------------------------------------------------------------------

    public function testMergeJUnitSumsRootCountersAndRejectsDuplicateSuites(): void
    {
        $dir = $this->scratchDir();

        file_put_contents($dir . '/junit-a.xml', $this->junitFixture('SuiteA', 2, 3, 0, 1, 1, 0.5));
        file_put_contents($dir . '/junit-b.xml', $this->junitFixture('SuiteB', 1, 1, 1, 0, 0, 0.25));

        $out = $dir . '/merged.xml';
        $result = $this->runPhp(self::MERGE_JUNIT, [$dir, $out]);

        $this->assertSame(0, $result['exit'], $result['output']);

        $document = $this->parseXml($out);
        $root = $document->documentElement;

        $this->assertSame('testsuites', $root->tagName);
        $this->assertSame('3', $root->getAttribute('tests'));
        $this->assertSame('4', $root->getAttribute('assertions'));
        $this->assertSame('1', $root->getAttribute('errors'));
        $this->assertSame('1', $root->getAttribute('failures'));
        $this->assertSame('1', $root->getAttribute('skipped'));
        $this->assertSame(2, $document->getElementsByTagName('testsuite')->length);

        file_put_contents($dir . '/junit-c.xml', $this->junitFixture('SuiteA', 1, 1, 0, 0, 0, 0.1));
        $duplicate = $this->runPhp(self::MERGE_JUNIT, [$dir, $out]);

        $this->assertSame(1, $duplicate['exit'], 'the same class in two worker files means double-scheduling — fail loudly');
        $this->assertStringContainsString('SuiteA', $duplicate['output']);
    }

    // -----------------------------------------------------------------------
    // 6 — merge-coverage.php refuses everything it cannot trust. Executed.
    // -----------------------------------------------------------------------

    public function testMergeCoverageFailsLoudlyOnEveryBadInput(): void
    {
        $dir = $this->scratchDir();
        $garbage = $dir . '/garbage.php';

        file_put_contents($garbage, 'not a serialized CodeCoverage');

        $missing = $this->runPhp(self::MERGE_COVERAGE, [$dir . '/out.xml', $dir . '/absent.php']);
        $this->assertSame(1, $missing['exit'], $missing['output']);
        $this->assertStringContainsString('absent.php', $missing['output']);

        $garbageRun = $this->runPhp(self::MERGE_COVERAGE, [$dir . '/out.xml', $garbage]);
        $this->assertSame(1, $garbageRun['exit'], $garbageRun['output']);
        $this->assertStringContainsString('does not return a php-code-coverage object', $garbageRun['output']);

        $usage = $this->runPhp(self::MERGE_COVERAGE, []);
        $this->assertSame(2, $usage['exit'], $usage['output']);

        $this->assertFileDoesNotExist($dir . '/out.xml', 'a refused merge must leave no half-written coverage.xml');
    }

    // -----------------------------------------------------------------------
    // 7 — the Serialized suite and the Unit exclusions must be IDENTICAL and exact.
    //     This is the "runs exactly once" invariant merge-junit cannot see (it only
    //     catches the >1 side; a class in neither lane runs 0 times and CI stays green).
    // -----------------------------------------------------------------------

    public function testEveryExcludedClassIsReScheduledInSerializedAndViceVersa(): void
    {
        $document = $this->parseXml(self::PARALLEL_CONFIG);

        $unitExcludes = [];
        $serializedFiles = [];
        $suiteNames = [];

        foreach ($document->getElementsByTagName('testsuite') as $suite) {
            if (!$suite instanceof DOMElement) {
                continue;
            }

            $name = $suite->getAttribute('name');
            $suiteNames[] = $name;

            foreach ($suite->getElementsByTagName('exclude') as $exclude) {
                $unitExcludes[] = trim($exclude->textContent);
            }

            if ($name === 'Serialized') {
                foreach ($suite->getElementsByTagName('file') as $file) {
                    $serializedFiles[] = trim($file->textContent);
                }
            }
        }

        $expected = self::SERIALIZED_CLASSES;
        sort($expected);
        sort($unitExcludes);
        sort($serializedFiles);

        $this->assertSame(
            $expected,
            $unitExcludes,
            'the parallel Unit lane must exclude EXACTLY the multicast-receive classes — one more'
            . ' silently loses coverage, one less re-introduces the 8-way contention that reddened CI.',
        );
        $this->assertSame(
            $expected,
            $serializedFiles,
            'the Serialized suite must carry EXACTLY the classes excluded from Unit. An excluded class'
            . ' with no Serialized entry runs ZERO times in CI and the job still passes.',
        );
        $this->assertSame(
            $unitExcludes,
            $serializedFiles,
            'Unit <exclude> and Serialized <file> must be the same set — the only guard against a class'
            . ' running 0 times (dropped from both) is that neither list can drift from the other.',
        );

        foreach ($serializedFiles as $relative) {
            $this->assertStringEndsWith('Test.php', $relative, 'a Serialized member must be a test file');
            $this->assertFileExists(self::REPO . '/' . $relative, $relative . ' is listed but does not exist');
        }

        // The developer's SERIAL config must still run all three inside Unit: it carries no
        // exclusion for them and no Serialized suite. This is the only machine check that a
        // plain `vendor/bin/phpunit` still exercises each multicast class exactly once.
        $serial = $this->parseXml(self::SERIAL_CONFIG);
        $serialExcludes = [];
        $serialSuiteNames = [];

        foreach ($serial->getElementsByTagName('testsuite') as $suite) {
            if (!$suite instanceof DOMElement) {
                continue;
            }

            $serialSuiteNames[] = $suite->getAttribute('name');

            foreach ($suite->getElementsByTagName('exclude') as $exclude) {
                $serialExcludes[] = trim($exclude->textContent);
            }
        }

        $this->assertNotContains('Serialized', $serialSuiteNames, 'phpunit.xml must not host the Serialized suite');
        $this->assertSame(
            [],
            array_values(array_intersect($expected, $serialExcludes)),
            'phpunit.xml must NOT exclude the multicast classes — that exclusion belongs only to the'
            . ' parallel config; a serial developer run must still cover each class inside Unit.',
        );
    }

    // -----------------------------------------------------------------------
    // 8 — merge-junit refuses a collapsed seam. Executed (MAJOR-2a).
    //     If the __PHLIX_WORKER__ token never expands, all children clobber ONE file;
    //     the duplicate-class check above cannot fire (only one file exists), so the
    //     merge would publish ~1/8 of the evidence under a green S305 gate.
    // -----------------------------------------------------------------------

    public function testMergeJUnitRefusesAnUnexpandedWorkerToken(): void
    {
        $dir = $this->scratchDir();

        file_put_contents($dir . '/junit-__PHLIX_WORKER__.xml', $this->junitFixture('CollapsedSuite', 2, 2, 0, 0, 0, 0.1));

        $result = $this->runPhp(self::MERGE_JUNIT, [$dir, $dir . '/merged.xml']);

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString('__PHLIX_WORKER__', $result['output']);
        $this->assertFileDoesNotExist($dir . '/merged.xml', 'a collapsed seam must not produce merged evidence');
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function testJobSteps(): array
    {
        $workflow = Yaml::parseFile(self::WORKFLOW);
        $jobs = is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];
        $steps = $jobs['test']['steps'] ?? null;

        $this->assertIsArray($steps, 'phpunit.yml lost its `test` job');

        /** @var list<array<string, mixed>> $steps */
        return $steps;
    }

    /** @return list<array<string, mixed>> */
    private function allJobSteps(): array
    {
        $workflow = Yaml::parseFile(self::WORKFLOW);
        $jobs = is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];

        $out = [];

        foreach ($jobs as $job) {
            if (!is_array($job) || !is_array($job['steps'] ?? null)) {
                continue;
            }

            foreach ($job['steps'] as $step) {
                if (is_array($step)) {
                    $out[] = $step;
                }
            }
        }

        return $out;
    }

    private function junitFixture(
        string $class,
        int $tests,
        int $assertions,
        int $errors,
        int $failures,
        int $skipped,
        float $time,
    ): string {
        $testcases = '';

        for ($i = 0; $i < $tests; $i++) {
            $child = match (true) {
                $i < $errors => '<error message="boom"/>',
                $i < $errors + $failures => '<failure message="nope"/>',
                $i < $errors + $failures + $skipped => '<skipped message="later"/>',
                default => '',
            };

            $testcases .= $child === ''
                ? sprintf('<testcase class="%s" name="case%d" time="0.01"/>', $class, $i)
                : sprintf('<testcase class="%s" name="case%d" time="0.01">%s</testcase>', $class, $i, $child);
        }

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><testsuites>'
            . '<testsuite name="%s" tests="%d" assertions="%d" errors="%d" failures="%d" skipped="%d" time="%.2F">%s</testsuite>'
            . '</testsuites>',
            $class,
            $tests,
            $assertions,
            $errors,
            $failures,
            $skipped,
            $time,
            $testcases,
        );
    }

    /** @param array<string, string> $env @param list<string> $phpArgs */
    private function runShim(string $tmpBase, array $env, array $phpArgs): array
    {
        $command = [self::SHIM, ...$phpArgs];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            self::REPO,
            [
                'PATH' => getenv('PATH') ?: '',
                'PHLIX_REAL_PHP' => PHP_BINARY,
                'PHLIX_TEST_TMP_BASE' => $tmpBase,
                ...$env,
            ],
        );

        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'output' => $stdout . $stderr];
    }

    /** @param list<string> $args */
    private function runPhp(string $script, array $args): array
    {
        $command = array_merge([PHP_BINARY, $script], $args);
        $output = [];
        $exit = 0;

        exec(
            implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1',
            $output,
            $exit,
        );

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }

    /**
     * Invoke a bash script with argv and capture combined output + exit code. Used to EXECUTE
     * run-suite.sh's guard rails (both refuse before any side effect) rather than grep for them.
     *
     * @param list<string> $args
     *
     * @return array{exit:int, output:string}
     */
    private function runBash(string $script, array $args): array
    {
        $command = array_merge(['/usr/bin/env', 'bash', $script], $args);
        $output = [];
        $exit = 0;

        exec(
            implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1',
            $output,
            $exit,
        );

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }

    private function parseXml(string $path): DOMDocument
    {
        $document = new DOMDocument();

        $this->assertTrue(@$document->load($path), $path . ' must parse');

        return $document;
    }

    private function scratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/phlix-parallel-wiring-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempPaths[] = $dir;

        return $dir;
    }

    /** @return iterable<string> */
    private function trackedFiles(): iterable
    {
        $skipDirs = ['vendor', 'node_modules', 'coverage-report', '.phpunit.cache', '.git'];
        $allowed = ['php', 'md', 'yml', 'yaml', 'xml', 'json', 'sh', 'js', 'ts', 'txt'];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::REPO, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            foreach ($skipDirs as $skip) {
                if (str_contains($path, DIRECTORY_SEPARATOR . $skip . DIRECTORY_SEPARATOR)) {
                    continue 2;
                }
            }

            if (!in_array(strtolower($file->getExtension()), $allowed, true)) {
                continue;
            }

            yield $path;
        }
    }

    private function relative(string $absolute): string
    {
        $root = realpath(self::REPO);
        $real = realpath($absolute);

        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return $absolute;
        }

        return substr($real, strlen($root) + 1);
    }
}
