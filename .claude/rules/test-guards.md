---
paths:
  - "tests/**"
  - "phpunit.xml"
  - "scripts/assertion-escape-check.php"
  - "scripts/coverage-threshold-check.php"
  - "scripts/security-audit-check.php"
---

# Test Guards (S120 assertion escapes · S126 real-DB gate)

- **A test that needs a real MySQL uses the trait, nothing else.** `use Phlix\Tests\Support\Database\RequiresRealDatabase;` then `$db = $this->requireRealDatabase('skipping X. Runs in CI.');` (`tests/Support/Database/RequiresRealDatabase.php`). Do **not** re-add a private `isMysqlReachable()` / `fsockopen()` probe: a port probe cannot tell "no MySQL here" (skip) from "wrong credentials" (a real failure), and with the default pooled connection nothing after it can fail either — `PooledMySQLConnection` opens no socket until `query()`.
- **Never wrap the guard in `try`/`catch`.** `requireRealDatabase()` already skips on genuine absence; the only thing it throws is `IntegrationDbUnusableException`, raised precisely so a reachable-but-unusable database reddens the run. Turning that back into a skip by any route restores the S126 defect. `tests/Unit/Support/IntegrationDbGuardAdoptionTest.php` is the net under this rule, not the specification of it. Skipping because a *specific schema object* is missing is fine — keep the acquisition outside the `try`, only the schema probe inside.
- **Assertions never run inside a callback that a `catch` can swallow.** `phpunit.xml` registers `Phlix\Tests\Support\AssertionEscape\AssertionEscapeGuardExtension`, which writes `.phpunit-assertion-escapes.json` when an assertion failure did not decide its test's outcome; `php scripts/assertion-escape-check.php` turns that into a non-zero exit in CI. Remedy: have the callback RECORD what it saw and assert OUTSIDE it.
- **CI-gate scripts must fail loudly, never `exit 0` on "couldn't measure".** `scripts/coverage-threshold-check.php` and `scripts/security-audit-check.php` exit 1 on every unmeasurable path (missing report, unparseable XML, missing metric). Do not add a fallback branch; `tests/Unit/Support/CoverageThresholdCheckTest.php` and `tests/Unit/Support/SecurityAuditCheckTest.php` enforce it.
- Suites are `Unit`, `Integration` and `E2E` (`phpunit.xml`); mocked-DB tests still use `$this->createMock(Workerman\MySQL\Connection::class)`.
