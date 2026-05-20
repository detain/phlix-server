<?php

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Auth\JwtHandler;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Hub\Ed25519KeyManager;
use Phlix\Hub\HubApplication;
use Phlix\Hub\HubClient;
use Phlix\Hub\HubJwtValidator;
use Phlix\Hub\HubJwtValidatorInterface;
use Phlix\Hub\HttpClient;
use Phlix\Hub\HttpClientFactory;
use Phlix\Hub\HttpClientFactoryInterface;
use Phlix\Hub\JwksCache;
use Phlix\Hub\RelayApplication;
use Phlix\Hub\RelayConfig;
use Phlix\Hub\RelayConsumer;
use Phlix\Hub\RelayMessageFramer;
use Phlix\Server\Http\Controllers\HubJwksController;
use Phlix\Server\Http\Controllers\HubTokenController;
use Phlix\Server\Http\Middleware\HubJwtMiddleware;
use Psr\Log\LoggerInterface;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * Registers the hub subsystem: key manager, HTTP client, hub client,
 * hub application worker, JWKS controller, JWT validator, and token exchange.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
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
        $cacheTtl = is_int($hubConfig['jwks_cache_ttl'] ?? null) ? $hubConfig['jwks_cache_ttl'] : 900;

        $builder->addDefinitions([
            Ed25519KeyManager::class => autowire()
                ->constructorParameter('keyPath', $keyPath),

            HttpClient::class => autowire(),

            HttpClientFactory::class => autowire(),

            HttpClientFactoryInterface::class => get(HttpClientFactory::class),

            JwksCache::class => autowire()
                ->constructorParameter('ttl', $cacheTtl),

            HubClient::class => autowire()
                ->constructorParameter('logger', get('logger.hub'))
                ->constructorParameter('configDir', $configDir),

            HubJwksController::class => autowire()
                ->constructorParameter('hubClient', get(HubClient::class)),

            HubApplication::class => autowire()
                ->constructorParameter('logger', get('logger.hub'))
                ->constructorParameter('hubClient', get(HubClient::class)),

            HubJwtValidator::class => factory(
                static function (
                    HubClient $hubClient,
                    HttpClientFactory $factory,
                    LoggerInterface $logger,
                ) use ($cacheTtl): ?HubJwtValidator {
                    $enrollment = $hubClient->loadEnrollment();
                    if ($enrollment === null || $enrollment->hubJwksUrl === '') {
                        return null;
                    }
                    return new HubJwtValidator(
                        $enrollment->hubJwksUrl,
                        $factory,
                        $logger,
                        $enrollment->serverId,
                        null,
                        $cacheTtl,
                    );
                }
            ),

            HubTokenController::class => autowire()
                ->constructorParameter('validator', get(HubJwtValidator::class))
                ->constructorParameter('jwtHandler', get(JwtHandler::class)),

            HubJwtMiddleware::class => autowire()
                ->constructorParameter('validator', get(HubJwtValidatorInterface::class)),

            HubJwtValidatorInterface::class => get(HubJwtValidator::class),

            RelayConfig::class => factory(
                static function () use ($appConfig): RelayConfig {
                    $relayConfig = is_array($appConfig['relay'] ?? null) ? $appConfig['relay'] : [];
                    return RelayConfig::fromEnv($relayConfig);
                }
            ),

            RelayMessageFramer::class => autowire(),

            RelayConsumer::class => autowire()
                ->constructorParameter('logger', get('logger.hub'))
                ->constructorParameter('config', get(RelayConfig::class)),

            RelayApplication::class => autowire()
                ->constructorParameter('logger', get('logger.hub'))
                ->constructorParameter('consumer', get(RelayConsumer::class)),
        ]);
    }
}
