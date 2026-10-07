<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use DI\ContainerBuilder;
use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthManager;
use Phlix\Auth\AuthMethodPolicy;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Server\Http\Controllers\Admin\AdminSettingsController;
use Phlix\Server\Http\Controllers\AuthProviderController;
use Phlix\Server\Http\Controllers\WebAuthnController;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * F7 — the PRODUCTION DI wiring of the auth-method policy.
 *
 * Every consumer takes `?AuthMethodPolicy $authPolicy = null` as an OPTIONAL
 * constructor parameter, and PHP-DI's `autowire()` SKIPS optional parameters:
 * unless each provider names the argument explicitly, the container hands back
 * guards whose `$authPolicy` is null. For the write surfaces that null means a
 * hard 500 (fail-closed), so a missing binding would be loud — but the login
 * surfaces would silently run UNGUARDED for password/WebAuthn while the whole
 * hand-built suite stays green. Same defect class as
 * AuthManagerSessionTeardownWiringGuardTest (M-1): a test which builds the
 * object by hand cannot prove the container builds it. So this file never
 * calls `new` on any consumer below.
 */
final class AuthMethodPolicyWiringGuardTest extends TestCase
{
    private ?ContainerInterface $sharedContainer = null;

    public function testTheContainerComposesTheAuthMethodPolicy(): void
    {
        $policy = $this->container()->get(AuthMethodPolicy::class);

        $this->assertInstanceOf(AuthMethodPolicy::class, $policy);

        $logger = (new ReflectionClass(AuthMethodPolicy::class))->getProperty('logger');
        $logger->setAccessible(true);
        $this->assertNotNull(
            $logger->getValue($policy),
            'AuthMethodPolicy::$logger is null — the all-disabled fallback event would be '
            . 'silently dropped. AuthServicesProvider must name the logger.auth parameter.',
        );
    }

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function consumerProvider(): array
    {
        return [
            'AuthManager (login + OPDS gates)' => [AuthManager::class, 'authPolicy'],
            'AdminSettingsController (PUT guard)' => [AdminSettingsController::class, 'authPolicy'],
            'AuthProviderController (disable guard)' => [AuthProviderController::class, 'authPolicy'],
            'WebAuthnController (ceremony gates)' => [WebAuthnController::class, 'authPolicy'],
        ];
    }

    /**
     * @param class-string $consumer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('consumerProvider')]
    public function testConsumerCarriesThePolicyFromTheContainer(string $consumer, string $property): void
    {
        $instance = $this->container()->get($consumer);
        $this->assertInstanceOf($consumer, $instance);

        $reflected = (new ReflectionClass($consumer))->getProperty($property);
        $reflected->setAccessible(true);

        $this->assertNotNull(
            $reflected->getValue($instance),
            "{$consumer}::\${$property} is null after container resolution — PHP-DI skipped the "
            . 'optional parameter, so the F7 lock-out guard/enforcement on this surface is inert '
            . 'in production while every hand-built unit test passes it positionally.',
        );
    }

    // ---- helpers -------------------------------------------------------------

    /**
     * B2 provider-parity precondition: the serialization protocol (BEGIN →
     * FOR UPDATE → locked re-read → persist → COMMIT) is only real if the
     * guard's locks and the surface's writes traverse the SAME connection.
     * Production gets that from the PHP-DI singleton SettingsRepository —
     * this pins it between the policy (which locks) and the bootstrapper
     * (whose disable()/enable() writes inside the transaction). A future
     * factory-scoped split would silently unserialize the protocol while
     * every hand-wired unit test stays green; it must redden HERE first.
     */
    public function testPolicyAndBootstrapperShareOneSettingsRepositorySingleton(): void
    {
        $container = $this->container();

        $policy = $container->get(AuthMethodPolicy::class);
        $bootstrapper = $container->get(AuthProviderBootstrapper::class);

        $extract = static function (object $instance): SettingsRepository {
            $property = (new ReflectionClass($instance))->getProperty('settings');
            $property->setAccessible(true);

            /** @var SettingsRepository */
            return $property->getValue($instance);
        };

        $this->assertSame(
            $extract($policy),
            $extract($bootstrapper),
            'AuthMethodPolicy and AuthProviderBootstrapper resolved to DIFFERENT SettingsRepository '
            . 'instances — the provider protocol would lock one connection while disable() writes '
            . 'another (lock theater). The container must memoize one singleton.',
        );
    }

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
