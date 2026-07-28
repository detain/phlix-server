<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * The coverage gate must fail when it cannot measure — never skip.
 *
 * ## What went wrong
 *
 * `.github/workflows/phpunit.yml`'s "Check minimum coverage threshold" step
 * shelled out to `xmllint`, which is not installed on `ubuntu-latest`. The
 * failure was hidden by `2>/dev/null`, converted to the string `0` by
 * `|| echo "0"`, read as "empty report" by the very next line, and rewarded with
 * `exit 0`. `MIN_COVERAGE=40` never gated a single PR or push.
 *
 * The XPath was fine. {@see testCloverWriterStillEmitsTheAttributesTheGateReads()}
 * pins that fact to the installed php-code-coverage source so nobody "fixes" it
 * again on a hunch.
 *
 * ## What these tests are actually protecting
 *
 * Not the arithmetic — that part always worked. What is worth guarding is the
 * *failure direction*. Every way the gate can fail to measure must exit
 * non-zero:
 *
 * | condition                     | old behaviour  | required behaviour |
 * | ----------------------------- | -------------- | ------------------ |
 * | parser missing                | skip, exit 0   | **exit 1**         |
 * | report missing                | skip, exit 0   | **exit 1**         |
 * | report unparseable            | skip, exit 0   | **exit 1**         |
 * | metric absent or non-numeric  | skip, exit 0   | **exit 1**         |
 * | `statements="0"`              | skip, exit 0   | **exit 1**         |
 *
 * {@see testZeroStatementReportFailsInsteadOfSkipping()} is the direct
 * regression test for the defect: that row is the one the old step got
 * backwards, and every other row funnelled into it.
 *
 * This mirrors S146, which found the Psalm job reporting GREEN having analysed
 * zero files for the same reason — a missing tool behind a conditional that
 * treated absence as success.
 */
