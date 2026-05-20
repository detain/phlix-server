<?php

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Metadata\MetadataManager;
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
