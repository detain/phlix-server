<?php

declare(strict_types=1);

namespace Phlex\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlex\Auth\JwtHandler;
use Phlex\Common\Container\ServiceProviderInterface;
use Phlex\Hub\Ed25519KeyManager;
use Phlex\Hub\HubApplication;
use Phlex\Hub\HubClient;
use Phlex\Hub\HubJwtValidator;
use Phlex\Hub\HubJwtValidatorInterface;
use Phlex\Hub\HttpClient;
use Phlex\Hub\HttpClientFactory;
use Phlex\Hub\HttpClientFactoryInterface;
use Phlex\Hub\JwksCache;
use Phlex\Server\Http\Controllers\HubJwksController;
use Phlex\Server\Http\Controllers\HubTokenController;
use Phlex\Server\Http\Middleware\HubJwtMiddleware;
use Psr\Log\LoggerInterface;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * Registers the hub subsystem: key manager, HTTP client, hub client,
 * hub application worker, JWKS controller, JWT validator, and token exchange.
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
                static function (HubClient $hubClient, HttpClientFactory $factory, LoggerInterface $logger) use ($cacheTtl): ?HubJwtValidator {
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
        ]);
    }
}
