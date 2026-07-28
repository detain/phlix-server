<?php

namespace Phlix\Tests\Unit\Server\Core;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Server\Core\Application;
use Phlix\Tests\Support\Database\RequiresRealDatabase;

class ApplicationTest extends TestCase
{
    use RequiresRealDatabase;

    /**
     * @group integration
     */
    public function testApplicationCanBeInstantiated(): void
    {
        $app = $this->bootApplication();
        $this->assertInstanceOf(Application::class, $app);
    }

    /**
     * The media-library JSON API (music/books/audiobooks/photos) must be
     * registered under the same `/api/v1` prefix as the rest of the API and
     * as documented in phlix-docs `reference/api.md`. OPDS is the deliberate
     * exception and keeps its spec path `/opds/v1.2`.
     *
     * @group integration
     */
    public function testMediaRoutesAreRegisteredUnderApiV1(): void
    {
        $app = $this->bootApplication();

        $routes = $app->getRouter()->getRoutes();
        $getPaths = array_column($routes['GET'], 'path');
        $postPaths = array_column($routes['POST'], 'path');

        // Prefixed JSON metadata routes.
        $this->assertContains('/api/v1/music/artists', $getPaths);
        $this->assertContains('/api/v1/music/albums', $getPaths);
        $this->assertContains('/api/v1/books', $getPaths);
        $this->assertContains('/api/v1/books/{id}/download', $getPaths);
        $this->assertContains('/api/v1/audiobooks', $getPaths);
        $this->assertContains('/api/v1/audiobooks/{id}/stream', $getPaths);
        $this->assertContains('/api/v1/audiobooks/{id}/progress', $postPaths);
        $this->assertContains('/api/v1/photo/albums', $getPaths);
        $this->assertContains('/api/v1/photo/slideshow', $getPaths);

        // The old un-prefixed paths must be gone.
        $this->assertNotContains('/music/artists', $getPaths);
        $this->assertNotContains('/books', $getPaths);
        $this->assertNotContains('/audiobooks', $getPaths);
        $this->assertNotContains('/photo/albums', $getPaths);

        // OPDS stays on its spec path (not prefixed).
        $this->assertContains('/opds/v1.2', $getPaths);
        $this->assertNotContains('/api/v1/opds/v1.2', $getPaths);
    }

    /**
     * Security regression: the SPA "Connect Last.fm" OAuth routes
     * (`GET /api/v1/oauth/lastfm` + `/callback`) MUST be gated by
     * AdminMiddleware, exactly like the sibling `/api/v1/admin/services/*`
     * routes. PR #260 originally registered them on the bare router with no
     * middleware, so any authenticated user could link a Last.fm account.
     *
     * @group integration
     */
    public function testLastfmOAuthRoutesAreAdminGated(): void
    {
        $app = $this->bootApplication();

        $routes = $app->getRouter()->getRoutes();

        $authorize = $this->findRouteByPath($routes['GET'], '/api/v1/oauth/lastfm');
        $callback = $this->findRouteByPath($routes['GET'], '/api/v1/oauth/lastfm/callback');

        $this->assertNotNull($authorize, 'GET /api/v1/oauth/lastfm must be registered.');
        $this->assertNotNull($callback, 'GET /api/v1/oauth/lastfm/callback must be registered.');

        // Both must carry a non-empty middleware stack including AdminMiddleware.
        foreach (['authorize' => $authorize, 'callback' => $callback] as $label => $route) {
            /** @var array<int, mixed> $middleware */
            $middleware = $route['middleware'] ?? [];
            $this->assertNotEmpty(
                $middleware,
                "Last.fm OAuth {$label} route must be wrapped in middleware.",
            );
            $hasAdmin = false;
            foreach ($middleware as $mw) {
                if ($mw instanceof \Phlix\Server\Http\Middleware\AdminMiddleware) {
                    $hasAdmin = true;
                    break;
                }
            }
            $this->assertTrue(
                $hasAdmin,
                "Last.fm OAuth {$label} route must be gated by AdminMiddleware.",
            );
        }
    }

    /**
     * Locate the first route entry whose 'path' matches exactly.
     *
     * @param array<int|string, array<string, mixed>> $methodRoutes
     * @return array<string, mixed>|null
     */
    private function findRouteByPath(array $methodRoutes, string $path): ?array
    {
        foreach ($methodRoutes as $route) {
            if (($route['path'] ?? null) === $path) {
                return $route;
            }
        }
        return null;
    }

    /**
     * Boots a real Application against the CI MySQL service.
     *
     * Application's constructor eagerly resolves controllers
     * (loadApiRoutes -> getMediaItemController -> ItemRepository ->
     * Connection), so this needs a reachable MySQL. CI provides one as a
     * service container; skip locally when the host doesn't.
     */
    private function bootApplication(): Application
    {
        // ⚠ Probe target pinned to the literal `127.0.0.1:3306` this test has always
        // used rather than to DB_HOST/DB_PORT — deliberately UNCHANGED by S126, which
        // replaced the private fsockopen probe here. Under `phpunit.xml`'s own `<env>`
        // block (`DB_HOST=127.0.0.1`, `DB_PORT=3306`) the two resolve identically, so
        // pinning keeps this site byte-identical. Note this file is in the
        // `tests/Unit/Server/` set that `.github/workflows/phpunit.yml`'s `test-server`
        // job runs with NO MySQL service, so the skip below is a live path in CI and
        // must stay a skip.
        $this->requireHealthyDatabase(
            'skipping Application boot. Run in docker-compose for integration testing.',
            '127.0.0.1',
            3306,
        );

        // ConnectionPool keeps process-wide static state. If another test in
        // the suite already initialised it (e.g. via the prod
        // config/database.php with user "phlix"), our temp config below
        // would be ignored and the pool would reuse those creds. Reset the
        // statics so this test starts from a clean slate.
        $this->resetConnectionPool();

        // Application::fromConfigPath() expects the same shape that
        // public/index.php builds: server config + paths to the db and
        // logger configs.
        $configFile  = __DIR__ . '/../../../../config/server.php';
        $tmpDbConfig = $this->writeTempDbConfig();

        $merged = require $configFile;
        $merged['db_config_path']     = $tmpDbConfig;
        $merged['logger_config_path'] = __DIR__ . '/../../../../config/logger.php';

        $tmpConfig = tempnam(sys_get_temp_dir(), 'phlix-app-test-');
        file_put_contents($tmpConfig, "<?php\nreturn " . var_export($merged, true) . ';');

        try {
            return Application::fromConfigPath($tmpConfig);
        } finally {
            @unlink($tmpConfig);
            @unlink($tmpDbConfig);
        }
    }

    private function resetConnectionPool(): void
    {
        $ref = new \ReflectionClass(ConnectionPool::class);
        foreach (['instance' => null, 'connections' => [], 'configPath' => ''] as $prop => $value) {
            if (!$ref->hasProperty($prop)) {
                continue;
            }
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }
    }

    private function writeTempDbConfig(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phlix-db-test-');
        // Honour the env credentials phpunit.xml exports so the CI workflow
        // (which uses a non-root DB user) can connect.
        file_put_contents($path, "<?php\nreturn " . var_export([
            'connections' => [
                'mysql' => [
                    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
                    'port'     => (int) (getenv('DB_PORT') ?: 3306),
                    'username' => getenv('DB_USER') ?: 'root',
                    'password' => getenv('DB_PASSWORD') ?: '',
                    'database' => getenv('DB_DATABASE') ?: 'phlix_test',
                ],
            ],
        ], true) . ';');
        return $path;
    }
}
