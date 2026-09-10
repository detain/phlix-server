<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * S253 — the Web UI job is a bundle gate, and this test is that gate's gate.
 *
 * ## The defect
 *
 * `web-ui/vite.config.ts` builds with `outDir: ../public/assets/app` and
 * `emptyOutDir: true`, so `npm run build` writes DIRECTLY into the committed
 * tree. Before S253 the job built that bundle and threw the verdict away —
 * nothing ever compared the rebuilt tree against the committed one — and its
 * `paths:` filter omitted `public/assets/app/**` entirely, so a bundle-only
 * commit fired the workflow for nothing (the ":5384 hole"). S253 measured
 * master's bundle genuinely stale behind a green "Web UI Build" run: the hole
 * was live, not theoretical.
 *
 * ## What the workflow now pins, and what this test pins about it
 *
 * | step                                        | invariant asserted here                    |
 * | ------------------------------------------- | ------------------------------------------ |
 * | Setup Node                                  | exact 24.x `node-version`, never floating  |
 * | Report bundle corpus size                   | a zero tracked corpus is `exit 1`          |
 * | List bundle drift (diagnostic, never…)      | `git diff --name-status`, never `--exit-code` |
 * | Fail if the committed bundle is stale…      | verbatim `git diff --exit-code …`          |
 * | Fail if the emitted tree… not the same set  | `comm -3` index-vs-disk, ignore-blind      |
 *
 * plus the widened `push.paths`/`pull_request.paths` and the five-name gate
 * ORDER — asserted over the Symfony-Yaml PARSE of the real file (both `['on']`
 * and `[true]`, because unquoted `on:` is a YAML 1.1 boolean and some parsers
 * key it that way), not over its comments, because a comment can say anything
 * while the YAML does something else.
 *
 * ## A nothing-matched defence needs its OWN guard
 *
 * Every pin above is a search over a parsed structure, so a pin that cannot
 * fail is the S146 theatre this repo already outlawed. The negative fuzz at the
 * bottom feeds each predicate deliberately broken mutants of the PARSED
 * workflow — compare step deleted, node pin floated to `24`, `public/assets/app/**`
 * dropped from `push.paths` — and asserts every one is falsified, mirroring how
 * {@see StaticAnalysisScopeTest} proves its pins bite.
 */
final class WebUiBundleGateTest extends TestCase
{
    /**
     * S253 premerge survival token — code-resident only, never in markdown. The
     * merge ritual verifies this literal survives into master's copy of this exact
     * file, and separately into the inline comment on the stale-bundle `run:` line
     * asserted below: premerge.sh strips only WHOLE-LINE comments from .yml files,
     * so a token trailing a code line is what survives the strip.
     */
    public const SURVIVAL_TOKEN = 'S253SRPGATEX7H8';

    private const WORKFLOW = __DIR__ . '/../../../.github/workflows/web-ui.yml';

    /** The gate's precondition lives here, not in the workflow — pin it at its source. */
    private const VITE_CONFIG = __DIR__ . '/../../../web-ui/vite.config.ts';

    private const VITE_OUT_DIR_LITERAL = "outDir: resolve(__dirname, '../public/assets/app')";

    private const VITE_EMPTY_OUTDIR_LITERAL = 'emptyOutDir: true';

    private const BUILD_STEP = 'Build SPA (vue-tsc --noEmit && vite build)';

    private const CORPUS_STEP = 'Report bundle corpus size';

    private const DIAGNOSTIC_STEP = 'List bundle drift (diagnostic, never the verdict)';

    private const COMPARE_STEP = 'Fail if the committed bundle is stale for this source';

    private const SET_CHECK_STEP = 'Fail if the emitted tree and the committed tree are not the same set';

    /** The byte-exact verdict command; YAML strips the inline survival-token comment that follows it. */
    private const COMPARE_COMMAND = 'git diff --exit-code -- public/assets/app/';

