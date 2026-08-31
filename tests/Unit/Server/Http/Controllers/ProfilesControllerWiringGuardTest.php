<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use DI\ContainerBuilder;
use Phlix\Access\ProfileAccessPolicy;
use Phlix\Auth\AuthManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\RateLimit\DbRateLimiter;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Http\Controllers\ProfilesController;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S81 — the PRODUCTION DI wiring of {@see ProfilesController}.
 *
 * ## The hole this closes
 *
 * The controller's fifth constructor parameter is its PIN-verify rate
 * limiter. PHP-DI's `autowire()` **silently skips optional constructor
 * parameters**, so had that dependency been declared
 * `?RateLimiterInterface $pinVerifyLimiter = null`, the container would hand
 * back a controller whose limiter is null — every hand-wired unit test stays
 * green while production answers the PIN oracle UNTHROTTLED. That is exactly
 * the S81 blocker record ("no rate limiter anywhere near it"), re-opened by
 * a wiring mistake instead of by missing code. The failure mode is already
 * documented in `AuthServicesProvider` for `UserProfileManager::$settings`,
 * and in the other wiring guards this file mirrors (S79/S80).
 *
 * A test that builds the controller by hand cannot prove the container
 * builds it. So this file never calls `new ProfilesController(...)`: it
 * resolves the class out of a container assembled from
 * {@see ContainerFactory::defaultProviders()} — the same provider stack
 * `public/index.php` and the Workerman daemon use, with only the MySQL
 * {@see Connection} doubled — and reads each collaborator back off the
 * constructed instance.
 *
 * The final test is the belt to that brace: every constructor parameter is
 * REQUIRED and non-nullable, so nobody can reintroduce the fail-open shape
 * and leave the resolution test passing by luck.
 */
final class ProfilesControllerWiringGuardTest extends TestCase
{
    private ?ContainerInterface $sharedContainer = null;

    /**
     * The controller the production container builds carries the DB-backed
     * PIN-verify limiter — not a null, and not a worker-local in-memory one.
     */
    public function testTheContainerComposedControllerCarriesTheDbBackedPinLimiter(): void
    {
        $controller = $this->container()->get(ProfilesController::class);

        $this->assertInstanceOf(ProfilesController::class, $controller);

        $limiter = $this->collaborator($controller, 'pinVerifyLimiter');

        $this->assertNotNull(
            $limiter,
            'ProfilesController::$pinVerifyLimiter is null after container resolution — the '
            . 'PIN-verify endpoint would be an unthrottled oracle in production',
        );
        $this->assertInstanceOf(
            DbRateLimiter::class,
            $limiter,
            'the pin_verify surface is in RateLimitProfiles::dbBacked(); a worker-local limiter '
            . 'here would hand out roughly max × workers attempts',
        );
    }

    /**
     * The other four collaborators resolve too — a controller the container
     * cannot build is a 500 on every profiles request.
     */
    public function testTheContainerComposedControllerCarriesEveryCollaborator(): void
    {
        $controller = $this->container()->get(ProfilesController::class);

        $this->assertInstanceOf(
            UserProfileManager::class,
            $this->collaborator($controller, 'profiles'),
        );
        $this->assertInstanceOf(
            ProfileAccessPolicy::class,
            $this->collaborator($controller, 'accessPolicy'),
        );
        $this->assertInstanceOf(
            AuthManager::class,
            $this->collaborator($controller, 'auth'),
        );
        $this->assertInstanceOf(
            AvatarStorage::class,
            $this->collaborator($controller, 'avatars'),
        );
    }

    /**
     * Every constructor parameter is REQUIRED and non-nullable.
     *
     * Without this, "fixing" a broken container by defaulting a parameter to
     * `null` would restore the silent-degradation bug while the resolution
     * tests above kept passing by luck — PHP-DI would skip the parameter and
     * the class would be constructible without it in every other test too.
     */
    public function testEveryConstructorParameterIsRequiredAndNonNullable(): void
    {
        $constructor = (new ReflectionClass(ProfilesController::class))->getConstructor();
        $this->assertNotNull($constructor);

        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $constructor->getParameters(),
        );

        foreach (['profiles', 'accessPolicy', 'auth', 'avatars', 'pinVerifyLimiter'] as $expected) {
            $this->assertContains($expected, $names, "the $expected dependency must be a constructor parameter");
        }

        foreach ($constructor->getParameters() as $parameter) {
            $this->assertFalse(
                $parameter->isOptional(),
                sprintf(
                    'ProfilesController::$%s must be REQUIRED — PHP-DI silently skips optional parameters',
                    $parameter->getName(),
                ),
            );
            $this->assertFalse(
                $parameter->allowsNull(),
                sprintf(
                    'ProfilesController::$%s must be non-nullable, so a null can never reach the endpoint',
                    $parameter->getName(),
                ),
            );
        }
    }

    // ---- helpers -------------------------------------------------------------

    /**
     * The PRODUCTION container: `ContainerFactory::defaultProviders()`, with
     * only the MySQL {@see Connection} doubled.
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
            'logger_config_path' => dirname(__DIR__, 4) . '/config/logger.php',
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
