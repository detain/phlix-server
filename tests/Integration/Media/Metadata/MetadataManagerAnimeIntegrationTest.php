<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\Metadata\MetadataProviderInterface;
use Phlix\Media\Library\ItemRepository;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;

/**
 * Integration tests for MetadataManager with anime-type providers.
 *
 * Verifies that anidb/myanimelist plugins can register and be returned
 * when calling getProvidersForType('anime').
 *
 * @covers \Phlix\Media\Metadata\MetadataManager
 */
final class MetadataManagerAnimeIntegrationTest extends TestCase
{
    private MetadataManager $manager;

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');

        $mockDb = $this->createMock(\Workerman\MySQL\Connection::class);
        $mockItemRepo = $this->createMock(ItemRepository::class);

        $this->manager = new MetadataManager($mockItemRepo);
    }

    /**
     * Test that anidb provider can be registered for 'anime' type.
     */
    public function testAnidbProviderRegisteredForAnimeType(): void
    {
        $anidbProvider = $this->createMock(MetadataProviderInterface::class);
        $anidbProvider->method('getProviders')->willReturn(['anidb']);
        $anidbProvider->method('getSourceName')->willReturn('anidb');

        $this->manager->registerProvider('anidb', $anidbProvider, ['anime']);

        $this->assertTrue($this->manager->hasProvider('anidb'));
        $this->assertSame($anidbProvider, $this->manager->getProvider('anidb'));
    }

    /**
     * Test that myanimelist provider can be registered for 'anime' type.
     */
    public function testMyAnimeListProviderRegisteredForAnimeType(): void
    {
        $malProvider = $this->createMock(MetadataProviderInterface::class);
        $malProvider->method('getProviders')->willReturn(['myanimelist']);
        $malProvider->method('getSourceName')->willReturn('myanimelist');

        $this->manager->registerProvider('myanimelist', $malProvider, ['anime']);

        $this->assertTrue($this->manager->hasProvider('myanimelist'));
        $this->assertSame($malProvider, $this->manager->getProvider('myanimelist'));
    }

    /**
     * Test that getProvidersForType('anime') returns registered anime providers in priority order.
     */
    public function testGetProvidersForTypeAnimeReturnsProvidersInPriorityOrder(): void
    {
        $anidbProvider = $this->createMock(MetadataProviderInterface::class);
        $anidbProvider->method('getProviders')->willReturn(['anidb']);
        $anidbProvider->method('getSourceName')->willReturn('anidb');

        $malProvider = $this->createMock(MetadataProviderInterface::class);
        $malProvider->method('getProviders')->willReturn(['myanimelist']);
        $malProvider->method('getSourceName')->willReturn('myanimelist');

        // Register both providers for 'anime' type
        $this->manager->registerProvider('anidb', $anidbProvider, ['anime']);
        $this->manager->registerProvider('myanimelist', $malProvider, ['anime']);

        $providers = $this->manager->getProvidersForType('anime');

        $this->assertCount(2, $providers);
        // Verify the first provider returned is 'anidb' (highest priority)
        $this->assertSame($anidbProvider, $providers[0]);
        $this->assertSame($malProvider, $providers[1]);
    }

    /**
     * Test that anime providers are returned when mixed with series providers.
     */
    public function testAnimeProvidersCoexistWithSeriesProviders(): void
    {
        $anidbProvider = $this->createMock(MetadataProviderInterface::class);
        $anidbProvider->method('getProviders')->willReturn(['anidb']);
        $anidbProvider->method('getSourceName')->willReturn('anidb');

        $tvdbProvider = $this->createMock(MetadataProviderInterface::class);
        $tvdbProvider->method('getProviders')->willReturn(['tvdb']);
        $tvdbProvider->method('getSourceName')->willReturn('tvdb');

        // Register anidb for anime and tvdb for series
        $this->manager->registerProvider('anidb', $anidbProvider, ['anime']);
        $this->manager->registerProvider('tvdb', $tvdbProvider, ['series', 'anime']);

        $animeProviders = $this->manager->getProvidersForType('anime');
        $seriesProviders = $this->manager->getProvidersForType('series');

        // Anime should return anidb (highest priority), tvdb (fallback), local
        $this->assertGreaterThanOrEqual(1, count($animeProviders));
        // First provider should be anidb (highest priority for anime)
        $this->assertSame($anidbProvider, $animeProviders[0]);

        // Series should return tvdb (anidb not in series priority list)
        $this->assertGreaterThanOrEqual(1, count($seriesProviders));
        $this->assertSame($tvdbProvider, $seriesProviders[0]);
    }

    /**
     * Test that missing anime providers fall back to tvdb and local.
     */
    public function testAnimeFallsBackToTvdbAndLocalWhenAnidbMalNotRegistered(): void
    {
        $tvdbProvider = $this->createMock(MetadataProviderInterface::class);
        $tvdbProvider->method('getProviders')->willReturn(['tvdb']);
        $tvdbProvider->method('getSourceName')->willReturn('tvdb');

        $localProvider = $this->createMock(MetadataProviderInterface::class);
        $localProvider->method('getProviders')->willReturn(['local']);
        $localProvider->method('getSourceName')->willReturn('local');

        // Register only tvdb and local (no anidb/myanimelist)
        $this->manager->registerProvider('tvdb', $tvdbProvider, ['series', 'anime']);
        $this->manager->registerProvider('local', $localProvider, ['movie', 'series', 'anime']);

        $providers = $this->manager->getProvidersForType('anime');

        // Should return tvdb (highest priority that exists) and local
        $this->assertGreaterThanOrEqual(1, count($providers));
        // First provider should be tvdb since anidb/myanimelist not registered
        $this->assertSame($tvdbProvider, $providers[0]);
        // Verify the registered providers exist
        $this->assertTrue($this->manager->hasProvider('tvdb'));
        $this->assertTrue($this->manager->hasProvider('local'));
    }
}
