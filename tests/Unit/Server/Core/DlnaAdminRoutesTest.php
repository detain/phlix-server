<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use Phlix\Common\Logger\AuditLogger;
use Phlix\Dlna\CdsServer;
use Phlix\Auth\UserRepository;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Regression coverage for {@see Application::loadDlnaAdminRoutes()}.
 *
 * The DLNA admin route group used to be wrapped — group registration and all —
 * in a single `try { … } catch (\Throwable) {}`. On a box where `CdsServer` is
 * bound but throws on construction, the catch swallowed the failure and the
 * `/api/v1/admin/dlna` group was never registered, so `GET .../dlna/status`
 * 404'd (observed live). The fix registers the group UNCONDITIONALLY and guards
 * only the optional CdsServer wiring, so a CdsServer failure leaves the
 * controller with a null server → `status()` returns `{enabled:false}` 200.
 *
 * These tests drive the private loader directly via reflection so they do not
 * need a live MySQL (the real Application constructor eagerly resolves DB-backed
 * controllers).
 *
 * @covers \Phlix\Server\Core\Application
 */
final class DlnaAdminRoutesTest extends TestCase
{
    public function testDlnaRoutesRegisterEvenWhenCdsServerThrows(): void
    {
        $router = $this->invokeLoader($this->makeContainer(static function (string $id): never {
            throw new \RuntimeException("CdsServer construction failed for {$id}");
        }));

        $this->assertDlnaRoutesRegistered($router);
    }

    public function testDlnaRoutesRegisterWhenCdsServerAbsent(): void
    {
        // has(CdsServer) === false — the loader never touches it at all.
        $router = $this->invokeLoader($this->makeContainer(null, hasCds: false));

        $this->assertDlnaRoutesRegistered($router);
    }

    public function testStatusReportsDisabledWhenCdsServerUnavailable(): void
    {
        $router = $this->invokeLoader($this->makeContainer(static function (string $id): never {
            throw new \RuntimeException("CdsServer construction failed for {$id}");
        }));

        $routes = $router->getRoutes();
        $status = $this->findRoute($routes['GET'] ?? [], '/api/v1/admin/dlna/status');
        $this->assertNotNull($status, 'GET /api/v1/admin/dlna/status must be registered.');

        /** @var array{handler: mixed} $status */
        $handler = $status['handler'];
        $this->assertIsArray($handler);
        [$controller, $method] = $handler;

        /** @var Response $response */
        $response = $controller->$method(new Request(), []);
        $this->assertSame(200, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame(false, $body['enabled']);
        $this->assertSame(false, $body['running']);
    }

    /**
     * Build an Application without invoking its constructor, attach a fresh
     * Router + the supplied container, then invoke the private loader.
     */
    private function invokeLoader(ContainerInterface $container): Router
    {
        $ref = new \ReflectionClass(Application::class);
        /** @var Application $app */
        $app = $ref->newInstanceWithoutConstructor();

        $router = new Router();

        $routerProp = $ref->getProperty('router');
        $routerProp->setAccessible(true);
        $routerProp->setValue($app, $router);

        $containerProp = $ref->getProperty('container');
        $containerProp->setAccessible(true);
        $containerProp->setValue($app, $container);

        $loader = $ref->getMethod('loadDlnaAdminRoutes');
        $loader->setAccessible(true);
        $loader->invoke($app);

        return $router;
    }

    /**
     * @param (callable(string): never)|null $cdsThrow Invoked when CdsServer is requested.
     */
    private function makeContainer(?callable $cdsThrow, bool $hasCds = true): ContainerInterface
    {
        $adminMiddleware = new AdminMiddleware(
            $this->createMock(UserRepository::class),
            $this->createMock(AuditLogger::class),
        );

        return new class ($adminMiddleware, $cdsThrow, $hasCds) implements ContainerInterface {
            /** @param (callable(string): never)|null $cdsThrow */
            public function __construct(
                private readonly AdminMiddleware $adminMiddleware,
                private readonly mixed $cdsThrow,
                private readonly bool $hasCds,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === AdminMiddleware::class) {
                    return $this->adminMiddleware;
                }
                if ($id === CdsServer::class && is_callable($this->cdsThrow)) {
                    ($this->cdsThrow)($id);
                }
                throw new \RuntimeException("Unexpected container get: {$id}");
            }

            public function has(string $id): bool
            {
                if ($id === AdminMiddleware::class) {
                    return true;
                }
                if ($id === CdsServer::class) {
                    return $this->hasCds;
                }
                return false;
            }
        };
    }

    private function assertDlnaRoutesRegistered(Router $router): void
    {
        $routes = $router->getRoutes();
        $getPaths = array_column($routes['GET'] ?? [], 'path');
        $postPaths = array_column($routes['POST'] ?? [], 'path');

        $this->assertContains('/api/v1/admin/dlna/status', $getPaths);
        $this->assertContains('/api/v1/admin/dlna/start', $postPaths);
        $this->assertContains('/api/v1/admin/dlna/stop', $postPaths);

        // The route group must carry AdminMiddleware.
        $status = $this->findRoute($routes['GET'], '/api/v1/admin/dlna/status');
        $this->assertNotNull($status);
        $middleware = $status['middleware'] ?? [];
        $hasAdmin = false;
        foreach ($middleware as $mw) {
            if ($mw instanceof AdminMiddleware) {
                $hasAdmin = true;
                break;
            }
        }
        $this->assertTrue($hasAdmin, 'DLNA admin routes must be gated by AdminMiddleware.');
    }

    /**
     * @param array<int|string, array<string, mixed>> $methodRoutes
     * @return array<string, mixed>|null
     */
    private function findRoute(array $methodRoutes, string $path): ?array
    {
        foreach ($methodRoutes as $route) {
            if (($route['path'] ?? null) === $path) {
                return $route;
            }
        }
        return null;
    }
}
