<?php

/**
 * Phlix media server component: Catalog.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Plugins\Catalog;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;

/**
 * Checks installed plugins for newer versions and applies updates.
 *
 * An installed plugin's "latest available" version is read from its source
 * repository's own `plugin.json` manifest (default branch). The repository is
 * resolved by matching the installed plugin's name against the configured
 * plugin {@see PluginCatalogService catalogs} — so any catalog plugin can be
 * update-checked without persisting an install URL. A plugin not present in any
 * catalog is reported as "not checkable" rather than erroring the whole sweep.
 *
 * Updating re-runs the normal install from the resolved repo URL, which the
 * installer performs in place (it replaces `var/plugins/<name>/`), so an update
 * is just a fresh install of the latest tarball.
 *
 * The network fetch is injectable for offline tests.
 *
 * @package Phlix\Plugins\Catalog
 * @since 0.39.0
 */
final class PluginUpdateService
{
    /** Per-fetch timeout (seconds) for a plugin's manifest. */
    private const FETCH_TIMEOUT = 10;

    /** @var callable(string, int): string */
    private $fetcher;

    /**
     * @param PluginLoader         $loader  Installed-plugin list + install engine.
     * @param PluginCatalogService $catalog Catalog source of name → repo URLs.
     * @param (callable(string, int): string)|null $fetcher Network fetcher;
     *        defaults to the catalog service's `file_get_contents` impl.
     */
    public function __construct(
        private readonly PluginLoader $loader,
        private readonly PluginCatalogService $catalog,
        ?callable $fetcher = null,
    ) {
        $this->fetcher = $fetcher ?? PluginCatalogService::defaultFetcher();
    }

    /**
     * Check every installed plugin for a newer version.
     *
     * @return array{
     *     updates: list<array{
     *         name: string, installed_version: string, latest_version: ?string,
     *         update_available: bool, repo: ?string, checkable: bool, error: ?string
     *     }>,
     *     available: int
     * }
     *
     * @since 0.39.0
     */
    public function checkUpdates(): array
    {
        $repoByName = $this->repoByName();
        $updates = [];
        $available = 0;

        foreach ($this->loader->listInstalled() as $plugin) {
            $name = $plugin->manifest->name;
            $installed = $plugin->manifest->version;
            $repo = $repoByName[$name] ?? null;

            $row = [
                'name' => $name,
                'installed_version' => $installed,
                'latest_version' => null,
                'update_available' => false,
                'repo' => $repo,
                'checkable' => $repo !== null,
                'error' => null,
            ];

            if ($repo === null) {
                $row['error'] = 'Not found in any configured catalog — cannot check for updates.';
                $updates[] = $row;
                continue;
            }

            try {
                $latest = $this->fetchLatestVersion($repo);
            } catch (\Throwable $e) {
                $row['error'] = 'Could not read the latest version: ' . $e->getMessage();
                $updates[] = $row;
                continue;
            }

            $row['latest_version'] = $latest;
            $row['update_available'] = $latest !== null && version_compare($latest, $installed, '>');
            if ($row['update_available']) {
                $available++;
            }
            $updates[] = $row;
        }

        return ['updates' => $updates, 'available' => $available];
    }

    /**
     * Update one installed plugin to the latest version from its catalog repo.
     *
     * @param string $name The installed plugin's manifest name.
     *
     * @return Manifest The freshly-installed manifest.
     *
     * @throws \RuntimeException When the plugin is not in any catalog (no repo
     *         to update from). Install failures propagate from the loader.
     *
     * @since 0.39.0
     */
    public function update(string $name): Manifest
    {
        $repo = $this->repoByName()[$name] ?? null;
        if ($repo === null) {
            throw new \RuntimeException(sprintf(
                'Plugin "%s" is not in any configured catalog, so it has no source to update from.',
                $name,
            ));
        }
        // SV-B2: thread the catalog pin (artifactSha256 + ref) into the install
        // so a pinned official plugin clears the SV-S2b default-deny. An
        // un-pinned (schemaVersion 1 / third-party) entry yields [null, null]
        // and stays on the default-deny path unchanged.
        [$sha, $ref] = $this->catalog->pinFor($repo);
        return $this->loader->install($repo, $sha, $ref);
    }

    /**
     * Update every installed plugin that has a newer version available.
     *
     * @return array{
     *     updated: list<array{name: string, from: string, to: ?string}>,
     *     failed: list<array{name: string, error: string}>
     * }
     *
     * @since 0.39.0
     */
    public function updateAll(): array
    {
        $updated = [];
        $failed = [];

        foreach ($this->checkUpdates()['updates'] as $row) {
            if ($row['update_available'] !== true) {
                continue;
            }
            try {
                $this->update($row['name']);
                $updated[] = [
                    'name' => $row['name'],
                    'from' => $row['installed_version'],
                    'to' => $row['latest_version'],
                ];
            } catch (\Throwable $e) {
                $failed[] = ['name' => $row['name'], 'error' => $e->getMessage()];
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /**
     * Build a `name => repo URL` map from every configured catalog. The first
     * catalog that lists a name wins (matching the catalog browse de-dup).
     *
     * @return array<string, string>
     */
    private function repoByName(): array
    {
        $map = [];
        foreach ($this->catalog->aggregate()['catalogs'] as $catalog) {
            foreach ($catalog['plugins'] as $entry) {
                if (!isset($map[$entry->name]) && $entry->repo !== '') {
                    $map[$entry->name] = $entry->repo;
                }
            }
        }
        return $map;
    }

    /**
     * Fetch a repository's own `plugin.json` and return its `version`, or null
     * when the manifest has no usable version string.
     *
     * @throws \RuntimeException Fixed curated copy on transport failure or
     *         non-JSON response — the real cause is logged, never carried
     *         (INFO-SERVER-62, E2 laundering leg).
     */
    private function fetchLatestVersion(string $repo): ?string
    {
        $url = CatalogSourceResolver::normalize($repo, 'plugin.json');

        try {
            $body = ($this->fetcher)($url, self::FETCH_TIMEOUT);
        } catch (\Throwable $e) {
            // INFO-SERVER-62 (E2 laundering leg): transport errors surface as
            // exception text embedding the upstream URL, and checkUpdates()
            // echoes this rethrow's getMessage() into the row a client reads
            // (HTTP 200 body). The wire carries the fixed curated copy; class
            // + cause go to the log only — the estate E2 law exactly as the
            // E2W wave's internalFailure() ships it. (The update() refusal
            // 404 copy is curated by update() itself; it never reaches this
            // leg. Srvtail-R1 overstatement folded 2026-11-04.) Teeth:
            // PluginUpdateServiceTest::test_transport_failure_* and
            // PluginCatalogControllerTest::test_updates_leg_surfaces_* —
            // all three redden on reverting this block.
            LoggerFactory::get(LogChannels::PLUGINS)->error(
                'Plugin manifest fetch failed',
                ['repo' => $repo, 'exception' => get_class($e), 'message' => $e->getMessage()]
            );

            throw new \RuntimeException(
                'The plugin manifest could not be fetched from its catalog repository.',
                0,
                $e,
            );
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('plugin.json was not valid JSON.');
        }

        $version = $decoded['version'] ?? null;
        return is_string($version) && trim($version) !== '' ? trim($version) : null;
    }
}
