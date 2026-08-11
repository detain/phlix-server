<?php

/**
 * S305 — install and PROVE the prerequisites of the headless-browser HLS gate.
 *
 * ## The defect this closes, measured
 *
 * S57's three `Fmp4HlsPlaybackE2ETest` cases — real ffmpeg, real hls.js, real
 * headless Chrome, playing past a segment boundary — **skipped on every CI run**.
 * `.github/workflows/phpunit.yml` never installed `web-ui/node_modules`, so hls.js
 * was absent and `setUp()` called `markTestSkipped()`. Measured on PR #664 (run
 * `31263130044`): `Skipped: 6` against master's 3, exactly those three cases.
 *
 * 🔴 **A skipped test reads as a pass.** PHPUnit exits 0 with "OK, but some tests
 * were skipped!" and, with no branch protection in this estate, `skipped` counts as
 * SUCCESS. So the strongest evidence S57 produced was invisible to the gate that
 * guards master.
 *
 * This script is the SUPPLY half of the fix: it puts hls.js on disk and asserts a
 * browser, a node and an ffmpeg the probe can actually use. The PROOF half is
 * `scripts/assert-browser-e2e-ran.php`, which reads the JUnit report afterwards and
 * fails when a required case did not execute. Neither substitutes for the other:
 * this one can only say the ingredients are present, that one says the cases ran.
 *
 * ## Pinning — read this before changing how hls.js is fetched
 *
 * ⚠ [[S309]] is live in this repo: a CI job cloned `bwoebi/php-uv` at an UNPINNED
 * HEAD over the network and a TLS hiccup redded an unrelated PR. Adding another
 * unpinned network fetch to the job that decides merges would be a regression
 * against it. So:
 *
 *   * the version, the URL **and the sha512** all come from `web-ui/package-lock.json`
 *     (hls.js is a transitive dependency of `@phlix/ui`), never from a literal here;
 *   * the downloaded bytes are verified against that hash BEFORE extraction, so this
 *     is content-addressed, not merely version-pinned;
 *   * the version in use is PRINTED, so a reader of the log can find it;
 *   * the fetch retries with backoff, and a failure is loud — never `|| true`.
 *
 * `npm ci` in `web-ui/` was considered and rejected for this job: it pulls the whole
 * Vite/TypeScript/Vue toolchain plus a GitHub tarball for `@phlix/ui` — a minute of
 * install and a second remote host on the critical path — to obtain one 500 KB file.
 *
 * ## Usage
 *
 *   php scripts/ci-browser-e2e-prereqs.php
 *   php scripts/ci-browser-e2e-prereqs.php --lock=… --dest=… --tarball=… --skip-binaries
 *
 * The four options exist ONLY so `tests/Unit/Support/BrowserE2EGateTest.php` can
 * exercise both directions of the integrity check offline. Using any of them in the
 * workflow would narrow the gate, so that same test asserts the CI step passes none
 * of them.
 *
 * @package Phlix
 */

declare(strict_types=1);

use Phlix\Tests\Support\Browser\BrowserProbeEnvironment;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "::error::S305 browser-E2E prerequisites: vendor/autoload.php is missing; run composer install.\n");
    exit(1);
}
require_once $autoload;

/** Every failure path ends here. There is no branch that returns success unmeasured. */
$fail = static function (string $message): never {
    fwrite(STDERR, '::error::S305 browser-E2E prerequisites: ' . $message . "\n");
    exit(1);
};

$say = static function (string $message): void {
    fwrite(STDOUT, $message . "\n");
};

/**
 * @param list<string> $argv
 */
$option = static function (array $argv, string $name, ?string $default = null): ?string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return $default;
};

/** @var list<string> $args */
$args = array_slice($argv, 1);
$repoRoot = dirname(__DIR__);
$lockPath = $option($args, 'lock', $repoRoot . '/' . BrowserProbeEnvironment::WEB_UI_LOCKFILE) ?? '';
$destDir = $option($args, 'dest', $repoRoot . '/web-ui/node_modules/hls.js') ?? '';
$localTarball = $option($args, 'tarball');
$skipBinaries = in_array('--skip-binaries', $args, true);

// ---------------------------------------------------------------------------
// 1. Resolve the pin out of the lockfile.
// ---------------------------------------------------------------------------

if (!is_file($lockPath)) {
    $fail(sprintf('the lockfile %s does not exist, so the hls.js version cannot be pinned.', $lockPath));
}

/** @var mixed $lock */
$lock = json_decode((string) file_get_contents($lockPath), true);
if (!is_array($lock) || !is_array($lock['packages'] ?? null)) {
    $fail(sprintf('%s is not a readable npm lockfile (no "packages" object).', $lockPath));
}

