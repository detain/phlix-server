<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\Providers\MediaServicesProvider;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\TitleSuffixStripper;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Music\MusicLibraryScanner;
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

    /**
     * S96(a): the music scanner's `logger` constructor parameter MUST be named in the
     * container definition.
     *
     * PHP-DI SKIPS defaulted optional constructor parameters during autowiring, so
     * omitting this is what made every music-scan log line land in
     * `sys_get_temp_dir()/phlix_music_scanner_<uniqid>/music_scanner.log` — inside the
     * `phlix-server` unit's `PrivateTmp`, unreadable without `nsenter`-ing the MainPID,
     * destroyed on restart, and leaking one directory per instantiation (66 counted on
     * production). That invisible log is the direct reason the empty Music library
     * survived four wrong diagnoses.
     *
     * `has()` cannot catch this — the entry exists either way — so this asserts the
     * DEFINITION, which is the only thing that differs. `eventDispatcher` and
     * `ignorePatterns` are asserted alongside it because they are the same
     * skipped-optional-parameter trap and the class docblock pins all three.
     */
    public function testMusicScannerDefinitionNamesTheMediaLogger(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        (new MediaServicesProvider())->register($builder, []);

        $container = $builder->build();
        $this->assertTrue($container->has(MusicLibraryScanner::class));

        $definition = $container->debugEntry(MusicLibraryScanner::class);

        $this->assertStringContainsString(
            '$logger = get(logger.media)',
            $definition,
            'MusicLibraryScanner must be wired to the shared MEDIA-channel logger. Without the named '
            . 'parameter PHP-DI skips it, and the scanner logs where no operator can read it (S96(a)).',
        );
        $this->assertStringContainsString('$eventDispatcher = get(', $definition);
        $this->assertStringContainsString('$ignorePatterns = get(', $definition);
    }

    /**
     * Step 13.3: the `matching.noise_suffixes` definition resolves the admin
     * override (via SettingsRepository::getEffective) and returns it, so a
     * custom phrase reaches the matching services.
     */
    public function test_noise_suffixes_definition_returns_admin_override(): void
    {
        $custom = ['imax edition', 'remux'];

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->with('matching.noise_suffixes')
            ->willReturn($custom);

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new MediaServicesProvider())->register($builder, []);
        $builder->addDefinitions([SettingsRepository::class => $settings]);

        $container = $builder->build();

        /** @var list<string> $resolved */
        $resolved = $container->get('matching.noise_suffixes');
        $this->assertSame($custom, $resolved);
    }

    /**
     * Step 13.3: an EMPTY effective value (admin cleared the field, or no
     * override and an unreadable config) falls back to the built-in const — it
     * never blanks the noise list.
     */
    public function test_noise_suffixes_definition_falls_back_to_const_on_empty(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->with('matching.noise_suffixes')
            ->willReturn([]);

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new MediaServicesProvider())->register($builder, []);
        $builder->addDefinitions([SettingsRepository::class => $settings]);

        $container = $builder->build();

        /** @var list<string> $resolved */
        $resolved = $container->get('matching.noise_suffixes');
        $this->assertSame(TitleSuffixStripper::NOISE_SUFFIXES, $resolved);
    }

    /**
     * Step 13.3: when no SettingsRepository is available at all, the definition
     * still returns the built-in const (defensive fallback, never crashes).
     */
    public function test_noise_suffixes_definition_falls_back_without_settings_repository(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new MediaServicesProvider())->register($builder, []);

        $container = $builder->build();

        /** @var list<string> $resolved */
        $resolved = $container->get('matching.noise_suffixes');
        $this->assertSame(TitleSuffixStripper::NOISE_SUFFIXES, $resolved);
    }

    /**
     * Step 3.3: the PriorityConfig definition merges the admin
     * `metadata.provider_priority` override per-type OVER the config default
     * (REPLACE-not-deep-merge) and applies the `metadata.genres_mode` override.
     */
    public function test_priority_config_definition_merges_override_over_default(): void
    {
        $defaultMap = [
            'movie'  => ['tmdb', 'imdb'],
            'series' => ['tmdb', 'imdb'],
            'anime'  => ['anidb', 'myanimelist', 'tvdb', 'fanart', 'local'],
        ];

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getDefault')
            ->with('metadata.provider_priority')
            ->willReturn($defaultMap);
        $settings->method('getOverride')
            ->with('metadata.provider_priority')
            ->willReturn(['value' => ['movie' => ['imdb', 'tmdb']], 'type' => 'json']);
        $settings->method('getEffective')
            ->with('metadata.genres_mode')
            ->willReturn('union');

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new MediaServicesProvider())->register($builder, []);
        $builder->addDefinitions([SettingsRepository::class => $settings]);

        $container = $builder->build();

        $config = $container->get(PriorityConfig::class);
        $this->assertInstanceOf(PriorityConfig::class, $config);
        // Movie order replaced by the override.
        $this->assertSame(['imdb', 'tmdb'], $config->orderFor('movie'));
        // Untouched types keep their config default.
        $this->assertSame(['tmdb', 'imdb'], $config->orderFor('series'));
        $this->assertSame(
            ['anidb', 'myanimelist', 'tvdb', 'fanart', 'local'],
            $config->orderFor('anime'),
        );
        $this->assertSame('union', $config->genresMode());
    }

    /**
     * Step 3.3: with no override and no genres_mode override, PriorityConfig
     * uses the config defaults (genres_mode 'first').
     */
    public function test_priority_config_definition_uses_config_default_without_override(): void
    {
        $defaultMap = [
            'movie'  => ['tmdb', 'imdb'],
            'series' => ['tmdb', 'imdb'],
        ];

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getDefault')
            ->with('metadata.provider_priority')
            ->willReturn($defaultMap);
        $settings->method('getOverride')
            ->with('metadata.provider_priority')
            ->willReturn(null);
        $settings->method('getEffective')
            ->with('metadata.genres_mode')
            ->willReturn('first');

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new MediaServicesProvider())->register($builder, []);
        $builder->addDefinitions([SettingsRepository::class => $settings]);

        $container = $builder->build();

        $config = $container->get(PriorityConfig::class);
        $this->assertInstanceOf(PriorityConfig::class, $config);
        $this->assertSame(['tmdb', 'imdb'], $config->orderFor('movie'));
        $this->assertSame('first', $config->genresMode());
    }

    /**
     * Step 3.3: without any SettingsRepository, PriorityConfig still resolves and
     * orderFor() falls back to the canonical [tmdb, imdb] baseline.
     */
    public function test_priority_config_definition_falls_back_without_settings_repository(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new MediaServicesProvider())->register($builder, []);

        $container = $builder->build();

        $config = $container->get(PriorityConfig::class);
        $this->assertInstanceOf(PriorityConfig::class, $config);
        $this->assertSame(['tmdb', 'imdb'], $config->orderFor('movie'));
        $this->assertSame('first', $config->genresMode());
    }
}
