<?php

/**
 * S305 — fail the build when the headless-browser HLS cases did not EXECUTE.
 *
 * ## The defect, measured
 *
 * S57's three `Fmp4HlsPlaybackE2ETest` cases skipped on every CI run: the PHP
 * workflow never installed `web-ui/node_modules`, so hls.js was absent and `setUp()`
 * called `markTestSkipped()`. Measured on PR #664 (run `31263130044`): CI reported
 * `Skipped: 6` against master's 3, exactly those three cases.
 *
 * 🔴 **A skipped test is not a neutral absence — it reads as a pass.** PHPUnit exits
 * **0** with `OK, but some tests were skipped!`, so the check goes green and "nothing
 * was verified" arrives as the same green tick as success. With no branch protection
 * in this estate, `skipped` scores as SUCCESS for a status check too.
 * `scripts/ci-browser-e2e-prereqs.php` supplies the prerequisites; this script is the
 * half that PROVES the cases then ran, and it deliberately does not share a line of
 * code with the discovery logic it is checking up on.
 *
 * This is phlix-hub's `scripts/assert-integration-tests-ran.php` (S173) ported to a
 * NAMED set of cases rather than a whole-suite zero-skip rule, because phlix-server
 * has legitimate environment-dependent skips (they are listed, by name, in the output
 * of every run) and a blanket rule here would be the kind of noisy check that gets
 * deleted.
 *
 * ## What it asserts, and why each half exists
 *
 *  1. **The report exists, is non-empty and parses.** A gate that cannot measure must
 *     not report success — no `|| exit 0`, no silent degradation. This repo spent its
 *     whole life reporting success from a coverage gate whose parser was not installed
 *     (S146), so this failure mode is not hypothetical here.
 *  2. **The run contains test cases at all**, and the number is PRINTED. The commonest
 *     neuter of a gate is one that ran and inspected zero items: same exit 0, usually
 *     less output. A denominator makes that visible.
 *  3. **Every required case is PRESENT by exact class+name.** Half 4 alone is
 *     satisfiable by DELETING the tests. Substring matching is not used anywhere here:
 *     a sibling name can absorb a deleted one.
 *  4. **No required case was skipped**, with the skip message quoted.
 *  5. **Every required case recorded at least one assertion.** A case can execute and
 *     assert nothing — that is a test body replaced by a `return`, and it would satisfy
 *     halves 3 and 4 while proving exactly as little as a skip.
 *
 * A required case that FAILED is deliberately NOT an error here: the PHPUnit step
 * already reds for that, and this gate answering "yes, it ran, and it failed" on the
 * same run is the useful reading. That is also why the workflow gives this step
 * `if: always()`.
 *
 * Usage: php scripts/assert-browser-e2e-ran.php [path/to/junit.xml]
 *
 * @package Phlix
 */

declare(strict_types=1);

use Phlix\Tests\Support\Browser\BrowserProbeEnvironment;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "::error::S305 browser-E2E gate: vendor/autoload.php is missing; run composer install.\n");
    exit(1);
}
require_once $autoload;

$reportPath = $argv[1] ?? (dirname(__DIR__) . '/junit.xml');

/** Every failure path ends here; there is no fallback that returns success. */
$fail = static function (string $message): never {
    fwrite(STDERR, '::error::S305 browser-E2E gate: ' . $message . "\n");
    exit(1);
};

if (!is_file($reportPath)) {
    $fail(sprintf(
        'the JUnit report %s was not produced. The PHPUnit step must run with `--log-junit junit.xml`; '
        . 'without the report this gate cannot tell a run that PLAYED the fMP4 playlists in a real '
        . 'browser from one that skipped all three cases. Do NOT make this exit 0 when the file is '
        . 'missing.',
        $reportPath,
    ));
}

if (filesize($reportPath) === 0) {
    $fail(sprintf('%s exists but is empty (0 bytes).', $reportPath));
}

if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
    $fail(
        'PHP is missing ext-dom (DOMDocument/DOMXPath), so the JUnit report cannot be parsed. Add '
        . '`dom` to the setup-php extensions list — do not add a branch that skips this gate when its '
        . 'parser is absent.',
    );
}

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$loaded = $document->load($reportPath);
$xmlErrors = libxml_get_errors();
libxml_clear_errors();
libxml_use_internal_errors($previous);

if ($loaded === false) {
    $messages = array_map(static fn (LibXMLError $e): string => trim($e->message), $xmlErrors);
    $fail(sprintf(
        '%s is not parseable XML (%s).',
        $reportPath,
        $messages === [] ? 'no libxml detail' : implode('; ', $messages),
    ));
}

$xpath = new DOMXPath($document);

$allCases = $xpath->query('//testcase');
$totalCases = $allCases === false ? 0 : $allCases->length;
if ($totalCases === 0) {
    $fail(sprintf('%s contains no <testcase> elements. An empty run is not a pass.', $reportPath));
}

/**
 * The whole-run skip census, by name.
 *
 * Not asserted (phlix-server has legitimate environment-dependent skips), but always
 * PRINTED: the before/after of this step is an accounting of skips, and a census that
 * only a human with the raw report can reproduce is not an accounting.
 *
 * ⚠ PHPUnit 10.5's JUnit logger writes a bare `<skipped/>` with NO message — verified
 * against a real report — so only the case name is available here. The reason lives in
 * the `markTestSkipped()` call in the test's own `setUp()`.
 *
 * @var list<string> $skipped
 */
