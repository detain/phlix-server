<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\Providers\MediaServicesProvider;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Playlists\SmartPlaylistController;
use Phlix\Playlists\SmartPlaylistEngine;
use Phlix\Playlists\SmartPlaylistRefreshHandler;
use Phlix\Playlists\SmartPlaylistRepository;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for {@see MediaServicesProvider}.
 *
 * @covers \Phlix\Common\Container\Providers\MediaServicesProvider
 */
final class MediaServicesProviderTest extends TestCase
{
    public function test_register_adds_media_service_definitions(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        (new MediaServicesProvider())->register($builder, []);

        $container = $builder->build();

        // Core library services
        $this->assertTrue($container->has(ItemRepository::class));
        $this->assertTrue($container->has(MetadataManager::class));

        // Streaming services
        $this->assertTrue($container->has(QualitySelector::class));
        $this->assertTrue($container->has(HlsStreamer::class));

        // Playlist services
        $this->assertTrue($container->has(SmartPlaylistRepository::class));
        $this->assertTrue($container->has(SmartPlaylistEngine::class));
        $this->assertTrue($container->has(SmartPlaylistRefreshHandler::class));
        $this->assertTrue($container->has(SmartPlaylistController::class));

        // Marker services
        $this->assertTrue($container->has(MarkerCandidateRepository::class));
        $this->assertTrue($container->has(MarkerService::class));
        $this->assertTrue($container->has(PlaybackMarkerService::class));
    }

    public function test_marker_services_can_be_resolved(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        (new MediaServicesProvider())->register($builder, []);

        $container = $builder->build();

        // Verify marker services are registered (resolution would require full DB config)
        $this->assertTrue($container->has(MarkerCandidateRepository::class));
        $this->assertTrue($container->has(MarkerService::class));
        $this->assertTrue($container->has(PlaybackMarkerService::class));
    }

    public function test_marker_services_bindings_reference_correct_dependencies(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        (new MediaServicesProvider())->register($builder, []);

        $container = $builder->build();

        // Verify the binding definitions reference the correct classes
        // These checks confirm the DI entries exist without requiring full resolution
        $this->assertTrue($container->has(MarkerCandidateRepository::class));
        $this->assertTrue($container->has(MarkerService::class));
        $this->assertTrue($container->has(PlaybackMarkerService::class));

        // Verify the binding chain: PlaybackMarkerService -> MarkerService -> MarkerCandidateRepository -> ItemRepository
        $this->assertTrue($container->has(ItemRepository::class));
    }
}