    /**
     * Both trigger events must name every one of these. Dropping the bundle path is
     * what reopens the :5384 hole; dropping the workflow path would stop the gate
     * testing its own changes.
     *
     * @var list<string>
     */
    private const REQUIRED_PATHS = ['web-ui/**', 'public/assets/app/**', '.github/workflows/web-ui.yml'];

    private function workflowRaw(): string
    {
        $raw = file_get_contents(self::WORKFLOW);
        self::assertIsString($raw, 'the web-ui workflow must be readable');

        return $raw;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parsedWorkflow(): array
    {
        $parsed = Yaml::parse($this->workflowRaw());
        self::assertIsArray($parsed, 'the web-ui workflow must parse as YAML');

        return $parsed;
    }

    /**
     * The parser-proof key under which the `on:` section lives: an unquoted `on:` is
     * a YAML 1.1 boolean, so some parsers store it as `true` (PHP casts that to the
     * int key 1) rather than the string `'on'`. Both shapes must resolve.
     *
     * @param array<array-key, mixed> $parsed
     */
    private function triggerKey(array $parsed): int|string|null
    {
        if (is_array($parsed['on'] ?? null)) {
            return 'on';
        }

        return is_array($parsed[1] ?? null) ? 1 : null;
    }

    /**
     * @param array<array-key, mixed> $parsed
     *
     * @return array<array-key, mixed>|null
     */
    private function triggers(array $parsed): ?array
    {
        $key = $this->triggerKey($parsed);
        if ($key === null) {
            return null;
        }

        $triggers = $parsed[$key];

        return is_array($triggers) ? $triggers : null;
    }

    /**
     * Every step of every job, in declaration order.
     *
     * @param array<array-key, mixed> $parsed
     *
     * @return list<array<array-key, mixed>>
     */
    private function steps(array $parsed): array
    {
        $out = [];

        $jobs = $parsed['jobs'] ?? null;
        if (!is_array($jobs)) {
            return $out;
        }

        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }

            $steps = $job['steps'] ?? null;
            if (!is_array($steps)) {
                continue;
            }

            foreach ($steps as $step) {
                if (is_array($step)) {
                    $out[] = $step;
                }
            }
        }

