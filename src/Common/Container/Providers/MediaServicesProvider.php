<?php

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Admin\SettingsRepository;
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
use Phlix\Media\Metadata\Imdb\ImdbLookup;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Playlists\SmartPlaylistController;
use Phlix\Playlists\SmartPlaylistEngine;
use Phlix\Playlists\SmartPlaylistRefreshHandler;
use Phlix\Playlists\SmartPlaylistRepository;
use Phlix\Stats\StatsCollector;
use Psr\Container\ContainerInterface;
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
            // `statsCollector` is named explicitly because PHP-DI skips optional
            // ctor params with defaults during autowiring; without it item
            // add/remove changes never reach stats_library_changes (the admin
            // dashboard activity feed).
            ItemRepository::class => autowire()
                ->constructorParameter('statsCollector', get(StatsCollector::class)),

            TmdbProvider::class => factory(static function (ContainerInterface $c) use ($tmdbApiKey): TmdbProvider {
                // Prefer the admin-managed server setting (set via the admin
                // UI's Settings → Metadata page, persisted in server_settings)
                // and fall back to config/tmdb.php / the TMDB_API_KEY env var.
                // getEffective() already returns the override when present,
                // else the config/env default, so an admin-saved key wins.
                // Resolved when the provider is first built, so a saved key
                // applies on the next worker cycle without a redeploy.
                $key = $tmdbApiKey;
                try {
                    $settings = $c->get(SettingsRepository::class);
                    if ($settings instanceof SettingsRepository) {
                        $stored = $settings->getEffective('tmdb.api_key');
                        if (is_string($stored) && $stored !== '') {
                            $key = $stored;
                        }
                    }
                } catch (\Throwable) {
                    // Settings store not available — keep the config/env key.
                }
                return new TmdbProvider($key);
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

            // Offline IMDb dataset lookup — ctor dep is the Workerman MySQL
            // Connection (already resolvable here); the optional logger defaults
            // to the MEDIA channel.
            ImdbLookup::class => autowire(),

            // Cross-source movie metadata resolver (TMDB + IMDb). TmdbProvider is
            // built by the factory above (admin-managed key, env/config
            // fallback); ImdbLookup is autowired; the optional logger defaults to
            // the MEDIA channel.
            MovieMetadataResolver::class => autowire()
                ->constructorParameter('tmdb', get(TmdbProvider::class))
                ->constructorParameter('imdb', get(ImdbLookup::class)),

            // Background per-library metadata matcher run for `metadata`-type
            // scan jobs. Its ItemRepository + MovieMetadataResolver deps are
            // resolvable above; the optional logger defaults to the MEDIA channel.
            LibraryMetadataMatcher::class => autowire()
                ->constructorParameter('items', get(ItemRepository::class))
                ->constructorParameter('resolver', get(MovieMetadataResolver::class)),

            // Async scan worker (Step 1.1b). Its ctor deps — ScanJobRepository,
            // LibraryManager and the LibraryMetadataMatcher (for `metadata`
            // jobs) — are all autowired above; the optional StructuredLogger
            // defaults to the MEDIA channel.
            LibraryScanWorker::class => autowire()
                ->constructorParameter('metadataMatcher', get(LibraryMetadataMatcher::class)),

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
