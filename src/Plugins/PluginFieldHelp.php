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
 * Server-side field-help overlay for plugin configure forms.
 *
 * Merges the curated help map in `config/plugin_field_help.php` over a
 * plugin's manifest settings schema so an ALREADY-INSTALLED plugin shows
 * improved labels / descriptions / "where to get this" links immediately,
 * without waiting for a plugin update that carries the same text in its own
 * `plugin.json`. Each plugin's own manifest remains the canonical source; the
 * overlay only fills in or upgrades the four human-facing keys
 * (`label`, `description`, `link`, `link_text`) for keys it explicitly covers.
 *
 * The map is loaded once and cached for the process (an admin-config read, off
 * the media hot path). Ops can extend the overlay by editing the config file.
 *
 * @package Phlix\Plugins
 * @since 1.2.0
 */
final class PluginFieldHelp
{
    /**
     * Keys the overlay is allowed to contribute. Everything else in a schema
     * descriptor (`type`/`required`/`secret`/`default`) always comes from the
     * manifest and is never touched by the overlay.
     */
    private const OVERLAY_KEYS = ['label', 'description', 'link', 'link_text'];

    /**
     * Cached overlay map (`pluginName => fieldKey => partial descriptor`), or
     * null before the first load. Typed loosely because the source is an
     * operator-editable config file; {@see self::decorate()} validates each
     * level defensively so a malformed edit degrades gracefully.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $map = null;

    /**
     * Merge the field-help overlay over one plugin's settings schema.
     *
     * For each schema field that the overlay covers, the overlay's
     * {@see self::OVERLAY_KEYS} replace the corresponding schema values (the
     * overlay is server-curated and kept in step with the manifests). Fields
     * the overlay does not mention pass through unchanged, and overlay entries
     * for keys not in the schema are ignored (so a stale overlay never invents
     * a field).
     *
     * @param string                             $pluginName Manifest name.
     * @param array<string, array<string, mixed>> $schema     Manifest schema from
     *        {@see SettingsMasker::schema()}.
     *
     * @return array<string, array<string, mixed>> The decorated schema.
     *
     * @since 1.2.0
     */
    public static function decorate(string $pluginName, array $schema): array
    {
        $overlay = self::load()[$pluginName] ?? null;
        if (!is_array($overlay)) {
            return $schema;
        }
        foreach ($schema as $key => $descriptor) {
            $fieldHelp = $overlay[$key] ?? null;
            if (!is_array($fieldHelp)) {
                continue;
            }
            foreach (self::OVERLAY_KEYS as $overlayKey) {
                $value = $fieldHelp[$overlayKey] ?? null;
                if (is_string($value) && $value !== '') {
                    $descriptor[$overlayKey] = $value;
                }
            }
            $schema[$key] = $descriptor;
        }
        return $schema;
    }

    /**
     * Inject a custom overlay map (tests) or reset to force a reload.
     *
     * @param array<string, mixed>|null $map
     *
     * @internal
     */
    public static function setMapForTesting(?array $map): void
    {
        self::$map = $map;
    }

    /**
     * Load (and cache) the overlay map from `config/plugin_field_help.php`.
     * A missing or malformed file degrades to an empty map so the configure
     * form still renders the manifest schema.
     *
     * @return array<string, mixed>
     */
    private static function load(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }
        $path = dirname(__DIR__, 2) . '/config/plugin_field_help.php';
        $loaded = is_file($path) ? require $path : [];
        self::$map = is_array($loaded) ? $loaded : [];
        return self::$map;
    }
}
