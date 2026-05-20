<?php

declare(strict_types=1);

namespace Phlix\Plugins\Repository;

use DateTimeImmutable;
use Phlix\Plugins\Exception\PluginNotFoundException;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Workerman\MySQL\Connection;

/**
 * Workerman\MySQL-backed CRUD for the `plugins` table created in
 * migration `003_plugins.sql`.
 *
 * All queries are parameterized — never interpolate user input into the
 * SQL string. The repository hands back fully-hydrated
 * {@see InstalledPlugin} DTOs so the loader doesn't deal in raw rows.
 *
 * @package Phlix\Plugins\Repository
 * @since 0.10.0
 */
class PluginRepository
{
    /**
     * Charset map mirrored from the existing UUID helpers used across
     * the codebase. Generates an RFC 4122 v4-style identifier without
     * pulling in an external dependency.
     */
    private const UUID_FORMAT = '%04x%04x-%04x-%04x-%04x-%04x%04x%04x';

    /**
     * Absolute base directory under which every plugin's install
     * subdirectory lives. Injected so unit tests can swap in a tmpdir.
     */
    private string $pluginsBaseDir;

    public function __construct(
        private readonly Connection $db,
        string $pluginsBaseDir,
    ) {
        $this->pluginsBaseDir = rtrim($pluginsBaseDir, DIRECTORY_SEPARATOR);
    }

    /**
     * Persist a freshly-installed plugin. Returns the generated UUID.
     *
     * @param Manifest             $manifest Parsed manifest of the plugin.
     * @param bool                 $enabled  Initial enabled flag (almost always false).
     * @param array<string, mixed> $settings Initial settings (defaults from manifest).
     *
     * @return string Generated UUID primary key.
     *
     * @since 0.10.0
     */
    public function insert(Manifest $manifest, bool $enabled, array $settings): string
    {
        $id = self::generateUuid();
        $this->db->query(
            'INSERT INTO plugins '
            . '(id, name, version, type, entry, enabled, installed_at, settings_json, manifest_json) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $manifest->name,
                $manifest->version,
                $manifest->type,
                $manifest->entry,
                $enabled ? 1 : 0,
                (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                (string) json_encode($settings),
                (string) json_encode($manifest->toArray()),
            ],
        );
        return $id;
    }

    /**
     * Look up an installed plugin by manifest name.
     *
     * @param string $name Manifest `name` field, e.g. `phlix-plugin-lastfm`.
     *
     * @return InstalledPlugin Fully hydrated DTO.
     *
     * @throws PluginNotFoundException When no row matches the name.
     *
     * @since 0.10.0
     */
    public function findByName(string $name): InstalledPlugin
    {
        $rows = $this->db->query(
            'SELECT id, name, version, type, entry, enabled, installed_at, settings_json, manifest_json '
            . 'FROM plugins WHERE name = ? LIMIT 1',
            [$name],
        );

        if (!is_array($rows) || count($rows) === 0) {
            throw new PluginNotFoundException(sprintf('No installed plugin named "%s".', $name));
        }

        $row = $rows[0];
        if (!is_array($row)) {
            throw new PluginNotFoundException(sprintf('No installed plugin named "%s".', $name));
        }

        return $this->hydrate(\Phlix\Common\Util\RowMap::fromMixed($row));
    }

    /**
     * Whether a plugin with the given name is installed.
     *
     * @param string $name Manifest name.
     *
     * @since 0.10.0
     */
    public function existsByName(string $name): bool
    {
        $rows = $this->db->query('SELECT 1 FROM plugins WHERE name = ? LIMIT 1', [$name]);
        return is_array($rows) && count($rows) > 0;
    }

    /**
     * Update the enabled flag for a plugin.
     *
     * @param string $name    Manifest name.
     * @param bool   $enabled New enabled value.
     *
     * @since 0.10.0
     */
    public function setEnabled(string $name, bool $enabled): void
    {
        $this->db->query(
            'UPDATE plugins SET enabled = ? WHERE name = ?',
            [$enabled ? 1 : 0, $name],
        );
    }

