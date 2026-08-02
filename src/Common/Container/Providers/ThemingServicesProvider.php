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
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Theming\ThemeRegistry;
use Phlix\Theming\ThemeSourceRegistry;
use Workerman\MySQL\Connection;

use function DI\autowire;
use function DI\factory;

/**
 * Registers the theming subsystem: ThemeRegistry and ThemeSourceRegistry.
 *
 * Built-in CSS themes are registered from config/themes.php during
 * {@see ThemeRegistry} construction. Plugin token-map themes land in
 * {@see ThemeSourceRegistry}, which {@see \Phlix\Plugins\PluginLoader}
 * (de)registers on plugin enable/disable via the
 * {@see \Phlix\Theming\ThemeSourceInterface} capability arm.
 *
 * `ThemeMiddleware` used to be registered here too. It was retired in S84:
 * it string-replaced two Smarty placeholders (`{$theme_css|raw}` /
 * `{$theme_js|raw}`) into rendered HTML, and no template has emitted either
 * since the Smarty page renderer was deleted — the `/app` SPA themes itself.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
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

            // S84 capability registry. Plain autowire is safe here precisely
            // because the class declares NO constructor — there is no
            // optional dependency for PHP-DI's autowire() to silently skip.
            // It holds only its own two maps, so one container-scoped
            // instance per worker is exactly right.
            ThemeSourceRegistry::class => autowire(),
        ];

        $builder->addDefinitions($definitions);
    }
}
