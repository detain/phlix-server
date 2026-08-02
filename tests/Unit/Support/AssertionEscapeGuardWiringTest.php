<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\Extension;
use Symfony\Component\Yaml\Yaml;

/**
 * S120's guard must not be one deletable line away from silent.
 *
 * ## The hole this closes, measured rather than imagined
 *
 * S120 ships a *runtime* guard: `AssertionEscapeGuardExtension` (registered from
 * `phpunit.xml`) writes `.phpunit-assertion-escapes.json` when an assertion failure
 * did not decide its test's outcome, and `scripts/assertion-escape-check.php` turns
 * that report into a non-zero exit in CI. Both halves work. What did NOT work is that
 * nothing noticed when a half went missing:
 *
 * | mutation                                            | before this test        |
 * | --------------------------------------------------- | ----------------------- |
 * | delete the `<bootstrap>` line from `phpunit.xml`      | **completely silent**   |
 * | delete `scripts/assertion-escape-check.php`           | loud (php exits 1)      |
 * | delete `tests/Support/AssertionEscape/…Extension.php` | loud (php exits 1)      |
 * | delete the workflow step that runs the check          | **completely silent**   |
 *
 * The two silent rows were verified by execution on 2026-08-02: with the
 * `<bootstrap>` line removed, a run containing twelve deliberately planted assertion
 * escapes — eight of which the guard had just reported — produced no report file at
 * all and `php scripts/assertion-escape-check.php` printed "no escapes reported" and
 * exited 0. That is the exact "a gate can PASS a broken artifact" shape: a guard that
 * silently no-ops is strictly worse than no guard, because its presence is taken as
 * evidence.
 *
 * The two loud rows are loud for a reason worth writing down, because it is NOT what
 * S120's own docblocks claimed: `failOnPhpunitWarning` **defaults to `true`** in
 * PHPUnit 10.5 (`vendor/phpunit/phpunit/phpunit.xsd:184` declares
 * `default="true"`, and `.../TextUI/Configuration/Xml/Loader.php:833` passes `true`
 * as the default to `getBooleanAttribute()`). A missing extension class therefore
 * raises a test-runner PHPUnit warning that already fails the run.
 *
 * ## Scope — deliberately structural, deliberately not paranoid
 *
 * This test pins that the guard is WIRED and that the check script's two exit paths
 * behave. It does NOT try to defend the guard against an author who edits the guard's
 * own matching logic (the budget constant, the `count() > $budget` comparison, the
 * `is_file()` test). A guard cannot defend against being rewritten, and a rule that
 * tries produces churn and gets deleted — which is the failure mode S120 itself warns
 * about in {@see \Phlix\Tests\Support\AssertionEscape\EscapeCollector}.
 */
final class AssertionEscapeGuardWiringTest extends TestCase
{
    private const REPO = __DIR__ . '/../../..';

    private const EXTENSION_CLASS = \Phlix\Tests\Support\AssertionEscape\AssertionEscapeGuardExtension::class;

    private const CHECK_SCRIPT = self::REPO . '/scripts/assertion-escape-check.php';

    private const PHPUNIT_XML = self::REPO . '/phpunit.xml';

    private const WORKFLOW = self::REPO . '/.github/workflows/phpunit.yml';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach ([$dir . '/scripts', $dir] as $level) {
                foreach ((array) glob($level . '/{,.}[!.,!..]*', GLOB_BRACE) as $child) {
                    if (is_string($child) && is_file($child)) {
                        unlink($child);
                    }
                }

                if (is_dir($level)) {
                    rmdir($level);
                }
            }
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Half 1 — the extension is REGISTERED, not merely present on disk.
    // -----------------------------------------------------------------------

    /**
     * The silent mutation: delete one line from `phpunit.xml`.
     *
     * Asserted against the PARSED document, not the file's text, so a registration
     * that has been commented out cannot satisfy it — an XML comment is not an
     * element and XPath will not find it.
     */
    public function testPhpunitXmlStillRegistersTheAssertionEscapeExtension(): void
    {
        $document = new \DOMDocument();
        $this->assertTrue(
            $document->load(self::PHPUNIT_XML),
            'phpunit.xml must parse before its contents can be asserted.',
        );

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query(
            sprintf('/phpunit/extensions/bootstrap[@class="%s"]', self::EXTENSION_CLASS),
        );

        $this->assertNotFalse($nodes, 'the XPath query must be valid');
        $this->assertSame(
            1,
            $nodes->length,
            'phpunit.xml must register ' . self::EXTENSION_CLASS . ' as an <extensions><bootstrap> '
            . 'element. Without it the S120 guard loads nothing, writes no report, and '
            . 'scripts/assertion-escape-check.php exits 0 on a run full of swallowed assertions — '
            . 'verified by execution. Commenting the line out does not count: this reads the '
            . 'parsed document, where a comment is not an element.',
        );
    }

    /** The class the XML names must exist and be a real PHPUnit extension. */
    public function testTheRegisteredExtensionClassExistsAndIsAPhpunitExtension(): void
    {
        $this->assertTrue(
            class_exists(self::EXTENSION_CLASS),
            'phpunit.xml names a bootstrap class that must be autoloadable.',
        );
        $this->assertContains(
            Extension::class,
            class_implements(self::EXTENSION_CLASS) ?: [],
            self::EXTENSION_CLASS . ' must implement ' . Extension::class
            . ' or PHPUnit will refuse to bootstrap it.',
        );
    }

    // -----------------------------------------------------------------------
    // Half 2 — the check script exists and BOTH its exit paths are correct.
    // -----------------------------------------------------------------------

