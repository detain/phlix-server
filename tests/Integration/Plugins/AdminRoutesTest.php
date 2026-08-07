<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Plugins;

use DateTimeImmutable;
use Phlix\Access\AccessScheduleService;
use Phlix\Access\ProfileAccessPolicy;
use Phlix\Access\ProfileTagService;
use Phlix\Access\StreamSessionService;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\DuplicateFinder;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\PathDeduper;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Plugins\Exception\PluginNotFoundException;
use Phlix\Plugins\Github\Controller\GithubAdminController;
use Phlix\Plugins\Github\Plugin as GithubPlugin;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Ldap\Controller\LdapAdminController;
use Phlix\Plugins\Ldap\Plugin as LdapPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\Oidc\Controller\OidcAdminController;
use Phlix\Plugins\Oidc\Plugin;
use Phlix\Plugins\PluginLoader;
use Phlix\Webhooks\WebhookService;
use Phlix\Admin\BackupManager;
use Phlix\Admin\DashboardService;
use Phlix\Admin\Maintenance\MaintenanceJobRepository;
use Phlix\Admin\Maintenance\MaintenanceTaskRunner;
use Phlix\Admin\SettingsRepository;
use Phlix\Admin\WatchHistoryService;
use Phlix\Server\Http\Controllers\Admin\AdminMergeController;
use Phlix\Server\Http\Controllers\Admin\AdminMetadataSourceController;
use Phlix\Server\Http\Controllers\Admin\AdminProfileController;
use Phlix\Server\Http\Controllers\Admin\AdminRestartController;
use Phlix\Server\Http\Controllers\Admin\AdminSettingsController;
use Phlix\Server\Http\Controllers\Admin\AdminTranscodingController;
use Phlix\Server\Http\Controllers\Admin\AdminUpdatesController;
use Phlix\Server\Http\Controllers\Admin\AdminUserController;
use Phlix\Server\Http\Controllers\Admin\AdminWebhooksController;
use Phlix\Server\Http\Controllers\Admin\BackupController;
use Phlix\Server\Http\Controllers\Admin\DashboardController;
use Phlix\Server\Http\Controllers\Admin\FsBrowseController;
use Phlix\Server\Http\Controllers\Admin\LogController;
use Phlix\Server\Http\Controllers\Admin\MaintenanceController;
use Phlix\Server\Http\Controllers\Admin\WatchHistoryController;
use Phlix\Server\Http\Controllers\AccessScheduleController;
use Phlix\Server\Http\Controllers\AuthProviderController;
use Phlix\Server\Http\Controllers\ProfileTagController;
use Phlix\Server\Http\Controllers\StreamLimitController;
use Phlix\Server\Updates\CoreUpdateCheckService;
use Phlix\Server\Updates\VersionMarkerFetcherInterface;
use Phlix\Plugins\Catalog\PluginCatalogService;
use Phlix\Plugins\Catalog\PluginUpdateService;
use Phlix\Server\Http\Controllers\PluginAdminController;
use Phlix\Server\Http\Controllers\PluginCatalogController;
use Phlix\Server\Http\Controllers\Stats\MetricsController;
use Phlix\Server\Http\Controllers\Stats\StatsController;
use Phlix\Stats\Metrics\MetricsRepositoryInterface;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Router;
use Phlix\Server\Http\Routes\AdminRoutes;
use Phlix\Stats\StatsCollector;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

/**
 * End-to-end exercise of the /api/v1/admin/plugins routes (Step A.5).
 *
 * Boots a real {@see Router}, registers the {@see AdminRoutes} group
 * against a hand-rolled PSR-11 container that hands out stubbed
 * loader / repository / audit-logger collaborators, then sends
 * synthetic {@see Request} objects through the router and asserts both
 * the HTTP response and the side-effects on the collaborators.
 */
final class AdminRoutesTest extends TestCase
{
    private Router $router;
    private FakePluginLoader $loader;
    private FakeUserRepository $users;
    private FakeAuditLogger $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = new FakePluginLoader();
        $this->users  = new FakeUserRepository();
        $this->audit  = new FakeAuditLogger();

        // Hand-rolled PSR-11 to avoid wiring the full DI tree just to
        // run the router. Plugin-related collaborators are real
        // fakes (FakePluginLoader, FakeUserRepository, FakeAuditLogger);
        // the Stats/Dashboard/Backup controllers are stubbed because
        // AdminRoutes::register() eagerly resolves them at bind time
        // but the plugin-only tests below never actually dispatch
        // requests to those routes.
        $statsController     = new StatsController(new FakeStatsCollector());
        $dashboardController = new DashboardController(FakeDashboardService::make());
        $backupController    = new BackupController(FakeBackupManager::make());
        $settingsController  = new AdminSettingsController(
            new SettingsRepository($this->createMock(Connection::class)),
        );
        $fsBrowseController  = new FsBrowseController([sys_get_temp_dir()]);
        $logController       = new LogController(sys_get_temp_dir());
        $adminUserController  = new AdminUserController($this->users);
        $profileManager = new FakeUserProfileManager();
        $adminProfileController = new AdminProfileController($profileManager, $this->users);
        // Duplicate preview + merge controller (Step 1.6). AdminRoutes::register()
        // eagerly resolves it at bind time; the plugin-only tests below never
        // dispatch to /libraries/{id}/duplicates or /media/merge, so a stub
        // ItemRepository + null merger (preview-only) is sufficient here.
        $mergeItemRepository = new ItemRepository($this->createMock(Connection::class));
        $adminMergeController = new AdminMergeController(
            $mergeItemRepository,
            new DuplicateFinder($mergeItemRepository),
            null,
        );
        // Metadata-source name list controller (Step 3.6). AdminRoutes::register()
        // eagerly resolves it at bind time; the plugin-only tests below never
        // dispatch to /metadata/sources, so an empty SourceRegistry is
        // sufficient here (the dedicated gating tests below DO dispatch to it).
        $sourceRegistry = new SourceRegistry();
        $adminMetadataSourceController = new AdminMetadataSourceController($sourceRegistry);
        // Cross-user watch-history controller (Step S4). AdminRoutes::register()
        // eagerly resolves it at bind time; the plugin-only tests below never
        // dispatch to /watch-history, so a service over a mocked Connection is
        // sufficient here.
        $watchHistoryController = new WatchHistoryController(
            new WatchHistoryService($this->createMock(Connection::class)),
        );
        // Catalog controller: a real service wired to a stub SettingsRepository
        // and an offline fetcher (the lifecycle tests never hit the network).
        $catalogService = new PluginCatalogService(
            new SettingsRepository($this->createMock(Connection::class)),
            static fn (string $url, int $timeout): string =>
                throw new \RuntimeException('catalog fetch disabled in tests'),
        );
        $pluginCatalogController = new PluginCatalogController(
            $catalogService,
            $this->loader,
            $this->audit,
            new PluginUpdateService(
                $this->loader,
                $catalogService,
                static fn (string $url, int $timeout): string =>
                    throw new \RuntimeException('update fetch disabled in tests'),
            ),
        );

