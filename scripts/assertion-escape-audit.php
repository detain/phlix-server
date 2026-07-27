<?php

/**
 * S120 — enumerate every assertion-bearing closure under `tests/`, probing its FIRST
 * assertion, and (with `--probe`) decide EMPIRICALLY whether that assertion can fail
 * its test.
 *
 * Scope, stated precisely because the point of S120 is not to overstate what a check
 * covers: the unit of enumeration is the CLOSURE, not the assertion. `collectSites()`
 * keeps only the first assert-like call per innermost closure (see the `$firstAssert`
 * guard below), because one tripwire decides whether that closure sits in a swallowing
 * context — which is a property of the closure's CALL SITE, not of the individual
 * assertion. Measured on this tree by re-running the same token logic without that
 * filter: 51 assert-like calls live inside anonymous `function` bodies, in 20 closures,
 * so 20 of 51 calls are probed. Consequence, latent rather than live: if a closure's
 * FIRST assertion sits in a branch that is not taken, the site is filed `NOT-REACHED`
 * and that closure's later, reachable assertions are never decided.
 *
 * ## Why this exists alongside the runtime guard
 *
 * `tests/Support/AssertionEscape/AssertionEscapeGuardExtension` is exact and free, but
 * it only sees an escape when the assertion actually fails, and it is blind to every
 * failure that does not go through `Assert::assertThat()` — `Assert::fail()`
 * (`vendor/phpunit/phpunit/src/Framework/Assert.php:2282`, which throws
 * `AssertionFailedError` directly), mock parameter/invocation rules
 * (`.../MockObject/Runtime/Rule/Parameters.php:117` → `.../Constraint/Constraint.php:106`),
 * and `markTestSkipped()`/`markTestIncomplete()`. None of those emit an
 * `AssertionFailed` event (PHPUnit 10.5.64). This script closes those gaps by mutation:
 * it plants a named tripwire immediately before the first assertion in each closure,
 * runs the owning test, and reads the verdict off the run.
 *
 * ## Verdicts
 *
 *  - `GATES`      — the test went RED and the tripwire's message is in the output.
 *                   The assertion genuinely gates its test. Nothing to do.
 *  - `VACUOUS`    — the tripwire executed and the test still passed. The assertion is
 *                   swallowed; the test proves nothing. **Violation.**
 *  - `DEGRADED`   — the test went RED but on some other assertion; the tripwire's own
 *                   message never surfaced. The assertion is swallowed and the reader
 *                   gets a misleading diff. **Violation.**
 *  - `NOT-REACHED`— the tripwire line never executed, so the probe decided nothing.
 *                   Typical of a `$this->fail('must not happen')` guard inside a
 *                   branch that is not supposed to be taken. Listed, never a violation.
 *
 * `NOT-REACHED` is ENVIRONMENT-DEPENDENT and a census that quotes it must say so. A
 * test that self-skips without a live database — e.g. the Integration site in
 * `tests/Integration/Media/Transcoding/PooledConnectionConcurrencyTest.php`, which is
 * also a known pre-existing flake under coroutine churn — probes `NOT-REACHED` on a box
 * with no DB and can probe `GATES` on a box with one. The same site has been observed
 * both ways on this branch. Never quote a bare count: re-run the probe and quote the
 * run, and say which box it ran on.
 *
 * ## Safety
 *
 * Every mutated file is copied to a temp backup first, restored with `copy()` after the
 * run, and the restore is verified by `md5_file()` against the pre-mutation hash. A
 * shutdown handler restores on fatal too. It never runs any git command, so it cannot
 * destroy uncommitted work.
 *
 * Usage:
 *   php scripts/assertion-escape-audit.php            # list the sites
 *   php scripts/assertion-escape-audit.php --probe    # decide each one (slow: one
 *                                                     # phpunit invocation per site)
 *   php scripts/assertion-escape-audit.php --probe --only=42
 */

declare(strict_types=1);

const TRIPWIRE_MESSAGE = 'S120-ASSERTION-ESCAPE-TRIPWIRE';

$root = dirname(__DIR__);
$probe = in_array('--probe', $argv, true);
$only = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $only = (int) substr($arg, 7);
    }
}

$sites = collectSites($root . '/tests');

if (!$probe) {
    foreach ($sites as $i => $site) {
        printf(
            "%3d  %s:%d  closure@%d  %s\n",
            $i,
            substr($site['file'], strlen($root) + 1),
            $site['assertLine'],
            $site['closureLine'],
            $site['method'],
        );
    }
    printf("\n%d assertion-bearing closure(s). Re-run with --probe to decide each one.\n", count($sites));

    exit(0);
}

