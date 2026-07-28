<?php

/**
 * Make the minimum-coverage gate in `.github/workflows/phpunit.yml` actually gate.
 *
 * ## The defect this replaces
 *
 * The "Check minimum coverage threshold" step parsed the Clover report with
 * `xmllint`, and swallowed every possible failure:
 *
 * ```bash
 * STMTS=$(xmllint --xpath "string(/coverage/project/metrics/@statements)" coverage.xml 2>/dev/null || echo "0")
 * if [ -z "$STMTS" ] || [ "$STMTS" = "0" ]; then
 *   echo "WARNING: no statements found in coverage.xml — skipping threshold check"
 *   exit 0
 * fi
 * ```
 *
 * **`xmllint` is not installed on the `ubuntu-latest` runner.** Ubuntu 24.04.4
 * LTS, image provisioner 20260707.563: `libxml2-utils` (which ships `xmllint`)
 * appears nowhere in that image's installed-apt-packages manifest. So the
 * command exited 127, `2>/dev/null` hid `xmllint: command not found`, and
 * `|| echo "0"` turned the crash into the string `0` — which the very next line
 * read as "empty report" and rewarded with `exit 0`. `MIN_COVERAGE=40` has
 * therefore never gated a single PR.
 *
 * The XPath itself was CORRECT and is preserved verbatim below. Verified against
 * a report generated locally by this repo's own toolchain (PHPUnit 10.5.64 /
 * phpunit/php-code-coverage 10.1.16): `/coverage/project/metrics` really does
 * carry `statements` and `coveredstatements`, and the workflow's expression
 * returns `62537` / `71` against it. Do not "fix" the XPath — it was never wrong.
 *
 * Timing from the CI log of run 30354212368 is what settles it. The step body
 * began at 11:21:28.1580647 and printed the WARNING at 11:21:28.1685980 —
 * **10.5 ms** for two invocations plus the test and the echo. Measured locally on
 * the same-shape 3.8 MB report: two real `xmllint` parses take **461 ms**; two
 * command-not-found failures take **5 ms**. 10.5 ms cannot parse that file twice.
 *
 * ## Why PHP rather than `apt-get install libxml2-utils`
 *
 * Installing the tool would fix the symptom and leave the shape of the bug in
 * place. Two reasons to parse in PHP instead:
 *
 * 1. PHP is already guaranteed here — `setup-php` ran, and the suite that wrote
 *    this report ran on it. An apt round-trip adds a network dependency to every
 *    run for a tool nothing else in this repo uses.
 * 2. It removes a SECOND latent copy of the same landmine: the old step also
 *    piped through `bc`, and `bc` is likewise absent from the Ubuntu 24.04 image
 *    manifest. That has never been observed failing only because the `xmllint`
 *    line short-circuited three lines above it — the gate died before it could
 *    reach its next missing dependency. The arithmetic is done in PHP below.
 *
 * ## The contract
 *
 * Every failure mode is LOUD. There is deliberately **no `exit 0` fallback** and
 * no `|| echo "0"` anywhere in this file: a missing report, a missing parser, an
 * unparseable report, a missing or non-numeric metric, and a zero-statement
 * report are all `exit 1`. A gate that cannot measure must not report success —
 * that silent-skip is the entire reason this went unnoticed across every PR and
 * every push to master.
 *
 * This mirrors "Assert Psalm is actually installed" in
 * `.github/workflows/coding-standards.yml`, added by S146 after that job spent
 * its whole life reporting GREEN having analysed zero files. Same defect class,
 * same remedy: make the absence of the tool fail instead of degrade.
 *
 * Usage:
 *   php scripts/coverage-threshold-check.php [path/to/coverage.xml]
 *   MIN_COVERAGE=40 php scripts/coverage-threshold-check.php
 *
 * Exit codes: 0 = coverage met the floor. 1 = below floor, OR the gate could not
 * measure at all (which is a failure, not a skip).
 */

declare(strict_types=1);

const DEFAULT_MIN_COVERAGE = 40.0;

/**
 * Emit a GitHub Actions error annotation and stop.
 *
 * Annotations go to STDOUT because that is the stream the runner scans for
 * workflow commands, and it is what `coding-standards.yml` already does with its
 * `echo "::error::..."` guards.
 */
function fail(string $headline, string ...$detail): never
{
    fwrite(STDOUT, '::error::' . $headline . "\n");

    foreach ($detail as $line) {
        fwrite(STDOUT, '  ' . $line . "\n");
    }

    exit(1);
}

$reportPath = $argv[1] ?? (dirname(__DIR__) . '/coverage.xml');

// ---------------------------------------------------------------------------
// Guard 1 — the report exists.
// ---------------------------------------------------------------------------

if (!is_file($reportPath)) {
    fail(
        sprintf('Coverage report "%s" does not exist — the coverage gate cannot run.', $reportPath),
        'The "Run PHPUnit tests" step passes --coverage-clover coverage.xml, so a missing file',
        'means that step did not write one. Fix the run; do not skip the gate.',
    );
}

if (filesize($reportPath) === 0) {
    fail(sprintf('Coverage report "%s" is empty (0 bytes).', $reportPath));
}

