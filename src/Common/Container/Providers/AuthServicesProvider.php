<?php

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Auth\AuthManager;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\ProviderManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WatchHistory;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Auth\WebAuthn\WebAuthnSettings;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Server\Http\Controllers\AuthProviderController;
use Phlix\Server\Http\Controllers\WebAuthnController;
use Psr\EventDispatcher\EventDispatcherInterface;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * Registers the auth subsystem: JWT handler, user repository, auth
 * manager, profiles, watch history.
 *
 * The JWT handler is configured with a factory that reads JWT_SECRET
 * from the environment, defaulting to the existing
 * "default-secret-change-me" sentinel from public/index.php to preserve
 * parity for local installs that never set the env var.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
 * @since 0.10.0
 */
final class AuthServicesProvider implements ServiceProviderInterface
{
    /**
     * Default JWT secret used when JWT_SECRET is not present in the
     * environment. Matches the historical literal from public/index.php
     * so existing deployments continue to authenticate while we migrate
     * to the container.
     */
    public const DEFAULT_JWT_SECRET = 'default-secret-change-me';

    /**
     * Register the auth bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $jwtSecret = (string)(getenv('JWT_SECRET') ?: self::DEFAULT_JWT_SECRET);
        $jwtConfig = is_array($appConfig['jwt'] ?? null) ? $appConfig['jwt'] : [];
        $jwtTtl = is_numeric($jwtConfig['ttl'] ?? null) ? (int)$jwtConfig['ttl'] : 3600;
        $jwtRefreshTtl = is_numeric($jwtConfig['refresh_ttl'] ?? null)
            ? (int)$jwtConfig['refresh_ttl']
            : 604800;
        $jwtAlgorithm = is_string($jwtConfig['algorithm'] ?? null) ? $jwtConfig['algorithm'] : 'HS256';

        $builder->addDefinitions([
            JwtHandler::class => factory(
                static function () use ($jwtSecret, $jwtAlgorithm, $jwtTtl, $jwtRefreshTtl): JwtHandler {
                    return new JwtHandler($jwtSecret, $jwtAlgorithm, $jwtTtl, $jwtRefreshTtl);
                }
            ),

            UserRepository::class => autowire(),
            UserProfileManager::class => autowire(),
            WatchHistory::class => autowire(),

            AuthProviderRegistry::class => autowire(),
            ProviderManager::class => autowire(),

            AuthProviderController::class => autowire(),

            AuthManager::class => autowire()
                ->constructorParameter('logger', get('logger.auth'))
                ->constructorParameter('eventDispatcher', get(EventDispatcherInterface::class))
                ->constructorParameter('db', get(\Workerman\MySQL\Connection::class)),

            // WebAuthn — rpId/rpName/rpOrigin come from $appConfig['webauthn'].
            // Without this factory, php-di would try to autowire string scalars
            // and fail with "Parameter $rpId of __construct() has no value defined".
            WebAuthnSettings::class => factory(
                static function () use ($appConfig): WebAuthnSettings {
                    $cfg = is_array($appConfig['webauthn'] ?? null) ? $appConfig['webauthn'] : [];
                    return WebAuthnSettings::fromConfig($cfg);
                }
            ),
            WebAuthnCredentialRepository::class => autowire(),
            WebAuthnManager::class => autowire(),
            WebAuthnController::class => autowire(),
        ]);
    }
}