$violations = [];
$tally = ['GATES' => 0, 'VACUOUS' => 0, 'DEGRADED' => 0, 'NOT-REACHED' => 0, 'ERROR' => 0];
$probed = 0;

foreach ($sites as $i => $site) {
    if ($only !== null && $only !== $i) {
        continue;
    }

    $verdict = probeSite($root, $site);
    $probed++;
    $tally[$verdict] = ($tally[$verdict] ?? 0) + 1;
    printf(
        "%3d  %-11s %s:%d  %s\n",
        $i,
        $verdict,
        substr($site['file'], strlen($root) + 1),
        $site['assertLine'],
        $site['method'],
    );

    if ($verdict === 'VACUOUS' || $verdict === 'DEGRADED') {
        $violations[] = sprintf('%s:%d (%s) — %s', $site['file'], $site['assertLine'], $site['method'], $verdict);
    }
}

// Report the CENSUS, never a bare "all good". The previous wording here claimed
// "every probed assertion gates its test", which is false the moment any site comes
// back NOT-REACHED: such a site was probed and DECIDED NOTHING. Print the tally so a
// reader can only quote a number that came with its undecided remainder attached.
$summary = sprintf(
    "\nS120: %d site(s) probed — %d GATES, %d NOT-REACHED, %d VACUOUS, %d DEGRADED%s.\n",
    $probed,
    $tally['GATES'],
    $tally['NOT-REACHED'],
    $tally['VACUOUS'],
    $tally['DEGRADED'],
    $tally['ERROR'] > 0 ? sprintf(', %d ERROR', $tally['ERROR']) : '',
);

if ($violations !== []) {
    fwrite(STDERR, $summary);
    fwrite(STDERR, "\nS120: " . count($violations) . " assertion(s) cannot fail their test as written:\n");

    foreach ($violations as $violation) {
        fwrite(STDERR, '  ' . $violation . "\n");
    }

    fwrite(STDERR, "\nRemedy: have the callback RECORD what it saw and assert OUTSIDE the callback.\n");

    exit(1);
}

fwrite(STDOUT, $summary);
fwrite(
    STDOUT,
    $tally['NOT-REACHED'] === 0
        ? "Every site executed its tripwire and gates its test.\n"
        : "Every site that EXECUTED its tripwire gates its test. The NOT-REACHED sites are\n"
        . "UNDECIDED, not verified, and the verdict can differ per box (see the NOT-REACHED\n"
        . "note in this file's header). Quote this run, not a remembered count.\n",
);

exit(0);

/**
 * Every assertion call that is lexically inside an anonymous `function` body, grouped
 * by the INNERMOST enclosing closure, with the enclosing named function recorded so the
 * prober knows what to run.
 *
 * Arrow functions are not scanned: a `fn() =>` body is a single expression and this
 * repo has none containing an assertion (checked while writing this script).
 *
 * @return list<array{file: string, closureLine: int, assertLine: int, method: string}>
 */
function collectSites(string $testsDir): array
{
    $files = [];
    /** @var iterable<\SplFileInfo> $it */
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDir));

    foreach ($it as $entry) {
        if ($entry->isFile() && str_ends_with($entry->getFilename(), '.php')) {
            $files[] = $entry->getPathname();
        }
    }

    sort($files);
    $sites = [];

    foreach ($files as $file) {
        foreach (collectSitesInFile($file) as $site) {
            $sites[] = $site;
        }
    }

    return $sites;
}

/**
 * @return list<array{file: string, closureLine: int, assertLine: int, method: string}>
 */
