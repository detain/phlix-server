<?php

/**
 * Phlix media server component: Plugins.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins;

/**
 * Shared helper that hides values for manifest-declared secret settings
 * before they are returned to the admin UI (JSON or SSR).
 *
 * Both {@see \Phlix\Server\Http\Controllers\PluginAdminController} and
 * {@see \Phlix\Server\WebPortal\Controllers\PluginAdminPageController}
 * used to carry a private copy of this logic; this class is the single
 * source of truth.
 *
 * Two shapes are exposed because the API and the SSR template need
 * different envelopes:
 *
 *  - {@see self::mask()} returns the raw key/value associative array
 *    expected by the JSON response (`{ "settings": {...} }`).
 *  - {@see self::view()} returns the row form expected by Smarty
 *    (`[ {key, type, value, secret}, ... ]`).
 *
 * @package Phlix\Plugins
 * @since 0.10.1
 */
final class SettingsMasker
{
    /**
     * Placeholder rendered in place of any secret setting's value.
     */
    public const MASK = '***';

    /**
     * Mask any setting flagged `secret: true` in the manifest. Returns
     * a copy of the persisted settings array with the secret values
     * replaced by {@see self::MASK}.
     *
     * @param InstalledPlugin $plugin Installed plugin whose values to mask.
     *
     * @return array<string, mixed> Settings array with secrets redacted.
     *
     * @since 0.10.1
     */
    public static function mask(InstalledPlugin $plugin): array
    {
        $masked = $plugin->settings;
        foreach ($plugin->manifest->settings as $key => $schema) {
            if (
                array_key_exists($key, $masked)
                && isset($schema['secret']) && $schema['secret'] === true
            ) {
                $masked[$key] = self::MASK;
            }
        }
        return $masked;
    }

    /**
     * Project a plugin manifest's `settings` map into the normalised
     * schema shape the admin configure form consumes: per key
     * `{type, required, secret, label, description, default}`.
     *
     * Missing optional descriptors are defaulted (`required`/`secret` →
     * false, `label`/`description` → empty string, `type` → `mixed`).
     * The `default` key is only present when the manifest declares one,
     * so the UI can distinguish "no default" from "default is null".
     *
     * @param InstalledPlugin $plugin Installed plugin whose manifest to project.
     *
     * @return array<string, array{type:string, required:bool, secret:bool, label:string,
     *     description:string, default?:mixed}>
     *
     * @since 0.12.0 (S6 — plugin configure endpoint)
     */
    public static function schema(InstalledPlugin $plugin): array
    {
        $out = [];
        foreach ($plugin->manifest->settings as $key => $schema) {
            $entry = [
                'type'        => is_string($schema['type'] ?? null) ? (string) $schema['type'] : 'mixed',
                'required'    => isset($schema['required']) && $schema['required'] === true,
                'secret'      => isset($schema['secret']) && $schema['secret'] === true,
                'label'       => is_string($schema['label'] ?? null) ? (string) $schema['label'] : '',
                'description' => is_string($schema['description'] ?? null) ? (string) $schema['description'] : '',
            ];
            if (array_key_exists('default', $schema)) {
                $entry['default'] = $schema['default'];
            }
            $out[$key] = $entry;
        }
        return $out;
    }

    /**
     * Render the masked settings as a flat list of rows suitable for a
     * Smarty `{foreach}`: one row per declared setting key with
     * `{key, type, value, secret}`.
     *
     * @param InstalledPlugin $plugin Installed plugin whose values to mask.
     *
     * @return list<array{key:string, type:string, value:mixed, secret:bool}>
     *
     * @since 0.10.1
     */
    public static function view(InstalledPlugin $plugin): array
    {
        $rows = [];
        foreach ($plugin->manifest->settings as $key => $schema) {
            $isSecret = isset($schema['secret']) && $schema['secret'] === true;
            $value = $plugin->settings[$key] ?? null;
            if ($isSecret && $value !== null) {
                $value = self::MASK;
            }
            $rows[] = [
                'key'    => $key,
                'type'   => is_string($schema['type'] ?? null) ? (string) $schema['type'] : 'mixed',
                'value'  => $value,
                'secret' => $isSecret,
            ];
        }
        return $rows;
    }
}
