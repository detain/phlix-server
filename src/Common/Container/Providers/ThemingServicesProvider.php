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
use Phlix\Theming\ThemeSourceRegistry;

use function DI\autowire;

/**
 * Registers the theming subsystem: ThemeSourceRegistry.
 *
 * Plugin token-map themes land in {@see ThemeSourceRegistry}, which
 * {@see \Phlix\Plugins\PluginLoader} (de)registers on plugin
 * enable/disable via the {@see \Phlix\Theming\ThemeSourceInterface}
 * capability arm; the SPA's built-in token-map themes are host-owned
 * data ({@see \Phlix\Theming\BuiltInThemes}). The pre-S84 stylesheet-URL
 * theme registry and its config file were dead code and were deleted in S227.
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
        $definitions = [
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
