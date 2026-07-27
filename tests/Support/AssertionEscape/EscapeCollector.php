<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\AssertionEscape;

/**
 * S120 — records assertion failures that did NOT decide their test's outcome.
 *
 * ## The defect class this exists to catch
 *
 * PHPUnit's `ExpectationFailedException` extends `AssertionFailedError` extends
 * `PHPUnit\Framework\Exception` extends `RuntimeException` (verified by executing
 * `class_parents(ExpectationFailedException::class)` — see the S120 worklog). So an
 * assertion that runs inside a callback which is invoked inside
 * `try { … } catch (\Throwable)` or `catch (\RuntimeException)` is caught by the code
 * doing the catching, and never reaches PHPUnit's outcome. Two observed forms:
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
 * ## Known blind spot — stated as a limitation, not a claim of completeness
 *
 * `Assert::fail()` (same file) throws `AssertionFailedError` directly and does not go
 * through `assertThat()`, so it emits no `AssertionFailed` event and this collector
 * cannot see a swallowed `$this->fail(...)`. `scripts/assertion-escape-audit.php`
 * covers that shape by planting a tripwire and observing the run instead.
 *
 * @phpstan-type Violation array{test: string, kind: string, failures: list<string>, outcome: string}
 */
final class EscapeCollector
{
    /**
     * A test that FAILS is expected to emit exactly one `AssertionFailed` — the one
     * that propagated and became the failure. Anything above that budget was
     * swallowed. A test with any other outcome has a budget of zero.
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
