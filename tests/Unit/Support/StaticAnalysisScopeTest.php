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

    /**
     * `phpstan-tests.neon` with its comment lines removed.
     *
     * ⚠ Every assertion about that file must run against DIRECTIVES, never against its
     * prose. The first version of the `stubFiles` guard below asserted over the raw text
     * and failed on its own commit, because the comment explaining why `stubFiles` is the
     * wrong key names the key. That is the FOURTH detector in this repo to fire on its own
     * documentation (S105's middleware counter, the phlix-docs `.shell__main` case,
     * CoverageMetadataPolicyTest, this) — so the rule is now explicit: strip the comments,
     * then assert.
     */
    private function phpstanTestsDirectives(): string
    {
        $raw = file_get_contents(self::PHPSTAN_TESTS);
        self::assertIsString($raw);

        $kept = [];
        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }

            $kept[] = $line;
        }

        $directives = implode("\n", $kept);

        // Anti-vacuity: stripping must not have eaten the file. If it did, "does not
        // contain" would be trivially true.
        self::assertStringContainsString('level:', $directives, 'comment stripping ate the config');
        self::assertStringContainsString('paths:', $directives, 'comment stripping ate the config');

        return $directives;
    }

    /**
     * `scanFiles` is an exclusion list wearing different clothes, so it gets the same
     * "assertSame, not assertContains" treatment as the phpcs excludes below: every entry
     * teaches PHPStan about a symbol it would otherwise reject, and one added quietly can
     * hide a genuine `class.notFound`/`function.notFound` — the identifier a plain typo
     * produces.
     *
     * ⚠ The WaitGroup entry is the one that must not be lost. CI's phpstan job installs
     * `extensions: json` only, so without it that job fails with 10 `class.notFound`
     * errors — while this box, which HAS swoole, reports `[OK]`. Measured 2x2:
     *
     * | | entry present | entry absent |
     * | swoole loaded  | [OK]  | [OK] (inert) |
     * | swoole absent  | [OK]  | **10 errors** |
     */
    public function testTheTestsConfigScansOnlyTheDocumentedSymbolFiles(): void
    {
        $cfg = $this->phpstanTestsDirectives();

        preg_match('/^\s*scanFiles:\s*$(?<body>(?:\n\s*-\s*\S+)*)/m', $cfg, $m);
        self::assertArrayHasKey('body', $m, 'phpstan-tests.neon must declare scanFiles');

        preg_match_all('/^\s*-\s*(\S+)\s*$/m', $m['body'], $entries);
        $scanned = $entries[1];
        sort($scanned);

        self::assertSame(
            [
                'phpstan-stubs/Swoole/Coroutine/WaitGroup.stub',
                'scripts/bootstrap_env.php',
            ],
            $scanned,
            'phpstan-tests.neon may scan exactly these files. Each one suppresses a whole '
            . 'class of "symbol not found" error, so adding a third needs its reason in '
            . 'that config and a change to this assertion in the same commit.',
        );

        foreach ($scanned as $rel) {
            self::assertFileExists(
                realpath(self::REPO) . '/' . $rel,
                $rel . ' is scanned by phpstan-tests.neon but does not exist. PHPStan is '
                . 'loud about this, but only when it runs — assert it here too.',
            );
        }

        // ⚠ `stubFiles` was tried FIRST and does not work: a stub only OVERRIDES a class
        // the reflection provider can already find, it does not INTRODUCE an unknown one,
        // so the no-swoole run still reported all 10 errors. Anyone "tidying" this into
        // the more obvious-looking key would silently reintroduce the CI failure, and the
        // local run would stay green. Hence the guard, and the reason beside it.
        self::assertStringNotContainsString(
            'stubFiles:',
            $cfg,   // comment-stripped: the prose above it legitimately names the key
            'do not move these entries to stubFiles: a stub cannot introduce an unknown '
            . 'class, so the no-swoole CI job would go back to 10 class.notFound errors '
            . 'while a local run with ext-swoole stayed green. See the header of '
            . 'phpstan-stubs/Swoole/Coroutine/WaitGroup.stub for the measurement.',
        );
    }

    /**
     * The hand-written `WaitGroup` declaration must keep matching the real extension.
     *
     * This is the one genuine weakness of writing a declaration by hand: it can drift
     * from the class it describes and nothing would notice, because the environment that
     * NEEDS it (CI, no swoole) is exactly the environment that cannot check it. So the
     * check lives here, in the suite, whose CI job DOES load swoole — and skips where
     * there is nothing to compare against.
     */
    public function testTheWaitGroupDeclarationStillMatchesTheRealExtension(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped(
                'ext-swoole is not loaded, so there is no real class to compare against. '
                . 'This is the expected state in CI\'s phpstan job and NOT in its phpunit job.',
            );
        }

        $class = 'Swoole\Coroutine\WaitGroup';
        self::assertTrue(class_exists($class), $class . ' must exist when swoole is loaded');

        $real = new \ReflectionClass($class);

        // Anti-circularity: prove we are reflecting the EXTENSION's class and not some
        // other declaration that happened to get autoloaded — including, in principle,
        // the scanned file itself. It is a PHP class inside swoole's bundled library,
        // which is exactly why phpstorm-stubs cannot ship it.
        self::assertStringContainsString(
            'swoole',
            (string) $real->getFileName(),
            'the reflected WaitGroup must be the one swoole ships, or this comparison is '
            . 'circular and proves nothing',
        );

        $file = file_get_contents(
            realpath(self::REPO) . '/phpstan-stubs/Swoole/Coroutine/WaitGroup.stub',
        );
        self::assertIsString($file);

        // Whitespace-insensitive so reformatting is not a failure, but types and default
        // values are compared exactly — those are the parts that matter to the analyser.
        $squash = static fn (string $s): string => (string) preg_replace('/\s+/', '', $s);
        $haystack = $squash($file);

        $expectedNames = [];
        foreach ($real->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $expectedNames[] = $method->getName();

            $params = [];
            foreach ($method->getParameters() as $param) {
                $type = $param->getType();
                $piece = ($type instanceof \ReflectionNamedType ? $type->getName() . ' ' : '')
                    . '$' . $param->getName();
                if ($param->isDefaultValueAvailable()) {
                    $piece .= ' = ' . var_export($param->getDefaultValue(), true);
                }
                $params[] = $piece;
            }

            $returnType = $method->getReturnType();
            $signature = 'public function ' . $method->getName()
                . '(' . implode(', ', $params) . ')'
                . ($returnType instanceof \ReflectionNamedType ? ': ' . $returnType->getName() : '');

            self::assertStringContainsString(
                $squash($signature),
                $haystack,
                sprintf(
                    "phpstan-stubs/Swoole/Coroutine/WaitGroup.stub has drifted from the real "
                    . "ext-swoole %s. Expected to find:\n    %s\nRe-derive it with "
                    . 'ReflectionClass rather than editing by hand — the whole point of that '
                    . 'file is that it was not designed, it was dumped.',
                    phpversion('swoole') ?: 'swoole',
                    $signature,
                ),
            );
        }

        // Anti-vacuity: if reflection returned nothing, every assertion above passed
        // without comparing anything.
        self::assertGreaterThanOrEqual(
            4,
            count($expectedNames),
            'reflection found almost no public methods on WaitGroup, so the comparison above '
            . 'was vacuous',
        );

        // And the declaration must not claim MORE than the real class has: an invented
        // method would let a test call something that does not exist at run time and still
        // pass analysis.
        preg_match_all('/public function (\w+)\s*\(/', $file, $declared);
        sort($expectedNames);
        $declaredNames = $declared[1];
        sort($declaredNames);
        self::assertSame(
            $expectedNames,
            $declaredNames,
            'the declared public method set must equal the real one exactly — a method '
            . 'listed here but absent from the extension would pass analysis and fail at '
            . 'run time',
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
