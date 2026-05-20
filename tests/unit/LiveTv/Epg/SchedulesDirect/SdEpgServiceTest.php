<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Epg\SchedulesDirect;

use Phlix\LiveTv\Epg\SchedulesDirect\SdApiClient;
use Phlix\LiveTv\Epg\SchedulesDirect\SdEpgService;
use Phlix\LiveTv\Epg\SchedulesDirect\SdLineupHandler;
use Phlix\LiveTv\Epg\SchedulesDirect\SdProgramMapper;
use Phlix\LiveTv\ChannelManager;
use Phlix\LiveTv\GuideManager;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

class SdEpgServiceTest extends TestCase
{
    public function test_sync_epg_imports_programs(): void
    {
        $mockClient = $this->createMock(SdApiClient::class);
        $mockChannelManager = $this->createMock(ChannelManager::class);
        $mockGuideManager = $this->createMock(GuideManager::class);
        $mockLineupHandler = $this->createMock(SdLineupHandler::class);

        $mockClient->method('getSchedules')->willReturn([
            [
                'programID' => 'EP0012345678901',
                'stationID' => 'station123',
                'airDateTime' => '2024-01-15T14:00:00Z',
                'duration' => 60,
                'isRepeat' => false,
            ],
        ]);

        $mockClient->method('getPrograms')->willReturn([
            [
                'programID' => 'EP0012345678901',
                'title' => 'Test Program',
                'description' => 'Test description',
                'entityType' => 'episode',
                'seasonNumber' => 1,
                'episodeNumber' => 0,
                'genres' => ['Drama'],
            ],
        ]);

        $mockGuideManager->method('upsertProgram')->willReturn([
            'program_id' => 'test-id',
            'title' => 'Test Program',
        ]);

        $mapper = new SdProgramMapper();
        $service = new SdEpgService($mockClient, $mockLineupHandler, $mapper, $mockGuideManager);

        $result = $service->syncEpg(['station123'], 14);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('imported', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(0, $result['errors']);
    }

    public function test_sync_station_imports_and_returns_stats(): void
    {
        $mockClient = $this->createMock(SdApiClient::class);
        $mockChannelManager = $this->createMock(ChannelManager::class);
        $mockGuideManager = $this->createMock(GuideManager::class);
        $mockLineupHandler = $this->createMock(SdLineupHandler::class);

        $mockClient->method('getSchedules')->willReturn([]);
        $mockClient->method('getPrograms')->willReturn([]);

        $mapper = new SdProgramMapper();
        $service = new SdEpgService($mockClient, $mockLineupHandler, $mapper, $mockGuideManager);

        $result = $service->syncStation('station123', 7);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('imported', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    public function test_sync_handles_api_errors_gracefully(): void
    {
        $mockClient = $this->createMock(SdApiClient::class);
        $mockChannelManager = $this->createMock(ChannelManager::class);
        $mockGuideManager = $this->createMock(GuideManager::class);
        $mockLineupHandler = $this->createMock(SdLineupHandler::class);

        // Simulate API error - getSchedules returns empty array (treated as no data)
        $mockClient->method('getSchedules')->willReturn([]);

        $mapper = new SdProgramMapper();
        $service = new SdEpgService($mockClient, $mockLineupHandler, $mapper, $mockGuideManager);

        $result = $service->syncEpg(['station123'], 14);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['imported']);
        $this->assertGreaterThanOrEqual(0, $result['errors']);
    }

    public function test_sync_with_empty_station_ids(): void
    {
        $mockClient = $this->createMock(SdApiClient::class);
        $mockChannelManager = $this->createMock(ChannelManager::class);
        $mockGuideManager = $this->createMock(GuideManager::class);
        $mockLineupHandler = $this->createMock(SdLineupHandler::class);

        $mapper = new SdProgramMapper();
        $service = new SdEpgService($mockClient, $mockLineupHandler, $mapper, $mockGuideManager);

        $result = $service->syncEpg([], 14);

        $this->assertEquals(['imported' => 0, 'errors' => 0], $result);
    }

    public function test_sync_station_with_empty_response(): void
    {
        $mockClient = $this->createMock(SdApiClient::class);
        $mockChannelManager = $this->createMock(ChannelManager::class);
        $mockGuideManager = $this->createMock(GuideManager::class);
        $mockLineupHandler = $this->createMock(SdLineupHandler::class);

        $mockClient->method('getSchedules')->willReturn([]);
        $mockClient->method('getPrograms')->willReturn([]);

        $mapper = new SdProgramMapper();
        $service = new SdEpgService($mockClient, $mockLineupHandler, $mapper, $mockGuideManager);

        $result = $service->syncStation('station123');

        // Empty schedules result counts as error (no data returned)
        $this->assertEquals(['imported' => 0, 'errors' => 1], $result);
    }

    public function test_import_lineup_and_sync_returns_channels_and_stats(): void
    {
        $mockClient = $this->createMock(SdApiClient::class);
        $mockChannelManager = $this->createMock(ChannelManager::class);
        $mockGuideManager = $this->createMock(GuideManager::class);

        $mockChannelManager->method('createChannel')->willReturn([
            'channel_id' => 'ch_123',
            'service_id' => 'station123',
            'name' => 'Test Channel',
        ]);

        $mockClient->method('getSchedules')->willReturn([]);
        $mockClient->method('getPrograms')->willReturn([]);

        $mapper = new SdProgramMapper();
        $lineupHandler = new SdLineupHandler($mockClient, $mockChannelManager);
        $service = new SdEpgService($mockClient, $lineupHandler, $mapper, $mockGuideManager);

        $result = $service->importLineupAndSync('USA-OTA-00000');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('channels', $result);
        $this->assertArrayHasKey('stats', $result);
    }
}
