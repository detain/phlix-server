<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use DI\ContainerBuilder;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Media\Metadata\MatchConfidencePolicy;
use Phlix\Media\Metadata\MetadataCachePolicy;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\Metadata\MovieMetadataResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * W3 rework (P2) — the PRODUCTION DI wiring of the two metadata settings
 * policies (match-confidence gate + cache-TTL gate).
 *
 * Both consumers fall back to a STORE-LESS policy when the container does not
 * name the optional parameter — `MovieMetadataResolver::__construct` does
 * `$confidencePolicy ?? new MatchConfidencePolicy()` and
 * `MetadataManager::__construct` does `$cachePolicy ?? new MetadataCachePolicy()`.
 * PHP-DI's autowire SKIPS defaulted optional ctor params, so dropping either
 * `constructorParameter` line keeps every hand-built unit test green while the
 * setting silently rots at its shipped default (blind first result forever /
 * 24 h window forever) — exactly the inert-settings defect the adversarial
 * review reproduced by dropping BOTH lines. Same law as
 * DiscoveryPolicyWiringGuardTest (W2) and SecurityHeadersPolicyWiringGuardTest
 * (W4); this file therefore never calls `new` on any subject below — every
 * object comes out of the production container.
 *
 * The assertions reach for the POLICY'S `$settings` (not just the consumer's
 * policy property) because the fallback and the injection are both non-null
 * MatchConfidencePolicy/MetadataCachePolicy instances — only the store thread
 * distinguishes "guarding live" from "guarding inert".
 */
final class MetadataPoliciesWiringGuardTest extends TestCase
{
    private ?ContainerInterface $sharedContainer = null;

    public function testTheContainerComposesTheMatchConfidencePolicyWithItsStore(): void
    {
        $policy = $this->container()->get(MatchConfidencePolicy::class);

        self::assertInstanceOf(MatchConfidencePolicy::class, $policy);

        self::assertInstanceOf(
            SettingsRepository::class,
            self::property($policy, 'settings'),
            'MatchConfidencePolicy::$settings is not a store in the production container — '
            . 'MediaServicesProvider lost the optionalSettings() thread in the policy factory, '
            . 'so metadata.min_match_confidence can never rise above the 0.0 gate-off default.',
        );
    }

    public function testTheContainerComposesTheMetadataCachePolicyWithItsStore(): void
    {
        $policy = $this->container()->get(MetadataCachePolicy::class);

        self::assertInstanceOf(MetadataCachePolicy::class, $policy);

        self::assertInstanceOf(
            SettingsRepository::class,
            self::property($policy, 'settings'),
            'MetadataCachePolicy::$settings is not a store in the production container — '
            . 'MediaServicesProvider lost the optionalSettings() thread in the policy factory, '
            . 'so metadata.cache_ttl_hours is frozen at the shipped 24 h window.',
        );
    }

    public function testTheContainerThreadsTheConfidencePolicyIntoTheResolver(): void
    {
        $resolver = $this->container()->get(MovieMetadataResolver::class);
        self::assertInstanceOf(MovieMetadataResolver::class, $resolver);

        $policy = self::property($resolver, 'confidencePolicy');
        self::assertInstanceOf(
            MatchConfidencePolicy::class,
            $policy,
            'MovieMetadataResolver::$confidencePolicy missing after container resolution — '
            . 'PHP-DI skipped the optional parameter because the binding stopped naming it.',
        );

        self::assertInstanceOf(
            SettingsRepository::class,
            self::property($policy, 'settings'),
            'MovieMetadataResolver resolved by the container carries a store-less policy: the '
            . 'binding fell back to `new MatchConfidencePolicy()` (threshold permanently 0.0) — '
            . 'a server_settings row for metadata.min_match_confidence would silently do nothing.',
        );
    }

    public function testTheContainerThreadsTheCachePolicyIntoTheManager(): void
    {
        $manager = $this->container()->get(MetadataManager::class);
        self::assertInstanceOf(MetadataManager::class, $manager);

        $policy = self::property($manager, 'cachePolicy');
        self::assertInstanceOf(
            MetadataCachePolicy::class,
            $policy,
            'MetadataManager::$cachePolicy missing after container resolution — PHP-DI skipped '
            . 'the optional parameter because the binding stopped naming it.',
        );

        self::assertInstanceOf(
            SettingsRepository::class,
            self::property($policy, 'settings'),
            'MetadataManager resolved by the container carries a store-less policy: the binding '
            . 'fell back to `new MetadataCachePolicy()` (freshness window permanently 24 h) — a '
            . 'server_settings row for metadata.cache_ttl_hours would silently do nothing.',
        );
    }

    // ---- helpers -------------------------------------------------------------

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
            'logger_config_path' => dirname(__DIR__, 4) . '/config/logger.php',
            'db_config_path' => null,
        ], $providers);
    }
}
