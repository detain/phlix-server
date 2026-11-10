<?php

/**
 * Phlix media server test: SH-025 start.php quota-wiring pin (w18L1 R2).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * The start.php wiring leg of the cgroup-aware auto worker count (SH-025).
 *
 * `start.php` runs outside PHPUnit — no CI job executes the daemon — so the
 * wiring that makes the whole SH-025 cure reach production lives in a file
 * nothing boots from source. The w18L1 adversarial review EXECUTED the hole:
 * reverting start.php's fifth `http()` argument — the
 * `ListenEndpoints::detectCpuQuotaCores()` reading — keeps the FULL Unit
 * battery byte-identical (994 junit tails, errors=0, failures=base). A
 * future edit dropping that argument would silently restore "14 workers
 * inside a 2-core k8s pod" — the CFS quota nproc never reports, the exact
 * SH-025 OOMKill-lottery defect — with the suite green. This is the w17L3
 * SH-024 zero-enforcement class, cured the same house way: a source-text
 * pin in the boot-wiring family next to
 * {@see \Phlix\Tests\Unit\Server\Integrations\Trakt\TraktSyncBootWiringTest}
 * and {@see \Phlix\Tests\Unit\Playlists\SmartPlaylistRefreshWiringTest}.
 * Comments are stripped first, so the pin cannot be satisfied by the SH-025
 * explanation block sitting next to the code it pins.
 */
final class HttpWorkerQuotaWiringTest extends TestCase
{
    public function test_start_php_hands_the_cgroup_quota_cores_to_the_http_listen_call(): void
    {
        $code = $this->startPhpWithoutComments();

        // The guard is vacuous unless the resolved-HTTP call site exists at all.
        self::assertStringContainsString(
            'ListenEndpoints::http(',
            $code,
            'start.php must resolve the HTTP worker through ListenEndpoints::http()',
        );

        // The shipped call shape: config, env host, env port, env worker
        // count, THEN the cgroup CPU quota reading. Dropping the fifth
        // argument — or shuffling it out of the call — reddens this pin.
        self::assertMatchesRegularExpression(
            '/ListenEndpoints::http\(\s*'
            . '\$config,\s*'
            . 'ListenEndpoints::envValue\(ListenEndpoints::ENV_HTTP_HOST\),\s*'
            . 'ListenEndpoints::envValue\(ListenEndpoints::ENV_HTTP_PORT\),\s*'
            . 'ListenEndpoints::envValue\(ListenEndpoints::ENV_HTTP_WORKER_COUNT\),\s*'
            . 'ListenEndpoints::detectCpuQuotaCores\(\)\s*\)/',
            $code,
            'start.php must pass ListenEndpoints::detectCpuQuotaCores() as the quota '
            . 'argument of the http() call — without it the auto arm never sees a k8s '
            . 'CFS limit and the SH-025 oversubscription defect returns silently',
        );

        // The pre-cure shape must stay extinct: the worker-count env read may
        // never again be the FINAL argument of the http() call.
        self::assertDoesNotMatchRegularExpression(
            '/ListenEndpoints::envValue\(ListenEndpoints::ENV_HTTP_WORKER_COUNT\)\s*\)/',
            $code,
            'the pre-SH-025 four-argument http() call must be gone — the quota '
            . 'argument must follow the worker-count argument',
        );
    }

    /** start.php source with all comments removed. */
    private function startPhpWithoutComments(): string
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/start.php');
        self::assertIsString($source);

        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}
