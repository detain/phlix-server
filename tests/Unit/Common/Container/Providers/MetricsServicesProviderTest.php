<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\Providers\MetricsServicesProvider;
use Phlix\Stats\Metrics\MetricsCollector;
use Phlix\Stats\Metrics\MetricsFlushService;
use Phlix\Stats\Metrics\MetricsRegistry;
use Phlix\Stats\Metrics\MetricsRepository;
use Phlix\Stats\Metrics\MetricsRepositoryInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Wiring test for {@see MetricsServicesProvider}.
 *
 * Builds a container with the provider plus a mocked MySQL {@see Connection}
 * (the flush service and repository depend on it) and proves that all four
 * metrics services resolve, that they are SHARED (one registry per worker, so
 * the hooks and the flush timer share the same counters), and that the
 * `enabled` flag threaded from config reaches the collector.
 *
 */
final class MetricsServicesProviderTest extends TestCase
{
    /**
     * Build a container with the metrics provider and a mocked Connection.
     *
     * @param array<string, mixed> $appConfig
     */
    private function container(array $appConfig = []): \Psr\Container\ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        (new MetricsServicesProvider())->register($builder, $appConfig);

        $connection = $this->createMock(Connection::class);
        $builder->addDefinitions([
            Connection::class => factory(static fn (): Connection => $connection),
        ]);

        return $builder->build();
    }

    public function test_all_metrics_services_are_registered(): void
    {
        $container = $this->container();

        $this->assertTrue($container->has(MetricsRegistry::class));
        $this->assertTrue($container->has(MetricsCollector::class));
        $this->assertTrue($container->has(MetricsFlushService::class));
        $this->assertTrue($container->has(MetricsRepository::class));
    }

    public function test_all_metrics_services_resolve(): void
    {
        $container = $this->container();

        $this->assertInstanceOf(MetricsRegistry::class, $container->get(MetricsRegistry::class));
        $this->assertInstanceOf(MetricsCollector::class, $container->get(MetricsCollector::class));
        $this->assertInstanceOf(MetricsFlushService::class, $container->get(MetricsFlushService::class));
        $this->assertInstanceOf(MetricsRepository::class, $container->get(MetricsRepository::class));
    }

    /**
     * Regression guard: the admin MetricsController type-hints
     * MetricsRepositoryInterface and AdminRoutes resolves the controller at
     * route registration, so the interface MUST resolve. Without the
     * interface->concrete alias PHP-DI throws "MetricsRepositoryInterface cannot
     * be resolved: the class is not instantiable" (which surfaced live as a
     * "Couldn't execute method Error::__toString" fatal).
     */
    public function test_repository_interface_resolves_to_concrete_repository(): void
    {
        $container = $this->container();

        $this->assertTrue($container->has(MetricsRepositoryInterface::class));
        $this->assertInstanceOf(
            MetricsRepository::class,
            $container->get(MetricsRepositoryInterface::class),
        );
    }

    public function test_interface_and_concrete_share_the_same_singleton(): void
    {
        $container = $this->container();

        // The alias must reuse the shared concrete instance, not build a second
        // repository (all metrics services are SHARED per worker).
        $this->assertSame(
            $container->get(MetricsRepository::class),
            $container->get(MetricsRepositoryInterface::class),
        );
    }

    public function test_registry_is_shared_singleton(): void
    {
        $container = $this->container();

        $first  = $container->get(MetricsRegistry::class);
        $second = $container->get(MetricsRegistry::class);
        $this->assertSame($first, $second);
    }

    /**
     * The collector, flush service and repository must all reference the SAME
     * shared registry instance — otherwise a request recorded via the collector
     * would never be seen by the flush service's drain.
     */
    public function test_collector_and_flush_service_share_one_registry(): void
    {
        $container = $this->container();

        /** @var MetricsRegistry $registry */
        $registry = $container->get(MetricsRegistry::class);
        /** @var MetricsCollector $collector */
        $collector = $container->get(MetricsCollector::class);

        $this->assertSame($registry, $collector->registry());

        /** @var MetricsFlushService $flush */
        $flush = $container->get(MetricsFlushService::class);
        $this->assertSame(
            $collector,
            $this->readPrivate($flush, 'collector'),
            'flush service must drain the same collector the hooks record into'
        );
    }

    public function test_collector_enabled_by_default(): void
    {
        $container = $this->container();

        /** @var MetricsCollector $collector */
        $collector = $container->get(MetricsCollector::class);
        $this->assertTrue($collector->isEnabled());
    }

    public function test_enabled_flag_from_config_reaches_collector(): void
    {
        $container = $this->container(['metrics' => ['enabled' => false]]);

        /** @var MetricsCollector $collector */
        $collector = $container->get(MetricsCollector::class);
        $this->assertFalse($collector->isEnabled());
    }

    public function test_latency_buckets_are_schema_locked_ignoring_config(): void
    {
        // A stray latency_buckets_ms override in config MUST be ignored: the
        // histogram columns are fixed by migration 046, so honouring arbitrary
        // bounds would silently zero every column and break percentiles. The
        // registry always receives the canonical schema-locked set.
        $container = $this->container(['metrics' => [
            'bucket_seconds'        => 30,
            'route_cardinality_cap' => 12,
            'latency_buckets_ms'    => [5, 25, 75],
        ]]);

        /** @var MetricsRegistry $registry */
        $registry = $container->get(MetricsRegistry::class);

        $this->assertSame(
            MetricsRegistry::DEFAULT_LATENCY_BUCKETS_MS,
            $registry->latencyBounds(),
            'Config latency_buckets_ms must not reach the registry (schema-locked).',
        );
    }

    public function test_flush_service_reads_config_knobs(): void
    {
        $container = $this->container(['metrics' => [
            'retention_days'         => 3,
            'connection_ttl_seconds' => 20,
            'flush_interval_seconds' => 7,
        ]]);

        /** @var MetricsFlushService $flush */
        $flush = $container->get(MetricsFlushService::class);

        $this->assertSame(3, $this->readPrivate($flush, 'retentionDays'));
        $this->assertSame(20, $this->readPrivate($flush, 'connectionTtlSeconds'));
        $this->assertSame(7, $this->readPrivate($flush, 'flushIntervalSeconds'));
    }

    public function test_falls_back_to_config_file_when_appconfig_missing_metrics(): void
    {
        // No 'metrics' key in appConfig -> provider includes config/metrics.php.
        // The bindings must still resolve to real objects.
        $container = $this->container([]);

        $this->assertInstanceOf(MetricsCollector::class, $container->get(MetricsCollector::class));
        $this->assertInstanceOf(MetricsRegistry::class, $container->get(MetricsRegistry::class));
    }

    /**
     * Read a private property without changing production visibility.
     */
    private function readPrivate(object $target, string $property): mixed
    {
        $ref  = new ReflectionClass($target);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($target);
    }
}
