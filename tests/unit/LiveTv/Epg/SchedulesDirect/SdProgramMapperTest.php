<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\LiveTv\Epg\SchedulesDirect;

use Phlex\LiveTv\Epg\SchedulesDirect\SdProgramMapper;
use Phlex\LiveTv\GuideManager;
use PHPUnit\Framework\TestCase;

class SdProgramMapperTest extends TestCase
{
    private SdProgramMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new SdProgramMapper();
    }

    public function test_map_converts_sd_schedule_to_guide_entry(): void
    {
        $scheduleEntry = [
            'programID' => 'EP0012345678901',
            'stationID' => 'station123',
            'airDateTime' => '2024-01-15T14:00:00Z',
            'duration' => 60,
            'isRepeat' => false,
        ];

        $programData = [
            'programID' => 'EP0012345678901',
            'title' => 'Test Program',
            'description' => 'A test program description',
            'entityType' => 'episode',
            'seasonNumber' => 1,
            'episodeNumber' => 5,
            'episodeTitle' => 'The Fifth Episode',
            'genres' => ['Drama', 'Series'],
            'originalAirDate' => '2024-01-15',
            'contentRating' => ['TV-PG'],
        ];

        $result = $this->mapper->map($scheduleEntry, $programData);

        $this->assertIsArray($result);
        $this->assertEquals('station123', $result['channel_id']);
        $this->assertEquals('Test Program', $result['title']);
        $this->assertEquals('A test program description', $result['description']);
        $this->assertEquals(GuideManager::CATEGORY_SERIES, $result['category']);
        $this->assertEquals(6, $result['episode_number']); // 0-indexed + 1
        $this->assertEquals('The Fifth Episode', $result['episode_title']);
        $this->assertEquals('S01E06', $result['series_episode']);
        $this->assertFalse($result['is_repeat']);
        $this->assertFalse($result['is_film']);
        $this->assertEquals(2024, $result['year']);
        $this->assertEquals('TV-PG', $result['rating']);
    }

    public function test_map_station_to_channel_data(): void
    {
        $station = [
            'stationID' => 'station12345',
            'callSign' => 'WXYZ-TV',
            'channelNumber' => 12,
            'logicalChannelNumber' => 12,
            'stationName' => 'WXYZ Television',
            'logo' => [
                'URL' => 'https://example.com/logo.png',
            ],
        ];

        $result = $this->mapper->mapStation($station);

        $this->assertIsArray($result);
        $this->assertEquals('WXYZ-TV', $result['name']);
        $this->assertEquals(12, $result['number']);
        $this->assertEquals('tv', $result['type']);
        $this->assertEquals('WXYZ Television', $result['description']);
        $this->assertEquals('https://example.com/logo.png', $result['icon_url']);
        $this->assertEquals('sd_station12345', $result['tuner_id']);
        $this->assertEquals('station12345', $result['service_id']);
    }

    public function test_map_handles_null_episode_title(): void
    {
        $scheduleEntry = [
            'programID' => 'EP0012345678901',
            'stationID' => 'station123',
            'airDateTime' => '2024-01-15T14:00:00Z',
            'duration' => 60,
            'isRepeat' => true,
        ];

        $programData = [
            'programID' => 'EP0012345678901',
            'title' => 'Movie Title',
            'description' => 'A movie description',
            'entityType' => 'movie',
            'genres' => ['Movie'],
            'originalAirDate' => '2023-05-20',
            'contentRating' => ['PG-13'],
        ];

        $result = $this->mapper->map($scheduleEntry, $programData);

        $this->assertIsArray($result);
        $this->assertNull($result['episode_title']);
        $this->assertEquals(GuideManager::CATEGORY_MOVIE, $result['category']);
        $this->assertTrue($result['is_film']);
        $this->assertTrue($result['is_repeat']);
        $this->assertEquals(2023, $result['year']);
    }

    public function test_map_station_returns_null_for_insufficient_data(): void
    {
        $station = [
            'stationID' => 'station12345',
            // missing callSign
        ];

        $result = $this->mapper->mapStation($station);
        $this->assertNull($result);
    }

    public function test_map_station_handles_missing_logo(): void
    {
        $station = [
            'stationID' => 'station12345',
            'callSign' => 'WXYZ-TV',
            'channelNumber' => 12,
        ];

        $result = $this->mapper->mapStation($station);

        $this->assertIsArray($result);
        $this->assertNull($result['icon_url']);
    }

    public function test_map_handles_missing_schedule_fields(): void
    {
        $scheduleEntry = [];
        $programData = [
            'programID' => 'EP0012345678901',
            'title' => 'Test',
        ];

        $result = $this->mapper->map($scheduleEntry, $programData);
        $this->assertEquals([], $result);
    }

    public function test_map_handles_sports_genre(): void
    {
        $scheduleEntry = [
            'programID' => 'SP0012345678901',
            'stationID' => 'station123',
            'airDateTime' => '2024-01-15T14:00:00Z',
            'duration' => 180,
        ];

        $programData = [
            'programID' => 'SP0012345678901',
            'title' => 'Football Game',
            'genres' => ['Sports', 'Football'],
            'entityType' => 'sports',
        ];

        $result = $this->mapper->map($scheduleEntry, $programData);
        $this->assertEquals(GuideManager::CATEGORY_SPORTS, $result['category']);
    }

    public function test_map_handles_kids_genre(): void
    {
        $scheduleEntry = [
            'programID' => 'EP0012345678901',
            'stationID' => 'station123',
            'airDateTime' => '2024-01-15T14:00:00Z',
            'duration' => 30,
        ];

        $programData = [
            'programID' => 'EP0012345678901',
            'title' => 'Cartoon Show',
            'genres' => ['Children', 'Animation'],
        ];

        $result = $this->mapper->map($scheduleEntry, $programData);
        $this->assertEquals(GuideManager::CATEGORY_KIDS, $result['category']);
    }

    public function test_map_handles_news_genre(): void
    {
        $scheduleEntry = [
            'programID' => 'EP0012345678901',
            'stationID' => 'station123',
            'airDateTime' => '2024-01-15T19:00:00Z',
            'duration' => 60,
        ];

        $programData = [
            'programID' => 'EP0012345678901',
            'title' => 'Evening News',
            'genres' => ['News'],
        ];

        $result = $this->mapper->map($scheduleEntry, $programData);
        $this->assertEquals(GuideManager::CATEGORY_NEWS, $result['category']);
    }

    public function test_map_handles_music_genre(): void
    {
        $scheduleEntry = [
            'programID' => 'MU0012345678901',
            'stationID' => 'station123',
            'airDateTime' => '2024-01-15T22:00:00Z',
            'duration' => 120,
        ];

        $programData = [
            'programID' => 'MU0012345678901',
            'title' => 'Music Video Hour',
            'genres' => ['Music'],
        ];

        $result = $this->mapper->map($scheduleEntry, $programData);
        $this->assertEquals(GuideManager::CATEGORY_MUSIC, $result['category']);
    }

    public function test_map_station_uses_logical_channel_number_fallback(): void
    {
        $station = [
            'stationID' => 'station12345',
            'callSign' => 'WXYZ-TV',
            'logicalChannelNumber' => 5,
            // no channelNumber
        ];

        $result = $this->mapper->mapStation($station);
        $this->assertEquals(5, $result['number']);
    }
}