function collectSitesInFile(string $file): array
{
    $tokens = token_get_all((string) file_get_contents($file));
    $t = [];

    foreach ($tokens as $token) {
        $t[] = is_array($token) ? [$token[0], $token[1], $token[2]] : [-1, $token, null];
    }

    $line = 1;

    foreach ($t as $i => $entry) {
        if ($entry[2] !== null) {
            $line = $entry[2];
        } else {
            $t[$i][2] = $line;
        }
        $line += substr_count((string) $t[$i][1], "\n");
    }

    $n = count($t);
    $methods = [];
    $closures = [];

    for ($i = 0; $i < $n; $i++) {
        if ($t[$i][0] !== T_FUNCTION && $t[$i][0] !== T_FN) {
            continue;
        }

        $j = $i + 1;

        while ($j < $n && $t[$j][0] === T_WHITESPACE) {
            $j++;
        }

        if ($t[$j][0] === T_STRING) {
            $methods[$i] = (string) $t[$j][1];

            continue;
        }

        if ($t[$i][0] !== T_FUNCTION) {
            continue;
        }

        $depth = 0;
        $brace = null;

        for ($k = $j; $k < $n; $k++) {
            if ($t[$k][1] === '(') {
                $depth++;
            } elseif ($t[$k][1] === ')') {
                $depth--;
            } elseif ($t[$k][1] === '{' && $depth === 0) {
                $brace = $k;

                break;
            } elseif ($t[$k][1] === ';' && $depth === 0) {
                break;
            }
        }

        if ($brace === null) {
            continue;
        }

        $open = 0;

        for ($k = $brace; $k < $n; $k++) {
            if ($t[$k][1] === '{' || $t[$k][0] === T_CURLY_OPEN || $t[$k][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $open++;
            } elseif ($t[$k][1] === '}') {
                $open--;

                if ($open === 0) {
                    break;
                }
            }
        }

        $closures[$i] = ['brace' => $brace, 'end' => $k, 'line' => (int) $t[$i][2]];
    }

    $firstAssert = [];

    for ($m = 0; $m < $n; $m++) {
        if ($t[$m][0] !== T_STRING || preg_match('/^(assert[A-Z]\w*|fail)$/', (string) $t[$m][1]) !== 1) {
            continue;
        }

        $p = $m - 1;

        while ($p >= 0 && $t[$p][0] === T_WHITESPACE) {
            $p--;
        }

        if (!in_array($t[$p][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            continue;
        }

        $innermost = null;

        foreach ($closures as $ci => $closure) {
            if ($m > $closure['brace'] && $m < $closure['end']) {
                if ($innermost === null || $closure['brace'] > $closures[$innermost]['brace']) {
                    $innermost = $ci;
                }
            }
        }

        if ($innermost !== null && !isset($firstAssert[$innermost])) {
            $firstAssert[$innermost] = (int) $t[$m][2];
        }
    }

    $sites = [];

    foreach ($firstAssert as $ci => $assertLine) {
        $method = '?';

        foreach ($methods as $mi => $name) {
            if ($mi < $ci) {
                $method = $name;
            }
        }

        $sites[] = [
            'file' => $file,
            'closureLine' => $closures[$ci]['line'],
            'assertLine' => $assertLine,
            'method' => $method,
        ];
    }

    return $sites;
}

/**
 * @param array{file: string, closureLine: int, assertLine: int, method: string} $site
 */
function probeSite(string $root, array $site): string
{
    $file = $site['file'];
    $backup = tempnam(sys_get_temp_dir(), 's120-');

    if ($backup === false) {
        return 'ERROR';
    }

    copy($file, $backup);
    $originalHash = (string) md5_file($file);
    $marker = $backup . '.ran';
    @unlink($marker);

    $restore = static function () use ($file, $backup, $originalHash): void {
        if (md5_file($file) !== $originalHash) {
            copy($backup, $file);
        }
    };
    register_shutdown_function($restore);

    $lines = file($file);

    if ($lines === false) {
        return 'ERROR';
    }

    $tripwire = sprintf(
        "            \\file_put_contents(%s, 'ran'); self::assertTrue(false, '%s');\n",
        var_export($marker, true),
        TRIPWIRE_MESSAGE,
    );
    array_splice($lines, $site['assertLine'] - 1, 0, [$tripwire]);
    file_put_contents($file, implode('', $lines));

    // `--no-extensions` keeps this probe INDEPENDENT of the runtime guard. With the
    // guard loaded, its STDERR block quotes the swallowed message, which would make a
    // DEGRADED site look like it named its own failure and hide the very shape this
    // probe exists to find (observed on sites 12 and 23 of the S120 census).
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/vendor/bin/phpunit')
        . ' ' . escapeshellarg($file) . ' --no-coverage --no-extensions';

    if (str_starts_with($site['method'], 'test')) {
        $command .= ' --filter ' . escapeshellarg($site['method']);
    }

    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);

    $restore();
    $ran = is_file($marker);
    @unlink($marker);
    @unlink($backup);

    if (md5_file($file) !== $originalHash) {
        fwrite(STDERR, "S120 AUDIT: FAILED TO RESTORE {$file} — restore it from git before continuing.\n");

        exit(2);
    }

    if (!$ran) {
        return 'NOT-REACHED';
    }

    if ($status === 0) {
        return 'VACUOUS';
    }

    return str_contains(implode("\n", $output), TRIPWIRE_MESSAGE) ? 'GATES' : 'DEGRADED';
}
