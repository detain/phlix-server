<?php

/**
 * Phlix media server component: Routes.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Server\Http\Routes;

use Phlix\Server\Http\Controllers\Admin\AdminMergeController;
use Phlix\Server\Http\Controllers\Admin\AdminMetadataSourceController;
use Phlix\Server\Http\Controllers\Admin\AdminProfileController;
use Phlix\Server\Http\Controllers\Admin\AdminSettingsController;
use Phlix\Server\Http\Controllers\Admin\AdminTranscodingController;
use Phlix\Server\Http\Controllers\Admin\AdminUserController;
use Phlix\Server\Http\Controllers\Admin\AdminWebhooksController;
use Phlix\Server\Http\Controllers\Admin\BackupController;
use Phlix\Server\Http\Controllers\Admin\DashboardController;
use Phlix\Server\Http\Controllers\Admin\FsBrowseController;
use Phlix\Server\Http\Controllers\Admin\LogController;
use Phlix\Server\Http\Controllers\Admin\WatchHistoryController;
use Phlix\Server\Http\Controllers\AuthProviderController;
use Phlix\Server\Http\Controllers\PluginAdminController;
use Phlix\Server\Http\Controllers\PluginCatalogController;
use Phlix\Server\Http\Controllers\Stats\MetricsController;
use Phlix\Server\Http\Controllers\Stats\StatsController;
use Phlix\Plugins\Ldap\Controller\LdapAdminController;
use Phlix\Plugins\Oidc\Controller\OidcAdminController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Router;
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
 *  - `GET    /api/v1/admin/plugins/catalog`          → aggregated catalog + install state
 *  - `POST   /api/v1/admin/plugins/catalog/sources`  → add a catalog source
 *  - `DELETE /api/v1/admin/plugins/catalog/sources`  → remove a catalog source
 *  - `GET    /api/v1/admin/plugins/{name}`           → detail + settings schema
 *  - `PUT    /api/v1/admin/plugins/{name}/settings`  → save settings
 *  - `POST   /api/v1/admin/plugins/{name}/enable`    → enable
 *  - `POST   /api/v1/admin/plugins/{name}/disable`   → disable
 *  - `DELETE /api/v1/admin/plugins/{name}`           → uninstall
 *  - `GET    /api/v1/admin/settings`                 → effective settings
 *  - `PUT    /api/v1/admin/settings`                 → persist overrides
 *  - `GET    /api/v1/admin/fs/browse`                → list subdirectories
 *  - `GET    /api/v1/admin/libraries/{id}/duplicates` → preview duplicate groups
 *  - `POST   /api/v1/admin/media/merge`              → apply a duplicate merge
 *  - `GET    /api/v1/admin/metadata/sources`         → available metadata-source names
 *  - `GET    /api/v1/admin/watch-history`            → recent watch history (all users)
 *
 * Every route is gated by {@see AdminMiddleware} (which requires a
 * valid JWT in `Authorization: Bearer …` AND `users.is_admin = 1`).
 *
 * @package Phlix\Server\Http\Routes
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

                /** @var PluginCatalogController $catalogController */
                $catalogController = $container->get(PluginCatalogController::class);

                $r->get('/plugins', [$pluginController, 'index']);
                $r->post('/plugins/install', [$pluginController, 'install']);

                // Catalog + update routes must precede `/plugins/{name}` so the
                // literal `catalog`/`updates`/`auto-update` segments are not
                // captured as a plugin name.
                $r->get('/plugins/catalog', [$catalogController, 'index']);
                $r->post('/plugins/catalog/sources', [$catalogController, 'addSource']);
                $r->delete('/plugins/catalog/sources', [$catalogController, 'removeSource']);
                $r->get('/plugins/updates', [$catalogController, 'updates']);
                $r->post('/plugins/updates/apply', [$catalogController, 'applyUpdates']);
                $r->get('/plugins/auto-update', [$catalogController, 'autoUpdate']);
                $r->put('/plugins/auto-update', [$catalogController, 'autoUpdate']);

                $r->get('/plugins/{name}', [$pluginController, 'show']);
                $r->put('/plugins/{name}/settings', [$pluginController, 'updateSettings']);
                $r->post('/plugins/{name}/enable', [$pluginController, 'enable']);
                $r->post('/plugins/{name}/disable', [$pluginController, 'disable']);
                $r->post('/plugins/{name}/update', [$catalogController, 'updatePlugin']);
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

                /** @var MetricsController $metricsController */
                $metricsController = $container->get(MetricsController::class);
                $r->get('/metrics/snapshot', [$metricsController, 'snapshot']);
                $r->get('/metrics/history', [$metricsController, 'history']);
                $r->get('/metrics/connections', [$metricsController, 'connections']);
                $r->get('/metrics/routes', [$metricsController, 'routes']);

                /** @var DashboardController $dashboardController */
                $dashboardController = $container->get(DashboardController::class);

                $r->get('/dashboard/now-playing', [$dashboardController, 'nowPlaying']);
                $r->get('/dashboard/top-users', [$dashboardController, 'topUsers']);
                $r->get('/dashboard/top-media', [$dashboardController, 'topMedia']);
                $r->get('/dashboard/storage', [$dashboardController, 'storage']);
                $r->get('/dashboard/activity', [$dashboardController, 'activity']);

                /** @var WatchHistoryController $watchHistoryController */
                $watchHistoryController = $container->get(WatchHistoryController::class);

                $r->get('/watch-history', [$watchHistoryController, 'index']);

                /** @var BackupController $backupController */
                $backupController = $container->get(BackupController::class);

                $r->post('/backup/create', [$backupController, 'create']);
                $r->get('/backup/list', [$backupController, 'list']);
                $r->delete('/backup/{id}', [$backupController, 'delete']);
                $r->post('/backup/{id}/restore', [$backupController, 'restore']);
                $r->post('/backup/{id}/upload-s3', [$backupController, 'uploadS3']);
                $r->get('/backup/schedule', [$backupController, 'getSchedule']);
                $r->put('/backup/schedule', [$backupController, 'updateSchedule']);

                // Server-wide settings store (Step 0.5).
                /** @var AdminSettingsController $settingsController */
                $settingsController = $container->get(AdminSettingsController::class);

                $r->get('/settings', [$settingsController, 'index']);
                $r->put('/settings', [$settingsController, 'update']);

                // Hardware accelerator introspection (P6-S1).
                /** @var AdminTranscodingController $transcodingController */
                $transcodingController = $container->get(AdminTranscodingController::class);

                $r->get('/transcoding/accelerators', [$transcodingController, 'accelerators']);

                // HDR tone-mapping settings (P6-S3).
                $r->get('/transcoding/tone-mapping', [$transcodingController, 'toneMapping']);
                $r->put('/transcoding/tone-mapping', [$transcodingController, 'setToneMapping']);

                // Filesystem browse for the library path picker (Step 0.6).
                /** @var FsBrowseController $fsBrowseController */
                $fsBrowseController = $container->get(FsBrowseController::class);
                $r->get('/fs/browse', [$fsBrowseController, 'browse']);

                // Log viewer (Step 1.7): list + tail the server log files.
                /** @var LogController $logController */
                $logController = $container->get(LogController::class);
                $r->get('/logs', [$logController, 'index']);
                $r->get('/logs/tail-all', [$logController, 'tailAll']);
                $r->get('/logs/tail', [$logController, 'tail']);

                // Admin user management (Step 1.2a).
                /** @var AdminUserController $adminUserController */
                $adminUserController = $container->get(AdminUserController::class);

                $r->get('/users', [$adminUserController, 'list']);
                $r->get('/users/{id}', [$adminUserController, 'get']);
                $r->post('/users', [$adminUserController, 'create']);
                $r->put('/users/{id}', [$adminUserController, 'update']);
                $r->delete('/users/{id}', [$adminUserController, 'delete']);
                $r->post('/users/{id}/set-admin', [$adminUserController, 'setAdmin']);
                $r->post('/users/{id}/reset-password', [$adminUserController, 'resetPassword']);

                // Signup approval gate (S1): manage the pending queue.
                $r->post('/users/{id}/approve', [$adminUserController, 'approve']);
                $r->post('/users/{id}/disable', [$adminUserController, 'disable']);
                $r->post('/users/{id}/reject', [$adminUserController, 'reject']);

                // Admin profile management (Step 1.2b).
                /** @var AdminProfileController $adminProfileController */
                $adminProfileController = $container->get(AdminProfileController::class);

                $r->get('/users/{userId}/profiles', [$adminProfileController, 'listForUser']);
                $r->post('/users/{userId}/profiles', [$adminProfileController, 'createForUser']);
                $r->get('/profiles/{id}', [$adminProfileController, 'get']);
                $r->put('/profiles/{id}', [$adminProfileController, 'update']);
                $r->delete('/profiles/{id}', [$adminProfileController, 'delete']);
                $r->post('/profiles/{id}/pin', [$adminProfileController, 'setPin']);
                $r->delete('/profiles/{id}/pin', [$adminProfileController, 'deletePin']);

                // Duplicate preview + merge (Step 1.6, Feature 1).
                /** @var AdminMergeController $adminMergeController */
                $adminMergeController = $container->get(AdminMergeController::class);

                $r->get('/libraries/{id}/duplicates', [$adminMergeController, 'duplicates']);
                $r->post('/media/merge', [$adminMergeController, 'merge']);

                // Available metadata-source names for the priority editor
                // (Step 3.6, Feature 3): built-ins + registered plugin sources.
                /** @var AdminMetadataSourceController $adminMetadataSourceController */
                $adminMetadataSourceController = $container->get(AdminMetadataSourceController::class);

                $r->get('/metadata/sources', [$adminMetadataSourceController, 'index']);

                // Webhook subscriptions and deliveries (P9-S1).
                /** @var AdminWebhooksController $webhooksController */
                $webhooksController = $container->get(AdminWebhooksController::class);

                $r->get('/webhooks/subscriptions', [$webhooksController, 'listSubscriptions']);
                $r->post('/webhooks/subscriptions', [$webhooksController, 'createSubscription']);
                $r->delete('/webhooks/subscriptions/{id}', [$webhooksController, 'deleteSubscription']);
                $r->get('/webhooks/deliveries', [$webhooksController, 'getDeliveries']);
            },
            [$adminMiddleware],
        );
    }
}
