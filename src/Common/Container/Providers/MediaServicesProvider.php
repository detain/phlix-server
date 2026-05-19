<?php

declare(strict_types=1);

namespace Phlex\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlex\Common\Container\ServiceProviderInterface;
use Phlex\Media\Library\FolderWatcher;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Library\LibraryManager;
use Phlex\Media\Library\MediaScanner;
use Phlex\Media\Metadata\MetadataManager;
use Phlex\Media\Streaming\HlsStreamer;
use Phlex\Media\Streaming\QualitySelector;
use Phlex\Playlists\SmartPlaylistController;
use Phlex\Playlists\SmartPlaylistEngine;
use Phlex\Playlists\SmartPlaylistRefreshHandler;
use Phlex\Playlists\SmartPlaylistRepository;
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
 * @internal Phlex-internal service provider.
 *
 * @package Phlex\Common\Container\Providers
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
        $segmentDir = is_string($segmentDirRaw) ? $segmentDirRaw : sys_get_temp_dir() . '/phlex_hls';
        $baseUrlRaw = $hlsConfig['base_url'] ?? null;
        $baseUrl = is_string($baseUrlRaw) ? $baseUrlRaw : 'http://localhost:8096';

        $builder->addDefinitions([
            ItemRepository::class => autowire(),

            FolderWatcher::class => autowire()
                ->constructorParameter('logger', get('logger.media'))
                ->constructorParameter('eventDispatcher', get(EventDispatcherInterface::class)),

            MediaScanner::class => autowire()
                ->constructorParameter('logger', get('logger.media'))
                ->constructorParameter('eventDispatcher', get(EventDispatcherInterface::class)),

            LibraryManager::class => autowire()
                ->constructorParameter('logger', get('logger.media')),

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
        ]);
    }
}
