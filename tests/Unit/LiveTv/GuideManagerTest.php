<?php

namespace Phlix\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\GuideManager;
use Phlix\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

class GuideManagerTest extends TestCase
{
    private GuideManager $manager;
    private $mockDb;
    private $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDb = $this->createMock(Connection::class);
        $this->mockLogger = $this->createMock(StructuredLogger::class);
        $this->manager = new GuideManager($this->mockDb, $this->mockLogger);
    }

    public function testCanCreateGuideManager(): void
    {
        $this->assertInstanceOf(GuideManager::class, $this->manager);
    }

    public function testCategoryConstants(): void
    {
        $this->assertEquals('movie', GuideManager::CATEGORY_MOVIE);
        $this->assertEquals('series', GuideManager::CATEGORY_SERIES);
        $this->assertEquals('news', GuideManager::CATEGORY_NEWS);
        $this->assertEquals('sports', GuideManager::CATEGORY_SPORTS);
        $this->assertEquals('kids', GuideManager::CATEGORY_KIDS);
        $this->assertEquals('music', GuideManager::CATEGORY_MUSIC);
        $this->assertEquals('education', GuideManager::CATEGORY_EDUCATION);
        $this->assertEquals('other', GuideManager::CATEGORY_OTHER);
    }

    public function testRatingSystemConstants(): void
    {
        $this->assertEquals('tv', GuideManager::RATING_SYSTEM_TV);
        $this->assertEquals('mpaa', GuideManager::RATING_SYSTEM_MPAA);
        $this->assertEquals('acb', GuideManager::RATING_SYSTEM_ACB);
    }

    public function testGetProgramsForChannelsReturnsEmptyArrayForEmptyInput(): void
    {
        $programs = $this->manager->getProgramsForChannels([], time(), time() + 3600);
        $this->assertIsArray($programs);
        $this->assertEmpty($programs);
    }

    public function testImportGuideDataHandlesMissingFields(): void
    {
        $result = $this->manager->importGuideData([
            ['title' => 'Missing channel_id'],
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testSetCacheTtl(): void
    {
        $this->manager->setCacheTtl(7200);
        $stats = $this->manager->getCacheStats();
        $this->assertEquals(7200, $stats['ttl']);
    }

    public function testGetCacheStatsReturnsArray(): void
    {
        $stats = $this->manager->getCacheStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('entries', $stats);
        $this->assertArrayHasKey('ttl', $stats);
        $this->assertEquals(3600, $stats['ttl']); // default TTL
    }

    public function testClearCacheDoesNotThrow(): void
    {
        $this->manager->clearCache();
        $this->assertTrue(true); // If we get here, no exception was thrown
    }
}
