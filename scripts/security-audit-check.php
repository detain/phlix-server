<?php

/**
 * Make the "Security Audit" job in `.github/workflows/coding-standards.yml`
 * actually audit something.
 *
 * ## The defect this replaces
 *
 * The "Run Security Audit" step was a no-op for the entire life of the repo:
 *
 * ```bash
 * if [ -f vendor/bin/security-checker ]; then
 *   ./vendor/bin/security-checker security:check
 * elif [ -f vendor/bin/composer-audit ]; then
 *   ./vendor/bin/composer-audit audit --format=github
 * fi
 * ```
 *
 * **Neither binary has ever existed.** `sensiolabs/security-checker` and any
 * package shipping a `composer-audit` binary are both absent from composer.json,
 * so `vendor/bin/` contains `phpcs`, `phpstan` and `psalm` and neither of these
 * two. Both branches were therefore always false, the `if` fell through, the
 * step exited 0, and the job reported GREEN having audited nothing.
 *
 * This is the same defect class as S146 (the Psalm job that spent its life green
 * having analysed zero files) and as the coverage-threshold repair (an `xmllint`
 * parse that silently skipped because xmllint is not installed on
 * ubuntu-latest). Same shape every time: a *missing tool* behind a conditional
 * that treats absence as success.
 *
 * ## Why `composer audit` and not a package
 *
 * `sensiolabs/security-checker` is ABANDONED upstream — adding it would import a
 * dead dependency to check for dead dependencies. `composer audit` ships inside
 * Composer itself as of 2.4, needs nothing added to composer.json, reads the
 * same Packagist advisory database, and supports `--format=json`.
 *
 * It is invoked with `--locked`, which audits `composer.lock` directly and needs
 * no `vendor/` at all. That matters for two reasons: the lock is the committed
 * artifact a PR actually changes, and Composer 2.10 refuses to *install*
 * packages with known advisories (`policy.advisories.block`), so auditing after
 * `composer install` would mean a vulnerable lock died in the solver with an
 * opaque dependency-resolution error before the audit ever ran.
 *
 * ## Blocking or advisory: BOTH, split by category
 *
 * `composer audit` returns a single exit code that conflates two very different
 * findings, and on THIS repo that conflation is not hypothetical. Measured on
 * this tree, `composer audit --locked` exits **1** with **zero** security
 * advisories — entirely because two packages are abandoned:
 *
 *     fgrosse/phpasn1            (no replacement offered)
 *     web-auth/metadata-service  (replaced by web-auth/webauthn-lib)
 *
 * Both are transitive, pulled in by web-auth/webauthn-lib and
 * web-token/jwt-framework. Neither is fixable from this repo. Composer's default
 * abandoned policy is AUDIT_FAIL, so simply running `composer audit` and
 * trusting `$?` would turn EVERY pull request red on day one for a reason nobody
 * here can act on — and a gate that is red for reasons unrelated to the code
 * gets switched off. That is stated outright in the Psalm job three sections
 * above this one, and it is exactly how the no-op guard being removed here came
 * to exist in the first place.
 *
 * So the verdict is computed from `--format=json` rather than inherited from the
 * exit code:
 *
 * | finding                     | verdict                                     |
 * | --------------------------- | ------------------------------------------- |
 * | security advisory           | **BLOCKING** — exit 1                       |
 * | abandoned package           | ADVISORY — loud `::warning::`, exit 0       |
 * | advisory ignored via config | ADVISORY — loud `::notice::`, exit 0        |
 * | unreachable advisory repo   | **BLOCKING** — exit 1 (audited nothing)     |
 * | missing / unparseable JSON  | **BLOCKING** — exit 1                       |
 * | composer absent or < 2.4    | **BLOCKING** — exit 1                       |
 *
 * A known-vulnerable transitive dependency therefore cannot pass silently, while
 * an unfixable abandonment cannot wedge the pipeline. Crucially the advisory
 * cases are LOUD — printed as GitHub annotations that surface in the PR — rather
 * than implicit in a swallowed exit code, which is the failure mode this whole
 * file exists to delete.
 *
 * The last row is the coverage gate's lesson restated: if the advisory database
 * could not be reached, the audit MEASURED NOTHING, and a gate that cannot
 * measure must fail rather than report success.
 *
 * Usage:
 *   php scripts/security-audit-check.php                 # runs composer audit itself
 *   php scripts/security-audit-check.php audit.json      # reads a captured payload
 *
 * The optional path argument exists so the guard tests can exercise every
 * verdict offline, without a network round-trip to Packagist.
 *
 * Environment:
 *   COMPOSER_BIN  path to the composer binary (default: `composer` on PATH)
 *
 * Exit codes: 0 = no blocking finding. 1 = a security advisory was found, OR the
 * audit could not run at all (which is a failure, not a skip).
 */

declare(strict_types=1);

const MIN_COMPOSER_VERSION = '2.4.0';

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

/**
 * Emit a non-blocking annotation. The whole point of this script is that these
 * are VISIBLE rather than swallowed, so they are real workflow commands and not
 * a bare echo.
 *
 * @param 'notice'|'warning' $level
 */
