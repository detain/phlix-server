<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Config\EffectiveConfig;
use Phlix\Server\Core\Application;

/**
 * Consequence tests for two shipped-but-inert settings that have been repaired.
 *
 * ## The defect class
 *
 * Both keys resolved to a real `config/*.php` default, so
 * `SettingsDefaultResolvabilityTest` passed them and always would. Neither
 * override could reach its consumer:
 *
 *  - **`port-forward.port_forwarding.upnp_enabled`** — `config/server.php` never
 *    `require`s `config/port-forward.php`, so `NetworkServicesProvider`'s
 *    `$appConfig['port_forwarding']` was permanently absent and every value took
 *    its `??` literal. `EffectiveConfig::overlayAppConfig()` could not help
 *    either: it refuses to create keys that do not already exist.
 *  - **`lastfm.api_key` / `shared_secret` / `enabled`** — the overrides were
 *    applied only inside `LastfmController::buildOverrideAwareConfig()`, whose
 *    result fed exactly one thing, the `api_key_set` boolean in `status()`. The
 *    `LastfmConfig` gating the OAuth flow and the `LastfmApi` signing the token
 *    exchange were both built from an override-blind raw `include`.
 *
 * Per plan §4 rule 9 the assertions below check the OBSERVABLE EFFECT of an
 * override — never that a key exists or a flag is projected. Each was
 * mutation-verified by reverting the repair and confirming the test goes red.
 */
class InertSettingRepairsTest extends TestCase
{
    protected function tearDown(): void
    {
        EffectiveConfig::reset();
        parent::tearDown();
    }

    /**
     * Half one of two: the override must be OBSERVABLE at the path the provider
     * now reads.
     *
     * Bootstraps EffectiveConfig with an override flipping `upnp_enabled` to
     * false and asserts `EffectiveConfig::file('port-forward')` reflects it.
     *
     * **Scope, stated honestly.** This proves the value resolves and overlays; it
     * does NOT by itself prove `NetworkServicesProvider` consumes that path —
     * mutation testing confirmed it stays green when the provider is reverted to
     * the broken `$appConfig` read. The read path is pinned by the companion test
     * {@see self::test_network_provider_does_not_read_port_forwarding_from_app_config()},
     * and the two are only meaningful together. Recorded rather than papered over,
     * because an overclaiming docblock is how "resolvable" got mistaken for
     * "consumed" in the first place.
     */
    public function test_port_forward_override_reaches_the_network_provider(): void
    {
        $configDir = __DIR__ . '/../../../config';

        // Baseline: no overrides -> the shipped default (true).
        EffectiveConfig::bootstrap($this->emptyOverrideDb(), null, $configDir);
        $baseline = EffectiveConfig::file('port-forward');
        $baselinePf = $baseline['port_forwarding'] ?? null;
        $this->assertIsArray($baselinePf);
        $this->assertTrue(
            $baselinePf['upnp_enabled'] ?? null,
            'Shipped default for upnp_enabled should be true.'
        );

        // With an override -> false must be observed at the SAME path the
        // provider now reads.
        EffectiveConfig::bootstrap(
            $this->overrideDb([
                ['setting_key' => 'port-forward.port_forwarding.upnp_enabled',
                 'setting_value' => '0', 'value_type' => 'bool'],
            ]),
            null,
            $configDir
        );

        $overlaid = EffectiveConfig::file('port-forward');
        $overlaidPf = $overlaid['port_forwarding'] ?? null;
        $this->assertIsArray($overlaidPf);
        $this->assertFalse(
            $overlaidPf['upnp_enabled'] ?? null,
            'A saved upnp_enabled=false override must be visible via '
            . 'EffectiveConfig::file("port-forward"), which is what '
            . 'NetworkServicesProvider now reads. If this is true, the setting is '
            . 'inert again.'
        );
    }

    /**
     * CONSEQUENCE: the provider must not read the boot `$appConfig` path.
     *
     * The specific regression is subtle enough that the value test above could
     * be satisfied by accident, so pin the read path itself: `$appConfig` must
     * no longer be the source for port-forwarding.
     */
    public function test_network_provider_does_not_read_port_forwarding_from_app_config(): void
    {
        $raw = file_get_contents(
            __DIR__ . '/../../../src/Common/Container/Providers/NetworkServicesProvider.php'
        );
        $this->assertIsString($raw);

        // Strip comments before asserting. The repair's own explanatory comment
        // quotes the offending expression to document why it was removed, which
        // would otherwise trip this assertion — the same false-signal trap that
        // a comment-matching assertion in ApplicationBackgroundTimersTest hit.
        $src = $this->stripComments($raw);

        $this->assertStringNotContainsString(
            "\$appConfig['port_forwarding']",
            $src,
            'NetworkServicesProvider must not read $appConfig[\'port_forwarding\'] — '
            . 'config/server.php does not compose config/port-forward.php, so that '
            . 'key is permanently absent and every setting silently takes its '
            . 'literal fallback. Read EffectiveConfig::file(\'port-forward\') instead.'
        );
        $this->assertStringContainsString("EffectiveConfig::file('port-forward')", $src);
    }

