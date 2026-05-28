<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Plugins;

use DateTimeImmutable;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Plugins\Exception\PluginNotFoundException;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Ldap\Controller\LdapAdminController;
use Phlix\Plugins\Ldap\Plugin as LdapPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\Oidc\Controller\OidcAdminController;
use Phlix\Plugins\Oidc\Plugin;
use Phlix\Plugins\PluginLoader;
use Phlix\Admin\BackupManager;
use Phlix\Admin\DashboardService;
use Phlix\Admin\SettingsRepository;
use Phlix\Server\Http\Controllers\Admin\AdminProfileController;
use Phlix\Server\Http\Controllers\Admin\AdminSettingsController;
use Phlix\Server\Http\Controllers\Admin\AdminUserController;
use Phlix\Server\Http\Controllers\Admin\BackupController;
use Phlix\Server\Http\Controllers\Admin\DashboardController;
use Phlix\Server\Http\Controllers\Admin\FsBrowseController;
use Phlix\Server\Http\Controllers\AuthProviderController;
use Phlix\Server\Http\Controllers\PluginAdminController;
use Phlix\Server\Http\Controllers\Stats\StatsController;
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
 *
 * @covers \Phlix\Server\Http\Routes\AdminRoutes
 * @covers \Phlix\Server\Http\Controllers\PluginAdminController
 * @covers \Phlix\Server\Http\Middleware\AdminMiddleware
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
        $adminUserController  = new AdminUserController($this->users);
        $profileManager = new FakeUserProfileManager();
        $adminProfileController = new AdminProfileController($profileManager, $this->users);

        $container = new class (
            $this->loader,
            $this->users,
            $this->audit,
            $statsController,
            $dashboardController,
            $backupController,
            $settingsController,
            $fsBrowseController,
            $adminUserController,
            $profileManager,
            $adminProfileController,
        ) implements ContainerInterface {
            private Plugin $oidcPlugin;
            private LdapPlugin $ldapPlugin;

            public function __construct(
                private readonly FakePluginLoader $loader,
                private readonly FakeUserRepository $users,
                private readonly FakeAuditLogger $audit,
                private readonly StatsController $statsController,
                private readonly DashboardController $dashboardController,
                private readonly BackupController $backupController,
                private readonly AdminSettingsController $settingsController,
                private readonly FsBrowseController $fsBrowseController,
                private readonly AdminUserController $adminUserController,
                private readonly FakeUserProfileManager $profileManager,
                private readonly AdminProfileController $adminProfileController,
            ) {
                $tempDir = sys_get_temp_dir() . '/phlix_oidc_test_' . uniqid('', true);
                mkdir($tempDir, 0775, true);
                Plugin::setPluginDirectory($tempDir);
                $this->oidcPlugin = new Plugin();

                $ldapTempDir = sys_get_temp_dir() . '/phlix_ldap_test_' . uniqid('', true);
                mkdir($ldapTempDir, 0775, true);
                LdapPlugin::setPluginDirectory($ldapTempDir);
                $this->ldapPlugin = new LdapPlugin();
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    PluginAdminController::class => new PluginAdminController(
                        $this->loader,
                        $this->audit,
                    ),
                    AdminMiddleware::class => new AdminMiddleware(
                        $this->users,
                        $this->audit,
                    ),
                    AuthProviderController::class => new AuthProviderController(
                        new AuthProviderRegistry(),
                    ),
                    OidcAdminController::class => new OidcAdminController(
                        $this->oidcPlugin,
                    ),
                    LdapAdminController::class => new LdapAdminController(
                        $this->ldapPlugin,
                    ),
                    StatsController::class     => $this->statsController,
                    DashboardController::class => $this->dashboardController,
                    BackupController::class    => $this->backupController,
                    AdminSettingsController::class => $this->settingsController,
                    FsBrowseController::class => $this->fsBrowseController,
                    AdminUserController::class => $this->adminUserController,
                    UserProfileManager::class => $this->profileManager,
                    AdminProfileController::class => $this->adminProfileController,
                    default => throw new \RuntimeException("no binding for $id"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    PluginAdminController::class,
                    AdminMiddleware::class,
                    AuthProviderController::class,
                    OidcAdminController::class,
                    LdapAdminController::class,
                    StatsController::class,
                    DashboardController::class,
                    BackupController::class,
                    AdminSettingsController::class,
                    FsBrowseController::class,
                    AdminUserController::class,
                    UserProfileManager::class,
                    AdminProfileController::class,
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

        // 4. Uninstall
        $response = $this->router->dispatch($this->request(
            'DELETE',
            '/api/v1/admin/plugins/phlix-plugin-demo',
            'admin-1',
        ));
        $this->assertSame(204, $response->statusCode);
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

    public function install(string $sourceUrl): Manifest
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
        $key = $action . '.' . ($context['source'] ?? 'system');
        $this->pluginActions[$key] = ($this->pluginActions[$key] ?? 0) + 1;
    }

    public function logPermissionDenied(string $userId, string $resource, string $action): void
    {
        $this->permissionDenied++;
    }
}
