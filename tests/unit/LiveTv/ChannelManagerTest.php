<?php

namespace Phlix\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\ChannelManager;
use Phlix\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

class ChannelManagerTest extends TestCase
{
    private ChannelManager $manager;
    private $mockDb;
    private $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDb = $this->createMock(Connection::class);
        $this->mockLogger = $this->createMock(StructuredLogger::class);
        $this->manager = new ChannelManager($this->mockDb, $this->mockLogger);
    }

    public function testCanCreateChannelManager(): void
    {
        $this->assertInstanceOf(ChannelManager::class, $this->manager);
    }

    public function testTypeConstants(): void
    {
        $this->assertEquals('tv', ChannelManager::TYPE_TV);
        $this->assertEquals('radio', ChannelManager::TYPE_RADIO);
        $this->assertEquals('data', ChannelManager::TYPE_DATA);
    }

    public function testVisibilityConstants(): void
    {
        $this->assertEquals('visible', ChannelManager::VISIBILITY_VISIBLE);
        $this->assertEquals('hidden', ChannelManager::VISIBILITY_HIDDEN);
        $this->assertEquals('deleted', ChannelManager::VISIBILITY_DELETED);
    }

    public function testCreateChannelWithMinimalData(): void
    {
        $channelData = [
            'channel_id' => 'test-channel-id',
            'name' => 'Unknown Channel',
            'number' => 0,
            'type' => ChannelManager::TYPE_TV,
            'frequency' => 0,
            'tuner_id' => null,
            'service_id' => null,
            'visual_id' => null,
            'description' => null,
            'icon_url' => null,
            'visibility' => ChannelManager::VISIBILITY_VISIBLE,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Mock INSERT query - return 1 (lastInsertId)
        // Mock SELECT query to get the created channel
        $this->mockDb->method('query')
            ->willReturnOnConsecutiveCalls(1, [$channelData]);

        $channel = $this->manager->createChannel([]);

        $this->assertIsArray($channel);
        $this->assertEquals('Unknown Channel', $channel['name']);
        $this->assertEquals(ChannelManager::TYPE_TV, $channel['type']);
    }

    public function testGetChannelReturnsNullForNonexistent(): void
    {
        $this->mockDb->method('query')
            ->willReturn([]);

        $channel = $this->manager->getChannel('nonexistent');
        $this->assertNull($channel);
    }

    public function testGetAllChannelsReturnsArray(): void
    {
        $this->mockDb->method('query')
            ->willReturn([]);

        $channels = $this->manager->getAllChannels();
        $this->assertIsArray($channels);
    }

    public function testGetChannelByNumberReturnsNullForNonexistent(): void
    {
        $this->mockDb->method('query')
            ->willReturn([]);

        $channel = $this->manager->getChannelByNumber(999);
        $this->assertNull($channel);
    }

    public function testDeleteChannelReturnsFalseForNonexistent(): void
    {
        $this->mockDb->method('query')
            ->willReturn([]);

        $result = $this->manager->deleteChannel('nonexistent');
        $this->assertFalse($result);
    }

    public function testHideChannelReturnsFalseForNonexistent(): void
    {
        $this->mockDb->method('query')
            ->willReturn([]);

        $result = $this->manager->hideChannel('nonexistent');
        $this->assertFalse($result);
    }

    public function testAddToFavoritesReturnsFalseForNonexistentChannel(): void
    {
        $this->mockDb->method('query')
            ->willReturn([]);

        $result = $this->manager->addToFavorites('nonexistent', 'user123');
        $this->assertFalse($result);
    }

    public function testIsFavoriteReturnsFalseForNonexistentFavorite(): void
    {
        $this->mockDb->method('query')
            ->willReturn([]);

        $result = $this->manager->isFavorite('channel_1', 'user123');
        $this->assertFalse($result);
    }

    public function testGetFavoriteChannelsReturnsArray(): void
    {
        $this->mockDb->method('query')
            ->willReturn([]);

        $favorites = $this->manager->getFavoriteChannels('user123');
        $this->assertIsArray($favorites);
    }

    public function testGetChannelCountReturnsZero(): void
    {
        $this->mockDb->method('query')
            ->willReturn([['cnt' => 0]]);

        $count = $this->manager->getChannelCount();
        $this->assertEquals(0, $count);
    }

    public function testGetChannelCountReturnsInt(): void
    {
        $this->mockDb->method('query')
            ->willReturn([['cnt' => 42]]);

        $count = $this->manager->getChannelCount();
        $this->assertEquals(42, $count);
    }

    public function testCreateLineupReturnsArray(): void
    {
        $lineupData = [
            'lineup_id' => 'lineup-1',
            'name' => 'My Lineup',
            'user_id' => 'user123',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Mock all queries
        $this->mockDb->method('query')
            ->willReturnOnConsecutiveCalls(
                1, // INSERT into lineups
                [$lineupData], // SELECT lineup
                [] // SELECT lineup channels
            );

        $lineup = $this->manager->createLineup('My Lineup', 'user123', []);
        $this->assertIsArray($lineup);
        $this->assertEquals('My Lineup', $lineup['name']);
    }

    public function testGetLineupReturnsNullForNonexistent(): void
    {
        $this->mockDb->method('query')
            ->willReturn([]);

        $lineup = $this->manager->getLineup('nonexistent');
        $this->assertNull($lineup);
    }

    public function testGetUserLineupsReturnsArray(): void
    {
        $this->mockDb->method('query')
            ->willReturn([]);

        $lineups = $this->manager->getUserLineups('user123');
        $this->assertIsArray($lineups);
    }

    public function testGetLineupChannelsReturnsArray(): void
    {
        $this->mockDb->method('query')
            ->willReturn([]);

        $channels = $this->manager->getLineupChannels('lineup-1');
        $this->assertIsArray($channels);
    }

    public function testDeleteLineupReturnsTrue(): void
    {
        $this->mockDb->method('query')->willReturn(1);

        $result = $this->manager->deleteLineup('lineup-1');
        $this->assertTrue($result);
    }
}
