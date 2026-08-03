<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use Phlix\Common\Database\ConnectionPool;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\MusicController;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

/**
 * S94 AC-audit residual — pins the ONE link that made S94's fix reachable.
 *
 * ## Why this file exists
 *
 * S94 corrected `al.name` → `al.title` in
 * {@see \Phlix\Media\Music\MusicLibraryService::getAllTracks()}. The SQL itself
 * is well pinned by `tests/Integration/Media/MusicTracksQueryIntegrationTest.php`
 * against a real MySQL (a mocked `Connection` cannot reject a bad column name),
 * and the controller→service link is pinned by that same class plus
 * `tests/Unit/Server/Http/Controllers/MusicControllerTest.php`.
 *
 * What nothing pinned was **reachability**. When S94 merged, the fixed query was
 * on a path no HTTP request could reach: `WebPortalRouter::getMusicTracks()` was
 * its only caller and `Application`'s registration of the same path won dispatch,
 * so the deploy audit recorded S94 as a correct fix with *zero* observable effect.
 * S99 resolved that by deleting the `WebPortalRouter` copy and repointing
 * {@see MusicController} at the service — which makes **`Application::loadMusicRoutes()`
 * the single line that puts S94's query on the served path.**
 *
 * That line was covered by nothing. Deleting
 * `$r->get('/api/v1/music/tracks', [$controller, 'listTracks']);` from
 * `Application::loadMusicRoutes()` left the whole Unit suite (8,463 tests) and every
 * `tests/Integration/Media/` test (131 tests, real MySQL) GREEN — the endpoint would
 * have 404'd in production with no test noticing, putting `getAllTracks()` straight
 * back into the unreachable state S94 shipped into.
 *
 * ⚠ **`tests/Unit/Server/Http/RouterMediaRoutesTest.php` does not close this gap and
 * must not be mistaken for it.** It asserts the same paths, but on
 * {@see Router::music()} — a registrar with **zero** callers in `src/`, `public/`,
 * `bin/`, `scripts/` or `start.php` (verified by grep; the test file is its only
 * caller anywhere in the repo including `vendor/`). It therefore pins a code path
 * production never executes. This class asserts the registrar production actually
 * runs, and asserts the HANDLER as well as the path, so repointing the route at a
 * different controller or method is caught too.
 */
final class MusicTracksRouteReachabilityTest extends TestCase
{
    /**
     * `GET /api/v1/music/tracks` must be registered by the router `Application`
     * actually dispatches, and must land on `MusicController::listTracks` — the
     * only method that reaches S94's corrected `getAllTracks()` query.
     */
    public function testTheServedTracksRouteResolvesToMusicControllerListTracks(): void
    {
        $router = $this->routerAfterLoadingMusicRoutes();

        $routes = $router->getRoutes();
        $this->assertArrayHasKey('GET', $routes);
        $this->assertArrayHasKey(
            '/api/v1/music/tracks',
            $routes['GET'],
            'Application::loadMusicRoutes() must register GET /api/v1/music/tracks — '
            . 'without it the endpoint 404s and S94\'s getAllTracks() fix is unreachable again',
        );

        $handler = $routes['GET']['/api/v1/music/tracks']['handler'];
        $this->assertIsArray($handler, 'The route must be a [controller, method] pair, not a closure');
        $this->assertInstanceOf(
            MusicController::class,
            $handler[0],
            'GET /api/v1/music/tracks must be served by MusicController',
        );
        $this->assertSame(
            'listTracks',
            $handler[1],
            'MusicController::listTracks is the only handler that calls MusicLibraryService::getAllTracks()',
        );
    }

    /**
     * The registration is behind `AuthMiddleware`, and the route carries it —
     * a group-middleware leak or a route moved out of the group would otherwise
     * silently publish the whole music library.
     */
    public function testTheServedTracksRouteIsAuthenticated(): void
    {
        $router = $this->routerAfterLoadingMusicRoutes();

        $routes = $router->getRoutes();
        $this->assertArrayHasKey('GET', $routes);
        $this->assertArrayHasKey('/api/v1/music/tracks', $routes['GET']);

        $middleware = $routes['GET']['/api/v1/music/tracks']['middleware'];
        $this->assertIsArray($middleware);

        $classes = array_map(
            static fn (object $m): string => $m::class,
            array_filter($middleware, static fn (mixed $m): bool => is_object($m)),
        );
        $this->assertContains(
            \Phlix\Server\Http\Middleware\AuthMiddleware::class,
            $classes,
            'GET /api/v1/music/tracks must stay inside the AuthMiddleware group',
        );
    }

    /**
     * Build an `Application` without its constructor (which would call
     * `loadRoutes()` and open real sockets), give it a fresh `Router` plus a
     * container that binds a mocked `Connection` — the binding
     * `Application::createDatabaseConnection()` prefers — and run only the music
     * route loader.
     */
    private function routerAfterLoadingMusicRoutes(): Router
    {
        $connection = $this->createMock(Connection::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $id): mixed => $id === Connection::class
                ? $connection
                : throw new \RuntimeException('unbound: ' . $id),
        );

        $ref = new ReflectionClass(Application::class);

        /** @var Application $app */
        $app = $ref->newInstanceWithoutConstructor();

        $router = new Router();

        foreach (
            [
            'container' => $container,
            'connectionPool' => $this->createMock(ConnectionPool::class),
            'config' => [],
            'router' => $router,
            ] as $property => $value
        ) {
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($app, $value);
        }

        $loader = $ref->getMethod('loadMusicRoutes');
        $loader->setAccessible(true);
        $loader->invoke($app);

        return $router;
    }
}
