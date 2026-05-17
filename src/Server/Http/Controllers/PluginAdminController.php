<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers;

use Phlex\Common\Logger\AuditLogger;
use Phlex\Plugins\Exception\PluginEnableException;
use Phlex\Plugins\Exception\PluginInstallException;
use Phlex\Plugins\Exception\PluginNotFoundException;
use Phlex\Plugins\InstalledPlugin;
use Phlex\Plugins\Manifest;
use Phlex\Plugins\PluginLoader;
use Phlex\Plugins\SettingsMasker;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

/**
 * JSON API for the admin-only plugin lifecycle (Step A.5).
 *
 * Endpoints (all wired via
 * {@see \Phlex\Server\Http\Routes\AdminRoutes} under the
 * `/api/v1/admin` group, with
 * {@see \Phlex\Server\Http\Middleware\AdminMiddleware} in front):
 *
 *  - `GET    /api/v1/admin/plugins`                    → list installed
 *  - `POST   /api/v1/admin/plugins/install`            → install from URL
 *  - `POST   /api/v1/admin/plugins/{name}/enable`      → enable
 *  - `POST   /api/v1/admin/plugins/{name}/disable`     → disable
 *  - `DELETE /api/v1/admin/plugins/{name}`             → uninstall
 *
 * Failure modes are translated to HTTP shape:
 *
 *  | Exception                       | HTTP | Code in body                |
 *  | ------------------------------- | ---- | --------------------------- |
 *  | Missing input (`url`)           | 400  | `plugin.url.required`       |
 *  | Non-HTTPS scheme on install URL | 400  | `plugin.url.invalid_scheme` |
 *  | {@see PluginInstallException}   | 422  | `plugin.install.failed`     |
 *  | {@see PluginNotFoundException}  | 404  | `plugin.not_found`          |
 *  | {@see PluginEnableException}    | 422  | `plugin.enable.failed`      |
 *
 * Every successful state-changing call emits one
 * {@see AuditLogger::logPluginAction()} audit entry so the operator can
 * see who installed / enabled / disabled / uninstalled what. The actor
 * user id comes from `$request->userId`, which
 * {@see \Phlex\Server\Http\Middleware\AdminMiddleware} guarantees is set
 * and admin.
 *
 * CSRF: the API is Bearer-token authenticated, so it is not subject to
 * cross-site cookie attacks. The middleware refuses anonymous traffic
 * with 401 before this controller ever sees the request.
 *
 * @package Phlex\Server\Http\Controllers
 * @since   0.10.0 (Step A.5)
 */
