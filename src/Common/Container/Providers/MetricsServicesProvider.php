<?php

/**
 * Phlix media server component: Providers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Stats\Metrics\MetricsCollector;
use Phlix\Stats\Metrics\MetricsFlushService;
use Phlix\Stats\Metrics\MetricsRegistry;
use Phlix\Stats\Metrics\MetricsRepository;
use Phlix\Stats\Metrics\MetricsRepositoryInterface;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\factory;
use function DI\get;

/**
 * Registers the metrics / live-traffic telemetry subsystem
 * ({@see \Phlix\Stats\Metrics}).
 *
 * All four services are registered as SHARED (singleton) instances so that a
 * single {@see MetricsRegistry} lives per forked worker: the request /
 * WebSocket hooks (wired in step S2) write into the very same registry that the
 * per-worker flush timer drains. If any of these were re-instantiated per
 * resolution the collector's counters would be silently dropped on every flush.
 *
 * Configuration is read from `$appConfig['metrics']` (threaded in by
 * config/server.php so it reaches BOTH entry points — the public/index.php CGI
 * path and the Workerman daemon in start.php). When that sub-array is absent
 * (e.g. a bare unit-test container) the provider falls back to a direct include
 * of config/metrics.php, and finally to the classes' own built-in defaults, so
 * the bindings always resolve.
 *
 * The registry constructor takes decomposed tuning knobs (`bucket_seconds`,
 * `route_cardinality_cap`; the latency histogram is schema-locked to
 * {@see MetricsRegistry::DEFAULT_LATENCY_BUCKETS_MS} and NOT config-driven);
 * the flush service and repository take the raw config array (they read
 * `retention_days` / `connection_ttl_seconds` / `flush_interval_seconds`
 * themselves). The collector is fed the `enabled` master switch and the default
 * `time()` clock — tests inject their own registry/clock directly rather than
 * through the container.
 *
 * @internal Phlix-internal service provider; consumed by ContainerFactory only.
 *
 * @package Phlix\Common\Container\Providers
 * @since S1
 */
final class MetricsServicesProvider implements ServiceProviderInterface
{
    /**
     * Register the metrics bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     *
     * @since S1
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $config = $this->resolveConfig($appConfig);

        $enabled             = $this->cfgBool($config, 'enabled', true);
        $bucketSeconds       = $this->cfgInt($config, 'bucket_seconds', 10);
        $routeCardinalityCap = $this->cfgInt($config, 'route_cardinality_cap', 200);

        $builder->addDefinitions([
            // One in-RAM accumulator per worker. SHARED so the hooks and the
            // flush timer share the same counters (PHP-DI treats factory
            // definitions as singletons by default).
            //
            // The latency histogram bounds are NOT read from config: they are
            // locked to MetricsRegistry::DEFAULT_LATENCY_BUCKETS_MS, which maps 1:1
            // onto the fixed h_le_*/h_gt_5000 columns the flush service writes and
            // the repository reads. A config override would silently zero every
            // histogram column (the flush service references $h[10], $h[50], …),
            // so bucket_seconds / route_cardinality_cap stay tunable but the
            // histogram is schema-locked.
            MetricsRegistry::class => factory(
                static fn (): MetricsRegistry => new MetricsRegistry(
                    $bucketSeconds,
                    MetricsRegistry::DEFAULT_LATENCY_BUCKETS_MS,
                    $routeCardinalityCap
                )
            ),

            // Thin façade over the shared registry, injected into the request /
            // connection hooks (S2). The `enabled` flag makes every record call a
            // no-op when metrics are disabled; the clock defaults to time().
            MetricsCollector::class => factory(
                static function (ContainerInterface $c) use ($enabled): MetricsCollector {
                    /** @var MetricsRegistry $registry */
                    $registry = $c->get(MetricsRegistry::class);
                    return new MetricsCollector($registry, $enabled);
                }
            ),

            // Drains the registry to MySQL on the per-worker flush timer (S2).
            // Shares the same collector (hence the same registry) and reads its
            // own tuning knobs from the raw config array.
            MetricsFlushService::class => factory(
                static function (ContainerInterface $c) use ($config): MetricsFlushService {
                    /** @var Connection $db */
                    $db = $c->get(Connection::class);
                    /** @var MetricsCollector $collector */
                    $collector = $c->get(MetricsCollector::class);
                    return new MetricsFlushService($db, $collector, $config);
                }
            ),

            // Read side consumed by the admin controller (S2). Aggregates the
            // per-worker rows written by the flush service.
            MetricsRepository::class => factory(
                static function (ContainerInterface $c) use ($config): MetricsRepository {
                    /** @var Connection $db */
                    $db = $c->get(Connection::class);
                    return new MetricsRepository($db, $config);
                }
            ),

            // Bind the read-side interface to the concrete repository. The admin
            // MetricsController type-hints MetricsRepositoryInterface and
            // AdminRoutes resolves get(MetricsController::class) at route
            // registration; without this alias PHP-DI tries to instantiate the
            // interface directly and throws "MetricsRepositoryInterface cannot be
            // resolved: the class is not instantiable" (surfacing on the server
            // as a mangled "Couldn't execute method Error::__toString" fatal from
            // the Workerman error handler). The alias reuses the shared concrete
            // singleton.
            MetricsRepositoryInterface::class => get(MetricsRepository::class),
        ]);
    }

    /**
     * Resolve the effective metrics config array.
     *
     * Prefers `$appConfig['metrics']` (threaded in by config/server.php); falls
     * back to a direct include of config/metrics.php so an entry point that does
     * not compose the full server.php still gets the real defaults; finally
     * yields an empty array (the classes supply their own built-in defaults).
     *
     * @param array<string, mixed> $appConfig
     *
     * @return array<string, mixed>
     */
    private function resolveConfig(array $appConfig): array
    {
        $raw = $appConfig['metrics'] ?? null;
        if (!is_array($raw)) {
            /** @var mixed $included */
            $included = @include __DIR__ . '/../../../../config/metrics.php';
            $raw = is_array($included) ? $included : [];
        }

        /** @var array<string, mixed> $out */
        $out = [];
        /** @var mixed $value */
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Read a boolean config value with a default.
     *
     * @param array<string, mixed> $config
     * @param string               $key
     * @param bool                 $default
     *
     * @return bool
     */
    private function cfgBool(array $config, string $key, bool $default): bool
    {
        $v = $config[$key] ?? null;
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
        }
        return $default;
    }

    /**
     * Read an int config value with a default.
     *
     * @param array<string, mixed> $config
     * @param string               $key
     * @param int                  $default
     *
     * @return int
     */
    private function cfgInt(array $config, string $key, int $default): int
    {
        $v = $config[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return $default;
    }
}
