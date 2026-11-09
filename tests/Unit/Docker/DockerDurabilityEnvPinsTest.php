<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Docker;

use PHPUnit\Framework\TestCase;

/**
 * SH-024 enforcement pins — the durability posture must be asserted IN THE
 * SHIPPED DEPLOYMENT ARTIFACTS, not only in the config-level readers.
 *
 * Provenance (w17L3 DD adversarial review 2026-11-09, PATCH-FIRST Major):
 * the cure itself (docker ENV `PHLIX_LOG_STDOUT=1` + `PHLIX_ENV=production`
 * in all three runtime Dockerfiles, the durable `PHLIX_JOB_QUEUE_ROOT=`
 * `/var/phlix/data/jobs` in all three compose examples, the helm ConfigMap
 * `PHLIX_LOG_STDOUT: "1"` default, and the server-only compose healthcheck
 * re-stating `start_period: 90s`) shipped with ZERO enforcement: a revert-all
 * of every leg in a clean clone measured exactly ONE red, and that red was a
 * venue artifact of the clone living under /tmp (JobQueuePathsTest's
 * never-/tmp premise). The config-level tests (LogEnvironmentKnobsTest,
 * JobQueuePathsTest, DatabaseConfigCredentialGuardTest) all assert behavior
 * WHEN the var is set — none asserts that the artifacts SET it — and
 * scripts/docker-boot-smoke.sh's EXPECTED_CHECKS never greps any of these
 * names. A future commit deleting every SH-024 line therefore passed all
 * unit tests and all CI boot gates with zero signal: the estate's named
 * vacuous-leg class, enforcement-less fix on a MAJOR card.
 *
 * These pins close that hole in the repo's established file-content idiom
 * (sibling: PublicFrontControllerRemovalGuardTest). Each leg is scanned on
 * DIRECTIVE lines only — comment prose quoting the same literal must never
 * satisfy a pin, so every read carries the positive-control non-empty check
 * and the whole suite carries the synthetic comment-only control below.
 *
 * Pure unit test: reads repository files, touches no DB, no network, no
 * process. Deleting (or commenting out) any pinned leg reddens exactly its
 * own test method; the 14 legs were each reverted individually in a /tmp
 * clone and convicted exactly one failure apiece (register
 * FIX-server-w17L3-round2.md pin matrix).
 *
 * @since 1.4.0
 */
final class DockerDurabilityEnvPinsTest extends TestCase
{
    /** Merge-ritual survival token for SH-024 enforcement; a structural pin that this guard exists. */
    public const string SURVIVAL_TOKEN = 'SH024DURENVPIPINSQ8V4';

