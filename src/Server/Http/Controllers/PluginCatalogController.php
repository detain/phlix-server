<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Common\Logger\AuditLogger;
use Phlix\Plugins\Catalog\PluginCatalogService;
use Phlix\Plugins\Catalog\PluginUpdateService;
use Phlix\Plugins\Exception\PluginInstallException;
use Phlix\Plugins\PluginLoader;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * JSON API for the admin Plugins **catalog browser** (Step C — catalog rework).
 *
 * The admin UI seeds its plugin list from one or more *catalog* repositories
 * (`plugins.json`); this controller serves that catalog server-side so the
 * browser is not blocked by CORS and every fetch goes through one auditable
 * path. Install / uninstall / enable / configure remain on
 * {@see PluginAdminController} — this controller only discovers what is
 * installable and manages the catalog source list.
 *
 * Endpoints (wired in {@see \Phlix\Server\Http\Routes\AdminRoutes} under the
 * admin group, behind {@see \Phlix\Server\Http\Middleware\AdminMiddleware}):
 *
 *  - `GET    /api/v1/admin/plugins/catalog`          → aggregated catalog +
 *    per-entry installed/enabled flags + per-source errors.
 *  - `POST   /api/v1/admin/plugins/catalog/sources`  → add an extra catalog.
 *  - `DELETE /api/v1/admin/plugins/catalog/sources`  → remove an extra catalog.
 *
 * @package Phlix\Server\Http\Controllers
 * @since   0.33.0
 */
final class PluginCatalogController
{
    /**
     * @param PluginCatalogService $catalog Catalog fetch/aggregate + sources.
     * @param PluginLoader         $loader  Installed-plugin lookup for the
     *                                      install-state cross-reference.
     * @param AuditLogger          $audit   Records source add/remove actions.
     * @param PluginUpdateService  $updates Update check/apply against catalogs.
     */
    public function __construct(
        private readonly PluginCatalogService $catalog,
        private readonly PluginLoader $loader,
        private readonly AuditLogger $audit,
        private readonly PluginUpdateService $updates,
    ) {
    }

    /**
     * Aggregated catalog across every configured source, with each entry
     * annotated by whether it is already installed (and enabled).
     *
     * `GET /api/v1/admin/plugins/catalog` →
     * `200 { default_source, sources: [...], catalogs: [{ source, name,
     *        plugins: [{ ...entry, installed: bool, enabled: bool }] }],
     *        errors: [{ source, error }] }`.
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @since 0.33.0
     */
    public function index(Request $request, array $params): Response
    {
        $installed = $this->installedState();
        $aggregate = $this->catalog->aggregate();

        $catalogs = [];
        foreach ($aggregate['catalogs'] as $catalog) {
            $plugins = [];
            foreach ($catalog['plugins'] as $entry) {
                $row = $entry->toArray();
                $row['installed'] = array_key_exists($entry->name, $installed);
                $row['enabled']   = $installed[$entry->name] ?? false;
                $plugins[] = $row;
            }
            $catalogs[] = [
                'source'  => $catalog['source'],
                'name'    => $catalog['name'],
                'plugins' => $plugins,
            ];
        }

        return (new Response())->json([
            'default_source' => $this->catalog->defaultSource(),
            'sources'        => $aggregate['sources'],
            'catalogs'       => $catalogs,
            'errors'         => $aggregate['errors'],
        ]);
    }

    /**
     * Check every installed plugin for a newer version (per its catalog repo).
     *
     * `GET /api/v1/admin/plugins/updates` →
     * `200 { auto_update: bool, available: int, updates: [{ name,
     *        installed_version, latest_version, update_available, repo,
     *        checkable, error }] }`.
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @since 0.39.0
     */
    public function updates(Request $request, array $params): Response
    {
        $result = $this->updates->checkUpdates();
        return (new Response())->json([
            'auto_update' => $this->catalog->autoUpdateEnabled(),
            'available'   => $result['available'],
            'updates'     => $result['updates'],
        ]);
    }

