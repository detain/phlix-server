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
 * ## S482 — the same sweep extended to `scripts/install.sh`
 *
 * The bare-metal installer carried S309's exact defect on the USER-facing path:
 * it cloned swoole-src and php-uv `--depth=1` with no ref at all while its own
 * comment claimed "verbatim parity" with the pinned `docker/Dockerfile.base`.
 * The parity claim is now true — swoole tracks `ARG SWOOLE_REF` (a release tag;
 * `--depth=1 --branch <tag>` is safe because a tag resolves to its own commit
 * even shallow, unlike S309's rejected shallow+bare-SHA), php-uv tracks
 * `ARG PHP_UV_REF` (full clone + detached SHA + rev-parse self-verify, exactly
 * the S309 durable form) — and this file holds BOTH pins equal to the base
 * image's, per clone-site region, never whole-file: install.sh carries two
 * different 40-hex pins in one file, so a file-wide "no other 40-hex" sweep
 * would make the two sites fail each other. The install.sh corpus is a
 * line-based text walk with the same comment-strip precedent as {@see
 * shellDirectives()}, so a docblock quoting the old floating shape cannot
 * self-flag.
 *
 * The step token {@see self::STEP_MARK} is the S309 survival marker; it lives in code (a
 * string literal), not a comment, so `php_strip_whitespace()` keeps it for the merge ritual.
 * The S482 extension carries its own marker {@see self::INSTALL_SH_MARK}, same rule.
 *
 * @see WorkflowToolGateTest the S183 sibling that also sweeps every workflow step
 */
final class ThirdPartyClonePinGuardTest extends TestCase
{
    private const WORKFLOWS = __DIR__ . '/../../../.github/workflows';

    /** S309 survival marker — a code string, deliberately not a comment. */
    private const STEP_MARK = 'S309UVPINX9K9';

    /** S482 survival marker for the install.sh sweep — code string, not comment. */
    private const INSTALL_SH_MARK = 'S482INSTALLX9P1';

    /** The one php-uv commit the base image, CI and install.sh must agree on (full 40-hex SHA). */
    private const PHP_UV_PIN = '670a609efc36c9043be37bae4126f06ed30fde21';

    private const PHP_UV_URL = 'https://github.com/bwoebi/php-uv.git';

    private const SWOOLE_URL = 'https://github.com/swoole/swoole-src.git';

    /** The swoole release tag docker/Dockerfile.base ARG SWOOLE_REF carries (S482). */
    private const SWOOLE_TAG = 'v6.2.1';

    /**
     * The commit SWOOLE_TAG resolved to when S482 pinned it (re-verified via
     * `git ls-remote` at filing). install.sh self-verifies against it so a
     * force-moved tag fails the bare-metal install loudly; move it here, in
     * install.sh, and in Dockerfile.base's ARG in ONE commit when re-pinning.
     */
    private const SWOOLE_SHA = '163f173caa7b1e2391d5dfec5969a12d8c76cc02';

    /** install.sh: repo-root-relative path swept by the S482 legs. */
    private const INSTALL_SH = __DIR__ . '/../../../scripts/install.sh';

    /** Third-party clone sources install.sh is allowed to carry (one site each). */
    private const EXPECTED_INSTALL_SITES = [
        'https://github.com/swoole/swoole-src.git' => 1,
        'https://github.com/bwoebi/php-uv.git' => 1,
    ];

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
    // S482 — the same sweep over scripts/install.sh (the user-facing path).
    // Line-based text walk, comment-stripped, applied per clone-site region:
    // one file carries TWO different 40-hex pins, so whole-file predicates
    // would make the sites fail each other.
    // -----------------------------------------------------------------------

    public function testInstallShCarriesExactlyTheEnumeratedCloneSites(): void
    {
        $counts = [];
        foreach ($this->installShCloneSites() as [$url, $where]) {
            $counts[$url] = ($counts[$url] ?? 0) + 1;
        }
        $expected = self::EXPECTED_INSTALL_SITES;
        ksort($counts);
        ksort($expected);

        self::assertSame(
            $expected,
            $counts,
            'S482: scripts/install.sh must carry exactly the enumerated third-party clone sites. '
            . 'A different set means the installer gained or lost a from-source build — register '
            . 'it in EXPECTED_INSTALL_SITES and pin it (immutable SHA, or a tag whose recorded '
            . 'SHA is self-verified). ' . self::INSTALL_SH_MARK . "\nFound:\n  "
            . implode("\n  ", array_map(
                static fn (string $u, int $c): string => $u . ' x' . $c,
                array_keys($counts),
                $counts
            )),
        );
    }

    public function testInstallShClonesNoUnregisteredThirdPartyRepository(): void
    {
        $offenders = [];
        foreach ($this->installShCloneSites() as [$url, $where]) {
            if (!array_key_exists($url, self::EXPECTED_INSTALL_SITES)) {
                $offenders[] = $where . ' -> ' . $url;
            }
        }

        // Anti-vacuity: the walk must have actually seen the two registered sites,
        // else "no offenders" proves nothing (S345 law 3).
        self::assertCount(
            2,
            $this->installShCloneSites(),
            'S482: the install.sh walk found no clone sites — the corpus parse is broken, '
            . 'not the installer clean. ' . self::INSTALL_SH_MARK,
        );

        self::assertSame(
            [],
            $offenders,
            'S482: scripts/install.sh clones a repository no one registered and pinned. Every '
            . 'third-party clone is a supply-chain surface compiled into the operator\'s PHP. '
            . self::INSTALL_SH_MARK . "\nOffenders:\n  " . implode("\n  ", $offenders),
        );
    }

    public function testInstallShPhpUvCloneIsPinnedToTheBaseImageCommit(): void
    {
        $sites = $this->installShCloneRegions();

        self::assertTrue(
            $this->isPinnedToImmutableSha($sites[self::PHP_UV_URL], self::PHP_UV_PIN, self::PHP_UV_URL),
            'S482: the php-uv build function in scripts/install.sh must durably pin to '
            . self::PHP_UV_PIN . ' — full clone (no `--depth`), `git checkout --quiet --detach <sha>`, '
            . 'and a `git rev-parse HEAD` self-verify, exactly the S309 form — and reference no '
            . 'other 40-hex commit in its region. ' . self::INSTALL_SH_MARK,
        );
    }

    public function testInstallShSwooleCloneIsPinnedToTheBaseImageTag(): void
    {
        $sites = $this->installShCloneRegions();

        self::assertTrue(
            $this->isPinnedToTagWithRecordedSha(
                $sites[self::SWOOLE_URL],
                self::SWOOLE_URL,
                self::SWOOLE_TAG,
                self::SWOOLE_SHA
            ),
            'S482: the swoole build function in scripts/install.sh must clone `--branch '
            . self::SWOOLE_TAG . '` and self-verify `rev-parse HEAD` against the recorded '
            . self::SWOOLE_SHA . ' (stated in both the check and its failure message), so a '
            . 'force-moved tag fails the install loud. ' . self::INSTALL_SH_MARK,
        );
    }

    public function testInstallShPinsAreTheSameRefsTheBaseImageDeclares(): void
    {
        // "We mirror that exact build" is only true if the two files name the
        // SAME refs — the S309 base-image-equality philosophy (test above for
        // the CI legs), now also holding install.sh ↔ docker/Dockerfile.base.
        [$swooleArg, $phpUvArg] = $this->dockerfileBasePins();

        $swooleLine = null;
        foreach ($this->installShCloneRegions() as $region) {
            foreach (explode("\n", $region) as $line) {
                if (preg_match('/git\s+clone\b.*--branch\s+(\S+).*swoole\/swoole-src\.git/', $line, $m) === 1) {
                    $swooleLine = $m[1];
                }
            }
        }

        self::assertNotNull(
            $swooleLine,
            'S482: no `git clone --branch <ref>` line for swoole-src found in install.sh — the '
            . 'pin was deleted or restructured out of recognition. ' . self::INSTALL_SH_MARK,
        );
        self::assertSame(
            $swooleArg,
            $swooleLine,
            'S482: install.sh clones swoole at ' . $swooleLine . ' but docker/Dockerfile.base '
            . 'declares ARG SWOOLE_REF=' . $swooleArg . '. The "exact build" parity claim is a '
            . 'lie until these agree; bump them together. ' . self::INSTALL_SH_MARK,
        );
        self::assertSame(
            $phpUvArg,
            $this->installShPhpUvRegionSha(),
            'S482: install.sh pins php-uv to a commit other than docker/Dockerfile.base '
            . 'ARG PHP_UV_REF=' . $phpUvArg . '. Same commit or the parity claim is false. '
            . self::INSTALL_SH_MARK,
        );
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

    /**
     * S482 anti-vacuity: the exact floating bodies scripts/install.sh shipped
     * with (tip of each default branch, no ref stated) must be judged UNPINNED
     * by the predicates now sweeping that file — else the install.sh tests
     * above are vacuous, the same defence S345 law 3 demands for every
     * "nothing matched" result. Paired with positive controls over the REAL
     * shipped regions so the predicates and the corpus are both proven live.
     */
    public function testUnpinnedInstallShHistoricalShapesAreDetected(): void
    {
        $swooleHistorical = <<<'SH'
            log "Building Swoole from source (this can take several minutes)"
            git clone --depth=1 https://github.com/swoole/swoole-src.git "$tmp/swoole"
            (
              cd "$tmp/swoole"
              phpize
            SH;

        self::assertFalse(
            $this->isPinnedToTagWithRecordedSha(
                $swooleHistorical,
                self::SWOOLE_URL,
                self::SWOOLE_TAG,
                self::SWOOLE_SHA
            ),
            'the pre-S482 floating swoole clone must be flagged UNPINNED, or the install.sh swoole sweep is vacuous',
        );

        $uvHistorical = <<<'SH'
            log "Building php-uv from source"
            git clone --depth=1 https://github.com/bwoebi/php-uv.git "$tmp/php-uv"
            (
              cd "$tmp/php-uv"
              phpize
            SH;

        self::assertFalse(
            $this->isPinnedToImmutableSha($uvHistorical, self::PHP_UV_PIN, self::PHP_UV_URL),
            'the pre-S482 floating php-uv clone must be flagged UNPINNED, or the install.sh php-uv sweep is vacuous',
        );

        // Positive controls against the real file (S345 law 2: judge the real artefact).
        $regions = $this->installShCloneRegions();
        self::assertTrue(
            $this->isPinnedToTagWithRecordedSha(
                $regions[self::SWOOLE_URL],
                self::SWOOLE_URL,
                self::SWOOLE_TAG,
                self::SWOOLE_SHA
            ),
            'the shipped swoole region must satisfy its predicate',
        );
        self::assertTrue(
            $this->isPinnedToImmutableSha($regions[self::PHP_UV_URL], self::PHP_UV_PIN, self::PHP_UV_URL),
            'the shipped php-uv region must satisfy its predicate',
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
     *   - its clone of $cloneUrl is NOT `--depth` shallow (shallow can only reach the tip);
     *   - it references no OTHER 40-hex commit (a silent re-pin to a different SHA is drift).
     */
    private function isPinnedToImmutableSha(string $body, string $pin, string $cloneUrl = self::PHP_UV_URL): bool
    {
        $detachPattern = '/git\s+checkout\b[^\n]*--detach\b[^\n]*' . preg_quote($pin, '/') . '\b/';
        $detachesToPin = preg_match($detachPattern, $body) === 1;
        $selfVerifies  = str_contains($body, 'git rev-parse HEAD') && substr_count($body, $pin) >= 2;
        $cloneIsShallow = preg_match(
            '/git\s+clone\b[^\n]*--depth\b[^\n]*' . preg_quote($cloneUrl, '/') . '/',
            $body
        ) === 1;
        $onlyPinRef = (bool) preg_match_all('/\b[0-9a-f]{40}\b/', $body, $shas)
            && array_unique($shas[0]) === [$pin];

        return $detachesToPin && $selfVerifies && !$cloneIsShallow && $onlyPinRef;
    }

    /**
     * S482 sibling predicate: is a body pinned to a mutable-but-tagged ref?
     * True only when all hold:
     *   - the clone of $url carries `--branch $tag` ON THE CLONE LINE (the ref is
     *     stated, not defaulted to the branch HEAD — the pre-S482 floating shape);
     *   - it self-verifies `rev-parse HEAD` against the recorded $sha AND states that
     *     $sha twice (check + failure message), so a force-moved tag cannot silently
     *     change what gets compiled and the operator sees the expected value;
     *   - it references no OTHER 40-hex commit in its region.
     * Unlike isPinnedToImmutableSha() this ALLOWS `--depth=1`: cloning a TAG
     * shallow is sound (the tag resolves to its own commit), which is why
     * docker/Dockerfile.base uses the same shape and why S482 mirrors it.
     */
    private function isPinnedToTagWithRecordedSha(
        string $body,
        string $url,
        string $tag,
        string $sha
    ): bool {
        $clonePattern = '/git\s+clone\b[^\n]*--branch\s+' . preg_quote($tag, '/')
            . '\b[^\n]*' . preg_quote($url, '/') . '/';
        $cloneToTag   = preg_match($clonePattern, $body) === 1;
        $selfVerifies = str_contains($body, 'rev-parse HEAD')
            && str_contains($body, $sha)
            && substr_count($body, $sha) >= 2;
        $noOtherSha = (bool) preg_match_all('/\b[0-9a-f]{40}\b/', $body, $shas)
            && array_unique($shas[0]) === [$sha];

        return $cloneToTag && $selfVerifies && $noOtherSha;
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

    // -----------------------------------------------------------------------
    // S482 helpers — the install.sh line walk.
    // -----------------------------------------------------------------------

    /**
     * install.sh split into shell functions: name => body with comment lines
     * stripped (same precedent as {@see shellDirectives()} — the file's own
     * S482 comments quote the old floating `--depth=1` shape and the pinned
     * SHAs' prose, and must not self-flag).
     *
     * Parsing contract this relies on (verified against the tip file by the
     * assertions below, not assumed): every function either fits on one line
     * (`name() { …; }`) or opens at `^name() {` and closes at the next column-0
     * `}`; no heredoc body in install.sh contains a column-0 `}` or a line
     * matching the opener shape. A violation lands on a loud assert, never on
     * a silently empty corpus.
     *
     * @return array<string, string>
     */
    private function installShFunctions(): array
    {
        $raw = file_get_contents(self::INSTALL_SH);
        self::assertIsString($raw, self::INSTALL_SH . ' must be readable');

        $functions = [];
        $current = null;
        $body = [];
        foreach (explode("\n", $raw) as $line) {
            if ($current === null && preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*\(\)\s*\{(.*)$/', $line, $m) === 1) {
                if (str_ends_with(rtrim($m[2]), '}')) {
                    // One-line function: opener and closer share the line.
                    $functions[$m[1]] = $this->shellDirectives($line);
                    continue;
                }

                $current = $m[1];
                $body = [];
                continue;
            }

            if ($current !== null && $line === '}') {
                $functions[$current] = $this->shellDirectives(implode("\n", $body));
                $current = null;
                continue;
            }

            if ($current !== null) {
                $body[] = $line;
            }
        }

        self::assertSame(
            null,
            $current,
            'S482: install.sh ended inside an unterminated function — the region parse is '
            . 'untrustworthy; fix the walk before trusting any corpus assertion. '
            . self::INSTALL_SH_MARK,
        );

        // The two swept sites must be found BY NAME: a rename that drops them
        // out of the corpus must be loud, not silently vacuous (S345 law 3).
        self::assertArrayHasKey(
            'phlix_build_swoole',
            $functions,
            'S482: swoole build function not found by name. ' . self::INSTALL_SH_MARK
        );
        self::assertArrayHasKey(
            'phlix_build_uv',
            $functions,
            'S482: php-uv build function not found by name. ' . self::INSTALL_SH_MARK
        );

        return $functions;
    }

    /**
     * Every third-party clone line in install.sh as [url, enclosing function].
     *
     * @return list<array{0: string, 1: string}>
     */
    private function installShCloneSites(): array
    {
        $sites = [];
        foreach ($this->installShFunctions() as $name => $body) {
            foreach ($this->cloneUrls($body) as $url) {
                $sites[] = [$url, $name];
            }
        }

        return $sites;
    }

    /**
     * Clone URL => the body of the function that clones it — the per-site
     * region the predicates run over. Two functions cloning the same URL
     * would make "the region" ambiguous against the one-site-per-URL
     * registration, so that is asserted, not averaged away.
     *
     * @return array<string, string>
     */
    private function installShCloneRegions(): array
    {
        $regions = [];
        foreach ($this->installShFunctions() as $name => $body) {
            foreach ($this->cloneUrls($body) as $url) {
                if (isset($regions[$url])) {
                    self::fail(
                        'S482: ' . $url . ' is cloned by both ' . $regions[$url] . ' and ' . $name
                        . ' — pin predicates need one region per URL; re-enumerate install.sh sites. '
                        . self::INSTALL_SH_MARK
                    );
                }

                $regions[$url] = $body;
            }
        }

        return $regions;
    }

    /**
     * The single 40-hex commit stated in install.sh's php-uv region — for the
     * install.sh ↔ ARG PHP_UV_REF equality (multiplicity is the pin predicate's job).
     */
    private function installShPhpUvRegionSha(): string
    {
        $region = $this->installShCloneRegions()[self::PHP_UV_URL] ?? '';
        self::assertSame(
            1,
            preg_match('/\b([0-9a-f]{40})\b/', $region, $m),
            'S482: the php-uv region of install.sh states no 40-hex commit to compare against '
            . 'docker/Dockerfile.base. ' . self::INSTALL_SH_MARK,
        );

        return $m[1];
    }

    /**
     * The refs docker/Dockerfile.base declares: [SWOOLE_REF, PHP_UV_REF].
     *
     * @return array{0: string, 1: string}
     */
    private function dockerfileBasePins(): array
    {
        $dockerfile = __DIR__ . '/../../../docker/Dockerfile.base';
        self::assertFileExists($dockerfile);

        $raw = file_get_contents($dockerfile);
        self::assertIsString($raw);

        self::assertSame(
            1,
            preg_match('/^ARG\s+SWOOLE_REF=(\S+)\s*$/m', $raw, $s),
            'docker/Dockerfile.base must declare `ARG SWOOLE_REF=<ref>` (S482).',
        );
        self::assertSame(
            1,
            preg_match('/^ARG\s+PHP_UV_REF=([0-9a-f]{40})\s*$/m', $raw, $u),
            'docker/Dockerfile.base must declare `ARG PHP_UV_REF=<40-hex>` (S309).',
        );

        return [$s[1], $u[1]];
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