        // Metrics controller (Step S2). AdminRoutes::register() eagerly resolves
        // it at bind time; the plugin-only tests below never dispatch to
        // /metrics/*, so a MetricsController over a mocked repository interface
        // is sufficient here.
        $metricsController = new MetricsController(
            $this->createMock(MetricsRepositoryInterface::class),
        );

        // Hardware accelerator introspection (P6-S1). AdminRoutes::register()
        // eagerly resolves it at bind time; the plugin-only tests below never
        // dispatch to /transcoding/*, so a mock FfmpegRunner is sufficient here.
        $ffmpegRunner = $this->createMock(FfmpegRunner::class);
        $adminTranscodingController = new AdminTranscodingController($ffmpegRunner, []);

        // Webhook controller (P9-S1). AdminRoutes::register() eagerly resolves
        // it at bind time; the plugin-only tests below never dispatch to
        // /webhooks/*, so a mock WebhookService is sufficient here.
        $webhookService = $this->createMock(WebhookService::class);
        $adminWebhooksController = new AdminWebhooksController($webhookService);

        // Parental-controls controllers (S208). AdminRoutes::register() eagerly
        // resolves all three at bind time; the plugin-only tests below never
        // dispatch to /profiles/{id}/schedules|tags|stream-limits, so services
        // over a mocked Connection are sufficient here. The access policy is
        // built from the same in-memory fakes the admin middleware uses, so the
        // gating tests further down see a coherent user/profile world.
        $parentalDb = $this->createMock(Connection::class);
        $profileAccessPolicy = new ProfileAccessPolicy($profileManager, $this->users);
        $accessScheduleController = new AccessScheduleController(
            new AccessScheduleService($parentalDb),
            $profileAccessPolicy,
        );
        $profileTagController = new ProfileTagController(
            new ProfileTagService($parentalDb),
            $profileAccessPolicy,
        );
        $streamLimitController = new StreamLimitController(
            new StreamSessionService($parentalDb),
            $profileAccessPolicy,
        );

        // Core update-check controller (S74). AdminRoutes::register() eagerly
        // resolves it at bind time; the plugin-only tests below never dispatch
        // to /updates/*, so a service over a mocked Connection is sufficient. The
        // fetcher would throw if it were ever reached — nothing here polls.
        $adminUpdatesController = new AdminUpdatesController(
            new CoreUpdateCheckService(
                new SettingsRepository($this->createMock(Connection::class)),
                new class implements VersionMarkerFetcherInterface {
                    public function fetch(string $url, callable $onDone): void
                    {
                        throw new \RuntimeException('No update-marker fetch expected in this test: ' . $url);
                    }
                },
                $this->createMock(StructuredLogger::class),
                'https://example.invalid/VERSION',
                'noop',
            ),
            new AdminMiddleware($this->users, $this->audit),
        );

        // Maintenance tasks (S77). AdminRoutes::register() eagerly resolves this
        // at bind time too, so the stub container must bind it or every route
        // test in this class dies during registration. The plugin-only tests
        // below never dispatch to /maintenance/*, so a controller over mocked
        // collaborators is sufficient.
        $maintenanceDb = $this->createMock(Connection::class);
        $maintenanceController = new MaintenanceController(
            new MaintenanceJobRepository($maintenanceDb),
            new MaintenanceTaskRunner(
                $maintenanceDb,
                new ScanJobRepository($maintenanceDb),
                new PathDeduper($maintenanceDb),
            ),
            new AdminMiddleware($this->users, $this->audit),
        );

