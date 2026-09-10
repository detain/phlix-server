<?php

/**
 * S304 — the EXECUTED assertion behind every CI `Setup PHP` step (token CS304CIEXTX9K2).
 *
 * ## Why this exists (and why the `extensions:` line alone is not the gate)
 *
 * `shivammathur/setup-php`'s `fail_fast` defaults to **false**, so an extension it
 * is asked to install but cannot is a red cross in the log and a **GREEN step**.
 * Naming `sodium` in `extensions:` therefore makes setup-php *try*; it does not
 * make the build *fail* when the result is a PHP without `sodium`. The [[S304]]
 * audit (plan_updates.md:6258) is explicit: "naming is not a gate … the executed
 * assertion is the load-bearing part, not the `extensions:` line."
 *
 * `composer.json`'s `config.platform-check` + `vendor/composer/platform_check.php`
 * (S314) already abort on a missing extension — but only for the 22 entries that
 * are not silently provided by a polyfill, and only for jobs that include the
 * Composer autoloader. This closes the remaining gap with a check that is:
 *
 *   - **Sourced from the contract, never re-derived.** The required set is the
 *     key list of `scripts/required-php-extensions.php` — the single source of
 *     truth S314 derived from phlix-server's own call sites. A second list here
 *     would be the exact drift this estate keeps repeating (see the header of
 *     that file). We `require` it; we do not copy from it.
 *   - **Executed on every server-source job**, before the slow `composer install`,
 *     so the failure names the missing extension in seconds, not after a solver
 *     error three steps later.
 *   - **Self-proving against the no-op failure mode.** The whole hazard of an
 *     extension gate is that it can pass while checking nothing (S146's Psalm job,
 *     S146's coverage gate, this repo's old `if [ -f ... ]` guards). Before it
 *     trusts itself, this script confirms its own detector actually reports a
 *     guaranteed-absent extension as absent. If that self-check ever flips — the
 *     function was wrapped, the loop was `|| true`-d, the exit code neutered — the
 *     script fails LOUDLY rather than degrading to a spelling that proves nothing.
 *
 * ## The contract it enforces (per AC, plan_updates.md:6260)
 *
 * Fails the job when a required extension is absent — including `sodium`
 * (`src/Hub/HubJwtValidator.php`, `src/Server/Integrations/Trakt/SodiumTokenCipher.php`)
 * and `hash` (`src/Auth/SignedUrl.php`, `src/Auth/JwtHandler.php`), the two the
 * audit found named nowhere, proven with a negative control at PR time.
 *
 * ## Usage
 *
 *   php scripts/assert-php-extensions.php
 *   php scripts/assert-php-extensions.php --require=swoole --require=ffi
 *
 * `--require=<ext>` (repeatable) adds a name the CALLER's job also depends on but
 * that is not a server-source contract entry (e.g. the psalm/e2e jobs' swoole). It
 * is deliberately additive to, never a replacement for, the contract set. It doubles
 * as the documented negative-control knob: `--require=this_ext_does_not_exist`
 * reddens exactly the job that carries it, which is the AC's "forced assertion flip"
 * proof, without touching the contract file.
 *
 * Exit codes: 0 = every required extension is loaded. 1 = at least one is absent, OR
 * the self-check detected that this gate has been neutered into a no-op (a failure
 * must never be able to read as a pass — the entire S146/S304 defect class).
 */

declare(strict_types=1);

/**
 * A name no real PHP extension can ever have. The self-check asks
 * `extension_loaded()` about this and requires the answer to be `false`. If it is
 * ever `true`, or the loop cannot turn it into a failure, the detection path is
 * dead and the gate is a no-op — which is exactly what it must never become.
 */
const SELFTEST_SENTINEL_EXTENSION = '__php_ext_assert_selftest_9f3c__';

/**
 * S304 merge-ritual survival token. CODE-resident on purpose (a docblock mention
 * would not survive `php -w`): the premerge ritual greps the tokenized corpus for
 * this literal to prove THIS file shipped with the branch.
 */
const S304_SURVIVAL_TOKEN = 'CS304CIEXTX9K2';

/**
 * The security-critical subset the S304 audit singled out. Named in the failure
 * output so a reviewer reading a red job sees the risk, not just a list of strings.
 */
const SECURITY_CRITICAL = ['sodium', 'hash'];

/**
 * Emit a GitHub Actions error annotation and stop. Same helper shape as
 * `coverage-threshold-check.php`: annotations go to STDOUT because that is the
 * stream the runner scans for workflow commands.
 */
