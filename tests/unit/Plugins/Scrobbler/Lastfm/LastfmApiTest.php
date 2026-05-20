<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmApi;

/**
 * Unit tests for {@see LastfmApi}.
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\LastfmApi
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
final class LastfmApiTest extends TestCase
{
    /**
     * Worked example from the Last.fm Web Service authentication docs:
     * the api_sig is the MD5 of the alphabetically-sorted concatenation
     * of (key . value) for every parameter except `format`/`callback`,
     * followed by the shared secret.
     */
    public function testBuildApiSigMatchesLastfmWorkedExample(): void
    {
        $api = new LastfmApi('xxxxxxxxxx', 'mysecret');

        // Parameters from the Last.fm docs sample (sorted: api_key, method, token).
        $params = [
            'method'  => 'auth.getSession',
            'api_key' => 'xxxxxxxxxx',
            'token'   => 'xxxxxxxxxxxxxxx',
        ];

        $expected = md5('api_keyxxxxxxxxxxmethodauth.getSessiontokenxxxxxxxxxxxxxxxmysecret');
        $this->assertSame($expected, $api->buildApiSig($params));
    }

    public function testBuildApiSigSkipsFormatAndCallback(): void
    {
        $api = new LastfmApi('k', 's');
        $params = ['method' => 'm', 'api_key' => 'k', 'format' => 'json', 'callback' => 'cb', 'extra' => 'e'];
        $expected = md5('api_keykextraemethodms');
        $this->assertSame($expected, $api->buildApiSig($params));
    }

    public function testGetSessionReturnsSessionKeyAndUsernameOnSuccess(): void
    {
        $api = $this->apiWith(200, json_encode([
            'session' => ['key' => 'SESSION_KEY', 'name' => 'rj'],
        ]));
        $session = $api->getSession('TOKEN');
        $this->assertNotNull($session);
        $this->assertSame('SESSION_KEY', $session['session_key']);
        $this->assertSame('rj', $session['username']);
    }

    public function testGetSessionReturnsNullOnHttpError(): void
    {
        $api = $this->apiWith(403, json_encode(['error' => 14, 'message' => 'Unauthorized Token']));
        $this->assertNull($api->getSession('BAD_TOKEN'));
    }

    public function testGetSessionReturnsNullOnUnparseableBody(): void
    {
        $api = $this->apiWith(200, 'not-json');
        $this->assertNull($api->getSession('TOKEN'));
    }

    public function testUpdateNowPlayingReturnsTrueOn200(): void
    {
        $api = $this->apiWith(200, json_encode([
            'nowplaying' => ['track' => ['#text' => 'Song'], 'artist' => ['#text' => 'Artist']],
        ]));
        $this->assertTrue($api->updateNowPlaying('SK', 'Song', 'Artist'));
    }

    public function testUpdateNowPlayingReturnsFalseOnApiError(): void
    {
        $api = $this->apiWith(200, json_encode(['error' => 9, 'message' => 'Invalid session key']));
        $this->assertFalse($api->updateNowPlaying('SK', 'Song', 'Artist'));
    }

    public function testScrobbleReturnsTrueOn200(): void
    {
        $api = $this->apiWith(200, json_encode([
            'scrobbles' => ['scrobble' => ['track' => ['#text' => 'Song']]],
        ]));
        $this->assertTrue($api->scrobble('SK', 'Song', 'Artist', 'Album', 1700000000));
    }

    public function testScrobbleReturnsFalseOn500(): void
    {
        $api = $this->apiWith(500, '');
        $this->assertFalse($api->scrobble('SK', 'Song', 'Artist'));
    }

    public function testScrobbleIncludesSortedSignatureAndTimestamp(): void
    {
        $captured = ['url' => '', 'body' => ''];
        $http = function (string $url, string $body) use (&$captured) {
            $captured['url'] = $url;
            $captured['body'] = $body;
            return ['status' => 200, 'body' => '{"scrobbles":{}}'];
        };
        $api = new LastfmApi('K', 'S', null, $http);
        $api->scrobble('SK', 'Song', 'Artist', 'Album', 1700000000);

        parse_str($captured['body'], $parsed);
        $this->assertSame('track.scrobble', $parsed['method'] ?? null);
        $this->assertSame('Song', $parsed['track'] ?? null);
        $this->assertSame('Artist', $parsed['artist'] ?? null);
        $this->assertSame('Album', $parsed['album'] ?? null);
        $this->assertSame('1700000000', $parsed['timestamp'] ?? null);
        $this->assertSame('json', $parsed['format'] ?? null);
        $expectedSig = md5(
            'albumAlbumapi_keyKartistArtistmethodtrack.scrobbleskSKtimestamp1700000000trackSongS'
        );
        $this->assertSame($expectedSig, $parsed['api_sig'] ?? null);
    }

    /**
     * Build a LastfmApi backed by a stubbed HTTP runner.
     */
    private function apiWith(int $status, string|false $body): LastfmApi
    {
        $http = static function () use ($status, $body): array {
            return ['status' => $status, 'body' => is_string($body) ? $body : ''];
        };
        return new LastfmApi('API_KEY', 'SHARED_SECRET', null, $http);
    }
}