        $container = new class (
            $this->loader,
            $this->users,
            $this->audit,
            $statsController,
            $dashboardController,
            $backupController,
            $settingsController,
            $fsBrowseController,
            $logController,
            $adminUserController,
            $profileManager,
            $adminProfileController,
            $adminMergeController,
            $adminMetadataSourceController,
            $watchHistoryController,
            $pluginCatalogController,
            $catalogService,
            $metricsController,
            $adminTranscodingController,
            $adminWebhooksController,
            new SettingsRepository($this->createMock(Connection::class)),
            $accessScheduleController,
            $profileTagController,
            $streamLimitController,
            $adminUpdatesController,
            $maintenanceController,
        ) implements ContainerInterface {
            private Plugin $oidcPlugin;
            private LdapPlugin $ldapPlugin;
            private GithubPlugin $githubPlugin;

            public function __construct(
                private readonly FakePluginLoader $loader,
                private readonly FakeUserRepository $users,
                private readonly FakeAuditLogger $audit,
                private readonly StatsController $statsController,
                private readonly DashboardController $dashboardController,
                private readonly BackupController $backupController,
                private readonly AdminSettingsController $settingsController,
                private readonly FsBrowseController $fsBrowseController,
                private readonly LogController $logController,
                private readonly AdminUserController $adminUserController,
                private readonly FakeUserProfileManager $profileManager,
                private readonly AdminProfileController $adminProfileController,
                private readonly AdminMergeController $adminMergeController,
                private readonly AdminMetadataSourceController $adminMetadataSourceController,
                private readonly WatchHistoryController $watchHistoryController,
                private readonly PluginCatalogController $pluginCatalogController,
                private readonly PluginCatalogService $pluginCatalogService,
                private readonly MetricsController $metricsController,
                private readonly AdminTranscodingController $adminTranscodingController,
                private readonly AdminWebhooksController $adminWebhooksController,
                private readonly SettingsRepository $settingsRepository,
                private readonly AccessScheduleController $accessScheduleController,
                private readonly ProfileTagController $profileTagController,
                private readonly StreamLimitController $streamLimitController,
                private readonly AdminUpdatesController $adminUpdatesController,
                private readonly MaintenanceController $maintenanceController,
            ) {
                $tempDir = sys_get_temp_dir() . '/phlix_oidc_test_' . uniqid('', true);
                mkdir($tempDir, 0775, true);
                Plugin::setPluginDirectory($tempDir);
                $this->oidcPlugin = new Plugin();

                $ldapTempDir = sys_get_temp_dir() . '/phlix_ldap_test_' . uniqid('', true);
                mkdir($ldapTempDir, 0775, true);
                LdapPlugin::setPluginDirectory($ldapTempDir);
                $this->ldapPlugin = new LdapPlugin();

                $githubTempDir = sys_get_temp_dir() . '/phlix_github_test_' . uniqid('', true);
                mkdir($githubTempDir, 0775, true);
                GithubPlugin::setPluginDirectory($githubTempDir);
                $this->githubPlugin = new GithubPlugin();
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    PluginAdminController::class => new PluginAdminController(
                        $this->loader,
                        $this->audit,
                        $this->pluginCatalogService,
                    ),
                    PluginCatalogController::class => $this->pluginCatalogController,
                    AdminMiddleware::class => new AdminMiddleware(
                        $this->users,
                        $this->audit,
                    ),
                    AuthProviderController::class => (function (): AuthProviderController {
                        $registry = new AuthProviderRegistry();
                        return new AuthProviderController(
                            $registry,
                            new AuthProviderBootstrapper(
                                $this->settingsRepository,
                                $registry,
                                $this->oidcPlugin,
                                $this->ldapPlugin,
                                $this->githubPlugin,
                            ),
                        );
                    })(),
                    OidcAdminController::class => new OidcAdminController(
                        $this->oidcPlugin,
                    ),
                    LdapAdminController::class => new LdapAdminController(
                        $this->ldapPlugin,
                    ),
                    GithubAdminController::class => new GithubAdminController(
                        $this->githubPlugin,
                    ),
                    StatsController::class     => $this->statsController,
                    DashboardController::class => $this->dashboardController,
                    BackupController::class    => $this->backupController,
                    AdminSettingsController::class => $this->settingsController,
                    // POST /api/v1/admin/restart is registered by AdminRoutes; the
                    // stub container must bind it or every route test in this class
                    // dies during registration. Pointed at a path that does not exist
                    // so it can never signal anything from a test run.
                    AdminRestartController::class => new AdminRestartController(
                        '/nonexistent/phlix-test-pid',
                    ),
                    FsBrowseController::class => $this->fsBrowseController,
                    LogController::class => $this->logController,
                    AdminUserController::class => $this->adminUserController,
                    UserProfileManager::class => $this->profileManager,
                    AdminProfileController::class => $this->adminProfileController,
                    AdminMergeController::class => $this->adminMergeController,
                    AdminMetadataSourceController::class => $this->adminMetadataSourceController,
                    WatchHistoryController::class => $this->watchHistoryController,
                    MetricsController::class => $this->metricsController,
                    AdminTranscodingController::class => $this->adminTranscodingController,
                    AdminWebhooksController::class => $this->adminWebhooksController,
                    AccessScheduleController::class => $this->accessScheduleController,
                    ProfileTagController::class => $this->profileTagController,
                    StreamLimitController::class => $this->streamLimitController,
                    AdminUpdatesController::class => $this->adminUpdatesController,
                    MaintenanceController::class => $this->maintenanceController,
                    default => throw new \RuntimeException("no binding for $id"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    PluginAdminController::class,
                    PluginCatalogController::class,
                    AdminMiddleware::class,
                    AuthProviderController::class,
                    OidcAdminController::class,
                    LdapAdminController::class,
                    GithubAdminController::class,
                    StatsController::class,
                    DashboardController::class,
                    BackupController::class,
                    AdminSettingsController::class,
                    AdminRestartController::class,
                    FsBrowseController::class,
                    LogController::class,
                    AdminUserController::class,
                    UserProfileManager::class,
                    AdminProfileController::class,
                    AdminMergeController::class,
                    AdminMetadataSourceController::class,
                    WatchHistoryController::class,
                    MetricsController::class,
                    AdminTranscodingController::class,
                    AdminWebhooksController::class,
                    AccessScheduleController::class,
                    ProfileTagController::class,
                    StreamLimitController::class,
                    AdminUpdatesController::class,
                    MaintenanceController::class,
                ], true);
            }
        };

        $this->router = new Router();
        AdminRoutes::register($this->router, $container);
    }

    public function test_anonymous_request_is_rejected_with_401(): void
    {
        $response = $this->router->dispatch($this->request('GET', '/api/v1/admin/plugins', null));
        $this->assertSame(401, $response->statusCode);
    }

    public function test_non_admin_request_is_rejected_with_403(): void
    {
        // user-2 is known but not admin
        $this->users->register('user-2', false);
        $response = $this->router->dispatch($this->request('GET', '/api/v1/admin/plugins', 'user-2'));
        $this->assertSame(403, $response->statusCode);
        $this->assertSame(1, $this->audit->permissionDenied);
    }

    public function test_merge_routes_are_registered_and_admin_gated(): void
    {
        // Step 1.6: both merge routes must be reachable through
        // AdminRoutes::register() — the single registration that BOTH entry
        // points (Application.php daemon + public/index.php web portal) call.
        // Anonymous callers hit the AdminMiddleware 401 (proving the routes
        // exist and are inside the admin group, not a 404 from an unregistered
        // path).
        $duplicates = $this->router->dispatch(
            $this->request('GET', '/api/v1/admin/libraries/lib-1/duplicates', null),
        );
        $this->assertSame(401, $duplicates->statusCode);

        $merge = $this->router->dispatch(
            $this->request('POST', '/api/v1/admin/media/merge', null),
        );
        $this->assertSame(401, $merge->statusCode);
    }

    public function test_metadata_sources_route_is_registered_and_admin_gated(): void
    {
        // Step 3.6: the metadata-source list route must be reachable through
        // AdminRoutes::register() — the single registration that BOTH entry
        // points (Application.php daemon + public/index.php web portal) call.
        // An anonymous caller hits the AdminMiddleware 401 (proving the route
        // exists inside the admin group, not a 404 from an unregistered path).
        $response = $this->router->dispatch(
            $this->request('GET', '/api/v1/admin/metadata/sources', null),
        );
        $this->assertSame(401, $response->statusCode);
    }

    public function test_metadata_sources_route_reaches_controller_for_admin(): void
    {
        // An admin GET to the route must reach AdminMetadataSourceController and
        // return the 200 `{sources: …}` envelope with the built-ins (the test
        // container's SourceRegistry is empty), NOT a 404 — confirming the
        // route binds to the controller.
        $this->users->register('admin-1', true);

        $response = $this->router->dispatch(
            $this->request('GET', '/api/v1/admin/metadata/sources', 'admin-1'),
        );

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('sources', $body);
        $this->assertSame(['tmdb', 'imdb', 'tvdb', 'fanart', 'local'], $body['sources']);
    }

    public function test_duplicates_preview_route_reaches_controller_for_admin(): void
    {
        // An admin GET to the preview route must reach AdminMergeController and
        // return the 200 `{groups: …}` envelope (empty here: the stub repo has
        // no rows), NOT a 404 — confirming the route binds to the controller.
        $this->users->register('admin-1', true);

        $response = $this->router->dispatch(
            $this->request('GET', '/api/v1/admin/libraries/lib-1/duplicates', 'admin-1'),
        );

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('groups', $body);
        $this->assertSame([], $body['groups']);
    }

    public function test_merge_apply_route_503_when_no_transactional_merger(): void
    {
        // The test container wires AdminMergeController with a null merger
        // (preview-only), so an admin POST reaches the controller and returns
        // 503 — proving the route binds to merge() (not a 404).
        $this->users->register('admin-1', true);

        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/media/merge',
            'admin-1',
            ['primary_id' => 'p-1', 'duplicate_ids' => ['d-1']],
        ));

        $this->assertSame(503, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('error', $body);
    }

    public function test_admin_request_lists_plugins(): void
    {
        $this->users->register('admin-1', true);
        $this->loader->installed[] = $this->fixturePlugin('phlix-plugin-demo', enabled: true);

        $response = $this->router->dispatch($this->request('GET', '/api/v1/admin/plugins', 'admin-1'));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertCount(1, $body['plugins']);
        $this->assertSame('phlix-plugin-demo', $body['plugins'][0]['name']);
    }

    public function test_catalog_route_precedes_plugin_name_route(): void
    {
        // Regression guard: `GET /plugins/catalog` must hit the catalog
        // controller (200 + `default_source`), NOT be captured by the
        // `/plugins/{name}` route as a plugin literally named "catalog"
        // (which would 404). The offline fetcher makes the default catalog
        // fail, so `catalogs` is empty and the failure surfaces in `errors`.
        $this->users->register('admin-1', true);

        $response = $this->router->dispatch($this->request('GET', '/api/v1/admin/plugins/catalog', 'admin-1'));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('default_source', $body);
        $this->assertArrayHasKey('sources', $body);
        $this->assertArrayHasKey('catalogs', $body);
        $this->assertArrayHasKey('errors', $body);
        $this->assertNotEmpty($body['errors']);
    }

    public function test_updates_route_precedes_plugin_name_route(): void
    {
        // `GET /plugins/updates` must hit the catalog controller's update check
        // (200 + `updates`/`available`), not be captured as a plugin named
        // "updates". No plugins installed → empty, zero available.
        $this->users->register('admin-1', true);

        $response = $this->router->dispatch($this->request('GET', '/api/v1/admin/plugins/updates', 'admin-1'));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('updates', $body);
        $this->assertArrayHasKey('available', $body);
        $this->assertArrayHasKey('auto_update', $body);
        $this->assertSame(0, $body['available']);
    }

    public function test_catalog_channel_route_is_registered_and_admin_gated(): void
    {
        // S27: `GET /plugins/catalog/channel` must be reachable through
        // AdminRoutes::register() and sit inside the admin group. An anonymous
        // caller hits the AdminMiddleware 401 (route exists + gated), NOT a 404
        // from an unregistered path — and NOT a capture by `/plugins/{name}`.
        $response = $this->router->dispatch(
            $this->request('GET', '/api/v1/admin/plugins/catalog/channel', null),
        );

        $this->assertSame(401, $response->statusCode);
        $this->assertNotSame(404, $response->statusCode);
    }

    public function test_catalog_channel_get_route_reaches_controller_for_admin(): void
    {
        // An admin GET must reach PluginCatalogController::channel() and return
        // the 200 `{channel, options}` envelope (default `stable`, with `dev`
        // flagged advanced/opt-in), NOT a 404 — confirming the route binds to
        // the controller rather than being shadowed by `/plugins/{name}`.
        $this->users->register('admin-1', true);

        $response = $this->router->dispatch(
            $this->request('GET', '/api/v1/admin/plugins/catalog/channel', 'admin-1'),
        );

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('stable', $body['channel']);
        $dev = array_values(array_filter(
            $body['options'],
            static fn (array $o): bool => $o['value'] === 'dev',
        ));
        $this->assertCount(1, $dev);
        $this->assertTrue($dev[0]['advanced']);
    }

    public function test_catalog_channel_put_rejects_invalid_value_through_router(): void
    {
        // Enum validation end-to-end: an admin PUT with an out-of-enum channel
        // must be refused with 400 `plugin.catalog.channel.invalid` — proving the
        // reject happens through the full router + middleware stack, not only via
        // a direct controller call. The store is left untouched (validated before
        // setChannel), so no audit is recorded.
        $this->users->register('admin-1', true);

        $response = $this->router->dispatch($this->request(
            'PUT',
            '/api/v1/admin/plugins/catalog/channel',
            'admin-1',
            ['channel' => 'bogus'],
        ));

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('plugin.catalog.channel.invalid', $body['code']);
        $this->assertArrayNotHasKey('catalog.channel.ui', $this->audit->pluginActions);
    }

    public function test_catalog_channel_put_valid_reaches_controller_and_audits(): void
    {
        // A valid admin PUT reaches the controller's set branch, returns the 200
        // `{channel, options}` envelope, and audits `catalog.channel` (source ui).
        // The channel readback is `stable` here because the test SettingsRepository
        // is backed by a mocked Connection that does not persist the write — the
        // persistence round-trip is covered by the service/controller unit tests;
        // this asserts the ROUTE reaches the controller and the write path runs.
        $this->users->register('admin-1', true);

        $response = $this->router->dispatch($this->request(
            'PUT',
            '/api/v1/admin/plugins/catalog/channel',
            'admin-1',
            ['channel' => 'dev'],
        ));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('channel', $body);
        $this->assertArrayHasKey('options', $body);
        $this->assertSame(1, $this->audit->pluginActions['catalog.channel.ui'] ?? 0);
    }

    public function test_install_then_enable_then_disable_then_uninstall_via_http(): void
    {
        $this->users->register('admin-1', true);

        // Stage a fake fixture manifest on disk so we can install via
        // file:// without spinning up an HTTP server.
        $fixtureDir = sys_get_temp_dir() . '/phlix_admin_routes_' . uniqid('', true);
        mkdir($fixtureDir, 0775, true);
        $manifest = [
            'name' => 'phlix-plugin-demo',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'metadata-provider',
            'entry' => 'Demo\\Plugin',
        ];
        file_put_contents($fixtureDir . '/plugin.json', json_encode($manifest));
        $fileUrl = 'file://' . $fixtureDir . '/plugin.json';

        // Pre-program the fake loader so the install() call returns a
        // canned Manifest matching the fixture, and the subsequent
        // listInstalled() reflects the new plugin.
        $this->loader->installResult = Manifest::fromArray($manifest);
        $this->loader->onInstall = function () use ($manifest): void {
            $this->loader->installed[] = $this->fixturePlugin($manifest['name'], enabled: false);
        };

        // 1. Install
        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/install',
            'admin-1',
            ['url' => $fileUrl],
        ));
        $this->assertSame(201, $response->statusCode);
        $this->assertSame(1, $this->loader->installCalls);
        $this->assertSame(1, $this->audit->pluginActions['install.ui'] ?? 0);

        // 2. Enable
        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/phlix-plugin-demo/enable',
            'admin-1',
        ));
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['phlix-plugin-demo'], $this->loader->enableCalls);
        $this->assertSame(1, $this->audit->pluginActions['enable.ui'] ?? 0);

        // 3. Disable
        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/phlix-plugin-demo/disable',
            'admin-1',
        ));
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['phlix-plugin-demo'], $this->loader->disableCalls);
        $this->assertSame(1, $this->audit->pluginActions['disable.ui'] ?? 0);

        // 4. Uninstall — 200 with a JSON body (not 204) so the SPA fetch client
        //    can parse the response and run its post-uninstall refresh.
        $response = $this->router->dispatch($this->request(
            'DELETE',
            '/api/v1/admin/plugins/phlix-plugin-demo',
            'admin-1',
        ));
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(
            ['uninstalled' => true, 'name' => 'phlix-plugin-demo'],
            json_decode((string) $response->body, true),
        );
        $this->assertSame(['phlix-plugin-demo'], $this->loader->uninstallCalls);
        $this->assertSame(1, $this->audit->pluginActions['uninstall.ui'] ?? 0);

        // Cleanup fixture dir.
        @unlink($fixtureDir . '/plugin.json');
        @rmdir($fixtureDir);
    }

    public function test_install_rejects_http_scheme(): void
    {
        $this->users->register('admin-1', true);
        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/install',
            'admin-1',
            ['url' => 'http://example.com/plugin.json'],
        ));
        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('plugin.url.invalid_scheme', $body['code']);
        $this->assertSame(0, $this->loader->installCalls);
    }

    public function test_test_credentials_route_is_registered_and_admin_gated(): void
    {
        // `POST /plugins/{name}/test` binds to PluginAdminController::testCredentials(),
        // which was fully implemented but NEVER REGISTERED — every call 404'd, which
        // is why the UI's "Test credentials" button was withheld. An anonymous caller
        // must now hit the AdminMiddleware 401, not a 404: 401 proves the route both
        // exists AND sits inside the admin group. Registered once in
        // AdminRoutes::register(), which BOTH entry points call
        // (Application.php:771 daemon + public/index.php:213 web portal).
        $response = $this->router->dispatch(
            $this->request('POST', '/api/v1/admin/plugins/phlix-plugin-demo/test', null),
        );

        $this->assertSame(401, $response->statusCode);
        $this->assertNotSame(404, $response->statusCode);
    }

    public function test_test_credentials_route_rejects_non_admin_with_403(): void
    {
        // Every sibling plugin route is admin-gated; this one must be too.
        // A KNOWN but non-admin user must be refused with 403 and audited.
        $this->users->register('user-2', false);
        $this->loader->installed[] = $this->fixturePlugin('phlix-plugin-demo', enabled: true);
        $this->loader->entryInstance = new FakeCredentialTestingPlugin();

        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/phlix-plugin-demo/test',
            'user-2',
            ['settings' => ['api_key' => 'super-secret-key']],
        ));

        $this->assertSame(403, $response->statusCode);
        $this->assertSame(1, $this->audit->permissionDenied);
        // The consequence that matters: the gate ran BEFORE the controller, so the
        // plugin's testCredentials() was never invoked with the submitted secret.
        // S128: read through a narrowed local. `entryInstance` is declared
        // `object|false`, so `->calls` on it is an error at PHPStan level 2 — and the
        // assertInstanceOf is not ceremony, it is the thing that makes the next line
        // mean "the REAL fake was installed and recorded nothing" rather than
        // "something falsy happened".
        $entry = $this->loader->entryInstance;
        $this->assertInstanceOf(FakeCredentialTestingPlugin::class, $entry);
        $this->assertSame([], $entry->calls);
    }

    public function test_test_credentials_route_reaches_controller_and_returns_result(): void
    {
        // Assert the CONSEQUENCE: a real request reaches the controller, the
        // controller invokes the plugin's testCredentials() with the submitted
        // settings, and the plugin's verdict comes back in the `{success, message}`
        // envelope. Not merely that a route entry exists in a table.
        $this->users->register('admin-1', true);
        $this->loader->installed[] = $this->fixturePlugin('phlix-plugin-demo', enabled: true);
        $entry = new FakeCredentialTestingPlugin();
        $entry->result = ['success' => true, 'message' => 'Authenticated as demo.'];
        $this->loader->entryInstance = $entry;

        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/phlix-plugin-demo/test',
            'admin-1',
            ['settings' => ['api_key' => 'super-secret-key']],
        ));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
        $this->assertSame('Authenticated as demo.', $body['message']);
        // The controller actually called through to the plugin with our settings.
        $this->assertSame([['api_key' => 'super-secret-key']], $entry->calls);
    }

    public function test_test_credentials_route_reports_unsupported_plugin(): void
    {
        // A plugin entry without a testCredentials() method must 501, proving the
        // route reached the controller rather than 404ing at the router.
        $this->users->register('admin-1', true);
        $this->loader->installed[] = $this->fixturePlugin('phlix-plugin-demo', enabled: true);
        $this->loader->entryInstance = new \stdClass();

        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/phlix-plugin-demo/test',
            'admin-1',
            ['settings' => []],
        ));

        $this->assertSame(501, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('plugin.test_not_supported', $body['code'] ?? null);
    }

    public function test_test_credentials_does_not_leak_a_thrown_secret_into_the_response(): void
    {
        // THE leak this endpoint invites. A plugin's testCredentials() typically
        // performs an HTTP call, and HTTP client exceptions embed the full request
        // URI — while several provider APIs carry the key as a QUERY PARAMETER
        // (OMDb's `?apikey=…` is the in-tree example). Relaying getMessage()
        // verbatim would put the operator's plaintext key in the JSON body.
        //
        // Assert the CONSEQUENCE: the secret is ABSENT from the whole response,
        // not merely that some masking flag was set.
        $this->users->register('admin-1', true);
        $this->loader->installed[] = $this->fixturePluginWithSecretSetting('phlix-plugin-demo');
        $entry = new FakeCredentialTestingPlugin();
        $entry->throwable = new \RuntimeException(
            'GET https://www.omdbapi.com/?apikey=s3cr3t-live-key&t=Dune resulted in a 401 response',
        );
        $this->loader->entryInstance = $entry;

        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/phlix-plugin-demo/test',
            'admin-1',
            ['settings' => ['api_key' => 's3cr3t-live-key']],
        ));

        $this->assertSame(200, $response->statusCode);
        $this->assertStringNotContainsString('s3cr3t-live-key', $response->body);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('Credential test failed', $body['message']);
        // Redacted, not merely truncated — the surrounding diagnostic survives.
        $this->assertStringContainsString('omdbapi.com', $body['message']);
        $this->assertStringContainsString('***', $body['message']);
    }

    public function test_test_credentials_redacts_a_secret_echoed_by_the_plugin_itself(): void
    {
        // The non-exception path leaks just as easily: a plugin that helpfully
        // echoes the rejected key into its own `message` must not get that key
        // relayed to the client either.
        $this->users->register('admin-1', true);
        $this->loader->installed[] = $this->fixturePluginWithSecretSetting('phlix-plugin-demo');
        $entry = new FakeCredentialTestingPlugin();
        $entry->result = ['success' => false, 'message' => 'Rejected key s3cr3t-live-key.'];
        $this->loader->entryInstance = $entry;

        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/phlix-plugin-demo/test',
            'admin-1',
            ['settings' => ['api_key' => 's3cr3t-live-key']],
        ));

        $this->assertSame(200, $response->statusCode);
        $this->assertStringNotContainsString('s3cr3t-live-key', $response->body);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('Rejected key ***.', $body['message']);
    }

    public function test_test_credentials_redacts_a_url_encoded_secret(): void
    {
        // A credential leaks most often INSIDE a URI, where it arrives
        // percent-encoded. A literal-substring-only scrub would miss it.
        $this->users->register('admin-1', true);
        $this->loader->installed[] = $this->fixturePluginWithSecretSetting('phlix-plugin-demo');
        $entry = new FakeCredentialTestingPlugin();
        $entry->throwable = new \RuntimeException(
            'GET https://api.example.com/v1?key=' . rawurlencode('p@ss w0rd/key+1') . ' failed',
        );
        $this->loader->entryInstance = $entry;

        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/phlix-plugin-demo/test',
            'admin-1',
            ['settings' => ['api_key' => 'p@ss w0rd/key+1']],
        ));

        $this->assertSame(200, $response->statusCode);
        $this->assertStringNotContainsString(rawurlencode('p@ss w0rd/key+1'), $response->body);
        $this->assertStringNotContainsString('p%40ss', $response->body);
    }

    public function test_test_credentials_redacts_a_long_value_not_flagged_secret(): void
    {
        // Defence in depth: `secret` is plugin-authored advisory metadata, and
        // manifests get it wrong. A long submitted value must be scrubbed even
        // when the manifest never flagged it — otherwise a forgotten flag is a
        // leak. `fixturePlugin()` declares NO settings schema at all.
        $this->users->register('admin-1', true);
        $this->loader->installed[] = $this->fixturePlugin('phlix-plugin-demo', enabled: true);
        $entry = new FakeCredentialTestingPlugin();
        $entry->throwable = new \RuntimeException('auth failed for token unflagged-but-secret-token');
        $this->loader->entryInstance = $entry;

        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/phlix-plugin-demo/test',
            'admin-1',
            ['settings' => ['token' => 'unflagged-but-secret-token']],
        ));

        $this->assertSame(200, $response->statusCode);
        $this->assertStringNotContainsString('unflagged-but-secret-token', $response->body);
    }

    public function test_test_credentials_keeps_short_non_credential_values_readable(): void
    {
        // The length floor exists so diagnostics stay useful: a short, non-secret
        // value like a locale must NOT be scrubbed out of the message.
        $this->users->register('admin-1', true);
        $this->loader->installed[] = $this->fixturePlugin('phlix-plugin-demo', enabled: true);
        $entry = new FakeCredentialTestingPlugin();
        $entry->throwable = new \RuntimeException('unsupported language "en"');
        $this->loader->entryInstance = $entry;

        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/phlix-plugin-demo/test',
            'admin-1',
            ['settings' => ['language' => 'en']],
        ));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertStringContainsString('unsupported language "en"', $body['message']);
    }

    public function test_test_credentials_redacts_a_short_value_the_manifest_flags_secret(): void
    {
        // Below the length floor, but manifest-declared secret → still redacted.
        // This is the branch that proves the two rules are independent.
        $this->users->register('admin-1', true);
        $this->loader->installed[] = $this->fixturePluginWithSecretSetting('phlix-plugin-demo');
        $entry = new FakeCredentialTestingPlugin();
        $entry->throwable = new \RuntimeException('rejected pin 1234');
        $this->loader->entryInstance = $entry;

        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/phlix-plugin-demo/test',
            'admin-1',
            ['settings' => ['api_key' => '1234']],
        ));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertStringNotContainsString('1234', $body['message']);
        $this->assertStringContainsString('***', $body['message']);
    }

    public function test_enable_404_for_unknown_plugin(): void
    {
        $this->users->register('admin-1', true);
        $this->loader->throwOnEnable = new PluginNotFoundException('No installed plugin named "missing".');
        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/missing/enable',
            'admin-1',
        ));
        $this->assertSame(404, $response->statusCode);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $path, ?string $userId, ?array $body = null): Request
    {
        $request           = new Request();
        $request->method   = $method;
        $request->path     = $path;
        $request->headers  = [];
        $request->query    = [];
        $request->body     = $body ?? [];
        $request->files    = [];
        $request->remoteIp = '127.0.0.1';
        $request->remotePort = 0;
        $request->protocol = 'HTTP/1.1';
        $request->queryString = '';
        $request->userId   = $userId;
        return $request;
    }

    private function fixturePlugin(string $name, bool $enabled): InstalledPlugin
    {
        return new InstalledPlugin(
            id: 'id-' . $name,
            manifest: Manifest::fromArray([
                'name' => $name,
                'version' => '1.0.0',
                'phlix_min_server_version' => '0.10.0',
                'type' => 'metadata-provider',
                'entry' => 'Demo\\Plugin',
            ]),
            enabled: $enabled,
            installedAt: new DateTimeImmutable('2024-01-01 00:00:00'),
            settings: [],
            directory: '/tmp/' . $name,
        );
    }

    /**
     * Like {@see self::fixturePlugin()} but with a manifest that declares an
     * `api_key` setting flagged `secret: true`, so the credential-test
     * redaction can be exercised against a real manifest flag.
     */
    private function fixturePluginWithSecretSetting(string $name): InstalledPlugin
    {
        return new InstalledPlugin(
            id: 'id-' . $name,
            manifest: Manifest::fromArray([
                'name' => $name,
                'version' => '1.0.0',
                'phlix_min_server_version' => '0.10.0',
                'type' => 'metadata-provider',
                'entry' => 'Demo\\Plugin',
                'settings' => [
                    'api_key' => ['type' => 'string', 'required' => true, 'secret' => true],
                ],
            ]),
            enabled: true,
            installedAt: new DateTimeImmutable('2024-01-01 00:00:00'),
            settings: [],
            directory: '/tmp/' . $name,
        );
    }
}