    /**
     * Run the real script, not a reimplementation of it — but from a COPY inside a
     * temp directory.
     *
     * The script resolves its report as `dirname(__DIR__) . '/.phpunit-assertion-escapes.json'`,
     * so a copy at `<tmp>/scripts/assertion-escape-check.php` reads `<tmp>/…json`.
     * That is what makes it safe to exercise the failing path: writing a report at the
     * real repo root would make this suite's own CI guard step fail immediately after
     * PHPUnit, which is a self-inflicted red the next maintainer would "fix" by
     * deleting the guard.
     */
    public function testCheckScriptExitsZeroWhenNoReportExists(): void
    {
        $sandbox = $this->sandboxedCheckScript();

        $result = $this->runScript($sandbox);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('no escapes reported', $result['output']);
    }

    public function testCheckScriptExitsOneAndNamesTheTestWhenAReportExists(): void
    {
        $sandbox = $this->sandboxedCheckScript();

        file_put_contents(
            dirname($sandbox, 2) . '/.phpunit-assertion-escapes.json',
            json_encode([[
                'test' => 'Some\\Namespace\\SomeTest::testSomething',
                'kind' => 'VACUOUS (an assertion failed, the test did not)',
                'outcome' => 'passed',
                'failures' => ['the swallowed message'],
            ]]),
        );

        $result = $this->runScript($sandbox);

        $this->assertSame(
            1,
            $result['exit'],
            'A report present means an assertion failed without failing its test. The check '
            . "must exit non-zero or the CI step is decorative. Output:\n" . $result['output'],
        );
        $this->assertStringContainsString('Some\\Namespace\\SomeTest::testSomething', $result['output']);
        $this->assertStringContainsString('VACUOUS', $result['output']);
    }

    // -----------------------------------------------------------------------
    // Half 3 — CI actually executes it, and its failure actually fails the job.
    // -----------------------------------------------------------------------

    /**
     * Parsed YAML, not a substring search, so a step that has been commented out or
     * neutered with `continue-on-error` cannot satisfy this.
     */
    public function testTheWorkflowRunsTheCheckAndLetsItFailTheJob(): void
    {
        /** @var array<string, mixed> $workflow */
        $workflow = Yaml::parseFile(self::WORKFLOW);

        /** @var array<string, mixed> $jobs */
        $jobs = is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];

        /** @var list<array{job: string, step: array<string, mixed>, siblings: array<int|string, mixed>}> $matches */
        $matches = [];

        foreach ($jobs as $jobName => $job) {
            /** @var array<int|string, mixed> $steps */
            $steps = is_array($job) && is_array($job['steps'] ?? null) ? $job['steps'] : [];

            foreach ($steps as $step) {
                if (!is_array($step) || !is_string($step['run'] ?? null)) {
                    continue;
                }

                if (str_contains($step['run'], 'scripts/assertion-escape-check.php')) {
                    $matches[] = ['job' => (string) $jobName, 'step' => $step, 'siblings' => $steps];
                }
            }
        }

        $this->assertCount(
            1,
            $matches,
            '.github/workflows/phpunit.yml must contain exactly one step that runs '
            . 'scripts/assertion-escape-check.php. Zero means the guard produces a report '
            . 'nobody reads; more than one means two jobs disagree about who owns it.',
        );

        $step = $matches[0]['step'];

        $this->assertNotTrue(
            $step['continue-on-error'] ?? false,
            'continue-on-error makes a FAILED step report success, so the guard would '
            . 'detect an escape and pass the job anyway.',
        );

        $condition = is_string($step['if'] ?? null) ? $step['if'] : '';
        $this->assertTrue(
            str_contains($condition, 'always()') || str_contains($condition, 'cancelled()'),
            'The check step must run even after the PHPUnit step failed — a swallowed '
            . 'assertion is most misleading exactly when the suite is already red, because '
            . 'the visible failure is then the WRONG one. Keep `if: always()` (or an '
            . "equivalent !cancelled() form). Found: '" . $condition . "'",
        );

        $phpunitStepIndex = null;
        $checkStepIndex = null;

        foreach ($matches[0]['siblings'] as $i => $candidate) {
            if (!is_array($candidate) || !is_string($candidate['run'] ?? null)) {
                continue;
            }

            if ($phpunitStepIndex === null && str_contains($candidate['run'], 'phpunit')) {
                $phpunitStepIndex = $i;
            }

            if (str_contains($candidate['run'], 'scripts/assertion-escape-check.php')) {
                $checkStepIndex = $i;
            }
        }

        $this->assertNotNull($phpunitStepIndex, 'the job that checks for escapes must also run PHPUnit');
        $this->assertGreaterThan(
            $phpunitStepIndex,
            $checkStepIndex,
            'The check reads a report PHPUnit writes, so it must run AFTER PHPUnit in the '
            . 'same job and the same workspace.',
        );
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /** @return string absolute path to a runnable copy of the check script */
    private function sandboxedCheckScript(): string
    {
        $this->assertFileExists(
            self::CHECK_SCRIPT,
            'scripts/assertion-escape-check.php is what turns the guard\'s report into a CI '
            . 'failure. The workflow step that runs it would exit 1 if it vanished, but this '
            . 'names the reason rather than leaving CI to say "Could not open input file".',
        );

        $dir = sys_get_temp_dir() . '/s120-wiring-' . bin2hex(random_bytes(6));
        mkdir($dir . '/scripts', 0o777, true);
        $this->tempDirs[] = $dir;

        $copy = $dir . '/scripts/assertion-escape-check.php';
        copy(self::CHECK_SCRIPT, $copy);

        return $copy;
    }

    /** @return array{exit: int, output: string} */
    private function runScript(string $script): array
    {
        $output = [];
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }
}
