<?php

declare(strict_types=1);

namespace Phlex\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlex\Auth\AuthManager;
use Phlex\Auth\JwtHandler;
use Phlex\Auth\UserProfileManager;
use Phlex\Auth\UserRepository;
use Phlex\Auth\WatchHistory;
use Phlex\Common\Container\ServiceProviderInterface;

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
 * @internal Phlex-internal service provider.
 *
 * @package Phlex\Common\Container\Providers
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
        $jwtTtl = isset($jwtConfig['ttl']) ? (int)$jwtConfig['ttl'] : 3600;
        $jwtRefreshTtl = isset($jwtConfig['refresh_ttl']) ? (int)$jwtConfig['refresh_ttl'] : 604800;
        $jwtAlgorithm = isset($jwtConfig['algorithm']) ? (string)$jwtConfig['algorithm'] : 'HS256';

        $builder->addDefinitions([
            JwtHandler::class => factory(
                static function () use ($jwtSecret, $jwtAlgorithm, $jwtTtl, $jwtRefreshTtl): JwtHandler {
                    return new JwtHandler($jwtSecret, $jwtAlgorithm, $jwtTtl, $jwtRefreshTtl);
                }
            ),

            UserRepository::class => autowire(),
            UserProfileManager::class => autowire(),
            WatchHistory::class => autowire(),

            AuthManager::class => autowire()
                ->constructorParameter('logger', get('logger.auth')),
        ]);
    }
}
