<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Common\Container;

use DI\ContainerBuilder;
use Phlex\Auth\AuthManager;
use Phlex\Auth\JwtHandler;
use Phlex\Common\Container\ContainerFactory;
use Phlex\Common\Container\Providers\AuthServicesProvider;
use Phlex\Common\Container\Providers\CoreServicesProvider;
use Phlex\Common\Container\Providers\EventServicesProvider;
use Phlex\Common\Container\Providers\MediaServicesProvider;
use Phlex\Common\Container\Providers\PluginsProvider;
use Phlex\Common\Container\Providers\SessionServicesProvider;
use Phlex\Common\Container\Providers\WebPortalServicesProvider;
use Phlex\Common\Container\ServiceProviderInterface;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Media\Library\LibraryManager;
use Phlex\Tests\Fixtures\Container\CircularA;
use Phlex\Tests\Fixtures\Container\CircularB;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Unit tests for {@see ContainerFactory}.
 *
 * The tests stub the database {@see Connection} and the logger config so the
 * container can be exercised in isolation without touching MySQL or the
 * filesystem-bound logger handlers.
 *
 * @covers \Phlex\Common\Container\ContainerFactory
 * @covers \Phlex\Common\Container\Providers\CoreServicesProvider
 * @covers \Phlex\Common\Container\Providers\AuthServicesProvider
 * @covers \Phlex\Common\Container\Providers\MediaServicesProvider
 * @covers \Phlex\Common\Container\Providers\SessionServicesProvider
 * @covers \Phlex\Common\Container\Providers\WebPortalServicesProvider
 */
final class ContainerFactoryTest extends TestCase
{
    /** @var string Path to an isolated logger config that writes to a temp dir. */
    private string $loggerConfigPath = '';

    /** @var string Temp directory backing $loggerConfigPath. */
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->tempDir = sys_get_temp_dir() . '/phlex_container_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);

        $configContents = "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($this->tempDir . '/app.log', true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n";
        $this->loggerConfigPath = $this->tempDir . '/logger.php';
        file_put_contents($this->loggerConfigPath, $configContents);

