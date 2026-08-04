<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use Phlix\Tests\Support\AssertionEscape\ProbeBaseline;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * S180 — the STATIC half of S120's guard is now a CI job, so the same three questions
 * S120's wiring test asks of the runtime half have to be asked of this one.
 *
 * ## The three failure modes this pins, and why each is not paranoia
 *
 * 1. **The job/step acquires an `if:`.** A skipped GitHub job reports conclusion
 *    `skipped`, and `skipped` counts as SUCCESS for a status check. An `if:` on this
 *    gate would therefore produce a green check from a gate that never ran — the exact
 *    "a gate can PASS a broken artifact" shape, one level above the swallowed
 *    assertions the gate looks for. Measured precedent in this repo: S120's runtime
 *    guard was one deletable `<bootstrap>` line from being silent with exit 0.
 * 2. **The step acquires `continue-on-error`.** Then a FAILED step reports success.
 *    The Codacy upload in the same file does this deliberately and documents it, which
 *    is exactly why the spelling is close at hand for someone silencing a red.
 * 3. **The baseline degrades into a permanent allow-list.** `probe-baseline.json`
 *    records the UNDECIDED sites. If entries could be added without a reason, or if a
 *    listed site could start gating and stay listed, the file would become the
 *    "gate that proves nothing" this repo has already been burned by three times
 *    (the Psalm job, the security-audit job, the xmllint coverage step).
 *
 * ## Scope — deliberately structural
 *
 * Like {@see AssertionEscapeGuardWiringTest}, this does NOT try to defend the prober
 * against an author who rewrites its classification logic. A guard cannot defend
 * against being rewritten and a rule that tries produces churn and gets deleted. What
 * it does defend is the wiring and the baseline contract, i.e. every mutation that is
 * SILENT rather than loud.
 */
final class AssertionEscapeProbeGateTest extends TestCase
{
    private const REPO = __DIR__ . '/../../..';

    private const WORKFLOW = self::REPO . '/.github/workflows/phpunit.yml';

    private const PROBE_SCRIPT = 'scripts/assertion-escape-audit.php';

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
    // Half 1 — CI runs the prober, and it can fail the build.
    // -----------------------------------------------------------------------

    /**
     * Parsed YAML, not a substring search: a step that has been commented out is not a
     * step, and a substring search would happily match this file's own explanatory
     * comments.
     */
    public function testTheWorkflowRunsTheProberInBulkModeAndLetsItFailTheJob(): void
    {
        $match = $this->probeStep();

        $this->assertStringContainsString(
            '--probe',
            $match['step']['run'],
            'The gate is the --probe mode. Without it the script only LISTS sites and always '
            . 'exits 0, which is a step that cannot fail — the defect S120 exists to catch.',
        );

        $this->assertStringNotContainsString(
            '--only=',
            $match['step']['run'],
            '--only=N probes a single site and skips baseline reconciliation entirely. In CI '
            . 'that would be a gate over 1 of 22 sites reporting as if it covered all of them.',
        );

        $this->assertNotTrue(
            $match['step']['continue-on-error'] ?? false,
            'continue-on-error makes a FAILED step report success, so the prober would find a '
            . 'vacuous assertion and pass the job anyway.',
        );

        $this->assertArrayNotHasKey(
            'if',
            $match['step'],
            'A skipped step contributes nothing and a skipped JOB reports conclusion "skipped", '
            . 'which counts as SUCCESS for a status check. This gate must not be able to pass by '
            . 'not running. If a condition ever becomes genuinely necessary, it has to be one that '
            . 'cannot evaluate false on a pull request — and it needs its own proof.',
        );

        $this->assertArrayNotHasKey(
            'if',
            $match['job'],
            'Same reason as the step, one level up: `skipped` is a passing conclusion.',
        );

        $this->assertNotTrue(
            $match['job']['continue-on-error'] ?? false,
            'A job-level continue-on-error hides the whole gate.',
        );
    }

    /** The script the workflow names must exist, or the job fails for the wrong reason. */
    public function testTheProberScriptAndItsBaselineBothExist(): void
    {
        $this->assertFileExists(
            self::REPO . '/' . self::PROBE_SCRIPT,
            'The workflow step names this script. If it vanishes the job says "Could not open '
            . 'input file", which is loud but does not say WHY it mattered.',
        );

        $this->assertFileExists(
            self::REPO . '/' . ProbeBaseline::RELATIVE_PATH,
            'The prober refuses to run without its baseline (verified: it exits 1 with a named '
            . 'message). This assertion makes the reason visible in seconds instead of after a '
            . 'multi-minute CI job.',
        );
    }

    // -----------------------------------------------------------------------
    // Half 2 — the baseline is a reasoned enumeration, not an allow-list.
    // -----------------------------------------------------------------------