    /** @return array<string, array{0: string}> */
    public static function runtimeDockerfiles(): array
    {
        return [
            'Dockerfile' => ['docker/Dockerfile'],
            'Dockerfile.intel' => ['docker/Dockerfile.intel'],
            'Dockerfile.nvidia' => ['docker/Dockerfile.nvidia'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function composeExamples(): array
    {
        return [
            'server-only' => ['docker/examples/server-only/docker-compose.yml'],
            'server-hub' => ['docker/examples/server-hub/docker-compose.yml'],
            'full-stack' => ['docker/examples/full-stack/docker-compose.yml'],
        ];
    }

    public function testTheSurvivalTokenIsCarriedByTheSuite(): void
    {
        self::assertStringContainsString(
            self::SURVIVAL_TOKEN,
            (string) file_get_contents(__FILE__),
            'the SH-024 enforcement guard must survive merges with its token intact'
        );
    }

    /**
     * @param string $file repo-relative artifact path
     *
     * @dataProvider runtimeDockerfiles
     */
    public function testEveryRuntimeDockerfileArmsTheStdoutLogSink(string $file): void
    {
        $lines = self::directiveLines($file);

        self::assertTrue(
            self::anyMatches($lines, '/^ENV\s+PHLIX_LOG_STDOUT=1(\s|$)/'),
            "$file must set ENV PHLIX_LOG_STDOUT=1 on an active ENV line: without it the "
            . "container's Monolog stdout sink (config/logger.php, LogChannels::LOG_STDOUT_ENV) "
            . "stays off and application records never reach `docker logs` — the exact SH-024 "
            . "defect (supervisord-captured stdout only; .logs/ file handlers die with the image layer). "
            . 'Comments quoting the literal do not count; revert-proof in the w17L3-round2 register.'
        );
    }

    /**
     * @param string $file repo-relative artifact path
     *
     * @dataProvider runtimeDockerfiles
     */
    public function testEveryRuntimeDockerfileArmsTheProductionCredentialGuard(string $file): void
    {
        $lines = self::directiveLines($file);

        self::assertTrue(
            self::anyMatches($lines, '/^ENV\s+PHLIX_ENV=production(\s|$)/'),
            "$file must set ENV PHLIX_ENV=production on an active ENV line: without it the "
            . 'config/database.php DB-credential guard is inert in every container while the '
            . 'shipped systemd unit arms it — the warn-only suppression posture this lane '
            . 'disclosed, closed deliberately, and now pinned.'
        );
    }

    public function testTheHelmConfigMapCarriesTheStdoutSinkDefault(): void
    {
        $file = 'k8s/helm/phlix/templates/configmap.yaml';
        $lines = self::directiveLines($file);

        self::assertTrue(
            self::anyMatches($lines, '/^PHLIX_LOG_STDOUT:\s*"1"(\s|$)/'),
            "$file must carry the PHLIX_LOG_STDOUT: \"1\" key: pod logs are equally ephemeral, "
            . 'and the chart mirrors the runtime Dockerfiles\' default since SH-024. A dropped key '
            . 'silently returns kubectl logs to supervisord-only.'
        );
    }

    /**
     * @param string $file repo-relative artifact path
     *
     * @dataProvider composeExamples
     */
    public function testEveryComposeExampleCarriesTheStdoutSinkEntry(string $file): void
    {
        $lines = self::directiveLines($file);

        self::assertTrue(
            self::anyMatches($lines, '/^- PHLIX_LOG_STDOUT=1(\s|$)/'),
            "$file must set PHLIX_LOG_STDOUT=1 explicitly in the server service's env list: the "
            . 'image default alone is not enough for an example that documents operator posture, '
            . 'and an operator -e PHLIX_LOG_STDOUT=0 escape hatch stays possible either way. '
            . 'SH-024 leg pinned per DD review.'
        );
    }

    /**
     * @param string $file repo-relative artifact path
     *
     * @dataProvider composeExamples
     */
    public function testEveryComposeExampleCarriesTheDurableJobQueueRoot(string $file): void
    {
        $lines = self::directiveLines($file);

        self::assertTrue(
            self::anyMatches($lines, '/^- PHLIX_JOB_QUEUE_ROOT=\/var\/phlix\/data\/jobs(\s|$)/'),
            "$file must set PHLIX_JOB_QUEUE_ROOT=/var/phlix/data/jobs on the server service: "
            . 'unresolved, JobQueuePaths lands the five file-backed queues under the repo tree '
            . '(var/jobs), which the bind mounts do NOT carry — queues would then die with the '
            . 'container on the very examples that advertise durability.'
        );
    }

    public function testTheServerOnlyComposeRestatesTheHealthStartPeriod(): void
    {
        $file = 'docker/examples/server-only/docker-compose.yml';
        $lines = self::directiveLines($file);

        self::assertTrue(
            self::anyMatches($lines, '/^start_period:\s*90s(\s|$)/'),
            "$file's server healthcheck block must restate start_period: 90s: a compose "
            . '`healthcheck:` block REPLACES the image HEALTHCHECK wholesale, so without this '
            . 'line a first boot that migrates past the interval would be flapped to unhealthy '
            . '(the Dockerfile pins --start-period=90s; compose silently dropped it before SH-024).'
        );
    }

    /**
     * Positive control against the vacuity this whole file exists to kill:
     * comment prose quoting the literals must NOT satisfy the scanner.
     *
     * The shipped Dockerfile comments genuinely contain `PHLIX_LOG_STDOUT=1`
     * in rationale prose — a naive assertStringContainsString over raw bytes
     * would stay green with every ENV line deleted. This test drives the same
     * scan over a comment-only fixture and requires zero directive hits.
     */
    public function testTheDirectiveScanIgnoresCommentProse(): void
    {
        $content = implode("\n", [
            '# rationale prose quoting ENV PHLIX_LOG_STDOUT=1 and PHLIX_ENV=production',
            '  # - PHLIX_LOG_STDOUT=1 and - PHLIX_JOB_QUEUE_ROOT=/var/phlix/data/jobs',
            '# PHLIX_LOG_STDOUT: "1"',
            '# start_period: 90s',
        ]);

        foreach (self::allPinPatterns() as $name => $pattern) {
            self::assertFalse(
                self::anyMatches(self::scan($content), $pattern),
                "the '$name' pattern must not be satisfied by comment prose — that is the "
                . 'exact false-green the raw-bytes grep class shipped with; if this reddens, '
                . 'the scan no longer strips comments and every pin in this file is theater.'
            );
        }
    }

    /**
     * Every pattern this file enforces, keyed for the comment-prose control.
     *
     * @return array<string, string>
     */
    private static function allPinPatterns(): array
    {
        return [
            'dockerfile-stdout' => '/^ENV\s+PHLIX_LOG_STDOUT=1(\s|$)/',
            'dockerfile-prod-env' => '/^ENV\s+PHLIX_ENV=production(\s|$)/',
            'helm-stdout' => '/^PHLIX_LOG_STDOUT:\s*"1"(\s|$)/',
            'compose-stdout' => '/^- PHLIX_LOG_STDOUT=1(\s|$)/',
            'compose-queue-root' => '/^- PHLIX_JOB_QUEUE_ROOT=\/var\/phlix\/data\/jobs(\s|$)/',
            'compose-start-period' => '/^start_period:\s*90s(\s|$)/',
        ];
    }

    /**
     * Active (non-comment) lines of a repo artifact, with a fail-loud read
     * guard and the S171 positive control: a wholly-commented or empty file
     * must never pass a pin vacuously.
     *
     * @return list<string>
     */
    private static function directiveLines(string $relative): array
    {
        $raw = @file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
        self::assertNotFalse(
            $raw,
            "cannot read $relative — the SH-024 pins must not pass vacuously"
        );

        $lines = self::scan((string) $raw);
        self::assertNotSame([], $lines, "$relative holds no active lines at all");

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function scan(string $contents): array
    {
        $kept = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (!str_starts_with(ltrim($line), '#')) {
                $kept[] = rtrim(ltrim($line));
            }
        }

        return $kept;
    }

    /**
     * @param list<string> $lines
     */
    private static function anyMatches(array $lines, string $pattern): bool
    {
        foreach ($lines as $line) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
