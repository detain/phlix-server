<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * S309 — a CI job must never clone a third-party repository at an unpinned HEAD.
 *
 * ## The defect
 *
 * `phpunit.yml` (x2) and `syncplay-e2e.yml` compiled the `uv` PHP extension from a
 * floating `git clone --depth=1 https://github.com/bwoebi/php-uv.git` — no tag, no
 * commit SHA. Two failures in one class:
 *
 *   1. Supply chain. CI fetches and `make install`s whatever `HEAD` happens to be that
 *      day, then `php -m` loads the resulting `uv.so` into the very process that runs
 *      the test suite. A hostile or merely broken upstream push is compiled into every
 *      build with no reviewable change in this repo.
 *   2. Flake. A TLS hiccup on the third party's CDN (`server certificate verification
 *      failed` on PR #666, 2026-08-08) aborts an unrelated contributor's build. That is
 *      the third network-fetch-on-a-critical-path incident in a day (S308 boot fetch, the
 *      W3C XSD 429) — the estate keeps putting an uncontrolled remote on a load-bearing step.
 *
 * ## The fix this file holds in place
 *
 * All three clone sites are pinned to the SAME immutable full SHA the production base
 * image compiles — `docker/Dockerfile.base`'s `ARG PHP_UV_REF` — so "reproducible from
 * the base image plus the lockfile" is a measured fact, not an aspiration. The clone is
 * full (not `--depth=1`) because the pinned commit must stay reachable even after the
 * `0.3.x` tip moves on: a shallow clone keeps exactly one commit, so `git checkout <fixed
 * sha>` against it only works by luck while the SHA still happens to be the tip.
 *
 * ## Why this is a workflow-parsing test (and not a text grep)
 *
 * The estate's CI-workflow-change detectors ({@see StaticAnalysisScopeTest}, {@see
 * WorkflowToolGateTest}) parse the YAML with Symfony and assert over the parsed steps, so a
 * reformat or a rename cannot hide a regression, and a NEW unpinned clone cannot slip in
 * unregistered. {@see testUnpinnedHistoricalShapeIsDetected()} is the anti-vacuity guard
 * (S345 law 3): it feeds the predicate the exact body this step shipped with and demands it
 * be judged UNPINNED — otherwise a predicate that matches everything would report a green
 * pin while detecting nothing.
 *
 * The step token {@see self::STEP_MARK} is the S309 survival marker; it lives in code (a
 * string literal), not a comment, so `php_strip_whitespace()` keeps it for the merge ritual.
 *
 * @see WorkflowToolGateTest the S183 sibling that also sweeps every workflow step
 */
final class ThirdPartyClonePinGuardTest extends TestCase
{
    private const WORKFLOWS = __DIR__ . '/../../../.github/workflows';

    /** S309 survival marker — a code string, deliberately not a comment. */
    private const STEP_MARK = 'S309UVPINX9K9';

    /** The one php-uv commit the base image and CI must agree on (full 40-hex SHA). */
    private const PHP_UV_PIN = '670a609efc36c9043be37bae4126f06ed30fde21';

    private const PHP_UV_URL = 'https://github.com/bwoebi/php-uv.git';

    /** Enumerated clone sites: phpunit.yml has two jobs, syncplay-e2e.yml one (S345 law 1). */
    private const EXPECTED_PHP_UV_SITES = 3;

    /** The closed set of third-party clone sources allowed in CI. Adding one needs a pin here. */
    private const ALLOWED_CLONE_SOURCES = [
        'https://github.com/bwoebi/php-uv.git',
    ];

    // -----------------------------------------------------------------------
    // The php-uv clone, at every site, pinned to the base-image SHA.
    // -----------------------------------------------------------------------

    public function testPhpUvIsClonedAtExactlyTheEnumeratedNumberOfSites(): void
    {
        $sites = $this->phpUvCloneSites();

        self::assertCount(
            self::EXPECTED_PHP_UV_SITES,
            $sites,
            'S309: expected exactly ' . self::EXPECTED_PHP_UV_SITES . ' php-uv clone sites across the '
            . 'workflows. A different count means a build job was added or removed WITHOUT updating '
            . 'this guard — re-enumerate them and pin every one (' . self::STEP_MARK . ').',
        );
    }

    public function testEveryPhpUvCloneIsDetachedToThePinnedSha(): void
    {
        foreach ($this->phpUvCloneSites() as $where => $body) {
            self::assertTrue(
                $this->isPinnedToImmutableSha($body, self::PHP_UV_PIN),
                "S309: $where does not durably pin the php-uv clone to " . self::PHP_UV_PIN . ". "
                . 'It must (a) `git checkout --detach <that full SHA>`, (b) self-verify with '
                . '`git rev-parse HEAD`, (c) NOT shallow-clone with `--depth` (a shallow clone '
                . 'reaches only the tip, so the fixed SHA becomes uncheckoutable the moment '
                . '`0.3.x` moves), and (d) reference no OTHER 40-hex commit. ' . self::STEP_MARK,
            );
        }
    }

    public function testTheCiPinAndTheBaseImagePinAreTheSameCommit(): void
    {
        // "Reproducible from the base image" is only true if CI and the image compile the
        // SAME php-uv. Read the base image's declared ref and hold it to the CI pin.
        $dockerfile = __DIR__ . '/../../../docker/Dockerfile.base';
        self::assertFileExists($dockerfile);

        $raw = file_get_contents($dockerfile);
        self::assertIsString($raw);

        self::assertSame(
            1,
            preg_match('/^ARG\s+PHP_UV_REF=([0-9a-f]{40})\s*$/m', $raw, $m),
            'docker/Dockerfile.base must declare `ARG PHP_UV_REF=<40-hex>` (S309).',
        );

        self::assertSame(
            self::PHP_UV_PIN,
            $m[1],
            'S309: docker/Dockerfile.base pins php-uv at ' . $m[1] . ' but CI pins it at '
            . self::PHP_UV_PIN . '. These must be the SAME commit or CI does not reproduce the '
            . 'base image; bump them together. ' . self::STEP_MARK,
        );
    }

    // -----------------------------------------------------------------------
    // The general AC: no CI job clones an unpinned third-party repository.
    // -----------------------------------------------------------------------

    public function testNoUnregisteredThirdPartyRepositoryIsCloned(): void
    {
        $offenders = [];
        foreach ($this->runSteps() as $step) {
            foreach ($this->cloneUrls($this->shellDirectives($step['run'])) as $url) {
                if (!in_array($url, self::ALLOWED_CLONE_SOURCES, true)) {
                    $offenders[] = $step['workflow'] . ' :: ' . $step['step'] . ' -> ' . $url;
                }
            }
        }

        sort($offenders);

        self::assertSame(
            [],
            $offenders,
            'A CI step clones a repository that is not in ALLOWED_CLONE_SOURCES. Every third-party '
            . 'clone is a supply-chain surface: register it there AND pin it to an immutable ref, '
            . 'or better, bake it into ghcr.io/detain/phlix-base so no job fetches it at all. '
            . self::STEP_MARK . "\nOffenders:\n  " . implode("\n  ", $offenders),
        );
    }

    public function testEveryClonedSourceIsPinnedToAnImmutableRef(): void
    {
        $offenders = [];
        foreach ($this->runSteps() as $step) {
            $body = $this->shellDirectives($step['run']);
            if ($this->cloneUrls($body) === []) {
                continue;
            }

            if (!$this->isPinnedToImmutableSha($body, self::PHP_UV_PIN)) {
                $offenders[] = $step['workflow'] . ' :: ' . $step['job'] . ' :: ' . $step['step'];
            }
        }

        // Anti-vacuity: this loop must actually have inspected the php-uv steps, else "no
        // offenders" is trivially true because nothing was checked.
        self::assertNotEmpty(
            $this->phpUvCloneSites(),
            'the corpus contains no php-uv clone step, so the pin sweep above proves nothing',
        );

        self::assertSame(
            [],
            $offenders,
            'A CI step clones a third-party repo without a detached full-SHA pin — the S309 defect. '
            . 'Pin it (clone full, `git checkout --detach <sha>`, self-verify). ' . self::STEP_MARK
            . "\nOffenders:\n  " . implode("\n  ", $offenders),
        );
    }

    // -----------------------------------------------------------------------
    // Sibling sweep — what the AC asks us to confirm stays unfetched.
    // -----------------------------------------------------------------------

    /**
     * Swoole is installed by `shivammathur/setup-php`'s `extensions:` line (apt/pecl), not
     * cloned at job time; libuv arrives as the `libuv1-dev` apt package. Neither is a network
     * clone, so both are outside S309's defect class — but a future "just clone swoole-src"
     * edit would silently reintroduce it. This pins the sibling sweep as a standing invariant.
     */
    public function testNoWorkflowClonesSwooleOrLibuvFromSource(): void
    {
        $offenders = [];
        foreach ($this->runSteps() as $step) {
            $body = strtolower($this->shellDirectives($step['run']));
            foreach (['swoole-src', '/swoole/swoole', 'libuv.git', 'clone libuv'] as $needle) {
                if (str_contains($body, 'git clone') && str_contains($body, $needle)) {
                    $offenders[] = $step['workflow'] . ' :: ' . $step['step'] . ' (' . $needle . ')';
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Swoole must come from setup-php (apt/pecl) and libuv from the libuv1-dev package — '
            . 'neither from a job-time source clone. S309 applies to any unpinned fetch, not just '
            . 'php-uv. ' . self::STEP_MARK . "\nOffenders:\n  " . implode("\n  ", $offenders),
        );
    }

    /**
     * Estate law: a network-fetch step must never be papered with `continue-on-error`, which
     * turns a real missing-extension failure into a silent pass (this repo has no branch
     * protection, so a soft-failed step reads as green).
     */
    public function testThePhpUvBuildStepIsNeverSilenced(): void
    {
        foreach ($this->allSteps() as $step) {
            $run = $step['raw']['run'] ?? null;
            if (!is_string($run) || !str_contains($run, 'bwoebi/php-uv')) {
                continue;
            }

            self::assertArrayNotHasKey(
                'continue-on-error',
                $step['raw'],
                'the php-uv build step in ' . $step['workflow'] . ' must not carry '
                . 'continue-on-error: a failed uv build must redden the job, not hide. ' . self::STEP_MARK,
            );
            self::assertArrayNotHasKey(
                'if',
                $step['raw'],
                'the php-uv build step in ' . $step['workflow'] . ' must run unconditionally; an '
                . '`if:` that is false reports success having built nothing.',
            );
        }
    }

    // -----------------------------------------------------------------------
    // Anti-vacuity for the predicate itself (S345 law 3).
    // -----------------------------------------------------------------------

    /**
     * The exact floating body this step shipped with must be judged UNPINNED. Without this,
     * a predicate that returns true for everything would keep every test above green while
     * detecting nothing — the "nothing matched" defence needs its own guard.
     */
    public function testUnpinnedHistoricalShapeIsDetected(): void
    {
        $historical = <<<'SH'
            sudo apt-get update && sudo apt-get install -y --no-install-recommends libuv1-dev
            git clone --depth=1 https://github.com/bwoebi/php-uv.git /tmp/php-uv
            cd /tmp/php-uv && phpize && ./configure --with-uv && make
            SH;

        self::assertFalse(
            $this->isPinnedToImmutableSha($historical, self::PHP_UV_PIN),
            'the pre-S309 floating clone must be flagged UNPINNED, or the pin sweep above is vacuous',
        );

        // And the current body must be the opposite — a positive control proving the two
        // assertions move together rather than one matching by accident.
        $first = $this->phpUvCloneSites();
        self::assertTrue(
            $this->isPinnedToImmutableSha((string) reset($first), self::PHP_UV_PIN),
            'the shipped pinned body must satisfy the predicate it is tested against',
        );
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /**
     * Every step body that clones php-uv, keyed by "workflow :: job :: step".
     *
     * @return array<string, string>
     */
    private function phpUvCloneSites(): array
    {
        $sites = [];
        foreach ($this->runSteps() as $step) {
            $body = $this->shellDirectives($step['run']);
            if (in_array(self::PHP_UV_URL, $this->cloneUrls($body), true)) {
                $sites[$step['workflow'] . ' :: ' . $step['job'] . ' :: ' . $step['step']] = $body;
            }
        }

        return $sites;
    }

    /**
     * The full clone URLs (before the trailing dest path) on every `git clone` line of a body.
     *
     * @return list<string>
     */
    private function cloneUrls(string $body): array
    {
        $urls = [];
        foreach (explode("\n", $body) as $line) {
            if (preg_match('/\bgit\s+clone\b.*?(https?:\/\/\S+?\.git)\b/', $line, $m) === 1) {
                $urls[] = $m[1];
            }
        }

        return $urls;
    }

    /**
     * Is a step body a DURABLE pin to $pin? True only when all four hold:
     *   - it detaches onto the exact full SHA;
     *   - it self-verifies `git rev-parse HEAD` against the same SHA (fails loud, never silent);
     *   - its php-uv clone is NOT `--depth` shallow (shallow can only reach the tip);
     *   - it references no OTHER 40-hex commit (a silent re-pin to a different SHA is drift).
     */
    private function isPinnedToImmutableSha(string $body, string $pin): bool
    {
        $detachPattern = '/git\s+checkout\b[^\n]*--detach\b[^\n]*' . preg_quote($pin, '/') . '\b/';
        $detachesToPin = preg_match($detachPattern, $body) === 1;
        $selfVerifies  = str_contains($body, 'git rev-parse HEAD') && substr_count($body, $pin) >= 2;
        $cloneIsShallow = preg_match('/git\s+clone\b[^\n]*--depth\b[^\n]*bwoebi\/php-uv/', $body) === 1;
        $onlyPinRef = (bool) preg_match_all('/\b[0-9a-f]{40}\b/', $body, $shas)
            && array_unique($shas[0]) === [$pin];

        return $detachesToPin && $selfVerifies && !$cloneIsShallow && $onlyPinRef;
    }

    /**
     * Every step of every workflow, with its raw mapping. Mirrors WorkflowToolGateTest so a
     * new workflow file is swept automatically.
     *
     * @return list<array{workflow: string, job: string, step: string, raw: array<string, mixed>}>
     */
    private function allSteps(): array
    {
        $files = glob(self::WORKFLOWS . '/*.yml') ?: [];
        sort($files);

        self::assertGreaterThanOrEqual(6, count($files), 'the workflow directory looks empty');

        $out = [];
        foreach ($files as $file) {
            $raw = file_get_contents($file);
            self::assertIsString($raw, $file . ' must be readable');

            $parsed = Yaml::parse($raw);
            self::assertIsArray($parsed, $file . ' must parse as YAML');
            self::assertIsArray($parsed['jobs'] ?? null, $file . ' must declare jobs');

            foreach ($parsed['jobs'] as $jobName => $job) {
                if (!is_array($job) || !is_array($job['steps'] ?? null)) {
                    continue;
                }

                foreach ($job['steps'] as $step) {
                    if (!is_array($step)) {
                        continue;
                    }

                    $out[] = [
                        'workflow' => basename($file),
                        'job' => (string) $jobName,
                        'step' => is_string($step['name'] ?? null) ? $step['name'] : '(unnamed)',
                        'raw' => $step,
                    ];
                }
            }
        }

        self::assertGreaterThan(30, count($out), 'the workflow parse produced almost no steps');

        return $out;
    }

    /**
     * The subset of {@see allSteps()} that runs shell, with the body flattened out.
     *
     * @return list<array{workflow: string, job: string, step: string, run: string, raw: array<string, mixed>}>
     */
    private function runSteps(): array
    {
        $out = [];
        foreach ($this->allSteps() as $step) {
            if (!is_string($step['raw']['run'] ?? null)) {
                continue;
            }

            $out[] = $step + ['run' => $step['raw']['run']];
        }

        self::assertGreaterThan(20, count($out), 'the workflow parse produced almost no run steps');

        return $out;
    }

    /**
     * A `run:` body with its SHELL comment lines removed — the step's own S309 docblock quotes
     * the forbidden `--depth=1` shape, so asserting over the raw body would flag documentation
     * as a defect (the fifth time a workflow detector in this repo fires on its own text).
     */
    private function shellDirectives(string $run): string
    {
        $kept = [];
        foreach (explode("\n", $run) as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept) . "\n";
    }
}
