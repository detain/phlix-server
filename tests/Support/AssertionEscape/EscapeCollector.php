<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\AssertionEscape;

/**
 * S120 — records assertion failures that did NOT decide their test's outcome.
 *
 * ## The defect class this exists to catch
 *
 * PHPUnit's `ExpectationFailedException` extends `AssertionFailedError` extends
 * `PHPUnit\Framework\Exception` extends `RuntimeException` extends `Exception`
 * (verified by executing `class_parents(ExpectationFailedException::class)` — see the
 * S120 worklog). So an assertion that runs inside a callback which production invokes
 * inside a `try` is caught by whichever of `catch (\Throwable)`, `catch (\Exception)`
 * or `catch (\RuntimeException)` that `try` carries, and never reaches PHPUnit's
 * outcome. A `return` inside a `finally` discards the in-flight exception too, with no
 * `catch` anywhere — so "grep for the two catch types" is NOT a sound way to find these
 * (all four shapes were planted and confirmed swallowed on 2026-08-02). Two observed
 * forms:
 *
 *  - the catch is in the test's OWN body → the test goes fully **GREEN** while the
 *    assertion said `false`. Silently vacuous.
 *  - the catch is in production code → the test still goes RED, but on some *later*
 *    assertion, with a misleading diff instead of the named message that was written
 *    to explain the failure.
 *
 * ## Why this collector can see it when a static analyser cannot
 *
 * `Assert::assertThat()` (`vendor/phpunit/phpunit/src/Framework/Assert.php`, read at
 * PHPUnit 10.5.64) wraps the constraint evaluation in `try { … } finally { … }` and
 * emits `PHPUnit\Event\Test\AssertionFailed` from the `finally` **before** the
 * exception propagates. The event therefore fires even when the exception is
 * subsequently swallowed. Comparing "assertion failures emitted" against "did an
 * assertion failure decide the outcome" is exact, needs no whole-program analysis,
 * and costs one integer increment per failing assertion.
 *
 * ## Known blind spots — stated as limitations, not a claim of completeness
 *
 * The collector sees exactly what `Assert::assertThat()` emits. Three failure paths
 * throw an `AssertionFailedError`/`ExpectationFailedException` WITHOUT going through
 * `assertThat()`, and therefore emit no `AssertionFailed` event at all (all read at
 * PHPUnit 10.5.64); a fourth blind spot is the budget rule itself. All four are covered
 * by `scripts/assertion-escape-audit.php --probe`, which decides a site by MUTATION
 * rather than by event, and is the reason that script is kept alongside this one.
 *
 *  1. `Assert::fail()` — `vendor/phpunit/phpunit/src/Framework/Assert.php:2282` throws
 *     `AssertionFailedError` directly, so a swallowed `$this->fail(...)` is invisible
 *     here. `scripts/assertion-escape-audit.php` covers that shape by planting a
 *     tripwire and observing the run instead.
 *  2. Mock parameter and invocation rules —
 *     `.../MockObject/Runtime/Rule/Parameters.php:117` calls `$parameter->evaluate(...)`
 *     directly and `.../Constraint/Constraint.php:106` throws
 *     `ExpectationFailedException`; sibling rules throw from their own `verify()`
 *     (e.g. `.../Rule/InvokedCount.php:60`). Currently HARMLESS, and the reason is
 *     structural rather than lucky: `TestCase.php:688` calls `verifyMockObjects()`
 *     AFTER `runTest()` returns, i.e. outside any in-test callback, so a swallowed
 *     `->with()` mismatch is re-raised where nothing is catching it and the test still
 *     goes red. What is lost is only this collector's diagnosis, not the failure.
 *  3. `markTestSkipped()` / `markTestIncomplete()` —
 *     `class_parents(SkippedWithMessageException::class)` and
 *     `class_parents(IncompleteTestError::class)` are BOTH
 *     `AssertionFailedError → PHPUnit\Framework\Exception → RuntimeException` (verified
 *     by execution), so either one raised inside a callback that production wraps in
 *     `catch (\Throwable)`/`catch (\RuntimeException)` is eaten silently and emits
 *     nothing. Currently HARMLESS because there are none to eat: a token scan over all
 *     701 files under `tests/` finds 0 `markTestSkipped`/`markTestIncomplete` calls
 *     lexically inside an anonymous `function` body. (Re-derived 2026-08-02 with an
 *     independent nikic/php-parser AST walk over the now-753 files: still 0.)
 *  4. THE BUDGET ITSELF, when the VISIBLE failure emits no event. This one is
 *     different in kind from 1-3: the swallowed assertion IS seen, and the collector
 *     discards it anyway. `FAILED_OUTCOME_BUDGET = 1` assumes that a test whose outcome
 *     is `failed` failed *through* an `assertThat()` assertion, which spends the budget.
 *     When the visible failure comes from a path in 1-2 instead — `Assert::fail()`, or a
 *     mock invocation-count/parameter rule — the budget is never spent and it absorbs
 *     exactly one genuinely swallowed assertion. Reported as no violation at all.
 *     NOT hypothetical: this is precisely how
 *     `tests/Unit/Playlists/SmartPlaylistRefreshSubscriberTest::test_drain_tick_refreshes_the_linked_smart_collections`
 *     escaped both this guard and CI between S120 shipping and 2026-08-02. Breaking the
 *     production binding it pinned turned the test red on
 *     `refreshSmartCollection … called 0 times` — a mock rule, no event — while this
 *     collector stayed silent and `scripts/assertion-escape-check.php` exited 0.
 *     Deliberately NOT closed here: separating "the event that became the outcome" from
 *     "an event that was swallowed" needs message correlation between `AssertionFailed`
 *     and `Failed::throwable()`, whose texts differ by construction (the first carries
 *     the constraint description, the second the rendered diff). A correlation heuristic
 *     that mis-fires reports healthy tests as vacuous, and a noisy guard gets deleted —
 *     which costs more than this gap. `--probe` decides the same site correctly and
 *     without heuristics: it reports it `DEGRADED` and exits 1 (verified 2026-08-02).
 *
 * ## Known false-positive class — and why there is deliberately no opt-out
 *
 * The MEASUREMENT above is exact. The VERDICT derived from it is not: the collector
 * reads "an assertion failed and the test did not" as a defect, and one legitimate
 * shape produces exactly that reading — a test that DELIBERATELY catches its own
 * assertion failure, which is the normal way to test a custom assertion helper or
 * `Constraint`:
 *
 *     try { $this->assertSame(1, 2, '…'); }
 *     catch (\PHPUnit\Framework\AssertionFailedError $e) { $caught = $e; }
 *     $this->assertNotNull($caught, 'the helper must report a failure');
 *
 * That test is correct and is reported `VACUOUS (an assertion failed, the test did
 * not)`, which fails CI via `scripts/assertion-escape-check.php`. A milder variant: a
 * test that fails in its body while `tearDown()` also asserts and fails is reported
 * `SWALLOWED-BUT-RED` even though PHPUnit already printed both failures and nothing was
 * hidden. Note that `expectException(ExpectationFailedException::class)` is NOT an
 * escape from this — the event still fires and the outcome is still `passed`.
 *
 * No suppression attribute and no allow-list is provided, and that is a decision rather
 * than an omission. This guard's entire value is that it cannot be waved away; an
 * opt-out is reached for at exactly the moment a maintainer is looking at a real
 * escape and wants the red to stop, so it would be used to silence the defect class
 * far more often than the false positive. There are 0 tests of this shape in the suite
 * today, so an opt-out would ship with no caller — speculative machinery guarding a
 * hypothetical.
 *
 * If you hit this, the remedies in order of preference, none of which trips the guard:
 *
 *  1. Exercise the `Constraint` through its non-throwing path —
 *     `Constraint::evaluate(mixed $other, string $description = '', bool $returnResult
 *     = false): ?bool` (`.../Constraint/Constraint.php:38`) returns a bool instead of
 *     throwing when `$returnResult` is true, and emits no event.
 *  2. For a helper that is not a `Constraint`, assert on what it RECORDS rather than on
 *     the exception it throws — the same callback-records/assert-outside shape this
 *     whole guard exists to enforce.
 *  3. Only if neither fits: add a suppression keyed on the test id here, greppable and
 *     with the justification inline. Do NOT delete the extension — a real escape is
 *     silent by construction, so removing the guard removes the only thing that can
 *     see it.
 *
 * @phpstan-type Violation array{test: string, kind: string, failures: list<string>, outcome: string}
 */
