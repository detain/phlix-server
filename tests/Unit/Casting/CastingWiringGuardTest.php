<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Casting;

use DI\ContainerBuilder;
use Phlix\AirPlay\AirPlayManager;
use Phlix\Casting\CastingSessionStore;
use Phlix\Chromecast\CastManager;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Dlna\PlayToManager;
use Phlix\Roku\RokuManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Device-M1 — the PRODUCTION DI wiring of the casting-session store.
 *
 * ## The silent degradation this closes
 *
 * All four device managers ({@see PlayToManager}, {@see RokuManager},
 * {@see CastManager}, {@see AirPlayManager}) take the shared
 * `CastingSessionStoreInterface` as a TRAILING-OPTIONAL constructor parameter,
 * and PHP-DI's `autowire()` SKIPS optional constructor parameters. Unless
 * `CastingServicesProvider` names `store` explicitly — which it does, via
 * `->constructorParameter('store', get(CastingSessionStore::class))` — the
 * container hands back managers whose `$store` is null: every cast silently
 * reverts to the pre-Device-M1 worker-local map, cross-worker pause/stop
 * 404s again across the `count = 14` HTTP pool, and the entire suite stays
 * GREEN because all ~22 hand-built test constructions pass the parameter
 * positionally.
 *
 * Same class of defect as `AuthManagerSessionTeardownWiringGuardTest` (M-1)
 * and `AuthManagerProfileClaimWiringGuardTest` (S80) — this file mirrors that
 * file's construction exactly: the REAL `ContainerFactory::defaultProviders()`
 * with only the MySQL {@see Connection} doubled, and never a `new XManager(...)`.
 * **A test which builds the object by hand cannot prove the container builds
 * it**, so the four managers below are resolved from the container and their
 * private `$store` members are read by reflection.
 */
final class CastingWiringGuardTest extends TestCase
{
    private ?ContainerInterface $sharedContainer = null;

    /**
     * Every manager the live HTTP path resolves carries a LIVE CastingSessionStore,
     * and they all share the one store instance the provider binds.
     *
     * @return array<string, array{0: class-string}>
     */
    public static function managerProvider(): array
    {
        return [
            'PlayToManager' => [PlayToManager::class],
            'RokuManager' => [RokuManager::class],
            'CastManager' => [CastManager::class],
            'AirPlayManager' => [AirPlayManager::class],
        ];
    }

    /**
     * @param class-string $managerClass
     *
     * @dataProvider managerProvider
     */
    public function testTheContainerComposedManagerCarriesTheSharedStore(string $managerClass): void
    {
        $manager = $this->container()->get($managerClass);

        $this->assertInstanceOf($managerClass, $manager);

        $reflected = (new ReflectionClass($managerClass))->getProperty('store');
        $reflected->setAccessible(true);
        $store = $reflected->getValue($manager);

        $this->assertNotNull(
            $store,
            "{$managerClass}::\$store is null after container resolution. PHP-DI skipped the "
            . 'trailing-optional parameter, so casting sessions are worker-local again: a cast '
            . 'started on one of the 14 HTTP workers answers pause/stop/status on another with '
            . 'the pre-Device-M1 404, and the suite stays green because every unit test injects '
            . 'the store by hand.'
        );
        $this->assertInstanceOf(
            CastingSessionStore::class,
            $store,
            "{$managerClass}::\$store must be the concrete DB-backed CastingSessionStore the "
            . 'provider binds, not a stand-in.',
        );
    }

    public function testAllFourManagersShareOneStoreInstance(): void
    {
        $container = $this->container();

        $stores = [];
        foreach (self::managerProvider() as [$managerClass]) {
            $reflected = (new ReflectionClass($managerClass))->getProperty('store');
            $reflected->setAccessible(true);

            /** @var CastingSessionStore|null $store */
            $store = $reflected->getValue($container->get($managerClass));

            // Without this, four NULL stores would chain-assertSame(null, null)
            // green — the identity pin only means something on live objects.
            self::assertInstanceOf(CastingSessionStore::class, $store);
            $stores[$managerClass] = $store;
        }

        $first = reset($stores);
        foreach ($stores as $managerClass => $store) {
            self::assertSame(
                $first,
                $store,
                "{$managerClass} got its own store instance: the throttle memo stops being "
                . 'one per process and the provider\'s single get(CastingSessionStore::class) '
                . 'binding was replaced. Same DB, so nothing breaks — but this is not the '
                . 'wiring Device-M1 shipped, so it must be a deliberate change, not drift.',
            );
        }
    }

    // ---- helpers -------------------------------------------------------------

    /**
     * The PRODUCTION container: `ContainerFactory::defaultProviders()`, with only
     * the MySQL {@see Connection} doubled — the verbatim pattern of
     * `AuthManagerSessionTeardownWiringGuardTest::container()`.
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
}
