<?php

/**
 * Phlix media server component: Github.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Github;

use Phlix\Auth\AuthProviderRegistry;
use Phlix\Plugins\Contract\LifecycleInterface;
use Phlix\Plugins\PluginDbSettings;
use Phlix\Plugins\Repository\PluginSettingsStore;
use Psr\Container\ContainerInterface;

/**
 * GitHub OAuth2 authentication provider plugin entry point.
 *
 * A bundled provider (like {@see \Phlix\Plugins\Oidc\Plugin} /
 * {@see \Phlix\Plugins\Ldap\Plugin}): it is NOT installed through the catalog
 * `plugins` table but registered through {@see \Phlix\Auth\AuthProviderBootstrapper}
 * off the `auth.github.enabled` server flag. Its configuration
 * (client id/secret/scopes) persists in the DB-backed `plugin_settings` store
 * ({@see PluginDbSettings}) rather than a hand-rolled settings.json.
 *
 * @package Phlix\Plugins\Github
 * @since 0.102.0
 */
final class Plugin implements LifecycleInterface
{
    use PluginDbSettings;

    /** The `plugin_settings.plugin_name` / registry family key. */
    public const string PLUGIN_NAME = 'github';

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
        return self::$pluginDirectory ?? __DIR__;
    }

    protected function settingsStoreKey(): string
    {
        return self::PLUGIN_NAME;
    }

    /**
     * Bundled providers are registered via AuthProviderBootstrapper, so this hook
     * is not driven by the PluginLoader in production. It is kept for parity and
     * builds a provider from the DB-backed settings when invoked directly.
     */
    public function onEnable(ContainerInterface $container): void
    {
        $settings = $this->getSettings();

        $clientId = is_string($settings['client_id'] ?? null) ? $settings['client_id'] : '';
        $clientSecret = is_string($settings['client_secret'] ?? null) ? $settings['client_secret'] : '';
        $scopes = is_string($settings['scopes'] ?? null) && $settings['scopes'] !== ''
            ? $settings['scopes']
            : GithubOAuthProvider::DEFAULT_SCOPES;

        $provider = new GithubOAuthProvider($clientId, $clientSecret, $scopes);

        /** @var AuthProviderRegistry $registry */
        $registry = $container->get(AuthProviderRegistry::class);
        $registry->registerProvider($provider);
    }

    public function onDisable(): void
    {
    }

    /**
     * @return array<string, string>
     */
    public function subscribedEvents(): array
    {
        return [];
    }

    /**
     * Mask secret fields for read-back to the admin UI.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function maskSecrets(array $settings): array
    {
        $masked = $settings;
        unset($masked['client_secret']);

        return $masked;
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

        /** @var array<string, mixed> */
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
}