// ---------------------------------------------------------------------------
// Guard 2 — a parser is actually present.
//
// This is the direct analogue of S146's "Assert Psalm is actually installed":
// the tool being absent must fail loudly, exactly as `xmllint` being absent
// should have. ext-dom is bundled with PHP and enabled by default, so this
// should never fire — which is precisely why it must be an assertion rather
// than an assumption.
// ---------------------------------------------------------------------------

if (!class_exists(DOMDocument::class) || !class_exists(DOMXPath::class)) {
    fail(
        'PHP is missing ext-dom (DOMDocument/DOMXPath) — the coverage report cannot be parsed.',
        'Add "dom" to the setup-php `extensions:` list in .github/workflows/phpunit.yml.',
        'Do NOT wrap this check in a conditional that skips when the parser is absent (S146).',
    );
}

// ---------------------------------------------------------------------------
// Guard 3 — the report parses.
// ---------------------------------------------------------------------------

$previousUseErrors = libxml_use_internal_errors(true);
libxml_clear_errors();

$dom    = new DOMDocument();
$loaded = $dom->load($reportPath, LIBXML_NONET);

$parseErrors = libxml_get_errors();
libxml_clear_errors();
libxml_use_internal_errors($previousUseErrors);

if ($loaded === false) {
    $first = $parseErrors[0] ?? null;

    fail(
        sprintf('Coverage report "%s" is not parseable XML.', $reportPath),
        $first instanceof LibXMLError
            ? sprintf('libxml: %s (line %d)', trim($first->message), $first->line)
            : 'libxml reported no detail.',
    );
}

// ---------------------------------------------------------------------------
// Guard 4 — the metrics are present AND numeric.
//
// "Assert the parse actually produced a number." An attribute that is absent,
// empty, or non-numeric means the report is not the shape this gate understands,
// and the honest response is to fail rather than to invent a 0 and skip.
// ---------------------------------------------------------------------------

$xpath = new DOMXPath($dom);

/**
 * Read one project-level Clover metric as a non-negative integer, or die trying.
 */
function readMetric(DOMXPath $xpath, string $attribute, string $reportPath): int
{
    // Same expression the xmllint version used. It was always correct.
    $expression = '/coverage/project/metrics/@' . $attribute;
    $nodes      = $xpath->query($expression);

    if ($nodes === false || $nodes->length === 0) {
        fail(
            sprintf('Coverage report "%s" has no %s at %s.', $reportPath, $attribute, $expression),
            'PHPUnit\'s Clover writer always emits this attribute, so its absence means the file',
            'is not PHPUnit Clover XML (Cobertura\'s @line-rate, for instance, lives elsewhere).',
        );
    }

    $raw = trim((string) $nodes->item(0)?->nodeValue);

    if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
        fail(
            sprintf('Coverage metric %s is not a non-negative integer.', $attribute),
            sprintf('Parsed value: "%s" (from %s)', $raw, $expression),
            'A gate that cannot read its own input must fail, not skip.',
        );
    }

    return (int) $raw;
}

$statements = readMetric($xpath, 'statements', $reportPath);
$covered    = readMetric($xpath, 'coveredstatements', $reportPath);

// ---------------------------------------------------------------------------
// Guard 5 — a zero-statement report is BROKEN, not a reason to skip.
//
// This is the line the old step got backwards. `statements="0"` means the run
// measured nothing; treating that as "nothing to check, pass" is what let an
// unenforced gate look green for the life of the repo.
// ---------------------------------------------------------------------------

if ($statements === 0) {
    fail(
        'Coverage report contains 0 statements — the run measured nothing.',
        'This is a BROKEN report, not an empty one. Check that a coverage driver (pcov) is',
        'loaded and that phpunit.xml\'s <source> still includes src/.',
        'This condition used to `exit 0` and skip the gate. It must never do that again.',
    );
}

if ($covered > $statements) {
    fail(sprintf(
        'Coverage report is internally inconsistent: coveredstatements (%d) > statements (%d).',
        $covered,
        $statements,
    ));
}

// ---------------------------------------------------------------------------
// The threshold itself.
// ---------------------------------------------------------------------------

$minRaw      = getenv('MIN_COVERAGE');
$minCoverage = DEFAULT_MIN_COVERAGE;

if (is_string($minRaw) && trim($minRaw) !== '') {
    $minRaw = trim($minRaw);

    if (!is_numeric($minRaw)) {
        fail(
            sprintf('MIN_COVERAGE is set to "%s", which is not a number.', $minRaw),
            'Set it to a percentage (e.g. MIN_COVERAGE=40) or unset it to use the default.',
        );
    }

    $minCoverage = (float) $minRaw;
}

// Round before comparing so the printed figure and the verdict can never
// disagree at the boundary. (The old `bc` version truncated at scale=2 and
// compared the truncated value; this keeps that property.)
$percentage = round($covered * 100 / $statements, 2);

fwrite(STDOUT, sprintf("Statement coverage: %.2f%% (%d / %d)\n", $percentage, $covered, $statements));
fwrite(STDOUT, sprintf("Minimum required:   %s%%\n", rtrim(rtrim(sprintf('%.2f', $minCoverage), '0'), '.')));

if ($percentage < $minCoverage) {
    fail(sprintf('Coverage %.2f%% is below minimum %.2f%%', $percentage, $minCoverage));
}

fwrite(STDOUT, "Coverage check passed.\n");

exit(0);