    /**
     * Update one installed plugin to its catalog's latest version.
     *
     * `POST /api/v1/admin/plugins/{name}/update` → `200 { plugin: {...} }`,
     * `404 plugin.update.no_source` (not in a catalog), or `422
     * plugin.update.failed`.
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  `name` is the manifest name.
     *
     * @since 0.39.0
     */
    public function updatePlugin(Request $request, array $params): Response
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->jsonError(400, 'plugin.name.required', 'A "name" path parameter is required.');
        }

        try {
            $manifest = $this->updates->update($name);
        } catch (PluginInstallException $e) {
            return $this->jsonError(422, 'plugin.update.failed', $e->getMessage());
        } catch (\RuntimeException $e) {
            return $this->jsonError(404, 'plugin.update.no_source', $e->getMessage());
        }

        $this->audit->logPluginAction($this->actor($request), 'update', $name, ['source' => 'ui']);

        return (new Response())->json([
            'plugin' => ['name' => $manifest->name, 'version' => $manifest->version],
        ]);
    }

    /**
     * Update every installed plugin that has a newer version available.
     *
     * `POST /api/v1/admin/plugins/updates/apply` →
     * `200 { updated: [{name, from, to}], failed: [{name, error}] }`.
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @since 0.39.0
     */
    public function applyUpdates(Request $request, array $params): Response
    {
        $result = $this->updates->updateAll();
        $this->audit->logPluginAction(
            $this->actor($request),
            'update_all',
            '*',
            ['source' => 'ui', 'updated' => count($result['updated']), 'failed' => count($result['failed'])],
        );
        return (new Response())->json($result);
    }

    /**
     * Read or set the auto-update toggle.
     *
     * `GET /api/v1/admin/plugins/auto-update` → `200 { auto_update: bool }`.
     * `PUT /api/v1/admin/plugins/auto-update` body `{ enabled: bool }` → same.
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @since 0.39.0
     */
    public function autoUpdate(Request $request, array $params): Response
    {
        if ($request->method === 'PUT') {
            $enabled = $request->input('enabled');
            if (!is_bool($enabled)) {
                return $this->jsonError(
                    400,
                    'plugin.auto_update.invalid',
                    'An "enabled" boolean is required.',
                    ['enabled'],
                );
            }
            $this->catalog->setAutoUpdate($enabled);
            $this->audit->logPluginAction(
                $this->actor($request),
                'auto_update',
                '*',
                ['source' => 'ui', 'enabled' => $enabled],
            );
        }
        return (new Response())->json(['auto_update' => $this->catalog->autoUpdateEnabled()]);
    }

    /**
     * Add an extra catalog source.
     *
     * `POST /api/v1/admin/plugins/catalog/sources` body `{ "url": "<url>" }`
     * → `200 { sources: [...] }`, or `400 plugin.catalog.url.invalid`.
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @since 0.33.0
     */
    public function addSource(Request $request, array $params): Response
    {
        $url = $request->input('url');
        if (!is_string($url) || trim($url) === '') {
            return $this->jsonError(400, 'plugin.catalog.url.required', 'A "url" field is required.', ['url']);
        }

        try {
            $sources = $this->catalog->addSource($url);
        } catch (\InvalidArgumentException $e) {
            // 409 marks "already present / is the default" — a distinct, non-fatal
            // outcome the SPA surfaces with the service's message (rather than the
            // generic "not a valid URL" copy it uses for `url.invalid`).
            if ($e->getCode() === 409) {
                return $this->jsonError(409, 'plugin.catalog.url.duplicate', $e->getMessage(), ['url']);
            }
            return $this->jsonError(400, 'plugin.catalog.url.invalid', $e->getMessage(), ['url']);
        }

        $this->audit->logPluginAction(
            $this->actor($request),
            'catalog.add_source',
            trim($url),
            ['source' => 'ui'],
        );

        return (new Response())->json(['sources' => $sources]);
    }

    /**
     * Remove an extra catalog source. The default source cannot be removed.
     *
     * `DELETE /api/v1/admin/plugins/catalog/sources` body `{ "url": "<url>" }`
     * → `200 { sources: [...] }`.
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @since 0.33.0
     */
    public function removeSource(Request $request, array $params): Response
    {
        // Accept the URL from the JSON body or a `?url=` query param: the
        // browser's DELETE carries no body, so the SPA passes it on the query
        // string, while direct API callers may still send a body.
        $url = $request->input('url');
        if (!is_string($url) || trim($url) === '') {
            $queryUrl = $request->query['url'] ?? null;
            $url = is_string($queryUrl) ? $queryUrl : null;
        }
        if (!is_string($url) || trim($url) === '') {
            return $this->jsonError(400, 'plugin.catalog.url.required', 'A "url" field is required.', ['url']);
        }

        $sources = $this->catalog->removeSource($url);

        $this->audit->logPluginAction(
            $this->actor($request),
            'catalog.remove_source',
            trim($url),
            ['source' => 'ui'],
        );

        return (new Response())->json(['sources' => $sources]);
    }

    /**
     * Map of installed plugin name → enabled flag, for the catalog
     * install-state cross-reference.
     *
     * @return array<string, bool>
     */
    private function installedState(): array
    {
        $state = [];
        foreach ($this->loader->listInstalled() as $plugin) {
            $state[$plugin->manifest->name] = $plugin->enabled;
        }
        return $state;
    }

    /**
     * Resolve the acting admin user id for audit entries.
     */
    private function actor(Request $request): string
    {
        $id = $request->userId;
        return is_string($id) && $id !== '' ? $id : 'system';
    }

    /**
     * Build a JSON error Response mirroring {@see PluginAdminController}.
     *
     * @param list<string>|null $fields
     */
    private function jsonError(int $status, string $code, string $message, ?array $fields = null): Response
    {
        $body = ['error' => $message, 'code' => $code];
        if ($fields !== null) {
            $body['fields'] = $fields;
        }
        return (new Response())->status($status)->json($body);
    }
}
