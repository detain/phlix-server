<?php

/**
 * Phlix media server test: hub chart placeholder-secret guard (SH-027).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * SH-027 (seceng major 1): the vendored phlix-hub helm chart shipped values
 * whose secret positions carry PUBLIC placeholders — values.example.yaml
 * literally ships database_password "CHANGE_ME" and secret_key "openssl rand
 * -hex 64" (the COMMAND as the VALUE) — and templates/secret.yaml wrapped
 * them in bare required(), which proves non-emptiness only. `helm install -f
 * values.example.yaml` therefore succeeded verbatim, booting a hub with a
 * publicly-known JWT signing key and DB password.
 *
 * The sibling server chart learned this lesson as M29: a
 * rejectKnownPlaceholder helper fails RENDER on any known-placeholder shape.
 * These text pins hold the hub chart to the same doctrine and keep the
 * README honest about it (no argv secret leaks, no "own required values"
 * false-safeguard prose). No helm binary exists in CI, so the guard is pinned
 * at the source-text level — the same convention as the DockerEntrypoint and
 * deploy-artifact text pins in this directory.
 */
final class HubChartPlaceholderSecretGuardTest extends TestCase
{
    /** @var string Repository root (tests/Unit/Deploy → repo root). */
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 3);
    }

    private function chartFile(string $relative): string
    {
        $path = $this->repoRoot . '/k8s/helm/phlix-hub/' . $relative;
        $contents = file_get_contents($path);
        $this->assertIsString($contents, "chart file {$relative} must exist and be readable");

        return $contents;
    }

    public function test_the_hub_chart_defines_the_placeholder_guard_helper(): void
    {
        $helpers = $this->chartFile('templates/_helpers.tpl');

        $this->assertStringContainsString('{{- define "phlix-hub.rejectKnownPlaceholder" -}}', $helpers);
        // The two shapes the example file actually baits with must be in the
        // dirty list, and the refusal must be a render-time fail().
        $this->assertStringContainsString('"change_me"', $helpers);
        $this->assertStringContainsString('"openssl rand -hex 64"', $helpers);
        $this->assertStringContainsString('{{- fail (', $helpers);
    }

    public function test_the_helper_matches_the_server_chart_dirty_list(): void
    {
        // One doctrine, two charts: a placeholder refused by the server chart
        // must be refused by the hub chart too. Extract both dirty-list lines
        // and pin them identical.
        $extract = static function (string $helpers): ?string {
            foreach (explode("\n", $helpers) as $line) {
                if (str_contains($line, '$dirty := list')) {
                    return trim($line);
                }
            }

            return null;
        };

        $server = $extract($this->readServerChartHelpers());
        $hub = $extract($this->chartFile('templates/_helpers.tpl'));

        $this->assertNotNull($server, 'server chart must keep its $dirty list');
        $this->assertNotNull($hub, 'hub chart guard must carry its $dirty list');
        $this->assertSame($server, $hub);
    }

    public function test_secret_template_wraps_both_credentials_in_the_guard(): void
    {
        $secret = $this->chartFile('templates/secret.yaml');

        $this->assertStringContainsString(
            'include "phlix-hub.rejectKnownPlaceholder" (list "config.database_password"',
            $secret,
        );
        $this->assertStringContainsString(
            'include "phlix-hub.rejectKnownPlaceholder" (list "config.secret_key"',
            $secret,
        );
        // Bare required() alone is the removed shape — required stays (it is
        // the presence gate) but never as the only wrapper on the password key.
        $this->assertStringNotContainsString('database-password: {{ required', $secret);
    }

    public function test_the_example_values_still_ship_the_loud_bait(): void
    {
        // Deliberate: the example keeps the exact placeholder strings so a
        // copy-paste install hits the render-time refusal. Replacing the bait
        // with plausible-looking real values would restore the silent risk.
        $example = $this->chartFile('values.example.yaml');

        $this->assertStringContainsString('database_password: "CHANGE_ME"', $example);
        $this->assertStringContainsString('secret_key: "openssl rand -hex 64"', $example);
    }

    public function test_the_readme_no_longer_leaks_secrets_through_argv(): void
    {
        // `helm install --set ...=secret` puts credentials in argv (shell
        // history, ps). Both chart sections must show the values-file idiom.
        $readme = $this->readK8sReadme();

        $this->assertStringNotContainsString('--set config.secret_key=', $readme);
        $this->assertStringNotContainsString('--set config.database_password=', $readme);
    }

    public function test_the_readme_documents_the_placeholder_refusal(): void
    {
        // The hub section used to claim "its own required config.database_password /
        // config.secret_key values" as the safeguard — required() only proves
        // non-emptiness. The prose must now point at the actual guard.
        $readme = $this->readK8sReadme();

        $this->assertStringNotContainsString('its own required', $readme);
        $this->assertStringContainsString('rejectKnownPlaceholder', $readme);
        $this->assertMatchesRegularExpression('/values file|values-file|--values/', $readme);
    }

    private function readServerChartHelpers(): string
    {
        $contents = file_get_contents($this->repoRoot . '/k8s/helm/phlix/templates/_helpers.tpl');
        $this->assertIsString($contents);

        return $contents;
    }

    private function readK8sReadme(): string
    {
        $contents = file_get_contents($this->repoRoot . '/k8s/README.md');
        $this->assertIsString($contents);

        return $contents;
    }
}
