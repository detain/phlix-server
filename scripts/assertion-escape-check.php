<?php

/**
 * S120 — turn the assertion-escape guard's report into a CI exit code.
 *
 * `tests/Support/AssertionEscape/AssertionEscapeGuardExtension` writes
 * `.phpunit-assertion-escapes.json` when a test run contained an assertion failure
 * that did not decide its test's outcome. It cannot reliably fail the run itself:
 * PHPUnit's `DirectDispatcher::dispatch()` catches every `Throwable` a subscriber
 * raises (`vendor/phpunit/phpunit/src/Event/Dispatcher/DirectDispatcher.php`, read at
 * 10.5.64) and `handleThrowable()` demotes it to a PHPUnit *warning*.
 *
 * That warning is not nothing — it reaches `TestResult::numberOfPhpunitWarnings()`
 * (`vendor/phpunit/phpunit/src/Runner/TestResult/TestResult.php:523-527`, which counts
 * `testRunnerTriggeredWarningEvents`) and
 * `ShellExitCodeCalculator::calculate()` (`.../TextUI/ShellExitCodeCalculator.php:140-142`)
 * turns it into a failing exit code — but ONLY when `failOnPhpunitWarning` is set.
 * This repo's `phpunit.xml` (line 2) sets `failOnWarning="true"`, which is a DIFFERENT
 * counter, and does not set `failOnPhpunitWarning` (verified: 0 occurrences in
 * `phpunit.xml`). So under this repo's configuration a subscriber cannot influence the
 * exit code, and this separate script is required. It is a config fact, not a
 * property of PHPUnit: if `--fail-on-phpunit-warning` is ever adopted, revisit.
 *
 * Run this immediately after PHPUnit. Exits 0 when the report is absent (the normal
 * case) and 1 when it is present.
 *
 * Usage: php scripts/assertion-escape-check.php
 */

declare(strict_types=1);

$report = dirname(__DIR__) . '/.phpunit-assertion-escapes.json';

if (!is_file($report)) {
    fwrite(STDOUT, "S120 assertion-escape guard: no escapes reported.\n");

    exit(0);
}

$raw = (string) file_get_contents($report);
/** @var mixed $violations */
$violations = json_decode($raw, true);

fwrite(STDERR, "S120 assertion-escape guard: FAILED — an assertion failed without failing its test.\n");
fwrite(STDERR, "An assertion inside a callback is being swallowed by a catch (\\Throwable) or\n");
fwrite(STDERR, "catch (\\RuntimeException) between the assertion and PHPUnit. Remedy: have the\n");
fwrite(STDERR, "callback RECORD what it saw and run the assertions OUTSIDE the callback.\n\n");

if (is_array($violations)) {
    foreach ($violations as $violation) {
        if (!is_array($violation)) {
            continue;
        }

        fwrite(STDERR, sprintf(
            "  %s\n    outcome=%s  %s\n",
            is_string($violation['test'] ?? null) ? $violation['test'] : '?',
            is_string($violation['outcome'] ?? null) ? $violation['outcome'] : '?',
            is_string($violation['kind'] ?? null) ? $violation['kind'] : '?',
        ));
    }
} else {
    fwrite(STDERR, $raw);
}

exit(1);
