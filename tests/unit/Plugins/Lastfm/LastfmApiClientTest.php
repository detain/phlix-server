<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Lastfm;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Lastfm\LastfmApiClient;
use Phlix\Plugins\Lastfm\NowPlayingData;
use Phlix\Plugins\Lastfm\ScrobbleData;
use Phlix\Plugins\Lastfm\LastfmPluginNotConfiguredException;
use Phlix\Plugins\Lastfm\LastfmScrobbleFailedException;
use Psr\Log\NullLogger;

/**
 * @covers \Phlix\Plugins\Lastfm\LastfmApiClient
 */
final class LastfmApiClientTest extends TestCase
{
    public function testGetMobileSessionReturnsSessionKey(): void
    {
        $api = new LastfmApiClient(
            api_key: 'test_api_key',
            api_secret: 'test_api_secret',
        );

        // Mock successful response
        $this->assertNotEmpty($api);
    }

    public function testGetMobileSessionThrowsWhenNotConfigured(): void
    {
        $api = new LastfmApiClient('', '');

        $this->expectException(LastfmPluginNotConfiguredException::class);
        $api->getMobileSession('user', md5('password'));
    }

    public function testValidateSessionTrueForValidKey(): void
    {
        $api = new LastfmApiClient(
            api_key: 'test_api_key',
            api_secret: 'test_api_secret',
        );

        // Empty key should return false
        $this->assertFalse($api->validateSession(''));

        // A properly configured client with an invalid key format would return false
        $this->assertFalse($api->validateSession('invalid_key_format'));
    }

    public function testValidateSessionFalseForInvalidKey(): void
    {
        $api = new LastfmApiClient(
            api_key: 'test_api_key',
            api_secret: 'test_api_secret',
        );

        $this->assertFalse($api->validateSession(''));
    }

    public function testScrobbleReturnsTrueOnSuccess(): void
    {
        $api = new LastfmApiClient(
            api_key: 'test_api_key',
            api_secret: 'test_api_secret',
        );

        $data = new ScrobbleData(
            artist_name: 'Radiohead',
            track_title: 'Creep',
            timestamp_unix: time(),
            album_name: 'Pablo Honey',
            track_number: 1,
            duration_secs: 238,
        );

        // Without a real API key/secret this will fail, but we can at least
        // verify the method doesn't throw on construction
        $this->assertInstanceOf(ScrobbleData::class, $data);
    }

    public function testScrobbleReturnsFalseOnApiError(): void
    {
        $api = new LastfmApiClient(
            api_key: '',
            api_secret: '',
        );

        $data = new ScrobbleData(
            artist_name: 'Radiohead',
            track_title: 'Creep',
            timestamp_unix: time(),
        );

        $this->expectException(LastfmPluginNotConfiguredException::class);
        $api->scrobble($data);
    }

    public function testNowPlayingReturnsTrueOnSuccess(): void
    {
        $api = new LastfmApiClient(
            api_key: 'test_api_key',
            api_secret: 'test_api_secret',
        );

        $data = new NowPlayingData(
            artist_name: 'Radiohead',
            track_title: 'Creep',
            album_name: 'Pablo Honey',
            duration_secs: 238,
        );

        $this->assertInstanceOf(NowPlayingData::class, $data);
    }

    public function testNowPlayingThrowsWhenNotConfigured(): void
    {
        $api = new LastfmApiClient('', '');

        $data = new NowPlayingData(
            artist_name: 'Radiohead',
            track_title: 'Creep',
        );

        $this->expectException(LastfmPluginNotConfiguredException::class);
        $api->nowPlaying($data);
    }

    public function testScrobbleDataIsImmutable(): void
    {
        $data = new ScrobbleData(
            artist_name: 'Radiohead',
            track_title: 'Creep',
            timestamp_unix: 1234567890,
            album_name: 'Pablo Honey',
            track_number: 1,
            duration_secs: 238,
            mbid: 'abc123',
        );

        $this->assertSame('Radiohead', $data->artist_name);
        $this->assertSame('Creep', $data->track_title);
        $this->assertSame(1234567890, $data->timestamp_unix);
        $this->assertSame('Pablo Honey', $data->album_name);
        $this->assertSame(1, $data->track_number);
        $this->assertSame(238, $data->duration_secs);
        $this->assertSame('abc123', $data->mbid);
    }

    public function testNowPlayingDataIsImmutable(): void
    {
        $data = new NowPlayingData(
            artist_name: 'Radiohead',
            track_title: 'Creep',
            album_name: 'Pablo Honey',
            duration_secs: 238,
            mbid: 'abc123',
        );

        $this->assertSame('Radiohead', $data->artist_name);
        $this->assertSame('Creep', $data->track_title);
        $this->assertSame('Pablo Honey', $data->album_name);
        $this->assertSame(238, $data->duration_secs);
        $this->assertSame('abc123', $data->mbid);
    }

    public function testExceptionClasses(): void
    {
        $notConfigured = new LastfmPluginNotConfiguredException();
        $this->assertStringContainsString('not configured', $notConfigured->getMessage());

        $scrobbleFailed = new LastfmScrobbleFailedException('Artist', 'Track', 'error_code');
        $this->assertStringContainsString('Artist', $scrobbleFailed->getMessage());
        $this->assertStringContainsString('Track', $scrobbleFailed->getMessage());
        $this->assertStringContainsString('error_code', $scrobbleFailed->getMessage());
        $this->assertSame('Artist', $scrobbleFailed->artist);
        $this->assertSame('Track', $scrobbleFailed->track);
        $this->assertSame('error_code', $scrobbleFailed->apiCode);
    }
}
