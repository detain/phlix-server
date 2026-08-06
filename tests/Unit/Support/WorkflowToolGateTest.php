<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * S183 — a CI gate must fail when its tool is missing, never skip.
 *
 * ## The defect
 *
 * `.github/workflows/phpunit.yml`'s "Check code style" step was
 *
 * ```bash
 * if [ -f vendor/bin/phpcs ]; then
 *   ./vendor/bin/phpcs --standard=PSR12 src/Server/
 * fi
 * ```
 *
 * — the exact shape S146 removed from `coding-standards.yml`'s Psalm, PHPCS and
 * Security-Audit steps, surviving in a different workflow file. When `vendor/` is
 * partial (interrupted `composer install`, incomplete cache restore, the dependency
 * dropped from require-dev) the condition is false, the body never runs, the step
 * exits 0, and the job reports success having sniffed nothing. GitHub scores that the
 * same as a real pass, and this repo has no branch protection to tell them apart.
 *
 * It is the fourth instance of one defect class in this repo: S146's Psalm job (green
 * for its entire existence having analysed zero files), the Security Audit job (two
 * binaries that never existed, so the `if`/`elif` always fell through) and the
 * coverage-threshold step (`xmllint`, absent from ubuntu-latest, hidden behind
 * `2>/dev/null` and `|| echo "0"`). Every time, a MISSING TOOL sat behind a
 * conditional that treated absence as success.
 *
 * ## Why this file executes shell instead of reading YAML
 *
 * A test that only asserted the workflow text would be a declaration assertion: it
 * pins what the file SAYS, not what the shell DOES. {@see
 * testTheGuardFailsWhenPhpcsIsMissing()} therefore lifts the guard step's `run` body
 * out of the workflow and runs it under `bash` in a scratch directory with no
 * `vendor/bin/phpcs`, and asserts the exit status. {@see
 * testTheGuardPassesWhenPhpcsIsPresent()} runs the same body with a stub binary in
 * place, so the failure above is attributable to the tool's ABSENCE and not to a body
 * that fails unconditionally — one without the other proves nothing.
 *
 * The remaining tests are the sweep S183's acceptance criteria ask for: no workflow in
 * this repo may guard a tool behind a positive existence test, and the only
 * `continue-on-error` step is the documented Codacy uploader.
 *
 * @see SecurityAuditCheckTest the S146 sibling of this file
 * @see CoverageThresholdCheckTest the xmllint sibling
 */
final class WorkflowToolGateTest extends TestCase
{
    private const WORKFLOWS = __DIR__ . '/../../../.github/workflows';

    /**
     * The step whose absence-path is executed below.
     */
    private const GUARD_STEP = 'Assert PHPCS is actually installed';

    /**
     * A positive existence test on a tool — `if [ -f vendor/bin/x ]`, `if [ -x ./vendor/…`.
     *
     * The negated form `if [ ! -f vendor/bin/x ]; then … exit 1; fi` is the REQUIRED
     * shape and must not match, which is why the `!` is excluded explicitly.
     */
    private const TOOL_EXISTENCE_GUARD = '~\bif\s+\[+\s+(?!!)-[fxse]\s+["\']?\.?/?vendor/~';

    /**
     * `if command -v foo` — the same defect wearing a different hat. `if ! command -v`
     * is the assertion form and is allowed.
     */
    private const POSITIVE_COMMAND_V_GUARD = '~\bif\s+(?!!)command\s+-v~';