/**
 * Stand-in for a plugin entry class that implements `testCredentials()`.
 *
 * Records each invocation so a test can assert the controller actually called
 * through (or, for the 403 case, that it did NOT).
 *
 * @internal
 */
final class FakeCredentialTestingPlugin
{
    /** @var list<array<mixed, mixed>> Settings maps this was called with. */
    public array $calls = [];

    /** Thrown from testCredentials() when set — models an HTTP client failure. */
    public ?\Throwable $throwable = null;

    /** Returned from testCredentials() when no throwable is configured. */
    public mixed $result = true;

    /**
     * @param array<mixed, mixed> $settings
     */
    public function testCredentials(array $settings): mixed
    {
        $this->calls[] = $settings;
        if ($this->throwable !== null) {
            throw $this->throwable;
        }
        return $this->result;
    }
}

/**
 * In-memory test double for {@see PluginLoader}. Keeps a list of
 * installed plugins so the controller's serialisation can exercise
 * real {@see InstalledPlugin} DTOs.
 *
 * @internal
 */
final class FakePluginLoader extends PluginLoader
{
    /** @var list<InstalledPlugin> */
    public array $installed = [];

    public ?Manifest $installResult = null;
    public int $installCalls = 0;
    /** @var (\Closure(): void)|null */
    public ?\Closure $onInstall = null;

