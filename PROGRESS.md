# S486 — race-free teardown: OrphanMusicContainerReap + HlsServing integration tests

Lane: s486srv · Branch: s486srv-S486-teardown-races · Base: master @eb6bb619 (clean)
Token: S486 lane sentinel lives as code const in both test files (never in .md).

## Status log (newest last)

- [ok] Forensics done (see below). Root causes diagnosed:
  - OrphanMusic: DETERMINISTIC double-cleanup in tearDown() — testHealingRescan
    registers $clean in BOTH $cleanupDirs tree-walk (root dir loop unlinks all children)
    AND $cleanupFiles; second loop chmod()/unlink() an already-gone file. `@`-suppressed,
    so serial PHPUnit Collector drops it — paraunit prints suppressed PHP events → gate red.
  - HlsServing: detached fMP4 writer race. FfmpegRunner fmp4 wrapper chain ends with
    `rm -f <tmp> <tmp>.i <tmp>.s0 <tmp>.m3u8`; produceSegment() returns as soon as the
    FINAL segment file exists while the wrapper is still alive; tearDown rrmdir() has
    UNGUARDED unlink()/rmdir() → ENOENT warnings on the .part-* siblings.
- [ok] composer install run in sandbox; vendor/bin/paraunit 2.11.0 available.
- [ok] Reproduction: loud serial config surfaced the OrphanMusic pair DETERMINISTICALLY
  (Warnings: 2 — chmod() + unlink(…s153-renamed-ok.mp3)); real paraunit gate shape
  (`--parallel=8 --chunk-size=1 phpunit-parallel.xml "Integration/Media"`) reproduced the same
  CI pair with no config help (mechanism proof: paraunit prints suppressed events). HlsServing's
  wrapper race needs a >100 ms rm stall to interleave; forced deterministically with an
  LD_PRELOAD unlink-delay shim + slow-`rm` PATH shim (3/3 runs = the exact CI `.part-*`+`.m3u8` pair).
- [ok] Fixes implemented (S460 pattern, both files, lane sentinel consts code-resident):
  - OrphanMusic: register-before-create guarded `makeCleanupDirectory()`; removals existence-guarded
    (`discardPath`), files-first-then-dirs, every attempted removal verified via fresh-stat
    `pathStillThere()`, loud sentinel-named fail on survival.
  - HlsServing: `random_bytes` dir mint + guarded mkdir; tearDown = bounded 15 s poll for
    `seg-*.part-*` quiescence (marker loss ⇒ wrapper write phase over) → guarded recursive
    removal (3 attempts) → survivor list fails loudly naming sentinel; blind `rrmdir` deleted.
- [ok] Gates: phpcs --standard=phpcs-tests.xml PASS; phpstan -c phpstan-tests.neon PASS (0 errors;
    three if.alwaysTrue findings fixed honestly — removal-call return value + fresh-stat helper).
- [ok] Proofs: OrphanMusic 10× loud → 10× OK(7 tests) 0 warnings 0 residue; HlsServing 10× loud →
  10× OK(2 tests) 0 warnings; HlsServing 5× on the forced-race venue (pre-fix 2 warnings every run)
  → 0 warnings AND 0 residue; paraunit gate shape Integration/Media 3× → EXIT=0, zero warning lines,
  zero errors/failures; census extension executed in every loud+para run, green; ZeroResidueCensusTest 5/5 OK.
- [ok] Mutation proofs: blind dirs-first loops reintroduced → CI pair verbatim; marker poll removed
  → forced-race venue reproduces unlink ENOENT pair. Both files restored byte-identical.
- [ok] CHANGELOG entry (Unreleased/Fixed, house style, no token).
- [next] commit → push → premerge → merge-under-lock → watch post-merge master.

---

# S485 lane s485srv — run-suite.sh paraunit verdict exact

