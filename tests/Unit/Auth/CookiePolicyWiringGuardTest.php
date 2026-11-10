<?php

/**
 * Phlix media server component: Auth tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use DI\ContainerBuilder;
use Phlix\Auth\CookiePolicy;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Server\Http\Controllers\AuthController;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * CP-003 — the PRODUCTION DI wiring of the auth-cookie policy.
 *
 * Same class of defect this repo's sibling guards exist for (S80, M-1):
 * `AuthController::$cookiePolicy` is an optional constructor parameter and
 * PHP-DI's `autowire()` SKIPS optional params. If `AuthServicesProvider`
 * stopped naming it, the container would hand back a controller running the
 * store-less default policy: `auth.cookie.enabled` / `auth.cookie.insecure`
 * become silently inert (the posture stays safe, but the admin-facing
 * cookie-program rollback lever (§7) stops working) and every hand-built
 * CookieDualEmitTest leg stays GREEN because none of them resolve the
 * container. This file proves the container does.
 */
final class CookiePolicyWiringGuardTest extends TestCase
{
    private ?ContainerInterface $sharedContainer = null;

    public function testTheContainerComposedAuthControllerCarriesACookiePolicy(): void
    {
        $controller = $this->container()->get(AuthController::class);

        $this->assertInstanceOf(AuthController::class, $controller);

        $reflected = (new ReflectionClass(AuthController::class))->getProperty('cookiePolicy');
        $reflected->setAccessible(true);
        $policy = $reflected->getValue($controller);

        $this->assertInstanceOf(
            CookiePolicy::class,
            $policy,
            'AuthController::$cookiePolicy is not the container-composed policy — PHP-DI skipped '
            . 'the optional parameter, so the auth.cookie.* toggles are inert in production.'
        );

        $this->assertSame(
            $this->container()->get(CookiePolicy::class),
            $policy,
            'The controller holds a policy instance distinct from the container singleton — '
            . 'the constructorParameter binding regressed to an implicit new.'
        );
    }

    public function testTheContainerComposedCookiePolicyCarriesTheSettingsStore(): void
    {
        $policy = $this->container()->get(CookiePolicy::class);

        $reflected = (new ReflectionClass(CookiePolicy::class))->getProperty('settings');
        $reflected->setAccessible(true);

        $this->assertNotNull(
            $reflected->getValue($policy),
            'CookiePolicy::$settings is null after container resolution — PHP-DI skipped the '
            . 'optional store, so live reads of auth.cookie.enabled/.insecure always answer '
            . 'with defaults and the §7 config-level rollback lever is theater.'
        );
    }

    /**
     * The PRODUCTION container: `ContainerFactory::defaultProviders()`, with only
     * the MySQL {@see Connection} doubled (sibling-guard idiom).
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
