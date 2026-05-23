<?php

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Auth\AuthManager;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Server\WebPortal\PageRenderer;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Psr\Container\ContainerInterface;

use function DI\factory;

/**
 * Registers web-portal-tier services ({@see PageRenderer} and {@see WebPortalRouter}).
 *
 * {@see PageRenderer} needs an absolute path to the Smarty template
 * directory plus three already-container-managed collaborators
 * ({@see LibraryManager}, {@see ItemRepository}, {@see PlaybackController}).
 * The template path is sourced from `$appConfig['web_portal']['template_dir']`
 * with a default that points at `public/templates/` relative to the
 * project root so the stock bootstrap keeps working without extra config.
 *
 * {@see WebPortalRouter} handles API routing for the web portal,
 * including library browsing, playback info, and user activity endpoints.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
 * @since 0.10.1
 */
final class WebPortalServicesProvider implements ServiceProviderInterface
{
    /**
     * Default subdirectory (relative to the project root) that hosts the
     * Smarty templates served by the web portal. Override by setting
     * `web_portal.template_dir` in the application config.
     */
    public const DEFAULT_TEMPLATE_DIR = 'public/templates';

    /**
     * Register the web-portal bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     *
     * @since 0.10.1
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $webConfig = is_array($appConfig['web_portal'] ?? null) ? $appConfig['web_portal'] : [];
        $templateDir = is_string($webConfig['template_dir'] ?? null) && $webConfig['template_dir'] !== ''
            ? (string) $webConfig['template_dir']
            : self::resolveDefaultTemplateDir();

        $builder->addDefinitions([
            'web_portal.template_dir' => $templateDir,

            PageRenderer::class => factory(
                static function (ContainerInterface $c) use ($templateDir): PageRenderer {
                    /** @var LibraryManager $libraryManager */
                    $libraryManager = $c->get(LibraryManager::class);
                    /** @var ItemRepository $itemRepository */
                    $itemRepository = $c->get(ItemRepository::class);
                    /** @var PlaybackController $playbackController */
                    $playbackController = $c->get(PlaybackController::class);

                    return new PageRenderer(
                        $templateDir,
                        $libraryManager,
                        $itemRepository,
                        $playbackController,
                    );
                }
            ),

            WebPortalRouter::class => factory(
                static function (ContainerInterface $c): WebPortalRouter {
                    /** @var LibraryManager $libraryManager */
                    $libraryManager = $c->get(LibraryManager::class);
                    /** @var ItemRepository $itemRepository */
                    $itemRepository = $c->get(ItemRepository::class);
                    /** @var SessionManager $sessionManager */
                    $sessionManager = $c->get(SessionManager::class);
                    /** @var PlaybackController $playbackController */
                    $playbackController = $c->get(PlaybackController::class);
                    /** @var AuthManager $authManager */
                    $authManager = $c->get(AuthManager::class);
                    /** @var PlaybackMarkerService $playbackMarkerService */
                    $playbackMarkerService = $c->get(PlaybackMarkerService::class);

                    return new WebPortalRouter(
                        $libraryManager,
                        $itemRepository,
                        $sessionManager,
                        $playbackController,
                        $authManager,
                        $playbackMarkerService,
                    );
                }
            ),
        ]);
    }

    /**
     * Resolve the default `public/templates/` directory relative to the
     * project root (`src/Common/Container/Providers/WebPortalServicesProvider.php`
     * -> up four levels).
     */
    private static function resolveDefaultTemplateDir(): string
    {
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . self::DEFAULT_TEMPLATE_DIR;
    }
}