    /** @var list<string> */
    public array $enableCalls = [];
    /** @var list<string> */
    public array $disableCalls = [];
    /** @var list<string> */
    public array $uninstallCalls = [];

    public ?\Throwable $throwOnEnable = null;

    public function __construct()
    {
        // Skip parent constructor — collaborators not needed in tests.
    }

    public function install(string $sourceUrl, ?string $expectedSha256 = null, ?string $pinnedRef = null): Manifest
    {
        $this->installCalls++;
        if ($this->onInstall !== null) {
            ($this->onInstall)();
        }
        if ($this->installResult === null) {
            throw new \LogicException('Test forgot to set $installResult.');
        }
        return $this->installResult;
    }

    public function enable(string $name): void
    {
        if ($this->throwOnEnable !== null) {
            throw $this->throwOnEnable;
        }
        $this->enableCalls[] = $name;
        foreach ($this->installed as $i => $p) {
            if ($p->manifest->name === $name) {
                $this->installed[$i] = new InstalledPlugin(
                    id: $p->id,
                    manifest: $p->manifest,
                    enabled: true,
                    installedAt: $p->installedAt,
                    settings: $p->settings,
                    directory: $p->directory,
                );
            }
        }
    }

    public function disable(string $name): void
    {
        $this->disableCalls[] = $name;
    }