    /**
     * The EXCLUSION list is pinned exactly, because an exclusion removes a site from
     * the gate entirely — the one edit here that can quietly shrink what CI checks.
     * The `notReached` list is deliberately NOT pinned by count: the prober reconciles
     * it at runtime in both directions, which is a stronger check than a number copied
     * into a test, and duplicating the count here would make every legitimate baseline
     * edit a two-file change for no extra safety.
     */
    public function testTheExclusionListIsExactlyTheOneMeasuredFlake(): void
    {
        $baseline = $this->baseline();

        $excluded = array_map(
            static fn (array $entry): string => $entry['file'] . '::' . $entry['method'],
            $baseline->excluded(),
        );

        $this->assertSame(
            ['tests/Integration/Media/Transcoding/PooledConnectionConcurrencyTest.php::runChurn'],
            $excluded,
            'Exactly ONE site is excluded from the prober, and its measurement lives in the '
            . '"reason" field next to it (19 valid probe runs against a live MySQL: 18 GATES, '
            . '1 NOT-REACHED). Adding a second exclusion is a real decision and must break this '
            . 'assertion, because an exclusion list that grows quietly is how a gate becomes a '
            . 'permanent hole — see phpcs-tests.xml\'s <description> for the same rule.',
        );
    }

    public function testEveryBaselineEntryCarriesASubstantiveReasonAndAFileThatExists(): void
    {
        $baseline = $this->baseline();
        $entries = [...$baseline->excluded(), ...$baseline->notReached()];

        $this->assertNotEmpty($entries, 'an empty baseline means nobody enumerated anything');

        foreach ($entries as $entry) {
            $label = $entry['file'] . '::' . $entry['method'];

            $this->assertFileExists(
                self::REPO . '/' . $entry['file'],
                'Baseline entry ' . $label . ' names a file that is not on disk. The prober '
                . 'reports this as a stale entry too, but only after the full multi-minute run.',
            );

            $this->assertGreaterThan(
                80,
                strlen($entry['reason']),
                'Baseline entry ' . $label . ' has a reason too short to be one. This file '
                . 'replaces folklore; "known flake" is folklore. State what was measured.',
            );
        }
    }

    public function testTheBaselineHasNoDuplicateSites(): void
    {
        $baseline = $this->baseline();

        $keys = array_map(
            static fn (array $entry): string => $entry['file'] . '::' . $entry['method'],
            [...$baseline->excluded(), ...$baseline->notReached()],
        );

        $this->assertSame(
            array_values(array_unique($keys)),
            $keys,
            'A site listed twice — or listed as both excluded and not-reached — makes the '
            . 'reconciliation ambiguous and one of the two entries unfalsifiable.',
        );
    }

    // -----------------------------------------------------------------------
    // Half 3 — the reconciliation itself, in BOTH directions.
    // -----------------------------------------------------------------------

    public function testAnUnrecordedNotReachedSiteIsAViolation(): void
    {
        $baseline = $this->syntheticBaseline([], []);

        $violations = $baseline->reconcile(
            [['file' => 'tests/Unit/NewTest.php', 'method' => 'testThing', 'verdict' => 'NOT-REACHED']],
            [],
        );

        $this->assertCount(1, $violations, 'a newly undecided site must red the gate');
        $this->assertStringContainsString('UNRECORDED NOT-REACHED', $violations[0]);
        $this->assertStringContainsString('tests/Unit/NewTest.php', $violations[0]);
    }

    public function testARecordedNotReachedSiteIsAccepted(): void
    {
        $baseline = $this->syntheticBaseline(
            [],
            [['file' => 'tests/Unit/NewTest.php', 'method' => 'testThing']],
        );

        $this->assertSame(
            [],
            $baseline->reconcile(
                [['file' => 'tests/Unit/NewTest.php', 'method' => 'testThing', 'verdict' => 'NOT-REACHED']],
                [],
            ),
            'the whole point of recording a site is that it stops redding the gate',
        );
    }

    /** The direction a plain known-issues list never checks. */
    public function testARecordedSiteThatStartsGatingIsAViolation(): void
    {
        $baseline = $this->syntheticBaseline(
            [],
            [['file' => 'tests/Unit/NewTest.php', 'method' => 'testThing']],
        );

        $violations = $baseline->reconcile(
            [['file' => 'tests/Unit/NewTest.php', 'method' => 'testThing', 'verdict' => 'GATES']],
            [],
        );

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('BASELINE ENTRY NOW DECIDED', $violations[0]);
        $this->assertStringContainsString('GATES', $violations[0]);
    }

    public function testARecordedSiteThatNoLongerExistsIsAViolation(): void
    {
        $baseline = $this->syntheticBaseline(
            [],
            [['file' => 'tests/Unit/GoneTest.php', 'method' => 'testThing']],
        );

        $violations = $baseline->reconcile([], []);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('STALE BASELINE ENTRY', $violations[0]);
    }