final class EscapeCollector
{
    /**
     * A test that FAILS is expected to emit exactly one `AssertionFailed` — the one
     * that propagated and became the failure. Anything above that budget was
     * swallowed. A test with any other outcome has a budget of zero.
     *
     * ⚠ This is a heuristic, not a measurement, and it is blind spot 4 in the class
     * docblock: it assumes the visible failure went through `Assert::assertThat()`. If
     * it did not (`Assert::fail()`, a mock parameter/invocation rule), the budget goes
     * unspent and hides one real escape. Do NOT "fix" that by lowering the budget to 0 —
     * every honest single-assertion failure in the suite would then be reported.
     */
    private const FAILED_OUTCOME_BUDGET = 1;

    private string $currentTest = '';

    /** @var list<string> */
    private array $currentFailures = [];

    private string $currentOutcome = 'passed';

    /** @var list<array{test: string, kind: string, failures: list<string>, outcome: string}> */
    private array $violations = [];

    public function testPrepared(string $testId): void
    {
        $this->currentTest = $testId;
        $this->currentFailures = [];
        $this->currentOutcome = 'passed';
    }

    public function assertionFailed(string $message): void
    {
        $this->currentFailures[] = $message;
    }

    public function outcome(string $outcome): void
    {
        $this->currentOutcome = $outcome;
    }

    public function testFinished(): void
    {
        $budget = $this->currentOutcome === 'failed' ? self::FAILED_OUTCOME_BUDGET : 0;

        if (count($this->currentFailures) > $budget) {
            $this->violations[] = [
                'test' => $this->currentTest,
                'kind' => $this->currentOutcome === 'failed'
                    ? 'SWALLOWED-BUT-RED (the failure the reader sees is not the one that fired first)'
                    : 'VACUOUS (an assertion failed, the test did not)',
                'outcome' => $this->currentOutcome,
                'failures' => $this->currentFailures,
            ];
        }

        $this->currentTest = '';
        $this->currentFailures = [];
        $this->currentOutcome = 'passed';
    }

    /**
     * @return list<array{test: string, kind: string, failures: list<string>, outcome: string}>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