    /**
     * Entry instance handed back by {@see self::getEntryInstance()}.
     *
     * `false` means "not configured by the test" and yields `null` (the
     * controller's 404 "entry class could not be instantiated" branch).
     */
    public object|false|null $entryInstance = false;

    public function getInstalled(string $name): InstalledPlugin
    {
        foreach ($this->installed as $plugin) {
            if ($plugin->manifest->name === $name) {
                return $plugin;
            }
        }
        throw new PluginNotFoundException(sprintf('No installed plugin named "%s".', $name));
    }

    public function getEntryInstance(string $name): ?object
    {
        if ($this->entryInstance === false) {
            return null;
        }
        return $this->entryInstance;
    }

    public function uninstall(string $name): void
    {
        $this->uninstallCalls[] = $name;
        $this->installed = array_values(array_filter(
            $this->installed,
            static fn (InstalledPlugin $p): bool => $p->manifest->name !== $name,
        ));
    }

    /** @return list<InstalledPlugin> */
    public function listInstalled(): array
    {
        return $this->installed;
    }
}

/**
 * In-memory profile store for AdminProfileController.
 *
 * @internal
 */
final class FakeUserProfileManager extends UserProfileManager
{
    /** @var array<string, array<string, mixed>> profileId => profile */
    private array $profiles = [];

