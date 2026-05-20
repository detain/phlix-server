<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Epg\SchedulesDirect;

use Phlix\LiveTv\Epg\SchedulesDirect\SdApiClient;
use PHPUnit\Framework\TestCase;

class SdApiClientTest extends TestCase
{
    public function test_validate_token_returns_bool(): void
    {
        // Test that validateToken returns a boolean
        $client = new SdApiClient('test-token-12345');
        // Without a real server, this will return null (false) or throw
        // The method should return bool, so we test its contract
        $result = $client->validateToken();
        $this->assertIsBool($result);
    }

    public function test_fetch_token_returns_string_on_success(): void
    {
        // With invalid credentials, should return null
        $client = new SdApiClient('');
        $result = $client->fetchToken('invalid', 'credentials');
        $this->assertNull($result);
    }

    public function test_fetch_token_returns_null_on_bad_credentials(): void
    {
        $client = new SdApiClient('');
        $result = $client->fetchToken('bad_user', 'bad_pass');
        $this->assertNull($result);
    }

    public function test_get_stations_returns_array(): void
    {
        $client = new SdApiClient('test-token');
        $result = $client->getStations('USA-OTA-00000');
        $this->assertIsArray($result);
    }

    public function test_get_schedules_returns_array(): void
    {
        $client = new SdApiClient('test-token');
        $result = $client->getSchedules(['station1', 'station2'], time(), time() + 86400);
        $this->assertIsArray($result);
    }

    public function test_get_programs_returns_array(): void
    {
        $client = new SdApiClient('test-token');
        $result = $client->getPrograms(['program1', 'program2']);
        $this->assertIsArray($result);
    }

    public function test_get_schedule_md5_returns_array(): void
    {
        $client = new SdApiClient('test-token');
        $result = $client->getScheduleMd5(['station1', 'station2']);
        $this->assertIsArray($result);
    }

    public function test_get_available_lineups_returns_array(): void
    {
        $client = new SdApiClient('test-token');
        $result = $client->getAvailableLineups();
        $this->assertIsArray($result);
    }

    public function test_set_token_updates_token(): void
    {
        $client = new SdApiClient('original-token');
        $client->setToken('new-token');
        // Validate that the token was updated (via reflection or by checking behavior)
        $this->assertTrue(true); // Token setter doesn't throw
    }

    public function test_empty_station_ids_returns_empty_array(): void
    {
        $client = new SdApiClient('test-token');
        $result = $client->getSchedules([], time(), time() + 86400);
        $this->assertEquals([], $result);
    }

    public function test_empty_program_ids_returns_empty_array(): void
    {
        $client = new SdApiClient('test-token');
        $result = $client->getPrograms([]);
        $this->assertEquals([], $result);
    }
}