final class PluginAdminController
{
    /**
     * @param PluginLoader $loader The lifecycle facade from Step A.4.
     * @param AuditLogger  $audit  Records every admin-initiated lifecycle action.
     */
    public function __construct(
        private readonly PluginLoader $loader,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * List every installed plugin.
     *
     * `GET /api/v1/admin/plugins` →
     * `200 { "plugins": [InstalledPluginJson, ...] }`
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @return Response JSON-encoded list of installed plugins.
     *
     * @since 0.10.0 (Step A.5)
     */
    public function index(Request $request, array $params): Response
    {
        $plugins = $this->loader->listInstalled();
        $payload = array_map([$this, 'serializeInstalled'], $plugins);
        return (new Response())->json(['plugins' => $payload]);
    }

    /**
     * Install a plugin from a URL.
     *
     * `POST /api/v1/admin/plugins/install` body `{"url": "..."}` →
     * `201 { "plugin": ManifestJson }`.
     *
     * The URL scheme is restricted to `https://` and `file://` for
     * defence-in-depth — the underlying `HttpInstaller` also enforces
     * this in A.4. We refuse `http://` even when the underlying
     * installer would tolerate it via `PHLEX_PLUGINS_ALLOW_HTTP=1`,
     * because the admin UI surface MUST stay secure by default.
     *
     * @param Request              $request The HTTP request (`body.url`).
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @return Response 201 + manifest on success, 4xx on failure.
     *
     * @since 0.10.0 (Step A.5)
     */
    public function install(Request $request, array $params): Response
    {
        $url = $request->input('url');
        if (!is_string($url) || trim($url) === '') {
            return $this->jsonError(400, 'plugin.url.required', 'A "url" field is required.', ['url']);
        }
        $url = trim($url);

        if (!self::isAllowedInstallUrl($url)) {
            return $this->jsonError(
                400,
                'plugin.url.invalid_scheme',
                'Install URL must use https:// or file:// scheme.',
                ['url'],
            );
        }

        $actor = $this->actor($request);

        try {
            $manifest = $this->loader->install($url);
        } catch (PluginInstallException $e) {
            $body = [
                'error' => $e->getMessage(),
                'code'  => 'plugin.install.failed',
            ];
            $validation = $e->validationErrors();
            if ($validation !== []) {
                $body['fields'] = array_map(
                    static fn ($err) => [
                        'field'   => $err->field,
                        'code'    => $err->code,
                        'message' => $err->message,
                    ],
                    $validation,
                );
            }
            return (new Response())->status(422)->json($body);
        }

        $this->audit->logPluginAction(
            $actor,
            'install',
            $manifest->name,
            ['source' => 'ui', 'url' => $url],
        );

        return (new Response())->status(201)->json([
            'plugin' => $this->serializeManifest($manifest),
        ]);
    }

    /**
     * Enable a previously-installed plugin.
     *
     * `POST /api/v1/admin/plugins/{name}/enable` →
     * `200 { "plugin": {"name": ..., "enabled": true} }`.
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path parameters; `name` is the manifest name.
     *
     * @return Response 200 on success, 404 if unknown, 422 if enable failed.
     *
     * @since 0.10.0 (Step A.5)
     */
    public function enable(Request $request, array $params): Response
    {
        $name = self::pluginName($params);
        if ($name === null) {
            return $this->jsonError(400, 'plugin.name.required', 'A "name" path parameter is required.');
        }

        try {
            $this->loader->enable($name);
        } catch (PluginNotFoundException $e) {
            return $this->jsonError(404, 'plugin.not_found', $e->getMessage());
        } catch (PluginEnableException $e) {
            return $this->jsonError(422, 'plugin.enable.failed', $e->getMessage());
        }

        $this->audit->logPluginAction(
            $this->actor($request),
            'enable',
            $name,
            ['source' => 'ui'],
        );

        return (new Response())->json([
            'plugin' => ['name' => $name, 'enabled' => true],
        ]);
    }

    /**
     * Disable a currently-enabled plugin.
     *
     * `POST /api/v1/admin/plugins/{name}/disable` →
     * `200 { "plugin": {"name": ..., "enabled": false} }`.
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path parameters; `name` is the manifest name.
     *
     * @return Response 200 on success, 404 if unknown.
     *
     * @since 0.10.0 (Step A.5)
     */
    public function disable(Request $request, array $params): Response
    {
        $name = self::pluginName($params);
        if ($name === null) {
            return $this->jsonError(400, 'plugin.name.required', 'A "name" path parameter is required.');
        }

        try {
            $this->loader->disable($name);
        } catch (PluginNotFoundException $e) {
            return $this->jsonError(404, 'plugin.not_found', $e->getMessage());
        }

        $this->audit->logPluginAction(
            $this->actor($request),
            'disable',
            $name,
            ['source' => 'ui'],
        );

        return (new Response())->json([
            'plugin' => ['name' => $name, 'enabled' => false],
        ]);
    }

    /**
     * Uninstall a plugin entirely (removes files + DB row).
     *
     * `DELETE /api/v1/admin/plugins/{name}` →
     * `204 No Content` on success.
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path parameters; `name` is the manifest name.
     *
     * @return Response 204 on success, 404 if unknown.
     *
     * @since 0.10.0 (Step A.5)
     */
    public function uninstall(Request $request, array $params): Response
    {
        $name = self::pluginName($params);
        if ($name === null) {
            return $this->jsonError(400, 'plugin.name.required', 'A "name" path parameter is required.');
        }

        try {
            $this->loader->uninstall($name);
        } catch (PluginNotFoundException $e) {
            return $this->jsonError(404, 'plugin.not_found', $e->getMessage());
        }

        $this->audit->logPluginAction(
            $this->actor($request),
            'uninstall',
            $name,
            ['source' => 'ui'],
        );

        return (new Response())->status(204)->json([]);
    }

    /**
     * Serialise an {@see InstalledPlugin} to its JSON-API shape.
     *
     * @return array<string, mixed>
     */
    private function serializeInstalled(InstalledPlugin $plugin): array
    {
        return [
            'id'           => $plugin->id,
            'name'         => $plugin->manifest->name,
            'version'      => $plugin->manifest->version,
            'type'         => $plugin->manifest->type,
            'entry'        => $plugin->manifest->entry,
            'enabled'      => $plugin->enabled,
            'installed_at' => $plugin->installedAt->format(\DateTimeInterface::ATOM),
            'signed'       => $plugin->manifest->signature !== null,
            'settings'     => SettingsMasker::mask($plugin),
        ];
    }

    /**
     * Render a {@see Manifest} as the install-endpoint response body.
     * Distinct from {@see self::serializeInstalled()} because we don't
     * have an installed-row id yet at install time.
     *
     * @return array<string, mixed>
     */
    private function serializeManifest(Manifest $manifest): array
    {
        return [
            'name'                     => $manifest->name,
            'version'                  => $manifest->version,
            'type'                     => $manifest->type,
            'entry'                    => $manifest->entry,
            'phlex_min_server_version' => $manifest->phlexMinServerVersion,
            'signed'                   => $manifest->signature !== null,
            'events'                   => $manifest->events,
        ];
    }

    /**
     * Extract the `{name}` path parameter, returning null if missing or empty.
     *
     * @param array<string,string> $params
     */
    private static function pluginName(array $params): ?string
    {
        $name = $params['name'] ?? '';
        $name = trim((string) $name);
        return $name === '' ? null : $name;
    }

    /**
     * Resolve the actor user id from the request, defaulting to
     * "system" if for some reason the middleware did not populate it
     * (which would itself be a bug, but we don't want the audit log to
     * silently lose attribution).
     */
    private function actor(Request $request): string
    {
        $id = $request->userId;
        return is_string($id) && $id !== '' ? $id : 'system';
    }

    /**
     * Standard JSON error body shape.
     *
     * @param int               $status  HTTP status code.
     * @param string            $code    Machine-readable error code.
     * @param string            $message Human-readable summary.
     * @param list<string>|null $fields  Optional list of offending field names.
     */
    private function jsonError(int $status, string $code, string $message, ?array $fields = null): Response
    {
        $body = ['error' => $message, 'code' => $code];
        if ($fields !== null) {
            $body['fields'] = $fields;
        }
        return (new Response())->status($status)->json($body);
    }

    /**
     * Whether the given install URL is allowed by the admin API.
     * Defence-in-depth check — the HttpInstaller enforces it too.
     */
    private static function isAllowedInstallUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme)) {
            return false;
        }
        $scheme = strtolower($scheme);
        return $scheme === 'https' || $scheme === 'file';
    }
}
