<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use DI\ContainerBuilder;
use Phlix\Auth\AuthManager;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * M-1 (security audit rework 2026-09-29) — the PRODUCTION DI wiring of the
 * logout device-session teardown.
 *
 * ## The silent degradation this closes
 *
 * `AuthManager::$sessionManager` is the collaborator `logout()` calls
 * ({@see SessionManager::endAllUserSessions()}) to end the user's `sessions`
 * rows server-side alongside the JWT watermark bump. It is an OPTIONAL
 * constructor parameter — like every other collaborator this file's sibling
 * guard exists for — and PHP-DI's `autowire()` SKIPS optional constructor
 * parameters. Unless `AuthServicesProvider` names it explicitly, the container
 * hands back an `AuthManager` whose `$sessionManager` is null: `logout()`
 * silently skips the session teardown, cookie-backed device sessions outlive
 * the "REAL server-side logout" the openapi description promises, and the
 * entire suite stays GREEN because every hand-built test manager passes the
 * parameter positionally.
 *
 * Same class of defect as `AuthManagerProfileClaimWiringGuardTest` (S80); the
 * whole point of both is that **a test which builds the object by hand cannot
 * prove the container builds it.** So this file never calls
 * `new AuthManager(...)`.
 */
final class AuthManagerSessionTeardownWiringGuardTest extends TestCase
{
    private ?ContainerInterface $sharedContainer = null;

    /**
     * The container-composed `AuthManager` carries a real SessionManager, so
     * logout() actually ends device sessions in production.
     */
    public function testTheContainerComposedAuthManagerCarriesASessionManager(): void
    {
        $authManager = $this->container()->get(AuthManager::class);

        $this->assertInstanceOf(AuthManager::class, $authManager);

        $reflected = (new ReflectionClass(AuthManager::class))->getProperty('sessionManager');
        $reflected->setAccessible(true);
        $sessionManager = $reflected->getValue($authManager);

        $this->assertNotNull(
            $sessionManager,
            'AuthManager::$sessionManager is null after container resolution. PHP-DI skipped the '
            . 'optional parameter, so logout() silently skips endAllUserSessions() and every '
            . 'cookie-backed device session survives the "real server-side logout" the openapi '
            . 'spec advertises — M-1 teardown inert, suite green.'
        );
        $this->assertInstanceOf(SessionManager::class, $sessionManager);
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
