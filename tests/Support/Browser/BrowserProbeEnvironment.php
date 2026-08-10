<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Browser;

/**
 * S305 — the ONE place that knows what the headless-browser gate needs, and what
 * that gate is called.
 *
 * ## Why this exists rather than living in the test that uses it
 *
 * S57 shipped `Fmp4HlsPlaybackE2ETest` with its prerequisite discovery inline —
 * ffmpeg, node >= 22, a Chrome binary, hls.js — each one a `markTestSkipped()`.
 * Those guards are CORRECT for a developer box that has no browser. What they are
 * not is safe: **a skipped test reads as a pass**. PHPUnit exits 0 with "OK, but
 * some tests were skipped!", and with no branch protection in this estate a
 * `skipped` conclusion scores identically to a real one. Measured on PR #664
 * (run `31263130044`): CI reported `Skipped: 6` against master's 3 — exactly these
 * three cases, on every run, invisibly.
 *
 * The fix is NOT to delete the skip guards (a browser-less box must still be able
 * to run the suite) and NOT to loosen them. It is to make CI **provide** the
 * prerequisites and then **assert the cases executed**, by name. Two consumers
 * therefore need the same facts:
 *
 *   * {@see \Phlix\Tests\E2E\Media\Transcoding\Fmp4HlsPlaybackE2ETest} — decides
 *     whether it can run at all;
 *   * `scripts/ci-browser-e2e-prereqs.php` — installs hls.js and REFUSES to
 *     continue when a prerequisite is missing, so CI fails at the cheap step with
 *     a named reason instead of silently skipping three tests twenty minutes later.
 *
 * If those two disagreed about, say, which Chrome paths count, CI could satisfy
 * the script and still skip the test. They share this class so they cannot.
 *
 * ⚠ This class is not the gate. The gate is `scripts/assert-browser-e2e-ran.php`,
 * which reads the JUnit report and fails when any of {@see REQUIRED_CASES} is
 * absent or skipped — that check does not go through this discovery code at all,
 * so breaking the discovery still reds CI rather than quietly disarming it.
 */
final class BrowserProbeEnvironment
{
    /**
     * The test class whose cases the CI gate requires to have EXECUTED.
     *
     * A string rather than `::class` on purpose: `scripts/assert-browser-e2e-ran.php`
     * compares it against the `class` attribute in a JUnit report, which is text.
     * `tests/Unit/Support/BrowserE2EGateTest.php` asserts the class and every method
     * below really exist, so a rename cannot leave the gate pointing at nothing.
     */
    public const TEST_CLASS = 'Phlix\\Tests\\E2E\\Media\\Transcoding\\Fmp4HlsPlaybackE2ETest';

    /**
     * The three cases that must run where merges are decided.
     *
     * All three, not just the positive one: the EXT-X-MAP removal control and the
     * MPEG-TS control are what make the positive result mean anything, so a run that
     * executed only the happy path proves materially less.
     *
     * @var list<string>
     */
    public const REQUIRED_CASES = [
        'testHlsJsPlaysTheFmp4VariantPastASegmentBoundary',
        'testRemovingTheExtXMapBreaksPlayback',
        'testHlsJsStillPlaysTheMpegTsVariant',
    ];

    /**
     * Node's built-in global `WebSocket` (used to drive Chrome over the DevTools
     * protocol without an npm client) landed in Node 22.
     */
    public const MIN_NODE_MAJOR = 22;

    /** @var list<string> */
    public const CHROME_CANDIDATES = [
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
    ];

    public const FFMPEG = '/usr/bin/ffmpeg';

    public const FFPROBE = '/usr/bin/ffprobe';

    /** Where the probe expects hls.js, relative to the repository root. */
    public const HLSJS_RELATIVE_PATH = 'web-ui/node_modules/hls.js/dist/hls.min.js';

    /** The lockfile that PINS the hls.js version and its integrity hash. */
    public const WEB_UI_LOCKFILE = 'web-ui/package-lock.json';

    /** The lockfile key for hls.js. It is a transitive dependency of `@phlix/ui`. */
    public const HLSJS_LOCK_KEY = 'node_modules/hls.js';

    public static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** Absolute path to hls.js if it is installed, else null. */
    public static function hlsJs(): ?string
    {
        $path = self::repoRoot() . '/' . self::HLSJS_RELATIVE_PATH;

        return is_file($path) ? $path : null;
    }

    /** The first Chrome/Chromium binary the probe can drive, else null. */
    public static function chrome(): ?string
    {
        foreach (self::CHROME_CANDIDATES as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * A `node` >= {@see MIN_NODE_MAJOR}, else null.
     *
     * `node` is frequently not on a non-login shell's PATH (nvm), so a couple of
     * conventional locations are tried before giving up. The version is READ from the
     * binary rather than assumed from the path.
     */
    public static function node(): ?string
    {
        $candidates = [];
        $which = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($which !== '') {
            $candidates[] = $which;
        }
        $candidates[] = '/usr/bin/node';
        $candidates[] = '/usr/local/bin/node';
        foreach (glob((string) getenv('HOME') . '/.nvm/versions/node/*/bin/node') ?: [] as $path) {
            $candidates[] = $path;
        }

        foreach ($candidates as $candidate) {
            if (!is_executable($candidate)) {
                continue;
            }
            $version = trim((string) shell_exec(escapeshellarg($candidate) . ' --version 2>/dev/null'));
            if (preg_match('/^v(\d+)\./', $version, $m) === 1 && (int) $m[1] >= self::MIN_NODE_MAJOR) {
                return $candidate;
            }
        }

        return null;
    }
}