function fail(string $headline, string ...$detail): never
{
    fwrite(STDOUT, '::error::' . $headline . "\n");

    foreach ($detail as $line) {
        fwrite(STDOUT, $line . "\n");
    }

    exit(1);
}

/**
 * Parse the CLI arguments into a list of caller-supplied extra extension names.
 *
 * Unknown `--flag` values are a hard error, not a shrug: a typo'd `--requires=`
 * would otherwise read as "no extras" and let a job that meant to pin swoole pass
 * without pinning it — the silent-degradation this whole file exists to prevent.
 *
 * @param array<int, string> $argv
 *
 * @return list<string>
 */
function parse_extra_requires(array $argv): array
{
    $extras = [];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--require' || $arg === '--require=') {
            fail('`--require` needs a value.', 'Usage: php scripts/assert-php-extensions.php --require=<ext>');
        }

        if (str_starts_with($arg, '--require=')) {
            $name = substr($arg, strlen('--require='));

            if (trim($name) === '') {
                fail('`--require=` was given an empty value.');
            }

            $extras[] = trim($name);
            continue;
        }

        fail(
            sprintf('Unrecognised argument "%s".', $arg),
            'Supported: --require=<ext> (repeatable).',
        );
    }

    return $extras;
}

// ---------------------------------------------------------------------------
// 0. Self-check: prove the detector is live before trusting its verdict.
// ---------------------------------------------------------------------------

// A guaranteed-absent name MUST read as not-loaded. If it reads as loaded, the
// only possibilities are a broken/shimmed extension_loaded() or a compiler that
// invented an extension — either way this gate cannot be trusted to say "absent",
// so it must not be trusted to say "present" either.
if (extension_loaded(SELFTEST_SENTINEL_EXTENSION)) {
    fail(
        'Self-check failed: extension_loaded() reports the sentinel "'
            . SELFTEST_SENTINEL_EXTENSION . '" as loaded, which is impossible.',
        'The absence detector this gate relies on is not trustworthy. Investigate before trusting a green run.',
    );
}

// ---------------------------------------------------------------------------
// 1. Load the contract (consume it — never re-derive a second list).
// ---------------------------------------------------------------------------

$contractPath = __DIR__ . '/required-php-extensions.php';

if (!is_file($contractPath)) {
    fail(sprintf('The extension contract file "%s" is missing.', $contractPath));
}

$contract = require $contractPath;

if (!is_array($contract) || $contract === []) {
    fail(
        'The extension contract returned an unexpected value.',
        sprintf('Expected a non-empty array keyed by extension name; got %s.', get_debug_type($contract)),
    );
}

$required = array_keys($contract);

foreach (parse_extra_requires($argv) as $extra) {
    if (!in_array($extra, $required, true)) {
        $required[] = $extra;
    }
}

// ---------------------------------------------------------------------------
// 2. The assertion. extension_loaded() is case-insensitive, so the contract's
//    lowercase keys match the registry's canonical names ('SimpleXML', 'PDO', …).
// ---------------------------------------------------------------------------

$missing = [];
$present = [];

foreach ($required as $extension) {
    if (extension_loaded($extension)) {
        $present[] = $extension;
        continue;
    }

    $missing[] = $extension;
}

$total = count($required);

if ($missing !== []) {
    $securityMisses = array_values(array_intersect(SECURITY_CRITICAL, $missing));

    $headline = sprintf(
        'PHP is missing %d required extension(s): %s',
        count($missing),
        implode(', ', $missing),
    );

    $detail = [
        'Source of truth: scripts/required-php-extensions.php (the S314 derived contract).',
        'Naming them in a workflow `extensions:` line is NOT enough — setup-php `fail_fast`',
        'defaults to false, so an extension it cannot install is still a GREEN step. This',
        'executed assertion is the load-bearing gate (S304).',
    ];

    if ($securityMisses !== []) {
        $detail[] = 'SECURITY-CRITICAL among them: ' . implode(', ', $securityMisses)
            . ' — these guard the hub JWT, Trakt token-at-rest cipher, signed media URLs and JWTs.';
    }

    $detail[] = 'Fix the `extensions:` list on the Setup PHP step of THIS job, or the runner image —';
    $detail[] = sprintf('do NOT weaken or delete this assertion (present: %d/%d).', count($present), $total);

    fail($headline, ...$detail);
}

fwrite(STDOUT, sprintf(
    "Required PHP extensions: all %d loaded (contract + %d job-specific).\n",
    $total,
    $total - count($contract),
));

exit(0);
