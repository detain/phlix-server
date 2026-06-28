<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Plugins\Exception\PluginEnableException;
use Phlix\Plugins\Exception\PluginInstallException;
use Phlix\Plugins\Exception\PluginNotFoundException;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Installer\SourceUrlResolver;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use Phlix\Plugins\SettingsMasker;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * JSON API for the admin-only plugin lifecycle (Step A.5).
 *
 * Endpoints (all wired via
 * {@see \Phlix\Server\Http\Routes\AdminRoutes} under the
 * `/api/v1/admin` group, with
 * {@see \Phlix\Server\Http\Middleware\AdminMiddleware} in front):
 *
 *  - `GET    /api/v1/admin/plugins`                    → list installed
 *  - `POST   /api/v1/admin/plugins/install`            → install from URL
 *  - `GET    /api/v1/admin/plugins/{name}`             → detail + settings schema
 *  - `PUT    /api/v1/admin/plugins/{name}/settings`    → save settings
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
 * {@see \Phlix\Server\Http\Middleware\AdminMiddleware} guarantees is set
 * and admin.
 *
 * CSRF: the API is Bearer-token authenticated, so it is not subject to
 * cross-site cookie attacks. The middleware refuses anonymous traffic
 * with 401 before this controller ever sees the request.
 *
 * @package Phlix\Server\Http\Controllers
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
     * Detail for a single installed plugin, including its manifest
     * settings SCHEMA (so the admin UI can render a configure form) and
     * its current persisted VALUES with secrets masked.
     *
     * `GET /api/v1/admin/plugins/{name}` →
     * `200 { "plugin": { name, version, type, enabled, installed_at,
     *        settings_schema: {key:{type,required,secret,label,description,default?}},
     *        settings: {key: value-or-***} } }`.
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path parameters; `name` is the manifest name.
     *
     * @return Response 200 + detail, 404 if not found.
     *
     * @since 0.12.0 (S6 — plugin configure endpoint)
     */
    public function show(Request $request, array $params): Response
    {
        $name = self::pluginName($params);
        if ($name === null) {
            return $this->jsonError(400, 'plugin.name.required', 'A "name" path parameter is required.');
        }

        try {
            $plugin = $this->loader->getInstalled($name);
        } catch (PluginNotFoundException $e) {
            return $this->jsonError(404, 'plugin.not_found', $e->getMessage());
        }

        return (new Response())->json(['plugin' => $this->serializeDetail($plugin)]);
    }

    /**
     * Persist a plugin's settings from the configure form.
     *
     * `PUT /api/v1/admin/plugins/{name}/settings` body
     * `{ "settings": { "<key>": <value>, ... } }`.
     *
     * Validation mirrors {@see AdminSettingsController}:
     *  - every submitted key must EXIST in the manifest settings schema
     *    (unknown keys → 400 with the offending key);
     *  - each value's TYPE must match the manifest descriptor (string /
     *    boolean / integer / number) → 400 on mismatch.
     *
     * Secret handling: when a secret field's submitted value is the mask
     * sentinel {@see SettingsMasker::MASK} (the UI echoed the masked value
     * back unchanged) the stored secret is KEPT — only a genuinely new
     * value overwrites it. Otherwise saving the form would wipe API keys.
     *
     * The accepted keys are merged OVER the existing settings (keys the
     * UI did not send are preserved) and persisted via
     * {@see PluginLoader::updateSettings()}. Returns the refreshed detail
     * (same shape as {@see self::show()}, secrets masked).
     *
     * @param Request              $request The HTTP request (`body.settings`).
     * @param array<string,string> $params  Path parameters; `name` is the manifest name.
     *
     * @return Response 200 + refreshed detail, 400 on validation error, 404 if not found.
     *
     * @since 0.12.0 (S6 — plugin configure endpoint)
     */
    public function updateSettings(Request $request, array $params): Response
    {
        $name = self::pluginName($params);
        if ($name === null) {
            return $this->jsonError(400, 'plugin.name.required', 'A "name" path parameter is required.');
        }

        try {
            $plugin = $this->loader->getInstalled($name);
        } catch (PluginNotFoundException $e) {
            return $this->jsonError(404, 'plugin.not_found', $e->getMessage());
        }

        $submitted = $request->input('settings');
        if (!is_array($submitted)) {
            return $this->jsonError(
                400,
                'plugin.settings.invalid',
                'Body must contain a "settings" object.',
                ['settings'],
            );
        }

        // Normalize the manifest descriptors (guarantees a string 'type',
        // defaulting to 'mixed' for malformed/typeless descriptors so a bad
        // manifest can't trigger a 500 here).
        $schema = SettingsMasker::schema($plugin);
        $errors = [];
        /** @var array<string, mixed> $accepted */
        $accepted = [];

        /** @var mixed $value */
        foreach ($submitted as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $schema)) {
                $errors[is_string($key) ? $key : (string) $key] = 'Unknown setting key.';
                continue;
            }

            $descriptor = $schema[$key];
            $type     = $descriptor['type'];
            $isSecret = $descriptor['secret'] === true;

            // Secret echoed back unchanged → keep the stored value, skip.
            if ($isSecret && $value === SettingsMasker::MASK) {
                continue;
            }

            if (!self::valueMatchesType($value, $type)) {
                $errors[$key] = sprintf('Expected type %s.', $type);
                continue;
            }

            $accepted[$key] = self::coerceValue($value, $type);
        }

        if ($errors !== []) {
            return (new Response())->status(400)->json([
                'error'  => 'Validation failed.',
                'code'   => 'plugin.settings.validation_failed',
                'errors' => $errors,
            ]);
        }

        // Merge accepted values over the existing settings so keys the UI
        // did not send (including untouched secrets) are preserved.
        $merged = array_merge($plugin->settings, $accepted);
        $this->loader->updateSettings($name, $merged);

        $this->audit->logPluginAction(
            $this->actor($request),
            'configure',
            $name,
            ['source' => 'ui', 'keys' => array_keys($accepted)],
        );

        $refreshed = $this->loader->getInstalled($name);
        return (new Response())->json(['plugin' => $this->serializeDetail($refreshed)]);
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
     * installer would tolerate it via `PHLIX_PLUGINS_ALLOW_HTTP=1`,
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

        // Repository URLs (e.g. https://github.com/owner/repo) are rewritten
        // to a tarball before the scheme is validated, so a scheme-less
        // `github.com/owner/repo` paste is accepted rather than 400-rejected.
        $normalized = SourceUrlResolver::normalize($url);
        if (!self::isAllowedInstallUrl($normalized)) {
            return $this->jsonError(
                400,
                'plugin.url.invalid_scheme',
                'Install URL must be an https:// archive or repository URL (or file:// for local sources).',
                ['url'],
            );
        }

        // SSRF guard on the resolved http(s) install URL: reject loopback/
        // link-local/private/metadata targets at admin-config time. `file://`
        // sources are exempt (isAllowedInstallUrl permits them and they are
        // operator-local). Blocking DNS here is fine — this is an operator
        // admin action, off the media-serving hot path.
        $scheme = strtolower((string) (parse_url($normalized, PHP_URL_SCHEME) ?? ''));
        if ($scheme === 'http' || $scheme === 'https') {
            try {
                SsrfGuard::assertPublicUrl($normalized);
            } catch (\InvalidArgumentException $e) {
                return $this->jsonError(400, 'plugin.url.blocked', $e->getMessage(), ['url']);
            }
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
     * Serialise an {@see InstalledPlugin} to the configure-form detail
     * shape: identity + manifest settings schema + masked current values.
     *
     * @return array<string, mixed>
     *
     * @since 0.12.0 (S6 — plugin configure endpoint)
     */
    private function serializeDetail(InstalledPlugin $plugin): array
    {
        return [
            'name'            => $plugin->manifest->name,
            'version'         => $plugin->manifest->version,
            'type'            => $plugin->manifest->type,
            'enabled'         => $plugin->enabled,
            'installed_at'    => $plugin->installedAt->format(\DateTimeInterface::ATOM),
            'settings_schema' => SettingsMasker::schema($plugin),
            'settings'        => SettingsMasker::mask($plugin),
        ];
    }

    /**
     * Whether a raw submitted value is acceptable for a manifest setting
     * type. Mirrors {@see \Phlix\Server\Http\Controllers\Admin\AdminSettingsController}
     * but keyed on the manifest's JSON-Schema-style type vocabulary
     * (`string`/`boolean`/`integer`/`number`); unknown types accept any
     * scalar/array so the UI is never blocked by an exotic descriptor.
     *
     * Numeric strings are accepted for integer/number and the canonical
     * bool-ish set for boolean (JSON/form bodies often arrive as strings).
     *
     * @param mixed  $value Raw submitted value.
     * @param string $type  Manifest setting type.
     */
    private static function valueMatchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'boolean', 'bool' => is_bool($value)
                || (is_int($value) && ($value === 0 || $value === 1))
                || (is_string($value) && in_array(strtolower($value), ['0', '1', 'true', 'false'], true)),
            'integer', 'int' => is_int($value)
                || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'number', 'float' => is_int($value) || is_float($value)
                || (is_string($value) && is_numeric($value)),
            'string' => is_string($value),
            'array', 'object', 'json' => is_array($value),
            default  => is_scalar($value) || is_array($value),
        };
    }

    /**
     * Coerce a validated raw value into its canonical PHP type for
     * storage. Mirrors {@see \Phlix\Server\Http\Controllers\Admin\AdminSettingsController::coerce()}.
     *
     * @param mixed  $value Raw submitted value (already type-validated).
     * @param string $type  Manifest setting type.
     */
    private static function coerceValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean', 'bool' => is_bool($value)
                ? $value
                : (is_string($value) ? in_array(strtolower($value), ['1', 'true'], true) : (bool) $value),
            'integer', 'int' => (int) (is_numeric($value) ? $value : 0),
            'number', 'float' => (float) (is_numeric($value) ? $value : 0),
            default => $value,
        };
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
            'phlix_min_server_version' => $manifest->phlixMinServerVersion,
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