        putenv('JWT_SECRET');
        putenv('PHLEX_CONTAINER_COMPILE');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        LoggerFactory::reset();
        $this->rmdir($this->tempDir);
    }

    private function rmdir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $entries = scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(): array
    {
        return [
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ];
    }

    /**
     * Build a container with the canonical providers but with the
     * MySQL Connection rebound to a PHPUnit mock so resolving the
     * database does not touch a real DB server.
     */
    private function containerWithMockedDb(): ContainerInterface
    {
        $mockConnection = $this->createMock(Connection::class);

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($mockConnection) implements ServiceProviderInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;
                $builder->addDefinitions([
                    Connection::class => factory(static fn (): Connection => $connection),
                ]);
            }
        };

        return ContainerFactory::create($this->baseConfig(), $providers);
    }

    public function test_create_returns_psr_container(): void
    {
        $container = ContainerFactory::create([]);
        $this->assertInstanceOf(ContainerInterface::class, $container);
    }

    public function test_resolves_jwt_handler_with_env_secret(): void
    {
        putenv('JWT_SECRET=xyz-secret-from-env');

        $container = ContainerFactory::create($this->baseConfig());

        /** @var JwtHandler $handler */
        $handler = $container->get(JwtHandler::class);
        $this->assertSame('xyz-secret-from-env', $this->readPrivate($handler, 'secretKey'));
    }

    public function test_resolves_jwt_handler_with_default_secret_when_env_missing(): void
    {
        putenv('JWT_SECRET');

        $container = ContainerFactory::create($this->baseConfig());

        /** @var JwtHandler $handler */
        $handler = $container->get(JwtHandler::class);
        $this->assertSame(
            AuthServicesProvider::DEFAULT_JWT_SECRET,
            $this->readPrivate($handler, 'secretKey')
        );
    }

    public function test_resolves_auth_manager_with_dependencies_wired(): void
    {
        $container = $this->containerWithMockedDb();

        /** @var AuthManager $manager */
        $manager = $container->get(AuthManager::class);

        $this->assertInstanceOf(JwtHandler::class, $this->readPrivate($manager, 'jwtHandler'));
        $this->assertInstanceOf(
            StructuredLogger::class,
            $this->readPrivate($manager, 'logger')
        );
    }

    public function test_resolves_singleton_returns_same_instance(): void
    {
        $container = $this->containerWithMockedDb();

        $first  = $container->get(LibraryManager::class);
        $second = $container->get(LibraryManager::class);

        $this->assertSame($first, $second);
    }

    public function test_get_unknown_id_throws_psr_not_found_exception(): void
    {
        $container = ContainerFactory::create($this->baseConfig());

        $this->expectException(NotFoundExceptionInterface::class);
        $container->get('definitely.not.a.real.binding');
    }

    public function test_get_with_circular_dependency_throws(): void
    {
        $container = ContainerFactory::create($this->baseConfig());

        $this->expectException(\Throwable::class);
        $container->get(CircularA::class);
        $container->get(CircularB::class);
    }

    public function test_db_connection_factory_resolves_via_connection_pool(): void
    {
        $mockConnection = $this->createMock(Connection::class);

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($mockConnection) implements ServiceProviderInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;
                $builder->addDefinitions([
                    Connection::class => factory(static fn (): Connection => $connection),
                ]);
            }
        };

        $container = ContainerFactory::create($this->baseConfig(), $providers);

        $this->assertSame($mockConnection, $container->get(Connection::class));
    }

    public function test_default_providers_returns_canonical_stack(): void
    {
        $providers = ContainerFactory::defaultProviders();

        $this->assertCount(9, $providers);
        $this->assertInstanceOf(CoreServicesProvider::class, $providers[0]);
        $this->assertInstanceOf(EventServicesProvider::class, $providers[1]);
        $this->assertInstanceOf(AuthServicesProvider::class, $providers[2]);
        $this->assertInstanceOf(\Phlex\Common\Container\Providers\HubServicesProvider::class, $providers[3]);
        $this->assertInstanceOf(MediaServicesProvider::class, $providers[4]);
        $this->assertInstanceOf(\Phlex\Common\Container\Providers\NetworkServicesProvider::class, $providers[5]);
        $this->assertInstanceOf(SessionServicesProvider::class, $providers[6]);
        $this->assertInstanceOf(WebPortalServicesProvider::class, $providers[7]);
        $this->assertInstanceOf(PluginsProvider::class, $providers[8]);
    }

    public function test_resolves_hls_streamer_with_config_overrides(): void
    {
        $container = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'hls' => [
                'segment_dir' => $this->tempDir . '/segments',
                'base_url' => 'https://example.test/stream',
            ],
        ]);

        $streamer = $container->get(\Phlex\Media\Streaming\HlsStreamer::class);
        $this->assertInstanceOf(\Phlex\Media\Streaming\HlsStreamer::class, $streamer);
        $this->assertSame(
            $this->tempDir . '/segments',
            $this->readPrivate($streamer, 'segmentDir')
        );
        $this->assertSame(
            'https://example.test/stream',
            $this->readPrivate($streamer, 'baseUrl')
        );
    }

    public function test_resolves_page_renderer_with_template_dir_config(): void
    {
        $customDir = $this->tempDir . '/my-templates';
        @mkdir($customDir, 0775, true);

        $providers = ContainerFactory::defaultProviders();
        $mockConnection = $this->createMock(Connection::class);
        $providers[] = new class ($mockConnection) implements ServiceProviderInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;
                $builder->addDefinitions([
                    Connection::class => factory(static fn (): Connection => $connection),
                ]);
            }
        };

        $container = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
            'web_portal' => ['template_dir' => $customDir],
        ], $providers);

        /** @var \Phlex\Server\WebPortal\PageRenderer $renderer */
        $renderer = $container->get(\Phlex\Server\WebPortal\PageRenderer::class);
        $this->assertInstanceOf(\Phlex\Server\WebPortal\PageRenderer::class, $renderer);
        $this->assertSame($customDir, $this->readPrivate($renderer, 'templateDir'));

        // Singleton semantics: resolving twice yields the same instance.
        $this->assertSame($renderer, $container->get(\Phlex\Server\WebPortal\PageRenderer::class));
    }

    public function test_resolves_page_renderer_with_default_template_dir_when_config_missing(): void
    {
        $mockConnection = $this->createMock(Connection::class);

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($mockConnection) implements ServiceProviderInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;
                $builder->addDefinitions([
                    Connection::class => factory(static fn (): Connection => $connection),
                ]);
            }
        };

        $container = ContainerFactory::create($this->baseConfig(), $providers);

        /** @var \Phlex\Server\WebPortal\PageRenderer $renderer */
        $renderer = $container->get(\Phlex\Server\WebPortal\PageRenderer::class);
        $resolved = $this->readPrivate($renderer, 'templateDir');
        $this->assertIsString($resolved);
        $this->assertStringEndsWith(
            DIRECTORY_SEPARATOR . WebPortalServicesProvider::DEFAULT_TEMPLATE_DIR,
            (string) $resolved
        );
    }

    public function test_logger_aliases_resolve_to_structured_loggers(): void
    {
        $container = ContainerFactory::create($this->baseConfig());

        foreach (CoreServicesProvider::channels() as $alias => $_channel) {
            $this->assertInstanceOf(
                StructuredLogger::class,
                $container->get($alias),
                "alias {$alias} should resolve to StructuredLogger"
            );
        }
    }

    public function test_compile_dir_created_when_flag_enabled(): void
    {
        $compileDir = $this->tempDir . '/compiled';
        putenv('PHLEX_CONTAINER_COMPILE=1');

        try {
            // PHP-DI 7 cannot compile closures that capture `use` variables;
            // the current provider style relies on closures, so we only
            // assert here that the factory honours the flag by creating
            // the compile directory and (so as not to depend on the
            // compiler succeeding) suppress the inner failure. The compile
            // path is exercised end-to-end in `ContainerFactoryCompileTest`
            // once Phase B replaces closure factories with invokable
            // classes.
            try {
                ContainerFactory::create([
                    'logger_config_path' => $this->loggerConfigPath,
                    'compile_dir' => $compileDir,
                ]);
            } catch (\Throwable $e) {
                $this->assertStringContainsString('compile', strtolower($e->getMessage()));
            }
            $this->assertDirectoryExists($compileDir);
        } finally {
            putenv('PHLEX_CONTAINER_COMPILE');
        }
    }

    /**
     * Read a private property without modifying production visibility.
     */
    private function readPrivate(object $target, string $property): mixed
    {
        $ref  = new ReflectionClass($target);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($target);
    }
}
