<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\AssertionEscape;

use PHPUnit\Event\Test\AssertionFailed;
use PHPUnit\Event\Test\AssertionFailedSubscriber;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * S120 — the mechanical guard against assertions that fail without failing their test.
 *
 * Registered from `phpunit.xml`. It subscribes to PHPUnit's own assertion and outcome
 * events and reports any test where an assertion failure did not become the outcome —
 * see {@see EscapeCollector} for the mechanism, the exactness argument, the failure
 * paths that emit no event, and the one known false-positive class.
 *
 * On a run with violations it writes `.phpunit-assertion-escapes.json` at the repo root
 * and prints a block to STDERR. It deliberately does NOT try to fail the run from
 * inside the event system: `DirectDispatcher::dispatch()`
 * (`vendor/phpunit/phpunit/src/Event/Dispatcher/DirectDispatcher.php`, read at 10.5.64)
 * catches every `Throwable` a subscriber raises and demotes it to a PHPUnit warning.
 * `scripts/assertion-escape-check.php` reads the report file and is what turns a
 * violation into a NAMED non-zero exit for CI.
 *
 * ⚠ CORRECTED 2026-08-02 (S120 AC audit). This docblock used to add that the demoted
 * warning "changes the exit code only under `failOnPhpunitWarning`", which this repo
 * "does NOT set, so a subscriber cannot change the exit code". Both halves of that
 * inference are unsound: `failOnPhpunitWarning` **defaults to `true`**
 * (`vendor/phpunit/phpunit/phpunit.xsd:184`, and
 * `.../TextUI/Configuration/Xml/Loader.php:833` passes `true` as the default), so an
 * unset attribute is an ENABLED one. Verified by execution: a subscriber that throws
 * makes an otherwise-green run exit 1 with `PHPUnit Warnings: 1`. Keeping the check in
 * a separate script is still the right call — it names the failure instead of leaving a
 * bare warning in the tail — but it is a legibility argument, not a necessity one.
 *
 * The wiring is pinned by `tests/Unit/Support/AssertionEscapeGuardWiringTest.php`,
 * because deleting the `<bootstrap>` line that registers THIS class from `phpunit.xml`
 * used to be entirely silent: no report, no warning, check script exit 0.
 */
final class AssertionEscapeGuardExtension implements Extension
{
    public const REPORT_FILE = '.phpunit-assertion-escapes.json';

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $collector = new EscapeCollector();
        $reportPath = dirname(__DIR__, 3) . '/' . self::REPORT_FILE;

        if (is_file($reportPath)) {
            @unlink($reportPath);
        }

        $facade->registerSubscribers(
            new class ($collector) implements PreparedSubscriber {
                public function __construct(private readonly EscapeCollector $collector)
                {
                }

                public function notify(Prepared $event): void
                {
                    $this->collector->testPrepared($event->test()->id());
                }
            },
            new class ($collector) implements AssertionFailedSubscriber {
                public function __construct(private readonly EscapeCollector $collector)
                {
                }

                public function notify(AssertionFailed $event): void
                {
                    $this->collector->assertionFailed(
                        $event->message() !== '' ? $event->message() : $event->asString()
                    );
                }
            },
            new class ($collector) implements FailedSubscriber {
                public function __construct(private readonly EscapeCollector $collector)
                {
                }

                public function notify(Failed $event): void
                {
                    $this->collector->outcome('failed');
                }
            },
            new class ($collector) implements ErroredSubscriber {
                public function __construct(private readonly EscapeCollector $collector)
                {
                }

                public function notify(Errored $event): void
                {
                    $this->collector->outcome('errored');
                }
            },
            new class ($collector) implements SkippedSubscriber {
                public function __construct(private readonly EscapeCollector $collector)
                {
                }

                public function notify(Skipped $event): void
                {
                    $this->collector->outcome('skipped');
                }
            },
            new class ($collector) implements FinishedSubscriber {
                public function __construct(private readonly EscapeCollector $collector)
                {
                }

                public function notify(Finished $event): void
                {
                    $this->collector->testFinished();
                }
            },
            new class ($collector, $reportPath) implements ExecutionFinishedSubscriber {
                public function __construct(
                    private readonly EscapeCollector $collector,
                    private readonly string $reportPath,
                ) {
                }

                public function notify(ExecutionFinished $event): void
                {
                    $violations = $this->collector->violations();

                    if ($violations === []) {
                        return;
                    }

                    file_put_contents(
                        $this->reportPath,
                        json_encode($violations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
                    );

                    $out = "\nS120 ASSERTION-ESCAPE GUARD — " . count($violations)
                        . " test(s) had an assertion failure that did not decide the outcome:\n";

                    foreach ($violations as $v) {
                        $out .= sprintf("  %s\n    outcome=%s  %s\n", $v['test'], $v['outcome'], $v['kind']);

                        foreach ($v['failures'] as $message) {
                            $first = strtok($message, "\n");
                            $out .= '      · ' . ($first === false ? '' : $first) . "\n";
                        }
                    }

                    $out .= '  Report: ' . $this->reportPath . "\n";

                    fwrite(STDERR, $out);
                }
            },
        );
    }
}
