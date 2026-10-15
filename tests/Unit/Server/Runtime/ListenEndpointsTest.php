<?php

/**
 * Phlix media server test: listen endpoint resolution (R14-06).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
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
