<?php

declare(strict_types=1);

namespace Phlix\Plugins;

use DateTimeImmutable;

/**
 * Read-only DTO returned by {@see PluginLoader::listInstalled()} and
 * {@see PluginLoader::getEnabled()}.
 *
 * Combines the parsed {@see Manifest} with the per-installation state
 * tracked in the `plugins` table (UUID, enabled flag, install timestamp,
 * persisted settings). Callers mutate state via {@see PluginLoader},
 * never by reassigning fields on this DTO.
 *
 * @package Phlix\Plugins
 * @since 0.10.0
 */
final class InstalledPlugin
{
    /**
     * @param string                $id          UUID primary key of the plugins row.
     * @param Manifest              $manifest    Parsed manifest for the plugin.
     * @param bool                  $enabled     Whether the plugin is currently enabled.
     * @param DateTimeImmutable     $installedAt Timestamp the plugin was installed.
     * @param array<string, mixed>  $settings    Persisted settings — keyed by setting name.
     * @param string                $directory   Absolute path to the plugin's on-disk root.
     */
    public function __construct(
        public readonly string $id,
        public readonly Manifest $manifest,
        public readonly bool $enabled,
        public readonly DateTimeImmutable $installedAt,
        public readonly array $settings,
        public readonly string $directory,
    ) {
    }

    /**
     * Convenience accessor for the manifest's `name` field.
     *
     * @return string Plugin name as declared in `plugin.json`.
     *
     * @since 0.10.0
     */
    public function name(): string
    {
        return $this->manifest->name;
    }
}
