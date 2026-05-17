<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins;

use DateTimeImmutable;
use Phlex\Plugins\InstalledPlugin;
use Phlex\Plugins\Manifest;
use Phlex\Plugins\SettingsMasker;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Plugins\SettingsMasker
 */
final class SettingsMaskerTest extends TestCase
{
    public function test_mask_replaces_secret_values_with_placeholder(): void
    {
        $plugin = $this->plugin(
            ['api_key' => 'topsecret', 'public_flag' => true],
            [
                'api_key'     => ['type' => 'string', 'secret' => true],
                'public_flag' => ['type' => 'bool'],
            ],
        );

        $masked = SettingsMasker::mask($plugin);

        $this->assertSame(SettingsMasker::MASK, $masked['api_key']);
        $this->assertTrue($masked['public_flag']);
    }

    public function test_mask_leaves_non_secret_values_alone(): void
    {
        $plugin = $this->plugin(
            ['url' => 'https://example.test', 'verbose' => false],
            [
                'url'     => ['type' => 'string'],
                'verbose' => ['type' => 'bool', 'secret' => false],
            ],
        );

        $masked = SettingsMasker::mask($plugin);

        $this->assertSame('https://example.test', $masked['url']);
        $this->assertFalse($masked['verbose']);
    }

    public function test_mask_skips_keys_with_no_persisted_value(): void
    {
        // Manifest declares a secret key but the plugin has no value
        // stored for it yet; the masker must not invent a key.
        $plugin = $this->plugin(
            [],
            ['api_key' => ['type' => 'string', 'secret' => true]],
        );

        $masked = SettingsMasker::mask($plugin);

        $this->assertArrayNotHasKey('api_key', $masked);
    }

    public function test_view_returns_rows_for_every_manifest_setting(): void
    {
        $plugin = $this->plugin(
            ['api_key' => 'topsecret', 'verbose' => true],
            [
                'api_key' => ['type' => 'string', 'secret' => true],
                'verbose' => ['type' => 'bool'],
                'unset'   => ['type' => 'int'],
            ],
        );

        $rows = SettingsMasker::view($plugin);

        $this->assertCount(3, $rows);
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['key']] = $row;
        }

        $this->assertSame(SettingsMasker::MASK, $byKey['api_key']['value']);
        $this->assertTrue($byKey['api_key']['secret']);
        $this->assertSame('string', $byKey['api_key']['type']);

        $this->assertTrue($byKey['verbose']['value']);
        $this->assertFalse($byKey['verbose']['secret']);

        // Missing values surface as null, not masked.
        $this->assertNull($byKey['unset']['value']);
        $this->assertFalse($byKey['unset']['secret']);
        $this->assertSame('int', $byKey['unset']['type']);
    }

    public function test_view_does_not_mask_null_secret_values(): void
    {
        // Spec: only stored secrets are redacted; missing-secret keys
        // surface as null in the row so the UI can show "(not set)".
        $plugin = $this->plugin(
            [],
            ['api_key' => ['type' => 'string', 'secret' => true]],
        );

        $rows = SettingsMasker::view($plugin);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['value']);
        $this->assertTrue($rows[0]['secret']);
    }

    public function test_view_falls_back_to_mixed_when_type_missing(): void
    {
        $plugin = $this->plugin(
            ['x' => 1],
            ['x' => []], // no type at all
        );

        $rows = SettingsMasker::view($plugin);

        $this->assertSame('mixed', $rows[0]['type']);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, array<string, mixed>> $manifestSettings
     */
    private function plugin(array $values, array $manifestSettings): InstalledPlugin
    {
        return new InstalledPlugin(
            id: 'id-test',
            manifest: Manifest::fromArray([
                'name'                     => 'phlex-plugin-mask',
                'version'                  => '1.0.0',
                'phlex_min_server_version' => '0.10.0',
                'type'                     => 'notifier',
                'entry'                    => 'X\\Y',
                'settings'                 => $manifestSettings,
            ]),
            enabled: true,
            installedAt: new DateTimeImmutable('2024-01-01 00:00:00'),
            settings: $values,
            directory: '/tmp/mask-test',
        );
    }
}
