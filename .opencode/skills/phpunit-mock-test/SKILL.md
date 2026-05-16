---
name: phpunit-mock-test
description: Writes a PHPUnit 10 unit test under `tests/unit/{Module}/{Class}Test.php` extending `PHPUnit\Framework\TestCase`, following the exact patterns in `tests/unit/Auth/JwtHandlerTest.php` and `tests/unit/Media/Library/ItemRepositoryTest.php` — uses `$this->createMock(Connection::class)` for `Workerman\MySQL\Connection` with `->method('query')->willReturn([['col' => 'val']])` for reads and `->expects($this->once())->method('query')->with($this->stringContains('SQL'), $this->callback(fn))` for writes. Use when the user says 'write a test', 'add unit test', 'TDD this', 'test this class', or adds files under `tests/unit/`. Covers PSR-4 namespacing (`Phlex\Tests\Unit\{Module}`), constructor-injection mocking, return-value stubs, expectation-based assertions, and running with `vendor/bin/phpunit --testsuite Unit`. Do NOT use for JS tests (project has no JS test runner), integration/E2E tests that need a real DB (no integration testsuite is configured), or non-`tests/unit/` paths.
---

# PHPUnit Mock Test (phlex)

## Critical

- **PHPUnit version is 10** (`phpunit/phpunit: ^10.0` in `composer.json`). Do NOT use `@dataProvider` annotations only — attributes (`#[DataProvider]`) are preferred under PHPUnit 10, but annotations still work. Do NOT use `setExpectedException` (removed); use `$this->expectException(...)`.
- **All tests live under `tests/unit/{Module}/{Class}Test.php`**. The `phpunit.xml` testsuite `Unit` scans `tests/unit` with suffix `Test.php`. Files placed elsewhere will NOT run.
- **Namespace MUST be `Phlex\Tests\Unit\{Module}`** matching the directory under `tests/unit/`. Autoload is PSR-4 (`Phlex\Tests\` → `tests/`) per `composer.json`.
- **Database type is `Workerman\MySQL\Connection`** — never `PDO`, never `mysqli`. Always mock it with `$this->createMock(Connection::class)`.
- **The phpunit config is strict**: `failOnRisky="true"`, `failOnWarning="true"`, `beStrictAboutOutputDuringTests="true"`. A test that produces output (echo, var_dump, error_log to stdout) will FAIL. Every test must contain at least one assertion or `$mock->expects(...)`.
- **No integration testsuite is wired up** — only `Unit` exists in `phpunit.xml`. Do not add tests that require a live DB connection; mock the `Connection` instead.

## Instructions

1. **Locate the class under test.** Identify its namespace (`Phlex\{Module}\{Class}`) and constructor signature. Note every dependency the constructor accepts — these become the mocks in your test.

   Verify: `grep -rn "class {ClassName}" src/{Module}/` returns exactly one file. If not, ask the user which one.

2. **Create the test file at the mirrored path** `tests/unit/{Module}/{Class}Test.php`. The directory under `tests/unit/` MUST mirror the directory under `src/`. Example: `src/Media/Library/ItemRepository.php` → `tests/unit/Media/Library/ItemRepositoryTest.php`.

   Verify the directory exists: `ls tests/unit/{Module}/` — create it if missing.

3. **Write the file header** exactly in this shape (from `tests/unit/Auth/JwtHandlerTest.php` and `tests/unit/Media/Library/ItemRepositoryTest.php`):

   ```php
   <?php

   namespace Phlex\Tests\Unit\{Module};

   use PHPUnit\Framework\TestCase;
   use Phlex\{Module}\{Class};
   // Only add this if the class takes a DB connection:
   use Workerman\MySQL\Connection;

   class {Class}Test extends TestCase
   {
   }
   ```

   Verify the namespace exactly matches the directory path under `tests/unit/`.

4. **For classes without dependencies**, instantiate directly in `setUp()` and assert against real behavior — see `tests/unit/Auth/JwtHandlerTest.php:12-15`:

   ```php
   private JwtHandler $jwtHandler;

   protected function setUp(): void
   {
       $this->jwtHandler = new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
   }
   ```

   Do NOT introduce a `setUp()` for classes that need per-test mock variations — build the mock inside each test method instead (see step 5).

5. **For classes with a `Connection` dependency**, build the mock inside each test method (NOT in `setUp`), since each test needs different stubbed data. Pattern from `tests/unit/Media/Library/ItemRepositoryTest.php`:

   ```php
   public function testFindByIdReturnsItemWhenFound(): void
   {
       $db = $this->createMock(Connection::class);
       $db->method('query')->willReturn([
           [
               'id' => 'test-id',
               'name' => 'Test Movie',
               // ... full row matching the SELECT columns
           ]
       ]);

       $repo = new {Class}($db);
       $result = $repo->findById('test-id');

       $this->assertIsArray($result);
       $this->assertEquals('test-id', $result['id']);
   }
   ```

   - `query()` returns an `array` of associative-array rows. For "not found", return `[]`.
   - For aggregate queries (`COUNT(*)`), return `[['count' => 5]]` and assert the unwrapped value (see `ItemRepositoryTest.php:241-250`).

6. **For methods that perform writes (INSERT/UPDATE/DELETE), assert the call shape with `expects()`** — not the return value. Pattern from `ItemRepositoryTest.php:159-188`:

   ```php
   $db = $this->createMock(Connection::class);
   $db->expects($this->once())
       ->method('query')
       ->with(
           $this->stringContains('INSERT INTO {table}'),
           $this->callback(function ($params) {
               return count($params) === 7
                   && $params[1] === 'expected-value'
                   && $params[3] === 'another-value';
           })
       );

   $repo = new {Class}($db);
   $id = $repo->create([...]);

   $this->assertNotEmpty($id);
   ```

   Use `$this->stringContains('SQL fragment')` rather than full SQL — it tolerates whitespace/formatting drift. Use `$this->callback(...)` to verify positional parameter binding.

7. **For batch / multi-call methods, use `$this->exactly(N)`** (see `ItemRepositoryTest.php:322-346`):

   ```php
   $db->expects($this->exactly(2))
       ->method('query')
       ->with($this->stringContains('INSERT INTO {table}'));
   ```

8. **Test naming convention** — method names must start with `test` and read as a sentence describing behavior:
   - GOOD: `testFindByIdReturnsNullWhenNotFound`, `testCreateGeneratesUuidAndInsertsItem`, `testExpiredTokenReturnsNull`
   - BAD: `testFindById1`, `test_find_by_id`, `findByIdTest`

   All methods MUST declare `: void` return type.

9. **Run the test in isolation, then the full suite.** From the project root:

   ```bash
   vendor/bin/phpunit --filter {Class}Test
   vendor/bin/phpunit --testsuite Unit
   ```

   Verify both pass before marking the task done. Because `failOnRisky` and `failOnWarning` are `true`, a test that produces no assertions OR triggers any deprecation will fail the run. Do not declare success on partial green.

10. **Do NOT add `@covers` annotations** — none of the existing tests use them, and PHPUnit 10 with `failOnRisky="true"` does not require them since `requireCoverageMetadata` is unset.

## Examples

### Example 1: New class with constructor-injected dependencies

**User says:** "Add a unit test for `Phlex\Session\SessionManager` — it takes a `Workerman\MySQL\Connection` and has `findById(string): ?array` and `create(array): string`."

**Actions taken:**

1. Create `tests/unit/Session/SessionManagerTest.php`.
2. Write header with `namespace Phlex\Tests\Unit\Session;`, `use PHPUnit\Framework\TestCase;`, `use Phlex\Session\SessionManager;`, `use Workerman\MySQL\Connection;`.
3. Add `testFindByIdReturnsNullWhenNotFound` stubbing `$db->method('query')->willReturn([])`.
4. Add `testFindByIdReturnsSessionWhenFound` stubbing `willReturn([['id' => 'sess-1', ...]])`.
5. Add `testCreateInsertsSession` using `$db->expects($this->once())->method('query')->with($this->stringContains('INSERT INTO sessions'), $this->callback(...))`.
6. Run `vendor/bin/phpunit --filter SessionManagerTest`.

**Result:** File mirrors `tests/unit/Media/Library/ItemRepositoryTest.php` structure exactly; all tests pass under strict mode.

### Example 2: Pure class with no dependencies

**User says:** "TDD a `PasswordHasher` class in `src/Auth/`."

**Actions taken:**

1. Create `tests/unit/Auth/PasswordHasherTest.php`.
2. Mirror the `JwtHandlerTest` shape: private property `$hasher`, `setUp()` constructs the real instance, no mocks.
3. Add tests like `testHashProducesNonEmptyString`, `testVerifyAcceptsCorrectPassword`, `testVerifyRejectsWrongPassword`.
4. Run `vendor/bin/phpunit --filter PasswordHasherTest` — failing → implement → green.

**Result:** TDD cycle complete; test file structure identical to `JwtHandlerTest.php`.

## Common Issues

- **`Class "Phlex\...\FooTest" not found` when running phpunit:** The namespace in the test file does not match its directory. `tests/unit/Media/Library/FooTest.php` MUST declare `namespace Phlex\Tests\Unit\Media\Library;`. Fix the namespace, then re-run `composer dump-autoload` if needed.

- **`This test did not perform any assertions` causes failure:** `failOnRisky="true"` is set in `phpunit.xml:8`. Either add an `$this->assert*` call or use `$mock->expects($this->once())` (expectations count as assertions). Do NOT change phpunit.xml to silence this.

- **`PHPUnit\Framework\MockObject\...\BadMethodCallException: Method query may not be called more than 0 times`:** You used `$mock->expects($this->never())` somewhere, or you stubbed a different method name. Verify the method name matches the real `Workerman\MySQL\Connection::query` signature. If you simply forgot a stub, use `$db->method('query')->willReturn([])`.

- **`Test code or tested code printed unexpected output`:** `beStrictAboutOutputDuringTests="true"` is set. Remove any `echo`, `print`, `var_dump`, or `error_log(..., 4)` from the class under test or the test itself. If output is intentional, capture it with `$this->expectOutputString(...)`.

- **`Error: Class "Workerman\MySQL\Connection" not found`:** Composer autoload not built. Run `composer install` (or `composer dump-autoload` if vendor exists). Verify `vendor/workerman/mysql/` exists.

- **`SQLSTATE[HY000] [2002] Connection refused` during a unit test:** You instantiated a real `Connection` instead of mocking it. Replace `new Connection(...)` with `$this->createMock(Connection::class)`. Unit tests must never connect to MySQL — only the (currently non-existent) integration suite would.

- **`with()` matcher mismatch failures on SQL strings:** Do NOT pass full SQL to `->with()`. Use `$this->stringContains('INSERT INTO media_items')` — see `ItemRepositoryTest.php:165`. Whitespace/formatting in the production query will otherwise break the matcher.

- **`Test triggered deprecation` causing red:** `displayDetailsOnTestsThatTriggerDeprecations="true"` plus `failOnWarning="true"` makes any deprecation visible. Update the test (e.g. remove `@expectedException` annotations, switch to `#[DataProvider]` attributes) rather than suppressing.