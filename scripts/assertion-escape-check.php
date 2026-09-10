<?php

/**
 * S120 — turn the assertion-escape guard's report into a CI exit code.
 *
 * `tests/Support/AssertionEscape/AssertionEscapeGuardExtension` writes
 * `.phpunit-assertion-escapes.json` when a test run contained an assertion failure
 * that did not decide its test's outcome. This script turns that report into a
 * non-zero exit.
 *
 * ## Why a separate script
 *
 * PHPUnit's `DirectDispatcher::dispatch()` catches every `Throwable` a subscriber
 * raises (`vendor/phpunit/phpunit/src/Event/Dispatcher/DirectDispatcher.php`, read at
 * 10.5.64) and `handleThrowable()` demotes it to a PHPUnit *warning*, so a subscriber
 * cannot fail the run by throwing the way ordinary code would.
 *
 * ⚠ CORRECTED 2026-08-02 (S120 AC audit). Earlier revisions of this comment went on to
 * claim that the demoted warning could not affect the exit code "because `phpunit.xml`
 * does not set `failOnPhpunitWarning`". That inference is WRONG:
 * `failOnPhpunitWarning` **defaults to `true`** —
 * `vendor/phpunit/phpunit/phpunit.xsd:184` declares `default="true"`, and
 * `.../TextUI/Configuration/Xml/Loader.php:833` passes `true` as the default to
 * `getBooleanAttribute()`. Verified by execution: an extension whose subscriber throws
 * makes an otherwise-green run print `PHPUnit Warnings: 1` and exit **1** under this
 * repo's unmodified `phpunit.xml`.
 *
 * So this script is not what makes a violation capable of failing CI — it is what makes
 * the failure NAMED. A demoted warning is an unnamed line in the PHPUnit tail whose
 * effect rides on a default that a `phpunit.xml` edit or a PHPUnit major can flip; a
 * dedicated CI step with a readable diagnostic does not.
 * `tests/Unit/Support/AssertionEscapeGuardWiringTest.php` pins both exit paths of this
 * script and the workflow step that runs it.
 *
 * The useful corollary of that default being `true`: if the extension CLASS goes
 * missing, PHPUnit reports "Cannot bootstrap extension because class … does not exist"
 * and exits 1 by itself. Deleting the `<bootstrap>` REGISTRATION from `phpunit.xml` is
 * by contrast completely silent — the guard loads nothing, no report is written, and
 * this script happily prints "no escapes reported". That asymmetry is the reason the
 * wiring test exists.
 *
 * Run this immediately after PHPUnit. Exits 0 when no report exists (the normal case)
 * and 1 when any does.
 *
 * S457 — the family, not one file. Parallel children share the repo root as CWD and
 * each writes `.phpunit-assertion-escapes.<chunk-id>.json` (see the extension's
 * reportPath()); the serial fixed name is also matched by the glob. Every report in
 * the family is read and the union is the verdict — a single escaping child fails the
 * run regardless of which worker finished last. Reports are unlinked after being read
 * so a stale child report cannot haunt the next run (the same job the fixed-name
 * unlink-at-bootstrap does for serial runs).
 *
 * Usage: php scripts/assertion-escape-check.php
 */

declare(strict_types=1);

$reports = glob(dirname(__DIR__) . '/.phpunit-assertion-escapes*.json') ?: [];

$violations = [];
$unreadable = [];

foreach ($reports as $report) {
    $raw = (string) file_get_contents($report);
    @unlink($report);

    /** @var mixed $decoded */
    $decoded = json_decode($raw, true);

    if (is_array($decoded)) {
        foreach ($decoded as $violation) {
            $violations[] = $violation;
        }
    } else {
        $unreadable[] = $report . ': ' . $raw;
    }
}

if ($reports === []) {
    fwrite(STDOUT, "S120 assertion-escape guard: no escapes reported.\n");

    exit(0);
}

fwrite(STDERR, "S120 assertion-escape guard: FAILED — an assertion failed without failing its test.\n");
fwrite(STDERR, "An assertion inside a callback is being swallowed between the assertion and\n");
fwrite(STDERR, "PHPUnit. ExpectationFailedException extends RuntimeException extends Exception,\n");
fwrite(STDERR, "so catch (\\Throwable), catch (\\Exception) and catch (\\RuntimeException) all eat\n");
fwrite(STDERR, "it — and so does a `return` inside a `finally`, with no catch involved at all.\n");
fwrite(STDERR, "Remedy: have the callback RECORD what it saw and assert OUTSIDE the callback.\n\n");

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

foreach ($unreadable as $complaint) {
    fwrite(STDERR, '  unparseable report — ' . $complaint . "\n");
}

exit(1);
