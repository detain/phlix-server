<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use Phlix\Admin\SettingsRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for the server-wide settings store (Step 0.5).
 *
 * @covers \Phlix\Admin\SettingsRepository
 */
final class SettingsRepositoryTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/config-settings';

    public function testGetDefaultResolvesTopLevelConfigKey(): void
    {
        $db = $this->createMock(Connection::class);
        $repo = new SettingsRepository($db, self::FIXTURE_DIR);

        $this->assertTrue($repo->getDefault('hwaccel.enabled'));
        $this->assertSame(30, $repo->getDefault('hwaccel.probe_timeout'));
    }

    public function testGetDefaultResolvesNestedDottedKey(): void
    {
        $db = $this->createMock(Connection::class);
        $repo = new SettingsRepository($db, self::FIXTURE_DIR);

        $this->assertTrue($repo->getDefault('port-forward.port_forwarding.upnp_enabled'));
    }

    public function testGetDefaultReturnsNullForUnknownFileOrPath(): void
    {
        $db = $this->createMock(Connection::class);
        $repo = new SettingsRepository($db, self::FIXTURE_DIR);

        $this->assertNull($repo->getDefault('does-not-exist.foo'));
        $this->assertNull($repo->getDefault('hwaccel.no_such_key'));
        // Path-traversal in the file segment must not load anything.
        $this->assertNull($repo->getDefault('../secrets.value'));
    }

    public function testGetOverrideReturnsNullWhenNoRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $repo = new SettingsRepository($db, self::FIXTURE_DIR);

        $this->assertNull($repo->getOverride('hwaccel.enabled'));
    }

    public function testGetOverrideDecodesByType(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_value' => '0', 'value_type' => 'bool'],
        ]);
        $repo = new SettingsRepository($db, self::FIXTURE_DIR);

        $override = $repo->getOverride('hwaccel.enabled');
        $this->assertIsArray($override);
        $this->assertFalse($override['value']);
        $this->assertSame('bool', $override['value_type']);
    }

    public function testGetEffectivePrefersOverrideThenDefault(): void
    {
        // Override present → override wins over the config default (true).
        $dbOverride = $this->createMock(Connection::class);
        $dbOverride->method('query')->willReturn([
            ['setting_value' => '0', 'value_type' => 'bool'],
        ]);
        $repoOverride = new SettingsRepository($dbOverride, self::FIXTURE_DIR);
        $this->assertFalse($repoOverride->getEffective('hwaccel.enabled'));

        // No override → falls back to the config default.
        $dbDefault = $this->createMock(Connection::class);
        $dbDefault->method('query')->willReturn([]);
        $repoDefault = new SettingsRepository($dbDefault, self::FIXTURE_DIR);
        $this->assertTrue($repoDefault->getEffective('hwaccel.enabled'));
    }

    public function testSetIssuesUpsertWithEncodedValue(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('ON DUPLICATE KEY UPDATE'),
                $this->callback(static function (array $params): bool {
                    // [id, key, encoded value, value_type]
                    return $params[1] === 'hwaccel.enabled'
                        && $params[2] === '0'
                        && $params[3] === 'bool';
                }),
            );

        $repo = new SettingsRepository($db, self::FIXTURE_DIR);
        $repo->set('hwaccel.enabled', false, 'bool');
    }

    public function testSetEncodesIntFloatStringAndJson(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            // Match the real Connection::query() signature: $params defaults to
            // null (a no-param SELECT-all calls query($sql) with one arg).
            static function (string $sql, ?array $params = null, $fetchmode = \PDO::FETCH_ASSOC) use (&$captured) {
                $captured[] = $params ?? [];
                return [];
            }
        );

        $repo = new SettingsRepository($db, self::FIXTURE_DIR);
        $repo->set('hwaccel.probe_timeout', 45, 'int');
        $repo->set('marker_detection.similarity_threshold', 0.5, 'float');
        $repo->set('tmdb.api_key', 'abc123', 'string');
        $repo->set('subtitles.style', ['font_size' => 24], 'json');

        $this->assertSame('45', $captured[0][2]);
        $this->assertSame('0.5', $captured[1][2]);
        $this->assertSame('abc123', $captured[2][2]);
        $this->assertSame('{"font_size":24}', $captured[3][2]);
    }

    public function testGetEffectiveManyMergesOverridesAndDefaults(): void
    {
        $db = $this->createMock(Connection::class);
        // getAllOverrides() returns a single override row.
        $db->method('query')->willReturn([
            ['setting_key' => 'hwaccel.enabled', 'setting_value' => '0', 'value_type' => 'bool'],
        ]);

        $repo = new SettingsRepository($db, self::FIXTURE_DIR);
        $merged = $repo->getEffectiveMany([
            'hwaccel.enabled',
            'hwaccel.probe_timeout',
            'port-forward.port_forwarding.upnp_enabled',
        ]);

        // Overridden key uses the DB value; non-overridden keys use defaults.
        $this->assertFalse($merged['values']['hwaccel.enabled']);
        $this->assertSame(30, $merged['values']['hwaccel.probe_timeout']);
        $this->assertTrue($merged['values']['port-forward.port_forwarding.upnp_enabled']);
        $this->assertSame(['hwaccel.enabled'], $merged['overridden']);
    }

    public function testGetAllOverridesDecodesEachRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_key' => 'hwaccel.probe_timeout', 'setting_value' => '42', 'value_type' => 'int'],
            ['setting_key' => 'tmdb.api_key', 'setting_value' => 'k', 'value_type' => 'string'],
        ]);

        $repo = new SettingsRepository($db, self::FIXTURE_DIR);
        $all = $repo->getAllOverrides();

        $this->assertSame(42, $all['hwaccel.probe_timeout']);
        $this->assertSame('k', $all['tmdb.api_key']);
    }

    /**
     * Constructor branch: an explicit `PHLIX_CONFIG_DIR` constant is used when
     * no `$configDir` argument is supplied. Runs in a separate process so the
     * `define()` cannot leak into other tests (and vice-versa).
     *
     * Covers SettingsRepository.php lines 76, 78, 79 (the `defined()` →
     * `constant()` → assign branch).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructorUsesPhlixConfigDirConstantWhenNoArgument(): void
    {
        define('PHLIX_CONFIG_DIR', self::FIXTURE_DIR);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        // No $configDir arg → falls through to the PHLIX_CONFIG_DIR constant,
        // which points at the fixtures, so the default resolves.
        $repo = new SettingsRepository($db);

        $this->assertTrue($repo->getDefault('hwaccel.enabled'));
        $this->assertSame(30, $repo->getDefault('hwaccel.probe_timeout'));
    }

    /**
     * Constructor branch: with neither a `$configDir` argument nor the
     * `PHLIX_CONFIG_DIR` constant defined, it falls back to the literal
     * `'config'` directory. Runs in a separate process so we can be certain
     * the constant is NOT defined here.
     *
     * Covers SettingsRepository.php line 81 (the `'config'` fallback). We probe
     * a deliberately non-existent config file segment so the result is null
     * regardless of what real `config/*.php` files happen to exist in the
     * default `config/` directory — the point is that the fallback branch
     * executed and resolution proceeded against `config/`.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructorFallsBackToConfigDirWhenNoArgumentOrConstant(): void
    {
        $this->assertFalse(
            defined('PHLIX_CONFIG_DIR'),
            'PHLIX_CONFIG_DIR must not be defined for this isolated-process test.',
        );

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $repo = new SettingsRepository($db);

        // No `config/__phlix_no_such_settings_fixture__.php` exists, so this
        // resolves to null via the fallback `config/` dir — proving the
        // no-arg/no-constant branch executed without throwing.
        $this->assertNull($repo->getDefault('__phlix_no_such_settings_fixture__.value'));
    }

    public function testGetOverrideReturnsNullWhenRowIsNotAnArray(): void
    {
        $db = $this->createMock(Connection::class);
        // A result list whose first element is NOT an array (e.g. a scalar).
        $db->method('query')->willReturn(['not-an-array']);

        $repo = new SettingsRepository($db, self::FIXTURE_DIR);

        // Covers SettingsRepository.php line 108 (`!is_array($row)` → null).
        $this->assertNull($repo->getOverride('hwaccel.enabled'));
    }

    public function testGetAllOverridesReturnsEmptyWhenResultIsNotAnArray(): void
    {
        $db = $this->createMock(Connection::class);
        // SELECT-all returning a non-array (Connection returns false on error).
        $db->method('query')->willReturn(false);

        $repo = new SettingsRepository($db, self::FIXTURE_DIR);

        // Covers SettingsRepository.php line 135 (`!is_array($rows)` → []).
        $this->assertSame([], $repo->getAllOverrides());
    }

    public function testGetAllOverridesSkipsMalformedRows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            'scalar-not-a-row',                                  // not an array → skipped
            ['setting_value' => 'x', 'value_type' => 'string'], // missing setting_key → skipped
            ['setting_key' => 42, 'setting_value' => 'x', 'value_type' => 'string'], // non-string key → skipped
            ['setting_key' => 'tmdb.api_key', 'setting_value' => 'good', 'value_type' => 'string'],
        ]);

        $repo = new SettingsRepository($db, self::FIXTURE_DIR);
        $all = $repo->getAllOverrides();

        // Covers SettingsRepository.php line 140 (the `continue` for bad rows):
        // only the single well-formed row survives.
        $this->assertSame(['tmdb.api_key' => 'good'], $all);
    }

    public function testGetDefaultReturnsNullWhenFirstSegmentFailsTraversalJail(): void
    {
        $db = $this->createMock(Connection::class);
        $repo = new SettingsRepository($db, self::FIXTURE_DIR);

        // First segment contains a slash → fails `^[A-Za-z0-9_-]+$`, so
        // loadConfig() short-circuits to null. Covers SettingsRepository.php
        // line 281 (the regex-jail null assignment). Note: a leading `../`
        // key produces an empty first segment and bails earlier (line 193),
        // so we use a non-empty-but-invalid first segment here.
        $this->assertNull($repo->getDefault('foo/bar.key'));
        $this->assertNull($repo->getDefault('..%2f.key'));
    }

    public function testGetOverrideDecodesIntAndFloatTypes(): void
    {
        // int decode arm (line 325).
        $dbInt = $this->createMock(Connection::class);
        $dbInt->method('query')->willReturn([
            ['setting_value' => '42', 'value_type' => 'int'],
        ]);
        $repoInt = new SettingsRepository($dbInt, self::FIXTURE_DIR);
        $intOverride = $repoInt->getOverride('hwaccel.probe_timeout');
        $this->assertIsArray($intOverride);
        $this->assertSame(42, $intOverride['value']);

        // float decode arm (line 326).
        $dbFloat = $this->createMock(Connection::class);
        $dbFloat->method('query')->willReturn([
            ['setting_value' => '0.75', 'value_type' => 'float'],
        ]);
        $repoFloat = new SettingsRepository($dbFloat, self::FIXTURE_DIR);
        $floatOverride = $repoFloat->getOverride('marker_detection.similarity_threshold');
        $this->assertIsArray($floatOverride);
        $this->assertSame(0.75, $floatOverride['value']);
    }

    public function testGetOverrideDecodesJsonAndStringTypes(): void
    {
        // json decode arm (line 327).
        $dbJson = $this->createMock(Connection::class);
        $dbJson->method('query')->willReturn([
            ['setting_value' => '{"font_size":24}', 'value_type' => 'json'],
        ]);
        $repoJson = new SettingsRepository($dbJson, self::FIXTURE_DIR);
        $jsonOverride = $repoJson->getOverride('subtitles.style');
        $this->assertIsArray($jsonOverride);
        $this->assertSame(['font_size' => 24], $jsonOverride['value']);

        // string / default decode arm (line 328).
        $dbString = $this->createMock(Connection::class);
        $dbString->method('query')->willReturn([
            ['setting_value' => 'en-US', 'value_type' => 'string'],
        ]);
        $repoString = new SettingsRepository($dbString, self::FIXTURE_DIR);
        $stringOverride = $repoString->getOverride('subtitles.default_language');
        $this->assertIsArray($stringOverride);
        $this->assertSame('en-US', $stringOverride['value']);
    }
}
