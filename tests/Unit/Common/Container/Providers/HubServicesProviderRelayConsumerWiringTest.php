<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\Container;
use DI\ContainerBuilder;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Common\Container\Providers\HubServicesProvider;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Hub\HubClient;
use Phlix\Hub\RelayConsumer;
use Phlix\Hub\RelayIdentityResolver;
use PHPUnit\Framework\TestCase;

/**
 * The container's {@see RelayConsumer} must be built with the S301 {@see RelayIdentityResolver}.
 *
 * The ninth constructor argument is optional, so an omitted binding leaves it null without an
 * error, and every relayed principal is then unmapped. This resolves the consumer through the
 * real {@see HubServicesProvider} factory, with only the external edges mocked: the hub client,
 * the logger and the `user_identities` repository (the resolver's sole dependency).
 */
final class HubServicesProviderRelayConsumerWiringTest extends TestCase
{
    public function test_the_container_relay_consumer_receives_a_non_null_identity_resolver(): void
    {
        $container = $this->buildContainer($this->identitiesMapping('hub-user-42', 'server-user-9'));

        $consumer = $container->get(RelayConsumer::class);
        self::assertInstanceOf(RelayConsumer::class, $consumer);

        $resolver = (new \ReflectionProperty(RelayConsumer::class, 'identityResolver'))->getValue($consumer);
        self::assertInstanceOf(
            RelayIdentityResolver::class,
            $resolver,
            'the container-built consumer must receive the resolver, not null',
        );
    }

    public function test_the_injected_resolver_maps_a_linked_hub_principal_to_its_server_user(): void
    {
        // Non-null is not enough on its own: the injected resolver must be the live linkage reader.
        $container = $this->buildContainer($this->identitiesMapping('hub-user-42', 'server-user-9'));

        $consumer = $container->get(RelayConsumer::class);
        $resolver = (new \ReflectionProperty(RelayConsumer::class, 'identityResolver'))->getValue($consumer);
        self::assertInstanceOf(RelayIdentityResolver::class, $resolver);

        self::assertSame('server-user-9', $resolver->resolve('hub-user-42'));
        self::assertNull($resolver->resolve('hub-user-unlinked'));
    }

    /**
     * A repository stub that links one hub principal to one server user and nothing else.
     */
    private function identitiesMapping(string $hubUser, string $serverUser): UserIdentityRepository
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')
            ->willReturnCallback(static fn (string $provider, string $instance, string $externalId): ?array
                => $externalId === $hubUser ? ['user_id' => $serverUser] : null);

        return $identities;
    }

    private function buildContainer(UserIdentityRepository $identities): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        // Only the HubServicesProvider is registered: the relay wiring needs nothing from the rest of
        // the container except the external edges overridden below.
        (new HubServicesProvider())->register($builder, []);

        // No enrollment on disk, so the factory builds the consumer with an empty server id.
        $hubClient = $this->createMock(HubClient::class);
        $hubClient->method('loadEnrollment')->willReturn(null);

        $builder->addDefinitions([
            HubClient::class => $hubClient,
            UserIdentityRepository::class => $identities,
            'logger.hub' => $this->createMock(StructuredLogger::class),
        ]);

        return $builder->build();
    }
}
