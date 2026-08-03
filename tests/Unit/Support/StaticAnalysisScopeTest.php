<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * S128 — `tests/` is analysed and style-gated, and CI is what proves it.
 *
 * ## The defect, and the trap inside the defect
 *
 * Before S128, `tests/` was read by neither tool. Two brand-new S121 test files (451
 * and 358 lines) passed every gate without phpstan or phpcs ever opening them, in a
 * repo whose acceptance criteria are almost entirely test evidence.
 *
 * The obvious fix — "`phpstan.neon` says `paths: [src]`, so add `tests`" — would NOT
 * have worked, and would have looked like it had. The CI step is
 *
 *     ./vendor/bin/phpstan analyze src/ --level=9 …
 *
 * and a path on the COMMAND LINE overrides `parameters.paths` completely. Editing the
 * config would have changed local runs, left CI analysing exactly `src/`, and produced
 * a green gate plus a config file that misdescribed its own scope. That is why this
 * test asserts the WORKFLOW rather than the configs, and why it also asserts that the
 * pre-existing `src/` steps are still there: the change had to be additive, and a
 * "widening" that quietly REPLACED the src/ gate would otherwise pass unnoticed.
 *
 * ## Measured fallout, which is why there are two configs and not one
 *
 * `tests/` on origin/master @ 12bdea6c:
 *
 * | gate                        | errors |
 * | --------------------------- | ------ |
 * | PHPStan level 9             |    532 |
 * | PHPStan level 4             |     86 |
 * | PHPStan level 2 (chosen)    |     51 -> all fixed, 0 |
 * | PHPCS PSR-12                |   2155 -> 25 fixed + 2 carved out, 0 |
 *
 * One uniform level was never available. `src/` keeps level 9 with no baseline and no
 * `ignoreErrors`; `tests/` gets its own config at level 2. Both of those invariants are
 * asserted below, because "src/ keeps level 9" is the load-bearing half of S128's
 * acceptance criteria and a second config is exactly the shape that could erode it.
 */
final class StaticAnalysisScopeTest extends TestCase
{
    private const REPO = __DIR__ . '/../../..';

    private const WORKFLOW = self::REPO . '/.github/workflows/coding-standards.yml';

    private const PHPSTAN_SRC = self::REPO . '/phpstan.neon';

    private const PHPSTAN_TESTS = self::REPO . '/phpstan-tests.neon';

    private const PHPCS_TESTS = self::REPO . '/phpcs-tests.xml';

    /**
     * Every `run:` line in the workflow, flattened, with its job and step name.
     *
     * @return list<array{job: string, step: string, run: string}>
     */
    private function runSteps(): array
    {
        $raw = file_get_contents(self::WORKFLOW);
        self::assertIsString($raw, 'the coding-standards workflow must be readable');

        $parsed = Yaml::parse($raw);
        self::assertIsArray($parsed);
        self::assertIsArray($parsed['jobs'] ?? null, 'the workflow must declare jobs');

        $out = [];
        foreach ($parsed['jobs'] as $jobName => $job) {
            if (!is_array($job) || !is_array($job['steps'] ?? null)) {
                continue;
            }

            foreach ($job['steps'] as $step) {
                if (!is_array($step) || !is_string($step['run'] ?? null)) {
                    continue;
                }

                $out[] = [
                    'job' => (string) $jobName,
                    'step' => is_string($step['name'] ?? null) ? $step['name'] : '(unnamed)',
                    'run' => $step['run'],
                ];
            }
        }

        // Anti-vacuity: every assertion below is a search over this list, so an empty
        // or near-empty list would make all of them pass while proving nothing.
        self::assertGreaterThan(
            8,
            count($out),
            'the workflow parse produced almost no run steps, so every search below is vacuous',
        );

        return $out;
    }

