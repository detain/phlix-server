<?php

declare(strict_types=1);

/**
 * S457 — union per-worker PHPUnit JUnit logs into the single junit.xml the CI flow expects.
 *
 * Every parallel child writes `.phpunit-junit/junit-<chunk-id>.xml` (the
 * __PHLIX_WORKER__ token expanded by scripts/parallel/php); the serial E2E tail writes
 * `junit-e2e.xml` straight into that directory. Downstream consumers of junit.xml —
 * scripts/assert-browser-e2e-ran.php (the S305 gate, whose denominator is the FULL run)
 * and the skipped-name census — must see every testcase from every suite exactly once,
 * with suite-level counters that match the union.
 *
 * Merge rules:
 *   - <testsuite> children are copied verbatim and each name must appear once across
 *     all inputs (a class running twice would mean paraunit double-scheduled it — the
 *     merge fails loudly rather than silently double-counting).
 *   - root <testsuites> counters and time are the sums of the per-suite attributes
 *     (assertions may be absent on some outputs; absent reads as 0, same as PHPUnit).
 *
 * ## The `assertions=` counter is INFORMATIONAL — a documented known-limit (S488)
 *
 * The stable identity of a run — what a CI baseline must key on — is the union of
 * { worker-file count, tests, errors, failures, skipped }, all emitted verbatim on the
 * `merge-junit:` line below. `assertions=` is NOT part of that identity. A handful of
 * Integration lanes legitimately execute a data-driven number of assertions — per-segment
 * loops over real ffmpeg output, per-poll guards that wait on a real OS-signal /
 * `SELECT SLEEP()` timing boundary — so the executed-assertion total can drift by a few
 * between otherwise byte-identical runs. That drift is not a regression: `tests`,
 * `errors`, `failures` and `skipped` stay byte-equal while `assertions=` moves. Baselines
 * that quote `assertions=` as load-bearing therefore manufacture causeless deltas; the
 * estate's prose follows this emitter, and the guard test
 * `ParallelTestWiringTest::testTheAssertionsCounterIsDeclaredInformationalAtTheEmitter`
 * pins the contract. See also the named S488 determinism fix in
 * `CliScanJobVisibilityTest::db()` — that pathological per-poll counted assertion was the
 * specific mover behind the −2 that motivated this note; the class of data-driven
 * assertion loops is broader than any single test, which is why the counter is declared
 * informational rather than exhaustively pinned.
 *
 * Usage: php scripts/parallel/merge-junit.php <junit-dir> <out.xml>
 */

/**
 * Code-resident known-limit marker (S488). Emitted on stderr below so the counter's
 * informational status travels with the very line consumers grep. Not a comment-only
 * token: this constant is read at runtime.
 */
const MERGE_JUNIT_ASSERTIONS_INFORMATIONAL_TOKEN = 'S488ASSERTFIXX9P6';

$dir = $argv[1] ?? '';
$out = $argv[2] ?? '';

if ($dir === '' || $out === '') {
    fwrite(STDERR, "usage: php scripts/parallel/merge-junit.php <junit-dir> <out.xml>\n");
    exit(2);
}

$files = glob($dir . '/*.xml') ?: [];
sort($files);

if ($files === []) {
    fwrite(STDERR, "merge-junit: no per-worker JUnit files in {$dir} — the parallel run produced no evidence.\n");
    exit(1);
}

// Tripwire for a collapsed seam: scripts/parallel/php rewrites __PHLIX_WORKER__ to the
// child's chunk id so every worker writes its OWN file. If paraunit ever stops launching
// children through the PATH shim (an absolute PHP_BINARY, a changed process model), all
// children write the single literal `junit-__PHLIX_WORKER__.xml` and clobber each other —
// one file survives, the duplicate-suite check below can never fire, and the merge would
// silently publish roughly 1/8 of the evidence. Refuse to launder that into a green run.
foreach ($files as $file) {
    if (str_contains(basename($file), '__PHLIX_WORKER__')) {
        fwrite(STDERR, "merge-junit: {$file} still carries the unexpanded __PHLIX_WORKER__ token —"
            . " the per-child JUnit seam did not fire, so workers wrote the same file and the merged"
            . " evidence is whatever the last child left. Not merging.\n");
        exit(1);
    }
}

$root = new DOMDocument('1.0', 'UTF-8');
$rootSuites = $root->createElement('testsuites');
$root->appendChild($rootSuites);

$attributes = [
    'tests' => 0,
    'assertions' => 0,
    'errors' => 0,
    'failures' => 0,
    'skipped' => 0,
];
$totalTime = 0.0;
$seenSuiteNames = [];

foreach ($files as $file) {
    $doc = new DOMDocument();
    $loaded = @$doc->load($file);

    if (!$loaded) {
        fwrite(STDERR, "merge-junit: {$file} is not parseable XML.\n");
        exit(1);
    }

    $suites = $doc->getElementsByTagName('testsuite');

    foreach ($suites as $suite) {
        // parentNode is `DOMNode|null` — a detached element would fatal on the
        // property read. Nullsafe collapses that case to `null !== 'testsuites'`,
        // i.e. skip, which is what "not an outer suite" already means here.
        if (!$suite instanceof DOMElement || $suite->parentNode?->localName !== 'testsuites') {
            // Only the outer per-class suites; nested ones are testcases' own groups.
            continue;
        }

        $name = $suite->getAttribute('name');

        if (isset($seenSuiteNames[$name])) {
            fwrite(STDERR, "merge-junit: suite '{$name}' appears in both {$seenSuiteNames[$name]} and {$file}.\n");
            exit(1);
        }

        $seenSuiteNames[$name] = $file;

        foreach ($attributes as $key => $unused) {
            $value = $suite->getAttribute($key);
            $attributes[$key] += $value === '' ? 0 : (int) $value;
        }

        $time = $suite->getAttribute('time');
        $totalTime += $time === '' ? 0.0 : (float) $time;

        $rootSuites->appendChild($root->importNode($suite, true));
    }
}

foreach ($attributes as $key => $value) {
    $rootSuites->setAttribute($key, (string) $value);
}

$rootSuites->setAttribute('name', 'phpunit-parallel-merged');
$rootSuites->setAttribute('time', sprintf('%.6F', $totalTime));

$root->formatOutput = true;

if ($root->save($out) === false) {
    fwrite(STDERR, "merge-junit: cannot write {$out}.\n");
    exit(1);
}

printf(
    "merge-junit: %d worker files -> %s (tests=%d assertions=%d errors=%d failures=%d skipped=%d)\n",
    count($files),
    $out,
    $attributes['tests'],
    $attributes['assertions'],
    $attributes['errors'],
    $attributes['failures'],
    $attributes['skipped'],
);

// S488 known-limit: the stdout `merge-junit:` line keeps its exact format for existing
// consumers; this stderr line declares which half of it is load-bearing identity and
// which half is informational, so a run-to-run `assertions=` drift is never mistaken
// for a regression. Reference the constant here so it is read, not decorative.
fwrite(STDERR, sprintf(
    "merge-junit: baseline identity = worker-files/tests/errors/failures/skipped; "
    . "assertions=%d is informational (S488 known-limit marker %s)\n",
    $attributes['assertions'],
    MERGE_JUNIT_ASSERTIONS_INFORMATIONAL_TOKEN,
));