        return $out;
    }

    /**
     * Step names in workflow order, unnamed steps as '' so positions stay meaningful.
     *
     * @param array<array-key, mixed> $parsed
     *
     * @return list<string>
     */
    private function stepNames(array $parsed): array
    {
        $names = [];
        foreach ($this->steps($parsed) as $step) {
            $name = $step['name'] ?? null;
            $names[] = is_string($name) ? $name : '';
        }

        return $names;
    }

    /**
     * The unique `run:` body of the step named exactly $stepName; null when the name
     * is absent, duplicated or its run is not a string — ambiguity is as broken as
     * deletion.
     *
     * @param array<array-key, mixed> $parsed
     */
    private function stepRun(string $stepName, array $parsed): ?string
    {
        $hits = [];
        foreach ($this->steps($parsed) as $step) {
            if (($step['name'] ?? null) !== $stepName) {
                continue;
            }

            $run = $step['run'] ?? null;
            if (!is_string($run)) {
                return null;
            }

            $hits[] = $run;
        }

        return count($hits) === 1 ? $hits[0] : null;
    }

    // ------------------------------------------------------------- predicates
    // Pure over the parsed array, so the negative fuzz can call them on mutants.

    /** @param array<array-key, mixed> $parsed */
    private function pathsWidenToBundle(array $parsed): bool
    {
        $triggers = $this->triggers($parsed);
        if ($triggers === null) {
            return false;
        }

        foreach (['push', 'pull_request'] as $event) {
            $eventConfig = $triggers[$event] ?? null;
            if (!is_array($eventConfig)) {
                return false;
            }

            $paths = $eventConfig['paths'] ?? null;
            if (!is_array($paths)) {
                return false;
            }

            foreach (self::REQUIRED_PATHS as $needle) {
                if (!in_array($needle, $paths, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<array-key, mixed> $parsed */
    private function nodePinIsExact(array $parsed): bool
    {
        foreach ($this->steps($parsed) as $step) {
            $with = $step['with'] ?? null;
            if (!is_array($with) || !array_key_exists('node-version', $with)) {
                continue;
            }

            $pin = $with['node-version'];

            return is_string($pin)
                && preg_match('/^\d+\.\d+\.\d+$/', $pin) === 1
                && str_starts_with($pin, '24.');
        }

        return false;
    }

    /** @param array<array-key, mixed> $parsed */
    private function gateOrderIsSound(array $parsed): bool
    {
        $names = $this->stepNames($parsed);

        $cursor = -1;
        foreach (
            [
                self::BUILD_STEP,
                self::CORPUS_STEP,
                self::DIAGNOSTIC_STEP,
                self::COMPARE_STEP,
                self::SET_CHECK_STEP,
            ] as $required
        ) {
            $index = array_search($required, $names, true);
            if (!is_int($index) || $index <= $cursor) {
                return false;
            }

            $cursor = $index;
        }

        return true;
    }

    /** @param array<array-key, mixed> $parsed */
    private function compareStepIsVerbatim(array $parsed): bool
    {
        $run = $this->stepRun(self::COMPARE_STEP, $parsed);

        return $run !== null && trim($run) === self::COMPARE_COMMAND;
    }

    /** @param array<array-key, mixed> $parsed */
    private function corpusStepFailsAtZero(array $parsed): bool
    {
        $run = $this->stepRun(self::CORPUS_STEP, $parsed);
        if ($run === null) {
            return false;
        }

        foreach (['TRACKED', '-eq 0', 'exit 1'] as $needle) {
            if (!str_contains($run, $needle)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, mixed> $parsed */
    private function setCheckComparesIndexToDisk(array $parsed): bool
    {
        $run = $this->stepRun(self::SET_CHECK_STEP, $parsed);
        if ($run === null) {
            return false;
        }

        // Review finding 2: `--exclude-standard` would let a future .gitignore rule
        // hollow the check — the comparison must be index-vs-disk, ignore-blind.
        if (str_contains($run, '--exclude-standard')) {
            return false;
        }

        foreach (
            [
                'LC_ALL=C comm -3',
                'git ls-files -- public/assets/app',
                'find public/assets/app \\( -type f -o -type l \\)',
                'LC_ALL=C sort',
                'exit 1',
            ] as $needle
        ) {
            if (!str_contains($run, $needle)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, mixed> $parsed */
    private function diagnosticNeverVerdict(array $parsed): bool
    {
        $run = $this->stepRun(self::DIAGNOSTIC_STEP, $parsed);
        if ($run === null) {
            return false;
        }

        return str_contains($run, 'git diff --name-status')
            && str_contains($run, 'comm -3')
            && str_contains($run, "|| DIVERGENT=''")
            && !str_contains($run, '--exit-code')
            && !str_contains($run, 'exit 1');
    }

    /**
     * The precondition predicate: the build must emit INTO the tracked tree and
     * must empty it first, or the compare step grades a tree it never wrote.
     */
    private function buildWritesIntoTrackedTree(string $config): bool
    {
        return str_contains($config, self::VITE_OUT_DIR_LITERAL)
            && str_contains($config, self::VITE_EMPTY_OUTDIR_LITERAL);
    }

    // ---------------------------------------------------------------- mutants
    // Structured edits of the PARSED array, each one exactly one member of the
    // silent-regression class the pins exist for.

    /**
     * @param array<array-key, mixed> $parsed
     *
     * @return array<array-key, mixed>
     */
    private function withoutStep(array $parsed, string $stepName): array
    {
        $jobs = $parsed['jobs'] ?? null;
        self::assertIsArray($jobs, 'the step-drop mutant needs the parsed jobs to exist');

        $mutantJobs = [];
        $removed = false;
        foreach ($jobs as $jobName => $job) {
            if (!is_array($job) || !is_array($job['steps'] ?? null)) {
                $mutantJobs[$jobName] = $job;
                continue;
            }

            $kept = [];
            foreach ($job['steps'] as $step) {
                if (is_array($step) && ($step['name'] ?? null) === $stepName) {
                    $removed = true;
                    continue;
                }

                $kept[] = $step;
            }

            $job['steps'] = $kept;
            $mutantJobs[$jobName] = $job;
        }

        self::assertTrue($removed, "the step-drop mutant must actually find '{$stepName}' to drop");

        $mutant = $parsed;
        $mutant['jobs'] = $mutantJobs;

        return $mutant;
    }

    /**
     * @param array<array-key, mixed> $parsed
     *
     * @return array<array-key, mixed>
     */
    private function floatedNodePin(array $parsed): array
    {
        $jobs = $parsed['jobs'] ?? null;
        self::assertIsArray($jobs, 'the node-float mutant needs the parsed jobs to exist');

        $mutantJobs = [];
        $floated = false;
        foreach ($jobs as $jobName => $job) {
            if (!is_array($job) || !is_array($job['steps'] ?? null)) {
                $mutantJobs[$jobName] = $job;
                continue;
            }

            $mutantSteps = [];
            foreach ($job['steps'] as $step) {
                if (is_array($step)) {
                    $with = $step['with'] ?? null;
                    if (is_array($with) && array_key_exists('node-version', $with)) {
                        $with['node-version'] = '24';
                        $step['with'] = $with;
                        $floated = true;
                    }
                }

                $mutantSteps[] = $step;
            }

            $job['steps'] = $mutantSteps;
            $mutantJobs[$jobName] = $job;
        }

        self::assertTrue($floated, 'the node-float mutant must actually find a node-version to float');

        $mutant = $parsed;
        $mutant['jobs'] = $mutantJobs;

        return $mutant;
    }

    /**
     * @param array<array-key, mixed> $parsed
     *
     * @return array<array-key, mixed>
     */
    private function narrowedPushPaths(array $parsed): array
    {
        $key = $this->triggerKey($parsed);
        self::assertNotNull($key, 'the paths-narrowing mutant needs the on: section under either parser shape');

        $triggers = $this->triggers($parsed);
        self::assertIsArray($triggers);

        $push = $triggers['push'] ?? null;
        self::assertIsArray($push);

        $paths = $push['paths'] ?? null;
        self::assertIsArray($paths);

        $narrowed = array_values(array_filter(
            $paths,
            static fn (mixed $path): bool => $path !== 'public/assets/app/**',
        ));
        self::assertNotSame(
            $paths,
            $narrowed,
            'the paths-narrowing mutant must actually remove public/assets/app/** from push.paths',
        );

        $push['paths'] = $narrowed;
        $triggers['push'] = $push;

        $mutant = $parsed;
        $mutant[$key] = $triggers;

        return $mutant;
    }

    // ------------------------------------------------------------------ tests

    public function testSurvivalTokenIsResidentAndIntact(): void
    {
        self::assertSame(
            'S253SRPGATEX7H8',
            self::SURVIVAL_TOKEN,
            'the S253 survival token must stay exactly this literal in this file — the merge '
            . "ritual greps master's copy of THIS path for it",
        );
    }

    public function testWorkflowParsesAndBothTriggersFireOnTheBundleCorpus(): void
    {
        $parsed = $this->parsedWorkflow();

        self::assertSame('Web UI', $parsed['name'] ?? null, 'sanity: this is the Web UI workflow');
        self::assertNotNull(
            $this->triggers($parsed),
            'the on: section must resolve under either parser shape (["on"] or [true])',
        );
        self::assertGreaterThan(
            4,
            count($this->steps($parsed)),
            'anti-vacuity: a near-empty parse would make every search below pass while proving nothing',
        );

        self::assertTrue(
            $this->pathsWidenToBundle($parsed),
            'push.paths and pull_request.paths must each contain web-ui/**, public/assets/app/** and the '
            . 'workflow file itself — without the bundle path a bundle-only commit fires NOTHING, which is '
            . 'the exact :5384 hole S253 was opened for',
        );
    }

    public function testNodeVersionIsAnExactPinAndAFloatingMajorWouldNotPass(): void
    {
        self::assertTrue(
            $this->nodePinIsExact($this->parsedWorkflow()),
            'node-version must be an exact X.Y.Z inside the 24 family. The bundle gate is a byte-exact '
            . '`git diff`, so a floating `24` lets the next node 24.x minor change vite output and turn '
            . 'every PR red for nobody`s fault — the stated reason the S253 comment gives for the pin',
        );
    }

    public function testTheGateStepsRunInTheirOnlyMeaningfulOrder(): void
    {
        self::assertTrue(
            $this->gateOrderIsSound($this->parsedWorkflow()),
            'the build step must precede the corpus-size report, the name-status diagnostic, the byte-exact '
            . 'stale compare and the index-vs-disk set check, in that relative order — the diagnostic before '
            . 'the verdicts so a red run names the diverged files, the corpus guard before any check that '
            . 'could read an empty comparison as success',
        );
    }

    public function testTheCompareStepIsTheVerbatimVerdictAndCarriesTheTokenOnItsLine(): void
    {
        self::assertTrue(
            $this->compareStepIsVerbatim($this->parsedWorkflow()),
            'exactly one step named "' . self::COMPARE_STEP . '" and its run must be verbatim `'
            . self::COMPARE_COMMAND . '` — an added ` || true`, a narrowed path or a reworded command turns '
            . 'the job back into the build-and-throw-away-the-verdict one S253 replaced',
        );

        $lines = array_values(array_filter(
            explode("\n", $this->workflowRaw()),
            static fn (string $line): bool => str_contains($line, self::COMPARE_COMMAND),
        ));

        self::assertCount(1, $lines, 'the verdict command must appear on exactly one workflow line');
        self::assertStringContainsString(
            self::SURVIVAL_TOKEN,
            $lines[0],
            'the premerge survival token must live INLINE on the same line as the verdict command — '
            . 'premerge.sh strips whole-line comments from .yml files, so a token on its own line would '
            . 'not survive the merge ritual',
        );
    }

    public function testTheCorpusStepFailsAtZeroInsteadOfReadingEmptyAsSuccess(): void
    {
        self::assertTrue(
            $this->corpusStepFailsAtZero($this->parsedWorkflow()),
            'the corpus report must count TRACKED files, test -eq 0 and exit 1 on zero — the S146 and '
            . 'coverage-checker lesson: a gate that measured nothing must never report success',
        );
    }

    public function testTheSetCheckComparesIndexAgainstDiskBlindToIgnoreRules(): void
    {
        self::assertTrue(
            $this->setCheckComparesIndexToDisk($this->parsedWorkflow()),
            'the set check must compare `git ls-files -- public/assets/app` against `find public/assets/app '
            . '\( -type f -o -type l \)` with `LC_ALL=C comm -3` (byte-collation pinned, symlink-symmetric) and '
            . 'exit 1, and must never use --exclude-standard — a future .gitignore entry would otherwise '
            . 'hollow the check without touching a line of the gate',
        );
    }

    public function testTheDiagnosticStepCanNeverBecomeTheVerdict(): void
    {
        self::assertTrue(
            $this->diagnosticNeverVerdict($this->parsedWorkflow()),
            'the name-status step exists so a red run prints the diverged files; adding --exit-code would '
            . 'make it a second verdict and split the single gate across two competing steps',
        );
    }

    /**
     * The precondition the whole gate rests on, pinned at its source: `vite build`
     * writes INTO the tracked tree. If `outDir` ever pointed at a scratch directory
     * again, a clean `git diff` would prove nothing about a bundle the build never
     * wrote there — the vacuity door S253 exists to close.
     */
    public function testTheBuildWritesIntoTheTrackedTree(): void
    {
        $config = (string) file_get_contents(self::VITE_CONFIG);
        self::assertNotSame('', $config, 'the vite config must be readable — an empty read vacates both pins');
        self::assertTrue(
            $this->buildWritesIntoTrackedTree($config),
            'web-ui/vite.config.ts must keep writing the bundle directly into public/assets/app/: the '
            . 'gate compares that tree against the build, so the build has to land there',
        );

        $mutant = str_replace(self::VITE_OUT_DIR_LITERAL, "resolve(__dirname, 'dist')", $config);
        self::assertNotSame($config, $mutant, 'the outDir mutant must actually bite the config text');
        self::assertFalse(
            $this->buildWritesIntoTrackedTree($mutant),
            'pointing outDir at a scratch tree must violate the precondition pin — that is the exact shape '
            . 'of the old job, which built, compared nothing, and passed green',
        );

        $mutant = str_replace(self::VITE_EMPTY_OUTDIR_LITERAL, 'emptyOutDir: false', $config);
        self::assertNotSame($config, $mutant, 'the emptyOutDir mutant must actually bite the config text');
        self::assertFalse(
            $this->buildWritesIntoTrackedTree($mutant),
            'leaving stale hashed chunks in the tree must violate the pin too — the set check would then '
            . 'report committed-but-not-emitted for files vite simply never deleted',
        );
    }

    /**
     * The guard's own guard: every predicate must go FALSE on a mutant that is
     * exactly the silent-regression class its pin exists for. A pin that stays TRUE
     * on these mutants is the nothing-matched defence the program law outlaws.
     */
    public function testSilentNarrowingOfTheGateReddensItsOwnPins(): void
    {
        $parsed = $this->parsedWorkflow();

        // Anti-vacuity: every pin holds on the shipped file before any mutation.
        self::assertTrue($this->compareStepIsVerbatim($parsed), 'the shipped compare pin must pass first');
        self::assertTrue($this->gateOrderIsSound($parsed), 'the shipped order pin must pass first');
        self::assertTrue($this->nodePinIsExact($parsed), 'the shipped node-pin guard must pass first');
        self::assertTrue($this->pathsWidenToBundle($parsed), 'the shipped paths guard must pass first');

        $mutant = $this->withoutStep($parsed, self::COMPARE_STEP);
        self::assertNotSame($parsed, $mutant, 'the compare-step mutant must actually bite the parsed workflow');
        self::assertFalse(
            $this->compareStepIsVerbatim($mutant),
            'deleting the compare step must violate the verdict pin — otherwise the '
            . 'build-and-throw-away-the-verdict job comes back unnoticed',
        );
        self::assertFalse(
            $this->gateOrderIsSound($mutant),
            'deleting the compare step must violate the order pin too — the verdict is not interchangeable '
            . 'with the diagnostic that prints almost the same command',
        );

        $mutant = $this->floatedNodePin($parsed);
        self::assertNotSame($parsed, $mutant, 'the node-float mutant must actually bite the parsed workflow');
        self::assertFalse(
            $this->nodePinIsExact($mutant),
            'floating `24` must violate the exact-pin guard — bare `24` is not an X.Y.Z reproducibility '
            . 'promise, and this byte-exact gate is only as honest as the toolchain that produced the tree',
        );

        $mutant = $this->narrowedPushPaths($parsed);
        self::assertNotSame($parsed, $mutant, 'the paths-narrowing mutant must actually bite the parsed workflow');
        self::assertFalse(
            $this->pathsWidenToBundle($mutant),
            'dropping public/assets/app/** from push.paths must violate the corpus pin — that is the exact '
            . 'shape of the :5384 hole, a bundle-only commit whose gate silently never runs',
        );
    }
}