    /**
     * @return list<string> the `run` bodies whose text contains every fragment
     */
    private function stepsRunning(string ...$fragments): array
    {
        $hits = [];
        foreach ($this->runSteps() as $step) {
            foreach ($fragments as $fragment) {
                if (!str_contains($step['run'], $fragment)) {
                    continue 2;
                }
            }

            $hits[] = $step['run'];
        }

        return $hits;
    }

    public function testCiRunsPhpcsOnTests(): void
    {
        $hits = $this->stepsRunning('phpcs', 'phpcs-tests.xml');

        self::assertCount(
            1,
            $hits,
            'exactly one CI step must sniff tests/ via phpcs-tests.xml. Without it, '
            . 'tests/ is style-gated by nothing — which is the whole of S128.',
        );

        // ⚠ `-n` would suppress WARNINGS entirely. That is the S109 defect verbatim: it
        // hid 8 real PSR-12 warnings in src/ for months. The ruleset makes warnings
        // non-blocking WITHOUT hiding them; `-n` is not the same thing.
        self::assertStringNotContainsString(
            ' -n',
            $hits[0],
            'do not pass -n: it deletes warnings from the report (S109) rather than '
            . 'making them non-blocking, which phpcs-tests.xml already does',
        );

        foreach ([' || true', '|| exit 0', 'continue-on-error'] as $escape) {
            self::assertStringNotContainsString(
                $escape,
                $hits[0],
                'a gate that cannot fail is not a gate',
            );
        }
    }

    public function testCiRunsPhpstanOnTests(): void
    {
        $hits = $this->stepsRunning('phpstan', 'phpstan-tests.neon');

        self::assertCount(
            1,
            $hits,
            'exactly one CI step must analyse tests/ via phpstan-tests.neon',
        );

        // The level must come from the config, not the command line, or the two can
        // disagree and the config's documented ladder becomes decoration.
        self::assertStringNotContainsString(
            '--level',
            $hits[0],
            'do not pass --level here: a CLI level overrides the config and lets the '
            . 'shipped level drift away from the one phpstan-tests.neon documents',
        );

        foreach ([' || true', '|| exit 0'] as $escape) {
            self::assertStringNotContainsString($escape, $hits[0], 'a gate that cannot fail is not a gate');
        }
    }

    /**
     * The new gates must be ADDITIONS. A change that widened coverage by REPLACING the
     * src/ steps would satisfy the two tests above and quietly lower the bar.
     */
    public function testTheSrcGatesAreStillThereUnchanged(): void
    {
        self::assertCount(
            1,
            $this->stepsRunning('phpcs', '--standard=PSR12', 'src/'),
            'the src/ phpcs gate must still run unmodified PSR-12 over src/',
        );

        self::assertCount(
            1,
            $this->stepsRunning('phpstan', 'src/', '--level=9'),
            'the src/ phpstan gate must still run at level 9 over src/',
        );
    }

    public function testSrcKeepsLevelNineWithNoBaselineAndNoIgnoreList(): void
    {
        $src = file_get_contents(self::PHPSTAN_SRC);
        self::assertIsString($src);

        self::assertMatchesRegularExpression('/^\s*level:\s*9\s*$/m', $src, 'src/ must stay at level 9');
        self::assertMatchesRegularExpression('/^\s*paths:\s*$/m', $src);
        self::assertMatchesRegularExpression('/^\s*-\s*src\s*$/m', $src, 'src/ must be in the src config paths');

        // S128's hardest rule: never a baseline for src/.
        foreach (['phpstan-baseline.neon', 'psalm-baseline.xml'] as $baseline) {
            self::assertFileDoesNotExist(
                self::REPO . '/' . $baseline,
                $baseline . ' must not exist — fix errors at the source. The workflow '
                . 'asserts this too; both checks are deliberate duplication.',
            );
        }

        self::assertStringNotContainsString(
            'includes:',
            $src,
            'phpstan.neon must not include another config — that is how a baseline '
            . 'gets in without a file named "baseline"',
        );
    }

