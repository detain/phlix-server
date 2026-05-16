<?php

declare(strict_types=1);

namespace Phlex\Common\Container;

use DI\ContainerBuilder;

/**
 * Contract for service providers that register bindings on the PHP-DI
 * ContainerBuilder.
 *
 * Service providers group related bindings (auth, media, sessions, etc.)
 * so that ContainerFactory can compose them without knowing the details
 * of each subsystem. Implementations should be stateless: a single
 * register() call receives the builder, and the provider mutates it by
 * adding definitions.
 *
 * @internal Phlex-internal API. Third-party plugins should depend on
 *           {@see \Psr\Container\ContainerInterface} instead. The list
 *           of providers wired by {@see ContainerFactory} may change
 *           without notice.
 *
 * @package Phlex\Common\Container
 * @since 0.10.0
 */
interface ServiceProviderInterface
{
    /**
     * Register service definitions on the given container builder.
     *
     * @param ContainerBuilder<\DI\Container> $builder The builder being assembled by ContainerFactory.
     * @param array<string, mixed> $appConfig  Application configuration as returned by
     *                                         config/server.php (with db_config_path /
     *                                         logger_config_path injected by the factory).
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void;
}
