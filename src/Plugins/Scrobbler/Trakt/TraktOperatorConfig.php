<?php

/**
 * Phlix media server component: Trakt operator credentials.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

use Phlix\Admin\SettingsRepository;

/**
 * Loads the operator's Trakt application credentials, with admin-saved
 * `server_settings` values overlaid on top of the env/file config.
 *
 * ## Why this class exists
 *
 * The credentials were loaded in TWO places that disagreed:
 *
 * | site | behaviour before |
 * |---|---|
 * | `TraktOAuthController::loadConfig()` | included the file, then overlaid `server_settings` |
 * | `TraktPlugin::loadConfig()` | raw `include` of the file, **no overlay** |
 *
 * That is read-path class (d) in `plan_settings.md` §0.4 — a raw `include`
 * cannot see a saved override. The user-visible consequence was a split brain:
 * an operator who entered their credentials in the admin Settings page could
 * complete the OAuth *connect* flow, because the controller honoured them, and
 * then watch history sync silently do nothing forever, because
 * `TraktPlugin::initApi()` read only the file and left `$this->api` null — at
 * which point `isConfigured()` is false and every tick is a no-op. No error is
 * logged on that path, so it presents as "Trakt connected but nothing syncs".
 *
 * Both callers now route through here so the two paths cannot drift again.
 *
 * ## Precedence
 *
 * A saved setting BEATS the environment variable, which beats the file
 * literal. That is the same (initially surprising) ordering the rest of this
 * settings program established — `config/scrobblers/trakt.php` reads `getenv()`
 * at include time, and the DB overlay is applied afterwards.
 *
 * An override is applied only when it is a **non-empty string**, so clearing a
 * field in the admin UI falls back to the environment rather than blanking the
 * credential.
 *
 * @since 0.40.0
 */
final class TraktOperatorConfig
{
    /**
     * Maps dotted `server_settings` keys to the local config keys they
     * override.
     *
     * These three keys exist in `phlix-shared`'s server-settings schema
     * (`trakt.client_id`, `trakt.client_secret`, `trakt.redirect_uri`).
     * `sync_interval` is deliberately absent: the sync cadence is a per-plugin
     * setting living in `plugins.settings_json`
     * ({@see TraktSettings::$syncIntervalMinutes}), not a server setting.
     *
     * @var array<string, string>
     */
    public const SETTING_KEY_MAP = [
        'trakt.client_id'     => 'client_id',
        'trakt.client_secret' => 'client_secret',
        'trakt.redirect_uri'  => 'redirect_uri',
    ];

    /**
     * Include the config file (if present) and overlay admin-saved settings.
     *
     * @param string                  $configFile Absolute path to `config/scrobblers/trakt.php`
     * @param SettingsRepository|null $settings   When null, the file/env values are
     *                                            returned unchanged — which is what
     *                                            happens in contexts with no database.
     *
     * @return array<string, mixed>
     */
    public static function load(string $configFile, ?SettingsRepository $settings): array
    {
        $config = [];

        if (is_file($configFile)) {
            /** @var mixed $loaded */
            $loaded = include $configFile;
            if (is_array($loaded)) {
                /** @var array<string, mixed> $config */
                $config = $loaded;
            }
        }

        return self::applyOverrides($config, $settings);
    }

    /**
     * Overlay admin-saved operator credentials on top of an already-loaded
     * config array.
     *
     * Split out from {@see self::load()} so it can be exercised without
     * touching the filesystem, and so a caller that already holds the file
     * contents does not have to re-include it.
     *
     * @param array<string, mixed>    $config
     * @param SettingsRepository|null $settings
     *
     * @return array<string, mixed>
     */
    public static function applyOverrides(array $config, ?SettingsRepository $settings): array
    {
        if ($settings === null) {
            return $config;
        }

        foreach (self::SETTING_KEY_MAP as $settingKey => $configKey) {
            // getOverride(), not getEffective(): the schema default for these
            // keys is an empty string, so an effective read would overwrite a
            // real env-supplied credential with "" on every install that has
            // never visited the Settings page.
            $override = $settings->getOverride($settingKey);
            $value = $override['value'] ?? null;
            if (is_string($value) && $value !== '') {
                $config[$configKey] = $value;
            }
        }

        return $config;
    }
}
