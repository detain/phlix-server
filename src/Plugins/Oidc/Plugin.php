<?php

/**
 * Phlix media server component: Oidc.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Oidc;

use Phlix\Plugins\Contract\LifecycleInterface;
use Phlix\Plugins\PluginDbSettings;
use Phlix\Plugins\Repository\PluginSettingsStore;
use Phlix\Auth\AuthProviderRegistry;
use Psr\Container\ContainerInterface;

/**
 * OIDC/OAuth2 authentication provider plugin entry point.
 *
 * Implements LifecycleInterface to integrate with the Phlix plugin
 * system. On enable, registers the OidcProvider with the AuthProviderRegistry.
 *
 * S48: provider configuration now persists in the DB-backed `plugin_settings`
 * store ({@see PluginDbSettings}) instead of a hand-rolled `settings.json`. When
 * no DB store is injected (unit tests, no-DB contexts) it transparently falls
 * back to the legacy file store, and the first DB-backed read imports any
 * existing settings.json so no operator loses their configured OIDC settings.
 *
 * @package Phlix\Plugins\Oidc
 * @since 0.11.0
 */
final class Plugin implements LifecycleInterface
{
    use PluginDbSettings;

    /** The `plugin_settings.plugin_name` / registry family key. */
    public const string PLUGIN_NAME = 'oidc';

    private static ?string $pluginDirectory = null;

    /** @var array<string, mixed>|null Cached file settings to avoid repeated file reads */
    private static ?array $cachedSettings = null;

    /** @var int|null Timestamp when cached settings were loaded */
    private static ?int $cacheTimestamp = null;

    /** @var int Cache TTL in seconds (60 seconds) */
    private const CACHE_TTL = 60;

    public function __construct(?PluginSettingsStore $store = null)
    {
        $this->settingsStore = $store;
    }

    public static function setPluginDirectory(string $directory): void
    {
        self::$pluginDirectory = $directory;

        // The cached settings were loaded from whatever directory was active
        // at the time; if the directory changes (e.g. tests pointing the
        // plugin at a fresh temp dir per case, or an operator relocating the
        // plugin's data dir at runtime) the old cache would otherwise leak
        // into the new directory's context. Invalidate eagerly so the next
        // loadFileSettings() call re-reads from the new location.
        self::$cachedSettings = null;
        self::$cacheTimestamp = null;
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
        $settings = $this->filterSettings($this->getSettings());

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
     * @return array<class-string, callable|string>
     */
    public function subscribedEvents(): array
    {
        return [];
    }

    /**
     * Legacy on-disk settings.json reader (no-DB fallback + one-time import
     * source). Retains the 60s memory cache for the file path.
     * {@see PluginDbSettings::getSettings()}.
     *
     * @return array<string, mixed>
     */
    protected function loadFileSettings(): array
    {
        $now = time();

        // Return cached settings if still valid
        if (
            self::$cachedSettings !== null
            && self::$cacheTimestamp !== null
            && ($now - self::$cacheTimestamp) < self::CACHE_TTL
        ) {
            return self::$cachedSettings;
        }

        $settingsFile = self::getPluginDirectory() . '/settings.json';
        if (!is_file($settingsFile)) {
            self::$cachedSettings = [];
            self::$cacheTimestamp = $now;
            return [];
        }
        $content = file_get_contents($settingsFile);
        if ($content === false) {
            self::$cachedSettings = [];
            self::$cacheTimestamp = $now;
            return [];
        }
        /** @var mixed $decoded */
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            self::$cachedSettings = [];
            self::$cacheTimestamp = $now;
            return [];
        }
        /** @var array<string, mixed> $decoded */
        self::$cachedSettings = $decoded;
        self::$cacheTimestamp = $now;
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

        // Invalidate cache so next loadFileSettings() reads fresh data
        self::$cachedSettings = null;
        self::$cacheTimestamp = null;
    }
}
