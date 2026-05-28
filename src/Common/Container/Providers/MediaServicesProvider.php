<?php

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\LibraryScanWorker;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Playlists\SmartPlaylistController;
use Phlix\Playlists\SmartPlaylistEngine;
use Phlix\Playlists\SmartPlaylistRefreshHandler;
use Phlix\Playlists\SmartPlaylistRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Workerman\MySQL\Connection;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * Registers the media subsystem: library scanning, repositories, the
 * metadata manager and the HLS streamer.
 *
 * The HlsStreamer needs a segments directory and a base URL that are
 * not class-level concerns, so they come from $appConfig['hls'] with
 * sensible defaults aligned to public/index.php's current behaviour.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
 * @since 0.10.0
 */
final class MediaServicesProvider implements ServiceProviderInterface
{
    /**
     * Register the media bindings.
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
        $hlsConfig = is_array($appConfig['hls'] ?? null) ? $appConfig['hls'] : [];
        $segmentDirRaw = $hlsConfig['segment_dir'] ?? null;
        $segmentDir = is_string($segmentDirRaw) ? $segmentDirRaw : sys_get_temp_dir() . '/phlix_hls';
        $baseUrlRaw = $hlsConfig['base_url'] ?? null;
        $baseUrl = is_string($baseUrlRaw) ? $baseUrlRaw : 'http://localhost:8096';

        // TMDB API key — prefer $appConfig['tmdb']['api_key'] (loaded by the
        // bootstrap from config/tmdb.php when available), otherwise fall back
        // to the TMDB_API_KEY environment variable. An empty key is harmless:
        // TrailerResolver consults the local extras cache before any HTTP
        // call, so the trailers endpoints stay live even without a key.
        $tmdbConfig = is_array($appConfig['tmdb'] ?? null) ? $appConfig['tmdb'] : [];
        $tmdbApiKeyRaw = $tmdbConfig['api_key'] ?? null;
        $tmdbApiKey = is_string($tmdbApiKeyRaw) && $tmdbApiKeyRaw !== ''
            ? $tmdbApiKeyRaw
            : ((string)(getenv('TMDB_API_KEY') ?: ''));

        $builder->addDefinitions([
            ItemRepository::class => autowire(),

            TmdbProvider::class => factory(static function () use ($tmdbApiKey): TmdbProvider {
                return new TmdbProvider($tmdbApiKey);
            }),

            FolderWatcher::class => autowire()
                ->constructorParameter('logger', get('logger.media'))
                ->constructorParameter('eventDispatcher', get(EventDispatcherInterface::class)),

            MediaScanner::class => autowire()
                ->constructorParameter('logger', get('logger.media'))
                ->constructorParameter('eventDispatcher', get(EventDispatcherInterface::class)),

            LibraryManager::class => autowire()
                ->constructorParameter('logger', get('logger.media')),

            // Scan-job data layer (Step 1.1a). Its only ctor dependency is the
            // Workerman MySQL Connection, already resolvable in this provider.
            ScanJobRepository::class => autowire(),

            // Async scan worker (Step 1.1b). Its ctor deps — ScanJobRepository
            // + LibraryManager — are both autowired above; the optional
            // StructuredLogger defaults to the MEDIA channel.
            LibraryScanWorker::class => autowire(),

            MetadataManager::class => autowire(),

            QualitySelector::class => factory(static function (): QualitySelector {
                return new QualitySelector();
            }),

            HlsStreamer::class => factory(static function ($container) use ($segmentDir, $baseUrl): HlsStreamer {
                return new HlsStreamer(
                    $segmentDir,
                    $baseUrl,
                    $container->get(QualitySelector::class)
                );
            }),

            // Smart playlist services
            SmartPlaylistRepository::class => autowire()
                ->constructorParameter('db', get(Connection::class)),

            SmartPlaylistEngine::class => autowire()
                ->constructorParameter('itemRepository', get(ItemRepository::class)),

            SmartPlaylistRefreshHandler::class => autowire(),

            SmartPlaylistController::class => factory(static function ($container): SmartPlaylistController {
                return new SmartPlaylistController(
                    $container->get(Connection::class),
                    $container->get(ItemRepository::class)
                );
            }),

            // Marker services
            MarkerCandidateRepository::class => autowire()
                ->constructorParameter('itemRepo', get(ItemRepository::class)),

            MarkerService::class => autowire()
                ->constructorParameter('item_repo', get(ItemRepository::class))
                ->constructorParameter('candidate_repo', get(MarkerCandidateRepository::class)),

            PlaybackMarkerService::class => autowire()
                ->constructorParameter('marker_service', get(MarkerService::class)),
        ]);
    }
}
