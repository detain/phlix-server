<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use DOMDocument;
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
            . ' a last-writer-wins clobber of the artifact the merge step owns. Strip the block:',
            'scripts/parallel/merge-coverage.php is the only writer of coverage.xml now.',
        );

        $bootstraps = [];

        foreach ($document->getElementsByTagName('bootstrap') as $bootstrap) {
            $bootstraps[] = $bootstrap->getAttribute('class');
        }

        foreach ([
            'Phlix\Tests\Support\AssertionEscape\AssertionEscapeGuardExtension',
            'Phlix\Tests\Support\ResidueCensus\ZeroResidueCensusExtension',
            'Paraunit\Configuration\ParaunitExtension',
        ] as $required) {
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

        foreach (['clone shards' => $clone, 'unit' => $unit, 'integration' => $integration,
                  'e2e tail' => $e2e, 'merge' => $merge, 'browser gate' => $gate] as $label => $index) {
            $this->assertGreaterThanOrEqual(0, $index, "phpunit.yml lost the {$label} step.");
        }

        $this->assertLessThan($unit, $clone, 'shard schemas must exist before Integration claims them');
        $this->assertLessThan($integration, $unit, 'the JUnit corpus wipe happens in the Unit step; ordering is the contract');
        $this->assertLessThan($e2e, $integration, 'E2E tail is SERIAL — after the parallel suites');
        $this->assertLessThan($merge, $e2e, 'merge consumes the E2E tail artifacts');
        $this->assertLessThan($gate, $merge, 'the S305 gate must read MERGED junit.xml, never a per-worker fragment');

        $this->assertSame(
            '8',
            $steps[$clone]['env']['PHLIX_TEST_DB_SHARDS'] ?? null,
            'run-suite.sh refuses Integration when shards < parallel; the clone literal and the'
            . ' Integration step literal (8) must move together.',
        );

        $this->assertStringContainsString('--configuration=phpunit-parallel.xml', (string) $steps[$e2e]['run']);
        $this->assertStringContainsString('--log-junit .phpunit-junit/junit-e2e.xml', (string) $steps[$e2e]['run']);
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

        foreach ([
            'PATH="$repo_root/scripts/parallel:$PATH"' => 'per-worker TMPDIR needs the shim on PATH',
            '--pass-through=--do-not-cache-result' => 'children must not race on .phpunit.cache',
            self::JUNIT_TOKEN => 'per-child JUnit evidence',
            'shards' => 'Integration shard claiming',
            '--php=' => 'coverage mode emits the merged php-code-coverage object',
            'files with (ERRORS|FAILURES)' => 'paraunit may exit 0 around recorded ERRORS/FAILURES; the wrapper must not',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $script, "run-suite.sh lost a seam ({$why}).");
        }

        $this->assertStringContainsString(
            'serial tail step',
            $script,
            'the wrapper must REFUSE to parallelize E2E (hardware-encoder contention flakes HwaccelE2ETest).',
        );
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

        $serial = $this->runShim($scratch, [], ['-r', 'echo $argv[1] ?? "";', '--', 'junit-__PHLIX_WORKER__.xml']);

        $this->assertSame(0, $serial['exit'], $serial['output']);
        $this->assertStringContainsString(
            'junit-__PHLIX_WORKER__.xml',
            $serial['output'],
            'without a chunk id the seam must be inert — a developer running plain `php` through'
            . ' this PATH entry (or paraunit itself as parent) must see their argv untouched.',
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
