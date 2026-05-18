<?php

declare(strict_types=1);

namespace Phlex\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlex\Auth\UserProfileManager;
use Phlex\Common\Container\ServiceProviderInterface;
use Phlex\Theming\Theme;
use Phlex\Theming\ThemeMiddleware;
use Phlex\Theming\ThemeRegistry;
use Workerman\MySQL\Connection;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * Registers the theming subsystem: ThemeRegistry and ThemeMiddleware.
 *
 * Built-in themes are registered from config/themes.php during registry
 * construction. Plugin themes are registered via registerFromPlugin()
 * during the plugin bootstrap phase.
 *
 * @internal Phlex-internal service provider.
 *
 * @package Phlex\Common\Container\Providers
 * @since 0.14.0
 */
final class ThemingServicesProvider implements ServiceProviderInterface
{
    /**
     * Default path to runtime themes directory.
     */
    public const DEFAULT_THEMES_DIR = 'var/themes';

    /**
     * Register theming bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig Application config
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        /** @var string $themesDir */
        $themesDir = $appConfig['themes_dir'] ?? self::DEFAULT_THEMES_DIR;

        $definitions = [
            ThemeRegistry::class => factory(
                static function (Connection $db) use ($themesDir): ThemeRegistry {
                    $registry = new ThemeRegistry($db, $themesDir);
                    $registry->registerBuiltInThemes();
                    return $registry;
                }
            ),

            ThemeMiddleware::class => autowire()
                ->constructorParameter('registry', get(ThemeRegistry::class))
                ->constructorParameter('profiles', get(UserProfileManager::class)),
        ];

        $builder->addDefinitions($definitions);
    }
}
