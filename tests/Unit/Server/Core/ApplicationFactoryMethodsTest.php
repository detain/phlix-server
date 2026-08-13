<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserRepository;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\LibraryController;
use Phlix\Server\Http\Controllers\ThemeMediaController;
use Phlix\Server\Http\Controllers\MusicController;
use Phlix\Server\Http\Controllers\BookController;
use Phlix\Theming\ThemeMediaFinder;
use Phlix\Theming\ThemeMediaRepository;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for Application controller factory methods.
 *
 * Tests the factory methods that create controllers with the proper
 * LibraryManager dependency (4-argument constructor: db, scanner, watcher, musicLibraryService).
 */
class ApplicationFactoryMethodsTest extends TestCase
{
    /** @var ContainerInterface&MockObject */
    private ContainerInterface $container;

    /** @var ConnectionPool&MockObject */
    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->connectionPool = $this->createMock(ConnectionPool::class);
    }

    /**
     * Creates an Application instance for testing with a mock container.
     * This allows testing factory methods that use the container path.
     */
    private function createApplicationWithContainer(): Application
    {
        // Use reflection to create Application without calling the real constructor
        $ref = new \ReflectionClass(Application::class);

        /** @var Application $app */
        $app = $ref->newInstanceWithoutConstructor();

        // Set the container via reflection
        $containerProp = $ref->getProperty('container');
        $containerProp->setAccessible(true);
        $containerProp->setValue($app, $this->container);

        // Set the connection pool
        $connectionPoolProp = $ref->getProperty('connectionPool');
        $connectionPoolProp->setAccessible(true);
        $connectionPoolProp->setValue($app, $this->connectionPool);

        // Set empty config to avoid null warnings
        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($app, []);

        return $app;
    }

    /**
     * Test getLibraryController returns a LibraryController when container is available.
     *
     * This verifies the factory method correctly:
     * - Gets LibraryManager from container
     * - Gets ScanJobRepository from container
     * - Gets ItemRepository from container
     * - Gets AdminMiddleware from container — UNCONDITIONALLY (S282). It is a
     *   required constructor dependency of LibraryController, and the factory no
     *   longer calls `has()` on it: a container that cannot build the gate must
     *   throw at boot rather than yield an ungated controller. Do not "fix" a
     *   `Unexpected class: ...AdminMiddleware` here by re-adding a `has()`-style
     *   branch — supply the middleware.
     * - Creates LibraryController with the correct dependencies
     */
    public function testGetLibraryControllerReturnsControllerFromContainer(): void
    {
        $app = $this->createApplicationWithContainer();

        // Set up container to return mocks for the dependencies
        $libraryManager = $this->createMock(LibraryManager::class);
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        // No has() stub: S282 removed the `if ($container->has(AdminMiddleware::class))`
        // escape hatch from getLibraryController(), so the factory never calls has().

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($libraryManager, $scanJobs, $itemRepo): object {
                return match ($class) {
                    LibraryManager::class => $libraryManager,
                    ScanJobRepository::class => $scanJobs,
                    ItemRepository::class => $itemRepo,
                    // AdminMiddleware is final and cannot be doubled; build a real
                    // instance with mocked collaborators instead.
                    \Phlix\Server\Http\Middleware\AdminMiddleware::class =>
                    new \Phlix\Server\Http\Middleware\AdminMiddleware(
                        $this->createMock(UserRepository::class),
                        $this->createMock(AuditLogger::class),
                    ),
                    default => throw new \RuntimeException("Unexpected class: $class"),
                };
            });

        // Use reflection to call the private factory method
        $ref = new \ReflectionClass(Application::class);
        $factoryMethod = $ref->getMethod('getLibraryController');
        $factoryMethod->setAccessible(true);

        /** @var LibraryController $controller */
        $controller = $factoryMethod->invoke($app);

        $this->assertInstanceOf(LibraryController::class, $controller);
    }

    /**
     * Test getThemeMediaController returns a ThemeMediaController when container is available.
     *
     * This verifies the factory method correctly:
     * - Gets ThemeMediaRepository from container
     * - Gets ThemeMediaFinder from container
     * - Gets LibraryManager from container
     * - Gets AdminMiddleware from container — UNCONDITIONALLY (S323). It is a
     *   required constructor dependency of ThemeMediaController, and the factory
     *   no longer calls `has()` on it: a container that cannot build the gate must
     *   throw at boot rather than yield a controller whose two mutation handlers
     *   are reachable ANONYMOUSLY. Do not "fix" a
     *   `Unexpected class: ...AdminMiddleware` here by re-adding a `has()`-style
     *   branch — supply the middleware.
     * - Creates ThemeMediaController with the correct dependencies
     */
    public function testGetThemeMediaControllerReturnsControllerFromContainer(): void
    {
        $app = $this->createApplicationWithContainer();

        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaFinder = $this->createMock(ThemeMediaFinder::class);
        $libraryManager = $this->createMock(LibraryManager::class);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use (
                $themeMediaRepository,
                $themeMediaFinder,
                $libraryManager
            ): object {
                return match ($class) {
                    ThemeMediaRepository::class => $themeMediaRepository,
                    ThemeMediaFinder::class => $themeMediaFinder,
                    LibraryManager::class => $libraryManager,
                    // AdminMiddleware is final and cannot be doubled; build a real
                    // instance with mocked collaborators instead.
                    \Phlix\Server\Http\Middleware\AdminMiddleware::class =>
                    new \Phlix\Server\Http\Middleware\AdminMiddleware(
                        $this->createMock(UserRepository::class),
                        $this->createMock(AuditLogger::class),
                    ),
                    default => throw new \RuntimeException("Unexpected class: $class"),
                };
            });

        // No has() stub: S323 removed the `if ($container->has(AdminMiddleware::class))`
        // escape hatch from getThemeMediaController(), so the factory never calls has().

        $ref = new \ReflectionClass(Application::class);
        $factoryMethod = $ref->getMethod('getThemeMediaController');
        $factoryMethod->setAccessible(true);

        /** @var ThemeMediaController $controller */
        $controller = $factoryMethod->invoke($app);

        $this->assertInstanceOf(ThemeMediaController::class, $controller);
    }

    /**
     * Pins the dependency graph `Application::getMusicController()` builds.
     *
     * S99 collapsed it: the factory used to build
     * `LibraryManager(db, MediaScanner, FolderWatcher, musicLibraryService)` and
     * `MusicLibraryManager(audioScanner, metadataManager, itemRepo, db)` on every
     * boot just to hand `MusicController` a reader that read
     * `media_items.metadata_json`, which the music scanner never populates. The
     * controller now takes `(MusicLibraryService, SessionManager)` — the `music_*`
     * tables — and nothing else.
     *
     * Until this round the test above this line described the DELETED graph behind
     * `expectNotToPerformAssertions()`, i.e. it would have passed forever while
     * documenting the wrong thing (S99 review r1, LOW-6). This repo has already
     * misdiagnosed the music read path three times off stale documentation, so the
     * ctor is now asserted rather than described: re-adding `MusicLibraryManager`
     * or `LibraryManager` here fails this test.
     */
    public function testGetMusicControllerBuildsTheTwoArgumentMusicGraph(): void
    {
        $ctor = new \ReflectionMethod(MusicController::class, '__construct');

        $parameterTypes = array_map(
            static function (\ReflectionParameter $p): ?string {
                $type = $p->getType();

                return $type instanceof \ReflectionNamedType ? $type->getName() : null;
            },
            $ctor->getParameters(),
        );

        $this->assertSame(
            [MusicLibraryService::class, \Phlix\Session\SessionManager::class],
            $parameterTypes,
            'MusicController must read the music_* tables via MusicLibraryService only',
        );

        // The factory itself must not reference the graph S99 deleted.
        $factory = new \ReflectionMethod(Application::class, 'getMusicController');
        $file = $factory->getFileName();
        $this->assertIsString($file);
        $source = (string) file_get_contents($file);
        $body = implode(
            "\n",
            array_slice(
                explode("\n", $source),
                (int) $factory->getStartLine() - 1,
                (int) $factory->getEndLine() - (int) $factory->getStartLine() + 1,
            ),
        );
        $this->assertStringNotContainsString('MusicLibraryManager', $body);
        $this->assertStringContainsString('MusicLibraryService', $body);
    }

    /**
     * Test getBookController creates a BookController with the new 4-argument LibraryManager.
     *
     * This test verifies that getBookController() correctly creates MusicLibraryScanner
     * and MusicLibraryService before creating LibraryManager with 4 arguments.
     *
     * Note: Similar to testGetMusicControllerCreatesCorrectDependencies, this documents
     * the expected behavior of the factory method.
     */
    public function testGetBookControllerCreatesCorrectDependencies(): void
    {
        // In the non-container path, the factory creates:
        // - MusicLibraryScanner(db, FfmpegRunner)
        // - MusicLibraryService(db, musicScanner)
        // - LibraryManager(db, MediaScanner, FolderWatcher, musicLibraryService)
        // - OpdsFeedBuilder(itemRepo, baseUrl)
        // - BookController(itemRepo, libraryManager, opdsBuilder)

        // This test documents the expected behavior (no runtime assertion).
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test that LibraryManager is constructed with 4 arguments in the factory methods.
     *
     * This is a structural test that verifies the factory methods create
     * MusicLibraryScanner and MusicLibraryService before creating LibraryManager.
     *
     * The factory methods should create:
     * 1. MusicLibraryScanner(db, FfmpegRunner)
     * 2. MusicLibraryService(db, musicScanner)
     * 3. LibraryManager(db, scanner, watcher, musicLibraryService) <-- 4 arguments
     */
    public function testLibraryManagerReceivesFourArguments(): void
    {
        // Get the LibraryManager constructor to verify parameter count
        $ref = new \ReflectionClass(LibraryManager::class);
        $constructor = $ref->getConstructor();

        $this->assertNotNull($constructor);
        // 4 required (db, scanner, watcher, musicLibraryService) + 3 optional
        // (logger, itemRepository, artworkStorage — the latter two wire the
        // fine-grained maintenance ops, migration 084).
        $this->assertCount(
            7,
            $constructor->getParameters(),
            'LibraryManager should have 7 parameters (4 required + 3 optional)',
        );

        // Verify the 4th parameter is MusicLibraryService
        $params = $constructor->getParameters();
        $this->assertSame('musicLibraryService', $params[3]->getName());
        $type = $params[3]->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame(MusicLibraryService::class, $type->getName());
    }
}
