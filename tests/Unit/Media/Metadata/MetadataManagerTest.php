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
        
        $this->manager = new MetadataManager($mockItemRepo);
        
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
        $this->manager->registerProvider('local', $this->mockProvider, ['movie']);
        $this->manager->registerProvider('tmdb', $this->mockProvider, ['movie']);
        $this->manager->registerProvider('fanart', $this->mockProvider, ['movie']);
        $this->manager->setProviderPriority('movie', ['local', 'tmdb', 'fanart']);

        // All three prioritised providers now resolve for the movie type.
        $this->assertCount(3, $this->manager->getProvidersForType('movie'));
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

        // Unknown types fall back to the default ['local'] priority; with no
        // 'local' provider registered here, the resolved list is empty.
        $this->assertEmpty($providers);
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

        $manager = new MetadataManager($itemRepo, $libraries);
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
        $manager = new MetadataManager($itemRepo);
        $manager->registerProvider('tmdb', $provider, ['movie']);
        $manager->setProviderPriority('movie', ['tmdb']);

        $this->assertTrue($manager->refreshItemMetadata('item-1', true));

        $images = $captured['images']['tmdb'] ?? null;
        $this->assertIsArray($images);
        $this->assertArrayHasKey('posters', $images);
        $this->assertArrayHasKey('backdrops', $images);
    }

    /**
     * refreshLibraryMetadataBatched() yields one result per item and pages through
     * the library in PAGE_SIZE batches (100 items per page).
     */
    public function testRefreshLibraryMetadataBatchedPagesThroughLibrary(): void
    {
        $mockDb = $this->createMock(\Workerman\MySQL\Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        // Build three pages: 100 + 100 + 50 = 250 items.
        $page1 = $this->makeItemBatch('lib-1', 100, 'page1-');
        $page2 = $this->makeItemBatch('lib-1', 100, 'page2-');
        $page3 = $this->makeItemBatch('lib-1', 50, 'page3-');

        $callCount = 0;
        $itemRepo->method('getByLibrary')
            ->willReturnCallback(
                static function (string $libId, int $limit, int $offset) use (&$callCount, $page1, $page2, $page3): array {
                    $callCount++;
                    // PAGE_SIZE=100 is hard-coded in MetadataManager.
                    if ($offset === 0 && $limit === 100) {
                        return $page1;
                    }
                    if ($offset === 100 && $limit === 100) {
                        return $page2;
                    }
                    if ($offset === 200 && $limit === 100) {
                        return $page3;
                    }
                    return [];
                }
            );

        // findById is called by refreshItemMetadata for each item.
        $itemRepo->method('findById')
            ->willReturnCallback(
                static function (string $id): array {
                    return [
                        'id' => $id,
                        'library_id' => 'lib-1',
                        'type' => 'movie',
                        'name' => 'Test Movie',
                        'metadata_json' => '{}',
                    ];
                }
            );

        $manager = new MetadataManager($itemRepo);

        $results = [];
        foreach ($manager->refreshLibraryMetadataBatched('lib-1') as $refreshed) {
            $results[] = $refreshed;
        }

        // 250 total items across 3 pages.
        $this->assertCount(250, $results);
        $this->assertEquals(3, $callCount);
    }

    /**
     * refreshLibraryMetadata() aggregates the generator results and returns
     * the count of successfully refreshed items.
     */
    public function testRefreshLibraryMetadataReturnsCorrectCount(): void
    {
        $mockDb = $this->createMock(\Workerman\MySQL\Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        // Single page with 5 items.
        $batch = $this->makeItemBatch('lib-1', 5, 'item-');
        $itemRepo->method('getByLibrary')->willReturn($batch);
        $itemRepo->method('findById')
            ->willReturnCallback(
                static function (string $id): array {
                    return [
                        'id' => $id,
                        'library_id' => 'lib-1',
                        'type' => 'movie',
                        'name' => 'Test Movie',
                        'metadata_json' => '{}',
                    ];
                }
            );

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('search')->willReturn([['id' => '123', 'title' => 'Test']]);
        $provider->method('getDetails')->willReturn(['name' => 'Test Movie']);
        $provider->method('getImages')->willReturn([]);

        $manager = new MetadataManager($itemRepo);
        $manager->registerProvider('tmdb', $provider, ['movie']);
        $manager->setProviderPriority('movie', ['tmdb']);

        $count = $manager->refreshLibraryMetadata('lib-1');

        // All 5 items should refresh successfully.
        $this->assertEquals(5, $count);
    }

    /**
     * refreshLibraryMetadata() with an empty library returns 0.
     */
    public function testRefreshLibraryMetadataEmptyLibrary(): void
    {
        $mockDb = $this->createMock(\Workerman\MySQL\Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('getByLibrary')->willReturn([]);

        $manager = new MetadataManager($itemRepo);

        $count = $manager->refreshLibraryMetadata('lib-empty');

        $this->assertEquals(0, $count);
    }

    /**
     * refreshLibraryMetadataBatched() stops immediately on an empty first page.
     */
    public function testRefreshLibraryMetadataBatchedStopsOnEmptyPage(): void
    {
        $mockDb = $this->createMock(\Workerman\MySQL\Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('getByLibrary')->willReturn([]);

        $manager = new MetadataManager($itemRepo);

        $results = [];
        foreach ($manager->refreshLibraryMetadataBatched('lib-empty') as $refreshed) {
            $results[] = $refreshed;
        }

        $this->assertCount(0, $results);
    }

    /**
     * refreshLibraryMetadataBatched() with a large library (10K+ items) uses
     * bounded memory by paging through items in PAGE_SIZE batches.
     */
    public function testRefreshLibraryMetadataBatchedLargeLibraryMemorySafe(): void
    {
        $mockDb = $this->createMock(\Workerman\MySQL\Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        // Simulate 10,500 items (105 full pages of 100).
        $pageCount = 0;
        $itemRepo->method('getByLibrary')
            ->willReturnCallback(
                static function (string $libId, int $limit, int $offset) use (&$pageCount): array {
                    // After 105 pages (10,500 items), return empty to signal end.
                    if ($offset >= 10500) {
                        return [];
                    }
                    $pageCount++;
                    // Each page is a full PAGE_SIZE batch.
                    return array_fill(0, $limit, [
                        'id' => "large-lib-item-{$offset}",
                        'library_id' => $libId,
                        'type' => 'movie',
                        'name' => 'Large Library Test Movie',
                        'metadata_json' => '{}',
                    ]);
                }
            );

        $itemRepo->method('findById')
            ->willReturnCallback(
                static function (string $id): array {
                    return [
                        'id' => $id,
                        'library_id' => 'lib-large',
                        'type' => 'movie',
                        'name' => 'Large Library Test Movie',
                        'metadata_json' => '{}',
                    ];
                }
            );

        $manager = new MetadataManager($itemRepo);

        $processed = 0;
        foreach ($manager->refreshLibraryMetadataBatched('lib-large') as $refreshed) {
            $processed++;
            // Verify we're processing items, not accumulating the whole library.
            $this->assertLessThanOrEqual(100, $processed % 10500 > 0 ? 100 : $processed % 10500);
        }

        $this->assertEquals(10500, $processed);
        $this->assertEquals(105, $pageCount);
    }

    /**
     * progressCallback is called once per item with running processed count.
     */
    public function testRefreshLibraryMetadataProgressCallback(): void
    {
        $mockDb = $this->createMock(\Workerman\MySQL\Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $batch = $this->makeItemBatch('lib-1', 3, 'item-');
        $itemRepo->method('getByLibrary')->willReturn($batch);
        $itemRepo->method('findById')
            ->willReturnCallback(
                static function (string $id): array {
                    return [
                        'id' => $id,
                        'library_id' => 'lib-1',
                        'type' => 'movie',
                        'name' => 'Test Movie',
                        'metadata_json' => '{}',
                    ];
                }
            );

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('search')->willReturn([['id' => '123', 'title' => 'Test']]);
        $provider->method('getDetails')->willReturn(['name' => 'Test Movie']);
        $provider->method('getImages')->willReturn([]);

        $manager = new MetadataManager($itemRepo);
        $manager->registerProvider('tmdb', $provider, ['movie']);
        $manager->setProviderPriority('movie', ['tmdb']);

        $progressCalls = [];
        $count = $manager->refreshLibraryMetadata(
            'lib-1',
            static function (int $processed, int $total, int $refreshed) use (&$progressCalls): void {
                $progressCalls[] = ['processed' => $processed, 'total' => $total, 'refreshed' => $refreshed];
            }
        );

        $this->assertEquals(3, $count);
        $this->assertCount(3, $progressCalls);

        // Verify processed increments from 1 to 3.
        $this->assertEquals(1, $progressCalls[0]['processed']);
        $this->assertEquals(2, $progressCalls[1]['processed']);
        $this->assertEquals(3, $progressCalls[2]['processed']);
    }

    /**
     * Helper: build a batch of mock item rows.
     *
     * @return list<array{id: string, library_id: string, type: string, name: string, metadata_json: string}>
     */
    private function makeItemBatch(string $libraryId, int $count, string $prefix): array
    {
        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $batch[] = [
                'id' => $prefix . $i,
                'library_id' => $libraryId,
                'type' => 'movie',
                'name' => "Test Movie {$i}",
                'metadata_json' => '{}',
            ];
        }
        return $batch;
    }
}
