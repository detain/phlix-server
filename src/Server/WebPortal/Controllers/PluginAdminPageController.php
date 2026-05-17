<?php

declare(strict_types=1);

namespace Phlex\Server\WebPortal\Controllers;

use Phlex\Plugins\Exception\PluginNotFoundException;
use Phlex\Plugins\InstalledPlugin;
use Phlex\Plugins\PluginLoader;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Server\WebPortal\PageRenderer;

/**
 * Server-side renderer for the `/admin/plugins` HTML pages (Step A.5).
 *
 * The companion to {@see \Phlex\Server\Http\Controllers\PluginAdminController}.
 * Where the controller is the JSON API consumed by the JS layer, this
 * class renders the Smarty templates so the page works with JS
 * disabled — every enable/disable button submits a regular form to the
 * same JSON endpoint (the small JS file then progressively enhances
 * the experience to avoid a full reload).
 *
 * Templates rendered:
 *  - `admin/plugins/index.tpl`    — table + install form
 *  - `admin/plugins/detail.tpl`   — per-plugin read-only view
 *  - `admin/plugins/install.tpl`  — fallback install form for JS-off
 *
 * AuthN/AuthZ: caller MUST gate these routes behind the same
 * authenticated-admin check that {@see \Phlex\Server\Http\Middleware\AdminMiddleware}
 * applies to the JSON API. The page controller does NOT re-validate
 * (single source of truth for auth lives in the middleware).
 *
 * @package Phlex\Server\WebPortal\Controllers
 * @since   0.10.0 (Step A.5)
 */
final class PluginAdminPageController
{
    /**
     * @param PluginLoader $loader      Lifecycle facade (read-only here).
     * @param string       $templateDir Absolute path to the Smarty template root.
     */
    public function __construct(
        private readonly PluginLoader $loader,
        private readonly string $templateDir,
    ) {
    }

    /**
     * Render the plugin list page.
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @return Response HTML response built from `admin/plugins/index.tpl`.
     *
     * @since 0.10.0 (Step A.5)
     */
    public function index(Request $request, array $params): Response
    {
        $plugins = array_map([$this, 'viewModel'], $this->loader->listInstalled());
        $html = $this->render('admin/plugins/index.tpl', [
            'current_page' => 'admin_plugins',
            'plugins'      => $plugins,
        ]);
        return (new Response())->html($html);
    }

    /**
     * Render the per-plugin detail page. Settings flagged
     * `secret: true` in the manifest are masked.
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path parameters; `name` required.
     *
     * @return Response HTML detail page, or 404 page if unknown.
     *
     * @since 0.10.0 (Step A.5)
     */
    public function detail(Request $request, array $params): Response
    {
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            return (new Response())->status(400)->html('<h1>400 — name required</h1>');
        }

        try {
            $installed = $this->loadOne($name);
        } catch (PluginNotFoundException) {
            return (new Response())->status(404)->html('<h1>404 — plugin not found</h1>');
        }

        $html = $this->render('admin/plugins/detail.tpl', [
            'current_page' => 'admin_plugins',
            'plugin'       => $this->viewModel($installed),
            'settings'     => self::maskedSettingsView($installed),
        ]);
        return (new Response())->html($html);
    }

    /**
     * Render the standalone install form (JS-off fallback).
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @return Response HTML response built from `admin/plugins/install.tpl`.
     *
     * @since 0.10.0 (Step A.5)
     */
    public function install(Request $request, array $params): Response
    {
        $html = $this->render('admin/plugins/install.tpl', [
            'current_page' => 'admin_plugins',
        ]);
        return (new Response())->html($html);
    }

    /**
     * Translate {@see InstalledPlugin} into the flat array shape the
     * templates expect. Done here (not in the template) so XSS-prone
     * fields are easier to audit.
     *
     * @return array<string, mixed>
     */
    private function viewModel(InstalledPlugin $plugin): array
    {
        return [
            'id'           => $plugin->id,
            'name'         => $plugin->manifest->name,
            'version'      => $plugin->manifest->version,
            'type'         => $plugin->manifest->type,
            'entry'        => $plugin->manifest->entry,
            'enabled'      => $plugin->enabled,
            'installed_at' => $plugin->installedAt->format('Y-m-d H:i'),
            'signed'       => $plugin->manifest->signature !== null,
        ];
    }

    /**
     * Mask any setting flagged `secret: true` in the manifest. Returns
     * a list of `[key, type, value, secret]` rows suitable for the
     * Smarty `{foreach}`.
     *
     * @return list<array{key:string, type:string, value:mixed, secret:bool}>
     */
    private static function maskedSettingsView(InstalledPlugin $plugin): array
    {
        $rows = [];
        foreach ($plugin->manifest->settings as $key => $schema) {
            $isSecret = isset($schema['secret']) && $schema['secret'] === true;
            $value = $plugin->settings[$key] ?? null;
            if ($isSecret && $value !== null) {
                $value = '***';
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

    /**
     * Fetch a single installed plugin by name. Wrapped so the page
     * controller doesn't need to filter the listInstalled() output.
     *
     * @throws PluginNotFoundException When no installed plugin matches `$name`.
     */
    private function loadOne(string $name): InstalledPlugin
    {
        foreach ($this->loader->listInstalled() as $plugin) {
            if ($plugin->manifest->name === $name) {
                return $plugin;
            }
        }
        throw new PluginNotFoundException(sprintf('No installed plugin named "%s".', $name));
    }

    /**
     * Render a template via the shared
     * {@see PageRenderer::renderTemplate()} helper, which applies the
     * default-on HTML escaping policy in a single place.
     *
     * @param string               $template Template path relative to {@see self::$templateDir}.
     * @param array<string, mixed> $vars     Variables to assign before fetching.
     */
    private function render(string $template, array $vars): string
    {
        return PageRenderer::renderTemplate($this->templateDir, $template, $vars);
    }
}
