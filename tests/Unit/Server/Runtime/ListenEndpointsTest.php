<?php

/**
 * Phlix media server test: listen endpoint resolution (R14-06).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Runtime;

use Phlix\Server\Runtime\ListenEndpoints;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the `env > config > shipped default` ladder for the two listening
 * workers, the byte-identical defaults that held before the config keys
 * were wired (0.0.0.0:8096 count 14 / 0.0.0.0:8097), the loud refusal of
 * unparseable values, and the reuse_port opt-in law (shipped inert-true →
 * wired-false so wiring alone changed no socket behavior).
 */
final class ListenEndpointsTest extends TestCase
{
    public function test_defaults_reproduce_the_pre_r14_06_hardcodes_byte_identical(): void
    {
        $http = ListenEndpoints::http([]);

        self::assertSame('0.0.0.0', $http['host']);
        self::assertSame(8096, $http['port']);
        self::assertSame(14, $http['workerCount']);
        self::assertSame('http://0.0.0.0:8096', $http['socketName']);

        $ws = ListenEndpoints::webSocket([]);

        self::assertSame('websocket://0.0.0.0:8097', $ws['socketName']);
    }

    public function test_the_shipped_config_array_resolves_to_the_same_defaults(): void
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 4) . '/config/server.php';

        // A stock checkout with no env set must bind exactly what it bound
        // before the keys were honored.
        self::assertSame('http://0.0.0.0:8096', ListenEndpoints::http($config)['socketName']);
        self::assertSame(14, ListenEndpoints::http($config)['workerCount']);
        self::assertSame('websocket://0.0.0.0:8097', ListenEndpoints::webSocket($config)['socketName']);
    }

    public function test_config_port_is_honored_and_env_beats_config(): void
    {
        $config = ['server' => ['host' => '127.0.0.1', 'port' => 8123]];

        self::assertSame('http://127.0.0.1:8123', ListenEndpoints::http($config)['socketName']);
        self::assertSame(
            'http://127.0.0.1:9999',
            ListenEndpoints::http($config, null, '9999')['socketName'],
        );
    }

    public function test_websocket_config_pair_is_honored_with_env_precedence(): void
    {
        $config = ['websocket' => ['host' => '10.0.0.5', 'port' => 9097]];

        self::assertSame('websocket://10.0.0.5:9097', ListenEndpoints::webSocket($config)['socketName']);
        self::assertSame(
            'websocket://10.0.0.5:9098',
            ListenEndpoints::webSocket($config, null, '9098')['socketName'],
        );
        self::assertSame(
            'websocket://[::1]:9097',
            ListenEndpoints::webSocket($config, '[::1]')['socketName'],
        );
    }

    public function test_worker_count_ladder_auto_int_and_env(): void
    {
        self::assertSame(14, ListenEndpoints::http(['worker' => ['count' => 'auto']])['workerCount']);
        self::assertSame(4, ListenEndpoints::http(['worker' => ['count' => 4]])['workerCount']);
        // Env wins over an explicit config int.
        self::assertSame(2, ListenEndpoints::http(['worker' => ['count' => 4]], null, null, '2')['workerCount']);
    }

    public function test_auto_shrinks_the_pool_to_a_visible_cpu_quota(): void
    {
        // SH-025: a k8s CPU limit is a cgroup CFS quota that `nproc` lies
        // about, so 'auto' must consult the quota when one is detectable:
        // ceil(cores) workers, never more than the shipped 14, never fewer
        // than 1. A pod capped at 2 cores forks 2 HTTP workers, not 14 —
        // 14 workers + ~11 sibling processes inside a 2000m/2Gi limit is an
        // OOMKill lottery for the whole pod.
        self::assertSame(
            2,
            ListenEndpoints::http(['worker' => ['count' => 'auto']], null, null, null, 2.0)['workerCount'],
        );
        self::assertSame(
            2,
            ListenEndpoints::http([], null, null, null, 2.0)['workerCount'],
        );
        // Fractional quotas round up but clamp at the floor of one worker.
        self::assertSame(
            1,
            ListenEndpoints::http(['worker' => ['count' => 'auto']], null, null, null, 0.5)['workerCount'],
        );
    }

    public function test_auto_never_exceeds_the_shipped_pool_from_a_quota(): void
    {
        // The quota is a ceiling input, not a mandate to grow: on a 30-core
        // box 'auto' still resolves to the shipped 14, and with no quota
        // visible (bare host, unlimited docker, PHPUnit) it stays 14 exactly
        // as it has always been — default-preserving.
        self::assertSame(
            14,
            ListenEndpoints::http(['worker' => ['count' => 'auto']], null, null, null, 30.0)['workerCount'],
        );
        self::assertSame(
            14,
            ListenEndpoints::http(['worker' => ['count' => 'auto']], null, null, null, null)['workerCount'],
        );
    }

    public function test_an_explicit_worker_count_beats_the_cpu_quota(): void
    {
        // Precedence law unchanged: env > config > auto-resolution. The quota
        // only feeds the auto arm; operators who pin a count keep it even
        // when it overshoots the limit (that becomes their informed choice).
        self::assertSame(
            5,
            ListenEndpoints::http(['worker' => ['count' => 'auto']], null, null, '5', 2.0)['workerCount'],
        );
        self::assertSame(
            6,
            ListenEndpoints::http(['worker' => ['count' => 6]], null, null, null, 2.0)['workerCount'],
        );
    }

    public function test_detect_cpu_quota_cores_reads_the_cgroup_v2_cpu_max(): void
    {
        // SH-025 detection: cgroup v2 renders the limit as "<quota> <period>"
        // in one file (µs/µs). 200000/100000 = 2.0 cores.
        $dir = sys_get_temp_dir() . '/phlix-cgv2-' . uniqid('', true);
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/cpu.max', "200000 100000\n");
        try {
            self::assertSame(
                2.0,
                ListenEndpoints::detectCpuQuotaCores($dir . '/cpu.max', $dir . '/absent', $dir . '/absent'),
            );
        } finally {
            @unlink($dir . '/cpu.max');
            @rmdir($dir);
        }
    }

    public function test_detect_cpu_quota_cores_reads_the_cgroup_v1_pair(): void
    {
        // cgroup v1 splits the same limit into cpu.cfs_quota_us +
        // cpu.cfs_period_us, reached only when no v2 cpu.max exists.
        $dir = sys_get_temp_dir() . '/phlix-cgv1-' . uniqid('', true);
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/quota', "150000\n");
        file_put_contents($dir . '/period', "100000\n");
        try {
            self::assertSame(
                1.5,
                ListenEndpoints::detectCpuQuotaCores($dir . '/absent', $dir . '/quota', $dir . '/period'),
            );
        } finally {
            @unlink($dir . '/quota');
            @unlink($dir . '/period');
            @rmdir($dir);
        }
    }

    public function test_detect_cpu_quota_cores_is_null_for_unlimited_absent_or_malformed(): void
    {
        // Every "no usable quota" shape — v2 'max', v1 -1 sentinel, missing
        // files, non-numeric garbage, zero period, wrong token count — must
        // yield null so 'auto' falls back to the shipped 14 instead of
        // guessing. A detection that mis-answers "no limit" as "1 core"
        // would throttle every container host.
        $dir = sys_get_temp_dir() . '/phlix-cgnu-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $missing = $dir . '/absent';
        try {
            file_put_contents($dir . '/max', "max 100000\n");
            self::assertNull(ListenEndpoints::detectCpuQuotaCores($dir . '/max', $missing, $missing));

            file_put_contents($dir . '/quota', "-1\n");
            file_put_contents($dir . '/period', "100000\n");
            self::assertNull(ListenEndpoints::detectCpuQuotaCores($missing, $dir . '/quota', $dir . '/period'));

            file_put_contents($dir . '/garbage', "banana apple\n");
            self::assertNull(ListenEndpoints::detectCpuQuotaCores($dir . '/garbage', $missing, $missing));

            file_put_contents($dir . '/onerow', "200000\n");
            self::assertNull(ListenEndpoints::detectCpuQuotaCores($dir . '/onerow', $missing, $missing));

            file_put_contents($dir . '/zero', "200000 0\n");
            self::assertNull(ListenEndpoints::detectCpuQuotaCores($dir . '/zero', $missing, $missing));

            self::assertNull(ListenEndpoints::detectCpuQuotaCores($missing, $missing, $missing));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function test_hostile_values_fail_loud_naming_the_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PHLIX_HTTP_PORT');

        ListenEndpoints::http([], null, 'http');
    }

    public function test_out_of_range_ports_fail_loud(): void
    {
        foreach (['0', '65536'] as $garbage) {
            try {
                ListenEndpoints::http([], null, $garbage);
                self::fail("port {$garbage} must not resolve");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('1..65535', $e->getMessage());
            }
        }
    }

    public function test_bare_ipv6_and_space_hosts_fail_loud_but_bracketed_ipv6_passes(): void
    {
        try {
            ListenEndpoints::http(['server' => ['host' => '::1']]);
            self::fail('a bare unbracketed IPv6 host must throw');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('bracket', $e->getMessage());
        }

        self::assertSame(
            'http://[::]:8096',
            ListenEndpoints::http(['server' => ['host' => '[::]']])['socketName'],
        );
    }

    public function test_non_positive_or_garbage_counts_fail_loud(): void
    {
        try {
            ListenEndpoints::http([], null, null, '0');
            self::fail('count 0 must throw');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('PHLIX_HTTP_WORKER_COUNT', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        ListenEndpoints::http(['worker' => ['count' => 'most of them']]);
    }

    public function test_process_flags_default_to_workermans_own_shipped_values(): void
    {
        // Wiring must not change behavior: reloadable ships true, reusePort
        // ships FALSE (its inert-true config value was never applied).
        self::assertTrue(ListenEndpoints::reloadable([]));
        self::assertFalse(ListenEndpoints::reusePort([]));
        self::assertTrue(ListenEndpoints::reloadable(['process' => ['reloadable' => true]]));
        self::assertTrue(ListenEndpoints::reusePort(['process' => ['reuse_port' => true]]));
    }

    public function test_the_shipped_config_keeps_reuse_port_opt_in(): void
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 4) . '/config/server.php';

        self::assertFalse(
            ListenEndpoints::reusePort($config),
            'process.reuse_port must ship false until an operator opts in (R14-06 law)',
        );
    }

    public function test_non_bool_process_flags_fail_loud(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('process.reloadable');

        ListenEndpoints::reloadable(['process' => ['reloadable' => 'yes please']]);
    }

    public function test_env_value_treats_unset_empty_and_whitespace_as_absent(): void
    {
        self::assertNull(ListenEndpoints::envValue('PHLIX_R1406_DEFINITELY_UNSET'));
        putenv('PHLIX_R1406_BLANK=   ');
        self::assertNull(ListenEndpoints::envValue('PHLIX_R1406_BLANK'));
        putenv('PHLIX_R1406_SET= 8123 ');
        self::assertSame('8123', ListenEndpoints::envValue('PHLIX_R1406_SET'));
        putenv('PHLIX_R1406_SET');
        putenv('PHLIX_R1406_BLANK');
    }
}