    public function testTheTestsConfigTargetsTestsAtADocumentedLevelWithNoIgnoreList(): void
    {
        $cfg = file_get_contents(self::PHPSTAN_TESTS);
        self::assertIsString($cfg);

        self::assertMatchesRegularExpression('/^\s*-\s*tests\s*$/m', $cfg, 'it must analyse tests/');
        self::assertMatchesRegularExpression('/^\s*level:\s*\d+\s*$/m', $cfg, 'it must pin a level');

        // A config-level ignore list is invisible at the code it excuses and matches by
        // pattern, so it keeps excusing new code. The seven suppressions this change
        // does carry are all INLINE, each with its identifier, and PHPStan's default
        // reportUnmatchedIgnoredErrors makes every one of them self-clearing.
        self::assertMatchesRegularExpression(
            '/^\s*ignoreErrors:\s*\[\s*\]\s*$/m',
            $cfg,
            'phpstan-tests.neon must keep an EMPTY ignoreErrors list. If the fallout '
            . 'cannot be fixed at the source, lower the level and record the new '
            . 'position on the ladder in that file — do not start a baseline.',
        );

        self::assertStringNotContainsString('includes:', $cfg, 'no included baseline');

        // The ladder is the rationale S128's AC asks for; it must survive edits.
        self::assertStringContainsString(
            'level 9 -> 532',
            $cfg,
            'the measured level ladder must stay in phpstan-tests.neon — it is the '
            . 'documented rationale for the level that was chosen',
        );
    }

    public function testThePhpcsTestsRulesetIsScopedAndExcludesOnlyTheDocumentedSniffs(): void
    {
        $doc = new DOMDocument();
        self::assertTrue($doc->load(self::PHPCS_TESTS), 'phpcs-tests.xml must parse');

        $root = $doc->documentElement;
        self::assertNotNull($root);

        $files = [];
        foreach ($root->getElementsByTagName('file') as $file) {
            $files[] = trim($file->textContent);
        }
        self::assertSame(['tests'], $files, 'the ruleset must target exactly tests/');

        $bases = [];
        foreach ($root->getElementsByTagName('rule') as $rule) {
            self::assertInstanceOf(DOMElement::class, $rule);
            $bases[] = $rule->getAttribute('ref');
        }
        self::assertContains('PSR12', $bases, 'the ruleset must be based on PSR-12');

        $excluded = [];
        foreach ($root->getElementsByTagName('exclude') as $exclude) {
            self::assertInstanceOf(DOMElement::class, $exclude);
            $excluded[] = $exclude->getAttribute('name');
        }
        sort($excluded);

        // ⚠ assertSame, not assertContains. An exclusion list that can grow silently IS
        // a baseline; each addition has to change this list and justify itself in review.
        self::assertSame(
            [
                'PSR1.Classes.ClassDeclaration.MultipleClasses',
                'PSR1.Methods.CamelCapsMethodName',
            ],
            $excluded,
            'phpcs-tests.xml may exclude exactly these two sniffs. Both are justified in '
            . 'that file with a classified count. To add a third, put the count and the '
            . 'classification in the ruleset and change this assertion in the same commit.',
        );

        // Warnings must stay REPORTED. This is the setting that makes that possible
        // without `-n`; deleting it turns the gate permanently red over 288 pre-existing
        // warnings, and the next person reaches for `-n`.
        $configs = [];
        foreach ($root->getElementsByTagName('config') as $config) {
            self::assertInstanceOf(DOMElement::class, $config);
            $configs[$config->getAttribute('name')] = $config->getAttribute('value');
        }
        self::assertSame(
            '1',
            $configs['ignore_warnings_on_exit'] ?? null,
            'phpcs-tests.xml must set ignore_warnings_on_exit=1 so warnings are printed '
            . 'and counted but do not fail the gate. Do NOT replace it with -n.',
        );
    }
}
