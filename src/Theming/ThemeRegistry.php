<?php

declare(strict_types=1);

namespace Phlix\Theming;

use Workerman\MySQL\Connection;

/**
 * Central registry for available UI themes.
 *
 * This class manages registration and retrieval of themes from two sources:
 * - Built-in themes (defined in config/themes.php)
 * - ui-theme plugins (registered during plugin bootstrap)
 *
 * The registry also handles per-user theme preferences, storing the
 * active theme ID in user_profiles.active_theme_id.
 *
 * @package Phlix\Theming
 * @since 0.14.0
 */
class ThemeRegistry
{
    /**
     * Default theme ID when no preference is set.
     */
    public const DEFAULT_THEME_ID = 'phlix-dark';

    /**
     * @var array<string, Theme> Map of theme ID to Theme instance
     */
    private array $themes = [];

    /**
     * @var Connection Database connection for persisting user preferences
     */
    private Connection $db;

    /**
     * @var string Path to the runtime themes directory (var/themes/)
     */
    private string $themesDir;

    /**
     * Creates a new ThemeRegistry instance.
     *
     * @param Connection $db Database connection for user preference persistence
     * @param string $themesDir Path to var/themes/ for extracted plugin themes
     */
    public function __construct(Connection $db, string $themesDir)
    {
        $this->db = $db;
        $this->themesDir = $themesDir;
    }

    /**
     * Registers a built-in theme with the registry.
     *
     * @param Theme $theme The theme to register
     * @return void
     */
    public function registerBuiltIn(Theme $theme): void
    {
        $this->themes[$theme->id] = $theme;
    }

    /**
     * Registers a theme from a plugin's manifest.
     *
     * This method extracts theme metadata directly from the plugin manifest
     * without instantiating the plugin's entry class. It reads the 'theme'
     * key from the manifest array.
     *
     * @param array{
     *     type: string,
     *     theme: array{
     *         id: string,
     *         name: string,
     *         css: string,
     *         js?: string,
     *         thumbnail?: string,
     *         dark?: bool
     *     }
     * } $pluginManifest The parsed plugin manifest array
     * @param string $pluginName The name of the plugin providing this theme
     * @return void
     *
     * @throws \InvalidArgumentException If manifest is missing required theme fields
     */
    public function registerFromPlugin(array $pluginManifest, string $pluginName): void
    {
        if (!isset($pluginManifest['theme'])) {
            throw new \InvalidArgumentException(
                "Plugin '{$pluginName}' declares type 'ui-theme' but has no 'theme' key in manifest"
            );
        }

        $themeData = $pluginManifest['theme'];

        if (!isset($themeData['id'], $themeData['name'], $themeData['css'])) {
            throw new \InvalidArgumentException(
                "Plugin '{$pluginName}' theme manifest is missing required fields (id, name, css)"
            );
        }

        $theme = new Theme(
            id: $themeData['id'],
            name: $themeData['name'],
            type: 'ui-theme-plugin',
            cssUrl: $themeData['css'],
            jsUrl: $themeData['js'] ?? null,
            thumbnailUrl: $themeData['thumbnail'] ?? null,
            version: $themeData['version'] ?? '1.0.0',
            pluginName: $pluginName,
            dark: $themeData['dark'] ?? false,
        );

        $this->themes[$theme->id] = $theme;
    }

    /**
     * Retrieves a theme by its ID.
     *
     * @param string $id The theme identifier
     * @return Theme|null The matching theme, or null if not found
     */
    public function getTheme(string $id): ?Theme
    {
        return $this->themes[$id] ?? null;
    }

    /**
     * Retrieves all registered themes.
     *
     * @return Theme[] Array of all registered Theme instances
     */
    public function getAllThemes(): array
    {
        return array_values($this->themes);
    }

    /**
     * Gets the active theme for a user.
     *
     * If the user has no active theme preference set, returns the default
     * theme (phlix-dark). If the stored theme ID is no longer registered
     * (e.g., plugin was uninstalled), also falls back to the default.
     *
     * @param string $userId The user identifier
     * @return Theme The user's active theme (never null)
     */
    public function getActiveThemeForUser(string $userId): Theme
    {
        /** @var array<array<string, mixed>> $result */
        $result = $this->db->query(
            "SELECT active_theme_id FROM user_profiles WHERE user_id = ? AND is_active = TRUE LIMIT 1",
            [$userId]
        );

        /** @var string|null $themeId */
        $themeId = null;

        if (count($result) > 0) {
            /** @var array<string, mixed> $row */
            $row = $result[0];
            $themeId = is_string($row['active_theme_id'] ?? null) ? $row['active_theme_id'] : null;
        }

        if ($themeId === null || !isset($this->themes[$themeId])) {
            $themeId = self::DEFAULT_THEME_ID;
        }

        /** @var Theme $theme */
        $theme = $this->themes[$themeId];

        return $theme;
    }

    /**
     * Sets the active theme for a user.
     *
     * Updates the active_theme_id for all active profiles of the user.
     *
     * @param string $userId The user identifier
     * @param string $themeId The theme identifier to set as active
     * @return void
     *
     * @throws \InvalidArgumentException If the theme ID is not registered
     */
    public function setActiveThemeForUser(string $userId, string $themeId): void
    {
        if (!isset($this->themes[$themeId])) {
            throw new \InvalidArgumentException("Unknown theme ID: {$themeId}");
        }

        $this->db->query(
            "UPDATE user_profiles SET active_theme_id = ? WHERE user_id = ? AND is_active = TRUE",
            [$themeId, $userId]
        );
    }

    /**
     * Gets the path to the runtime themes directory.
     *
     * @return string Absolute path to var/themes/
     */
    public function getThemesDir(): string
    {
        return $this->themesDir;
    }

    /**
     * Registers all built-in themes from config/themes.php.
     *
     * This method is called during ThemeRegistry construction to register
     * the four default Phlix themes (dark, light, amoled, contrast).
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function registerBuiltInThemes(): void
    {
        $configPath = dirname(__DIR__, 2) . '/config/themes.php';

        if (!file_exists($configPath)) {
            return;
        }

        /** @var array<string, array<string, array<string, mixed>>> $config */
        $config = include $configPath;

        if (!isset($config['builtin']) || !is_array($config['builtin'])) {
            return;
        }

        foreach ($config['builtin'] as $themeData) {
            if (
                !is_array($themeData)
                || !isset($themeData['id'], $themeData['name'], $themeData['css'])
                || !is_string($themeData['id'])
                || !is_string($themeData['name'])
                || !is_string($themeData['css'])
            ) {
                continue;
            }

            /** @var string $jsUrl */
            $jsUrl = is_string($themeData['js'] ?? null) ? $themeData['js'] : null;
            /** @var string|null $thumbnailUrl */
            $thumbnailUrl = is_string($themeData['thumb'] ?? null) ? $themeData['thumb'] : null;
            /** @var bool $dark */
            $dark = is_bool($themeData['dark'] ?? false) ? $themeData['dark'] : false;

            $theme = new Theme(
                id: $themeData['id'],
                name: $themeData['name'],
                type: 'builtin',
                cssUrl: $themeData['css'],
                jsUrl: $jsUrl,
                thumbnailUrl: $thumbnailUrl,
                version: '1.0.0',
                pluginName: null,
                dark: $dark,
            );

            $this->themes[$theme->id] = $theme;
        }
    }
}
