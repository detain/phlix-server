---
paths:
  - "tests/**"
  - "phpunit.xml"
  - "scripts/assertion-escape-check.php"
  - "scripts/assertion-escape-audit.php"
  - "scripts/ci-browser-e2e-prereqs.php"
  - "scripts/assert-browser-e2e-ran.php"
  - "scripts/coverage-threshold-check.php"
  - "scripts/security-audit-check.php"
  - "scripts/skipped-test-names.sh"
  - "phpcs-tests.xml"
  - "phpstan-tests.neon"
---

# Test Guards (S120 assertion escapes · S126 real-DB gate · S305 browser-E2E gate)

- **A test that needs a real MySQL uses the trait, nothing else.** `use Phlix\Tests\Support\Database\RequiresRealDatabase;` then `$db = $this->requireRealDatabase('skipping X. Runs in CI.');` (`tests/Support/Database/RequiresRealDatabase.php`). Do **not** re-add a private `isMysqlReachable()` / `fsockopen()` probe: a port probe cannot tell "no MySQL here" (skip) from "wrong credentials" (a real failure), and with the default pooled connection nothing after it can fail either — `PooledMySQLConnection` opens no socket until `query()`.
- **Never wrap the guard in `try`/`catch`.** `requireRealDatabase()` already skips on genuine absence; the only thing it throws is `IntegrationDbUnusableException`, raised precisely so a reachable-but-unusable database reddens the run. Turning that back into a skip by any route restores the S126 defect. `tests/Unit/Support/IntegrationDbGuardAdoptionTest.php` is the net under this rule, not the specification of it. Skipping because a *specific schema object* is missing is fine — keep the acquisition outside the `try`, only the schema probe inside.
- **Assertions never run inside a callback that a `catch` can swallow.** `phpunit.xml` registers `Phlix\Tests\Support\AssertionEscape\AssertionEscapeGuardExtension`, which writes `.phpunit-assertion-escapes.json` when an assertion failure did not decide its test's outcome; `php scripts/assertion-escape-check.php` turns that into a non-zero exit in CI. Remedy: have the callback RECORD what it saw and assert OUTSIDE it.
- **A skipped test reads as a PASS — gate the browser-E2E cases on both ends.** PHPUnit exits `0` with "OK, but some tests were skipped!", so `phpunit.yml` runs `php scripts/ci-browser-e2e-prereqs.php` BEFORE the suite (installs the pinned hls.js, proves a usable browser/node/ffmpeg) and `php scripts/assert-browser-e2e-ran.php junit.xml` AFTER it (required `Fmp4HlsPlaybackE2ETest` cases must be PRESENT by exact class+name, unskipped, and each must record at least one assertion). Neither half substitutes for the other, and the proof half shares no code with the supply half — keep it that way.
- **Never add a network fetch at an unpinned ref to a merge-gating job.** `ci-browser-e2e-prereqs.php` pins what it downloads; `scripts/skipped-test-names.sh` is how you list a run's skips by name (`--testdox` cannot).
- **CI-gate scripts must fail loudly, never `exit 0` on "couldn't measure".** `scripts/coverage-threshold-check.php` and `scripts/security-audit-check.php` exit 1 on every unmeasurable path (missing report, unparseable XML, missing metric). Do not add a fallback branch; `tests/Unit/Support/CoverageThresholdCheckTest.php` and `tests/Unit/Support/SecurityAuditCheckTest.php` enforce it.
- **`tests/` is linted and analysed by its OWN config — never fold it into the `src/` gate.** CI runs `./vendor/bin/phpcs --standard=phpcs-tests.xml` and `./vendor/bin/phpstan analyse -c phpstan-tests.neon` as steps separate from `phpcs --standard=PSR12 src/` and `phpstan analyze src/ --level=9`. A path given on the PHPStan command line overrides `parameters.paths`, so adding `tests` to `phpstan.neon` changes local runs only and leaves CI analysing exactly `src/` behind a green gate. `src/` keeps level 9 with no baseline, no `ignoreErrors` and empty `excludePaths`; `phpstan-tests.neon` is level 2 and `phpcs-tests.xml` excludes exactly two sniffs (snake_case test method names, co-located test doubles). `tests/Unit/Support/StaticAnalysisScopeTest.php` asserts the workflow, not the config.
- Suites are `Unit`, `Integration` and `E2E` (`phpunit.xml`); mocked-DB tests still use `$this->createMock(Workerman\MySQL\Connection::class)`.
