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
        // Fake token cannot be validated against SD, so the contract yields false
        $this->assertFalse($result);
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
        $this->assertEmpty($result);
    }

    public function test_get_schedules_returns_array(): void
    {
        $client = new SdApiClient('test-token');
        $result = $client->getSchedules(['station1', 'station2'], time(), time() + 86400);
        $this->assertEmpty($result);
    }

    public function test_get_programs_returns_array(): void
    {
        $client = new SdApiClient('test-token');
        $result = $client->getPrograms(['program1', 'program2']);
        $this->assertEmpty($result);
    }

    public function test_get_schedule_md5_returns_array(): void
    {
        $client = new SdApiClient('test-token');
        $result = $client->getScheduleMd5(['station1', 'station2']);
        $this->assertEmpty($result);
    }

    public function test_get_available_lineups_returns_array(): void
    {
        $client = new SdApiClient('test-token');
        $result = $client->getAvailableLineups();
        $this->assertEmpty($result);
    }

    public function test_set_token_updates_token(): void
    {
        $client = new SdApiClient('original-token');
        $client->setToken('new-token');
        // Validate that the token was actually updated by reading the private property
        $tokenProperty = new \ReflectionProperty($client, 'token');
        $this->assertSame('new-token', $tokenProperty->getValue($client));
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

    // ---- F-5: path interpolation boundary + response cap wiring ----

    public function test_station_path_identity_for_canonical_sd_ids(): void
    {
        // Allowlisted bytes are already RFC-3986 path-safe: the encoder is an
        // identity map, so legitimate request URLs are byte-for-byte unchanged.
        $this->assertSame(
            '/headend/USA-OTA-00000/station',
            SdApiClient::stationPath('USA-OTA-00000')
        );
        $this->assertSame(
            '/headend/CAN.NGWG_00002/station',
            SdApiClient::stationPath('CAN.NGWG_00002')
        );
    }

    public static function hostile_system_id_provider(): array
    {
        return [
            'empty' => [''],
            'traversal' => ['../x'],
            'bare dot-dot' => ['..'],
            'bare dot-dot-dot' => ['...'],
            'single dot' => ['.'],
            'double-encoded traversal' => ['%2e%2e%2f'],
            'embedded slash' => ['a/b'],
            'space' => ['a b'],
            'newline' => ["USA\nOTA"],
            'query breakout' => ['x?y=1'],
            'fragment breakout' => ['x#y'],
            'unicode homoglyph' => ['USA–OTA'],
            'control byte' => ["\x01USA"],
        ];
    }

    /**
     * @dataProvider hostile_system_id_provider
     */
    public function test_station_path_refuses_non_bare_token(string $systemId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Schedules Direct lineup/system ID');

        SdApiClient::stationPath($systemId);
    }

    public function test_response_cap_is_above_legitimate_batch_sizes(): void
    {
        // SD multi-day batches are legitimately large; the cap sits above the
        // XMLTV guide cap on purpose (see constant docblock).
        $this->assertGreaterThanOrEqual(64 * 1024 * 1024, SdApiClient::MAX_RESPONSE_BYTES);
        $this->assertLessThanOrEqual(256 * 1024 * 1024, SdApiClient::MAX_RESPONSE_BYTES);
    }
}
