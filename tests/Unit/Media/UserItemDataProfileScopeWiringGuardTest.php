<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media;

use DI\ContainerBuilder;
use Phlix\Auth\UserProfileManager;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Media\UserItemDataRepository;
use Phlix\Server\Http\Controllers\MediaUserDataController;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S79 — the PRODUCTION DI wiring of {@see UserItemDataRepository}'s profile scope.
 *
 * ## The hole this closes
 *
 * `UserItemDataRepository` now resolves — and ownership-checks — the profile every
 * favorite/rating/like/watched row is written under, through an injected
 * {@see UserProfileManager}. PHP-DI's `autowire()` **silently skips optional
 * constructor parameters**, so had that dependency been declared
 * `?UserProfileManager $profiles = null`, the container would have handed back a
 * repository with `$profiles === null` and every hand-wired unit test would still
 * be green — while in production the first `setFavorite()` fatals and the reads
 * lose their profile predicate entirely. That exact failure mode is already
 * documented in `AuthServicesProvider` for `UserProfileManager::$settings`, and in
 * `project_di_provider_silent_degradation`.
 *
 * **A test that builds the repository by hand cannot prove the container builds
 * it.** So this file never calls `new UserItemDataRepository(...)`: it resolves the
 * class out of a container assembled from `ContainerFactory::defaultProviders()` —
 * the same provider stack `public/index.php` and the Workerman daemon use, with
 * only the MySQL {@see Connection} doubled — and reads the collaborator back off
 * the constructed instance.
 *
 * The final test is the belt to that brace: it asserts the constructor parameter
 * is REQUIRED, so nobody can reintroduce the fail-open shape and leave the
 * resolution test passing by luck.
 */
final class UserItemDataProfileScopeWiringGuardTest extends TestCase
{
    private ?ContainerInterface $sharedContainer = null;

    /**
     * The repository the production container builds carries a real profile
     * resolver.
     */
    public function testTheContainerComposedRepositoryCarriesAProfileResolver(): void
    {
        $repository = $this->container()->get(UserItemDataRepository::class);

        $this->assertInstanceOf(UserItemDataRepository::class, $repository);

        $profiles = $this->collaborator($repository, 'profiles');

        $this->assertNotNull(
            $profiles,
            'UserItemDataRepository::$profiles is null after container resolution — every '
            . 'profile-scoped write would fatal and every read would lose its profile predicate'
        );
        $this->assertInstanceOf(UserProfileManager::class, $profiles);
    }

    /**
     * The controller that actually serves the favorite/rating/like/watched routes
     * gets that same wired repository, not a fresh unwired one.
     */
    public function testTheMediaUserDataControllerReceivesTheWiredRepository(): void
    {
        $controller = $this->container()->get(MediaUserDataController::class);

        $this->assertInstanceOf(MediaUserDataController::class, $controller);

        $repository = $this->collaborator($controller, 'userItemData');
        $this->assertInstanceOf(UserItemDataRepository::class, $repository);

        $profiles = $this->collaborator($repository, 'profiles');
        $this->assertInstanceOf(
            UserProfileManager::class,
            $profiles,
            "the controller's repository must itself be profile-wired"
        );
    }

    /**
     * The constructor parameter must stay REQUIRED and non-nullable.
     *
     * Without this, someone "fixing" a broken test by defaulting the parameter to
     * `null` would restore the silent-degradation bug and the resolution test above
     * would keep passing — PHP-DI would simply skip the parameter and the
     * assertion `assertNotNull` would be the only thing to catch it, which it
     * would not, because the class would then be constructible without it in
     * every other test too.
     */
    public function testTheProfileResolverParameterIsRequiredAndNonNullable(): void
    {
        $constructor = (new ReflectionClass(UserItemDataRepository::class))->getConstructor();
        $this->assertNotNull($constructor);

        $parameters = $constructor->getParameters();
        $names = [];
        foreach ($parameters as $parameter) {
            $names[] = $parameter->getName();
        }

        $this->assertContains('profiles', $names, 'the profile resolver must be a constructor parameter');

        foreach ($parameters as $parameter) {
            if ($parameter->getName() !== 'profiles') {
                continue;
            }

            $this->assertFalse(
                $parameter->isOptional(),
                'the profile resolver must be REQUIRED — PHP-DI silently skips optional parameters'
            );
            $this->assertFalse(
                $parameter->allowsNull(),
                'the profile resolver must be non-nullable, so a null can never reach the scope helper'
            );

            $type = $parameter->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertSame(UserProfileManager::class, $type->getName());
        }
    }

    // ---- helpers -------------------------------------------------------------

    /**
     * The PRODUCTION container: `ContainerFactory::defaultProviders()`, with only
     * the MySQL {@see Connection} doubled.
     */
    private function container(): ContainerInterface
    {
        if ($this->sharedContainer !== null) {
            return $this->sharedContainer;
        }

        $connection = $this->createMock(Connection::class);

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($connection) implements ServiceProviderInterface {
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

        return $this->sharedContainer = ContainerFactory::create([
            'logger_config_path' => dirname(__DIR__, 3) . '/config/logger.php',
            'db_config_path' => null,
        ], $providers);
    }

    /**
     * Read a private collaborator off a constructed instance by reflection.
     */
    private function collaborator(object $instance, string $property): ?object
    {
        $reflected = (new ReflectionClass($instance))->getProperty($property);
        $reflected->setAccessible(true);

        $value = $reflected->getValue($instance);

        return is_object($value) ? $value : null;
    }
}
