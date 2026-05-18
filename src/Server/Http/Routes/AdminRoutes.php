<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Routes;

use Phlex\Server\Http\Controllers\AuthProviderController;
use Phlex\Server\Http\Controllers\PluginAdminController;
use Phlex\Server\Http\Controllers\Stats\StatsController;
use Phlex\Plugins\Ldap\Controller\LdapAdminController;
use Phlex\Plugins\Oidc\Controller\OidcAdminController;
use Phlex\Server\Http\Middleware\AdminMiddleware;
use Phlex\Server\Http\Router;
use Psr\Container\ContainerInterface;

/**
 * Route registrar for the `/api/v1/admin/*` JSON API (Step A.5).
 *
 * Kept as a standalone registrar (rather than a one-off block in
 * `public/index.php`) so test code can build a router and call
 * {@see self::register()} against it without spinning up the whole
 * web-portal bootstrap.
 *
 * Wiring contract: the caller passes in the application's PSR-11
 * container; controllers and the middleware are resolved from it so
 * autowiring stays the single source of truth for construction.
 *
 * Routes registered:
 *
 *  - `GET    /api/v1/admin/plugins`                  → list installed
 *  - `POST   /api/v1/admin/plugins/install`          → install from URL
 *  - `POST   /api/v1/admin/plugins/{name}/enable`    → enable
 *  - `POST   /api/v1/admin/plugins/{name}/disable`   → disable
 *  - `DELETE /api/v1/admin/plugins/{name}`           → uninstall
 *
 * Every route is gated by {@see AdminMiddleware} (which requires a
 * valid JWT in `Authorization: Bearer …` AND `users.is_admin = 1`).
 *
 * @package Phlex\Server\Http\Routes
 * @since   0.10.0 (Step A.5)
 */
final class AdminRoutes
{
    /**
     * Pure-static registrar — instantiation gives nothing.
     */
    private function __construct()
    {
    }

    /**
     * Register the admin route group on the given router using the
     * given container.
     *
     * @param Router             $router    The application router.
     * @param ContainerInterface $container PSR-11 container used to
     *        resolve {@see PluginAdminController} and {@see AdminMiddleware}.
     *
     * @since 0.10.0 (Step A.5)
     */
    public static function register(Router $router, ContainerInterface $container): void
    {
        /** @var AdminMiddleware $adminMiddleware */
        $adminMiddleware = $container->get(AdminMiddleware::class);

        $router->group(
            '/api/v1/admin',
            static function (Router $r) use ($container): void {
                /** @var PluginAdminController $pluginController */
                $pluginController = $container->get(PluginAdminController::class);

                $r->get('/plugins', [$pluginController, 'index']);
                $r->post('/plugins/install', [$pluginController, 'install']);
                $r->post('/plugins/{name}/enable', [$pluginController, 'enable']);
                $r->post('/plugins/{name}/disable', [$pluginController, 'disable']);
                $r->delete('/plugins/{name}', [$pluginController, 'uninstall']);

                /** @var AuthProviderController $authProviderController */
                $authProviderController = $container->get(AuthProviderController::class);

                $r->get('/auth-providers', [$authProviderController, 'listProviders']);
                $r->post('/auth-providers/{name}/enable', [$authProviderController, 'enableProvider']);
                $r->post('/auth-providers/{name}/disable', [$authProviderController, 'disableProvider']);
                $r->get('/auth-providers/{name}/config-schema', [$authProviderController, 'getConfigSchema']);

                /** @var OidcAdminController $oidcAdminController */
                $oidcAdminController = $container->get(OidcAdminController::class);

                $r->get('/auth-providers/oidc/config', [$oidcAdminController, 'getSettings']);
                $r->post('/auth-providers/oidc/config', [$oidcAdminController, 'saveSettings']);
                $r->get('/auth-providers/oidc/schema', [$oidcAdminController, 'getSchema']);

                /** @var LdapAdminController $ldapAdminController */
                $ldapAdminController = $container->get(LdapAdminController::class);

                $r->get('/auth-providers/ldap/config', [$ldapAdminController, 'getSettings']);
                $r->post('/auth-providers/ldap/config', [$ldapAdminController, 'saveSettings']);
                $r->post('/auth-providers/ldap/test', [$ldapAdminController, 'testConnection']);
                $r->get('/auth-providers/ldap/schema', [$ldapAdminController, 'getSchema']);

                /** @var StatsController $statsController */
                $statsController = $container->get(StatsController::class);

                $r->get('/stats/playback', [$statsController, 'playback']);
                $r->get('/stats/top-users', [$statsController, 'topUsers']);
                $r->get('/stats/top-media', [$statsController, 'topMedia']);
                $r->get('/stats/storage', [$statsController, 'storage']);
            },
            [$adminMiddleware],
        );
    }
}
