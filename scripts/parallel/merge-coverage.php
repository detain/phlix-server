<?php

declare(strict_types=1);

/**
 * S457 — union the per-suite serialized php-code-coverage objects into coverage.xml.
 *
 * The parallel flow never merges Clover XML: coverage travels as the *object* it was
 * collected in. `scripts/parallel/run-suite.sh <suite> N --coverage <out.php>` runs
 * `paraunit coverage`, whose children each emit `--coverage-php=<unique temp>` and
 * whose parent merges them (php-code-coverage's own CodeCoverage::merge) and writes
 * the result via its `--php` processor; the serial E2E tail writes the same PHP format
 * with PHPUnit's own `--coverage-php`. This script merges those per-suite objects and
 * emits Clover through php-code-coverage's Clover generator — the exact writer
 * `--coverage-clover` uses — so `coverage.xml` keeps its byte format and its metrics
 * stay exact (statement/method/conditional *unions*, not a lossy attribute arithmetic).
 *
 * Usage: php scripts/parallel/merge-coverage.php <out.xml> <in.php> [<in.php> ...]
 */

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "merge-coverage: {$autoload} missing — composer install first.\n");
    exit(1);
}

require $autoload;

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;

[$program, $target, $inputs] = [
    $argv[0],
    $argv[1] ?? '',
    array_slice($argv, 2),
];

if ($target === '' || $inputs === []) {
    fwrite(STDERR, "usage: {$program} <out.xml> <in.php> [<in.php> ...]\n");
    exit(2);
}

$merged = null;

foreach ($inputs as $input) {
    if (!is_file($input)) {
        fwrite(STDERR, "merge-coverage: {$input} does not exist — did the matching suite run?\n");
        exit(1);
    }

    // Both producers write the PHPUnit-native PHP format — a file that returns
    // the unserialized CodeCoverage object: paraunit's `coverage --php` processor
    // delegates to php-code-coverage's own exporter, and PHPUnit's --coverage-php
    // is the same shape. Loading = including the file; the return value is the object.
    $coverage = (static fn (string $path) => include $path)($input);

    if (!$coverage instanceof CodeCoverage) {
        fwrite(STDERR, "merge-coverage: {$input} does not return a php-code-coverage object.\n");
        exit(1);
    }

    if ($merged === null) {
        $merged = $coverage;

        continue;
    }

    $merged->merge($coverage);
}

/** @var CodeCoverage $merged */
(new Clover())->process($merged, $target);

$cloverXml = simplexml_load_file($target);

if ($cloverXml === false) {
    fwrite(STDERR, "merge-coverage: {$target} was written but does not parse.\n");
    exit(1);
}

$project = $cloverXml->project->metrics;
$statements = (int) $project['statements'];
$covered = (int) $project['coveredstatements'];

printf(
    "merge-coverage: %d suite(s) -> %s (lines %d/%d = %.2f%%)\n",
    count($inputs),
    $target,
    $covered,
    $statements,
    $statements > 0 ? $covered / $statements * 100 : 0.0,
);
