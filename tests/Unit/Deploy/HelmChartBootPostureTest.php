<?php

/**
 * Phlix media server test: helm chart boot posture + startup-probe budget (SRV-S1).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * SRV-1: the image CMD is /docker-entrypoint.sh (root) -> supervisord
 * (user=root) -> daemon as `nobody`. A pod-level runAsNonRoot/runAsUser 1000
 * makes supervisord refuse to drop privileges, so the chart CrashLooped on
 * every install. SRV-29: the startupProbe budget (10 + 5*30 = 160s) was
 * shorter than a first boot running strict migrations. No helm binary exists
 * in CI, so both are pinned at source-text level and fail loud on drift.
 */
final class HelmChartBootPostureTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    private function read(string $rel): string
    {
        $s = file_get_contents($this->root . '/' . $rel);
        self::assertIsString($s, $rel);

        return $s;
    }

    public function testDeploymentRunsEntrypointAsRootWithMinimalCaps(): void
    {
        $d = $this->read('k8s/helm/phlix/templates/deployment.yaml');

        self::assertStringNotContainsString('runAsNonRoot: true', $d, 'supervisord user=nobody needs root');
        self::assertStringNotContainsString('runAsUser: 1000', $d);
        self::assertMatchesRegularExpression('/runAsUser: 0\b/', $d);
        self::assertMatchesRegularExpression('/fsGroup: 65534\b/', $d, 'daemon runs as nobody');
        self::assertStringContainsString('allowPrivilegeEscalation: false', $d);
        self::assertMatchesRegularExpression('/drop:\s*\n\s*- ALL/', $d);
        // Parse the `add:` list (consecutive `- CAP` items) and pin the EXACT
        // minimal set: a stray extra capability must fail, not just a missing one.
        self::assertSame(1, preg_match('/^\s*add:\s*\n((?:\s*- [A-Z_]+\s*\n)+)/m', $d, $m), 'capabilities.add block');
        preg_match_all('/- ([A-Z_]+)/', $m[1], $caps);
        $added = $caps[1];
        sort($added);
        self::assertSame(['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'KILL', 'SETGID', 'SETUID'], $added);
    }

    public function testChartPostureMatchesImageBootChain(): void
    {
        $sup = $this->read('docker/supervisord.conf');
        self::assertMatchesRegularExpression('/^user=root$/m', $sup);
        self::assertMatchesRegularExpression('/^user=nobody$/m', $sup);
        self::assertStringContainsString('CMD ["sh", "/docker-entrypoint.sh"]', $this->read('docker/Dockerfile'));
    }

    public function testStartupProbeBudgetCoversFirstBootMigrations(): void
    {
        $v = $this->read('k8s/helm/phlix/values.yaml');
        self::assertSame(1, preg_match('/^startupProbe:\n(?:  .*\n)+/m', $v, $m));
        self::assertSame(1, preg_match('/initialDelaySeconds: (\d+)/', $m[0], $i));
        self::assertSame(1, preg_match('/periodSeconds: (\d+)/', $m[0], $p));
        self::assertSame(1, preg_match('/failureThreshold: (\d+)/', $m[0], $f));
        self::assertGreaterThanOrEqual(300, (int) $i[1] + (int) $p[1] * (int) $f[1]);
    }
}
