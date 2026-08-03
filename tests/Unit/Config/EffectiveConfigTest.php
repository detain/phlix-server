<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Config;

use Phlix\Config\EffectiveConfig;
use Phlix\Config\HwAccelConfig;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Boot-time settings overlay — the MECHANISM.
 *
 * These tests assert the CONSEQUENCE of an override, not that a merge helper
 * was called: for each of the four config SHAPES the shipped schema's 16
 * `"restart": true` keys take, an override persisted in `server_settings` must
 * change the value a consumer actually reads.
 *
 * Every assertion is paired with a "the file default says otherwise" check, so
 * a test can never pass merely because the config file happened to agree.
 *
 */
final class EffectiveConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EffectiveConfig::reset();
        HwAccelConfig::reset();
    }

    protected function tearDown(): void
    {
        EffectiveConfig::reset();
        HwAccelConfig::reset();
        parent::tearDown();
    }

    /**
     * A stand-in for the `server_settings` table.
     *
     * Faithfully answers BOTH queries {@see \Phlix\Admin\SettingsRepository}
     * issues — the whole-table read and the single-key read — so a consumer
     * that layers `getEffective()` on top of the overlay sees a consistent
     * store rather than a half-populated one.
     *
     * @param array<string, array{0: string, 1: string}> $rows key → [stored text, value_type]
     */
    private function fakeSettingsDb(array $rows): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param mixed $query
             * @param mixed $params
             *
             * @return list<array<string, string>>
             */
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

                if (str_contains($sql, 'SELECT setting_value,')) {
                    $key = is_array($params) && isset($params[0]) && is_string($params[0]) ? $params[0] : '';
                    if (isset($rows[$key])) {
                        return [['setting_value' => $rows[$key][0], 'value_type' => $rows[$key][1]]];
                    }
                    return [];
                }

                return [];
            }
        );

        return $db;
    }

    /**
     * A connection whose every query blows up — the "settings store is
     * unreachable" case (DB down, `server_settings` table absent on a fresh
     * install whose migrations have not run yet).
     */
    private function brokenDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(
            new \RuntimeException("Table 'phlix.server_settings' doesn't exist")
        );

        return $db;
    }

    // -----------------------------------------------------------------------
    // Shape 1 — flat `hwaccel.*`
    // -----------------------------------------------------------------------

    public function test_flat_hwaccel_key_override_reaches_the_merged_hwaccel_config(): void
    {
        // The shipped default must be the opposite, or this proves nothing.
        $this->assertTrue(
            HwAccelConfig::get()['enabled'],
            'config/hwaccel.php is expected to ship enabled=true'
        );

        EffectiveConfig::bootstrap($this->fakeSettingsDb([
            'hwaccel.enabled' => ['0', 'bool'],
        ]));

        $this->assertFalse(
            HwAccelConfig::get()['enabled'],
            'hwaccel.enabled override must reach HwAccelConfig::get(), the single source '
            . 'of truth FfmpegRunner::setConfig() is fed from'
        );
    }

    public function test_flat_hwaccel_prefer_hardware_override_reaches_the_merged_config(): void
    {
        $this->assertTrue(HwAccelConfig::get()['prefer_hardware']);

        EffectiveConfig::bootstrap($this->fakeSettingsDb([
            'hwaccel.prefer_hardware' => ['0', 'bool'],
        ]));

        $this->assertFalse(HwAccelConfig::get()['prefer_hardware']);
    }

    public function test_transcoding_preferred_accelerator_override_reaches_the_merged_config(): void
    {
        $this->assertSame('', HwAccelConfig::get()['preferred_accelerator']);

        EffectiveConfig::bootstrap($this->fakeSettingsDb([
            'transcoding.preferred_accelerator' => ['vaapi', 'string'],
        ]));

        $this->assertSame('vaapi', HwAccelConfig::get()['preferred_accelerator']);
    }

    // -----------------------------------------------------------------------
    // Shape 2 — nested `server.hls.*`
    // -----------------------------------------------------------------------

    public function test_nested_server_hls_key_override_reaches_the_boot_config(): void
    {
        /** @var array<string, mixed> $bootConfig */
        $bootConfig = include \dirname(__DIR__, 3) . '/config/server.php';

        $this->assertIsArray($bootConfig['hls']);
        $this->assertSame(6, $bootConfig['hls']['segment_seconds'], 'shipped default');

        EffectiveConfig::bootstrap($this->fakeSettingsDb([
            'server.hls.segment_seconds'        => ['10', 'int'],
            'server.hls.max_concurrent_segments' => ['3', 'int'],
            'server.hls.cache_max_age'          => ['60', 'int'],
            'server.hls.cache_max_bytes'        => ['1024', 'int'],
        ]));

        $overlaid = EffectiveConfig::overlayAppConfig($bootConfig);

        $this->assertIsArray($overlaid['hls']);
        $this->assertSame(10, $overlaid['hls']['segment_seconds']);
        $this->assertSame(3, $overlaid['hls']['max_concurrent_segments']);
        $this->assertSame(60, $overlaid['hls']['cache_max_age']);
        $this->assertSame(1024, $overlaid['hls']['cache_max_bytes']);
    }

    // -----------------------------------------------------------------------
    // Shape 3 — hyphenated `process.<worker>.enabled`
    // -----------------------------------------------------------------------

    public function test_hyphenated_process_key_override_reaches_the_managed_worker_gate(): void
    {
        // The exact expression start.php's managed-worker onWorkerStart gate
        // evaluates. The shipped file enables all five.
        $shipped = EffectiveConfig::file('process');
        $this->assertIsArray($shipped['library-scan']);
        $this->assertTrue($shipped['library-scan']['enabled'], 'shipped default');

        EffectiveConfig::bootstrap($this->fakeSettingsDb([
            'process.library-scan.enabled' => ['0', 'bool'],
        ]));

        $effective = EffectiveConfig::file('process');
        $this->assertIsArray($effective['library-scan']);
        $this->assertFalse(
            $effective['library-scan']['enabled'],
            'a hyphenated dotted segment must resolve; start.php gates the poll timer on this'
        );

        // Siblings are untouched — the overlay sets one leaf, not the block.
        $this->assertIsArray($effective['similarity']);
        $this->assertTrue($effective['similarity']['enabled']);
        $this->assertSame(5, $effective['library-scan']['poll_seconds']);
        $this->assertSame(1, $effective['library-scan']['count']);
    }

    public function test_every_process_worker_toggle_resolves(): void
    {
        EffectiveConfig::bootstrap($this->fakeSettingsDb([
            'process.library-scan.enabled'       => ['0', 'bool'],
            'process.plugin-auto-update.enabled' => ['0', 'bool'],
            'process.marker-detection.enabled'   => ['0', 'bool'],
            'process.media-asset.enabled'        => ['0', 'bool'],
            'process.similarity.enabled'         => ['0', 'bool'],
        ]));

        $effective = EffectiveConfig::file('process');
        foreach (
            ['library-scan', 'plugin-auto-update', 'marker-detection', 'media-asset', 'similarity'] as $key
        ) {
            $this->assertIsArray($effective[$key]);
            $this->assertFalse($effective[$key]['enabled'], $key . ' must be disable-able');
        }
    }

    // -----------------------------------------------------------------------
    // Shape 4 — `ffmpeg.*`
    // -----------------------------------------------------------------------

    public function test_ffmpeg_key_override_reaches_the_effective_ffmpeg_config(): void
    {
        $shipped = EffectiveConfig::file('ffmpeg');
        $this->assertSame(4, $shipped['max_concurrent_transcodes'], 'shipped default');
        $this->assertSame(7200, $shipped['transcode_timeout'], 'shipped default');
        $this->assertSame(4, $shipped['max_concurrent_scan_probes'], 'shipped default');

        EffectiveConfig::bootstrap($this->fakeSettingsDb([
            'ffmpeg.max_concurrent_transcodes'  => ['9', 'int'],
            'ffmpeg.transcode_timeout'          => ['111', 'int'],
            'ffmpeg.max_concurrent_scan_probes' => ['7', 'int'],
        ]));

        $effective = EffectiveConfig::file('ffmpeg');
        $this->assertSame(9, $effective['max_concurrent_transcodes']);
        $this->assertSame(111, $effective['transcode_timeout']);
        $this->assertSame(7, $effective['max_concurrent_scan_probes']);
    }

    public function test_ffmpeg_override_also_reaches_the_nested_copy_inside_the_boot_config(): void
    {
        // config/server.php composes config/ffmpeg.php under $config['ffmpeg'],
        // which is what the DI providers read.
        /** @var array<string, mixed> $bootConfig */
        $bootConfig = include \dirname(__DIR__, 3) . '/config/server.php';
        $this->assertIsArray($bootConfig['ffmpeg']);
        $this->assertSame(4, $bootConfig['ffmpeg']['max_concurrent_transcodes']);

        EffectiveConfig::bootstrap($this->fakeSettingsDb([
            'ffmpeg.max_concurrent_transcodes'  => ['9', 'int'],
            'ffmpeg.max_concurrent_scan_probes' => ['7', 'int'],
        ]));

        $overlaid = EffectiveConfig::overlayAppConfig($bootConfig);
        $this->assertIsArray($overlaid['ffmpeg']);
        $this->assertSame(9, $overlaid['ffmpeg']['max_concurrent_transcodes']);
        $this->assertSame(7, $overlaid['ffmpeg']['max_concurrent_scan_probes']);
    }

    // -----------------------------------------------------------------------
    // Boot-failure behaviour
    // -----------------------------------------------------------------------

    public function test_unreachable_settings_store_still_boots_on_file_defaults(): void
    {
        /** @var array<string, mixed> $bootConfig */
        $bootConfig = include \dirname(__DIR__, 3) . '/config/server.php';

        EffectiveConfig::bootstrap($this->brokenDb());

        $this->assertSame([], EffectiveConfig::overrides(), 'a throwing store yields no overrides');
        $this->assertSame(
            $bootConfig,
            EffectiveConfig::overlayAppConfig($bootConfig),
            'boot config must be returned verbatim when the settings store is unreachable'
        );
        $this->assertSame(6, EffectiveConfig::file('server')['hls']['segment_seconds']);
        $this->assertSame(7200, EffectiveConfig::file('ffmpeg')['transcode_timeout']);
        $this->assertTrue(HwAccelConfig::get()['enabled']);
    }

    public function test_no_connection_and_no_pool_still_boots_on_file_defaults(): void
    {
        // bootstrapAndOverlay() with no db_config_path: ConnectionPool is
        // uninitialised, and getConnection() on an uninitialised pool WARNS
        // rather than throwing — so the null check must be explicit.
        /** @var array<string, mixed> $bootConfig */
        $bootConfig = include \dirname(__DIR__, 3) . '/config/server.php';

        $result = EffectiveConfig::bootstrapAndOverlay($bootConfig);

        $this->assertSame([], EffectiveConfig::overrides());
        $this->assertSame($bootConfig, $result);
    }

    public function test_empty_settings_store_is_a_no_op(): void
    {
        /** @var array<string, mixed> $bootConfig */
        $bootConfig = include \dirname(__DIR__, 3) . '/config/server.php';

        EffectiveConfig::bootstrap($this->fakeSettingsDb([]));

        $this->assertSame($bootConfig, EffectiveConfig::overlayAppConfig($bootConfig));
    }

    public function test_overlay_is_inert_before_bootstrap(): void
    {
        /** @var array<string, mixed> $bootConfig */
        $bootConfig = include \dirname(__DIR__, 3) . '/config/server.php';

        $this->assertSame(0, EffectiveConfig::generation(), 'reset() leaves a never-bootstrapped state');
        $this->assertSame([], EffectiveConfig::overrides());
        $this->assertSame($bootConfig, EffectiveConfig::overlayAppConfig($bootConfig));
    }

    // -----------------------------------------------------------------------
    // Malformed / unknown persisted keys
    // -----------------------------------------------------------------------

    public function test_malformed_and_unknown_persisted_keys_cannot_break_boot(): void
    {
        /** @var array<string, mixed> $bootConfig */
        $bootConfig = include \dirname(__DIR__, 3) . '/config/server.php';

        EffectiveConfig::bootstrap($this->fakeSettingsDb([
            // Path traversal attempts — rejected by the key-shape jail.
            '../../etc/passwd'                    => ['x', 'string'],
            'ffmpeg/../database.password'         => ['x', 'string'],
            'ffmpeg..transcode_timeout'           => ['1', 'int'],
            // Bare file name: addresses no value inside the file.
            'ffmpeg'                              => ['x', 'string'],
            // Well-formed but addresses nothing that exists.
            'ffmpeg.does_not_exist'               => ['x', 'string'],
            'nosuchfile.nosuchkey'                => ['x', 'string'],
            'server.hls.nope.deeper'              => ['x', 'string'],
            // Descends THROUGH a scalar — must not clobber the scalar.
            'ffmpeg.transcode_timeout.deeper'     => ['x', 'string'],
            // Empty segments.
            '.'                                   => ['x', 'string'],
            '..'                                  => ['x', 'string'],
            ''                                    => ['x', 'string'],
            // One genuinely valid key alongside the garbage.
            'ffmpeg.transcode_timeout'            => ['321', 'int'],
        ]));

        $overlaid = EffectiveConfig::overlayAppConfig($bootConfig);

        // Boot survived and the ONE valid key applied.
        $this->assertIsArray($overlaid['ffmpeg']);
        $this->assertSame(321, $overlaid['ffmpeg']['transcode_timeout']);

        // Nothing was invented.
        $this->assertArrayNotHasKey('does_not_exist', $overlaid['ffmpeg']);
        $this->assertArrayNotHasKey('nosuchfile', $overlaid);
        $this->assertArrayNotHasKey('..', $overlaid);

        // Every other shipped value is untouched.
        $expected = $bootConfig;
        $this->assertIsArray($expected['ffmpeg']);
        $expected['ffmpeg']['transcode_timeout'] = 321;
        $this->assertSame($expected, $overlaid);
    }

    public function test_unsafe_config_file_name_is_refused(): void
    {
        EffectiveConfig::bootstrap($this->fakeSettingsDb([]));

        $this->assertSame([], EffectiveConfig::file('../database'));
        $this->assertSame([], EffectiveConfig::file('scrobblers/trakt'));
        $this->assertSame([], EffectiveConfig::file(''));
    }

    // -----------------------------------------------------------------------
    // Cache invalidation across bootstraps (the master-forks-children hazard)
    // -----------------------------------------------------------------------

    public function test_rebootstrapping_invalidates_derived_caches(): void
    {
        // Simulates the real hazard: config/server.php requires config/ffmpeg.php,
        // which calls HwAccelConfig::get() in the MASTER — before any worker has
        // read the overrides. The per-worker bootstrap must invalidate that.
        $this->assertTrue(HwAccelConfig::get()['enabled'], 'pre-overlay master-side merge');

        EffectiveConfig::bootstrap($this->fakeSettingsDb([
            'hwaccel.enabled' => ['0', 'bool'],
        ]));

        $this->assertFalse(HwAccelConfig::get()['enabled'], 'worker bootstrap must invalidate the master cache');

        EffectiveConfig::bootstrap($this->fakeSettingsDb([]));

        $this->assertTrue(HwAccelConfig::get()['enabled'], 'clearing the override must be observed too');
    }

    public function test_generation_increments_on_every_bootstrap_and_resets_to_zero(): void
    {
        $this->assertSame(0, EffectiveConfig::generation());
        EffectiveConfig::bootstrap($this->fakeSettingsDb([]));
        $this->assertSame(1, EffectiveConfig::generation());
        EffectiveConfig::bootstrap($this->fakeSettingsDb([]));
        $this->assertSame(2, EffectiveConfig::generation());
        EffectiveConfig::reset();
        $this->assertSame(0, EffectiveConfig::generation(), 'reset() restores the never-bootstrapped state');
    }
}