    /**
     * Replace the settings JSON blob for a plugin.
     *
     * @param string               $name     Manifest name.
     * @param array<string, mixed> $settings New settings map.
     *
     * @since 0.10.0
     */
    public function updateSettings(string $name, array $settings): void
    {
        $this->db->query(
            'UPDATE plugins SET settings_json = ? WHERE name = ?',
            [(string) json_encode($settings), $name],
        );
    }

    /**
     * Delete the plugin row.
     *
     * @param string $name Manifest name.
     *
     * @since 0.10.0
     */
    public function delete(string $name): void
    {
        $this->db->query('DELETE FROM plugins WHERE name = ?', [$name]);
    }

    /**
     * List every installed plugin.
     *
     * @return list<InstalledPlugin>
     *
     * @since 0.10.0
     */
    public function listAll(): array
    {
        $rows = $this->db->query(
            'SELECT id, name, version, type, entry, enabled, installed_at, settings_json, manifest_json '
            . 'FROM plugins ORDER BY name ASC',
        );

        return $this->mapToPlugins($rows);
    }

    /**
     * List every enabled plugin.
     *
     * @return list<InstalledPlugin>
     *
     * @since 0.10.0
     */
    public function listEnabled(): array
    {
        $rows = $this->db->query(
            'SELECT id, name, version, type, entry, enabled, installed_at, settings_json, manifest_json '
            . 'FROM plugins WHERE enabled = 1 ORDER BY name ASC',
        );

        return $this->mapToPlugins($rows);
    }

    /**
     * Hydrate a mixed result-set into a list of InstalledPlugin objects.
     *
     * @param mixed $rows Raw `$db->query()` result.
     * @return list<InstalledPlugin>
     */
    private function mapToPlugins(mixed $rows): array
    {
        $out = [];
        foreach (\Phlix\Common\Util\RowMap::listFromMixed($rows) as $row) {
            $out[] = $this->hydrate($row);
        }
        return $out;
    }

    /**
     * Absolute filesystem path of the plugin's install directory.
     *
     * @param string $name Manifest name.
     *
     * @since 0.10.0
     */
    public function directoryFor(string $name): string
    {
        return $this->pluginsBaseDir . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Construct an {@see InstalledPlugin} from a raw DB row.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): InstalledPlugin
    {
        $manifestJson = is_string($row['manifest_json'] ?? null) ? (string) $row['manifest_json'] : '{}';
        /** @var mixed $decodedManifest */
        $decodedManifest = json_decode($manifestJson, true);
        $manifest = Manifest::fromArray(is_array($decodedManifest) ? $decodedManifest : []);

        $settingsJson = is_string($row['settings_json'] ?? null) ? (string) $row['settings_json'] : '';
        $settings = [];
        if ($settingsJson !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode($settingsJson, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $settings = $decoded;
            }
        }

        $installedAtRaw = is_string($row['installed_at'] ?? null)
            ? (string) $row['installed_at']
            : '1970-01-01 00:00:00';
        try {
            $installedAt = new DateTimeImmutable($installedAtRaw);
        } catch (\Exception) {
            $installedAt = new DateTimeImmutable('1970-01-01 00:00:00');
        }

        $name = is_string($row['name'] ?? null) ? (string) $row['name'] : $manifest->name;
        $idRaw = $row['id'] ?? '';
        $id = is_scalar($idRaw) ? (string) $idRaw : '';
        $enabledRaw = $row['enabled'] ?? 0;
        $enabled = is_scalar($enabledRaw) ? (int) $enabledRaw : 0;

        return new InstalledPlugin(
            id: $id,
            manifest: $manifest,
            enabled: $enabled === 1,
            installedAt: $installedAt,
            settings: $settings,
            directory: $this->directoryFor($name),
        );
    }

    /**
     * Generate a UUIDv4-style identifier matching the local helper
     * pattern duplicated across the codebase. Public so callers (the
     * loader) can reuse the same flavour for transient identifiers.
     *
     * @since 0.10.0
     */
    public static function generateUuid(): string
    {
        return sprintf(
            self::UUID_FORMAT,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
