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
use Phlix\Common\Container\DegradedBuild;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LogChannels;
use Phlix\Auth\AuthManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WatchHistory;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\ChapterSearchService;
use Phlix\Media\Library\BookProgressStore;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\PhotoLibraryManager;
use Phlix\Media\Library\PhotoScanner;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\RecommendationService;
use Phlix\Media\SimilarityService;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Media\UserItemDataRepository;
use Phlix\Media\Metadata\ExifProvider;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\Metadata\OpdsFeedBuilder;
use Phlix\Server\Http\Controllers\BookController;
use Phlix\Server\Http\Controllers\MediaUserDataController;
use Phlix\Server\Http\Controllers\TranscodeController;
use Phlix\Server\Http\Controllers\UserAvatarController;
use Phlix\Server\Http\Controllers\PhotoController;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Registers the web-portal-tier {@see WebPortalRouter} service.
 *
 * The template path is sourced from `$appConfig['web_portal']['template_dir']`
 * with a default that points at `public/templates/` relative to the
 * project root; it is exposed as `web_portal.template_dir` for the remaining
 * consumers (e.g. the newsletter generator) after the Smarty page UI retired.
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
                    /** @var MarkerService $markerService */
                    $markerService = $c->get(MarkerService::class);
                    /** @var ChapterSearchService|null $chapterSearchService */
                    $chapterSearchService = $c->get(ChapterSearchService::class);
                    /** @var UserRepository $userRepository */
                    $userRepository = $c->get(UserRepository::class);
                    /** @var WatchHistory $watchHistory */
                    $watchHistory = $c->get(WatchHistory::class);
                    /** @var UserProfileManager $profileManager */
                    $profileManager = $c->get(UserProfileManager::class);
                    /** @var UserItemDataRepository $userItemData */
                    $userItemData = $c->get(UserItemDataRepository::class);
                    /** @var MediaUserDataController $mediaUserDataController */
                    $mediaUserDataController = $c->get(MediaUserDataController::class);
                    /** @var AuditLogger $auditLogger */
                    $auditLogger = $c->get(AuditLogger::class);
                    /** @var AvatarStorage $avatarStorage */
                    $avatarStorage = $c->get(AvatarStorage::class);
                    $userAvatarController = new UserAvatarController($avatarStorage, $userRepository);
                    /** @var SimilarityService|null $similarityService */
                    $similarityService = $c->get(SimilarityService::class);
                    /** @var RecommendationService|null $recommendationService */
                    $recommendationService = $c->get(RecommendationService::class);
                    /** @var Connection $db */
                    $db = $c->get(Connection::class);
                    /** @var MetadataManager $metadataManager */
                    $metadataManager = $c->get(MetadataManager::class);

                    // Create MusicLibraryService for the music hierarchy. Resolve
                    // the scanner from the container (not `new`) so it carries the
                    // wired EventDispatcher (musicbrainz enrichment) + the live
                    // ScanIgnorePatterns setting — see MediaServicesProvider.
                    /** @var MusicLibraryScanner $musicLibraryScanner */
                    $musicLibraryScanner = $c->get(MusicLibraryScanner::class);
                    $musicLibraryService = new MusicLibraryService($db, $musicLibraryScanner);

                    /** @var TranscodeManager|null $transcodeManager */
                    $transcodeManager = $c->get(TranscodeManager::class);
                    // Parental ACCESS gate for the transcode start/status endpoints
                    // (Finding 1a) — optional; a null gate is a strict no-op.
                    $transcodeRatingGate = null;
                    try {
                        /** @var \Phlix\Media\Library\RatingGate $transcodeRatingGate */
                        $transcodeRatingGate = $c->get(\Phlix\Media\Library\RatingGate::class);
                    } catch (\Throwable $e) {
                        // A null gate is a strict no-op, i.e. parental ACCESS
                        // control is OFF for the transcode endpoints — and this
                        // decision is frozen for the worker's lifetime. That must
                        // never be silent, even though it should be unreachable:
                        // RatingGate's three dependencies (ItemRepository,
                        // UserRepository, UserProfileManager) are all resolved
                        // UNGUARDED earlier in this same factory, so a dependency
                        // failure would already have thrown above.
                        $transcodeRatingGate = null;
                        DegradedBuild::warnUnlessAbsent(
                            $c,
                            LogChannels::MEDIA,
                            'RatingGate could not be built: parental access control is NOT enforced '
                            . 'on the transcode start/status endpoints for this worker.',
                            $e
                        );
                    }
                    $transcodeController = $transcodeManager !== null
                        ? new TranscodeController($transcodeManager, $transcodeRatingGate)
                        : null;

                    $settingsRepo = null;
                    if ($c->has(\Phlix\Admin\SettingsRepository::class)) {
                        $resolved = $c->get(\Phlix\Admin\SettingsRepository::class);
                        $settingsRepo = $resolved instanceof \Phlix\Admin\SettingsRepository
                            ? $resolved
                            : null;
                    }

                    return new WebPortalRouter(
                        $libraryManager,
                        $itemRepository,
                        $sessionManager,
                        $playbackController,
                        $authManager,
                        $playbackMarkerService,
                        $markerService,
                        $chapterSearchService,
                        $userRepository,
                        $watchHistory,
                        $profileManager,
                        $userItemData,
                        $mediaUserDataController,
                        $auditLogger,
                        $avatarStorage,
                        $userAvatarController,
                        null, // MediaRatingsController (not wired in this context)
                        $transcodeController,
                        $similarityService,
                        $recommendationService,
                        null, // CollectionService (not wired in this context)
                        $musicLibraryService,
                        null, // StreamProbeBackfill (not wired in this context)
                        // Backs the `subtitles.default_language` server-wide default.
                        $settingsRepo,
                    );
                }
            ),

            BookController::class => factory(
                static function (ContainerInterface $c): BookController {
                    /** @var ItemRepository $itemRepository */
                    $itemRepository = $c->get(ItemRepository::class);
                    // BookController needs the generic LibraryManager (getAllLibraries()/
                    // getLibrary()); BookLibraryManager does not expose those, so resolving
                    // it here was both a type error and a runtime fatal on those calls.
                    /** @var LibraryManager $libraryManager */
                    $libraryManager = $c->get(LibraryManager::class);
                    /** @var BookProgressStore|null $progressStore */
                    $progressStore = $c->get(BookProgressStore::class);

                    // Parental ACCESS gate for book read/download (serve-time
                    // backstop on the plain /books/{id}/download route this
                    // container controller serves) — optional; null = no-op.
                    $bookRatingGate = null;
                    try {
                        /** @var \Phlix\Media\Library\RatingGate $bookRatingGate */
                        $bookRatingGate = $c->get(\Phlix\Media\Library\RatingGate::class);
                    } catch (\Throwable $e) {
                        // Unlike the WebPortalRouter factory above, this factory
                        // resolves only ItemRepository beforehand — RatingGate's
                        // other two dependencies (UserProfileManager, UserRepository)
                        // are NOT resolved here, so this catch is genuinely
                        // reachable when either fails to build. A null gate makes
                        // BookController::bookOverCap() return false, i.e. every
                        // book is served regardless of its rating, for the worker's
                        // whole lifetime. Whether it fires at all depends on PHP-DI
                        // resolution order, which is exactly why it would never
                        // reproduce reliably — so it must be logged.
                        $bookRatingGate = null;
                        DegradedBuild::warnUnlessAbsent(
                            $c,
                            LogChannels::MEDIA,
                            'RatingGate could not be built: parental access control is NOT enforced '
                            . 'on book read/download for this worker — every book is served '
                            . 'regardless of its content rating.',
                            $e
                        );
                    }
                    $controller = new BookController(
                        $itemRepository,
                        $libraryManager,
                        new OpdsFeedBuilder($itemRepository, 'http://localhost:8080'),
                        $bookRatingGate,
                    );
                    if ($progressStore !== null) {
                        $controller->setProgressStore($progressStore);
                    }

                    return $controller;
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
