<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\Metadata\MetadataProviderInterface;
use Phlix\Media\Library\ItemRepository;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;

class MetadataManagerTest extends TestCase
{
    private MetadataManager $manager;
    private MetadataProviderInterface $mockProvider;

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
        
        // Create mock DB and item repository
        $mockDb = $this->createMock(\Workerman\MySQL\Connection::class);
        $mockItemRepo = $this->createMock(ItemRepository::class);
        
        $this->manager = new MetadataManager($mockDb, $mockItemRepo);
        
        // Create mock provider
        $this->mockProvider = $this->createMock(MetadataProviderInterface::class);
    }

    public function testCanCreateMetadataManager(): void
    {
        $this->assertInstanceOf(MetadataManager::class, $this->manager);
    }

    public function testRegisterProvider(): void
    {
        $this->manager->registerProvider('test', $this->mockProvider, ['movie', 'series']);
        
        $this->assertTrue($this->manager->hasProvider('test'));
        $this->assertSame($this->mockProvider, $this->manager->getProvider('test'));
    }

    public function testHasProviderReturnsFalseForUnregistered(): void
    {
        $this->assertFalse($this->manager->hasProvider('nonexistent'));
    }

    public function testGetProviderReturnsNullForUnregistered(): void
    {
        $this->assertNull($this->manager->getProvider('nonexistent'));
    }

    public function testGetRegisteredProviders(): void
    {
        $this->manager->registerProvider('test1', $this->mockProvider, ['movie']);
        $this->manager->registerProvider('test2', $this->mockProvider, ['series']);
        
        $providers = $this->manager->getRegisteredProviders();
        
        $this->assertContains('test1', $providers);
        $this->assertContains('test2', $providers);
    }

    public function testSetProviderPriority(): void
    {
        $this->manager->setProviderPriority('movie', ['local', 'tmdb', 'fanart']);
        
        // No exception means success
        $this->assertTrue(true);
    }

    public function testGetProvidersForTypeWithDefaultPriority(): void
    {
        $this->manager->registerProvider('local', $this->mockProvider, ['movie']);
        $this->manager->registerProvider('tmdb', $this->mockProvider, ['movie']);
        
        $providers = $this->manager->getProvidersForType('movie');
        
        $this->assertNotEmpty($providers);
    }

    public function testGetProvidersForTypeWithCustomPriority(): void
    {
        $this->manager->registerProvider('local', $this->mockProvider, ['movie']);
        $this->manager->registerProvider('tmdb', $this->mockProvider, ['movie']);
        $this->manager->setProviderPriority('movie', ['local', 'tmdb']);
        
        $providers = $this->manager->getProvidersForType('movie');
        
        $this->assertCount(2, $providers);
    }

    public function testGetProvidersForUnknownTypeReturnsDefault(): void
    {
        $providers = $this->manager->getProvidersForType('unknown');
        
        // Should return default priority which includes 'local'
        $this->assertIsArray($providers);
    }

    public function testRegisterProviderWithEmptySupportedTypes(): void
    {
        $this->manager->registerProvider('standalone', $this->mockProvider, []);
        
        $this->assertTrue($this->manager->hasProvider('standalone'));
    }

    public function testMultipleProvidersForSameType(): void
    {
        $provider1 = $this->createMock(MetadataProviderInterface::class);
        $provider2 = $this->createMock(MetadataProviderInterface::class);

        $this->manager->registerProvider('provider1', $provider1, ['movie']);
        $this->manager->registerProvider('provider2', $provider2, ['movie']);

        // Set custom priority that includes our test providers
        $this->manager->setProviderPriority('movie', ['provider1', 'provider2']);

        $providers = $this->manager->getProvidersForType('movie');

        // Both providers should be returned in priority order
        $this->assertCount(2, $providers);
    }

    /**
     * refreshItemMetadata() filters the per-provider image set (M5) to the
     * library's enabled `options.image_types` before storing it under
     * metadata_json.images.{provider}: a disabled type's key is dropped, an
     * enabled type's is kept, and an unmapped key passes through.
     */
    public function testRefreshFiltersStoredImagesToEnabledTypes(): void
    {
        $mockDb = $this->createMock(\Workerman\MySQL\Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->with('item-1')->willReturn([
            'id' => 'item-1',
            'library_id' => 'lib-1',
            'type' => 'movie',
            'name' => 'The Matrix',
            'metadata_json' => '{}',
        ]);

        $libraries = $this->createMock(\Phlix\Media\Library\LibraryManager::class);
        $libraries->method('getLibrary')->with('lib-1')->willReturn([
            'id' => 'lib-1',
            'type' => 'movie',
            // poster ON (default), backdrop OFF, logo OFF.
            'options' => ['image_types' => [
                'poster' => true,
                'backdrop' => false,
                'logo' => false,
            ]],
        ]);

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('search')->willReturn([['id' => '603', 'title' => 'The Matrix']]);
        $provider->method('getDetails')->willReturn(['name' => 'The Matrix']);
        $provider->method('getImages')->willReturn([
            'posters' => [['url' => 'p1']],
            'backdrops' => [['url' => 'b1']],
            'logos' => [['url' => 'l1']],
            'weird_key' => [['url' => 'w1']],   // unmapped → passes through
        ]);

        $captured = null;
        $itemRepo->method('update')->willReturnCallback(
            static function (string $id, array $data) use (&$captured): void {
                $meta = $data['metadata_json'] ?? null;
                $captured = is_string($meta) ? json_decode($meta, true) : $meta;
            }
        );

        $manager = new MetadataManager($mockDb, $itemRepo, $libraries);
        $manager->registerProvider('tmdb', $provider, ['movie']);
        $manager->setProviderPriority('movie', ['tmdb']);

        $this->assertTrue($manager->refreshItemMetadata('item-1', true));

        $this->assertIsArray($captured);
        $images = $captured['images']['tmdb'] ?? null;
        $this->assertIsArray($images);
        // Enabled poster kept; disabled backdrop + logo dropped; unmapped kept.
        $this->assertArrayHasKey('posters', $images);
        $this->assertArrayNotHasKey('backdrops', $images);
        $this->assertArrayNotHasKey('logos', $images);
        $this->assertArrayHasKey('weird_key', $images);
    }

    /**
     * Back-compat: with NO LibraryManager wired, the full provider image set is
     * stored unfiltered.
     */
    public function testRefreshWithoutLibraryManagerStoresFullImageSet(): void
    {
        $mockDb = $this->createMock(\Workerman\MySQL\Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->with('item-1')->willReturn([
            'id' => 'item-1',
            'library_id' => 'lib-1',
            'type' => 'movie',
            'name' => 'The Matrix',
            'metadata_json' => '{}',
        ]);

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('search')->willReturn([['id' => '603', 'title' => 'The Matrix']]);
        $provider->method('getDetails')->willReturn(['name' => 'The Matrix']);
        $provider->method('getImages')->willReturn([
            'posters' => [['url' => 'p1']],
            'backdrops' => [['url' => 'b1']],
        ]);

        $captured = null;
        $itemRepo->method('update')->willReturnCallback(
            static function (string $id, array $data) use (&$captured): void {
                $meta = $data['metadata_json'] ?? null;
                $captured = is_string($meta) ? json_decode($meta, true) : $meta;
            }
        );

        // No LibraryManager → no filtering.
        $manager = new MetadataManager($mockDb, $itemRepo);
        $manager->registerProvider('tmdb', $provider, ['movie']);
        $manager->setProviderPriority('movie', ['tmdb']);

        $this->assertTrue($manager->refreshItemMetadata('item-1', true));

        $images = $captured['images']['tmdb'] ?? null;
        $this->assertIsArray($images);
        $this->assertArrayHasKey('posters', $images);
        $this->assertArrayHasKey('backdrops', $images);
    }
}
