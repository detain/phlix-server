<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * S128 — `tests/` is analysed and style-gated, and CI is what proves it.
 * S343 — completed the src/ assertion set this class's name and docblock always claimed.
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
 * One uniform level was never available. `src/` keeps level 9 with no baseline, no
 * `ignoreErrors` and an empty `excludePaths`; `tests/` gets its own config at level 2.
 * S128's test asserted only part of the src/ invariant (level, paths, no baseline file,
 * no `includes:`) while its name and docblock claimed the whole conjunction; S343
 * deleted the three stale suppressions in `phpstan.neon` (two excludePaths entries and
 * one `reportUnmatched: false` ignore) and extended this test to assert `ignoreErrors: []`
 * and an empty excludePaths allow-list on the src config, so the guard now asserts
 * everything its name claims.
 *
 * ## S306 — the same trick, one tool over: `tests/` under Psalm
 *
     * Server Psalm was src-only (`psalm.xml` directory `src`), while PHPStan already
     * covered `tests/` — the S128 defect half-ported to the second analyser. Mirroring the
     * hub's S444 precedent (#274): because Psalm 6 admits exactly one `errorLevel` per
     * config file, `tests/` gets a second shipped config, `psalm-tests.xml`, at the
     * measured level 5 (ladder L1 4236 → L8 79 recorded in that file's header; all 290
     * L5 findings fixed at the source except one exclusion), plus a second CI step and
     * the verbatim pins below. (At S306 time `psalm.xml` itself was NOT touched — the
     * server never widened it the way the hub did; S256-residual later added scripts/
     * to it, and the corpus pin below moved with it, exact-listed.)
     * One written-why exclusion (`tests/codeception/acceptance/SyncPlayCest.php`) and one
     * `<stubs>` entry (the drift-tested WaitGroup declaration, single source of truth
     * shared with PHPStan) are allow-listed exactly; both configs keep an absent
     * `ignoreErrors` block, no IssueHandler and no baseline, and the negative fuzz at the
     * bottom proves every one of these pins bites on a silent-scope mutant.
     *
     * ## S256-residual — `scripts/` joins the PRODUCTION corpus in both tools
     *
     * The tests/ halves closed S256's other leg; the measured residual was that
     * phlix-server's `scripts/` (43 PHP files) was analysed by NOTHING: phpstan.neon
     * had paths=[src], phpstan-tests.neon only *scanned* scripts/bootstrap_env.php
     * for its two function symbols, and psalm.xml was src-only. The hub closed this
     * same gap for its own scripts/ under S248; this mirrors that shape.
     *
     * The trap is the S128 trap wearing a new costume: CI's production PHPStan step
     * was `analyze src/ --level=9`, and a CLI path/level overrides the config
     * entirely — widening `paths:` alone would have left CI analysing exactly src/
     * behind a green gate. So the step is now `./vendor/bin/phpstan analyse
     * --no-progress --error-format=github` (no path, no level — the hub's shape),
     * phpstan.neon carries the corpus, and the verbatim pins below are what make
     * that arrangement unbreakable-by-accident: the config's exact paths list, the
     * config's scanFiles allow-list, psalm.xml's exact <directory> set and stub set,
     * the byte-identity of phpstan.neon and phpstan.neon.dist, and the CI command
     * itself. All 47 PHPStan-L9 and all 10 Psalm-L5 findings scripts/ produced were
     * fixed at the source (five of them were real runtime defects: two boot-blocking
     * wrong-constructor-argument TypeErrors, an invalid constant applied mid-iteration,
     * a silently ignored --limit, and a wrong config key); the only config additions are
     * the two path entries and the shared WaitGroup declaration in each tool's sanctioned
     * slot. No baseline, no ignoreErrors, no exclusions.
     */
final class StaticAnalysisScopeTest extends TestCase
{
    /**
     * S306 survival token — code-resident only, never in markdown. The merge
     * ritual verifies this literal survives into master's copy of this exact file.
     */
    public const SURVIVAL_TOKEN = 'S306SRVPALMGATEX5T2';

    /**
     * S256-residual survival token — code-resident only, never in markdown
     * (this file is the PHP corpus; 0 occurrences in any .md anywhere at commit
     * time, and 0 in any other checkout repo-wide — verified before embedding).
     * The merge ritual verifies this literal survives into master's copy of
     * this exact file.
     */
    public const SCRIPTS_SCOPE_TOKEN = 'CS256SCRIPTSSCOPEX9S';

    private const REPO = __DIR__ . '/../../..';

    private const WORKFLOW = self::REPO . '/.github/workflows/coding-standards.yml';

    private const PHPSTAN_SRC = self::REPO . '/phpstan.neon';

    private const PHPSTAN_TESTS = self::REPO . '/phpstan-tests.neon';

    private const PHPCS_TESTS = self::REPO . '/phpcs-tests.xml';

    private const PSALM_CONFIG = self::REPO . '/psalm.xml';

    private const PSALM_TESTS_CONFIG = self::REPO . '/psalm-tests.xml';

    /** The single written-why psalm exclusion — its justification lives in psalm-tests.xml's header. */
    private const EXCLUDED_CEST = 'tests/codeception/acceptance/SyncPlayCest.php';

    /** The single psalm stub — the same file PHPStan scans; one source of truth for the declaration. */
    private const WAITGROUP_STUB = 'phpstan-stubs/Swoole/Coroutine/WaitGroup.stub';

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
     *
     * S256-residual changed the SHAPE of the production PHPStan step (the CLI path and
     * level became config-driven — see the step's own comment and the class docblock),
     * so the old `analyze src/ --level=9` fragment pin is replaced by the stronger one
     * below: the exact command, verbatim, carrying NO path and NO level. What has to
     * stay untouched is the src/ PHPCS gate and the production corpus itself — and
     * "untouched" is now asserted against a step that cannot silently shrink it,
     * because testTheProductionConfigKeepsLevelNine... below pins the paths list
     * exactly and nothing on the command line can override it any more.
     */
    public function testTheSrcGatesAreStillThereUnchanged(): void
    {
        self::assertCount(
            1,
            $this->stepsRunning('phpcs', '--standard=PSR12', 'src/'),
            'the src/ phpcs gate must still run unmodified PSR-12 over src/',
        );

        $hits = $this->stepsRunning('phpstan analyse --no-progress');
        self::assertCount(
            1,
            $hits,
            'exactly one CI step must run the production PHPStan analysis config-driven '
            . '(no path, no level on the command line — S256-residual)',
        );

        self::assertSame(
            './vendor/bin/phpstan analyse --no-progress --error-format=github',
            trim($hits[0]),
            'the production PHPStan step is pinned verbatim; every escape hatch below is '
            . 're-asserted here even if a fragment search misses',
        );

        foreach (['--level', 'src/', 'scripts/', 'tests/', '-c '] as $override) {
            self::assertStringNotContainsString(
                $override,
                $hits[0],
                "a CLI {$override} on the production step overrides the shipped config — "
                . 'that is the S128 trap: the gate would go green over a corpus the config '
                . 'no longer describes',
            );
        }

        foreach ([' || true', '|| exit 0', 'continue-on-error'] as $escape) {
            self::assertStringNotContainsString($escape, $hits[0], 'a gate that cannot fail is not a gate');
        }
    }

    /**
     * `phpstan.neon` / `phpstan.neon.dist` with their comment lines removed, so every
     * directive-level "must not contain" runs against DIRECTIVES only — the rule the
     * `phpstanTestsDirectives()` twin documents (fourth-detector lesson): this config's
     * own comments legitimately name `scanFiles`, `stubFiles` and `--level`.
     */
    private function phpstanSrcDirectives(string $path = self::PHPSTAN_SRC): string
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $kept = [];
        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }

            $kept[] = $line;
        }

        $directives = implode("\n", $kept);

        // Anti-vacuity: stripping must not have eaten the file.
        self::assertStringContainsString('level:', $directives, 'comment stripping ate the config');
        self::assertStringContainsString('paths:', $directives, 'comment stripping ate the config');

        return $directives;
    }

    /**
     * @return list<string> the exact `paths:` entries of a phpstan neon directives string
     */
    private function phpstanPaths(string $directives): array
    {
        preg_match('/^\s*paths:\s*$(?<body>(?:\n\s*-\s*\S+)*)/m', $directives, $m);
        self::assertArrayHasKey('body', $m, 'the production phpstan config must declare paths');

        preg_match_all('/^\s*-\s*(\S+)\s*$/m', $m['body'], $entries);

        return $entries[1];
    }

    public function testSrcKeepsLevelNineWithNoBaselineAndNoIgnoreList(): void
    {
        $src = $this->phpstanSrcDirectives();

        self::assertMatchesRegularExpression(
            '/^\s*level:\s*9\s*$/m',
            $src,
            'the production corpus must stay at level 9',
        );
        self::assertMatchesRegularExpression('/^\s*paths:\s*$/m', $src);

        // S256-residual: assertSame over the EXACT corpus, not assertContains over one
        // entry. Dropping either directory must redden the suite, not just lose
        // coverage silently, and a third path needs the same commit that widens CI.
        self::assertSame(
            ['src', 'scripts'],
            $this->phpstanPaths($src),
            'the production phpstan corpus is exactly [src, scripts]. scripts/ joined in '
            . 'S256-residual; a silent narrowing back to src/ is the '
            . 'gate-that-proves-nothing defect this class exists to stop.',
        );

        // S128's hardest rule: never a baseline for the production corpus.
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

        // S343: the method name and the class docblock always claimed "no ignore list",
        // but the assertions stopped at the baseline file and `includes:`. The three
        // suppressions S343 deleted excused nothing — src/ measured [OK] at level 9 with
        // and without ext-swoole — and the ignore entry carried `reportUnmatched: false`,
        // which means PHPStan could never flag it as dead, so it could never self-clear.
        self::assertMatchesRegularExpression(
            '/^\s*ignoreErrors:\s*\[\s*\]\s*$/m',
            $src,
            'phpstan.neon must keep an EMPTY ignoreErrors list. The entry S343 deleted '
            . 'excused nothing (measured [OK] with and without swoole) and carried '
            . 'reportUnmatched: false, so it could never self-clear — an ignore list '
            . 'that cannot self-clear is a baseline by another name. Fix errors at the '
            . 'source instead.',
        );

        // An exclusion list is an ignore list in disguise: a path added here silently
        // disappears from analysis. Same assertSame treatment as the scanFiles and
        // phpcs-tests.xml allow-lists above, plus an anti-vacuity guard so a deleted
        // key fails rather than passing.
        preg_match('/^\s*excludePaths:\s*\[\s*\](?<body>(?:\n\s*-\s*\S+)*)/m', $src, $m);
        self::assertArrayHasKey('body', $m, 'phpstan.neon must declare excludePaths as the '
            . 'EMPTY inline list `excludePaths: []` — S343 deleted the two stale entries. '
            . 'A path added here, in any form, silently disappears from analysis.');
        preg_match_all('/^\s*-\s*(\S+)\s*$/m', $m['body'], $entries);
        self::assertSame(
            [],
            $entries[1],
            'phpstan.neon must keep an EMPTY excludePaths allow-list. A path added here '
            . 'silently disappears from analysis; the two entries S343 deleted excused '
            . 'nothing. To exclude a path, fix the errors at the source and justify the '
            . 'exclusion in review.',
        );
    }

    /**
     * phpstan.neon and phpstan.neon.dist both exist, so PHPStan picks the local one
     * and the dist one is the shipped default. Two copies of the same law are only a
     * law while they are byte-identical — the day they diverge, which corpus a run
     * covered depends on which file the runner happened to see. S256-residual widened
     * BOTH; this pin is what keeps the next change from widening one.
     */
    public function testTheTwoProductionPhpstanConfigsAreByteIdentical(): void
    {
        $local = file_get_contents(self::PHPSTAN_SRC);
        $dist = file_get_contents(self::PHPSTAN_SRC . '.dist');

        self::assertIsString($local);
        self::assertIsString($dist);
        self::assertSame(
            $local,
            $dist,
            'phpstan.neon and phpstan.neon.dist must stay byte-identical.',
        );
    }

    /**
     * The production config's scanFiles is the same allow-listed mechanism
     * phpstan-tests.neon documents at length. It is NOT a mute: it introduces a symbol
     * the analysing environment genuinely lacks — swoole's USERLAND WaitGroup class,
     * invisible to bundled phpstorm-stubs because stubs describe EXTENSION classes.
     * scripts/bench/coroutine_bench.php is why the production corpus needs it too: it
     * constructs a WaitGroup behind an extension_loaded('swoole') guard, and CI's
     * phpstan job runs without swoole. The declaration stays honest through
     * testTheWaitGroupDeclarationStillMatchesTheRealExtension (phpunit job, swoole ON).
     */
    public function testTheProductionConfigScansOnlyTheDocumentedSymbolFiles(): void
    {
        $cfg = $this->phpstanSrcDirectives();

        preg_match('/^\s*scanFiles:\s*$(?<body>(?:\n\s*-\s*\S+)*)/m', $cfg, $m);
        self::assertArrayHasKey('body', $m, 'phpstan.neon must declare scanFiles (the WaitGroup entry)');

        preg_match_all('/^\s*-\s*(\S+)\s*$/m', $m['body'], $entries);
        $scanned = $entries[1];
        sort($scanned);

        self::assertSame(
            [self::WAITGROUP_STUB],
            $scanned,
            'phpstan.neon may scan exactly the drift-tested WaitGroup declaration. Each '
            . 'scanFiles entry teaches PHPStan about a symbol it would otherwise reject, '
            . 'so adding one needs its reason in that config and a change to this '
            . 'assertion in the same commit.',
        );

        foreach ($scanned as $rel) {
            self::assertFileExists(self::REPO . '/' . $rel);
        }

        // Same measured trap as the tests config: a stub only OVERRIDES a class
        // reflection can already find, it cannot INTRODUCE an unknown one — moving
        // this entry to stubFiles would silently reopen the CI no-swoole failure
        // while a local run with ext-swoole stayed green.
        self::assertStringNotContainsString(
            'stubFiles:',
            $cfg,
            'do not move the scanFiles entry to stubFiles: a stub cannot introduce an '
            . 'unknown class, so the no-swoole CI job would go back to class.notFound '
            . 'errors on Swoole\\Coroutine\\WaitGroup while a local run stayed green.',
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

    // ------------------------------------------------------- S306 — psalm scope

    /**
     * The line-based twin of `runSteps()` for the negative fuzz: step lookup by exact
     * name over injectable workflow TEXT, so mutants can prove the pins bite without
     * ever touching the shipped file. (`if:` lines sit between `name:` and `run:` in
     * this workflow, hence the three-line probe window.)
     */
    /**
     * @return list<string> every `run:` body found under a step named exactly $stepName,
     *                      without asserting the count — the negative fuzz needs to see
     *                      zero hits on a deleted-step mutant, and the S120 escape guard
     *                      forbids catching the AssertionFailedError a count assert throws.
     */
    private function workflowStepRunBodies(string $stepName, ?string $workflow = null): array
    {
        $lines = explode("\n", $workflow ?? $this->workflowContents());
        $hits = [];

        foreach ($lines as $index => $line) {
            if (trim($line) !== "- name: {$stepName}") {
                continue;
            }

            for ($probe = $index + 1; $probe < count($lines) && $probe < $index + 4; $probe++) {
                if (preg_match('/^\s*run:\s*(.+)$/', $lines[$probe], $match) === 1) {
                    $hits[] = $match[1];
                    break;
                }
            }
        }

        return $hits;
    }

    private function workflowStepRun(string $stepName, ?string $workflow = null): string
    {
        $hits = $this->workflowStepRunBodies($stepName, $workflow);
        self::assertCount(1, $hits, "exactly one CI step must be named '{$stepName}' and carry a run: line");

        return $hits[0];
    }

    private function workflowText(string $stepName): bool
    {
        return preg_match(
            '/^      - name: ' . preg_quote($stepName, '/') . '\n        if: always\(\)$/m',
            $this->workflowContents(),
        ) === 1;
    }

    private function workflowContents(): string
    {
        $raw = file_get_contents(self::WORKFLOW);
        self::assertIsString($raw, 'the coding-standards workflow must be readable');

        return $raw;
    }

    private function psalmConfigText(string $path): string
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw, "{$path} must be readable");

        return $raw;
    }

    /**
     * @return array{0: string} the inner text of <element>…</element>
     */
    private function xmlBlock(string $config, string $element): array
    {
        $pattern = '#<' . $element . '>(.*?)</' . $element . '>#s';
        if (preg_match($pattern, $config, $match) !== 1) {
            self::fail("the psalm config must declare <{$element}>");
        }

        return [$match[1]];
    }

    /**
     * <directory> names directly under projectFiles but NOT inside its <ignoreFiles>
     * child (vendor stays out of the asserted corpus list).
     *
     * @param array{0: string} $projectFiles
     *
     * @return list<string>
     */
    private function xmlDirectoryNames(array $projectFiles): array
    {
        $body = (string) preg_replace('#<ignoreFiles>.*?</ignoreFiles>#s', '', $projectFiles[0]);

        preg_match_all('#<directory name="([^"]+)"\s*/>#s', $body, $names);

        return $names[1];
    }

    /**
     * <file> entries inside projectFiles/<ignoreFiles>.
     *
     * @param array{0: string} $projectFiles
     *
     * @return list<string>
     */
    private function xmlFileNames(array $projectFiles): array
    {
        if (preg_match('#<ignoreFiles>(.*?)</ignoreFiles>#s', $projectFiles[0], $ignored) !== 1) {
            return [];
        }

        preg_match_all('#<file name="([^"]+)"\s*/>#s', $ignored[1], $names);

        return $names[1];
    }

    public function testTheSurvivalTokenIsResidentAndIntact(): void
    {
        self::assertSame(
            'S306SRVPALMGATEX5T2',
            self::SURVIVAL_TOKEN,
            'the S306 survival token must stay exactly this literal in this file — the merge '
            . 'ritual greps master\'s copy of THIS path for it',
        );
    }

    /**
     * S256-residual survival token. Same ritual as the S306 one above: code-resident
     * only (this file is in the PHP corpus the analysers read; the literal appears in
     * ZERO .md files, and was collision-checked against every checkout under
     * /home/sites/phlix before being embedded), verified to survive into master.
     */
    public function testTheScriptsScopeTokenIsResidentAndIntact(): void
    {
        self::assertSame(
            'CS256SCRIPTSSCOPEX9S',
            self::SCRIPTS_SCOPE_TOKEN,
            'the S256-residual scripts-scope token must stay exactly this literal in this '
            . "file — the merge ritual greps master's copy of THIS path for it",
        );
    }

    public function testCiPsalmSrcStepRunsPsalmXmlVerbatim(): void
    {
        $run = $this->workflowStepRun('Run Psalm');

        self::assertSame(
            './vendor/bin/psalm --show-info=false --no-progress',
            trim($run),
            'the production step is pinned verbatim and must keep resolving psalm.xml by default: '
            . 'a -c here would let the shipped src-only scope drift away from what CI runs',
        );

        self::assertTrue($this->workflowText('Run Psalm'), "the 'Run Psalm' step must keep its if: always()");

        foreach (['--error-level', '-c ', '|| true', '|| exit 0', '; true'] as $escape) {
            self::assertStringNotContainsString(
                $escape,
                $run,
                "a CLI {$escape} overrides or neuters the shipped config",
            );
        }
    }

    public function testCiPsalmTestsStepRunsPsalmTestsXmlVerbatim(): void
    {
        $run = $this->workflowStepRun('Run Psalm on tests/');

        self::assertSame(
            './vendor/bin/psalm -c psalm-tests.xml --show-info=false --no-progress',
            trim($run),
            'the tests step is pinned verbatim: the ONLY sanctioned -c here names the second shipped '
            . 'config itself. Dropping it silently re-runs psalm.xml (production twice, tests never); '
            . 'pointing it anywhere else escapes the measured scope',
        );

        self::assertSame(
            1,
            substr_count($run, '-c '),
            'exactly one -c, and it is psalm-tests.xml — chaining overrides would let the last one win',
        );

        self::assertTrue(
            $this->workflowText('Run Psalm on tests/'),
            "the 'Run Psalm on tests/' step must keep its if: always()",
        );

        foreach (['--error-level', '|| true', '|| exit 0', '; true'] as $escape) {
            self::assertStringNotContainsString(
                $escape,
                $run,
                "a CLI {$escape} overrides or neuters the shipped config",
            );
        }
    }

    /**
     * S256-residual: psalm.xml is the production psalm corpus — src/ AND scripts/ at
     * the measured L5 (the level rationale lives in that file's header; the scripts/
     * leg's own measurement — 10 findings, all fixed — is recorded beside it).
     */
    public function testPsalmConfigAnalysesSrcAndScriptsAtTheMeasuredLevel(): void
    {
        $config = $this->psalmConfigText(self::PSALM_CONFIG);

        self::assertMatchesRegularExpression(
            '/errorLevel="5"/',
            $config,
            'the production corpus is a measured L5 (its own header records the ladder); '
            . 'changing the level means re-measuring and re-documenting, not editing this pin',
        );

        $projectFiles = $this->xmlBlock($config, 'projectFiles');

        self::assertSame(
            ['src', 'scripts'],
            $this->xmlDirectoryNames($projectFiles),
            'the production psalm corpus is exactly [src, scripts] — dropping either '
            . 'must redden the suite, not just lose coverage silently',
        );

        self::assertSame(
            [],
            $this->xmlFileNames($projectFiles),
            'the production config carries zero <file> exclusions — the one written-why exclusion '
            . 'lives under tests/ and belongs to psalm-tests.xml; anything added here is a new mute',
        );

        // The WaitGroup stub in the PRODUCTION config gets the same exact-list
        // treatment as psalm-tests.xml's: a second entry here would be an
        // unresolvable-symbol mute wearing a <stubs> costume.
        $stubs = $this->xmlBlock($config, 'stubs');
        preg_match_all('#<file name="([^"]+)"\s*/>#s', $stubs[0], $entries);

        self::assertSame(
            [self::WAITGROUP_STUB],
            $entries[1],
            'psalm.xml may load exactly the drift-tested WaitGroup declaration — the same '
            . 'single source of truth phpstan.neon scans and psalm-tests.xml loads.',
        );

        self::assertFileExists(self::REPO . '/' . self::WAITGROUP_STUB);
    }

    public function testPsalmTestsConfigAnalysesTestsAtMeasuredLevelFive(): void
    {
        $config = $this->psalmConfigText(self::PSALM_TESTS_CONFIG);

        self::assertMatchesRegularExpression(
            '/errorLevel="5"/',
            $config,
            'level 5 remains the measured, documented pin for tests/ (ladder in this config header); '
            . 'relaxing it must update the pin and the evidence, not mute it',
        );

        $projectFiles = $this->xmlBlock($config, 'projectFiles');

        self::assertSame(
            ['tests'],
            $this->xmlDirectoryNames($projectFiles),
            'dropping the tests directory from psalm-tests.xml must redden the suite, not just lose coverage silently',
        );

        self::assertSame(
            [self::EXCLUDED_CEST],
            $this->xmlFileNames($projectFiles),
            'the tests-config ignoreFiles list is an allow-list with exactly one written-why entry',
        );

        self::assertFileExists(
            self::REPO . '/' . self::EXCLUDED_CEST,
            'the excluded Cest must still exist — if it is deleted, its exclusion becomes dead scope '
            . 'noise and the written-why in psalm-tests.xml must be removed in the same commit',
        );
    }

    public function testThePsalmExclusionAllowListIsTheUnionAcrossBothConfigs(): void
    {
        $excluded = array_merge(
            $this->xmlFileNames($this->xmlBlock($this->psalmConfigText(self::PSALM_CONFIG), 'projectFiles')),
            $this->xmlFileNames($this->xmlBlock($this->psalmConfigText(self::PSALM_TESTS_CONFIG), 'projectFiles')),
        );
        sort($excluded);

        self::assertSame(
            [self::EXCLUDED_CEST],
            $excluded,
            'the combined psalm exclusion corpus is exactly one written-why entry — smuggling a second mute into '
            . 'either config, or hopping one between configs, reddens this union assertion',
        );
    }

    public function testTheWaitGroupStubIsSharedWithPhpstanAsOneSourceOfTruth(): void
    {
        $config = $this->psalmConfigText(self::PSALM_TESTS_CONFIG);

        $stubs = $this->xmlBlock($config, 'stubs');
        preg_match_all('#<file name="([^"]+)"\s*/>#s', $stubs[0], $entries);

        self::assertSame(
            [self::WAITGROUP_STUB],
            $entries[1],
            'psalm-tests.xml may load exactly one stub: the hand-written Swoole\\Coroutine\\WaitGroup '
            . 'declaration. It is the same file phpstan-tests.neon scans, so the two analysers can never '
            . 'drift apart, and testTheWaitGroupDeclarationStillMatchesTheRealExtension keeps it honest '
            . 'against the loaded extension. A second stub added here is a new mute in disguise.',
        );

        self::assertFileExists(self::REPO . '/' . self::WAITGROUP_STUB);
    }

    public function testNeitherPsalmConfigHasMutingMachinery(): void
    {
        foreach ([self::PSALM_CONFIG, self::PSALM_TESTS_CONFIG] as $path) {
            // Comments excluded: the configs' own prose names the machinery they forbid.
            $bare = (string) preg_replace('/<!--.*?-->/s', '', $this->psalmConfigText($path));

            self::assertStringNotContainsString(
                '<ignoreErrors',
                $bare,
                "an ignoreErrors block in {$path} would be the first mute — S306's policy is that the "
                . 'list stays absent, and findings get fixed at the source or the level is re-measured',
            );

            self::assertStringNotContainsString(
                '<IssueHandler',
                $bare,
                "a per-issue-type suppress block in {$path} is the psalm equivalent of a mute list",
            );
        }

        self::assertFileDoesNotExist(
            self::REPO . '/psalm-baseline.xml',
            'a psalm baseline would reproduce the "gate that proves nothing" defect S146 removed',
        );
    }

    public function testTheLadderEvidenceStaysInThePsalmTestsConfig(): void
    {
        $config = $this->psalmConfigText(self::PSALM_TESTS_CONFIG);

        foreach (
            [
                'level 1: 4236', 'level 2: 2532', 'level 3: 963', 'level 4: 552',
                'level 5:  290', 'level 6:  252', 'level 7:  84', 'level 8:  79',
            ] as $measurement
        ) {
            self::assertStringContainsString(
                $measurement,
                $config,
                "the measured ladder evidence '{$measurement}' was edited out of psalm-tests.xml — "
                . 're-measure and record honestly instead of deleting the proof',
            );
        }

        self::assertStringContainsString(
            'Psalm 6.5.0',
            $config,
            'the ladder must stay attributed to the pinned psalm version — counts without a tool '
            . 'version are not reproducible evidence',
        );
    }

    public function testSilentlyDroppingEitherConfigOrPathReddensTheGate(): void
    {
        // S306 negative fuzz, executed in-suite (the hub's S444 lesson): each mutant is
        // exactly one member of the silent-regression class this gate exists for, applied
        // to the shipped text in memory. The parsers MUST falsify on every mutant — an
        // assertion that cannot fail is the S146 theatre this test replaces.
        $main = $this->psalmConfigText(self::PSALM_CONFIG);
        $tests = $this->psalmConfigText(self::PSALM_TESTS_CONFIG);
        $workflow = $this->workflowContents();

        $mutant = str_replace('<directory name="src" />', '', $main);
        self::assertNotSame($main, $mutant, 'the src-directory mutant must actually bite the text');
        self::assertNotSame(
            ['src', 'scripts'],
            $this->xmlDirectoryNames($this->xmlBlock($mutant, 'projectFiles')),
            'dropping src/ from psalm.xml would go unnoticed — the corpus pin is a paper tiger',
        );

        // S256-residual twin: silently narrowing the PRODUCTION psalm corpus back to
        // src/ (deleting the scripts entry from the shipped config) must bite too.
        $mutant = str_replace('<directory name="scripts" />', '', $main);
        self::assertNotSame($main, $mutant, 'the scripts-directory mutant must actually bite the text');
        self::assertNotSame(
            ['src', 'scripts'],
            $this->xmlDirectoryNames($this->xmlBlock($mutant, 'projectFiles')),
            'dropping scripts/ from psalm.xml would go unnoticed — the corpus pin is a paper tiger',
        );

        // S256-residual, phpstan side: the same silent narrowing of the production
        // paths list — which, unlike psalm, CI can no longer override from the CLI,
        // so this config IS the scope.
        $phpstan = $this->phpstanSrcDirectives();
        $mutant = (string) preg_replace('/^\s*-\s*scripts\s*$/m', '', $phpstan, 1);
        self::assertNotSame($phpstan, $mutant, 'the phpstan scripts-path mutant must actually bite the text');
        self::assertNotSame(
            ['src', 'scripts'],
            $this->phpstanPaths($mutant),
            'dropping scripts/ from phpstan.neon would go unnoticed — the paths pin is a paper tiger',
        );

        $mutant = str_replace('<directory name="tests" />', '', $tests);
        self::assertNotSame($tests, $mutant, 'the tests-directory mutant must actually bite the text');
        self::assertNotSame(
            ['tests'],
            $this->xmlDirectoryNames($this->xmlBlock($mutant, 'projectFiles')),
            'dropping tests/ from psalm-tests.xml would go unnoticed — the corpus pin is a paper tiger',
        );

        $mutant = str_replace('<file name="' . self::EXCLUDED_CEST . '" />', '', $tests);
        self::assertNotSame($tests, $mutant, 'the exclusion mutant must actually bite the text');
        self::assertNotSame(
            [self::EXCLUDED_CEST],
            $this->xmlFileNames($this->xmlBlock($mutant, 'projectFiles')),
            'deleting the allow-list entry would go unnoticed — the exclusion pin is a paper tiger',
        );

        // Silently repointing the tests step at psalm.xml keeps a valid command and the
        // step name — only the verbatim pin sees it.
        $mutant = str_replace('-c psalm-tests.xml', '-c psalm.xml', $workflow);
        self::assertNotSame($workflow, $mutant, 'the repoint mutant must actually bite the workflow');
        self::assertNotSame(
            './vendor/bin/psalm -c psalm-tests.xml --show-info=false --no-progress',
            trim($this->workflowStepRun('Run Psalm on tests/', $mutant)),
            'a tests step repointed at the production config would silently analyse src/ twice while '
            . 'tests/ leaves scope — the exact-string pin must catch it',
        );

        $mutant = str_replace('-c psalm-tests.xml ', '', $workflow);
        self::assertNotSame($workflow, $mutant, 'the config-strip mutant must actually bite the workflow');
        self::assertNotSame(
            './vendor/bin/psalm -c psalm-tests.xml --show-info=false --no-progress',
            trim($this->workflowStepRun('Run Psalm on tests/', $mutant)),
            'a tests step stripped of its config would silently re-analyse production twice — '
            . 'the exact-string pin must catch it',
        );

        // Deleting the whole step must make lookup find nothing, not fall back
        // to the surviving production step.
        $mutant = (string) preg_replace(
            '/^      - name: Run Psalm on tests\/\n        if: always\(\)\n        run: .*$\n/m',
            '',
            $workflow,
            1,
        );
        self::assertNotSame($workflow, $mutant, 'the step-deletion mutant must actually bite the workflow');

        self::assertSame(
            ['./vendor/bin/psalm --show-info=false --no-progress'],
            $this->workflowStepRunBodies('Run Psalm', $mutant),
            'anti-vacuity: the production step must still resolve on the mutant, so a zero-hit '
            . 'result below means the tests step vanished — not that the parser broke',
        );
        self::assertSame(
            [],
            $this->workflowStepRunBodies('Run Psalm on tests/', $mutant),
            'a deleted tests step must resolve to zero run bodies — lookup proves the pin bites',
        );
    }

    public function testTheTestsCorpusIsActuallyPopulated(): void
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            self::REPO . '/tests',
            \FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        self::assertGreaterThan(
            500,
            count($files),
            'tests/ collapsed below 500 PHP files — the analysers would go green over a shrunk corpus',
        );
    }

    /**
     * S256-residual twin: scripts/ is in both production corpora now (43 PHP files at
     * the time of writing). Same reason as the tests/ floor above — the analysers would
     * go GREEN over a shrunk corpus, so the corpus itself needs a floor that fails when
     * files leave without the configs noticing.
     */
    public function testTheScriptsCorpusIsActuallyPopulated(): void
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            self::REPO . '/scripts',
            \FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        self::assertGreaterThan(
            40,
            count($files),
            'scripts/ collapsed below 40 PHP files — the analysers would go green over a '
            . 'shrunk corpus (S256-residual measured 43)',
        );
    }
}
