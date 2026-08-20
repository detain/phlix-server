<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use DOMDocument;
use PHPUnit\Framework\TestCase;

/**
 * S345 — a skip set must be comparable BY NAME, not by count.
 *
 * ## What was actually wrong, measured rather than asserted
 *
 * `phpunit.xml` displayed details for deprecations, warnings and errors, and NOT for
 * skipped tests, and no workflow passed the CLI flag either. So the only thing any run
 * of this suite published about its skips was the number in
 * `Tests: N, ..., Skipped: M.`. Measured on master run `32027697809`
 * (head `e5099b88`): the log says `Skipped: 3` and contains ZERO
 * `There were N skipped tests:` sections.
 *
 * A count cannot distinguish the three things that move it —
 *
 *   1. a test STARTED skipping (a real regression),
 *   2. a conditionally-skipped test turned into a FAILURE (it left the skip set),
 *   3. a test DISAPPEARED (deleted, renamed, or no longer collected),
 *
 * — and it drifts on its own between runs, because this repo has legitimate
 * environment-dependent skips: CI reports 3 where a box with no MySQL, no Chromium and
 * no mysqldump reports ~259. Worse, 1 and 2 can happen together and leave the count
 * UNMOVED. Two audits were forced to record skip movement as "unproven" for exactly
 * this reason. A NAME SET compared with `comm` separates all three.
 *
 * ## What this test pins, and what it deliberately does not
 *
 * Two halves, and both must hold or the guarantee is gone:
 *
 *   * `phpunit.xml` carries `displayDetailsOnSkippedTests="true"` — checked against the
 *     PARSED document, not the file's text, so commenting the root element's attribute
 *     out cannot pass. Deleting it is otherwise SILENT: every run stays green and every
 *     count keeps being reported, and the loss shows up only the next time somebody
 *     needs a name set and no longer has one.
 *   * `scripts/skipped-test-names.sh` still turns that output into a sorted,
 *     `comm`-ready set — exercised end to end against fixtures, including the three
 *     refusal paths, because a parser that quietly matches NOTHING reads as a pass.
 *
 * The digit-bearing class-name fixture is not decoration: the first cut of the parser
 * matched `[A-Za-z_\\]+::` and silently dropped 29 of 259 real names (`Fmp4Hls…`,
 * `OAuth2…`). It was caught only because the script cross-checks its output against the
 * count PHPUnit itself printed — which is the same "print the denominator" discipline
 * this whole step is about.
 *
 * It does NOT try to defend the script against being rewritten, and it does not assert
 * a skip COUNT anywhere. Asserting a count here would reintroduce the exact measure the
 * step exists to replace.
 */
final class SkippedTestNameReportingTest extends TestCase
{
    private const REPO = __DIR__ . '/../../..';

    private const SCRIPT = self::REPO . '/scripts/skipped-test-names.sh';

    private const CONFIG = self::REPO . '/phpunit.xml';

    /**
     * A minimal but realistic PHPUnit 10.5 tail: a first list (so the `--` separator is
     * present, as it is in a real red run), the skipped-details list, and the summary.
     */
    private const FIXTURE = <<<'OUT'
        PHPUnit 10.5.64 by Sebastian Bergmann and contributors.

        Runtime:       PHP 8.3.6
        Configuration: /repo/phpunit.xml

        There was 1 failure:

