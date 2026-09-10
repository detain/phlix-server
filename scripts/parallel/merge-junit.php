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
 * Usage: php scripts/parallel/merge-junit.php <junit-dir> <out.xml>
 */

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
        if (!$suite instanceof DOMElement || $suite->parentNode->localName !== 'testsuites') {
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