/** @var array<string, mixed> $packages */
$packages = $lock['packages'];
$entry = $packages[BrowserProbeEnvironment::HLSJS_LOCK_KEY] ?? null;
if (!is_array($entry)) {
    $fail(sprintf(
        '%s has no "%s" entry. hls.js is a transitive dependency of @phlix/ui and the S57 browser '
        . 'gate cannot run without it. If the SPA genuinely stopped using hls.js, that is a real '
        . 'decision about the gate — make it deliberately; do not delete this step.',
        $lockPath,
        BrowserProbeEnvironment::HLSJS_LOCK_KEY,
    ));
}

$version = is_string($entry['version'] ?? null) ? $entry['version'] : '';
$resolved = is_string($entry['resolved'] ?? null) ? $entry['resolved'] : '';
$integrity = is_string($entry['integrity'] ?? null) ? $entry['integrity'] : '';

if ($version === '' || $resolved === '' || $integrity === '') {
    $fail(sprintf(
        'the "%s" lockfile entry is missing version/resolved/integrity, so the download cannot be '
        . 'pinned or verified.',
        BrowserProbeEnvironment::HLSJS_LOCK_KEY,
    ));
}

if (!str_starts_with($integrity, 'sha512-')) {
    $fail(sprintf('the lockfile integrity for hls.js is "%s"; only sha512 is accepted here.', $integrity));
}

if (!str_starts_with($resolved, 'https://')) {
    $fail(sprintf('the lockfile resolves hls.js from "%s"; only https is accepted.', $resolved));
}

$say(sprintf('hls.js pinned by %s: version %s', basename($lockPath), $version));
$say(sprintf('  url       %s', $resolved));
$say(sprintf('  integrity %s', $integrity));

// ---------------------------------------------------------------------------
// 2. Install it, unless the pinned version is already on disk.
// ---------------------------------------------------------------------------

$distFile = $destDir . '/dist/hls.min.js';
$installedVersion = null;
if (is_file($destDir . '/package.json')) {
    /** @var mixed $installedPackage */
    $installedPackage = json_decode((string) file_get_contents($destDir . '/package.json'), true);
    if (is_array($installedPackage) && is_string($installedPackage['version'] ?? null)) {
        $installedVersion = $installedPackage['version'];
    }
}

if ($installedVersion === $version && is_file($distFile)) {
    $say(sprintf('hls.js %s already installed at %s (%d bytes) — nothing to fetch.', $version, $distFile, (int) filesize($distFile)));
} else {
    $bytes = null;

    if (is_string($localTarball)) {
        // Offline path, used by the unit test only.
        if (!is_file($localTarball)) {
            $fail(sprintf('--tarball=%s does not exist.', $localTarball));
        }
        $bytes = (string) file_get_contents($localTarball);
        $say(sprintf('read %d bytes from --tarball=%s', strlen($bytes), $localTarball));
    } else {
        $context = stream_context_create(['http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'phlix-server-ci/s305',
        ]]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $downloaded = @file_get_contents($resolved, false, $context);
            if (is_string($downloaded) && $downloaded !== '') {
                $bytes = $downloaded;
                $say(sprintf('downloaded %d bytes on attempt %d', strlen($bytes), $attempt));
                break;
            }
            $say(sprintf('attempt %d to fetch %s failed', $attempt, $resolved));
            if ($attempt < 3) {
                // A blocking sleep is forbidden inside the Workerman workers; this is a
                // one-shot CI script with no event loop, so a backoff here costs nothing
                // but the wait it is asking for.
                sleep($attempt * 3);
            }
        }
    }

    if (!is_string($bytes) || $bytes === '') {
        $fail(sprintf(
            'could not download %s after 3 attempts. This job depends on the npm registry being '
            . 'reachable; do NOT make this step non-fatal — that turns "hls.js is missing" back into '
            . 'three silently skipped tests.',
            $resolved,
        ));
    }

    $actual = 'sha512-' . base64_encode(hash('sha512', $bytes, true));
    if (!hash_equals($integrity, $actual)) {
        $fail(sprintf(
            "the downloaded tarball does NOT match the lockfile integrity.\n  expected %s\n  actual   %s",
            $integrity,
            $actual,
        ));
    }
    $say('integrity verified against the lockfile hash');

    $work = sys_get_temp_dir() . '/phlix-hlsjs-' . bin2hex(random_bytes(6));
    if (!mkdir($work, 0o755, true) && !is_dir($work)) {
        $fail(sprintf('could not create the working directory %s.', $work));
    }
    $archive = $work . '/hls.tgz';
    file_put_contents($archive, $bytes);

    $extractDir = $work . '/x';
    mkdir($extractDir, 0o755, true);
    $command = sprintf('tar -xzf %s -C %s 2>&1', escapeshellarg($archive), escapeshellarg($extractDir));
    /** @var list<string> $output */
    $output = [];
    $code = 0;
    exec($command, $output, $code);
    if ($code !== 0) {
        $fail(sprintf("tar could not extract the hls.js tarball (exit %d):\n%s", $code, implode("\n", $output)));
    }

    // npm tarballs put everything under a single `package/` directory.
    $payload = $extractDir . '/package';
    if (!is_dir($payload)) {
        $fail(sprintf('the tarball did not contain the expected package/ directory (looked in %s).', $extractDir));
    }

    if (!is_dir(dirname($destDir)) && !mkdir(dirname($destDir), 0o755, true) && !is_dir(dirname($destDir))) {
        $fail(sprintf('could not create %s.', dirname($destDir)));
    }
    if (is_dir($destDir)) {
        exec(sprintf('rm -rf %s', escapeshellarg($destDir)));
    }
    if (!rename($payload, $destDir)) {
        $fail(sprintf('could not move the extracted package into %s.', $destDir));
    }
    exec(sprintf('rm -rf %s', escapeshellarg($work)));

    $say(sprintf('installed hls.js %s into %s', $version, $destDir));
}