        1) Phlix\Tests\Unit\Zeta\ZetaTest::testUnrelated
        Failed asserting that false is true.

        --

        There were 4 skipped tests:

        1) Phlix\Tests\Integration\Media\MusicDtoTest::testColumnIsChar36
        No MySQL on 127.0.0.1:3306

        /repo/tests/Support/Database/RequiresRealDatabase.php:41

        2) Phlix\Tests\E2E\Media\Transcoding\Fmp4HlsPlaybackE2ETest::testRemovingTheExtXMapBreaksPlayback
        no Chrome/Chromium binary

        3) Phlix\Tests\Unit\Admin\BackupManagerTest::testCreateBackupGeneratesIdAndPath with data set "a b"
        createBackup requires mysqldump

        4) Phlix\Tests\Unit\Alpha\AlphaTest::testGamma
        FFI is not available on this system

        FAILURES!
        Tests: 9, Assertions: 12, Failures: 1, Skipped: 4.
        OUT;

    /** @var list<string> */
    private const EXPECTED_NAMES = [
        'Phlix\Tests\E2E\Media\Transcoding\Fmp4HlsPlaybackE2ETest::testRemovingTheExtXMapBreaksPlayback',
        'Phlix\Tests\Integration\Media\MusicDtoTest::testColumnIsChar36',
        'Phlix\Tests\Unit\Admin\BackupManagerTest::testCreateBackupGeneratesIdAndPath with data set "a b"',
        'Phlix\Tests\Unit\Alpha\AlphaTest::testGamma',
    ];

    private string $tmpDir = '';

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-s345-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o700), 'could not create the fixture directory');
        $this->tmpDir = $dir;
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir === '') {
            return;
        }

        foreach ((glob($this->tmpDir . '/*') ?: []) as $file) {
            @unlink($file);
        }

        @rmdir($this->tmpDir);
        $this->tmpDir = '';
    }

    public function test_phpunit_xml_still_asks_for_skipped_test_names(): void
    {
        $document = new DOMDocument();
        self::assertTrue(
            $document->load(self::CONFIG),
            'phpunit.xml could not be parsed.',
        );

        $root = $document->documentElement;
        self::assertNotNull($root, 'phpunit.xml has no root element.');

        self::assertSame(
            'true',
            $root->getAttribute('displayDetailsOnSkippedTests'),
            'phpunit.xml has lost displayDetailsOnSkippedTests="true" (S345). Without it PHPUnit '
            . 'prints only the skip COUNT, and a count cannot distinguish a test that started '
            . 'skipping from one that turned into a failure from one that disappeared. Removing it '
            . 'is otherwise SILENT — every run stays green. Restore the attribute; do not delete '
            . 'this test.',
        );
    }

    public function test_the_extraction_script_is_present_and_executable(): void
    {
        self::assertFileExists(
            self::SCRIPT,
            'scripts/skipped-test-names.sh is gone. It is the documented way to turn a run into a '
            . '`comm`-ready name set (README, "Comparing skip sets between two runs").',
        );
        self::assertTrue(
            is_executable(self::SCRIPT),
            'scripts/skipped-test-names.sh has lost its executable bit, so the documented '
            . '`| scripts/skipped-test-names.sh` pipeline no longer runs.',
        );
    }

    public function test_the_readme_documents_the_comm_ready_one_liner(): void
    {
        $readme = (string) file_get_contents(self::REPO . '/README.md');

        self::assertStringContainsString(
            'scripts/skipped-test-names.sh',
            $readme,
            'README.md no longer points at the extraction script. A one-liner that lives only in a '
            . 'PR description is not documentation.',
        );
        self::assertStringContainsString(
            'comm -3',
            $readme,
            'README.md no longer shows the `comm` comparison, which is the whole point of producing '
            . 'a name set rather than a count.',
        );
    }

    public function test_it_emits_the_sorted_name_set_and_nothing_else(): void
    {
        $result = $this->runScript($this->fixtureFile('run.log'));

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame(self::EXPECTED_NAMES, $result['lines'], $result['stderr']);

        $sorted = self::EXPECTED_NAMES;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $result['lines'], 'the output must be sorted, or `comm` misreads it');
    }

    public function test_a_class_name_containing_digits_is_not_dropped(): void
    {
        // The bug the denominator caught: `Fmp4Hls…` and `OAuth2…` were silently lost.
        self::assertContains(
            'Phlix\Tests\E2E\Media\Transcoding\Fmp4HlsPlaybackE2ETest::testRemovingTheExtXMapBreaksPlayback',
            $this->runScript($this->fixtureFile('run.log'))['lines'],
        );
    }

    public function test_it_reads_a_gh_run_view_log_with_its_job_step_timestamp_prefix(): void
    {
        $prefixed = [];
        foreach (explode("\n", self::FIXTURE) as $line) {
            $prefixed[] = "PHPUnit Test Suite\tRun PHPUnit tests\t2026-08-20T06:22:33.1234567Z " . $line;
        }

        $result = $this->runScript($this->fixtureFile('gh.log', implode("\n", $prefixed)));

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame(self::EXPECTED_NAMES, $result['lines'], $result['stderr']);
    }

    public function test_input_that_is_not_a_phpunit_run_is_refused_rather_than_reported_as_empty(): void
    {
        $result = $this->runScript($this->fixtureFile('junk.log', "hello\nworld\n"));

        self::assertSame(2, $result['exit'], 'a parser fed the wrong input must not read as "no skips"');
        self::assertSame([], $result['lines']);
        self::assertStringContainsString('no PHPUnit result summary', $result['stderr']);
    }

    public function test_a_run_that_skipped_tests_but_printed_no_names_is_refused(): void
    {
        // Exactly master's shape before S345: a count, and no detail list to read it from.
        $countOnly = "PHPUnit 10.5.64\n\nOK, but there were issues!\nTests: 9, Assertions: 12, Skipped: 4.\n";

        $result = $this->runScript($this->fixtureFile('countonly.log', $countOnly));

        self::assertSame(4, $result['exit'], 'a missing displayDetailsOnSkippedTests must be loud, not empty');
        self::assertSame([], $result['lines']);
        self::assertStringContainsString('displayDetailsOnSkippedTests', $result['stderr']);
    }

    public function test_a_run_with_no_skips_at_all_is_an_empty_set_and_a_success(): void
    {
        $clean = "PHPUnit 10.5.64\n\nOK (9 tests, 12 assertions)\n";

        $result = $this->runScript($this->fixtureFile('clean.log', $clean));

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame([], $result['lines']);
    }

    public function test_extraction_that_disagrees_with_phpunits_own_count_is_refused(): void
    {
        // One name mangled: PHPUnit still declares 4, only 3 are recoverable.
        $drifted = str_replace(
            '4) Phlix\Tests\Unit\Alpha\AlphaTest::testGamma',
            '4) something the parser cannot read',
            self::FIXTURE,
        );

        $result = $this->runScript($this->fixtureFile('drift.log', $drifted));

        self::assertSame(3, $result['exit'], 'a partial extraction must not be handed to `comm` as if complete');
        self::assertSame([], $result['lines']);
    }

    private function fixtureFile(string $name, ?string $contents = null): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, ($contents ?? self::FIXTURE) . "\n");

        return $path;
    }

    /**
     * @return array{exit: int, lines: list<string>, stderr: string}
     */
    private function runScript(string $input): array
    {
        $stderrFile = $this->tmpDir . '/stderr.txt';

        $output = [];
        $exit = 0;
        exec(
            escapeshellarg(self::SCRIPT) . ' ' . escapeshellarg($input) . ' 2>' . escapeshellarg($stderrFile),
            $output,
            $exit,
        );

        return [
            'exit' => $exit,
            'lines' => array_values(array_filter($output, static fn (string $line): bool => $line !== '')),
            'stderr' => (string) file_get_contents($stderrFile),
        ];
    }
}
