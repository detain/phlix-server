<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license MIT
 */

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Admin\SettingsRepository;
use Phlix\Plugins\Scrobbler\Trakt\TraktOperatorConfig;
use PHPUnit\Framework\TestCase;

/**
 * Guards for the shared Trakt operator-credential loader.
 *
 * The defect this class exists to fix: `TraktPlugin::loadConfig()` was a raw
 * `include` (read-path class (d) in `plan_settings.md` §0.4) while
 * `TraktOAuthController::loadConfig()` overlaid `server_settings`. An operator
 * who saved their credentials in the admin Settings page could therefore
 * complete the OAuth connect flow and then have history sync silently never
 * run.
 *
 * These tests assert the OVERLAY ACTUALLY HAPPENS and that the precedence
 * rules hold — not merely that the method is callable.
 */
final class TraktOperatorConfigTest extends TestCase
{
    /** @var list<string> Temp files to unlink in tearDown. */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    /**
     * Write a throwaway PHP config file returning $values.
     *
     * @param array<string, mixed> $values
     */
    private function writeConfig(array $values): string
    {
        $path = sys_get_temp_dir() . '/trakt_cfg_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($path, '<?php return ' . var_export($values, true) . ';');
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Build a SettingsRepository stub whose getOverride() answers from $rows.
     *
     * @param array<string, mixed> $rows Keyed by dotted setting key.
     */
    private function settingsWith(array $rows): SettingsRepository
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getOverride')->willReturnCallback(
            static function (string $key) use ($rows): ?array {
                return array_key_exists($key, $rows) ? ['value' => $rows[$key]] : null;
            }
        );

        return $repo;
    }

    public function testOverrideReplacesTheFileValue(): void
    {
        $path = $this->writeConfig([
            'client_id'     => 'from-file',
            'client_secret' => 'secret-from-file',
            'redirect_uri'  => 'https://file.example/cb',
        ]);

        $config = TraktOperatorConfig::load($path, $this->settingsWith([
            'trakt.client_id'     => 'from-db',
            'trakt.client_secret' => 'secret-from-db',
            'trakt.redirect_uri'  => 'https://db.example/cb',
        ]));

        self::assertSame('from-db', $config['client_id']);
        self::assertSame('secret-from-db', $config['client_secret']);
        self::assertSame('https://db.example/cb', $config['redirect_uri']);
    }

    public function testOverrideSuppliesCredentialsThatTheFileLacksEntirely(): void
    {
        // The real-world shape: config/scrobblers/trakt.php defaults every
        // credential to '' because the env vars are unset, and the operator
        // typed them into the admin Settings page instead. Before the fix this
        // is exactly the case where the plugin saw nothing.
        $path = $this->writeConfig(['client_id' => '', 'client_secret' => '']);

        $config = TraktOperatorConfig::load($path, $this->settingsWith([
            'trakt.client_id'     => 'admin-entered',
            'trakt.client_secret' => 'admin-secret',
        ]));

        self::assertSame('admin-entered', $config['client_id']);
        self::assertSame('admin-secret', $config['client_secret']);
    }

    public function testNullRepositoryLeavesTheFileValuesUntouched(): void
    {
        $path = $this->writeConfig(['client_id' => 'from-file', 'client_secret' => 's']);

        $config = TraktOperatorConfig::load($path, null);

        self::assertSame('from-file', $config['client_id']);
        self::assertSame('s', $config['client_secret']);
    }

    public function testBlankOverrideDoesNotBlankAnEnvSuppliedCredential(): void
    {
        // Clearing the field in the admin UI must fall back to the environment,
        // not wipe a working credential. This is why the loader uses
        // getOverride() and requires a non-empty string.
        $path = $this->writeConfig(['client_id' => 'from-env', 'client_secret' => 'env-secret']);

        $config = TraktOperatorConfig::load($path, $this->settingsWith([
            'trakt.client_id'     => '',
            'trakt.client_secret' => 'env-secret',
        ]));

        self::assertSame('from-env', $config['client_id']);
    }

    public function testNonStringOverrideIsIgnored(): void
    {
        $path = $this->writeConfig(['client_id' => 'from-file']);

        $config = TraktOperatorConfig::load($path, $this->settingsWith([
            'trakt.client_id' => 12345,
        ]));

        self::assertSame('from-file', $config['client_id']);
    }

    public function testMissingConfigFileStillReceivesOverrides(): void
    {
        $config = TraktOperatorConfig::load('/nonexistent/trakt.php', $this->settingsWith([
            'trakt.client_id' => 'only-in-db',
        ]));

        self::assertSame('only-in-db', $config['client_id']);
    }

    public function testSyncIntervalIsNotAServerSettingKey(): void
    {
        // sync_interval lives in plugins.settings_json, not server_settings.
        // Pinning the map prevents a future pass from "helpfully" adding a key
        // that has no schema entry and would therefore never resolve.
        self::assertSame(
            ['trakt.client_id', 'trakt.client_secret', 'trakt.redirect_uri'],
            array_keys(TraktOperatorConfig::SETTING_KEY_MAP)
        );
    }
}
