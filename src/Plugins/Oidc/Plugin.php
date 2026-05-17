<?php

declare(strict_types=1);

namespace Phlex\Plugins\Oidc;

use Phlex\Plugins\Contract\LifecycleInterface;
use Phlex\Shared\Plugin\LifecycleInterface as SharedLifecycleInterface;
use Phlex\Auth\AuthProviderRegistry;
use Psr\Container\ContainerInterface;

/**
 * OIDC/OAuth2 authentication provider plugin entry point.
 *
 * Implements LifecycleInterface to integrate with the Phlex plugin
 * system. On enable, registers the OidcProvider with the AuthProviderRegistry.
 *
 * @package Phlex\Plugins\Oidc
 * @since 0.11.0
 */
final class Plugin implements LifecycleInterface
{
    private static ?string $pluginDirectory = null;

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

    /**
     * @param array<string, string> $settings
     * @return array<string, string>
     */
    private function filterSettings(array $settings): array
    {
        $filtered = [];
        foreach ($settings as $key => $value) {
            if (is_string($value)) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    public function onEnable(ContainerInterface $container): void
    {
        $settings = $this->filterSettings($this->loadSettings());

        $discovery = new DiscoveryDocument(
            $settings['provider_url'] ?? '',
        );

        $oidcProvider = new OidcProvider(
            discovery: $discovery,
            clientId: $settings['client_id'] ?? '',
            clientSecret: $settings['client_secret'] ?? '',
            scopes: $settings['scopes'] ?? 'openid profile email',
        );

        /** @var AuthProviderRegistry */
        $registry = $container->get(AuthProviderRegistry::class);
        $registry->registerProvider($oidcProvider);
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
     * @return array<string, string>
     */
    private function loadSettings(): array
    {
        $settingsFile = self::getPluginDirectory() . '/settings.json';
        if (!is_file($settingsFile)) {
            return [];
        }
        $content = file_get_contents($settingsFile);
        if ($content === false) {
            return [];
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }
        /** @var array<string, string> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, string> $settings
     */
    public function saveSettings(array $settings): void
    {
        $settingsFile = self::getPluginDirectory() . '/settings.json';
        file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, string>
     */
    public function getSettings(): array
    {
        return $this->loadSettings();
    }
}