    /** @var list<string> */
    private array $scratchDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            $this->removeTree($dir);
        }

        $this->scratchDirs = [];

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // The absence path, by execution.
    // -----------------------------------------------------------------------

    public function testTheGuardFailsWhenPhpcsIsMissing(): void
    {
        $dir = $this->scratchDir();
        // No vendor/ at all: the partial-install case, at its most extreme.
        self::assertFileDoesNotExist($dir . '/vendor/bin/phpcs', 'the scratch tree must start empty');

        $result = $this->runShell($this->guardStepBody(), $dir);

        self::assertNotSame(
            0,
            $result['exit'],
            'A missing vendor/bin/phpcs must FAIL the step. It exited 0, which is what the '
            . "`if [ -f … ]` guard did for this step's whole life: sniff nothing, report "
            . "success.\nOutput:\n" . $result['output'],
        );

        self::assertStringContainsString(
            '::error::',
            $result['output'],
            'the failure must be annotated so the reason is legible in the Actions UI',
        );

        self::assertStringContainsString(
            'vendor/bin/phpcs is missing',
            $result['output'],
            'the annotation must name the missing tool',
        );
    }

    /**
     * The control for the test above: with the tool present the same body exits 0.
     *
     * Without this, a `run` body of `exit 1` would satisfy the absence test perfectly
     * while making the gate permanently red — i.e. the assertion would be measuring the
     * body's unconditional failure, not the tool's absence.
     */
    public function testTheGuardPassesWhenPhpcsIsPresent(): void
    {
        $dir = $this->scratchDir();
        mkdir($dir . '/vendor/bin', 0o755, true);
        file_put_contents(
            $dir . '/vendor/bin/phpcs',
            "#!/bin/sh\necho 'PHP_CodeSniffer version 3.99.0 (stable) by Squiz Pty Ltd'\n",
        );
        chmod($dir . '/vendor/bin/phpcs', 0o755);

        $result = $this->runShell($this->guardStepBody(), $dir);

        self::assertSame(
            0,
            $result['exit'],
            "the guard must pass when the tool IS installed:\n" . $result['output'],
        );
        self::assertStringNotContainsString('::error::', $result['output']);
    }

    /**
     * Anti-vacuity for the two tests above, and the direct regression detector: the
     * historical body — the one this step shipped with — must be caught by the sweep's
     * pattern AND must exit 0 with the tool absent. If either stops being true, the
     * sweep below has quietly stopped detecting the defect it exists for.
     */
    public function testTheHistoricalGuardShapeIsDetectedAndIsIndeedSilent(): void
    {
        $historical = <<<'SH'
            if [ -f vendor/bin/phpcs ]; then
              ./vendor/bin/phpcs --standard=PSR12 src/Server/
            fi
            SH;

        self::assertMatchesRegularExpression(
            self::TOOL_EXISTENCE_GUARD,
            $this->shellDirectives($historical),
            'the sweep pattern no longer matches the exact body S183 removed, so the '
            . 'sweep below would not notice it coming back',
        );

        $result = $this->runShell($historical, $this->scratchDir());

        self::assertSame(
            0,
            $result['exit'],
            'the historical body is supposed to be the silent-pass defect; if it now '
            . 'fails, this test is asserting against something that is no longer the bug',
        );
        self::assertSame('', trim($result['output']), 'and it said nothing at all while doing it');
    }

    // -----------------------------------------------------------------------
    // The sweep.
    // -----------------------------------------------------------------------

    public function testNoWorkflowGuardsAToolBehindAPositiveExistenceTest(): void
    {
        $offenders = [];
        $corpus = '';
        foreach ($this->runSteps() as $step) {
            $directives = $this->shellDirectives($step['run']);
            $corpus .= $directives;

            if (
                preg_match(self::TOOL_EXISTENCE_GUARD, $directives) === 1
                || preg_match(self::POSITIVE_COMMAND_V_GUARD, $directives) === 1
            ) {
                $offenders[] = $step['workflow'] . ' :: ' . $step['job'] . ' :: ' . $step['step'];
            }
        }

        // Anti-vacuity: comment-stripping must not have eaten the shell. If it had,
        // "no offenders" would be trivially true. All four of these are executable
        // lines in coding-standards.yml / phpunit.yml.
        foreach (['vendor/bin/phpcs', 'vendor/bin/phpstan', 'vendor/bin/psalm', 'composer install'] as $token) {
            self::assertStringContainsString(
                $token,
                $corpus,
                'comment stripping ate the workflow bodies, so this sweep proves nothing',
            );
        }

        self::assertSame(
            [],
            $offenders,
            "A tool guarded by `if [ -f … ]` / `if command -v …` turns a missing tool into a "
            . "silent pass, and GitHub scores that the same as a real green (S146, S183).\n"
            . 'Write it as an assertion instead: `if [ ! -f vendor/bin/x ]; then echo '
            . '"::error::…"; exit 1; fi`.',
        );
    }

    /**
     * The style step itself must be unconditional. Converting the guard while leaving
     * the sniff behind `|| true` would move the silent pass one line down.
     */
    public function testTheServerStyleStepRunsPhpcsUnconditionally(): void
    {
        $hits = [];
        foreach ($this->runSteps() as $step) {
            if ($step['workflow'] !== 'phpunit.yml' || !str_contains($step['run'], '--standard=PSR12')) {
                continue;
            }

            $hits[] = $step;
        }

        self::assertCount(
            1,
            $hits,
            'phpunit.yml must still sniff src/Server/ with unmodified PSR-12 exactly once',
        );

        $style = $hits[0];
        self::assertStringContainsString('src/Server/', $style['run']);

        foreach ([' || true', '|| exit 0', 'if [', ' -n '] as $escape) {
            self::assertStringNotContainsString(
                $escape,
                $style['run'],
                'a gate that cannot fail is not a gate (and -n deletes warnings — S109)',
            );
        }

        self::assertArrayNotHasKey(
            'if',
            $style['raw'],
            'no `if:` on the style step: a skipped step reports success',
        );
        self::assertArrayNotHasKey(
            'continue-on-error',
            $style['raw'],
            'no `continue-on-error:` on the style step: it makes a FAILED step report success',
        );

        // And the assertion step must still precede it in the same job.
        $guards = array_filter(
            $this->runSteps(),
            static fn (array $s): bool => $s['step'] === self::GUARD_STEP && $s['job'] === $style['job'],
        );
        self::assertCount(
            1,
            $guards,
            'the "' . self::GUARD_STEP . '" step must live in the same job as the sniff, '
            . 'or the sniff can still run with a missing tool',
        );
    }

    /**
     * Every soft gate in the repo's workflows, enumerated. `continue-on-error` makes a
     * FAILED step report success, so the list of steps allowed to carry it is closed.
     */
    public function testContinueOnErrorIsConfinedToTheDocumentedUploader(): void
    {
        $soft = [];
        foreach ($this->allSteps() as $step) {
            if (($step['raw']['continue-on-error'] ?? false) === false) {
                continue;
            }

            $soft[] = $step['workflow'] . ' :: ' . $step['step'];
        }

        sort($soft);

        self::assertSame(
            ['phpunit.yml :: Upload coverage to Codacy'],
            $soft,
            'Exactly one step may carry continue-on-error: the Codacy uploader, which is '
            . 'informational and has no fail_ci_if_error input. Adding a second needs its '
            . 'reason in the workflow and a change to this assertion in the same commit — '
            . 'continue-on-error on an ANALYSIS step is a gate that cannot fail.',
        );
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /**
     * The `run:` body of the PHPCS assertion step in phpunit.yml.
     */
    private function guardStepBody(): string
    {
        foreach ($this->runSteps() as $step) {
            if ($step['workflow'] === 'phpunit.yml' && $step['step'] === self::GUARD_STEP) {
                return $step['run'];
            }
        }

        self::fail(
            'phpunit.yml has no "' . self::GUARD_STEP . '" step. It is the assertion that '
            . 'replaced `if [ -f vendor/bin/phpcs ]` (S183); deleting it restores a gate '
            . 'that passes by not running.',
        );
    }

    /**
     * Every step of every workflow, with its raw mapping.
     *
     * @return list<array{workflow: string, job: string, step: string, raw: array<string, mixed>}>
     */
    private function allSteps(): array
    {
        $files = glob(self::WORKFLOWS . '/*.yml') ?: [];
        sort($files);

        // Anti-vacuity: every assertion above is a search over this list.
        self::assertGreaterThanOrEqual(6, count($files), 'the workflow directory looks empty');

        $out = [];
        foreach ($files as $file) {
            $raw = file_get_contents($file);
            self::assertIsString($raw, $file . ' must be readable');

            $parsed = Yaml::parse($raw);
            self::assertIsArray($parsed, $file . ' must parse as YAML');
            self::assertIsArray($parsed['jobs'] ?? null, $file . ' must declare jobs');

            foreach ($parsed['jobs'] as $jobName => $job) {
                if (!is_array($job) || !is_array($job['steps'] ?? null)) {
                    continue;
                }

                foreach ($job['steps'] as $step) {
                    if (!is_array($step)) {
                        continue;
                    }

                    $out[] = [
                        'workflow' => basename($file),
                        'job' => (string) $jobName,
                        'step' => is_string($step['name'] ?? null) ? $step['name'] : '(unnamed)',
                        'raw' => $step,
                    ];
                }
            }
        }

        self::assertGreaterThan(30, count($out), 'the workflow parse produced almost no steps');

        return $out;
    }

    /**
     * The subset of {@see allSteps()} that runs shell, with the body flattened out.
     *
     * @return list<array{workflow: string, job: string, step: string, run: string, raw: array<string, mixed>}>
     */
    private function runSteps(): array
    {
        $out = [];
        foreach ($this->allSteps() as $step) {
            if (!is_string($step['raw']['run'] ?? null)) {
                continue;
            }

            $out[] = $step + ['run' => $step['raw']['run']];
        }

        self::assertGreaterThan(20, count($out), 'the workflow parse produced almost no run steps');

        return $out;
    }

    /**
     * A `run:` body with its SHELL comment lines removed.
     *
     * ⚠ These are `#` lines INSIDE the body, so the YAML parser keeps them — and every
     * one of S146's assertion steps documents itself by quoting the very shape this
     * file forbids (*"used to be wrapped in `if [ -f vendor/bin/psalm ]`"*). Asserting
     * over the raw body reports all four as offenders. That is the fifth detector in
     * this repo to fire on its own documentation (S105's middleware counter, the
     * phlix-docs `.shell__main` case, CoverageMetadataPolicyTest,
     * StaticAnalysisScopeTest's `stubFiles` guard, this) — so: strip, then assert, and
     * prove the stripping did not empty the corpus.
     */
    private function shellDirectives(string $run): string
    {
        $kept = [];
        foreach (explode("\n", $run) as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept) . "\n";
    }

    /**
     * Run a workflow `run:` body under bash, in $cwd, exactly as the runner would.
     *
     * @return array{exit: int, output: string}
     */
    private function runShell(string $body, string $cwd): array
    {
        $script = $cwd . '/step.sh';
        // GitHub's default shell for `run:` on Linux is `bash -e {0}`.
        file_put_contents($script, "#!/usr/bin/env bash\nset -e\n" . $body . "\n");

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(['bash', '-e', $script], $descriptors, $pipes, $cwd);
        self::assertIsResource($process, 'bash must be runnable');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'output' => $stdout . $stderr];
    }

    private function scratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/phlix-s183-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $this->scratchDirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }

        rmdir($dir);
    }
}