clearstatcache();
if (!is_file($distFile)) {
    $fail(sprintf(
        '%s is still missing after the install. The E2E test looks for exactly this path and would '
        . 'markTestSkipped() — which reads as a pass.',
        $distFile,
    ));
}
$distBytes = (int) filesize($distFile);
if ($distBytes < 1024) {
    $fail(sprintf('%s is only %d bytes — that is not a browser build of hls.js.', $distFile, $distBytes));
}
$say(sprintf('hls.js browser build present: %s (%d bytes)', $distFile, $distBytes));

// ---------------------------------------------------------------------------
// 3. The binaries the probe drives. Absence must be LOUD, never a skip.
// ---------------------------------------------------------------------------

if ($skipBinaries) {
    $say('--skip-binaries: chrome/node/ffmpeg were NOT checked (unit-test mode).');
    exit(0);
}

// ⚠ ffmpeg spells it `-version`, with ONE dash; `--version` is unrecognised and exits
// non-zero. Chrome and node want two. Getting this wrong does not break the check (the
// executable test above is what decides) but it does print "(version query failed)" for
// a perfectly good binary, which is exactly the sort of noise that gets a step deleted.
$describe = static function (string $binary, string $flag = '--version'): string {
    /** @var list<string> $out */
    $out = [];
    $code = 0;
    exec(escapeshellarg($binary) . ' ' . $flag . ' 2>&1', $out, $code);

    return $code === 0 && $out !== [] ? trim($out[0]) : '(' . $flag . ' query failed)';
};

$chrome = BrowserProbeEnvironment::chrome();
if ($chrome === null) {
    $fail(sprintf(
        "no Chrome/Chromium binary at any of the paths the probe accepts:\n  %s\nThe hls.js check "
        . "needs a real browser. Install one in the workflow — do NOT let the test skip instead.",
        implode("\n  ", BrowserProbeEnvironment::CHROME_CANDIDATES),
    ));
}
$say(sprintf('chrome: %s — %s', $chrome, $describe($chrome)));

$node = BrowserProbeEnvironment::node();
if ($node === null) {
    $fail(sprintf(
        'no node >= %d was found. The probe drives Chrome over the DevTools protocol with node\'s '
        . 'built-in global WebSocket, which landed in node 22. Add or fix the setup-node step.',
        BrowserProbeEnvironment::MIN_NODE_MAJOR,
    ));
}
$say(sprintf('node: %s — %s', $node, $describe($node)));

foreach ([BrowserProbeEnvironment::FFMPEG, BrowserProbeEnvironment::FFPROBE] as $binary) {
    if (!is_executable($binary)) {
        $fail(sprintf('%s is missing; the E2E test encodes a real clip with it and would skip without it.', $binary));
    }
}
$say(sprintf('ffmpeg: %s — %s', BrowserProbeEnvironment::FFMPEG, $describe(BrowserProbeEnvironment::FFMPEG, '-version')));

$say(sprintf(
    'S305 browser-E2E prerequisites OK: hls.js %s, a browser, node >= %d and ffmpeg are all present, '
    . 'so none of the %d required cases across %s may skip.',
    $version,
    BrowserProbeEnvironment::MIN_NODE_MAJOR,
    BrowserProbeEnvironment::requiredCaseCount(),
    implode(' + ', array_keys(BrowserProbeEnvironment::REQUIRED_CASES_BY_CLASS)),
));

exit(0);
