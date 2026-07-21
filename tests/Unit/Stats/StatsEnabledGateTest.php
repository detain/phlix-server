<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Stats;

use PHPUnit\Framework\TestCase;
use Phlix\Config\EffectiveConfig;
use Phlix\Stats\StatsCollector;
use Workerman\MySQL\Connection;

/**
 * Consequence tests for the `stats.enabled` switch.
 *
 * ## Scope note
 *
 * plan_settings.md describes this row as "telemetry no opt-out". That framing is
 * wrong and worth stating in the tests too: these statistics are entirely LOCAL
 * — written to this server's own `stats_*` tables, read back only by the admin
 * dashboard, never transmitted anywhere. The switch is a performance/retention
 * control, not a privacy one.
 *
 * ## What is asserted
 *
 * The guard lives in `StatsCollector` rather than at the ~52 call sites, so the
 * behaviour to pin is that EVERY public `record*()` method honours it. A test
 * covering only one method would pass while the other four kept writing — the
 * "half-effective setting" failure this program keeps hitting.
 *
 * All assertions are on whether a DB write actually happens, not on whether a
 * config value was read.
 *
 * Each test is mutation-verified; see individual docblocks.
 */
final class StatsEnabledGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EffectiveConfig::reset();
    }

    protected function tearDown(): void
    {
        EffectiveConfig::reset();
        parent::tearDown();
    }

    /**
     * @param array<string, array{0: string, 1: string}> $rows
     */
    private function fakeSettingsDb(array $rows): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function ($query = '', $params = null) use ($rows): array {
                $sql = is_string($query) ? $query : '';
                if (str_contains($sql, 'SELECT setting_key,')) {
                    $out = [];
                    foreach ($rows as $key => [$value, $type]) {
                        $out[] = [
                            'setting_key'   => $key,
                            'setting_value' => $value,
                            'value_type'    => $type,
                        ];
                    }
                    return $out;
                }
                return [];
            }
        );
        return $db;
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function bootstrapWith(array $stats, array $overrides = []): void
    {
        $dir = sys_get_temp_dir() . '/phlix_stats_' . uniqid('', true) . '/config';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/stats.php', '<?php return ' . var_export($stats, true) . ";\n");
        EffectiveConfig::bootstrap($this->fakeSettingsDb($overrides), null, $dir);
    }

    /**
     * Exercise every public record*() method on the given collector.
     */
    private function callEveryRecorder(StatsCollector $c): void
    {
        $id = $c->recordPlaybackStart('u1', 'm1', 'movie', 'd1');
        $c->recordPlaybackEnd($id, 60, true);
        $c->recordLibraryChange('item_added', 'm1', 'lib1', 'u1', []);
        $c->recordUserActivity('u1', 'login', '10.0.0.1', []);
        $c->recordStorageSnapshot('movie', 1, 1024, 0, 'lib1');
    }

    /**
     * CONSEQUENCE: with the switch OFF, NO record*() method writes.
     *
     * The mock refuses every query, so any method that forgot its guard fails
     * here. This is the test that makes the single-switch claim real.
     *
     * Mutation-verified: removing the guard from ANY ONE of the five methods
     * fails this test.
     */
    public function test_disabling_stats_stops_every_recorder_writing(): void
    {
        $this->bootstrapWith(['enabled' => false]);

        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $this->callEveryRecorder(new StatsCollector($db));
    }

    /**
     * CONSEQUENCE: with the switch ON, the recorders DO write.
     *
     * A disable-only test would pass against a collector that never writes at
     * all, so the enabled path must be shown to reach the database — once per
     * recorder, five in total.
     *
     * Mutation-verified: hardcoding isEnabled() to false fails this.
     */
    public function test_enabled_stats_still_write(): void
    {
        $this->bootstrapWith(['enabled' => true]);

        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(5))->method('query')->willReturn([]);

        $this->callEveryRecorder(new StatsCollector($db));
    }

    /**
     * CONSEQUENCE: an absent key keeps recording.
     *
     * `config/stats.php` is net-new, so every existing install upgrades without
     * it and without an override. Defaulting to "off" would silently blank the
     * dashboard on upgrade — including the storage snapshot whose timer was only
     * just fixed.
     *
     * Mutation-verified: changing the `?? true` default to `?? false` fails this.
     */
    public function test_an_absent_setting_keeps_recording(): void
    {
        $this->bootstrapWith([]);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        self::assertTrue((new StatsCollector($db))->isEnabled());
    }

    /**
     * CONSEQUENCE: an admin override reaches the collector.
     *
     * The config file says enabled; the override says disabled. The override
     * must win, which is what makes this a SETTING rather than just a config
     * constant (read-path class (c), not the class (d) trap).
     *
     * Mutation-verified: reading the file with a raw include instead of
     * EffectiveConfig::file() fails this.
     */
    public function test_an_admin_override_reaches_the_collector(): void
    {
        $this->bootstrapWith(['enabled' => true], ['stats.enabled' => ['0', 'bool']]);

        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        self::assertFalse((new StatsCollector($db))->isEnabled());
        $this->callEveryRecorder(new StatsCollector($db));
    }

    /**
     * CONSEQUENCE: recordPlaybackStart still returns a usable id when disabled.
     *
     * Its return value is handed straight to recordPlaybackEnd() by callers that
     * have no idea statistics are switched off. Returning an empty string would
     * change the method's contract based on a setting, so the guard returns the
     * generated id and lets recordPlaybackEnd() no-op instead.
     *
     * Mutation-verified: returning '' from the disabled branch fails this.
     */
    public function test_playback_start_keeps_its_contract_when_disabled(): void
    {
        $this->bootstrapWith(['enabled' => false]);

        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $id = (new StatsCollector($db))->recordPlaybackStart('u1', 'm1', 'movie');

        self::assertNotSame('', $id, 'Callers pass this id to recordPlaybackEnd(); it must stay well-formed.');
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $id);
    }
}
