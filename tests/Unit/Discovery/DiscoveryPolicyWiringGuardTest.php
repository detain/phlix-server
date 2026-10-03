<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Discovery;

use DI\ContainerBuilder;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Discovery\DiscoveryPolicy;
use Phlix\Discovery\DiscoveryServer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * W2 — the PRODUCTION DI wiring of the discovery gates.
 *
 * `DiscoveryServer::__construct` takes `?DiscoveryPolicy $policy = null` as an
 * OPTIONAL parameter, and PHP-DI's autowire SKIPS optional parameters: unless
 * the provider names `policy` explicitly, the container hands back a server
 * whose `$policy` is the null-store default — both probes permanently on —
 * while every hand-built unit test passes the policy positionally and stays
 * green. Identical defect class to AuthMethodPolicyWiringGuardTest (and the
 * 2e63b30 law); this file therefore never calls `new` on the server.
 */
final class DiscoveryPolicyWiringGuardTest extends TestCase
{
    private ?ContainerInterface $sharedContainer = null;

    public function testTheContainerComposesTheDiscoveryPolicy(): void
    {
        $policy = $this->container()->get(DiscoveryPolicy::class);

        self::assertInstanceOf(DiscoveryPolicy::class, $policy);

        self::assertNotNull(
            self::property($policy, 'settings'),
            'DiscoveryPolicy::$settings is null in the production container — the '
            . 'DlnaServicesProvider policy factory lost its optionalSettings() thread, '
            . 'so admin-saved discovery gates are inert.',
        );
    }

    public function testTheContainerNthreadsThePolicyIntoDiscoveryServer(): void
    {
        $server = $this->container()->get(DiscoveryServer::class);
        self::assertInstanceOf(DiscoveryServer::class, $server);

        $policy = self::property($server, 'policy');
        self::assertInstanceOf(
            DiscoveryPolicy::class,
            $policy,
            'DiscoveryServer::$policy missing after container resolution — PHP-DI skipped '
            . 'the optional parameter because the binding stopped naming it.',
        );

        self::assertTrue(
            self::property($policy, 'settings') instanceof SettingsRepository,
            'DiscoveryServer resolved by the container carries a settings-less policy: '
            . 'constructorParameter(policy) is wired to a stale/default DiscoveryPolicy.',
        );
    }

    private static function property(object $subject, string $name): mixed
    {
        $reflected = (new ReflectionClass($subject))->getProperty($name);
        $reflected->setAccessible(true);

        return $reflected->getValue($subject);
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
