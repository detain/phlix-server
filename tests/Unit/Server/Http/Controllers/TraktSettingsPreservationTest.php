<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\Repository\PluginRepository;
use Phlix\Server\Integrations\Trakt\TraktSettings;
use Phlix\Server\Http\Controllers\TraktOAuthController;
use Phlix\Server\Http\Request;

/**
 * Regression tests: the Trakt OAuth flow must not DESTROY settings it does not own.
 *
 * ## The defect these pin
 *
 * `PluginRepository::updateSettings()` is a wholesale replace
 * (`UPDATE plugins SET settings_json = ?`), and `TraktSettings` models only 7 of
 * the manifest's 8 declared keys — it has **no `enabled` field at all**. Both
 * write paths in `TraktOAuthController` handed their own array straight to that
 * replace, so `enabled` was silently deleted:
 *
 *  - connect: completing the OAuth flow wiped it;
 *  - disconnect: wiped it too, under a comment claiming it "preserve[s] user
 *    preferences".
 *
 * `start.php:387` gates the pull-sync timer on `settings['enabled'] ?? false`, so
 * losing the key silently disabled Trakt sync until an admin noticed and re-toggled
 * it — and re-authorising wiped it again.
 *
 * **This was live on production**: the Trakt row had valid OAuth tokens, a
 * username, `sync_enabled: true` — and no `enabled` key, so every boot logged
 * "Trakt pull-sync timer not armed … master_enabled: false".
 *
 * Both tests below were mutation-verified: reverting either `array_merge(...)` to
 * the bare array turns the corresponding test red.
 */
final class TraktSettingsPreservationTest extends TestCase
{
    /**
     * CONSEQUENCE: disconnecting keeps the master `enabled` toggle.
     *
     * Disconnect is the drivable half of the pair — it needs no token exchange —
     * and it exercises the identical replace-vs-merge defect.
     *
     * Mutation-verified: restoring
     * `updateSettings(self::TRAKT_PLUGIN_NAME, $clearedSettings)` fails this.
     */
    public function test_disconnect_preserves_the_master_enabled_toggle(): void
    {
        $stored = [
            'enabled'               => true,
            'access_token'          => 'tok',
            'refresh_token'         => 'ref',
            'expires_at'            => 1784631711,
            'username'              => 'detain',
            'sync_enabled'          => true,
            'sync_interval_minutes' => 30,
            'scrobble_enabled'      => true,
        ];

        $installed = new InstalledPlugin(
            id: 'trakt-1',
            manifest: Manifest::fromArray([
                'name'                     => 'phlix-plugin-trakt',
                'version'                  => '1.3.0',
                'phlix_min_server_version' => '1.2.0',
                'type'                     => 'scrobbler',
                'entry'                    => 'X\\Y',
                'settings'                 => [],
            ]),
            enabled: true,
            installedAt: new \DateTimeImmutable('2026-07-12 10:04:43'),
            settings: $stored,
            directory: '/tmp/trakt-test',
        );

        $captured = null;
        $plugins = $this->createMock(PluginRepository::class);
        $plugins->method('findByName')->willReturn($installed);
        $plugins->method('updateSettings')
            ->willReturnCallback(function (string $name, array $settings) use (&$captured): void {
                $captured = $settings;
            });

        $controller = new TraktOAuthController(plugins: $plugins);
        $controller->disconnect(new Request(), []);

        // Deliberately an assertion, NOT markTestSkipped(): if disconnect() ever
        // stops writing settings at all, that is a regression to FAIL on, not to
        // quietly skip. A skip here would read as "green" forever.
        self::assertIsArray($captured, 'disconnect() must persist the cleared settings.');

        self::assertArrayHasKey(
            'enabled',
            $captured,
            'Disconnecting Trakt must not DELETE the master enable toggle: '
            . 'PluginRepository::updateSettings() replaces the whole settings_json, '
            . 'and start.php gates the sync timer on `enabled`.'
        );
        self::assertTrue($captured['enabled']);

        // The tokens must still actually be cleared — this is a disconnect.
        self::assertNull($captured['access_token']);
        self::assertNull($captured['refresh_token']);
    }

    /**
     * CONSEQUENCE: the merge is PROVABLY necessary, not defensive habit.
     *
     * This is the test that explains the bug rather than just catching it: the DTO
     * cannot round-trip the full settings map, so any write path that hands its
     * output to a wholesale replace loses whatever it omits. If someone later adds
     * `enabled` to `TraktSettings`, this test fails loudly and points them at the
     * merge, which is the correct outcome — the merge stays right either way.
     *
     * Mutation-verified: adding an `'enabled' => ...` entry to
     * `TraktSettings::toArray()` fails this test.
     */
    public function test_the_dto_cannot_round_trip_every_manifest_key(): void
    {
        $manifestPath = '/home/sites/phlix/phlix-plugin-trakt/plugin.json';
        if (!is_file($manifestPath)) {
            self::markTestSkipped('trakt manifest not available in this checkout');
        }

        /** @var array{settings: array<string, mixed>} $manifest */
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifestKeys = array_keys($manifest['settings']);

        $dtoKeys = array_keys((new TraktSettings())->toArray());

        $unmodelled = array_values(array_diff($manifestKeys, $dtoKeys));

        self::assertContains(
            'enabled',
            $unmodelled,
            'TraktSettings still does not model `enabled`, so every write path MUST '
            . 'merge over the stored settings rather than replace them.'
        );
    }
}
