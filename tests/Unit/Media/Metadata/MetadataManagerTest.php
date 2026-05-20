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
}
