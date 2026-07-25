<?php

/**
 * Phlix media server component: Ldap.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Ldap;

use Phlix\Plugins\Contract\LifecycleInterface;
use Phlix\Plugins\PluginDbSettings;
use Phlix\Plugins\Repository\PluginSettingsStore;
use Phlix\Auth\AuthProviderRegistry;
use Psr\Container\ContainerInterface;

/**
 * LDAP authentication provider plugin entry point.
 *
 * S48: provider configuration now persists in the DB-backed `plugin_settings`
 * store ({@see PluginDbSettings}) instead of a hand-rolled `settings.json`, with
 * the same no-DB file fallback + one-time settings.json import as OIDC.
 *
 * @package Phlix\Plugins\Ldap
 * @since 0.11.0
 */
final class Plugin implements LifecycleInterface
{
    use PluginDbSettings;

    /** The `plugin_settings.plugin_name` / registry family key. */
    public const string PLUGIN_NAME = 'ldap';

    private static ?string $pluginDirectory = null;

    public function __construct(?PluginSettingsStore $store = null)
    {
        $this->settingsStore = $store;
    }

    public static function setPluginDirectory(string $directory): void
    {
        self::$pluginDirectory = $directory;
    }

    public static function getPluginDirectory(): string
    {
        if (self::$pluginDirectory !== null) {
            return self::$pluginDirectory;
        }
        return __DIR__;
    }

    protected function settingsStoreKey(): string
    {
        return self::PLUGIN_NAME;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function filterSettings(array $settings): array
    {
        $filtered = [];
        foreach ($settings as $key => $value) {
            if (is_string($value) || is_int($value) || is_bool($value)) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    public function onEnable(ContainerInterface $container): void
    {
        $settings = $this->filterSettings($this->getSettings());

        $host = is_string($settings['host'] ?? null) ? $settings['host'] : '';
        $port = is_numeric($settings['port'] ?? null) ? (int) $settings['port'] : 389;
        $ssl = isset($settings['ssl']) && is_bool($settings['ssl']) ? $settings['ssl'] : false;
        $baseDn = is_string($settings['base_dn'] ?? null) ? $settings['base_dn'] : '';
        $bindDn = is_string($settings['bind_dn'] ?? null) ? $settings['bind_dn'] : null;
        $bindPw = is_string($settings['bind_pw'] ?? null) ? $settings['bind_pw'] : null;
        $userFilter = is_string($settings['user_filter'] ?? null) ? $settings['user_filter'] : '(uid={{username}})';
        $adminGroup = is_string($settings['admin_group'] ?? null) ? $settings['admin_group'] : null;

        $ldapProvider = new LdapProvider(
            host: $host,
            port: $port,
            ssl: $ssl,
            baseDn: $baseDn,
            bindDn: $bindDn,
            bindPw: $bindPw,
            userFilter: $userFilter,
            adminGroup: $adminGroup,
        );

        /** @var AuthProviderRegistry */
        $registry = $container->get(AuthProviderRegistry::class);
        $registry->registerProvider($ldapProvider);
    }

    public function onDisable(): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function subscribedEvents(): array
    {
        return [];
    }

    /**
     * Legacy on-disk settings.json reader (no-DB fallback + one-time import
     * source). {@see PluginDbSettings::getSettings()}.
     *
     * @return array<string, mixed>
     */
    protected function loadFileSettings(): array
    {
        $settingsFile = self::getPluginDirectory() . '/settings.json';
        if (!is_file($settingsFile)) {
            return [];
        }
        $content = file_get_contents($settingsFile);
        if ($content === false) {
            return [];
        }
        /** @var mixed $decoded */
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Legacy on-disk settings.json writer (no-DB fallback only).
     *
     * @param array<string, mixed> $settings
     */
    protected function persistFileSettings(array $settings): void
    {
        $settingsFile = self::getPluginDirectory() . '/settings.json';
        file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function maskSecrets(array $settings): array
    {
        $masked = $settings;
        unset($masked['bind_pw']);
        return $masked;
    }
}
