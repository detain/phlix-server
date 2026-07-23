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
use Phlix\Auth\JwtHandler;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Ed25519KeyManager;
use Phlix\Hub\HubApplication;
use Phlix\Hub\HubClient;
use Phlix\Hub\HubJwtValidator;
use Phlix\Hub\HubJwtValidatorInterface;
use Phlix\Hub\HttpClient;
use Phlix\Hub\HttpClientFactory;
use Phlix\Hub\HttpClientFactoryInterface;
use Phlix\Hub\HttpClientInterface;
use Phlix\Hub\JwksCache;
use Phlix\Hub\RelayApplication;
use Phlix\Hub\RelayConfig;
use Phlix\Hub\RelayConsumer;
use Phlix\Hub\RelayMessageFramer;
use Phlix\Hub\RelayStateStore;
use Phlix\Server\Http\Controllers\HubJwksController;
use Phlix\Server\Http\Controllers\HubTokenController;
use Phlix\Server\Http\Middleware\HubJwtMiddleware;
use Psr\Container\ContainerInterface;
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
        // Resolve relative configDir to absolute path for HubClient
        if ($configDir !== '' && $configDir[0] !== '/') {
            $configDir = dirname(__DIR__, 3) . '/' . $configDir;
        }
        $defaultKeyPath = $configDir . '/hub-server-key.pem';
        $keyPath = is_string($hubConfig['key_path'] ?? null) ? $hubConfig['key_path'] : $defaultKeyPath;
        $heartbeatInterval = is_int($hubConfig['heartbeat_interval'] ?? null) ? $hubConfig['heartbeat_interval'] : 60;
        $cacheTtl = is_int($hubConfig['jwks_cache_ttl'] ?? null) ? $hubConfig['jwks_cache_ttl'] : 900;
        $publicUrl = is_string($hubConfig['public_url'] ?? null) ? $hubConfig['public_url'] : '';
        // R5: renewal threshold extracted from config (was hardcoded constant in HubClient)
        $renewalThreshold = is_int($hubConfig['renewal_threshold'] ?? null) ? $hubConfig['renewal_threshold'] : 518400;

        $builder->addDefinitions([
            Ed25519KeyManager::class => autowire()
                ->constructorParameter('keyPath', $keyPath),

            HttpClient::class => autowire(),

            // HubClient requires an HttpClientInterface but creates its own
            // enrollment-scoped client lazily (startHeartbeatLoop / pairing),
            // so this injected instance is just a placeholder that lets the
            // container resolve HubClient (and HubApplication) at all. Without
            // a binding the interface is "not instantiable" and the hub
            // heartbeat worker can't boot.
            HttpClientInterface::class => factory(
                static fn (): HttpClientInterface => new HttpClient('')
            ),

            HttpClientFactory::class => autowire(),

            HttpClientFactoryInterface::class => get(HttpClientFactory::class),

            JwksCache::class => autowire()
                ->constructorParameter('ttl', $cacheTtl),

            HubClient::class => autowire()
                ->constructorParameter('logger', get('logger.hub'))
                ->constructorParameter('configDir', $configDir)
                ->constructorParameter('httpClient', get(HttpClientInterface::class))
                ->constructorParameter('publicUrl', $publicUrl)
                ->constructorParameter('renewalThreshold', $renewalThreshold)
                // S40: inject the cross-process state store so the heartbeat loop
                // (in the phlix-hub-heartbeat fork) persists its live state to
                // hub-heartbeat.state.json for the HTTP-worker health endpoints
                // to read. Optional ctor param → PHP-DI would skip it during
                // autowiring, so it MUST be bound explicitly.
                ->constructorParameter('stateStore', get(RelayStateStore::class))
                // Advertise this server's libraries in each heartbeat so the hub
                // caches them (server_libraries) and the owner's dashboard can list
                // them. Resolved lazily per heartbeat; failures degrade to empty.
                ->constructorParameter('librariesProvider', factory(
                    static function (ContainerInterface $c): \Closure {
                        return static function () use ($c): array {
                            $manager = $c->get(\Phlix\Media\Library\LibraryManager::class);
                            if (!$manager instanceof \Phlix\Media\Library\LibraryManager) {
                                return [];
                            }
                            $out = [];
                            foreach ($manager->getAllLibraries() as $lib) {
                                if (!is_array($lib)) {
                                    continue;
                                }
                                $id = is_string($lib['id'] ?? null) ? $lib['id'] : '';
                                $name = is_string($lib['name'] ?? null) ? $lib['name'] : '';
                                if ($id !== '' && $name !== '') {
                                    $out[] = ['library_id' => $id, 'library_name' => $name];
                                }
                            }
                            return $out;
                        };
                    }
                )),

            // SV-4.15(g): the public JWKS endpoint gets its OWN worker-local
            // in-memory limiter (RateLimitProfiles::JWKS). The `limiter` ctor
            // param is optional (PHP-DI skips optional params during autowiring),
            // so it must be bound EXPLICITLY to its RateLimitProfiles container id
            // — an unbound limiter would silently stay null and leave the surface
            // unlimited. The profile is registered in AuthServicesProvider (all
            // providers merge into one container).
            HubJwksController::class => autowire()
                ->constructorParameter('hubClient', get(HubClient::class))
                ->constructorParameter('limiter', get(RateLimitProfiles::JWKS)),

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

            // Cross-process state store (S38): the relay + heartbeat forks write
            // their live state to single-writer JSON files under $configDir; the
            // HTTP worker's health/admin surfaces read them (S39/S40). Same dir
            // as hub-enrollment.json (already writable).
            RelayStateStore::class => factory(
                static fn (): RelayStateStore => new RelayStateStore($configDir)
            ),

            RelayConsumer::class => factory(
                static function (ContainerInterface $c): RelayConsumer {
                    $config = $c->get(RelayConfig::class);
                    $hubClient = $c->get(HubClient::class);
                    $logger = $c->get('logger.hub');
                    $stateStore = $c->get(RelayStateStore::class);

                    if (
                        !$config instanceof RelayConfig
                        || !$hubClient instanceof HubClient
                        || !$logger instanceof StructuredLogger
                        || !$stateStore instanceof RelayStateStore
                    ) {
                        throw new \RuntimeException('RelayConsumer dependencies misconfigured');
                    }

                    $enrollment = $hubClient->loadEnrollment();
                    $serverId = $enrollment !== null ? $enrollment->serverId : '';

                    if ($enrollment !== null && $enrollment->hubBaseUrl !== '') {
                        $config = $config->withAutoEnable($enrollment->hubBaseUrl);
                    }

                    return new RelayConsumer(
                        $config,
                        $hubClient,
                        $logger,
                        $serverId,
                        null,
                        null,
                        null,
                        $stateStore,
                    );
                }
            ),

            RelayApplication::class => autowire()
                ->constructorParameter('logger', get('logger.hub'))
                ->constructorParameter('consumer', get(RelayConsumer::class)),
        ]);
    }
}