function annotate(string $level, string $headline, string ...$detail): void
{
    fwrite(STDOUT, '::' . $level . '::' . $headline . "\n");

    foreach ($detail as $line) {
        fwrite(STDOUT, '  ' . $line . "\n");
    }
}

/**
 * Run a command without a shell and capture both streams separately.
 *
 * @param list<string> $command
 *
 * @return array{stdout: string, stderr: string, exit: int}
 */
function runProcess(array $command): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $pipes   = [];
    $process = @proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        return ['stdout' => '', 'stderr' => 'proc_open() failed', 'exit' => 127];
    }

    fclose($pipes[0]);

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => proc_close($process)];
}

/**
 * Resolve the composer binary and prove it can audit, or die trying.
 *
 * This is the direct analogue of S146's "Assert Psalm is actually installed":
 * the tool being absent must fail LOUDLY. It must never degrade back into
 * `if [ -f ... ]; then ... fi`.
 */
function assertComposerCanAudit(string $composerBin): string
{
    $probe = runProcess([$composerBin, '--version', '--no-interaction']);

    if ($probe['exit'] !== 0 || $probe['stdout'] === '') {
        fail(
            sprintf('Cannot run "%s --version" — the security audit tool is not available.', $composerBin),
            'composer must be on PATH (setup-php provides it) or COMPOSER_BIN must point at it.',
            sprintf('exit=%d stderr=%s', $probe['exit'], trim($probe['stderr']) !== '' ? trim($probe['stderr']) : '<empty>'),
            'Do NOT wrap the audit in a conditional that skips when the tool is absent — that is',
            'the exact defect this script replaces (S146).',
        );
    }

    if (preg_match('/Composer(?:\s+version)?\s+(\d+\.\d+\.\d+)/i', $probe['stdout'], $matches) !== 1) {
        fail(
            sprintf('Could not parse a version out of "%s --version".', $composerBin),
            'Output was: ' . trim($probe['stdout']),
        );
    }

    $version = $matches[1];

    if (version_compare($version, MIN_COMPOSER_VERSION, '<')) {
        fail(
            sprintf('Composer %s is too old — `composer audit` was added in %s.', $version, MIN_COMPOSER_VERSION),
            'Pin a newer composer in the setup-php step (tools: composer:v2).',
        );
    }

    fwrite(STDOUT, sprintf("Auditing with composer %s (%s)\n", $version, $composerBin));

    return $composerBin;
}

/**
 * Ask composer for the audit payload.
 *
 * The exit code is deliberately NOT used as the verdict: it folds abandoned
 * packages in with real advisories (see the header). It is only reported when
 * the payload fails to parse, where it helps explain why.
 */
function captureAuditPayload(string $composerBin): string
{
    $result = runProcess([
        $composerBin,
        'audit',
        '--locked',
        '--format=json',
        '--no-interaction',
    ]);

    if (trim($result['stdout']) === '') {
        fail(
            'composer audit produced no output — the audit did not run.',
            sprintf('exit=%d', $result['exit']),
            'stderr: ' . (trim($result['stderr']) !== '' ? trim($result['stderr']) : '<empty>'),
            'A common cause is a missing or stale composer.lock (--locked needs one).',
        );
    }

    return $result['stdout'];
}

/**
 * Decode the payload, or fail. An unreadable audit is a failed audit.
 *
 * @return array<string, mixed>
 */
