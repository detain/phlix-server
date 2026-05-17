<?php

declare(strict_types=1);

namespace Phlex\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlex\Common\Container\ServiceProviderInterface;
use Phlex\Hub\Ed25519KeyManager;
use Phlex\Hub\HubApplication;
use Phlex\Hub\HubClient;
use Phlex\Hub\HttpClient;
use Phlex\Server\Http\Controllers\HubJwksController;
use Psr\Log\LoggerInterface;

use function DI\autowire;
use function DI\get;

/**
 * Registers the hub subsystem: key manager, HTTP client, hub client,
 * hub application worker, and JWKS controller.
 *
 * @internal Phlex-internal service provider.
 *
 * @package Phlex\Common\Container\Providers
 * @since 0.11.0
 */
final class HubServicesProvider implements ServiceProviderInterface
{
    /**
     * Register the hub bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     *
     * @since 0.11.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $hubConfig = is_array($appConfig['hub'] ?? null) ? $appConfig['hub'] : [];
        $configDir = is_string($hubConfig['config_dir'] ?? null) ? $hubConfig['config_dir'] : 'config';
        $defaultKeyPath = $configDir . '/hub-server-key.pem';
        $keyPath = is_string($hubConfig['key_path'] ?? null) ? $hubConfig['key_path'] : $defaultKeyPath;
        $heartbeatInterval = is_int($hubConfig['heartbeat_interval'] ?? null) ? $hubConfig['heartbeat_interval'] : 60;

        $builder->addDefinitions([
            Ed25519KeyManager::class => autowire()
                ->constructorParameter('keyPath', $keyPath),

            HttpClient::class => autowire(),

            HubClient::class => autowire()
                ->constructorParameter('logger', get('logger.hub'))
                ->constructorParameter('configDir', $configDir),

            HubJwksController::class => autowire()
                ->constructorParameter('hubClient', get(HubClient::class)),

            HubApplication::class => autowire()
                ->constructorParameter('logger', get('logger.hub'))
                ->constructorParameter('hubClient', get(HubClient::class)),
        ]);
    }
}
