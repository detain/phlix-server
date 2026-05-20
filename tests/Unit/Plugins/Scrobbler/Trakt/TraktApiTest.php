<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\HttpClient;
use Phlix\Plugins\Scrobbler\Trakt\HttpClientInterface;
use Phlix\Plugins\Scrobbler\Trakt\TraktApi;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TraktApiTest extends TestCase
{
    private const CLIENT_ID = 'test-client-id';
    private const CLIENT_SECRET = 'test-client-secret';

    public function testGetAuthUrlContainsExpectedParams(): void
    {
        $http = new MockHttpClient();
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $state = 'test-state-12345';
        $codeVerifier = 'test-code-verifier';

        $authUrl = $api->getAuthUrl($state, $codeVerifier);

        $this->assertStringContainsString('client_id=test-client-id', $authUrl);
        $this->assertStringContainsString('response_type=code', $authUrl);
        $this->assertStringContainsString('state=test-state-12345', $authUrl);
        $this->assertStringContainsString('code_challenge_method=S256', $authUrl);
        $this->assertStringContainsString('https://api.trakt.tv/oauth/authorize?', $authUrl);
    }

    public function testExchangeCodeReturnsTokens(): void
    {
        $http = new MockHttpClient([
            ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]
        ]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $result = $api->exchangeCode('auth-code', 'code-verifier');

        $this->assertSame('new-access', $result['access_token']);
        $this->assertSame('new-refresh', $result['refresh_token']);
        $this->assertSame(3600, $result['expires_in']);
    }

    public function testRefreshAccessTokenReturnsNewTokens(): void
    {
        $http = new MockHttpClient([
            ['access_token' => 'refreshed-access', 'refresh_token' => 'refreshed-refresh', 'expires_in' => 7200]
        ]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $result = $api->refreshAccessToken('old-refresh-token');

        $this->assertSame('refreshed-access', $result['access_token']);
        $this->assertSame('refreshed-refresh', $result['refresh_token']);
        $this->assertSame(7200, $result['expires_in']);
    }

    public function testGetWatchedHistoryReturnsArray(): void
    {
        $history = [['id' => 1, 'title' => 'Test Movie']];
        $http = new MockHttpClient([$history]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $result = $api->getWatchedHistory('testuser', 1, 100, 'access-token');

        $this->assertSame($history, $result);
    }

    public function testExchangeCodeThrowsOnError(): void
    {
        $http = new MockHttpClient([['error' => 'invalid_grant', 'error_description' => 'Code expired']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $this->expectException(TraktApiException::class);
        $api->exchangeCode('auth-code', 'code-verifier');
    }

    public function testRefreshTokenThrowsOnError(): void
    {
        $http = new MockHttpClient([['error' => 'invalid_grant']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $this->expectException(TraktApiException::class);
        $api->refreshAccessToken('old-refresh-token');
    }

    public function testGetWatchedHistoryUsesCorrectEndpoint(): void
    {
        $http = new MockHttpClient([[]]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $api->getWatchedHistory('testuser', 1, 100, 'access-token');

        $this->assertSame('GET', $http->lastMethod);
        $this->assertStringContainsString('/users/testuser/watched', $http->lastUrl);
    }
}

final class MockHttpClient implements HttpClientInterface
{
    public string $lastMethod = '';
    public string $lastUrl = '';
    public array $lastData = [];
    public array $lastHeaders = [];

    /** @var array<array> */
    private array $responses;
    private int $responseIndex = 0;

    /**
     * @param array<array> $responses Queue of responses to return
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function get(string $url, array $params = [], array $headers = []): array
    {
        $this->lastMethod = 'GET';
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;

        if (!empty($params)) {
            $this->lastUrl .= '?' . http_build_query($params);
        }

        return $this->getNextResponse();
    }

    public function post(string $url, array $data = [], array $headers = []): array
    {
        $this->lastMethod = 'POST';
        $this->lastUrl = $url;
        $this->lastData = $data;
        $this->lastHeaders = $headers;

        return $this->getNextResponse();
    }

    private function getNextResponse(): array
    {
        if ($this->responseIndex >= count($this->responses)) {
            return [];
        }

        return $this->responses[$this->responseIndex++];
    }
}
