<?php

declare(strict_types=1);

namespace Phlex\Common\Container;

use DI\ContainerBuilder;
use Phlex\Common\Container\Providers\AuthServicesProvider;
use Phlex\Common\Container\Providers\CoreServicesProvider;
use Phlex\Common\Container\Providers\MediaServicesProvider;
use Phlex\Common\Container\Providers\SessionServicesProvider;
use Psr\Container\ContainerInterface;

/**
 * Builds the application's PSR-11 container.
 *
 * `ContainerFactory::create($config)` composes the canonical service
 * providers ({@see CoreServicesProvider}, {@see AuthServicesProvider},
 * {@see MediaServicesProvider}, {@see SessionServicesProvider}) against
 * a fresh PHP-DI {@see ContainerBuilder} with autowiring and PHP 8
 * attribute parsing enabled, then returns the compiled container.
 *
 * Caching: when the `PHLEX_CONTAINER_COMPILE` env var is truthy
 * ("1", "true", "yes"), the factory writes compiled definitions to
 * `var/cache/container/` for production deployments. Compilation is
 * disabled by default so that local development picks up new
 * definitions without manual cache clears.
 *
 * @package Phlex\Common\Container
 * @since 0.10.0
 */
final class ContainerFactory
{
    /**
     * Default location of the compiled-container cache, relative to the
     * project root. Override by passing `compile_dir` in $appConfig.
     */
    public const DEFAULT_COMPILE_DIR = 'var/cache/container';

    /**
     * Private constructor — the factory is purely static.
     */
    private function __construct()
    {
    }

    /**
     * Build and return a PSR-11 container for the running application.
     *
     * @param array<string, mixed> $appConfig Application configuration
     *        (typically the array returned by config/server.php with
     *        `db_config_path` and `logger_config_path` added by the
     *        bootstrap script). Pass an empty array to build a bare
     *        container suitable for unit tests.
     * @param array<int, ServiceProviderInterface>|null $providers
     *        Override the default provider stack. Mostly useful for
     *        tests that want to register additional fakes.
     *
     * @return ContainerInterface Fully built, PSR-11 compliant container.
     *
     * @throws \Exception When PHP-DI fails to compile the container
     *                   (e.g. circular definitions, unreadable
     *                   compile directory).
     *
     * @since 0.10.0
     */
    public static function create(array $appConfig = [], ?array $providers = null): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->useAttributes(true);

        if (self::shouldCompile()) {
            /** @var mixed $compileDirRaw */
            $compileDirRaw = $appConfig['compile_dir'] ?? self::DEFAULT_COMPILE_DIR;
            $compileDir = is_string($compileDirRaw) ? $compileDirRaw : self::DEFAULT_COMPILE_DIR;
            if ($compileDir !== '' && !is_dir($compileDir)) {
                @mkdir($compileDir, 0775, true);
            }
            if ($compileDir !== '' && is_dir($compileDir) && is_writable($compileDir)) {
                $builder->enableCompilation($compileDir);
            }
        }

        foreach ($providers ?? self::defaultProviders() as $provider) {
            $provider->register($builder, $appConfig);
        }

        return $builder->build();
    }

    /**
     * Canonical list of providers wired into a stock Phlex container.
     *
     * Exposed so plugins / tests can prepend or append their own
     * providers without re-implementing the defaults.
     *
     * @return array<int, ServiceProviderInterface>
     *
     * @since 0.10.0
     */
    public static function defaultProviders(): array
    {
        return [
            new CoreServicesProvider(),
            new AuthServicesProvider(),
            new MediaServicesProvider(),
            new SessionServicesProvider(),
        ];
    }

    /**
     * Whether to enable PHP-DI's compiled-container cache.
     *
     * @return bool True when PHLEX_CONTAINER_COMPILE is set to a truthy value.
     *
     * @since 0.10.0
     */
    private static function shouldCompile(): bool
    {
        $value = getenv('PHLEX_CONTAINER_COMPILE');
        if ($value === false) {
            return false;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }
}