## State (resume anchor)
- Branch: s485srv-S485-ex10-mechanism @ eb6bb619 (base). NOTHING COMMITTED YET. Working tree clean.
- sibling s486srv MERGED (PR #771 → origin/master ea0aaf73...). Rebase at ritual (premerge handles).
- paraunit 2.11.0 now installed in sandbox vendor/ via `composer install --no-scripts` (was missing; vendor/ is gitignored — no census impact).

## Exit-mapping DERIVED (read actual vendor source, not guessed)
- paraunit `Runner.php`: `$exitCode` default 0; ONLY setter `onProcessParsingCompleted()` (listens ProcessParsingCompleted): `if process->getExitCode() !== 0 → 10`. ⇒ runner returns 0 or 10 exactly. Symfony Console usage/config errors return 1 (measured: extension-not-registered case). `run-paraunit.inc.php` → `$application->run()`.
- child = PHPUnit 10.5.64 `ShellExitCodeCalculator`: 0 success; 1 = failures OR failOn* issues (config phpunit-parallel.xml sets failOnWarning=true failOnRisky=true beStrictAboutOutputDuringTests=true); 2 = hasErrors() (incl. phpunit errors) ; 255 crash (Application::exitWithCrashMessage → CRASH).
- ⇒ warnings-only worker exits 1 (phpunit failOnWarning) ⇒ paraunit 10. Identical code, warning fires only when race hits ⇒ flaky gate. MATCHES run 34651283031 evidence.
- JUnit XML (JunitXmlLogger, measured real run): testsuite attrs = tests/assertions/errors/failures/skipped/time ONLY — NO warnings attribute, NO <warning> element (grep -rln 'arning' vendor/phpunit/phpunit/src/Logging/JUnit/ = empty). Warnings live ONLY in paraunit stdout recap "N files with WARNINGS:".
- paraunit recap printer (FilesRecapPrinter.php:59): `"%d files with %s:"` per status in PRINT_ORDER, strtoupper(title): "ERRORS", "FAILURES" (separate lines!), "WARNINGS", "ABNORMAL TERMINATIONS (FATAL ERRORS, SEGFAULTS)", "NO TESTS EXECUTED", "DEPRECATIONS", ... Printed only when count>0 (nor chunked "chunks"). NOTE colors: real stdout wraps in style tags — when redirected to file without decor, plain text. run-suite greps must match non-decorated log (colors=auto ⇒ non-tty ⇒ no ANSI — confirmed? verify in fixture run).
- run-suite.sh defect: :122-124 re-exits ANY status≠0 verbatim (kills warnings-only status-10). :126-129 grep gate. :131-137 `|| true` pipefail lesson — PRESERVE.

## Design (agreed w/ AC)
- Replace :122-124: status 0 → keep existing ERRORS/FAILURES grep (paraunit's own claim). status≠0 → verdict from artifacts:
  - log recap `[1-9] files with (ERRORS|FAILURES|ABNORMAL TERMINATIONS|NO TESTS EXECUTED)` → exit 1 fatal.
  - else php scripts/parallel/paraunit-verdict.php .phpunit-junit → 1 = tails show errors/failures → fatal; 2 = no outer per-class suites at all = workers died before evidence → fatal fail-fast; 0 = warnings-only/clean → warn + exit 0.
  - unparseable XML in tails → fatal.
- Verdict helper must count ONLY outer suites (parent localName testsuites) — nested double-count trap (merge-junit.php predicate reused).
- Test seam: env PHLIX_S485_VERDICT_ONLY=1 + PHLIX_S485_STATUS + PHLIX_S485_LOG + PHLIX_JUNIT_DIR → jump to verdict block, skip paraunit. Guard test drives REAL script path.
- Guard test: tests/Unit/Parallel/... (new .php ⇒ census 1862→1863 same commit). Generates scratch tree, runs REAL vendor/bin/paraunit 2.11.0 (warnings-only / error / failure / clean), captures real log+junit, feeds verdict seam; asserts: warnings-only→0, error→fatal, failure→fatal, clean→0, missing-evidence→fatal (fail-fast), anti-vacuity: scratch MUST contain the marker text the parser reads (rule-3), and the planted warning case MUST show "files with WARNINGS" in paraunit log (else fixture drifted ⇒ test fails loudly).
- Token <step token>: code-resident const in guard test. 0 in .md.

## Ground truth (REAL paraunit 2.11.0 runs, /tmp/opencode/s485-real)
- warn-only: exit10, recap "1 files with WARNINGS:"+" WTest", junit 0/0. fail: exit10 "files with FAILURES:" junit failures="1". err: exit10 "files with ERRORS:" junit errors="1". clean: exit0 no recap. Console/config error (bad cmd, missing config, ext-not-registered): exit **1**.
- S439 zero-residue census under paraunit: exit10, recap "2 files with WARNINGS:" incl. line " [UNKNOWN]" (runner-level warnings → Test::unknown(), Logs/ValueObject/Test.php:46); child STDERR NOT forwarded to parent log. ⇒ VERDICT DESIGN: status10+WARNINGS block containing "[UNKNOWN]" line stays FATAL (keeps S439 red); plain test-file warnings → non-fatal.
- Log non-decorated when redirected (no ANSI). Recap headers: "N files with X:" then single-space-indented names until blank.
- Exit path map (rule-1): status∉{0,10} ⇒ paraunit never mapped a verdict (console/config/crash) ⇒ propagate verbatim. status0 ⇒ old behavior (ERRORS/FAILURES grep + warn echo). status10 ⇒ fatal iff recap has [1-9] files with (ERRORS|FAILURES) | ABNORMAL TERMINATIONS | RISKY OUTCOME | WARNINGS-block-with-[UNKNOWN] | junit tails contain <error/<failure | zero *.xml (no-evidence fail-fast); else warn-and-pass exit 0.
- Test seam: run-suite.sh subcommand `verdict <status> <log> <junit-dir>` runs the SAME function; guard test shells real paraunit (5 fixtures incl. clean+residue) asserting fixture markers FIRST (anti-vacuity rule-3), then seam exits: warn→0 fail→1 err→1 clean→0 residue→1 status7→7 no-xml→1. Reverting narrowing ⇒ warn case gets 10 ⇒ test red automatically (AC mutation clause structural).

## TODO
1. [done] mapping derivation + real-paraunit ground truth above.
2. Implement run-suite.sh + scripts/parallel/paraunit-verdict.php.
3. Guard test + census re-pin (find EXPECTED_PHP_FILES owner: grep says tests/Unit/Server/Http/RequestDynamicPropertyCensusExecutableTest.php?? — VERIFY actual census file, that grep match may be a different constant).
4. Gates: phpunit this test; phpstan analyze (prod cfg includes scripts/) + phpstan -c phpstan-tests.neon; phpcs PSR12 scripts? + phpcs-tests.xml; hermetic PHP_INI_SCAN_DIR venue for phpstan/phpcs (no swoole, copy /etc/php/8.3/cli/conf.d/*.ini minus swoole).
5. CHANGELOG entry (no token).
6. premerge → merge-under-lock --create-pr. Report ≤40 lines.

## Implementation (role: implement — DONE, verifying)
- run-suite.sh: paraunit_verdict() + `verdict <status> <log> <junit-dir>` seam; run path calls same fn. Rules per design above; awk block-scan (no SIGPIPE/pipefail trap); old `|| true` kept.
- tests/Unit/Support/ParaunitVerdictExactnessTest.php: 12 tests — sentinel (git ls-files --cached --others), 5 REAL paraunit fixtures (warn/fail/err/clean/census) w/ artifact-shape anti-vacuity asserts BEFORE verdict grading, propagation/no-evidence/JUnit-backstop/usage synthetic cases, 2 mutation controls on script copies (revert-narrowing→warn dies 10; drop-[UNKNOWN]→census goes green), seam-needle contract. All pass in 1.9s.
- Fixture gotcha found live: invalid `colors="never"` in scratch XML ⇒ paraunit [UNKNOWN] runner-warning on EVERY scenario incl. clean — proves fixture-shape pinning matters; removed attr.
- Census 1862→1863 same commit (verified: estate scan counts the new file).
- CHANGELOG entry under ### Fixed (no token). PROGRESS scrubbed of token (sentinel test caught it — live detection proof).
- Real Unit lane `run-suite.sh Unit 4`: 830 classes/10852 tests, paraunit exit 0 (30 WARNINGS are @-suppressed printouts), new note echoed; rerun capturing exit code pending in /tmp/opencode/s485-unit-run2.log.
- Gates: phpcs-tests + phpstan-tests hermetic PHP_INI_SCAN_DIR running → /tmp/opencode/s485-{phpcs,phpstan}.log.

## Verification (role: verify)
- Guard test rebuilt after a python open('w') truncation accident — re-ran: OK (12 tests, 2529 assertions) incl. 5 REAL paraunit fixtures + both mutation controls (warn→10 red, census→0 red). 1.2s.
- run-suite.sh: bash -n OK; seam synthetic matrix all correct (earlier table); ParallelTestWiringTest 11 regression tests green (seam needles preserved).
- REAL CI-shape Unit lane (twice): 830 classes/10852 tests, paraunit exit 0 (30 WARNINGS are @-suppressed printouts), new note echoed, RUNSUITE_EXIT=0 captured.
- phpcs --standard=phpcs-tests.xml (errors): my file 0 errors 0 warnings (20 line-length warnings reflowed; CI tolerates warnings — ParallelTestWiringTest itself carries 6).
- phpstan-tests + psalm-tests runs pending → /tmp/opencode/s485-{phpstan,psalm}.log.
