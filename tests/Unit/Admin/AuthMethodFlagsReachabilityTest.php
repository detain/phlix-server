<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use Phlix\Admin\SettingsRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workerman\MySQL\Connection;

/**
 * F7 config-side reachability: every `auth.<method>.enabled` schema key must
 * resolve through SettingsRepository::getDefault() over the REAL config
 * directory — a dotted key with no matching config subtree silently defaults
 * to null, which is exactly the 213fce9d near-miss class this pins against.
 *
 * The schema declarations themselves live in phlix-shared (pinned there);
 * this test owns the server half: config/auth.php carries the defaults, and
 * nothing collides with the boot overlay trees in config/server.php.
 */
final class AuthMethodFlagsReachabilityTest extends TestCase
{
    private function repositoryOverRealConfigDir(): SettingsRepository
    {
        /** @var Connection $db */
        $db = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();

        return new SettingsRepository($db, dirname(__DIR__, 3) . '/config');
    }

    /**
     * @return array<string, array{0: string, 1: bool}> key => expected default
     */
    public static function toggleProvider(): array
    {
        return [
            'password default-ON'  => ['auth.password.enabled', true],
            'webauthn default-ON'  => ['auth.webauthn.enabled', true],
            'oidc default-OFF'     => ['auth.oidc.enabled', false],
            'ldap default-OFF'     => ['auth.ldap.enabled', false],
            'github default-OFF'   => ['auth.github.enabled', false],
        ];
    }

    /**
     * @dataProvider toggleProvider
     */
    public function test_toggle_key_resolves_through_real_config(string $key, bool $expected): void
    {
        $repo = $this->repositoryOverRealConfigDir();

        $this->assertTrue($repo->hasDefault($key), "{$key} must resolve via config defaults");
        $this->assertSame($expected, $repo->getDefault($key));
    }

    /**
     * The webauthn toggle walks auth.php['webauthn']['enabled'] — a NEW
     * subtree. The boot overlay consumes a top-level $appConfig['webauthn']
     * (rp_id etc.) supplied from the user environment, NOT config/server.php;
     * if server.php ever grows a top-level 'webauthn' (or 'auth') key,
     * EffectiveConfig layering changes and this near-miss law trips loud.
     */
    public function test_server_php_carries_no_collision_trees(): void
    {
        $serverConfig = require dirname(__DIR__, 3) . '/config/server.php';

        $this->assertIsArray($serverConfig);
        $this->assertArrayNotHasKey('auth', $serverConfig, 'config/server.php must not grow a top-level auth tree');
        $this->assertArrayNotHasKey(
            'webauthn',
            $serverConfig,
            'config/server.php must not grow a top-level webauthn tree',
        );
    }

    public function test_auth_php_subtree_shapes(): void
    {
        $auth = require dirname(__DIR__, 3) . '/config/auth.php';

        // password: 'enabled' was ADDED to an existing subtree (min_length
        // keeps living there) — pin both so a refactor cannot drop one arm.
        $this->assertTrue($auth['password']['enabled']);
        $this->assertArrayHasKey('min_length', $auth['password']);

        $this->assertTrue($auth['webauthn']['enabled']);
        $this->assertFalse($auth['oidc']['enabled']);
        $this->assertFalse($auth['ldap']['enabled']);
        $this->assertFalse($auth['github']['enabled']);
    }
}
