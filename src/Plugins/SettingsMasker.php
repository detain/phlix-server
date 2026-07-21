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
 * {@see \Phlix\Server\Http\Controllers\PluginAdminController} used to carry a
 * private copy of this logic (as did the now-retired Smarty page controller);
 * this class is the single source of truth.
 *
 * Two shapes are exposed because the JSON API and legacy callers need
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
     * The complete disclosure-tier vocabulary, mirroring the server-settings
     * schema's `tier`. Anything else a manifest declares is not a tier we can
     * honour, so {@see self::normaliseTier()} folds it to `standard` — the
     * fail-VISIBLE direction.
     */
    public const TIERS = ['standard', 'advanced'];

    /**
     * Default tier for a field whose manifest omits `tier` (most of them).
     */
    public const DEFAULT_TIER = 'standard';

    /**
     * Resolve a manifest's declared `tier` to one we will actually render.
     *
     * Two deliberate asymmetries, both erring toward SHOWING a field:
     *
     * 1. An unrecognised or non-string tier becomes `standard`, not `advanced`.
     *    A typo (`"advnaced"`) must never hide a field.
     * 2. A `required` field is ALWAYS `standard`, whatever the manifest says.
     *    `PluginAdminController::configure()` validates types and unknown keys
     *    but does NOT enforce `required`, so a required field hidden behind the
     *    Advanced toggle produces no error at all — the admin saves a form that
     *    looks complete and the plugin silently never works. That is precisely
     *    the "control that lies" failure this settings program exists to remove,
     *    so the invariant is enforced here, in the single projection that both
     *    the read path and the validation path share, rather than in the UI
     *    where a second consumer could forget it.
     *
     * @param mixed $declared  Raw `tier` from the manifest descriptor.
     * @param bool  $required  Whether the field is manifest-required.
     *
     * @return string One of {@see self::TIERS}.
     *
     * @since 1.3.0
     */
    public static function normaliseTier(mixed $declared, bool $required): string
    {
        if ($required) {
            return 'standard';
        }
        return is_string($declared) && in_array($declared, self::TIERS, true)
            ? $declared
            : self::DEFAULT_TIER;
    }

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
     * `tier` is ALWAYS emitted, even when the manifest omits it, so the UI
     * never has to re-derive the default and the `required` invariant in
     * {@see self::normaliseTier()} is visible in the payload itself.
     *
     * @param InstalledPlugin $plugin Installed plugin whose manifest to project.
     *
     * @return array<string, array{type:string, required:bool, secret:bool, label:string,
     *     description:string, tier:string, default?:mixed}>
     *
     * @since 0.12.0 (S6 — plugin configure endpoint)
     */
    public static function schema(InstalledPlugin $plugin): array
    {
        $out = [];
        foreach ($plugin->manifest->settings as $key => $schema) {
            $required = isset($schema['required']) && $schema['required'] === true;
            $entry = [
                'type'        => is_string($schema['type'] ?? null) ? (string) $schema['type'] : 'mixed',
                'required'    => $required,
                'secret'      => isset($schema['secret']) && $schema['secret'] === true,
                'label'       => is_string($schema['label'] ?? null) ? (string) $schema['label'] : '',
                'description' => is_string($schema['description'] ?? null) ? (string) $schema['description'] : '',
                'tier'        => self::normaliseTier($schema['tier'] ?? null, $required),
            ];
            // Optional "where to get this value" link a manifest may declare
            // (`link` = URL, `link_text` = anchor label). Passed through so the
            // configure form can render a helper link next to the field.
            if (is_string($schema['link'] ?? null) && $schema['link'] !== '') {
                $entry['link'] = (string) $schema['link'];
            }
            if (is_string($schema['link_text'] ?? null) && $schema['link_text'] !== '') {
                $entry['link_text'] = (string) $schema['link_text'];
            }
            if (array_key_exists('default', $schema)) {
                $entry['default'] = $schema['default'];
            }
            $out[$key] = $entry;
        }
        return $out;
    }

    /**
     * Per-secret "is it set?" status for the configure form, so the admin can
     * tell a set secret from an unset one (both mask to {@see self::MASK} in
     * {@see self::mask()}, which is exactly why they are indistinguishable in
     * the value map). The raw secret is NEVER included — only whether a
     * non-empty value is stored and its character length (so the UI can render
     * a length-appropriate row of dots as a "yes, it's really set" cue).
     *
     * @param InstalledPlugin $plugin Installed plugin whose secrets to summarise.
     *
     * @return array<string, array{set: bool, length: int}> Keyed by secret setting key.
     *
     * @since 1.2.0
     */
    public static function secretStatus(InstalledPlugin $plugin): array
    {
        $out = [];
        foreach ($plugin->manifest->settings as $key => $schema) {
            if (!(isset($schema['secret']) && $schema['secret'] === true)) {
                continue;
            }
            $value = $plugin->settings[$key] ?? null;
            $set = is_scalar($value) && (string) $value !== '';
            $out[$key] = [
                'set'    => $set,
                'length' => $set ? mb_strlen((string) $value) : 0,
            ];
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