    /**
     * CONSEQUENCE: a saved `lastfm.api_key` must change the credential the
     * Last.fm objects are constructed with — not merely a status boolean.
     *
     * Exercises the real `applyLastfmOverrides()` against a SettingsRepository
     * that reports an override, and asserts the returned array carries the DB
     * value. Both `LastfmConfig` and `LastfmApi` are built from this array, so
     * this is the value that reaches the wire.
     *
     * Mutation-verified: removing the `applyLastfmOverrides()` call from
     * `getLastfmController()` fails the companion source assertion below, and
     * neutering the method body fails this one.
     */
    public function test_lastfm_override_reaches_the_constructed_credentials(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getOverride')->willReturnCallback(
            static fn (string $key): ?array => match ($key) {
                'lastfm.api_key'       => ['value' => 'DB_KEY'],
                'lastfm.shared_secret' => ['value' => 'DB_SECRET'],
                'lastfm.enabled'       => ['value' => true],
                default                => null,
            }
        );

        $ref = new \ReflectionClass(Application::class);
        /** @var Application $app */
        $app = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('applyLastfmOverrides');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($app, [
            'api_key'       => 'FILE_KEY',
            'shared_secret' => 'FILE_SECRET',
            'enabled'       => false,
        ], $settings);

        $this->assertSame('DB_KEY', $result['api_key'], 'Saved API key must win over the file value.');
        $this->assertSame('DB_SECRET', $result['shared_secret']);
        $this->assertTrue($result['enabled']);
    }

    /**
     * An empty or blank override must NOT clobber the file value — otherwise
     * clearing a field in the UI would silently break a working install.
     */
    public function test_blank_lastfm_override_does_not_clobber_the_file_value(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getOverride')->willReturn(['value' => '']);

        $ref = new \ReflectionClass(Application::class);
        /** @var Application $app */
        $app = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('applyLastfmOverrides');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($app, ['api_key' => 'FILE_KEY'], $settings);

        $this->assertSame('FILE_KEY', $result['api_key']);
    }

    /**
     * A settings-store failure must degrade to the file config, never propagate.
     */
    public function test_lastfm_override_survives_a_settings_store_failure(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getOverride')->willThrowException(new \RuntimeException('db down'));

        $ref = new \ReflectionClass(Application::class);
        /** @var Application $app */
        $app = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('applyLastfmOverrides');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($app, ['api_key' => 'FILE_KEY'], $settings);

        $this->assertSame('FILE_KEY', $result['api_key']);
    }

    /**
     * CONSEQUENCE: the overlay must be applied BEFORE LastfmConfig/LastfmApi are
     * constructed, at both wiring sites.
     *
     * Applying it afterwards (or only in the controller, as before) leaves the
     * objects that actually talk to Last.fm on the file credentials.
     */
    public function test_both_lastfm_wiring_sites_apply_the_overlay(): void
    {
        $src = file_get_contents(__DIR__ . '/../../../src/Server/Core/Application.php');
        $this->assertIsString($src);

        $this->assertSame(
            2,
            substr_count($src, '$this->applyLastfmOverrides('),
            'Both Last.fm wiring sites (getLastfmController() and loadLastfmRoutes()) '
            . 'must overlay the admin settings before constructing LastfmConfig and '
            . 'LastfmApi; otherwise the OAuth flow authenticates with the file key '
            . 'while the UI reports the saved one as active.'
        );
    }

    /**
     * Return $source with all comments removed, so a source-level assertion
     * cannot be satisfied (or defeated) by prose in a docblock.
     */
    private function stripComments(string $source): string
    {
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

    /**
     * @param list<array<string, string>> $rows
     */
    private function overrideDb(array $rows): \Workerman\MySQL\Connection
    {
        $db = $this->createMock(\Workerman\MySQL\Connection::class);
        $db->method('query')->willReturn($rows);

        return $db;
    }

    private function emptyOverrideDb(): \Workerman\MySQL\Connection
    {
        return $this->overrideDb([]);
    }
}