    public function testAnExclusionThatMatchesNothingIsAViolation(): void
    {
        $baseline = $this->syntheticBaseline(
            [['file' => 'tests/Unit/GoneTest.php', 'method' => 'helper']],
            [],
        );

        $violations = $baseline->reconcile([], []);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('STALE EXCLUSION', $violations[0]);
    }

    public function testAMatchedExclusionAndAGatingSiteReconcileCleanly(): void
    {
        $baseline = $this->syntheticBaseline(
            [['file' => 'tests/Unit/FlakyTest.php', 'method' => 'helper']],
            [],
        );

        $this->assertSame(
            [],
            $baseline->reconcile(
                [['file' => 'tests/Unit/GoodTest.php', 'method' => 'testThing', 'verdict' => 'GATES']],
                [['file' => 'tests/Unit/FlakyTest.php', 'method' => 'helper']],
            ),
        );
    }

    public function testTheRealBaselineReportsTheExclusionReasonForTheExcludedSite(): void
    {
        $baseline = $this->baseline();

        $reason = $baseline->exclusionReason(
            'tests/Integration/Media/Transcoding/PooledConnectionConcurrencyTest.php',
            'runChurn',
        );

        $this->assertIsString($reason, 'the prober prints this reason in its output, so it must resolve');
        $this->assertStringContainsString('S137', $reason);

        $this->assertNull(
            $baseline->exclusionReason('tests/Unit/Auth/UserIdentityRepositoryTest.php', 'whatever'),
            'exclusion must match on file AND method, not on either alone',
        );
    }

    // -----------------------------------------------------------------------
    // Half 4 — an unreadable baseline is FATAL, never a silent default.
    // -----------------------------------------------------------------------

    public function testAMissingBaselineThrowsRatherThanDefaultingToEmpty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is missing');

        ProbeBaseline::fromFile(sys_get_temp_dir() . '/s180-does-not-exist-' . bin2hex(random_bytes(6)) . '.json');
    }

    public function testMalformedJsonThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');

        ProbeBaseline::fromFile($this->tempJson('{ not json'));
    }

    public function testAMissingSectionThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"notReached"');

        ProbeBaseline::fromFile($this->tempJson('{"excluded": []}'));
    }

    public function testAnEntryWithoutAReasonThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reason');

        ProbeBaseline::fromFile($this->tempJson(
            '{"excluded": [], "notReached": [{"file": "a.php", "method": "m", "recorded": "2026-08-03"}]}',
        ));
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    private function baseline(): ProbeBaseline
    {
        return ProbeBaseline::fromFile(self::REPO . '/' . ProbeBaseline::RELATIVE_PATH);
    }

    /**
     * @param list<array{file: string, method: string}> $excluded
     * @param list<array{file: string, method: string}> $notReached
     */
    private function syntheticBaseline(array $excluded, array $notReached): ProbeBaseline
    {
        $decorate = static fn (array $entry): array => $entry + [
            'reason' => 'synthetic fixture for the reconciliation unit test, long enough to satisfy '
                . 'the substantive-reason assertion in this same file',
            'recorded' => '2026-08-03',
        ];

        return ProbeBaseline::fromFile($this->tempJson((string) json_encode([
            'excluded' => array_map($decorate, $excluded),
            'notReached' => array_map($decorate, $notReached),
        ])));
    }

    private function tempJson(string $contents): string
    {
        $path = sys_get_temp_dir() . '/s180-baseline-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array{job: array<string, mixed>, step: array<string, mixed>}
     */
    private function probeStep(): array
    {
        /** @var array<string, mixed> $workflow */
        $workflow = Yaml::parseFile(self::WORKFLOW);

        /** @var array<string, mixed> $jobs */
        $jobs = is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];

        /** @var list<array{job: array<string, mixed>, step: array<string, mixed>}> $matches */
        $matches = [];

        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }

            /** @var array<int|string, mixed> $steps */
            $steps = is_array($job['steps'] ?? null) ? $job['steps'] : [];

            foreach ($steps as $step) {
                if (!is_array($step) || !is_string($step['run'] ?? null)) {
                    continue;
                }

                if (str_contains($step['run'], self::PROBE_SCRIPT)) {
                    /** @var array<string, mixed> $typedJob */
                    $typedJob = $job;
                    /** @var array<string, mixed> $typedStep */
                    $typedStep = $step;
                    $matches[] = ['job' => $typedJob, 'step' => $typedStep];
                }
            }
        }

        $this->assertCount(
            1,
            $matches,
            '.github/workflows/phpunit.yml must contain exactly ONE step that runs '
            . self::PROBE_SCRIPT . '. Zero means the static half of S120\'s guard is back to '
            . 'being something people remember to run by hand — which is how the live escape of '
            . '2026-08-02 reached master. More than one means two jobs disagree about who owns it.',
        );

        return $matches[0];
    }
}
