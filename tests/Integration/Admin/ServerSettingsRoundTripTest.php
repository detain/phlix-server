<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Admin;

use Phlix\Admin\SettingsRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Round-trip integration test for the server-settings store (Step 0.5).
 *
 * Mirrors the suite's existing convention (e.g. CollectionCrudTest) of
 * driving a mocked {@see Connection} with a stateful query callback that
 * simulates the table. The "survives a restart" acceptance criterion is
 * exercised by writing via {@see SettingsRepository::set()} and then reading
 * the value back through a *fresh* repository instance pointed at the same
 * simulated store — a restart's only durable carry-over is the DB row.
 *
 * @covers \Phlix\Admin\SettingsRepository
 */
final class ServerSettingsRoundTripTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/config-settings';

    public function testOverridePersistsAndIsReadBackAfterRestart(): void
    {
        /** @var array<string, array{value: string, type: string}> $store */
        $store = [];

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null, $fetchmode = \PDO::FETCH_ASSOC) use (&$store) {
                // Match the REAL Workerman\MySQL\Connection::query() contract:
                //   query($query = '', $params = null, $fetchmode = PDO::FETCH_ASSOC)
                // getAllOverrides() legitimately calls query($sql) with no params,
                // so the 2nd arg arrives as null — normalise it to [].
                $params ??= [];
                if (str_contains($sql, 'INSERT INTO server_settings')) {
                    // [id, setting_key, setting_value, value_type]
                    $store[$params[1]] = ['value' => $params[2], 'type' => $params[3]];
                    return [];
                }

                if (str_contains($sql, 'WHERE setting_key = ?')) {
                    $key = $params[0];
                    if (!isset($store[$key])) {
                        return [];
                    }
                    return [[
                        'setting_value' => $store[$key]['value'],
                        'value_type'    => $store[$key]['type'],
                    ]];
                }

                // getAllOverrides()
                $rows = [];
                foreach ($store as $key => $entry) {
                    $rows[] = [
                        'setting_key'   => $key,
                        'setting_value' => $entry['value'],
                        'value_type'    => $entry['type'],
                    ];
                }
                return $rows;
            }
        );

        // Initial state: no override → effective value is the config default.
        $writer = new SettingsRepository($db, self::FIXTURE_DIR);
        $this->assertTrue($writer->getEffective('hwaccel.enabled'));

        // Persist an override.
        $writer->set('hwaccel.enabled', false, 'bool');
        $writer->set('hwaccel.probe_timeout', 60, 'int');

        // Simulate a restart: a brand-new repository instance (no in-process
        // cache carried over) reads the same persistent store.
        $afterRestart = new SettingsRepository($db, self::FIXTURE_DIR);
        $this->assertFalse($afterRestart->getEffective('hwaccel.enabled'));
        $this->assertSame(60, $afterRestart->getEffective('hwaccel.probe_timeout'));

        $merged = $afterRestart->getEffectiveMany([
            'hwaccel.enabled',
            'hwaccel.probe_timeout',
            'port-forward.port_forwarding.upnp_enabled',
        ]);
        $this->assertFalse($merged['values']['hwaccel.enabled']);
        $this->assertSame(60, $merged['values']['hwaccel.probe_timeout']);
        // Untouched key still resolves to its config default.
        $this->assertTrue($merged['values']['port-forward.port_forwarding.upnp_enabled']);
        $this->assertContains('hwaccel.enabled', $merged['overridden']);
        $this->assertContains('hwaccel.probe_timeout', $merged['overridden']);
    }
}