    public function __construct()
    {
        // Skip parent constructor; no DB connection needed.
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $profileId): ?array
    {
        return $this->profiles[$profileId] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByUserId(string $userId): array
    {
        return array_values(array_filter(
            $this->profiles,
            static fn (array $p): bool => ($p['user_id'] ?? '') === $userId,
        ));
    }

    public function create(string $userId, array $data): string
    {
        return 'fake-profile-id';
    }

    public function update(string $profileId, array $data): void
    {
    }

    public function delete(string $profileId): void
    {
    }

    public function setPin(string $profileId, string $pin): void
    {
    }

    public function removePin(string $profileId): void
    {
    }
}

/**
 * In-memory user store for the admin middleware.
 *
 * @internal
 */
final class FakeUserRepository extends UserRepository
{
    /** @var array<string, bool> userId => isAdmin */
    private array $users = [];

    public function __construct()
    {
        // Skip parent constructor; no DB connection needed.
    }

    public function register(string $id, bool $isAdmin): void
    {
        $this->users[$id] = $isAdmin;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAdminById(string $id): ?array
    {
        if (!isset($this->users[$id]) || $this->users[$id] !== true) {
            return null;
        }
        return ['id' => $id, 'is_admin' => 1];
    }
}

/**
 * Stats collector test double for AdminRoutes register-time wiring.
 *
 * AdminRoutes::register() eagerly resolves StatsController, which
 * requires a StatsCollector; the plugin-focused tests in this file
 * never dispatch requests to /api/v1/admin/stats/*, so the
 * collector itself is never exercised — it just needs to be the
 * right type.
 *
 * @internal
 */
final class FakeStatsCollector extends StatsCollector
{
    public function __construct()
    {
        // Skip parent constructor; no DB connection needed.
    }
}

/**
 * Dashboard service test double. Same rationale as
 * {@see FakeStatsCollector}.
 *
 * @internal
 */
final class FakeDashboardService extends DashboardService
{
    public function __construct()
    {
        // Skip parent constructor.
    }

    public static function make(): self
    {
        return new self();
    }
}

/**
 * Backup manager test double. Same rationale as
 * {@see FakeStatsCollector}.
 *
 * @internal
 */
final class FakeBackupManager extends BackupManager
{
    public function __construct()
    {
        // Skip parent constructor.
    }

    public static function make(): self
    {
        return new self();
    }
}

/**
 * Counts {@see AuditLogger} calls so tests can assert on side-effects
 * without a real log file.
 *
 * @internal
 */
final class FakeAuditLogger extends AuditLogger
{
    /** @var array<string, int> */
    public array $pluginActions = [];
    public int $permissionDenied = 0;

    public function __construct()
    {
        // Skip parent constructor; no Monolog wiring needed.
    }

    public function logPluginAction(
        ?string $actorUserId,
        string $action,
        string $pluginName,
        array $context = [],
    ): void {
        $source = $context['source'] ?? 'system';
        $key = $action . '.' . (is_string($source) ? $source : 'system');
        $this->pluginActions[$key] = ($this->pluginActions[$key] ?? 0) + 1;
    }

    public function logPermissionDenied(string $userId, string $resource, string $action): void
    {
        $this->permissionDenied++;
    }
}