function decodePayload(string $raw, string $origin): array
{
    try {
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fail(
            sprintf('Audit payload from %s is not parseable JSON.', $origin),
            'json_decode: ' . $e->getMessage(),
            'First 200 bytes: ' . substr(trim($raw), 0, 200),
        );
    }

    if (!is_array($decoded)) {
        fail(sprintf('Audit payload from %s is not a JSON object.', $origin));
    }

    if (!array_key_exists('advisories', $decoded)) {
        fail(
            sprintf('Audit payload from %s has no "advisories" key.', $origin),
            'composer audit --format=json always emits one, so this is not a composer audit',
            'payload and the gate cannot read it. Failing rather than assuming "no advisories".',
        );
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * `composer audit --format=json` emits `[]` for an empty advisory set and a
 * package-keyed object when populated. Normalise both to a map.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function normaliseAdvisoryMap(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $map = [];

    foreach ($value as $package => $entries) {
        if (!is_array($entries)) {
            continue;
        }

        $list = [];

        foreach ($entries as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $list[] = $entry;
            }
        }

        $map[(string) $package] = $list;
    }

    return $map;
}

/**
 * @param array<string, mixed> $advisory
 */
function describeAdvisory(array $advisory): string
{
    $severity = is_string($advisory['severity'] ?? null) ? strtoupper($advisory['severity']) : 'UNKNOWN';
    $cve      = is_string($advisory['cve'] ?? null) && $advisory['cve'] !== ''
        ? $advisory['cve']
        : (is_string($advisory['advisoryId'] ?? null) ? $advisory['advisoryId'] : 'unidentified');
    $title    = is_string($advisory['title'] ?? null) ? $advisory['title'] : '(no title)';

    return sprintf('[%s] %s — %s', $severity, $cve, $title);
}

/**
 * @param array<string, list<array<string, mixed>>> $advisories
 *
 * @return list<string>
 */
function renderAdvisoryLines(array $advisories): array
{
    $lines = [];

    foreach ($advisories as $package => $entries) {
        $affected = '';

        foreach ($entries as $entry) {
            if (is_string($entry['affectedVersions'] ?? null)) {
                $affected = $entry['affectedVersions'];

                break;
            }
        }

        $lines[] = $affected !== ''
            ? sprintf('%s (affected: %s)', $package, $affected)
            : $package;

        foreach ($entries as $entry) {
            $lines[] = '  ' . describeAdvisory($entry);

            if (is_string($entry['link'] ?? null) && $entry['link'] !== '') {
                $lines[] = '    ' . $entry['link'];
            }
        }
    }

    return $lines;
}

/**
 * @param array<string, list<array<string, mixed>>> $advisories
 */
function countAdvisories(array $advisories): int
{
    $total = 0;

    foreach ($advisories as $entries) {
        $total += count($entries);
    }

    return $total;
}

// ---------------------------------------------------------------------------
// Acquire the payload — either from a file (tests) or from composer (CI).
// ---------------------------------------------------------------------------

$payloadPath = $argv[1] ?? null;

if (is_string($payloadPath) && $payloadPath !== '') {
    if (!is_file($payloadPath)) {
        fail(sprintf('Audit payload "%s" does not exist.', $payloadPath));
    }

    $raw    = (string) file_get_contents($payloadPath);
    $origin = $payloadPath;

    if (trim($raw) === '') {
        fail(sprintf('Audit payload "%s" is empty.', $payloadPath));
    }
} else {
    $composerBin = getenv('COMPOSER_BIN');

    if (!is_string($composerBin) || trim($composerBin) === '') {
        $composerBin = 'composer';
    }

    $raw    = captureAuditPayload(assertComposerCanAudit(trim($composerBin)));
    $origin = 'composer audit --locked';
}

$payload = decodePayload($raw, $origin);

// ---------------------------------------------------------------------------
// Guard — an audit that could not reach its advisory source measured NOTHING.
//
// Same rule as the coverage gate: cannot-measure must fail, never pass.
// ---------------------------------------------------------------------------

$unreachable = $payload['unreachable-repositories'] ?? [];

if (is_array($unreachable) && $unreachable !== []) {
    $names = [];

    foreach ($unreachable as $repo) {
        if (is_string($repo)) {
            $names[] = '  ' . $repo;

            continue;
        }

        $encoded = json_encode($repo);

        $names[] = '  ' . (is_string($encoded) ? $encoded : '<unprintable>');
    }

    fail(
        sprintf('%d advisory repository/ies were unreachable — the audit measured nothing.', count($names)),
        ...$names,
    );
}

// ---------------------------------------------------------------------------
// Advisory-only findings. LOUD, but they do not block.
// ---------------------------------------------------------------------------

$ignored = normaliseAdvisoryMap($payload['ignored-advisories'] ?? []);

if ($ignored !== []) {
    annotate(
        'notice',
        sprintf(
            '%d security advisory/ies affecting %d package(s) are IGNORED by composer config — acknowledged, not fixed.',
            countAdvisories($ignored),
            count($ignored),
        ),
        ...renderAdvisoryLines($ignored),
    );
}

$abandoned = $payload['abandoned'] ?? [];

if (is_array($abandoned) && $abandoned !== []) {
    $lines = [];

    foreach ($abandoned as $package => $replacement) {
        $lines[] = sprintf(
            '%s — %s',
            (string) $package,
            is_string($replacement) && $replacement !== ''
                ? 'replaced by ' . $replacement
                : 'no replacement suggested',
        );
    }

    annotate(
        'warning',
        sprintf(
            '%d abandoned package(s). ADVISORY ONLY — this does NOT fail the build.',
            count($lines),
        ),
        ...array_merge($lines, [
            'Abandonment is not a vulnerability, and these are transitive dependencies that',
            'cannot be fixed from this repo. Blocking on them would make every PR red for a',
            'reason nobody can act on, and a gate that is red for unrelated reasons gets',
            'switched off — which is how the no-op this job replaces came to exist.',
        ]),
    );
}

// ---------------------------------------------------------------------------
// The blocking verdict.
// ---------------------------------------------------------------------------

$advisories = normaliseAdvisoryMap($payload['advisories']);

if ($advisories !== []) {
    fail(
        sprintf(
            'Security audit FAILED — %d advisory/ies affecting %d package(s).',
            countAdvisories($advisories),
            count($advisories),
        ),
        ...array_merge(renderAdvisoryLines($advisories), [
            'Update the affected package(s). If an advisory genuinely cannot be actioned,',
            'acknowledge it explicitly in composer.json so it is recorded in the repo and',
            'reported above as IGNORED — do not disable this gate.',
        ]),
    );
}

fwrite(STDOUT, "No security advisories affecting locked packages.\n");
fwrite(STDOUT, "Security audit passed.\n");

exit(0);