final class CoverageThresholdCheckTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../scripts/coverage-threshold-check.php';

    private const WORKFLOW = __DIR__ . '/../../../.github/workflows/phpunit.yml';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // The happy path still works.
    // -----------------------------------------------------------------------

    public function testPassesWhenCoverageMeetsTheFloor(): void
    {
        $report = $this->writeClover('statements="100" coveredstatements="65"');

        $result = $this->runCheck($report, ['MIN_COVERAGE' => '40']);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('Statement coverage: 65.00% (65 / 100)', $result['output']);
        $this->assertStringContainsString('Coverage check passed.', $result['output']);
    }

    public function testUsesTheDefaultFloorWhenMinCoverageIsUnset(): void
    {
        $report = $this->writeClover('statements="100" coveredstatements="41"');

        $result = $this->runCheck($report, ['MIN_COVERAGE' => '']);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('Minimum required:   40%', $result['output']);
    }

    public function testFailsWhenCoverageIsBelowTheFloor(): void
    {
        $report = $this->writeClover('statements="100" coveredstatements="39"');

        $result = $this->runCheck($report, ['MIN_COVERAGE' => '40']);

        $this->assertSame(1, $result['exit'], 'Coverage below the floor must fail the step.');
        $this->assertStringContainsString('::error::', $result['output']);
        $this->assertStringContainsString('is below minimum', $result['output']);
    }

    // -----------------------------------------------------------------------
    // Every "cannot measure" path is LOUD.
    // -----------------------------------------------------------------------

    /**
     * The defect, stated as a test.
     *
     * A report with `statements="0"` means the run measured nothing. The old
     * step printed "WARNING: no statements found in coverage.xml — skipping
     * threshold check" and exited 0, which is how an unenforced gate looked
     * green for the life of the repo.
     */
    public function testZeroStatementReportFailsInsteadOfSkipping(): void
    {
        $report = $this->writeClover('statements="0" coveredstatements="0"');

        $result = $this->runCheck($report, ['MIN_COVERAGE' => '40']);

        $this->assertSame(1, $result['exit'], 'A zero-statement report is BROKEN and must fail, not skip.');
        $this->assertStringContainsString('::error::', $result['output']);
        $this->assertStringContainsString('0 statements', $result['output']);
        $this->assertStringNotContainsString('skipping threshold check', $result['output']);
    }

    public function testMissingReportFails(): void
    {
        $result = $this->runCheck(sys_get_temp_dir() . '/phlix-coverage-does-not-exist.xml');

        $this->assertSame(1, $result['exit'], 'A missing report must fail the step.');
        $this->assertStringContainsString('does not exist', $result['output']);
    }

    public function testEmptyReportFails(): void
    {
        $report = $this->tempPath();
        file_put_contents($report, '');

        $result = $this->runCheck($report);

        $this->assertSame(1, $result['exit'], 'An empty report must fail the step.');
        $this->assertStringContainsString('::error::', $result['output']);
    }

    public function testUnparseableXmlFails(): void
    {
        $report = $this->tempPath();
        file_put_contents($report, "<coverage><project><metrics statements=\"100\"\n");

        $result = $this->runCheck($report);

        $this->assertSame(1, $result['exit'], 'Malformed XML must fail the step.');
        $this->assertStringContainsString('not parseable XML', $result['output']);
    }

    public function testReportWithoutProjectMetricsFails(): void
    {
        $report = $this->tempPath();
        file_put_contents($report, '<?xml version="1.0"?><coverage><project timestamp="1"/></coverage>');

        $result = $this->runCheck($report);

        $this->assertSame(1, $result['exit'], 'A report missing /coverage/project/metrics must fail.');
        $this->assertStringContainsString('has no statements', $result['output']);
    }

    /**
     * Cobertura is the shape people reach for when "fixing" this gate. It has
     * `line-rate`, not `statements`, and must be rejected rather than read as 0.
     */
    public function testCoberturaShapedReportFails(): void
    {
        $report = $this->tempPath();
        file_put_contents($report, '<?xml version="1.0"?><coverage line-rate="0.65"><packages/></coverage>');

        $result = $this->runCheck($report);

        $this->assertSame(1, $result['exit'], 'A Cobertura report is not Clover and must fail loudly.');
        $this->assertStringContainsString('::error::', $result['output']);
    }

    public function testNonNumericStatementsFails(): void
    {
        $report = $this->writeClover('statements="N/A" coveredstatements="65"');

        $result = $this->runCheck($report);

        $this->assertSame(1, $result['exit'], 'A non-numeric metric must fail the step.');
        $this->assertStringContainsString('not a non-negative integer', $result['output']);
    }

    public function testInconsistentReportFails(): void
    {
        $report = $this->writeClover('statements="100" coveredstatements="101"');

        $result = $this->runCheck($report);

        $this->assertSame(1, $result['exit'], 'coveredstatements > statements is not a report we can trust.');
        $this->assertStringContainsString('internally inconsistent', $result['output']);
    }

    public function testNonNumericMinCoverageFails(): void
    {
        $report = $this->writeClover('statements="100" coveredstatements="65"');

        $result = $this->runCheck($report, ['MIN_COVERAGE' => 'forty']);

        $this->assertSame(1, $result['exit'], 'A malformed threshold must fail rather than silently default.');
        $this->assertStringContainsString('which is not a number', $result['output']);
    }

    // -----------------------------------------------------------------------
    // Guards against the defect being reintroduced.
    // -----------------------------------------------------------------------

    /**
     * The XPath was never wrong — pin that to the installed library so a future
     * php-code-coverage upgrade that renames the attributes fails here, in a
     * test, rather than silently in CI.
     *
     * @see vendor/phpunit/php-code-coverage/src/Report/Clover.php
     */
    public function testCloverWriterStillEmitsTheAttributesTheGateReads(): void
    {
        $clover = __DIR__ . '/../../../vendor/phpunit/php-code-coverage/src/Report/Clover.php';

        if (!is_file($clover)) {
            $this->markTestSkipped('phpunit/php-code-coverage is not installed.');
        }

        $source = (string) file_get_contents($clover);

        $this->assertStringContainsString("createElement('project')", $source);
        $this->assertStringContainsString("createElement('metrics')", $source);
        $this->assertStringContainsString("setAttribute('statements'", $source);
        $this->assertStringContainsString("setAttribute('coveredstatements'", $source);
    }

    /**
     * The workflow must not reacquire a dependency the runner does not have, and
     * must not reacquire a silent-skip branch.
     *
     * Comments are stripped first, and deliberately so. Both the workflow step
     * and the checker script *quote* the broken original in order to explain why
     * it was broken, and a guard that fires on its own documentation is a
     * false positive — which, per the reasoning in
     * {@see IntegrationDbGuardAdoptionTest}, costs more than an escape, because
     * a noisy check gets deleted and then nothing is caught at all.
     */
    public function testWorkflowDoesNotParseCoverageWithUninstalledTools(): void
    {
        $yaml = $this->stripYamlComments((string) file_get_contents(self::WORKFLOW));

        $this->assertStringNotContainsString(
            'xmllint',
            $yaml,
            'xmllint is NOT installed on ubuntu-latest (libxml2-utils is absent from the '
            . 'Ubuntu 24.04 image manifest). Parse the Clover report with PHP instead.',
        );
        $this->assertStringNotContainsString(
            '| bc',
            $yaml,
            'bc is also absent from the Ubuntu 24.04 runner image manifest. Do the arithmetic in PHP.',
        );
        $this->assertStringNotContainsString(
            'skipping threshold check',
            $yaml,
            'The silent-skip branch is the whole defect. A gate that cannot measure must fail.',
        );
        $this->assertStringContainsString('scripts/coverage-threshold-check.php', $yaml);
        $this->assertStringContainsString('MIN_COVERAGE', $yaml);
    }

    /**
     * No error-swallowing constructs in the checker itself.
     *
     * Comments stripped for the same reason as above: the script's header quotes
     * the original `xmllint ... 2>/dev/null || echo "0"` verbatim so the defect
     * is legible to the next reader.
     */
    public function testCheckerHasNoSilentFallback(): void
    {
        $source = $this->stripPhpComments((string) file_get_contents(self::SCRIPT));

        $this->assertStringNotContainsString('2>/dev/null', $source);
        $this->assertStringNotContainsString('|| echo', $source);
        $this->assertSame(
            1,
            substr_count($source, 'exit(0);'),
            'The only exit(0) in the checker must be the single success path at the end.',
        );
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /**
     * Write a minimal report in the exact shape php-code-coverage emits: the
     * project-level <metrics> is a direct child of <project>, whether or not the
     * files above it are wrapped in <package> elements.
     */
    private function writeClover(string $metricAttributes): string
    {
        $path = $this->tempPath();

        file_put_contents($path, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<coverage generated="1785240767">'
            . '<project timestamp="1785240767">'
            . '<metrics files="1" loc="10" ncloc="8" classes="1" methods="2" coveredmethods="1" '
            . 'conditionals="0" coveredconditionals="0" %s elements="102" coveredelements="66"/>'
            . '</project></coverage>',
            $metricAttributes,
        ));

        return $path;
    }

    /**
     * Drop whole-line `#` comments — the YAML step comments and the shell
     * comments inside `run:` blocks. A genuine reintroduction of the broken
     * parse would be executable code, not a commented line.
     */
    private function stripYamlComments(string $yaml): string
    {
        $kept = array_filter(
            explode("\n", $yaml),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '#'),
        );

        return implode("\n", $kept);
    }

    private function stripPhpComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private function tempPath(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'phlix-clover-');

        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{exit: int, output: string}
     */
    private function runCheck(string $reportPath, array $env = []): array
    {
        $command = '';

        foreach ($env as $name => $value) {
            $command .= $name . '=' . escapeshellarg($value) . ' ';
        }

        $command .= escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(self::SCRIPT)
            . ' ' . escapeshellarg($reportPath)
            . ' 2>&1';

        $output   = [];
        $exitCode = 0;

        exec($command, $output, $exitCode);

        return ['exit' => $exitCode, 'output' => implode("\n", $output)];
    }
}
