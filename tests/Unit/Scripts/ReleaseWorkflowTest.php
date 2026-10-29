<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * Pins `.github/workflows/release.yml` to the version law the runbook
 * documents (RELEASE_PROCESS.md) — and to reality.
 *
 * ## Why this file exists (review server__devops M1 / campaign M-SERVER-09)
 *
 * The Release workflow asserted the OPPOSITE of the repo's own law on two
 * counts, and every tagged run since the trigger existed was red because of
 * it (v1.2.0–v1.2.3, seven consecutive failures; step: "Assert Chart.yaml
 * appVersion matches tag"):
 *
 * 1. It looped over BOTH Helm charts demanding `appVersion == tag`. But
 *    `scripts/release.sh` only ever bumps the server chart, and the runbook
 *    itself states server and hub "share no version space" — the hub chart
 *    sat at `appVersion: "1.0.0"` and could never catch up.
 * 2. It demanded `composer.json`'s `version` equal the tag, while the same
 *    runbook mandates composer.json carry NO version field at all
 *    (`composer validate --strict` fails on it, decision `f5375f7a`). The
 *    assert was only satisfiable by violating the law at tag time — which is
 *    exactly what the v1.2.3 tag tree did.
 *
 * The workflow now asserts the documented mirror trio (Version.php::STRING,
 * VERSION, the server Chart.yaml) and enforces the composer no-version law
 * instead of contradicting it. These tests are the tripwires against the
 * fiction creeping back into either file; each one is red on the pre-fix
 * shape of the files it reads.
 *
 * @package Phlix\Tests\Unit\Scripts
 */
final class ReleaseWorkflowTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/release.yml';
    private const RUNBOOK = 'RELEASE_PROCESS.md';

    private static function workflowSource(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/' . self::WORKFLOW);
    }

    private static function runbookSource(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/' . self::RUNBOOK);
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * The shell body of one `run:` step, found by its exact step name.
     * Fails loudly when the step is absent — a renamed or deleted assert is
     * exactly the drift this guard exists to catch.
     */
    private static function stepBody(string $name): string
    {
        $lines = explode("\n", self::workflowSource());
        $inStep = false;
        $body = [];
        foreach ($lines as $line) {
            if (!$inStep) {
                if (trim($line) === '- name: ' . $name) {
                    $inStep = true;
                }
                continue;
            }
            if (preg_match('/^\s*-\s+name:\s/', $line) || preg_match('/^\s{0,4}[a-z-]+:\s*$/', $line)) {
                break;
            }
            $body[] = $line;
        }
        self::assertNotEmpty($body, 'workflow step "' . $name . '" not found in ' . self::WORKFLOW);
        return implode("\n", $body);
    }

    /**
     * Executable (non-comment, non-echo) lines of a step body — so narrative
     * echo lines may MENTION a file the step refuses to read.
     */
    private static function executableLines(string $body): string
    {
        $kept = [];
        foreach (explode("\n", $body) as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '#') || str_starts_with($t, 'echo ')) {
                continue;
            }
            $kept[] = $t;
        }
        return implode("\n", $kept);
    }

    public function testTheAssertStepReadsTheAuthoritativeMirrorTrio(): void
    {
        $body = self::stepBody('Assert version sources match tag');
        $mirrors = ['src/Common/Version.php', 'public const STRING', 'VERSION', 'k8s/helm/phlix/Chart.yaml'];
        foreach ($mirrors as $needle) {
            self::assertStringContainsString(
                $needle,
                $body,
                self::WORKFLOW . ' must assert the ' . $needle . ' mirror (RELEASE_PROCESS.md "Update Version" trio).'
            );
        }
    }

    public function testTheHubChartIsPackagedButNeverAssertedAgainstTheServerTag(): void
    {
        $source = self::workflowSource();
        self::assertStringContainsString(
            'helm package k8s/helm/phlix-hub',
            $source,
            'the hub chart must still ship as a release asset'
        );
        $executable = self::executableLines(self::stepBody('Assert version sources match tag'));
        self::assertStringNotContainsString(
            'phlix-hub',
            $executable,
            'server release tags cannot and must not gate the hub chart (no shared version space) — '
                . 'this assert killed every Release run v1.2.0–v1.2.3.'
        );
    }

    public function testTheComposerNoVersionLawIsEnforcedNotContradicted(): void
    {
        $source = self::workflowSource();
        self::assertStringNotContainsString(
            'COMPOSER_VERSION',
            $source,
            'the equality assert (composer version == tag) contradicts the f5375f7a no-version-field '
                . 'law; it must not come back.'
        );
        $body = self::stepBody('Assert version sources match tag');
        self::assertStringContainsString(
            "'version' in json.load",
            $body,
            'the workflow must fail loud when composer.json declares a version field again.'
        );
    }

    public function testTheAssertComparesThePrereleaseBaseVersion(): void
    {
        $body = self::stepBody('Assert version sources match tag');
        self::assertStringContainsString(
            '${TAG_VERSION%%-*}',
            $body,
            'the runbook Beta channel tags v1.3.0-beta.1-style versions; mirrors carry only '
                . 'MAJOR.MINOR.PATCH, so the assert must strip the suffix.'
        );
        self::assertStringContainsString(
            '[0-9]+\\.[0-9]+\\.[0-9]+',
            $body,
            'the stripped base must be validated as SemVer.'
        );
    }

    public function testNoDeadCoverageArtifactIsAttachedToTheRelease(): void
    {
        $source = self::workflowSource();
        self::assertStringNotContainsString(
            'coverage-report.tar.gz',
            $source,
            'nothing in the repository or workflow produces this file; at softprops/action-gh-release@v3 '
                . 'it is a dead reference (pre-fix runs only tolerated it because they pinned @v1).'
        );
        self::assertStringContainsString('dist/charts/*.tgz', $source);
    }

    public function testTheRunbookDescribesWhatTheWorkflowActuallyDoes(): void
    {
        $runbook = self::runbookSource();
        $section = self::runbookReleaseSection();
        self::assertStringContainsString(
            'trigger `v*.*.*`',
            $runbook,
            'the documented trigger must match the workflow.'
        );
        self::assertStringContainsString('- \'v*.*.*\'', self::workflowSource());
        $mirrors = ['src/Common/Version.php::STRING', 'VERSION', 'k8s/helm/phlix/Chart.yaml'];
        foreach ($mirrors as $needle) {
            self::assertStringContainsString(
                $needle,
                $section,
                'RELEASE_PROCESS.md must name every mirror the fixed workflow asserts (' . $needle . ').'
            );
        }
        self::assertStringNotContainsString(
            "assert both Helm charts'",
            $section,
            'the runbook must not claim the hub chart is tag-asserted again — '
                . 'that claim was falsified by seven red runs.'
        );
        self::assertStringNotContainsString('composer.json`\'s `version` match the tag', $section);
    }

    /** The "GitHub Actions will:" release-workflow item of the runbook. */
    private static function runbookReleaseSection(): string
    {
        $runbook = self::runbookSource();
        $start = strpos($runbook, 'GitHub Actions will:');
        self::assertNotFalse($start, 'RELEASE_PROCESS.md lost its "GitHub Actions will:" section.');
        $end = strpos($runbook, "\n2. `Docker", $start);
        self::assertNotFalse($end, 'RELEASE_PROCESS.md release-workflow item no longer ends at the Docker item.');
        return substr($runbook, $start, $end - $start);
    }
}
