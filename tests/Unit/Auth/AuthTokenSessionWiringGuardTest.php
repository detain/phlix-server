<?php

/**
 * CP-029 / D-1a — the PRODUCTION DI wiring of the session-family store.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use DI\ContainerBuilder;
use Phlix\Auth\AuthManager;
use Phlix\Auth\TokenSessionRepository;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * The sibling guard of the class {@see AuthManagerSessionTeardownWiringGuardTest}
 * pins — same law, more dangerous collaborator.
 *
 * ## The silent degradation this closes
 *
 * `AuthManager::$tokenSessions` is an OPTIONAL constructor parameter, and
 * PHP-DI's `autowire()` SKIPS optional parameters. If
 * `AuthServicesProvider::getDefinitions()` ever stops naming it, the container
 * hands back an `AuthManager` whose store is null and the composition does not
 * merely lose a feature — it inverts:
 *
 *  - every mint carries NO `sid` claim (the wave silently never happened),
 *    per-device revocation and reuse detection are inert, and every hand-built
 *    14-positional-arg test still passes; and
 *  - worse, any D-1a-shaped token already in the wild (minted while the store
 *    was wired, or by a sibling worker after a partial deploy) fails CLOSED at
 *    validation (sid claim + no store → refused): users silently bounce to
 *    re-login with no error class to grep.
 *
 * The second arm is why this guard exists IN ADDITION to the unit convictions:
 * the failure is invisible to every hand-built test by construction — only the
 * REAL container proves the binding.
 *
 * ## The two arms this pins (each strips exactly one provider line)
 *
 *  1. dropping `->constructorParameter('tokenSessions', …)` from the
 *     AuthManager entry → `$tokenSessions` null → RED here.
 *  2. dropping the `TokenSessionRepository::class => autowire()…` entry (or
 *     un-naming its `db`) → container resolution of AuthManager THROWS
 *     (DefinitionNotFound / unbound Connection) → RED here.
 *
 * Per repo law this file never calls `new AuthManager(...)`.
 */
final class AuthTokenSessionWiringGuardTest extends TestCase
{
    private ?ContainerInterface $sharedContainer = null;

    /**
     * The container-composed AuthManager carries a real TokenSessionRepository.
     */
    public function testTheContainerComposedAuthManagerCarriesTheTokenSessionStore(): void
    {
        $authManager = $this->container()->get(AuthManager::class);

        $this->assertInstanceOf(AuthManager::class, $authManager);

        $reflected = (new ReflectionClass(AuthManager::class))->getProperty('tokenSessions');
        $reflected->setAccessible(true);
        $store = $reflected->getValue($authManager);

        $this->assertNotNull(
            $store,
            'AuthManager::$tokenSessions is null after container resolution. PHP-DI skipped the '
            . 'optional parameter, so D-1a silently never shipped: mints carry no sid claim, '
            . 'reuse detection and per-device revocation are inert, and any sid-carrying token '
            . 'already minted fails closed against the unwired manager. Suite-green invisibility '
            . 'is exactly the defect this pin exists to end (CP-029).'
        );
        $this->assertInstanceOf(TokenSessionRepository::class, $store);
    }

    /**
     * The store itself is container-resolvable with its pool-backed Connection
     * bound — an un-named `db` parameter here would autowire against the raw
     * Workerman Connection definition and a missing one THROWS.
     */
    public function testTheTokenSessionStoreResolvesWithItsConnectionBound(): void
    {
        $store = $this->container()->get(TokenSessionRepository::class);

        $this->assertInstanceOf(TokenSessionRepository::class, $store);

        $reflected = (new ReflectionClass(TokenSessionRepository::class))->getProperty('db');
        $reflected->setAccessible(true);

        $this->assertNotNull(
            $reflected->getValue($store),
            'TokenSessionRepository::$db is null — the family store would run against nothing.'
        );
    }

    /**
     * Identity arm: the manager's store and the container's store are the same
     * instance (PHP-DI singleton semantics), i.e. the provider names the ONE
     * definition rather than double-construction (which would double pools).
     */
    public function testTheManagerStoreIsTheContainerSharedInstance(): void
    {
        $authManager = $this->container()->get(AuthManager::class);
        $reflected = (new ReflectionClass(AuthManager::class))->getProperty('tokenSessions');
        $reflected->setAccessible(true);

        $this->assertSame(
            $this->container()->get(TokenSessionRepository::class),
            $reflected->getValue($authManager),
            'AuthManager received a different TokenSessionRepository than the container shares.'
        );
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
}
