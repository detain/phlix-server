<?php

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Auth\AuthManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\AudioScanner;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MusicLibraryManager;
use Phlix\Media\Library\PhotoLibraryManager;
use Phlix\Media\Library\PhotoScanner;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\Metadata\ExifProvider;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\Metadata\OpdsFeedBuilder;
use Phlix\Server\Http\Controllers\BookController;
use Phlix\Server\Http\Controllers\PhotoController;
use Phlix\Server\WebPortal\Controllers\AudiobookPageController;
use Phlix\Server\WebPortal\Controllers\BookPageController;
use Phlix\Server\WebPortal\Controllers\MusicPageController;
use Phlix\Server\WebPortal\Controllers\PhotoPageController;
use Phlix\Server\WebPortal\PageRenderer;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

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

                    $renderer = new PageRenderer(
                        $templateDir,
                        $libraryManager,
                        $itemRepository,
                        $playbackController,
                    );

                    // Wire AuthManager + UserRepository so renderHome()
                    // can gate on auth (redirect to /login when no
                    // session) and trigger the first-run wizard
                    // (redirect to /auth/register when no users
                    // exist), and so the greeting can show the real
                    // display name instead of hard-coded "User".
                    /** @var AuthManager $authManager */
                    $authManager = $c->get(AuthManager::class);
                    /** @var UserRepository $userRepository */
                    $userRepository = $c->get(UserRepository::class);
                    $renderer->setAuthServices($authManager, $userRepository);

                    return $renderer;
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
                    /** @var UserRepository $userRepository */
                    $userRepository = $c->get(UserRepository::class);

                    return new WebPortalRouter(
                        $libraryManager,
                        $itemRepository,
                        $sessionManager,
                        $playbackController,
                        $authManager,
                        $playbackMarkerService,
                        $userRepository,
                    );
                }
            ),

            MusicPageController::class => factory(
                static function (ContainerInterface $c) use ($templateDir): MusicPageController {
                    /** @var Connection $db */
                    $db = $c->get(Connection::class);
                    /** @var ItemRepository $itemRepository */
                    $itemRepository = $c->get(ItemRepository::class);
                    /** @var MetadataManager $metadataManager */
                    $metadataManager = $c->get(MetadataManager::class);
                    /** @var LibraryManager $libraryManager */
                    $libraryManager = $c->get(LibraryManager::class);

                    $musicManager = new MusicLibraryManager(
                        new AudioScanner($db, $itemRepository),
                        $metadataManager,
                        $itemRepository,
                        $db,
                    );

                    return new MusicPageController($musicManager, $libraryManager, $templateDir);
                }
            ),

            BookPageController::class => factory(
                static function (ContainerInterface $c) use ($templateDir): BookPageController {
                    /** @var ItemRepository $itemRepository */
                    $itemRepository = $c->get(ItemRepository::class);
                    /** @var LibraryManager $libraryManager */
                    $libraryManager = $c->get(LibraryManager::class);

                    return new BookPageController($itemRepository, $libraryManager, $templateDir);
                }
            ),

            AudiobookPageController::class => factory(
                static function (ContainerInterface $c) use ($templateDir): AudiobookPageController {
                    /** @var ItemRepository $itemRepository */
                    $itemRepository = $c->get(ItemRepository::class);
                    /** @var LibraryManager $libraryManager */
                    $libraryManager = $c->get(LibraryManager::class);

                    return new AudiobookPageController($itemRepository, $libraryManager, $templateDir);
                }
            ),

            PhotoPageController::class => factory(
                static function (ContainerInterface $c) use ($templateDir): PhotoPageController {
                    /** @var Connection $db */
                    $db = $c->get(Connection::class);
                    /** @var ItemRepository $itemRepository */
                    $itemRepository = $c->get(ItemRepository::class);
                    /** @var LibraryManager $libraryManager */
                    $libraryManager = $c->get(LibraryManager::class);

                    return new PhotoPageController(
                        $itemRepository,
                        new PhotoLibraryManager(new PhotoScanner($db, $itemRepository), $itemRepository),
                        new ExifProvider($itemRepository),
                        $libraryManager,
                        $templateDir,
                    );
                }
            ),

            BookController::class => factory(
                static function (ContainerInterface $c): BookController {
                    /** @var ItemRepository $itemRepository */
                    $itemRepository = $c->get(ItemRepository::class);
                    /** @var LibraryManager $libraryManager */
                    $libraryManager = $c->get(LibraryManager::class);

                    return new BookController(
                        $itemRepository,
                        $libraryManager,
                        new OpdsFeedBuilder($itemRepository, 'http://localhost:8080'),
                    );
                }
            ),

            PhotoController::class => factory(
                static function (ContainerInterface $c): PhotoController {
                    /** @var Connection $db */
                    $db = $c->get(Connection::class);
                    /** @var ItemRepository $itemRepository */
                    $itemRepository = $c->get(ItemRepository::class);

                    return new PhotoController(
                        $itemRepository,
                        new PhotoLibraryManager(new PhotoScanner($db, $itemRepository), $itemRepository),
                        new ExifProvider($itemRepository),
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
