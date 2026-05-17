<?php

declare(strict_types=1);

namespace Phlex\Tests\Integration\Plugins;

use DateTimeImmutable;
use Phlex\Auth\AuthProviderRegistry;
use Phlex\Auth\UserRepository;
use Phlex\Common\Logger\AuditLogger;
use Phlex\Plugins\Exception\PluginNotFoundException;
use Phlex\Plugins\InstalledPlugin;
use Phlex\Plugins\Ldap\Controller\LdapAdminController;
use Phlex\Plugins\Ldap\Plugin as LdapPlugin;
use Phlex\Plugins\Manifest;
use Phlex\Plugins\Oidc\Controller\OidcAdminController;
use Phlex\Plugins\Oidc\Plugin;
use Phlex\Plugins\PluginLoader;
use Phlex\Server\Http\Controllers\AuthProviderController;
use Phlex\Server\Http\Controllers\PluginAdminController;
use Phlex\Server\Http\Middleware\AdminMiddleware;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Router;
use Phlex\Server\Http\Routes\AdminRoutes;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * End-to-end exercise of the /api/v1/admin/plugins routes (Step A.5).
 *
 * Boots a real {@see Router}, registers the {@see AdminRoutes} group
 * against a hand-rolled PSR-11 container that hands out stubbed
 * loader / repository / audit-logger collaborators, then sends
 * synthetic {@see Request} objects through the router and asserts both
 * the HTTP response and the side-effects on the collaborators.
 *
 * @covers \Phlex\Server\Http\Routes\AdminRoutes
 * @covers \Phlex\Server\Http\Controllers\PluginAdminController
 * @covers \Phlex\Server\Http\Middleware\AdminMiddleware
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
        // run the router. Only the two services AdminRoutes::register
        // resolves from the container are needed.
        $container = new class ($this->loader, $this->users, $this->audit) implements ContainerInterface {
            private Plugin $oidcPlugin;
            private LdapPlugin $ldapPlugin;

            public function __construct(
                private readonly FakePluginLoader $loader,
                private readonly FakeUserRepository $users,
                private readonly FakeAuditLogger $audit,
            ) {
                $tempDir = sys_get_temp_dir() . '/phlex_oidc_test_' . uniqid('', true);
                mkdir($tempDir, 0775, true);
                Plugin::setPluginDirectory($tempDir);
                $this->oidcPlugin = new Plugin();

                $ldapTempDir = sys_get_temp_dir() . '/phlex_ldap_test_' . uniqid('', true);
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
        $this->loader->installed[] = $this->fixturePlugin('phlex-plugin-demo', enabled: true);

        $response = $this->router->dispatch($this->request('GET', '/api/v1/admin/plugins', 'admin-1'));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertCount(1, $body['plugins']);
        $this->assertSame('phlex-plugin-demo', $body['plugins'][0]['name']);
    }

    public function test_install_then_enable_then_disable_then_uninstall_via_http(): void
    {
        $this->users->register('admin-1', true);

        // Stage a fake fixture manifest on disk so we can install via
        // file:// without spinning up an HTTP server.
        $fixtureDir = sys_get_temp_dir() . '/phlex_admin_routes_' . uniqid('', true);
        mkdir($fixtureDir, 0775, true);
        $manifest = [
            'name' => 'phlex-plugin-demo',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
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
            '/api/v1/admin/plugins/phlex-plugin-demo/enable',
            'admin-1',
        ));
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['phlex-plugin-demo'], $this->loader->enableCalls);
        $this->assertSame(1, $this->audit->pluginActions['enable.ui'] ?? 0);

        // 3. Disable
        $response = $this->router->dispatch($this->request(
            'POST',
            '/api/v1/admin/plugins/phlex-plugin-demo/disable',
            'admin-1',
        ));
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['phlex-plugin-demo'], $this->loader->disableCalls);
        $this->assertSame(1, $this->audit->pluginActions['disable.ui'] ?? 0);

        // 4. Uninstall
        $response = $this->router->dispatch($this->request(
            'DELETE',
            '/api/v1/admin/plugins/phlex-plugin-demo',
            'admin-1',
        ));
        $this->assertSame(204, $response->statusCode);
        $this->assertSame(['phlex-plugin-demo'], $this->loader->uninstallCalls);
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
                'phlex_min_server_version' => '0.10.0',
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