$skipped = [];
$skippedNodes = $xpath->query('//testcase[skipped]');
if ($skippedNodes !== false) {
    foreach ($skippedNodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $class = $node->getAttribute('class');
        $name = $node->getAttribute('name');
        $skipped[] = ($class === '' ? '?' : $class) . '::' . ($name === '' ? '?' : $name);
    }
}

/**
 * Exact-match lookup. `@class` and `@name` are compared for equality, never with
 * `contains()`: a data-provider variant or a sibling test whose name merely starts the
 * same way must not be able to stand in for the case being demanded.
 *
 * @return array{node: DOMElement, assertions: int, time: float, status: string}|null
 */
$findCase = static function (DOMXPath $xpath, string $class, string $method): ?array {
    $nodes = $xpath->query(sprintf(
        '//testcase[@class = "%s" and @name = "%s"]',
        $class,
        $method,
    ));
    if ($nodes === false || $nodes->length === 0) {
        return null;
    }
    $node = $nodes->item(0);
    if (!$node instanceof DOMElement) {
        return null;
    }

    $status = 'executed';
    foreach (['skipped', 'error', 'failure'] as $child) {
        if ($node->getElementsByTagName($child)->length > 0) {
            $status = $child;
            break;
        }
    }

    return [
        'node' => $node,
        'assertions' => (int) $node->getAttribute('assertions'),
        'time' => (float) $node->getAttribute('time'),
        'status' => $status,
    ];
};

/**
 * S315 — every browser class, not just S57's. The map is the authoritative demand
 * list and this script iterates it whole: a class added to `tests/E2E` and left out
 * of the map is the one way a new browser case can go on skipping invisibly, which
 * is why `tests/Unit/Support/BrowserE2EGateTest.php` reconciles the map against the
 * directory.
 */
$byClass = BrowserProbeEnvironment::REQUIRED_CASES_BY_CLASS;
$requiredCount = BrowserProbeEnvironment::requiredCaseCount();

/** @var list<string> $missing */
$missing = [];
/** @var list<string> $wereSkipped */
$wereSkipped = [];
/** @var list<string> $assertedNothing */
$assertedNothing = [];
/** @var list<string> $lines */
$lines = [];
$wallClock = 0.0;

foreach ($byClass as $class => $required) {
    $lines[] = $class;
    foreach ($required as $method) {
        $case = $findCase($xpath, $class, $method);
        if ($case === null) {
            $missing[] = $class . '::' . $method;
            continue;
        }

        $wallClock += $case['time'];
        $lines[] = sprintf(
            '  %-64s %-8s assertions=%d  %.1fs',
            $method,
            $case['status'],
            $case['assertions'],
            $case['time'],
        );

        if ($case['status'] === 'skipped') {
            $wereSkipped[] = $class . '::' . $method;
            continue;
        }

        if ($case['assertions'] < 1) {
            $assertedNothing[] = $class . '::' . $method;
        }
    }
}

if ($missing !== []) {
    $fail(sprintf(
        "%d of the %d required browser cases are ABSENT from %s:\n  %s\n"
        . "They were not skipped — they are missing, so either the E2E test suite is no longer being "
        . "run (check the PHPUnit invocation still covers tests/E2E) or the cases were renamed or "
        . "deleted. Renaming them means renaming them in "
        . "tests/Support/Browser/BrowserProbeEnvironment::REQUIRED_CASES_BY_CLASS too; deleting them "
        . "is deleting the only evidence that a real player can play these playlists.",
        count($missing),
        $requiredCount,
        basename($reportPath),
        implode("\n  ", $missing),
    ));
}

if ($wereSkipped !== []) {
    $fail(sprintf(
        "%d of the %d required browser cases were SKIPPED:\n  %s\n"
        . "A skipped test reads as a pass — PHPUnit exits 0 with \"OK, but some tests were skipped!\" "
        . "— so this build proves less than it appears to. PHPUnit's JUnit logger records no reason, "
        . "so read the `markTestSkipped()` guards in the test's setUp(): a missing ffmpeg, a node "
        . "older than 22, no Chrome/Chromium binary, or hls.js not installed. The "
        . "`Install the browser E2E prerequisites (S305)` step exists to make all four impossible and "
        . "must have gone wrong. Fix the prerequisite. Do NOT relax this gate, and do NOT delete the "
        . "skip guards in the test — a developer box with no browser must still be able to run the "
        . "suite.",
        count($wereSkipped),
        $requiredCount,
        implode("\n  ", $wereSkipped),
    ));
}

if ($assertedNothing !== []) {
    $fail(sprintf(
        "%d of the %d required browser cases executed but recorded ZERO assertions:\n  %s\n"
        . "A case that asserts nothing proves exactly as much as one that skipped. (If the run is red "
        . "for another reason, read the PHPUnit output first: a case that errors before its first "
        . "assertion also lands here.)",
        count($assertedNothing),
        $requiredCount,
        implode("\n  ", $assertedNothing),
    ));
}

printf(
    "S305 browser-E2E gate OK: %d/%d required cases across %d classes EXECUTED in %s "
    . "(%d test cases in the run, %d skipped).\n",
    $requiredCount,
    $requiredCount,
    count($byClass),
    basename($reportPath),
    $totalCases,
    count($skipped),
);
printf("%s\n", implode("\n", $lines));
printf("  browser cases wall clock: %.1fs\n", $wallClock);

if ($skipped === []) {
    printf("No test in this run was skipped.\n");
} else {
    printf("Skips in this run (%d), for the record — none of them a required browser case:\n", count($skipped));
    foreach ($skipped as $case) {
        printf("  %s\n", $case);
    }
}

exit(0);
